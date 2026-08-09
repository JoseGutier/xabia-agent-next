<?php
/**
 * Addon: Xabia Central — Federación de nodos (XABIA CENTRAL)
 * Ruta: integrations/central/
 * Comercial: [xabia.ai/precio](https://xabia.ai/precio/) — Redes federadas, ayuntamientos, corporaciones.
 * Principios: ahorro, privacidad (datos en casa), eficacia (unificación por ente, pull/push).
 */

if (!defined('ABSPATH')) exit;

define('XABIA_CENTRAL_SLUG', 'xabia_central');
define('XABIA_CENTRAL_PATH', plugin_dir_path(__FILE__));

/**
 * ¿La licencia del sitio tiene activo el add-on de federación según el hub?
 */
function xabia_central_hub_includes_federation_addon(): bool {
    if (!class_exists('Xabia_Digixop_Client', false) || !Xabia_Digixop_Client::is_license_configured()) {
        return false;
    }
    if (!class_exists('Xabia_Addons', false)) {
        return false;
    }
    $key = Xabia_Digixop_Client::get_license_key();
    if ($key === '') {
        return false;
    }
    $j = Xabia_Digixop_Client::license_validate_json_for_key($key);
    if (!is_array($j)) {
        return false;
    }
    $slugs = ['xabia_central', 'xabia-central', 'federation', 'xabia-federation'];

    return Xabia_Addons::hub_payload_includes_addon($j, $slugs) !== null;
}

add_action('xabia_register_addons', function() {
    register_xabia_addon(XABIA_CENTRAL_SLUG, [
        'name'     => 'Xabia Central (Federación)',
        'icon'     => 'hub',
        'desc'     => 'Redes federadas: ingesta unificada desde varias webs, pull/push, asignación por ente.',
        'callback' => ['Xabia_Central', 'get_sync_placeholder'],
    ]);
});

add_filter('xabia_addon_sync_result', function($result, $project_id, $config) {
    if (($config['addon_slug'] ?? '') !== XABIA_CENTRAL_SLUG) return $result;
    if (!class_exists('Xabia_Central')) return $result;
    $count = Xabia_Central::run_sync($project_id);
    return ['count' => $count];
}, 10, 3);

/**
 * Federación: la sincronización periódica la gestiona Xabia_Auto_Sync (cron unificado).
 */
add_action('xabia_install_addon_tables', function () {
    Xabia_Central_Setup::install();
    if (class_exists('Xabia_Auto_Sync', false)) {
        Xabia_Auto_Sync::ensure_cron_scheduled();
    }
});

add_action('plugins_loaded', function() {
    Xabia_Central_Setup::ensure_federation_column();
}, 20);

add_action('wp_ajax_xabia_central_ingest', ['Xabia_Central_Ingest', 'handle_push']);
add_action('wp_ajax_nopriv_xabia_central_ingest', ['Xabia_Central_Ingest', 'handle_push']);

add_action('admin_menu', function() {
    if (!current_user_can('manage_options')) {
        return;
    }
    add_submenu_page(
        null,
        __('Xabia Central', 'xabia-intelligence'),
        '',
        'manage_options',
        'xabia-central',
        ['Xabia_Central_Admin', 'render_page']
    );
}, 20);

require_once XABIA_CENTRAL_PATH . 'class-xabia-central-setup.php';
require_once XABIA_CENTRAL_PATH . 'class-xabia-central.php';
require_once XABIA_CENTRAL_PATH . 'class-xabia-central-sync.php';
require_once XABIA_CENTRAL_PATH . 'class-xabia-central-ingest.php';
require_once XABIA_CENTRAL_PATH . 'class-xabia-central-admin.php';
