<?php
/**
 * Xabia Sources — types/csv.php (v3.7 PRO)
 * Lector CSV ultrarobusto:
 * - Limpieza BOM y puntos iniciales (".empresa")
 * - Normalización total de cabeceras (idéntica a parser.php)
 * - Detección sólida de delimitador ; , o tab
 * - Valores saneados y sin comillas extra
 */

if (!defined('ABSPATH')) exit;

/**
 * Carga un CSV y devuelve un array de filas crudas normalizadas.
 */
function xabia_sources_load_csv(array $source): array {

    $path   = $source['path']   ?? '';
    $entity = $source['entity'] ?? 'generic';

    if (!$path) {
        error_log('[Xabia CSV] ⚠ Fuente sin path.');
        return [];
    }

    /* ===============================================
     * 0) Directorio /uploads/xabia/
     * =============================================== */
    $uploads = wp_upload_dir();
    if (empty($uploads['basedir'])) {
        error_log('[Xabia CSV] ❌ wp_upload_dir() sin basedir.');
        return [];
    }

    $dir  = trailingslashit($uploads['basedir']) . 'xabia/';
    $file = $dir . $path;

    if (!file_exists($file)) {
        error_log('[Xabia CSV] ⚠ No existe CSV: ' . $file);
        return [];
    }

    $handle = fopen($file, 'r');
    if (!$handle) {
        error_log('[Xabia CSV] ❌ No se pudo abrir CSV: ' . $file);
        return [];
    }

    /* ===============================================
     * 1) Detectar delimitador robusto
     * =============================================== */
    $sample = fgets($handle, 8192) ?: '';
    $delim_counts = [
        ';'  => substr_count($sample, ';'),
        ','  => substr_count($sample, ','),
        "\t" => substr_count($sample, "\t"),
    ];

    arsort($delim_counts);
    $delimiter = key($delim_counts);

    // fallback por si el sample está vacío
    if (!$delimiter) $delimiter = ';';

    rewind($handle);

    /* ===============================================
     * 2) Cabeceras crudas
     * =============================================== */
    $raw_headers = fgetcsv($handle, 0, $delimiter);
    if (!$raw_headers) {
        fclose($handle);
        error_log('[Xabia CSV] ⚠ CSV sin cabeceras.');
        return [];
    }

    /* ===============================================
     * 3) Normalización EXACTA como en parser.php
     *    - quitar BOM
     *    - quitar puntos iniciales ".empresa"
     *    - sanitize_title (tildes, espacios → guiones)
     * =============================================== */
    $headers = array_map(function($h){

        if ($h === null) return '';

        // Quitar BOM
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);

        // Quitar basura: puntos iniciales, espacios, saltos, null bytes
        $h = ltrim($h, ". \t\n\r\0\x0B");

        // Normalización igual que parser.php
        $h = sanitize_title(strtolower(trim($h)));

        return $h;

    }, $raw_headers);

    /* ===============================================
     * 4) Leer filas y normalizar valores
     * =============================================== */
    $rows = [];

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {

        if (!is_array($row) || $row === [null]) continue;

        $assoc = [];

        foreach ($headers as $i => $key) {

            if ($key === '' || !array_key_exists($i, $row)) {
                continue;
            }

            $val = $row[$i];

            // Eliminar comillas, espacios, BOM
            $val = trim($val);
            $val = trim($val, "\" \t\n\r\0\x0B");
            $val = preg_replace('/^\xEF\xBB\xBF/', '', $val);

            $assoc[$key] = $val;
        }

        if (!empty($assoc)) {
            $assoc['_entity_hint'] = $entity;
            $rows[] = $assoc;
        }
    }

    fclose($handle);

    error_log(
        '[Xabia CSV] ✅ ' .
        count($rows) .
        ' filas extraídas de ' . basename($file) .
        ' (delimiter="' . $delimiter . '")'
    );

    return $rows;
}