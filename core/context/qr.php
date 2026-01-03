<?php
if (!defined('ABSPATH')) exit;

/**
 * Aplica contexto QR (NO es una app)
 * Preselecciona entidad desde parámetros externos
 */
function xabia_apply_qr_context(array $params): void {

    if (empty($params['entity'])) return;

    $slug = sanitize_title($params['entity']);

    if (!function_exists('xabia_load_knowledge')) return;

    $entities = xabia_load_knowledge();
    if (!is_array($entities)) return;

    foreach ($entities as $e) {

        $name = $e['empresa'] ?? '';
        if (!$name) continue;

        if (sanitize_title($name) === $slug) {
            XabiaContext::set('last_company', $e);
            XabiaContext::set('last_list', [$e]);
            XabiaContext::set('qr_mode', true);
            return;
        }
    }
}