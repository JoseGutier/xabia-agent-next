<?php
/**
 * Puente agnóstico para rescate RAG multilingüe (local, sin APIs externas).
 * Normaliza acrónimos y sufijos aglutinantes frecuentes (p. ej. EOJak → EOJ).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Rag_Language_Bridge {

    /**
     * @return list<string>
     */
    public static function agglutinative_suffixes(): array {
        $suffixes = ['ak', 'ek', 'ik', 'ok', 'rk', 'tik', 'raino', 'ko', 'ren'];

        return array_values(array_filter(array_map(
            static function ($s) {
                $s = mb_strtolower(trim((string) $s), 'UTF-8');

                return mb_strlen($s, 'UTF-8') >= 1 ? $s : '';
            },
            (array) apply_filters('xabia_rag_agglutinative_suffixes', $suffixes)
        )));
    }

    /**
     * Variantes de un token para búsqueda léxica / refuerzo (acrónimos, sufijos).
     *
     * @return list<string>
     */
    public static function token_variants(string $token): array {
        $token = trim((string) $token);
        if ($token === '') {
            return [];
        }

        $lower = mb_strtolower($token, 'UTF-8');
        $variants = [$lower];

        if (preg_match('/^[\p{Lu}]{2,12}$/u', $token)) {
            $variants[] = $lower;
        }

        foreach (self::agglutinative_suffixes() as $suffix) {
            $suf_len = mb_strlen($suffix, 'UTF-8');
            if ($suf_len < 1 || mb_strlen($lower, 'UTF-8') <= $suf_len + 1) {
                continue;
            }
            if (mb_substr($lower, -$suf_len, null, 'UTF-8') === $suffix) {
                $stem = mb_substr($lower, 0, mb_strlen($lower, 'UTF-8') - $suf_len, 'UTF-8');
                if (mb_strlen($stem, 'UTF-8') >= 2) {
                    $variants[] = $stem;
                }
            }
        }

        foreach (self::romance_inflection_variants($lower) as $inflected) {
            $variants[] = $inflected;
        }

        $variants = array_values(array_unique(array_filter($variants, static function (string $v): bool {
            return mb_strlen($v, 'UTF-8') >= 2;
        })));

        return (array) apply_filters('xabia_rag_token_variants', $variants, $token);
    }

    /**
     * Flexión románica agnóstica (género/número): urbano↔urbana↔urbanos↔urbanas.
     * No traduce ni añade sinónimos de cliente; solo terminaiones -o/-a/-os/-as/-e/-es.
     *
     * @return list<string>
     */
    public static function romance_inflection_variants(string $token): array {
        $token = mb_strtolower(trim($token), 'UTF-8');
        $len = mb_strlen($token, 'UTF-8');
        if ($len < 5) {
            return [];
        }

        $out = [];
        $ends = static function (string $suf) use ($token, $len): bool {
            $n = mb_strlen($suf, 'UTF-8');

            return $len > $n && mb_substr($token, -$n, null, 'UTF-8') === $suf;
        };
        $stem = static function (string $suf) use ($token, $len): string {
            $n = mb_strlen($suf, 'UTF-8');

            return mb_substr($token, 0, $len - $n, 'UTF-8');
        };

        // Sustantivos / sufijos que no flexionan en género como adjetivos (evita entorno→entorna).
        $stem_blocked = static function (string $base): bool {
            return (bool) preg_match(
                '/(?:orn|ism|amient|imient|acion|asion|ción|sión|tud|umbre|azgo|erio|ería|aje)$/u',
                $base
            );
        };

        if ($ends('os') || $ends('as')) {
            $base = $stem(mb_substr($token, -2, null, 'UTF-8'));
            if (mb_strlen($base, 'UTF-8') >= 4 && !$stem_blocked($base)) {
                $out[] = $base . 'o';
                $out[] = $base . 'a';
                $out[] = $base . 'os';
                $out[] = $base . 'as';
            }
        } elseif ($ends('es') && !$ends('nes')) {
            $base = $stem('es');
            if (mb_strlen($base, 'UTF-8') >= 4 && !$stem_blocked($base)
                && !preg_match('/(?:cion|sión|sion|dad|tad)$/u', $base)) {
                $out[] = $base . 'e';
                $out[] = $base . 'es';
            }
        } elseif ($ends('o') || $ends('a')) {
            $base = $stem(mb_substr($token, -1, null, 'UTF-8'));
            if (mb_strlen($base, 'UTF-8') >= 4 && !$stem_blocked($base)) {
                $out[] = $base . 'o';
                $out[] = $base . 'a';
                $out[] = $base . 'os';
                $out[] = $base . 'as';
            }
        } elseif ($ends('e')) {
            $base = $stem('e');
            if (mb_strlen($base, 'UTF-8') >= 4 && !$stem_blocked($base)) {
                $out[] = $base . 'e';
                $out[] = $base . 'es';
            }
        }

        $out = array_values(array_unique(array_filter($out, static function (string $v) use ($token): bool {
            return $v !== $token && mb_strlen($v, 'UTF-8') >= 4;
        })));

        return (array) apply_filters('xabia_rag_romance_inflection_variants', $out, $token);
    }

    /**
     * Expande agujas RAG con variantes morfológicas (sin traducción externa).
     *
     * @param list<string> $needles
     * @return list<string>
     */
    public static function expand_keyword_needles(array $needles, string $source_text = ''): array {
        $out = [];
        foreach ($needles as $needle) {
            $needle = mb_strtolower(trim((string) $needle), 'UTF-8');
            if ($needle === '') {
                continue;
            }
            $out[] = $needle;
            foreach (self::token_variants($needle) as $variant) {
                $out[] = $variant;
            }
        }

        $raw = trim(wp_strip_all_tags($source_text));
        if ($raw !== '' && preg_match_all('/\b[\p{Lu}]{2,12}\b/u', $raw, $matches)) {
            foreach ($matches[0] as $acro) {
                foreach (self::token_variants((string) $acro) as $variant) {
                    $out[] = $variant;
                }
            }
        }

        $out = array_values(array_unique(array_filter($out, static function (string $v): bool {
            return mb_strlen($v, 'UTF-8') >= 2;
        })));

        return (array) apply_filters('xabia_rag_expanded_keyword_needles', $out, $needles, $source_text);
    }

    /**
     * Términos extra para embedding / Hub RAG (concatenables al search_term).
     *
     * @return list<string>
     */
    public static function retrieval_term_variants(string $text): array {
        $text = trim(wp_strip_all_tags((string) $text));
        if ($text === '') {
            return [];
        }

        $needles = [];
        if (preg_match_all('/\b[\p{Lu}]{2,12}\b/u', $text, $acro_matches)) {
            foreach ($acro_matches[0] as $acro) {
                foreach (self::token_variants((string) $acro) as $variant) {
                    $needles[] = $variant;
                }
            }
        }

        if (preg_match_all('/\p{L}[\p{L}\p{M}\'-]{1,}/u', mb_strtolower($text, 'UTF-8'), $word_matches)) {
            foreach ($word_matches[0] as $word) {
                $word = trim((string) $word, "'-");
                if (mb_strlen($word, 'UTF-8') >= 4) {
                    $needles[] = $word;
                }
                foreach (self::token_variants($word) as $variant) {
                    $needles[] = $variant;
                }
            }
        }

        $needles = array_values(array_unique(array_filter($needles, static function (string $v): bool {
            return mb_strlen($v, 'UTF-8') >= 2;
        })));

        return (array) apply_filters('xabia_rag_retrieval_term_variants', $needles, $text);
    }

    /**
     * Comprueba si alguna variante del término aparece en el contexto (rescate multilingüe).
     */
    public static function context_contains_term_variant(string $term, string $context): bool {
        $term = mb_strtolower(trim(wp_strip_all_tags($term)), 'UTF-8');
        $context = mb_strtolower((string) $context, 'UTF-8');
        if ($term === '' || $context === '') {
            return false;
        }
        if (mb_strpos($context, $term) !== false) {
            return true;
        }
        foreach (self::token_variants($term) as $variant) {
            if (mb_strpos($context, $variant) !== false) {
                return true;
            }
            if (self::tokens_soft_match($variant, $context)) {
                return true;
            }
        }

        return self::tokens_soft_match($term, $context);
    }

    /**
     * Match léxico agnóstico: substring exacto o prefijo Unicode compartido entre tokens
     * (p. ej. urbano ↔ urbanas). Sin diccionarios de sinónimos.
     *
     * Umbrales filtrables vía {@see 'xabia_rag_token_soft_match'}.
     */
    public static function tokens_soft_match(string $needle, string $haystack): bool {
        $needle = mb_strtolower(trim(wp_strip_all_tags($needle)), 'UTF-8');
        $haystack = mb_strtolower((string) $haystack, 'UTF-8');
        if ($needle === '' || $haystack === '') {
            return false;
        }

        $opts = [
            'min_token_len'   => 4,
            'min_prefix_len'  => 4,
            'prefix_ratio'    => 0.75,
            'max_len_diff'    => 3,
            'max_len_ratio'   => 0.40,
            'strict_ratio'    => 0.80,
        ];
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('xabia_rag_token_soft_match', $opts, $needle, $haystack);
            if (is_array($filtered)) {
                $opts = array_merge($opts, $filtered);
            }
        }
        $min_token = max(3, (int) ($opts['min_token_len'] ?? 4));
        $min_prefix = max(3, (int) ($opts['min_prefix_len'] ?? 4));
        $ratio = (float) ($opts['prefix_ratio'] ?? 0.75);
        if ($ratio < 0.5) {
            $ratio = 0.5;
        }
        if ($ratio > 1.0) {
            $ratio = 1.0;
        }
        $max_len_diff = max(0, (int) ($opts['max_len_diff'] ?? 3));
        $max_len_ratio = (float) ($opts['max_len_ratio'] ?? 0.40);
        if ($max_len_ratio < 0.1) {
            $max_len_ratio = 0.1;
        }
        if ($max_len_ratio > 1.0) {
            $max_len_ratio = 1.0;
        }
        $strict_ratio = (float) ($opts['strict_ratio'] ?? 0.80);
        if ($strict_ratio < 0.5) {
            $strict_ratio = 0.5;
        }
        if ($strict_ratio > 1.0) {
            $strict_ratio = 1.0;
        }

        $needle_len = mb_strlen($needle, 'UTF-8');
        if ($needle_len < $min_token) {
            // Agujas cortas: solo coincidencia de token exacto (evita prefijos espurios).
            if (!preg_match_all('/\p{L}[\p{L}\p{M}\'-]*/u', $haystack, $short_m)) {
                return false;
            }
            foreach ($short_m[0] as $token) {
                if (mb_strtolower(trim((string) $token, "'-"), 'UTF-8') === $needle) {
                    return true;
                }
            }

            return false;
        }

        if (!preg_match_all('/\p{L}[\p{L}\p{M}\'-]{2,}/u', $haystack, $matches)) {
            return false;
        }

        foreach ($matches[0] as $token) {
            $token = trim((string) $token, "'-");
            $token_len = mb_strlen($token, 'UTF-8');
            if ($token_len < $min_token) {
                continue;
            }
            if ($token === $needle) {
                return true;
            }
            // Anti falso positivo: vela ↔ velatorio (substring crudo o raíz con longitud dispar).
            $len_diff = abs($needle_len - $token_len);
            if ($len_diff > $max_len_diff && ($len_diff / max($needle_len, $token_len)) > $max_len_ratio) {
                continue;
            }
            $min_len = min($needle_len, $token_len);
            $need_prefix = max($min_prefix, (int) ceil($strict_ratio * $min_len), (int) floor($ratio * $min_len));
            $common = self::shared_prefix_length($needle, $token);
            if ($common >= $need_prefix) {
                return true;
            }
        }

        return false;
    }

    /**
     * Longitud del prefijo Unicode común entre dos cadenas ya normalizadas.
     */
    public static function shared_prefix_length(string $a, string $b): int {
        $len = min(mb_strlen($a, 'UTF-8'), mb_strlen($b, 'UTF-8'));
        $n = 0;
        for ($i = 0; $i < $len; $i++) {
            if (mb_substr($a, $i, 1, 'UTF-8') !== mb_substr($b, $i, 1, 'UTF-8')) {
                break;
            }
            ++$n;
        }

        return $n;
    }
}
