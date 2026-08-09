<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param string $message Línea principal (prefijo [XABIA_*] recomendado).
 * @param mixed    $data  Opcional: se serializa en JSON en la misma entrada (truncado si es enorme).
 */
function xabia_trace(string $message, $data = null): void
{
    error_log($message);
    if (defined('XABIA_DISABLE_CHAT_TRACE') && XABIA_DISABLE_CHAT_TRACE) {
        return;
    }
    $path = '';
    if (defined('WP_CONTENT_DIR') && is_string(WP_CONTENT_DIR) && WP_CONTENT_DIR !== '' && is_writable(WP_CONTENT_DIR)) {
        $path = rtrim(WP_CONTENT_DIR, '/') . '/xabia-chat-trace.log';
    }
    if ($path === '' && function_exists('wp_upload_dir')) {
        $u = wp_upload_dir();
        if (is_array($u) && empty($u['error']) && !empty($u['basedir']) && is_writable($u['basedir'])) {
            $path = rtrim((string) $u['basedir'], '/') . '/xabia-chat-trace.log';
        }
    }
    if ($path === '') {
        return;
    }
    $line = gmdate('Y-m-d H:i:s') . ' UTC ' . $message;
    if ($data !== null) {
        $enc = '';
        if (is_string($data)) {
            $enc = $data;
        } elseif (is_scalar($data)) {
            $enc = (string) $data;
        } else {
            $enc = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($enc)) {
                $enc = '[non-encodable data]';
            }
        }
        if (strlen($enc) > 12000) {
            $enc = substr($enc, 0, 12000) . '…[truncated]';
        }
        $line .= ' | ' . $enc;
    }
    $line .= "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}
