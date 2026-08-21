<?php
/**
 * XABIA SQL CONNECTOR — conector genérico para consultas SQL externas/internas.
 * PROPÓSITO: Driver genérico para conectar a cualquier DB MySQL/MariaDB.
 * USO: Utilizado por el Admin (para testear) y por el Bridge (para sincronizar).
 *
 * Prefijos: en remoto NUNCA se usa $wpdb->prefix local. Se usa el prefijo
 * manual del proyecto o se detecta en la BD remota (SHOW TABLES …posts).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_SQL_Connector {

    /**
     * TEST: Prueba la conexión y devuelve las columnas (Schema).
     *
     * @param array<string, mixed> $config
     * @return list<string>|WP_Error
     */
    public static function get_schema($config) {
        $config = self::prepare_config($config);
        if (is_wp_error($config)) {
            return $config;
        }

        $sql = (string) ($config['query'] ?? '');
        if (stripos($sql, 'LIMIT') === false) {
            $sql .= ' LIMIT 1';
        }

        if (empty($config['host'])) {
            global $wpdb;
            $results = $wpdb->get_results($sql, ARRAY_A);
            if (!empty($wpdb->last_error)) {
                return new WP_Error('sql_error', 'Error SQL: ' . $wpdb->last_error);
            }
            if (empty($results)) {
                return new WP_Error('empty_result', 'Conexión OK, pero la consulta no devolvió datos.');
            }

            return array_keys($results[0]);
        }

        $db = self::connect($config);
        if (is_wp_error($db)) {
            return $db;
        }

        $results = $db->get_results($sql, ARRAY_A);

        if (!empty($db->last_error)) {
            return new WP_Error('sql_error', 'Error SQL: ' . $db->last_error);
        }

        if (empty($results)) {
            return new WP_Error('empty_result', 'Conexión OK, pero la consulta no devolvió datos.');
        }

        return array_keys($results[0]);
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>|WP_Error
     */
    public static function fetch_data($config) {
        $config = self::prepare_config($config);
        if (is_wp_error($config)) {
            return $config;
        }

        if (empty($config['host'])) {
            global $wpdb;
            $results = $wpdb->get_results($config['query'] ?? '', ARRAY_A);
            if (!empty($wpdb->last_error)) {
                return new WP_Error('sql_error', 'Error SQL: ' . $wpdb->last_error);
            }

            return is_array($results) ? $results : [];
        }

        $db = self::connect($config);
        if (is_wp_error($db)) {
            return $db;
        }

        $results = $db->get_results($config['query'], ARRAY_A);

        if (!empty($db->last_error)) {
            return new WP_Error('sql_error', 'Error SQL: ' . $db->last_error);
        }

        return is_array($results) ? $results : [];
    }

    /**
     * Resuelve el prefijo de tablas correcto para la conexión.
     * Remoto: solo prefijo manual o detección en la BD externa — nunca $wpdb->prefix.
     *
     * @param array<string, mixed> $config
     * @param object|wpdb|null     $remote_db Conexión remota ya abierta (opcional).
     */
    public static function resolve_table_prefix(array $config, $remote_db = null): string {
        $manual = self::normalize_prefix((string) ($config['prefix'] ?? ''));
        // Nunca aceptar un prefijo de tablas temporales WP All Export (pmxe_/pmxi_).
        if ($manual !== '' && class_exists('Xabia_Knowledge_Sync', false)
            && method_exists('Xabia_Knowledge_Sync', 'is_temp_or_export_prefix')
            && Xabia_Knowledge_Sync::is_temp_or_export_prefix($manual)) {
            $manual = '';
        }
        $host = trim((string) ($config['host'] ?? ''));

        if ($host === '') {
            if ($manual !== '') {
                return $manual;
            }
            global $wpdb;

            return (string) $wpdb->prefix;
        }

        // Remoto: no usar el prefijo de esta WordPress (aunque esté guardado por error en el formulario).
        global $wpdb;
        $local_prefix = isset($wpdb) && is_object($wpdb) ? self::normalize_prefix((string) $wpdb->prefix) : '';
        if ($manual !== '' && $manual !== $local_prefix) {
            return $manual;
        }

        if ($remote_db === null) {
            $remote_db = self::connect($config);
            if (is_wp_error($remote_db)) {
                return $manual !== '' ? $manual : 'wp_';
            }
        }

        $detected = self::detect_active_prefix($remote_db);
        if ($detected !== '' && !(
            class_exists('Xabia_Knowledge_Sync', false)
            && method_exists('Xabia_Knowledge_Sync', 'is_temp_or_export_prefix')
            && Xabia_Knowledge_Sync::is_temp_or_export_prefix($detected)
        )) {
            return $detected;
        }

        return $manual !== '' && $manual !== $local_prefix ? $manual : 'wp_';
    }

    /**
     * Sustituye {prefix} (y wp_… por defecto) con el prefijo resuelto de esta conexión.
     *
     * @param array<string, mixed> $config
     * @param object|wpdb|null     $remote_db
     */
    public static function apply_prefix_to_sql(string $sql, array $config, $remote_db = null): string {
        $sql = trim($sql);
        if ($sql === '') {
            return '';
        }

        $real_prefix = self::resolve_table_prefix($config, $remote_db);
        $host = trim((string) ($config['host'] ?? ''));

        if (stripos($sql, '{prefix}') !== false) {
            $sql = str_replace('{prefix}', $real_prefix, $sql);
        }

        // Solo reescribe el prefijo canónico wp_…; nunca el prefijo de la WP local como destino.
        if ($real_prefix !== 'wp_' && preg_match('/\bwp_[a-z]/i', $sql)) {
            $sql = preg_replace('/\bwp_([a-zA-Z0-9_]+)/', $real_prefix . '$1', $sql);
        }

        // Remoto: si la query ya trae el prefijo de ESTA WordPress (bug previo), sustituirlo por el remoto.
        if ($host !== '') {
            global $wpdb;
            $local_prefix = isset($wpdb) && is_object($wpdb) ? self::normalize_prefix((string) $wpdb->prefix) : '';
            if ($local_prefix !== '' && $local_prefix !== $real_prefix && stripos($sql, $local_prefix) !== false) {
                $sql = str_replace($local_prefix, $real_prefix, $sql);
            }
        }

        // Seguridad: reescribir tablas WP All Export (…_pmxe_posts → …_posts).
        $sql = preg_replace(
            '/\b([a-zA-Z0-9]+)_pmxe_(posts|postmeta|options|terms|termmeta|term_taxonomy|term_relationships)\b/i',
            '$1_$2',
            $sql
        );
        $sql = preg_replace(
            '/\b([a-zA-Z0-9]+)_pmxi_(posts|postmeta|options|terms|termmeta|term_taxonomy|term_relationships)\b/i',
            '$1_$2',
            $sql
        );

        return is_string($sql) ? $sql : '';
    }

    /**
     * Detecta el prefijo activo buscando tablas *posts con datos en la BD dada.
     *
     * @param object|wpdb $db
     */
    public static function detect_active_prefix($db): string {
        if (class_exists('Xabia_Knowledge_Sync', false) && method_exists('Xabia_Knowledge_Sync', 'find_active_prefix')) {
            $found = Xabia_Knowledge_Sync::find_active_prefix($db);
            if (is_string($found) && $found !== '') {
                return self::normalize_prefix($found);
            }
        }

        if (!is_object($db) || !method_exists($db, 'get_results')) {
            return '';
        }

        $tables = $db->get_results("SHOW TABLES LIKE '%posts'", ARRAY_N);
        if (empty($tables) || !is_array($tables)) {
            return '';
        }

        $candidates = [];
        foreach ($tables as $t) {
            if (!is_array($t) || empty($t[0])) {
                continue;
            }
            $table = (string) $t[0];
            if (class_exists('Xabia_Knowledge_Sync', false)
                && method_exists('Xabia_Knowledge_Sync', 'is_temp_or_export_wp_table')
                && Xabia_Knowledge_Sync::is_temp_or_export_wp_table($table)) {
                continue;
            }
            if (preg_match('/pmxe|pmxi/i', $table)) {
                continue;
            }
            $candidates[] = $table;
        }

        usort($candidates, static function ($a, $b) {
            return strlen((string) $a) <=> strlen((string) $b);
        });

        foreach ($candidates as $table) {
            $safe = '`' . str_replace('`', '``', $table) . '`';
            $check = $db->get_results("SELECT ID FROM {$safe} LIMIT 1");
            if (!empty($check)) {
                return self::normalize_prefix(substr($table, 0, -5));
            }
        }

        return '';
    }

    /**
     * URL pública del sitio remoto (medios / guid). Prioridad: sql_config.public_site_url → options siteurl/home.
     *
     * @param array<string, mixed> $sql_config
     * @param object|null          $db         wpdb remoto opcional
     */
    public static function resolve_public_site_url(array $sql_config, $db = null): string {
        $manual = trim((string) ($sql_config['public_site_url'] ?? $sql_config['media_base_url'] ?? ''));
        if ($manual !== '') {
            $manual = esc_url_raw($manual);
            return $manual !== '' ? untrailingslashit($manual) : '';
        }

        $host = trim((string) ($sql_config['host'] ?? ''));
        if ($host === '') {
            return untrailingslashit(home_url('/'));
        }

        if ($db === null) {
            $db = self::connect($sql_config);
            if (is_wp_error($db)) {
                return '';
            }
        }

        $prefix = self::resolve_table_prefix($sql_config, $db);
        $table = $prefix . 'options';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $siteurl = $db->get_var("SELECT option_value FROM `{$table}` WHERE option_name = 'siteurl' LIMIT 1");
        if (!is_string($siteurl) || trim($siteurl) === '') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $siteurl = $db->get_var("SELECT option_value FROM `{$table}` WHERE option_name = 'home' LIMIT 1");
        }
        $siteurl = is_string($siteurl) ? trim($siteurl) : '';
        if ($siteurl === '' || !preg_match('#^https?://#i', $siteurl)) {
            return '';
        }

        return untrailingslashit($siteurl);
    }

    /**
     * Reescribe el host de una URL de medio al site público real (GUID WP a menudo apunta a dominios viejos).
     */
    public static function rewrite_media_url_to_public_base(string $url, string $public_base): string {
        $url = trim($url);
        $public_base = untrailingslashit(trim($public_base));
        if ($url === '' || $public_base === '' || !preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (preg_match('#(/wp-content/uploads/.+)$#i', $url, $m)) {
            return $public_base . $m[1];
        }
        $parts = wp_parse_url($url);
        $base = wp_parse_url($public_base);
        if (!is_array($parts) || !is_array($base) || empty($base['host'])) {
            return $url;
        }
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'];
        $port = isset($base['port']) ? ':' . $base['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $port . $path . $query;
    }

    /**
     * Resuelve la URL pública de un attachment en la BD del proyecto (SQL remoto o local).
     * Usa public_site_url / siteurl remoto + _wp_attached_file; el guid crudo suele llevar hosts obsoletos.
     *
     * @param array<string, mixed> $sql_config
     */
    public static function resolve_attachment_url(array $sql_config, int $attachment_id): string {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id < 1) {
            return '';
        }

        $host = trim((string) ($sql_config['host'] ?? ''));
        if ($host === '') {
            $url = wp_get_attachment_url($attachment_id);

            return is_string($url) ? $url : '';
        }

        $db = self::connect($sql_config);
        if (is_wp_error($db)) {
            return '';
        }
        $prefix = self::resolve_table_prefix($sql_config, $db);
        $posts = $prefix . 'posts';
        $meta = $prefix . 'postmeta';
        $public = self::resolve_public_site_url($sql_config, $db);

        // Preferir ruta relativa de uploads + site público.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $attached = $db->get_var($db->prepare(
            "SELECT meta_value FROM `{$meta}` WHERE post_id = %d AND meta_key = '_wp_attached_file' LIMIT 1",
            $attachment_id
        ));
        $attached = is_string($attached) ? ltrim(trim($attached), '/') : '';
        if ($attached !== '' && $public !== '') {
            return $public . '/wp-content/uploads/' . $attached;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $guid = $db->get_var($db->prepare(
            "SELECT guid FROM `{$posts}` WHERE ID = %d AND post_type = 'attachment' LIMIT 1",
            $attachment_id
        ));
        $guid = is_string($guid) ? trim($guid) : '';
        if ($guid !== '' && preg_match('#^https?://#i', $guid)) {
            return $public !== '' ? self::rewrite_media_url_to_public_base($guid, $public) : $guid;
        }

        return '';
    }

    /**
     * Prepara query + prefijo antes de ejecutar.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>|WP_Error
     */
    private static function prepare_config(array $config) {
        $host = trim((string) ($config['host'] ?? ''));
        $sql = (string) ($config['query'] ?? '');

        if ($host === '') {
            if ($sql !== '' && (stripos($sql, '{prefix}') !== false || preg_match('/\bwp_[a-z]/i', $sql))) {
                $config['query'] = self::apply_prefix_to_sql($sql, $config, null);
            }

            return $config;
        }

        $db = self::connect($config);
        if (is_wp_error($db)) {
            return $db;
        }

        $resolved = self::resolve_table_prefix($config, $db);
        $config['prefix'] = $resolved;
        $config['query'] = self::apply_prefix_to_sql($sql, $config, $db);

        return $config;
    }

    private static function normalize_prefix(string $prefix): string {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return '';
        }
        $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix);
        if ($prefix === '') {
            return '';
        }
        if (substr($prefix, -1) !== '_') {
            $prefix .= '_';
        }

        return $prefix;
    }

    /**
     * @param array<string, mixed> $config
     * @return wpdb|WP_Error
     */
    private static function connect($config) {
        if (empty($config['host']) || empty($config['user']) || empty($config['name'])) {
            return new WP_Error('missing_config', 'Faltan datos de conexión (Host, User o Name).');
        }

        $db = new wpdb(
            $config['user'],
            $config['pass'],
            $config['name'],
            $config['host']
        );

        if (!empty($db->error)) {
            return new WP_Error('db_connect_error', 'No se pudo conectar: ' . $db->error->get_error_message());
        }

        return $db;
    }
}
