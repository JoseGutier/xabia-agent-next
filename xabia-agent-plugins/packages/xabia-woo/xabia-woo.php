<?php
/**
 * Plugin Name: Xabia Woo
 * Plugin URI: https://xabia.ai
 * Description: Addon que transforma tu WooCommerce en una plataforma de comercio conversacional avanzado. Dota al Agente de IA con inteligencia sobre tu catálogo para carritos asistidos e interacciones de ventas hiperpersonalizadas.
 * Version: 1.0.4
 * Author: Digixop
 * Author URI: https://digixop.com
 * Text Domain: xabia-intelligence
 */

if (!defined('ABSPATH')) {
    exit;
}

define('XABIA_WOO_PATH', plugin_dir_path(__FILE__));
define('XABIA_WOO_URL', plugin_dir_url(__FILE__));

function xabia_woo_requires_core_notice(): void {
    echo '<div class="notice notice-error"><p>' . esc_html__('Xabia Woo requiere el plugin Xabia Agent Core activo.', 'xabia-intelligence') . '</p></div>';
}

$core_bootstrap = WP_PLUGIN_DIR . '/xabia-agent-core/xabia-intelligence.php';
if (!function_exists('is_plugin_active')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if (!file_exists($core_bootstrap) || !is_plugin_active('xabia-agent-core/xabia-intelligence.php')) {
    add_action('admin_notices', 'xabia_woo_requires_core_notice');

    return;
}

require_once XABIA_WOO_PATH . 'includes/xabia-woo-integration.php';

add_filter('xabia_agent_native_connectors', static function ($plugins) {
    if (!is_array($plugins)) {
        $plugins = [];
    }
    if (function_exists('xabia_woo_license_gate') && xabia_woo_license_gate()) {
        $plugins[] = plugin_basename(XABIA_WOO_PATH . 'xabia-woo.php');
    }

    return array_values(array_unique(array_map('strval', $plugins)));
}, 10, 1);

add_filter('xabia_agent_known_addons', static function ($addons) {
    if (!is_array($addons)) {
        $addons = [];
    }
    $addons[] = [
        'plugin_file' => plugin_basename(XABIA_WOO_PATH . 'xabia-woo.php'),
        'label'       => __('Xabia Woo', 'xabia-intelligence'),
    ];

    return $addons;
}, 10, 1);

register_activation_hook(__FILE__, static function (): void {
    if (!class_exists('Xabia_DB', false)) {
        return;
    }
    global $wpdb;
    $table_name = Xabia_DB::table('conversions');
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        project_id varchar(50) NOT NULL,
        log_id bigint(20),
        order_id bigint(20) NOT NULL,
        order_total decimal(10,2) NOT NULL,
        product_ids text NOT NULL,
        status varchar(20) DEFAULT 'completed',
        PRIMARY KEY  (id),
        KEY order_id (order_id)
    ) $charset_collate;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
});
