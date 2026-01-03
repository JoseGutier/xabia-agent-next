<?php
/**
 * Xabia Sources — sanitizer.php
 * - Limpieza y normalización de texto (como antes)
 * - Normalización total de claves de CSV (nuevo)
 */

if (!defined('ABSPATH')) exit;

/* =====================================================
 * A) NORMALIZACIÓN DE TEXTO (VERSIÓN ORIGINAL)
 * ===================================================== */

/**
 * Normaliza espacios en blanco: colapsa espacios múltiples, limpia saltos de línea.
 */
function xabia_normalize_whitespace(string $text): string {

    // Normalizar saltos de línea
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    // Quitar espacios al principio de cada línea
    $lines = array_map('trim', explode("\n", $text));
    $text  = implode("\n", $lines);

    // Colapsar espacios múltiples
    $text  = preg_replace('/[ \t]+/', ' ', $text);

    // Quitar líneas vacías repetidas
    $text  = preg_replace("/\n{3,}/", "\n\n", $text);

    return trim($text);
}

/**
 * Limpia HTML, entidades y limita longitud.
 */
function xabia_sanitize_text(string $text, int $max_len = 8000): string {

    // Decode entidades HTML
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Elimina etiquetas
    $text = wp_strip_all_tags($text, true);

    // Normalizar espacios
    $text = xabia_normalize_whitespace($text);

    // Limitar longitud
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') > $max_len) {
            $text = mb_substr($text, 0, $max_len, 'UTF-8') . '…';
        }
    } else {
        if (strlen($text) > $max_len) {
            $text = substr($text, 0, $max_len) . '…';
        }
    }

    return $text;
}


/* =====================================================
 * B) NORMALIZACIÓN DE CLAVES DE CSV (NUEVO)
 * ===================================================== */

/**
 * Sanitiza una clave de columna (nombre del campo CSV).
 * Garantiza claves estables y seguras:
 * - minúsculas
 * - guiones → guiones bajos
 * - quitar acentos
 * - quitar caracteres raros
 * - collapse múltiple underscores
 * - trim
 * - evitar claves vacías
 */
function xabia_sanitize_csv_key(string $key): string {

    // 1) Eliminar BOM si existe
    $key = ltrim($key, "\xEF\xBB\xBF");

    // 2) Minúsculas
    $key = mb_strtolower($key);

    // 3) Guiones → guiones bajos
    $key = str_replace('-', '_', $key);

    // 4) Quitar acentos
    if (function_exists('remove_accents')) {
        $key = remove_accents($key);
    }

    // 5) Reemplazar caracteres raros por "_"
    $key = preg_replace('/[^a-z0-9_]+/u', '_', $key);

    // 6) Normalizar underscores múltiples
    $key = preg_replace('/_+/', '_', $key);

    // 7) Recortar underscores sobrantes
    $key = trim($key, '_');

    // 8) Garantizar que no quede vacío
    if ($key === '') {
        $key = 'col_' . wp_generate_password(6, false, false);
    }

    return $key;
}


/**
 * Sanitiza todas las claves de un CSV.
 * Recibe un array de filas (array associativo cada fila).
 */
function xabia_sanitize_csv_rows(array $rows): array {

    $out = [];

    foreach ($rows as $row) {
        if (!is_array($row)) continue;

        $clean_row = [];

        foreach ($row as $k => $v) {
            $k2 = xabia_sanitize_csv_key((string)$k);
            $clean_row[$k2] = $v;
        }

        $out[] = $clean_row;
    }

    return $out;
}