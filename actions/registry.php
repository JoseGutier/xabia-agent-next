<?php
/**
 * Xabia — actions/registry.php
 * Registro central de acciones disponibles.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Almacén global de acciones
 */
global $xabia_actions_registry;
$xabia_actions_registry = [];

/**
 * Registra una acción
 * @param string $name
 * @param array  $args
 */
function xabia_register_action(string $name, array $args) {
    global $xabia_actions_registry;

    $defaults = [
        'label'   => $name,
        'handler' => '',        // función PHP que ejecuta la acción
        'type'    => 'frontend' // frontend | backend
    ];

    $xabia_actions_registry[$name] = array_merge($defaults, $args);
}

/**
 * Devuelve todas las acciones registradas.
 */
function xabia_get_actions() : array {
    global $xabia_actions_registry;
    return $xabia_actions_registry;
}

/**
 * Ejecuta acción (desde el motor o API)
 * Devuelve estructura arbitraria (array), por ejemplo:
 * [
 *   "command" => "...",
 *   "value"   => "..."
 * ]
 */
function xabia_run_action(string $name, array $payload = []) {
    global $xabia_actions_registry;

    if (!isset($xabia_actions_registry[$name])) {
        return [
            'error'   => true,
            'message' => "Acción '$name' no registrada."
        ];
    }

    $handler = $xabia_actions_registry[$name]['handler'];

    if (!$handler || !function_exists($handler)) {
        return [
            'error'   => true,
            'message' => "Handler de acción '$name' no encontrado."
        ];
    }

    return call_user_func($handler, $payload);
}

/* =====================================================
 *  Registro inicial de acciones FRONTEND básicas
 * ===================================================== */

xabia_register_action('open_url', [
    'label'   => 'Abrir URL',
    'handler' => 'xabia_action_open_url',
    'type'    => 'frontend',
]);

xabia_register_action('call_phone', [
    'label'   => 'Llamar por teléfono',
    'handler' => 'xabia_action_call_phone',
    'type'    => 'frontend',
]);

xabia_register_action('open_company_profile', [
    'label'   => 'Abrir ficha de empresa',
    'handler' => 'xabia_action_open_company_profile',
    'type'    => 'frontend',
]);

xabia_register_action('book_experience', [
    'label'   => 'Reservar una experiencia',
    'handler' => 'xabia_action_book_experience',
    'type'    => 'backend'
]);


/* =====================================================
 *  Registro de acciones BACKEND (datos / semántica)
 * ===================================================== */

xabia_register_action('search_company', [
    'label'   => 'Buscar empresas',
    'handler' => 'xabia_action_search_company',
    'type'    => 'backend',
]);

xabia_register_action('search_by_category', [
    'label'   => 'Buscar por categoría',
    'handler' => 'xabia_action_search_by_category',
    'type'    => 'backend',
]);

xabia_register_action('recommend_company', [
    'label'   => 'Recomendar empresa por intención',
    'handler' => 'xabia_action_recommend_company',
    'type'    => 'backend',
]);

xabia_register_action('get_company', [
    'label'   => 'Obtener empresa completa',
    'handler' => 'xabia_action_get_company',
    'type'    => 'backend',
]);

xabia_register_action('get_faqs', [
    'label'   => 'Obtener FAQs de empresa',
    'handler' => 'xabia_action_get_faqs',
    'type'    => 'backend',
]);

xabia_register_action('get_experiences', [
    'label'   => 'Obtener experiencias de empresa',
    'handler' => 'xabia_action_get_experiences',
    'type'    => 'backend',
]);

xabia_register_action('get_benefits', [
    'label'   => 'Obtener beneficios de empresa',
    'handler' => 'xabia_action_get_benefits',
    'type'    => 'backend',
]);