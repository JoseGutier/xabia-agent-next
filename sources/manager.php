<?php
/**
 * Xabia Sources — MANAGER v3.7 FINAL + KEY SANITIZER
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/sanitizer.php';   // ⬅️ Necesario para sanitize_csv_rows()

/**
 * Cargar loaders específicos por tipo (csv, db_schema, woo, mec…)
 */
$type_files = glob(__DIR__ . '/types/*.php');
if (is_array($type_files)) {
    foreach ($type_files as $typefile) {
        if (is_readable($typefile)) {
            require_once $typefile;
        }
    }
}

/* ============================================================
 * 1️⃣ REGISTRO DE FUENTES
 * ============================================================ */
function xabia_get_registered_sources(): array {

    $uploads = wp_upload_dir();
    if (empty($uploads['basedir'])) {
        error_log('[Xabia Sources] ❌ wp_upload_dir() sin basedir.');
        return [];
    }

    $dir  = trailingslashit($uploads['basedir']) . 'xabia/';
    $json = $dir . 'sources.json';

    $sources = [];

    /* A) sources.json */
    if (file_exists($json)) {

        $raw = file_get_contents($json);
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {

            foreach ($decoded as &$s) {

                if (!is_array($s)) continue;

                $id     = sanitize_title($s['id'] ?? '');
                $type   = $s['type']   ?? 'csv';
                $label  = $s['label']  ?? ($s['path'] ?? $id);
                $path   = $s['path']   ?? '';
                $entity = $s['entity'] ?? 'empresa';
                $active = isset($s['active']) ? (bool)$s['active'] : true;

                $s = [
                    'id'      => $id,
                    'type'    => $type,
                    'label'   => $label,
                    'path'    => $path,
                    'entity'  => $entity,
                    'active'  => $active,
                    'options' => $s['options'] ?? [
                        'entity' => $entity,
                    ],
                ];
            }

            $sources = array_values(
                array_filter($decoded, fn($x) => is_array($x) && !empty($x['id']))
            );
        }
    }

    /* B) Autodetección CSV */
    if (empty($sources) && is_dir($dir)) {

        $csvfiles = glob($dir . '*.{csv,CSV}', GLOB_BRACE) ?: [];

        foreach ($csvfiles as $csv) {

            if (!is_readable($csv)) continue;

            $basename = basename($csv);
            $id       = sanitize_title(str_replace(['.csv', '.CSV'], '', $basename));

            $sources[] = [
                'id'      => $id,
                'type'    => 'csv',
                'label'   => $basename,
                'path'    => $basename,
                'entity'  => 'empresa',
                'active'  => true,
                'options' => [
                    'entity' => 'empresa',
                ],
            ];
        }
    }

    return $sources;
}

/* ============================================================
 * 2️⃣ CARGAR FILAS CRUDAS DE TODAS LAS FUENTES
 * ============================================================ */
function xabia_sources_load_all_raw(): array {

    $sources = xabia_get_registered_sources();
    $all     = [];

    if (empty($sources)) return [];

    foreach ($sources as $source) {

        if (empty($source['active'])) continue;

        $id   = $source['id']   ?? 'sin_id';
        $type = $source['type'] ?? 'csv';
        $rows = [];

        switch ($type) {

            case 'db_schema':
                if (function_exists('xabia_sources_load_db_schema')) {
                    $rows = xabia_sources_load_db_schema($source);
                }
                break;

            case 'csv':
            default:
                $rows = xabia_sources_load_csv($source);

                /* ********************************************
                 * ➕ NUEVO: SANITIZAR CLAVES CSV
                 * ******************************************** */
                if (!empty($rows) && function_exists('xabia_sanitize_csv_rows')) {
                    $rows = xabia_sanitize_csv_rows($rows);
                }
                /* ******************************************** */

                break;
        }

        if (!empty($rows)) {
            $all[$id] = [
                'def'  => $source,
                'rows' => $rows,
            ];
        }
    }

    return $all;
}

/* ============================================================
 * 3️⃣ FLATTEN (DEBUG)
 * ============================================================ */
function xabia_sources_flatten_rows(array $raw): array {

    $flat = [];

    foreach ($raw as $entry) {
        if (!isset($entry['rows']) || !is_array($entry['rows'])) continue;

        foreach ($entry['rows'] as $row) {
            if (is_array($row)) $flat[] = $row;
        }
    }

    error_log('[Xabia] 🔄 Aplanado final: ' . count($flat) . ' filas.');
    return $flat;
}