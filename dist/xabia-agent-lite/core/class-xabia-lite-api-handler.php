<?php
/**
 * API AJAX LITE — Gemini BYOK directo. Sin Hub, proxies ni HMAC corporativo.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Lite_API_Handler {

    public const NONCE_ACTION = 'xabia_lite_nonce';
    private const MODEL_ID = 'gemini-1.5-flash';
    private const API_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const TEMPERATURE = 0.2;
    private const REQUEST_TIMEOUT = 60;
    private const MAX_HISTORY_TURNS = 6;

    public static function init(): void {
        if (!class_exists('Xabia_Features', false) || !Xabia_Features::is_lite()) {
            return;
        }

        add_action('wp_ajax_xabia_lite_ask_ai', [self::class, 'handle_request']);
        add_action('wp_ajax_nopriv_xabia_lite_ask_ai', [self::class, 'handle_request']);
    }

    public static function handle_request(): void {
        if (!self::verify_nonce()) {
            wp_send_json_error([
                'message' => __('La comprobación de seguridad falló.', 'xabia-intelligence'),
            ]);
        }

        $message = isset($_POST['message'])
            ? sanitize_textarea_field(wp_unslash((string) $_POST['message']))
            : '';
        if ($message === '') {
            wp_send_json_success(['response' => '']);
        }

        $settings = Xabia_Mode::get_lite_settings();
        $auth_mode = $settings['auth_mode'] ?? 'xabia_cloud';
        $system_instruction = Xabia_Lite_Context::build_system_prompt($settings['system_instructions']);
        if ($system_instruction === '') {
            $system_instruction = 'You are a helpful assistant. Answer clearly using the catalog data when it is provided.';
        }

        $history_raw = isset($_POST['history']) ? (string) wp_unslash($_POST['history']) : '';

        if ($auth_mode === 'xabia_cloud') {
            self::handle_xabia_cloud_request($message, $history_raw, $system_instruction);
            return;
        }

        $api_key = Xabia_Mode::get_lite_gemini_api_key();
        if ($api_key === '') {
            wp_send_json_error([
                'message' => __('Configura tu API Key de Gemini en el panel Xabia LITE.', 'xabia-intelligence'),
            ]);
        }

        $contents = self::build_contents($message, $history_raw);

        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $system_instruction],
                ],
            ],
            'contents'          => $contents,
            'generationConfig'    => [
                'temperature' => self::TEMPERATURE,
            ],
        ];

        $url = self::API_ENDPOINT . rawurlencode(self::MODEL_ID) . ':generateContent?key=' . rawurlencode($api_key);

        $response = wp_remote_post(
            $url,
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body'    => wp_json_encode($payload),
                'timeout' => self::REQUEST_TIMEOUT,
            ]
        );

        if (is_wp_error($response)) {
            wp_send_json_error([
                'message' => __('No se pudo contactar con la API de Gemini. Inténtalo de nuevo.', 'xabia-intelligence'),
            ]);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body_raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body_raw, true);

        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            wp_send_json_error([
                'message' => self::extract_error_message($decoded, $status),
            ]);
        }

        $parsed = self::extract_model_text($decoded);
        if ($parsed['text'] === '') {
            wp_send_json_error([
                'message' => __('Gemini no devolvió una respuesta utilizable.', 'xabia-intelligence'),
            ]);
        }

        $out = ['response' => $parsed['text']];
        if ($parsed['truncated']) {
            $out['truncated'] = true;
        }

        wp_send_json_success($out);
    }

    private static function handle_xabia_cloud_request(string $message, string $history_raw, string $system_instruction): void {
        if (!class_exists('Xabia_Lite_Hub_Client', false)) {
            wp_send_json_error([
                'message' => __('Xabia Cloud no está disponible en esta instalación.', 'xabia-intelligence'),
            ]);
        }

        $license = Xabia_Mode::get_lite_xabia_api_key();
        $messages = self::history_to_openai_messages($message, $history_raw);
        $result = Xabia_Lite_Hub_Client::chat($license, $messages, $system_instruction);

        if (empty($result['ok'])) {
            wp_send_json_error([
                'message' => (string) ($result['message'] ?? __('Error de Xabia Cloud.', 'xabia-intelligence')),
            ]);
        }

        wp_send_json_success(['response' => (string) ($result['text'] ?? '')]);
    }

    /**
     * @return list<array{role:string,content:string}>
     */
    private static function history_to_openai_messages(string $message, string $history_raw): array {
        $messages = [];
        $history_raw = trim($history_raw);
        if ($history_raw !== '') {
            $decoded = json_decode($history_raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $role = isset($item['role']) ? (string) $item['role'] : 'user';
                    $content = isset($item['content']) ? sanitize_textarea_field((string) $item['content']) : '';
                    if ($content === '') {
                        continue;
                    }
                    if ($role === 'assistant' || $role === 'bot' || $role === 'model') {
                        $role = 'assistant';
                    } else {
                        $role = 'user';
                    }
                    $messages[] = ['role' => $role, 'content' => $content];
                }
            }
        }

        $last = end($messages);
        if (!is_array($last) || ($last['content'] ?? '') !== $message) {
            $messages[] = ['role' => 'user', 'content' => $message];
        }

        if (count($messages) > self::MAX_HISTORY_TURNS) {
            $messages = array_slice($messages, -self::MAX_HISTORY_TURNS);
        }

        return $messages;
    }

    private static function verify_nonce(): bool {
        if (!isset($_POST['nonce'])) {
            return false;
        }

        $nonce = sanitize_text_field(wp_unslash((string) $_POST['nonce']));

        return $nonce !== '' && wp_verify_nonce($nonce, self::NONCE_ACTION);
    }

    /**
     * @return list<array{role:string,parts:list<array{text:string}>}>
     */
    public static function map_history_for_gemini(string $message, string $history_raw): array {
        return self::build_contents($message, $history_raw);
    }

    /**
     * @return list<array{role:string,parts:list<array{text:string}>}>
     */
    private static function build_contents(string $message, string $history_raw): array {
        $contents = self::parse_history($history_raw);

        $last = end($contents);
        if (
            is_array($last)
            && ($last['role'] ?? '') === 'user'
            && isset($last['parts'][0]['text'])
            && (string) $last['parts'][0]['text'] === $message
        ) {
            return $contents;
        }

        $contents[] = [
            'role'  => 'user',
            'parts' => [
                ['text' => $message],
            ],
        ];

        return $contents;
    }

    /**
     * @return list<array{role:string,parts:list<array{text:string}>}>
     */
    private static function parse_history(string $history_raw): array {
        $history_raw = trim($history_raw);
        if ($history_raw === '') {
            return [];
        }

        $decoded = json_decode($history_raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $role = isset($item['role']) ? strtolower(sanitize_key((string) $item['role'])) : '';
            $content = isset($item['content'])
                ? sanitize_textarea_field((string) $item['content'])
                : '';
            if ($content === '') {
                continue;
            }

            if ($role === 'user') {
                $out[] = [
                    'role'  => 'user',
                    'parts' => [['text' => $content]],
                ];
            } elseif ($role === 'assistant' || $role === 'bot' || $role === 'model') {
                $out[] = [
                    'role'  => 'model',
                    'parts' => [['text' => $content]],
                ];
            }
        }

        if (count($out) > self::MAX_HISTORY_TURNS) {
            $out = array_slice($out, -self::MAX_HISTORY_TURNS);
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $decoded
     */
    private static function extract_error_message(?array $decoded, int $status): string {
        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $msg = isset($decoded['error']['message']) ? trim((string) $decoded['error']['message']) : '';
            if ($msg !== '') {
                return sanitize_text_field($msg);
            }
        }

        if ($status === 401 || $status === 403) {
            return __('API Key de Gemini inválida o sin permisos.', 'xabia-intelligence');
        }

        if ($status === 429) {
            return __('Límite de uso de Gemini alcanzado. Espera un momento e inténtalo de nuevo.', 'xabia-intelligence');
        }

        return __('Error al procesar la respuesta de Gemini.', 'xabia-intelligence');
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array{text:string,truncated:bool}
     */
    private static function extract_model_text(array $decoded): array {
        $text = '';
        $truncated = false;

        $candidates = $decoded['candidates'] ?? null;
        if (!is_array($candidates) || $candidates === []) {
            return ['text' => '', 'truncated' => false];
        }

        $first = $candidates[0];
        if (!is_array($first)) {
            return ['text' => '', 'truncated' => false];
        }

        $finish = isset($first['finishReason']) ? strtoupper((string) $first['finishReason']) : '';
        if ($finish === 'MAX_TOKENS') {
            $truncated = true;
        }

        $content = $first['content'] ?? null;
        if (!is_array($content)) {
            return ['text' => '', 'truncated' => $truncated];
        }

        $parts = $content['parts'] ?? null;
        if (!is_array($parts)) {
            return ['text' => '', 'truncated' => $truncated];
        }

        $chunks = [];
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            $chunk = isset($part['text']) ? trim((string) $part['text']) : '';
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
        }

        $text = trim(implode("\n", $chunks));

        return ['text' => $text, 'truncated' => $truncated];
    }
}

Xabia_Lite_API_Handler::init();
