<?php
/**
 * XABIA BRAIN — búsqueda RAG (LIKE y vectorial) y formateo de contexto.
 * - LIKE: prioridad frase exacta y palabras clave (cuando no hay vectores o use_vector = false).
 * - Vector: embeddings + similitud coseno, umbral y top_k (cuando use_vector y hay vector_data).
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('Xabia_Brain')) :

class Xabia_Brain {

    
    const DEFAULT_MAX_CHUNKS = 4;
    /** Coincide con el límite del Hub y el campo «Resultados máximos de contexto» en el admin. */
    const MAX_RAG_CHUNKS = 15;
    /** Listados de catálogo (varias empresas): evita discriminar por el top-k corto del chat normal. */
    const MAX_CATALOG_RAG_CHUNKS = 50;
    /**
     * Embeddings vía Hub/Vertex (espacio vectorial unificado).
     * OpenAI BYOK directo usa {@see self::OPENAI_BYOK_EMBEDDING_MODEL}.
     */
    const EMBEDDING_MODEL = 'text-embedding-004';

    /** Embeddings solo cuando el agente llama a api.openai.com con clave propia. */
    const OPENAI_BYOK_EMBEDDING_MODEL = 'text-embedding-3-small';
    const VECTOR_CANDIDATES_LIMIT = 200;

    /**
     * Top-K efectivo para RAG según reglas del proyecto (legacy context_chunk_limit o max_chunks_context).
     */
    public static function effective_rag_max_chunks_from_project_config(array $config): int {
        $rules = isset($config['rules']) && is_array($config['rules']) ? $config['rules'] : [];
        if (isset($rules['max_chunks_context']) && $rules['max_chunks_context'] !== '' && $rules['max_chunks_context'] !== null) {
            return max(1, min(self::MAX_RAG_CHUNKS, (int) $rules['max_chunks_context']));
        }
        if (isset($rules['context_chunk_limit']) && $rules['context_chunk_limit'] !== '' && $rules['context_chunk_limit'] !== null) {
            return max(1, min(self::MAX_RAG_CHUNKS, (int) $rules['context_chunk_limit']));
        }

        return self::DEFAULT_MAX_CHUNKS;
    }

    /**
     * Términos para LIKE: frase completa, sin signos finales típicos y palabras sin puntuación adherida
     * (p. ej. "caballo?" → también "caballo"), UTF-8. Ampliable con `xabia_brain_search_like_terms`.
     *
     * @return list<string>
     */
    private static function normalize_search_like_terms(string $query): array {
        $query = trim((string) $query);
        if ($query === '') {
            return [];
        }
        $terms = [];
        $terms[] = $query;
        $stripped_end = preg_replace('/[\p{P}\p{Z}]+$/u', '', $query);
        if ($stripped_end !== '' && $stripped_end !== $query) {
            $terms[] = $stripped_end;
        }
        foreach (preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) as $raw) {
            $t = preg_replace('/^[\p{P}\p{Z}]+|[\p{P}\p{Z}]+$/u', '', $raw);
            if ($t !== '' && mb_strlen($t, 'UTF-8') > 2) {
                $terms[] = $t;
            }
        }
        $terms = apply_filters('xabia_brain_search_like_terms', array_values(array_unique($terms)), $query);

        return is_array($terms) ? array_values(array_filter(array_map('strval', $terms))) : [];
    }

    /**
     * Columna de metadatos en knowledge_vectors si existe (Hub: meta_json; local: meta_data).
     */
    private static function knowledge_meta_column_for_search(): ?string {
        if (!class_exists('Xabia_DB', false)) {
            return null;
        }
        $cm = Xabia_DB::knowledge_vectors_column_map();
        if (isset($cm['meta_json'])) {
            return 'meta_json';
        }
        if (isset($cm['meta_data'])) {
            return 'meta_data';
        }

        return null;
    }

    /**
     * Búsqueda por LIKE (keywords). Usado cuando no hay vector search o como fallback.
     */
    public static function search_knowledge($project_id, $query, $scope = 'global', $strict_ente = false, $max_chunks = null) {
        global $wpdb;
        $table = Xabia_DB::table('knowledge_vectors');
        
        $sql = "SELECT content_chunk FROM $table WHERE project_id = %s";
        $args = [$project_id];

        if ($scope !== 'global' && !empty($scope)) {
            $sql .= " AND ente_id = %s";
            $args[] = $scope;
        }

        $query = trim((string)$query);
        if (!($strict_ente && $scope !== 'global' && !empty($scope))) {
            $terms = self::normalize_search_like_terms($query);
            $meta_col = self::knowledge_meta_column_for_search();
            $like_parts = [];
            foreach ($terms as $term) {
                if ($term === '') {
                    continue;
                }
                $pat = '%' . $wpdb->esc_like($term) . '%';
                if ($meta_col !== null) {
                    $col_sql = ($meta_col === 'meta_json') ? 'meta_json' : 'meta_data';
                $like_parts[] = "(content_chunk LIKE %s OR `{$col_sql}` LIKE %s)";
                    $args[] = $pat;
                    $args[] = $pat;
                } else {
                    $like_parts[] = 'content_chunk LIKE %s';
                    $args[] = $pat;
                }
            }
            if (!empty($like_parts)) {
                $sql .= ' AND (' . implode(' OR ', $like_parts) . ')';
            }
            $order_hint = $terms[0] ?? $query;
            $sql .= ' ORDER BY (CASE WHEN content_chunk LIKE %s THEN 1 ELSE 2 END) ASC, id DESC';
            $args[] = '%' . $wpdb->esc_like($order_hint) . '%';
        } else {
            $sql .= " ORDER BY id DESC";
        }

        $limit = $max_chunks !== null ? max(1, min(self::MAX_RAG_CHUNKS, (int) $max_chunks)) : self::DEFAULT_MAX_CHUNKS;
        if ($max_chunks !== null && (int) $max_chunks > self::MAX_RAG_CHUNKS) {
            $limit = max(1, min(self::MAX_CATALOG_RAG_CHUNKS, (int) $max_chunks));
        }
        $sql .= " LIMIT " . (int) $limit;

        $results = $wpdb->get_results($wpdb->prepare($sql, $args));
        return self::format_context_from_rows($results);
    }

    /**
     * Búsqueda léxica rankeada (para RRF híbrido local).
     *
     * @return list<array{id: string, content: string, score: float}>
     */
    public static function search_knowledge_ranked($project_id, $query, $scope = 'global', $strict_ente = false, $max_chunks = null) {
        global $wpdb;
        $table = Xabia_DB::table('knowledge_vectors');

        $sql = "SELECT id, content_chunk FROM $table WHERE project_id = %s";
        $args = [$project_id];

        if ($scope !== 'global' && !empty($scope)) {
            $sql .= " AND ente_id = %s";
            $args[] = $scope;
        }

        $query = trim((string) $query);
        $terms = [];
        if (!($strict_ente && $scope !== 'global' && !empty($scope))) {
            $terms = self::normalize_search_like_terms($query);
            $meta_col = self::knowledge_meta_column_for_search();
            $like_parts = [];
            foreach ($terms as $term) {
                if ($term === '') {
                    continue;
                }
                $pat = '%' . $wpdb->esc_like($term) . '%';
                if ($meta_col !== null) {
                    $col_sql = ($meta_col === 'meta_json') ? 'meta_json' : 'meta_data';
                    $like_parts[] = "(content_chunk LIKE %s OR `{$col_sql}` LIKE %s)";
                    $args[] = $pat;
                    $args[] = $pat;
                } else {
                    $like_parts[] = 'content_chunk LIKE %s';
                    $args[] = $pat;
                }
            }
            if (!empty($like_parts)) {
                $sql .= ' AND (' . implode(' OR ', $like_parts) . ')';
            }
            $order_hint = $terms[0] ?? $query;
            $sql .= ' ORDER BY (CASE WHEN content_chunk LIKE %s THEN 1 ELSE 2 END) ASC, id DESC';
            $args[] = '%' . $wpdb->esc_like($order_hint) . '%';
        } else {
            $sql .= ' ORDER BY id DESC';
        }

        $limit = $max_chunks !== null ? max(1, min(self::MAX_RAG_CHUNKS, (int) $max_chunks)) : self::DEFAULT_MAX_CHUNKS;
        if ($max_chunks !== null && (int) $max_chunks > self::MAX_RAG_CHUNKS) {
            $limit = max(1, min(self::MAX_CATALOG_RAG_CHUNKS, (int) $max_chunks));
        }
        $fetch = min(self::MAX_CATALOG_RAG_CHUNKS, max($limit * 3, 24));
        $sql .= ' LIMIT ' . (int) $fetch;

        $results = $wpdb->get_results($wpdb->prepare($sql, $args));
        if (!is_array($results) || $results === []) {
            // Soft-prefix rescue: fetch recent candidates and score in PHP when LIKE misses morphology.
            if ($query !== '' && class_exists('Xabia_Rag_Language_Bridge', false)) {
                return self::search_knowledge_ranked_soft($project_id, $query, $scope, $limit);
            }

            return [];
        }

        $ranked = [];
        $rank = 0;
        foreach ($results as $r) {
            $content = trim((string) ($r->content_chunk ?? ''));
            if ($content === '') {
                continue;
            }
            ++$rank;
            $score = max(0.05, 1.0 - (($rank - 1) * 0.03));
            if (class_exists('Xabia_Rag_Language_Bridge', false)) {
                foreach ($terms as $term) {
                    if ($term !== '' && Xabia_Rag_Language_Bridge::tokens_soft_match($term, $content)) {
                        $score = min(0.99, $score + 0.08);
                    }
                }
            }
            $ranked[] = [
                'id'      => (string) ($r->id ?? (class_exists('Xabia_Rag_Hybrid_Ranker', false)
                    ? Xabia_Rag_Hybrid_Ranker::content_key($content)
                    : md5($content))),
                'content' => $content,
                'score'   => $score,
            ];
            if (count($ranked) >= $limit) {
                break;
            }
        }
        usort($ranked, static fn ($a, $b) => ($b['score'] <=> $a['score']));

        return array_slice($ranked, 0, $limit);
    }

    /**
     * @return list<array{id: string, content: string, score: float}>
     */
    private static function search_knowledge_ranked_soft(string $project_id, string $query, string $scope, int $limit): array {
        global $wpdb;
        $table = Xabia_DB::table('knowledge_vectors');
        $sql = "SELECT id, content_chunk FROM $table WHERE project_id = %s";
        $args = [$project_id];
        if ($scope !== 'global' && $scope !== '') {
            $sql .= ' AND ente_id = %s';
            $args[] = $scope;
        }
        $sql .= ' ORDER BY id DESC LIMIT 200';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args));
        if (!is_array($rows)) {
            return [];
        }
        $tokens = class_exists('Xabia_Rag_Language_Bridge', false)
            ? Xabia_Rag_Language_Bridge::retrieval_term_variants($query)
            : (preg_split('/\s+/u', mb_strtolower($query, 'UTF-8')) ?: []);
        if (!is_array($tokens)) {
            $tokens = [];
        }
        $tokens = array_values(array_filter(array_map('strval', $tokens), static function ($t) {
            return mb_strlen($t, 'UTF-8') >= 4;
        }));
        $hits = [];
        foreach ($rows as $r) {
            $content = trim((string) ($r->content_chunk ?? ''));
            if ($content === '') {
                continue;
            }
            $matched = 0;
            foreach ($tokens as $tok) {
                if (Xabia_Rag_Language_Bridge::tokens_soft_match($tok, $content)) {
                    ++$matched;
                }
            }
            if ($matched < 1) {
                continue;
            }
            $hits[] = [
                'id'      => (string) ($r->id ?? ''),
                'content' => $content,
                'score'   => min(0.96, 0.4 + 0.12 * $matched),
            ];
        }
        usort($hits, static fn ($a, $b) => ($b['score'] <=> $a['score']));

        return array_slice($hits, 0, max(1, $limit));
    }

    /**
     * Listado de catálogo: solo fichas EMPRESA, filtradas por CATEGORÍA/SUBCATEGORÍAS (no EXPERIENCIAS).
     *
     * @param array{match_in_header?: list<string>, exclude_in_category?: list<string>} $activity_profile
     * @return string Contexto compacto (cabecera de pasaporte por empresa).
     */
    public static function search_catalog_companies(
        string $project_id,
        string $scope = 'global',
        bool $strict_ente = false,
        $max_chunks = null,
        array $activity_profile = []
    ): string {
        global $wpdb;
        $table = class_exists('Xabia_DB', false)
            ? Xabia_DB::resolve_knowledge_catalog_table($project_id)
            : Xabia_DB::table('knowledge_vectors');
        $project_id = trim(sanitize_text_field($project_id));
        if ($project_id === '') {
            return '';
        }

        $limit = $max_chunks !== null
            ? max(1, min(self::MAX_CATALOG_RAG_CHUNKS, (int) $max_chunks))
            : self::MAX_CATALOG_RAG_CHUNKS;

        $table_sql = preg_replace('/[^a-z0-9_]/i', '', (string) $table);
        if ($table_sql === '') {
            return '';
        }

        $sql = "SELECT ente_id, content_chunk FROM `{$table_sql}` WHERE project_id = %s AND content_chunk LIKE %s";
        $args = [$project_id, '%EMPRESA:%'];

        if ($scope !== 'global' && $scope !== '') {
            $sql .= ' AND ente_id = %s';
            $args[] = $scope;
        }

        $sql .= ' ORDER BY id ASC LIMIT 800';

        $results = $wpdb->get_results($wpdb->prepare($sql, $args));
        if ($wpdb->last_error) {
            error_log('[XABIA_BRAIN] search_catalog_companies SQL error: ' . $wpdb->last_error);
        }
        if (!is_array($results)) {
            $results = [];
        }

        return self::format_catalog_company_context_from_rows($results, $activity_profile, $limit);
    }

    /**
     * Listado de catálogo desde la SQL fuente del proyecto cuando knowledge_vectors/Hub están incompletos.
     *
     * @param array<string, mixed> $config
     * @param array{match_in_header?: list<string>, exclude_in_category?: list<string>, exclude_ente_slugs?: list<string>, match_regexp?: string} $activity_profile
     */
    public static function search_catalog_companies_from_sql_source(
        string $project_id,
        array $config,
        array $activity_profile = [],
        $max_chunks = null
    ): string {
        if (!class_exists('Xabia_Knowledge_Ingest', false)) {
            return '';
        }
        $post_type = Xabia_Knowledge_Ingest::resolve_catalog_post_type($config);
        if ($post_type === '' && trim((string) (($config['sql_config']['query'] ?? ''))) === '') {
            return '';
        }

        $raw_data = Xabia_Knowledge_Ingest::fetch_lightweight_catalog_rows($project_id, $config);
        if ($raw_data === []) {
            return '';
        }

        $mapping = is_array($config['attributes'] ?? null) ? $config['attributes'] : [];
        $results = [];
        foreach ($raw_data as $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }
            if (Xabia_Knowledge_Ingest::should_skip_translated_row($row, $project_id)) {
                continue;
            }
            $prepared = Xabia_Knowledge_Ingest::build_passport_chunk($row, $mapping, [
                'project_id'     => (string) $project_id,
                'project_config' => $config,
            ]);
            $blob = trim((string) ($prepared['text_blob'] ?? ''));
            if ($blob === '' || stripos($blob, 'EMPRESA:') === false) {
                continue;
            }
            $results[] = (object) [
                'ente_id'       => self::catalog_ente_id_from_sql_row($row),
                'content_chunk' => $blob,
            ];
        }
        Xabia_Knowledge_Ingest::$last_lightweight_catalog_stats['passports'] = count($results);
        if ($results === []) {
            return '';
        }

        $limit = $max_chunks !== null
            ? max(1, min(self::MAX_CATALOG_RAG_CHUNKS, (int) $max_chunks))
            : self::MAX_CATALOG_RAG_CHUNKS;

        return self::format_catalog_company_context_from_rows($results, $activity_profile, $limit);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function catalog_ente_id_from_sql_row(array $row): string
    {
        foreach (['Slug_Empresa', 'post_name', 'slug', 'post_slug'] as $col) {
            if (empty($row[$col])) {
                continue;
            }
            $slug = Xabia_Knowledge_Ingest::canonical_slug((string) $row[$col]);
            if ($slug !== '' && $slug !== 'global') {
                return $slug;
            }
        }
        if (!empty($row['ID']) && ctype_digit((string) $row['ID'])) {
            return 'id-' . (string) absint($row['ID']);
        }

        return '';
    }

    /**
     * Cabecera de pasaporte sin EXPERIENCIAS / PROPUESTAS / descripción larga.
     */
    public static function company_taxonomy_header(string $chunk): string
    {
        $chunk = trim(wp_strip_all_tags($chunk));
        if ($chunk === '') {
            return '';
        }
        if (preg_match('/^(.*?)(?:\s*\|\s*(?:EXPERIENCIAS|PROPUESTAS|DESCRIPCI[ÓO]N\s+GENERAL):)/isu', $chunk, $m)) {
            return trim((string) $m[1]);
        }
        $parts = preg_split('/\s*\|\s*/u', $chunk);
        if (is_array($parts) && $parts !== []) {
            return trim(implode(' | ', array_slice($parts, 0, 6)));
        }

        return mb_substr($chunk, 0, 420, 'UTF-8');
    }

    /**
     * Texto indexable para filtro de actividad: cabecera + EXPERIENCIAS (no descripción larga).
     */
    public static function company_activity_match_text(string $chunk): string
    {
        $chunk = trim(wp_strip_all_tags($chunk));
        if ($chunk === '') {
            return '';
        }
        $parts = [self::company_taxonomy_header($chunk)];
        if (preg_match('/\bEXPERIENCIAS:\s*(.+?)(?:\s*\|\s*(?:PROPUESTAS|DESCRIPCI[ÓO]N\s+GENERAL):)/isu', $chunk, $m)) {
            $parts[] = trim((string) $m[1]);
        } elseif (preg_match('/\bEXPERIENCIAS:\s*(.+)$/isu', $chunk, $m)) {
            $parts[] = trim((string) $m[1]);
        }

        return trim(implode(' ', array_filter($parts)));
    }

    private static function catalog_norm_str(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        if ($text === '') {
            return '';
        }
        if (function_exists('remove_accents')) {
            $text = remove_accents($text);
        }

        return $text;
    }

    /**
     * @param array{match_in_header?: list<string>, exclude_in_category?: list<string>, exclude_ente_slugs?: list<string>} $profile
     */
    public static function matches_catalog_activity_profile(string $chunk, array $profile, string $ente_id = ''): bool
    {
        if ($profile === []) {
            return true;
        }
        $ente_id = self::catalog_norm_str($ente_id);
        foreach ($profile['exclude_ente_slugs'] ?? [] as $slug) {
            $sl = self::catalog_norm_str((string) $slug);
            if ($sl !== '' && $ente_id !== '' && mb_strpos($ente_id, $sl) !== false) {
                return false;
            }
        }
        $header = self::company_taxonomy_header($chunk);
        $category = '';
        if (preg_match('/\bcategor[ií]a:\s*([^|]+)/iu', $header, $cm)) {
            $category = self::catalog_norm_str(trim((string) $cm[1]));
        }
        foreach ($profile['exclude_in_category'] ?? [] as $ex) {
            $exl = self::catalog_norm_str((string) $ex);
            if ($exl === '' || $category === '') {
                continue;
            }
            if ($category === $exl || mb_strpos($category, $exl) !== false) {
                return false;
            }
        }
        foreach ($profile['match_category'] ?? [] as $cat_needle) {
            $cn = self::catalog_norm_str((string) $cat_needle);
            if ($cn !== '' && $category !== '' && ($category === $cn || mb_strpos($category, $cn) !== false)) {
                return true;
            }
        }
        $subcats = '';
        if (preg_match('/\bsubcategor[ií]as:\s*([^|]+)/iu', $header, $sm)) {
            $subcats = self::catalog_norm_str(trim((string) $sm[1]));
        }
        foreach ($profile['match_subcategory'] ?? [] as $sub_needle) {
            $sn = self::catalog_norm_str((string) $sub_needle);
            if ($sn !== '' && $subcats !== '' && mb_strpos($subcats, $sn) !== false) {
                return true;
            }
        }
        $match_regexp = trim((string) ($profile['match_regexp'] ?? ''));
        if ($match_regexp !== '') {
            $blob = self::catalog_norm_str(wp_strip_all_tags($chunk));
            if ($blob !== '' && @preg_match('/' . $match_regexp . '/iu', $blob) === 1) {
                return true;
            }
        }
        $match_scope = (string) ($profile['match_scope'] ?? '');
        if ($match_scope === 'experiences') {
            $exp_text = '';
            if (preg_match('/\bEXPERIENCIAS:\s*(.+?)(?:\s*\|\s*(?:PROPUESTAS|DESCRIPCI[ÓO]N\s+GENERAL):)/isu', $chunk, $em)) {
                $exp_text = trim((string) $em[1]);
            } elseif (preg_match('/\bEXPERIENCIAS:\s*(.+)$/isu', $chunk, $em)) {
                $exp_text = trim((string) $em[1]);
            }
            $haystack = self::catalog_norm_str($exp_text);
            if ($haystack === '') {
                return false;
            }
            foreach ($profile['match_in_header'] ?? [] as $needle) {
                $nl = self::catalog_norm_str((string) $needle);
                if ($nl === '' || mb_strlen($nl, 'UTF-8') < 3) {
                    continue;
                }
                if (mb_strpos($haystack, $nl) !== false) {
                    return true;
                }
            }

            return false;
        }
        $haystack = self::catalog_norm_str(self::company_activity_match_text($chunk));
        if ($haystack === '') {
            return false;
        }
        foreach ($profile['match_in_header'] ?? [] as $needle) {
            $nl = self::catalog_norm_str((string) $needle);
            if ($nl === '' || mb_strlen($nl, 'UTF-8') < 3) {
                continue;
            }
            if (mb_strpos($haystack, $nl) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cabecera de pasaporte (EMPRESA / CATEGORÍA / localidad) sin EXPERIENCIAS ni descripción larga.
     */
    public static function compact_company_passport_chunk(string $chunk): string
    {
        $chunk = trim(wp_strip_all_tags($chunk));
        if ($chunk === '') {
            return '';
        }
        // Listados: sin anexo de atributos mapeados (bloque tras ---).
        $chunk = preg_replace('/\n---\s*\n[\s\S]*$/u', '', $chunk) ?? $chunk;
        $chunk = trim($chunk);
        if ($chunk === '' || !preg_match('/\bEMPRESA:\s*([^|]+)/iu', $chunk)) {
            return '';
        }
        $labels = [
            'EMPRESA',
            'CATEGORÍA',
            'CATEGORIA',
            'SUBCATEGORÍAS',
            'SUBCATEGORIAS',
            'LOCALIDAD',
            'UBICACIÓN',
            'UBICACION',
            'MUNICIPIO',
            'ZONA',
            'TERRITORIO',
        ];
        $parts = [];
        foreach ($labels as $label) {
            $re = '/\b' . preg_quote($label, '/') . ':\s*([^|]+)/iu';
            if (preg_match($re, $chunk, $m)) {
                $val = trim((string) $m[1]);
                if ($val !== '') {
                    $parts[$label] = $label . ': ' . $val;
                }
            }
        }
        if ($parts === []) {
            return 'EMPRESA: ' . trim(preg_replace('/\s*\|.*/', '', preg_replace('/^.*?\bEMPRESA:\s*/iu', '', $chunk)));
        }

        return implode(' | ', array_values($parts));
    }

    /**
     * @param array<int, object>|null $results
     * @param array{match_in_header?: list<string>, exclude_in_category?: list<string>} $activity_profile
     */
    private static function format_catalog_company_context_from_rows($results, array $activity_profile = [], int $limit = 50): string
    {
        $chunks = [];
        $seen_entes = [];
        $seen_chunks = [];
        if (!$results) {
            return '';
        }
        foreach ($results as $r) {
            $raw = (string) ($r->content_chunk ?? '');
            $eid = trim((string) ($r->ente_id ?? ''));
            if ($activity_profile !== [] && !self::matches_catalog_activity_profile($raw, $activity_profile, $eid)) {
                continue;
            }
            if ($eid !== '' && isset($seen_entes[$eid])) {
                continue;
            }
            $compact = self::compact_company_passport_chunk($raw);
            if ($compact === '') {
                continue;
            }
            $key = hash('sha256', mb_strtolower($compact, 'UTF-8'));
            if (isset($seen_chunks[$key])) {
                continue;
            }
            if ($eid !== '') {
                $seen_entes[$eid] = true;
            }
            $seen_chunks[$key] = true;
            $chunks[] = $compact;
            if (count($chunks) >= $limit) {
                break;
            }
        }

        return implode("\n\n", $chunks);
    }

    /**
     * Búsqueda vectorial: usa el vector de la query (o lo genera con OpenAI si no se pasa).
     * Recupera chunks con vector_data, filtra por umbral y top_k. Doble salto: lista vs desarrollo.
     *
     * @param float $threshold Umbral de similitud coseno (0.0–1.0). Por debajo se descarta.
     * @param array|null $query_vector Vector precalculado (p. ej. Vertex/OpenAI según ai_driver). Si null, se usa get_embedding (OpenAI).
     * @param array{catalog_list?: bool, lexical_query_text?: string, keyword_boost_only?: bool, keyword_expansions?: array<string, string>} $hub_opts
     * @return array{context: string, chunk_count: int, similarity_avg: float|null, total_found?: int|null}
     */
    public static function search_knowledge_vector($project_id, $query, $scope = 'global', $strict_ente = false, $max_chunks = null, $threshold = 0.2, $query_vector = null, array $hub_opts = []) {
        global $wpdb;
        $table = Xabia_DB::table('knowledge_vectors');

        if ($query_vector === null || !is_array($query_vector)) {
            $query_vector = self::get_embedding($query, $project_id);
        }
        if ($query_vector === null || !is_array($query_vector)) {
            return [
                'context'        => '',
                'chunk_count'    => 0,
                'similarity_avg' => null,
                'total_found'    => null,
            ];
        }

        if (class_exists('Xabia_Hub_Knowledge', false) && Xabia_Hub_Knowledge::is_hub_rag_enabled($project_id)) {
            $threshold = max(0, min(1, (float) $threshold));
            $max_k = $max_chunks !== null ? max(1, min(self::MAX_RAG_CHUNKS, (int) $max_chunks)) : self::DEFAULT_MAX_CHUNKS;
            if (!empty($hub_opts['catalog_list'])) {
                $catalog_k = $max_chunks !== null ? (int) $max_chunks : self::MAX_CATALOG_RAG_CHUNKS;
                $max_k = max($max_k, min(self::MAX_CATALOG_RAG_CHUNKS, max(1, $catalog_k)));
            }
            $hubRes = Xabia_Hub_Knowledge::search_vector($project_id, $query_vector, $scope, $strict_ente, $max_k, $threshold, $query, $hub_opts);
            $hub_meta = isset($hubRes['_hub_meta']) && is_array($hubRes['_hub_meta']) ? $hubRes['_hub_meta'] : null;
            $hub_context = trim((string) ($hubRes['context'] ?? ''));
            if (($hubRes['chunk_count'] ?? 0) > 0 && $hub_context !== '') {
                return $hubRes;
            }
            if (!empty($hub_opts['keyword_boost_only'])) {
                return [
                    'context'        => (string) ($hubRes['context'] ?? ''),
                    'chunk_count'    => (int) ($hubRes['chunk_count'] ?? 0),
                    'similarity_avg' => $hubRes['similarity_avg'] ?? null,
                    'total_found'    => $hubRes['total_found'] ?? null,
                    'chunks'         => isset($hubRes['chunks']) && is_array($hubRes['chunks']) ? $hubRes['chunks'] : [],
                    '_hub_meta'      => $hub_meta,
                ];
            }
            if (!apply_filters('xabia_hub_knowledge_fallback_local', true, $project_id)) {
                return [
                    'context'        => '',
                    'chunk_count'    => 0,
                    'similarity_avg' => null,
                    'total_found'    => null,
                    '_hub_meta'      => $hub_meta,
                ];
            }
            $hub_failover_meta = $hub_meta;
        } else {
            $hub_failover_meta = null;
        }

        $vec_col = class_exists('Xabia_DB', false) ? Xabia_DB::knowledge_vectors_vector_column() : 'vector_data';
        $has_emb = class_exists('Xabia_DB', false) ? Xabia_DB::knowledge_vectors_sql_has_embedding() : 'vector_data IS NOT NULL';

        $sql = "SELECT id, content_chunk, {$vec_col} AS vector_data FROM $table WHERE project_id = %s AND ({$has_emb})";
        $args = [$project_id];
        if ($scope !== 'global' && !empty($scope)) {
            $sql .= " AND ente_id = %s";
            $args[] = $scope;
        }
        $sql .= " ORDER BY id DESC LIMIT " . (int) self::VECTOR_CANDIDATES_LIMIT;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $args));
        if (empty($rows)) {
            return ['context' => '', 'chunk_count' => 0, 'similarity_avg' => null, 'total_found' => null];
        }

        $threshold = max(0, min(1, (float) $threshold));
        $max_chunks = $max_chunks !== null ? max(1, min(self::MAX_RAG_CHUNKS, (int) $max_chunks)) : self::DEFAULT_MAX_CHUNKS;

        $scored = [];
        foreach ($rows as $r) {
            $vec = json_decode($r->vector_data, true);
            if (!is_array($vec)) continue;
            $sim = self::cosine_similarity($query_vector, $vec);
            if ($sim >= $threshold) {
                $scored[] = [
                    'id'      => (string) ($r->id ?? ''),
                    'chunk'   => $r->content_chunk,
                    'content' => (string) $r->content_chunk,
                    'score'   => $sim,
                ];
            }
        }
        usort($scored, function ($a, $b) { return $b['score'] <=> $a['score']; });
        $total_qualifying = count($scored);
        $sliced = array_slice($scored, 0, $max_chunks);

        $context = self::format_context_from_rows(array_map(function ($s) { return (object) ['content_chunk' => $s['chunk']]; }, $sliced));
        $avg = empty($sliced) ? null : array_sum(array_column($sliced, 'score')) / count($sliced);

        $out = [
            'context'        => $context,
            'chunk_count'    => count($sliced),
            'similarity_avg' => $avg,
            'total_found'    => null,
            'chunks'         => array_map(static function ($s) {
                return [
                    'id'      => (string) ($s['id'] ?? ''),
                    'content' => (string) ($s['content'] ?? $s['chunk'] ?? ''),
                    'score'   => (float) ($s['score'] ?? 0),
                ];
            }, $sliced),
        ];
        if ($total_qualifying > count($sliced)) {
            $out['total_found'] = $total_qualifying;
        }
        if (isset($hub_failover_meta) && is_array($hub_failover_meta)) {
            $out['_hub_meta'] = $hub_failover_meta;
        }

        return $out;
    }

    /**
     * Genera embedding del texto (Hub/Vertex: text-embedding-004; OpenAI BYOK: text-embedding-3-small).
     *
     * @param string $project_id ID del agente (para herencia de claves y licencia).
     */
    public static function get_embedding($text, $project_id = '') {
        if (class_exists('Xabia_Knowledge_Text', false)) {
            $text = Xabia_Knowledge_Text::embedding_input((string) $text);
        } else {
            $text = trim(strip_tags((string) $text));
        }
        if ($text === '') {
            return null;
        }
        $projects = get_option('xabia_projects_config', []);
        $config = ($project_id !== '' && isset($projects[$project_id]) && is_array($projects[$project_id]))
            ? $projects[$project_id]
            : [];
        $model = self::resolve_embedding_model_for_project($project_id, $config);
        if (class_exists('Xabia_Embedding_Cache', false)) {
            $cached = Xabia_Embedding_Cache::get($model, $text);
            if ($cached !== null) {
                return $cached;
            }
        }
        $vector = self::fetch_embedding_uncached($text, $project_id, $model, $config);
        if (is_array($vector) && $vector !== [] && class_exists('Xabia_Embedding_Cache', false)) {
            Xabia_Embedding_Cache::set($model, $text, $vector);
        }

        return $vector;
    }

    /**
     * Modelo de embedding efectivo: proxy/cloud/Vertex → text-embedding-004; OpenAI BYOK → 3-small.
     *
     * @param array<string, mixed> $config
     */
    public static function resolve_embedding_model_for_project(string $project_id, array $config = []): string {
        if (class_exists('Xabia_API', false) && method_exists('Xabia_API', 'resolve_embedding_model')) {
            return (string) Xabia_API::resolve_embedding_model($config, $project_id);
        }
        if (class_exists('Xabia_Digixop_Client', false) && Xabia_Digixop_Client::should_use_openai_proxy($project_id, $config)) {
            return self::EMBEDDING_MODEL;
        }
        if (class_exists('Xabia_Digixop_Client', false) && Xabia_Digixop_Client::should_use_local_vertex($config)) {
            return self::EMBEDDING_MODEL;
        }

        return self::OPENAI_BYOK_EMBEDDING_MODEL;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, float>|null
     */
    private static function fetch_embedding_uncached(string $text, string $project_id = '', string $model = '', array $config = []) {
        if ($config === [] && $project_id !== '') {
            $projects = get_option('xabia_projects_config', []);
            $config = (isset($projects[$project_id]) && is_array($projects[$project_id]))
                ? $projects[$project_id]
                : [];
        }
        if ($model === '') {
            $model = self::resolve_embedding_model_for_project($project_id, $config);
        }

        if (class_exists('Xabia_Digixop_Client') && Xabia_Digixop_Client::should_use_openai_proxy($project_id, $config)) {
            return Xabia_Digixop_Client::embedding_via_proxy($text, $model, $project_id, $config);
        }

        $key = class_exists('Xabia_Digixop_Client')
            ? Xabia_Digixop_Client::get_effective_openai_key($project_id, $config)
            : trim((string) get_option('xabia_openai_key', ''));
        if ($key === '') {
            return null;
        }
        // Direct OpenAI only accepts OpenAI embedding ids.
        $openai_model = self::OPENAI_BYOK_EMBEDDING_MODEL;

        $resp = wp_remote_post('https://api.openai.com/v1/embeddings', [
            'headers' => ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'],
            'body'    => json_encode(['input' => $text, 'model' => $openai_model]),
            'timeout' => 15,
        ]);
        if (is_wp_error($resp)) {
            return null;
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        return isset($body['data'][0]['embedding']) ? $body['data'][0]['embedding'] : null;
    }

    private static function cosine_similarity(array $a, array $b) {
        if (count($a) !== count($b) || count($a) === 0) return 0;
        $dot = 0;
        $norm_a = 0;
        $norm_b = 0;
        foreach ($a as $i => $v) {
            $w = isset($b[$i]) ? (float) $b[$i] : 0;
            $v = (float) $v;
            $dot += $v * $w;
            $norm_a += $v * $v;
            $norm_b += $w * $w;
        }
        $den = sqrt($norm_a) * sqrt($norm_b);
        return $den > 0 ? $dot / $den : 0;
    }

    /**
     * Densifica contexto RAG: mismas entidades/datos, menos tokens de etiquetas y whitespace.
     * Conserva líneas [Imagen disponible: …].
     */
    public static function densify_rag_context(string $context): string {
        $context = trim($context);
        if ($context === '') {
            return '';
        }
        $enabled = apply_filters('xabia_rag_densify_context', true, $context);
        if (!$enabled) {
            return $context;
        }
        $parts = preg_split('/\n\n+/u', $context);
        if (!is_array($parts) || $parts === []) {
            return self::densify_rag_chunk($context);
        }
        $out = [];
        foreach ($parts as $part) {
            $d = self::densify_rag_chunk((string) $part);
            if ($d !== '') {
                $out[] = $d;
            }
        }

        return implode("\n\n", $out);
    }

    /**
     * Compacta un chunk individual sin perder hechos ni URLs de imagen.
     */
    public static function densify_rag_chunk(string $chunk): string {
        $chunk = trim($chunk);
        if ($chunk === '') {
            return '';
        }

        $imagen_tail = '';
        if (preg_match_all('/\[Imagen disponible:\s*https?:\/\/[^\s\]]+\s*\]/iu', $chunk, $m)) {
            $imagen_tail = "\n" . implode("\n", array_unique($m[0]));
            $chunk = trim((string) preg_replace('/\[Imagen disponible:\s*https?:\/\/[^\s\]]+\s*\]/iu', '', $chunk));
        }

        // Quitar etiquetas redundantes (EMPRESA:, CATEGORÍA:, …); conservar el valor.
        $label_re = '/\b(?:'
            . 'EMPRESA|NOMBRE|NAME|ENTIDAD|ENTITY|T[IÍ]TULO|TITLE|'
            . 'CATEGOR[IÍ]A|CATEGORY|TIPO|TYPE|LOCALIDAD|CIUDAD|CITY|ZONA|BARRIO|AREA|'
            . 'DESCRIPCI[OÓ]N(?:\s+GENERAL)?|DESCRIPTION|RESUMEN|SUMMARY|'
            . 'HORARIO|SCHEDULE|FECHA|DATE|HORA|TIME|'
            . 'DIRECCI[OÓ]N|ADDRESS|TEL[EÉ]FONO|PHONE|WEB|URL|EMAIL|CORREO|'
            . 'PRECIO|PRICE|SLUG|ID|ENTE_ID|SOURCE'
            . ')\s*:\s*/iu';
        $chunk = preg_replace($label_re, '', $chunk);
        $chunk = is_string($chunk) ? $chunk : '';

        $chunk = preg_replace('/[ \t]+/u', ' ', $chunk);
        $chunk = preg_replace('/\s*\|\s*/u', ' | ', $chunk);
        $chunk = preg_replace('/(?:\s*\|\s*){2,}/u', ' | ', $chunk);
        $chunk = preg_replace('/\n{3,}/u', "\n\n", $chunk);
        $chunk = preg_replace('/^\s*\|\s*|\s*\|\s*$/u', '', (string) $chunk);
        $chunk = trim((string) $chunk);

        return rtrim($chunk) . $imagen_tail;
    }

    /**
     * Ensambla chunks recuperados para el LLM: sin viñetas ni pipes; bloques separados por línea en blanco
     * (más denso frente al límite ~20k del prompt).
     */
    private static function format_context_from_rows($results) {
        $chunks = [];
        $seen = [];
        if ($results) {
            foreach ($results as $r) {
                $chunk = trim(strip_tags($r->content_chunk ?? ''));
                if ($chunk === '' || isset($seen[$chunk])) {
                    continue;
                }
                $seen[$chunk] = true;
                $chunk = self::truncate_chunk_preserving_imagen($chunk, 900);
                $chunk = self::densify_rag_chunk($chunk);
                if ($chunk === '') {
                    continue;
                }
                $chunks[] = $chunk;
            }
        }
        return implode("\n\n", $chunks);
    }

    /**
     * Trunca el chunk pero conserva líneas [Imagen disponible: …] al final.
     */
    public static function truncate_chunk_preserving_imagen(string $chunk, int $max_chars = 900): string {
        $chunk = trim($chunk);
        if ($max_chars < 1 || strlen($chunk) <= $max_chars) {
            return $chunk;
        }
        $imagen_tail = '';
        if (preg_match_all('/\[Imagen disponible:\s*https?:\/\/[^\s\]]+\s*\]/iu', $chunk, $m)) {
            $imagen_tail = "\n" . implode("\n", array_unique($m[0]));
            $chunk = trim((string) preg_replace('/\[Imagen disponible:\s*https?:\/\/[^\s\]]+\s*\]/iu', '', $chunk));
        }
        $budget = max(80, $max_chars - strlen($imagen_tail));
        if (strlen($chunk) > $budget) {
            $chunk = substr($chunk, 0, $budget) . '…';
        }

        return rtrim($chunk) . $imagen_tail;
    }
}

endif;