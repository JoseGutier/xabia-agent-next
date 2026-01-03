<?php
/**
 * Xabia Agent — endpoint-train-embeddings.php (v7.6 FIX)
 */

if (!defined('ABSPATH')) exit;

/* =====================================================
 * REGISTRO (lo llama Xabia_API::register_all)
 * ===================================================== */
function xabia_register_train_embeddings_endpoint() {

    register_rest_route('xabi/v1', '/train-embeddings', [
        'methods'  => 'POST',
        'callback' => 'xabia_api_train_embeddings_only',
        'permission_callback' => '__return_true',
    ]);

    error_log("[Xabia] TRAIN-EMBEDDINGS endpoint registered");
}


/* =====================================================
 * CONTROLADOR PRINCIPAL
 * ===================================================== */
function xabia_api_train_embeddings_only(WP_REST_Request $req)
{
    try {

        // 🔥 CARGA REAL DE ENTIDADES DESDE base.json
        if (!function_exists('xabia_load_knowledge')) {
            return [
                'status'  => 'error',
                'message' => 'Falta xabia_load_knowledge().'
            ];
        }

        $entities = xabia_load_knowledge();

        if (!is_array($entities) || empty($entities)) {
            return [
                'status'  => 'error',
                'message' => 'No hay entidades cargadas desde base.json.'
            ];
        }

        // 🔥 ENTRENAR SOLO EMBEDDINGS CON ENTIDADES CORRECTAS
        $res = xabia_train_embeddings([
            'entities'      => $entities,
            'send_progress' => false,
        ]);

        // error WP
        if (is_wp_error($res)) {
            return [
                'status'  => 'error',
                'message' => $res->get_error_message()
            ];
        }

        return [
            'status' => 'ok',
            'count'  => $res['count'] ?? 0,
            'model'  => $res['model'] ?? 'text-embedding-3-large',
            'time'   => current_time('mysql'),
        ];

    } catch (Throwable $e) {

        if (function_exists('xabia_log')) {
            xabia_log("Error regenerando embeddings: " . $e->getMessage());
        }

        return [
            'status'  => 'error',
            'message' => $e->getMessage(),
        ];
    }
}