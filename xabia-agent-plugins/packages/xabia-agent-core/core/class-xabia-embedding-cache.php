<?php
/**
 * Caché de embeddings de consulta (mismo texto + modelo → mismo vector).
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Xabia_Embedding_Cache')) :

class Xabia_Embedding_Cache {

    /** @var array<string, array<int, float>> */
    private static $runtime = [];

    private const DEFAULT_TTL = 2592000; // 30 días

    public static function transient_key(string $model_name, string $text): string {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower(trim($text)) : strtolower(trim($text));

        return 'xabia_emb_' . md5($model_name . '_' . $normalized);
    }

    /**
     * @return array<int, float>|null
     */
    public static function get(string $model_name, string $text): ?array {
        $model_name = trim($model_name);
        if ($model_name === '' || trim($text) === '') {
            return null;
        }
        $key = self::transient_key($model_name, $text);
        if (isset(self::$runtime[$key])) {
            return self::$runtime[$key];
        }
        if (!function_exists('get_transient')) {
            return null;
        }
        $cached = get_transient($key);
        $vector = self::normalize_vector($cached);
        if ($vector === null) {
            return null;
        }
        self::$runtime[$key] = $vector;

        return $vector;
    }

    /**
     * @param array<int, float> $vector
     */
    public static function set(string $model_name, string $text, array $vector): void {
        $model_name = trim($model_name);
        $vector = self::normalize_vector($vector);
        if ($model_name === '' || trim($text) === '' || $vector === null) {
            return;
        }
        $key = self::transient_key($model_name, $text);
        self::$runtime[$key] = $vector;
        if (!function_exists('set_transient')) {
            return;
        }
        $ttl = (int) apply_filters('xabia_query_embedding_cache_ttl', self::DEFAULT_TTL, $model_name, $text);
        if ($ttl < 1) {
            $ttl = self::DEFAULT_TTL;
        }
        set_transient($key, $vector, $ttl);
    }

    /**
     * @param mixed $value
     * @return array<int, float>|null
     */
    private static function normalize_vector($value): ?array {
        if (!is_array($value) || $value === []) {
            return null;
        }
        $out = [];
        foreach ($value as $v) {
            if (!is_numeric($v)) {
                return null;
            }
            $out[] = (float) $v;
        }

        return $out === [] ? null : $out;
    }
}

endif;
