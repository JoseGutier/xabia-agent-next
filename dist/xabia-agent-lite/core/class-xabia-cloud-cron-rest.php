<?php
/**
 * REST oyente — Reloj Maestro del Hub (POST /xabia/v1/cloud-cron-trigger).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Cloud_Cron_Rest {

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_rest_routes']);
    }

    public static function register_rest_routes(): void {
        register_rest_route(
            'xabia/v1',
            '/cloud-cron-trigger',
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'handle_trigger'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function handle_trigger($request) {
        unset($request);

        if (!class_exists('Xabia_Digixop_Client', false) || !Xabia_Digixop_Client::is_license_configured()) {
            return new WP_REST_Response(
                ['error' => ['message' => 'Licencia no configurada', 'code' => 'missing_license']],
                401
            );
        }

        $raw_body = (string) file_get_contents('php://input');
        if (!Xabia_Digixop_Client::verify_hub_inbound_signature($raw_body)) {
            return new WP_REST_Response(
                ['error' => ['message' => 'Firma inválida o caducada', 'code' => 'invalid_signature']],
                401
            );
        }

        if (!class_exists('Xabia_Auto_Sync', false)) {
            return new WP_REST_Response(
                ['error' => ['message' => 'Auto-sync no disponible', 'code' => 'auto_sync_missing']],
                503
            );
        }

        Xabia_Auto_Sync::dispatch_cloud_cron_async();

        return new WP_REST_Response(
            [
                'ok'      => true,
                'status'  => __('Pipeline iniciado', 'xabia-intelligence'),
                'version' => defined('XABIA_VERSION') ? (string) XABIA_VERSION : '',
            ],
            200
        );
    }
}
