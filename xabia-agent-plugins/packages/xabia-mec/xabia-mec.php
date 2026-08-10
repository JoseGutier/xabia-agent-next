<?php
/**
 * Plugin Name: Xabia MEC
 * Plugin URI: https://xabia.ai
 * Description: Addon de especialización que dota a Xabia AI con inteligencia avanzada para la gestión de eventos, plazas y reservas de Modern Events Calendar, ofreciendo interacciones asistidas en tiempo real.
 * Version: 1.0.3
 * Author: Digixop
 * Author URI: https://digixop.com
 * Text Domain: xabia-intelligence
 */

if (!defined('ABSPATH')) {
    exit;
}

define('XABIA_MEC_PATH', plugin_dir_path(__FILE__));
define('XABIA_MEC_URL', plugin_dir_url(__FILE__));

function xabia_mec_requires_core_notice(): void {
    echo '<div class="notice notice-error"><p>' . esc_html__('Xabia MEC requiere el plugin Xabia Agent Core activo.', 'xabia-intelligence') . '</p></div>';
}

$core_bootstrap = WP_PLUGIN_DIR . '/xabia-agent-core/xabia-intelligence.php';
if (!function_exists('is_plugin_active')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if (!file_exists($core_bootstrap) || !is_plugin_active('xabia-agent-core/xabia-intelligence.php')) {
    add_action('admin_notices', 'xabia_mec_requires_core_notice');

    return;
}

require_once XABIA_MEC_PATH . 'includes/xabia-mec-integration.php';

add_filter('xabia_agent_native_connectors', static function ($plugins) {
    if (!is_array($plugins)) {
        $plugins = [];
    }
    if (file_exists(WP_PLUGIN_DIR . '/xabia-mec/xabia-mec.php') && function_exists('xabia_mec_license_gate') && xabia_mec_license_gate()) {
        $plugins[] = plugin_basename(XABIA_MEC_PATH . 'xabia-mec.php');
    }

    return array_values(array_unique(array_map('strval', $plugins)));
}, 10, 1);

add_filter('xabia_agent_admin_tabs', static function ($tabs, $edit_id) {
    if (!is_array($tabs)) {
        $tabs = [];
    }
    if (!is_string($edit_id) || $edit_id === '' || $edit_id === 'new') {
        return $tabs;
    }
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (!is_plugin_active('xabia-mec/xabia-mec.php')) {
        return $tabs;
    }
    if (function_exists('xabia_mec_license_gate') && !xabia_mec_license_gate()) {
        return $tabs;
    }
    $tabs[] = [
        'id'    => 'tab-mec',
        'label' => __('MEC', 'xabia-intelligence'),
    ];

    return $tabs;
}, 11, 2);

add_action('xabia_agent_admin_extra_tabs_content', static function ($edit_id, $data) {
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (!is_plugin_active('xabia-mec/xabia-mec.php')) {
        return;
    }
    if (function_exists('xabia_mec_license_gate') && !xabia_mec_license_gate()) {
        return;
    }
    if (!is_string($edit_id) || $edit_id === '' || $edit_id === 'new') {
        return;
    }
    if (!is_array($data)) {
        $data = [];
    }
    $is_mec_source = (($data['source_type'] ?? '') === 'addon' && ($data['addon_slug'] ?? '') === 'mec');
    $mec_vec_count = 0;
    if ($is_mec_source && class_exists('Xabia_DB', false)) {
        global $wpdb;
        $t = Xabia_DB::table('knowledge_vectors');
        $mec_vec_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE project_id = %s", $edit_id));
    }
    ?>
    <div id="tab-mec" class="xabia-tab-content">
        <h3 class="xabia-section-title"><?php echo esc_html__('Modern Events Calendar (MEC)', 'xabia-intelligence'); ?></h3>
        <p class="description"><?php echo esc_html__('Configura aquí la conexión a la base de datos donde viven los eventos MEC y revisa el mapeo. En General elige «Addon nativo» y «Modern Events Calendar (MEC)».', 'xabia-intelligence'); ?></p>
        <?php if ($is_mec_source) : ?>
        <div class="xabia-mec-status-panel xabia-panel-muted" style="padding:14px;border-radius:8px;margin:14px 0;">
            <p style="margin:0 0 8px;"><strong><?php echo esc_html__('Sincronización con MEC', 'xabia-intelligence'); ?></strong></p>
            <p style="margin:0 0 12px;" id="xabia-mec-vector-count"><?php echo esc_html(sprintf(__('Filas en la base de conocimiento de este agente: %d (tras la última sincronización).', 'xabia-intelligence'), $mec_vec_count)); ?></p>
            <button type="button" class="button button-primary button-hero" id="xabia-mec-test-connection-btn"><?php echo esc_html__('Probar conexión con MEC', 'xabia-intelligence'); ?></button>
            <p class="description" style="margin:10px 0 0;"><?php echo esc_html__('Ejecuta la misma comprobación que «Conectar y mapear» en General: valida SQL (local o remoto) y columnas de eventos MEC.', 'xabia-intelligence'); ?></p>
        </div>
        <?php endif; ?>
        <div id="xabia-mec-connect-landing" class="xabia-panel-muted" style="padding:12px;border-radius:8px;margin:14px 0;"></div>
        <div id="xabia-mapping-slot-mec"></div>
        <?php if (function_exists('xabia_federation_mec_render_feed_panel')) : ?>
            <details class="xabia-mec-dev-advanced" style="margin-top:22px;border:1px solid #c3c4c7;border-radius:8px;padding:10px 14px;background:#fcfcfc;">
                <summary style="cursor:pointer;font-weight:600;outline:none;"><?php echo esc_html__('Opciones avanzadas / Desarrolladores', 'xabia-intelligence'); ?></summary>
                <div style="margin-top:12px;">
                    <?php xabia_federation_mec_render_feed_panel(true); ?>
                </div>
            </details>
        <?php endif; ?>
    </div>
    <?php
}, 11, 2);

add_filter('xabia_agent_known_addons', static function ($addons) {
    if (!is_array($addons)) {
        $addons = [];
    }
    $addons[] = [
        'plugin_file' => plugin_basename(XABIA_MEC_PATH . 'xabia-mec.php'),
        'label'       => __('Xabia MEC', 'xabia-intelligence'),
    ];

    return $addons;
}, 10, 1);
