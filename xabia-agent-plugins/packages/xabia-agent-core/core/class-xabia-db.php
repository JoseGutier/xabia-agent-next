<?php
/**
 * XABIA CORE — gestor de base de datos (tablas de conocimiento y logs).
 */

if (!defined('ABSPATH')) exit;

class Xabia_DB {
    /**
     * Nombre físico de tabla propia de Xabia (sin prefijo `wp_`).
     * Filtro `xabia_db_table` ($name, $key): p. ej. `usage_logs` → `xabia_site_usage_log` cuando WP y el Hub comparten MySQL (mu-plugins).
     * `wallets` usa `xabia_site_wallets`: `xabia_wallets` en el schema del Hub es BIGINT+FK y no debe pasar por dbDelta del sitio.
     *
     * @param string $key Una de: knowledge_vectors, logs, usage_logs, wallets, recharge_history, embeddings, discovery_blocks,
     *                    response_cache, federation_nodes, amelia_bookings, conversions, qr_poi, analytics_events.
     */
    public static function table(string $key): string {
        static $map = [
            'knowledge_vectors'   => 'xabia_knowledge_vectors',
            'logs'                => 'xabia_logs',
            'usage_logs'          => 'xabia_usage_log',
            'wallets'             => 'xabia_site_wallets',
            'recharge_history'    => 'xabia_recharge_history',
            'embeddings'          => 'xabia_embeddings',
            'discovery_blocks'    => 'xabia_discovery_blocks',
            'response_cache'      => 'xabia_response_cache',
            'federation_nodes'    => 'xabia_federation_nodes',
            'amelia_bookings'     => 'xabia_amelia_bookings',
            'conversions'         => 'xabia_conversions',
            'qr_poi'              => 'xabia_qr_poi',
            'analytics_events'    => 'xabia_analytics_events',
        ];
        $k = strtolower(trim($key));
        $name = $map[$k] ?? ('xabia_' . preg_replace('/[^a-z0-9_]/', '', $k));

        $name = (string) apply_filters('xabia_db_table', $name, $k);
        if ($k === 'wallets' && $name === 'xabia_wallets' && self::hub_xabia_wallets_is_shared_with_hub()) {
            return 'xabia_site_wallets';
        }
        if ($k === 'knowledge_vectors' && $name === 'xabia_knowledge_vectors' && self::hub_xabia_knowledge_vectors_is_shared_with_hub()) {
            return 'xabia_site_knowledge_vectors';
        }

        return $name;
    }

    /** @var bool|null */
    private static $hub_xabia_wallets_shared_cache = null;

    /** @var bool|null */
    private static $hub_xabia_kv_shared_cache = null;

    /**
     * True si xabia_knowledge_vectors tiene esquema del Hub (license_id + vector_json): el sitio usa xabia_site_knowledge_vectors.
     */
    public static function hub_xabia_knowledge_vectors_is_shared_with_hub(): bool {
        if (self::$hub_xabia_kv_shared_cache !== null) {
            return self::$hub_xabia_kv_shared_cache;
        }
        global $wpdb;
        if (!isset($wpdb) || !($wpdb instanceof wpdb) || !$wpdb->dbh) {
            self::$hub_xabia_kv_shared_cache = false;

            return false;
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', 'xabia_knowledge_vectors')) !== 'xabia_knowledge_vectors') {
            self::$hub_xabia_kv_shared_cache = false;

            return false;
        }
        $table_esc = esc_sql('xabia_knowledge_vectors');
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$table_esc}`", 0);
        $col_set = [];
        if (is_array($cols)) {
            foreach ($cols as $c) {
                if (is_string($c) && $c !== '') {
                    $col_set[$c] = true;
                }
            }
        }
        self::$hub_xabia_kv_shared_cache = isset($col_set['license_id'], $col_set['vector_json']);

        return self::$hub_xabia_kv_shared_cache;
    }

    /**
     * True si existe xabia_wallets con FK del Hub: dbDelta del sitio no debe tocarla; usar xabia_site_wallets.
     */
    private static function hub_xabia_wallets_is_shared_with_hub(): bool {
        if (self::$hub_xabia_wallets_shared_cache !== null) {
            return self::$hub_xabia_wallets_shared_cache;
        }
        global $wpdb;
        if (!isset($wpdb) || !($wpdb instanceof wpdb) || !$wpdb->dbh) {
            self::$hub_xabia_wallets_shared_cache = false;

            return false;
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', 'xabia_wallets')) !== 'xabia_wallets') {
            self::$hub_xabia_wallets_shared_cache = false;

            return false;
        }
        $db = isset($wpdb->dbname) && is_string($wpdb->dbname) && $wpdb->dbname !== ''
            ? $wpdb->dbname
            : (defined('DB_NAME') ? (string) DB_NAME : '');
        if ($db === '') {
            self::$hub_xabia_wallets_shared_cache = false;

            return false;
        }
        $fk = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
               AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = %s',
            $db,
            'xabia_wallets',
            'fk_xabia_wallet_license',
            'FOREIGN KEY'
        ));
        self::$hub_xabia_wallets_shared_cache = $fk > 0;

        return self::$hub_xabia_wallets_shared_cache;
    }

    public static function init() {
        self::install_tables();
    }

    /**
     * Alias para activación / compatibilidad con hooks que llamen create_tables.
     */
    public static function create_tables() {
        self::install_tables();
    }

    /**
     * Crea o actualiza tablas core con dbDelta (activación y plugins_loaded).
     * Incluye vectores (con columna de federación), logs, caché de embeddings y bloques de descubrimiento.
     */
    public static function install_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table_vectors = self::table('knowledge_vectors');
        $table_logs = self::table('logs');
        $table_usage_logs = self::table('usage_logs');
        $table_wallets = self::table('wallets');
        self::maybe_rename_legacy_wp_wallet_table($table_wallets);

        $table_recharge_history = self::table('recharge_history');
        $table_embeddings = self::table('embeddings');
        $table_discovery = self::table('discovery_blocks');
        $table_response_cache = self::table('response_cache');
        $table_analytics = self::table('analytics_events');

        $sql = "CREATE TABLE $table_vectors (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            project_id varchar(50) NOT NULL,
            content_chunk text NOT NULL,
            meta_data longtext,
            vector_data longtext,
            source_file varchar(255),
            source_record_id varchar(100) DEFAULT NULL,
            content_hash char(32) DEFAULT NULL,
            ente_id varchar(100) DEFAULT 'global',
            federation_node_id varchar(80) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY project_id (project_id),
            KEY ente_id (ente_id),
            KEY federation_node_id (federation_node_id),
            KEY project_source (project_id, source_record_id),
            KEY content_hash (content_hash)
        ) $charset;";

        $sql_logs = "CREATE TABLE $table_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            project_id varchar(50) NOT NULL,
            ente_id varchar(100) DEFAULT 'global',
            user_question text,
            ai_response text,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY project_id (project_id)
        ) $charset;";

        $sql_embeddings = "CREATE TABLE $table_embeddings (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            project_id varchar(50) NOT NULL,
            cache_key varchar(128) NOT NULL,
            vector_json longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY project_cache (project_id, cache_key),
            KEY project_id (project_id)
        ) $charset;";

        $sql_discovery = "CREATE TABLE $table_discovery (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            project_id varchar(50) NOT NULL,
            block_slug varchar(80) NOT NULL,
            payload longtext,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY project_slug (project_id, block_slug)
        ) $charset;";

        $sql_usage_logs = "CREATE TABLE $table_usage_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            project_id varchar(50) NOT NULL,
            model_used varchar(80) NOT NULL,
            tokens_input int(11) NOT NULL DEFAULT 0,
            tokens_output int(11) NOT NULL DEFAULT 0,
            tokens_count int(11) NOT NULL DEFAULT 0,
            estimated_cost decimal(12,6) NOT NULL DEFAULT 0,
            sensitive_detected tinyint(1) NOT NULL DEFAULT 0,
            query_fingerprint varchar(64) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY project_date (project_id, created_at)
        ) $charset;";

        $sql_wallets = "CREATE TABLE $table_wallets (
            license_id varchar(191) NOT NULL,
            license_key_hash varchar(64) DEFAULT '',
            tokens_remaining bigint(20) unsigned NOT NULL DEFAULT 0,
            tokens_used_total bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (license_id),
            KEY license_key_hash (license_key_hash)
        ) $charset;";

        $sql_recharge_history = "CREATE TABLE $table_recharge_history (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            license_id varchar(191) NOT NULL,
            license_key_hash varchar(64) NOT NULL DEFAULT '',
            amount bigint(20) unsigned NOT NULL DEFAULT 0,
            balance_after bigint(20) unsigned NOT NULL DEFAULT 0,
            source varchar(191) NOT NULL DEFAULT 'api_recharge',
            signature_hash varchar(64) NOT NULL DEFAULT '',
            date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY license_date (license_id, date),
            KEY license_key_hash (license_key_hash),
            KEY signature_hash (signature_hash)
        ) $charset;";

        $sql_response_cache = "CREATE TABLE $table_response_cache (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            project_id varchar(50) NOT NULL,
            query_hash varchar(64) NOT NULL,
            response longtext NOT NULL,
            source_type varchar(32) NOT NULL,
            expiry datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY project_hash (project_id, query_hash),
            KEY expiry (expiry)
        ) $charset;";

        $sql_analytics = "CREATE TABLE $table_analytics (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            project_id varchar(50) NOT NULL,
            event_type varchar(32) NOT NULL DEFAULT 'message',
            source varchar(191) NOT NULL DEFAULT 'web',
            qr_id varchar(191) NOT NULL DEFAULT '',
            rag_source varchar(64) NOT NULL DEFAULT '',
            rag_hit tinyint(1) NOT NULL DEFAULT 0,
            feedback varchar(8) NOT NULL DEFAULT '',
            tokens_used int(11) NOT NULL DEFAULT 0,
            lang varchar(16) NOT NULL DEFAULT '',
            visitor_key varchar(64) NOT NULL DEFAULT '',
            outcome varchar(32) NOT NULL DEFAULT '',
            query_excerpt varchar(500) NOT NULL DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY project_time (project_id, created_at),
            KEY project_source (project_id, source),
            KEY project_outcome (project_id, outcome),
            KEY project_lang (project_id, lang)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        dbDelta($sql_logs);
        dbDelta($sql_embeddings);
        dbDelta($sql_discovery);
        dbDelta($sql_usage_logs);
        dbDelta($sql_wallets);
        dbDelta($sql_recharge_history);
        dbDelta($sql_response_cache);
        dbDelta($sql_analytics);
        self::ensure_knowledge_vector_optimizer_columns();
        self::ensure_analytics_events_columns();
    }

    /**
     * Columnas de analítica ampliada (idioma, visitante, outcome, extracto).
     */
    public static function ensure_analytics_events_columns(): void {
        global $wpdb;
        $table = self::table('analytics_events');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $cols_raw = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
        $cols = [];
        if (is_array($cols_raw)) {
            foreach ($cols_raw as $row) {
                if (!empty($row['Field'])) {
                    $cols[(string) $row['Field']] = true;
                }
            }
        }
        $alters = [
            'lang'          => "ADD COLUMN lang varchar(16) NOT NULL DEFAULT '' AFTER tokens_used",
            'visitor_key'   => "ADD COLUMN visitor_key varchar(64) NOT NULL DEFAULT '' AFTER lang",
            'outcome'       => "ADD COLUMN outcome varchar(32) NOT NULL DEFAULT '' AFTER visitor_key",
            'query_excerpt' => "ADD COLUMN query_excerpt varchar(500) NOT NULL DEFAULT '' AFTER outcome",
        ];
        foreach ($alters as $col => $ddl) {
            if (isset($cols[$col])) {
                continue;
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("ALTER TABLE `{$table}` {$ddl}");
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
        $index_names = [];
        if (is_array($indexes)) {
            foreach ($indexes as $idx) {
                if (!empty($idx['Key_name'])) {
                    $index_names[(string) $idx['Key_name']] = true;
                }
            }
        }
        if (!isset($index_names['project_outcome'])) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("ALTER TABLE `{$table}` ADD KEY project_outcome (project_id, outcome)");
        }
        if (!isset($index_names['project_lang'])) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("ALTER TABLE `{$table}` ADD KEY project_lang (project_id, lang)");
        }
    }

    /**
     * Columnas v1.0.61: content_hash + source_record_id (ahorro tokens / upsert incremental).
     */
    public static function ensure_knowledge_vector_optimizer_columns(): void {
        global $wpdb;
        $table = self::table('knowledge_vectors');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }
        $cols = self::knowledge_vectors_column_map();
        $table_esc = esc_sql($table);
        if (!isset($cols['source_record_id'])) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("ALTER TABLE `{$table_esc}` ADD COLUMN source_record_id varchar(100) DEFAULT NULL AFTER source_file");
        }
        if (!isset($cols['content_hash'])) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("ALTER TABLE `{$table_esc}` ADD COLUMN content_hash char(32) DEFAULT NULL AFTER source_record_id");
        }
        self::$knowledge_vectors_column_map_cache = null;
    }

    /** @var array<string, true>|null */
    private static $knowledge_vectors_column_map_cache = null;

    public static function compute_content_hash(string $content_chunk): string {
        if (class_exists('Xabia_Knowledge_Optimizer', false)) {
            return Xabia_Knowledge_Optimizer::content_hash($content_chunk);
        }

        return md5($content_chunk);
    }

    /**
     * @return object|null
     */
    public static function find_knowledge_row_by_source(string $project_id, string $source_record_id) {
        global $wpdb;
        $project_id = sanitize_key($project_id);
        $source_record_id = sanitize_text_field($source_record_id);
        if ($project_id === '' || $source_record_id === '') {
            return null;
        }
        $cols = self::knowledge_vectors_column_map();
        if (!isset($cols['source_record_id'])) {
            return null;
        }
        $table = self::table('knowledge_vectors');
        $vec_col = self::knowledge_vectors_vector_column();
        $meta_col = self::knowledge_vectors_meta_column();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, content_chunk, content_hash, source_record_id, ente_id, {$vec_col} AS vector_col, `{$meta_col}` AS meta_blob FROM {$table}
             WHERE project_id = %s AND source_record_id = %s LIMIT 1",
            $project_id,
            $source_record_id
        ));
    }

    /**
     * @return object|null
     */
    public static function find_knowledge_row_by_ente(string $project_id, string $ente_id) {
        global $wpdb;
        $project_id = sanitize_key($project_id);
        $canonical = class_exists('Xabia_Knowledge_Ingest', false)
            ? Xabia_Knowledge_Ingest::canonical_slug($ente_id)
            : sanitize_title($ente_id);
        if ($project_id === '' || $canonical === '' || $canonical === 'global') {
            return null;
        }
        $cols = self::knowledge_vectors_column_map();
        if (!isset($cols['ente_id'])) {
            return null;
        }
        $table = self::table('knowledge_vectors');
        $vec_col = self::knowledge_vectors_vector_column();
        $meta_col = self::knowledge_vectors_meta_column();
        $select_source = isset($cols['source_record_id']) ? ', source_record_id' : '';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, content_chunk, content_hash, ente_id{$select_source}, {$vec_col} AS vector_col, `{$meta_col}` AS meta_blob FROM {$table}
             WHERE project_id = %s
               AND (
                    ente_id = %s
                    OR REPLACE(LOWER(ente_id), '_', '-') = %s
                    OR source_record_id = %s
                    OR REPLACE(LOWER(source_record_id), '_', '-') = %s
               )
             ORDER BY (ente_id = %s) DESC,
                      (source_record_id REGEXP '^[0-9]+$') ASC,
                      id DESC
             LIMIT 1",
            $project_id,
            $canonical,
            $canonical,
            $canonical,
            $canonical,
            $canonical
        ));
    }

    /**
     * @param array<string, mixed> $meta_array
     */
    public static function update_knowledge_meta_only(int $row_id, array $meta_array): bool {
        global $wpdb;
        $table = self::table('knowledge_vectors');
        $meta_col = self::knowledge_vectors_meta_column();
        $enc = wp_json_encode($meta_array, JSON_UNESCAPED_UNICODE);
        if ($enc === false) {
            $enc = '{}';
        }

        return $wpdb->update($table, [$meta_col => $enc], ['id' => $row_id], ['%s'], ['%d']) !== false;
    }

    /**
     * @param array<string, mixed> $meta_array
     */
    public static function update_knowledge_content(int $row_id, string $content_chunk, array $meta_array, string $content_hash, array $identity = []): bool {
        global $wpdb;
        $table = self::table('knowledge_vectors');
        $meta_col = self::knowledge_vectors_meta_column();
        $vec_col = self::knowledge_vectors_vector_column();
        $cols = self::knowledge_vectors_column_map();
        $meta_enc = wp_json_encode($meta_array, JSON_UNESCAPED_UNICODE);
        if ($meta_enc === false) {
            $meta_enc = '{}';
        }
        $data = [
            'content_chunk' => $content_chunk,
            $meta_col       => $meta_enc,
            'content_hash'  => $content_hash,
            $vec_col        => $vec_col === 'vector_json' ? '[]' : null,
        ];
        if (isset($cols['ente_id'], $identity['ente_id'])) {
            $eid = sanitize_title((string) $identity['ente_id']);
            if ($eid !== '') {
                $data['ente_id'] = $eid;
            }
        }
        if (isset($cols['source_record_id'], $identity['source_record_id'])) {
            $sid = sanitize_text_field((string) $identity['source_record_id']);
            if ($sid !== '') {
                $data['source_record_id'] = $sid;
            }
        }

        return $wpdb->update($table, $data, ['id' => $row_id]) !== false;
    }

    /**
     * Migra ente_id / source_record_id sin tocar embeddings ni content_chunk.
     *
     * @param array<string, string> $identity
     * @param array<string, mixed> $meta_array
     */
    public static function update_knowledge_identity(int $row_id, array $identity, array $meta_array): bool {
        global $wpdb;
        $table = self::table('knowledge_vectors');
        $cols = self::knowledge_vectors_column_map();
        $meta_col = self::knowledge_vectors_meta_column();
        $meta_enc = wp_json_encode($meta_array, JSON_UNESCAPED_UNICODE);
        if ($meta_enc === false) {
            $meta_enc = '{}';
        }
        $data = [$meta_col => $meta_enc];
        if (isset($cols['ente_id'], $identity['ente_id'])) {
            $eid = sanitize_title((string) $identity['ente_id']);
            if ($eid !== '') {
                $data['ente_id'] = $eid;
            }
        }
        if (isset($cols['source_record_id'], $identity['source_record_id'])) {
            $sid = sanitize_text_field((string) $identity['source_record_id']);
            if ($sid !== '') {
                $data['source_record_id'] = $sid;
            }
        }

        return $wpdb->update($table, $data, ['id' => $row_id]) !== false;
    }

    /**
     * Filas que necesitan embedding (sin vector o contenido cambiado).
     */
    public static function knowledge_vectors_sql_needs_embedding(): string {
        $pending = self::knowledge_vectors_sql_pending_embedding();
        $cols = self::knowledge_vectors_column_map();
        if (!isset($cols['content_hash'])) {
            return "({$pending})";
        }

        return "({$pending} OR (content_hash IS NOT NULL AND content_hash != MD5(content_chunk)))";
    }

    /**
     * Sitios antiguos con caché local en xabia_wallets (varchar, sin FK). Renombra a xabia_site_wallets.
     * Si xabia_wallets es la tabla del Hub (FK fk_xabia_wallet_license), no tocar: dbDelta creará xabia_site_wallets aparte.
     */
    private static function maybe_rename_legacy_wp_wallet_table(string $target_table): void {
        global $wpdb;
        if ($target_table !== 'xabia_site_wallets') {
            return;
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $target_table)) === $target_table) {
            return;
        }
        $legacy = 'xabia_wallets';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy)) !== $legacy) {
            return;
        }
        if (self::hub_xabia_wallets_is_shared_with_hub()) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nombres de tabla literales validados.
        $wpdb->query("RENAME TABLE `{$legacy}` TO `{$target_table}`");
    }

    public static function wallet_license_id(): string {
        return self::wallet_license_id_for_key(trim((string) get_option('xabia_digixop_license_key', '')));
    }

    public static function wallet_license_id_for_key(string $license_key): string {
        $cached = class_exists('Xabia_Digixop_Client', false) ? Xabia_Digixop_Client::get_cached_license_meta() : null;
        $configured = trim((string) get_option('xabia_digixop_license_key', ''));
        if ($license_key === $configured && is_array($cached)) {
            $payload = isset($cached['license_validate_response']) && is_array($cached['license_validate_response'])
                ? $cached['license_validate_response']
                : $cached;
            foreach (['wallet_license_id', 'license_id', 'id'] as $key) {
                if (!empty($payload[$key])) {
                    return sanitize_text_field((string) $payload[$key]);
                }
            }
        }

        if ($license_key !== '') {
            return 'lic_' . substr(hash('sha256', $license_key), 0, 24);
        }

        $site = function_exists('home_url') ? home_url('/') : 'local';
        return 'site_' . substr(hash('sha256', (string) $site), 0, 24);
    }

    /**
     * Columnas reales de la tabla de vectores (nombre de tabla vía xabia_db_table).
     * El Hub usa license_id, meta_json, vector_json; el plugin histórico usa meta_data, vector_data.
     *
     * @return array<string, true>
     */
    public static function knowledge_vectors_column_map(): array {
        if (self::$knowledge_vectors_column_map_cache !== null) {
            return self::$knowledge_vectors_column_map_cache;
        }
        global $wpdb;
        $cache = null;
        if (!$wpdb instanceof wpdb) {
            return [];
        }
        $table = self::table('knowledge_vectors');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return [];
        }
        $table_esc = esc_sql($table);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SHOW COLUMNS; nombre validado.
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$table_esc}`", 0);
        $cache = [];
        if (is_array($cols)) {
            foreach ($cols as $c) {
                if (is_string($c) && $c !== '') {
                    $cache[$c] = true;
                }
            }
        }
        self::$knowledge_vectors_column_map_cache = $cache;

        return $cache;
    }

    /**
     * PK numérica en xabia_licenses para FK license_id cuando la tabla de vectores es la del Hub.
     */
    public static function resolve_hub_license_numeric_id(): int {
        global $wpdb;
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }
        $resolved = 0;
        if (class_exists('Xabia_Digixop_Client', false)) {
            $cached = Xabia_Digixop_Client::get_cached_license_meta();
            if (is_array($cached)) {
                $payload = isset($cached['license_validate_response']) && is_array($cached['license_validate_response'])
                    ? $cached['license_validate_response']
                    : $cached;
                foreach (['wallet_license_id', 'license_id'] as $k) {
                    if (!empty($payload[$k]) && is_numeric($payload[$k])) {
                        $n = (int) $payload[$k];
                        if ($n > 0) {
                            $resolved = $n;

                            return $resolved;
                        }
                    }
                }
            }
        }
        if (!$wpdb instanceof wpdb) {
            return $resolved;
        }
        $key = trim((string) get_option('xabia_digixop_license_key', ''));
        if ($key === '') {
            return $resolved;
        }
        $lic_table = (string) apply_filters('xabia_hub_licenses_table', 'xabia_licenses');
        $lic_table = preg_replace('/[^a-zA-Z0-9_]/', '', $lic_table);
        if ($lic_table === '') {
            $lic_table = 'xabia_licenses';
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lic_table)) !== $lic_table) {
            return $resolved;
        }
        $lic_esc = esc_sql($lic_table);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla validada.
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(id) FROM `{$lic_esc}` WHERE license_key = %s",
            $key
        ));
        if ($row !== null && $row !== '' && is_numeric($row)) {
            $resolved = (int) $row;
        }

        return $resolved;
    }

    /**
     * Inserta una fila en xabia_knowledge_vectors respetando el esquema (plugin local o tabla Hub).
     *
     * @param array<string, mixed> $meta_array
     * @param array{source_file?: string, federation_node_id?: string|null} $extras
     */
    public static function insert_knowledge_vector_row(
        string $project_id,
        string $ente_id,
        string $content_chunk,
        array $meta_array,
        array $extras = []
    ): bool {
        global $wpdb;
        $table = self::table('knowledge_vectors');
        $cols = self::knowledge_vectors_column_map();
        if ($cols === [] || !isset($cols['project_id'], $cols['content_chunk'])) {
            return false;
        }

        $chunk = trim($content_chunk);
        if ($chunk === '') {
            return false;
        }

        $data = [];
        $format = [];

        $data['project_id'] = $project_id;
        $format[] = '%s';

        if (isset($cols['ente_id'])) {
            $data['ente_id'] = $ente_id !== '' ? $ente_id : 'global';
            $format[] = '%s';
        }

        $data['content_chunk'] = $chunk;
        $format[] = '%s';

        $meta_enc = wp_json_encode($meta_array, JSON_UNESCAPED_UNICODE);
        if ($meta_enc === false) {
            $meta_enc = '{}';
        }

        if (isset($cols['meta_json'])) {
            $data['meta_json'] = $meta_enc;
            $format[] = '%s';
        } elseif (isset($cols['meta_data'])) {
            $data['meta_data'] = $meta_enc;
            $format[] = '%s';
        }

        if (isset($cols['vector_json'])) {
            $data['vector_json'] = '[]';
            $format[] = '%s';
        } elseif (isset($cols['vector_data'])) {
            $data['vector_data'] = null;
            $format[] = '%s';
        }

        if (isset($cols['license_id'])) {
            $lid = self::resolve_hub_license_numeric_id();
            if ($lid < 1) {
                return false;
            }
            $data['license_id'] = $lid;
            $format[] = '%d';
        }

        if (isset($cols['source_file']) && isset($extras['source_file'])) {
            $data['source_file'] = (string) $extras['source_file'];
            $format[] = '%s';
        }

        if (isset($cols['federation_node_id']) && array_key_exists('federation_node_id', $extras)) {
            $fn = $extras['federation_node_id'];
            $data['federation_node_id'] = $fn !== null && $fn !== '' ? (string) $fn : null;
            $format[] = '%s';
        }

        if (isset($cols['source_record_id']) && !empty($extras['source_record_id'])) {
            $data['source_record_id'] = (string) $extras['source_record_id'];
            $format[] = '%s';
        }

        if (isset($cols['content_hash']) && !empty($extras['content_hash'])) {
            $data['content_hash'] = (string) $extras['content_hash'];
            $format[] = '%s';
        }

        $res = $wpdb->insert($table, $data, $format);

        return $res !== false;
    }

    /**
     * Columna física de embeddings en knowledge_vectors (Hub: vector_json; plugin local: vector_data).
     */
    public static function knowledge_vectors_vector_column(): string {
        $m = self::knowledge_vectors_column_map();

        return isset($m['vector_json']) ? 'vector_json' : 'vector_data';
    }

    /**
     * Columna física de metadatos (Hub: meta_json; plugin local: meta_data).
     */
    public static function knowledge_vectors_meta_column(): string {
        $m = self::knowledge_vectors_column_map();

        return isset($m['meta_json']) ? 'meta_json' : 'meta_data';
    }

    /**
     * Filas sin embedding utilizable (NULL, vacío, [] o literal "null").
     */
    public static function knowledge_vectors_sql_lacks_valid_embedding(): string {
        $c = self::knowledge_vectors_vector_column();

        return "({$c} IS NULL OR TRIM(COALESCE({$c},'')) = '' OR TRIM({$c}) = '[]' OR LOWER(TRIM({$c})) = 'null')";
    }

    /**
     * Fragmento SQL AND… para filas aún sin embedding útil (entrenamiento pendiente).
     */
    public static function knowledge_vectors_sql_pending_embedding(): string {
        return self::knowledge_vectors_sql_lacks_valid_embedding();
    }

    /**
     * Fragmento SQL AND… para filas con embedding almacenado (no placeholder vacío).
     */
    public static function knowledge_vectors_sql_has_embedding(): string {
        return 'NOT (' . self::knowledge_vectors_sql_lacks_valid_embedding() . ')';
    }

    /**
     * Fragmento SQL AND… para filas con texto utilizable en el chat (sin embedding).
     */
    public static function knowledge_vectors_sql_has_usable_content(): string {
        return "(content_chunk IS NOT NULL AND TRIM(content_chunk) <> '')";
    }

    /**
     * Tabla para listados de catálogo EMPRESA: si Hub y WP comparten MySQL, usa xabia_knowledge_store (68 filas)
     * en lugar de xabia_site_knowledge_vectors (cerebro de sync parcial del sitio).
     */
    public static function resolve_knowledge_catalog_table(string $project_id): string {
        global $wpdb;
        $project_id = trim(sanitize_text_field($project_id));
        $site_table = self::table('knowledge_vectors');
        if ($project_id === '' || !isset($wpdb) || !($wpdb instanceof wpdb)) {
            return $site_table;
        }

        $store_table = 'xabia_knowledge_store';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $store_table)) !== $store_table) {
            return $site_table;
        }

        $hub_enabled = class_exists('Xabia_Hub_Knowledge', false)
            && Xabia_Hub_Knowledge::is_hub_rag_enabled($project_id);
        if (!$hub_enabled) {
            return $site_table;
        }

        $store_count = self::count_catalog_empresa_rows($project_id, $store_table);
        if ($store_count < 1) {
            return $site_table;
        }

        $site_count = self::count_catalog_empresa_rows($project_id, $site_table);
        if ($store_count >= $site_count) {
            return $store_table;
        }

        return $site_table;
    }

    public static function uses_co_located_hub_store(string $project_id): bool {
        return self::resolve_knowledge_catalog_table($project_id) === 'xabia_knowledge_store';
    }

    /**
     * Empresas distintas (ente_id) con ficha EMPRESA en una tabla de conocimiento.
     */
    public static function count_catalog_empresa_rows(string $project_id, ?string $table = null): int {
        global $wpdb;
        $project_id = trim(sanitize_text_field($project_id));
        if ($project_id === '' || !isset($wpdb) || !($wpdb instanceof wpdb)) {
            return 0;
        }
        $table = $table ?? self::resolve_knowledge_catalog_table($project_id);
        $table = preg_replace('/[^a-z0-9_]/i', '', (string) $table);
        if ($table === '') {
            return 0;
        }

        return max(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT ente_id) FROM `{$table}` WHERE project_id = %s AND content_chunk LIKE %s",
            $project_id,
            '%EMPRESA:%'
        )));
    }

    public static function sync_wallet_balance(?int $tokens_remaining = null): void {
        global $wpdb;
        $table = self::table('wallets');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            self::install_tables();
        }
        if ($tokens_remaining === null) {
            $cached = class_exists('Xabia_Digixop_Client', false) ? Xabia_Digixop_Client::get_cached_license_meta() : null;
            if (is_array($cached) && isset($cached['tokens_remaining']) && is_numeric($cached['tokens_remaining'])) {
                $tokens_remaining = (int) $cached['tokens_remaining'];
            }
        }
        if ($tokens_remaining === null) {
            return;
        }
        $license = trim((string) get_option('xabia_digixop_license_key', ''));
        $license_hash = $license !== '' ? hash('sha256', $license) : '';
        $license_id = self::wallet_license_id();
        $now = gmdate('Y-m-d H:i:s');
        $exists = $wpdb->get_var($wpdb->prepare("SELECT license_id FROM $table WHERE license_id = %s", $license_id));
        if ($exists) {
            $wpdb->update($table, [
                'license_key_hash' => $license_hash,
                'tokens_remaining' => max(0, $tokens_remaining),
                'updated_at' => $now,
            ], ['license_id' => $license_id]);
            return;
        }
        $wpdb->insert($table, [
            'license_id' => $license_id,
            'license_key_hash' => $license_hash,
            'tokens_remaining' => max(0, $tokens_remaining),
            'tokens_used_total' => 0,
            'updated_at' => $now,
        ]);
    }

    public static function deduct_wallet_tokens(int $tokens): void {
        if ($tokens < 1) {
            return;
        }
        global $wpdb;
        $table = self::table('wallets');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            self::install_tables();
        }
        $license_id = self::wallet_license_id();
        $now = gmdate('Y-m-d H:i:s');
        $exists = $wpdb->get_var($wpdb->prepare("SELECT license_id FROM $table WHERE license_id = %s", $license_id));
        if (!$exists) {
            $initial = 0;
            $cached = class_exists('Xabia_Digixop_Client', false) ? Xabia_Digixop_Client::get_cached_license_meta() : null;
            if (is_array($cached) && isset($cached['tokens_remaining']) && is_numeric($cached['tokens_remaining'])) {
                $initial = max(0, (int) $cached['tokens_remaining']);
            }
            $wpdb->insert($table, [
                'license_id' => $license_id,
                'license_key_hash' => '',
                'tokens_remaining' => $initial,
                'tokens_used_total' => 0,
                'updated_at' => $now,
            ]);
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE $table
             SET tokens_remaining = GREATEST(0, tokens_remaining - %d),
                 tokens_used_total = tokens_used_total + %d,
                 updated_at = %s
             WHERE license_id = %s",
            $tokens,
            $tokens,
            $now,
            $license_id
        ));
    }
}