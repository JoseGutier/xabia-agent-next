<?php
/**
 * Xabia App — AKTIBA
 * Preset mínimo de activación de proyecto
 *
 * ❗ NO define conocimiento
 * ❗ NO define saludo
 * ❗ NO define CTA
 *
 * Todo eso vive en:
 * /uploads/xabia/aktiba/config.json
 * /uploads/xabia/aktiba/knowledge.json (opcional)
 */

if (!defined('ABSPATH')) exit;

/**
 * Activa el contexto del proyecto Aktiba
 */
function xabia_app_aktiba_apply_context_preset(array $params = []) {

    // Proyecto activo
    XabiaContext::set('app', 'aktiba');

    // Modo operativo (informativo / listados)
    XabiaContext::set('mode', 'directory');

    // Fuentes dinámicas (CSV actuales de Aktiba)
    XabiaContext::set('sources', [
        'aktiba_agua',
        'aktiba_tierra',
        'aktiba_aire',
        'aktiba_ecoturismo',
        'aktiba_otras'
    ]);

    // Prioridad de búsqueda
    XabiaContext::set('search_priority', [
        'empresa',
        'actividad'
    ]);

    // Rol general del asistente
    XabiaContext::set('assistant_role', 'tourism_directory');

    // Debug
    error_log('[Xabia AKTIBA] ✔ Preset aktiba aplicado');
}