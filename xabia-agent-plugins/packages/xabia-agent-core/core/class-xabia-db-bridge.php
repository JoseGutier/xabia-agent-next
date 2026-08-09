<?php
/**
 * XABIA DB BRIDGE — ingesta híbrida (CSV, SQL local/remoto, reemplazo de {prefix}).
 * Convierte cada fuente en registros normalizados y aplica limpieza profunda al texto indexable.
 * Soporta prefijo manual definido en la configuración del proyecto.
 */

if (!defined('ABSPATH')) exit;

class Xabia_DB_Bridge {

    /**
     * MODO A: Procesar CSV (Legacy file-based)
     * Lee archivos físicos de la carpeta del proyecto y los vuelca a la tabla de vectores.
     * @param string $project_id
     * @param string $file_path Ruta al CSV.
     * @param array|null $attributes_override Mapeo de atributos para esta fuente (si multi-fuente). Si null, usa el del proyecto.
     */
    public static function process_csv_knowledge($project_id, $file_path, $attributes_override = null) {
        global $wpdb;
        if (!file_exists($file_path)) return 0;

        $projects = get_option('xabia_projects_config', []);
        $mapping  = $attributes_override !== null ? $attributes_override : ($projects[$project_id]['attributes'] ?? []);
        $table_target = Xabia_DB::table('knowledge_vectors');
        $count = 0;

        if (class_exists('Xabia_Knowledge_Relations', false)) {
            $sql_cfg = is_array($projects[$project_id]['sql_config'] ?? null) ? $projects[$project_id]['sql_config'] : [];
            Xabia_Knowledge_Relations::warm_for_project((string) $project_id, $sql_cfg);
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) return 0;

        
        $first_line = fgets($handle);
        $first_line = preg_replace('/^\xEF\xBB\xBF/', '', $first_line); 
        $separator = (substr_count($first_line, ';') > substr_count($first_line, ',')) ? ';' : ',';
        $headers = str_getcsv($first_line, $separator);
        $headers = array_map('trim', $headers);

        if (empty($headers)) { fclose($handle); return 0; }

        while (($data = fgetcsv($handle, 0, $separator)) !== FALSE) {
            if (empty($data) || (count($data) === 1 && is_null($data[0]))) continue;
            
            
            if (count($data) < count($headers)) $data = array_pad($data, count($headers), '');
            if (count($data) > count($headers)) $data = array_slice($data, 0, count($headers));
            
            $row = array_combine($headers, $data);
            if (!$row) continue;

            if (class_exists('Xabia_Knowledge_Ingest', false) && Xabia_Knowledge_Ingest::should_skip_translated_row($row, (string) $project_id)) {
                continue;
            }

            
            if (self::upsert_record($project_id, $row, $mapping, $table_target)) $count++;
        }
        fclose($handle);
        if (class_exists('Xabia_Knowledge_Relations', false)) {
            Xabia_Knowledge_Relations::clear_cache((string) $project_id);
        }
        return $count;
    }

    /**
     * @param array<string, mixed>|null $opts incremental, stats (array ref keys: content_updated, inserted)
     * @return int
     */
    public static function process_sql_knowledge($project_id, $sql_config, $attributes_override = null, $opts = null) {
        $projects = get_option('xabia_projects_config', []);
        $config = $projects[$project_id] ?? [];
        $opts = is_array($opts) ? $opts : [];
        
        $sql = $sql_config['query'] ?? '';
        if (empty($sql)) return 0;

        global $wpdb;

        $manual_prefix = trim((string) ($sql_config['prefix'] ?? ''));
        $sql_db = $wpdb;
        $is_remote = !empty($sql_config['host']) && !self::sql_config_uses_local_wpdb($sql_config);

        if (!$is_remote) {
            // Local / misma instalación WP: prefijo de esta WordPress (o manual).
            if ($manual_prefix !== '') {
                $real_prefix = $manual_prefix;
            } else {
                $real_prefix = $wpdb->prefix;
            }
            if (!empty($sql_config['host']) && self::sql_config_uses_local_wpdb($sql_config)) {
                $sql_config['host'] = '';
            }
        } else {
            // Remoto estricto: NUNCA $wpdb->prefix local.
            if (!class_exists('Xabia_SQL_Connector')) {
                $path = plugin_dir_path(dirname(__FILE__)) . 'integrations/class-xabia-sql-connector.php';
                if (file_exists($path)) {
                    require_once $path;
                }
            }
            $remote_db = null;
            if (class_exists('Xabia_SQL_Connector', false)) {
                $real_prefix = Xabia_SQL_Connector::resolve_table_prefix($sql_config, null);
                $sql = Xabia_SQL_Connector::apply_prefix_to_sql($sql, array_merge($sql_config, ['prefix' => $real_prefix]), null);
                $remote_db = new wpdb(
                    (string) ($sql_config['user'] ?? ''),
                    (string) ($sql_config['pass'] ?? ''),
                    (string) ($sql_config['name'] ?? ''),
                    (string) ($sql_config['host'] ?? '')
                );
                if (empty($remote_db->error)) {
                    $sql_db = $remote_db;
                }
            } else {
                $real_prefix = $manual_prefix !== '' ? $manual_prefix : 'wp_';
                if (class_exists('Xabia_Knowledge_Sync', false)
                    && method_exists('Xabia_Knowledge_Sync', 'is_temp_or_export_prefix')
                    && Xabia_Knowledge_Sync::is_temp_or_export_prefix($real_prefix)) {
                    $real_prefix = 'wp_';
                }
                if (class_exists('Xabia_Knowledge_Sync', false)) {
                    $remote_db = new wpdb(
                        (string) ($sql_config['user'] ?? ''),
                        (string) ($sql_config['pass'] ?? ''),
                        (string) ($sql_config['name'] ?? ''),
                        (string) ($sql_config['host'] ?? '')
                    );
                    if (empty($remote_db->error)) {
                        $sql_db = $remote_db;
                        $detected = Xabia_Knowledge_Sync::find_active_prefix($remote_db);
                        if (is_string($detected) && $detected !== '') {
                            $real_prefix = $detected;
                        }
                    }
                }
                if (stripos($sql, '{prefix}') !== false) {
                    $sql = str_replace('{prefix}', $real_prefix, $sql);
                }
                $sql = preg_replace(
                    '/\b([a-zA-Z0-9]+)_pmxe_(posts|postmeta|options|terms|termmeta|term_taxonomy|term_relationships)\b/i',
                    '$1_$2',
                    $sql
                );
            }
            $sql_config['prefix'] = $real_prefix;
        }

        if (!$is_remote) {
            if (stripos($sql, '{prefix}') !== false) {
                $sql = str_replace('{prefix}', $real_prefix, $sql);
            }
            if ($real_prefix !== 'wp_' && stripos($sql, 'wp_') !== false) {
                $sql = preg_replace('/\bwp_([a-zA-Z0-9_]+)/', $real_prefix . '$1', $sql);
            }
        }

        if (!empty($opts['incremental']) && class_exists('Xabia_Knowledge_Optimizer', false)) {
            $sql = Xabia_Knowledge_Optimizer::apply_incremental_sql_filter($sql, (string) $project_id, $config);
        }

        if (class_exists('Xabia_Knowledge_Ingest', false)) {
            $sql = Xabia_Knowledge_Ingest::apply_primary_language_sql_filter($sql, (string) $project_id, $config, $real_prefix, $sql_db);
        }

        $sql_config['query'] = $sql;

        if (!class_exists('Xabia_SQL_Connector')) {
            $path = plugin_dir_path(dirname(__FILE__)) . 'integrations/class-xabia-sql-connector.php';
            if (file_exists($path)) require_once $path;
            else return 0;
        }

        $raw_data = Xabia_SQL_Connector::fetch_data($sql_config);

        if (is_wp_error($raw_data)) {
            throw new Exception((string) $raw_data->get_error_message());
        }
        if (empty($raw_data)) {
            // 0 filas ≠ fallo de conexión: la consulta se ejecutó (credenciales/host OK).
            // Típico en MEC remoto cuando no quedan eventos con fecha >= hoy, o en sync
            // incremental sin posts modificados desde la última sync correcta.
            unset($raw_data, $sql, $sql_config);

            return 0;
        }

        $is_remote = !empty($sql_config['host']) && !self::sql_config_uses_local_wpdb($sql_config);
        $driver_type = Xabia_Knowledge_Language_Driver::TYPE_NONE;
        if (class_exists('Xabia_Knowledge_Language_Driver', false)) {
            Xabia_Knowledge_Language_Driver::begin_sync_pass();
            $driver_type = Xabia_Knowledge_Language_Driver::detect((string) $real_prefix, $sql_db, $is_remote);
        }

        if (class_exists('Xabia_Knowledge_Ingest', false)) {
            $raw_data = Xabia_Knowledge_Ingest::filter_primary_language_rows(
                $raw_data,
                (string) $project_id,
                $driver_type,
                $sql_db,
                (string) $real_prefix
            );
        } elseif (class_exists('Xabia_Knowledge_Language_Driver', false) && $driver_type === Xabia_Knowledge_Language_Driver::TYPE_NONE) {
            $raw_data = Xabia_Knowledge_Language_Driver::dedupe_by_slug_keep_first_id($raw_data);
        }

        if (class_exists('Xabia_Knowledge_Relations', false)) {
            Xabia_Knowledge_Relations::warm_for_project((string) $project_id, $sql_config);
        }

        $table_target = Xabia_DB::table('knowledge_vectors');
        $mapping = $attributes_override !== null ? $attributes_override : ($config['attributes'] ?? []);
        $count = 0;
        $row_index = 0;

        foreach ($raw_data as $row_index => $row) {
            if (empty($row) || !is_array($row)) {
                unset($raw_data[$row_index]);
                continue;
            }
            $action = self::upsert_record($project_id, $row, $mapping, $table_target);
            if ($action !== false) {
                $count++;
                self::note_sync_action($opts, $action);
            }
            unset($raw_data[$row_index], $row);
            if ($row_index > 0 && ($row_index % 25) === 0 && function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        unset($raw_data, $sql, $sql_config, $mapping);
        if (class_exists('Xabia_Knowledge_Relations', false)) {
            Xabia_Knowledge_Relations::clear_cache((string) $project_id);
        }
        if (class_exists('Xabia_Knowledge_Language_Driver', false)) {
            Xabia_Knowledge_Language_Driver::end_sync_pass();
        }

        return $count;
    }

    /**
     * Ingesta filas ya materializadas en PHP (addons con lógica no portable a un único SELECT).
     *
     * @param string               $project_id
     * @param array<int, array<string, mixed>> $rows Filas asociativas (mismas claves que espera el mapeo / insert_record).
     * @param array<string, mixed>|null        $attributes_override Mapeo opcional (multi-fuente).
     * @return int Número de filas insertadas o actualizadas.
     */
    public static function process_prefetched_rows($project_id, array $rows, $attributes_override = null) {
        global $wpdb;
        $projects = get_option('xabia_projects_config', []);
        $config = $projects[$project_id] ?? [];
        $mapping = $attributes_override !== null ? $attributes_override : ($config['attributes'] ?? []);
        $table_target = Xabia_DB::table('knowledge_vectors');
        $count = 0;
        if (class_exists('Xabia_Knowledge_Language_Driver', false)) {
            Xabia_Knowledge_Language_Driver::begin_sync_pass();
            $is_remote = class_exists('Xabia_Knowledge_Sync', false)
                && Xabia_Knowledge_Sync::is_remote_config($config);
            $prefix = (string) (($config['sql_config']['prefix'] ?? '') ?: 'wp_');
            $driver = Xabia_Knowledge_Language_Driver::detect($prefix, null, $is_remote);
            if ($driver === Xabia_Knowledge_Language_Driver::TYPE_NONE) {
                $rows = Xabia_Knowledge_Language_Driver::dedupe_by_slug_keep_first_id($rows);
            }
        }
        if (class_exists('Xabia_Knowledge_Relations', false)) {
            $sql_cfg = is_array($config['sql_config'] ?? null) ? $config['sql_config'] : [];
            Xabia_Knowledge_Relations::warm_for_project((string) $project_id, $sql_cfg);
        }
        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }
            if (class_exists('Xabia_Knowledge_Ingest', false) && Xabia_Knowledge_Ingest::should_skip_translated_row($row, (string) $project_id)) {
                continue;
            }
            $action = self::upsert_record($project_id, $row, $mapping, $table_target);
            if ($action !== false) {
                $count++;
            }
        }
        if (class_exists('Xabia_Knowledge_Relations', false)) {
            Xabia_Knowledge_Relations::clear_cache((string) $project_id);
        }
        if (class_exists('Xabia_Knowledge_Language_Driver', false)) {
            Xabia_Knowledge_Language_Driver::end_sync_pass();
        }

        return $count;
    }

    /**
     * Upsert con content_hash: evita re-embedding si el texto RAG no cambió.
     *
     * @return false|'insert'|'content_update'|'unchanged'
     */
    private static function upsert_record($project_id, $row, $mapping, $table_target) {
        unset($table_target);

        $row = apply_filters('xabia_knowledge_sync_enrich_row', $row, $project_id, $mapping);
        if (!is_array($row)) {
            $row = [];
        }

        if (class_exists('Xabia_Knowledge_Ingest', false) && Xabia_Knowledge_Ingest::should_skip_translated_row($row, (string) $project_id)) {
            return false;
        }

        if (
            class_exists('Xabia_Knowledge_Language_Driver', false)
            && Xabia_Knowledge_Language_Driver::should_skip_duplicate_in_pass($row, is_array($mapping) ? $mapping : [], (string) $project_id)
        ) {
            return false;
        }

        $canonical_key = class_exists('Xabia_Knowledge_Ingest', false)
            ? Xabia_Knowledge_Ingest::canonical_record_key($row, $mapping)
            : '';

        $ente = self::resolve_ente_identity($row, $mapping, $canonical_key);
        $ente_id = $ente['ente_id'];
        $ente_display = $ente['ente_display'];
        $ente_col = $ente['ente_col'];

        $prepared = self::prepare_node_data($row, $mapping, (string) $project_id);

        if (!empty($ente_display)) {
            $prepared['meta_array']['__ente_display'] = $ente_display;
            $prepared['meta_array']['__ente_col'] = (string) $ente_col;
            $prepared['meta_array']['__ente_id'] = (string) $ente_id;
        }

        $source_id = $canonical_key !== ''
            ? $canonical_key
            : (class_exists('Xabia_Knowledge_Optimizer', false)
                ? Xabia_Knowledge_Optimizer::source_record_id_from_row($row, $mapping)
                : '');
        if ($source_id !== '') {
            $prepared['meta_array']['__source_record_id'] = $source_id;
        }
        if ($canonical_key !== '') {
            $prepared['meta_array']['__canonical_key'] = $canonical_key;
        }

        $text_blob = (string) $prepared['text_blob'];
        if ($text_blob === '') {
            unset($prepared, $ente, $row);

            return false;
        }

        if (class_exists('Xabia_Knowledge_Language_Driver', false)) {
            Xabia_Knowledge_Language_Driver::mark_seen_in_pass($row, is_array($mapping) ? $mapping : [], (string) $project_id);
        }

        if (class_exists('Xabia_Knowledge_Relations', false)) {
            $enriched = Xabia_Knowledge_Relations::enrich_text_blob($text_blob, $row, (string) $project_id, []);
            if (is_string($enriched) && $enriched !== '') {
                $text_blob = $enriched;
                $prepared['text_blob'] = $text_blob;
            }
            unset($enriched);
        }

        if (!class_exists('Xabia_DB', false)) {
            unset($prepared, $ente, $row);

            return false;
        }

        $content_hash = Xabia_DB::compute_content_hash($text_blob);
        $existing = null;
        if ($canonical_key !== '') {
            $existing = Xabia_DB::find_knowledge_row_by_ente((string) $project_id, $canonical_key);
        }
        if ($existing === null && $source_id !== '') {
            $existing = Xabia_DB::find_knowledge_row_by_source((string) $project_id, $source_id);
        }
        if ($existing === null && $canonical_key !== '') {
            foreach (['ID', 'id'] as $id_key) {
                if (!isset($row[$id_key]) || trim((string) $row[$id_key]) === '') {
                    continue;
                }
                $legacy = Xabia_DB::find_knowledge_row_by_source((string) $project_id, sanitize_text_field((string) $row[$id_key]));
                if ($legacy !== null) {
                    $existing = $legacy;
                    break;
                }
            }
        }

        $identity = [];
        if ($canonical_key !== '') {
            $identity = [
                'ente_id'          => $canonical_key,
                'source_record_id' => $canonical_key,
            ];
            $ente_id = $canonical_key;
        }

        if ($existing !== null && isset($existing->id)) {
            $stored_hash = (string) ($existing->content_hash ?? '');
            if ($stored_hash !== '' && hash_equals($stored_hash, $content_hash)) {
                $prev_meta = [];
                if (!empty($existing->meta_blob)) {
                    $decoded = json_decode((string) $existing->meta_blob, true);
                    if (is_array($decoded)) {
                        $prev_meta = $decoded;
                    }
                }
                $merged = class_exists('Xabia_Knowledge_Optimizer', false)
                    ? Xabia_Knowledge_Optimizer::merge_volatile_meta($prev_meta, $prepared['meta_array'])
                    : array_merge($prev_meta, $prepared['meta_array']);
                if ($identity !== [] && self::row_needs_identity_migration($existing, $identity)) {
                    $ok = Xabia_DB::update_knowledge_identity((int) $existing->id, $identity, $merged);
                    unset($prepared, $ente, $row, $prev_meta, $merged, $existing);

                    return $ok ? 'content_update' : false;
                }
                $ok = Xabia_DB::update_knowledge_meta_only((int) $existing->id, $merged);
                unset($prepared, $ente, $row, $prev_meta, $merged, $existing);

                return $ok ? 'unchanged' : false;
            }
            $ok = Xabia_DB::update_knowledge_content((int) $existing->id, $text_blob, $prepared['meta_array'], $content_hash, $identity);
            unset($prepared, $ente, $row, $existing);

            return $ok ? 'content_update' : false;
        }

        $extras = [
            'source_record_id' => $source_id,
            'content_hash'     => $content_hash,
        ];
        $ok = Xabia_DB::insert_knowledge_vector_row(
            $project_id,
            $ente_id,
            $text_blob,
            $prepared['meta_array'],
            $extras
        );
        unset($prepared, $ente, $row, $extras);

        return $ok ? 'insert' : false;
    }

    /**
     * @param array<string, mixed>|null $opts
     */
    private static function note_sync_action($opts, string $action): void {
        if (!is_array($opts) || !isset($opts['stats']) || !is_array($opts['stats'])) {
            return;
        }
        if ($action === 'content_update') {
            $opts['stats']['content_updated'] = (int) ($opts['stats']['content_updated'] ?? 0) + 1;
        } elseif ($action === 'insert') {
            $opts['stats']['inserted'] = (int) ($opts['stats']['inserted'] ?? 0) + 1;
        } elseif ($action === 'unchanged') {
            $opts['stats']['unchanged'] = (int) ($opts['stats']['unchanged'] ?? 0) + 1;
        }
    }

    /**
     * PREPARADOR DE NODOS: limpieza profunda de valores antes de armar content_chunk y meta_data.
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $mapping
     */
    private static function prepare_node_data($row, $mapping, string $project_id = '') {
        if (
            $project_id !== ''
            && class_exists('Xabia_Knowledge_Ingest', false)
            && Xabia_Knowledge_Ingest::uses_passport_chunk($project_id, $mapping, $row)
        ) {
            return Xabia_Knowledge_Ingest::build_passport_chunk($row, $mapping, [
                'project_id' => (string) $project_id,
            ]);
        }

        $meta_array = [];
        $text_parts = [];
        $use_kt = class_exists('Xabia_Knowledge_Text', false);

        $clean = static function ($text) use ($use_kt) {
            if ($use_kt) {
                return Xabia_Knowledge_Text::clean_field_value((string) $text);
            }
            $text = strip_tags((string) $text);
            $text = preg_replace('/\s+/u', ' ', $text);

            return trim($text);
        };

        $limit_field = static function ($text, $col, $label) use ($use_kt) {
            if (!$use_kt) {
                return $text;
            }
            $max = (int) apply_filters('xabia_rag_field_max_chars', Xabia_Knowledge_Text::FIELD_MAX_CHARS, $col, $label);

            return Xabia_Knowledge_Text::limit_field_value($text, $max);
        };

        if (!empty($mapping)) {
            foreach ($mapping as $attr) {
                $col   = $attr['csv_col'] ?? '';
                $label = $attr['label'] ?? $col;
                if ($col === '' || !isset($row[$col]) || trim((string) $row[$col]) === '') {
                    continue;
                }
                $clean_val = $limit_field($clean($row[$col]), (string) $col, (string) $label);
                if ($clean_val === '') {
                    continue;
                }
                $meta_array[sanitize_key($label)] = $clean_val;
                $for_rag = !$use_kt || Xabia_Knowledge_Text::attribute_imports_for_rag($attr);
                if ($for_rag) {
                    $instr = !empty($attr['instruction']) ? ' (' . $attr['instruction'] . ')' : '';
                    $text_parts[] = $label . $instr . ': ' . $clean_val;
                }
            }
        } else {
            foreach ($row as $k => $v) {
                $key = (string) $k;
                $clean_val = $limit_field($clean($v), $key, $key);
                if ($clean_val === '') {
                    continue;
                }
                $meta_array[sanitize_key($k)] = $clean_val;
                $for_rag = !$use_kt || Xabia_Knowledge_Text::default_import_rag_for_column($key);
                if ($for_rag) {
                    $text_parts[] = $key . ': ' . $clean_val;
                }
            }
        }

        $blob = implode(' | ', $text_parts);
        if ($use_kt) {
            $blob = Xabia_Knowledge_Text::finalize_content_chunk($blob);
        }

        return [
            'meta_array' => $meta_array,
            'text_blob'  => $blob,
        ];
    }

    /**
     * Nombre visible del ente guardado en meta (__ente_display).
     */
    public static function get_stored_ente_display(string $project_id, string $ente_id): string {
        $ente_id = trim($ente_id);
        if ($ente_id === '' || $ente_id === 'global' || !class_exists('Xabia_DB', false)) {
            return $ente_id;
        }
        global $wpdb;
        $table = Xabia_DB::table('knowledge_vectors');
        $meta_col = Xabia_DB::knowledge_vectors_meta_column();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT `{$meta_col}` AS meta_blob, content_chunk FROM `{$table}` WHERE project_id = %s AND ente_id = %s ORDER BY id DESC LIMIT 1",
            $project_id,
            $ente_id
        ), ARRAY_A);
        if (is_array($row)) {
            $decoded = [];
            if (!empty($row['meta_blob'])) {
                $tmp = json_decode((string) $row['meta_blob'], true);
                if (is_array($tmp)) {
                    $decoded = $tmp;
                }
            }
            $friendly = self::friendly_name_from_meta_or_chunk($decoded, (string) ($row['content_chunk'] ?? ''), $ente_id);
            if ($friendly !== '') {
                return $friendly;
            }
        }

        return $ente_id;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function friendly_name_from_meta_or_chunk(array $meta, string $content_chunk, string $ente_id): string {
        $display = isset($meta['__ente_display']) ? trim((string) $meta['__ente_display']) : '';
        if ($display !== '' && !self::value_looks_like_raw_post_id($display) && $display !== $ente_id) {
            return $display;
        }
        foreach (['empresa', 'post_title', 'titulo', 'title', 'nombre'] as $key) {
            if (!isset($meta[$key])) {
                continue;
            }
            $val = trim((string) $meta[$key]);
            if ($val !== '' && !self::value_looks_like_raw_post_id($val)) {
                return $val;
            }
        }
        foreach ($meta as $key => $val) {
            if (!is_string($key) || !is_scalar($val)) {
                continue;
            }
            if (str_starts_with($key, '__')) {
                continue;
            }
            if (stripos($key, 'empresa') === false) {
                continue;
            }
            $val = trim((string) $val);
            if ($val !== '' && !self::value_looks_like_raw_post_id($val)) {
                return $val;
            }
        }
        $chunk = wp_strip_all_tags($content_chunk);
        if ($chunk !== '') {
            if (preg_match('/\bEMPRESA:\s*([^|]+)/iu', $chunk, $m_passport)) {
                $from_chunk = trim((string) ($m_passport[1] ?? ''));
                if ($from_chunk !== '' && !self::value_looks_like_raw_post_id($from_chunk)) {
                    return $from_chunk;
                }
            }
            if (preg_match('/\bempresa\s*:\s*([^|]+)/iu', $chunk, $m)) {
                $from_chunk = trim((string) ($m[1] ?? ''));
                if ($from_chunk !== '' && !self::value_looks_like_raw_post_id($from_chunk)) {
                    return $from_chunk;
                }
            }
            if (preg_match('/\bpost_title\s*:\s*([^|]+)/iu', $chunk, $m2)) {
                $from_chunk = trim((string) ($m2[1] ?? ''));
                if ($from_chunk !== '' && !self::value_looks_like_raw_post_id($from_chunk)) {
                    return $from_chunk;
                }
            }
        }

        return '';
    }

    private static function value_looks_like_raw_post_id(string $value): bool {
        $value = trim($value);

        return $value !== '' && ctype_digit($value);
    }

    /**
     * @param object $existing
     * @param array<string, string> $identity
     */
    private static function row_needs_identity_migration($existing, array $identity): bool {
        if (!isset($existing->ente_id, $identity['ente_id'])) {
            return false;
        }
        $current_ente = sanitize_title((string) $existing->ente_id);
        $target_ente = sanitize_title((string) $identity['ente_id']);
        if ($target_ente !== '' && $current_ente !== $target_ente) {
            return true;
        }
        if (!isset($existing->source_record_id, $identity['source_record_id'])) {
            return false;
        }
        $current_source = trim((string) $existing->source_record_id);
        $target_source = trim((string) $identity['source_record_id']);

        return $target_source !== '' && $current_source !== $target_source;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $mapping
     * @return array{ente_id: string, ente_display: string, ente_col: string}
     */
    private static function resolve_ente_identity(array $row, array $mapping, string $canonical_key = ''): array {
        $ente_id = 'global';
        $ente_display = '';
        $ente_col = '';
        $ente_raw_val = '';
        $ente_attr = null;

        if ($canonical_key !== '') {
            $ente_id = class_exists('Xabia_Knowledge_Ingest', false)
                ? Xabia_Knowledge_Ingest::canonical_slug($canonical_key)
                : sanitize_title($canonical_key);
        }

        if (!empty($mapping) && is_array($mapping)) {
            $ente_pick = self::pick_best_ente_attribute($row, $mapping);
            if ($ente_pick !== null) {
                $ente_col = $ente_pick['col'];
                $ente_raw_val = $ente_pick['raw'];
                $ente_attr = $ente_pick['attr'];
                $ente_display = $ente_pick['display'];
            }
        }

        if ($ente_col === '' && !empty($mapping) && is_array($mapping)) {
            foreach ($mapping as $attr) {
                $role = strtolower(trim((string) ($attr['visual_role'] ?? '')));
                if ($role === 'title' || $role === 'título') {
                    $candidate = $attr['csv_col'] ?? '';
                    if ($candidate !== '' && isset($row[$candidate]) && trim((string) $row[$candidate]) !== '') {
                        $ente_col = $candidate;
                        $ente_raw_val = trim((string) $row[$candidate]);
                        $ente_display = $ente_raw_val;
                        $ente_attr = $attr;
                        break;
                    }
                }
            }
        }

        if ($ente_col === '') {
            $first_key = function_exists('array_key_first') ? array_key_first($row) : null;
            if (!empty($first_key) && isset($row[$first_key]) && trim((string) $row[$first_key]) !== '') {
                $ente_col = (string) $first_key;
                $ente_raw_val = trim((string) $row[$first_key]);
                $ente_display = $ente_raw_val;
            }
        }

        if ($ente_col !== '' && self::column_looks_like_wp_post_id($ente_col, $ente_raw_val)) {
            $friendly = self::find_friendly_ente_label($row, $mapping, is_array($ente_attr) ? $ente_attr : []);
            if ($friendly !== '') {
                $ente_display = $friendly;
            }
        }

        if ($ente_display !== '' && $canonical_key === '') {
            $slug_base = $ente_display;
            if (self::column_looks_like_wp_post_id($ente_col, $ente_raw_val)) {
                $slug_base = $ente_display;
            } elseif ($ente_raw_val !== '') {
                $slug_base = $ente_raw_val;
            }
            $ente_id = class_exists('Xabia_Knowledge_Ingest', false)
                ? Xabia_Knowledge_Ingest::canonical_slug($slug_base)
                : sanitize_title($slug_base);
            if ($ente_id === '') {
                $ente_id = class_exists('Xabia_Knowledge_Ingest', false)
                    ? Xabia_Knowledge_Ingest::canonical_slug($ente_display)
                    : sanitize_title($ente_display);
            }
            if ($ente_id === '') {
                $ente_id = 'global';
            }
        } elseif ($canonical_key !== '' && $ente_display === '') {
            foreach (['empresa', 'post_title', 'title'] as $name_col) {
                if (!isset($row[$name_col]) || trim((string) $row[$name_col]) === '') {
                    continue;
                }
                $ente_display = trim((string) $row[$name_col]);
                break;
            }
        }

        return [
            'ente_id'      => $ente_id,
            'ente_display' => $ente_display,
            'ente_col'     => $ente_col,
        ];
    }

    /**
     * Si hay varios campos ENTE, prioriza empresa/título frente a ID numérico.
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $mapping
     * @return array{col: string, raw: string, display: string, attr: array<string, mixed>}|null
     */
    private static function pick_best_ente_attribute(array $row, array $mapping): ?array {
        $best = null;
        $best_score = -1;
        foreach ($mapping as $attr) {
            if (empty($attr['is_ente'])) {
                continue;
            }
            $candidate = $attr['csv_col'] ?? '';
            if ($candidate === '' || !isset($row[$candidate]) || trim((string) $row[$candidate]) === '') {
                continue;
            }
            $raw = trim((string) $row[$candidate]);
            $label_col = isset($attr['ente_label_col']) ? trim((string) $attr['ente_label_col']) : '';
            if ($label_col !== '' && isset($row[$label_col])) {
                $from_label = trim((string) $row[$label_col]);
                $display = $from_label !== '' ? $from_label : $raw;
            } else {
                $display = $raw;
            }
            $score = 10;
            if (self::column_looks_like_wp_post_id($candidate, $raw)) {
                $score = 1;
            } elseif (strcasecmp($candidate, 'empresa') === 0 || stripos($candidate, 'empresa') !== false) {
                $score = 100;
            } elseif (in_array(strtolower(trim((string) ($attr['visual_role'] ?? ''))), ['title', 'título'], true)) {
                $score = 90;
            } elseif (strcasecmp($candidate, 'post_title') === 0) {
                $score = 85;
            }
            if ($score > $best_score) {
                $best_score = $score;
                $best = [
                    'col'     => $candidate,
                    'raw'     => $raw,
                    'display' => $display,
                    'attr'    => $attr,
                ];
            }
        }

        return $best;
    }

    private static function column_looks_like_wp_post_id(string $col, string $val): bool {
        if (strtoupper(trim($col)) === 'ID') {
            return true;
        }

        return $val !== '' && ctype_digit($val);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $mapping
     * @param array<string, mixed> $ente_attr
     */
    private static function find_friendly_ente_label(array $row, array $mapping, array $ente_attr): string {
        $label_col = isset($ente_attr['ente_label_col']) ? trim((string) $ente_attr['ente_label_col']) : '';
        if ($label_col !== '' && isset($row[$label_col])) {
            $v = trim((string) $row[$label_col]);
            if ($v !== '') {
                return $v;
            }
        }
        foreach ($mapping as $attr) {
            $role = strtolower(trim((string) ($attr['visual_role'] ?? '')));
            if ($role !== 'title' && $role !== 'título') {
                continue;
            }
            $c = $attr['csv_col'] ?? '';
            if ($c !== '' && isset($row[$c])) {
                $v = trim((string) $row[$c]);
                if ($v !== '') {
                    return $v;
                }
            }
        }
        foreach ($mapping as $attr) {
            $c = $attr['csv_col'] ?? '';
            if ($c === '' || !isset($row[$c])) {
                continue;
            }
            if (stripos($c, 'empresa') !== false || stripos((string) ($attr['label'] ?? ''), 'empresa') !== false) {
                $v = trim((string) $row[$c]);
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return '';
    }

    /**
     * Misma BD que el WordPress actual (p. ej. addon Woo local con host=DB_HOST en config).
     *
     * @param array<string, mixed> $sql_config
     */
    private static function sql_config_uses_local_wpdb(array $sql_config): bool {
        if (empty($sql_config['host'])) {
            return true;
        }
        if (!defined('DB_NAME') || !defined('DB_USER') || !defined('DB_HOST')) {
            return false;
        }
        $name = (string) ($sql_config['name'] ?? '');
        $user = (string) ($sql_config['user'] ?? '');
        if ($name !== (string) DB_NAME || $user !== (string) DB_USER) {
            return false;
        }

        return self::sql_hosts_equivalent((string) $sql_config['host'], (string) DB_HOST);
    }

    private static function sql_hosts_equivalent(string $a, string $b): bool {
        $norm = static function (string $h): string {
            $h = strtolower(trim($h));
            if ($h === 'localhost' || $h === '127.0.0.1') {
                return 'local';
            }
            if (strpos($h, 'localhost:') === 0 || strpos($h, '127.0.0.1:') === 0) {
                return 'local';
            }

            return $h;
        };

        return $norm($a) === $norm($b);
    }
}