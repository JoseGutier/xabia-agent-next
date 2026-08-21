<?php
/**
 * Plugin Name: Xabia — Aislamiento tablas sitio / Hub
 * Description: Recomendado: instalar vía Xabia Agent Core (copia automática a mu-plugins). Cuando WordPress y el Hub comparten MySQL, desvía usage_logs y wallets a tablas locales.
 * Author: Xabia
 * Version: 1.1.0
 *
 * Instalación manual (opcional): copiar a wp-content/mu-plugins/. El core despliega xabia-hub-table-isolation.php con el mismo filtro.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('XABIA_HUB_TABLE_ISOLATION_LOADED')) {
    return;
}
define('XABIA_HUB_TABLE_ISOLATION_LOADED', true);

add_filter(
    'xabia_db_table',
    static function (string $name, string $key): string {
        if ($key === 'usage_logs') {
            return 'xabia_site_usage_log';
        }
        if ($key === 'wallets') {
            return 'xabia_site_wallets';
        }

        return $name;
    },
    10,
    2
);
