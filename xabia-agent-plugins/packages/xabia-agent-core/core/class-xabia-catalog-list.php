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
        $term_ids = self::resolve_matching_term_ids($taxonomy, $activity_profile, $canonical, $user_msg, $search_term);
        self::$last_debug['term_ids'] = count($term_ids);

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
            $entity_meta
        );
        self::$last_debug['rows'] = count($rows);

        if ($rows === []) {
            return [
                'manifest' => [],
                'debug'    => self::$last_debug,
            ];
        }

        $exclude_terms = self::normalize_term_labels($activity_profile['exclude_in_category'] ?? []);
        $manifest = [];
        foreach ($rows as $row) {
            if ($exclude_terms !== [] && self::row_matches_excluded_terms($row, $exclude_terms)) {
                continue;
            }
            $name = trim((string) ($row['empresa'] ?? ''));
            if ($name === '') {
                continue;
            }
            $loc = trim((string) ($row['localidad'] ?? ''));
            $cat = trim((string) ($row['categoria'] ?? ''));
            if ($cat !== '' && class_exists('Xabia_Knowledge_Ingest', false)) {
                $cat = Xabia_Knowledge_Ingest::localize_taxonomy_term_label($cat);
            }
            $line = '**' . $name . '**';
            if ($loc !== '') {
                $line .= ' (' . $loc . ')';
            } elseif ($cat !== '') {
                $line .= ' — ' . $cat;
            }
            $key = mb_strtolower($name, 'UTF-8');
            $manifest[$key] = $line;
        }

        $sorted = array_values($manifest);
        sort($sorted, SORT_NATURAL | SORT_FLAG_CASE);
        self::$last_debug['matched'] = count($sorted);

        return [
            'manifest' => $sorted,
            'debug'    => self::$last_debug,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param list<int>            $term_ids
     * @param array<string, mixed> $activity_profile
     * @param array{title_meta: string, location_meta: string, slug_column: string} $entity_meta
     *
     * @return list<array{empresa: string, localidad: string, categoria: string, term_names: string}>
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
        string $canonical = '',
        array $entity_meta = []
    ): array {
        global $wpdb;

        $title_meta = trim((string) ($entity_meta['title_meta'] ?? ''));
        $location_meta = trim((string) ($entity_meta['location_meta'] ?? ''));

        $keywords = self::keyword_needles($user_msg, $search_term, $activity_profile);
        if ($keywords === [] && $canonical !== '') {
            $keywords = [$canonical];
        }
        if ($term_ids === [] && $keywords === [] && $activity_profile !== []) {
            return [];
        }

        $lang_join = '';
        if (class_exists('Xabia_Knowledge_Ingest', false)
            && Xabia_Knowledge_Ingest::is_multilingual_site()) {
            $lang_scope = apply_filters('xabia_knowledge_lang_scope', 'primary', $project_id, $config);
            if ($lang_scope !== 'all') {
                $primary = esc_sql(Xabia_Knowledge_Ingest::primary_language_code());
                if ($primary !== '') {
                    $wpml = class_exists('Xabia_Knowledge_Ingest', false)
                        && Xabia_Knowledge_Ingest::wpml_schema_ready('', $wpdb);
                    $pll = function_exists('pll_default_language') || function_exists('pll_get_post_language');
                    if ($wpml) {
                        $icl = class_exists('Xabia_Knowledge_Ingest', false)
                            ? Xabia_Knowledge_Ingest::resolve_wpml_translations_table('', $wpdb)
                            : ($wpdb->prefix . 'icl_translations');
                        if ($icl === '') {
                            $icl = $wpdb->prefix . 'icl_translations';
                        }
                        $lang_join = " INNER JOIN `{$icl}` xabia_wpml_tr"
                            . " ON xabia_wpml_tr.element_id = p.ID"
                            . " AND xabia_wpml_tr.element_type = CONCAT('post_', p.post_type)"
                            . " AND xabia_wpml_tr.language_code = '{$primary}'";
                    } elseif ($pll) {
                        $lang_join = " INNER JOIN {$wpdb->term_relationships} xabia_pll_tr ON xabia_pll_tr.object_id = p.ID"
                            . " INNER JOIN {$wpdb->term_taxonomy} xabia_pll_tt ON xabia_pll_tt.term_taxonomy_id = xabia_pll_tr.term_taxonomy_id AND xabia_pll_tt.taxonomy = 'language'"
                            . " INNER JOIN {$wpdb->terms} xabia_pll_lang ON xabia_pll_lang.term_id = xabia_pll_tt.term_id AND xabia_pll_lang.slug = '{$primary}'";
                    }
                }
            }
        }

        $term_filter = '';
        if ($term_ids !== []) {
            $in = implode(',', array_map('intval', $term_ids));
            $term_filter = " AND EXISTS (
                SELECT 1 FROM {$wpdb->term_relationships} trf
                INNER JOIN {$wpdb->term_taxonomy} ttf ON ttf.term_taxonomy_id = trf.term_taxonomy_id AND ttf.taxonomy = %s
                WHERE trf.object_id = p.ID AND ttf.term_id IN ({$in})
            )";
            $term_filter = $wpdb->prepare($term_filter, $taxonomy);
        }

        $keyword_filter = '';
        if ($keywords !== [] && $term_ids === []) {
            $likes = [];
            foreach ($keywords as $kw) {
                if ($title_meta !== '') {
                    $likes[] = $wpdb->prepare(
                        '(p.post_title LIKE %s OR pm.meta_value LIKE %s OR t.name LIKE %s)',
                        '%' . $wpdb->esc_like($kw) . '%',
                        '%' . $wpdb->esc_like($kw) . '%',
                        '%' . $wpdb->esc_like($kw) . '%'
                    );
                } else {
                    $likes[] = $wpdb->prepare(
                        '(p.post_title LIKE %s OR t.name LIKE %s)',
                        '%' . $wpdb->esc_like($kw) . '%',
                        '%' . $wpdb->esc_like($kw) . '%'
                    );
                }
            }
            $keyword_filter = ' AND (' . implode(' OR ', $likes) . ')';
        }

        $title_expr = 'p.post_title AS empresa';
        if ($title_meta !== '') {
            $title_key = esc_sql($title_meta);
            $title_expr = "COALESCE(NULLIF(TRIM(MAX(CASE WHEN pm.meta_key = '{$title_key}' THEN pm.meta_value END)), ''), p.post_title) AS empresa";
        }
        $loc_expr = 'NULL AS localidad';
        if ($location_meta !== '') {
            $loc_key = esc_sql($location_meta);
            $loc_expr = "MAX(CASE WHEN pm.meta_key = '{$loc_key}' THEN pm.meta_value END) AS localidad";
        }

        $meta_keys = array_values(array_unique(array_filter([$title_meta, $location_meta])));
        $pm_join = '';
        if ($meta_keys !== []) {
            $quoted = array_map(static function (string $key): string {
                return "'" . esc_sql($key) . "'";
            }, $meta_keys);
            $pm_join = "LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN (" . implode(', ', $quoted) . ')';
        }

        $sql = "SELECT DISTINCT p.ID,
            {$title_expr},
            {$loc_expr},
            MAX(CASE WHEN tt.parent = 0 THEN t.name END) AS categoria,
            GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') AS term_names
        FROM {$wpdb->posts} p
        {$lang_join}
        {$pm_join}
        LEFT JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
        LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
        LEFT JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
        WHERE p.post_type = %s AND p.post_status = 'publish'
        {$term_filter}{$keyword_filter}
        GROUP BY p.ID, p.post_title
        ORDER BY empresa ASC
        LIMIT 250";

        $prepared = $wpdb->prepare($sql, $taxonomy, $post_type);
        $results = $wpdb->get_results($prepared, ARRAY_A);

        return is_array($results) ? $results : [];
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
        $parent_labels = array_merge(
            array_map('strval', $activity_profile['match_category'] ?? []),
            self::parent_terms_for_canonical($canonical)
        );
        foreach (array_unique(array_filter($parent_labels)) as $label) {
            $ids = array_merge($ids, self::term_ids_for_parent_label($taxonomy, $label));
        }

        foreach ($activity_profile['match_subcategory'] ?? [] as $sub) {
            $ids = array_merge($ids, self::term_ids_for_label($taxonomy, (string) $sub));
        }

        foreach ($activity_profile['match_in_header'] ?? [] as $needle) {
            $needle = trim((string) $needle);
            if ($needle === '' || mb_strlen($needle, 'UTF-8') < 3 || strpos($needle, ':') !== false) {
                continue;
            }
            $ids = array_merge($ids, self::term_ids_for_label($taxonomy, $needle));
        }

        if ($ids === [] && $canonical !== '') {
            $ids = array_merge($ids, self::term_ids_for_label($taxonomy, $canonical));
        }

        if ($ids === []) {
            foreach (self::keyword_needles($user_msg, $search_term, $activity_profile) as $kw) {
                $ids = array_merge($ids, self::term_ids_for_label($taxonomy, $kw));
            }
        }

        if ($ids === [] && $canonical === 'náutica') {
            $regexp = trim((string) ($activity_profile['match_regexp'] ?? 'nautic|surf|kayak|vela|agua|paddle|piragua|buceo'));
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
            if (!is_wp_error($terms) && is_array($terms)) {
                foreach ($terms as $term) {
                    if (!$term instanceof WP_Term) {
                        continue;
                    }
                    $blob = mb_strtolower($term->slug . ' ' . $term->name, 'UTF-8');
                    if (@preg_match('/' . $regexp . '/iu', $blob) === 1 || mb_strpos($blob, 'agua') !== false) {
                        $ids[] = (int) $term->term_id;
                        if ((int) $term->parent > 0) {
                            $ids[] = (int) $term->parent;
                        }
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
    private static function term_ids_for_label(string $taxonomy, string $label): array {
        $term = self::find_term($taxonomy, $label);
        if ($term === null) {
            return [];
        }
        $ids = [(int) $term->term_id];
        if ((int) $term->parent > 0) {
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
        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'name__like' => $label,
            'number'     => 5,
        ]);
        if (!is_wp_error($terms) && is_array($terms)) {
            foreach ($terms as $t) {
                if ($t instanceof WP_Term) {
                    return $t;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function parent_terms_for_canonical(string $canonical): array {
        $map = [
            'náutica'        => ['agua'],
            'surf'           => ['agua', 'surf'],
            'kayak'          => ['agua', 'kayak'],
            'paddle surf'    => ['agua', 'paddle'],
            'vela'           => ['agua', 'vela'],
            'velero'         => ['agua', 'vela'],
            'hípica'         => ['hípica', 'hipica', 'equitación', 'equitacion'],
            'equitación'     => ['hípica', 'hipica', 'equitación', 'equitacion'],
            'caballo'        => ['hípica', 'hipica', 'caballo'],
            'montaña'        => ['montaña', 'montana', 'tierra'],
            'senderismo'     => ['senderismo', 'tierra'],
            'barranco'       => ['barranco', 'tierra'],
            'btt'            => ['btt', 'bicicleta', 'tierra'],
            'nordic walking' => ['nordic', 'marcha nórdica', 'marcha nordica'],
            'globo'          => ['globo', 'aire'],
        ];

        return $map[$canonical] ?? [];
    }

    private static function distill_canonical_activity(string $user_msg, string $search_term): string {
        $blob = mb_strtolower(trim(wp_strip_all_tags($user_msg !== '' ? $user_msg : $search_term)), 'UTF-8');
        if ($blob === '') {
            return '';
        }
        if (preg_match('/\bn[aá]utic/iu', $blob)) {
            return 'náutica';
        }
        $map = [
            'hípica' => 'hípica', 'hipica' => 'hípica', 'equitación' => 'equitación', 'equitacion' => 'equitación',
            'caballo' => 'caballo', 'náutica' => 'náutica', 'nautica' => 'náutica', 'surf' => 'surf', 'kayak' => 'kayak',
            'montaña' => 'montaña', 'montana' => 'montaña', 'senderismo' => 'senderismo', 'barranco' => 'barranco',
            'btt' => 'btt', 'globo' => 'globo', 'nordic' => 'nordic walking', 'vela' => 'vela', 'velero' => 'velero',
        ];
        foreach ($map as $needle => $canonical) {
            if (mb_strpos($blob, $needle) !== false) {
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
        foreach ($exclude_terms as $ex) {
            if (mb_strpos($blob, $ex) !== false) {
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
