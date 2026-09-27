<?php
/**
 * Recorrido medible de un mensaje de chat.
 * Cada mark() cierra el tramo anterior. durations_ms() es el tiempo entre nodos.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Chat_Pipeline {

    /** @var array<string, float> */
    private static $marks = [];

    /** @var list<string> */
    private static $order = [];

    private static $first_token_at = 0.0;

    public static function begin(): void {
        if (self::$marks !== []) {
            return;
        }
        self::$marks = ['start' => microtime(true)];
        self::$order = ['start'];
        self::$first_token_at = 0.0;
    }

    public static function reset(): void {
        self::$marks = [];
        self::$order = [];
        self::$first_token_at = 0.0;
    }

    public static function note_first_token(): void {
        if (self::$first_token_at > 0.0) {
            return;
        }
        if (self::$marks === []) {
            self::begin();
        }
        self::$first_token_at = microtime(true);
    }

    public static function mark(string $stage): void {
        $stage = preg_replace('/[^a-z0-9_]/', '', strtolower($stage));
        if (!is_string($stage) || $stage === '' || $stage === 'start') {
            return;
        }
        if (self::$marks === []) {
            self::begin();
        }
        if (isset(self::$marks[$stage])) {
            return;
        }
        self::$marks[$stage] = microtime(true);
        self::$order[] = $stage;
    }

    /**
     * @return array<string, int>
     */
    public static function durations_ms(): array {
        $out = [];
        $prev = null;
        $prev_name = '';
        foreach (self::$order as $name) {
            if (!isset(self::$marks[$name])) {
                continue;
            }
            $at = self::$marks[$name];
            if ($prev !== null && $prev_name !== '') {
                $out[$prev_name . '_to_' . $name] = (int) round(($at - $prev) * 1000);
            }
            $prev = $at;
            $prev_name = $name;
        }
        if (isset(self::$marks['start'])) {
            $out['total'] = (int) round((microtime(true) - self::$marks['start']) * 1000);
        }

        return $out;
    }

    /**
     * Milisegundos de cada fase, más el instante del primer token si hubo stream.
     *
     * @return array{nonce:int, route:int, rewrite:int, retrieve:int, generate:int, total:int, first_token:int}
     */
    public static function phase_ms(): array {
        $phases = [
            'nonce'    => 0,
            'route'    => 0,
            'rewrite'  => 0,
            'retrieve' => 0,
            'generate' => 0,
        ];
        $prev = null;
        foreach (self::$order as $name) {
            if (!isset(self::$marks[$name])) {
                continue;
            }
            $at = self::$marks[$name];
            if ($prev !== null && array_key_exists($name, $phases)) {
                $phases[$name] = (int) round(($at - $prev) * 1000);
            }
            $prev = $at;
        }
        $phases['total'] = isset(self::$marks['start'])
            ? (int) round((microtime(true) - self::$marks['start']) * 1000)
            : 0;
        $phases['first_token'] = (self::$first_token_at > 0.0 && isset(self::$marks['start']))
            ? (int) round((self::$first_token_at - self::$marks['start']) * 1000)
            : 0;

        return $phases;
    }

    public static function debug_line(): string {
        $p = self::phase_ms();

        return sprintf(
            '[XABIA_PIPELINE] nonce=%d route=%d rewrite=%d retrieve=%d generate=%d total=%d first_token=%d',
            $p['nonce'],
            $p['route'],
            $p['rewrite'],
            $p['retrieve'],
            $p['generate'],
            $p['total'],
            $p['first_token']
        );
    }

    public static function write_debug_log(): void {
        static $written = false;
        if ($written) {
            return;
        }
        $written = true;
        $line = self::debug_line();
        error_log($line);
        if (defined('WP_CONTENT_DIR') && is_string(WP_CONTENT_DIR) && WP_CONTENT_DIR !== '') {
            @file_put_contents(
                rtrim(WP_CONTENT_DIR, '/') . '/debug.log',
                gmdate('d-M-Y H:i:s') . ' UTC ' . $line . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
        if (function_exists('xabia_trace')) {
            xabia_trace($line);
        }
    }

    /**
     * @return 'ok'|'missing'|'invalid'|'forbidden'
     */
    public static function nonce_decision(string $nonce, bool $admin_ok, bool $public_ok, bool $can_manage): string {
        if (trim($nonce) === '') {
            return 'missing';
        }
        if ($admin_ok && !$can_manage) {
            return 'forbidden';
        }
        if ($admin_ok || $public_ok) {
            return 'ok';
        }

        return 'invalid';
    }
}
