<?php
/**
 * Xabia Central — Instalación de tablas y columna de federación
 * Privacidad: solo añade columna opcional; sin datos sensibles.
 */

if (!defined('ABSPATH')) exit;

class Xabia_Central_Setup {

    /**
     * Crea tabla de nodos y asegura columna federation_node_id en vectores.
     */
    public static function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $t = Xabia_DB::table('federation_nodes');

        $sql = "CREATE TABLE IF NOT EXISTS $t (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            project_id varchar(50) NOT NULL,
            node_id varchar(80) NOT NULL,
            name varchar(255) NOT NULL DEFAULT '',
            type varchar(20) NOT NULL DEFAULT 'pull',
            config longtext,
            api_key_hash varchar(64) DEFAULT NULL,
            last_sync_at datetime DEFAULT NULL,
            last_error varchar(255) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY project_node (project_id, node_id),
            KEY project_id (project_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        self::ensure_federation_column();
    }

    /**
     * Añade federation_node_id a xabia_knowledge_vectors si no existe (nullable).
     * Eficacia: una sola comprobación por carga; sin tocar el core.
     */
    public static function ensure_federation_column() {
        global $wpdb;
        $table = Xabia_DB::table('knowledge_vectors');
        $col = 'federation_node_id';
        $table_name = Xabia_DB::table('knowledge_vectors');
        $exists = $wpdb->get_results($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $table_name,
            $col
        ));
        if (empty($exists)) {
            $wpdb->query("ALTER TABLE `$table` ADD COLUMN `$col` varchar(80) DEFAULT NULL AFTER `ente_id`, ADD KEY `$col` (`$col`)");
        }
    }
}
