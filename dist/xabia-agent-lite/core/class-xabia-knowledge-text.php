<?php
/**
 * Límites y reglas de texto para RAG (content_chunk + embeddings).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Knowledge_Text {

    /** Máximo total guardado por fila (sincronización). */
    public const CHUNK_MAX_CHARS = 12000;

    /** Máximo enviado al modelo de embeddings (cobro). */
    public const EMBEDDING_MAX_CHARS = 5000;

    /** Máximo por campo dentro del chunk. */
    public const FIELD_MAX_CHARS = 2500;

    /**
     * ¿Incluir columna en content_chunk cuando no hay mapeo explícito?
     */
    public static function default_import_rag_for_column(string $col, string $visual_role = 'none'): bool {
        $role = strtolower(trim($visual_role));
        if ($role === 'img' || $role === 'logotipo') {
            return false;
        }
        $c = trim($col);
        if ($c === '' || strtoupper($c) === 'ID') {
            return false;
        }
        if ($c[0] === '@') {
            return false;
        }
        if (preg_match('/^@?empresa_img/i', $c)) {
            return false;
        }
        if (preg_match('/@(empresa_)?(logo|img)/i', $c)) {
            return false;
        }
        if (preg_match('/_(logo|img_\d{1,2})$/i', $c)) {
            return false;
        }
        if (preg_match('/^post_(author|date|date_gmt|modified|modified_gmt|status|password|mime_type|parent|guid|menu_order|type|content_filtered|ping_status|comment_status)/i', $c)) {
            return false;
        }
        if (preg_match('/^cliente_\d+$/i', $c) || preg_match('/^testimonio_\d+$/i', $c)) {
            return false;
        }

        return (bool) apply_filters('xabia_default_import_rag_for_column', true, $c, $visual_role);
    }

    /**
     * Respeta import_rag del mapeador; si no existe, aplica heurística por columna/rol.
     *
     * @param array<string, mixed> $attr
     */
    public static function attribute_imports_for_rag(array $attr): bool {
        if (array_key_exists('import_rag', $attr)) {
            return (int) $attr['import_rag'] !== 0;
        }
        $col = (string) ($attr['csv_col'] ?? '');
        $role = (string) ($attr['visual_role'] ?? 'none');

        return self::default_import_rag_for_column($col, $role);
    }

    public static function clean_field_value(string $text): string {
        $text = strip_tags($text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim((string) $text);
    }

    public static function limit_field_value(string $text, int $max = 0): string {
        if ($max <= 0) {
            $max = self::FIELD_MAX_CHARS;
        }
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strlen') && mb_strlen($text) > $max) {
            return mb_substr($text, 0, max(1, $max - 1)) . '…';
        }
        if (strlen($text) > $max) {
            return substr($text, 0, max(1, $max - 1)) . '…';
        }

        return $text;
    }

    public static function finalize_content_chunk(string $blob): string {
        $blob = trim(preg_replace('/\s+/u', ' ', $blob));
        if ($blob === '') {
            return '';
        }
        $max = (int) apply_filters('xabia_knowledge_chunk_max_chars', self::CHUNK_MAX_CHARS);
        if ($max < 500) {
            $max = self::CHUNK_MAX_CHARS;
        }
        if (function_exists('mb_strlen') && mb_strlen($blob) > $max) {
            return mb_substr($blob, 0, max(1, $max - 1)) . '…';
        }
        if (strlen($blob) > $max) {
            return substr($blob, 0, max(1, $max - 1)) . '…';
        }

        return $blob;
    }

    /** Texto final enviado a la API de embeddings. */
    public static function embedding_input(string $text): string {
        $text = self::clean_field_value($text);
        if ($text === '') {
            return '';
        }
        $max = (int) apply_filters('xabia_embedding_max_chars', self::EMBEDDING_MAX_CHARS);
        if ($max < 500) {
            $max = self::EMBEDDING_MAX_CHARS;
        }
        if (function_exists('mb_strlen') && mb_strlen($text) > $max) {
            return mb_substr($text, 0, max(1, $max - 1)) . '…';
        }
        if (strlen($text) > $max) {
            return substr($text, 0, max(1, $max - 1)) . '…';
        }

        return $text;
    }
}
