<?php
/**
 * Listados de catálogo nativos WP (sin RAG, Hub ni build_passport_chunk).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Catalog_List {

    /** @var array<string, mixed> */
    public static $last_debug = [];

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $activity_profile
     *
     * @return array{manifest: list<string>, debug: array<string, mixed>}|null
     */
    public static function fetch_native_list(
        string $project_id,
        array $config,
        array $activity_profile,
        string $user_msg,
        string $search_term
    ): ?array {
        self::$last_debug = [
            'mode'       => 'native_wp',
            'post_type'  => '',
            'taxonomy'   => '',
            'term_ids'   => 0,
            'rows'       => 0,
            'matched'    => 0,
        ];

        if (!class_exists('Xabia_Knowledge_Ingest', false)) {
            return null;
        }

        $post_type = Xabia_Knowledge_Ingest::resolve_catalog_post_type($config);
        if ($post_type === '' || !function_exists('post_type_exists') || !post_type_exists($post_type)) {
            return null;
        }

        $taxonomy = Xabia_Knowledge_Ingest::resolve_catalog_activity_taxonomy($config);
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return null;
        }

        self::$last_debug['post_type'] = $post_type;
        self::$last_debug['taxonomy'] = $taxonomy;

        $entity_meta = Xabia_Knowledge_Ingest::resolve_catalog_entity_meta_keys($config);
        self::$last_debug['title_meta'] = (string) ($entity_meta['title_meta'] ?? '');
        self::$last_debug['location_meta'] = (string) ($entity_meta['location_meta'] ?? '');

        $canonical = self::distill_canonical_activity($user_msg, $search_term);
        $preresolved = $activity_profile['resolved_term_ids'] ?? null;
        if (is_array($preresolved) && $preresolved !== []) {
            $term_ids = array_values(array_unique(array_filter(array_map('intval', $preresolved), static function ($id) {
                return $id > 0;
            })));
        } else {
            $term_ids = self::resolve_matching_term_ids($taxonomy, $activity_profile, $canonical, $user_msg, $search_term);
        }
        self::$last_debug['term_ids'] = count($term_ids);
        self::$last_debug['matched_term'] = (string) ($activity_profile['matched_catalog_term'] ?? '');

        $rows = self::query_catalog_entity_rows(
            $project_id,
            $config,
            $post_type,
            $taxonomy,
            $term_ids,
            $user_msg,
            $search_term,
            $activity_profile,
            $canonical,
        );
        self::$last_debug['rows'] = count($rows);
        $rows = self::dedupe_catalog_entity_rows($rows, $project_id, $config, $post_type);
        self::$last_debug['rows_deduped'] = count($rows);
        self::$last_debug['request_url'] = self::resolve_catalog_canonical_request_url($config);

        if ($rows === []) {
            return [
                'manifest' => [],
                'debug'    => self::$last_debug,
            ];
        }

        $exclude_terms = self::normalize_term_labels($activity_profile['exclude_in_category'] ?? []);
        $manifest = [];
        $entities = [];
        foreach ($rows as $row) {
            if ($exclude_terms !== [] && self::row_matches_excluded_terms($row, $exclude_terms)) {
                continue;
            }
            $name = self::resolve_native_catalog_entity_display_name($row);
            if ($name === '') {
                continue;
            }
            $loc = trim((string) ($row['location'] ?? $row['localidad'] ?? ''));
            $cat = trim((string) ($row['categoria'] ?? ''));
            $cat_display = $cat;
            if ($cat_display !== '' && class_exists('Xabia_Knowledge_Ingest', false)) {
                $cat_display = Xabia_Knowledge_Ingest::localize_taxonomy_term_label($cat_display);
            }
            $line = '**' . $name . '**';
            if ($loc !== '') {
                $line .= ' (' . $loc . ')';
            } elseif ($cat_display !== '') {
                $line .= ' — ' . $cat_display;
            }
            $key = self::catalog_entity_bucket_key($row, $post_type, $config);
            if ($key === '' || isset($entities[$key])) {
                continue;
            }
            $manifest[$key] = $line;
            $entities[$key] = $row;
        }

        $entity_list = array_values($entities);
        usort($entity_list, static function (array $a, array $b): int {
            return strnatcasecmp((string) ($a['empresa'] ?? ''), (string) ($b['empresa'] ?? ''));
        });
        $sorted = [];
        foreach ($entity_list as $ent) {
            $name = trim((string) ($ent['empresa'] ?? ''));
            if ($name === '') {
                continue;
            }
            $loc = trim((string) ($ent['localidad'] ?? ''));
            $cat = trim((string) ($ent['categoria'] ?? ''));
            $cat_display = $cat;
            if ($cat_display !== '' && class_exists('Xabia_Knowledge_Ingest', false)) {
                $cat_display = Xabia_Knowledge_Ingest::localize_taxonomy_term_label($cat_display);
            }
            $line = '**' . $name . '**';
            if ($loc !== '') {
                $line .= ' (' . $loc . ')';
            } elseif ($cat_display !== '') {
                $line .= ' — ' . $cat_display;
            }
            $sorted[] = $line;
        }
        self::$last_debug['matched'] = count($entity_list);

        return [
            'manifest' => $sorted,
            'entities' => $entity_list,
            'debug'    => self::$last_debug,
        ];
    }

    /**
     * Cuerpo textual del listado nativo (intro + viñetas), alineado con las tarjetas v2.
     *
     * @param list<array{ID?: int, empresa?: string, localidad?: string, categoria?: string}> $entities
     */
    /**
     * Etiqueta legible para la intro (sin pipes ni agujas de sinónimos).
     */
    public static function format_native_catalog_intro_term(string $matched_term): string {
        $matched_term = trim($matched_term);
        if ($matched_term === '') {
            return '';
        }
        if (strpos($matched_term, '|') === false) {
            return $matched_term;
        }
        $parts = preg_split('/\s*\|\s*/u', $matched_term) ?: [];
        $parts = array_values(array_filter(array_map(static function ($p) {
            return trim((string) $p);
        }, $parts)));
        foreach ($parts as $part) {
            if ($part !== '' && preg_match('/^\p{Lu}/u', $part)) {
                return $part;
            }
        }

        return $parts[0] ?? $matched_term;
    }

    public static function build_native_catalog_message_body(
        array $entities,
        string $matched_term,
        string $destination_label = ''
    ): string {
        return self::compose_native_catalog_message_from_entities($entities, $matched_term, $destination_label);
    }

    /**
     * Misma cadena de prioridad que encode_native_catalog_entity_card (viñetas ≡ tarjetas).
     *
     * @param array<string, mixed> $ent
     */
    public static function resolve_native_catalog_entity_display_name(array $ent): string {
        if (!empty($ent['title'])) {
            return trim((string) $ent['title']);
        }
        if (!empty($ent['name'])) {
            return trim((string) $ent['name']);
        }
        if (!empty($ent['empresa'])) {
            return trim((string) $ent['empresa']);
        }
        if (!empty($ent['post_title'])) {
            return trim((string) $ent['post_title']);
        }

        return '';
    }

    /**
     * Cuerpo del listado nativo a partir del array final que también alimenta las tarjetas v2.
     *
     * @param list<array<string, mixed>> $final_entities
     */
    public static function compose_native_catalog_message_from_entities(
        array $final_entities,
        string $matched_term_name,
        string $destination = ''
    ): string {
        if ($final_entities === []) {
            return '';
        }

        $display_term = self::format_native_catalog_intro_term(trim($matched_term_name));
        if ($display_term === '') {
            $display_term = 'actividades';
        }
        if ($display_term === '') {
            $display_term = 'actividades';
        }
        if (class_exists('Xabia_Knowledge_Ingest', false)) {
            $display_term = Xabia_Knowledge_Ingest::localize_taxonomy_term_label($display_term);
        }

        $destination = trim($destination);
        $safe_term = function_exists('esc_html') ? esc_html($display_term) : $display_term;
        $safe_dest = function_exists('esc_html') ? esc_html($destination) : $destination;
        if ($destination !== '') {
            $intro_text = sprintf(
                'Aquí tienes las empresas de %s disponibles en %s:',
                $safe_term,
                $safe_dest
            );
        } else {
            $intro_text = sprintf('Aquí tienes las empresas de %s:', $safe_term);
        }

        $bullet_lines = self::build_native_catalog_bullet_lines_from_entities($final_entities);

        return $intro_text . "\n\n" . implode("\n", $bullet_lines);
    }

    /**
     * Una viñeta Markdown por entidad (misma SSOT que las tarjetas codificables).
     *
     * @param list<array<string, mixed>|mixed> $final_entities
     *
     * @return list<string>
     */
    public static function build_native_catalog_bullet_lines_from_entities(array $final_entities): array {
        $bullet_lines = [];
        foreach ($final_entities as $ent) {
            if (!is_array($ent)) {
                $bullet_lines[] = '• **—**';
                continue;
            }
            $name = self::resolve_native_catalog_entity_display_name($ent);
            $loc = trim((string) ($ent['location'] ?? ''));
            if ($loc === '') {
                $loc = trim((string) ($ent['address'] ?? ''));
            }
            if ($loc === '') {
                $loc = trim((string) ($ent['localidad'] ?? ''));
            }
            $loc_suffix = $loc !== '' ? ' (' . $loc . ')' : '';
            if ($name !== '') {
                $safe_name = function_exists('esc_html') ? esc_html($name) : $name;
                $safe_loc = function_exists('esc_html') ? esc_html($loc_suffix) : $loc_suffix;
                $bullet_lines[] = '• **' . $safe_name . '**' . $safe_loc;
            } else {
                $bullet_lines[] = '• **—**';
            }
        }

        return $bullet_lines;
    }

    /**
     * Clave de negocio agnóstica (dominio), p. ej. mismo título → una empresa.
     *
     * @param array<string, mixed> $row
     */
    public static function catalog_entity_canonical_key(array $row): string {
        $title = trim((string) ($row['title'] ?? $row['post_title'] ?? $row['empresa'] ?? ''));
        if ($title === '') {
            return '';
        }

        return sanitize_title($title);
    }

    /**
     * URL de referencia para desempatar traducciones (referer o petición actual).
     *
     * @param array<string, mixed> $config
     */
    public static function resolve_catalog_canonical_request_url(array $config): string {
        $explicit = trim((string) ($config['_xabia_request_url'] ?? ''));
        if ($explicit !== '' && filter_var($explicit, FILTER_VALIDATE_URL)) {
            return $explicit;
        }
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $ref = trim((string) wp_unslash((string) $_SERVER['HTTP_REFERER']));
            if ($ref !== '' && filter_var($ref, FILTER_VALIDATE_URL)) {
                return $ref;
            }
        }
        if (function_exists('home_url') && !empty($_SERVER['REQUEST_URI'])) {
            $path = (string) wp_unslash((string) $_SERVER['REQUEST_URI']);

            return (string) home_url($path);
        }

        return '';
    }

    /**
     * Una fila por empresa canónica (sanitize_title del título).
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $config
     *
     * @return list<array<string, mixed>>
     */
    public static function dedupe_catalog_entity_rows(
        array $rows,
        string $project_id,
        array $config,
        string $post_type
    ): array {
        unset($project_id, $post_type);
        if ($rows === []) {
            return [];
        }
        if (count($rows) < 2) {
            return array_values($rows);
        }

        $request_url = self::resolve_catalog_canonical_request_url($config);
        /** @var array<string, list<array<string, mixed>>> $groups */
        $groups = [];
        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }
            $key = self::catalog_entity_canonical_key($row);
            if ($key === '') {
                continue;
            }
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $row;
        }

        $out = [];
        foreach ($groups as $candidates) {
            $picked = self::pick_canonical_catalog_row($candidates, $request_url);
            if (is_array($picked) && $picked !== []) {
                $out[] = $picked;
            }
        }

        usort($out, static function (array $a, array $b): int {
            return strnatcasecmp(
                trim((string) ($a['empresa'] ?? '')),
                trim((string) ($b['empresa'] ?? ''))
            );
        });

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     *
     * @return array<string, mixed>
     */
    private static function pick_canonical_catalog_row(array $candidates, string $request_url): array {
        if ($candidates === []) {
            return [];
        }
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        $best = $candidates[0];
        $best_score = -1;
        $best_id = PHP_INT_MAX;
        foreach ($candidates as $row) {
            $post_id = (int) ($row['ID'] ?? 0);
            $permalink = trim((string) ($row['permalink'] ?? ''));
            if ($permalink === '' && $post_id > 0 && function_exists('get_permalink')) {
                $permalink = trim((string) get_permalink($post_id));
            }
            $score = self::permalink_alignment_score($permalink, $request_url);
            if ($score > $best_score || ($score === $best_score && $post_id > 0 && $post_id < $best_id)) {
                $best_score = $score;
                $best_id = $post_id > 0 ? $post_id : $best_id;
                $best = $row;
            }
        }

        return $best;
    }

    private static function permalink_alignment_score(string $permalink, string $request_url): int {
        if ($permalink === '' || $request_url === '') {
            return 0;
        }
        if (strcasecmp($permalink, $request_url) === 0) {
            return 100;
        }
        $p_path = wp_parse_url($permalink, PHP_URL_PATH);
        $r_path = wp_parse_url($request_url, PHP_URL_PATH);
        if (is_string($p_path) && is_string($r_path) && $p_path !== '' && $p_path === $r_path) {
            return 90;
        }
        if (is_string($p_path) && is_string($r_path) && $p_path !== '' && $r_path !== '') {
            if (str_ends_with($permalink, $r_path) || str_ends_with($request_url, $p_path)) {
                return 50;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $config
     */
    private static function catalog_entity_bucket_key(array $row, string $post_type, array $config): string {
        unset($post_type, $config);
        $key = self::catalog_entity_canonical_key($row);

        return $key !== '' ? ('slug:' . $key) : '';
    }

    /**
     * Coincidencia explícita con nombres/slugs de taxonomía de catálogo (palabra completa)
     * y sinónimos de administración (`rules.keyword_expansions` en options del proyecto).
     *
     * @param array<string, mixed> $config
     * @param array<string, string> $keyword_expansions Reservado (lee desde config/options).
     *
     * @return array{matched_term: string, term_ids: list<int>, labels: list<string>}
     */
    public static function resolve_native_catalog_term_match(
        array $config,
        string $user_msg,
        string $search_term,
        array $keyword_expansions = []
    ): array {
        if ($keyword_expansions === []) {
            $keyword_expansions = self::admin_keyword_expansions_for_native($config);
        }
        $blob = trim($user_msg !== '' ? $user_msg : $search_term);
        if ($blob === '' || !class_exists('Xabia_Catalog_Token_Match', false)) {
            return ['matched_term' => '', 'term_ids' => [], 'labels' => []];
        }
        if (!class_exists('Xabia_Knowledge_Ingest', false)) {
            return ['matched_term' => '', 'term_ids' => [], 'labels' => []];
        }
        $taxonomy = Xabia_Knowledge_Ingest::resolve_catalog_activity_taxonomy($config);
        if ($taxonomy === '' || !function_exists('get_terms') || !taxonomy_exists($taxonomy)) {
            return ['matched_term' => '', 'term_ids' => [], 'labels' => []];
        }

        $ids = [];
        $labels = [];
        $terms = get_terms([
            'taxonomy'         => $taxonomy,
            'hide_empty'       => false,
            'suppress_filters' => false,
        ]);
        if (is_wp_error($terms) || !is_array($terms)) {
            $terms = [];
        }
        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }
            $name = trim((string) $term->name);
            $slug = trim((string) $term->slug);
            $matched = false;
            if ($name !== '' && Xabia_Catalog_Token_Match::message_contains_whole_term($blob, $name)) {
                $matched = true;
            } elseif ($slug !== '' && Xabia_Catalog_Token_Match::message_contains_whole_term($blob, $slug)) {
                $matched = true;
            }
            if ($matched) {
                $ids[] = (int) $term->term_id;
                if ($name !== '') {
                    $labels[] = $name;
                } elseif ($slug !== '') {
                    $labels[] = $slug;
                }
            }
        }

        foreach ($keyword_expansions as $canonical_key => $pattern) {
            $canonical_key = trim(mb_strtolower((string) $canonical_key, 'UTF-8'));
            $pattern = trim((string) $pattern);
            if ($canonical_key === '' || mb_strlen($canonical_key, 'UTF-8') < 2) {
                continue;
            }
            $needles = preg_split('/\|/u', $pattern) ?: [];
            $needles[] = $canonical_key;
            $hit = false;
            foreach ($needles as $needle) {
                $needle = trim((string) $needle);
                if ($needle === '' || mb_strlen($needle, 'UTF-8') < 2) {
                    continue;
                }
                if (self::message_contains_admin_synonym_token($blob, $needle)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
            foreach (self::term_ids_for_label_exact($taxonomy, $canonical_key) as $tid) {
                $ids[] = $tid;
            }
            $resolved = self::find_term($taxonomy, $canonical_key);
            if ($resolved instanceof WP_Term) {
                $labels[] = (string) $resolved->name;
            } else {
                $labels[] = $canonical_key;
            }
        }

        $map = apply_filters('xabia_catalog_activity_alias_map', [], $user_msg, $search_term);
        if (is_array($map)) {
            foreach ($map as $needle => $canonical) {
                $needle = trim((string) $needle);
                $canonical = trim((string) $canonical);
                if ($needle === '' || $canonical === '') {
                    continue;
                }
                if (!Xabia_Catalog_Token_Match::message_contains_whole_term($blob, $needle)) {
                    continue;
                }
                foreach (self::term_ids_for_label_exact($taxonomy, $canonical) as $tid) {
                    $ids[] = $tid;
                }
                $labels[] = $canonical;
            }
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) {
            return $id > 0;
        })));
        $labels = array_values(array_unique(array_filter(array_map('strval', $labels))));

        $matched_display = $labels !== [] ? implode('|', $labels) : '';
        $matched_display = self::format_native_catalog_intro_term($matched_display);

        return [
            'matched_term' => $matched_display,
            'term_ids'     => $ids,
            'labels'       => $labels,
        ];
    }

    /**
     * Sinónimos / expansiones léxicas del administrador (config en memoria o wp_options).
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, string>
     */
    public static function admin_keyword_expansions_for_native(array $config): array {
        $raw = $config['rules']['keyword_expansions'] ?? null;
        if (is_array($raw) && $raw !== []) {
            return $raw;
        }
        if (!function_exists('get_option')) {
            return [];
        }
        $projects = get_option('xabia_projects_config', []);
        if (!is_array($projects)) {
            return [];
        }
        $pid = trim((string) ($config['project_id'] ?? ''));
        if ($pid !== '' && is_array($projects[$pid] ?? null)) {
            $from_opt = $projects[$pid]['rules']['keyword_expansions'] ?? null;

            return is_array($from_opt) ? $from_opt : [];
        }
        foreach ($projects as $proj) {
            if (!is_array($proj)) {
                continue;
            }
            $from_opt = $proj['rules']['keyword_expansions'] ?? null;
            if (is_array($from_opt) && $from_opt !== []) {
                return $from_opt;
            }
        }

        return [];
    }

    /**
     * Palabra completa Unicode (misma regla que configuración de sinónimos en admin).
     */
    public static function message_contains_admin_synonym_token(string $message, string $keyword): bool {
        $message = trim($message);
        $keyword = trim($keyword);
        if ($message === '' || $keyword === '' || mb_strlen($keyword, 'UTF-8') < 2) {
            return false;
        }
        $pattern = '/(?:\b|^)' . preg_quote($keyword, '/') . '(?:\b|$)/iu';

        return preg_match($pattern, $message) === 1;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<int>            $term_ids
     * @param array<string, mixed> $activity_profile
     *
     * @return list<array<string, mixed>>
     */
    private static function query_catalog_entity_rows(
        string $project_id,
        array $config,
        string $post_type,
        string $taxonomy,
        array $term_ids,
        string $user_msg,
        string $search_term,
        array $activity_profile,
        string $canonical = ''
    ): array {
        unset($project_id);
        $keywords = self::keyword_needles($user_msg, $search_term, $activity_profile);
        if ($keywords === [] && $canonical !== '') {
            $keywords = [$canonical];
        }
        if ($term_ids === [] && $keywords === [] && $activity_profile !== []) {
            return [];
        }
        if (!class_exists('WP_Query', false)) {
            return [];
        }

        $query_args = [
            'post_type'           => $post_type,
            'post_status'         => 'publish',
            'posts_per_page'      => 250,
            'orderby'             => 'title',
            'order'               => 'ASC',
            'suppress_filters'    => false,
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ];

        if ($taxonomy !== '') {
            $tax_clause = [
                'taxonomy' => $taxonomy,
            ];
            if ($term_ids !== []) {
                $tax_clause['field'] = 'term_id';
                $tax_clause['terms'] = array_values(array_map('intval', $term_ids));
            } else {
                $tax_clause['operator'] = 'EXISTS';
            }
            $query_args['tax_query'] = [$tax_clause];
        }

        $keyword_only_broad = ($term_ids === [] && $keywords !== []);
        if ($keyword_only_broad) {
            $search = trim(implode(' ', array_slice(array_map('strval', $keywords), 0, 4)));
            if ($search !== '') {
                $query_args['s'] = $search;
            }
        }

        $query = new WP_Query($query_args);
        $results = [];
        foreach ($query->posts as $post) {
            $row = self::map_catalog_post_to_row($post, $config, $taxonomy);
            if ($row !== []) {
                $results[] = $row;
            }
        }
        if (function_exists('wp_reset_postdata')) {
            wp_reset_postdata();
        }

        if ($keyword_only_broad && $results !== []) {
            $anchor_needles = self::collect_keyword_only_catalog_anchor_needles(
                $activity_profile,
                $canonical,
                $keywords,
                $user_msg,
                $search_term
            );
            if ($anchor_needles === []) {
                return [];
            }
            $filtered = self::filter_catalog_rows_by_anchor_needles($results, $anchor_needles);
            if ($filtered === []) {
                return [];
            }
            $results = $filtered;
        }

        return $results;
    }

    /**
     * @param WP_Post|object       $post
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function map_catalog_post_to_row($post, array $config, string $taxonomy): array {
        $post_id = is_object($post) && isset($post->ID) ? (int) $post->ID : 0;
        if ($post_id < 1 || !class_exists('Xabia_Knowledge_Ingest', false)) {
            return [];
        }

        return Xabia_Knowledge_Ingest::map_catalog_native_entity_from_post($post_id, $config, $taxonomy);
    }

    /**
     * @param list<string> $canonical_keywords Agujas SQL (keyword_needles + canonical).
     *
     * @return list<string> Cadenas en minúsculas para anclar filas (≥4 chars), sin stopwords genéricas.
     */
    private static function collect_keyword_only_catalog_anchor_needles(
        array $activity_profile,
        string $canonical,
        array $canonical_keywords,
        string $user_msg,
        string $search_term
    ): array {
        $needles = [];
        $canon = trim(mb_strtolower($canonical, 'UTF-8'));
        if ($canon !== '' && mb_strlen($canon, 'UTF-8') >= 4) {
            $needles[$canon] = true;
        }
        foreach ($activity_profile['match_subcategory'] ?? [] as $sub) {
            $s = trim(mb_strtolower((string) $sub, 'UTF-8'));
            if ($s !== '' && mb_strlen($s, 'UTF-8') >= 4) {
                $needles[$s] = true;
            }
        }
        foreach ($activity_profile['match_in_header'] ?? [] as $n) {
            $n = trim(mb_strtolower((string) $n, 'UTF-8'));
            if ($n !== '' && mb_strlen($n, 'UTF-8') >= 4 && strpos($n, ':') === false) {
                $needles[$n] = true;
            }
        }
        foreach ($canonical_keywords as $kw) {
            $kw = trim(mb_strtolower((string) $kw, 'UTF-8'));
            if ($kw !== '' && mb_strlen($kw, 'UTF-8') >= 4) {
                $needles[$kw] = true;
            }
        }
        foreach (self::significant_tokens_from_catalog_text($user_msg) as $tok) {
            $needles[$tok] = true;
        }
        foreach (self::significant_tokens_from_catalog_text($search_term) as $tok) {
            $needles[$tok] = true;
        }

        $out = [];
        foreach (array_keys($needles) as $needle) {
            if ($needle === '') {
                continue;
            }
            $out[] = $needle;
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private static function significant_tokens_from_catalog_text(string $text): array {
        $blob = trim(wp_strip_all_tags($text));
        if ($blob === '' || !class_exists('Xabia_Catalog_Token_Match', false)) {
            return [];
        }

        return array_values(array_filter(
            Xabia_Catalog_Token_Match::query_tokens($blob),
            static function (string $tok): bool {
                return mb_strlen($tok, 'UTF-8') >= 4;
            }
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>             $anchor_needles Minúsculas
     *
     * @return list<array<string, mixed>>
     */
    private static function filter_catalog_rows_by_anchor_needles(array $rows, array $anchor_needles): array {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (self::catalog_row_matches_anchor_needles($row, $anchor_needles)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * El LIKE SQL puede acertar solo en `post_title`; exigir refuerzo en taxonomía (categoría / term_names).
     *
     * @param array<string, mixed> $row
     * @param list<string>        $anchor_needles
     */
    private static function catalog_row_matches_anchor_needles(array $row, array $anchor_needles): bool {
        $empresa = mb_strtolower(trim((string) ($row['empresa'] ?? '')), 'UTF-8');
        $terms_blob = mb_strtolower(trim((string) ($row['term_names'] ?? '') . ' ' . (string) ($row['categoria'] ?? '')), 'UTF-8');

        foreach ($anchor_needles as $needle) {
            $needle = trim((string) $needle);
            if ($needle === '' || !class_exists('Xabia_Catalog_Token_Match', false)) {
                continue;
            }
            if ($terms_blob !== '' && Xabia_Catalog_Token_Match::message_contains_whole_term($terms_blob, $needle)) {
                return true;
            }
            if ($empresa !== '' && Xabia_Catalog_Token_Match::message_contains_whole_term($empresa, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $activity_profile
     *
     * @return list<int>
     */
    private static function resolve_matching_term_ids(
        string $taxonomy,
        array $activity_profile,
        string $canonical,
        string $user_msg,
        string $search_term
    ): array {
        $ids = [];

        // 1) Hojas / subcategorías del perfil o del criterio destilado (sin expandir padres).
        foreach ($activity_profile['match_subcategory'] ?? [] as $sub) {
            $ids = array_merge($ids, self::term_ids_for_label($taxonomy, (string) $sub, false));
        }
        if ($canonical !== '') {
            $ids = array_merge($ids, self::term_ids_for_label($taxonomy, $canonical, false));
            foreach (self::parent_terms_for_canonical($canonical) as $label) {
                // Alias del mismo criterio (filtro vertical); siguen siendo hojas, no expansión de padre.
                $ids = array_merge($ids, self::term_ids_for_label($taxonomy, $label, false));
            }
        }
        if ($ids !== []) {
            return array_values(array_unique(array_filter(array_map('intval', $ids))));
        }

        // 2) Categorías amplias solo si el perfil las declara (match_category → término + hijos).
        foreach (array_unique(array_filter(array_map('strval', $activity_profile['match_category'] ?? []))) as $label) {
            $ids = array_merge($ids, self::term_ids_for_parent_label($taxonomy, $label));
        }

        foreach ($activity_profile['match_in_header'] ?? [] as $needle) {
            $needle = trim((string) $needle);
            if ($needle === '' || mb_strlen($needle, 'UTF-8') < 3 || strpos($needle, ':') !== false) {
                continue;
            }
            $ids = array_merge($ids, self::term_ids_for_label($taxonomy, $needle, false));
        }

        if ($ids === []) {
            foreach (self::keyword_needles($user_msg, $search_term, $activity_profile) as $kw) {
                $ids = array_merge($ids, self::term_ids_for_label($taxonomy, $kw, false));
            }
        }

        // 3) Regexp opcional del perfil (vertical vía filtro).
        $regexp = trim((string) ($activity_profile['match_regexp'] ?? ''));
        if ($ids === [] && $regexp !== '') {
            $terms = get_terms([
                'taxonomy'         => $taxonomy,
                'hide_empty'       => false,
                'suppress_filters' => false,
            ]);
            if (!is_wp_error($terms) && is_array($terms)) {
                foreach ($terms as $term) {
                    if (!$term instanceof WP_Term) {
                        continue;
                    }
                    $blob = mb_strtolower($term->slug . ' ' . $term->name, 'UTF-8');
                    if (@preg_match('/' . $regexp . '/iu', $blob) === 1) {
                        $ids[] = (int) $term->term_id;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * @return list<int>
     */
    private static function term_ids_for_parent_label(string $taxonomy, string $label): array {
        $term = self::find_term($taxonomy, $label);
        if ($term === null) {
            return [];
        }
        $ids = [(int) $term->term_id];
        $children = get_term_children((int) $term->term_id, $taxonomy);
        if (!is_wp_error($children) && is_array($children)) {
            foreach ($children as $child_id) {
                $ids[] = (int) $child_id;
            }
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    /**
     * @param bool $include_parent Si true, incluye el padre (útil solo en expansiones amplias).
     *                             En listados por hoja/subcategoría debe ser false: si no,
     *                             términos padre o hermanos pueden entrar en el IN.
     *
     * @return list<int>
     */
    /**
     * @return list<int>
     */
    public static function term_ids_for_label_exact(string $taxonomy, string $label): array {
        return self::term_ids_for_label($taxonomy, $label, false);
    }

    private static function term_ids_for_label(string $taxonomy, string $label, bool $include_parent = false): array {
        $term = self::find_term($taxonomy, $label);
        if ($term === null) {
            return [];
        }
        $ids = [(int) $term->term_id];
        if ($include_parent && (int) $term->parent > 0) {
            $ids[] = (int) $term->parent;
        }
        $children = get_term_children((int) $term->term_id, $taxonomy);
        if (!is_wp_error($children) && is_array($children)) {
            foreach ($children as $child_id) {
                $ids[] = (int) $child_id;
            }
        }

        return $ids;
    }

    private static function find_term(string $taxonomy, string $label): ?WP_Term {
        $label = trim($label);
        if ($label === '') {
            return null;
        }
        $slug = sanitize_title($label);
        $term = get_term_by('slug', $slug, $taxonomy);
        if ($term instanceof WP_Term && !is_wp_error($term)) {
            return $term;
        }
        $term = get_term_by('name', $label, $taxonomy);
        if ($term instanceof WP_Term && !is_wp_error($term)) {
            return $term;
        }
        if (class_exists('Xabia_Catalog_Token_Match', false)) {
            $needle = Xabia_Catalog_Token_Match::normalize_text($label);
            if ($needle !== '' && function_exists('get_terms')) {
                $terms = get_terms([
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => false,
                ]);
                if (!is_wp_error($terms) && is_array($terms)) {
                    foreach ($terms as $t) {
                        if (!$t instanceof WP_Term) {
                            continue;
                        }
                        $name = Xabia_Catalog_Token_Match::normalize_text((string) $t->name);
                        $slug = Xabia_Catalog_Token_Match::normalize_text((string) $t->slug);
                        if ($name === $needle || $slug === $needle) {
                            return $t;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Aliases del criterio canónico → etiquetas de taxonomía a probar.
     * Vacío por defecto; el vertical puede aportar mapa con `xabia_catalog_parent_terms_for_canonical`.
     *
     * @return list<string>
     */
    private static function parent_terms_for_canonical(string $canonical): array {
        $canonical = trim($canonical);
        if ($canonical === '') {
            return [];
        }
        $map = apply_filters('xabia_catalog_parent_terms_for_canonical', [], $canonical);
        if (!is_array($map)) {
            return [$canonical];
        }
        if (isset($map[$canonical]) && is_array($map[$canonical])) {
            return array_values(array_filter(array_map('strval', $map[$canonical])));
        }

        return [$canonical];
    }

    /**
     * Destila un criterio de actividad desde la pregunta.
     * Agnóstico: mapa de aliases vacío (filtro `xabia_catalog_activity_alias_map`);
     * si no hay alias, usa la primera palabra significativa de la pregunta.
     */
    public static function distill_canonical_activity(string $user_msg, string $search_term = ''): string {
        $blob = trim($user_msg !== '' ? $user_msg : $search_term);
        if ($blob === '' || !class_exists('Xabia_Catalog_Token_Match', false)) {
            return '';
        }
        $map = apply_filters('xabia_catalog_activity_alias_map', [], $user_msg, $search_term);
        if (!is_array($map)) {
            return '';
        }
        foreach ($map as $needle => $canonical) {
            $needle = trim((string) $needle);
            $canonical = trim((string) $canonical);
            if ($needle === '' || $canonical === '') {
                continue;
            }
            if (Xabia_Catalog_Token_Match::message_contains_whole_term($blob, $needle)) {
                return $canonical;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $activity_profile
     *
     * @return list<string>
     */
    private static function keyword_needles(
        string $user_msg,
        string $search_term,
        array $activity_profile
    ): array {
        $needles = [];
        foreach ($activity_profile['match_in_header'] ?? [] as $n) {
            $n = trim((string) $n);
            if ($n !== '' && strpos($n, ':') === false && mb_strlen($n, 'UTF-8') >= 4) {
                $needles[mb_strtolower($n, 'UTF-8')] = $n;
            }
        }
        $blob = trim(wp_strip_all_tags($user_msg !== '' ? $user_msg : $search_term));
        foreach (preg_split('/\s+/u', mb_strtolower($blob, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            if (mb_strlen($tok, 'UTF-8') >= 4) {
                $needles[$tok] = $tok;
            }
        }

        return array_values($needles);
    }

    /**
     * @param list<string> $labels
     *
     * @return list<string>
     */
    private static function normalize_term_labels(array $labels): array {
        $out = [];
        foreach ($labels as $label) {
            $label = mb_strtolower(trim((string) $label), 'UTF-8');
            if ($label !== '') {
                $out[] = $label;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $exclude_terms
     */
    private static function row_matches_excluded_terms(array $row, array $exclude_terms): bool {
        $blob = mb_strtolower(trim((string) ($row['term_names'] ?? '') . ' ' . (string) ($row['categoria'] ?? '')), 'UTF-8');
        if ($blob === '') {
            return false;
        }
        if (!class_exists('Xabia_Catalog_Token_Match', false)) {
            return false;
        }
        foreach ($exclude_terms as $ex) {
            if (Xabia_Catalog_Token_Match::message_contains_whole_term($blob, (string) $ex)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pasaporte nativo de una empresa (contacto, imágenes, taxonomía) para seguimientos RAG/acciones.
     *
     * @param array<string, mixed> $config
     */
    public static function fetch_entity_passport_context(string $project_id, array $config, string $entity_query): string {
        $entity_query = trim(wp_strip_all_tags($entity_query));
        if ($entity_query === '' || !class_exists('Xabia_Knowledge_Ingest', false)) {
            return '';
        }

        // Fuentes remotas (SQL host / addon remoto): no usar get_post_meta del WP local.
        if (class_exists('Xabia_Knowledge_Sync', false) && Xabia_Knowledge_Sync::is_remote_config($config)) {
            return '';
        }
        if (trim((string) (($config['sql_config']['host'] ?? '') ?: '')) !== '') {
            return '';
        }

        $post_type = Xabia_Knowledge_Ingest::resolve_catalog_post_type($config);
        if ($post_type === '' || !function_exists('post_type_exists') || !post_type_exists($post_type)) {
            return '';
        }

        $post_id = self::resolve_entity_post_id($project_id, $config, $post_type, $entity_query);
        if ($post_id < 1) {
            return '';
        }

        $entity_meta = Xabia_Knowledge_Ingest::resolve_catalog_entity_meta_keys($config);
        $title_meta = (string) ($entity_meta['title_meta'] ?? '');
        $location_meta = (string) ($entity_meta['location_meta'] ?? '');
        $contact_keys = Xabia_Knowledge_Ingest::resolve_catalog_contact_meta_keys($config);
        $image_keys = Xabia_Knowledge_Ingest::resolve_catalog_image_meta_keys($config);
        $logotipo_keys = Xabia_Knowledge_Ingest::resolve_catalog_logotipo_meta_keys($config);

        $meta_keys = array_values(array_unique(array_filter(array_merge(
            [$title_meta, $location_meta],
            $contact_keys,
            $image_keys,
            $logotipo_keys
        ))));

        $row = ['ID' => $post_id, 'post_title' => get_the_title($post_id), 'post_name' => (string) get_post_field('post_name', $post_id)];
        foreach ($meta_keys as $key) {
            $val = get_post_meta($post_id, $key, true);
            if ($val !== '' && $val !== null) {
                $row[$key] = is_scalar($val) ? (string) $val : '';
            }
        }

        $taxonomy = Xabia_Knowledge_Ingest::resolve_catalog_activity_taxonomy($config);
        if ($taxonomy !== '' && taxonomy_exists($taxonomy)) {
            $terms = get_the_terms($post_id, $taxonomy);
            if (is_array($terms) && $terms !== []) {
                $parents = [];
                $children = [];
                foreach ($terms as $term) {
                    if (!$term instanceof WP_Term) {
                        continue;
                    }
                    if ((int) $term->parent === 0) {
                        $parents[] = $term->name;
                    } else {
                        $children[] = $term->name;
                    }
                }
                if ($parents !== []) {
                    $row['categoria'] = $parents[0];
                }
                if ($children !== []) {
                    $row['subcategoria_01'] = implode(', ', $children);
                }
            }
        }

        $mapping = Xabia_Knowledge_Ingest::catalog_attributes_mapping($config);
        $prepared = Xabia_Knowledge_Ingest::build_passport_chunk($row, $mapping);
        $blob = trim((string) ($prepared['text_blob'] ?? ''));
        if ($blob === '' || stripos($blob, 'EMPRESA:') === false) {
            $name = $title_meta !== '' && !empty($row[$title_meta])
                ? trim((string) $row[$title_meta])
                : trim((string) get_the_title($post_id));
            if ($name === '') {
                return '';
            }
            $blob = 'EMPRESA: ' . $name;
            if (!empty($row['categoria'])) {
                $blob .= ' | CATEGORÍA: ' . trim((string) $row['categoria']);
            }
            if (!empty($row['subcategoria_01'])) {
                $blob .= ' | SUBCATEGORÍAS: ' . trim((string) $row['subcategoria_01']);
            }
            if ($location_meta !== '' && !empty($row[$location_meta])) {
                $blob .= ' | LOCALIDAD: ' . trim((string) $row[$location_meta]);
            }
        }

        foreach ($contact_keys as $ck) {
            if (empty($row[$ck])) {
                continue;
            }
            $label = preg_replace('/^empresa_/i', '', $ck);
            $blob .= ' | ' . strtoupper(str_replace('_', ' ', $label)) . ': ' . trim((string) $row[$ck]);
        }

        $media_lines = [];
        foreach ($image_keys as $ik) {
            if (empty($row[$ik])) {
                continue;
            }
            $resolved = self::resolve_image_meta_value((string) $row[$ik]);
            if ($resolved !== '' && stripos($blob, $resolved) === false) {
                $media_lines[] = ['kind' => 'imagen', 'value' => $resolved];
            }
        }
        foreach ($logotipo_keys as $lk) {
            if (empty($row[$lk])) {
                continue;
            }
            $resolved = self::resolve_image_meta_value((string) $row[$lk]);
            if ($resolved !== '' && stripos($blob, $resolved) === false) {
                $media_lines[] = ['kind' => 'logotipo', 'value' => $resolved];
            }
        }
        if ($media_lines !== []) {
            $blob .= "\n\n=== IMAGEN (mapeo; usar este valor tal cual en [ACTION:IMG:…]) ===";
            $img_n = 0;
            $logo_n = 0;
            foreach ($media_lines as $media) {
                $kind = (string) ($media['kind'] ?? 'imagen');
                $value = trim((string) ($media['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                if ($kind === 'logotipo') {
                    $logo_n++;
                    $blob .= "\nlogotipo" . ($logo_n > 1 ? '_' . $logo_n : '') . ': ' . $value;
                    continue;
                }
                $img_n++;
                $blob .= "\nimagen" . ($img_n > 1 ? '_' . $img_n : '') . ': ' . $value;
            }
        }

        return trim($blob);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function resolve_entity_post_id(
        string $project_id,
        array $config,
        string $post_type,
        string $entity_query
    ): int {
        global $wpdb;

        $slug = sanitize_title($entity_query);
        $like = '%' . $wpdb->esc_like($entity_query) . '%';
        $entity_meta = Xabia_Knowledge_Ingest::resolve_catalog_entity_meta_keys($config);
        $title_meta = trim((string) ($entity_meta['title_meta'] ?? ''));

        $sql = "SELECT p.ID FROM {$wpdb->posts} p
            WHERE p.post_type = %s AND p.post_status = 'publish'
            AND (p.post_name = %s OR p.post_title LIKE %s";
        $params = [$post_type, $slug, $like];
        if ($title_meta !== '') {
            $sql .= " OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} xm WHERE xm.post_id = p.ID AND xm.meta_key = %s AND xm.meta_value LIKE %s)";
            $params[] = $title_meta;
            $params[] = $like;
        }
        $sql .= ') ORDER BY (p.post_name = %s) DESC, (p.post_title LIKE %s) DESC LIMIT 1';
        $params[] = $slug;
        $params[] = $like;

        $prepared = $wpdb->prepare($sql, ...$params);
        $id = (int) $wpdb->get_var($prepared);

        return $id > 0 ? $id : 0;
    }

    private static function resolve_image_meta_value(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $raw) || strpos($raw, '//') === 0) {
            return $raw;
        }
        if (ctype_digit($raw) && function_exists('wp_get_attachment_url')) {
            $url = wp_get_attachment_url((int) $raw);

            return is_string($url) && $url !== '' ? $url : '';
        }
        if (preg_match('/"url"\s*;\s*s:\d+:"([^"]+)"/', $raw, $m)) {
            return trim((string) $m[1]);
        }

        return $raw;
    }
}
