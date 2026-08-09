<?php
/**
 * Puente WordPress → Xabia_Document_Ingest (adaptador + rutas uploads).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Document_Ingest_Bridge {

    /**
     * Directorio raíz donde cada proyecto tiene su subcarpeta de documentos.
     */
    public static function default_base_dir(): string {
        $default = '';
        if (function_exists('wp_upload_dir')) {
            $upload = wp_upload_dir(null, false);
            if (is_array($upload) && !empty($upload['basedir']) && empty($upload['error'])) {
                $default = rtrim((string) $upload['basedir'], '/\\') . '/xabia-knowledge/';
            }
        }
        if ($default === '') {
            $default = sys_get_temp_dir() . '/xabia-knowledge/';
        }

        return (string) apply_filters('xabia_document_ingest_base_dir', $default);
    }

    public static function for_wordpress(?string $base_dir = null): Xabia_Document_Ingest {
        global $wpdb;
        $adapter = new Xabia_WP_DB_Adapter($wpdb);
        $dir = $base_dir !== null && $base_dir !== '' ? $base_dir : self::default_base_dir();

        return new Xabia_Document_Ingest($adapter, $dir);
    }

    public static function table_prefix_for_wordpress(): string {
        global $wpdb;

        return isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_';
    }
}
