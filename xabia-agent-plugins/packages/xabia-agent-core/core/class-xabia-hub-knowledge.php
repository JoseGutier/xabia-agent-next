<?php
/**
 * Sincronización de vectores WordPress → Hub y RAG remoto (Xabia Cloud).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Hub_Knowledge {

    /**
     * RAG contra tabla xabia_knowledge_vectors del Hub (requiere modo cloud + licencia).
     */
    public static function is_hub_rag_enabled(string $project_id = ''): bool {
        if (!class_exists('Xabia_Digixop_Client', false) || !Xabia_Digixop_Client::is_license_configured()) {
            return false;
        }

        return (bool) apply_filters('xabia_use_hub_knowledge_rag', Xabia_Digixop_Client::is_xabia_cloud_mode(), $project_id);
    }

    /**
     * Búsqueda semántica en el Hub (el embedding de la query debe haberse generado ya en WordPress / proxy).
     *
     * @param list<float|int> $query_embedding
     * @param string|null $query_text Pregunta en texto; obligatorio para fallback léxico en el Hub si no hay embeddings aún.
     * @param array{catalog_list?: bool, lexical_query_text?: string, keyword_expansions?: array<string, string>} $hub_opts
     * @return array{context: string, chunk_count: int, similarity_avg: float|null, total_found: int|null}
     */
    public static function search_vector(
        string $project_id,
        array $query_embedding,
        string $ente_scope = 'global',
        bool $strict_ente = false,
        $max_chunks = null,
        float $threshold = 0.2,
        ?string $query_text = null,
        array $hub_opts = []
    ): array {
        if (!self::is_hub_rag_enabled($project_id)) {
            return ['context' => '', 'chunk_count' => 0, 'similarity_avg' => null, 'total_found' => null];
        }
        $url = (string) apply_filters('xabia_hub_knowledge_search_url', Xabia_Digixop_Client::default_knowledge_search_url(), $project_id);
        $qv = [];
        foreach ($query_embedding as $v) {
            if (is_numeric($v)) {
                $qv[] = (float) $v;
            }
        }
        if ($qv === []) {
            return ['context' => '', 'chunk_count' => 0, 'similarity_avg' => null, 'total_found' => null];
        }
        $mc = $max_chunks !== null ? (int) $max_chunks : 4;
        $body = [
            'project_id'           => $project_id,
            'query_embedding'      => $qv,
            'max_chunks'           => max(1, min(
                !empty($hub_opts['catalog_list']) && class_exists('Xabia_Brain', false)
                    ? Xabia_Brain::MAX_CATALOG_RAG_CHUNKS
                    : (class_exists('Xabia_Brain', false) ? Xabia_Brain::MAX_RAG_CHUNKS : 15),
                $mc
            )),
            'similarity_threshold' => max(0.0, min(1.0, $threshold)),
        ];
        if ($strict_ente && $ente_scope !== '' && $ente_scope !== 'global') {
            $body['ente_id'] = $ente_scope;
        }
        $qt = $query_text !== null ? trim(wp_strip_all_tags((string) $query_text)) : '';
        if ($qt !== '') {
            $body['query_text'] = mb_substr($qt, 0, 2000);
        }
        if (!empty($hub_opts['catalog_list'])) {
            $body['catalog_list'] = true;
        }
        if (!empty($hub_opts['keyword_boost_only'])) {
            $body['keyword_boost_only'] = true;
        }
        $lqt = isset($hub_opts['lexical_query_text']) ? trim(wp_strip_all_tags((string) $hub_opts['lexical_query_text'])) : '';
        if ($lqt !== '') {
            $body['lexical_query_text'] = mb_substr($lqt, 0, 2000);
        }
        $keyword_expansions = isset($hub_opts['keyword_expansions']) && is_array($hub_opts['keyword_expansions'])
            ? $hub_opts['keyword_expansions']
            : [];
        if ($keyword_expansions !== []) {
            $sanitized = [];
            foreach ($keyword_expansions as $key => $pattern) {
                $key = mb_strtolower(trim(sanitize_text_field((string) $key)), 'UTF-8');
                $pattern = trim(sanitize_text_field((string) $pattern));
                if ($key === '' || $pattern === '' || mb_strlen($key, 'UTF-8') < 2) {
                    continue;
                }
                if (mb_strlen($pattern, 'UTF-8') > 500) {
                    $pattern = mb_substr($pattern, 0, 500);
                }
                $sanitized[$key] = $pattern;
            }
            if ($sanitized !== []) {
                $body['keyword_expansions'] = $sanitized;
            }
        }
        $out = Xabia_Digixop_Client::hub_signed_json_post($url, $body, $project_id);
        $hub_meta = [
            'url'       => $url,
            'http_code' => (int) ($out['code'] ?? 0),
            'ok'        => !empty($out['ok']),
            'body'      => is_array($out['body'] ?? null) ? $out['body'] : null,
            'raw'       => substr((string) ($out['raw'] ?? ''), 0, 2000),
            'wp_error'  => isset($out['wp_error']) && is_array($out['wp_error']) ? $out['wp_error'] : null,
        ];
        if (!$out['ok'] || !is_array($out['body'])) {
            if (class_exists('Xabia_API', false) && method_exists('Xabia_API', 'log_hub_rag_transport_failure')) {
                Xabia_API::log_hub_rag_transport_failure($project_id, 'search_vector', $out, $url);
            } elseif (function_exists('xabia_trace')) {
                xabia_trace('[Xabia Hub RAG] search failed', ['code' => $out['code'], 'snippet' => substr($out['raw'], 0, 400)]);
            } else {
                error_log('[Xabia Hub RAG] search failed HTTP ' . (string) $out['code'] . ' ' . substr($out['raw'], 0, 400));
            }

            return [
                'context'        => '',
                'chunk_count'    => 0,
                'similarity_avg' => null,
                'total_found'    => null,
                '_hub_meta'      => $hub_meta,
            ];
        }
        $b = $out['body'];
        $results = isset($b['chunks']) && is_array($b['chunks']) ? $b['chunks'] : [];
        error_log('Xabia RAG: Se han encontrado ' . count($results) . ' vectores para el proyecto ' . $project_id);

        $avg = $b['similarity_avg'] ?? null;
        $tf = isset($b['total_found']) && is_numeric($b['total_found']) ? (int) $b['total_found'] : null;

        return [
            'context'         => (string) ($b['context'] ?? ''),
            'chunk_count'     => (int) ($b['chunk_count'] ?? 0),
            'similarity_avg'  => is_numeric($avg) ? (float) $avg : null,
            'total_found'     => $tf,
            'chunks'          => isset($b['chunks']) && is_array($b['chunks']) ? $b['chunks'] : [],
            '_hub_meta'       => $hub_meta,
        ];
    }

    /**
     * Listado exhaustivo de empresas en el Hub (sin ranking vectorial top-k).
     *
     * @param array{match_in_header?: list<string>, exclude_in_category?: list<string>, exclude_ente_slugs?: list<string>} $activity_profile
     * @return array{context: string, chunk_count: int, similarity_avg: float|null, total_found: int|null, catalog_debug?: array<string, mixed>}
     */
    public static function search_catalog_companies(
        string $project_id,
        array $activity_profile,
        string $ente_scope = 'global',
        bool $strict_ente = false,
        int $max_chunks = 50,
        string $lexical_query_text = ''
    ): array {
        $empty = ['context' => '', 'chunk_count' => 0, 'similarity_avg' => null, 'total_found' => null, 'catalog_debug' => []];
        if (!self::is_hub_rag_enabled($project_id)) {
            return $empty;
        }
        $needles = array_values(array_filter(array_map('strval', $activity_profile['match_in_header'] ?? [])));
        $match_regexp = trim((string) ($activity_profile['match_regexp'] ?? ''));
        if ($needles === [] && $match_regexp === '') {
            return $empty;
        }
        $url = (string) apply_filters('xabia_hub_knowledge_search_url', Xabia_Digixop_Client::default_knowledge_search_url(), $project_id);
        $mc = class_exists('Xabia_Brain', false) ? Xabia_Brain::MAX_CATALOG_RAG_CHUNKS : 50;
        $profile_body = [
            'match_in_header'     => $needles !== [] ? $needles : ['empresa'],
            'exclude_in_category' => array_values(array_filter(array_map('strval', $activity_profile['exclude_in_category'] ?? []))),
            'exclude_ente_slugs'  => array_values(array_filter(array_map('strval', $activity_profile['exclude_ente_slugs'] ?? []))),
        ];
        if ($match_regexp !== '') {
            $profile_body['match_regexp'] = $match_regexp;
        }
        foreach (['match_category', 'match_subcategory'] as $profile_key) {
            $vals = array_values(array_filter(array_map('strval', $activity_profile[$profile_key] ?? [])));
            if ($vals !== []) {
                $profile_body[$profile_key] = $vals;
            }
        }
        $match_scope = trim((string) ($activity_profile['match_scope'] ?? ''));
        if ($match_scope !== '') {
            $profile_body['match_scope'] = $match_scope;
        }
        $body = [
            'project_id'         => $project_id,
            'catalog_list'       => true,
            'catalog_exhaustive' => true,
            'activity_profile'   => $profile_body,
            'max_chunks'           => max(1, min($mc, $max_chunks)),
            'similarity_threshold' => 0.06,
        ];
        if ($strict_ente && $ente_scope !== '' && $ente_scope !== 'global') {
            $body['ente_id'] = $ente_scope;
        }
        $lqt = trim(wp_strip_all_tags($lexical_query_text));
        if ($lqt !== '') {
            $body['lexical_query_text'] = mb_substr($lqt, 0, 2000);
        }
        $out = Xabia_Digixop_Client::hub_signed_json_post($url, $body, $project_id);
        if (!$out['ok'] || !is_array($out['body'])) {
            error_log('[Xabia Hub RAG] catalog exhaustive HTTP ' . (string) ($out['code'] ?? 0) . ' ' . substr((string) ($out['raw'] ?? ''), 0, 500));
            if (function_exists('xabia_trace')) {
                xabia_trace('[Xabia Hub RAG] catalog exhaustive failed', ['code' => $out['code'], 'snippet' => substr($out['raw'], 0, 400)]);
            }

            return $empty;
        }
        $b = $out['body'];
        $tf = isset($b['total_found']) && is_numeric($b['total_found']) ? (int) $b['total_found'] : null;
        $avg = $b['similarity_avg'] ?? null;
        $catalog_debug = isset($b['catalog_debug']) && is_array($b['catalog_debug']) ? $b['catalog_debug'] : [];

        return [
            'context'        => (string) ($b['context'] ?? ''),
            'chunk_count'    => (int) ($b['chunk_count'] ?? 0),
            'similarity_avg' => is_numeric($avg) ? (float) $avg : null,
            'total_found'    => $tf,
            'catalog_debug'  => $catalog_debug,
        ];
    }

    /**
     * Envía vectores locales al Hub (lotes). Modo incremental: UPSERT sin borrado masivo.
     *
     * @param array{incremental?: bool} $args incremental=true → replace_project=false (auto-sync / Reloj Maestro).
     * @return array{ok: bool, message: string, inserted: int, batches: int, detail: mixed}
     */
    public static function sync_vectors_to_hub(string $project_id, array $args = []): array {
        @ini_set('max_execution_time', '240');
        if (function_exists('set_time_limit')) {
            @set_time_limit(240);
        }

        $project_id = sanitize_text_field($project_id);
        if ($project_id === '') {
            return [
                'ok'      => false,
                'message' => __('Proyecto no válido.', 'xabia-intelligence'),
                'inserted'=> 0,
                'batches' => 0,
                'detail'  => null,
            ];
        }
        if (!self::is_hub_rag_enabled($project_id)) {
            return [
                'ok'      => false,
                'message' => __('Activa Xabia Cloud y la licencia para sincronizar el cerebro con el Hub.', 'xabia-intelligence'),
                'inserted'=> 0,
                'batches' => 0,
                'detail'  => null,
            ];
        }

        global $wpdb;
        if (!class_exists('Xabia_DB', false)) {
            return [
                'ok'      => false,
                'message' => __('Xabia_DB no disponible.', 'xabia-intelligence'),
                'inserted'=> 0,
                'batches' => 0,
                'detail'  => null,
            ];
        }
        $t = Xabia_DB::table('knowledge_vectors');
        $meta_col = Xabia_DB::knowledge_vectors_meta_column();
        $vec_col = Xabia_DB::knowledge_vectors_vector_column();
        $has_emb = Xabia_DB::knowledge_vectors_sql_has_embedding();
        $cols = Xabia_DB::knowledge_vectors_column_map();
        $incremental = !empty($args['incremental']);

        $select_extra = '';
        if (isset($cols['source_record_id'])) {
            $select_extra .= ', source_record_id';
        }
        if (isset($cols['content_hash'])) {
            $select_extra .= ', content_hash';
        }

        $total_rows = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE project_id = %s",
            $project_id
        ));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ente_id, content_chunk{$select_extra}, {$meta_col} AS meta_data, {$vec_col} AS vector_data FROM {$t} WHERE project_id = %s AND ({$has_emb})",
                $project_id
            ),
            ARRAY_A
        );
        $text_only_mode = false;
        if (!is_array($rows) || $rows === []) {
            if ($total_rows < 1) {
                return [
                    'ok'      => false,
                    'message' => __('No hay datos en la memoria local. Ejecuta primero «Sincronizar datos».', 'xabia-intelligence'),
                    'inserted'=> 0,
                    'batches' => 0,
                    'detail'  => null,
                ];
            }
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ente_id, content_chunk{$select_extra}, {$meta_col} AS meta_data, {$vec_col} AS vector_data FROM {$t} WHERE project_id = %s AND TRIM(content_chunk) <> ''",
                    $project_id
                ),
                ARRAY_A
            );
            $text_only_mode = true;
            if (!is_array($rows) || $rows === []) {
                return [
                    'ok'      => false,
                    'message' => __('Hay registros sincronizados pero sin texto utilizable. Revisa el mapeo y vuelve a sincronizar.', 'xabia-intelligence'),
                    'inserted'=> 0,
                    'batches' => 0,
                    'detail'  => null,
                ];
            }
        }

        $url = (string) apply_filters('xabia_hub_knowledge_sync_url', Xabia_Digixop_Client::default_knowledge_sync_url(), $project_id);
        $batch_size = (int) apply_filters('xabia_hub_knowledge_sync_batch_size', 50, $project_id);
        $batch_size = max(10, min(100, $batch_size));
        $batches = array_chunk($rows, $batch_size);
        $inserted = 0;
        $sent_batches = 0;
        $valid_items = 0;
        $failed_batches = [];
        $did_replace = false;
        $replace_transient = 'xabia_hub_sync_replaced_' . md5($project_id);
        $replace_already_done = (bool) get_transient($replace_transient);

        foreach ($batches as $batch_index => $batch) {
            $items = [];
            foreach ($batch as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $raw_chunk = (string) ($r['content_chunk'] ?? '');
                if (trim($raw_chunk) === '') {
                    continue;
                }
                $vec = json_decode((string) ($r['vector_data'] ?? ''), true);
                $has_valid_vec = is_array($vec) && $vec !== [];
                if (!$has_valid_vec) {
                    $vec = [];
                }
                $md = json_decode((string) ($r['meta_data'] ?? ''), true);
                $mdArr = is_array($md) ? $md : [];
                $chunk = self::append_imagen_for_rag_context($raw_chunk, $mdArr, $project_id);

                $ente_id = class_exists('Xabia_Knowledge_Ingest', false)
                    ? Xabia_Knowledge_Ingest::canonical_slug((string) ($r['ente_id'] ?? ''))
                    : sanitize_title((string) ($r['ente_id'] ?? ''));
                if ($ente_id === '' || $ente_id === 'global') {
                    $ente_id = 'global';
                }

                $source_record_id = trim((string) ($r['source_record_id'] ?? ''));
                if ($source_record_id === '' && !empty($mdArr['__source_record_id'])) {
                    $source_record_id = trim((string) $mdArr['__source_record_id']);
                }
                if ($source_record_id === '' && !empty($mdArr['__canonical_key'])) {
                    $source_record_id = trim((string) $mdArr['__canonical_key']);
                }
                if ($ente_id !== '' && $ente_id !== 'global') {
                    $source_record_id = $ente_id;
                } elseif ($source_record_id === '') {
                    foreach (['source_record_id', 'source_id', 'sourceId', 'Slug_Empresa', 'post_name', 'slug'] as $meta_key) {
                        if (!empty($mdArr[$meta_key])) {
                            $candidate = class_exists('Xabia_Knowledge_Ingest', false)
                                ? Xabia_Knowledge_Ingest::canonical_slug((string) $mdArr[$meta_key])
                                : sanitize_title((string) $mdArr[$meta_key]);
                            if ($candidate !== '') {
                                $source_record_id = $candidate;
                                $ente_id = $candidate;
                                break;
                            }
                        }
                    }
                }
                if ($source_record_id !== '' && class_exists('Xabia_Knowledge_Ingest', false)) {
                    $source_record_id = Xabia_Knowledge_Ingest::canonical_slug($source_record_id);
                }
                if ($ente_id !== '' && $ente_id !== 'global' && class_exists('Xabia_Knowledge_Ingest', false)) {
                    $ente_id = Xabia_Knowledge_Ingest::canonical_slug($ente_id);
                }

                $stored_hash = trim((string) ($r['content_hash'] ?? ''));
                $live_hash = class_exists('Xabia_DB', false)
                    ? Xabia_DB::compute_content_hash($raw_chunk)
                    : md5($raw_chunk);
                $content_hash = $stored_hash !== '' ? $stored_hash : $live_hash;
                if ($source_record_id === '') {
                    $ente_for_source = trim((string) ($r['ente_id'] ?? ''));
                    $source_record_id = $ente_for_source !== '' && $ente_for_source !== 'global'
                        ? 'ente:' . substr(hash('sha256', $ente_for_source), 0, 59)
                        : 'hash:' . substr(hash('sha256', $raw_chunk), 0, 59);
                }

                $meta_only = $has_valid_vec && ($stored_hash !== '' && hash_equals($stored_hash, $live_hash));

                $items[] = [
                    'source_record_id' => $source_record_id,
                    'content_hash'     => $content_hash,
                    'ente_id'          => $ente_id,
                    'content_chunk'    => $chunk,
                    'meta_data'        => $mdArr,
                    'vector_data'      => $has_valid_vec ? array_values($vec) : [],
                    'meta_only'        => $meta_only,
                ];
            }
            if ($items === []) {
                continue;
            }
            $batch_replace = !$incremental && !$replace_already_done && !$did_replace;
            if ($batch_replace) {
                $did_replace = true;
                $replace_already_done = true;
                set_transient($replace_transient, 1, HOUR_IN_SECONDS);
            }
            $payload = [
                'project_id'      => $project_id,
                'incremental'     => $incremental || !$batch_replace,
                'replace_project' => $batch_replace,
                'items'           => $items,
            ];
            $valid_items += count($items);
            try {
                $out = Xabia_Digixop_Client::hub_signed_json_post($url, $payload, $project_id);
            } catch (\Throwable $e) {
                $out = [
                    'ok'   => false,
                    'code' => 0,
                    'body' => null,
                    'raw'  => $e->getMessage(),
                ];
            }
            if (!$out['ok']) {
                $detail = is_array($out['body']) ? $out['body'] : ['raw' => substr($out['raw'], 0, 800)];
                if (function_exists('xabia_trace')) {
                    xabia_trace('[Xabia Hub sync] batch failed', ['i' => $batch_index, 'sent_batches' => $sent_batches, 'code' => $out['code'], 'detail' => $detail]);
                }
                error_log('[Xabia Hub sync] batch ' . (string) $batch_index . ' HTTP ' . (string) $out['code'] . ' ' . wp_json_encode($detail));

                $failed_batches[] = [
                    'batch' => (int) $batch_index,
                    'code'  => (int) $out['code'],
                    'items' => count($items),
                    'error' => $detail,
                ];
                continue;
            }
            ++$sent_batches;
            $body = is_array($out['body']) ? $out['body'] : [];
            $batch_count = (int) ($body['upserted'] ?? 0);
            if ($batch_count < 1) {
                $batch_count = (int) ($body['inserted'] ?? 0) + (int) ($body['updated'] ?? 0);
            }
            $inserted += $batch_count;
        }

        if ($sent_batches < 1) {
            return [
                'ok'      => false,
                'message' => __('No había vectores válidos para enviar al Hub.', 'xabia-intelligence'),
                'inserted'=> 0,
                'batches' => 0,
                'detail'  => [
                    'local_rows'      => count($rows),
                    'valid_items'     => $valid_items,
                    'failed_batches'  => $failed_batches,
                    'batch_size'      => $batch_size,
                    'continued_retry' => $replace_already_done && !$incremental,
                ],
            ];
        }

        if ($failed_batches !== []) {
            return [
                'ok'      => false,
                'message' => __('Sincronización parcial: algunos lotes fallaron, vuelve a pulsar para continuar sin borrar lo ya subido.', 'xabia-intelligence'),
                'inserted'=> $inserted,
                'batches' => $sent_batches,
                'detail'  => [
                    'local_rows'      => count($rows),
                    'valid_items'     => $valid_items,
                    'failed_batches'  => $failed_batches,
                    'batch_size'      => $batch_size,
                    'continued_retry' => $replace_already_done && !$incremental,
                    'replace_run'     => $did_replace,
                ],
            ];
        }

        $success_message = $text_only_mode
            ? __('Texto enviado al Hub. Si faltan embeddings allí, el Hub los generará (o entrena localmente y vuelve a subir).', 'xabia-intelligence')
            : __('Conocimiento sincronizado. Xabia ya tiene acceso a estos datos.', 'xabia-intelligence');

        return [
            'ok'      => true,
            'message' => $success_message,
            'inserted'=> $inserted,
            'batches' => $sent_batches,
            'detail'  => [
                'local_rows'     => count($rows),
                'valid_items'    => $valid_items,
                'batch_size'     => $batch_size,
                'replace_run'    => $did_replace,
                'text_only_mode' => $text_only_mode,
            ],
        ];
    }

    /**
     * Valor del campo de imagen en meta_data del vector (mapeo SQL / CSV).
     *
     * @param array<string, mixed> $meta
     */
    /**
     * Valor del campo de imagen en meta_data del vector (mapeo SQL / CSV).
     * Preferir URLs absolutas ya resueltas en ingesta (__image_url / empresa_logo https).
     *
     * @param array<string, mixed> $meta
     */
    private static function extract_imagen_from_meta(array $meta): string {
        foreach (['__image_url', 'empresa_logo', 'logotipo', 'logo', 'imagen', 'image', 'url_imagen', 'imagen_url', 'featured_image', 'foto', 'photo', 'thumbnail'] as $k) {
            if (!array_key_exists($k, $meta) || $meta[$k] === null || $meta[$k] === '') {
                continue;
            }
            $v = is_scalar($meta[$k]) ? trim((string) $meta[$k]) : '';
            if ($v !== '' && preg_match('#^https?://#i', $v)) {
                return $v;
            }
        }
        if (!empty($meta['__image_urls']) && is_string($meta['__image_urls'])) {
            foreach (explode('|', $meta['__image_urls']) as $part) {
                $part = trim($part);
                if ($part !== '' && preg_match('#^https?://#i', $part)) {
                    return $part;
                }
            }
        }

        return '';
    }

    /**
     * Anexa marcadores [Imagen disponible: URL] al chunk hacia el Hub.
     * Solo URLs absolutas (resueltas en ingesta). Si meta aún tiene ID numérico, intenta
     * resolverlo aquí como parte del pipeline de sync (no en el chat).
     *
     * @param array<string, mixed> $meta
     */
    private static function append_imagen_for_rag_context(string $content_chunk, array $meta, string $project_id = ''): string {
        $urls = [];
        if (class_exists('Xabia_Knowledge_Ingest', false)) {
            $urls = Xabia_Knowledge_Ingest::extract_imagen_disponible_urls($content_chunk);
        }
        $from_meta = self::extract_imagen_from_meta($meta);
        if ($from_meta !== '') {
            $urls[] = $from_meta;
        }

        // Sync-time only: IDs huérfanos → URL remota (ingesta incompleta / datos viejos).
        if ($urls === [] && $project_id !== '' && class_exists('Xabia_Knowledge_Ingest', false)) {
            foreach (['empresa_logo', 'logotipo', 'logo', 'imagen', 'image'] as $k) {
                if (empty($meta[$k]) || !is_scalar($meta[$k])) {
                    continue;
                }
                $raw = trim((string) $meta[$k]);
                if ($raw === '' || !ctype_digit($raw)) {
                    continue;
                }
                $resolved = Xabia_Knowledge_Ingest::resolve_media_value_to_absolute_url($raw, [
                    'project_id' => $project_id,
                ]);
                if ($resolved !== '') {
                    $urls[] = $resolved;
                    break;
                }
            }
        }

        $urls = array_values(array_unique(array_filter($urls)));
        if ($urls === []) {
            return $content_chunk;
        }
        if (class_exists('Xabia_Knowledge_Ingest', false)) {
            return Xabia_Knowledge_Ingest::append_imagen_disponible_markers($content_chunk, $urls);
        }

        return $content_chunk;
    }

    /**
     * Borra conocimiento del proyecto en el Hub (store + vectores) por license_id + project_id.
     */
    public static function purge_hub_project(string $project_id): array {
        if (!self::is_hub_rag_enabled($project_id)) {
            return ['ok' => false, 'message' => __('Hub RAG no activo.', 'xabia-intelligence')];
        }
        $url = (string) apply_filters(
            'xabia_hub_knowledge_sync_url',
            Xabia_Digixop_Client::default_knowledge_sync_url(),
            $project_id
        );
        $payload = [
            'project_id' => $project_id,
            'purge_only' => true,
        ];
        $out = Xabia_Digixop_Client::hub_signed_json_post($url, $payload, $project_id);
        if (!$out['ok']) {
            return [
                'ok'      => false,
                'message' => __('No se pudo limpiar el Hub.', 'xabia-intelligence'),
                'detail'  => is_array($out['body']) ? $out['body'] : substr((string) $out['raw'], 0, 400),
            ];
        }
        $body = is_array($out['body']) ? $out['body'] : [];

        return [
            'ok'      => !empty($body['ok']),
            'message' => !empty($body['ok'])
                ? __('Memoria del Hub borrada para este agente.', 'xabia-intelligence')
                : __('Respuesta inesperada del Hub al borrar memoria.', 'xabia-intelligence'),
            'deleted' => (int) ($body['deleted'] ?? 0),
        ];
    }
}
