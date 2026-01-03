<?php
/**
 * Xabia Agent — REST API v3
 * Expone los endpoints públicos del agente:
 *  - /xabi/v1/chat      → conversación
 *  - /xabi/v1/train     → entrenamiento
 *  - /xabi/v1/sources   → inspeccionar fuentes
 */

if (!defined('ABSPATH')) exit;

// Núcleo necesario (⚠️ SIN init.php porque NO existe)
require_once plugin_dir_path(__FILE__) . 'interpreter.php';
require_once plugin_dir_path(__FILE__) . 'embeddings.php';

/**
 * Registrar todos los endpoints
 */
add_action('rest_api_init', function () {

    // Conversación
    register_rest_route('xabi/v1', '/chat', [
        'methods'             => 'POST',
        'callback'            => 'xabia_api_chat',
        'permission_callback' => '__return_true'
    ]);

    // Entrenamiento (delegado al wrapper)
    register_rest_route('xabi/v1', '/train', [
        'methods'             => 'POST',
        'callback'            => 'xabia_api_train_v3_wrapper',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        }
    ]);

    // Listar fuentes registradas
    register_rest_route('xabi/v1', '/sources', [
        'methods'             => 'GET',
        'callback'            => 'xabia_api_sources_list',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        }
    ]);
});

/**
 * 1️⃣ Endpoint de conversación
 */
function xabia_api_chat(WP_REST_Request $req)
{
    $msg   = sanitize_text_field($req->get_param('message') ?? '');
    $lang  = sanitize_text_field($req->get_param('lang') ?? 'es');

    if ($msg === '') {
        return ['error' => 'Missing message'];
    }

    $answer = xabia_interpreter_process($msg, $lang);

    return [
        'status'  => 'ok',
        'message' => $answer,
    ];
}

/**
 * 2️⃣ Endpoint para ver fuentes registradas
 */
function xabia_api_sources_list()
{
    $sources = xabia_get_registered_sources();

    return [
        'status'  => 'ok',
        'sources' => $sources
    ];
}

/**
 * 3️⃣ Delegación REAL al endpoint-train.php
 */
function xabia_api_train_v3_wrapper(WP_REST_Request $req)
{
    // Ejecutar el entrenador REAL si existe
    if (function_exists('xabia_api_train_v3_run')) {
        return xabia_api_train_v3_run($req);
    }

    return [
        'status'  => 'error',
        'message' => 'train handler missing (no existe xabia_api_train_v3_run)'
    ];
}