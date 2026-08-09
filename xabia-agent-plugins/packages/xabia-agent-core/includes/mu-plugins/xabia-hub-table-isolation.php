<?php
/**
 * Plugin Name: Xabia — Aislamiento tablas sitio / Hub
 * Description: Instalado automáticamente por Xabia Agent Core. Cuando WordPress y el Hub comparten MySQL, desvía usage_logs, wallets y knowledge_vectors a tablas locales del sitio y no toca las tablas del Hub.
 * Author: Xabia
 * Version: 1.1.0
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
        if ($key === 'knowledge_vectors' && class_exists('Xabia_DB', false) && Xabia_DB::hub_xabia_knowledge_vectors_is_shared_with_hub()) {
            return 'xabia_site_knowledge_vectors';
        }

        return $name;
    },
    10,
    2
);
