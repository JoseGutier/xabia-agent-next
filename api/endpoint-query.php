<?php
/**
 * API — endpoint-query.php
 * Ubicación: /api/endpoint-query.php
 */

if (!defined('ABSPATH')) exit;

function xabia_register_query_endpoint() {
    register_rest_route('xabia/v1', '/chat', [
        'methods'  => 'POST',
        'callback' => 'xabia_handle_query',
        'permission_callback' => '__return_true',
    ]);
}

function xabia_handle_query(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $message    = sanitize_text_field($params['message'] ?? '');
    $project_id = sanitize_text_field($params['project_id'] ?? 'demo');

    // 1. LOCALIZAR MOTOR: 
    // Usamos una ruta más robusta para saltar de /api/ a /core/
    $plugin_dir = plugin_dir_path(dirname(__FILE__)); 
    $engine_path = $plugin_dir . 'core/class-xabia-engine.php';
    
    if (!file_exists($engine_path)) {
        return new WP_REST_Response([
            'error' => 'Motor no encontrado',
            'debug_path' => $engine_path
        ], 500);
    }

    require_once $engine_path;

    try {
        if (!class_exists('Xabia_Engine')) {
            throw new Exception("La clase Xabia_Engine no se cargó.");
        }

        $engine = new Xabia_Engine($project_id);
        $ai_answer = $engine->generate_response($message);

        return new WP_REST_Response([
            'ok'       => true,
            'response' => $ai_answer
        ], 200);

    } catch (Throwable $e) {
        // Esto atrapará errores de sintaxis o fallos de OpenAI
        return new WP_REST_Response([
            'error' => $e->getMessage(),
            'file'  => $e->getFile(),
            'line'  => $e->getLine()
        ], 500);
    }
}