<?php
/**
 * Xabia — actions/frontend.php (v2.0)
 * Acciones que ejecuta el frontend (JavaScript).
 */

if (!defined('ABSPATH')) exit;

/**
 * =====================================================
 * 1) Abrir URL
 * =====================================================
 */
function xabia_action_open_url(array $payload) {

    $url = esc_url_raw($payload['url'] ?? '');

    if (!$url) {
        return [
            'error' => true,
            'message' => 'URL no proporcionada'
        ];
    }

    return [
        'command' => 'open_url',
        'value'   => $url
    ];
}

/**
 * =====================================================
 * 2) Llamar por teléfono
 * =====================================================
 */
function xabia_action_call_phone(array $payload) {

    $phone = preg_replace('/[^0-9+]/', '', $payload['phone'] ?? '');

    if (!$phone) {
        return [
            'error' => true,
            'message' => 'Número no proporcionado'
        ];
    }

    return [
        'command' => 'call_phone',
        'value'   => 'tel:' . $phone
    ];
}

/**
 * =====================================================
 * 3) Abrir ficha de empresa (AKTIBA)
 * =====================================================
 */
function xabia_action_open_company_profile(array $payload) {

    $slug = sanitize_title($payload['slug'] ?? '');

    if (!$slug) {
        return [
            'error' => true,
            'message' => 'Slug no proporcionado'
        ];
    }

    // URL compatible con SITE: Aktiba
    $url = home_url('/empresa/' . $slug . '/');

    return [
        'command' => 'open_url',
        'value'   => $url
    ];
}

/**
 * =====================================================
 * 4) Scroll a un selector CSS
 * =====================================================
 */
function xabia_action_scroll_to(array $payload) {

    $selector = sanitize_text_field($payload['selector'] ?? '');

    if (!$selector) {
        return [
            'error' => true,
            'message' => 'Selector no proporcionado'
        ];
    }

    return [
        'command' => 'scroll_to',
        'value'   => $selector
    ];
}

/**
 * =====================================================
 * 5) Resaltar un elemento CSS
 * =====================================================
 */
function xabia_action_highlight_element(array $payload) {

    $selector = sanitize_text_field($payload['selector'] ?? '');

    if (!$selector) {
        return [
            'error' => true,
            'message' => 'Selector no proporcionado'
        ];
    }

    return [
        'command' => 'highlight_element',
        'value'   => $selector
    ];
}

/**
 * =====================================================
 * 6) Mostrar mensaje flotante
 * =====================================================
 */
function xabia_action_show_message(array $payload) {

    $msg = wp_kses_post($payload['message'] ?? '');

    if (!$msg) {
        return [
            'error' => true,
            'message' => 'Mensaje vacío'
        ];
    }

    return [
        'command' => 'show_message',
        'value'   => $msg
    ];
}

/**
 * =====================================================
 * 7) Leer texto por voz
 * =====================================================
 */
function xabia_action_speak_text(array $payload) {

    $text = sanitize_text_field($payload['text'] ?? '');

    if (!$text) {
        return [
            'error' => true,
            'message' => 'Texto vacío'
        ];
    }

    return [
        'command' => 'speak_text',
        'value'   => $text
    ];
}

/**
 * =====================================================
 * 8) Parar lectura de voz
 * =====================================================
 */
function xabia_action_stop_speaking() {

    return [
        'command' => 'stop_speaking',
        'value'   => true
    ];
}

/**
 * =====================================================
 * 9) Abrir el popup Xabia
 * =====================================================
 */
function xabia_action_open_popup() {

    return [
        'command' => 'open_popup',
        'value'   => true
    ];
}