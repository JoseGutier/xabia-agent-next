<?php
/**
 * Cliente Hub mínimo para Xabia LITE (modo Xabia Cloud / recargas).
 * Sin cargar class-xabia-digixop-client.php en el build LITE.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Lite_Hub_Client {

    private const PROXY_URL = 'https://xabia.ai/api/xabia/v1/proxy';

    public static function get_client_source_url(): string {
        $home = home_url('/');
        $host = wp_parse_url($home, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return $home;
        }

        return 'https://' . strtolower($host);
    }

    /**
     * @return array<string, string>
     */
    private static function signed_headers(string $license, string $body): array {
        $license = trim($license);
        $source = self::get_client_source_url();
        $timestamp = (string) time();
        $payload = $license . "\n" . $source . "\n" . $timestamp . "\n" . $body;
        $headers = [
            'Content-Type'    => 'application/json',
            'Accept'          => 'application/json',
            'X-Xabia-License' => $license,
            'X-Xabia-Source'  => $source,
            'X-Xabia-Timestamp' => $timestamp,
            'X-Xabia-Signature' => 'sha256=' . hash_hmac('sha256', $payload, $license),
        ];

        return $headers;
    }

    /**
     * Chat vía proxy OpenAI-compatible del Hub Xabia.
     *
     * @param list<array{role:string,content:string}> $messages
     * @return array{ok:bool,text:string,message:string}
     */
    public static function chat(string $license, array $messages, string $system_instruction = ''): array {
        $license = trim($license);
        if ($license === '') {
            return [
                'ok'      => false,
                'text'    => '',
                'message' => __('Introduce tu Xabia API Key en el panel LITE.', 'xabia-intelligence'),
            ];
        }

        $openai_messages = [];
        if ($system_instruction !== '') {
            $openai_messages[] = ['role' => 'system', 'content' => $system_instruction];
        }
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $role = isset($msg['role']) ? (string) $msg['role'] : 'user';
            $content = isset($msg['content']) ? (string) $msg['content'] : '';
            if ($content === '') {
                continue;
            }
            if ($role === 'assistant') {
                $role = 'assistant';
            } elseif ($role === 'model') {
                $role = 'assistant';
            } else {
                $role = 'user';
            }
            $openai_messages[] = ['role' => $role, 'content' => $content];
        }

        $body_arr = [
            'model'       => 'gemini-2.5-flash',
            'messages'    => $openai_messages,
            'temperature' => 0.2,
        ];
        $body = wp_json_encode($body_arr);
        if (!is_string($body)) {
            $body = '{}';
        }

        $url = (string) apply_filters('xabia_lite_proxy_url', self::PROXY_URL);
        $resp = wp_remote_post($url, [
            'headers' => self::signed_headers($license, $body),
            'body'    => $body,
            'timeout' => 60,
        ]);

        if (is_wp_error($resp)) {
            return [
                'ok'      => false,
                'text'    => '',
                'message' => __('No se pudo contactar con Xabia Cloud. Inténtalo de nuevo.', 'xabia-intelligence'),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw = (string) wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);

        if ($code === 402) {
            return [
                'ok'      => false,
                'text'    => '',
                'message' => __('Saldo agotado. Recarga en xabia.ai/#wallet', 'xabia-intelligence'),
            ];
        }

        if ($code < 200 || $code >= 300 || !is_array($json)) {
            $msg = is_array($json) && isset($json['error']['message'])
                ? (string) $json['error']['message']
                : __('Error de Xabia Cloud.', 'xabia-intelligence');

            return ['ok' => false, 'text' => '', 'message' => sanitize_text_field($msg)];
        }

        $text = '';
        if (isset($json['choices'][0]['message']['content'])) {
            $text = trim((string) $json['choices'][0]['message']['content']);
        } elseif (isset($json['response'])) {
            $text = trim((string) $json['response']);
        }

        if ($text === '') {
            return [
                'ok'      => false,
                'text'    => '',
                'message' => __('Xabia Cloud no devolvió una respuesta utilizable.', 'xabia-intelligence'),
            ];
        }

        return ['ok' => true, 'text' => $text, 'message' => ''];
    }
}
