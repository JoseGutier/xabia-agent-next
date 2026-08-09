<?php
/**
 * Descubrimiento de esquema por CPT: meta (muestreo), taxonomías y campos virtuales.
 * Base para el asistente CPT / mapeo profundo (p. ej. MEC: meta mec_* + taxonomías).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Descubre columnas core, meta, taxonomías y campos computados para un post_type.
 *
 * @param string $post_type Slug del CPT (ej. mec-events).
 * @param array{
 *   recent_limit?: int,
 *   project_id?: string
 * } $args recent_limit: cuántos posts recientes usar para muestreo de meta (10–200; por defecto 100).
 * @return array{
 *   post_type: string,
 *   core: string[],
 *   meta: array<int, array{key: string, label: string, in_recent_sample?: bool}>,
 *   taxonomies: array<int, array{name: string, label: string}>,
 *   virtual: array<int, array{id: string, label: string, description?: string}>,
 *   discovery?: array<string, mixed>
 * }|WP_Error
 */
function xabia_discover_cpt_fields($post_type, $args = []) {
    $post_type = sanitize_key((string) $post_type);
    if ($post_type === '') {
        return new WP_Error('xabia_schema_empty_type', __('Indica el tipo de contenido.', 'xabia-intelligence'));
    }

    $args = is_array($args) ? $args : [];
    /** @var int $recent_limit */
    $recent_limit = isset($args['recent_limit']) ? max(10, min(200, absint($args['recent_limit']))) : 100;
    $project_id = isset($args['project_id']) ? sanitize_key((string) $args['project_id']) : '';
    $project_config = isset($args['project_config']) && is_array($args['project_config']) ? $args['project_config'] : [];

    $filtered = apply_filters('xabia_discover_cpt_fields', null, $post_type, $args);
    if (is_array($filtered) && isset($filtered['meta']) && is_array($filtered['meta'])) {
        return xabia_discover_cpt_fields_normalize_payload($filtered, $post_type);
    }

    $sql_host = trim((string) (($project_config['sql_config']['host'] ?? '') ?: ''));
    $force_remote = ($sql_host !== '');

    // CPT solo en BD remota, o proyecto con host SQL (híbrido: plugin local + datos remotos).
    if (!post_type_exists($post_type) || $force_remote) {
        $remote = xabia_discover_cpt_fields_from_remote($post_type, $project_id, $project_config);
        if (!is_wp_error($remote)) {
            return $remote;
        }
        if (!post_type_exists($post_type)) {
            return new WP_Error(
                'xabia_schema_unknown_type',
                __('Tipo de contenido no registrado en WordPress ni detectable en la fuente SQL remota.', 'xabia-intelligence')
            );
        }
        // Host remoto falló pero el CPT existe localmente: continuar con muestreo local.
    }

    global $wpdb;

    $recent_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft')
             ORDER BY post_modified DESC LIMIT %d",
            $post_type,
            $recent_limit
        )
    );
    $recent_ids = array_values(array_filter(array_map('absint', (array) $recent_ids)));

    $meta_raw = [];
    /** @var array<string, true> $recent_set */
    $recent_set = [];
    $discovery_note = [
        'recent_post_count' => count($recent_ids),
        'recent_limit'      => $recent_limit,
    ];

    if ($post_type === 'mec-events') {
        $like = $wpdb->esc_like('mec_') . '%';
        
        $global_keys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE p.post_type = %s AND pm.meta_key LIKE %s",
                $post_type,
                $like
            )
        );
        $global_keys = array_fill_keys(array_map('strval', (array) $global_keys), true);

        $recent_keys = [];
        if (!empty($recent_ids)) {
            $ph = implode(',', array_fill(0, count($recent_ids), '%d'));
            $sql = "SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm
                    WHERE pm.post_id IN ($ph) AND pm.meta_key LIKE %s";
            $prepare_args = array_merge($recent_ids, [$like]);
            $recent_keys = $wpdb->get_col($wpdb->prepare($sql, $prepare_args));
        }
        $recent_set = array_fill_keys(array_map('strval', (array) $recent_keys), true);

        $union = array_keys(array_merge($global_keys, $recent_set));
        $meta_raw = array_unique($union);
        $discovery_note['mec_meta_mode'] = 'mec_prefix_global_union_recent_sample';
    } elseif ($post_type === 'product') {
        if (!empty($recent_ids)) {
            $ph = implode(',', array_fill(0, count($recent_ids), '%d'));
            $sql = "SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm
                    WHERE pm.post_id IN ($ph) AND pm.meta_key <> ''";
            $meta_raw = $wpdb->get_col($wpdb->prepare($sql, $recent_ids));
        }
        $meta_raw = array_values(array_filter(
            array_map('strval', (array) $meta_raw),
            static function ($mk) {
                return $mk !== '' && !xabia_deep_schema_is_noise_meta($mk);
            }
        ));
        $discovery_note['meta_mode'] = 'product_recent_include_underscore';
    } else {
        if (!empty($recent_ids)) {
            $ph = implode(',', array_fill(0, count($recent_ids), '%d'));
            $sql = "SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm
                    WHERE pm.post_id IN ($ph) AND pm.meta_key <> ''
                    AND LEFT(pm.meta_key, 1) <> '_'";
            $meta_raw = $wpdb->get_col($wpdb->prepare($sql, $recent_ids));
        }
        $discovery_note['meta_mode'] = 'recent_posts_exclude_leading_underscore';
    }

    $meta_list = [];
    foreach (array_unique(array_map('strval', (array) $meta_raw)) as $mk) {
        if ($mk === '') {
            continue;
        }
        $row = [
            'key'   => $mk,
            'label' => $mk,
        ];
        if ($post_type === 'mec-events' && isset($recent_set[$mk])) {
            $row['in_recent_sample'] = true;
        }
        $meta_list[] = $row;
    }

    usort(
        $meta_list,
        static function ($a, $b) {
            return strcasecmp($a['key'] ?? '', $b['key'] ?? '');
        }
    );

    /** @var array<int, array{key: string, label: string, in_recent_sample?: bool}> $meta_list */
    $meta_list = apply_filters('xabia_deep_schema_meta_fields', $meta_list, $post_type, $project_id);

    $tax_objs = get_object_taxonomies($post_type, 'objects');
    $taxonomies = [];
    foreach ((array) $tax_objs as $tax) {
        if (!is_object($tax)) {
            continue;
        }
        $taxonomies[] = [
            'name'  => (string) $tax->name,
            'label' => isset($tax->labels->singular_name) && $tax->labels->singular_name !== ''
                ? (string) $tax->labels->singular_name
                : ((string) ($tax->label ?? $tax->name)),
        ];
    }
    usort(
        $taxonomies,
        static function ($a, $b) {
            return strcasecmp($a['label'] ?? '', $b['label'] ?? '');
        }
    );

    $virtual_fields = [];
    if ($post_type === 'mec-events') {
        $virtual_fields[] = [
            'id'          => 'mec_available_slots',
            'label'       => __('Plazas disponibles (capacidad − reservas)', 'xabia-intelligence'),
            'description' => __(
                'Se calcula al sincronizar: capacidad desde tickets/meta MEC menos reservas confirmadas (p. ej. CPT mec-books). Opcionalmente extensible con xabia_mec_booked_seats / tabla personalizada.',
                'xabia-intelligence'
            ),
        ];
    }
    $virtual_fields = apply_filters('xabia_deep_schema_virtual_fields', $virtual_fields, $post_type, $project_id);

    $core_columns = [
        'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt',
        'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged',
        'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order',
        'post_type', 'post_mime_type', 'comment_count',
    ];

    $mapping_hints = apply_filters(
        'xabia_deep_schema_mapping_hints',
        xabia_default_deep_mapping_hints($post_type, $taxonomies),
        $post_type,
        $project_id
    );
    $mapping_hints = array_values(
        array_unique(
            array_filter(
                array_map('strval', (array) $mapping_hints),
                static function ($v) {
                    return $v !== '';
                }
            )
        )
    );

    $payload = [
        'post_type'      => $post_type,
        'core'           => $core_columns,
        'meta'           => $meta_list,
        'taxonomies'     => $taxonomies,
        'virtual'        => $virtual_fields,
        'discovery'      => $discovery_note,
        'mapping_hints'  => $mapping_hints,
    ];

    /** @var array $payload */
    $payload = apply_filters('xabia_discover_cpt_fields_result', $payload, $post_type, $args);

    return xabia_discover_cpt_fields_normalize_payload($payload, $post_type);
}

/**
 * Esquema CPT desde SQL remoto (cuando el tipo no existe en este WordPress).
 *
 * @param array<string, mixed> $project_config
 * @return array<string, mixed>|WP_Error
 */
function xabia_discover_cpt_fields_from_remote(string $post_type, string $project_id, array $project_config) {
    if ($project_config === [] && $project_id !== '') {
        $projects = get_option('xabia_projects_config', []);
        $project_config = is_array($projects[$project_id] ?? null) ? $projects[$project_id] : [];
    }
    if (!class_exists('Xabia_Relation_Entity_Catalog', false)) {
        return new WP_Error('xabia_schema_remote_unavailable', __('Catálogo remoto no disponible.', 'xabia-intelligence'));
    }

    $sql_config = Xabia_Relation_Entity_Catalog::resolve_project_sql_config($project_config);
    if ($sql_config === null) {
        return new WP_Error('xabia_schema_no_sql', __('No hay conexión SQL configurada para descubrir este tipo.', 'xabia-intelligence'));
    }

    $meta_bundle = Xabia_Relation_Entity_Catalog::fetch_meta_keys_for_post_type($project_config, $post_type, 'content');
    $meta_keys = is_array($meta_bundle['meta_keys'] ?? null) ? $meta_bundle['meta_keys'] : [];

    // Product / MEC: enriquecer con metas privadas útiles (el catálogo de relaciones las excluye).
    if (in_array($post_type, ['product', 'mec-events'], true)) {
        $extra = xabia_discover_cpt_private_meta_keys_remote($post_type, $sql_config);
        $meta_keys = array_values(array_unique(array_merge($meta_keys, $extra)));
    }

    $meta = [];
    foreach ($meta_keys as $mk) {
        $mk = (string) $mk;
        if ($mk === '' || xabia_deep_schema_is_noise_meta($mk)) {
            continue;
        }
        if ($mk[0] === '_' && $post_type !== 'product' && !($post_type === 'mec-events' && $mk === '_thumbnail_id')) {
            continue;
        }
        $meta[] = [
            'key'              => $mk,
            'label'            => $mk,
            'in_recent_sample' => true,
        ];
    }

    /** @var array<int, array{key: string, label: string, in_recent_sample?: bool}> $meta */
    $meta = apply_filters('xabia_deep_schema_meta_fields', $meta, $post_type, $project_id);

    $taxonomies = [];
    if (!class_exists('Xabia_SQL_Connector', false)) {
        $path = dirname(__FILE__) . '/../integrations/class-xabia-sql-connector.php';
        if (is_readable($path)) {
            require_once $path;
        }
    }
    if (class_exists('Xabia_SQL_Connector', false)) {
        $pt = esc_sql($post_type);
        $sql_tax = "SELECT DISTINCT tt.taxonomy AS name
            FROM `{prefix}term_relationships` tr
            INNER JOIN `{prefix}term_taxonomy` tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            INNER JOIN `{prefix}posts` p ON p.ID = tr.object_id
            WHERE p.post_type = '{$pt}'
              AND p.post_status = 'publish'
            LIMIT 40";
        $cfg = $sql_config;
        $cfg['query'] = $sql_tax;
        $rows = Xabia_SQL_Connector::fetch_data($cfg);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $tax = sanitize_key((string) ($row['name'] ?? ''));
                if ($tax === '' || preg_match('/^(nav_menu|link_category|post_format|wp_)/', $tax)) {
                    continue;
                }
                $taxonomies[] = [
                    'name'  => $tax,
                    'label' => ucwords(str_replace(['-', '_'], ' ', $tax)),
                ];
            }
        }
    }

    $virtual_fields = [];
    if ($post_type === 'mec-events') {
        $virtual_fields[] = [
            'id'          => 'mec_available_slots',
            'label'       => __('Plazas disponibles (capacidad − reservas)', 'xabia-intelligence'),
            'description' => __(
                'Se calcula al sincronizar cuando hay MEC local. En catálogo remoto puede quedar vacío; el SQL del addon deja la columna preparada.',
                'xabia-intelligence'
            ),
        ];
    }
    $virtual_fields = apply_filters('xabia_deep_schema_virtual_fields', $virtual_fields, $post_type, $project_id);

    $mapping_hints = apply_filters(
        'xabia_deep_schema_mapping_hints',
        xabia_default_deep_mapping_hints($post_type, $taxonomies),
        $post_type,
        $project_id
    );
    $mapping_hints = array_values(
        array_unique(
            array_filter(
                array_map('strval', (array) $mapping_hints),
                static function ($v) {
                    return $v !== '';
                }
            )
        )
    );

    $payload = [
        'post_type'     => $post_type,
        'core'          => ['ID', 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_date', 'guid'],
        'meta'          => $meta,
        'taxonomies'    => $taxonomies,
        'virtual'       => $virtual_fields,
        'discovery'     => [
            'source' => 'remote_sql',
            'acf'    => (int) ($meta_bundle['debug']['acf'] ?? 0),
        ],
        'mapping_hints' => $mapping_hints !== []
            ? $mapping_hints
            : array_values(array_unique(array_merge(
                ['ID', 'post_title', 'post_content'],
                array_slice($meta_keys, 0, 12)
            ))),
    ];

    /** @var array $payload */
    $payload = apply_filters('xabia_discover_cpt_fields_result', $payload, $post_type, [
        'project_id'     => $project_id,
        'project_config' => $project_config,
        'remote'         => true,
    ]);

    return xabia_discover_cpt_fields_normalize_payload($payload, $post_type);
}

/**
 * Metas con prefijo _ útiles para deep schema (product / mec), vía SQL remoto.
 *
 * @param array<string, mixed> $sql_config
 * @return list<string>
 */
function xabia_discover_cpt_private_meta_keys_remote(string $post_type, array $sql_config): array {
    if (!class_exists('Xabia_SQL_Connector', false)) {
        $path = dirname(__FILE__) . '/../integrations/class-xabia-sql-connector.php';
        if (is_readable($path)) {
            require_once $path;
        }
    }
    if (!class_exists('Xabia_SQL_Connector', false)) {
        return $post_type === 'product' && function_exists('xabia_woo_deep_schema_meta_keys')
            ? xabia_woo_deep_schema_meta_keys()
            : ($post_type === 'mec-events' ? ['_thumbnail_id'] : []);
    }

    $pt = esc_sql($post_type);
    if ($post_type === 'product') {
        $sql = "SELECT DISTINCT pm.meta_key AS meta_key
            FROM `{prefix}postmeta` pm
            INNER JOIN `{prefix}posts` p ON p.ID = pm.post_id
            WHERE p.post_type = '{$pt}'
              AND p.post_status = 'publish'
              AND pm.meta_key <> ''
              AND LEFT(pm.meta_key, 1) = '_'
            LIMIT 120";
    } else {
        $sql = "SELECT DISTINCT pm.meta_key AS meta_key
            FROM `{prefix}postmeta` pm
            INNER JOIN `{prefix}posts` p ON p.ID = pm.post_id
            WHERE p.post_type = '{$pt}'
              AND p.post_status IN ('publish','future')
              AND pm.meta_key IN ('_thumbnail_id')
            LIMIT 20";
    }
    $cfg = $sql_config;
    $cfg['query'] = $sql;
    $rows = Xabia_SQL_Connector::fetch_data($cfg);
    $out = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mk = (string) ($row['meta_key'] ?? '');
            if ($mk === '' || xabia_deep_schema_is_noise_meta($mk)) {
                continue;
            }
            $out[] = $mk;
        }
    }
    if ($post_type === 'product' && function_exists('xabia_woo_deep_schema_meta_keys')) {
        $out = array_merge($out, xabia_woo_deep_schema_meta_keys());
    }
    if ($post_type === 'mec-events' && !in_array('_thumbnail_id', $out, true)) {
        $out[] = '_thumbnail_id';
    }

    return array_values(array_unique($out));
}

/**
 * Ruido típico de postmeta WP que no aporta al mapeo del Asistente CPT.
 */
function xabia_deep_schema_is_noise_meta(string $meta_key): bool {
    if ($meta_key === '') {
        return true;
    }
    static $exact = [
        '_edit_lock' => true,
        '_edit_last' => true,
        '_wp_old_slug' => true,
        '_wp_trash_meta_status' => true,
        '_wp_trash_meta_time' => true,
        '_wp_desired_post_slug' => true,
    ];
    if (isset($exact[$meta_key])) {
        return true;
    }
    if (strpos($meta_key, '_oembed_') === 0) {
        return true;
    }
    if (strpos($meta_key, '_wp_attached_') === 0) {
        return true;
    }

    return (bool) apply_filters('xabia_deep_schema_is_noise_meta', false, $meta_key);
}

/**
 * Campos recomendados por defecto para marcar en la consola de curación (checkboxes).
 *
 * @param array<int, array{name?: string}> $taxonomies Taxonomías ya resueltas del CPT.
 * @return string[] IDs de campo (post_title, mec_start_date, tax_mec_label, …).
 */
function xabia_default_deep_mapping_hints($post_type, array $taxonomies) {
    $hints = ['ID', 'post_title', 'post_content', 'post_excerpt'];
    if ($post_type === 'mec-events') {
        $hints[] = 'mec_start_date';
        $hints[] = 'mec_cost';
        $hints[] = 'mec_location';
        foreach ($taxonomies as $t) {
            $n = isset($t['name']) ? (string) $t['name'] : '';
            if ($n !== '') {
                $hints[] = 'tax_' . $n;
            }
        }
        $hints[] = 'mec_available_slots';
    } elseif ($post_type === 'product') {
        $hints[] = '_price';
        $hints[] = '_sku';
        $hints[] = '_stock_status';
        $hints[] = '_thumbnail_id';
        foreach ($taxonomies as $t) {
            $n = isset($t['name']) ? (string) $t['name'] : '';
            if ($n !== '') {
                $hints[] = 'tax_' . $n;
            }
        }
    }

    return array_values(array_unique($hints));
}

/**
 * @param array<string, mixed> $payload
 * @return array|WP_Error
 */
function xabia_discover_cpt_fields_normalize_payload($payload, $post_type) {
    if (!is_array($payload)) {
        return new WP_Error('xabia_schema_invalid', __('Respuesta de esquema inválida.', 'xabia-intelligence'));
    }
    $payload['post_type'] = isset($payload['post_type']) ? sanitize_key((string) $payload['post_type']) : $post_type;
    if (!isset($payload['core']) || !is_array($payload['core'])) {
        $payload['core'] = [];
    }
    if (!isset($payload['meta']) || !is_array($payload['meta'])) {
        $payload['meta'] = [];
    }
    if (!isset($payload['taxonomies']) || !is_array($payload['taxonomies'])) {
        $payload['taxonomies'] = [];
    }
    if (!isset($payload['virtual']) || !is_array($payload['virtual'])) {
        $payload['virtual'] = [];
    }
    if (!isset($payload['mapping_hints']) || !is_array($payload['mapping_hints'])) {
        $payload['mapping_hints'] = xabia_default_deep_mapping_hints(
            $payload['post_type'],
            $payload['taxonomies']
        );
    }

    return $payload;
}
