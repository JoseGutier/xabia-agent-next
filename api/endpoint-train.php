<?php
/**
 * Xabia Agent — endpoint-train.php (v3.9 PRO)
 *
 * Entrenamiento completo:
 *  1) Leer todas las fuentes CSV (manager.php)
 *  2) Parse → entities
 *  3) Guardar base.json
 *  4) Generar embeddings.json
 */

if (!defined('ABSPATH')) exit;

require_once XABIA_CORE_DIR    . 'core.php';
require_once XABIA_CORE_DIR    . 'embeddings.php';
require_once XABIA_SOURCES_DIR . 'manager.php';
require_once XABIA_SOURCES_DIR . 'parser.php';

/* =====================================================
 * REGISTRO (llamado por Xabia_API::register_all)
 * ===================================================== */
function xabia_register_train_endpoint() {

    register_rest_route('xabi/v1', '/train', [
        'methods'  => 'POST',
        'callback' => 'xabia_api_train_full',

        // 🔐 PROTECCIÓN NORMAL
        //'permission_callback' => fn() => current_user_can('manage_options'),

        // 🧪 DESARROLLO (sin login)
        'permission_callback' => '__return_true',
    ]);

    error_log("[Xabia] TRAIN endpoint registered");
}


/* =====================================================
 * CONTROLADOR PRINCIPAL
 * ===================================================== */
function xabia_api_train_full(WP_REST_Request $req) {

    $uploads = wp_upload_dir();
    $dir     = trailingslashit($uploads['basedir']) . 'xabia/';

    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }

    /* ========================================================
     * 1) Cargar CSV en bruto
     * ======================================================== */
    $raw = xabia_sources_load_all_raw();
    if (empty($raw)) {
        return new WP_REST_Response([
            'ok' => false,
            'error' => 'No se pudo cargar ninguna fuente (CSV vacío o ausente).'
        ], 500);
    }

    /* ========================================================
     * 2) Aplanar filas
     * ======================================================== */
    $flat = xabia_sources_flatten_rows($raw);
    if (empty($flat)) {
        return new WP_REST_Response([
            'ok' => false,
            'error' => 'No hay filas válidas en los CSV.'
        ], 500);
    }

    /* ========================================================
     * 3) Parsear → entidades normalizadas
     * ======================================================== */
    $entities = xabia_parse_records($flat, 'auto', 'empresa');
    if (empty($entities)) {
        return new WP_REST_Response([
            'ok' => false,
            'error' => 'Parser devolvió 0 entidades.'
        ], 500);
    }

    /* ========================================================
     * 4) Guardar BASE.JSON
     * ======================================================== */
    $base_file = $dir . 'base.json';
    $base = [
        'count'     => count($entities),
        'fecha'     => date('Y-m-d H:i:s'),
        'registros' => $entities,
    ];

    file_put_contents($base_file, json_encode($base, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    /* ========================================================
     * 5) Generar EMBEDDINGS
     * ======================================================== */
    $embed = xabia_train_embeddings([
        'entities' => $entities,
        'dir'      => $dir
    ]);

    if (is_wp_error($embed)) {
        return new WP_REST_Response([
            'ok'    => false,
            'error' => $embed->get_error_message()
        ], 500);
    }

    // 🟡 cache
    if (!empty($embed['cached'])) {
        return new WP_REST_Response([
            'ok'        => true,
            'cached'    => true,
            'message'   => 'Embeddings ya estaban generados (cache).',
            'base'      => $base_file,
            'embeddings'=> $dir . 'embeddings.json',
            'total'     => count($entities),
        ], 200);
    }

    // 🟢 entrenamiento nuevo
    if (empty($embed['count'])) {
        return new WP_REST_Response([
            'ok'    => false,
            'error' => 'No se pudieron generar embeddings.'
        ], 500);
    }

    return new WP_REST_Response([
        'ok'        => true,
        'message'   => 'Entrenamiento completado correctamente.',
        'base'      => $base_file,
        'embeddings'=> $dir . 'embeddings.json',
        'total'     => count($entities),
    ], 200);
}