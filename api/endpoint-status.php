<?php
/**
 * Xabia — endpoint-status.php (v3 PRO)
 */

if (!defined('ABSPATH')) exit;

/**
 * Nueva función requerida por class-xabia-api.php
 * Registra el endpoint utilizando la misma lógica que ya tenías.
 */
function xabia_register_status_endpoint() {

    register_rest_route('xabi/v1', '/status', [
        'methods'             => 'GET',
        'callback'            => 'xabia_handle_status_pro',
        'permission_callback' => '__return_true',
    ]);

    error_log("[Xabia] STATUS endpoint registered");
}

/**
 * Callback original — NO SE TOCA.
 */
function xabia_handle_status_pro(WP_REST_Request $request) {

    $uploads = wp_upload_dir();
    $dir     = trailingslashit($uploads['basedir']) . 'xabia/';

    $base_file = $dir . 'base.json';
    $emb_file  = $dir . 'embeddings.json';

    $base_info = [
        'exists'  => file_exists($base_file),
        'updated' => file_exists($base_file) ? date('Y-m-d H:i:s', filemtime($base_file)) : null,
        'count'   => 0,
    ];

    if ($base_info['exists']) {
        $j = json_decode(file_get_contents($base_file), true);

        if (is_array($j) && isset($j[0]['empresa'])) {
            $base_info['count'] = count($j);
        } elseif (isset($j['registros']) && is_array($j['registros'])) {
            $base_info['count'] = count($j['registros']);
        }
    }

    $emb_info = [
        'exists'  => file_exists($emb_file),
        'count'   => 0,
        'updated' => file_exists($emb_file) ? date('Y-m-d H:i:s', filemtime($emb_file)) : null,
        'model'   => null,
    ];

    if ($emb_info['exists']) {
        $j = json_decode(file_get_contents($emb_file), true);
        if (isset($j['count'])) $emb_info['count'] = $j['count'];
        if (isset($j['model'])) $emb_info['model'] = $j['model'];
    }

    return new WP_REST_Response([
        'ok'        => true,
        'version'   => XABIA_PLUGIN_VERSION,
        'php'       => PHP_VERSION,
        'wp'        => get_bloginfo('version'),
        'base'      => $base_info,
        'embeddings'=> $emb_info,
    ], 200);
}