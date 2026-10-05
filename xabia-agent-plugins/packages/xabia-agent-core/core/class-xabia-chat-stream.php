<?php
/**
 * Salida SSE del chat hacia el widget.
 * El nonce ya se validó. Aquí solo se abren cabeceras cuando llega el primer token.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Chat_Stream {

    public const REST_NAMESPACE = 'xabia/v1';

    public const REST_ROUTE = '/ask';

    private static $armed = false;

    private static $open = false;

    private static $primed = false;

    public static function arm(): void {
        self::$armed = true;
    }

    public static function is_armed(): bool {
        return self::$armed;
    }

    public static function is_open(): bool {
        return self::$open;
    }

    public static function is_primed(): bool {
        return self::$primed;
    }

    public static function mark_primed(): void {
        self::$primed = true;
    }

    /**
     * 1 KB de relleno justo tras las cabeceras, para que LiteSpeed/Nginx abran el stream.
     */
    public static function prime(): void {
        if (self::$primed || self::$open) {
            return;
        }
        self::$primed = true;
        echo ": " . str_repeat(" ", 1024) . "\n\n";
        echo "event: ping\ndata: {}\n\n";
        if (ob_get_level()) {
            @ob_end_flush();
        }
        @flush();
    }

    /**
     * @return array{protocol: string, enabled: bool, route: string, truncated: bool, finish_reason: string, max_tokens: int}
     */
    public static function response_meta(bool $truncated, string $finish_reason, int $max_tokens, bool $enabled = false): array {
        return [
            'protocol'      => 'sse',
            'enabled'       => $enabled,
            'route'         => '/wp-json/' . self::REST_NAMESPACE . self::REST_ROUTE,
            'truncated'     => $truncated,
            'finish_reason' => $finish_reason,
            'max_tokens'    => max(1, $max_tokens),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function encode_event(string $event, array $data): string {
        $event = preg_replace('/[^a-z0-9_]/', '', strtolower($event));
        if (!is_string($event) || $event === '') {
            $event = 'message';
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($data, $flags)
            : json_encode($data, $flags);
        if (!is_string($json)) {
            $json = '{}';
        }

        return 'event: ' . $event . "\ndata: " . $json . "\n\n";
    }

    public static function delta(string $text): void {
        if ($text === '') {
            return;
        }
        if (class_exists('Xabia_Chat_Pipeline', false)) {
            Xabia_Chat_Pipeline::note_first_token();
        }
        self::discard_buffers();
        self::open();
        echo self::encode_event('delta', ['text' => $text]);
        self::flush_buffers();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function complete(array $data): void {
        self::open();
        echo self::encode_event('done', $data);
        self::flush_buffers();
        exit;
    }

    public static function fail(string $message): void {
        self::open();
        echo self::encode_event('error', ['message' => $message]);
        self::flush_buffers();
        exit;
    }

    private static function open(): void {
        if (self::$open) {
            return;
        }
        self::$open = true;
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', 'off');
        self::discard_buffers();
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/event-stream; charset=utf-8');
            header('X-Accel-Buffering: no');
            header('X-LiteSpeed-Cache-Control: no-cache');
            header('Cache-Control: no-cache, no-transform');
            header('Connection: keep-alive');
        }
        if (!self::$primed) {
            self::$primed = true;
            echo ": " . str_repeat(" ", 1024) . "\n\n";
            echo "event: ping\ndata: {}\n\n";
            if (ob_get_level()) {
                @ob_end_flush();
            }
            @flush();
        }
    }

    private static function discard_buffers(): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
    }

    private static function flush_buffers(): void {
        while (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}
