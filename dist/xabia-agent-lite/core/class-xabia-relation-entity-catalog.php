<?php
/**
 * Catálogo de entidades para el mapeador de relaciones (agnóstico multi-fuente).
 * Descubre tipos de contenido y taxonomías desde la fuente activa del proyecto.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Relation_Entity_Catalog {

    /** @var string Último error SQL (admin AJAX meta keys). */
    public static $last_sql_error = '';

    /** @var list<string> */
    private const SYSTEM_BLOCKLIST = [
        'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
        'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part',
        'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face',
        'elementor_library', 'elementor_snippet', 'e-floating-buttons', 'e-landing-page',
        'acf-field', 'acf-field-group', 'acf-post-type', 'acf-taxonomy',
        'product_variation', 'shop_order', 'shop_coupon', 'shop_webhook',
        'scheduled-action', 'wpcf7_contact_form',
    ];

    /**
     * @param array<string, mixed> $project_config
     * @return array{entities: array<string, string>, source: string, kinds: array<string, string>}
     */
    public static function discover_for_project(array $project_config): array {
        $source_type = sanitize_key((string) ($project_config['source_type'] ?? 'csv'));
        $entities = [];
        $kinds = [];
        $origin = $source_type;

        switch ($source_type) {
            case 'sql':
            case 'local_sql':
                $bundle = self::discover_from_sql_config(
                    is_array($project_config['sql_config'] ?? null) ? $project_config['sql_config'] : []
                );
                $entities = $bundle['entities'];
                $kinds = $bundle['kinds'];
                $origin = $source_type;
                break;

            case 'addon':
                $bundle = self::discover_from_addon($project_config);
                $entities = $bundle['entities'];
                $kinds = $bundle['kinds'];
                $origin = 'addon:' . sanitize_key((string) ($project_config['addon_slug'] ?? ''));
                break;

            case 'multi':
                $bundle = self::discover_from_multi($project_config);
                $entities = $bundle['entities'];
                $kinds = $bundle['kinds'];
                $origin = 'multi';
                break;

            case 'csv':
            default:
                $bundle = self::discover_from_csv_project($project_config);
                $entities = $bundle['entities'];
                $kinds = $bundle['kinds'];
                $origin = 'csv';
                break;
        }

        // Enriquecer con catálogo / relaciones ya guardadas.
        $catalog_pt = sanitize_key((string) ($project_config['catalog_post_type'] ?? ''));
        if ($catalog_pt !== '') {
            self::add_entity($entities, $kinds, $catalog_pt, self::humanize_slug($catalog_pt), 'content');
        }
        $tax = sanitize_key((string) ($project_config['catalog_activity_taxonomy'] ?? ''));
        if ($tax !== '') {
            self::add_entity($entities, $kinds, $tax, self::humanize_slug($tax), 'taxonomy');
        }
        foreach ((array) ($project_config['rules']['knowledge_relations'] ?? []) as $rel) {
            if (!is_array($rel)) {
                continue;
            }
            foreach (['source_post_type', 'connected_post_type'] as $k) {
                $slug = sanitize_key((string) ($rel[$k] ?? ''));
                if ($slug !== '') {
                    self::add_entity($entities, $kinds, $slug, self::humanize_slug($slug), 'content');
                }
            }
        }

        if ($entities === [] && empty($project_config['_skip_local_fallback'])) {
            $fallback = self::discover_from_local_wp();
            $entities = $fallback['entities'];
            $kinds = $fallback['kinds'];
            $origin = $origin . '+local_wp';
        }

        /**
         * @param array<string, string> $entities
         * @param array<string, mixed>  $project_config
         */
        $entities = (array) apply_filters('xabia_relation_entity_choices', $entities, $project_config, $kinds);
        asort($entities, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'entities' => $entities,
            'source'   => $origin,
            'kinds'    => $kinds,
        ];
    }

    /**
     * Descubrimiento estricto de CPT para el Asistente CPT (sin fugas entre fuentes).
     *
     * @param array<string, mixed> $project_config Ya acotada por scope_config_for_source_discovery.
     * @return array{post_types: list<array{name:string,label:string,remote?:bool}>, origin: string, ui_hint: string}
     */
    public static function discover_cpt_assistant_types(array $project_config): array {
        $source_type = sanitize_key((string) ($project_config['source_type'] ?? 'csv'));
        $post_types = [];
        $origin = $source_type;
        $ui_hint = '';

        switch ($source_type) {
            case 'sql':
                $sql_config = is_array($project_config['sql_config'] ?? null) ? $project_config['sql_config'] : [];
                $slugs = self::query_published_post_types($sql_config, false);
                foreach ($slugs as $slug) {
                    $post_types[] = [
                        'name'   => $slug,
                        'label'  => self::humanize_slug($slug),
                        'remote' => true,
                    ];
                }
                $origin = 'sql_remote';
                $ui_hint = __('Mostrando tipos de contenido de la Base de Datos Remota', 'xabia-intelligence');
                break;

            case 'local_sql':
                $slugs = self::query_published_post_types_local();
                foreach ($slugs as $slug) {
                    $post_types[] = [
                        'name'   => $slug,
                        'label'  => self::humanize_slug($slug),
                        'remote' => false,
                    ];
                }
                $origin = 'sql_local';
                $ui_hint = __('Mostrando tipos de contenido de este WordPress (SQL local)', 'xabia-intelligence');
                break;

            case 'addon':
                $slug = sanitize_key((string) ($project_config['addon_slug'] ?? ''));
                $defaults = [
                    'mec' => ['mec-events'],
                    'woo' => ['product'],
                ];
                $defaults = (array) apply_filters('xabia_cpt_assistant_addon_post_types', $defaults, $slug, $project_config);
                $wanted = array_values(array_filter(array_map('sanitize_key', (array) ($defaults[$slug] ?? []))));
                $sql_config = is_array($project_config['sql_config'] ?? null) ? $project_config['sql_config'] : [];
                $has_remote = trim((string) ($sql_config['host'] ?? '')) !== '';
                if ($has_remote) {
                    $remote_slugs = self::query_published_post_types($sql_config, false);
                    $remote_set = array_fill_keys($remote_slugs, true);
                    foreach ($wanted as $pt) {
                        if (isset($remote_set[$pt])) {
                            $post_types[] = [
                                'name'   => $pt,
                                'label'  => self::humanize_slug($pt),
                                'remote' => true,
                            ];
                        }
                    }
                    // Si el remoto no confirma, aún mostramos los CPT nativos del addon (contrato del conector).
                    if ($post_types === []) {
                        foreach ($wanted as $pt) {
                            $post_types[] = [
                                'name'   => $pt,
                                'label'  => self::humanize_slug($pt),
                                'remote' => true,
                            ];
                        }
                    }
                    $origin = 'addon:' . $slug . '+remote';
                    $ui_hint = sprintf(
                        /* translators: %s: addon slug */
                        __('Mostrando tipos del addon %s (validado en SQL remoto)', 'xabia-intelligence'),
                        $slug !== '' ? $slug : 'addon'
                    );
                } else {
                    foreach ($wanted as $pt) {
                        $post_types[] = [
                            'name'   => $pt,
                            'label'  => self::humanize_slug($pt),
                            'remote' => false,
                        ];
                    }
                    $origin = 'addon:' . $slug;
                    $ui_hint = sprintf(
                        /* translators: %s: addon slug */
                        __('Mostrando tipos nativos del addon %s', 'xabia-intelligence'),
                        $slug !== '' ? $slug : 'addon'
                    );
                }
                break;

            case 'csv':
            default:
                $origin = $source_type !== '' ? $source_type : 'csv';
                $ui_hint = __('El Asistente CPT está pensado para fuentes SQL o addon. Para CSV usa el escaneo de columnas.', 'xabia-intelligence');
                break;
        }

        usort($post_types, static function ($a, $b) {
            return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
        });

        return [
            'post_types' => $post_types,
            'origin'     => $origin,
            'ui_hint'    => $ui_hint,
        ];
    }

    /**
     * DISTINCT post_type publicados vía SQL Bridge (remoto o local con host vacío).
     *
     * @param array<string, mixed> $sql_config
     * @return list<string>
     */
    public static function query_published_post_types(array $sql_config, bool $local_only = false): array {
        if ($local_only) {
            return self::query_published_post_types_local();
        }
        if (!class_exists('Xabia_SQL_Connector', false)) {
            $path = plugin_dir_path(dirname(__FILE__)) . 'integrations/class-xabia-sql-connector.php';
            if (is_readable($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Xabia_SQL_Connector', false)) {
            return [];
        }

        $sql = "SELECT DISTINCT post_type AS slug
                FROM `{prefix}posts`
                WHERE post_status = 'publish'
                LIMIT 80";
        $rows = self::run_sql($sql, $sql_config);
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = sanitize_key((string) ($row['slug'] ?? ''));
            if ($slug === '' || self::is_blocked($slug)) {
                continue;
            }
            $out[] = $slug;
        }

        return array_values(array_unique($out));
    }

    /**
     * DISTINCT post_type en el WP local ($wpdb).
     *
     * @return list<string>
     */
    public static function query_published_post_types_local(): array {
        global $wpdb;
        if (!isset($wpdb) || !($wpdb instanceof wpdb)) {
            return [];
        }
        $rows = $wpdb->get_col(
            "SELECT DISTINCT post_type FROM {$wpdb->posts} WHERE post_status = 'publish' LIMIT 80"
        );
        $out = [];
        foreach ((array) $rows as $slug) {
            $slug = sanitize_key((string) $slug);
            if ($slug === '' || self::is_blocked($slug)) {
                continue;
            }
            $out[] = $slug;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string, mixed> $sql_config
     * @return array{entities: array<string, string>, kinds: array<string, string>}
     */
    public static function discover_from_sql_config(array $sql_config): array {
        $entities = [];
        $kinds = [];

        $query = (string) ($sql_config['query'] ?? '');
        if ($query !== '' && preg_match_all("/post_type\s*=\s*['\"]([a-zA-Z0-9_-]+)['\"]/i", $query, $m)) {
            foreach ($m[1] as $slug) {
                self::add_entity($entities, $kinds, sanitize_key($slug), self::humanize_slug($slug), 'content');
            }
        }

        if (!class_exists('Xabia_SQL_Connector', false)) {
            $path = plugin_dir_path(dirname(__FILE__)) . 'integrations/class-xabia-sql-connector.php';
            if (is_readable($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Xabia_SQL_Connector', false)) {
            return ['entities' => $entities, 'kinds' => $kinds];
        }

        $sql_types = 'SELECT DISTINCT post_type AS slug FROM `{prefix}posts`
            WHERE post_status IN (\'publish\',\'future\',\'private\')
              AND post_type NOT IN (\'revision\',\'attachment\',\'nav_menu_item\',\'custom_css\',\'customize_changeset\',\'oembed_cache\',\'acf-field\',\'acf-field-group\')
            LIMIT 80';
        foreach (self::run_sql($sql_types, $sql_config) as $row) {
            $slug = sanitize_key((string) ($row['slug'] ?? ''));
            if ($slug === '' || self::is_blocked($slug)) {
                continue;
            }
            self::add_entity($entities, $kinds, $slug, self::humanize_slug($slug), 'content');
        }

        $sql_tax = 'SELECT DISTINCT taxonomy AS slug FROM `{prefix}term_taxonomy` LIMIT 60';
        foreach (self::run_sql($sql_tax, $sql_config) as $row) {
            $slug = sanitize_key((string) ($row['slug'] ?? ''));
            if ($slug === '' || self::is_blocked($slug) || preg_match('/^(nav_menu|link_category|post_format|wp_)/', $slug)) {
                continue;
            }
            self::add_entity($entities, $kinds, $slug, self::humanize_slug($slug), 'taxonomy');
        }

        return ['entities' => $entities, 'kinds' => $kinds];
    }

    /**
     * @param array<string, mixed> $project_config
     * @return array{entities: array<string, string>, kinds: array<string, string>}
     */
    private static function discover_from_addon(array $project_config): array {
        $entities = [];
        $kinds = [];
        $slug = sanitize_key((string) ($project_config['addon_slug'] ?? ''));
        $defaults = [
            'mec' => [['mec-events', 'content'], ['mec-calendars', 'content']],
            'woo' => [['product', 'content'], ['product_cat', 'taxonomy'], ['product_tag', 'taxonomy']],
        ];
        /**
         * @param array<string, list<array{0:string,1:string}>> $defaults
         */
        $defaults = (array) apply_filters('xabia_relation_addon_entity_defaults', $defaults, $slug, $project_config);
        foreach ((array) ($defaults[$slug] ?? []) as $pair) {
            if (!is_array($pair) || count($pair) < 1) {
                continue;
            }
            $entity = sanitize_key((string) $pair[0]);
            $kind = sanitize_key((string) ($pair[1] ?? 'content'));
            if ($entity !== '') {
                self::add_entity($entities, $kinds, $entity, self::humanize_slug($entity), $kind === 'taxonomy' ? 'taxonomy' : 'content');
            }
        }

        // Si el CPT existe en este WP, añadir taxonomías asociadas.
        foreach (array_keys($entities) as $entity) {
            if (($kinds[$entity] ?? '') !== 'content' || !post_type_exists($entity)) {
                continue;
            }
            $taxes = get_object_taxonomies($entity, 'objects');
            if (!is_array($taxes)) {
                continue;
            }
            foreach ($taxes as $tax_slug => $tax_obj) {
                $tax_slug = sanitize_key((string) $tax_slug);
                if ($tax_slug === '' || self::is_blocked($tax_slug)) {
                    continue;
                }
                $label = is_object($tax_obj) && !empty($tax_obj->labels->singular_name)
                    ? (string) $tax_obj->labels->singular_name
                    : self::humanize_slug($tax_slug);
                self::add_entity($entities, $kinds, $tax_slug, $label, 'taxonomy');
            }
        }

        // Shop remoto Woo / SQL remota MEC vía form: si hay sql_config usable.
        if (!empty($project_config['sql_config']['query']) || !empty($project_config['sql_config']['host'])) {
            $from_sql = self::discover_from_sql_config(
                is_array($project_config['sql_config'] ?? null) ? $project_config['sql_config'] : []
            );
            foreach ($from_sql['entities'] as $k => $label) {
                self::add_entity($entities, $kinds, $k, $label, $from_sql['kinds'][$k] ?? 'content');
            }
        }

        return ['entities' => $entities, 'kinds' => $kinds];
    }

    /**
     * @param array<string, mixed> $project_config
     * @return array{entities: array<string, string>, kinds: array<string, string>}
     */
    private static function discover_from_multi(array $project_config): array {
        $entities = [];
        $kinds = [];
        $sources = is_array($project_config['sources'] ?? null) ? $project_config['sources'] : [];
        foreach ($sources as $src) {
            if (!is_array($src)) {
                continue;
            }
            $type = sanitize_key((string) ($src['type'] ?? $src['source_type'] ?? ''));
            $sub = $project_config;
            $sub['source_type'] = $type !== '' ? $type : 'sql';
            if (!empty($src['sql_config']) && is_array($src['sql_config'])) {
                $sub['sql_config'] = $src['sql_config'];
            } elseif ($type === 'sql' || $type === 'local_sql') {
                $sub['sql_config'] = [
                    'host'   => (string) ($src['host'] ?? ''),
                    'user'   => (string) ($src['user'] ?? ''),
                    'pass'   => (string) ($src['pass'] ?? ''),
                    'name'   => (string) ($src['name'] ?? ''),
                    'prefix' => (string) ($src['prefix'] ?? ''),
                    'query'  => (string) ($src['query'] ?? ''),
                ];
            }
            if (!empty($src['addon_slug'])) {
                $sub['addon_slug'] = sanitize_key((string) $src['addon_slug']);
            }
            $part = self::discover_for_project($sub);
            foreach ($part['entities'] as $k => $label) {
                self::add_entity($entities, $kinds, $k, $label, $part['kinds'][$k] ?? 'content');
            }
        }

        return ['entities' => $entities, 'kinds' => $kinds];
    }

    /**
     * @param array<string, mixed> $project_config
     * @return array{entities: array<string, string>, kinds: array<string, string>}
     */
    private static function discover_from_csv_project(array $project_config): array {
        $entities = [];
        $kinds = [];
        $attrs = is_array($project_config['attributes'] ?? null) ? $project_config['attributes'] : [];
        foreach ($attrs as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $col = sanitize_key((string) ($attr['csv_col'] ?? ''));
            if (in_array($col, ['post_type', 'tipo', 'tipo_contenido', 'content_type'], true)) {
                // Sin sample de valores aquí; el catalog_post_type ya cubre.
                continue;
            }
        }
        // Fallback ligero: si hay CPT local del catálogo, listar siblings públicos del mismo sitio.
        $local = self::discover_from_local_wp();

        return $local;
    }

    /**
     * @return array{entities: array<string, string>, kinds: array<string, string>}
     */
    private static function discover_from_local_wp(): array {
        $entities = [];
        $kinds = [];
        $custom = get_post_types(['public' => true, '_builtin' => false], 'objects');
        if (!is_array($custom)) {
            $custom = [];
        }
        foreach (['post', 'page'] as $b) {
            $obj = get_post_type_object($b);
            if ($obj) {
                $custom[$b] = $obj;
            }
        }
        foreach ($custom as $slug => $obj) {
            $slug = sanitize_key((string) $slug);
            if ($slug === '' || self::is_blocked($slug)) {
                continue;
            }
            $label = is_object($obj) && !empty($obj->labels->singular_name)
                ? (string) $obj->labels->singular_name
                : self::humanize_slug($slug);
            self::add_entity($entities, $kinds, $slug, $label, 'content');
            if (post_type_exists($slug)) {
                $taxes = get_object_taxonomies($slug, 'objects');
                if (!is_array($taxes)) {
                    continue;
                }
                foreach ($taxes as $tax_slug => $tax_obj) {
                    $tax_slug = sanitize_key((string) $tax_slug);
                    if ($tax_slug === '' || self::is_blocked($tax_slug) || preg_match('/^(nav_menu|link_category|post_format)/', $tax_slug)) {
                        continue;
                    }
                    $tlabel = is_object($tax_obj) && !empty($tax_obj->labels->singular_name)
                        ? (string) $tax_obj->labels->singular_name
                        : self::humanize_slug($tax_slug);
                    self::add_entity($entities, $kinds, $tax_slug, $tlabel, 'taxonomy');
                }
            }
        }

        return ['entities' => $entities, 'kinds' => $kinds];
    }

    /**
     * @param array<string, string> $entities
     * @param array<string, string> $kinds
     */
    private static function add_entity(array &$entities, array &$kinds, string $slug, string $label, string $kind): void {
        $slug = sanitize_key($slug);
        if ($slug === '' || self::is_blocked($slug)) {
            return;
        }
        if ($label === '') {
            $label = self::humanize_slug($slug);
        }
        if (!isset($entities[$slug])) {
            $entities[$slug] = $label;
            $kinds[$slug] = $kind;
        }
    }

    private static function is_blocked(string $slug): bool {
        if (in_array($slug, self::SYSTEM_BLOCKLIST, true)) {
            return true;
        }

        return (bool) preg_match('/^(elementor|e-|acf-|jet-|wpcf7|wp_|shop_|wc_|fl-builder)/', $slug);
    }

    private static function humanize_slug(string $slug): string {
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    /**
     * @param array<string, mixed> $sql_config
     */
    private static function resolve_prefix(array $sql_config): string {
        global $wpdb;
        $manual = trim((string) ($sql_config['prefix'] ?? ''));
        if ($manual !== '') {
            return $manual;
        }
        if (!empty($sql_config['host']) && class_exists('Xabia_SQL_Connector', false)) {
            $resolved = Xabia_SQL_Connector::resolve_table_prefix($sql_config, null);

            return $resolved !== '' ? $resolved : 'wp_';
        }

        return isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_';
    }

    /**
     * @param array<string, mixed> $sql_config
     * @return list<array<string, mixed>>
     */
    private static function run_sql(string $sql, array $sql_config): array {
        if (!class_exists('Xabia_SQL_Connector', false)) {
            self::$last_sql_error = 'Xabia_SQL_Connector missing';

            return [];
        }
        $cfg = $sql_config;
        $cfg['query'] = $sql;
        $rows = Xabia_SQL_Connector::fetch_data($cfg);
        if (is_wp_error($rows)) {
            self::$last_sql_error = (string) $rows->get_error_message();

            return [];
        }
        if (!is_array($rows)) {
            return [];
        }

        return $rows;
    }

    private static function esc_ident(string $ident): string {
        return str_replace('`', '``', $ident);
    }

    /**
     * Meta keys públicas (sin prefijo _) para un post_type en la fuente del proyecto.
     * Paso 1 (alta): definiciones ACF relationship/post_object.
     * Paso 2 (baja): DISTINCT postmeta (sin ruido de aliases export).
     *
     * @param array<string, mixed> $project_config
     * @param string               $entity_kind content|taxonomy (las relaciones meta usan post types)
     * @return array{ok:bool, meta_keys:list<string>, acf_recommended?:list<array{key:string,label:string}>, relation_meta_keys?:list<string>, fallback:bool, message?:string, error_detail?:string, debug?:array<string,mixed>}
     */
    public static function fetch_meta_keys_for_post_type(array $project_config, string $post_type, string $entity_kind = 'content'): array {
        self::$last_sql_error = '';
        $post_type = sanitize_key($post_type);
        $entity_kind = sanitize_key($entity_kind);
        if ($post_type === '') {
            return [
                'ok'        => false,
                'meta_keys' => [],
                'fallback'  => true,
                'message'   => __('Indica el tipo de contenido de origen.', 'xabia-intelligence'),
            ];
        }

        if ($entity_kind === 'taxonomy') {
            return [
                'ok'        => false,
                'meta_keys' => [],
                'fallback'  => true,
                'message'   => __('El origen debe ser un tipo de contenido (p. ej. empresa), no una taxonomía. Las claves meta viven en los posts.', 'xabia-intelligence'),
            ];
        }

        $acf_keys = [];
        $from_postmeta = [];
        $sql_config = self::resolve_project_sql_config($project_config);

        // Paso 1 — Autodetección dinámica ACF (sin nombres de campo hardcodeados).
        if ($sql_config !== null) {
            $acf_keys = self::query_acf_relationship_fields_for_source($post_type, $sql_config);
        }

        // Paso 2 — Fallback postmeta (sin aliases numerados de export).
        if ($sql_config !== null) {
            $from_postmeta = self::query_meta_keys_via_sql($post_type, $sql_config);
        }
        if ($from_postmeta === [] && function_exists('post_type_exists') && post_type_exists($post_type)) {
            $from_postmeta = self::query_meta_keys_local_wpdb($post_type);
        }

        $acf_set = array_fill_keys($acf_keys, true);
        $postmeta_only = [];
        foreach ($from_postmeta as $mk) {
            $mk = (string) $mk;
            if ($mk === '' || isset($acf_set[$mk])) {
                continue;
            }
            if (preg_match('/_\d{1,2}$/', $mk)) {
                continue;
            }
            $postmeta_only[] = $mk;
        }

        $acf_keys = array_values(array_unique($acf_keys));
        sort($acf_keys, SORT_STRING);
        sort($postmeta_only, SORT_STRING);

        $keys = array_values(array_unique(array_merge($acf_keys, $postmeta_only)));
        $keys = (array) apply_filters('xabia_relation_meta_keys', $keys, $post_type, $project_config);
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys))));
        $keys = self::merge_acf_first($acf_keys, $keys);

        $acf_recommended = [];
        foreach ($acf_keys as $ak) {
            $acf_recommended[] = [
                'key'   => $ak,
                'label' => $ak . ' (ACF)',
            ];
        }

        if ($keys === []) {
            $detail = self::$last_sql_error !== '' ? self::$last_sql_error : '';
            if ($detail === '' && $sql_config === null) {
                $detail = __('Sin conexión SQL configurada y el tipo no existe en este WordPress.', 'xabia-intelligence');
            }

            return [
                'ok'                 => false,
                'meta_keys'          => [],
                'acf_recommended'    => [],
                'relation_meta_keys' => [],
                'fallback'           => true,
                'message'            => __('No se encontraron campos meta en la fuente. Escribe la clave manualmente.', 'xabia-intelligence'),
                'error_detail'       => $detail,
            ];
        }

        return [
            'ok'                 => true,
            'meta_keys'          => $keys,
            'acf_recommended'    => $acf_recommended,
            'relation_meta_keys' => $acf_keys,
            'fallback'           => false,
            'debug'              => [
                'acf'       => count($acf_keys),
                'postmeta'  => count($postmeta_only),
                'sql_error' => self::$last_sql_error,
            ],
        ];
    }

    /**
     * @param list<string> $acf_keys
     * @param list<string> $all_keys
     * @return list<string>
     */
    private static function merge_acf_first(array $acf_keys, array $all_keys): array {
        $seen = [];
        $out = [];
        foreach ($acf_keys as $k) {
            $k = (string) $k;
            if ($k === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $k;
        }
        foreach ($all_keys as $k) {
            $k = (string) $k;
            if ($k === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $k;
        }

        return $out;
    }

    /**
     * Extrae meta_key = '…' de la SQL guardada del proyecto (agnóstico).
     *
     * @param array<string, mixed> $project_config
     * @return list<string>
     */
    private static function meta_keys_from_project_sql_text(array $project_config): array {
        $chunks = [];
        $saved = is_array($project_config['sql_config'] ?? null) ? $project_config['sql_config'] : [];
        if (!empty($saved['query'])) {
            $chunks[] = (string) $saved['query'];
        }
        if (!empty($project_config['sources']) && is_array($project_config['sources'])) {
            foreach ($project_config['sources'] as $src) {
                if (!is_array($src)) {
                    continue;
                }
                $q = (string) (($src['sql_config']['query'] ?? $src['sql_query'] ?? ''));
                if ($q !== '') {
                    $chunks[] = $q;
                }
            }
        }

        $keys = [];
        foreach ($chunks as $sql) {
            if (preg_match_all("/meta_key\\s*=\\s*'([^']+)'/i", $sql, $m)) {
                foreach ($m[1] as $mk) {
                    $mk = trim((string) $mk);
                    if ($mk !== '' && $mk[0] !== '_' && preg_match('/^[a-z0-9_-]+$/i', $mk)) {
                        $keys[] = $mk;
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Columnas del mapeo que pueden ser meta_keys ACF (respaldo si la consulta SQL falla).
     * Excluye aliases de export/SQL tipo experiencia_01 (WP All Export / SELECT AS).
     *
     * @param array<string, mixed> $project_config
     * @return list<string>
     */
    private static function meta_keys_from_project_attributes(array $project_config, string $post_type): array {
        unset($post_type);
        $attrs = is_array($project_config['attributes'] ?? null) ? $project_config['attributes'] : [];
        $keys = [];
        foreach ($attrs as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $col = trim((string) ($attr['csv_col'] ?? ''));
            if ($col === '' || $col[0] === '_' || $col[0] === '@') {
                continue;
            }
            // Aliases numerados de export / SQL (experiencia_01, subcategoria_02…): no son meta ACF.
            if (preg_match('/_\d{1,2}$/', $col)) {
                continue;
            }
            if (preg_match('/^(ID|id|post_name|Slug_Empresa)$/i', $col)) {
                continue;
            }
            if (preg_match('/^[a-z0-9_-]+$/i', $col)) {
                $keys[] = $col;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $project_config
     * @return array<string, mixed>|null
     */
    public static function resolve_project_sql_config(array $project_config): ?array {
        $source_type = sanitize_key((string) ($project_config['source_type'] ?? 'csv'));
        $saved = is_array($project_config['sql_config'] ?? null) ? $project_config['sql_config'] : [];

        if (in_array($source_type, ['sql', 'local_sql'], true)) {
            return $saved !== [] ? $saved : ['host' => ''];
        }

        if ($source_type === 'addon') {
            $host = trim((string) (($saved['host'] ?? '')));
            if ($host !== '') {
                return $saved;
            }

            return ['host' => ''];
        }

        if ($source_type === 'multi' && !empty($project_config['sources']) && is_array($project_config['sources'])) {
            foreach ($project_config['sources'] as $src) {
                if (!is_array($src)) {
                    continue;
                }
                $type = sanitize_key((string) ($src['type'] ?? ''));
                if (!in_array($type, ['sql', 'local_sql'], true)) {
                    continue;
                }
                $cfg = is_array($src['sql_config'] ?? null) ? $src['sql_config'] : [];
                if ($type === 'local_sql') {
                    $cfg['host'] = '';
                }
                if ($cfg !== [] && ($cfg['host'] !== '' || $type === 'local_sql' || !empty($cfg['query']))) {
                    return $cfg;
                }
            }
        }

        // CSV u otras fuentes: si hay sql_config guardada (p. ej. remoto auxiliar), usarla.
        if ($saved !== [] && (!empty($saved['host']) || !empty($saved['query']))) {
            return $saved;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $sql_config
     * @return list<string>
     */
    private static function query_meta_keys_via_sql(string $post_type, array $sql_config): array {
        if (!class_exists('Xabia_SQL_Connector', false)) {
            return [];
        }

        $pt = esc_sql($post_type);
        // Tabla viva de producción: {prefix}postmeta (nunca …pmxe_postmeta).
        $sql = "SELECT DISTINCT pm.meta_key AS meta_key
                FROM `{prefix}postmeta` pm
                INNER JOIN `{prefix}posts` p ON pm.post_id = p.ID
                WHERE p.post_type = '{$pt}'
                  AND p.post_status = 'publish'
                  AND pm.meta_key <> ''
                  AND LEFT(pm.meta_key, 1) <> '_'
                ORDER BY pm.meta_key ASC
                LIMIT 250";

        $rows = self::run_sql($sql, $sql_config);
        $keys = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mk = trim((string) ($row['meta_key'] ?? ''));
            if ($mk !== '' && $mk[0] !== '_') {
                $keys[] = $mk;
            }
        }

        return $keys;
    }

    /**
     * Autodetección dinámica: campos ACF relationship / post_object en la fuente remota.
     * Sin nombres de campo hardcodeados; opcionalmente prioriza los vinculados al post_type origen.
     *
     * @param array<string, mixed> $sql_config
     * @return list<string>
     */
    private static function query_acf_relationship_fields_for_source(string $source_post_type, array $sql_config): array {
        if (!class_exists('Xabia_SQL_Connector', false)) {
            return [];
        }

        $sql = 'SELECT p.post_excerpt AS field_name, p.post_content AS post_content, g.post_content AS group_content
                FROM `{prefix}posts` p
                LEFT JOIN `{prefix}posts` g ON g.ID = p.post_parent AND g.post_type IN (\'acf-field-group\', \'acf-field\')
                WHERE p.post_type = \'acf-field\'
                  AND p.post_excerpt <> \'\'
                  AND LEFT(p.post_excerpt, 1) <> \'_\'
                  AND (
                    p.post_content LIKE \'%:12:"relationship"%\'
                    OR p.post_content LIKE \'%:11:"post_object"%\'
                    OR p.post_content LIKE \'%"type":"relationship"%\'
                    OR p.post_content LIKE \'%"type":"post_object"%\'
                  )
                ORDER BY p.post_excerpt ASC
                LIMIT 200';

        $rows = self::run_sql($sql, $sql_config);
        if ($rows === []) {
            $sql = 'SELECT p.post_excerpt AS field_name, p.post_content AS post_content, g.post_content AS group_content
                    FROM `{prefix}posts` p
                    LEFT JOIN `{prefix}posts` g ON g.ID = p.post_parent AND g.post_type IN (\'acf-field-group\', \'acf-field\')
                    WHERE p.post_type = \'acf-field\'
                      AND p.post_excerpt <> \'\'
                      AND LEFT(p.post_excerpt, 1) <> \'_\'
                      AND (
                        p.post_content LIKE \'%relationship%\'
                        OR p.post_content LIKE \'%post_object%\'
                      )
                    ORDER BY p.post_excerpt ASC
                    LIMIT 200';
            $rows = self::run_sql($sql, $sql_config);
        }

        return self::filter_acf_relationship_rows_for_source($rows, $source_post_type);
    }

    /**
     * Prioriza campos cuyo post_content/grupo mencionan el post_type origen (dinámico).
     * Si ninguno encaja, devuelve todos los relationship/post_object detectados.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private static function filter_acf_relationship_rows_for_source(array $rows, string $source_post_type): array {
        $source_post_type = sanitize_key($source_post_type);
        $preferred = [];
        $all = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['field_name'] ?? $row['post_excerpt'] ?? ''));
            if ($name === '' || $name[0] === '_' || !preg_match('/^[a-z0-9_-]+$/i', $name)) {
                continue;
            }
            $content = (string) ($row['post_content'] ?? '');
            $group = (string) ($row['group_content'] ?? '');
            $blob = $content . "\n" . $group;

            if (!preg_match('/relationship|post_object/i', $content)) {
                continue;
            }

            $all[$name] = true;

            if ($source_post_type !== '' && (
                stripos($blob, $source_post_type) !== false
                || stripos($name, $source_post_type) !== false
            )) {
                $preferred[$name] = true;
            }
        }

        $keys = array_keys($preferred !== [] ? $preferred : $all);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * @return list<string>
     */
    private static function query_meta_keys_local_wpdb(string $post_type): array {
        global $wpdb;
        if (!isset($wpdb) || !($wpdb instanceof wpdb)) {
            return [];
        }

        $from_db = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.meta_key
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE p.post_type = %s
                   AND pm.meta_key <> ''
                   AND LEFT(pm.meta_key, 1) <> '_'
                 ORDER BY pm.meta_key ASC
                 LIMIT 250",
                $post_type
            )
        );

        return is_array($from_db) ? array_map('strval', $from_db) : [];
    }
}
