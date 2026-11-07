<?php
/**
 * Coincidencia léxica de catálogo por término completo (límite de palabra Unicode).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Catalog_Token_Match {

    public static function normalize_text(string $text): string {
        $text = trim(wp_strip_all_tags($text));
        if ($text === '') {
            return '';
        }

        return mb_strtolower($text, 'UTF-8');
    }

    /**
     * true si $term aparece como palabra/token delimitado en $message (no substring invertida).
     */
    public static function message_contains_whole_term(string $message, string $term): bool {
        $message = self::normalize_text($message);
        $term = self::normalize_text($term);
        if ($message === '' || $term === '') {
            return false;
        }
        $quoted = preg_quote($term, '/');
        if ($quoted === '') {
            return false;
        }
        $pattern = '/(?:^|[\s\p{P}\p{Z}])' . $quoted . '(?:$|[\s\p{P}\p{Z}])/iu';
        if (@preg_match($pattern, ' ' . $message . ' ') === 1) {
            return true;
        }
        // Mensaje de una sola palabra.
        if ($message === $term) {
            return true;
        }

        return false;
    }

    /**
     * Tokens de la consulta (sin listas estáticas de stopwords en Core).
     *
     * @return list<string>
     */
    public static function query_tokens(string $message): array {
        $message = self::normalize_text($message);
        if ($message === '') {
            return [];
        }
        $tokens = [];
        foreach (preg_split('/\s+/u', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
            $tok = trim((string) preg_replace('/^[\p{P}\p{Z}]+|[\p{P}\p{Z}]+$/u', '', $tok));
            if ($tok === '') {
                continue;
            }
            $tokens[] = $tok;
        }

        return array_values(array_unique($tokens));
    }
}
