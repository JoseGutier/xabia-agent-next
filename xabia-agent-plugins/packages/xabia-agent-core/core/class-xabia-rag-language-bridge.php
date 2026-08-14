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

        $variants = array_values(array_unique(array_filter($variants, static function (string $v): bool {
            return mb_strlen($v, 'UTF-8') >= 2;
        })));

        return (array) apply_filters('xabia_rag_token_variants', $variants, $token);
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
        }

        return false;
    }
}
