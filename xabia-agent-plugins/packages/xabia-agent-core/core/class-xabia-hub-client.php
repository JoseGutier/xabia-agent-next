<?php
/**
 * Cliente Hub Xabia: servicios centralizados (TTS, etc.) vía licencia firmada.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Xabia_Hub_Client')) :

final class Xabia_Hub_Client {

    public const URL_TTS_SYNTHESIZE = 'https://xabia.ai/api/xabia/v1/tts/synthesize';

    public const OPTION_TTS_SYNTHESIZE_URL = 'xabia_hub_tts_synthesize_url';

    /** @var array<string, mixed>|null */
    private static ?array $last_error = null;

    public static function default_tts_synthesize_url(): string {
        $o = get_option(self::OPTION_TTS_SYNTHESIZE_URL, '');
        if (is_string($o) && trim($o) !== '') {
            return trim($o);
        }

        return self::URL_TTS_SYNTHESIZE;
    }

    public static function is_available(): bool {
        return class_exists('Xabia_Digixop_Client', false)
            && Xabia_Digixop_Client::is_license_configured();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get_last_error(): ?array {
        return self::$last_error;
    }

    /**
     * Sintetiza voz en el Hub (Google Cloud / OpenAI centralizados).
     *
     * @return array{base64: string, mime: string, engine: string, text: string}|null
     */
    public static function synthesize_speech(
        string $text,
        string $locale_bcp47,
        string $project_id,
        string $voice_pref = 'default',
        float $rate = 1.0
    ): ?array {
        self::$last_error = null;

        if (!self::is_available() || !class_exists('Xabia_Digixop_Client', false)) {
            self::$last_error = [
                'code'    => 'hub_unavailable',
                'message' => 'Licencia Xabia no configurada en este sitio',
            ];
            return null;
        }

        $text = trim($text);
        if ($text === '') {
            self::$last_error = [
                'code'    => 'empty_text',
                'message' => 'Texto TTS vacío',
            ];
            return null;
        }

        $locale_bcp47 = trim(str_replace('_', '-', $locale_bcp47));
        if ($locale_bcp47 === '') {
            $locale_bcp47 = 'es-ES';
        }

        $voice_pref = sanitize_key($voice_pref);
        if (!in_array($voice_pref, ['default', 'female', 'male'], true)) {
            $voice_pref = 'default';
        }

        $rate = max(0.5, min(2.0, $rate > 0 ? $rate : 1.0));

        $body = [
            'text'       => $text,
            'locale'     => $locale_bcp47,
            'agent_id'   => sanitize_key($project_id),
            'project_id' => sanitize_key($project_id),
            'voice'      => $voice_pref,
            'rate'       => $rate,
        ];

        $url = (string) apply_filters(
            'xabia_hub_tts_synthesize_url',
            self::default_tts_synthesize_url(),
            $project_id
        );

        $out = Xabia_Digixop_Client::hub_signed_json_post($url, $body, $project_id);
        if (!$out['ok'] || !is_array($out['body'])) {
            self::log_hub_failure($url, $out);
            return null;
        }

        $normalized = self::normalize_hub_audio_response($out['body'], $text);
        if ($normalized === null) {
            self::$last_error = [
                'code'    => 'invalid_hub_audio_payload',
                'message' => 'El Hub respondió 200 pero sin audio utilizable',
                'body'    => self::sanitize_log_payload($out['body']),
            ];
            error_log('Xabia Hub TTS Error: invalid audio payload from Hub');
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $out
     */
    private static function log_hub_failure(string $url, array $out): void {
        $code = (int) ($out['code'] ?? 0);
        $raw = isset($out['raw']) ? (string) $out['raw'] : '';
        $body = is_array($out['body'] ?? null) ? $out['body'] : null;
        $wp_error = is_array($out['wp_error'] ?? null) ? $out['wp_error'] : null;

        self::$last_error = [
            'code'     => $code,
            'url'      => $url,
            'message'  => self::extract_hub_error_message($body, $raw, $wp_error),
            'hub_body' => self::sanitize_log_payload($body),
        ];

        if ($wp_error !== null) {
            error_log('Xabia Hub TTS Error: WP_Error url=' . $url . ' code=' . ($wp_error['code'] ?? '') . ' message=' . ($wp_error['message'] ?? ''));
            return;
        }

        error_log('Xabia Hub TTS Error [' . $code . '] url=' . $url . ' body=' . substr($raw, 0, 2000));
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>|null $wp_error
     */
    private static function extract_hub_error_message(?array $body, string $raw, ?array $wp_error): string {
        if ($wp_error !== null && !empty($wp_error['message'])) {
            return (string) $wp_error['message'];
        }
        if (is_array($body)) {
            if (isset($body['error']['message']) && is_string($body['error']['message'])) {
                return (string) $body['error']['message'];
            }
            if (isset($body['message']) && is_string($body['message'])) {
                return (string) $body['message'];
            }
        }
        if ($raw !== '') {
            return substr(trim($raw), 0, 500);
        }

        return 'Hub TTS request failed';
    }

    /**
     * @param mixed $payload
     * @return array<string, mixed>|null
     */
    private static function sanitize_log_payload($payload): ?array {
        if (!is_array($payload)) {
            return null;
        }
        $copy = $payload;
        if (isset($copy['base64']) && is_string($copy['base64'])) {
            $copy['base64'] = '[omitted ' . strlen($copy['base64']) . ' chars]';
        }

        return $copy;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{base64: string, mime: string, engine: string, text: string}|null
     */
    private static function normalize_hub_audio_response(array $body, string $fallback_text): ?array {
        $mime = isset($body['mime']) ? trim((string) $body['mime']) : 'audio/mpeg';
        if ($mime === '') {
            $mime = 'audio/mpeg';
        }

        $engine = isset($body['engine']) ? trim((string) $body['engine']) : 'xabia_hub';
        $spoken = isset($body['text']) ? trim((string) $body['text']) : $fallback_text;

        $b64 = isset($body['base64']) ? trim((string) $body['base64']) : '';
        if ($b64 !== '') {
            return [
                'base64' => $b64,
                'mime'   => $mime,
                'engine' => $engine !== '' ? $engine : 'xabia_hub',
                'text'   => $spoken !== '' ? $spoken : $fallback_text,
            ];
        }

        $audioUrl = isset($body['audio_url']) ? trim((string) $body['audio_url']) : '';
        if ($audioUrl === '' || !wp_http_validate_url($audioUrl)) {
            return null;
        }

        $resp = wp_remote_get($audioUrl, ['timeout' => 45]);
        if (is_wp_error($resp)) {
            self::$last_error = [
                'code'    => 'audio_url_fetch_failed',
                'message' => $resp->get_error_message(),
                'url'     => $audioUrl,
            ];
            error_log('Xabia Hub TTS Error: audio_url fetch failed ' . $resp->get_error_message());
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($resp);
        if ($status !== 200) {
            self::$last_error = [
                'code'    => 'audio_url_http_' . $status,
                'message' => 'No se pudo descargar audio_url del Hub',
                'url'     => $audioUrl,
                'body'    => substr((string) wp_remote_retrieve_body($resp), 0, 500),
            ];
            error_log('Xabia Hub TTS Error: audio_url HTTP ' . $status . ' body=' . substr((string) wp_remote_retrieve_body($resp), 0, 500));
            return null;
        }

        $bin = (string) wp_remote_retrieve_body($resp);
        if ($bin === '' || strncmp($bin, '{', 1) === 0) {
            return null;
        }

        $remoteMime = (string) wp_remote_retrieve_header($resp, 'content-type');
        if ($remoteMime !== '') {
            $mime = strtok($remoteMime, ';') ?: $mime;
        }

        return [
            'base64' => base64_encode($bin),
            'mime'   => $mime,
            'engine' => $engine !== '' ? $engine : 'xabia_hub',
            'text'   => $spoken !== '' ? $spoken : $fallback_text,
        ];
    }
}

endif;
