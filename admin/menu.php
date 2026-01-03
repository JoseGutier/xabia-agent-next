<?php
/**
 * Xabia — admin/menu.php
 * Menú principal en el panel de administración.
 */

if (!defined('ABSPATH')) {
    exit;
}

function xabia_admin_menu() {
    // Menú principal
    add_menu_page(
        'Xabia Agent',
        'Xabia',
        'manage_options',
        'xabia-settings',
        'xabia_admin_page_settings',
        'dashicons-format-chat',
        58
    );

    // Aseguramos también el submenú "Ajustes" apuntando al mismo slug
    add_submenu_page(
        'xabia-settings',
        'Ajustes',
        'Ajustes',
        'manage_options',
        'xabia-settings',
        'xabia_admin_page_settings'
    );

    // Fuentes
    add_submenu_page(
        'xabia-settings',
        'Fuentes',
        'Fuentes',
        'manage_options',
        'xabia-sources',
        'xabia_admin_page_sources'
    );

    // Acciones
    add_submenu_page(
        'xabia-settings',
        'Acciones',
        'Acciones',
        'manage_options',
        'xabia-actions',
        'xabia_admin_page_actions'
    );

   // Embeddings (entrenamiento v7.5)
add_submenu_page(
    'xabia-settings',
    'Embeddings',
    'Embeddings',
    'manage_options',
    'xabia-embeddings',
    'xabia_admin_page_embeddings'
);

    // Estado (stub sencillo para no romper nada)
    add_submenu_page(
        'xabia-settings',
        'Estado del sistema',
        'Estado',
        'manage_options',
        'xabia-status',
        'xabia_admin_page_status'
    );
}
add_action('admin_menu', 'xabia_admin_menu');