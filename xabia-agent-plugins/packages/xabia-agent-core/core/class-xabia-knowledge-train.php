<?php
/**
 * Xabia — entrenamiento de embeddings (lotes reutilizables desde admin, cron y pipeline).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Knowledge_Train {

    public const DEFAULT_BATCH_SIZE = 20;

    /**
     * @param array<string, mixed> $config
     */
    public static function should_train_for_config(array $config): bool {
        return !empty($config['rules']['use_vector_search']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_project_config(string $project_id): array {
        $project_id = sanitize_key($project_id);
        if ($project_id === '') {
            return [];
        }
        $projects = get_option('xabia_projects_config', []);

        return isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];
    }

    public static function count_pending(string $project_id): int {
        global $wpdb;
        $project_id = sanitize_key($project_id);
        if ($project_id === '') {
            return 0;
        }
        $config = self::get_project_config($project_id);
        if (!self::should_train_for_config($config)) {
            return 0;
        }
        $t = Xabia_DB::table('knowledge_vectors');
        $needs_sql = Xabia_DB::knowledge_vectors_sql_needs_embedding();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE project_id = %s AND ({$needs_sql})",
            $project_id
        ));
    }

    /**
     * Estima tokens de embedding para filas pendientes (chars efectivos ÷ 3,2).
     *
     * @return array{pending:int,estimated_tokens:int,avg_chars:int,batch_size:int,rows_in_estimate:int}
     */
    public static function estimate_pending_train_cost(string $project_id, int $limit = 0): array {
        global $wpdb;
        $project_id = sanitize_key($project_id);
        $batch_size = self::DEFAULT_BATCH_SIZE;
        $pending = self::count_pending($project_id);
        if ($project_id === '' || $pending === 0) {
            return [
                'pending'           => 0,
                'estimated_tokens'  => 0,
                'avg_chars'         => 0,
                'batch_size'        => $batch_size,
                'rows_in_estimate'  => 0,
            ];
        }

        $t = Xabia_DB::table('knowledge_vectors');
        $needs_sql = Xabia_DB::knowledge_vectors_sql_needs_embedding();
        $row_limit = $limit > 0 ? max(1, min(500, $limit)) : min(500, $pending);
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT content_chunk FROM $t WHERE project_id = %s AND ({$needs_sql}) LIMIT %d",
            $project_id,
            $row_limit
        ));
        if (!is_array($rows) || $rows === []) {
            return [
                'pending'           => $pending,
                'estimated_tokens'  => 0,
                'avg_chars'         => 0,
                'batch_size'        => $batch_size,
                'rows_in_estimate'  => 0,
            ];
        }

        $char_sum = 0;
        foreach ($rows as $chunk) {
            $text = class_exists('Xabia_Knowledge_Text', false)
                ? Xabia_Knowledge_Text::embedding_input((string) $chunk)
                : trim(strip_tags((string) $chunk));
            $char_sum += function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        }

        $sampled = count($rows);
        $target_rows = $limit > 0 ? min($limit, $pending) : $pending;
        if ($sampled > 0 && $target_rows > $sampled) {
            $char_sum = (int) round($char_sum * ($target_rows / $sampled));
        }

        return [
            'pending'           => $pending,
            'estimated_tokens'  => max(1, (int) ceil($char_sum / 3.2)),
            'avg_chars'         => $target_rows > 0 ? (int) round($char_sum / $target_rows) : 0,
            'batch_size'        => $batch_size,
            'rows_in_estimate'  => $target_rows,
        ];
    }

    /**
     * @return array{updated:int,failed:int,pending:int,ok:bool,message:string,batch_tokens:int,skipped:int}
     */
    public static function run_batch(string $project_id, int $limit = self::DEFAULT_BATCH_SIZE): array {
        $project_id = sanitize_key($project_id);
        $limit = max(1, min(50, $limit));

        if ($project_id === '') {
            return self::result(0, 0, 0, false, __('Project ID inválido.', 'xabia-intelligence'));
        }

        $projects = get_option('xabia_projects_config', []);
        $config = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];
        if ($config === []) {
            return self::result(0, 0, 0, false, __('Proyecto no encontrado.', 'xabia-intelligence'));
        }
        if (!self::should_train_for_config($config)) {
            return self::result(
                0,
                0,
                0,
                true,
                __('Búsqueda vectorial desactivada: el chat usa los registros sincronizados por palabras clave; no hace falta entrenar embeddings.', 'xabia-intelligence')
            );
        }

        if (class_exists('Xabia_Digixop_Client', false)) {
            Xabia_Digixop_Client::reset_session_flags();
            if (
                Xabia_Digixop_Client::should_use_openai_proxy($project_id, $config)
                && Xabia_Digixop_Client::proxy_tokens_depleted()
            ) {
                Xabia_Digixop_Client::mark_insufficient_balance();

                return self::result(
                    0,
                    0,
                    self::count_pending($project_id),
                    false,
                    Xabia_Digixop_Client::get_insufficient_balance_user_message()
                );
            }
        }

        global $wpdb;
        $t = Xabia_DB::table('knowledge_vectors');
        $needs_sql = Xabia_DB::knowledge_vectors_sql_needs_embedding();
        $vec_col = Xabia_DB::knowledge_vectors_vector_column();
        $cols = Xabia_DB::knowledge_vectors_column_map();
        $select_hash = isset($cols['content_hash']) ? ', content_hash' : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, content_chunk{$select_hash}, {$vec_col} AS vector_col FROM $t WHERE project_id = %s AND ({$needs_sql}) LIMIT %d",
            $project_id,
            $limit
        ));

        if (empty($rows)) {
            return self::result(0, 0, 0, true, __('Entrenado', 'xabia-intelligence'), 0, 0);
        }

        if (!class_exists('Xabia_API', false)) {
            $api_path = function_exists('xabia_api_dir')
                ? xabia_api_dir() . 'class-xabia-api.php'
                : XABIA_PATH . 'api/class-xabia-api.php';
            if (is_readable($api_path)) {
                require_once $api_path;
            }
        }

        $batch_tokens = 0;
        $updated_count = 0;
        $failed_count = 0;
        $skipped_count = 0;

        foreach ($rows as $idx => $row) {
            $live_hash = Xabia_DB::compute_content_hash((string) $row->content_chunk);
            $stored_hash = isset($row->content_hash) ? (string) $row->content_hash : '';
            $vector_col_val = isset($row->vector_col) ? (string) $row->vector_col : '';
            $has_vector = $vector_col_val !== '' && $vector_col_val !== '[]' && $vector_col_val !== 'null';

            if ($stored_hash !== '' && hash_equals($stored_hash, $live_hash) && $has_vector) {
                $skipped_count++;
                unset($rows[$idx], $row);
                continue;
            }

            if (
                class_exists('Xabia_Digixop_Client', false)
                && Xabia_Digixop_Client::should_use_openai_proxy($project_id, $config)
                && Xabia_Digixop_Client::proxy_tokens_depleted()
            ) {
                Xabia_Digixop_Client::mark_insufficient_balance();
                break;
            }

            if (class_exists('Xabia_Digixop_Client', false)) {
                Xabia_Digixop_Client::reset_last_embedding_total_tokens();
            }
            $embedding = class_exists('Xabia_API', false)
                ? Xabia_API::get_embedding_for_project($row->content_chunk, $project_id)
                : null;

            if (class_exists('Xabia_Digixop_Client', false)) {
                $tok = Xabia_Digixop_Client::get_last_embedding_total_tokens();
                if ($tok !== null && $tok > 0) {
                    $batch_tokens += $tok;
                }
            }

            if (!empty($embedding) && is_array($embedding)) {
                $update_data = [
                    $vec_col => wp_json_encode($embedding, JSON_UNESCAPED_UNICODE),
                ];
                if (isset($cols['content_hash'])) {
                    $update_data['content_hash'] = $live_hash;
                }
                $wpdb->update($t, $update_data, ['id' => $row->id]);
                $updated_count++;
            } else {
                $failed_count++;
            }
            unset($rows[$idx], $row, $embedding, $update_data);
        }
        unset($rows);

        if (class_exists('Xabia_Digixop_Client', false) && Xabia_Digixop_Client::was_insufficient_balance()) {
            return self::result(
                $updated_count,
                $failed_count,
                self::count_pending($project_id),
                false,
                Xabia_Digixop_Client::get_insufficient_balance_user_message(),
                $batch_tokens,
                $skipped_count
            );
        }

        if (
            class_exists('Xabia_Digixop_Client', false)
            && !Xabia_Digixop_Client::should_use_openai_proxy($project_id, $config)
            && Xabia_Digixop_Client::is_license_configured()
            && $batch_tokens > 0
        ) {
            Xabia_Digixop_Client::report_usage([
                'prompt_tokens'     => 0,
                'completion_tokens' => 0,
                'total_tokens'      => $batch_tokens,
                'context'           => 'embedding_train',
                'project_id'        => $project_id,
            ]);
        }

        $pending = self::count_pending($project_id);

        if ($updated_count === 0 && $failed_count > 0 && $skipped_count === 0) {
            $use_vertex = ($config['ai_driver'] ?? '') === 'google_cloud'
                && class_exists('Xabia_Digixop_Client', false)
                && Xabia_Digixop_Client::should_use_local_vertex($config);
            $cloud = class_exists('Xabia_Digixop_Client', false) && Xabia_Digixop_Client::is_xabia_cloud_mode();

            if ($use_vertex) {
                $msg = __('No se pudo generar embeddings con Vertex local en este lote.', 'xabia-intelligence');
            } elseif ($cloud) {
                $msg = __('No se pudieron generar embeddings vía Conexión Segura Xabia.', 'xabia-intelligence');
            } else {
                $msg = __('No se pudo generar embeddings en este lote.', 'xabia-intelligence');
            }

            return self::result($updated_count, $failed_count, $pending, false, $msg, $batch_tokens, $skipped_count);
        }

        $message = $pending > 0
            ? sprintf(__('Quedan %d', 'xabia-intelligence'), $pending)
            : __('Entrenado', 'xabia-intelligence');
        if ($skipped_count > 0) {
            $message .= ' · ' . sprintf(__('omitidos (hash): %d', 'xabia-intelligence'), $skipped_count);
        }

        return self::result($updated_count, $failed_count, $pending, true, $message, $batch_tokens, $skipped_count);
    }

    /**
     * @return array{updated:int,failed:int,pending:int,ok:bool,message:string,batch_tokens:int,skipped:int}
     */
    private static function result(int $updated, int $failed, int $pending, bool $ok, string $message, int $batch_tokens = 0, int $skipped = 0): array {
        return [
            'updated'      => $updated,
            'failed'       => $failed,
            'pending'      => $pending,
            'ok'           => $ok,
            'message'      => $message,
            'batch_tokens' => $batch_tokens,
            'skipped'      => $skipped,
        ];
    }
}
