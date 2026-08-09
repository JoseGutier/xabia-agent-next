<?php
/**
 * Xabia — sincronización de conocimiento por proyecto (manual, cron e inmediata).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Knowledge_Sync {

    public const FORCE_FULL_SYNC_TRANSIENT_PREFIX = 'xabia_knowledge_force_full_sync_';

    /**
     * Tras «Borrar memoria» o tabla vacía: importación completa (sin filtro post_modified).
     */
    public static function wants_incremental_sync(string $project_id): bool {
        $project_id = sanitize_key($project_id);
        if ($project_id === '') {
            return false;
        }
        if (get_transient(self::FORCE_FULL_SYNC_TRANSIENT_PREFIX . $project_id)) {
            return false;
        }
        if (!class_exists('Xabia_DB', false)) {
            return true;
        }
        global $wpdb;
        $t = Xabia_DB::table('knowledge_vectors');
        $stored = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE project_id = %s",
            $project_id
        ));

        return $stored > 0;
    }

    public static function flag_force_full_sync(string $project_id): void {
        $project_id = sanitize_key($project_id);
        if ($project_id === '') {
            return;
        }
        set_transient(self::FORCE_FULL_SYNC_TRANSIENT_PREFIX . $project_id, 1, HOUR_IN_SECONDS);
        if (class_exists('Xabia_Auto_Sync', false)) {
            $state = Xabia_Auto_Sync::get_state();
            if (isset($state[$project_id]) && is_array($state[$project_id])) {
                unset($state[$project_id]['last_successful_sync']);
                update_option(Xabia_Auto_Sync::OPTION_STATE, $state, false);
            }
        }
    }

    /**
     * @param array{incremental?:bool} $args incremental=true evita borrado masivo y filtra SQL por last_successful_sync.
     * @return array{count:int,message:string,skipped_tokens?:int}
     * @throws Exception
     */
    public static function run_project(string $project_id, array $args = []): array {
        $incremental = !empty($args['incremental']);
        $sync_stats = ['content_updated' => 0, 'inserted' => 0, 'unchanged' => 0];
        $sync_opts = ['incremental' => $incremental, 'stats' => &$sync_stats];
        $project_id = sanitize_key($project_id);
        if ($project_id === '') {
            throw new Exception(__('Project ID inválido.', 'xabia-intelligence'));
        }

        $projects = get_option('xabia_projects_config', []);
        if (!isset($projects[$project_id]) || !is_array($projects[$project_id])) {
            throw new Exception(__('Proyecto no encontrado.', 'xabia-intelligence'));
        }

        $config = $projects[$project_id];
        if (!empty($config['paused'])) {
            throw new Exception(__('El agente está pausado.', 'xabia-intelligence'));
        }
        if (self::project_sync_blocked($config)) {
            throw new Exception(__('Requiere suscripción activa del addon en el Hub.', 'xabia-intelligence'));
        }

        if (!class_exists('Xabia_DB_Bridge', false)) {
            require_once XABIA_PATH . 'core/class-xabia-db-bridge.php';
        }

        if (($config['source_type'] ?? '') === 'multi' && !empty($config['sources']) && is_array($config['sources'])) {
            global $wpdb;
            if (!$incremental) {
                $wpdb->delete(Xabia_DB::table('knowledge_vectors'), ['project_id' => $project_id]);
            }
            $total = 0;
            foreach ($config['sources'] as $src) {
                $type = $src['type'] ?? '';
                $attrs = $src['attributes'] ?? [];
                if ($type === 'csv') {
                    $csv_file = $src['csv_filename'] ?? '';
                    if ($csv_file !== '') {
                        $file_path = self::resolve_project_csv_path($project_id, (string) $csv_file);
                        if ($file_path !== '' && file_exists($file_path)) {
                            $total += Xabia_DB_Bridge::process_csv_knowledge($project_id, $file_path, $attrs);
                        }
                    }
                } elseif ($type === 'sql' || $type === 'local_sql') {
                    $sql_config = $src['sql_config'] ?? [];
                    if (!empty($sql_config['query'])) {
                        $sql_config['prefix'] = $sql_config['prefix'] ?? '';
                        $host = $sql_config['host'] ?? '';
                        if ($type === 'local_sql') {
                            $sql_config['host'] = '';
                            $sql_config['query'] = str_replace('{prefix}', $GLOBALS['wpdb']->prefix, $sql_config['query']);
                        } elseif ($host !== '') {
                            if (!class_exists('Xabia_SQL_Connector', false)) {
                                $cpath = defined('XABIA_PATH')
                                    ? XABIA_PATH . 'integrations/class-xabia-sql-connector.php'
                                    : '';
                                if ($cpath !== '' && is_readable($cpath)) {
                                    require_once $cpath;
                                }
                            }
                            if (class_exists('Xabia_SQL_Connector', false)) {
                                $sql_config['query'] = Xabia_SQL_Connector::apply_prefix_to_sql(
                                    (string) $sql_config['query'],
                                    $sql_config
                                );
                                $sql_config['prefix'] = Xabia_SQL_Connector::resolve_table_prefix($sql_config);
                            } else {
                                $rdb = new wpdb($sql_config['user'], $sql_config['pass'], $sql_config['name'], $host);
                                $manual_prefix = isset($sql_config['prefix']) ? trim((string) $sql_config['prefix']) : '';
                                $prefix = $manual_prefix !== '' ? $manual_prefix : (self::find_active_prefix($rdb) ?: 'wp_');
                                $sql_config['query'] = str_replace('{prefix}', $prefix, $sql_config['query']);
                                $sql_config['prefix'] = $prefix;
                            }
                        } else {
                            $sql_config['query'] = str_replace('{prefix}', $GLOBALS['wpdb']->prefix, $sql_config['query']);
                        }
                        $total += Xabia_DB_Bridge::process_sql_knowledge($project_id, $sql_config, $attrs, $sync_opts);
                    }
                }
            }
            do_action('xabia_after_knowledge_sync', $project_id, $config, (int) $total);
            if ($incremental && class_exists('Xabia_Knowledge_Optimizer', false)) {
                Xabia_Knowledge_Optimizer::mark_last_successful_sync($project_id);
            }

            return self::finalize_sync_result($project_id, $config, (int) $total, $incremental, $sync_stats, sprintf(
                $incremental
                    ? __('Sincronización incremental: %d registros procesados (sin borrar memoria).', 'xabia-intelligence')
                    : __('Sincronizados (multi-fuente): %d', 'xabia-intelligence'),
                (int) $total
            ));
        }

        if (($config['source_type'] ?? '') === 'addon') {
            $custom_sync = apply_filters('xabia_addon_sync_result', null, $project_id, $config);
            if (is_array($custom_sync) && isset($custom_sync['count'])) {
                $count = (int) $custom_sync['count'];
                do_action('xabia_after_knowledge_sync', $project_id, $config, $count);

                return [
                    'count'   => $count,
                    'message' => sprintf(__('Sincronizados: %d', 'xabia-intelligence'), $count),
                ];
            }
            $central_slug = defined('XABIA_CENTRAL_SLUG') ? XABIA_CENTRAL_SLUG : 'xabia_central';
            if (($config['addon_slug'] ?? '') === $central_slug) {
                if (!class_exists('Xabia_Central', false)) {
                    throw new Exception(__('Federación Xabia Central no disponible en este sitio.', 'xabia-intelligence'));
                }
                $count = (int) Xabia_Central::run_sync($project_id);
                do_action('xabia_after_knowledge_sync', $project_id, $config, $count);

                return [
                    'count'   => $count,
                    'message' => sprintf(__('Sincronizados: %d', 'xabia-intelligence'), $count),
                ];
            }
        }

        global $wpdb;
        if (!$incremental) {
            $wpdb->delete(Xabia_DB::table('knowledge_vectors'), ['project_id' => $project_id]);
        }
        $count = 0;

        if (($config['source_type'] ?? '') === 'addon') {
            global $xabia_available_addons;
            $addons = array_merge((array) $xabia_available_addons, (array) apply_filters('xabia_register_sql_sources', []));
            $slug = (string) ($config['addon_slug'] ?? '');
            if ($slug === '' || !isset($addons[$slug]['callback'])) {
                throw new Exception(__('Addon de datos no disponible.', 'xabia-intelligence'));
            }
            $sql = call_user_func($addons[$slug]['callback']);
            $host = $config['sql_config']['host'] ?? '';
            // Catálogo local (Woo/MEC en el mismo WP): usar $wpdb global, no credenciales DB_*,
            // para respetar el prefijo real de tablas (p. ej. WPML icl_translations).
            $sql_config = !empty($host) ? ($config['sql_config'] ?? []) : ['host' => ''];

            if (!empty($host)) {
                $rdb = new wpdb($sql_config['user'], $sql_config['pass'], $sql_config['name'], $host);
                $manual_prefix = isset($sql_config['prefix']) ? trim((string) $sql_config['prefix']) : '';
                $prefix = $manual_prefix !== '' ? $manual_prefix : (self::find_active_prefix($rdb) ?: 'wp_');
                $sql = str_replace('{prefix}', $prefix, $sql);
            } else {
                global $wpdb;
                $sql = str_replace('{prefix}', $wpdb->prefix, $sql);
            }
            $sql_config['query'] = $sql;
            $count = Xabia_DB_Bridge::process_sql_knowledge($project_id, $sql_config, null, $sync_opts);
        } elseif (($config['source_type'] ?? '') === 'local_sql' || ($config['source_type'] ?? '') === 'sql') {
            if (($config['source_type'] ?? '') === 'local_sql') {
                $local_sql = $config['sql_config'] ?? [];
                $local_sql['host'] = '';
                $count = Xabia_DB_Bridge::process_sql_knowledge($project_id, $local_sql, null, $sync_opts);
            } else {
                $count = Xabia_DB_Bridge::process_sql_knowledge($project_id, $config['sql_config'] ?? [], null, $sync_opts);
            }
        } else {
            $file_path = self::resolve_project_csv_path($project_id, (string) ($config['csv_filename'] ?? ''));
            if ($file_path !== '' && file_exists($file_path)) {
                $count = Xabia_DB_Bridge::process_csv_knowledge($project_id, $file_path);
            }
        }

        do_action('xabia_after_knowledge_sync', $project_id, $config, (int) $count);
        if (!$incremental) {
            delete_transient(self::FORCE_FULL_SYNC_TRANSIENT_PREFIX . $project_id);
        }
        if (class_exists('Xabia_Knowledge_Optimizer', false)) {
            Xabia_Knowledge_Optimizer::mark_last_successful_sync($project_id);
        }

        return self::finalize_sync_result($project_id, $config, (int) $count, $incremental, $sync_stats);
    }

    /**
     * @param array<string, mixed> $config
     * @param array{content_updated?:int,inserted?:int} $sync_stats
     * @return array{count:int,message:string,orphans:list<array{id:int,source_record_id:string,label:string}>,content_updated:int,inserted:int}
     */
    private static function finalize_sync_result(
        string $project_id,
        array $config,
        int $count,
        bool $incremental,
        array $sync_stats,
        ?string $message = null
    ): array {
        $content_updated = (int) ($sync_stats['content_updated'] ?? 0);
        $inserted = (int) ($sync_stats['inserted'] ?? 0);
        $unchanged = (int) ($sync_stats['unchanged'] ?? 0);
        if ($unchanged === 0 && $count > 0) {
            $unchanged = max(0, $count - $inserted - $content_updated);
        }
        $orphans = class_exists('Xabia_Knowledge_Orphans', false)
            ? Xabia_Knowledge_Orphans::find_after_sync($project_id, $config)
            : [];

        $catalog_size = null;
        if (class_exists('Xabia_Knowledge_Orphans', false)) {
            $catalog_ids = Xabia_Knowledge_Orphans::fetch_catalog_source_ids($project_id, $config);
            if (is_array($catalog_ids)) {
                $catalog_size = count($catalog_ids);
            }
        }

        if ($message === null) {
            $entity_plural = class_exists('Xabia_Knowledge_Ingest', false)
                ? Xabia_Knowledge_Ingest::resolve_catalog_entity_plural_label($config)
                : __('entes', 'xabia-intelligence');
            $is_mec = self::is_mec_catalog_config($config);
            if ($is_mec && $incremental) {
                if ($count === 0) {
                    $message = __('Conexión OK, pero no hay eventos MEC con fecha de inicio ≥ hoy en la base remota.', 'xabia-intelligence');
                } else {
                    $message = sprintf(
                        /* translators: 1: total read, 2: new inserts, 3: content updates, 4: unchanged */
                        __('Catálogo MEC re-leído (%1$d próximos) sin borrar memoria: %2$d nuevos, %3$d con texto cambiado (pendientes de entrenar), %4$d iguales (sin gastar tokens de embedding).', 'xabia-intelligence'),
                        $count,
                        $inserted,
                        $content_updated,
                        $unchanged
                    );
                }
            } elseif ($incremental && $count === 0) {
                $message = __('Sincronización incremental: 0 registros nuevos o modificados desde la última sync. Si esperabas importación completa, pulsa «Borrar memoria vectorial» y vuelve a sincronizar.', 'xabia-intelligence');
            } elseif ($incremental) {
                $message = sprintf(
                    __('Sincronización incremental: %d registros procesados (sin borrar memoria).', 'xabia-intelligence'),
                    $count
                );
            } elseif ($count === 0 && $is_mec) {
                $message = __('Conexión OK, pero no hay eventos MEC con fecha de inicio ≥ hoy en la base remota. El catálogo de próximos eventos está vacío.', 'xabia-intelligence');
            } else {
                $message = sprintf(
                    /* translators: 1: count, 2: entity plural label (productos, eventos, entes…) */
                    __('Sincronización completa: %1$d %2$s en idioma principal.', 'xabia-intelligence'),
                    $count,
                    $entity_plural
                );
            }
        }

        $entity_plural = class_exists('Xabia_Knowledge_Ingest', false)
            ? Xabia_Knowledge_Ingest::resolve_catalog_entity_plural_label($config)
            : __('entes', 'xabia-intelligence');
        $parts = [$message];
        if ($catalog_size !== null && $catalog_size > 0 && $count > 0 && $count < $catalog_size) {
            $parts[] = sprintf(
                /* translators: 1: count in source, 2: entity plural label */
                __('Fuente del catálogo: %1$d %2$s publicados; revisa si faltan filas (sync incremental previo o filtros).', 'xabia-intelligence'),
                $catalog_size,
                $entity_plural
            );
        } elseif ($catalog_size !== null && $catalog_size > 0 && abs($count - $catalog_size) <= 2) {
            $parts[] = sprintf(
                /* translators: 1: count, 2: entity plural label */
                __('Catálogo alineado (%1$d %2$s en idioma principal).', 'xabia-intelligence'),
                $catalog_size,
                $entity_plural
            );
        }
        if ($content_updated > 0) {
            $parts[] = sprintf(
                _n(
                    '%d registro con contenido actualizado (re-entrenar si aplica).',
                    '%d registros con contenido actualizado (re-entrenar si aplica).',
                    $content_updated,
                    'xabia-intelligence'
                ),
                $content_updated
            );
        }
        if ($inserted > 0) {
            $parts[] = sprintf(
                _n('%d registro nuevo.', '%d registros nuevos.', $inserted, 'xabia-intelligence'),
                $inserted
            );
        }
        if (class_exists('Xabia_Knowledge_Orphans', false)) {
            $reconcile = Xabia_Knowledge_Orphans::get_last_reconcile_stats();
            $removed = (int) ($reconcile['deleted'] ?? 0) + (int) ($reconcile['purged_ghosts'] ?? 0);
            $normalized = (int) ($reconcile['migrated'] ?? 0) + (int) ($reconcile['fixed'] ?? 0);
            if ($removed > 0) {
                $parts[] = sprintf(
                    __('Duplicados o registros obsoletos eliminados: %d.', 'xabia-intelligence'),
                    $removed
                );
            } elseif ($normalized > 0 && $inserted > 0 && $normalized >= $inserted) {
                $parts[] = __('Identificadores normalizados a slug (listo para entrenar).', 'xabia-intelligence');
            }
        }

        if (class_exists('Xabia_DB', false)) {
            global $wpdb;
            $t = Xabia_DB::table('knowledge_vectors');
            $stored_after = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$t} WHERE project_id = %s",
                sanitize_key($project_id)
            ));
            if ($stored_after > 0) {
                $parts[] = sprintf(__('Memoria local: %d registros.', 'xabia-intelligence'), $stored_after);
            } elseif ($inserted > 0 || $count > 0) {
                $parts[] = __('AVISO: la memoria local quedó vacía tras la sincronización. Actualiza el plugin a la última versión y vuelve a sincronizar.', 'xabia-intelligence');
            }
        }

        return [
            'count'            => $count,
            'message'          => implode(' ', $parts),
            'orphans'          => $orphans,
            'content_updated'  => $content_updated,
            'inserted'         => $inserted,
            'unchanged'        => $unchanged,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function project_sync_blocked(array $config): bool {
        if (($config['source_type'] ?? '') !== 'addon') {
            return false;
        }
        $slug = (string) ($config['addon_slug'] ?? '');
        if ($slug === 'mec' && function_exists('xabia_mec_license_gate') && !xabia_mec_license_gate()) {
            return true;
        }
        if ($slug === 'woo' && function_exists('xabia_woo_license_gate') && !xabia_woo_license_gate()) {
            return true;
        }

        return false;
    }

    /**
     * Catálogo Modern Events Calendar (addon local/remoto o preset SQL mec_remote).
     *
     * @param array<string, mixed> $config
     */
    public static function is_mec_catalog_config(array $config): bool {
        if (($config['source_type'] ?? '') === 'addon' && ($config['addon_slug'] ?? '') === 'mec') {
            return true;
        }
        if (($config['source_type'] ?? '') === 'sql' && ($config['sql_preset'] ?? '') === 'mec_remote') {
            return true;
        }

        return (bool) apply_filters('xabia_is_mec_catalog_config', false, $config);
    }

    /**
     * Conexión remota: SQL externo, addon sin plugin local o multi con host remoto.
     *
     * @param array<string, mixed> $config
     */
    public static function is_remote_config(array $config): bool {
        $source_type = (string) ($config['source_type'] ?? '');

        if ($source_type === 'sql') {
            return trim((string) (($config['sql_config']['host'] ?? ''))) !== '';
        }
        if ($source_type === 'local_sql') {
            return false;
        }
        if ($source_type === 'addon') {
            $slug = (string) ($config['addon_slug'] ?? '');
            $central_slug = defined('XABIA_CENTRAL_SLUG') ? XABIA_CENTRAL_SLUG : 'xabia_central';
            if ($slug === $central_slug) {
                return true;
            }
            if (trim((string) (($config['sql_config']['host'] ?? ''))) !== '') {
                return true;
            }
            if ($slug === 'mec' && function_exists('xabia_mec_is_remote_catalog') && xabia_mec_is_remote_catalog($config)) {
                return true;
            }
            if ($slug === 'woo' && function_exists('xabia_woo_is_remote_catalog') && xabia_woo_is_remote_catalog($config)) {
                return true;
            }

            return false;
        }
        if ($source_type === 'multi' && !empty($config['sources']) && is_array($config['sources'])) {
            foreach ($config['sources'] as $src) {
                if (!is_array($src)) {
                    continue;
                }
                $type = (string) ($src['type'] ?? '');
                if ($type === 'local_sql') {
                    continue;
                }
                if ($type === 'sql' && trim((string) (($src['sql_config']['host'] ?? ''))) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param object|wpdb|null $db
     */
    /**
     * Tablas temporales / de export (WP All Export, etc.) — nunca usarlas como prefijo WP.
     */
    public static function is_temp_or_export_wp_table(string $table): bool {
        $table = strtolower($table);

        return (bool) preg_match('/(?:^|_)pmxe_(?:|$)|(?:^|_)pmxi_(?:|$)|_pmxe$|_pmxi$/', $table)
            || str_contains($table, '_pmxe_')
            || str_contains($table, '_pmxi_');
    }

    /**
     * Prefijo inválido si apunta a tablas de export temporales.
     */
    public static function is_temp_or_export_prefix(string $prefix): bool {
        $prefix = strtolower($prefix);

        return str_contains($prefix, 'pmxe') || str_contains($prefix, 'pmxi');
    }

    /**
     * Detecta el prefijo de tablas WP de producción (excluye pmxe_/pmxi_).
     *
     * @param object|wpdb $db
     * @return string|false
     */
    public static function find_active_prefix($db) {
        if (!is_object($db) || !method_exists($db, 'get_results')) {
            return false;
        }
        $tables = $db->get_results("SHOW TABLES LIKE '%posts'", ARRAY_N);
        if (empty($tables) || !is_array($tables)) {
            return false;
        }

        $candidates = [];
        foreach ($tables as $t) {
            if (!is_array($t) || empty($t[0])) {
                continue;
            }
            $table = (string) $t[0];
            if (self::is_temp_or_export_wp_table($table)) {
                continue;
            }
            // Solo tablas …posts canónicas (no …pmxe_posts ni variantes).
            if (!preg_match('/posts$/i', $table) || preg_match('/pmxe|pmxi/i', $table)) {
                continue;
            }
            $candidates[] = $table;
        }

        if ($candidates === []) {
            return false;
        }

        // Preferir la tabla más “corta” / canónica (wp_posts, 4ygrzUK_posts) con filas reales.
        usort($candidates, static function ($a, $b) {
            return strlen((string) $a) <=> strlen((string) $b);
        });

        foreach ($candidates as $table) {
            $safe = '`' . str_replace('`', '``', $table) . '`';
            $check = $db->get_results("SELECT ID FROM {$safe} LIMIT 1", ARRAY_A);
            if (!empty($check)) {
                $prefix = substr($table, 0, -5);
                if ($prefix !== '' && !self::is_temp_or_export_prefix($prefix)) {
                    return $prefix;
                }
            }
        }

        $first = (string) $candidates[0];
        $prefix = substr($first, 0, -5);

        return ($prefix !== '' && !self::is_temp_or_export_prefix($prefix)) ? $prefix : false;
    }

    public static function resolve_project_csv_path(string $project_id, string $csv_filename = ''): string {
        $project_id = sanitize_key($project_id);
        $csv_filename = sanitize_file_name($csv_filename);
        if ($project_id === '') {
            return '';
        }
        $dir = self::get_project_csv_dir($project_id);
        if ($csv_filename !== '') {
            $exact = $dir . '/' . $csv_filename;
            if (file_exists($exact)) {
                return $exact;
            }
        }
        $files = self::get_project_csv_files($project_id);

        return $files !== [] ? (string) $files[0] : '';
    }

    /**
     * @return list<string>
     */
    private static function get_project_csv_files(string $project_id): array {
        $dir = self::get_project_csv_dir($project_id);
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.csv');

        return is_array($files) ? $files : [];
    }

    private static function get_project_csv_dir(string $project_id): string {
        $uploads = wp_upload_dir();

        return rtrim((string) ($uploads['basedir'] ?? ''), '/') . '/xabia/' . sanitize_key($project_id);
    }
}
