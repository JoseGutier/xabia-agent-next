<?php
if (!defined('ABSPATH')) exit;

/**
 * Debug centralizado para Xabia.
 *
 * Usa la constante XABIA_DEBUG para activar / desactivar logs.
 */

if (!defined('XABIA_DEBUG')) {
    // Cambia a true si quieres modo debug verboso.
    define('XABIA_DEBUG', false);
}

/**
 * Log sencillo.
 *
 * @param string $msg
 * @param array  $context
 */
function xabia_log(string $msg, array $context = []): void
{
    if (!XABIA_DEBUG) return;

    $line = '[XABIA] ' . $msg;

    if (!empty($context)) {
        $line .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }

    error_log($line);
}