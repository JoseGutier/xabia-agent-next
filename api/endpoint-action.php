<?php
/**
 * Xabia — endpoint-action.php (v4.1 estable)
 * Ejecuta acciones: /xabi/v1/action
 */

if (!defined('ABSPATH')) exit;

/* =====================================================
 * REGISTRO ENDPOINT (llamado desde class-xabia-api.php)
 * ===================================================== */
function xabia_register_action_endpoint() {

    register_rest_route('xabi/v1', '/action', [
        'methods'             => 'POST',
        'callback'            => 'xabia_handle_action',
        'permission_callback' => '__return_true',
    ]);

    error_log("[Xabia] ACTION endpoint registered");
}


/* =====================================================
 * CONTROLADOR PRINCIPAL
 * ===================================================== */
function xabia_handle_action(WP_REST_Request $request) {

    /* -----------------------------
     * 1) NORMALIZAR INPUT
     * ----------------------------- */
$body = json_decode($request->get_body(), true) ?? [];

$action  = isset($body['action'])  ? sanitize_text_field($body['action']) : null;
$payload = isset($body['payload']) && is_array($body['payload'])
    ? $body['payload']
    : [];

    if (!$action) {
        return new WP_REST_Response([
            'ok'    => false,
            'error' => 'Missing action name.',
        ], 400);
    }
    
    /* =====================================================
     * 🔧 NORMALIZADOR DE PAYLOAD (PARCHE OBLIGATORIO)
     * Unifica formatos entre frontend / curl / tests
     * ===================================================== */

    // Teléfono: phone → telefono
    if (isset($payload['phone']) && !isset($payload['telefono'])) {
        $payload['telefono'] = $payload['phone'];
    }

    // Web: url → web
    if (isset($payload['url']) && !isset($payload['web'])) {
        $payload['web'] = $payload['url'];
    }

    /* -----------------------------
     * 2) CARGAR REGISTRO
     * ----------------------------- */
    if (!function_exists('xabia_run_action')) {
        error_log("❌ Xabia: xabia_run_action() no está disponible.");
        return new WP_REST_Response([
            'ok'    => false,
            'error' => 'Action engine not loaded.',
        ], 500);
    }

    /* -----------------------------
     * 3) EJECUTAR ACCIÓN
     * ----------------------------- */
    $result = xabia_run_action($action, $payload);

    /* -----------------------------
     * 4) ERRORES INTERNOS
     * ----------------------------- */
    if (is_wp_error($result)) {
        return new WP_REST_Response([
            'ok'    => false,
            'error' => $result->get_error_message(),
            'code'  => $result->get_error_code(),
        ], 400);
    }

    /* -----------------------------
     * 5) RESPUESTA OK
     * ----------------------------- */
    return new WP_REST_Response([
        'ok'       => true,
        'action'   => $action,
        'payload'  => $payload,
        'response' => $result,
    ], 200);
}