<?php
/**
 * API - Xabia Agent NEXT (v8.8)
 * Gestión de peticiones con Registro de Scoring y Logs de Negocio.
 */

if (!defined('ABSPATH')) exit;

class Xabia_API {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('xabia/v1', '/chat', [
            'methods'  => 'POST',
            'callback' => [$this, 'handle_chat'],
            'permission_callback' => '__return_true'
        ]);
    }

    public function handle_chat($request) {
        $params = $request->get_json_params();
        
        $project_id   = sanitize_text_field($params['project_id'] ?? '');
        $user_message = sanitize_textarea_field($params['prompt'] ?? $params['message'] ?? '');

        if (empty($project_id) || empty($user_message)) {
            return new WP_REST_Response(['response' => 'Error: Faltan datos críticos.'], 400);
        }

        // 1. Obtener Configuración del Agente
        $projects = get_option('xabia_projects_config', []);
        if (!isset($projects[$project_id])) {
            return new WP_REST_Response(['response' => 'Error: Agente no activo.'], 404);
        }

        // 2. Obtener Contexto con Scoring Real
        $contexto = "";
        $max_score = 0; // Para el log
        
        if (function_exists('get_xabia_context')) {
            // El core ahora necesita el mensaje del usuario para calcular relevancia
            $contexto = get_xabia_context($project_id, $user_message);
            
            // Lógica simple para detectar si hubo relevancia (basada en el contenido devuelto)
            $max_score = (strpos($contexto, 'No hay datos específicos') === false) ? 10 : 0;
        }

        // 3. Preparar llamada a OpenAI
        $api_key = get_option('xabia_openai_key');
        $instrucciones = $projects[$project_id]['rules']['instructions'] ?? 'Eres un asistente de Xabia Intelligence Center.';

        if (empty($api_key)) {
            return new WP_REST_Response(['response' => 'Error: API Key no configurada.'], 500);
        }

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system', 
                        'content' => $instrucciones . "\n\nCONTEXTO DE BASE DE DATOS:\n" . $contexto
                    ],
                    ['role' => 'user', 'content' => $user_message]
                ],
                'temperature' => 0.4 // Reducimos temperatura para ser más precisos
            ]),
            'timeout' => 45
        ]);

        if (is_wp_error($response)) {
            return new WP_REST_Response(['response' => 'Error de conexión externa.'], 500);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $answer = $body['choices'][0]['message']['content'] ?? 'Lo siento, el motor está saturado.';

        // --- 4. REGISTRO EN EL LOG DE INTELIGENCIA (CRÍTICO) ---
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'xabia_logs',
            [
                'project_id'  => $project_id,
                'user_query'  => $user_message,
                'ai_response' => $answer,
                'max_score'   => $max_score
            ],
            ['%s', '%s', '%s', '%d']
        );

        return new WP_REST_Response(['response' => $answer], 200);
    }
}