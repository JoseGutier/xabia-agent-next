<?php
/**
 * Plugin Name: Xabia Agent NEXT
 * Plugin URI:  https://xabia.ai/
 * Description: Sistema de Inteligencia Artificial para Webs Vivas - Motor v8.8 (Scoring & Logs)
 * Version:     8.8.0
 * Author:      Xabia Intelligence Center
 * Text Domain: xabia-agent
 */

if (!defined('ABSPATH')) exit;

// 1. Definir rutas y constantes de versión
define('XABIA_VERSION', '8.8.0');
define('XABIA_PATH', plugin_dir_path(__FILE__));
define('XABIA_URL', plugin_dir_url(__FILE__));

/**
 * 2. Lógica de Activación (Instalación de Base de Datos)
 * Creamos la tabla de logs automáticamente al activar el plugin.
 */
register_activation_hook(__FILE__, function() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'xabia_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        project_id varchar(50) NOT NULL,
        user_query text NOT NULL,
        ai_response text NOT NULL,
        max_score int(11) DEFAULT 0,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
});

/**
 * 3. Cargador Principal Modular
 */
add_action('plugins_loaded', function() {
    
    // Core: Lógica de datos y Scoring
    if (file_exists(XABIA_PATH . 'core/core.php')) {
        require_once XABIA_PATH . 'core/core.php';
    }
    
    // API: Endpoints REST para el chat
    require_once XABIA_PATH . 'api/class-xabia-api.php';
    if (class_exists('Xabia_API')) {
        new Xabia_API();
    }

    // Logger: Clase auxiliar para registrar eventos
    if (file_exists(XABIA_PATH . 'includes/class-xabia-logger.php')) {
        require_once XABIA_PATH . 'includes/class-xabia-logger.php';
    }

    // Frontend: Shortcode y Widget
    require_once XABIA_PATH . 'frontend/widgets/chatbox.php';

    // Admin: Panel de Control y Logs
    if (is_admin()) {
        require_once XABIA_PATH . 'admin/class-xabia-admin.php';
        if (class_exists('Xabia_Admin')) {
            Xabia_Admin::init();
        }
    }
});

/**
 * 4. Manejo de Sesión Segura
 */
add_action('init', function() {
    if (!session_id() && !headers_sent()) {
        session_start();
    }
}, 1);