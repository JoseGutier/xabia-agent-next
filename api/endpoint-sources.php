<?php
/**
 * Xabia API — endpoint-sources.php (v3.2 UNIFICADO)
 * Actualiza base.json con el formato estándar Xabia v3.
 */

if (!defined('ABSPATH')) exit;

/* =====================================================
 * REGISTRO ENDPOINT — llamado desde Xabia_API::register_all()
 * ===================================================== */
function xabia_register_sources_endpoint() {

    register_rest_route('xabi/v1', '/sources', [
        'methods'  => 'POST',
        'callback' => 'xabia_api_update_sources_v32',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);

    error_log("[Xabia] SOURCES endpoint registered");
}


/* =====================================================
 * CONTROLADOR PRINCIPAL
 * ===================================================== */
function xabia_api_update_sources_v32(WP_REST_Request $request) {

    if (!function_exists('xabia_load_sources_data')) {
        return new WP_REST_Response([
            'ok'=>false,
            'error'=>'El módulo de fuentes no está disponible.'
        ],500);
    }

    /* 1️⃣ Cargar crudo */
    $raw = xabia_load_sources_data();
    $raw_count = is_array($raw) ? count($raw) : 0;

    /* 2️⃣ Normalizar entidades */
    if (!function_exists('xabia_normalize_entities')) {
        return new WP_REST_Response(['ok'=>false,'error'=>'Falta parser/normalizer.'],500);
    }

    $entities = xabia_normalize_entities($raw);
    $norm_count = count($entities);

    error_log("[Xabia Sources] 🧠 Normalizadas {$norm_count} de {$raw_count} entradas.");

    if (empty($entities)) {
        return new WP_REST_Response([
            'ok'=>false,
            'error'=>'No hay entidades válidas tras normalizar.'
        ],400);
    }

    /* 3️⃣ Guardar base.json (formato estándar v3.2) */
    if (!function_exists('xabia_path')) {
        return new WP_REST_Response(['ok'=>false,'error'=>'Falta xabia_path()'],500);
    }

    $base_file = xabia_path('base.json');

    $json = [
        'fecha'     => current_time('mysql'),
        'count'     => $norm_count,
        'registros' => $entities,
    ];

    file_put_contents($base_file, wp_json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    error_log("[Xabia Sources] ✅ base.json actualizado: {$norm_count} registros.");

    return new WP_REST_Response([
        'ok'      => true,
        'mensaje' => "Base actualizada correctamente ({$norm_count} registros).",
        'archivo' => basename($base_file),
        'total'   => $norm_count
    ],200);
}