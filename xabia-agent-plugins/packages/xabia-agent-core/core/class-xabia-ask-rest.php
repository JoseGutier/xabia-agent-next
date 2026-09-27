<?php
/**
 * POST /wp-json/xabia/v1/ask
 * El nonce se valida antes de cargar el RAG o el modelo.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Ask_Rest {

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_rest_routes']);
    }

    public static function register_rest_routes(): void {
        register_rest_route(
            'xabia/v1',
            '/ask',
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'handle'],
                'permission_callback' => [self::class, 'authorize'],
            ]
        );
    }

    /**
     * Primera puerta. Un nonce ausente o inválido no entra en el callback.
     *
     * @param \WP_REST_Request $request
     * @return true|\WP_Error
     */
    public static function authorize($request) {
        $nonce = self::read_nonce($request);
        $admin_ok = ($nonce !== '' && wp_verify_nonce($nonce, 'xabia_admin_nonce'));
        $public_ok = ($nonce !== '' && wp_verify_nonce($nonce, 'xabia_nonce'));
        $decision = Xabia_Chat_Pipeline::nonce_decision(
            $nonce,
            (bool) $admin_ok,
            (bool) $public_ok,
            current_user_can('manage_options')
        );
        if ($decision !== 'ok') {
            return new WP_Error(
                'xabia_forbidden',
                __('La comprobación de seguridad falló.', 'xabia-intelligence'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function handle($request) {
        if (class_exists('Xabia_Chat_Pipeline', false)) {
            Xabia_Chat_Pipeline::begin();
            Xabia_Chat_Pipeline::mark('nonce');
        }
        if (self::wants_stream($request) && class_exists('Xabia_Chat_Stream', false)) {
            Xabia_Chat_Stream::arm();
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', 'off');
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            if (!headers_sent()) {
                header('Content-Type: text/event-stream; charset=utf-8');
                header('X-Accel-Buffering: no');
                header('X-LiteSpeed-Cache-Control: no-cache');
                header('Cache-Control: no-cache, no-transform');
                header('Connection: keep-alive');
            }
        }
        foreach ($request->get_params() as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_array($value)) {
                continue;
            }
            $_POST[$key] = $value;
        }
        $_POST['nonce'] = self::read_nonce($request);
        if (!class_exists('Xabia_API', false)) {
            return new WP_REST_Response([
                'success' => false,
                'data'    => ['message' => 'Xabia API no disponible'],
            ], 500);
        }
        Xabia_API::handle_chat_request();

        return new WP_REST_Response([
            'success' => false,
            'data'    => ['message' => 'El chat no devolvió respuesta'],
        ], 500);
    }

    /**
     * @param \WP_REST_Request $request
     */
    private static function wants_stream($request): bool {
        $stream = $request->get_param('stream');
        if (is_scalar($stream) && in_array(strtolower(trim((string) $stream)), ['1', 'true', 'yes'], true)) {
            return true;
        }

        return false;
    }

    /**
     * @param \WP_REST_Request $request
     */
    private static function read_nonce($request): string {
        $nonce = $request->get_param('nonce');
        if (!is_scalar($nonce) || (string) $nonce === '') {
            $nonce = $request->get_header('x-xabia-nonce');
        }

        return is_scalar($nonce) ? sanitize_text_field((string) $nonce) : '';
    }
}
