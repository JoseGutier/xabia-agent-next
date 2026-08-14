<?php
/**
 * XABIA ADMIN — panel de proyectos, sincronización, diseño y herramientas.
 * -------------------------------------------------------------------------
 * ESTADO: Producción Final / Máxima Integridad Estructural.
 * * CARACTERÍSTICAS INCLUIDAS:
 * - Soporte Driver Inteligencia: OpenAI y Google Cloud Vertex AI (JSON Path).
 * - Control de Confianza: Campo min_score (Umbral de precisión).
 * - Visual Chat Parser: Renderiza [ACTION:IMG:...] como imágenes reales.
 * - UX Mapping: Checkbox "ENTE" alineado a la derecha con diseño profesional.
 * - Diseño Nobel: Grid de 2 columnas, pestañas originales y estilos Nobel.
 * -------------------------------------------------------------------------
 */

if (!defined('ABSPATH')) exit;

class Xabia_Admin {
    private static function xabia_attributes_need_mec_defaults(array $attributes): bool {
        if ($attributes === []) {
            return true;
        }
        foreach ($attributes as $a) {
            if (!empty($a['csv_col'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Expansiones léxicas desde filas visuales (palabra + sinónimos separados por comas → patrón |).
     *
     * @param mixed $rows
     * @return array{value: array<string, string>, valid: bool, had_input: bool}
     */
    private static function sanitize_keyword_expansions_from_post($rows): array {
        if (!is_array($rows)) {
            return ['value' => [], 'valid' => true, 'had_input' => false];
        }
        $out = [];
        $had_input = false;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = mb_strtolower(trim(sanitize_text_field((string) ($row['key'] ?? ''))), 'UTF-8');
            $syn_raw = trim(sanitize_text_field((string) ($row['synonyms'] ?? '')));
            if ($key === '' && $syn_raw === '') {
                continue;
            }
            $had_input = true;
            if ($key === '' || mb_strlen($key, 'UTF-8') < 2) {
                continue;
            }
            $parts = preg_split('/\s*,\s*/u', $syn_raw, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($parts)) {
                $parts = [];
            }
            $terms = [];
            $terms[$key] = true;
            foreach ($parts as $p) {
                $p = mb_strtolower(trim(sanitize_text_field((string) $p)), 'UTF-8');
                if ($p === '' || mb_strlen($p, 'UTF-8') < 2) {
                    continue;
                }
                if (preg_match('/[|\r\n]/', $p)) {
                    continue;
                }
                $terms[$p] = true;
            }
            $pattern = implode('|', array_keys($terms));
            if ($pattern === '') {
                continue;
            }
            if (mb_strlen($pattern, 'UTF-8') > 500) {
                $pattern = mb_substr($pattern, 0, 500, 'UTF-8');
            }
            $out[$key] = $pattern;
            if (count($out) >= 40) {
                break;
            }
        }

        return ['value' => $out, 'valid' => true, 'had_input' => $had_input];
    }

    /**
     * Relaciones Index-Time desde filas visuales (sin JSON visible).
     *
     * @param mixed $rows
     * @return array{value: list<array<string, string>>, valid: bool, had_input: bool}
     */
    private static function sanitize_knowledge_relations_from_post($rows): array {
        if (!is_array($rows)) {
            return ['value' => [], 'valid' => true, 'had_input' => false];
        }
        $out = [];
        $had_input = false;
        foreach ($rows as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = sanitize_key((string) ($item['relation_type'] ?? 'meta_key'));
            if ($type === '' || $type === 'meta' || $type === 'custom_field') {
                $type = 'meta_key';
            }
            if ($type === 'junction' || $type === 'intermediate') {
                $type = 'table';
            }
            $source = sanitize_key((string) ($item['source_post_type'] ?? ''));
            $connected = sanitize_key((string) ($item['connected_post_type'] ?? ''));
            $meta_key = sanitize_text_field((string) ($item['meta_key'] ?? $item['relation_key'] ?? ''));
            $table = sanitize_text_field((string) ($item['relation_table'] ?? ''));
            $col_from = sanitize_text_field((string) ($item['col_from'] ?? ''));
            $col_to = sanitize_text_field((string) ($item['col_to'] ?? ''));

            if ($source === '' && $connected === '' && $meta_key === '' && $table === '' && $col_from === '' && $col_to === '') {
                continue;
            }
            $had_input = true;
            if (!in_array($type, ['meta_key', 'table'], true) || $source === '' || $connected === '') {
                continue;
            }
            if ($type === 'table') {
                if ($table === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                    continue;
                }
                $from = preg_replace('/[^a-zA-Z0-9_]/', '', $col_from);
                $to = preg_replace('/[^a-zA-Z0-9_]/', '', $col_to);
                if ($from === '' || $to === '') {
                    continue;
                }
                $out[] = [
                    'relation_type'       => 'table',
                    'relation_key'        => $from . ':' . $to,
                    'relation_table'      => $table,
                    'source_post_type'    => $source,
                    'connected_post_type' => $connected,
                ];
            } else {
                if ($meta_key === '') {
                    continue;
                }
                if (mb_strlen($meta_key, 'UTF-8') > 191) {
                    $meta_key = mb_substr($meta_key, 0, 191, 'UTF-8');
                }
                $out[] = [
                    'relation_type'       => 'meta_key',
                    'relation_key'        => $meta_key,
                    'source_post_type'    => $source,
                    'connected_post_type' => $connected,
                ];
            }
            if (count($out) >= 10) {
                break;
            }
        }

        return ['value' => $out, 'valid' => true, 'had_input' => $had_input];
    }

    /**
     * Patrón interno (a|b|c) → texto visual con comas (sin tuberías).
     */
    private static function keyword_pattern_to_synonyms_display(string $key, string $pattern): string {
        $parts = preg_split('/\|+/', $pattern, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return '';
        }
        $key_l = mb_strtolower(trim($key), 'UTF-8');
        $out = [];
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p === '' || mb_strtolower($p, 'UTF-8') === $key_l) {
                continue;
            }
            $out[] = $p;
        }

        return implode(', ', $out);
    }

    /**
     * Entidades (CPT + taxonomías) desde la fuente activa del proyecto.
     *
     * @param array<string, mixed> $project_config
     * @return array<string, string> slug => label
     */
    private static function get_public_post_type_choices(array $project_config = []): array {
        if ($project_config === [] || !class_exists('Xabia_Relation_Entity_Catalog', false)) {
            if (class_exists('Xabia_Relation_Entity_Catalog', false)) {
                $bundle = Xabia_Relation_Entity_Catalog::discover_for_project([]);
                return is_array($bundle['entities'] ?? null) ? $bundle['entities'] : [];
            }
            return [];
        }
        $bundle = Xabia_Relation_Entity_Catalog::discover_for_project($project_config);

        return is_array($bundle['entities'] ?? null) ? $bundle['entities'] : [];
    }

    /**
     * @param array<string, string> $choices
     * @param list<string>          $extra_slugs
     * @return array<string, string>
     */
    private static function merge_post_type_choices(array $choices, array $extra_slugs): array {
        foreach ($extra_slugs as $slug) {
            $slug = sanitize_key((string) $slug);
            if ($slug === '' || isset($choices[$slug])) {
                continue;
            }
            if (preg_match('/^(elementor|e-|acf-|jet-|wpcf7|wp_|shop_|wc_)/', $slug)
                || in_array($slug, ['attachment', 'custom_css', 'elementor_library', 'acf-field-group'], true)
            ) {
                continue;
            }
            $choices[$slug] = ucwords(str_replace(['-', '_'], ' ', $slug));
        }
        asort($choices, SORT_NATURAL | SORT_FLAG_CASE);

        return $choices;
    }

    /**
     * Fila visual: Origen → Destino → Clave de campo (relación meta).
     *
     * @param array<string, string>     $entities
     * @param array<string, mixed>|null $row
     */
    private static function render_knowledge_relation_row(array $entities, $row, int $index, array $kinds = []): void {
        if (!is_array($row)) {
            $row = [];
        }
        $source = sanitize_key((string) ($row['source_post_type'] ?? ''));
        $connected = sanitize_key((string) ($row['connected_post_type'] ?? ''));
        $meta_key = sanitize_text_field((string) ($row['relation_key'] ?? $row['meta_key'] ?? ''));
        // Filas antiguas tipo tabla: mostrar clave legible si había from:to
        if ($meta_key !== '' && strpos($meta_key, ':') !== false && sanitize_key((string) ($row['relation_type'] ?? '')) === 'table') {
            $meta_key = '';
        }
        $name = 'knowledge_rel_rows[' . $index . ']';
        ?>
        <div class="xabia-visual-row xabia-rel-row" data-xabia-rel-row>
            <input type="hidden" name="<?php echo esc_attr($name); ?>[relation_type]" value="meta_key">
            <div class="xabia-visual-row__grid xabia-rel-row__grid xabia-rel-row__grid--map">
                <label class="xabia-visual-field">
                    <span class="xabia-visual-field__label"><?php echo esc_html__('Origen', 'xabia-intelligence'); ?></span>
                    <select name="<?php echo esc_attr($name); ?>[source_post_type]" class="widefat xabia-rel-entity-select">
                        <option value=""><?php echo esc_html__('— Elegir —', 'xabia-intelligence'); ?></option>
                        <?php foreach ($entities as $slug => $label) :
                            $kind = sanitize_key((string) ($kinds[$slug] ?? 'content'));
                            ?>
                            <option value="<?php echo esc_attr($slug); ?>" data-kind="<?php echo esc_attr($kind); ?>" <?php selected($source, $slug); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="xabia-rel-arrow" aria-hidden="true">→</div>
                <label class="xabia-visual-field">
                    <span class="xabia-visual-field__label"><?php echo esc_html__('Destino', 'xabia-intelligence'); ?></span>
                    <select name="<?php echo esc_attr($name); ?>[connected_post_type]" class="widefat xabia-rel-entity-select">
                        <option value=""><?php echo esc_html__('— Elegir —', 'xabia-intelligence'); ?></option>
                        <?php foreach ($entities as $slug => $label) :
                            $kind = sanitize_key((string) ($kinds[$slug] ?? 'content'));
                            ?>
                            <option value="<?php echo esc_attr($slug); ?>" data-kind="<?php echo esc_attr($kind); ?>" <?php selected($connected, $slug); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="xabia-visual-field xabia-visual-field--grow">
                    <span class="xabia-visual-field__label"><?php echo esc_html__('A través de (clave del campo)', 'xabia-intelligence'); ?></span>
                    <?php self::render_relation_meta_key_field($name, $meta_key, $source); ?>
                </label>
            </div>
            <button type="button" class="button-link-delete xabia-visual-row__remove" data-xabia-remove-row aria-label="<?php echo esc_attr__('Eliminar fila', 'xabia-intelligence'); ?>">&times;</button>
        </div>
        <?php
    }

    /**
     * Combobox dinámico (input + datalist) para meta_key.
     * Siempre editable a mano; AJAX solo rellena sugerencias del datalist.
     */
    private static function render_relation_meta_key_field(string $name, string $meta_key, string $source_post_type): void {
        $field_name = $name . '[meta_key]';
        $has_source = $source_post_type !== '';
        static $meta_list_seq = 0;
        ++$meta_list_seq;
        $list_id = 'xabia-meta-keys-' . $meta_list_seq;
        ?>
        <div class="xabia-rel-meta-key-field" data-xabia-meta-key-field>
            <select class="widefat xabia-rel-meta-key-picker" data-xabia-meta-key-picker style="margin-bottom:6px;">
                <option value=""><?php echo esc_html__('— Sugerencias detectadas —', 'xabia-intelligence'); ?></option>
            </select>
            <input
                type="text"
                class="widefat xabia-rel-meta-key-input xabia-meta-input"
                data-xabia-meta-key-input
                name="<?php echo esc_attr($field_name); ?>"
                value="<?php echo esc_attr($meta_key); ?>"
                list="<?php echo esc_attr($list_id); ?>"
                placeholder="<?php echo esc_attr__('Escribe o selecciona la clave...', 'xabia-intelligence'); ?>"
                autocomplete="off"
            >
            <datalist id="<?php echo esc_attr($list_id); ?>" data-xabia-meta-key-list></datalist>
            <p class="description xabia-rel-meta-key-hint" data-xabia-meta-key-hint style="display:none;margin:4px 0 0;">
                <?php
                if ($has_source) {
                    echo esc_html__('No se pudieron cargar metas desde la fuente. Escribe la clave manualmente.', 'xabia-intelligence');
                } else {
                    echo esc_html__('Selecciona el origen para cargar sugerencias o escribe la clave manualmente.', 'xabia-intelligence');
                }
                ?>
            </p>
        </div>
        <?php
    }

    private static function render_keyword_expansion_row(string $key, string $synonyms_display, int $index): void {
        $name = 'keyword_exp_rows[' . $index . ']';
        ?>
        <div class="xabia-visual-row xabia-kw-row" data-xabia-kw-row>
            <div class="xabia-visual-row__grid xabia-kw-row__grid">
                <label class="xabia-visual-field">
                    <span class="xabia-visual-field__label"><?php echo esc_html__('Palabra clave', 'xabia-intelligence'); ?></span>
                    <input type="text" class="widefat" name="<?php echo esc_attr($name); ?>[key]" value="<?php echo esc_attr($key); ?>" placeholder="<?php echo esc_attr__('velero', 'xabia-intelligence'); ?>" autocomplete="off">
                </label>
                <label class="xabia-visual-field xabia-visual-field--grow">
                    <span class="xabia-visual-field__label"><?php echo esc_html__('Sinónimos (separados por comas)', 'xabia-intelligence'); ?></span>
                    <input type="text" class="widefat" name="<?php echo esc_attr($name); ?>[synonyms]" value="<?php echo esc_attr($synonyms_display); ?>" placeholder="<?php echo esc_attr__('vela, barco, náutica, sailing', 'xabia-intelligence'); ?>" autocomplete="off">
                </label>
            </div>
            <button type="button" class="button-link-delete xabia-visual-row__remove" data-xabia-remove-row aria-label="<?php echo esc_attr__('Eliminar fila', 'xabia-intelligence'); ?>">&times;</button>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $attr
     */
    private static function xabia_attr_import_rag_enabled(array $attr): bool {
        if (array_key_exists('import_rag', $attr)) {
            return (int) $attr['import_rag'] !== 0;
        }
        $col = (string) ($attr['csv_col'] ?? '');
        $role = (string) ($attr['visual_role'] ?? 'none');
        if (class_exists('Xabia_Knowledge_Text', false)) {
            return Xabia_Knowledge_Text::default_import_rag_for_column($col, $role);
        }

        return true;
    }

    private static function xabia_native_connector_plugin_basename(string $slug): string {
        $map = [
            'mec' => 'xabia-mec/xabia-mec.php',
            'woo' => 'xabia-woo/xabia-woo.php',
        ];
        return $map[$slug] ?? '';
    }

    private static function xabia_mec_remote_sql_preset(): string {
        if (class_exists('Xabia_MEC_Connector', false) && method_exists('Xabia_MEC_Connector', 'get_sync_sql')) {
            return (string) Xabia_MEC_Connector::get_sync_sql();
        }

        return "
            SELECT
                p.ID,
                p.post_title AS Evento,
                m_date.meta_value AS Fecha,
                CONCAT(
                    COALESCE(m_h.meta_value, '00'), ':',
                    COALESCE(m_m.meta_value, '00'), ' ',
                    COALESCE(m_ap.meta_value, '')
                ) AS Hora,
                COALESCE(m_loc.meta_value, '') AS Lugar,
                NULL AS mec_available_slots,
                p.post_name AS post_slug,
                '' AS Link,
                COALESCE(m_cost.meta_value, 'Consultar') AS Precio,
                p.post_content AS Descripcion,
                (
                    SELECT GROUP_CONCAT(t.name SEPARATOR ', ')
                    FROM {prefix}term_relationships tr
                    JOIN {prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    JOIN {prefix}terms t ON tt.term_id = t.term_id
                    WHERE tr.object_id = p.ID
                ) AS Categorias_Tags,
                (SELECT guid FROM {prefix}posts WHERE ID = m_thumb.meta_value) AS Imagen_URL
            FROM {prefix}posts p
            INNER JOIN {prefix}postmeta m_date ON (p.ID = m_date.post_id AND m_date.meta_key = 'mec_start_date')
            LEFT JOIN {prefix}postmeta m_h ON (p.ID = m_h.post_id AND m_h.meta_key = 'mec_start_time_hour')
            LEFT JOIN {prefix}postmeta m_m ON (p.ID = m_m.post_id AND m_m.meta_key = 'mec_start_time_minutes')
            LEFT JOIN {prefix}postmeta m_ap ON (p.ID = m_ap.post_id AND m_ap.meta_key = 'mec_start_time_ampm')
            LEFT JOIN {prefix}postmeta m_cost ON (p.ID = m_cost.post_id AND m_cost.meta_key = 'mec_cost')
            LEFT JOIN {prefix}postmeta m_thumb ON (p.ID = m_thumb.post_id AND m_thumb.meta_key = '_thumbnail_id')
            LEFT JOIN {prefix}postmeta m_loc ON (p.ID = m_loc.post_id AND m_loc.meta_key = 'mec_location')
            WHERE p.post_type = 'mec-events'
            AND p.post_status IN ('publish', 'future')
            AND p.post_parent = 0
            AND m_date.meta_value >= CURDATE()
            ORDER BY m_date.meta_value ASC
            LIMIT 100
        ";
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function xabia_mec_remote_default_mapping_fields(): array {
        if (class_exists('Xabia_MEC_Connector', false) && method_exists('Xabia_MEC_Connector', 'default_mapping_fields')) {
            $fields = Xabia_MEC_Connector::default_mapping_fields();
            if (is_array($fields) && $fields !== []) {
                return self::normalize_mec_mapping_roles($fields);
            }
        }

        return self::normalize_mec_mapping_roles([
            [
                'csv_col' => 'ID',
                'label' => __('ID del evento (MEC)', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente' => 1,
                'instruction' => __('Identificador del post mec-events.', 'xabia-intelligence'),
                'import_rag' => 0,
            ],
            [
                'csv_col' => 'Evento',
                'label' => __('Título del evento', 'xabia-intelligence'),
                'visual_role' => 'title',
                'is_ente' => 0,
                'instruction' => __('Nombre del evento para listados y búsqueda.', 'xabia-intelligence'),
                'import_rag' => 1,
            ],
            ['csv_col' => 'Fecha', 'label' => __('Fecha de inicio', 'xabia-intelligence'), 'visual_role' => 'date', 'is_ente' => 0, 'instruction' => __('Fecha Y-m-d.', 'xabia-intelligence'), 'import_rag' => 1],
            ['csv_col' => 'Hora', 'label' => __('Hora', 'xabia-intelligence'), 'visual_role' => 'none', 'is_ente' => 0, 'instruction' => '', 'import_rag' => 1],
            ['csv_col' => 'Lugar', 'label' => __('Ubicación', 'xabia-intelligence'), 'visual_role' => 'map', 'is_ente' => 0, 'instruction' => __('Lugar o sede desde MEC.', 'xabia-intelligence'), 'import_rag' => 1],
            ['csv_col' => 'mec_available_slots', 'label' => __('Plazas libres', 'xabia-intelligence'), 'visual_role' => 'none', 'is_ente' => 0, 'instruction' => __('Número de plazas disponibles, si existe.', 'xabia-intelligence'), 'import_rag' => 1],
            ['csv_col' => 'Link', 'label' => __('Enlace / reserva', 'xabia-intelligence'), 'visual_role' => 'web', 'is_ente' => 0, 'instruction' => __('URL pública del evento.', 'xabia-intelligence'), 'import_rag' => 1],
            ['csv_col' => 'Precio', 'label' => __('Precio', 'xabia-intelligence'), 'visual_role' => 'none', 'is_ente' => 0, 'instruction' => '', 'import_rag' => 1],
            ['csv_col' => 'Descripcion', 'label' => __('Descripción', 'xabia-intelligence'), 'visual_role' => 'info', 'is_ente' => 0, 'instruction' => '', 'import_rag' => 1],
            ['csv_col' => 'Categorias_Tags', 'label' => __('Categorías y etiquetas', 'xabia-intelligence'), 'visual_role' => 'info', 'is_ente' => 0, 'instruction' => __('Taxonomías MEC.', 'xabia-intelligence'), 'import_rag' => 1],
            ['csv_col' => 'Imagen_URL', 'label' => __('Imagen', 'xabia-intelligence'), 'visual_role' => 'img', 'is_ente' => 0, 'instruction' => '', 'import_rag' => 0],
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return list<array<string, mixed>>
     */
    private static function normalize_mec_mapping_roles(array $fields): array {
        $out = [];
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['csv_col'])) {
                continue;
            }
            $role = (string) ($field['visual_role'] ?? 'none');
            if ($role === 'url') {
                $role = 'web';
            } elseif ($role === 'image') {
                $role = 'img';
            }
            if (!array_key_exists('import_rag', $field)) {
                $field['import_rag'] = in_array((string) $field['csv_col'], ['ID', 'Imagen_URL'], true) ? 0 : 1;
            }
            $field['visual_role'] = $role;
            $out[] = $field;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $post
     * @return list<int>
     */
    private static function parse_web_page_ids_from_post(array $post, ?int $multi_idx = null): array {
        if (!class_exists('Xabia_Web_Pages_Source', false)) {
            return [];
        }
        if ($multi_idx !== null) {
            $src = isset($post['sources'][$multi_idx]) && is_array($post['sources'][$multi_idx])
                ? $post['sources'][$multi_idx]
                : [];
            $from_cb = $src['web_page_ids'] ?? [];
            $manual = (string) ($src['web_page_ids_manual'] ?? '');
        } else {
            $from_cb = $post['web_page_ids'] ?? [];
            $manual = (string) ($post['web_page_ids_manual'] ?? '');
        }
        $ids = Xabia_Web_Pages_Source::parse_page_ids($from_cb);
        if ($manual !== '') {
            $ids = array_merge($ids, Xabia_Web_Pages_Source::parse_page_ids($manual));
        }

        return array_values(array_unique($ids));
    }

    private static function purge_project_response_cache(string $project_id): void
    {
        global $wpdb;
        $table = Xabia_DB::table('response_cache');
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return;
        }
        $wpdb->delete($table, ['project_id' => $project_id], ['%s']);
    }
    /**
     * @return array<int, array<string, string>>
     */
    private static function get_master_addons_catalog(): array
    {
        $master = [
            [
                'slug'        => 'xabia-woo',
                'label'       => 'Xabia WooCommerce',
                'plugin_file' => 'xabia-woo/xabia-woo.php',
                'description' => __('Addon que transforma tu WooCommerce en una plataforma de comercio conversacional avanzado. Dota al Agente de IA con inteligencia sobre tu catálogo para carritos asistidos e interacciones de ventas hiperpersonalizadas.', 'xabia-intelligence'),
            ],
            [
                'slug'        => 'xabia-mec',
                'label'       => 'Xabia MEC',
                'plugin_file' => 'xabia-mec/xabia-mec.php',
                'description' => __('Addon de especialización que dota a Xabia AI con inteligencia avanzada para la gestión de eventos, plazas y reservas de Modern Events Calendar, ofreciendo interacciones asistidas en tiempo real.', 'xabia-intelligence'),
            ],
            [
                'slug'        => 'xabia-avirato',
                'label'       => 'Xabia Avirato',
                'plugin_file' => 'xabia-avirato/xabia-avirato.php',
                'description' => __('Scraping de disponibilidad pública de Avirato.', 'xabia-intelligence'),
            ],
            [
                'slug'        => 'xabia-amelia',
                'label'       => 'Xabia Amelia',
                'plugin_file' => 'xabia-amelia/xabia-amelia.php',
                'description' => __('Addon que dota a Xabia AI con inteligencia avanzada para la gestión de citas, servicios y calendarios de Amelia, automatizando la programación de reservas mediante interacciones conversacionales fluidas en tiempo real.', 'xabia-intelligence'),
            ],
            [
                'slug'        => 'xabia-federation',
                'label'       => 'Xabia Federation',
                'plugin_file' => 'xabia-federation/xabia-federation.php',
                'description' => __('Addon avanzado que transforma a Xabia AI en un nodo centralizado de federación global, permitiendo la interconexión inteligente de datos, el intercambio de conocimiento y la sincronización omnicanal entre múltiples sitios webs y plataformas.', 'xabia-intelligence'),
            ],
        ];

        if (!file_exists(WP_PLUGIN_DIR . '/xabia-mec/xabia-mec.php')) {
            $master = array_values(array_filter($master, static fn ($row) => ($row['slug'] ?? '') !== 'xabia-mec'));
        }

        return $master;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function get_addons_catalog(): array
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $known = apply_filters('xabia_agent_known_addons', []);
        if (!is_array($known)) {
            $known = [];
        }
        $knownByFile = [];
        foreach ($known as $item) {
            if (!is_array($item)) {
                continue;
            }
            $file = isset($item['plugin_file']) ? (string) $item['plugin_file'] : '';
            if ($file === '') {
                continue;
            }
            $knownByFile[$file] = $item;
        }

        $catalog = [];
        foreach (self::get_master_addons_catalog() as $row) {
            $file = (string) ($row['plugin_file'] ?? '');
            if ($file === '') {
                continue;
            }
            $knownData = $knownByFile[$file] ?? [];
            $installed = file_exists(WP_PLUGIN_DIR . '/' . $file);
            $active = $installed ? is_plugin_active($file) : false;
            $catalog[] = [
                'slug'        => (string) ($row['slug'] ?? ''),
                'label'       => (string) ($knownData['label'] ?? $row['label'] ?? $file),
                'description' => (string) ($knownData['description'] ?? $row['description'] ?? ''),
                'plugin_file' => $file,
                'installed'   => $installed,
                'active'      => $active,
                'is_master'   => true,
            ];
            unset($knownByFile[$file]);
        }

        
        foreach ($knownByFile as $file => $item) {
            $installed = file_exists(WP_PLUGIN_DIR . '/' . $file);
            $active = $installed ? is_plugin_active($file) : false;
            $catalog[] = [
                'slug'        => (string) ($item['slug'] ?? ''),
                'label'       => (string) ($item['label'] ?? $file),
                'description' => (string) ($item['description'] ?? ''),
                'plugin_file' => $file,
                'installed'   => $installed,
                'active'      => $active,
                'is_master'   => false,
            ];
        }

        return $catalog;
    }

    /**
     * Slug de Xabia_Addons (Polar/Hub) asociado a plugin_file, o cadena vacía.
     */
    private static function hub_registry_slug_for_plugin_file(string $pluginFile): string {
        if ($pluginFile === '' || !class_exists('Xabia_Addons', false)) {
            return '';
        }
        foreach (Xabia_Addons::registry() as $def) {
            if (!is_array($def)) {
                continue;
            }
            if ((string) ($def['plugin_file'] ?? '') === $pluginFile) {
                return sanitize_key((string) ($def['slug'] ?? ''));
            }
        }

        return '';
    }

    /**
     * SVG minimalista estilo Lucide (24px) para tarjetas de suscripción.
     */
    private static function render_addon_lucide_icon(string $name): void {
        $name = sanitize_key($name);
        $svgs = [
            'calendar-check-2' => '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="m9 16 2 2 4-4"/></svg>',
            'package'          => '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><polyline points="3.29 7 12 12 20.71 7"/><path d="m7.5 4.27 9 5.15"/></svg>',
        ];
        $svg = $svgs[$name] ?? $svgs['package'];
        echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup
    }

    /**
     * Inicialización de Hooks y Acciones AJAX
     */
    public static function init() {
        $self = new self();
        
        
        add_action('admin_init', [$self, 'controller_handle_addon_licenses'], 9);
        add_action('admin_init', [$self, 'controller_handle_post']);
        add_action('admin_init', [$self, 'controller_handle_list_actions']);
        
        
        add_action('admin_menu', [$self, 'register_menu']);
        add_action('admin_init', [$self, 'register_options']);
        add_action('admin_enqueue_scripts', [$self, 'load_assets']);
        add_action('admin_notices', [$self, 'render_low_wallet_notice']);
        
        
        add_action('wp_ajax_xabia_get_fields', [$self, 'ajax_scan_csv_fields']);
        add_action('wp_ajax_xabia_sync_content', [$self, 'handle_sync_content_ajax']);
        add_action('wp_ajax_xabia_train_ai', [$self, 'handle_train_ai_ajax']);
        add_action('wp_ajax_xabia_train_estimate', [$self, 'handle_train_estimate_ajax']);
        add_action('wp_ajax_xabia_purge_orphan_knowledge', [$self, 'handle_purge_orphan_knowledge_ajax']);
        add_action('wp_ajax_xabia_sync_brain_cloud', [$self, 'handle_sync_brain_cloud_ajax']);
        add_action('wp_ajax_xabia_knowledge_preview', [$self, 'ajax_knowledge_preview']);
        add_action('wp_ajax_xabia_clear_memory', [$self, 'handle_clear_memory_ajax']);
        add_action('wp_ajax_xabia_test_sql', [$self, 'ajax_test_sql_connection']);
        add_action('wp_ajax_xabia_test_addon', [$self, 'ajax_test_addon_columns']);
        add_action('wp_ajax_xabia_list_csv_files', [$self, 'ajax_list_csv_files']);
        add_action('wp_ajax_xabia_upload_csv', [$self, 'ajax_upload_csv']);
        add_action('wp_ajax_xabia_delete_csv', [$self, 'ajax_delete_csv']);
        add_action('wp_ajax_xabia_get_meta_fields', [$self, 'ajax_get_meta_fields']);
        add_action('wp_ajax_xabia_get_wp_schema', [$self, 'ajax_get_wp_schema']);
        add_action('wp_ajax_xabia_get_deep_schema', [$self, 'ajax_get_deep_schema']);
        add_action('wp_ajax_xabia_relation_entity_types', [$self, 'ajax_relation_entity_types']);
        add_action('wp_ajax_xabia_relation_meta_keys', [$self, 'ajax_relation_meta_keys']);
        add_action('wp_ajax_xabia_digixop_validate_license', [$self, 'ajax_digixop_validate_license']);
        add_action('wp_ajax_xabia_digixop_reveal_saved_license', [$self, 'ajax_digixop_reveal_saved_license']);
        add_action('wp_ajax_xabia_addon_sync_license', [$self, 'ajax_addon_sync_license']);
        add_action('admin_notices', [$self, 'maybe_notice_pro_unlocked']);
    }

    /**
     * Aviso tras activar PRO desde el panel LITE temporal del retail.
     */
    public function maybe_notice_pro_unlocked(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!isset($_GET['page'], $_GET['xabia_pro_unlocked'])) {
            return;
        }
        if (sanitize_key(wp_unslash((string) $_GET['page'])) !== 'xabia-settings') {
            return;
        }
        if ((string) wp_unslash($_GET['xabia_pro_unlocked']) !== '1') {
            return;
        }
        echo '<div class="notice notice-success is-dismissible"><p>'
            . esc_html__('Xabia Agent PRO activado. Revisa la licencia en Ajustes generales y valida la conexión con el Hub si hace falta.', 'xabia-intelligence')
            . '</p></div>';
    }

    /**
     * Registro del Menú en el Panel de WordPress
     */
    public function register_menu() {
        $icon = class_exists('Xabia_Admin_UI', false)
            ? Xabia_Admin_UI::menu_icon_url()
            : 'dashicons-superhero';

        add_menu_page(
            'Xabia Agent', 
            'Xabia Agent', 
            'manage_options', 
            'xabia-settings', 
            [$this, 'render_view'], 
            $icon, 
            25
        );
        add_submenu_page(
            'xabia-settings',
            __('Addons', 'xabia-intelligence'),
            __('Addons', 'xabia-intelligence'),
            'manage_options',
            'xabia-addons',
            [$this, 'render_addons_view']
        );
        add_submenu_page(
            'xabia-settings',
            __('Cartera / Wallet', 'xabia-intelligence'),
            __('Cartera / Wallet', 'xabia-intelligence'),
            'manage_options',
            'xabia-wallet',
            [$this, 'render_wallet_view']
        );

        // Enlace externo a la portada de manuales (no crea página admin).
        global $submenu;
        if (isset($submenu['xabia-settings']) && is_array($submenu['xabia-settings'])) {
            $help_url = (string) apply_filters('xabia_admin_help_docs_url', 'https://xabia.ai/documentacion/');
            $submenu['xabia-settings'][] = [
                __('Ayuda', 'xabia-intelligence'),
                'manage_options',
                $help_url,
                __('Ayuda', 'xabia-intelligence'),
            ];
        }
        add_action('admin_head', [$this, 'admin_help_menu_open_blank'], 20);
        if (class_exists('Xabia_Admin_UI', false)) {
            add_action('admin_head', ['Xabia_Admin_UI', 'print_menu_icon_styles'], 5);
        }
    }

    /**
     * Abre «Ayuda» en pestaña nueva (manuales en xabia.ai/documentacion/).
     */
    public function admin_help_menu_open_blank(): void {
        $help_url = (string) apply_filters('xabia_admin_help_docs_url', 'https://xabia.ai/documentacion/');
        if ($help_url === '') {
            return;
        }
        $js_url = wp_json_encode($help_url);
        echo '<script>jQuery(function($){var u=' . $js_url . ';$("#adminmenu a").filter(function(){return this.href===u||this.getAttribute("href")===u;}).attr({target:"_blank",rel:"noopener noreferrer"});});</script>' . "\n";
    }

    public function render_wallet_view() {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (class_exists('Xabia_Digixop_Client', false)) {
            Xabia_Digixop_Client::refresh_license_meta_from_hub_if_stale();
        }
        $wallet = self::get_wallet_summary();
        $usage = self::get_wallet_usage_30_days();
        $used30 = (int) $usage['total'];
        $hubMeta = class_exists('Xabia_Digixop_Client', false) ? Xabia_Digixop_Client::get_cached_license_meta() : null;
        $remaining = 0;
        if (is_array($hubMeta) && isset($hubMeta['tokens_remaining']) && is_numeric($hubMeta['tokens_remaining'])) {
            $remaining = (int) $hubMeta['tokens_remaining'];
        }
        $capacity = max(1, $remaining + $used30);
        $percent = min(100, max(0, (int) round(($used30 / $capacity) * 100)));
        $licenseId = (string) ($wallet['license_id'] ?? Xabia_DB::wallet_license_id());
        $checkoutLicenseId = trim((string) get_option('xabia_digixop_license_key', ''));
        if ($checkoutLicenseId === '') {
            $checkoutLicenseId = $licenseId;
        }
        $clientUrl = get_site_url();
        $licenseMeta = class_exists('Xabia_Digixop_Client', false) ? Xabia_Digixop_Client::get_cached_license_meta() : null;
        $expiryDate = is_array($licenseMeta) ? trim((string) ($licenseMeta['expiry_date'] ?? '')) : '';
        $showRenewal = false;
        if ($expiryDate !== '') {
            $expiryTs = strtotime($expiryDate);
            $showRenewal = $expiryTs !== false && $expiryTs <= strtotime('+30 days');
        }
        $renewalProductId = defined('XABIA_RENEWAL_ID') ? trim((string) XABIA_RENEWAL_ID) : 'core_renewal';
        $renewalUrl = add_query_arg([
            'metadata[product_type]' => 'core_renewal',
            'metadata[license_key]' => $checkoutLicenseId,
            'metadata[client_url]' => $clientUrl,
        ], 'https://polar.sh/xabia/products/' . rawurlencode($renewalProductId));
        $packs = [
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'price' => '29€',
                'tokens' => 5000000,
                'conversations_hint' => '~1.100 conversaciones reales',
                'polar_product_id' => defined('XABIA_PACK_S_ID') ? (string) XABIA_PACK_S_ID : 'pack_s',
                'polar_checkout_url' => 'https://buy.polar.sh/polar_cl_7znld6W7Fu31Xq4yXBYqRokOCuxBuKdvKtvBG2fpbz2',
            ],
            [
                'slug' => 'business',
                'name' => 'Business',
                'price' => '79€',
                'tokens' => 20000000,
                'conversations_hint' => '~4.400 conversaciones reales',
                'polar_product_id' => defined('XABIA_PACK_M_ID') ? (string) XABIA_PACK_M_ID : 'pack_m',
                'polar_checkout_url' => 'https://buy.polar.sh/polar_cl_heJKC5RTzcZQDrfceeN3t6BuUs3wrf1XXUFQo0yFlK0',
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'price' => '249€',
                'tokens' => 100000000,
                'conversations_hint' => '~22.000 conversaciones reales',
                'polar_product_id' => defined('XABIA_PACK_L_ID') ? (string) XABIA_PACK_L_ID : 'pack_l',
                'polar_checkout_url' => 'https://buy.polar.sh/polar_cl_ydhAdvU1tOWwbdTNbGJEtqznOMMdhrExxEJmQ0Johm3',
            ],
        ];
        $packs = apply_filters('xabia_wallet_polar_packs', $packs, $checkoutLicenseId, $clientUrl);
        ?>
        <div class="wrap xabia-wrapper xabia-admin-app xabia-page-wallet">
            <div class="xabia-card xabia-admin-header xabia-admin-header--wallet">
                <div class="xabia-admin-header__brand">
                    <?php
                    if (class_exists('Xabia_Admin_UI', false)) {
                        Xabia_Admin_UI::render_brand_icon('xabia-admin-header__icon', 40);
                    }
                    ?>
                    <div class="xabia-admin-header__text">
                        <h1 class="xabia-page-title"><?php echo esc_html__('Cartera / Wallet', 'xabia-intelligence'); ?></h1>
                        <p class="xabia-page-subtitle"><?php echo esc_html__('Saldo de tokens de tu licencia Xabia, consumo reciente y packs de recarga.', 'xabia-intelligence'); ?></p>
                    </div>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=xabia-settings')); ?>" class="button xabia-btn--ghost"><?php echo esc_html__('← Ajustes principales', 'xabia-intelligence'); ?></a>
            </div>

            <div class="xabia-wallet-stats-row">
                <div class="xabia-card xabia-wallet-balance-card">
                    <h2 class="xabia-card-title"><?php echo esc_html__('Saldo actual', 'xabia-intelligence'); ?></h2>
                    <p class="xabia-card-desc xabia-card-desc--flush-bottom"><?php echo esc_html__('Una licencia permite agentes ilimitados en este dominio.', 'xabia-intelligence'); ?></p>
                    <div class="xabia-wallet-balance-figure">
                        <?php echo esc_html(number_format_i18n($remaining)); ?>
                        <span class="xabia-wallet-balance-unit"><?php echo esc_html__('tokens', 'xabia-intelligence'); ?></span>
                    </div>
                    <p class="xabia-wallet-meta">
                        <?php echo esc_html__('Identificador de licencia:', 'xabia-intelligence'); ?>
                        <code><?php echo esc_html($licenseId); ?></code>
                    </p>
                    <?php if ($showRenewal) : ?>
                        <p class="xabia-wallet-actions">
                            <a class="xabia-btn-wallet-cta" href="<?php echo esc_url($renewalUrl); ?>" target="_blank" rel="noopener"><?php echo esc_html__('Renovar licencia anual', 'xabia-intelligence'); ?></a>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="xabia-card xabia-wallet-usage-card">
                    <h2 class="xabia-card-title"><?php echo esc_html__('Consumo últimos 30 días', 'xabia-intelligence'); ?></h2>
                    <p class="xabia-wallet-usage-summary">
                        <strong><?php echo esc_html(number_format_i18n($used30)); ?></strong>
                        <?php echo esc_html__('tokens consumidos', 'xabia-intelligence'); ?>
                    </p>
                    <div class="xabia-wallet-progress" role="progressbar" aria-valuenow="<?php echo esc_attr((string) $percent); ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="xabia-wallet-progress__fill" style="width:<?php echo esc_attr((string) $percent); ?>%;"></div>
                    </div>
                    <div class="xabia-wallet-sparkline" aria-hidden="true">
                        <?php foreach ($usage['days'] as $day) :
                            $h = $usage['max'] > 0 ? max(4, (int) round(((int) $day['tokens'] / (int) $usage['max']) * 70)) : 4;
                            ?>
                            <div class="xabia-wallet-sparkline__bar" title="<?php echo esc_attr($day['date'] . ': ' . number_format_i18n((int) $day['tokens']) . ' tokens'); ?>" style="height:<?php echo esc_attr((string) $h); ?>px;"></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="xabia-card xabia-wallet-packs-panel">
                <h2 class="xabia-card-title"><?php echo esc_html__('Recargar saldo', 'xabia-intelligence'); ?></h2>
                <p class="xabia-card-desc xabia-card-desc--flush-bottom"><?php echo esc_html__('Elige un pack; se abrirá el checkout seguro con tu licencia y dominio ya indicados.', 'xabia-intelligence'); ?></p>
                <div class="xabia-wallet-toolbar">
                    <a class="xabia-btn-wallet-secondary" href="<?php echo esc_url(admin_url('admin.php?page=xabia-addons')); ?>"><?php echo esc_html__('Addons y suscripciones Polar', 'xabia-intelligence'); ?></a>
                    <a class="xabia-btn-wallet-secondary" href="<?php echo esc_url(admin_url('admin.php?page=xabia-settings')); ?>"><?php echo esc_html__('Conexión a la IA', 'xabia-intelligence'); ?></a>
                </div>
                <div class="xabia-wallet-pack-grid">
                    <?php foreach ($packs as $pack) :
                        $checkoutUrl = isset($pack['polar_checkout_url']) ? trim((string) $pack['polar_checkout_url']) : '';
                        $productId = isset($pack['polar_product_id']) ? trim((string) $pack['polar_product_id']) : '';
                        if ($checkoutUrl !== '') {
                            $baseUrl = $checkoutUrl;
                        } elseif ($productId !== '') {
                            $baseUrl = 'https://polar.sh/xabia/products/' . rawurlencode($productId);
                        } else {
                            $baseUrl = 'https://polar.sh/xabia/products';
                        }
                        $url = add_query_arg([
                            'metadata[product_type]' => 'pack_' . ($pack['slug'] === 'starter' ? 's' : ($pack['slug'] === 'business' ? 'm' : 'l')),
                            'metadata[license_id]' => $checkoutLicenseId,
                            'metadata[license_key]' => $checkoutLicenseId,
                            'metadata[client_url]' => $clientUrl,
                        ], $baseUrl);
                        ?>
                        <div class="xabia-card xabia-wallet-pack-card">
                            <h3 class="xabia-wallet-pack-card__name"><?php echo esc_html($pack['name']); ?></h3>
                            <div class="xabia-wallet-pack-card__price"><?php echo esc_html($pack['price']); ?></div>
                            <p class="xabia-wallet-pack-card__tokens"><?php echo esc_html(number_format_i18n((int) $pack['tokens'])); ?> <?php echo esc_html__('tokens', 'xabia-intelligence'); ?></p>
                            <?php
                            $conversations_hint = isset($pack['conversations_hint']) ? trim((string) $pack['conversations_hint']) : '';
                            if ($conversations_hint !== '') :
                                ?>
                                <p class="xabia-wallet-pack-card__conversations"><?php echo esc_html($conversations_hint); ?></p>
                            <?php endif; ?>
                            <a class="xabia-btn-wallet-cta" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html__('Recargar', 'xabia-intelligence'); ?></a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_low_wallet_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (class_exists('Xabia_Digixop_Client', false)) {
            Xabia_Digixop_Client::refresh_license_meta_from_hub_if_stale();
        }
        $hub = class_exists('Xabia_Digixop_Client', false) ? Xabia_Digixop_Client::get_cached_license_meta() : null;
        $bal = (is_array($hub) && isset($hub['tokens_remaining']) && is_numeric($hub['tokens_remaining'])) ? (int) $hub['tokens_remaining'] : null;
        if ($bal === null || $bal >= 50000) {
            return;
        }
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><strong><?php echo esc_html__('¡Atención!', 'xabia-intelligence'); ?></strong> <?php echo esc_html__('Tu saldo de tokens está bajo. Recarga para no perder reservas.', 'xabia-intelligence'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=xabia-wallet')); ?>"><?php echo esc_html__('Ir a Cartera / Wallet', 'xabia-intelligence'); ?></a></p>
        </div>
        <?php
    }

    public function render_addons_view() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $registry = class_exists('Xabia_Addons', false) ? Xabia_Addons::registry() : [];
        $catalog = self::get_addons_catalog();
        $catalogByFile = [];
        foreach ($catalog as $c) {
            if (!is_array($c)) {
                continue;
            }
            $pf = (string) ($c['plugin_file'] ?? '');
            if ($pf !== '') {
                $catalogByFile[$pf] = $c;
            }
        }
        ?>
        <div class="wrap xabia-wrapper xabia-admin-app xabia-page-addons">
            <?php if (!empty($_GET['xabia_addon_saved'])) : ?>
                <div class="notice notice-success is-dismissible xabia-addons-notice">
                    <p><?php echo esc_html__('Licencia guardada; hemos vuelto a comprobar el estado con el hub.', 'xabia-intelligence'); ?></p>
                </div>
            <?php endif; ?>
            <div class="xabia-card xabia-admin-header xabia-admin-header--addons">
                <div class="xabia-admin-header__brand">
                    <?php
                    if (class_exists('Xabia_Admin_UI', false)) {
                        Xabia_Admin_UI::render_brand_icon('xabia-admin-header__icon', 40);
                    }
                    ?>
                    <div class="xabia-admin-header__text">
                        <h1 class="xabia-page-title"><?php echo esc_html__('Addons', 'xabia-intelligence'); ?></h1>
                        <p class="xabia-page-subtitle"><?php echo esc_html__('Suscripciones Polar y extensiones modulares. Activa cada add-on con su clave; el hub confirma renovación y vigencia.', 'xabia-intelligence'); ?></p>
                    </div>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=xabia-settings')); ?>" class="button xabia-btn--ghost"><?php echo esc_html__('← Ajustes principales', 'xabia-intelligence'); ?></a>
            </div>

            <?php if ($registry !== []) : ?>
                <?php
                if (class_exists('Xabia_Addon_Updater', false)) {
                    Xabia_Addon_Updater::render_addons_updates_panel();
                }
                ?>
                <div class="xabia-card xabia-addons-polar-panel">
                    <h2 class="xabia-card-title"><?php echo esc_html__('Suscripciones (Polar)', 'xabia-intelligence'); ?></h2>
                    <p class="xabia-card-desc xabia-card-desc--flush-bottom"><?php echo esc_html__('La insignia «Hub Polar» indica si tu licencia incluye este add-on en el Hub. Es independiente de que el plugin esté activado en WordPress (cada tarjeta muestra ambos estados).', 'xabia-intelligence'); ?></p>
                    <p class="xabia-addons-panel-hint"><?php echo esc_html__('Introduce la clave del producto o reutiliza la licencia Core si Polar la tiene vinculada al mismo dominio.', 'xabia-intelligence'); ?></p>
                    <div class="xabia-addons-panel-toolbar">
                        <button type="button" class="xabia-btn-addons-cta" id="xabia-hub-sync-all-addons">
                            <?php echo esc_html__('Sincronizar licencias con el hub', 'xabia-intelligence'); ?>
                        </button>
                        <a class="xabia-btn-addons-secondary" href="<?php echo esc_url(admin_url('plugins.php')); ?>"><?php echo esc_html__('Abrir plugins de WordPress', 'xabia-intelligence'); ?></a>
                    </div>
                <div class="xabia-addon-subscription-grid" role="list">
                    <?php foreach ($registry as $def) :
                        if (!is_array($def)) {
                            continue;
                        }
                        $slug = sanitize_key((string) ($def['slug'] ?? ''));
                        if ($slug === '') {
                            continue;
                        }
                        $title = (string) ($def['title'] ?? $slug);
                        $desc = (string) ($def['description'] ?? '');
                        $price = (string) ($def['price_label'] ?? '');
                        $lucide = (string) ($def['lucide'] ?? 'package');
                        $polarShop = trim((string) ($def['polar_checkout_url'] ?? Xabia_Addons::POLAR_SHOP_BASE));
                        if ($polarShop === '') {
                            $polarShop = Xabia_Addons::POLAR_SHOP_BASE;
                        }
                        $polarPortal = trim((string) ($def['polar_portal_url'] ?? Xabia_Addons::POLAR_CUSTOMER_PORTAL));
                        if ($polarPortal === '') {
                            $polarPortal = Xabia_Addons::POLAR_CUSTOMER_PORTAL;
                        }
                        $pluginFile = (string) ($def['plugin_file'] ?? '');
                        $cat = $pluginFile !== '' && isset($catalogByFile[$pluginFile]) ? $catalogByFile[$pluginFile] : null;
                        $wpActive = is_array($cat) && !empty($cat['active']);
                        $wpInstalled = is_array($cat) && !empty($cat['installed']);
                        $storedKey = class_exists('Xabia_Addons', false) ? Xabia_Addons::get_stored_license_key($slug) : '';
                        $status = class_exists('Xabia_Addons', false) ? Xabia_Addons::get_hub_status($slug, false) : [
                            'subscription_active'   => false,
                            'license_valid'         => false,
                            'renewal_iso'           => null,
                            'renewal_ts'            => 0,
                            'addon_activated_iso'     => null,
                            'addon_activated_ts'    => 0,
                            'message'               => '',
                            'inactive_reason'       => '',
                        ];
                        $activeSub = !empty($status['subscription_active']);
                        $inactiveReason = (string) ($status['inactive_reason'] ?? '');
                        $inactiveHint = '';
                        if (!$activeSub && $inactiveReason !== '') {
                            $hints = [
                                'addon_not_on_license' => __('Add-on no incluido en el plan', 'xabia-intelligence'),
                                'license_invalid'      => __('Error de validación de licencia', 'xabia-intelligence'),
                                'hub_unreachable'      => __('Sin respuesta del Hub', 'xabia-intelligence'),
                            ];
                            $inactiveHint = $hints[$inactiveReason] ?? '';
                        }
                        $renewalFmt = '';
                        if ($activeSub && !empty($status['renewal_ts'])) {
                            $renewalFmt = wp_date('d/m/Y', (int) $status['renewal_ts']);
                        }
                        $activationFmt = '';
                        if ($activeSub && !empty($status['addon_activated_ts'])) {
                            $activationFmt = wp_date('d/m/Y', (int) $status['addon_activated_ts']);
                        }
                        $polarPrimaryHref = class_exists('Xabia_Addons', false)
                            ? Xabia_Addons::polar_checkout_url_with_site_context($polarShop)
                            : $polarShop;
                        $polarPrimaryLabel = __('Contratar suscripción', 'xabia-intelligence');
                        if ($activeSub) {
                            $polarPrimaryHref = $polarPortal;
                            $polarPrimaryLabel = __('Gestionar en Polar', 'xabia-intelligence');
                        }
                        $submitLabel = $storedKey !== ''
                            ? __('Actualizar suscripción', 'xabia-intelligence')
                            : __('Activar', 'xabia-intelligence');
                        $renewHint = class_exists('Xabia_Addons', false) ? Xabia_Addons::renewal_hint($slug) : ['expiring_soon' => false, 'days_left' => null, 'urgent' => false];
                        $hubUpdateSlug = class_exists('Xabia_Addons', false) ? Xabia_Addons::hub_update_slug($slug) : '';
                        $pluginUpdateStatus = ($hubUpdateSlug !== '' && class_exists('Xabia_Addon_Updater', false))
                            ? Xabia_Addon_Updater::get_ui_status($hubUpdateSlug, $pluginFile)
                            : null;
                        $hide_addon_license = $activeSub && !empty($status['validated_with_core_fallback']);
                        $cardMods = ['xabia-card', 'xabia-addon-sub-card'];
                        if ($activeSub) {
                            $cardMods[] = 'xabia-addon-sub-card--active';
                        } else {
                            $cardMods[] = 'xabia-addon-sub-card--inactive';
                        }
                        ?>
                        <article class="<?php echo esc_attr(implode(' ', $cardMods)); ?>" role="listitem">
                            <?php if ($activeSub && !empty($renewHint['expiring_soon']) && isset($renewHint['days_left']) && (int) $renewHint['days_left'] >= 0) : ?>
                                <div class="xabia-addon-sub-card__renewal-banner<?php echo !empty($renewHint['urgent']) ? ' xabia-addon-sub-card__renewal-banner--urgent' : ''; ?>" role="status">
                                    <?php
                                    if (!empty($renewHint['urgent'])) {
                                        printf(
                                            /* translators: 1: number of days until the add-on subscription ends */
                                            esc_html__('Quedan %1$d días para el vencimiento del add-on. Renueva en Polar para no perder las funciones premium del chat.', 'xabia-intelligence'),
                                            (int) $renewHint['days_left']
                                        );
                                    } else {
                                        printf(
                                            /* translators: %d: days until renewal */
                                            esc_html__('La renovación está cerca (%d días). Puedes revisar el cargo en el portal de Polar.', 'xabia-intelligence'),
                                            (int) $renewHint['days_left']
                                        );
                                    }
                                    ?>
                                </div>
                            <?php endif; ?>
                            <?php
                            if ($wpInstalled && is_array($pluginUpdateStatus) && class_exists('Xabia_Addon_Updater', false)) {
                                Xabia_Addon_Updater::render_card_update_banner($pluginUpdateStatus);
                            }
                            ?>
                            <div class="xabia-addon-sub-card__top">
                                <div class="xabia-addon-sub-card__icon" aria-hidden="true"><?php self::render_addon_lucide_icon($lucide); ?></div>
                                <div class="xabia-addon-sub-card__headlines">
                                    <h2 class="xabia-addon-sub-card__title"><?php echo esc_html($title); ?></h2>
                                    <span class="xabia-addon-status-badge xabia-addon-status-badge--<?php echo $activeSub ? 'active' : 'inactive'; ?>" role="status" title="<?php echo esc_attr__('Suscripción del add-on según Xabia Hub / Polar (no es el estado del plugin en WordPress).', 'xabia-intelligence'); ?>">
                                        <?php if (!$activeSub) : ?>
                                            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                                        <?php endif; ?>
                                        <span class="xabia-addon-status-badge__label"><?php echo $activeSub ? esc_html__('Hub Polar: activa', 'xabia-intelligence') : esc_html__('Hub Polar: inactiva', 'xabia-intelligence'); ?></span>
                                    </span>
                                    <?php if ($price !== '') : ?>
                                        <p class="xabia-addon-sub-card__price"><?php echo esc_html($price); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <p class="xabia-addon-sub-card__desc"><?php echo esc_html($desc); ?></p>
                            <?php if (!$activeSub && $wpActive) : ?>
                                <p class="xabia-addon-sub-card__consistency-hint" role="note">
                                    <?php echo esc_html__('El plugin está activo en WordPress; «Hub Polar: inactiva» significa que esta licencia aún no incluye el add-on en el Hub o no se ha sincronizado. Usa «Sincronizar licencia» o revisa tu suscripción en Polar.', 'xabia-intelligence'); ?>
                                </p>
                            <?php endif; ?>
                            <?php if ($pluginFile !== '') : ?>
                                <p class="xabia-addon-sub-card__wp">
                                    <?php
                                    echo '<span class="xabia-addon-sub-card__wp-prefix">' . esc_html__('Estado en WordPress:', 'xabia-intelligence') . '</span> ';
                                    if ($wpActive) {
                                        echo '<span class="xabia-addon-sub-card__wp-state xabia-addon-sub-card__wp-state--ok">' . esc_html__('activo', 'xabia-intelligence') . '</span>';
                                    } elseif ($wpInstalled) {
                                        echo '<span class="xabia-addon-sub-card__wp-state">' . esc_html__('instalado (inactivo)', 'xabia-intelligence') . '</span>';
                                    } else {
                                        echo '<span class="xabia-addon-sub-card__wp-state xabia-addon-sub-card__wp-state--no">' . esc_html__('no instalado', 'xabia-intelligence') . '</span>';
                                    }
                                    ?>
                                    <span class="xabia-addon-sub-card__wp-file"><code><?php echo esc_html($pluginFile); ?></code></span>
                                </p>
                            <?php endif; ?>
                            <form method="post" class="xabia-addon-sub-card__form" action="">
                                <?php wp_nonce_field('xabia_addon_license_save'); ?>
                                <input type="hidden" name="xabia_addon_license_save" value="1" />
                                <input type="hidden" name="xabia_addon_slug" value="<?php echo esc_attr($slug); ?>" />
                                <div class="xabia-addon-sub-card__panel">
                                <?php if (!$hide_addon_license) : ?>
                                <label class="xabia-addon-sub-card__label" for="xabia-license-<?php echo esc_attr($slug); ?>"><?php echo esc_html__('Clave de licencia', 'xabia-intelligence'); ?></label>
                                <input
                                    type="text"
                                    id="xabia-license-<?php echo esc_attr($slug); ?>"
                                    name="xabia_addon_license_key"
                                    class="xabia-addon-sub-card__input"
                                    value="<?php echo esc_attr($storedKey); ?>"
                                    autocomplete="off"
                                    placeholder="<?php echo esc_attr__('Misma licencia que en Conexión a la IA (la que muestra Polar)', 'xabia-intelligence'); ?>"
                                />
                                <p class="xabia-addon-sub-card__field-hint">
                                    <?php echo esc_html__('El hub activa el add-on sobre la misma licencia del sitio que en Conexión a la IA. Debe coincidir carácter a carácter con la que ves en Polar.', 'xabia-intelligence'); ?>
                                </p>
                                <?php else : ?>
                                <p class="xabia-addon-sub-card__inherited"><?php echo esc_html__('Suscripción activa con la licencia principal del sitio.', 'xabia-intelligence'); ?></p>
                                <?php endif; ?>
                                <div class="xabia-addon-sub-card__status <?php echo $activeSub ? 'xabia-addon-sub-card__status--ok' : 'xabia-addon-sub-card__status--bad'; ?>" role="status">
                                    <?php if ($activeSub) : ?>
                                        <span class="xabia-addon-sub-card__dot xabia-addon-sub-card__dot--ok"></span>
                                        <?php
                                        if ($activationFmt !== '' && $renewalFmt !== '') {
                                            printf(
                                                /* translators: 1: activation date, 2: renewal/expiry date */
                                                esc_html__('Suscripción activa (alta: %1$s · vence: %2$s)', 'xabia-intelligence'),
                                                esc_html($activationFmt),
                                                esc_html($renewalFmt)
                                            );
                                        } elseif ($renewalFmt !== '') {
                                            printf(
                                                /* translators: %s: renewal date */
                                                esc_html__('Suscripción activa (próxima renovación: %s)', 'xabia-intelligence'),
                                                esc_html($renewalFmt)
                                            );
                                        } elseif ($activationFmt !== '') {
                                            printf(
                                                /* translators: %s: activation date */
                                                esc_html__('Suscripción activa (alta: %s)', 'xabia-intelligence'),
                                                esc_html($activationFmt)
                                            );
                                        } else {
                                            echo esc_html__('Suscripción activa', 'xabia-intelligence');
                                        }
                                        ?>
                                    <?php else : ?>
                                        <span class="xabia-addon-sub-card__dot xabia-addon-sub-card__dot--bad"></span>
                                        <?php
                                        if ($inactiveHint !== '') {
                                            echo '<span class="xabia-addon-sub-card__reason">' . esc_html($inactiveHint) . '</span> ';
                                        }
                                        $hubMsg = isset($status['message']) ? trim((string) $status['message']) : '';
                                        $defaultInactive = __('Suscripción no encontrada o caducada para este addon.', 'xabia-intelligence');
                                        if ($hubMsg !== '' && $hubMsg !== $defaultInactive) {
                                            echo esc_html($hubMsg);
                                        } else {
                                            echo esc_html__('Suscripción expirada o no encontrada para esta clave.', 'xabia-intelligence');
                                        }
                                        ?>
                                    <?php endif; ?>
                                </div>
                                <div class="xabia-addon-sub-card__actions">
                                    <button type="button" class="xabia-btn-polar xabia-btn-polar--ghost xabia-addon-sync-license" data-slug="<?php echo esc_attr($slug); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('xabia_admin_nonce')); ?>">
                                        <?php echo esc_html__('Sincronizar licencia', 'xabia-intelligence'); ?>
                                    </button>
                                    <?php if (!$hide_addon_license) : ?>
                                    <button type="submit" class="xabia-btn-polar xabia-btn-polar--primary"><?php echo esc_html($submitLabel); ?></button>
                                    <?php endif; ?>
                                    <a class="xabia-btn-polar xabia-btn-polar--secondary" href="<?php echo esc_url($polarPrimaryHref); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($polarPrimaryLabel); ?></a>
                                    <a class="xabia-addon-sub-card__portal-link" href="<?php echo esc_url($polarPortal); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Portal de cliente Polar (facturas y recibos)', 'xabia-intelligence'); ?></a>
                                </div>
                                </div>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
                </div>
            <?php else : ?>
                <div class="xabia-card">
                    <p class="xabia-card-desc" style="margin-bottom:0;"><?php echo esc_html__('No hay add-ons de suscripción registrados (filtro xabia_addons_registry).', 'xabia-intelligence'); ?></p>
                </div>
            <?php endif; ?>

            <div class="xabia-card xabia-addons-catalog-panel">
                <h2 class="xabia-card-title"><?php echo esc_html__('Addons disponibles', 'xabia-intelligence'); ?></h2>
                <p class="xabia-card-desc xabia-card-desc--flush-bottom"><?php echo esc_html__('Estado de cada extensión en este sitio: si el plugin está instalado y activo en WordPress y, cuando aplica, si el hub tiene la suscripción Polar activa.', 'xabia-intelligence'); ?></p>
                <div class="xabia-addons-panel-toolbar xabia-addons-panel-toolbar--catalog">
                    <a class="xabia-btn-addons-cta xabia-btn-addons-cta--link" href="<?php echo esc_url(admin_url('plugins.php')); ?>"><?php echo esc_html__('Gestionar plugins instalados', 'xabia-intelligence'); ?></a>
                    <a class="xabia-btn-addons-secondary" href="<?php echo esc_url(admin_url('admin.php?page=xabia-settings')); ?>"><?php echo esc_html__('Conexión a la IA y licencia Core', 'xabia-intelligence'); ?></a>
                </div>
            <?php if ($catalog === []) : ?>
                    <p class="xabia-addons-catalog-empty"><?php echo esc_html__('No hay addons en el catálogo.', 'xabia-intelligence'); ?></p>
            <?php else : ?>
                <div class="xabia-addons-catalog-grid">
                    <?php foreach ($catalog as $addon) :
                        if (!is_array($addon)) {
                            continue;
                        }
                        $pf = (string) ($addon['plugin_file'] ?? '');
                        $lab = (string) ($addon['label'] ?? $pf);
                        $active = !empty($addon['active']);
                        $installed = !empty($addon['installed']);
                        $st = $active
                            ? __('Plugin activo', 'xabia-intelligence')
                            : ($installed ? __('Plugin instalado (inactivo)', 'xabia-intelligence') : __('Plugin no instalado', 'xabia-intelligence'));
                        $catCardMods = ['xabia-card', 'xabia-addon-catalog-card'];
                        if ($active) {
                            $catCardMods[] = 'xabia-addon-catalog-card--active';
                        } elseif ($installed) {
                            $catCardMods[] = 'xabia-addon-catalog-card--installed';
                        } else {
                            $catCardMods[] = 'xabia-addon-catalog-card--available';
                        }
                        $hubCatSlug = self::hub_registry_slug_for_plugin_file($pf);
                        $hubCatActive = false;
                        if ($hubCatSlug !== '' && class_exists('Xabia_Addons', false)) {
                            $hubCatSt = Xabia_Addons::get_hub_status($hubCatSlug, false);
                            $hubCatActive = !empty($hubCatSt['subscription_active']);
                        }
                        $catHubUpdateSlug = ($hubCatSlug !== '' && class_exists('Xabia_Addons', false))
                            ? Xabia_Addons::hub_update_slug($hubCatSlug)
                            : '';
                        $catPluginUpdate = ($installed && $catHubUpdateSlug !== '' && class_exists('Xabia_Addon_Updater', false))
                            ? Xabia_Addon_Updater::get_ui_status($catHubUpdateSlug, $pf)
                            : null;
                        ?>
                        <div class="<?php echo esc_attr(implode(' ', $catCardMods)); ?>">
                            <div class="xabia-addon-catalog-card__head">
                                <strong class="xabia-addon-catalog-card__title"><?php echo esc_html($lab); ?></strong>
                            </div>
                            <p class="xabia-addon-catalog-card__desc"><?php echo esc_html((string) ($addon['description'] ?? '')); ?></p>
                            <div class="xabia-addon-catalog-card__status-block">
                                <span class="xabia-addon-catalog-card__state"><?php echo esc_html($st); ?></span>
                                <?php if ($hubCatSlug !== '') : ?>
                                    <div class="xabia-addon-catalog-card__hub-row">
                                        <span class="xabia-addon-catalog-card__hub-key"><?php echo esc_html__('Suscripción Polar / Hub:', 'xabia-intelligence'); ?></span>
                                        <span class="xabia-addon-catalog-card__pill xabia-addon-catalog-card__pill--<?php echo $hubCatActive ? 'ok' : 'bad'; ?>"><?php echo $hubCatActive ? esc_html__('Activa', 'xabia-intelligence') : esc_html__('Inactiva', 'xabia-intelligence'); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (is_array($catPluginUpdate) && !empty($catPluginUpdate['update_available'])) : ?>
                                    <p class="xabia-addon-catalog-card__update-hint" style="margin:8px 0 0;color:#7a4e00;font-size:12px;font-weight:600;">
                                        <?php
                                        printf(
                                            esc_html__('Actualización disponible: %1$s (instalada %2$s).', 'xabia-intelligence'),
                                            esc_html((string) ($catPluginUpdate['remote_version'] ?? '')),
                                            esc_html((string) ($catPluginUpdate['installed'] ?? ''))
                                        );
                                        ?>
                                        <a href="<?php echo esc_url(admin_url('plugins.php')); ?>"><?php echo esc_html__('Actualizar', 'xabia-intelligence'); ?></a>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <code class="xabia-addon-catalog-card__file"><?php echo esc_html($pf); ?></code>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </div>
        </div>
        <script>
        (function($){
            function runSyncQueue($buttons, i, $mainBtn) {
                if (i >= $buttons.length) {
                    if ($mainBtn && $mainBtn.length) {
                        $mainBtn.prop('disabled', false);
                    }
                    window.location.reload();
                    return;
                }
                var $b = $buttons.eq(i);
                $b.prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'xabia_addon_sync_license',
                    nonce: $b.data('nonce'),
                    slug: $b.data('slug')
                }).done(function(r) {
                    $b.prop('disabled', false);
                    if (r && r.success) {
                        runSyncQueue($buttons, i + 1, $mainBtn);
                    } else {
                        if ($mainBtn && $mainBtn.length) {
                            $mainBtn.prop('disabled', false);
                        }
                        var m = (r && r.data && r.data.message) ? r.data.message : '<?php echo esc_js(__('Error al sincronizar.', 'xabia-intelligence')); ?>';
                        window.alert(m);
                    }
                }).fail(function() {
                    $b.prop('disabled', false);
                    if ($mainBtn && $mainBtn.length) {
                        $mainBtn.prop('disabled', false);
                    }
                    window.alert('<?php echo esc_js(__('Error de red.', 'xabia-intelligence')); ?>');
                });
            }
            $(document).on('click', '#xabia-hub-sync-all-addons', function() {
                var $all = $('.xabia-addon-sync-license');
                if (!$all.length) {
                    return;
                }
                var $btn = $(this);
                $btn.prop('disabled', true);
                runSyncQueue($all, 0, $btn);
            });
            $(document).on('click', '.xabia-addon-sync-license', function(){
                var $b = $(this);
                $b.prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'xabia_addon_sync_license',
                    nonce: $b.data('nonce'),
                    slug: $b.data('slug')
                }).done(function(r){
                    $b.prop('disabled', false);
                    if (r && r.success) {
                        window.location.reload();
                        return;
                    }
                    var m = (r && r.data && r.data.message) ? r.data.message : '<?php echo esc_js(__('Error al sincronizar.', 'xabia-intelligence')); ?>';
                    window.alert(m);
                }).fail(function(){
                    $b.prop('disabled', false);
                    window.alert('<?php echo esc_js(__('Error de red.', 'xabia-intelligence')); ?>');
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Registro de Opciones de Configuración Global
     */
    public function register_options() { 
        register_setting('xabia_settings_group', 'xabia_openai_key');
        register_setting('xabia_settings_group', 'xabia_google_key');
        register_setting('xabia_settings_group', 'xabia_gcloud_json_path');
        register_setting('xabia_settings_group', 'xabia_digixop_license_key');
        register_setting('xabia_settings_group', 'xabia_connection_mode');
    }

    /**
     * Carga de Estilos y Scripts con Diseño Nobel
     */
    public function load_assets($hook) {
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        $xabia_admin_hooks = [
            'toplevel_page_xabia-settings',
            'xabia-settings_page_xabia-addons',
            'xabia-settings_page_xabia-wallet',
            'xabia-settings_page_xabia-central',
        ];
        $xabia_pages = ['xabia-settings', 'xabia-addons', 'xabia-wallet', 'xabia-central'];
        $screen_ok = in_array($hook, $xabia_admin_hooks, true) || in_array($page, $xabia_pages, true);
        if (!$screen_ok) {
            return;
        }

        wp_enqueue_style('dashicons');
        wp_enqueue_style('wp-color-picker');
        $css_abs = XABIA_PATH . 'admin/css/xabia-admin.css';
        $css_ver = (defined('XABIA_VERSION') ? XABIA_VERSION : '1.0');
        if (is_readable($css_abs)) {
            $css_ver .= '.' . (string) filemtime($css_abs);
        }
        wp_enqueue_style(
            'xabia-admin',
            XABIA_URL . 'admin/css/xabia-admin.css',
            ['wp-color-picker'],
            $css_ver
        );
        // Asegura badges Hub visibles aunque el navegador o CDN sirvan CSS antiguo en caché.
        wp_add_inline_style(
            'xabia-admin',
            '.xabia-wrapper.xabia-admin-app .xabia-addon-catalog-mini{position:relative;padding-top:40px!important;}'
            . '.xabia-wrapper.xabia-admin-app .xabia-addon-catalog-mini .xabia-addon-status-badge{position:absolute;top:10px;right:10px;z-index:3;display:inline-flex;align-items:center;gap:5px;'
            . 'padding:5px 11px;border-radius:999px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;box-shadow:0 1px 3px rgba(0,0,0,.08);}'
            . '.xabia-wrapper.xabia-admin-app .xabia-addon-catalog-mini .xabia-addon-status-badge--active{background:#d1fae5;color:#059669;border:1px solid #6ee7b7;}'
            . '.xabia-wrapper.xabia-admin-app .xabia-addon-catalog-mini .xabia-addon-status-badge--inactive{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;}'
            . '.xabia-wrapper.xabia-admin-app .xabia-addon-catalog-mini .xabia-addon-status-badge .dashicons{font-size:14px;width:14px;height:14px;line-height:1;}'
            . '.xabia-wrapper.xabia-admin-app .xabia-agent-tile--paused{opacity:.72;}'
            . '.xabia-wrapper.xabia-admin-app .xabia-agent-paused-badge{display:inline-block;margin-left:8px;padding:2px 8px;font-size:11px;font-weight:600;border-radius:999px;background:#fef3c7;color:#92400e;}'
            . '.xabia-wrapper.xabia-admin-app .xabia-btn--pause{border-color:#d97706;color:#b45309;}'
        );
        wp_enqueue_style(
            'xabia-plus-jakarta',
            'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap',
            [],
            null
        );
        wp_enqueue_script('wp-color-picker');
        if ($page === 'xabia-settings') {
            wp_enqueue_media();
            $js_abs = XABIA_PATH . 'admin/js/xabia-visual-rows.js';
            $js_ver = defined('XABIA_VERSION') ? XABIA_VERSION : '1.0';
            if (is_readable($js_abs)) {
                $js_ver .= '.' . (string) filemtime($js_abs);
                wp_enqueue_script(
                    'xabia-visual-rows',
                    XABIA_URL . 'admin/js/xabia-visual-rows.js',
                    [],
                    $js_ver,
                    true
                );
                wp_localize_script('xabia-visual-rows', 'xabiaVisualRows', [
                    'nonce'   => wp_create_nonce('xabia_admin_nonce'),
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                ]);
            }
        }
    }

    /**
     * Guarda claves de licencia de addons (nonce + manage_options).
     */
    public function controller_handle_addon_licenses(): void {
        if (!isset($_POST['xabia_addon_license_save']) || !current_user_can('manage_options')) {
            return;
        }
        check_admin_referer('xabia_addon_license_save');
        $slug = sanitize_key((string) wp_unslash($_POST['xabia_addon_slug'] ?? ''));
        if ($slug === '' || !class_exists('Xabia_Addons', false) || !Xabia_Addons::is_registered_slug($slug)) {
            return;
        }
        $rawKey = isset($_POST['xabia_addon_license_key']) ? (string) wp_unslash($_POST['xabia_addon_license_key']) : '';
        $key = sanitize_text_field(trim($rawKey));
        update_option(Xabia_Addons::option_name($slug), $key, false);
        Xabia_Addons::flush_status_cache($slug);
        $st = Xabia_Addons::get_hub_status($slug, true);
        do_action('xabia_addon_hub_status_refreshed', $slug, $st);
        $redirect = add_query_arg(
            ['page' => 'xabia-addons', 'xabia_addon_saved' => '1', 'addon' => $slug],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    

    public function controller_handle_post() {
        if (!isset($_POST['xabia_action']) || !current_user_can('manage_options')) return;
        
        $projects = get_option('xabia_projects_config', []);
        $post = wp_unslash($_POST); 

        
        if ($post['xabia_action'] === 'save_project') {
            
            $raw_id = sanitize_key($post['project_id']);
            if ($raw_id === 'new' || $raw_id === '') {
                $id = sanitize_title($post['name']) ?: 'proj_' . time();
            } else {
                $id = $raw_id;
            }
            
            $attributes = [];
            if (!empty($post['attributes']) && is_array($post['attributes'])) {
                foreach ($post['attributes'] as $attr) {
                    if (!empty($attr['csv_col'])) {
                        $is_ente = isset($attr['is_ente']) ? 1 : 0;
                        $attributes[] = [
                            'csv_col'           => sanitize_text_field($attr['csv_col']),
                            'label'             => sanitize_text_field($attr['label']),
                            'instruction'       => sanitize_textarea_field($attr['instruction']),
                            'is_ente'           => $is_ente,
                            'visual_role'       => sanitize_text_field($attr['visual_role'] ?? 'none'),
                            'ente_label_col'    => $is_ente ? sanitize_text_field($attr['ente_label_col'] ?? '') : '',
                            'import_rag'        => isset($attr['import_rag']) ? 1 : 0,
                        ];
                    }
                }
            }
            $source_type_pre = sanitize_key($post['source_type'] ?? 'csv');
            if ($source_type_pre === 'addon' && sanitize_key($post['addon_slug'] ?? '') === 'mec' && class_exists('Xabia_MEC_Connector', false) && self::xabia_attributes_need_mec_defaults($attributes)) {
                $attributes = self::xabia_mec_remote_default_mapping_fields();
            }
            $sql_preset_pre = sanitize_key($post['sql_preset'] ?? '');
            if ($source_type_pre === 'sql' && $sql_preset_pre === 'mec_remote' && self::xabia_attributes_need_mec_defaults($attributes)) {
                $attributes = self::xabia_mec_remote_default_mapping_fields();
            }

            $csv_filename = '';
            $existing_project = $projects[$id] ?? null;
            if (!empty($post['selected_csv_file'])) {
                $csv_filename = sanitize_file_name($post['selected_csv_file']);
            } elseif (!empty($existing_project['csv_filename'])) {
                $csv_filename = sanitize_file_name($existing_project['csv_filename']);
            }

            
            $sources = [];
            $source_type = sanitize_key($post['source_type'] ?? 'csv');
            if (!in_array($source_type, ['csv', 'addon', 'multi', 'local_sql', 'sql', 'web_pages'], true)) {
                $source_type = 'csv';
            }
            if ($source_type === 'web_pages' && class_exists('Xabia_Web_Pages_Source', false) && $attributes === []) {
                $attributes = Xabia_Web_Pages_Source::default_mapping_fields();
            }
            if ($source_type === 'multi' && !empty($post['sources']) && is_array($post['sources'])) {
                foreach ($post['sources'] as $idx => $src) {
                    $st = sanitize_key($src['type'] ?? '');
                    if (!in_array($st, ['csv', 'sql', 'local_sql', 'web_pages'], true)) continue;
                    $attrs = [];
                    if (!empty($src['attributes']) && is_array($src['attributes'])) {
                        foreach ($src['attributes'] as $attr) {
                            if (!empty($attr['csv_col'])) {
                                $is_ente = isset($attr['is_ente']) ? 1 : 0;
                                $attrs[] = [
                                    'csv_col'           => sanitize_text_field($attr['csv_col']),
                                    'label'             => sanitize_text_field($attr['label']),
                                    'instruction'       => sanitize_textarea_field($attr['instruction'] ?? ''),
                                    'is_ente'           => $is_ente,
                                    'visual_role'       => sanitize_text_field($attr['visual_role'] ?? 'none'),
                                    'ente_label_col'    => $is_ente ? sanitize_text_field($attr['ente_label_col'] ?? '') : '',
                                    'import_rag'        => isset($attr['import_rag']) ? 1 : 0,
                                ];
                            }
                        }
                    }
                    $entry = ['type' => $st, 'attributes' => $attrs];
                    if ($st === 'csv') {
                        $entry['csv_filename'] = sanitize_file_name($src['csv_filename'] ?? '');
                    } elseif ($st === 'web_pages') {
                        $entry['web_page_ids'] = self::parse_web_page_ids_from_post($post, (int) $idx);
                        $entry['web_pages_use_public_html'] = !empty($src['web_pages_use_public_html']) ? 1 : 0;
                        if ($attrs === [] && class_exists('Xabia_Web_Pages_Source', false)) {
                            $entry['attributes'] = Xabia_Web_Pages_Source::default_mapping_fields();
                        }
                    } else {
                        $entry['sql_config'] = [
                            'host'  => sanitize_text_field($src['sql_host'] ?? ''),
                            'user'  => sanitize_text_field($src['sql_user'] ?? ''),
                            'name'  => sanitize_text_field($src['sql_name'] ?? ''),
                            'pass'  => sanitize_text_field($src['sql_pass'] ?? ''),
                            'query' => stripslashes($src['sql_query'] ?? ''),
                            'prefix'=> sanitize_text_field($src['sql_prefix'] ?? ''),
                        ];
                    }
                    $sources[] = $entry;
                }
            }

            if ($source_type === 'multi' && $sources !== []) {
                $primary_attrs = $sources[0]['attributes'] ?? [];
                if (is_array($primary_attrs) && $primary_attrs !== []) {
                    $attributes = $primary_attrs;
                }
            } else {
                $attributes = array_values($attributes);
            }

            $incoming_openai = isset($post['openai_api_key']) ? trim((string) $post['openai_api_key']) : '';
            if ($incoming_openai !== '') {
                $openai_api_key_store = sanitize_text_field($incoming_openai);
            } elseif ($existing_project !== null && array_key_exists('openai_api_key', $existing_project)) {
                $openai_api_key_store = (string) $existing_project['openai_api_key'];
            } else {
                $openai_api_key_store = '';
            }
            $existing_totem = (is_array($existing_project ?? null) && isset($existing_project['totem']) && is_array($existing_project['totem']))
                ? $existing_project['totem']
                : ['enabled' => 0, 'tiempo_inactividad_defecto' => 0];
            $totem_enabled = array_key_exists('modo_totem', $post)
                ? (!empty($post['modo_totem']) ? 1 : 0)
                : (int) ($existing_totem['enabled'] ?? 0);
            $totem_timeout = array_key_exists('tiempo_inactividad_defecto', $post)
                ? absint($post['tiempo_inactividad_defecto'] ?? 0)
                : absint($existing_totem['tiempo_inactividad_defecto'] ?? 0);

            $auto_sync_cfg = self::build_auto_sync_config_from_post($post, $source_type, $sources, $existing_project);

            $keyword_expansions_parsed = self::sanitize_keyword_expansions_from_post($post['keyword_exp_rows'] ?? null);
            $knowledge_relations_parsed = self::sanitize_knowledge_relations_from_post($post['knowledge_rel_rows'] ?? null);

            $rules = [
                    'instructions' => sanitize_textarea_field($post['instructions']),
                    'min_score'    => isset($post['min_score']) ? floatval($post['min_score']) : 0.2,
                    'max_output_tokens' => (isset($post['max_output_tokens']) && $post['max_output_tokens'] !== '')
                        ? max(1200, min(3000, absint($post['max_output_tokens'])))
                        : 1200,
                    'daily_token_limit' => (isset($post['daily_token_limit']) && $post['daily_token_limit'] !== '')
                        ? max(0, absint($post['daily_token_limit']))
                        : 20000,
                    'greeting'     => wp_kses_post($post['greeting']),
                    'starter_questions_enabled' => !empty($post['starter_questions_enabled']) ? 1 : 0,
                    'starter_questions' => class_exists('Xabia_Starter_Questions', false)
                        ? Xabia_Starter_Questions::normalize_manual_list($post['starter_questions'] ?? '')
                        : [],
                    'rag_behavior_preset' => in_array(sanitize_key($post['rag_behavior_preset'] ?? ''), ['neutral', 'compact', 'custom'], true)
                        ? sanitize_key($post['rag_behavior_preset'])
                        : 'neutral',
                    'rag_custom_behavior' => sanitize_textarea_field($post['rag_custom_behavior'] ?? ''),
                    'context_source_description' => sanitize_textarea_field($post['context_source_description'] ?? ''),
                    'max_chunks_context' => isset($post['max_chunks_context']) && $post['max_chunks_context'] !== ''
                        ? max(1, min(15, absint($post['max_chunks_context'])))
                        : (isset($post['context_chunk_limit']) && $post['context_chunk_limit'] !== ''
                            ? max(1, min(15, absint($post['context_chunk_limit'])))
                            : 4),
                    'context_chunk_limit' => isset($post['max_chunks_context']) && $post['max_chunks_context'] !== ''
                        ? max(1, min(15, absint($post['max_chunks_context'])))
                        : (isset($post['context_chunk_limit']) && $post['context_chunk_limit'] !== ''
                            ? max(1, min(15, absint($post['context_chunk_limit'])))
                            : 4),
                    'use_vector_search' => !empty($post['use_vector_search']) ? 1 : 0,
                    'similarity_threshold' => isset($post['similarity_threshold']) ? max(0, min(1, floatval($post['similarity_threshold']))) : 0.2,
                    'woo_remote_shop_url' => esc_url_raw(trim((string) ($post['woo_remote_shop_url'] ?? ''))),
                    'mec_remote_site_url' => esc_url_raw(trim((string) ($post['mec_remote_site_url'] ?? ''))),
                    'mec_events_rewrite_slug' => sanitize_title(trim((string) ($post['mec_events_rewrite_slug'] ?? ''))) ?: 'actividades',
            ];
            if ($keyword_expansions_parsed['value'] !== []) {
                $rules['keyword_expansions'] = $keyword_expansions_parsed['value'];
            }
            if ($knowledge_relations_parsed['value'] !== []) {
                $rules['knowledge_relations'] = $knowledge_relations_parsed['value'];
            }

            $project_language = 'es';
            if (class_exists('Xabia_Knowledge_Ingest', false)) {
                $project_language = Xabia_Knowledge_Ingest::sanitize_project_language_code(
                    (string) ($post['project_language'] ?? 'es')
                );
            } else {
                $pl_raw = strtolower(trim((string) ($post['project_language'] ?? 'es')));
                $project_language = preg_match('/^([a-z]{2})/', $pl_raw, $pl_m) ? $pl_m[1] : 'es';
            }

            $web_page_ids = self::parse_web_page_ids_from_post($post);
            $web_pages_use_public_html = !empty($post['web_pages_use_public_html']) ? 1 : 0;
            if ($source_type === 'web_pages' && $attributes === [] && class_exists('Xabia_Web_Pages_Source', false)) {
                $attributes = Xabia_Web_Pages_Source::default_mapping_fields();
            }

            $projects[$id] = [
                'name'             => sanitize_text_field($post['name']),
                'source_type'      => $source_type,
                'project_language' => $project_language,
                'ai_driver'        => sanitize_key($post['ai_driver'] ?? 'openai'),
                'gcloud_json_path' => sanitize_text_field($post['gcloud_json_path'] ?? ''),
                'openai_api_key'   => $openai_api_key_store,
                'addon_slug'       => sanitize_key($post['addon_slug'] ?? ''),
                'csv_filename'    => $csv_filename, 
                'attributes'       => $attributes,
                'sources'          => $sources, 
                'sql_config'       => [
                    'host' => sanitize_text_field($post['sql_host'] ?? ''),
                    'user' => sanitize_text_field($post['sql_user'] ?? ''),
                    'name' => sanitize_text_field($post['sql_name'] ?? ''),
                    'pass' => sanitize_text_field($post['sql_pass'] ?? ''),
                    'prefix' => sanitize_text_field($post['sql_prefix'] ?? ''),
                    'query'=> stripslashes($post['sql_query'] ?? ''),
                ],
                'sql_preset'       => sanitize_key($post['sql_preset'] ?? ''),
                'rules' => $rules,
                'design' => [
                    'primary_color' => sanitize_hex_color($post['primary_color']),
                    'bg_color'      => sanitize_hex_color($post['bg_color']),
                    'font_size'     => class_exists('Xabia_Agent_Core', false)
                        ? Xabia_Agent_Core::normalize_chat_font_size_em($post['font_size'] ?? '1')
                        : sanitize_text_field($post['font_size'] ?? '1'),
                    'avatar_name'   => sanitize_text_field($post['avatar_name'] ?? '') ?: 'Xabia',
                    'tts_voice'     => in_array($post['tts_voice'] ?? '', ['female', 'male'], true) ? $post['tts_voice'] : 'default',
                    'tts_rate'      => isset($post['tts_rate']) ? max(0.5, min(2, floatval($post['tts_rate']))) : 1,
                    'tts_clean_bold'    => !empty($post['tts_clean_bold']) ? 1 : 0,
                    'tts_clean_italic'  => !empty($post['tts_clean_italic']) ? 1 : 0,
                    'tts_clean_actions' => !empty($post['tts_clean_actions']) ? 1 : 0,
                    'tts_clean_emojis'  => !empty($post['tts_clean_emojis']) ? 1 : 0,
                    'tts_clean_patterns' => isset($post['tts_clean_patterns']) ? array_filter(array_map('trim', explode("\n", $post['tts_clean_patterns']))) : [],
                ],
                'totem' => [
                    'enabled' => $totem_enabled,
                    'tiempo_inactividad_defecto' => $totem_timeout,
                ],
                'smart_qr_landing_page_id' => array_key_exists('smart_qr_landing_page_id', $post)
                    ? absint($post['smart_qr_landing_page_id'])
                    : absint(is_array($existing_project) ? ($existing_project['smart_qr_landing_page_id'] ?? 0) : 0),
                'paused' => is_array($existing_project) && !empty($existing_project['paused']) ? 1 : 0,
                'auto_sync' => $auto_sync_cfg,
                'web_page_ids' => $web_page_ids,
                'web_pages_use_public_html' => $web_pages_use_public_html,
            ];
            if (class_exists('Xabia_Interface', false)) {
                $projects[$id]['interface'] = Xabia_Interface::build_config_from_post($post);
            }
            if (class_exists('Xabia_Knowledge_Ingest', false)) {
                $catalog_pt = Xabia_Knowledge_Ingest::resolve_catalog_post_type($projects[$id]);
                if ($catalog_pt !== '') {
                    $projects[$id]['catalog_post_type'] = $catalog_pt;
                }
                $catalog_tax = Xabia_Knowledge_Ingest::resolve_catalog_activity_taxonomy($projects[$id]);
                if ($catalog_tax !== '') {
                    $projects[$id]['catalog_activity_taxonomy'] = $catalog_tax;
                }
            }
            update_option('xabia_projects_config', $projects);
            if (class_exists('Xabia_I18n', false)) {
                Xabia_I18n::maybe_sync_greeting_via_hub($id, (string) ($projects[$id]['rules']['greeting'] ?? ''));
            }
            if (class_exists('Xabia_Starter_Questions', false)) {
                Xabia_Starter_Questions::bust_project_cache($id);
            }
            self::purge_project_response_cache($id);
            
            
            $redirect_args = [
                'page'  => 'xabia-settings',
                'edit'  => $id,
                'saved' => '1',
            ];
            if ($keyword_expansions_parsed['had_input'] && !$keyword_expansions_parsed['valid']) {
                $redirect_args['rag_expansions_error'] = '1';
            }
            $redirect_url = add_query_arg($redirect_args, admin_url('admin.php'));
            wp_redirect($redirect_url);
            exit;
        }
        
        
        if ($post['xabia_action'] === 'save_global_key') {
            $conn = isset($post['xabia_connection_mode']) ? sanitize_key((string) $post['xabia_connection_mode']) : 'xabia_cloud';
            if (!in_array($conn, ['xabia_cloud', 'own_infra'], true)) {
                $conn = 'xabia_cloud';
            }
            update_option('xabia_connection_mode', $conn);
            update_option('xabia_openai_key', sanitize_text_field($post['xabia_openai_key'] ?? ''));
            update_option('xabia_google_key', sanitize_text_field($post['xabia_google_key'] ?? ''));
            update_option('xabia_gcloud_json_path', sanitize_text_field($post['xabia_gcloud_json_path'] ?? ''));
            if (class_exists('Xabia_Federation_Nexus', false) && $conn === 'own_infra') {
                update_option(Xabia_Federation_Nexus::OPTION_BRIDGE_ONLY, !empty($post['xabia_federation_bridge_only']) ? 1 : 0);
            }
            $license_input_name = 'xabia_digixop_license_key';
            if (!empty($post['xabia_digixop_clear_license'])) {
                delete_option('xabia_digixop_license_key');
                delete_transient(Xabia_Digixop_Client::TRANSIENT_META);
            }
            if (isset($post[$license_input_name])) {
                $license_raw = trim((string) $post[$license_input_name]);
                if ($license_raw !== '' && !self::license_input_looks_like_masked_placeholder($license_raw)) {
                    update_option('xabia_digixop_license_key', sanitize_text_field($license_raw));
                    delete_transient(Xabia_Digixop_Client::TRANSIENT_META);
                }
            }
            wp_redirect(admin_url("admin.php?page=xabia-settings&updated=1")); exit;
        }
    }

    public function controller_handle_list_actions() {
        if (!isset($_GET['xabia_action']) || !current_user_can('manage_options')) return;
        
        $action = sanitize_key($_GET['xabia_action']);
        $id     = sanitize_key($_GET['project_id']);
        $projects = get_option('xabia_projects_config', []);

        if ($action === 'delete') {
            global $wpdb; 
            $wpdb->delete(Xabia_DB::table('knowledge_vectors'), ['project_id' => $id]);
            unset($projects[$id]);
            update_option('xabia_projects_config', $projects);
            wp_redirect(admin_url("admin.php?page=xabia-settings&msg=deleted")); exit;
        }

        if ($action === 'toggle_pause' && isset($projects[$id]) && is_array($projects[$id])) {
            check_admin_referer('xabia_toggle_pause_' . $id);
            $was_paused = (int) ($projects[$id]['paused'] ?? 0) === 1;
            if ($was_paused) {
                unset($projects[$id]['paused']);
            } else {
                $projects[$id]['paused'] = 1;
            }
            update_option('xabia_projects_config', $projects);
            if (class_exists('Xabia_Interface', false)) {
                Xabia_Interface::purge_frontend_caches();
            }
            self::purge_project_response_cache($id);
            $msg = $was_paused ? 'resumed' : 'paused';
            wp_redirect(admin_url('admin.php?page=xabia-settings&msg=' . $msg));
            exit;
        }
    }

    
    private function find_active_prefix($db) {
        if (class_exists('Xabia_Knowledge_Sync', false)) {
            return Xabia_Knowledge_Sync::find_active_prefix($db);
        }
        $tables = $db->get_results("SHOW TABLES LIKE '%posts'", ARRAY_N);
        if (empty($tables)) return false;
        foreach ($tables as $t) {
            $check = $db->get_results("SELECT ID FROM " . $t[0] . " LIMIT 1");
            if (!empty($check)) return substr($t[0], 0, -5); 
        }
        return substr($tables[0][0], 0, -5);
    }

    /**
     * @param array<string, mixed> $post
     * @param list<array<string, mixed>> $sources
     * @param array<string, mixed>|null $existing_project
     * @return array{enabled:int,interval:string,auto_train:int,auto_cloud:int}
     */
    private static function build_auto_sync_config_from_post(array $post, string $source_type, array $sources, ?array $existing_project): array {
        if (!class_exists('Xabia_Auto_Sync', false)) {
            return ['enabled' => 1, 'interval' => '1hour', 'auto_train' => 1, 'auto_cloud' => 0];
        }

        $temp_cfg = [
            'source_type' => $source_type,
            'addon_slug'  => sanitize_key((string) ($post['addon_slug'] ?? '')),
            'sql_config'  => [
                'host' => sanitize_text_field((string) ($post['sql_host'] ?? '')),
            ],
            'sources'     => $sources,
            'rules'       => [
                'use_vector_search' => !empty($post['use_vector_search']) ? 1 : 0,
            ],
        ];

        if (!array_key_exists('auto_sync_interval', $post) && is_array($existing_project) && isset($existing_project['auto_sync']) && is_array($existing_project['auto_sync'])) {
            $existing = $existing_project['auto_sync'];

            return [
                'enabled'    => !empty($existing['enabled']) ? 1 : 0,
                'interval'   => Xabia_Auto_Sync::sanitize_interval_for_config(
                    (string) ($existing['interval'] ?? ''),
                    $temp_cfg
                ),
                'auto_train' => array_key_exists('auto_train', $existing)
                    ? (!empty($existing['auto_train']) ? 1 : 0)
                    : Xabia_Auto_Sync::default_auto_train($temp_cfg),
                'auto_cloud' => array_key_exists('auto_cloud', $existing)
                    ? (!empty($existing['auto_cloud']) ? 1 : 0)
                    : Xabia_Auto_Sync::default_auto_cloud($temp_cfg),
            ];
        }

        $enabled = !empty($post['auto_sync_enabled']);
        $interval = sanitize_key((string) ($post['auto_sync_interval'] ?? ''));
        $defaults = Xabia_Auto_Sync::default_auto_sync_config($temp_cfg);
        if (!$enabled) {
            return [
                'enabled'    => 0,
                'interval'   => 'off',
                'auto_train' => !empty($post['auto_sync_auto_train']) ? 1 : 0,
                'auto_cloud' => !empty($post['auto_sync_auto_cloud']) ? 1 : 0,
            ];
        }
        if ($interval === '') {
            $interval = (string) $defaults['interval'];
        }
        $interval = Xabia_Auto_Sync::sanitize_interval_for_config($interval, $temp_cfg);

        return [
            'enabled'    => 1,
            'interval'   => $interval,
            'auto_train' => array_key_exists('auto_sync_auto_train', $post)
                ? (!empty($post['auto_sync_auto_train']) ? 1 : 0)
                : (int) $defaults['auto_train'],
            'auto_cloud' => array_key_exists('auto_sync_auto_cloud', $post)
                ? (!empty($post['auto_sync_auto_cloud']) ? 1 : 0)
                : (int) $defaults['auto_cloud'],
        ];
    }

    

    /**
     * Respuesta JSON de éxito con nonce rotado para la siguiente petición admin.
     *
     * @param array<string, mixed> $data
     */
    private function admin_json_success(array $data): void {
        $data['nonce'] = wp_create_nonce('xabia_admin_nonce');
        wp_send_json_success($data);
    }

    private function get_csv_base_dir(): string {
        $uploads = wp_upload_dir();
        return rtrim((string) ($uploads['basedir'] ?? ''), '/') . '/xabia';
    }

    private function get_project_csv_dir(string $project_id): string {
        return $this->get_csv_base_dir() . '/' . sanitize_key($project_id);
    }

    private function get_project_csv_files(string $project_id): array {
        $dir = $this->get_project_csv_dir($project_id);
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.csv');
        return is_array($files) ? $files : [];
    }

    private function resolve_project_csv_path(string $project_id, string $csv_filename = ''): string {
        $project_id = sanitize_key($project_id);
        $csv_filename = sanitize_file_name($csv_filename);
        if ($project_id === '') {
            return '';
        }
        if ($csv_filename !== '') {
            $exact = $this->get_project_csv_dir($project_id) . '/' . $csv_filename;
            if (file_exists($exact)) {
                return $exact;
            }
        }
        $files = $this->get_project_csv_files($project_id);
        if ($files !== []) {
            return (string) $files[0];
        }
        return '';
    }

    public function ajax_upload_csv() {
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permiso denegado.', 'xabia-intelligence')]);
            return;
        }
        $project_id = sanitize_key(wp_unslash($_POST['project_id'] ?? ''));
        if ($project_id === '') {
            wp_send_json_error(['message' => __('Project ID inválido.', 'xabia-intelligence')]);
            return;
        }
        if (empty($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
            wp_send_json_error(['message' => __('No se recibió archivo CSV.', 'xabia-intelligence')]);
            return;
        }
        $file = $_FILES['csv_file'];
        $original_name = sanitize_file_name((string) ($file['name'] ?? ''));
        if (strtolower((string) pathinfo($original_name, PATHINFO_EXTENSION)) !== 'csv') {
            wp_send_json_error(['message' => __('Solo se permiten archivos .csv', 'xabia-intelligence')]);
            return;
        }
        $project_dir = $this->get_project_csv_dir($project_id);
        if (!is_dir($project_dir) && !wp_mkdir_p($project_dir)) {
            wp_send_json_error(['message' => __('No se pudo crear la carpeta del proyecto.', 'xabia-intelligence')]);
            return;
        }
        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $upload_dir_filter = static function ($dirs) use ($project_id) {
            if (!is_array($dirs)) {
                return $dirs;
            }
            $subdir = '/xabia/' . sanitize_key($project_id);
            $dirs['subdir'] = $subdir;
            $dirs['path'] = rtrim((string) ($dirs['basedir'] ?? ''), '/') . $subdir;
            $dirs['url'] = rtrim((string) ($dirs['baseurl'] ?? ''), '/') . $subdir;
            return $dirs;
        };
        add_filter('upload_dir', $upload_dir_filter);
        $upload = wp_handle_upload($file, ['test_form' => false, 'mimes' => ['csv' => 'text/csv', 'txt' => 'text/plain']]);
        remove_filter('upload_dir', $upload_dir_filter);
        if (is_array($upload) && !empty($upload['error'])) {
            wp_send_json_error(['message' => (string) $upload['error']]);
            return;
        }
        if (!is_array($upload) || empty($upload['file'])) {
            wp_send_json_error(['message' => __('No se pudo guardar el archivo CSV.', 'xabia-intelligence')]);
            return;
        }
        $filename = basename((string) $upload['file']);
        $projects = get_option('xabia_projects_config', []);
        if (!isset($projects[$project_id]) || !is_array($projects[$project_id])) {
            $projects[$project_id] = [];
        }
        $projects[$project_id]['csv_filename'] = sanitize_file_name($filename);
        update_option('xabia_projects_config', $projects);
        $this->admin_json_success([
            'message' => __('CSV subido correctamente.', 'xabia-intelligence'),
            'file' => ['name' => sanitize_file_name($filename)],
        ]);
    }

    public function ajax_delete_csv() {
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permiso denegado.', 'xabia-intelligence')]);
            return;
        }
        $project_id = sanitize_key(wp_unslash($_POST['project_id'] ?? ''));
        if ($project_id === '') {
            wp_send_json_error(['message' => __('Project ID inválido.', 'xabia-intelligence')]);
            return;
        }
        $file = $this->resolve_project_csv_path($project_id, sanitize_file_name(wp_unslash($_POST['csv_file'] ?? '')));
        if ($file !== '' && file_exists($file)) {
            @unlink($file);
        }
        $projects = get_option('xabia_projects_config', []);
        if (isset($projects[$project_id]) && is_array($projects[$project_id])) {
            $projects[$project_id]['csv_filename'] = '';
            update_option('xabia_projects_config', $projects);
        }
        $this->admin_json_success(['message' => __('CSV eliminado.', 'xabia-intelligence')]);
    }

    public function ajax_test_sql_connection() {
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        $sourceType = sanitize_key($_POST['source_type'] ?? 'sql');
        $sqlPreset = sanitize_key($_POST['sql_preset'] ?? '');
        $test_sql = stripslashes((string) ($_POST['query'] ?? ''));
        $test_sql = trim(rtrim($test_sql, ';'));
        $test_sql = preg_replace('/\s+LIMIT\s+\d+\s*;?\s*$/i', '', $test_sql);
        $manualPrefix = sanitize_text_field(wp_unslash($_POST['prefix'] ?? ''));
        $is_local = ($sourceType === 'local_sql');
        $host = sanitize_text_field(wp_unslash($_POST['host'] ?? ''));
        $user = sanitize_text_field(wp_unslash($_POST['user'] ?? ''));
        $pass = sanitize_text_field(wp_unslash($_POST['pass'] ?? ''));
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));

        if (!class_exists('Xabia_SQL_Connector', false)) {
            $connector = defined('XABIA_PATH')
                ? XABIA_PATH . 'integrations/class-xabia-sql-connector.php'
                : plugin_dir_path(dirname(__FILE__)) . '../integrations/class-xabia-sql-connector.php';
            if (is_readable($connector)) {
                require_once $connector;
            }
        }

        $prefix_cfg = [
            'host'   => $is_local ? '' : $host,
            'user'   => $user,
            'pass'   => $pass,
            'name'   => $name,
            'prefix' => $manualPrefix,
            'query'  => $test_sql,
        ];
        $resolved_prefix = '';
        if (class_exists('Xabia_SQL_Connector', false)) {
            $resolved_prefix = Xabia_SQL_Connector::resolve_table_prefix($prefix_cfg);
            $prefix_cfg['prefix'] = $resolved_prefix;
            $test_sql = Xabia_SQL_Connector::apply_prefix_to_sql($test_sql, $prefix_cfg);
        } elseif (stripos($test_sql, '{prefix}') !== false) {
            if ($manualPrefix !== '') {
                $realPrefix = $manualPrefix;
            } elseif ($is_local) {
                global $wpdb;
                $realPrefix = $wpdb->prefix;
            } else {
                $realPrefix = 'wp_';
            }
            $test_sql = str_replace('{prefix}', $realPrefix, $test_sql);
            $resolved_prefix = $realPrefix;
        }

        if ($is_local) {
            global $wpdb;
            $results = $wpdb->get_results($test_sql . " LIMIT 1", ARRAY_A);
            if ($wpdb->last_error) {
                wp_send_json_error(['message' => 'Error SQL local: ' . $wpdb->last_error]);
            }
        } else {
            $remote_db = new wpdb($user, $pass, $name, $host);
            if (!empty($remote_db->error)) wp_send_json_error(['message' => 'Error Conexión DB: ' . $remote_db->error]);
            $results = $remote_db->get_results($test_sql . " LIMIT 1", ARRAY_A);
            if ($remote_db->last_error) wp_send_json_error(['message' => 'Error SQL: ' . $remote_db->last_error]);
        }
        if (empty($results)) wp_send_json_error(['message' => 'La consulta no devolvió datos.']);
        
        $columns = array_keys($results[0]);
        if ($sqlPreset === 'mec_remote') {
            $this->admin_json_success([
                'message' => __('Base de datos MEC remota vinculada correctamente.', 'xabia-intelligence'),
                'columns' => $columns,
                'fields'  => self::xabia_mec_remote_default_mapping_fields(),
                'prefix'  => $resolved_prefix,
            ]);
        }
        $has_post_title = in_array('post_title', $columns, true);
        $preferred_ente_col = null;
        foreach ($columns as $col) {
            if (strcasecmp((string) $col, 'empresa') === 0) {
                $preferred_ente_col = $col;
                break;
            }
        }
        if ($preferred_ente_col === null) {
            foreach ($columns as $col) {
                if (stripos((string) $col, 'empresa') !== false) {
                    $preferred_ente_col = $col;
                    break;
                }
            }
        }
        if ($preferred_ente_col === null && $has_post_title) {
            $preferred_ente_col = 'post_title';
        }
        $fields = [];
        foreach ($columns as $col) {
            $is_ente = ($preferred_ente_col !== null && $col === $preferred_ente_col)
                || ($preferred_ente_col === null && strtoupper((string) $col) === 'ID');
            $role = 'none';
            if (strcasecmp((string) $col, 'empresa') === 0 || stripos((string) $col, 'empresa') !== false) {
                $role = 'title';
            } elseif ($col === 'post_title') {
                $role = 'title';
            }
            $default_import = class_exists('Xabia_Knowledge_Text', false)
                ? Xabia_Knowledge_Text::default_import_rag_for_column($col)
                : true;
            $fields[] = [
                'csv_col'         => $col,
                'label'           => $col,
                'visual_role'     => $role,
                'is_ente'         => $is_ente,
                'ente_label_col'  => '',
                'instruction'     => '',
                'import_rag'      => $default_import ? 1 : 0,
            ];
        }
        $this->admin_json_success([
            'message' => __('Base de datos vinculada correctamente.', 'xabia-intelligence'),
            'columns' => $columns,
            'fields'  => $fields,
        ]);
    }

    public function ajax_test_addon_columns() {
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        $slug = sanitize_text_field($_POST['addon_slug']);
        global $xabia_available_addons;
        $addons = array_merge((array)$xabia_available_addons, (array)apply_filters('xabia_register_sql_sources', []));
        
        if (!isset($addons[$slug])) {
            wp_send_json_error(['message' => 'Addon no encontrado']);
        }

        if ($slug === 'mec' && (!function_exists('xabia_mec_license_gate') || !xabia_mec_license_gate())) {
            wp_send_json_error([
                'message' => __('Suscripción Xabia MEC activa en el Hub requerida para conectar y mapear eventos.', 'xabia-intelligence'),
            ]);
        }
        if ($slug === 'woo' && (!function_exists('xabia_woo_license_gate') || !xabia_woo_license_gate())) {
            wp_send_json_error([
                'message' => __('Suscripción Xabia Woo en el Hub requerida para conectar y mapear productos.', 'xabia-intelligence'),
            ]);
        }
        
        $sql = call_user_func($addons[$slug]['callback']);
        $sql = trim(rtrim($sql, ';'));
        $sql = preg_replace('/\s+LIMIT\s+\d+\s*;?\s*$/i', '', $sql); 

        $host = sanitize_text_field($_POST['host']);
        
        if (!empty($host)) {
            $remote_db = new wpdb(sanitize_text_field($_POST['user']), sanitize_text_field($_POST['pass']), sanitize_text_field($_POST['name']), $host);
            if ($remote_db->error) wp_send_json_error(['message' => 'Error: ' . $remote_db->error]);

            $manual_prefix = sanitize_text_field($_POST['prefix'] ?? '');
            $prefix = $manual_prefix !== '' ? $manual_prefix : ($this->find_active_prefix($remote_db) ?: 'wp_');
            $sql = str_replace('{prefix}', $prefix, $sql);
            
            $results = $remote_db->get_results($sql . " LIMIT 1", ARRAY_A);
            $results = apply_filters('xabia_addon_test_sql_results', $results, $slug, $sql, 'remote');
            
            if (empty($results)) {
                $cols = ['ID','Titulo','Descripcion','Link','Fecha','Hora','Precio','Imagen_URL','Categorias_Tags'];
                $payload = ['columns' => $cols];
                if ($slug === 'mec') {
                    $payload['fields'] = self::xabia_mec_remote_default_mapping_fields();
                } elseif ($slug === 'woo' && class_exists('Xabia_Woo_Connector', false)) {
                    $payload['fields'] = Xabia_Woo_Connector::default_mapping_fields();
                }
                $this->admin_json_success($payload);

                return;
            }
            if ($remote_db->last_error) wp_send_json_error(['message' => 'Error SQL: ' . $remote_db->last_error]);
        } else {
            global $wpdb;
            $sql = str_replace('{prefix}', $wpdb->prefix, $sql);
            $results = $wpdb->get_results($sql . " LIMIT 1", ARRAY_A);
            $results = apply_filters('xabia_addon_test_sql_results', $results, $slug, $sql, 'local');
        }
        
        if (empty($results)) {
            $cols = ['ID','Titulo','Descripcion','Link','Fecha','Hora','Precio','Imagen_URL','Categorias_Tags'];
            $payload = ['columns' => $cols];
            if ($slug === 'mec') {
                $payload['fields'] = self::xabia_mec_remote_default_mapping_fields();
            } elseif ($slug === 'woo' && class_exists('Xabia_Woo_Connector', false)) {
                $payload['fields'] = Xabia_Woo_Connector::default_mapping_fields();
            }
            $this->admin_json_success($payload);

            return;
        }
        $payload = ['columns' => array_keys(is_array($results[0] ?? null) ? $results[0] : [])];
        if ($slug === 'mec') {
            $payload['fields'] = self::xabia_mec_remote_default_mapping_fields();
        } elseif ($slug === 'woo' && class_exists('Xabia_Woo_Connector', false)) {
            $payload['fields'] = Xabia_Woo_Connector::default_mapping_fields();
        }
        $this->admin_json_success($payload);
    }

    

    public function handle_sync_content_ajax() {
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        @ini_set('max_execution_time', '300');
        $pid = sanitize_text_field($_POST['project_id']);

        if (!class_exists('Xabia_Knowledge_Sync', false)) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'core/class-xabia-knowledge-sync.php';
        }

        try {
            $incremental = class_exists('Xabia_Knowledge_Sync', false)
                ? Xabia_Knowledge_Sync::wants_incremental_sync($pid)
                : true;
            $result = Xabia_Knowledge_Sync::run_project($pid, ['incremental' => $incremental]);
            if (class_exists('Xabia_Auto_Sync', false)) {
                Xabia_Auto_Sync::record_manual_sync($pid, (int) ($result['count'] ?? 0));
            }
            if (class_exists('Xabia_Starter_Questions', false)) {
                Xabia_Starter_Questions::bust_project_cache($pid);
            }
            $this->admin_json_success(array_merge([
                'message'         => (string) ($result['message'] ?? __('Sincronización completada.', 'xabia-intelligence')),
                'count'           => (int) ($result['count'] ?? 0),
                'orphans'         => isset($result['orphans']) && is_array($result['orphans']) ? $result['orphans'] : [],
                'content_updated' => (int) ($result['content_updated'] ?? 0),
                'inserted'        => (int) ($result['inserted'] ?? 0),
                'unchanged'       => (int) ($result['unchanged'] ?? 0),
            ], self::agent_pipeline_ajax_payload($pid)));
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handle_train_ai_ajax() {
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        @ini_set('max_execution_time', '300');
        $pid = sanitize_text_field($_POST['project_id']);

        if (!class_exists('Xabia_Knowledge_Train', false)) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'core/class-xabia-knowledge-train.php';
        }

        $result = Xabia_Knowledge_Train::run_batch($pid);
        if (empty($result['ok'])) {
            $payload = array_merge([
                'message' => (string) ($result['message'] ?? __('Error al entrenar.', 'xabia-intelligence')),
                'pending' => (int) ($result['pending'] ?? 0),
            ], self::agent_pipeline_ajax_payload($pid));
            if (class_exists('Xabia_Digixop_Client', false) && Xabia_Digixop_Client::was_insufficient_balance()) {
                $payload['digixop_insufficient'] = true;
            }
            wp_send_json_error($payload);

            return;
        }

        if ((int) ($result['pending'] ?? 0) === 0 && class_exists('Xabia_Auto_Sync', false)) {
            Xabia_Auto_Sync::continue_pipeline_after_train($pid);
        }

        if (class_exists('Xabia_Starter_Questions', false)) {
            Xabia_Starter_Questions::bust_project_cache($pid);
        }

        $this->admin_json_success(array_merge([
            'message' => (string) ($result['message'] ?? __('Entrenado', 'xabia-intelligence')),
            'pending' => (int) ($result['pending'] ?? 0),
            'updated' => (int) ($result['updated'] ?? 0),
            'failed'  => (int) ($result['failed'] ?? 0),
        ], self::agent_pipeline_ajax_payload($pid)));
    }

    public function handle_train_estimate_ajax(): void {
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permiso denegado.', 'xabia-intelligence')]);

            return;
        }
        $pid = sanitize_text_field((string) ($_POST['project_id'] ?? ''));
        if ($pid === '') {
            wp_send_json_error(['message' => __('Proyecto no válido.', 'xabia-intelligence')]);

            return;
        }
        if (!class_exists('Xabia_Knowledge_Train', false)) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'core/class-xabia-knowledge-train.php';
        }

        $batch_cost = Xabia_Knowledge_Train::estimate_pending_train_cost($pid, Xabia_Knowledge_Train::DEFAULT_BATCH_SIZE);
        $total_cost = Xabia_Knowledge_Train::estimate_pending_train_cost($pid, 0);
        $tokens_remaining = class_exists('Xabia_Digixop_Client', false)
            ? Xabia_Digixop_Client::license_tokens_remaining()
            : null;

        $this->admin_json_success(array_merge([
            'batch_estimated_tokens'  => (int) ($batch_cost['estimated_tokens'] ?? 0),
            'total_estimated_tokens'  => (int) ($total_cost['estimated_tokens'] ?? 0),
            'pending'                 => (int) ($total_cost['pending'] ?? 0),
            'batch_size'              => (int) ($batch_cost['batch_size'] ?? Xabia_Knowledge_Train::DEFAULT_BATCH_SIZE),
            'avg_chars'               => (int) ($total_cost['avg_chars'] ?? 0),
            'tokens_remaining'        => $tokens_remaining,
            'tokens_depleted'         => class_exists('Xabia_Digixop_Client', false) && Xabia_Digixop_Client::proxy_tokens_depleted(),
            'hub_rag_hint'            => class_exists('Xabia_Hub_Knowledge', false) && Xabia_Hub_Knowledge::is_hub_rag_enabled($pid),
        ], self::agent_pipeline_ajax_payload($pid)));
    }

    public function handle_purge_orphan_knowledge_ajax(): void {
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permiso denegado.', 'xabia-intelligence')]);

            return;
        }
        $pid = sanitize_text_field((string) ($_POST['project_id'] ?? ''));
        if ($pid === '' || !class_exists('Xabia_Knowledge_Orphans', false)) {
            wp_send_json_error(['message' => __('Proyecto no válido.', 'xabia-intelligence')]);

            return;
        }
        $vector_ids = isset($_POST['vector_ids']) ? (array) $_POST['vector_ids'] : [];
        $deleted = Xabia_Knowledge_Orphans::purge($pid, $vector_ids);
        if ($deleted < 1) {
            wp_send_json_error(['message' => __('No se eliminó ningún registro.', 'xabia-intelligence')]);

            return;
        }

        $projects = get_option('xabia_projects_config', []);
        $agent_name = isset($projects[$pid]['name']) && is_string($projects[$pid]['name'])
            ? trim($projects[$pid]['name'])
            : __('el agente', 'xabia-intelligence');

        $this->admin_json_success(array_merge([
            'message' => sprintf(
                _n(
                    'Se eliminó %1$d registro de la memoria de %2$s.',
                    'Se eliminaron %1$d registros de la memoria de %2$s.',
                    $deleted,
                    'xabia-intelligence'
                ),
                $deleted,
                $agent_name
            ),
            'deleted' => $deleted,
        ], self::agent_pipeline_ajax_payload($pid)));
    }

    public function handle_sync_brain_cloud_ajax(): void {
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permiso denegado.', 'xabia-intelligence')]);

            return;
        }
        $pid = sanitize_text_field((string) ($_POST['project_id'] ?? ''));
        if ($pid === '' || !class_exists('Xabia_Hub_Knowledge', false)) {
            wp_send_json_error(['message' => __('Xabia Hub no disponible.', 'xabia-intelligence')]);

            return;
        }
        $r = Xabia_Hub_Knowledge::sync_vectors_to_hub($pid);
        if (empty($r['ok'])) {
            wp_send_json_error([
                'message' => (string) ($r['message'] ?? __('Error al sincronizar.', 'xabia-intelligence')),
                'detail'  => $r['detail'] ?? null,
                'inserted'=> (int) ($r['inserted'] ?? 0),
            ]);

            return;
        }
        $this->admin_json_success([
            'message' => (string) ($r['message'] ?? ''),
            'inserted'=> (int) ($r['inserted'] ?? 0),
            'batches' => (int) ($r['batches'] ?? 0),
        ]);
    }

    /**
     * Vista previa del índice local (nombre de tabla vía Xabia_DB) para un agente: recuentos y extractos.
     */
    public function ajax_knowledge_preview(): void {
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permiso denegado.', 'xabia-intelligence')]);

            return;
        }
        $pid = sanitize_text_field((string) ($_POST['project_id'] ?? ''));
        if ($pid === '' || !class_exists('Xabia_DB', false)) {
            wp_send_json_error(['message' => __('Proyecto no válido.', 'xabia-intelligence')]);

            return;
        }
        global $wpdb;
        $t = Xabia_DB::table('knowledge_vectors');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) {
            wp_send_json_success([
                'total'             => 0,
                'ready'             => 0,
                'pending_embedding' => 0,
                'table'             => $t,
                'samples'           => [],
                'hint'              => __('La tabla de vectores aún no existe en esta base de datos.', 'xabia-intelligence'),
            ]);

            return;
        }
        $projects = get_option('xabia_projects_config', []);
        $config = isset($projects[$pid]) && is_array($projects[$pid]) ? $projects[$pid] : [];
        $use_vector = !empty($config['rules']['use_vector_search']);
        $ready_sql = $use_vector
            ? Xabia_DB::knowledge_vectors_sql_has_embedding()
            : Xabia_DB::knowledge_vectors_sql_has_usable_content();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla desde helper acotado.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$t}` WHERE project_id = %s", $pid));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ready = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$t}` WHERE project_id = %s AND ({$ready_sql})", $pid));
        $pending = class_exists('Xabia_Knowledge_Train', false)
            ? Xabia_Knowledge_Train::count_pending($pid)
            : 0;
        $cm = Xabia_DB::knowledge_vectors_column_map();
        $has_ente = isset($cm['ente_id']);
        if ($has_ente) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $samples = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, ente_id, SUBSTRING(content_chunk, 1, 380) AS excerpt FROM `{$t}` WHERE project_id = %s ORDER BY id DESC LIMIT 12",
                    $pid
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $samples = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, SUBSTRING(content_chunk, 1, 380) AS excerpt FROM `{$t}` WHERE project_id = %s ORDER BY id DESC LIMIT 12",
                    $pid
                ),
                ARRAY_A
            );
        }
        if (!is_array($samples)) {
            $samples = [];
        }
        foreach ($samples as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $eid = isset($row['ente_id']) ? (string) $row['ente_id'] : '';
            if ($eid !== '' && $eid !== 'global') {
                $samples[$i]['ente_display'] = self::xabia_get_ente_display_name_for_project($pid, $eid);
            } else {
                $samples[$i]['ente_display'] = '';
            }
        }
        $hint = '';
        if ($total === 0) {
            $hint = __('No hay filas para este agente en la tabla de conocimiento. El playground solo usa lo que «Sincronizar datos» (SQL/CSV/multi) escribe para este project_id. Otras pantallas de sincronización solo cuentan si su código importa a la misma tabla con el mismo id de agente.', 'xabia-intelligence');
        } elseif (!$use_vector && $ready > 0) {
            $hint = __('Búsqueda vectorial desactivada: estos registros ya están listos para el chat por palabras clave; no hace falta entrenar embeddings.', 'xabia-intelligence');
        } elseif ($ready === 0 && class_exists('Xabia_Hub_Knowledge', false) && Xabia_Hub_Knowledge::is_hub_rag_enabled($pid)) {
            $hint = __('Hay registros de texto pero aún no hay embeddings útiles (vector_json distinto de vacío). Usa «Entrenar IA» y luego «Sincronizar Cerebro con Xabia Cloud» para RAG semántico en el Hub; el Hub y el plugin también pueden recuperar por palabras clave si están actualizados.', 'xabia-intelligence');
        }
        wp_send_json_success([
            'total'             => $total,
            'ready'             => $ready,
            'pending_embedding' => $pending,
            'table'             => $t,
            'samples'           => $samples,
            'hint'              => $hint,
        ]);
    }

    public function ajax_digixop_validate_license() {
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permiso denegado.', 'xabia-intelligence')]);
            return;
        }
        if (!class_exists('Xabia_Digixop_Client')) {
            wp_send_json_error(['message' => 'Xabia']);
            return;
        }
        $result = Xabia_Digixop_Client::validate_license_remote();
        $this->admin_json_success([
            'result' => $result,
            'cached' => Xabia_Digixop_Client::get_cached_license_meta(),
        ]);
    }

    /**
     * Devuelve la licencia guardada en wp_options (solo administradores). No usar en frontend público.
     */
    public function ajax_digixop_reveal_saved_license(): void {
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permiso denegado.', 'xabia-intelligence')]);

            return;
        }
        if (!class_exists('Xabia_Digixop_Client', false)) {
            wp_send_json_error(['message' => 'Xabia']);

            return;
        }
        $key = trim((string) get_option(Xabia_Digixop_Client::OPTION_LICENSE, ''));
        $this->admin_json_success([
            'license' => $key,
            'empty'   => ($key === ''),
        ]);
    }

    public function ajax_addon_sync_license(): void {
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permiso denegado.', 'xabia-intelligence')]);

            return;
        }
        $slug = sanitize_key((string) ($_POST['slug'] ?? ''));
        if ($slug === '' || !class_exists('Xabia_Addons', false) || !Xabia_Addons::is_registered_slug($slug)) {
            wp_send_json_error(['message' => __('Add-on no válido.', 'xabia-intelligence')]);

            return;
        }
        Xabia_Addons::flush_status_cache($slug);
        $st = Xabia_Addons::get_hub_status($slug, true);
        if (class_exists('Xabia_Digixop_Client', false)) {
            Xabia_Digixop_Client::validate_license_remote();
        }
        do_action('xabia_addon_hub_status_refreshed', $slug, $st);
        wp_send_json_success([
            'subscription_active' => !empty($st['subscription_active']),
            'message'             => (string) ($st['message'] ?? ''),
            'inactive_reason'     => (string) ($st['inactive_reason'] ?? ''),
        ]);
    }

    public function handle_clear_memory_ajax() {
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        global $wpdb;
        $pid = sanitize_key((string) ($_POST['project_id'] ?? ''));
        if ($pid === '') {
            wp_send_json_error(['message' => __('Project ID inválido.', 'xabia-intelligence')]);

            return;
        }
        $wpdb->delete(Xabia_DB::table('knowledge_vectors'), ['project_id' => $pid]);
        if (class_exists('Xabia_Knowledge_Sync', false)) {
            Xabia_Knowledge_Sync::flag_force_full_sync($pid);
        }
        $hub_purge = class_exists('Xabia_Hub_Knowledge', false)
            ? Xabia_Hub_Knowledge::purge_hub_project($pid)
            : null;
        $message = __('Memoria vectorial borrada. Pulsa «Sincronizar datos»: hará una importación completa del idioma principal.', 'xabia-intelligence');
        if (is_array($hub_purge) && !empty($hub_purge['ok'])) {
            $message .= ' ' . __('Memoria del Hub también eliminada.', 'xabia-intelligence');
        }
        $this->admin_json_success(['message' => $message, 'hub_purge' => $hub_purge]);
    }

    public function ajax_scan_csv_fields() {
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        $pid = sanitize_key($_POST['project_id']);
        $csv_file = !empty($_POST['csv_file']) ? sanitize_file_name($_POST['csv_file']) : '';
        $file_path = $this->resolve_project_csv_path($pid, $csv_file);
        if ($file_path === '') {
            wp_send_json_error(['message' => 'No se encontraron archivos CSV para este proyecto']);
            return;
        }
        
        if (file_exists($file_path)) {
            $h = fopen($file_path, 'r');
            if ($h) {
                $l = fgets($h);
                fclose($h);
                $cols = str_getcsv($l, (substr_count($l, ';') > substr_count($l, ',')) ? ';' : ',');
                $projects = get_option('xabia_projects_config', []);
                $existing = isset($projects[$pid]['attributes']) && is_array($projects[$pid]['attributes'])
                    ? $projects[$pid]['attributes']
                    : [];
                $by_col = [];
                foreach ($existing as $a) {
                    if (!empty($a['csv_col'])) {
                        $by_col[$a['csv_col']] = $a;
                    }
                }
                $out = [];
                $col_set = array_flip($cols);
                foreach ($cols as $i => $col) {
                    $col = trim((string) $col);
                    if ($col === '') continue;
                    if (isset($by_col[$col])) {
                        $a = $by_col[$col];
                        $is_ente = !empty($a['is_ente']);
                        $out[] = [
                            'csv_col'          => $col,
                            'label'            => isset($a['label']) ? (string) $a['label'] : $col,
                            'visual_role'      => isset($a['visual_role']) ? (string) $a['visual_role'] : 'none',
                            'is_ente'          => $is_ente,
                            'ente_label_col'   => $is_ente ? (string) ($a['ente_label_col'] ?? '') : '',
                            'instruction'      => isset($a['instruction']) ? (string) $a['instruction'] : '',
                            'import_rag'       => isset($a['import_rag']) ? (int) $a['import_rag'] : 1,
                        ];
                    } else {
                        $is_id = (strtoupper($col) === 'ID');
                        $default_import = class_exists('Xabia_Knowledge_Text', false)
                            ? Xabia_Knowledge_Text::default_import_rag_for_column($col)
                            : true;
                        $out[] = [
                            'csv_col'          => $col,
                            'label'            => $col,
                            'visual_role'      => 'none',
                            'is_ente'          => $is_id,
                            'ente_label_col'   => ($is_id && isset($col_set['post_title'])) ? 'post_title' : '',
                            'instruction'      => '',
                            'import_rag'       => $default_import ? 1 : 0,
                        ];
                    }
                }
                $this->admin_json_success(['fields' => $out]);
            } else {
                wp_send_json_error(['message' => 'No se pudo leer el archivo']);
            }
        } else {
            wp_send_json_error(['message' => 'El archivo no existe']);
        }
    }

    public function ajax_list_csv_files() {
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        $pid = sanitize_key($_POST['project_id'] ?? '');
        $base_dir = $this->get_project_csv_dir($pid);
        $files = [];
        if (is_dir($base_dir)) {
            $csv_files = glob($base_dir . '/*.csv');
            foreach ($csv_files as $file) {
                $files[] = [
                    'name' => basename($file),
                    'path' => str_replace(wp_upload_dir()['basedir'], '', $file)
                ];
            }
        }
        
        $this->admin_json_success(['files' => $files]);
    }

    /**
     * Meta keys y columnas típicas de posts para un CPT (incl. campos ACF registrados en grupos del tipo).
     */
    public function ajax_get_meta_fields() {
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Permiso denegado.', 'xabia-intelligence'),
                'nonce'   => wp_create_nonce('xabia_admin_nonce'),
            ]);
        }

        $post_type = sanitize_key(wp_unslash($_POST['post_type'] ?? ''));
        if ($post_type === '') {
            wp_send_json_error([
                'message' => __('Indica el slug del tipo de contenido (post_type).', 'xabia-intelligence'),
                'nonce'   => wp_create_nonce('xabia_admin_nonce'),
            ]);
        }

        if (!post_type_exists($post_type)) {
            wp_send_json_error([
                'message' => __('Tipo de contenido no registrado en WordPress.', 'xabia-intelligence'),
                'nonce'   => wp_create_nonce('xabia_admin_nonce'),
            ]);
        }

        global $wpdb;
        $keys = [];

        $core_columns = [
            'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt',
            'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged',
            'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order',
            'post_type', 'post_mime_type', 'comment_count',
        ];
        foreach ($core_columns as $c) {
            $keys[$c] = true;
        }

        $meta_keys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE p.post_type = %s",
                $post_type
            )
        );
        foreach ((array) $meta_keys as $mk) {
            if ($mk !== '') {
                $keys[$mk] = true;
            }
        }

        if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
            $groups = acf_get_field_groups(['post_type' => $post_type]);
            foreach ($groups as $group) {
                $group_key = $group['key'] ?? '';
                $fields = null;
                if ($group_key !== '') {
                    $fields = acf_get_fields($group_key);
                }
                if (!is_array($fields) && !empty($group['ID'])) {
                    $fields = acf_get_fields((int) $group['ID']);
                }
                if (is_array($fields)) {
                    self::xabia_collect_acf_field_names($fields, $keys);
                }
            }
        }

        $list = array_keys($keys);
        sort($list, SORT_STRING);

        $this->admin_json_success(['meta_keys' => $list]);
    }

    /**
     * Esquema WP para selectores: post types públicos y, opcionalmente, meta_keys únicas con filas en postmeta para un tipo.
     * Las claves generadas por ACF u otros plugins aparecen aquí si existen en postmeta (p. ej. nombre de campo o referencias internas).
     * Incluye siempre un nonce nuevo (`xabia_admin_nonce`) para que el cliente reautentique la siguiente petición.
     */
    public function ajax_get_wp_schema() {
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Permiso denegado.', 'xabia-intelligence'),
                'nonce'   => wp_create_nonce('xabia_admin_nonce'),
            ]);
        }

        $config = self::merge_relation_ajax_project_config();
        $source_type = sanitize_key((string) ($config['source_type'] ?? 'csv'));
        $scope = (string) ($config['_discovery_scope'] ?? $source_type);
        $post_types = [];
        $origin_label = $scope;
        $ui_hint = '';

        // Asistente CPT: descubrimiento estricto por fuente (sin fugas del WP local).
        if (class_exists('Xabia_Relation_Entity_Catalog', false)
            && method_exists('Xabia_Relation_Entity_Catalog', 'discover_cpt_assistant_types')
        ) {
            $bundle = Xabia_Relation_Entity_Catalog::discover_cpt_assistant_types($config);
            $post_types = is_array($bundle['post_types'] ?? null) ? $bundle['post_types'] : [];
            $origin_label = (string) ($bundle['origin'] ?? $scope);
            $ui_hint = (string) ($bundle['ui_hint'] ?? '');
        }

        if ($ui_hint === '') {
            if ($source_type === 'sql') {
                $ui_hint = __('Mostrando tipos de contenido de la Base de Datos Remota', 'xabia-intelligence');
            } elseif ($source_type === 'local_sql') {
                $ui_hint = __('Mostrando tipos de contenido de este WordPress (SQL local)', 'xabia-intelligence');
            }
        }
        if (strpos($scope, 'multi:') === 0) {
            $parts = explode(':', $scope);
            $multi_idx = isset($parts[1]) ? ((int) $parts[1] + 1) : 1;
            $ui_hint = sprintf(
                /* translators: 1: source number, 2: origin hint */
                __('Fuente %1$d — %2$s', 'xabia-intelligence'),
                $multi_idx,
                $ui_hint !== '' ? $ui_hint : $origin_label
            );
        }

        usort($post_types, function ($a, $b) {
            return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
        });

        /** @var array<int, array{name: string, label: string}> $post_types */
        $post_types = apply_filters('xabia_wp_schema_post_types', $post_types, $config);

        $post_type = sanitize_key(wp_unslash($_POST['post_type'] ?? ''));

        $payload = [
            'post_types' => $post_types,
            'meta_keys'  => [],
            'post_type'  => null,
            'source'     => $source_type,
            'scope'      => $scope,
            'origin'     => $origin_label,
            'ui_hint'    => $ui_hint,
        ];

        if ($post_type === '') {
            if ($post_types === []) {
                $payload['message'] = __('No se detectaron tipos de contenido en la fuente seleccionada. Revisa la conexión SQL, el addon o la fuente multi.', 'xabia-intelligence');
            }
            $this->admin_json_success($payload);
            return;
        }

        $suggest_project_id = sanitize_key(wp_unslash($_POST['project_id'] ?? ''));

        $virtual = apply_filters('xabia_wp_schema_for_post_type', null, $post_type, $suggest_project_id);
        if (is_array($virtual) && isset($virtual['meta_keys']) && is_array($virtual['meta_keys'])) {
            /** @var string[] $vlist */
            $vlist = apply_filters('xabia_mapping_column_suggestions', $virtual['meta_keys'], $suggest_project_id, $post_type);
            $payload['meta_keys'] = $vlist;
            $payload['post_type']  = $post_type;
            $this->admin_json_success($payload);
            return;
        }

        if (!post_type_exists($post_type)) {
            $sql_config = class_exists('Xabia_Relation_Entity_Catalog', false)
                ? Xabia_Relation_Entity_Catalog::resolve_project_sql_config($config)
                : null;
            if (is_array($sql_config) && class_exists('Xabia_Relation_Entity_Catalog', false)) {
                $remote_keys = Xabia_Relation_Entity_Catalog::fetch_meta_keys_for_post_type($config, $post_type, 'content');
                $list = is_array($remote_keys['meta_keys'] ?? null) ? $remote_keys['meta_keys'] : [];
                $list = apply_filters('xabia_mapping_column_suggestions', $list, $suggest_project_id, $post_type);
                $payload['meta_keys'] = $list;
                $payload['post_type'] = $post_type;
                $payload['remote'] = true;
                $this->admin_json_success($payload);
                return;
            }

            wp_send_json_error([
                'message' => __('Tipo de contenido no encontrado en la fuente seleccionada.', 'xabia-intelligence'),
                'nonce'   => wp_create_nonce('xabia_admin_nonce'),
                'post_types' => $post_types,
                'meta_keys'  => [],
                'post_type'  => null,
            ]);
        }

        global $wpdb;
        $from_db = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE p.post_type = %s AND pm.meta_key <> ''",
                $post_type
            )
        );
        $list = array_values(array_unique(array_map('strval', (array) $from_db)));
        sort($list, SORT_STRING);

        /** @var string[] $list */
        $list = apply_filters('xabia_mapping_column_suggestions', $list, $suggest_project_id, $post_type);

        $payload['meta_keys'] = $list;
        $payload['post_type'] = $post_type;

        $this->admin_json_success($payload);
    }

    /**
     * Descubrimiento de esquema profundo para el Asistente CPT: meta (MEC: solo mec_*; resto: últimos posts),
     * taxonomías del CPT y campos virtuales (p. ej. MEC plazas disponibles).
     */
    public function ajax_get_deep_schema() {
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Permiso denegado.', 'xabia-intelligence'),
                'nonce'   => wp_create_nonce('xabia_admin_nonce'),
            ]);
        }

        $post_type = sanitize_key(wp_unslash($_POST['post_type'] ?? ''));
        $project_id = sanitize_key(wp_unslash($_POST['project_id'] ?? ''));
        $recent_limit = isset($_POST['recent_limit']) ? max(10, min(200, absint($_POST['recent_limit']))) : 100;

        if ($post_type === '') {
            wp_send_json_error([
                'message' => __('Indica el tipo de contenido.', 'xabia-intelligence'),
                'nonce'   => wp_create_nonce('xabia_admin_nonce'),
            ]);
        }

        $virtual = apply_filters('xabia_deep_schema_for_post_type', null, $post_type, $project_id);
        if (is_array($virtual) && isset($virtual['meta']) && is_array($virtual['meta'])) {
            $hints = isset($virtual['mapping_hints']) && is_array($virtual['mapping_hints'])
                ? $virtual['mapping_hints']
                : ['id', 'name', 'description', 'price', 'status'];
            $this->admin_json_success([
                'core'           => isset($virtual['core']) && is_array($virtual['core']) ? $virtual['core'] : [],
                'meta'           => $virtual['meta'],
                'taxonomies'     => isset($virtual['taxonomies']) && is_array($virtual['taxonomies']) ? $virtual['taxonomies'] : [],
                'virtual'        => isset($virtual['virtual']) && is_array($virtual['virtual']) ? $virtual['virtual'] : [],
                'post_type'      => $post_type,
                'mapping_hints'  => $hints,
            ]);
            return;
        }

        if (!function_exists('xabia_discover_cpt_fields')) {
            wp_send_json_error([
                'message' => __('Módulo de descubrimiento de esquema no disponible.', 'xabia-intelligence'),
                'nonce'   => wp_create_nonce('xabia_admin_nonce'),
            ]);
        }

        $schema = xabia_discover_cpt_fields($post_type, [
            'recent_limit' => $recent_limit,
            'project_id'   => $project_id,
            'project_config' => self::merge_relation_ajax_project_config(),
        ]);
        if (is_wp_error($schema)) {
            wp_send_json_error([
                'message' => $schema->get_error_message(),
                'nonce'   => wp_create_nonce('xabia_admin_nonce'),
            ]);
        }

        $out = [
            'core'            => $schema['core'],
            'meta'            => $schema['meta'],
            'taxonomies'      => $schema['taxonomies'],
            'virtual'         => $schema['virtual'],
            'post_type'       => $schema['post_type'],
            'mapping_hints'   => isset($schema['mapping_hints']) && is_array($schema['mapping_hints'])
                ? $schema['mapping_hints']
                : [],
        ];
        if (!empty($schema['discovery']) && is_array($schema['discovery'])) {
            $out['discovery'] = $schema['discovery'];
        }

        $this->admin_json_success($out);
    }

    /**
     * Config del proyecto para AJAX del mapeador / asistente CPT (formulario + opción guardada).
     * Respeta source_type, addon y, en multi-fuente, el índice de fuente activo.
     *
     * @return array<string, mixed>
     */
    private static function merge_relation_ajax_project_config(): array {
        $project_id = sanitize_key((string) wp_unslash($_POST['project_id'] ?? ''));
        $projects = get_option('xabia_projects_config', []);
        $config = ($project_id !== '' && is_array($projects[$project_id] ?? null)) ? $projects[$project_id] : [];

        $source_type = sanitize_key((string) wp_unslash($_POST['source_type'] ?? ''));
        if ($source_type !== '' && in_array($source_type, ['csv', 'addon', 'multi', 'local_sql', 'sql', 'web_pages'], true)) {
            $config['source_type'] = $source_type;
        }
        $addon_slug = sanitize_key((string) wp_unslash($_POST['addon_slug'] ?? ''));
        if ($addon_slug !== '') {
            $config['addon_slug'] = $addon_slug;
        }
        $sql_config = [
            'host'   => sanitize_text_field((string) wp_unslash($_POST['sql_host'] ?? '')),
            'user'   => sanitize_text_field((string) wp_unslash($_POST['sql_user'] ?? '')),
            'name'   => sanitize_text_field((string) wp_unslash($_POST['sql_name'] ?? '')),
            'pass'   => sanitize_text_field((string) wp_unslash($_POST['sql_pass'] ?? '')),
            'prefix' => sanitize_text_field((string) wp_unslash($_POST['sql_prefix'] ?? '')),
            'query'  => isset($_POST['sql_query']) ? (string) wp_unslash($_POST['sql_query']) : '',
        ];
        $existing_sql = is_array($config['sql_config'] ?? null) ? $config['sql_config'] : [];
        if ($sql_config['query'] === '' && !empty($existing_sql['query'])) {
            $sql_config['query'] = (string) $existing_sql['query'];
        }
        if ($sql_config['pass'] === '' && !empty($existing_sql['pass'])) {
            $sql_config['pass'] = (string) $existing_sql['pass'];
        }
        $merged_sql = array_merge($existing_sql, array_filter($sql_config, static function ($v) {
            return $v !== null && $v !== '';
        }));
        if ($merged_sql !== []) {
            $config['sql_config'] = $merged_sql;
        }

        $source_index = isset($_POST['source_index']) ? (int) $_POST['source_index'] : -1;
        $active_source_type = sanitize_key((string) wp_unslash($_POST['active_source_type'] ?? ''));
        $config = self::scope_config_for_source_discovery($config, $source_index, $active_source_type);

        return $config;
    }

    /**
     * Reduce la config a la fuente que el usuario está curando (SQL / multi índice / addon).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function scope_config_for_source_discovery(array $config, int $source_index, string $active_source_type = ''): array {
        $source_type = sanitize_key((string) ($config['source_type'] ?? 'csv'));

        if ($source_type === 'multi') {
            $sources = is_array($config['sources'] ?? null) ? $config['sources'] : [];
            if ($source_index < 0 || $source_index >= count($sources)) {
                $source_index = 0;
            }
            $src = is_array($sources[$source_index] ?? null) ? $sources[$source_index] : [];
            $type = $active_source_type !== ''
                ? $active_source_type
                : sanitize_key((string) ($src['type'] ?? $src['source_type'] ?? 'sql'));
            if (!in_array($type, ['csv', 'addon', 'local_sql', 'sql'], true)) {
                $type = 'sql';
            }

            $scoped = $config;
            $scoped['source_type'] = $type;
            unset($scoped['sources']);

            if (in_array($type, ['sql', 'local_sql'], true)) {
                $row_sql = is_array($src['sql_config'] ?? null) ? $src['sql_config'] : [];
                if ($row_sql === []) {
                    $row_sql = [
                        'host'   => (string) ($src['sql_host'] ?? $src['host'] ?? ''),
                        'user'   => (string) ($src['sql_user'] ?? $src['user'] ?? ''),
                        'pass'   => (string) ($src['sql_pass'] ?? $src['pass'] ?? ''),
                        'name'   => (string) ($src['sql_name'] ?? $src['name'] ?? ''),
                        'prefix' => (string) ($src['sql_prefix'] ?? $src['prefix'] ?? ''),
                        'query'  => (string) ($src['sql_query'] ?? $src['query'] ?? ''),
                    ];
                }
                // Credenciales del formulario (esta fuente) tienen prioridad.
                $posted = is_array($config['sql_config'] ?? null) ? $config['sql_config'] : [];
                $scoped['sql_config'] = array_merge($row_sql, array_filter($posted, static function ($v) {
                    return $v !== null && $v !== '';
                }));
                if ($type === 'local_sql') {
                    $scoped['sql_config']['host'] = '';
                }
            }
            if (!empty($src['addon_slug'])) {
                $scoped['addon_slug'] = sanitize_key((string) $src['addon_slug']);
            }
            // En multi no mezclar CPT del WP local salvo local_sql/csv.
            $scoped['_skip_local_fallback'] = !in_array($type, ['local_sql', 'csv'], true);
            $scoped['_discovery_scope'] = 'multi:' . $source_index . ':' . $type;

            return $scoped;
        }

        if ($source_type === 'sql') {
            $config['_skip_local_fallback'] = true;
            $config['_discovery_scope'] = 'sql';
        } elseif ($source_type === 'local_sql') {
            if (!isset($config['sql_config']) || !is_array($config['sql_config'])) {
                $config['sql_config'] = [];
            }
            $config['sql_config']['host'] = '';
            $config['_skip_local_fallback'] = true;
            $config['_discovery_scope'] = 'local_sql';
        } elseif ($source_type === 'addon') {
            $has_remote = !empty($config['sql_config']['host']) || !empty($config['sql_config']['query']);
            $config['_skip_local_fallback'] = $has_remote;
            $config['_discovery_scope'] = 'addon:' . sanitize_key((string) ($config['addon_slug'] ?? ''));
        } else {
            $config['_discovery_scope'] = $source_type !== '' ? $source_type : 'csv';
        }

        return $config;
    }

    /**
     * Tipos de contenido / taxonomías desde la fuente activa del proyecto (mapeador de relaciones).
     */
    public function ajax_relation_entity_types(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permiso denegado.', 'xabia-intelligence')]);
        }
        check_ajax_referer('xabia_admin_nonce', 'nonce');

        $config = self::merge_relation_ajax_project_config();

        if (!class_exists('Xabia_Relation_Entity_Catalog', false)) {
            wp_send_json_error([
                'message' => __('Catálogo de relaciones no disponible.', 'xabia-intelligence'),
                'nonce'   => wp_create_nonce('xabia_admin_nonce'),
            ]);
        }

        $bundle = Xabia_Relation_Entity_Catalog::discover_for_project($config);
        $this->admin_json_success([
            'entities' => $bundle['entities'] ?? [],
            'kinds'    => $bundle['kinds'] ?? [],
            'source'   => (string) ($bundle['source'] ?? ''),
            'nonce'    => wp_create_nonce('xabia_admin_nonce'),
        ]);
    }

    /**
     * Meta keys de postmeta para el post_type de origen (mapeador de relaciones).
     */
    public function ajax_relation_meta_keys(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permiso denegado.', 'xabia-intelligence')]);
        }
        check_ajax_referer('xabia_admin_nonce', 'nonce');

        $post_type = sanitize_key((string) wp_unslash($_POST['source_post_type'] ?? $_POST['post_type'] ?? ''));
        if ($post_type === '') {
            wp_send_json_error([
                'message' => __('Indica el tipo de contenido de origen.', 'xabia-intelligence'),
                'nonce'   => wp_create_nonce('xabia_admin_nonce'),
            ]);
        }

        if (!class_exists('Xabia_Relation_Entity_Catalog', false)) {
            wp_send_json_error([
                'message' => __('Catálogo de relaciones no disponible.', 'xabia-intelligence'),
                'nonce'   => wp_create_nonce('xabia_admin_nonce'),
            ]);
        }

        $config = self::merge_relation_ajax_project_config();
        $entity_kind = sanitize_key((string) wp_unslash($_POST['source_kind'] ?? 'content'));
        $result = Xabia_Relation_Entity_Catalog::fetch_meta_keys_for_post_type($config, $post_type, $entity_kind);

        $this->admin_json_success(array_merge($result, [
            'post_type' => $post_type,
            'nonce'     => wp_create_nonce('xabia_admin_nonce'),
        ]));
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, true>              $keys
     */
    private static function xabia_collect_acf_field_names(array $fields, array &$keys) {
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = isset($field['name']) ? (string) $field['name'] : '';
            if ($name !== '') {
                $keys[$name] = true;
            }
            if (!empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                self::xabia_collect_acf_field_names($field['sub_fields'], $keys);
            }
        }
    }

    /**
     * Nombre visible del ente (__ente_display en meta_data) para listados admin.
     */
    private static function xabia_get_ente_display_name_for_project($project_id, $ente_id) {
        if (class_exists('Xabia_DB_Bridge', false)) {
            return Xabia_DB_Bridge::get_stored_ente_display((string) $project_id, (string) $ente_id);
        }

        return (string) $ente_id;
    }

    private static function mask_secret($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $len = strlen($value);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4) . substr($value, -4);
    }

    /**
     * Evita guardar como licencia el texto enmascarado del admin (solo asteriscos + últimos 4 caracteres).
     */
    private static function license_input_looks_like_masked_placeholder(string $raw): bool {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) < 8) {
            return false;
        }
        if (str_starts_with($raw, 'xabia_')) {
            return false;
        }

        return preg_match('/^\*+[A-Za-z0-9_]{4}$/', $raw) === 1;
    }

    

    public function render_view() {
        global $xabia_available_addons; 
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $projects = get_option('xabia_projects_config', []);
        $edit_id = isset($_GET['edit']) ? sanitize_key($_GET['edit']) : '';
        $data = $projects[$edit_id] ?? null;
        $xabia_premium_addon_sync_locked = false;
        if (is_array($data) && (($data['source_type'] ?? '') === 'addon')) {
            $addon_slug = (string) ($data['addon_slug'] ?? '');
            if ($addon_slug === 'mec' && function_exists('xabia_mec_license_gate') && !xabia_mec_license_gate()) {
                $xabia_premium_addon_sync_locked = true;
            }
            if ($addon_slug === 'woo' && function_exists('xabia_woo_license_gate') && !xabia_woo_license_gate()) {
                $xabia_premium_addon_sync_locked = true;
            }
        }
        
        $available_addons = array_merge((array)$xabia_available_addons, (array)apply_filters('xabia_register_sql_sources', []));
        $central_slug_ui = defined('XABIA_CENTRAL_SLUG') ? XABIA_CENTRAL_SLUG : 'xabia_central';
        unset($available_addons[$central_slug_ui], $available_addons['xabia-central']);

        $rag_slugs = apply_filters('xabia_agent_rag_preset_addon_slugs', ['mec', 'woo']);
        if (!is_array($rag_slugs)) {
            $rag_slugs = ['mec', 'woo'];
        }
        $rag_slugs = array_values(array_filter(array_map('strval', $rag_slugs), static function ($s) use ($central_slug_ui) {
            return $s !== '' && $s !== $central_slug_ui && $s !== 'xabia-central';
        }));

        $available_addons_rag = [];
        foreach ($rag_slugs as $rs) {
            if (!isset($available_addons[$rs])) {
                continue;
            }
            $pf = self::xabia_native_connector_plugin_basename($rs);
            if ($pf !== '' && !is_plugin_active($pf)) {
                continue;
            }
            $available_addons_rag[$rs] = $available_addons[$rs];
        }
        $data_cfg = is_array($data) ? $data : [];
        if (($data_cfg['source_type'] ?? '') === 'addon' && ($data_cfg['addon_slug'] ?? '') === 'mec' && self::xabia_attributes_need_mec_defaults($data_cfg['attributes'] ?? [])) {
            $data_cfg['attributes'] = self::xabia_mec_remote_default_mapping_fields();
        }
        if (($data_cfg['source_type'] ?? '') === 'web_pages' && ($data_cfg['attributes'] ?? []) === [] && class_exists('Xabia_Web_Pages_Source', false)) {
            $data_cfg['attributes'] = Xabia_Web_Pages_Source::default_mapping_fields();
        }
        $legacy_addon_slug = (string) ($data_cfg['addon_slug'] ?? '');
        if ($legacy_addon_slug !== '' && $legacy_addon_slug !== $central_slug_ui && !isset($available_addons_rag[$legacy_addon_slug]) && isset($available_addons[$legacy_addon_slug])) {
            $available_addons_rag[$legacy_addon_slug] = $available_addons[$legacy_addon_slug];
        }
        $has_rag_presets = $available_addons_rag !== [];
        $rag_preset_labels = [
            'mec' => __('Modern Events Calendar (MEC)', 'xabia-intelligence'),
            'woo' => __('WooCommerce', 'xabia-intelligence'),
        ];
        $source_type = $data_cfg['source_type'] ?? 'csv';
        if (!$has_rag_presets && $source_type === 'addon') {
            $source_type = 'csv';
        }
        $csv_project_dir = $this->get_project_csv_dir((string) $edit_id);
        $csv_project_files = $edit_id ? $this->get_project_csv_files((string) $edit_id) : [];
        $active_csv_name = '';
        if ($csv_project_files !== []) {
            $preferred_name = sanitize_file_name((string) ($data['csv_filename'] ?? ''));
            if ($preferred_name !== '' && file_exists($csv_project_dir . '/' . $preferred_name)) {
                $active_csv_name = $preferred_name;
            } else {
                $active_csv_name = basename((string) $csv_project_files[0]);
            }
        }

        if (class_exists('Xabia_Digixop_Client', false)) {
            Xabia_Digixop_Client::refresh_license_meta_from_hub_if_stale();
        }
        $digixop_license_saved = trim((string) get_option('xabia_digixop_license_key', ''));
        $digi_cache = class_exists('Xabia_Digixop_Client') ? Xabia_Digixop_Client::get_cached_license_meta() : null;
        $digixop_license_masked = self::mask_secret($digixop_license_saved);
        $digi_license_state = (is_array($digi_cache) && !empty($digi_cache['valid']))
            ? __('Activa', 'xabia-intelligence')
            : __('Inactiva', 'xabia-intelligence');
        $digi_tokens_display = '—';
        if (is_array($digi_cache) && array_key_exists('tokens_remaining', $digi_cache) && $digi_cache['tokens_remaining'] !== null && $digi_cache['tokens_remaining'] !== '') {
            $digi_tokens_display = number_format_i18n((int) $digi_cache['tokens_remaining']);
        }
        $digi_checked = (is_array($digi_cache) && !empty($digi_cache['checked_at']))
            ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $digi_cache['checked_at'])
            : '';
        $digi_expiry_display = '';
        if (is_array($digi_cache) && !empty($digi_cache['expiry_date'])) {
            $digi_expiry_display = (string) $digi_cache['expiry_date'];
        }

        $connection_mode_value = get_option('xabia_connection_mode', 'xabia_cloud');
        if (!is_string($connection_mode_value) || !in_array($connection_mode_value, ['xabia_cloud', 'own_infra'], true)) {
            $connection_mode_value = 'xabia_cloud';
        }
        $is_xabia_cloud_ui = class_exists('Xabia_Digixop_Client', false) && Xabia_Digixop_Client::is_xabia_cloud_mode();

        $primary = $data['design']['primary_color'] ?? '#2271b1';
        $bg = $data['design']['bg_color'] ?? '#ffffff';
        $font_size = class_exists('Xabia_Agent_Core', false)
            ? Xabia_Agent_Core::normalize_chat_font_size_em($data['design']['font_size'] ?? '1')
            : ($data['design']['font_size'] ?? '1');
        $avatar_name = $data['design']['avatar_name'] ?? 'Xabia';
        $tts_voice = $data['design']['tts_voice'] ?? 'default';
        $tts_rate = isset($data['design']['tts_rate']) ? (float) $data['design']['tts_rate'] : 1;
        $tts_clean_bold = !empty($data['design']['tts_clean_bold']);
        $tts_clean_italic = !empty($data['design']['tts_clean_italic']);
        $tts_clean_actions = !empty($data['design']['tts_clean_actions']);
        $tts_clean_emojis = !empty($data['design']['tts_clean_emojis']);
        $tts_clean_patterns = $data['design']['tts_clean_patterns'] ?? [];
        $tts_clean_patterns_str = is_array($tts_clean_patterns) ? implode("\n", $tts_clean_patterns) : '';
        $greet = $data['rules']['greeting'] ?? 'Hola, soy tu asistente.';
        $starter_questions_enabled = !isset($data['rules']['starter_questions_enabled']) || !empty($data['rules']['starter_questions_enabled']);
        $starter_questions_raw = '';
        if (!empty($data['rules']['starter_questions']) && is_array($data['rules']['starter_questions'])) {
            $starter_questions_raw = implode("\n", array_map('strval', $data['rules']['starter_questions']));
        }
        $min_score = $data['rules']['min_score'] ?? '0.2';
        ?>
        <div class="wrap xabia-wrapper xabia-admin-app xabia-page-settings">
            <div class="xabia-card xabia-admin-header">
                <div class="xabia-admin-header__brand">
                    <?php
                    if (class_exists('Xabia_Admin_UI', false)) {
                        Xabia_Admin_UI::render_brand_icon('xabia-admin-header__icon', 44);
                    }
                    ?>
                    <div class="xabia-admin-header__text">
                    <?php if (class_exists('Xabia_Admin_UI', false) && !$edit_id) : ?>
                        <div class="xabia-admin-header__wordmark-row">
                            <?php Xabia_Admin_UI::render_brand_logo('xabia-admin-header__wordmark', 32); ?>
                            <span class="xabia-admin-header__edition"><?php echo esc_html__('Agent PRO', 'xabia-intelligence'); ?></span>
                        </div>
                        <p class="xabia-page-subtitle"><?php echo esc_html__('Gestiona agentes de IA y conecta tus datos. Con Conexión Segura Xabia solo necesitas la licencia en la tarjeta de abajo.', 'xabia-intelligence'); ?></p>
                    <?php else : ?>
                    <h1 class="xabia-page-title"><?php echo $edit_id ? esc_html($data['name'] ?? __('Nuevo agente', 'xabia-intelligence')) : esc_html__('Xabia Agent', 'xabia-intelligence'); ?></h1>
                    <p class="xabia-page-subtitle"><?php echo $edit_id
                        ? esc_html__('Configura fuentes de datos, apariencia del chat e historial. Los cambios se guardan al pulsar «Guardar agente».', 'xabia-intelligence')
                        : esc_html__('Gestiona agentes de IA y conecta tus datos. Con Conexión Segura Xabia solo necesitas la licencia en la tarjeta de abajo.', 'xabia-intelligence'); ?></p>
                    <?php endif; ?>
                    </div>
                </div>
                <?php if ($edit_id) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=xabia-settings')); ?>" class="button xabia-btn--ghost"><?php echo esc_html__('← Volver al listado', 'xabia-intelligence'); ?></a>
                <?php endif; ?>
            </div>

            <?php if (!$edit_id && class_exists('Xabia_Updater', false)) : ?>
                <?php Xabia_Updater::render_version_panel(); ?>
            <?php endif; ?>

            <?php if(!$edit_id): ?>
                <div class="xabia-toolbar">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=xabia-settings&edit=new')); ?>" class="button button-primary"><?php echo esc_html__('Nuevo agente', 'xabia-intelligence'); ?></a>
                </div>

                <div class="xabia-agent-grid">
                <?php foreach ($projects as $id => $p) :
                    $is_paused = !empty($p['paused']);
                    $pause_url = wp_nonce_url(
                        admin_url('admin.php?page=xabia-settings&xabia_action=toggle_pause&project_id=' . rawurlencode($id)),
                        'xabia_toggle_pause_' . $id
                    );
                    ?>
                    <div class="xabia-card xabia-agent-tile<?php echo $is_paused ? ' xabia-agent-tile--paused' : ''; ?>">
                        <div>
                            <p class="xabia-agent-tile__name"><?php echo esc_html($p['name']); ?>
                                <?php if ($is_paused) : ?>
                                    <span class="xabia-agent-paused-badge"><?php echo esc_html__('Pausado', 'xabia-intelligence'); ?></span>
                                <?php endif; ?>
                            </p>
                            <span class="xabia-agent-tile__id"><?php echo esc_html(sprintf(__('ID: %s', 'xabia-intelligence'), $id)); ?></span>
                        </div>
                        <div class="xabia-agent-tile__actions xabia-actions">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=xabia-settings&edit=' . rawurlencode($id))); ?>" class="button"><?php echo esc_html__('Editar', 'xabia-intelligence'); ?></a>
                            <a href="<?php echo esc_url($pause_url); ?>" class="button xabia-btn--pause"><?php echo $is_paused ? esc_html__('Activar', 'xabia-intelligence') : esc_html__('Pausar', 'xabia-intelligence'); ?></a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=xabia-settings&xabia_action=delete&project_id=' . rawurlencode($id))); ?>" class="button xabia-btn--danger-outline" onclick="return confirm('¿Borrar?');"><?php echo esc_html__('Borrar', 'xabia-intelligence'); ?></a>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>

                <?php $catalog = self::get_addons_catalog(); ?>
                <div class="xabia-card" style="margin-top:18px;">
                    <h2 class="xabia-card-title"><?php echo esc_html__('Addons disponibles', 'xabia-intelligence'); ?></h2>
                    <p style="margin:0 0 16px;">
                        <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=xabia-addons')); ?>"><?php echo esc_html__('Gestionar Addons y Licencias', 'xabia-intelligence'); ?></a>
                    </p>
                    <div class="xabia-addons-home-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;">
                        <?php foreach ($catalog as $addon) : ?>
                            <?php
                            $active = !empty($addon['active']);
                            $installed = !empty($addon['installed']);
                            $cardStyle = $installed ? '' : 'filter: grayscale(1); opacity:.85;';
                            $pf = (string) ($addon['plugin_file'] ?? '');
                            $hubSlug = self::hub_registry_slug_for_plugin_file($pf);
                            $hubActive = false;
                            if ($hubSlug !== '' && class_exists('Xabia_Addons', false)) {
                                $hubSt = Xabia_Addons::get_hub_status($hubSlug, false);
                                $hubActive = !empty($hubSt['subscription_active']);
                            }
                            ?>
                            <div class="xabia-addon-catalog-mini xabia-panel-muted" style="padding:12px 14px;border-radius:12px;border:1px solid #e2e4e7;box-shadow:0 1px 2px rgba(60,64,67,.08);<?php echo esc_attr($cardStyle); ?>">
                                <?php if ($hubSlug !== '') : ?>
                                    <span class="xabia-addon-status-badge xabia-addon-status-badge--<?php echo $hubActive ? 'active' : 'inactive'; ?>" role="status">
                                        <span class="xabia-addon-status-badge__label"><?php echo $hubActive ? esc_html__('Hub: Activa', 'xabia-intelligence') : esc_html__('Hub: Inactiva', 'xabia-intelligence'); ?></span>
                                    </span>
                                <?php endif; ?>
                                <strong style="display:block;font-size:15px;"><?php echo esc_html((string) ($addon['label'] ?? 'Addon')); ?></strong>
                                <div style="font-size:12px;color:#5f6368;margin:6px 0;line-height:1.45;"><?php echo esc_html((string) ($addon['description'] ?? '')); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <details class="xabia-card xabia-global-advanced" style="margin-top:18px;padding:14px 18px;">
                    <summary style="cursor:pointer;font-weight:600;font-size:15px;outline:none;"><?php echo esc_html__('Avanzado', 'xabia-intelligence'); ?></summary>
                    <div style="margin-top:12px;">
                        <p class="description"><?php echo esc_html__('Redes multi-sitio y herramientas técnicas. No son necesarias para configurar el chat ni las fuentes MEC/Woo habituales.', 'xabia-intelligence'); ?></p>
                        <ul style="list-style:disc;margin:10px 0 0 1.25em;">
                            <li><a href="<?php echo esc_url(admin_url('admin.php?page=xabia-central')); ?>"><?php echo esc_html__('Federación — nodos Xabia Central', 'xabia-intelligence'); ?></a></li>
                            <li><a href="<?php echo esc_url(admin_url('admin.php?page=xabia-federation-nexus')); ?>"><?php echo esc_html__('Federación Nexus (REST y nodos amigos)', 'xabia-intelligence'); ?></a></li>
                        </ul>
                    </div>
                </details>

                <div class="xabia-card xabia-keys-form">
                    <h2 class="xabia-card-title"><?php echo esc_html__('Conexión a la IA', 'xabia-intelligence'); ?></h2>
                    <p class="xabia-card-desc"><?php echo esc_html__('Elige cómo se conecta este sitio al modelo. Con Conexión Segura Xabia, las peticiones van cifradas al servicio Xabia.', 'xabia-intelligence'); ?></p>
                    <form method="post" id="xabia-global-connection-form">
                    <input type="hidden" name="xabia_action" value="save_global_key">
                    <div class="xabia-field-group">
                        <label class="xabia-label" for="xabia_connection_mode"><?php echo esc_html__('Modo de conexión', 'xabia-intelligence'); ?></label>
                        <select name="xabia_connection_mode" id="xabia_connection_mode" class="widefat">
                            <option value="xabia_cloud" <?php selected($connection_mode_value, 'xabia_cloud'); ?>><?php echo esc_html__('Conexión Segura Xabia (recomendado)', 'xabia-intelligence'); ?></option>
                            <option value="own_infra" <?php selected($connection_mode_value, 'own_infra'); ?>><?php echo esc_html__('Infraestructura propia', 'xabia-intelligence'); ?></option>
                        </select>
                        <p class="description" id="xabia-connection-mode-help-cloud"><?php echo esc_html__('Con una licencia activa y saldo de tokens no necesitas OpenAI ni Google Cloud en este panel: el chat y los embeddings usan el proxy Xabia.', 'xabia-intelligence'); ?></p>
                        <p class="description" id="xabia-connection-mode-help-own" style="<?php echo $connection_mode_value === 'own_infra' ? '' : 'display:none;'; ?>"><?php echo esc_html__('Mostramos las claves globales y el motor por agente (OpenAI o Vertex) para quien quiera facturar las APIs en su cuenta.', 'xabia-intelligence'); ?></p>
                    </div>

                    <h3 class="xabia-card-title" style="margin-top:18px;"><?php echo esc_html__('Licencia y saldo', 'xabia-intelligence'); ?></h3>
                    <p class="xabia-card-desc"><?php echo esc_html__('Introduce la licencia que te facilitamos. El estado muestra si el hub la reconoce para este dominio.', 'xabia-intelligence'); ?></p>
                    <p class="xabia-license-status-line" style="margin:0 0 12px;font-weight:600;">
                        <?php echo esc_html__('Estado:', 'xabia-intelligence'); ?>
                        <span class="<?php echo !empty($digi_cache['valid']) ? 'xabia-license-status--ok' : 'xabia-license-status--bad'; ?>"><?php echo esc_html($digi_license_state); ?></span>
                    </p>
                    <div class="xabia-digixop-balance xabia-panel-muted" style="margin-bottom:18px;padding:12px;border-radius:6px;">
                        <strong><?php echo esc_html__('Tokens restantes (última validación)', 'xabia-intelligence'); ?>:</strong>
                        <span id="xabia-digixop-tokens-display"><?php echo esc_html($digi_tokens_display); ?></span>
                        <br>
                        <strong style="margin-top:8px;display:inline-block;"><?php echo esc_html__('Caducidad (licencia)', 'xabia-intelligence'); ?>:</strong>
                        <span id="xabia-digixop-expiry-display"><?php echo $digi_expiry_display !== '' ? esc_html($digi_expiry_display) : '—'; ?></span>
                        <?php if ($digi_checked !== '') : ?>
                            <span class="description" style="display:block;margin-top:6px;"><?php echo esc_html(sprintf(__('Comprobado: %s', 'xabia-intelligence'), $digi_checked)); ?></span>
                        <?php endif; ?>
                        <span id="xabia-digixop-validate-msg" class="description" style="display:block;margin-top:6px;"></span>
                        <p style="margin-top:10px;margin-bottom:0;">
                            <button type="button" class="button" id="xabia-digixop-refresh"><?php echo esc_html__('Actualizar saldo', 'xabia-intelligence'); ?></button>
                        </p>
                    </div>
                    <div class="xabia-field-group">
                        <label class="xabia-label" for="xabia_digixop_license_key"><?php echo esc_html__('Licencia Xabia de este sitio', 'xabia-intelligence'); ?></label>
                        <div class="xabia-license-input-row">
                            <div class="xabia-license-input-wrap">
                                <input type="password" id="xabia_digixop_license_key" name="xabia_digixop_license_key" value="" class="widefat" autocomplete="off" data-has-license="<?php echo $digixop_license_saved !== '' ? '1' : '0'; ?>" placeholder="<?php echo esc_attr($digixop_license_saved !== '' ? __('Pega una licencia nueva (deja vacío para no cambiar la guardada)', 'xabia-intelligence') : __('Pega aquí tu licencia Xabia', 'xabia-intelligence')); ?>">
                            </div>
                            <button type="button" class="button xabia-toggle-license-visibility" id="xabia_digixop_license_toggle" aria-label="<?php echo esc_attr__('Mostrar u ocultar lo escrito en el campo', 'xabia-intelligence'); ?>" aria-pressed="false" title="<?php echo esc_attr__('Mostrar / ocultar', 'xabia-intelligence'); ?>">
                                <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                            </button>
                        </div>
                        <p class="description" style="margin-top:8px;">
                            <button type="button" class="button button-small" id="xabia-digixop-show-saved-license"><?php echo esc_html__('Mostrar licencia guardada', 'xabia-intelligence'); ?></button>
                        </p>
                        <div id="xabia-digixop-saved-key-box" class="xabia-saved-license-reveal" hidden>
                            <code id="xabia-digixop-saved-key-value"></code>
                            <button type="button" class="button-link" id="xabia-digixop-hide-saved-license"><?php echo esc_html__('Ocultar', 'xabia-intelligence'); ?></button>
                        </div>
                        <p class="description">
                            <?php
                            echo $digixop_license_saved !== ''
                                ? esc_html(sprintf(__('Licencia guardada: %s', 'xabia-intelligence'), $digixop_license_masked))
                                : esc_html__('No hay licencia guardada todavía.', 'xabia-intelligence');
                            ?>
                        </p>
                    </div>
                    <p class="xabia-field-group">
                        <label><input type="checkbox" id="xabia_digixop_clear_license" name="xabia_digixop_clear_license" value="1" autocomplete="off"> <?php echo esc_html__('Eliminar licencia guardada de este sitio', 'xabia-intelligence'); ?></label>
                    </p>

                    <div id="xabia-advanced-infra-block" class="xabia-advanced-infra-block" style="<?php echo $connection_mode_value === 'xabia_cloud' ? 'display:none;' : ''; ?>">
                        <details class="xabia-details-advanced" <?php echo $connection_mode_value === 'own_infra' ? 'open' : ''; ?> style="margin-top:16px;border:1px solid #c3c4c7;border-radius:6px;padding:12px;background:#f6f7f7;">
                            <summary style="cursor:pointer;font-weight:600;color:#646970;"><?php echo esc_html__('Claves API y Google Cloud (avanzado)', 'xabia-intelligence'); ?></summary>
                            <p class="description" style="margin:10px 0 0;font-size:12.5px;line-height:1.5;color:#787c82;border-left:3px solid #dcdcde;padding:8px 0 8px 10px;background:#fff;border-radius:0 4px 4px 0;">
                                <?php echo esc_html__('No es necesario tocar esto si usas Conexión Segura Xabia.', 'xabia-intelligence'); ?>
                            </p>
                            <div style="margin-top:14px;color:#2c3338;">
                                <p class="description" style="color:#646970;"><?php echo esc_html__('OpenAI global, ruta JSON de Vertex y Maps. Cada agente puede tener su propia clave OpenAI o ruta JSON.', 'xabia-intelligence'); ?></p>
                                <hr style="margin:14px 0;">
                                <div class="xabia-field-group">
                                    <label class="xabia-label" for="xabia_openai_key"><?php echo esc_html__('OpenAI — clave secreta', 'xabia-intelligence'); ?></label>
                                    <input type="password" id="xabia_openai_key" name="xabia_openai_key" value="<?php echo esc_attr(get_option('xabia_openai_key')); ?>" class="widefat" autocomplete="off">
                                    <p class="description"><?php echo esc_html__('Chat y embeddings cuando el motor del proyecto es OpenAI.', 'xabia-intelligence'); ?></p>
                                </div>
                                <div class="xabia-field-group">
                                    <label class="xabia-label" for="xabia_gcloud_json_path"><?php echo esc_html__('Google Cloud (Vertex AI) — ruta al JSON', 'xabia-intelligence'); ?></label>
                                    <input type="text" id="xabia_gcloud_json_path" name="xabia_gcloud_json_path" value="<?php echo esc_attr(get_option('xabia_gcloud_json_path')); ?>" class="widefat" placeholder="/ruta/absoluta/al/service-account.json" autocomplete="off">
                                    <p class="description"><?php echo esc_html__('Cuenta de servicio Vertex / Gemini. Si un agente deja la ruta vacía, se usa esta global.', 'xabia-intelligence'); ?></p>
                                </div>
                                <div class="xabia-field-group">
                                    <label class="xabia-label" for="xabia_google_key"><?php echo esc_html__('Google Cloud — clave de Maps', 'xabia-intelligence'); ?></label>
                                    <input type="password" id="xabia_google_key" name="xabia_google_key" value="<?php echo esc_attr(get_option('xabia_google_key')); ?>" class="widefat" autocomplete="off">
                                    <p class="description"><?php echo esc_html__('Para mapas en el frontend, si los utilizas.', 'xabia-intelligence'); ?></p>
                                </div>
                                <?php if (class_exists('Xabia_Federation_Nexus', false)) : ?>
                                    <hr style="margin:18px 0;">
                                    <h4 class="xabia-card-title"><?php echo esc_html__('Federación Nexus', 'xabia-intelligence'); ?></h4>
                                    <p class="xabia-field-group">
                                        <label>
                                            <input type="checkbox" name="xabia_federation_bridge_only" value="1" <?php checked((bool) get_option(Xabia_Federation_Nexus::OPTION_BRIDGE_ONLY, false)); ?>>
                                            <?php echo esc_html__('Activar solo modo Puente', 'xabia-intelligence'); ?>
                                        </label>
                                    </p>
                                    <p class="description"><?php echo esc_html__('Si está activo, el shortcode del chat no se muestra en el sitio; este WordPress actúa solo como servidor de datos federados (REST /federate).', 'xabia-intelligence'); ?></p>
                                <?php endif; ?>
                            </div>
                        </details>
                    </div>

                    <div class="xabia-form-actions">
                        <button type="submit" class="button button-primary"><?php echo esc_html__('Guardar configuración', 'xabia-intelligence'); ?></button>
                    </div>
                    </form>
                </div>
                <script>
                jQuery(function($) {
                    var digiNonce = <?php echo wp_json_encode(wp_create_nonce('xabia_admin_nonce')); ?>;
                    function xabiaSyncConnectionModeUi() {
                        var v = $('#xabia_connection_mode').val();
                        var cloud = (v === 'xabia_cloud');
                        $('#xabia-connection-mode-help-cloud').toggle(cloud);
                        $('#xabia-connection-mode-help-own').toggle(!cloud);
                        $('#xabia-advanced-infra-block').toggle(!cloud);
                    }
                    $('#xabia_connection_mode').on('change', xabiaSyncConnectionModeUi);
                    $('#xabia_digixop_clear_license').prop('checked', false);
                    var $licInput = $('#xabia_digixop_license_key');
                    var $licToggle = $('#xabia_digixop_license_toggle');
                    var licShowLabel = <?php echo wp_json_encode(__('Mostrar lo escrito en el campo', 'xabia-intelligence')); ?>;
                    var licHideLabel = <?php echo wp_json_encode(__('Ocultar lo escrito en el campo', 'xabia-intelligence')); ?>;
                    $licToggle.on('click', function() {
                        var isPwd = $licInput.attr('type') === 'password';
                        $licInput.attr('type', isPwd ? 'text' : 'password');
                        $licToggle.attr('aria-pressed', isPwd ? 'true' : 'false');
                        $licToggle.attr('aria-label', isPwd ? licHideLabel : licShowLabel);
                        $licToggle.find('.dashicons').removeClass('dashicons-visibility dashicons-hidden').addClass(isPwd ? 'dashicons-hidden' : 'dashicons-visibility');
                    });
                    $('#xabia-digixop-show-saved-license').on('click', function() {
                        var $box = $('#xabia-digixop-saved-key-box');
                        var $val = $('#xabia-digixop-saved-key-value');
                        $val.text('');
                        $box.prop('hidden', false).show();
                        $.post(ajaxurl, { action: 'xabia_digixop_reveal_saved_license', nonce: digiNonce }, function(r) {
                            if (!r || !r.success) {
                                $val.text((r && r.data && r.data.message) ? r.data.message : 'Error');
                                return;
                            }
                            if (r.data && r.data.nonce) { digiNonce = r.data.nonce; }
                            if (r.data && r.data.empty) {
                                $val.text(<?php echo wp_json_encode(__('(No hay licencia en wp_options. Marca eliminar y guarda, o pega una clave nueva.)', 'xabia-intelligence')); ?>);
                            } else {
                                $val.text(r.data && r.data.license ? String(r.data.license) : '');
                            }
                        });
                    });
                    $('#xabia-digixop-hide-saved-license').on('click', function() {
                        $('#xabia-digixop-saved-key-box').prop('hidden', true).hide();
                        $('#xabia-digixop-saved-key-value').text('');
                    });
                    $('#xabia-digixop-refresh').on('click', function() {
                        var $license = $('#xabia_digixop_license_key');
                        var hasLicense = $.trim(String($license.val() || '')) !== '' || $license.data('has-license') === 1 || $license.data('has-license') === '1';
                        var $msg = $('#xabia-digixop-validate-msg');
                        if (!hasLicense) {
                            $msg.text('<?php echo esc_js(__('Introduce o guarda una licencia antes de actualizar el saldo.', 'xabia-intelligence')); ?>');
                            return;
                        }
                        $msg.text('<?php echo esc_js(__('Consultando…', 'xabia-intelligence')); ?>');
                        $.post(ajaxurl, { action: 'xabia_digixop_validate_license', nonce: digiNonce }, function(r) {
                            if (!r || !r.success) {
                                $msg.text((r && r.data && r.data.message) ? r.data.message : 'Error');
                                return;
                            }
                            if (r.data && r.data.nonce) { digiNonce = r.data.nonce; }
                            var c = r.data && r.data.cached;
                            if (c && c.tokens_remaining != null && c.tokens_remaining !== '') {
                                $('#xabia-digixop-tokens-display').text(String(c.tokens_remaining));
                            }
                            if (c && c.expiry_date) {
                                $('#xabia-digixop-expiry-display').text(String(c.expiry_date));
                            } else if (c && !c.expiry_date) {
                                $('#xabia-digixop-expiry-display').text('—');
                            }
                            var res = r.data && r.data.result;
                            $msg.text(res && res.message ? res.message : '');
                        });
                    });
                });
                </script>

            <?php else: ?>
                <?php if (!empty($_GET['saved']) && sanitize_key((string) $_GET['saved']) === '1') : ?>
                    <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Agente guardado correctamente.', 'xabia-intelligence'); ?></p></div>
                <?php endif; ?>
                <?php if (!empty($_GET['rag_expansions_error']) && sanitize_key((string) $_GET['rag_expansions_error']) === '1') : ?>
                    <div class="notice notice-warning is-dismissible"><p><?php echo esc_html__('No se pudieron guardar algunas expansiones léxicas. Revisa las filas de palabra clave y sinónimos.', 'xabia-intelligence'); ?></p></div>
                <?php endif; ?>
                <form method="post" id="xabia-project-form" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="xabia_action" value="save_project">
                    <input type="hidden" name="project_id" value="<?php echo esc_attr($edit_id === 'new' ? '' : $edit_id); ?>">

                    <?php if ($edit_id !== 'new') :
                        $summary_stats = self::get_vector_counts((string) $edit_id, is_array($data) ? $data : []);
                        $summary_total = (int) ($summary_stats['total'] ?? 0);
                        $summary_ready = (int) ($summary_stats['ready'] ?? 0);
                        $summary_vector_search = !empty($data['rules']['use_vector_search']);
                        $summary_train_pending = class_exists('Xabia_Knowledge_Train', false)
                            ? (int) Xabia_Knowledge_Train::count_pending((string) $edit_id)
                            : 0;
                        ?>
                    <div class="xabia-memory-summary-bar" id="xabia-memory-summary-bar" role="region" aria-label="<?php echo esc_attr__('Resumen de memoria del agente', 'xabia-intelligence'); ?>">
                        <div class="xabia-memory-summary-bar__main">
                            <strong class="xabia-memory-summary-bar__title"><?php echo esc_html__('Memoria del agente', 'xabia-intelligence'); ?></strong>
                            <span class="xabia-memory-summary-bar__stats" id="xabia-memory-summary-stats">
                                <?php if ($summary_total === 0) : ?>
                                    <?php echo esc_html__('Aún no hay datos importados. Usa «Sincronizar datos» en el panel de la derecha.', 'xabia-intelligence'); ?>
                                <?php else : ?>
                                    <?php echo esc_html(sprintf(
                                        __('Registros sincronizados: %1$s · Listos para el chat: %2$s', 'xabia-intelligence'),
                                        number_format_i18n($summary_total),
                                        number_format_i18n($summary_ready)
                                    )); ?>
                                    <?php if ($summary_train_pending > 0) : ?>
                                        <?php echo ' · ' . esc_html(sprintf(__('Pendientes de entrenar: %s', 'xabia-intelligence'), number_format_i18n($summary_train_pending))); ?>
                                    <?php elseif (!$summary_vector_search && $summary_total > 0) : ?>
                                        <?php echo ' · ' . esc_html__('Modo palabras clave (sin embeddings)', 'xabia-intelligence'); ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <p class="xabia-memory-summary-bar__hint description">
                            <?php echo esc_html__('Los botones Sincronizar, Entrenar y subir al Hub están en el panel lateral (a la derecha en pantalla grande; más abajo si la ventana es estrecha).', 'xabia-intelligence'); ?>
                            <span class="xabia-memory-summary-bar__version"><?php echo esc_html(sprintf(__('Core %s', 'xabia-intelligence'), defined('XABIA_VERSION') ? XABIA_VERSION : '')); ?></span>
                        </p>
                        <a href="#xabia-agent-memory-panel" class="button button-small xabia-memory-summary-bar__jump"><?php echo esc_html__('Ir a memoria y acciones', 'xabia-intelligence'); ?></a>
                    </div>
                    <?php endif; ?>

                    <div class="xabia-edit-layout">
                        
                        <div class="xabia-main-card postbox">
                            <div class="xabia-card-inner">
                            <?php
                            $default_tabs = [
                                ['id' => 'tab-data', 'label' => __('General', 'xabia-intelligence')],
                                ['id' => 'tab-analytics', 'label' => __('Analítica', 'xabia-intelligence')],
                                ['id' => 'tab-design', 'label' => __('Ajustes / Apariencia', 'xabia-intelligence')],
                                ['id' => 'tab-history', 'label' => __('Registro de conversaciones', 'xabia-intelligence')],
                            ];
                            $tabs = apply_filters('xabia_agent_admin_tabs', $default_tabs, $edit_id, $data ?? []);
                            if (!is_array($tabs) || $tabs === []) {
                                $tabs = $default_tabs;
                            }
                            ?>
                            <div class="xabia-tab-nav" role="tablist">
                                <?php foreach (array_values($tabs) as $ti => $tab) : ?>
                                    <?php
                                    $tab_id = isset($tab['id']) ? sanitize_key((string) $tab['id']) : '';
                                    $tab_label = isset($tab['label']) ? (string) $tab['label'] : '';
                                    if ($tab_id === '' || $tab_label === '') {
                                        continue;
                                    }
                                    ?>
                                    <button type="button" class="xabia-tab-btn <?php echo $ti === 0 ? 'active' : ''; ?>" data-tab="<?php echo esc_attr($tab_id); ?>" role="tab"><?php echo esc_html($tab_label); ?></button>
                                <?php endforeach; ?>
                            </div>

                            <div id="tab-data" class="xabia-tab-content active">
                                <?php if (class_exists('Xabia_Updater', false)) : ?>
                                    <?php Xabia_Updater::render_version_panel(); ?>
                                <?php endif; ?>
                                <label>Nombre del Agente</label><input type="text" name="name" value="<?php echo esc_attr($data['name'] ?? ''); ?>" class="widefat">
                                <?php if ($edit_id && $edit_id !== 'new'): ?>
                                    <p>Shortcode: <code>[xabia_agent id="<?php echo esc_html($edit_id); ?>"]</code></p>
                                <?php else: ?>
                                    <p class="description">Guarda el agente para ver aquí el shortcode definitivo (ID generado a partir del nombre).</p>
                                <?php endif; ?>
                                <?php
                                $project_language_val = 'es';
                                if (class_exists('Xabia_Knowledge_Ingest', false)) {
                                    $project_language_val = Xabia_Knowledge_Ingest::sanitize_project_language_code(
                                        (string) (($data_cfg['project_language'] ?? $data['project_language'] ?? 'es'))
                                    );
                                } elseif (!empty($data_cfg['project_language']) || !empty($data['project_language'])) {
                                    $pl_src = (string) ($data_cfg['project_language'] ?? $data['project_language'] ?? 'es');
                                    $project_language_val = strtolower(substr(preg_replace('/[^a-z]/i', '', $pl_src), 0, 2)) ?: 'es';
                                }
                                ?>
                                <p style="margin-top:14px;">
                                    <label for="project_language"><strong><?php echo esc_html__('Idioma del catálogo', 'xabia-intelligence'); ?></strong></label><br>
                                    <input type="text" name="project_language" id="project_language" value="<?php echo esc_attr($project_language_val); ?>" maxlength="5" placeholder="es" class="small-text" style="width:72px;" autocomplete="off">
                                </p>
                                <p class="description"><?php echo esc_html__('Código ISO de 2 letras (p. ej. es, eu, fr) — columna language_code de WPML/Polylang. Aplica a cualquier fuente al sincronizar. En sitios multilingües, si falta traducción en este idioma se indexa la versión publicada en cualquier otro idioma. Por defecto: es.', 'xabia-intelligence'); ?></p>
                                <hr>

                                <?php if ($is_xabia_cloud_ui) : ?>
                                <div class="xabia-panel-muted" style="padding:12px;border-radius:6px;margin-bottom:14px;">
                                    <p style="margin:0;"><strong><?php echo esc_html__('Conexión Segura Xabia', 'xabia-intelligence'); ?></strong></p>
                                </div>
                                <input type="hidden" name="ai_driver" value="<?php echo esc_attr($data['ai_driver'] ?? 'openai'); ?>">
                                <input type="hidden" name="gcloud_json_path" value="<?php echo esc_attr($data['gcloud_json_path'] ?? ''); ?>">
                                <?php else : ?>
                                <div class="xabia-vertex-box">
                                    <label><strong>🧠 Motor de Inteligencia (Driver)</strong></label>
                                    <select name="ai_driver" id="ai_driver_select" class="widefat">
                                        <option value="openai" <?php selected($data['ai_driver']??'openai', 'openai'); ?>>OpenAI (ChatGPT)</option>
                                        <option value="google_cloud" <?php selected($data['ai_driver']??'', 'google_cloud'); ?>>Google Cloud (Vertex AI)</option>
                                    </select>
                                    <div id="gcloud_json_wrapper" style="margin-top:10px; <?php echo ($data['ai_driver']??'')==='google_cloud' ? '' : 'display:none;'; ?>">
                                        <label>Ruta absoluta JSON Google Cloud (Service Account)</label>
                                        <input type="text" name="gcloud_json_path" value="<?php echo esc_attr($data['gcloud_json_path']??''); ?>" class="widefat" placeholder="Vacío = usar ruta global">
                                        <p class="description">Opcional. Si se deja vacío, se usa la ruta global definida en ajustes generales (modo infraestructura propia).</p>
                                    </div>
                                    <div style="margin-top:14px;">
                                        <label><strong><?php echo esc_html__('OpenAI — clave por agente (opcional)', 'xabia-intelligence'); ?></strong></label>
                                        <input type="password" name="openai_api_key" value="" class="widefat" autocomplete="new-password" placeholder="<?php echo esc_attr($edit_id && $edit_id !== 'new' ? __('Dejar en blanco para no cambiar la clave guardada', 'xabia-intelligence') : ''); ?>">
                                        <p class="description"><?php echo esc_html__('Si existe, sustituye la clave OpenAI global. Si no hay claves locales y hay licencia, las peticiones pueden ir al proxy Xabia según el modo de conexión.', 'xabia-intelligence'); ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <hr>

                                <label style="font-weight:bold;"><?php echo esc_html__('Fuente de información', 'xabia-intelligence'); ?></label>
                                <input type="hidden" name="sql_preset" id="xabia_sql_preset" value="<?php echo esc_attr((string) ($data_cfg['sql_preset'] ?? '')); ?>">
                                <select name="source_type" id="xabia-source-select" class="widefat" style="margin:8px 0 15px;">
                                    <option value="csv" <?php selected($source_type, 'csv'); ?>>📂 <?php echo esc_html__('Archivos CSV', 'xabia-intelligence'); ?></option>
                                    <option value="local_sql" <?php selected($source_type, 'local_sql'); ?>>🗄️ <?php echo esc_html__('Base de Datos WordPress (Mismo Sitio)', 'xabia-intelligence'); ?></option>
                                    <option value="sql" <?php selected($source_type, 'sql'); ?>>🌐 <?php echo esc_html__('Base de Datos Externa (SQL Remoto)', 'xabia-intelligence'); ?></option>
                                    <?php if ($has_rag_presets) : ?>
                                        <option value="addon" <?php selected($source_type, 'addon'); ?>>🔌 <?php echo esc_html__('Addon nativo (conector automático)', 'xabia-intelligence'); ?></option>
                                    <?php else : ?>
                                        <option value="addon" disabled>🔌 <?php echo esc_html__('Addon nativo (instala y activa Xabia MEC o Xabia Woo)', 'xabia-intelligence'); ?></option>
                                    <?php endif; ?>
                                    <option value="multi" <?php selected($source_type, 'multi'); ?>>🔀 <?php echo esc_html__('Multi-fuente (varias fuentes)', 'xabia-intelligence'); ?></option>
                                    <option value="web_pages" <?php selected($source_type, 'web_pages'); ?>>🌐 <?php echo esc_html__('Páginas web (este sitio)', 'xabia-intelligence'); ?></option>
                                </select>

                                <div id="xabia-sql-remote-default-anchor">
                                <div id="section-sql-remote-fields" class="xabia-panel-muted" style="display:none;">
                                    <p class="description" style="margin:0 0 12px;line-height:1.55;">
                                        <strong><?php echo esc_html__('¿Cuándo rellenar la conexión SQL?', 'xabia-intelligence'); ?></strong><br>
                                        <?php echo esc_html__('Déjala vacía si WooCommerce (u otros datos) están en este mismo WordPress y el prefijo de tablas es el habitual (wp_).', 'xabia-intelligence'); ?><br>
                                        <?php echo esc_html__('Rellénala solo si el catálogo viene de una base de datos en otro servidor (SQL remoto).', 'xabia-intelligence'); ?><br>
                                        <?php echo esc_html__('Si esa base remota usa un prefijo distinto de wp_, indícalo en «Prefijo de tablas» (por ejemplo wpdb_).', 'xabia-intelligence'); ?>
                                    </p>
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                                        <div><label><?php echo esc_html__('Host', 'xabia-intelligence'); ?></label><input type="text" name="sql_host" id="sql_host" value="<?php echo esc_attr($data_cfg['sql_config']['host'] ?? ''); ?>" class="widefat"></div>
                                        <div><label><?php echo esc_html__('Base de datos', 'xabia-intelligence'); ?></label><input type="text" name="sql_name" id="sql_name" value="<?php echo esc_attr($data_cfg['sql_config']['name'] ?? ''); ?>" class="widefat"></div>
                                        <div><label><?php echo esc_html__('Usuario', 'xabia-intelligence'); ?></label><input type="text" name="sql_user" id="sql_user" value="<?php echo esc_attr($data_cfg['sql_config']['user'] ?? ''); ?>" class="widefat"></div>
                                        <div><label><?php echo esc_html__('Prefijo de tablas', 'xabia-intelligence'); ?></label><input type="text" name="sql_prefix" id="sql_prefix" value="<?php echo esc_attr($data_cfg['sql_config']['prefix'] ?? ''); ?>" placeholder="wp_" class="widefat"></div>
                                        <div><label><?php echo esc_html__('Contraseña', 'xabia-intelligence'); ?></label>
                                            <div class="pass-wrapper">
                                                <input type="password" name="sql_pass" id="sql_pass" value="<?php echo esc_attr($data_cfg['sql_config']['pass'] ?? ''); ?>" class="widefat">
                                                <span class="dashicons dashicons-visibility toggle-pass"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                </div>

                                <div id="section-addon" class="source-section" style="display:none;">
                                    <label for="addon_slug" class="screen-reader-text"><?php echo esc_html__('Addon de datos', 'xabia-intelligence'); ?></label>
                                    <select name="addon_slug" id="addon_slug" class="widefat">
                                        <?php foreach ($available_addons_rag as $slug => $info) : ?>
                                            <?php
                                            $opt_label = $rag_preset_labels[$slug] ?? (isset($info['name']) ? (string) $info['name'] : (string) $slug);
                                            ?>
                                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($data_cfg['addon_slug'] ?? '', $slug); ?>><?php echo esc_html($opt_label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description" id="xabia-mec-addon-hint" style="margin-top:10px;<?php echo ($source_type === 'addon' && ($data_cfg['addon_slug'] ?? '') === 'mec') ? '' : 'display:none;'; ?>">
                                        <span class="dashicons dashicons-info" style="vertical-align:text-bottom;line-height:1.2;" aria-hidden="true"></span>
                                        <?php echo esc_html__('Usa la pestaña MEC para la conexión a base de datos y el mapeo cuando este agente toma eventos de Modern Events Calendar.', 'xabia-intelligence'); ?>
                                    </p>
                                    <p class="description" id="xabia-woo-addon-hint" style="margin-top:10px;<?php echo ($source_type === 'addon' && ($data_cfg['addon_slug'] ?? '') === 'woo') ? '' : 'display:none;'; ?>">
                                        <span class="dashicons dashicons-info" style="vertical-align:text-bottom;line-height:1.2;" aria-hidden="true"></span>
                                        <?php echo esc_html__('Productos publicados, precio y stock en el catálogo; en el chat puedes usar acciones de carrito donde el preset lo indique.', 'xabia-intelligence'); ?>
                                    </p>
                                    <div id="xabia-woo-remote-shop-row" style="margin-top:12px;<?php echo ($source_type === 'addon' && ($data_cfg['addon_slug'] ?? '') === 'woo') ? '' : 'display:none;'; ?>">
                                        <label for="woo_remote_shop_url"><strong><?php echo esc_html__('URL pública de la tienda Woo (remoto)', 'xabia-intelligence'); ?></strong></label>
                                        <input type="url" name="woo_remote_shop_url" id="woo_remote_shop_url" class="widefat" value="<?php echo esc_attr($data['rules']['woo_remote_shop_url'] ?? ''); ?>" placeholder="https://tu-tienda.com">
                                        <p class="description"><?php echo esc_html__('Si este sitio no tiene WooCommerce pero el catálogo viene de una BD remota, indica la URL base de la tienda real. El chat generará enlaces ?add-to-cart=ID hacia ese dominio (y usará la misma conexión SQL para listar cupones).', 'xabia-intelligence'); ?></p>
                                    </div>
                                    <div id="xabia-mec-remote-site-row" style="margin-top:12px;<?php echo (($source_type === 'addon' && ($data_cfg['addon_slug'] ?? '') === 'mec') || ($data_cfg['sql_preset'] ?? '') === 'mec_remote') ? '' : 'display:none;'; ?>">
                                        <label for="mec_remote_site_url"><strong><?php echo esc_html__('URL base pública de eventos MEC', 'xabia-intelligence'); ?></strong></label>
                                        <input type="url" name="mec_remote_site_url" id="mec_remote_site_url" class="widefat" value="<?php echo esc_attr($data['rules']['mec_remote_site_url'] ?? ''); ?>" placeholder="https://tu-sitio.com/eu">
                                        <p class="description"><?php echo esc_html__('Opcional si MEC está en este mismo WordPress (se usará el permalink nativo). Obligatoria si el catálogo viene de otra BD: URL base sin el slug de archivo, p. ej. https://tu-sitio.com/eu con WPML.', 'xabia-intelligence'); ?></p>
                                        <label for="mec_events_rewrite_slug" style="display:block;margin-top:10px;"><strong><?php echo esc_html__('Slug de archivo de eventos', 'xabia-intelligence'); ?></strong></label>
                                        <input type="text" name="mec_events_rewrite_slug" id="mec_events_rewrite_slug" class="regular-text" value="<?php echo esc_attr($data['rules']['mec_events_rewrite_slug'] ?? 'actividades'); ?>" placeholder="ekintzak">
                                        <p class="description"><?php echo esc_html__('Segmento tras la base (p. ej. ekintzak → …/eu/ekintzak/nombre-evento/). Debe coincidir con los permalinks del sitio donde se publican las fichas.', 'xabia-intelligence'); ?></p>
                                    </div>
                                    <div id="xabia-addon-button-default-anchor">
                                    <button type="button" id="btn-test-addon" class="button button-primary" style="margin-top:10px;">🔗 <?php echo esc_html__('Conectar y mapear', 'xabia-intelligence'); ?></button>
                                    </div>
                                </div>

                                <div id="section-sql" class="source-section" style="display:none;">
                                    <div class="xabia-panel-muted" style="margin-bottom:12px;">
                                        <strong><?php echo esc_html__('Presets para SQL remoto', 'xabia-intelligence'); ?></strong>
                                        <p class="description" style="margin:4px 0 10px;"><?php echo esc_html__('Usa un preset cuando la base externa es WordPress/MEC pero el addon no está instalado en este sitio.', 'xabia-intelligence'); ?></p>
                                        <button type="button" id="xabia-apply-remote-mec-preset" class="button"><?php echo esc_html__('Usar preset MEC remoto', 'xabia-intelligence'); ?></button>
                                        <span id="xabia-remote-mec-preset-state" class="description" style="margin-left:8px;<?php echo (($data_cfg['sql_preset'] ?? '') === 'mec_remote') ? '' : 'display:none;'; ?>"><?php echo esc_html__('Preset MEC remoto activo.', 'xabia-intelligence'); ?></span>
                                    </div>
                                    <textarea name="sql_query" id="sql_query" class="sql-box"><?php echo esc_textarea($data_cfg['sql_config']['query'] ?? ''); ?></textarea>
                                    <p style="margin-top:10px;">
                                        <button type="button" id="btn-test-sql" class="button"><?php echo esc_html__('Test SQL manual', 'xabia-intelligence'); ?></button>
                                        <button type="button" id="btn-xabia-cpt-assistant" class="button" style="margin-left:8px;"><?php echo esc_html__('Asistente CPT', 'xabia-intelligence'); ?></button>
                                    </p>
                                    <div class="xabia-cpt-meta-tool xabia-panel-muted" style="margin-top:14px;">
                                        <label class="xabia-label" for="xabia-cpt-meta-slug"><?php echo esc_html__('Campos de un CPT (meta + ACF)', 'xabia-intelligence'); ?></label>
                                        <p class="description" style="margin-top:0;"><?php echo esc_html__('Slug del tipo de contenido. Añade columnas meta detectadas y nombres de campos ACF a los selectores del mapeo.', 'xabia-intelligence'); ?></p>
                                        <input type="text" id="xabia-cpt-meta-slug" class="regular-text" placeholder="<?php echo esc_attr__('ej. mi-ente', 'xabia-intelligence'); ?>" autocomplete="off">
                                        <button type="button" class="button" id="xabia-btn-load-cpt-meta" style="margin-left:8px;vertical-align:middle;"><?php echo esc_html__('Añadir al mapeo', 'xabia-intelligence'); ?></button>
                                    </div>
                                </div>

                                <div id="section-csv" class="source-section" style="display:none;">
                                    <input type="hidden" name="selected_csv_file" id="selected_csv_file" value="<?php echo esc_attr($active_csv_name); ?>">
                                    <div id="xabia-csv-state" data-project-id="<?php echo esc_attr($edit_id); ?>" data-active-csv="<?php echo esc_attr($active_csv_name); ?>">
                                        <div id="xabia-csv-has-file" class="xabia-panel-muted" style="<?php echo $active_csv_name !== '' ? '' : 'display:none;'; ?>">
                                            <p style="margin:0 0 8px;"><strong><?php echo esc_html__('Archivo en uso:', 'xabia-intelligence'); ?></strong> <span id="xabia-csv-active-name"><?php echo esc_html($active_csv_name); ?></span></p>
                                            <button type="button" id="xabia-csv-delete-btn" class="button xabia-btn--danger-outline"><?php echo esc_html__('Eliminar CSV', 'xabia-intelligence'); ?></button>
                                        </div>
                                        <div id="xabia-csv-no-file" class="xabia-panel-muted" style="<?php echo $active_csv_name === '' ? '' : 'display:none;'; ?>">
                                            <label style="display:block; margin-bottom:8px; font-weight:bold;"><?php echo esc_html__('Subir CSV', 'xabia-intelligence'); ?></label>
                                            <input type="file" id="xabia_csv_upload" accept=".csv,text/csv" style="margin-bottom:10px;">
                                            <button type="button" id="xabia-csv-upload-btn" class="button button-primary"><?php echo esc_html__('Subir CSV', 'xabia-intelligence'); ?></button>
                                        </div>
                                    </div>
                                    <button type="button" id="btn-scan-csv" class="button">🔍 Scan CSV</button>
                                    <div id="csv-feedback" style="margin-top:10px;"></div>
                                </div>

                                <?php
                                $saved_web_page_ids = class_exists('Xabia_Web_Pages_Source', false)
                                    ? Xabia_Web_Pages_Source::parse_page_ids($data_cfg['web_page_ids'] ?? [])
                                    : [];
                                $web_pages_public_html = !empty($data_cfg['web_pages_use_public_html']);
                                ?>
                                <div id="section-web_pages" class="source-section" style="display:none;">
                                    <p class="description"><?php echo esc_html__('Indexa páginas o entradas publicadas de este WordPress en la memoria del agente (RAG). Respeta el idioma del proyecto (WPML).', 'xabia-intelligence'); ?></p>
                                    <?php
                                    if (class_exists('Xabia_Web_Pages_Source', false)) {
                                        Xabia_Web_Pages_Source::render_page_picker(
                                            'web_page_ids',
                                            'web_page_ids_manual',
                                            $saved_web_page_ids,
                                            __('Páginas a indexar', 'xabia-intelligence'),
                                            __('Marca las páginas institucionales (qué es Ondarea, jornadas, contacto…). Tras guardar, pulsa «Sincronizar datos».', 'xabia-intelligence')
                                        );
                                    }
                                    ?>
                                    <label style="display:flex;align-items:center;gap:8px;margin:10px 0 0;">
                                        <input type="checkbox" name="web_pages_use_public_html" value="1" <?php checked($web_pages_public_html); ?>>
                                        <?php echo esc_html__('Leer HTML público (recomendado con Elementor/page builders)', 'xabia-intelligence'); ?>
                                    </label>
                                    <p class="description" style="margin:6px 0 0;"><?php echo esc_html__('Si está activo, descarga la URL pública de cada página (como la demo de xabia.ai) además del contenido de la base de datos.', 'xabia-intelligence'); ?></p>
                                </div>

                                <div id="xabia-supplemental-web-pages" class="xabia-panel-muted" style="display:none;margin-top:12px;">
                                    <p style="margin:0 0 8px;"><strong><?php echo esc_html__('Páginas web complementarias', 'xabia-intelligence'); ?></strong></p>
                                    <p class="description" style="margin:0 0 10px;"><?php echo esc_html__('Opcional: añade contenido institucional además de la fuente principal (p. ej. Addon MEC + páginas «Qué es Ondarea»).', 'xabia-intelligence'); ?></p>
                                    <?php
                                    if (class_exists('Xabia_Web_Pages_Source', false)) {
                                        Xabia_Web_Pages_Source::render_page_picker(
                                            'web_page_ids',
                                            'web_page_ids_manual',
                                            $saved_web_page_ids,
                                            __('Páginas complementarias', 'xabia-intelligence')
                                        );
                                    }
                                    ?>
                                    <label style="display:flex;align-items:center;gap:8px;margin:8px 0 0;">
                                        <input type="checkbox" name="web_pages_use_public_html" value="1" <?php checked($web_pages_public_html); ?>>
                                        <?php echo esc_html__('Leer HTML público de esas páginas', 'xabia-intelligence'); ?>
                                    </label>
                                </div>

                                <div id="section-multi" class="source-section" style="display:none;">
                                    <p class="description"><?php echo esc_html__('Combina hasta dos fuentes (por ejemplo tabla WordPress y un CSV). Cada una tiene su consulta o archivo y su mapeo.', 'xabia-intelligence'); ?></p>
                                    <?php
                                    $sources_data = $data_cfg['sources'] ?? [];
                                    for ($si = 0; $si < 2; $si++):
                                        $sd = $sources_data[$si] ?? [];
                                        $st = $sd['type'] ?? ($si === 0 ? 'local_sql' : 'csv');
                                        $sc = $sd['sql_config'] ?? [];
                                        $csv_fn = $sd['csv_filename'] ?? '';
                                        $multi_web_ids = class_exists('Xabia_Web_Pages_Source', false)
                                            ? Xabia_Web_Pages_Source::parse_page_ids($sd['web_page_ids'] ?? [])
                                            : [];
                                        $multi_web_html = !empty($sd['web_pages_use_public_html']);
                                    ?>
                                    <div class="xabia-multi-source-box">
                                        <h4 style="margin-top:0;">Fuente <?php echo $si + 1; ?></h4>
                                        <label>Tipo</label>
                                        <select name="sources[<?php echo $si; ?>][type]" class="multi-source-type widefat" data-idx="<?php echo $si; ?>" style="margin-bottom:10px;">
                                            <option value="local_sql" <?php selected($st, 'local_sql'); ?>>🗄️ <?php echo esc_html__('Base de Datos WordPress (Mismo Sitio)', 'xabia-intelligence'); ?></option>
                                            <option value="sql" <?php selected($st, 'sql'); ?>>🌐 <?php echo esc_html__('Base de Datos Externa (SQL Remoto)', 'xabia-intelligence'); ?></option>
                                            <option value="csv" <?php selected($st, 'csv'); ?>>📂 <?php echo esc_html__('Archivos CSV', 'xabia-intelligence'); ?></option>
                                            <option value="web_pages" <?php selected($st, 'web_pages'); ?>>🌐 <?php echo esc_html__('Páginas web (este sitio)', 'xabia-intelligence'); ?></option>
                                        </select>
                                        <div class="multi-source-sql multi-source-panel" data-idx="<?php echo $si; ?>" style="display:<?php echo ($st === 'sql' || $st === 'local_sql') ? 'block' : 'none'; ?>;">
                                            <div class="multi-source-remote-fields" data-idx="<?php echo $si; ?>" style="display:<?php echo $st === 'sql' ? 'block' : 'none'; ?>; margin-bottom:8px;">
                                                <p class="description" style="margin:0 0 8px;line-height:1.5;">
                                                    <?php echo esc_html__('Solo para SQL remoto: déjalo vacío si los datos están en este WordPress (prefijo wp_). Rellena host/BD/usuario si la base es externa; indica el prefijo solo si no es wp_.', 'xabia-intelligence'); ?>
                                                </p>
                                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                                                <div><label>Host</label><input type="text" name="sources[<?php echo $si; ?>][sql_host]" value="<?php echo esc_attr($sc['host'] ?? ''); ?>" class="widefat"></div>
                                                <div><label>DB</label><input type="text" name="sources[<?php echo $si; ?>][sql_name]" value="<?php echo esc_attr($sc['name'] ?? ''); ?>" class="widefat"></div>
                                                <div><label>Usuario</label><input type="text" name="sources[<?php echo $si; ?>][sql_user]" value="<?php echo esc_attr($sc['user'] ?? ''); ?>" class="widefat"></div>
                                                <div><label>Pass</label><input type="password" name="sources[<?php echo $si; ?>][sql_pass]" value="<?php echo esc_attr($sc['pass'] ?? ''); ?>" class="widefat"></div>
                                                <div><label>Prefijo (opc.)</label><input type="text" name="sources[<?php echo $si; ?>][sql_prefix]" value="<?php echo esc_attr($sc['prefix'] ?? ''); ?>" placeholder="wp_" class="widefat"></div>
                                                </div>
                                            </div>
                                            <textarea name="sources[<?php echo $si; ?>][sql_query]" class="sql-box widefat" rows="4" placeholder="SELECT ... FROM {prefix}posts ..."><?php echo esc_textarea($sc['query'] ?? ''); ?></textarea>
                                            <p style="margin-top:8px;">
                                                <button type="button" class="button multi-test-sql" data-idx="<?php echo $si; ?>"><?php echo esc_html__('Test SQL y mapear', 'xabia-intelligence'); ?></button>
                                                <button type="button" class="button xabia-cpt-assistant-multi" data-idx="<?php echo $si; ?>" style="margin-left:8px;"><?php echo esc_html__('Asistente CPT', 'xabia-intelligence'); ?></button>
                                            </p>
                                        </div>
                                        <div class="multi-source-csv multi-source-panel" data-idx="<?php echo $si; ?>" style="display:<?php echo $st === 'csv' ? 'block' : 'none'; ?>;">
                                            <label>Archivo CSV</label>
                                            <select name="sources[<?php echo $si; ?>][csv_filename]" class="multi-csv-select widefat" data-idx="<?php echo $si; ?>" data-saved="<?php echo esc_attr($csv_fn); ?>">
                                                <option value="">-- Selecciona CSV --</option>
                                                <?php if ($csv_fn !== '') : ?>
                                                    <option value="<?php echo esc_attr($csv_fn); ?>" selected><?php echo esc_html($csv_fn); ?></option>
                                                <?php endif; ?>
                                            </select>
                                            <div style="display:flex; gap:8px; align-items:center; margin:8px 0;">
                                                <input type="file" class="multi-csv-upload-input" data-idx="<?php echo $si; ?>" accept=".csv,text/csv" style="flex:1;">
                                                <button type="button" class="button button-primary multi-csv-upload-btn" data-idx="<?php echo $si; ?>"><?php echo esc_html__('Subir CSV', 'xabia-intelligence'); ?></button>
                                            </div>
                                            <button type="button" class="button multi-scan-csv" data-idx="<?php echo $si; ?>">🔍 Scan CSV y mapear</button>
                                            <span class="multi-csv-feedback" data-idx="<?php echo $si; ?>" style="display:block;margin-top:8px;"></span>
                                        </div>
                                        <div class="multi-source-web_pages multi-source-panel" data-idx="<?php echo $si; ?>" style="display:<?php echo $st === 'web_pages' ? 'block' : 'none'; ?>;">
                                            <?php
                                            if (class_exists('Xabia_Web_Pages_Source', false)) {
                                                Xabia_Web_Pages_Source::render_page_picker(
                                                    'sources[' . $si . '][web_page_ids]',
                                                    'sources[' . $si . '][web_page_ids_manual]',
                                                    $multi_web_ids,
                                                    __('Páginas a indexar (fuente ' . ($si + 1) . ')', 'xabia-intelligence')
                                                );
                                            }
                                            ?>
                                            <label style="display:flex;align-items:center;gap:8px;margin:8px 0 0;">
                                                <input type="checkbox" name="sources[<?php echo $si; ?>][web_pages_use_public_html]" value="1" <?php checked($multi_web_html); ?>>
                                                <?php echo esc_html__('Leer HTML público', 'xabia-intelligence'); ?>
                                            </label>
                                        </div>
                                        <h4 style="margin:15px 0 8px;">Mapeo Fuente <?php echo $si + 1; ?></h4>
                                        <div class="multi-attr-container" data-idx="<?php echo $si; ?>">
                                            <?php
                                            $xabia_pri_cols = ['post_content', 'post_title', 'post_excerpt'];
                                            $all_src_cols = [];
                                            foreach (($sd['attributes'] ?? []) as $a2) {
                                                if (!empty($a2['csv_col'])) {
                                                    $all_src_cols[] = (string) $a2['csv_col'];
                                                }
                                            }
                                            $all_src_cols = array_values(array_unique($all_src_cols));
                                            sort($all_src_cols, SORT_STRING);
                                            foreach (($sd['attributes'] ?? []) as $i => $attr) :
                                                $col = $attr['csv_col'] ?? '';
                                                $is_pri = in_array($col, $xabia_pri_cols, true);
                                                $import_on = self::xabia_attr_import_rag_enabled($attr);
                                            ?>
                                            <div class="row-repeater-box<?php echo $import_on ? '' : ' xabia-rag-excluded'; ?>">
                                                <div class="row-col-select-wrap">
                                                    <?php if (strtoupper((string) $col) === 'ID') : ?>
                                                        <span class="xabia-field-id-hint dashicons dashicons-info" title="<?php echo esc_attr__('ID de WordPress: no se envía al entrenamiento IA. Marca ENTE en el nombre/título del registro, no en ID.', 'xabia-intelligence'); ?>"></span>
                                                    <?php elseif ($is_pri) : ?>
                                                        <span class="xabia-field-brain dashicons dashicons-superhero" title="<?php echo esc_attr__('Campo prioritario para el contexto del agente', 'xabia-intelligence'); ?>"></span>
                                                    <?php endif; ?>
                                                    <label class="xabia-import-rag-wrap" title="<?php echo esc_attr__('Incluir en el texto de entrenamiento (embeddings)', 'xabia-intelligence'); ?>">
                                                        <input type="checkbox" class="xabia-import-rag-cb" name="sources[<?php echo (int) $si; ?>][attributes][<?php echo (int) $i; ?>][import_rag]" value="1" <?php checked($import_on); ?>>
                                                        <span><?php echo esc_html__('IA', 'xabia-intelligence'); ?></span>
                                                    </label>
                                                    <select class="xabia-col-selector widefat" name="sources[<?php echo $si; ?>][attributes][<?php echo $i; ?>][csv_col]">
                                                        <option value=""><?php echo esc_html__('— Columna / campo —', 'xabia-intelligence'); ?></option>
                                                        <option value="<?php echo esc_attr($col); ?>" selected><?php echo esc_html($col); ?></option>
                                                    </select>
                                                </div>
                                                <div class="row-inputs">
                                                    <input type="text" name="sources[<?php echo $si; ?>][attributes][<?php echo $i; ?>][label]" value="<?php echo esc_attr($attr['label']); ?>" placeholder="Etiqueta" style="width:30%;">
                                                    <select name="sources[<?php echo $si; ?>][attributes][<?php echo $i; ?>][visual_role]" style="width:30%;">
                                                        <option value="none" <?php selected($attr['visual_role']??'','none'); ?>>Sin Rol</option>
                                                        <option value="title" <?php selected($attr['visual_role']??'','title'); ?>>Título</option>
                                                        <option value="info" <?php selected($attr['visual_role']??'','info'); ?>>Info</option>
                                                        <option value="date" <?php selected($attr['visual_role']??'','date'); ?>>Fecha</option>
                                                        <option value="img" <?php selected($attr['visual_role']??'','img'); ?>>Imagen</option>
                                                        <option value="logotipo" <?php selected($attr['visual_role']??'','logotipo'); ?>>Logotipo</option>
                                                        <option value="tel" <?php selected($attr['visual_role']??'','tel'); ?>>Teléfono</option>
                                                        <option value="web" <?php selected($attr['visual_role']??'','web'); ?>>Web</option>
                                                        <option value="email" <?php selected($attr['visual_role']??'','email'); ?>>Email</option>
                                                        <option value="map" <?php selected($attr['visual_role']??'','map'); ?>>Mapa</option>
                                                    </select>
                                                    <div class="xabia-ente-tools">
                                                    <div class="check-ente-wrapper"><input type="checkbox" name="sources[<?php echo $si; ?>][attributes][<?php echo $i; ?>][is_ente]" value="1" <?php checked($attr['is_ente']??0, 1); ?>> ENTE</div>
                                                    <?php
                                                    $saved_elc = !empty($attr['is_ente']) ? (string) ($attr['ente_label_col'] ?? '') : '';
                                                    ?>
                                                    <div class="xabia-ente-label-wrap" style="<?php echo !empty($attr['is_ente']) ? '' : 'display:none;'; ?>">
                                                        <label class="screen-reader-text" for="xabia-ente-lbl-m-<?php echo (int) $si; ?>-<?php echo (int) $i; ?>"><?php echo esc_html__('Nombre visible del ente', 'xabia-intelligence'); ?></label>
                                                        <select id="xabia-ente-lbl-m-<?php echo (int) $si; ?>-<?php echo (int) $i; ?>" name="sources[<?php echo $si; ?>][attributes][<?php echo $i; ?>][ente_label_col]" class="xabia-ente-label-col widefat">
                                                            <option value=""><?php echo esc_html__('Mismo campo (valor del ente)', 'xabia-intelligence'); ?></option>
                                                            <?php foreach ($all_src_cols as $ac) : ?>
                                                                <option value="<?php echo esc_attr($ac); ?>" <?php selected($saved_elc, $ac); ?>><?php echo esc_html($ac); ?></option>
                                                            <?php endforeach; ?>
                                                            <?php if ($saved_elc !== '' && ! in_array($saved_elc, $all_src_cols, true)) : ?>
                                                                <option value="<?php echo esc_attr($saved_elc); ?>" selected><?php echo esc_html($saved_elc); ?></option>
                                                            <?php endif; ?>
                                                        </select>
                                                    </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <input type="text" name="sources[<?php echo $si; ?>][attributes][<?php echo $i; ?>][instruction]" value="<?php echo esc_attr($attr['instruction']??''); ?>" class="widefat" placeholder="Instrucción IA..." style="margin-bottom:10px;">
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endfor; ?>
                                </div>

                                <div id="xabia-mapping-slot-general">
                                <div id="xabia-mapping-panel">
                                <h3 class="xabia-section-title" id="label-single-attr"><?php echo esc_html__('Mapeo de atributos', 'xabia-intelligence'); ?></h3>
                                <div class="xabia-rag-toolbar">
                                    <p class="description"><?php echo esc_html__('Marca «IA» en cada columna que debe alimentar el conocimiento del agente. Las filas atenuadas no se envían al entrenar (ahorra tokens). El icono ℹ en «ID» indica identificador técnico de WordPress: no marques ENTE ahí; usa el campo nombre/título del ente.', 'xabia-intelligence'); ?></p>
                                    <p class="xabia-rag-toolbar-actions">
                                        <button type="button" class="button" id="xabia-rag-preset-recommended"><?php echo esc_html__('Solo campos útiles (recomendado)', 'xabia-intelligence'); ?></button>
                                        <button type="button" class="button" id="xabia-rag-exclude-media"><?php echo esc_html__('Excluir imágenes y URLs', 'xabia-intelligence'); ?></button>
                                        <button type="button" class="button" id="xabia-rag-include-all"><?php echo esc_html__('Incluir todas', 'xabia-intelligence'); ?></button>
                                    </p>
                                </div>
                                <div id="attr-container">
                                    <?php
                                    $xabia_pri_cols = ['post_content', 'post_title', 'post_excerpt'];
                                    $all_attr_cols = [];
                                    foreach (($data_cfg['attributes'] ?? []) as $a2) {
                                        if (!empty($a2['csv_col'])) {
                                            $all_attr_cols[] = (string) $a2['csv_col'];
                                        }
                                    }
                                    $all_attr_cols = array_values(array_unique($all_attr_cols));
                                    sort($all_attr_cols, SORT_STRING);
                                    foreach (($data_cfg['attributes'] ?? []) as $i => $attr) :
                                        $col = $attr['csv_col'] ?? '';
                                        $is_pri = in_array($col, $xabia_pri_cols, true);
                                        $import_on = self::xabia_attr_import_rag_enabled($attr);
                                    ?>
                                    <div class="row-repeater-box<?php echo $import_on ? '' : ' xabia-rag-excluded'; ?>">
                                        <div class="row-col-select-wrap">
                                            <?php if (strtoupper((string) $col) === 'ID') : ?>
                                                <span class="xabia-field-id-hint dashicons dashicons-info" title="<?php echo esc_attr__('ID de WordPress: no se envía al entrenamiento IA. Marca ENTE en el nombre/título del registro, no en ID.', 'xabia-intelligence'); ?>"></span>
                                            <?php elseif ($is_pri) : ?>
                                                <span class="xabia-field-brain dashicons dashicons-superhero" title="<?php echo esc_attr__('Campo prioritario para el contexto del agente', 'xabia-intelligence'); ?>"></span>
                                            <?php endif; ?>
                                            <label class="xabia-import-rag-wrap" title="<?php echo esc_attr__('Incluir en el texto de entrenamiento (embeddings)', 'xabia-intelligence'); ?>">
                                                <input type="checkbox" class="xabia-import-rag-cb" name="attributes[<?php echo (int) $i; ?>][import_rag]" value="1" <?php checked($import_on); ?>>
                                                <span><?php echo esc_html__('IA', 'xabia-intelligence'); ?></span>
                                            </label>
                                            <select class="xabia-col-selector widefat" name="attributes[<?php echo $i; ?>][csv_col]">
                                                <option value=""><?php echo esc_html__('— Columna / campo —', 'xabia-intelligence'); ?></option>
                                                <option value="<?php echo esc_attr($col); ?>" selected><?php echo esc_html($col); ?></option>
                                            </select>
                                        </div>
                                        <div class="row-inputs">
                                            <input type="text" name="attributes[<?php echo $i; ?>][label]" value="<?php echo esc_attr($attr['label']); ?>" placeholder="Etiqueta" style="width:30%;">
                                            <select name="attributes[<?php echo $i; ?>][visual_role]" style="width:30%;">
                                                <option value="none" <?php selected($attr['visual_role'],'none'); ?>>Sin Rol</option>
                                                <option value="title" <?php selected($attr['visual_role'],'title'); ?>>Título</option>
                                                <option value="info" <?php selected($attr['visual_role'],'info'); ?>>Info (Texto)</option>
                                                <option value="date" <?php selected($attr['visual_role'],'date'); ?>>Fecha</option>
                                                <option value="img" <?php selected($attr['visual_role'],'img'); ?>>Imagen</option>
                                                <option value="logotipo" <?php selected($attr['visual_role'],'logotipo'); ?>>Logotipo</option>
                                                <option value="tel" <?php selected($attr['visual_role'],'tel'); ?>>Teléfono</option>
                                                <option value="web" <?php selected($attr['visual_role'],'web'); ?>>Web</option>
                                                <option value="email" <?php selected($attr['visual_role'],'email'); ?>>Email</option>
                                                <option value="map" <?php selected($attr['visual_role'],'map'); ?>>Mapa</option>
                                            </select>
                                            <div class="xabia-ente-tools">
                                            <div class="check-ente-wrapper">
                                                <input type="checkbox" name="attributes[<?php echo $i; ?>][is_ente]" value="1" <?php checked($attr['is_ente']??0, 1); ?>> ENTE
                                            </div>
                                            <?php
                                            $saved_elc = !empty($attr['is_ente']) ? (string) ($attr['ente_label_col'] ?? '') : '';
                                            ?>
                                            <div class="xabia-ente-label-wrap" style="<?php echo !empty($attr['is_ente']) ? '' : 'display:none;'; ?>">
                                                <label class="screen-reader-text" for="xabia-ente-lbl-<?php echo (int) $i; ?>"><?php echo esc_html__('Nombre visible del ente', 'xabia-intelligence'); ?></label>
                                                <select id="xabia-ente-lbl-<?php echo (int) $i; ?>" name="attributes[<?php echo $i; ?>][ente_label_col]" class="xabia-ente-label-col widefat">
                                                    <option value=""><?php echo esc_html__('Mismo campo (valor del ente)', 'xabia-intelligence'); ?></option>
                                                    <?php foreach ($all_attr_cols as $ac) : ?>
                                                        <option value="<?php echo esc_attr($ac); ?>" <?php selected($saved_elc, $ac); ?>><?php echo esc_html($ac); ?></option>
                                                    <?php endforeach; ?>
                                                    <?php if ($saved_elc !== '' && ! in_array($saved_elc, $all_attr_cols, true)) : ?>
                                                        <option value="<?php echo esc_attr($saved_elc); ?>" selected><?php echo esc_html($saved_elc); ?></option>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="text" name="attributes[<?php echo $i; ?>][instruction]" value="<?php echo esc_attr($attr['instruction']??''); ?>" class="widefat" placeholder="Instrucción para la IA..." style="margin-bottom:15px;">
                                    <?php endforeach; ?>
                                </div>
                                </div>
                                </div>

                            </div>

                            <div id="tab-design" class="xabia-tab-content">
                                <label>Nombre del asistente (avatar)</label>
                                <input type="text" name="avatar_name" value="<?php echo esc_attr($avatar_name); ?>" placeholder="Xabia" class="regular-text" maxlength="80">
                                <p class="description">Nombre que aparece en el chat junto a cada mensaje del bot (ej. Xabia, Nora, Bot tienda). En modo tótem puedes usar un nombre distinto por pantalla con el shortcode: <code>avatar_name="Totem 1"</code>.</p>
                                <hr>
                                <label>Color Identidad (Botones/Asistente)</label><input type="text" name="primary_color" value="<?php echo esc_attr($primary); ?>" class="xabia-color-field">
                                <hr>
                                <label>Color Fondo Chat</label><input type="text" name="bg_color" value="<?php echo esc_attr($bg); ?>" class="xabia-color-field">
                                <hr>
                                <label>Tamaño fuente (em)</label>
                                <input type="number" name="font_size" value="<?php echo esc_attr($font_size); ?>" class="small-text" min="0.625" max="2.5" step="0.05">
                                <p class="description">1 = tamaño base del tema WordPress. Ej.: 0.875 más pequeño, 1.125 más grande. Se aplica al texto del usuario y del bot.</p>
                                <?php
                                if (class_exists('Xabia_Interface', false) && $edit_id !== '' && $edit_id !== 'new') {
                                    Xabia_Interface::render_admin_fields($edit_id, is_array($data) ? $data : []);
                                }
                                ?>
                                <hr>
                                <h4 style="margin:15px 0 8px;">🔊 Voz (lectura en alto)</h4>
                                <p class="description">Opciones para la síntesis de voz cuando el usuario activa el botón de audio en el chat. Compatible con la API estándar del navegador (Web Speech API).</p>
                                <label>Preferencia de voz</label>
                                <select name="tts_voice" class="widefat" style="max-width:280px;">
                                    <option value="default" <?php selected($tts_voice, 'default'); ?>>Por defecto (idioma del navegador)</option>
                                    <option value="female" <?php selected($tts_voice, 'female'); ?>>Femenina</option>
                                    <option value="male" <?php selected($tts_voice, 'male'); ?>>Masculina</option>
                                </select>
                                <p class="description">El navegador elige la voz disponible que mejor coincida (por idioma y preferencia).</p>
                                <label style="display:block; margin-top:10px;">Velocidad de locución</label>
                                <input type="number" name="tts_rate" min="0.5" max="2" step="0.1" value="<?php echo esc_attr($tts_rate); ?>" class="small-text" style="width:80px;"> (0,5 = lento … 2 = rápido; 1 = normal)
                                <hr>
                                <label><strong>Limpiar texto antes de leer en alto</strong></label>
                                <p class="description">Elimina marcas que no deben leerse (asteriscos, bloques de acción, etc.).</p>
                                <label style="display:block; margin-top:8px;"><input type="checkbox" name="tts_clean_bold" value="1" <?php checked($tts_clean_bold); ?>> Quitar <code>**</code> (markdown negrita)</label>
                                <label style="display:block;"><input type="checkbox" name="tts_clean_italic" value="1" <?php checked($tts_clean_italic); ?>> Quitar <code>*</code> (markdown cursiva)</label>
                                <label style="display:block;"><input type="checkbox" name="tts_clean_actions" value="1" <?php checked($tts_clean_actions); ?>> Quitar bloques <code>[ACTION:...]</code> y <code>[IMAGE:...]</code></label>
                                <label style="display:block;"><input type="checkbox" name="tts_clean_emojis" value="1" <?php checked($tts_clean_emojis); ?>> Quitar emoticonos (emojis)</label>
                                <p class="description">Evita que la síntesis de voz intente describir los emojis (ej: que no diga "cara sonriente").</p>
                                <label style="display:block; margin-top:10px;">Patrones adicionales (uno por línea, texto literal a eliminar):</label>
                                <textarea name="tts_clean_patterns" class="widefat" rows="3" placeholder="ej: [ACTION:CALL:...&#10;http://"><?php echo esc_textarea($tts_clean_patterns_str); ?></textarea>
                                <p class="description">Cada línea se elimina del texto antes de leer. Útil para URLs, códigos o etiquetas concretas.</p>
                                <?php do_action('xabia_agent_admin_personality_bottom', $edit_id, $data ?? []); ?>
                                <hr>
                                <label><strong>Índice de Confianza (Umbral de búsqueda)</strong></label><br>
                                <input type="number" step="0.05" min="0.0" max="1.0" name="min_score" value="<?php echo esc_attr($min_score); ?>" class="small-text">
                                <p class="description">Controla la precisión de la IA. Recomendado: 0.20.</p>
                                <hr>
                                <label><strong>Límite de tokens de respuesta</strong></label><br>
                                <input type="number" min="1200" max="3000" name="max_output_tokens" value="<?php echo esc_attr($data['rules']['max_output_tokens'] ?? '1200'); ?>" class="small-text" style="width:90px;">
                                <p class="description">Controla la longitud máxima de cada respuesta de la IA para este agente (1200–3000). Por defecto 1200; valores bajos cortan frases a mitad.</p>
                                <hr>
                                <label><strong>Límite diario de tokens (Hard Limit)</strong></label><br>
                                <input type="number" min="0" step="100" name="daily_token_limit" value="<?php echo esc_attr($data['rules']['daily_token_limit'] ?? '20000'); ?>" class="small-text" style="width:120px;">
                                <p class="description">Si se supera, el agente entra en modo mantenimiento hasta el siguiente día UTC.</p>
                                <hr>
                                <?php
                                $__mx = isset($data['rules']['max_chunks_context']) ? (int) $data['rules']['max_chunks_context'] : 0;
                                if ($__mx < 1) {
                                    $__leg = isset($data['rules']['context_chunk_limit']) ? (int) $data['rules']['context_chunk_limit'] : 0;
                                    $__mx = $__leg > 0 ? max(1, min(15, $__leg)) : 4;
                                } else {
                                    $__mx = max(1, min(15, $__mx));
                                }
                                ?>
                                <label for="max_chunks_context"><strong><?php echo esc_html__('Resultados máximos de contexto', 'xabia-intelligence'); ?></strong></label>
                                <input id="max_chunks_context" type="number" name="max_chunks_context" min="1" max="15" value="<?php echo esc_attr((string) $__mx); ?>" class="small-text" style="width:80px;">
                                <p class="description"><?php echo esc_html__('Número máximo de fragmentos de conocimiento que se envían al modelo por consulta (1–15). Por defecto 4.', 'xabia-intelligence'); ?></p>
                                <hr>
                                <label><input type="checkbox" name="use_vector_search" value="1" <?php checked(!empty($data['rules']['use_vector_search'])); ?>> Usar búsqueda vectorial (embeddings)</label>
                                <p class="description">Si está activo y el proyecto tiene vectores entrenados, la recuperación se hace por similitud semántica (no por palabras clave). Requiere haber pulsado «Entrenar» antes.</p>
                                <label style="display:block; margin-top:10px;">Umbral de similitud (0–1)</label>
                                <input type="number" step="0.05" min="0" max="1" name="similarity_threshold" value="<?php echo esc_attr($data['rules']['similarity_threshold'] ?? '0.2'); ?>" class="small-text" style="width:80px;">
                                <p class="description">Chunks por debajo de este valor se descartan. Muy estricto (ej. 0,5) puede dejar fuera resultados válidos; 0,2–0,3 suele ir bien.</p>
                                <hr>
                                <label>Saludo Inicial</label><textarea name="greeting" class="widefat" rows="3"><?php echo esc_textarea($greet); ?></textarea>
                                <hr>
                                <label>
                                    <input type="checkbox" name="starter_questions_enabled" value="1" <?php checked($starter_questions_enabled); ?>>
                                    <?php echo esc_html__('Mostrar preguntas sugeridas iniciales', 'xabia-intelligence'); ?>
                                </label>
                                <p class="description"><?php echo esc_html__('Muestra chips clicables bajo el saludo para animar la primera interacción.', 'xabia-intelligence'); ?></p>
                                <label style="display:block;margin-top:10px;"><?php echo esc_html__('Preguntas manuales (una por línea)', 'xabia-intelligence'); ?></label>
                                <textarea name="starter_questions" class="widefat" rows="4" placeholder="<?php echo esc_attr__('Opcional. Una pregunta por línea.', 'xabia-intelligence'); ?>"><?php echo esc_textarea($starter_questions_raw); ?></textarea>
                                <p class="description"><?php echo esc_html__('Si se deja en blanco, Xabia las generará automáticamente según el contenido del proyecto. Si escribes preguntas aquí, se mostrarán estas con prioridad.', 'xabia-intelligence'); ?></p>
                                <hr>
                                <label>📜 Prompt Maestro (Instrucciones)</label>
                                <p class="description">Define el tono, el rol y las instrucciones generales del agente (cómo debe presentarse, estilo de respuesta, límites). El anclaje a los datos indexados lo gestionan las reglas RAG del sistema.</p>
                                <textarea name="instructions" class="widefat" rows="12"><?php echo esc_textarea($data['rules']['instructions']??''); ?></textarea>
                                <hr>
                                <?php
                                $rag_preset = sanitize_key((string) ($data['rules']['rag_behavior_preset'] ?? 'neutral'));
                                if (!in_array($rag_preset, ['neutral', 'compact', 'custom'], true)) {
                                    $rag_preset = 'neutral';
                                }
                                $rag_custom = (string) ($data['rules']['rag_custom_behavior'] ?? '');
                                $context_source_description = (string) ($data['rules']['context_source_description'] ?? '');
                                $kw_expansions = is_array($data['rules']['keyword_expansions'] ?? null) ? $data['rules']['keyword_expansions'] : [];
                                $kr_rows = is_array($data['rules']['knowledge_relations'] ?? null) ? $data['rules']['knowledge_relations'] : [];
                                $pt_extra = [];
                                foreach ($kr_rows as $kr) {
                                    if (!is_array($kr)) {
                                        continue;
                                    }
                                    $pt_extra[] = (string) ($kr['source_post_type'] ?? '');
                                    $pt_extra[] = (string) ($kr['connected_post_type'] ?? '');
                                }
                                $relation_project_cfg = is_array($data_cfg) ? $data_cfg : [];
                                if (!isset($relation_project_cfg['rules']) && is_array($data['rules'] ?? null)) {
                                    $relation_project_cfg['rules'] = $data['rules'];
                                }
                                $rel_discovery = class_exists('Xabia_Relation_Entity_Catalog', false)
                                    ? Xabia_Relation_Entity_Catalog::discover_for_project($relation_project_cfg)
                                    : ['entities' => [], 'source' => 'none'];
                                $post_type_choices = self::merge_post_type_choices(
                                    is_array($rel_discovery['entities'] ?? null) ? $rel_discovery['entities'] : [],
                                    $pt_extra
                                );
                                $rel_source_label = (string) ($rel_discovery['source'] ?? '');
                                $rel_entity_kinds = is_array($rel_discovery['kinds'] ?? null) ? $rel_discovery['kinds'] : [];
                                ?>
                                <label><strong><?php echo esc_html__('Comportamiento RAG', 'xabia-intelligence'); ?></strong></label>
                                <p class="description"><?php echo esc_html__('Reglas que el sistema añade al prompt para ceñirse al contexto recuperado. Neutral es el valor por defecto (marca blanca).', 'xabia-intelligence'); ?></p>
                                <select name="rag_behavior_preset" id="xabia-rag-behavior-preset" class="widefat" style="max-width:320px;">
                                    <option value="neutral" <?php selected($rag_preset, 'neutral'); ?>><?php echo esc_html__('Neutral — riguroso (recomendado)', 'xabia-intelligence'); ?></option>
                                    <option value="compact" <?php selected($rag_preset, 'compact'); ?>><?php echo esc_html__('Compact — mínimo de tokens', 'xabia-intelligence'); ?></option>
                                    <option value="custom" <?php selected($rag_preset, 'custom'); ?>><?php echo esc_html__('Personalizado — texto propio', 'xabia-intelligence'); ?></option>
                                </select>
                                <label for="xabia-rag-custom-behavior" class="xabia-rag-custom-label" style="display:block;margin-top:12px;"><?php echo esc_html__('Texto personalizado (solo si eligió Personalizado)', 'xabia-intelligence'); ?></label>
                                <textarea name="rag_custom_behavior" id="xabia-rag-custom-behavior" class="widefat xabia-rag-custom-field" rows="6"><?php echo esc_textarea($rag_custom); ?></textarea>
                                <label for="xabia-context-source-description" style="display:block;margin-top:16px;"><strong><?php echo esc_html__('Descripción semántica de la fuente de datos', 'xabia-intelligence'); ?></strong></label>
                                <p class="description"><?php echo esc_html__('Explica brevemente al modelo qué tipo de información contiene tu catálogo (ej: «Directorio de turismo náutico y aventura» o «Tienda de ropa de deporte»). Esto ayuda a la IA a relacionar conceptos sin necesidad de reglas rígidas de código.', 'xabia-intelligence'); ?></p>
                                <textarea name="context_source_description" id="xabia-context-source-description" class="widefat" rows="4" placeholder="<?php echo esc_attr__('Directorio de empresas de turismo activo, aventura y deportes náuticos…', 'xabia-intelligence'); ?>"><?php echo esc_textarea($context_source_description); ?></textarea>

                                <hr style="margin:20px 0;">
                                <div class="xabia-visual-block" id="xabia-keyword-expansions-block">
                                    <label><strong><?php echo esc_html__('Expansiones léxicas y sinónimos', 'xabia-intelligence'); ?></strong></label>
                                    <p class="description"><?php echo esc_html__('Añade palabras clave de búsqueda y sus sinónimos (separados por comas). El sistema las usa al recuperar memoria del catálogo.', 'xabia-intelligence'); ?></p>
                                    <div class="xabia-visual-rows" data-xabia-kw-list>
                                        <?php
                                        $kw_i = 0;
                                        if ($kw_expansions === []) {
                                            self::render_keyword_expansion_row('', '', 0);
                                            $kw_i = 1;
                                        } else {
                                            foreach ($kw_expansions as $kw_key => $kw_pattern) {
                                                $kw_key = (string) $kw_key;
                                                $syn = self::keyword_pattern_to_synonyms_display($kw_key, (string) $kw_pattern);
                                                self::render_keyword_expansion_row($kw_key, $syn, $kw_i);
                                                ++$kw_i;
                                            }
                                        }
                                        ?>
                                    </div>
                                    <p><button type="button" class="button" data-xabia-add-kw><?php echo esc_html__('Añadir palabra clave', 'xabia-intelligence'); ?></button></p>
                                    <template id="xabia-kw-row-tpl">
                                        <?php self::render_keyword_expansion_row('', '', 9999); ?>
                                    </template>
                                </div>

                                <hr style="margin:20px 0;">
                                <div class="xabia-visual-block" id="xabia-knowledge-relations-block" data-xabia-rel-source="<?php echo esc_attr($rel_source_label); ?>">
                                    <label><strong><?php echo esc_html__('Relaciones de catálogo', 'xabia-intelligence'); ?></strong></label>
                                    <p class="description"><?php echo esc_html__('Empareja origen y destino usando los tipos reales de la fuente de datos del agente. Al sincronizar, cada ficha incluirá los títulos relacionados.', 'xabia-intelligence'); ?></p>
                                    <?php if ($post_type_choices === []) : ?>
                                        <p class="description" style="color:#b45309;"><?php echo esc_html__('No se detectaron tipos en la fuente configurada. Guarda la conexión SQL/addon o usa «Actualizar tipos» tras configurar la fuente.', 'xabia-intelligence'); ?></p>
                                    <?php endif; ?>
                                    <div class="xabia-visual-rows" data-xabia-rel-list>
                                        <?php
                                        $rel_i = 0;
                                        if ($kr_rows === []) {
                                            self::render_knowledge_relation_row($post_type_choices, null, 0, $rel_entity_kinds);
                                            $rel_i = 1;
                                        } else {
                                            foreach ($kr_rows as $kr_row) {
                                                self::render_knowledge_relation_row($post_type_choices, is_array($kr_row) ? $kr_row : [], $rel_i, $rel_entity_kinds);
                                                ++$rel_i;
                                            }
                                        }
                                        ?>
                                    </div>
                                    <p class="xabia-rel-actions">
                                        <button type="button" class="button" data-xabia-add-rel><?php echo esc_html__('Añadir relación', 'xabia-intelligence'); ?></button>
                                        <button type="button" class="button" id="xabia-rel-refresh-types" data-xabia-refresh-rel-types><?php echo esc_html__('Actualizar tipos desde la fuente', 'xabia-intelligence'); ?></button>
                                    </p>
                                    <template id="xabia-rel-row-tpl">
                                        <?php self::render_knowledge_relation_row($post_type_choices, null, 9999, $rel_entity_kinds); ?>
                                    </template>
                                </div>
                            </div>

                            <div id="tab-history" class="xabia-tab-content">
                                <h3><?php echo esc_html__('Registro de conversaciones', 'xabia-intelligence'); ?></h3>
                                <?php $logs = self::get_project_logs($edit_id); if($logs): ?>
                                    <table class="xabia-log-table">
                                        <thead><tr><th>Fecha</th><th>User</th><th>Respuesta IA</th></tr></thead>
                                        <tbody>
                                        <?php foreach($logs as $l): ?>
                                            <tr><td><?php echo $l->timestamp; ?></td><td><?php echo esc_html($l->user_question); ?></td><td><?php echo esc_html($l->ai_response); ?></td></tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?><p>Sin actividad reciente.</p><?php endif; ?>
                            </div>

                            <?php do_action('xabia_agent_admin_extra_tabs_content', $edit_id, $data ?? []); ?>

                            <div class="xabia-card-footer">
                                <input type="submit" class="button button-primary" value="<?php echo esc_attr__('Guardar agente', 'xabia-intelligence'); ?>">
                            </div>
                            </div>
                        </div>

                        <aside class="xabia-sidebar">
                            <?php do_action('xabia_admin_sidebar_top', $edit_id, $data ?? []); ?>
                            <?php if($edit_id!=='new'): $stats=self::get_vector_counts($edit_id, is_array($data_cfg) ? $data_cfg : []); $today_tokens = self::get_today_token_usage($edit_id);
                            if (class_exists('Xabia_Digixop_Client', false)) {
                                Xabia_Digixop_Client::refresh_license_meta_from_hub_if_stale();
                            }
                            $hub_sb = class_exists('Xabia_Digixop_Client', false) ? Xabia_Digixop_Client::get_cached_license_meta() : null;
                            $hub_rem = (is_array($hub_sb) && isset($hub_sb['tokens_remaining']) && is_numeric($hub_sb['tokens_remaining']))
                                ? (int) $hub_sb['tokens_remaining'] : null;
                            $pipeline_status = self::get_agent_pipeline_status((string) $edit_id, is_array($data_cfg) ? $data_cfg : []);
                            $pipeline_alert = self::build_pipeline_alert_html($pipeline_status);
                            $tokens_depleted_ui = !empty($pipeline_status['tokens_depleted']);
                            $kv_table_name = class_exists('Xabia_DB', false) ? Xabia_DB::table('knowledge_vectors') : 'xabia_knowledge_vectors';
                            $vector_search_ui = !empty($data_cfg['rules']['use_vector_search']);
                            ?>
                            <div class="xabia-status-box" id="xabia-agent-memory-panel">
                                <div class="xabia-memory-panel-head">
                                    <h3><?php echo esc_html__('Memoria del agente', 'xabia-intelligence'); ?></h3>
                                    <a href="#xabia-playground-card" class="xabia-playground-jump"><?php echo esc_html__('Ir al Playground ↓', 'xabia-intelligence'); ?></a>
                                </div>
                                <div id="xabia-pipeline-alert" class="xabia-pipeline-alert<?php echo $pipeline_alert !== '' ? ' xabia-pipeline-alert--visible xabia-pipeline-alert--error' : ''; ?>"<?php echo $pipeline_alert === '' ? ' style="display:none;"' : ''; ?>><?php echo $pipeline_alert; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- build_pipeline_alert_html escapa ?></div>
                                <?php if ((int) ($stats['total'] ?? 0) === 0) : ?>
                                    <p class="xabia-stat-line"><?php echo esc_html__('Agente listo: Esperando datos de entrenamiento', 'xabia-intelligence'); ?></p>
                                <?php elseif (!$vector_search_ui) : ?>
                                    <p class="xabia-stat-line"><?php echo esc_html(sprintf(__('Registros sincronizados: %1$s · Listos para el chat: %2$s', 'xabia-intelligence'), number_format_i18n($stats['total']), number_format_i18n($stats['ready']))); ?></p>
                                    <p class="xabia-stat-line description"><?php echo esc_html__('Búsqueda vectorial desactivada: el chat recupera por palabras clave; «Entrenar IA» no es necesario.', 'xabia-intelligence'); ?></p>
                                <?php else : ?>
                                    <p class="xabia-stat-line"><?php echo esc_html(sprintf(__('Registros sincronizados: %1$s · Vectores listos: %2$s', 'xabia-intelligence'), number_format_i18n($stats['total']), number_format_i18n($stats['ready']))); ?></p>
                                <?php endif; ?>
                                <?php if ($hub_rem !== null) : ?>
                                    <p id="xabia-agent-token-balance" class="xabia-stat-line<?php echo $tokens_depleted_ui ? ' xabia-stat-line--danger' : ($hub_rem < 50000 ? ' xabia-stat-line--warn' : ''); ?>"><?php echo esc_html(sprintf(__('Saldo de licencia: %s tokens', 'xabia-intelligence'), number_format_i18n($hub_rem))); ?></p>
                                <?php endif; ?>
                                <?php if ((int) ($pipeline_status['train_pending'] ?? 0) > 0) : ?>
                                    <p class="xabia-stat-line"><?php echo esc_html(sprintf(__('Pendientes de entrenar (sin embedding): %s', 'xabia-intelligence'), number_format_i18n((int) $pipeline_status['train_pending']))); ?></p>
                                <?php endif; ?>
                                <p class="xabia-stat-line xabia-stat-line--muted"><?php echo esc_html(sprintf(__('Tabla local: %s', 'xabia-intelligence'), $kv_table_name)); ?></p>
                                <p class="xabia-stat-line"><?php echo esc_html(sprintf(__('Consumo hoy (este agente): %s', 'xabia-intelligence'), number_format_i18n($today_tokens))); ?></p>
                                <?php
                                $auto_sync_cfg_ui = is_array($data_cfg['auto_sync'] ?? null)
                                    ? $data_cfg['auto_sync']
                                    : (class_exists('Xabia_Auto_Sync', false) ? Xabia_Auto_Sync::default_auto_sync_config($data_cfg) : ['enabled' => 1, 'interval' => '1hour']);
                                $auto_sync_interval_ui = class_exists('Xabia_Auto_Sync', false)
                                    ? Xabia_Auto_Sync::get_interval_key($data_cfg)
                                    : (string) ($auto_sync_cfg_ui['interval'] ?? '1hour');
                                $auto_sync_enabled_ui = !empty($auto_sync_cfg_ui['enabled']) && $auto_sync_interval_ui !== 'off';
                                $auto_train_ui = class_exists('Xabia_Auto_Sync', false)
                                    ? Xabia_Auto_Sync::is_auto_train_enabled($data_cfg)
                                    : !empty($auto_sync_cfg_ui['auto_train']);
                                $auto_cloud_ui = class_exists('Xabia_Auto_Sync', false)
                                    ? Xabia_Auto_Sync::is_auto_cloud_enabled($data_cfg)
                                    : !empty($auto_sync_cfg_ui['auto_cloud']);
                                $hub_cloud_available_ui = class_exists('Xabia_Hub_Knowledge', false)
                                    && Xabia_Hub_Knowledge::is_hub_rag_enabled((string) $edit_id);
                                $is_remote_source_ui = class_exists('Xabia_Knowledge_Sync', false) && Xabia_Knowledge_Sync::is_remote_config($data_cfg);
                                $auto_sync_options = class_exists('Xabia_Auto_Sync', false) ? Xabia_Auto_Sync::interval_options() : [];
                                ?>
                                <div class="xabia-auto-sync-box" style="margin:12px 0;padding:10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">
                                    <p style="margin:0 0 8px;"><strong><?php echo esc_html__('Sincronización automática', 'xabia-intelligence'); ?></strong></p>
                                    <label style="display:flex;align-items:center;gap:8px;margin:0 0 8px;">
                                        <input type="checkbox" name="auto_sync_enabled" value="1" <?php checked($auto_sync_enabled_ui); ?>>
                                        <?php echo esc_html__('Actualizar datos automáticamente', 'xabia-intelligence'); ?>
                                    </label>
                                    <label for="auto_sync_interval" class="screen-reader-text"><?php echo esc_html__('Intervalo de sincronización', 'xabia-intelligence'); ?></label>
                                    <select name="auto_sync_interval" id="auto_sync_interval" class="widefat">
                                        <?php foreach ($auto_sync_options as $opt_key => $opt_label) : ?>
                                            <?php if ($is_remote_source_ui && $opt_key === 'immediate') {
                                                continue;
                                            } ?>
                                            <option value="<?php echo esc_attr($opt_key); ?>" <?php selected($auto_sync_interval_ui, $opt_key); ?>><?php echo esc_html($opt_label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label style="display:flex;align-items:center;gap:8px;margin:10px 0 6px;">
                                        <input type="checkbox" name="auto_sync_auto_train" value="1" <?php checked($auto_train_ui); ?><?php echo $vector_search_ui ? '' : ' disabled="disabled"'; ?>>
                                        <?php echo esc_html__('Tras sync: entrenar IA automáticamente', 'xabia-intelligence'); ?>
                                    </label>
                                    <label style="display:flex;align-items:center;gap:8px;margin:0 0 8px;">
                                        <input type="checkbox" name="auto_sync_auto_cloud" value="1" <?php checked($auto_cloud_ui); ?><?php echo $hub_cloud_available_ui ? '' : ' disabled="disabled"'; ?>>
                                        <?php echo esc_html__('Tras entrenar: subir cerebro al Hub', 'xabia-intelligence'); ?>
                                    </label>
                                    <p class="description" style="margin:8px 0 0;">
                                        <?php if ($is_remote_source_ui) : ?>
                                            <?php echo esc_html__('Los datos vienen de otra web: se revisan en el intervalo que elijas. Si marcas las casillas de arriba, también se preparará el chat y se subirá al Hub sin que tengas que pulsar los botones.', 'xabia-intelligence'); ?>
                                        <?php else : ?>
                                            <?php echo esc_html__('Si cambias productos o eventos en esta web, esperamos unos minutos para agrupar los cambios y actualizar la memoria de una vez. Con reservas (Amelia) o un CSV se usa el intervalo que elijas arriba.', 'xabia-intelligence'); ?>
                                        <?php endif; ?>
                                    </p>
                                    <?php if (class_exists('Xabia_Auto_Sync', false)) : ?>
                                        <p class="description" style="margin:6px 0 0;"><?php echo esc_html(Xabia_Auto_Sync::status_line((string) $edit_id, $data_cfg)); ?></p>
                                    <?php endif; ?>
                                </div>
                                <p class="description" id="xabia-premium-connector-sync-notice" style="margin:8px 0;<?php echo !empty($xabia_premium_addon_sync_locked) ? '' : 'display:none;'; ?>">
                                    <?php echo esc_html__('Requiere suscripción en el Hub (Addons): activa el conector premium correspondiente (MEC, Woo, etc.) para sincronizar.', 'xabia-intelligence'); ?>
                                </p>
                                <button type="button" id="btn-sync-ajax" class="button xabia-sidebar-action"<?php echo !empty($xabia_premium_addon_sync_locked) ? ' disabled="disabled" aria-disabled="true"' : ''; ?>><?php echo esc_html__('1. Sincronizar datos (manual)', 'xabia-intelligence'); ?></button>
                                <p class="description" style="margin:6px 0 0;"><?php echo esc_html__('Solo añade o actualiza lo que ha cambiado; no borra el resto. Para empezar de cero usa «Borrar memoria vectorial».', 'xabia-intelligence'); ?></p>
                                <button type="button" id="btn-train-ajax" class="button button-primary xabia-sidebar-action"<?php
                                    echo $tokens_depleted_ui
                                        ? ' disabled="disabled" aria-disabled="true" title="' . esc_attr__('Saldo de tokens agotado. Recarga en Cartera / Wallet.', 'xabia-intelligence') . '"'
                                        : (!$vector_search_ui
                                            ? ' disabled="disabled" aria-disabled="true" title="' . esc_attr__('Activa «Usar búsqueda vectorial» en General para generar embeddings.', 'xabia-intelligence') . '"'
                                            : '');
                                ?>><?php echo esc_html__('2. Entrenar IA — 1 lote', 'xabia-intelligence'); ?></button>
                                <p class="description" style="margin:6px 0 0;"><?php echo $vector_search_ui
                                    ? esc_html__('Cada clic entrena como máximo 20 registros (no todo de golpe). Revisa el saldo antes de continuar.', 'xabia-intelligence')
                                    : esc_html__('Con búsqueda vectorial desactivada el chat usa palabras clave sobre los registros sincronizados; no hace falta entrenar.', 'xabia-intelligence'); ?></p>
                                <button type="button" id="btn-sync-brain-cloud" class="button button-primary xabia-sidebar-action" style="width:100%;margin-top:10px;"><?php echo esc_html__('Subir cerebro al Hub (manual)', 'xabia-intelligence'); ?></button>
                                <button type="button" id="btn-knowledge-preview" class="button xabia-sidebar-action" style="width:100%;margin-top:8px;"><?php echo esc_html__('Vista previa del conocimiento (esta base)', 'xabia-intelligence'); ?></button>
                                <div id="xabia-knowledge-preview" class="xabia-knowledge-preview" style="display:none;margin-top:10px;padding:10px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;max-height:260px;overflow:auto;font-size:12px;line-height:1.45;white-space:pre-wrap;word-break:break-word;"></div>
                                <div id="xabia-work-progress" class="xabia-work-progress" role="status" aria-live="polite" hidden>
                                    <div class="xabia-work-progress__head">
                                        <span class="xabia-work-progress__spinner" aria-hidden="true"></span>
                                        <span class="xabia-work-progress__title"></span>
                                    </div>
                                    <div class="xabia-work-progress__track" aria-hidden="true">
                                        <div class="xabia-work-progress__bar"></div>
                                    </div>
                                    <p class="xabia-work-progress__step"></p>
                                    <p class="xabia-work-progress__elapsed"></p>
                                </div>
                                <div id="sync-feedback" class="xabia-sync-feedback"></div>
                                <details class="xabia-sidebar-advanced" style="margin-top:12px;font-size:12.5px;line-height:1.55;">
                                    <summary style="cursor:pointer;font-weight:600;"><?php echo esc_html__('Avanzado', 'xabia-intelligence'); ?></summary>
                                    <p style="margin:10px 0 6px;" class="description"><?php echo esc_html__('Solo si trabajas con redes de sitios federados.', 'xabia-intelligence'); ?></p>
                                    <p style="margin:0 0 4px;"><a href="<?php echo esc_url(admin_url('admin.php?page=xabia-central&project_id=' . rawurlencode((string) $edit_id))); ?>"><?php echo esc_html__('Nodos Xabia Central (este agente)', 'xabia-intelligence'); ?></a></p>
                                    <p style="margin:0;"><a href="<?php echo esc_url(admin_url('admin.php?page=xabia-federation-nexus')); ?>"><?php echo esc_html__('Federación Nexus (REST)', 'xabia-intelligence'); ?></a></p>
                                </details>
                                <hr>
                                <button type="button" id="btn-clear-ajax" class="button xabia-btn--danger-outline" style="width:100%;margin-top:8px;"><?php echo esc_html__('Borrar memoria vectorial', 'xabia-intelligence'); ?></button>
                            </div>

                            <div class="xabia-playground-card" id="xabia-playground-card">
                                <div class="xabia-playground-card__head"><?php echo esc_html__('Playground', 'xabia-intelligence'); ?> <span><?php echo esc_html__('· laboratorio de chat', 'xabia-intelligence'); ?></span></div>
                                <div id="p-chat-canvas"></div>
                                <div class="xabia-playground-input-row">
                                    <input type="text" id="p-input" placeholder="<?php echo esc_attr__('Escribe un mensaje de prueba…', 'xabia-intelligence'); ?>">
                                    <button type="button" id="p-send" class="button button-primary"><?php echo esc_html__('Enviar', 'xabia-intelligence'); ?></button>
                                </div>
                            </div>
                            <?php endif; ?>
                        </aside>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <div id="xabia-cpt-assistant-backdrop" class="xabia-modal-backdrop" style="display:none;" aria-hidden="true"></div>
        <div id="xabia-cpt-assistant-modal" class="xabia-modal-panel xabia-modal-panel--curator" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="xabia-cpt-assistant-title">
            <div class="xabia-modal-panel__inner">
                <h2 id="xabia-cpt-assistant-title" class="xabia-modal-panel__title"><?php echo esc_html__('Consola de curación de contenidos', 'xabia-intelligence'); ?></h2>
                <p class="description"><?php echo esc_html__('Elige el tipo de contenido y marca qué datos quieres que la IA conozca. No necesitas escribir claves técnicas: el sistema detecta el esquema y genera el SQL con {prefix}. Puedes revisar o afinar la consulta en el editor SQL del agente después.', 'xabia-intelligence'); ?></p>
                <p id="xabia-cpt-assistant-source-hint" class="xabia-cpt-assistant-source-hint description" style="display:none;margin:8px 0;padding:8px 10px;border-left:3px solid #2271b1;background:#f0f6fc;" role="status"></p>
                <label for="xabia-cpt-assistant-pt" class="xabia-label"><?php echo esc_html__('Tipo de contenido', 'xabia-intelligence'); ?></label>
                <select id="xabia-cpt-assistant-pt" class="widefat">
                    <option value=""><?php echo esc_html__('— Cargando… —', 'xabia-intelligence'); ?></option>
                </select>
                <div id="xabia-cpt-assistant-deep-wrap" style="display:none;margin-top:12px;">
                    <label class="xabia-label"><?php echo esc_html__('Campos incluidos en el conocimiento', 'xabia-intelligence'); ?></label>
                    <p class="description" style="margin-top:4px;"><?php echo esc_html__('Las sugerencias vienen marcadas por defecto. El botón radial «Ente» indica qué campo identifica cada registro para modo QR y segmentación.', 'xabia-intelligence'); ?></p>
                    <div id="xabia-cpt-assistant-deep-scroll" class="xabia-cpt-assistant-deep-scroll">
                        <div id="xabia-field-selector" class="xabia-field-selector" role="list" aria-label="<?php echo esc_attr__('Selección de campos', 'xabia-intelligence'); ?>">
                            <section class="xabia-field-selector__section" data-section="identity">
                                <h3 class="xabia-field-selector__heading" id="xabia-field-heading-core"></h3>
                                <div id="xabia-field-selector-core" class="xabia-field-selector__list" role="group" aria-labelledby="xabia-field-heading-core"></div>
                            </section>
                            <section class="xabia-field-selector__section" data-section="meta">
                                <h3 class="xabia-field-selector__heading" id="xabia-field-heading-meta"></h3>
                                <div id="xabia-field-selector-meta" class="xabia-field-selector__list" role="group" aria-labelledby="xabia-field-heading-meta"></div>
                            </section>
                            <section class="xabia-field-selector__section" data-section="tax">
                                <h3 class="xabia-field-selector__heading" id="xabia-field-heading-tax"></h3>
                                <div id="xabia-field-selector-tax" class="xabia-field-selector__list" role="group" aria-labelledby="xabia-field-heading-tax"></div>
                            </section>
                            <section class="xabia-field-selector__section xabia-field-selector__section--virtual" id="xabia-field-selector-section-virtual" style="display:none;">
                                <h3 class="xabia-field-selector__heading" id="xabia-field-heading-virtual"></h3>
                                <p class="description xabia-field-selector__virtual-hint" id="xabia-field-virtual-hint" style="display:none;margin-top:0;margin-bottom:8px;"></p>
                                <div id="xabia-field-selector-virtual" class="xabia-field-selector__list" role="group" aria-labelledby="xabia-field-heading-virtual"></div>
                            </section>
                        </div>
                    </div>
                    <p style="margin-top:12px;">
                        <button type="button" class="button button-primary" id="xabia-cpt-assistant-apply-mapping"><?php echo esc_html__('Aplicar SQL y mapeo al agente', 'xabia-intelligence'); ?></button>
                    </p>
                </div>
                <p class="xabia-cpt-assistant-status description" style="margin-top:8px;"></p>
                <div id="xabia-amelia-ente-tools" style="display:none;margin-top:14px;padding-top:12px;border-top:1px solid #c3c4c7;">
                    <p class="description" style="margin-top:0;"><?php echo esc_html__('Buscador de servicios Amelia: ID y nombre para mapear como Ente (?ente_id= / modo estricto).', 'xabia-intelligence'); ?></p>
                    <label for="xabia-amelia-ente-search" class="xabia-label"><?php echo esc_html__('Buscar por nombre', 'xabia-intelligence'); ?></label>
                    <input type="search" id="xabia-amelia-ente-search" class="widefat" placeholder="<?php echo esc_attr__('Escribe para filtrar…', 'xabia-intelligence'); ?>" autocomplete="off"/>
                    <ul id="xabia-amelia-ente-list" class="xabia-amelia-ente-list" style="max-height:180px;overflow:auto;margin:8px 0 0;padding:0;list-style:none;border:1px solid #dcdcde;border-radius:4px;"></ul>
                    <p class="description" id="xabia-amelia-ente-hint" style="margin-bottom:0;"></p>
                </div>
                <p class="xabia-modal-panel__actions">
                    <button type="button" class="button button-primary" id="xabia-cpt-assistant-close"><?php echo esc_html__('Cerrar', 'xabia-intelligence'); ?></button>
                </p>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($){
            let xabiaCurrentNonce = <?php echo wp_json_encode(wp_create_nonce('xabia_admin_nonce')); ?>;
            var XABIA_RESERVAS = <?php
                echo wp_json_encode([
                    'ameliaPt' => class_exists('Xabia_Reservas_Handler', false) ? Xabia_Reservas_Handler::VIRTUAL_POST_TYPE_AMELIA : '',
                    'amelia'   => class_exists('Xabia_Reservas_Handler', false) && Xabia_Reservas_Handler::is_amelia(),
                ], JSON_UNESCAPED_UNICODE);
                ?>;
            var XABIA_CURATOR_UI = <?php
                echo wp_json_encode(
                    [
                        'sectionIdentity'   => __('Identidad (Core)', 'xabia-intelligence'),
                        'sectionMeta'       => __('Detalles (Meta)', 'xabia-intelligence'),
                        'sectionTax'        => __('Clasificación (Taxonomías)', 'xabia-intelligence'),
                        'sectionVirtual'    => __('Estado (Virtual)', 'xabia-intelligence'),
                        'enteShort'         => __('Ente', 'xabia-intelligence'),
                        'badgeRecent'       => __('Con datos', 'xabia-intelligence'),
                        'emptyMeta'         => __('No se detectaron claves meta adicionales en el muestreo.', 'xabia-intelligence'),
                        'emptyTax'          => __('Este tipo no tiene taxonomías públicas.', 'xabia-intelligence'),
                        'closeConfirm'      => __('¿Aplicar la selección actual (SQL y mapeo) al formulario del agente antes de cerrar?', 'xabia-intelligence'),
                        'emptyCore'         => __('Sin columnas de la tabla «posts» para este origen; revisa Detalles.', 'xabia-intelligence'),
                        'coreLabels'        => [
                            'ID'                   => __('ID', 'xabia-intelligence'),
                            'post_author'          => __('Autor', 'xabia-intelligence'),
                            'post_date'            => __('Fecha de publicación', 'xabia-intelligence'),
                            'post_date_gmt'        => __('Fecha (GMT)', 'xabia-intelligence'),
                            'post_content'         => __('Contenido', 'xabia-intelligence'),
                            'post_title'           => __('Título', 'xabia-intelligence'),
                            'post_excerpt'         => __('Extracto', 'xabia-intelligence'),
                            'post_status'          => __('Estado', 'xabia-intelligence'),
                            'comment_status'       => __('Estado de comentarios', 'xabia-intelligence'),
                            'ping_status'          => __('Ping', 'xabia-intelligence'),
                            'post_password'        => __('Contraseña', 'xabia-intelligence'),
                            'post_name'            => __('Slug', 'xabia-intelligence'),
                            'to_ping'              => __('To ping', 'xabia-intelligence'),
                            'pinged'               => __('Pinged', 'xabia-intelligence'),
                            'post_modified'        => __('Última modificación', 'xabia-intelligence'),
                            'post_modified_gmt'    => __('Modificación (GMT)', 'xabia-intelligence'),
                            'post_content_filtered'=> __('Contenido filtrado', 'xabia-intelligence'),
                            'post_parent'          => __('Publicación padre', 'xabia-intelligence'),
                            'guid'                 => __('GUID', 'xabia-intelligence'),
                            'menu_order'           => __('Orden', 'xabia-intelligence'),
                            'post_type'            => __('Tipo', 'xabia-intelligence'),
                            'post_mime_type'       => __('MIME', 'xabia-intelligence'),
                            'comment_count'        => __('Nº comentarios', 'xabia-intelligence'),
                        ],
                    ],
                    JSON_UNESCAPED_UNICODE
                );
                ?>;
            var xabiaCptAssistTarget = null;
            function xabiaCptAssistSourcePayload(extra) {
                var payload = {
                    project_id: '<?php echo esc_js($edit_id === 'new' ? '' : $edit_id); ?>',
                    source_type: ($('#xabia-source-select').val() || $('select[name="source_type"]').val() || ''),
                    addon_slug: ($('select[name="addon_slug"]').val() || $('#addon_slug').val() || '')
                };
                if (xabiaCptAssistTarget && xabiaCptAssistTarget.type === 'multi') {
                    var idx = parseInt(xabiaCptAssistTarget.idx, 10);
                    if (isNaN(idx) || idx < 0) idx = 0;
                    var $box = $('.xabia-multi-source-box').eq(idx);
                    payload.source_type = 'multi';
                    payload.source_index = idx;
                    payload.active_source_type = ($box.find('select.multi-source-type').val() || 'sql');
                    payload.sql_host = $box.find('input[name="sources[' + idx + '][sql_host]"]').val() || '';
                    payload.sql_user = $box.find('input[name="sources[' + idx + '][sql_user]"]').val() || '';
                    payload.sql_name = $box.find('input[name="sources[' + idx + '][sql_name]"]').val() || '';
                    payload.sql_pass = $box.find('input[name="sources[' + idx + '][sql_pass]"]').val() || '';
                    payload.sql_prefix = $box.find('input[name="sources[' + idx + '][sql_prefix]"]').val() || '';
                    payload.sql_query = $box.find('textarea[name="sources[' + idx + '][sql_query]"]').val() || '';
                } else {
                    payload.sql_host = ($('#sql_host').val() || '');
                    payload.sql_user = ($('#sql_user').val() || '');
                    payload.sql_name = ($('#sql_name').val() || '');
                    payload.sql_pass = ($('#sql_pass').val() || '');
                    payload.sql_prefix = ($('#sql_prefix').val() || '');
                    payload.sql_query = ($('#sql_query').val() || '');
                }
                return $.extend({}, payload, extra || {});
            }
            var xabiaAmeliaServicesCache = null;

            function xabiaSyncNonce(r) {
                var d = r && r.data;
                if (d && typeof d.nonce === 'string' && d.nonce.length) {
                    xabiaCurrentNonce = d.nonce;
                }
            }

            function xabiaAdminPost(payload, done) {
                payload = payload || {};
                payload.nonce = (typeof payload.nonce === 'string' && payload.nonce.length) ? payload.nonce : xabiaCurrentNonce;
                return $.post(ajaxurl, payload, function(r) {
                    xabiaSyncNonce(r);
                    if (done) done(r);
                }).fail(function(xhr, status) {
                    var code = xhr && xhr.status ? xhr.status : 0;
                    var msg;
                    if (status === 'parsererror') {
                        msg = '<?php echo esc_js(__('Respuesta inválida del servidor (no JSON). Suele deberse a un error PHP: revisa el log del hosting o actualiza Xabia Core.', 'xabia-intelligence')); ?>';
                        if (xhr && xhr.responseText && window.console && console.warn) {
                            console.warn('[Xabia] admin-ajax parsererror:', String(xhr.responseText).substring(0, 800));
                        }
                    } else if (code === 504) {
                        msg = '<?php echo esc_js(__('Tiempo de espera del servidor agotado (504). La consulta SQL es pesada: vuelve a pulsar Sincronizar (continúa sin borrar) o pide al hosting más timeout en admin-ajax.', 'xabia-intelligence')); ?>';
                    } else {
                        msg = '<?php echo esc_js(__('Error de red en admin-ajax', 'xabia-intelligence')); ?> (' + (code || status || '?') + ').';
                    }
                    if (done) done({ success: false, data: { message: msg } });
                });
            }
            function parseChatVisualTags(text) {
                if(!text) return "";
                text = String(text);
                text = text.replace(/&lt;strong&gt;/gi, '<strong>').replace(/&lt;\/strong&gt;/gi, '</strong>');
                text = text.replace(/\n/g, '<br>');
                text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
                text = text.replace(/\[ACTION:IMG:(.*?)\]/g, '<img src="$1" alt="Imagen del evento">');
                text = text.replace(/\[ACTION:WEB:(.*?)\]/g, '<a href="$1" target="_blank" class="xabia-chat-link">🌐 Abrir en la Web</a>');
                return text;
            }

            if($.fn.wpColorPicker) { $('.xabia-color-field').wpColorPicker(); }
            
            function xabiaRelocateMecKnowledgeUi() {
                var v = $('#xabia-source-select').val();
                var slug = String($('#addon_slug').val() || '');
                var isMecPreset = (v === 'addon' && slug === 'mec');
                var $panel = $('#xabia-mapping-panel');
                var $slotG = $('#xabia-mapping-slot-general');
                var $slotM = $('#xabia-mapping-slot-mec');
                var $sqlRemote = $('#section-sql-remote-fields');
                var $btnAnch = $('#xabia-addon-button-default-anchor');
                var $btn = $('#btn-test-addon');
                var $mecLand = $('#xabia-mec-connect-landing');
                if ($mecLand.length && $sqlRemote.length) {
                    if (isMecPreset) {
                        $sqlRemote.appendTo($mecLand);
                        if ($btn.length) {
                            $btn.appendTo($mecLand);
                        }
                    } else {
                        $sqlRemote.prependTo('#xabia-sql-remote-default-anchor');
                        if ($btn.length && $btnAnch.length) {
                            $btn.appendTo($btnAnch);
                        }
                    }
                }
                if (!$panel.length || !$slotG.length) {
                    return;
                }
                if (v === 'multi') {
                    $panel.appendTo($slotG);
                    return;
                }
                if ($slotM.length && isMecPreset) {
                    $panel.appendTo($slotM);
                } else {
                    $panel.appendTo($slotG);
                }
            }

            $('.toggle-pass').click(function(){ 
                let i = $('#sql_pass'); 
                if(i.attr('type') === 'password') { i.attr('type', 'text'); $(this).removeClass('dashicons-visibility').addClass('dashicons-hidden'); }
                else { i.attr('type', 'password'); $(this).removeClass('dashicons-hidden').addClass('dashicons-visibility'); }
            });

            $('.xabia-tab-btn').click(function(){ 
                $('.xabia-tab-btn, .xabia-tab-content').removeClass('active'); 
                $(this).addClass('active'); 
                $('#'+$(this).data('tab')).addClass('active'); 
                xabiaRelocateMecKnowledgeUi();
            });

            $(document).on('click', '.xabia-copy-tunnel-url', function() {
                var $inp = $(this).closest('tr').find('.xabia-tunnel-url');
                var url = ($inp.val() || '').trim();
                if (!url) return;
                var okMsg = '<?php echo esc_js(__('URL copiada al portapapeles.', 'xabia-intelligence')); ?>';
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(url).then(function() { alert(okMsg); }).catch(function() {
                        $inp[0].select();
                        try { document.execCommand('copy'); } catch (e) {}
                        alert(okMsg);
                    });
                } else {
                    $inp[0].select();
                    try { document.execCommand('copy'); } catch (e) {}
                    alert(okMsg);
                }
            });

            var xabiaPrevSourceVal = ($('#xabia-source-select').val() || 'csv');
            var xabiaPendingMultiCsv0 = '';

            function xabiaCollectSingleAttributes() {
                var rows = [];
                $('#attr-container .row-repeater-box').each(function() {
                    var $box = $(this);
                    var $instruction = $box.next('input[type="text"]');
                    var csvCol = $box.find('select.xabia-col-selector').val() || '';
                    var label = $box.find('input[name*="[label]"]').val() || '';
                    var visualRole = $box.find('select[name*="[visual_role]"]').val() || 'none';
                    var isEnte = $box.find('input[name*="[is_ente]"]').is(':checked');
                    var enteLabelCol = isEnte ? ($box.find('select.xabia-ente-label-col').val() || '') : '';
                    var instruction = $instruction.val() || '';
                    var importRag = $box.find('.xabia-import-rag-cb').is(':checked');
                    if (!csvCol) return;
                    rows.push({
                        csv_col: csvCol,
                        label: label,
                        visual_role: visualRole,
                        is_ente: isEnte,
                        ente_label_col: enteLabelCol,
                        instruction: instruction,
                        import_rag: importRag ? 1 : 0
                    });
                });
                return rows;
            }

            function xabiaMultiSource0HasData() {
                var sql = $('textarea[name="sources[0][sql_query]"]').val() || '';
                var csv = $('select[name="sources[0][csv_filename]"]').val() || '';
                if (String(sql).trim() !== '' || String(csv).trim() !== '') return true;
                var hasAttrs = $('.multi-attr-container[data-idx="0"] .row-repeater-box').length > 0;
                return hasAttrs;
            }

            function xabiaCopySingleSourceIntoMultiSource0(fromType) {
                if (xabiaMultiSource0HasData()) return;
                var sourceType = (fromType === 'sql' || fromType === 'local_sql' || fromType === 'csv') ? fromType : 'local_sql';
                var rows = xabiaCollectSingleAttributes();
                var cols = xabiaUniqueSorted(rows.map(function(r) { return r.csv_col; }).filter(Boolean));

                $('select[name="sources[0][type]"]').val(sourceType).trigger('change');
                if (sourceType === 'sql' || sourceType === 'local_sql') {
                    $('input[name="sources[0][sql_host]"]').val($('#sql_host').val() || '');
                    $('input[name="sources[0][sql_name]"]').val($('#sql_name').val() || '');
                    $('input[name="sources[0][sql_user]"]').val($('#sql_user').val() || '');
                    $('input[name="sources[0][sql_pass]"]').val($('#sql_pass').val() || '');
                    $('input[name="sources[0][sql_prefix]"]').val($('#sql_prefix').val() || '');
                    $('textarea[name="sources[0][sql_query]"]').val($('#sql_query').val() || '');
                } else {
                    xabiaPendingMultiCsv0 = $('#selected_csv_file').val() || '';
                }
                renderAttributeRows($('.multi-attr-container[data-idx="0"]'), rows, cols, 'sources[0][attributes]');
            }

            var xabiaMecLicenseOk = <?php echo (function_exists('xabia_mec_license_gate') && xabia_mec_license_gate()) ? 'true' : 'false'; ?>;
            var xabiaWooLicenseOk = <?php echo (function_exists('xabia_woo_license_gate') && xabia_woo_license_gate()) ? 'true' : 'false'; ?>;
            var XABIA_REMOTE_MEC_SQL = <?php echo wp_json_encode(trim(self::xabia_mec_remote_sql_preset()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            var XABIA_REMOTE_MEC_FIELDS = <?php echo wp_json_encode(self::xabia_mec_remote_default_mapping_fields(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            function xabiaUpdatePremiumConnectorUi() {
                var addon = ($('#xabia-source-select').val() === 'addon');
                var slug = String($('#addon_slug').val() || '');
                var isMec = addon && slug === 'mec';
                var isWoo = addon && slug === 'woo';
                var isMecRemotePreset = String($('#xabia_sql_preset').val() || '') === 'mec_remote';
                $('#xabia-mec-addon-hint').toggle(isMec);
                $('#xabia-woo-addon-hint').toggle(isWoo);
                $('#xabia-woo-remote-shop-row').toggle(isWoo);
                $('#xabia-mec-remote-site-row').toggle(isMec || isMecRemotePreset);
                var locked = (isMec && !xabiaMecLicenseOk) || (isWoo && !xabiaWooLicenseOk);
                var $sync = $('#btn-sync-ajax');
                if ($sync.length) {
                    $sync.prop('disabled', !!locked).attr('aria-disabled', locked ? 'true' : 'false');
                }
                $('#xabia-premium-connector-sync-notice').toggle(!!locked);
            }

            function xabiaUpdateWebPagesUi() {
                var v = $('#xabia-source-select').val();
                var showSupplemental = (v === 'addon' || v === 'local_sql' || v === 'sql' || v === 'csv');
                $('#xabia-supplemental-web-pages').toggle(!!showSupplemental);
                if (v === 'web_pages') {
                    $('#label-single-attr').hide();
                    $('#attr-container').hide();
                } else if (v !== 'multi') {
                    $('#label-single-attr').show();
                    $('#attr-container').show();
                }
            }

            $('#xabia-source-select').change(function(){ 
                let v = $(this).val();
                if (v !== 'sql' && $('#xabia_sql_preset').val() === 'mec_remote') {
                    $('#xabia_sql_preset').val('');
                    $('#xabia-remote-mec-preset-state').hide();
                }
                if (v === 'multi') {
                    xabiaCopySingleSourceIntoMultiSource0(xabiaPrevSourceVal);
                    $('.source-section').hide();
                    $('#section-multi').show();
                    $('#xabia-supplemental-web-pages').hide();
                    $('#label-single-attr').hide();
                    $('#attr-container').hide();
                    $('#section-sql-remote-fields').hide();
                    loadMultiCsvOptions();
                } else {
                    $('#section-multi').hide();
                    $('#label-single-attr').show();
                    $('#attr-container').show();
                    $('.source-section').hide();
                    if (v === 'local_sql') $('#section-sql').show();
                    else if ($('#section-'+v).length) $('#section-'+v).show();
                    if(v === 'sql' || v === 'addon') $('#section-sql-remote-fields').show();
                    else $('#section-sql-remote-fields').hide();
                    if(v === 'csv') loadCsvFiles();
                    xabiaUpdateWebPagesUi();
                }
                xabiaPrevSourceVal = v;
                xabiaUpdatePremiumConnectorUi();
                xabiaRelocateMecKnowledgeUi();
            }).change();

            $('#addon_slug').on('change', function () {
                xabiaUpdatePremiumConnectorUi();
                xabiaRelocateMecKnowledgeUi();
            });
            xabiaUpdatePremiumConnectorUi();
            xabiaRelocateMecKnowledgeUi();

            $(document).on('click', '#xabia-mec-test-connection-btn', function () {
                var $b = $('#btn-test-addon');
                if ($b.length) { $b.trigger('click'); }
            });

            function xabiaListCsvFilesFromResponse(r) {
                if (!r.success || !r.data) return [];
                if (Array.isArray(r.data.files)) return r.data.files;
                if (Array.isArray(r.data)) return r.data;
                return [];
            }

            function loadCsvFiles() {
                xabiaAdminPost({ action: 'xabia_list_csv_files', project_id: '<?php echo esc_js($edit_id); ?>' }, function(r) {
                    var files = xabiaListCsvFilesFromResponse(r);
                    var hasAny = files.length > 0;
                    var preferred = String($('#xabia-csv-state').attr('data-active-csv') || '');
                    var current = '';
                    if (hasAny) {
                        current = String(files[0].name || '');
                        files.forEach(function(file) {
                            if (preferred && String(file.name || '') === preferred) {
                                current = preferred;
                            }
                        });
                    }
                    $('#selected_csv_file').val(current);
                    $('#xabia-csv-active-name').text(current);
                    if (hasAny) {
                        $('#xabia-csv-has-file').show();
                        $('#xabia-csv-no-file').hide();
                    } else {
                        $('#xabia-csv-has-file').hide();
                        $('#xabia-csv-no-file').show();
                    }
                });
            }

            function loadMultiCsvOptions() {
                xabiaAdminPost({ action: 'xabia_list_csv_files', project_id: '<?php echo esc_js($edit_id); ?>' }, function(r) {
                    var files = xabiaListCsvFilesFromResponse(r);
                    if (files.length > 0) {
                        $('.multi-csv-select').each(function() {
                            var $sel = $(this);
                            var saved = String($sel.attr('data-saved') || '');
                            $sel.empty().append('<option value="">-- Selecciona CSV --</option>');
                            files.forEach(function(file) {
                                $sel.append('<option value="' + file.name + '">' + file.name + '</option>');
                            });
                            if (saved) {
                                $sel.val(saved);
                            }
                        });
                        if (xabiaPendingMultiCsv0) {
                            $('select[name="sources[0][csv_filename]"]').val(xabiaPendingMultiCsv0);
                            xabiaPendingMultiCsv0 = '';
                        }
                    }
                });
            }

            if($('#xabia-source-select').val() === 'csv') {
                loadCsvFiles();
            } else if ($('#xabia-source-select').val() === 'multi') {
                loadMultiCsvOptions();
            }

            $('#xabia-csv-upload-btn').on('click', function(e) {
                e.preventDefault();
                var projectId = String($('#xabia-csv-state').data('project-id') || '');
                var input = $('#xabia_csv_upload')[0];
                if (!projectId || !input || !input.files || !input.files[0]) {
                    alert('Selecciona un archivo CSV primero.');
                    return;
                }
                var file = input.files[0];
                if (!/\.csv$/i.test(String(file.name || ''))) {
                    alert('Solo se permiten archivos .csv');
                    return;
                }
                var fd = new FormData();
                fd.append('action', 'xabia_upload_csv');
                fd.append('nonce', xabiaCurrentNonce);
                fd.append('project_id', projectId);
                fd.append('csv_file', file);
                $('#csv-feedback').text('⏳ Subiendo CSV...');
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false
                }).done(function(r) {
                    xabiaSyncNonce(r);
                    if (r && r.success && r.data && r.data.file && r.data.file.name) {
                        $('#selected_csv_file').val(String(r.data.file.name));
                        $('#xabia-csv-state').attr('data-active-csv', String(r.data.file.name));
                        $('#xabia-csv-active-name').text(String(r.data.file.name));
                        $('#xabia-csv-has-file').show();
                        $('#xabia-csv-no-file').hide();
                        $('#xabia_csv_upload').val('');
                        $('#csv-feedback').text('✅ CSV subido y activo: ' + String(r.data.file.name));
                    } else {
                        $('#csv-feedback').text('❌ ' + ((r && r.data && r.data.message) ? r.data.message : 'Error al subir CSV'));
                    }
                }).fail(function() {
                    $('#csv-feedback').text('❌ Error de red al subir CSV');
                });
            });

            $('#xabia-csv-delete-btn').on('click', function(e) {
                e.preventDefault();
                var projectId = String($('#xabia-csv-state').data('project-id') || '');
                var current = String($('#selected_csv_file').val() || '');
                if (!projectId || !current) return;
                if (!window.confirm('¿Eliminar el CSV activo de este proyecto?')) return;
                xabiaAdminPost({
                    action: 'xabia_delete_csv',
                    project_id: projectId,
                    csv_file: current
                }, function(r) {
                    if (r && r.success) {
                        $('#selected_csv_file').val('');
                        $('#xabia-csv-state').attr('data-active-csv', '');
                        $('#xabia-csv-active-name').text('');
                        $('#xabia-csv-has-file').hide();
                        $('#xabia-csv-no-file').show();
                        $('#csv-feedback').text('🗑️ CSV eliminado');
                    } else {
                        $('#csv-feedback').text('❌ ' + ((r && r.data && r.data.message) ? r.data.message : 'Error al eliminar CSV'));
                    }
                });
            });

            $('#xabia_csv_upload').on('change', function() {
                var v = String($(this).val() || '');
                if (v) {
                    $('#csv-feedback').text('📤 Archivo listo: ' + v.split('\\').pop());
                }
            });

            $('#ai_driver_select').change(function(){
                if($(this).val() === 'google_cloud') $('#gcloud_json_wrapper').show();
                else $('#gcloud_json_wrapper').hide();
            }).change();
            
            var xabiaPlaygroundBotName = <?php echo wp_json_encode($avatar_name ?: 'Xabia'); ?>;
            let initGreet = "<?php echo esc_js($greet); ?>";
            if(initGreet) {
                var $greetRow = $('<div class="xabia-chat-msg xabia-from-bot"></div>').attr('data-raw', initGreet);
                $greetRow.append($('<b>').text(xabiaPlaygroundBotName + ': ')).append(parseChatVisualTags(initGreet));
                $('#p-chat-canvas').append($greetRow);
            }

            var ROLES = ['none','title','info','date','img','logotipo','tel','web','email','map'];
            var ROLE_LABELS = {'none':'Sin Rol','title':'Título','info':'Info','date':'Fecha','img':'Imagen','logotipo':'Logotipo','tel':'Teléfono','web':'Web','email':'Email','map':'Mapa'};
            var XABIA_PRIORITY_FIELDS = ['post_content','post_title','post_excerpt'];
            function xabiaMappingColIconHtml(col) {
                var c = String(col || '');
                if (c.toUpperCase() === 'ID') {
                    return '<span class="xabia-field-id-hint dashicons dashicons-info" title="<?php echo esc_attr__('ID de WordPress: no se envía al entrenamiento IA (casilla IA desmarcada). Marca ENTE en el nombre/título del ente, no en ID.', 'xabia-intelligence'); ?>"></span>';
                }
                if (XABIA_PRIORITY_FIELDS.indexOf(c) !== -1) {
                    return '<span class="xabia-field-brain dashicons dashicons-superhero" title="<?php echo esc_attr__('Campo prioritario para el contexto del agente', 'xabia-intelligence'); ?>"></span>';
                }
                return '';
            }
            var XABIA_ENTE_LABEL_SAME = <?php echo wp_json_encode(__('Mismo campo (valor del ente)', 'xabia-intelligence')); ?>;
            var XABIA_ENTE_LABEL_SR = <?php echo wp_json_encode(__('Nombre visible del ente', 'xabia-intelligence')); ?>;

            function xabiaEscHtml(s) {
                return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            }

            function xabiaUniqueSorted(arr) {
                var o = {};
                (arr || []).forEach(function(x) {
                    if (x !== null && x !== undefined && String(x) !== '') o[String(x)] = true;
                });
                return Object.keys(o).sort();
            }

            function xabiaEnteLabelColOptionsHTML(columns, selectedRaw) {
                var esc = xabiaEscHtml;
                var cols = xabiaUniqueSorted((columns || []).slice());
                var sel = String(selectedRaw || '');
                if (sel !== '' && cols.indexOf(sel) === -1) {
                    cols.push(sel);
                }
                cols = xabiaUniqueSorted(cols);
                var opts = '<option value="">' + esc(XABIA_ENTE_LABEL_SAME) + '</option>';
                cols.forEach(function(c) {
                    var raw = String(c);
                    opts += '<option value="' + esc(raw) + '"' + (raw === sel ? ' selected' : '') + '>' + esc(raw) + '</option>';
                });
                return opts;
            }

            function xabiaRefreshEnteLabelColOptions($root) {
                function refreshOne($scope) {
                    if (!$scope || !$scope.length) {
                        return;
                    }
                    var all = [];
                    $scope.find('.xabia-col-selector').each(function() {
                        var v = $(this).val();
                        if (v) {
                            all.push(v);
                        }
                        $(this).find('option').each(function() {
                            var o = $(this).attr('value');
                            if (o) {
                                all.push(o);
                            }
                        });
                    });
                    all = xabiaUniqueSorted(all);
                    $scope.find('.row-repeater-box').each(function() {
                        var $sel = $(this).find('select.xabia-ente-label-col');
                        if (!$sel.length) {
                            return;
                        }
                        var cur = $sel.val() || '';
                        $sel.html(xabiaEnteLabelColOptionsHTML(all, cur));
                    });
                }
                if (!$root || !$root.length) {
                    return;
                }
                if ($root.is('#xabia-project-form')) {
                    $('#attr-container').each(function() {
                        refreshOne($(this));
                    });
                    $('.multi-attr-container').each(function() {
                        refreshOne($(this));
                    });
                    return;
                }
                refreshOne($root);
            }

            function xabiaDefaultImportRag(col, role) {
                role = role || 'none';
                if (role === 'img' || role === 'logotipo') return false;
                col = String(col || '').trim();
                if (!col || col.toUpperCase() === 'ID') return false;
                if (col.charAt(0) === '@') return false;
                if (/^@?empresa_img/i.test(col)) return false;
                if (/@(empresa_)?(logo|img)/i.test(col)) return false;
                if (/_logo$|_img_\d{1,2}$/i.test(col)) return false;
                if (/^post_(author|date|date_gmt|modified|modified_gmt|status|password|mime_type|parent|guid|menu_order|type|content_filtered)/i.test(col)) return false;
                if (/^cliente_\d+$/i.test(col) || /^testimonio_\d+$/i.test(col)) return false;
                return true;
            }
            function xabiaRecommendedImportRag(col, role) {
                return xabiaDefaultImportRag(col, role);
            }
            function xabiaSyncRowRagStyle($box) {
                var on = $box.find('.xabia-import-rag-cb').is(':checked');
                $box.toggleClass('xabia-rag-excluded', !on);
            }
            function xabiaApplyRagPreset(mode) {
                $('#xabia-project-form .row-repeater-box').each(function() {
                    var $box = $(this);
                    var col = $box.find('.xabia-col-selector').val() || '';
                    var role = $box.find('select[name*="[visual_role]"]').val() || 'none';
                    var $cb = $box.find('.xabia-import-rag-cb');
                    if (!$cb.length) return;
                    if (mode === 'all') $cb.prop('checked', true);
                    else if (mode === 'media') $cb.prop('checked', xabiaDefaultImportRag(col, role));
                    else $cb.prop('checked', xabiaRecommendedImportRag(col, role));
                    xabiaSyncRowRagStyle($box);
                });
            }

            function parseMappingData(data) {
                var columns = [];
                var rows = [];
                if (data == null) return { rows: [], columns: [] };
                if (Array.isArray(data)) {
                    if (data.length === 0) return { rows: [], columns: [] };
                    if (typeof data[0] === 'string') {
                        columns = data.slice();
                        rows = data.map(function(c) {
                            return { csv_col: c, label: c, visual_role: 'none', is_ente: false, ente_label_col: '', instruction: '', import_rag: xabiaDefaultImportRag(c) ? 1 : 0 };
                        });
                    } else {
                        rows = data;
                        data.forEach(function(f) { if (f && f.csv_col) columns.push(f.csv_col); });
                    }
                    return { rows: rows, columns: xabiaUniqueSorted(columns) };
                }
                if (data.fields && Array.isArray(data.fields)) {
                    rows = data.fields;
                    rows.forEach(function(f) { if (f && f.csv_col) columns.push(f.csv_col); });
                    if (data.columns && Array.isArray(data.columns)) {
                        data.columns.forEach(function(c) { columns.push(c); });
                    }
                    return { rows: rows, columns: xabiaUniqueSorted(columns) };
                }
                if (data.columns && Array.isArray(data.columns)) {
                    columns = data.columns.slice();
                    rows = columns.map(function(c) {
                        return { csv_col: c, label: c, visual_role: 'none', is_ente: false, ente_label_col: '', instruction: '', import_rag: xabiaDefaultImportRag(c) ? 1 : 0 };
                    });
                    return { rows: rows, columns: xabiaUniqueSorted(columns) };
                }
                return { rows: [], columns: [] };
            }

            function buildColSelectHTML(nameAttr, columns, selectedRaw) {
                var esc = xabiaEscHtml;
                var sel = String(selectedRaw || '');
                var cols = (columns || []).slice();
                if (sel !== '' && cols.indexOf(sel) === -1) cols.push(sel);
                cols = xabiaUniqueSorted(cols);
                var opts = '<option value="">' + esc('— Columna / campo —') + '</option>';
                cols.forEach(function(col) {
                    var raw = String(col);
                    var e = esc(raw);
                    var selected = (raw === sel) ? ' selected' : '';
                    opts += '<option value="' + e + '"' + selected + '>' + e + '</option>';
                });
                var brain = xabiaMappingColIconHtml(sel);
                return '<div class="row-col-select-wrap">' + brain + '<select class="xabia-col-selector widefat" name="' + nameAttr + '">' + opts + '</select></div>';
            }

            function renderAttributeRows($container, rows, columns, nameBase) {
                $container.empty();
                rows.forEach(function(f, i) {
                    var row = typeof f === 'string' ? { csv_col: f, label: f, visual_role: 'none', is_ente: false, ente_label_col: '', instruction: '' } : f;
                    var csvRaw = row.csv_col || '';
                    var label = xabiaEscHtml(row.label || row.csv_col || '');
                    var role = row.visual_role || 'none';
                    var instruction = xabiaEscHtml(row.instruction || '');
                    var enteLbl = String(row.ente_label_col || '');
                    var enteOpts = xabiaEnteLabelColOptionsHTML(columns, enteLbl);
                    var enteWrapStyle = row.is_ente ? '' : 'display:none;';
                    var roleOpts = ROLES.map(function(rg) {
                        return '<option value="'+rg+'"' + (rg === role ? ' selected' : '') + '>'+ (ROLE_LABELS[rg]||rg) +'</option>';
                    }).join('');
                    var enteChecked = row.is_ente ? ' checked' : '';
                    var importOn = (row.import_rag !== undefined && row.import_rag !== null)
                        ? (parseInt(row.import_rag, 10) !== 0)
                        : xabiaDefaultImportRag(csvRaw, role);
                    var importChecked = importOn ? ' checked' : '';
                    var rowCls = importOn ? '' : ' xabia-rag-excluded';
                    var nameAttr = nameBase + '[' + i + '][csv_col]';
                    var colBlock = buildColSelectHTML(nameAttr, columns, csvRaw);
                    colBlock = colBlock.replace(
                        '<div class="row-col-select-wrap">',
                        '<div class="row-col-select-wrap"><label class="xabia-import-rag-wrap" title="Incluir en entrenamiento IA"><input type="checkbox" class="xabia-import-rag-cb" name="'+nameBase+'['+i+'][import_rag]" value="1"'+importChecked+'><span>IA</span></label>'
                    );
                    var sr = xabiaEscHtml(XABIA_ENTE_LABEL_SR);
                    $container.append(
                        '<div class="row-repeater-box'+rowCls+'">' + colBlock +
                        '<div class="row-inputs">'+
                        '<input type="text" name="'+nameBase+'['+i+'][label]" value="'+label+'" placeholder="Etiqueta" style="width:30%;">'+
                        '<select name="'+nameBase+'['+i+'][visual_role]" class="xabia-role-select" style="width:30%;">'+roleOpts+'</select>'+
                        '<div class="xabia-ente-tools"><div class="check-ente-wrapper"><input type="checkbox" name="'+nameBase+'['+i+'][is_ente]" value="1"'+enteChecked+'> ENTE</div>'+
                        '<div class="xabia-ente-label-wrap" style="'+enteWrapStyle+'"><label class="screen-reader-text">'+sr+'</label>'+
                        '<select name="'+nameBase+'['+i+'][ente_label_col]" class="xabia-ente-label-col widefat">'+enteOpts+'</select></div></div></div></div>'+
                        '<input type="text" name="'+nameBase+'['+i+'][instruction]" class="widefat" placeholder="Instrucción IA..." style="margin-bottom:10px;" value="'+instruction+'">'
                    );
                });
            }

            function renderMapping(data) {
                var p = parseMappingData(data);
                if (p.columns.length === 0 && p.rows.length) {
                    p.columns = xabiaUniqueSorted(p.rows.map(function(r) { return r.csv_col; }).filter(Boolean));
                }
                renderAttributeRows($('#attr-container'), p.rows, p.columns, 'attributes');
                alert("✅ Columnas recibidas. Ajusta el mapeo y guarda el agente.");
            }

            function renderMultiMapping(idx, data) {
                var p = parseMappingData(data);
                if (p.columns.length === 0 && p.rows.length) {
                    p.columns = xabiaUniqueSorted(p.rows.map(function(r) { return r.csv_col; }).filter(Boolean));
                }
                var $container = $('.multi-attr-container[data-idx="' + idx + '"]');
                renderAttributeRows($container, p.rows, p.columns, 'sources[' + idx + '][attributes]');
                $container.closest('.xabia-multi-source-box').find('.multi-csv-feedback[data-idx="'+idx+'"]').text('✅ ' + (p.rows.length) + ' columnas mapeadas.');
            }

            function mergeColsIntoSelectors(extraCols) {
                extraCols = extraCols || [];
                var $sels = $('#xabia-project-form .xabia-col-selector');
                if ($sels.length === 0) {
                    alert('<?php echo esc_js(__('No hay selectores de mapeo visibles. Usa Test SQL o Scan CSV primero, o muestra la sección de atributos.', 'xabia-intelligence')); ?>');
                    return;
                }
                var all = [];
                $sels.each(function() {
                    $(this).find('option').each(function() {
                        var v = $(this).attr('value');
                        if (v) all.push(v);
                    });
                    if ($(this).val()) all.push($(this).val());
                });
                all = all.concat(extraCols);
                all = xabiaUniqueSorted(all);
                var emptyLabel = '— Columna / campo —';
                $sels.each(function() {
                    var $sel = $(this);
                    var cur = $sel.val();
                    $sel.empty().append($('<option></option>').val('').text(emptyLabel));
                    all.forEach(function(col) {
                        $sel.append($('<option></option>').attr('value', col).text(col));
                    });
                    if (cur && all.indexOf(cur) !== -1) $sel.val(cur);
                    $sel.trigger('change');
                });
                xabiaRefreshEnteLabelColOptions($('#xabia-project-form'));
            }

            $('#xabia-project-form').on('change', '.check-ente-wrapper input[type="checkbox"]', function() {
                var $scope = $(this).closest('#attr-container, .multi-attr-container');
                var $wrap = $(this).closest('.row-repeater-box').find('.xabia-ente-label-wrap');
                if ($(this).is(':checked')) {
                    $wrap.show();
                    xabiaRefreshEnteLabelColOptions($scope.length ? $scope : $('#xabia-project-form'));
                } else {
                    $wrap.hide();
                    $wrap.find('select').val('');
                }
            });

            $('#xabia-project-form').on('change', '.xabia-col-selector', function() {
                var $wrap = $(this).closest('.row-col-select-wrap');
                $wrap.find('.xabia-field-brain, .xabia-field-id-hint').remove();
                var v = $(this).val();
                var icon = xabiaMappingColIconHtml(v);
                if (icon) {
                    $(this).before(icon);
                }
                var $scopeMap = $(this).closest('#attr-container, .multi-attr-container');
                xabiaRefreshEnteLabelColOptions($scopeMap.length ? $scopeMap : $('#xabia-project-form'));
            });

            $('#xabia-rag-preset-recommended').on('click', function() { xabiaApplyRagPreset('recommended'); });
            $('#xabia-rag-exclude-media').on('click', function() { xabiaApplyRagPreset('media'); });
            $('#xabia-rag-include-all').on('click', function() { xabiaApplyRagPreset('all'); });
            $('#xabia-project-form').on('change', '.xabia-import-rag-cb', function() {
                xabiaSyncRowRagStyle($(this).closest('.row-repeater-box'));
            });
            $('#xabia-project-form').on('change', 'select[name*="[visual_role]"]', function() {
                var $box = $(this).closest('.row-repeater-box');
                if ($(this).val() === 'img') {
                    $box.find('.xabia-import-rag-cb').prop('checked', false);
                }
                xabiaSyncRowRagStyle($box);
            });

            $('#xabia-btn-load-cpt-meta').on('click', function() {
                var slug = ($('#xabia-cpt-meta-slug').val() || '').trim();
                if (!slug) {
                    alert('<?php echo esc_js(__('Escribe el slug del CPT (post_type).', 'xabia-intelligence')); ?>');
                    return;
                }
                var $btn = $(this).prop('disabled', true);
                xabiaAdminPost({ action: 'xabia_get_meta_fields', post_type: slug }, function(r) {
                    if (r.success && r.data && r.data.meta_keys && r.data.meta_keys.length) {
                        mergeColsIntoSelectors(r.data.meta_keys);
                        alert('<?php echo esc_js(__('Campos añadidos a los selectores del mapeo.', 'xabia-intelligence')); ?>');
                    } else {
                        alert((r.data && r.data.message) ? r.data.message : '<?php echo esc_js(__('No se pudieron cargar los campos.', 'xabia-intelligence')); ?>');
                    }
                }).always(function() { $btn.prop('disabled', false); });
            });

            $('.multi-source-type').on('change', function() {
                var idx = $(this).data('idx');
                var typ = $(this).val();
                $(this).closest('.xabia-multi-source-box').find('.multi-source-panel[data-idx="'+idx+'"]').hide();
                if (typ === 'local_sql' || typ === 'sql') {
                    $(this).closest('.xabia-multi-source-box').find('.multi-source-sql[data-idx="'+idx+'"]').show();
                    var $remoteWrap = $(this).closest('.xabia-multi-source-box').find('.multi-source-remote-fields[data-idx="'+idx+'"]');
                    if (typ === 'sql') $remoteWrap.show();
                    else $remoteWrap.hide();
                } else {
                    $(this).closest('.xabia-multi-source-box').find('.multi-source-'+typ+'[data-idx="'+idx+'"]').show();
                }
            });
            $('.multi-source-type').trigger('change');

            $('.multi-test-sql').on('click', function(e) {
                e.preventDefault();
                var idx = $(this).data('idx');
                var $box = $(this).closest('.xabia-multi-source-box');
                xabiaAdminPost({
                    action: 'xabia_test_sql',
                    source_type: (String($box.find('select[name="sources['+idx+'][type]"]').val() || 'sql')),
                    host: $box.find('input[name="sources['+idx+'][sql_host]"]').val(),
                    user: $box.find('input[name="sources['+idx+'][sql_user]"]').val(),
                    pass: $box.find('input[name="sources['+idx+'][sql_pass]"]').val(),
                    name: $box.find('input[name="sources['+idx+'][sql_name]"]').val(),
                    query: $box.find('textarea[name="sources['+idx+'][sql_query]"]').val()
                }, function(r) {
                    if (r.success) {
                        renderMultiMapping(idx, r.data);
                        if (r.data.message) alert(r.data.message);
                    } else alert((r.data && r.data.message) ? r.data.message : 'Error SQL');
                });
            });

            $('.multi-scan-csv').on('click', function(e) {
                e.preventDefault();
                var idx = $(this).data('idx');
                var $box = $(this).closest('.xabia-multi-source-box');
                var csvFile = $box.find('select[name="sources['+idx+'][csv_filename]"]').val();
                if (!csvFile) { alert('Selecciona un archivo CSV primero.'); return; }
                $box.find('.multi-csv-feedback[data-idx="'+idx+'"]').text('⏳ Escaneando...');
                xabiaAdminPost({
                    action: 'xabia_get_fields',
                    project_id: '<?php echo $edit_id; ?>',
                    csv_file: csvFile
                }, function(r) {
                    if (r.success) renderMultiMapping(idx, r.data);
                    else {
                        $box.find('.multi-csv-feedback[data-idx="'+idx+'"]').text('');
                        alert('Error: ' + (r.data.message || 'Error desconocido'));
                    }
                });
            });

            $('.multi-csv-upload-btn').on('click', function(e) {
                e.preventDefault();
                var idx = $(this).data('idx');
                var $box = $(this).closest('.xabia-multi-source-box');
                var $feedback = $box.find('.multi-csv-feedback[data-idx="'+idx+'"]');
                var input = $box.find('.multi-csv-upload-input[data-idx="'+idx+'"]')[0];
                var projectId = '<?php echo esc_js($edit_id); ?>';
                if (!projectId) {
                    $feedback.text('❌ Guarda primero el proyecto para poder subir CSV.');
                    return;
                }
                if (!input || !input.files || !input.files[0]) {
                    $feedback.text('❌ Selecciona un CSV primero.');
                    return;
                }
                var file = input.files[0];
                if (!/\.csv$/i.test(String(file.name || ''))) {
                    $feedback.text('❌ Solo se permiten archivos .csv');
                    return;
                }
                var fd = new FormData();
                fd.append('action', 'xabia_upload_csv');
                fd.append('nonce', xabiaCurrentNonce);
                fd.append('project_id', projectId);
                fd.append('csv_file', file);
                $feedback.text('⏳ Subiendo CSV...');
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false
                }).done(function(r) {
                    xabiaSyncNonce(r);
                    if (r && r.success && r.data && r.data.file && r.data.file.name) {
                        var uploaded = String(r.data.file.name);
                        loadMultiCsvOptions();
                        setTimeout(function() {
                            var $select = $box.find('select[name="sources['+idx+'][csv_filename]"]');
                            $select.attr('data-saved', uploaded);
                            $select.val(uploaded);
                        }, 100);
                        if (idx === 0) {
                            $('#selected_csv_file').val(uploaded);
                            $('#xabia-csv-state').attr('data-active-csv', uploaded);
                            $('#xabia-csv-active-name').text(uploaded);
                            $('#xabia-csv-has-file').show();
                            $('#xabia-csv-no-file').hide();
                        }
                        $box.find('.multi-csv-upload-input[data-idx="'+idx+'"]').val('');
                        $feedback.text('✅ CSV subido: ' + uploaded);
                    } else {
                        $feedback.text('❌ ' + ((r && r.data && r.data.message) ? r.data.message : 'Error al subir CSV'));
                    }
                }).fail(function() {
                    $feedback.text('❌ Error de red al subir CSV');
                });
            });

            var XABIA_CPT_CORE_MAP = {};
            ['ID','post_author','post_date','post_date_gmt','post_content','post_title','post_excerpt','post_status','comment_status','ping_status','post_password','post_name','to_ping','pinged','post_modified','post_modified_gmt','post_content_filtered','post_parent','guid','menu_order','post_type','post_mime_type','comment_count'].forEach(function(c) { XABIA_CPT_CORE_MAP[c] = 1; });
            var XABIA_CORE_POST_COLS_ORDER = ['ID','post_title','post_content','post_excerpt','post_name','post_date','post_modified','post_status','post_author','post_type','menu_order','comment_count','post_mime_type','post_parent','guid','post_password','comment_status','ping_status','to_ping','pinged','post_date_gmt','post_modified_gmt','post_content_filtered'];

            function xabiaSqlEscLiteral(s) {
                return String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
            }
            function xabiaSqlBacktickId(s) {
                return '`' + String(s).replace(/`/g, '') + '`';
            }
            function xabiaBuildCptSelectSql(postType, selectedVals) {
                var sel = (selectedVals || []).slice();
                var lines = [];
                sel.forEach(function(f) {
                    if (!f || typeof f !== 'string') return;
                    if (f === 'mec_available_slots') return;
                    if (f.indexOf('tax_') === 0) {
                        var tax = f.substring(4);
                        if (!/^[A-Za-z0-9_-]+$/.test(tax)) return;
                        lines.push(
                            '    (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR \', \')\n' +
                            '      FROM {prefix}term_relationships tr\n' +
                            '      INNER JOIN {prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = \'' + xabiaSqlEscLiteral(tax) + '\'\n' +
                            '      INNER JOIN {prefix}terms t ON tt.term_id = t.term_id\n' +
                            '      WHERE tr.object_id = p.ID) AS ' + xabiaSqlBacktickId(f)
                        );
                        return;
                    }
                    if (!/^[\w\-.]+$/.test(f)) return;
                    if (XABIA_CPT_CORE_MAP[f]) {
                        lines.push('    p.' + xabiaSqlBacktickId(f));
                    } else {
                        lines.push('    (SELECT meta_value FROM {prefix}postmeta WHERE post_id = p.ID AND meta_key = \'' + xabiaSqlEscLiteral(f) + '\' LIMIT 1) AS ' + xabiaSqlBacktickId(f));
                    }
                });
                if (lines.length === 0) lines.push('    p.ID');
                return 'SELECT\n' + lines.join(',\n') + '\nFROM {prefix}posts p\nWHERE p.post_type = \'' + xabiaSqlEscLiteral(postType) + '\'\n  AND p.post_status = \'publish\'';
            }
            function xabiaCptAssistShow(on) {
                var d = on ? 'block' : 'none';
                $('#xabia-cpt-assistant-backdrop, #xabia-cpt-assistant-modal').css('display', d).attr('aria-hidden', on ? 'false' : 'true');
            }
            function xabiaFillPostTypeOptions(types) {
                var $pt = $('#xabia-cpt-assistant-pt');
                $pt.empty().append($('<option></option>').val('').text('<?php echo esc_js(__('— Elige tipo —', 'xabia-intelligence')); ?>'));
                (types || []).forEach(function(pt) {
                    if (!pt || !pt.name) return;
                    var label = (pt.label || pt.name) + ' (' + pt.name + ')';
                    if (pt.remote) {
                        label += ' — <?php echo esc_js(__('fuente SQL', 'xabia-intelligence')); ?>';
                    }
                    $pt.append($('<option></option>').val(pt.name).text(label));
                });
            }
            function xabiaCptCoreDisplayLabel(key) {
                var L = XABIA_CURATOR_UI && XABIA_CURATOR_UI.coreLabels;
                return (L && L[key]) ? L[key] : key;
            }
            function xabiaCuratedFieldRow(fieldId, labelText, opts) {
                opts = opts || {};
                var recent = !!opts.inRecentSample;
                var suggested = !!opts.suggested;
                var id = 'xabia-deep-cb-' + String(fieldId).replace(/[^a-zA-Z0-9_-]/g, '_');
                var $row = $('<div class="xabia-curate-row" role="listitem"></div>').attr('data-field', fieldId);
                if (opts.titleAttr) {
                    $row.attr('title', opts.titleAttr);
                }
                var $ledger = $('<div class="xabia-curate-row__ledger"></div>');
                var $cb = $('<input type="checkbox" class="xabia-deep-cb">').attr('id', id).val(fieldId).data('label', labelText);
                if (suggested) {
                    $cb.prop('checked', true);
                }
                var $ml = $('<label class="xabia-curate-row__main"></label>').attr('for', id);
                $ml.append($cb);
                $ml.append($('<span class="xabia-curate-row__title"></span>').text(labelText));
                $ml.append($('<code class="xabia-curate-row__key"></code>').text(fieldId));
                $ledger.append($ml);
                if (recent) {
                    $ledger.append($('<span class="xabia-field-badge xabia-field-badge--data"></span>').text(XABIA_CURATOR_UI.badgeRecent));
                }
                var erId = 'xabia-ente-rb-' + String(fieldId).replace(/[^a-zA-Z0-9_-]/g, '_');
                var $ente = $('<label class="xabia-curate-row__ente"></label>').attr('for', erId);
                $ente.append($('<input type="radio" name="xabia_curate_ente">').attr('id', erId).val(fieldId));
                $ente.append($('<span class="xabia-curate-row__ente-label"></span>').text(XABIA_CURATOR_UI.enteShort));
                $row.append($ledger).append($ente);
                return $row;
            }
            function xabiaCurateSyncEnteRadiosState() {
                $('#xabia-field-selector .xabia-curate-row').each(function() {
                    var on = $(this).find('.xabia-deep-cb').is(':checked');
                    $(this).find('input[name="xabia_curate_ente"]').prop('disabled', !on);
                });
                var $rb = $('#xabia-field-selector input[name="xabia_curate_ente"]:checked');
                if ($rb.length && $rb.is(':disabled')) {
                    $rb.prop('checked', false);
                }
            }
            function xabiaCuratePickDefaultEnte() {
                xabiaCurateSyncEnteRadiosState();
                var $titleRb = $('#xabia-field-selector .xabia-curate-row[data-field="post_title"] input[name="xabia_curate_ente"]');
                if ($titleRb.length && !$titleRb.is(':disabled')) {
                    $titleRb.prop('checked', true);
                    return;
                }
                var $nameRb = $('#xabia-field-selector .xabia-curate-row[data-field="name"] input[name="xabia_curate_ente"]');
                if ($nameRb.length && !$nameRb.is(':disabled')) {
                    $nameRb.prop('checked', true);
                    return;
                }
                var $first = $('#xabia-field-selector .xabia-deep-cb:checked').first().closest('.xabia-curate-row').find('input[name="xabia_curate_ente"]');
                if ($first.length && !$first.is(':disabled')) {
                    $first.prop('checked', true);
                }
            }
            function xabiaRenderDeepSchemaUI(data) {
                var $wrap = $('#xabia-cpt-assistant-deep-wrap');
                var $fs = $('#xabia-field-selector');
                $('#xabia-field-heading-core').text(XABIA_CURATOR_UI.sectionIdentity);
                $('#xabia-field-heading-meta').text(XABIA_CURATOR_UI.sectionMeta);
                $('#xabia-field-heading-tax').text(XABIA_CURATOR_UI.sectionTax);
                $('#xabia-field-heading-virtual').text(XABIA_CURATOR_UI.sectionVirtual);
                $('#xabia-field-selector-core, #xabia-field-selector-meta, #xabia-field-selector-tax, #xabia-field-selector-virtual').empty();
                $('#xabia-field-virtual-hint').hide().text('');
                $('#xabia-field-selector-section-virtual').hide();
                var hints = (data.mapping_hints && data.mapping_hints.length) ? data.mapping_hints : ['ID', 'post_title', 'post_content', 'post_excerpt'];
                var hintSet = {};
                hints.forEach(function(h) { if (h) hintSet[h] = true; });
                var $core = $('#xabia-field-selector-core');
                var nCore = 0;
                (data.core || []).forEach(function(c) {
                    if (!c || !XABIA_CPT_CORE_MAP[c]) return;
                    nCore++;
                    var lb = xabiaCptCoreDisplayLabel(c);
                    $core.append(xabiaCuratedFieldRow(c, lb, { inRecentSample: false, suggested: !!hintSet[c] }));
                });
                if (!nCore) {
                    $core.append($('<p class="description xabia-curate-empty"></p>').text(XABIA_CURATOR_UI.emptyCore));
                }
                var $meta = $('#xabia-field-selector-meta');
                var nMeta = 0;
                (data.meta || []).forEach(function(m) {
                    var k = m.key || m;
                    var lb = m.label || k;
                    if (XABIA_CPT_CORE_MAP[k]) return;
                    nMeta++;
                    $meta.append(xabiaCuratedFieldRow(k, lb, {
                        inRecentSample: !!m.in_recent_sample,
                        suggested: !!hintSet[k]
                    }));
                });
                if (!nMeta) {
                    $meta.append($('<p class="description xabia-curate-empty"></p>').text(XABIA_CURATOR_UI.emptyMeta));
                }
                var $tax = $('#xabia-field-selector-tax');
                var nTax = 0;
                (data.taxonomies || []).forEach(function(t) {
                    if (!t || !t.name) return;
                    nTax++;
                    var fid = 'tax_' + t.name;
                    var lb = t.label || t.name;
                    $tax.append(xabiaCuratedFieldRow(fid, lb, { inRecentSample: false, suggested: !!hintSet[fid] }));
                });
                if (!nTax) {
                    $tax.append($('<p class="description xabia-curate-empty"></p>').text(XABIA_CURATOR_UI.emptyTax));
                }
                var $virt = $('#xabia-field-selector-virtual');
                if ((data.virtual || []).length) {
                    $('#xabia-field-selector-section-virtual').show();
                    var v0 = data.virtual[0];
                    if (v0 && v0.description) {
                        $('#xabia-field-virtual-hint').text(v0.description).show();
                    }
                    (data.virtual || []).forEach(function(v) {
                        if (!v || !v.id) return;
                        $virt.append(xabiaCuratedFieldRow(v.id, v.label || v.id, {
                            inRecentSample: false,
                            suggested: !!hintSet[v.id],
                            titleAttr: v.description || ''
                        }));
                    });
                }
                $fs.find('.xabia-deep-cb').each(function() {
                    var fid = $(this).val();
                    if (!hintSet[fid]) {
                        $(this).prop('checked', false);
                    }
                });
                if (!$fs.find('.xabia-deep-cb:checked').length) {
                    $fs.find('.xabia-deep-cb').first().prop('checked', true);
                }
                xabiaCuratePickDefaultEnte();
                $wrap.show();
            }
            function xabiaCptAssistGetSelectedFields() {
                var $wrap = $('#xabia-cpt-assistant-deep-wrap');
                if (!$wrap.is(':visible')) return [];
                var s = [];
                $('#xabia-field-selector input.xabia-deep-cb:checked').each(function() { s.push($(this).val()); });
                return s;
            }
            function xabiaCptAssistGetSelectedFieldsForSql() {
                var s = xabiaCptAssistGetSelectedFields().filter(function(f) { return f !== 'mec_available_slots'; });
                var pt = ($('#xabia-cpt-assistant-pt').val() || '').trim();
                if (XABIA_RESERVAS && XABIA_RESERVAS.ameliaPt && pt === XABIA_RESERVAS.ameliaPt) {
                    return s;
                }
                if (s.indexOf('ID') === -1) {
                    s.unshift('ID');
                }
                return s;
            }
            function xabiaBuildMappingRowsFromDeepSelection() {
                var enteVal = '';
                var $er = $('#xabia-field-selector input[name="xabia_curate_ente"]:checked:not(:disabled)');
                if ($er.length) enteVal = $er.val() || '';
                var selected = xabiaCptAssistGetSelectedFields();
                var rows = [];
                $('#xabia-field-selector .xabia-deep-cb:checked').each(function() {
                    var fid = $(this).val();
                    var label = $(this).data('label') || fid;
                    var role = (fid === 'post_title' || fid === 'name') ? 'title' : 'none';
                    var isEnte = (fid === enteVal);
                    var enteLabelCol = '';
                    if (isEnte && enteVal && selected.indexOf('post_title') !== -1 && enteVal !== 'post_title') {
                        enteLabelCol = 'post_title';
                    }
                    rows.push({
                        csv_col: fid,
                        label: label,
                        visual_role: role,
                        is_ente: isEnte,
                        ente_label_col: enteLabelCol,
                        instruction: ''
                    });
                });
                return rows;
            }
            function xabiaAmeliaEnteToolsToggle(pt) {
                if (!XABIA_RESERVAS || !XABIA_RESERVAS.amelia || !XABIA_RESERVAS.ameliaPt) {
                    $('#xabia-amelia-ente-tools').hide();
                    return;
                }
                if (pt === XABIA_RESERVAS.ameliaPt) {
                    $('#xabia-amelia-ente-tools').show();
                    xabiaLoadAmeliaEnteList();
                } else {
                    $('#xabia-amelia-ente-tools').hide();
                    $('#xabia-amelia-ente-list').empty();
                    $('#xabia-amelia-ente-hint').text('');
                }
            }
            function xabiaFilterAmeliaEnteList(q) {
                var $ul = $('#xabia-amelia-ente-list');
                $ul.empty();
                if (!xabiaAmeliaServicesCache || !xabiaAmeliaServicesCache.length) {
                    $ul.append($('<li class="description" style="padding:8px;"></li>').text('<?php echo esc_js(__('No hay servicios visibles.', 'xabia-intelligence')); ?>'));
                    return;
                }
                q = (q || '').toLowerCase();
                var n = 0;
                xabiaAmeliaServicesCache.forEach(function(s) {
                    if (!s || s.id == null) return;
                    var name = (s.name || '').toString();
                    if (q && name.toLowerCase().indexOf(q) === -1) return;
                    n++;
                    var slug = 'amelia-svc-' + s.id;
                    var $li = $('<li style="padding:8px;border-bottom:1px solid #f0f0f1;cursor:pointer;"></li>');
                    $li.append($('<strong></strong>').text('ID ' + s.id + ' — ' + name));
                    $li.attr('title', '<?php echo esc_js(__('Clic: copiar slug sugerido (?ente_id=)', 'xabia-intelligence')); ?>');
                    $li.on('click', function() {
                        var hint = '<?php echo esc_js(__('Slug sugerido (modo estricto / Ente):', 'xabia-intelligence')); ?> ' + slug + ' — ?ente_id=' + slug;
                        $('#xabia-amelia-ente-hint').text(hint);
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(slug).catch(function() {});
                        }
                    });
                    $ul.append($li);
                });
                if (!n) {
                    $ul.append($('<li class="description" style="padding:8px;"></li>').text('<?php echo esc_js(__('Ningún resultado.', 'xabia-intelligence')); ?>'));
                }
            }
            function xabiaLoadAmeliaEnteList() {
                if (xabiaAmeliaServicesCache) {
                    xabiaFilterAmeliaEnteList($('#xabia-amelia-ente-search').val() || '');
                    return;
                }
                xabiaAdminPost({ action: 'xabia_reservas_amelia_services', q: '' }, function(r) {
                    if (!r.success || !r.data || !r.data.services) {
                        xabiaAmeliaServicesCache = [];
                        xabiaFilterAmeliaEnteList('');
                        return;
                    }
                    xabiaAmeliaServicesCache = r.data.services;
                    xabiaFilterAmeliaEnteList($('#xabia-amelia-ente-search').val() || '');
                });
            }
            function xabiaCptAssistApplySql() {
                var pt = ($('#xabia-cpt-assistant-pt').val() || '').trim();
                var selected = xabiaCptAssistGetSelectedFieldsForSql();
                if (!pt) return;
                var sql;
                if (XABIA_RESERVAS && XABIA_RESERVAS.ameliaPt && pt === XABIA_RESERVAS.ameliaPt) {
                    sql = "SELECT id AS ID, name AS Ente, description AS Descripcion, price AS Precio, status AS Estado\nFROM {prefix}amelia_services\nWHERE status = 'visible'\nORDER BY name ASC";
                } else {
                    sql = xabiaBuildCptSelectSql(pt, selected);
                }
                if (!xabiaCptAssistTarget) return;
                if (xabiaCptAssistTarget.type === 'single') {
                    $('#sql_query').val(sql);
                } else if (xabiaCptAssistTarget.type === 'multi') {
                    var idx = xabiaCptAssistTarget.idx;
                    $('textarea[name="sources[' + idx + '][sql_query]"]').val(sql);
                }
            }
            function xabiaOpenCptAssistant(target) {
                xabiaCptAssistTarget = target;
                xabiaAmeliaServicesCache = null;
                $('#xabia-amelia-ente-search').val('');
                $('.xabia-cpt-assistant-status').text('');
                $('#xabia-cpt-assistant-source-hint').hide().text('');
                $('#xabia-cpt-assistant-deep-wrap').hide();
                $('#xabia-field-selector-core, #xabia-field-selector-meta, #xabia-field-selector-tax, #xabia-field-selector-virtual').empty();
                $('#xabia-field-selector-section-virtual').hide();
                $('#xabia-field-virtual-hint').hide().text('');
                $('#xabia-cpt-assistant-pt').empty().append($('<option></option>').val('').text('<?php echo esc_js(__('— Cargando… —', 'xabia-intelligence')); ?>'));
                xabiaAmeliaEnteToolsToggle('');
                xabiaCptAssistShow(true);
                xabiaAdminPost(xabiaCptAssistSourcePayload({ action: 'xabia_get_wp_schema' }), function(r) {
                    var $srcHint = $('#xabia-cpt-assistant-source-hint');
                    if (!r.success || !r.data || !r.data.post_types) {
                        $srcHint.hide().text('');
                        $('.xabia-cpt-assistant-status').text((r.data && r.data.message) ? r.data.message : '<?php echo esc_js(__('No se pudo cargar el esquema.', 'xabia-intelligence')); ?>');
                        return;
                    }
                    xabiaFillPostTypeOptions(r.data.post_types);
                    var uiHint = (r.data.ui_hint || '').toString();
                    if (uiHint) {
                        $srcHint.text(uiHint).show();
                    } else {
                        $srcHint.hide().text('');
                    }
                    var hint = '';
                    if (r.data.message) {
                        hint = r.data.message;
                    }
                    $('.xabia-cpt-assistant-status').text(hint);
                });
            }
            $('#btn-xabia-cpt-assistant').on('click', function() { xabiaOpenCptAssistant({ type: 'single' }); });
            $(document).on('click', '.xabia-cpt-assistant-multi', function() {
                xabiaOpenCptAssistant({ type: 'multi', idx: $(this).data('idx') });
            });
            $('#xabia-cpt-assistant-pt').on('change', function() {
                var pt = ($(this).val() || '').trim();
                var $st = $('.xabia-cpt-assistant-status');
                $('#xabia-cpt-assistant-deep-wrap').hide();
                if (!pt) { $st.text(''); return; }
                $st.text('<?php echo esc_js(__('Descubriendo esquema…', 'xabia-intelligence')); ?>');
                xabiaAdminPost(xabiaCptAssistSourcePayload({
                    action: 'xabia_get_deep_schema',
                    post_type: pt
                }), function(r) {
                    if (!r.success) {
                        $st.text((r.data && r.data.message) ? r.data.message : '');
                        return;
                    }
                    $st.text('');
                    xabiaRenderDeepSchemaUI(r.data);
                    xabiaCptAssistApplySql();
                    xabiaAmeliaEnteToolsToggle(pt);
                });
            });
            $(document).on('change', '#xabia-field-selector .xabia-deep-cb', function() {
                if (!$('#xabia-cpt-assistant-modal').is(':visible')) return;
                if (!$(this).is(':checked') && $(this).closest('.xabia-curate-row').find('input[name="xabia_curate_ente"]').is(':checked')) {
                    xabiaCuratePickDefaultEnte();
                } else {
                    xabiaCurateSyncEnteRadiosState();
                }
                xabiaCptAssistApplySql();
            });
            $(document).on('change', '#xabia-field-selector input[name="xabia_curate_ente"]', function() {
                if (!$('#xabia-cpt-assistant-modal').is(':visible')) return;
                xabiaCptAssistApplySql();
            });
            function xabiaCptAssistantApplyMappingToForm(showAlert) {
                var pt = ($('#xabia-cpt-assistant-pt').val() || '').trim();
                if (!pt) {
                    alert('<?php echo esc_js(__('Elige un tipo de contenido.', 'xabia-intelligence')); ?>');
                    return false;
                }
                var rows = xabiaBuildMappingRowsFromDeepSelection();
                if (!rows.length) {
                    alert('<?php echo esc_js(__('Marca al menos un campo para el mapeo.', 'xabia-intelligence')); ?>');
                    return false;
                }
                xabiaCptAssistApplySql();
                var cols = rows.map(function(r) { return r.csv_col; });
                var payload = { fields: rows, columns: cols };
                if (!xabiaCptAssistTarget) return false;
                if (xabiaCptAssistTarget.type === 'single') {
                    renderMapping(payload);
                } else {
                    renderMultiMapping(xabiaCptAssistTarget.idx, payload);
                }
                if (showAlert) {
                    alert('<?php echo esc_js(__('Mapeo y SQL aplicados. Guarda el agente para conservar los cambios.', 'xabia-intelligence')); ?>');
                }
                return true;
            }
            $('#xabia-cpt-assistant-apply-mapping').on('click', function() {
                xabiaCptAssistantApplyMappingToForm(true);
            });
            $('#xabia-amelia-ente-search').on('input', function() {
                if (!XABIA_RESERVAS || !XABIA_RESERVAS.ameliaPt) return;
                if (($('#xabia-cpt-assistant-pt').val() || '').trim() !== XABIA_RESERVAS.ameliaPt) return;
                if (!xabiaAmeliaServicesCache) {
                    xabiaLoadAmeliaEnteList();
                    return;
                }
                xabiaFilterAmeliaEnteList($(this).val() || '');
            });
            function xabiaCptAssistantCloseClick() {
                var $wrap = $('#xabia-cpt-assistant-deep-wrap');
                if ($wrap.is(':visible') && $('#xabia-field-selector .xabia-deep-cb:checked').length) {
                    if (window.confirm(XABIA_CURATOR_UI.closeConfirm)) {
                        xabiaCptAssistantApplyMappingToForm(false);
                    }
                }
                xabiaCptAssistShow(false);
            }
            $('#xabia-cpt-assistant-close, #xabia-cpt-assistant-backdrop').on('click', function() { xabiaCptAssistantCloseClick(); });

            $('#btn-test-addon').click(function(e){
                e.preventDefault();
                var b = $(this).text('⏳...');
                xabiaAdminPost({
                    action: 'xabia_test_addon',
                    addon_slug: $('#addon_slug').val(),
                    host: $('#sql_host').val(),
                    user: $('#sql_user').val(),
                    pass: $('#sql_pass').val(),
                    name: $('#sql_name').val(),
                    prefix: $('#sql_prefix').val()
                }, function(r) {
                    b.text('🔗 Conectar y Mapear');
                    if (r.success) renderMapping(r.data);
                    else alert((r.data && r.data.message) ? r.data.message : '');
                });
            });

            $('#btn-test-sql').click(function(e){
                e.preventDefault();
                xabiaAdminPost({
                    action: 'xabia_test_sql',
                    source_type: ($('#xabia-source-select').val() || 'sql'),
                    sql_preset: $('#xabia_sql_preset').val() || '',
                    host: $('#sql_host').val(),
                    user: $('#sql_user').val(),
                    pass: $('#sql_pass').val(),
                    name: $('#sql_name').val(),
                    prefix: $('#sql_prefix').val(),
                    query: $('#sql_query').val()
                }, function(r) {
                    if (r.success) {
                        if (r.data.message) alert(r.data.message);
                        renderMapping(r.data);
                    } else alert((r.data && r.data.message) ? r.data.message : '');
                });
            });

            $('#xabia-apply-remote-mec-preset').on('click', function(e) {
                e.preventDefault();
                $('#xabia-source-select').val('sql').trigger('change');
                $('#xabia_sql_preset').val('mec_remote');
                $('#sql_query').val(XABIA_REMOTE_MEC_SQL);
                if (!$('#sql_prefix').val()) {
                    $('#sql_prefix').val('wp_');
                }
                renderMapping({ fields: XABIA_REMOTE_MEC_FIELDS });
                $('#xabia-remote-mec-preset-state').show();
            });

            $('#btn-scan-csv').click(function(e){
                e.preventDefault();
                let pid = '<?php echo $edit_id; ?>';
                let selectedFile = $('#selected_csv_file').val();
                if (!selectedFile) {
                    alert('Primero sube un CSV para este proyecto.');
                    return;
                }
                xabiaAdminPost({
                    action: 'xabia_get_fields',
                    project_id: pid,
                    csv_file: selectedFile
                }, function(r) {
                    if (r.success) renderMapping(r.data);
                    else alert('Error al leer el CSV: ' + (r.data.message || 'Error desconocido'));
                });
            });

            var xabiaWorkProgressHandle = null;

            function xabiaSetPipelineBusy(busy) {
                var $box = $('.xabia-status-box').first();
                var $btns = $('#btn-sync-ajax, #btn-train-ajax, #btn-sync-brain-cloud, #btn-clear-ajax, #btn-knowledge-preview');
                if (busy) {
                    $box.attr('aria-busy', 'true').addClass('xabia-status-box--busy');
                    $btns.each(function() {
                        var $b = $(this);
                        $b.data('xabia-was-disabled', $b.prop('disabled'));
                        $b.prop('disabled', true).attr('aria-disabled', 'true');
                    });
                    return;
                }
                $box.removeAttr('aria-busy').removeClass('xabia-status-box--busy');
                $btns.each(function() {
                    var $b = $(this);
                    if ($b.data('xabia-was-disabled')) {
                        $b.removeData('xabia-was-disabled');
                        return;
                    }
                    $b.prop('disabled', false).removeAttr('aria-disabled');
                });
            }

            function xabiaStopWorkProgress() {
                if (xabiaWorkProgressHandle) {
                    clearInterval(xabiaWorkProgressHandle.stepTimer);
                    clearInterval(xabiaWorkProgressHandle.elapsedTimer);
                    xabiaWorkProgressHandle = null;
                }
                $('#xabia-work-progress').attr('hidden', 'hidden').hide();
                xabiaSetPipelineBusy(false);
            }

            function xabiaStartWorkProgress(cfg) {
                cfg = cfg || {};
                xabiaStopWorkProgress();
                var $box = $('#xabia-work-progress');
                var steps = cfg.steps && cfg.steps.length ? cfg.steps : ['<?php echo esc_js(__('Trabajando…', 'xabia-intelligence')); ?>'];
                var stepIdx = 0;
                var started = Date.now();
                $box.removeAttr('hidden').show();
                $box.find('.xabia-work-progress__title').text(cfg.title || '<?php echo esc_js(__('Xabia está trabajando', 'xabia-intelligence')); ?>');
                function tickStep() {
                    $box.find('.xabia-work-progress__step').text(steps[stepIdx % steps.length]);
                    stepIdx++;
                }
                function tickElapsed() {
                    var s = Math.floor((Date.now() - started) / 1000);
                    var m = Math.floor(s / 60);
                    var rs = s % 60;
                    var txt = m > 0
                        ? '<?php echo esc_js(__('Tiempo transcurrido: %1$d min %2$d s — no cierres esta pestaña.', 'xabia-intelligence')); ?>'
                            .replace('%1$d', String(m)).replace('%2$d', String(rs))
                        : '<?php echo esc_js(__('Tiempo transcurrido: %d s — no cierres esta pestaña.', 'xabia-intelligence')); ?>'.replace('%d', String(s));
                    $box.find('.xabia-work-progress__elapsed').text(txt);
                }
                tickStep();
                tickElapsed();
                xabiaWorkProgressHandle = {
                    stepTimer: setInterval(tickStep, 4000),
                    elapsedTimer: setInterval(tickElapsed, 1000)
                };
                if (cfg.disablePipeline !== false) {
                    xabiaSetPipelineBusy(true);
                }
            }

            function xabiaSetSyncFeedback(msg, kind, useHtml) {
                var $el = $('#sync-feedback');
                $el.removeClass('xabia-sync-feedback--success xabia-sync-feedback--error xabia-sync-feedback--pending');
                if (kind === 'success') { $el.addClass('xabia-sync-feedback--success'); }
                else if (kind === 'error') { $el.addClass('xabia-sync-feedback--error'); }
                else if (kind === 'pending') { $el.addClass('xabia-sync-feedback--pending'); }
                if (useHtml) { $el.html(msg); } else { $el.text(msg); }
            }

            function xabiaUpdatePipelineAlert(data) {
                if (!data) { return; }
                var $alert = $('#xabia-pipeline-alert');
                var $train = $('#btn-train-ajax');
                if (data.pipeline_alert) {
                    $alert.html(data.pipeline_alert).addClass('xabia-pipeline-alert--visible xabia-pipeline-alert--error').show();
                } else {
                    $alert.removeClass('xabia-pipeline-alert--visible xabia-pipeline-alert--error').hide().empty();
                }
                if (data.tokens_remaining != null && data.tokens_remaining !== '') {
                    var $bal = $('#xabia-agent-token-balance');
                    if ($bal.length) {
                        $bal.text('<?php echo esc_js(__('Saldo de licencia:', 'xabia-intelligence')); ?> ' + String(data.tokens_remaining) + ' <?php echo esc_js(__('tokens', 'xabia-intelligence')); ?>');
                    }
                }
                if (data.tokens_depleted) {
                    $train.prop('disabled', true).attr('aria-disabled', 'true').attr('title', '<?php echo esc_js(__('Saldo de tokens agotado. Recarga en Cartera / Wallet.', 'xabia-intelligence')); ?>');
                } else {
                    $train.prop('disabled', false).removeAttr('aria-disabled').removeAttr('title');
                }
            }

            function xabiaUpdateMemorySummary(data) {
                if (!data) { return; }
                var $stats = $('#xabia-memory-summary-stats');
                if (!$stats.length || data.vector_total == null) { return; }
                var total = parseInt(data.vector_total, 10) || 0;
                var ready = parseInt(data.vector_ready, 10) || 0;
                var pending = parseInt(data.train_pending, 10) || 0;
                if (total === 0) {
                    $stats.text('<?php echo esc_js(__('Aún no hay datos importados. Usa «Sincronizar datos» en el panel de la derecha.', 'xabia-intelligence')); ?>');
                    return;
                }
                var line = '<?php echo esc_js(__('Registros sincronizados: %1$s · Listos para el chat: %2$s', 'xabia-intelligence')); ?>'
                    .replace('%1$s', total.toLocaleString())
                    .replace('%2$s', ready.toLocaleString());
                if (pending > 0) {
                    line += ' · <?php echo esc_js(__('Pendientes de entrenar: %s', 'xabia-intelligence')); ?>'.replace('%s', pending.toLocaleString());
                } else if (data.vector_search_enabled === false) {
                    line += ' · <?php echo esc_js(__('Modo palabras clave (sin embeddings)', 'xabia-intelligence')); ?>';
                }
                $stats.text(line);
            }

            function xabiaApplyPipelinePayload(data) {
                if (!data) { return; }
                xabiaUpdatePipelineAlert(data);
                xabiaUpdateMemorySummary(data);
            }

            var xabiaAgentDisplayName = <?php echo wp_json_encode(($edit_id !== 'new' && is_array($data)) ? (string) ($data['name'] ?? __('el agente', 'xabia-intelligence')) : __('el agente', 'xabia-intelligence')); ?>;
            var xabiaCatalogEntityPlural = <?php echo wp_json_encode((is_array($data) && class_exists('Xabia_Knowledge_Ingest', false)) ? Xabia_Knowledge_Ingest::resolve_catalog_entity_plural_label($data) : __('entes', 'xabia-intelligence')); ?>;
            var xabiaCatalogEntitySingular = <?php echo wp_json_encode((is_array($data) && class_exists('Xabia_Knowledge_Ingest', false)) ? Xabia_Knowledge_Ingest::resolve_catalog_entity_singular_label($data) : __('ente', 'xabia-intelligence')); ?>;

            function xabiaPromptOrphanCleanup(orphans, projectId) {
                if (!orphans || !orphans.length) {
                    return;
                }
                if (orphans.length > 40) {
                    return;
                }
                var agentName = xabiaAgentDisplayName || '<?php echo esc_js(__('el agente', 'xabia-intelligence')); ?>';
                var msg;
                if (orphans.length === 1) {
                    msg = '<?php echo esc_js(__('El ente «%s» ya no existe en la fuente de datos.', 'xabia-intelligence')); ?>'
                        .replace('%s', orphans[0].label || orphans[0].source_record_id);
                    msg += '\n\n<?php echo esc_js(__('¿Lo borramos de la memoria de %s?', 'xabia-intelligence')); ?>'.replace('%s', agentName);
                } else {
                    msg = '<?php echo esc_js(__('Hay %d ente(s) en la memoria de %s que ya no están en la fuente de datos:', 'xabia-intelligence')); ?>'
                        .replace('%d', String(orphans.length))
                        .replace('%s', agentName);
                    msg += '\n\n' + orphans.map(function(o) { return '• ' + (o.label || o.source_record_id); }).join('\n');
                    msg += '\n\n<?php echo esc_js(__('¿Borramos todas de la memoria de %s?', 'xabia-intelligence')); ?>'.replace('%s', agentName);
                }
                if (!window.confirm(msg)) {
                    xabiaSetSyncFeedback('<?php echo esc_js(__('Sincronización OK. Registros huérfanos conservados en memoria.', 'xabia-intelligence')); ?>', 'pending');
                    return;
                }
                xabiaAdminPost({
                    action: 'xabia_purge_orphan_knowledge',
                    project_id: projectId,
                    vector_ids: orphans.reduce(function(acc, o) {
                        if (o.vector_ids && o.vector_ids.length) {
                            return acc.concat(o.vector_ids);
                        }
                        if (o.id) { acc.push(o.id); }
                        return acc;
                    }, [])
                }, function(r) {
                    xabiaApplyPipelinePayload(r.data);
                    if (r.success) {
                        xabiaSetSyncFeedback((r.data && r.data.message) ? r.data.message : 'OK', 'success');
                    } else {
                        xabiaSetSyncFeedback((r.data && r.data.message) ? r.data.message : 'Error', 'error');
                    }
                });
            }

            $('#btn-sync-ajax').click(function(){
                xabiaSetSyncFeedback('', 'pending');
                xabiaStartWorkProgress({
                    title: '<?php echo esc_js(__('Sincronizando datos', 'xabia-intelligence')); ?>',
                    steps: [
                        '<?php echo esc_js(__('Leyendo el catálogo de la fuente…', 'xabia-intelligence')); ?>',
                        '<?php echo esc_js(__('Importando %s en el idioma principal…', 'xabia-intelligence')); ?>'.replace('%s', xabiaCatalogEntityPlural),
                        '<?php echo esc_js(__('Comparando con la memoria actual…', 'xabia-intelligence')); ?>',
                        '<?php echo esc_js(__('Normalizando identificadores y limpiando duplicados…', 'xabia-intelligence')); ?>',
                        '<?php echo esc_js(__('Guardando en la memoria del agente…', 'xabia-intelligence')); ?>'
                    ]
                });
                xabiaAdminPost({ action: 'xabia_sync_content', project_id: '<?php echo esc_js((string) $edit_id); ?>' }, function(r) {
                    xabiaStopWorkProgress();
                    xabiaApplyPipelinePayload(r.data);
                    if (r.success) {
                        var msg = r.data.message || 'OK';
                        if (r.data.tokens_depleted && r.data.train_pending > 0) {
                            xabiaSetSyncFeedback(msg + ' — <?php echo esc_js(__('datos guardados, pero el entrenamiento está pausado por falta de tokens.', 'xabia-intelligence')); ?>', 'error');
                        } else {
                            xabiaSetSyncFeedback(msg, 'success');
                        }
                        if (r.data.orphans && r.data.orphans.length) {
                            xabiaPromptOrphanCleanup(r.data.orphans, '<?php echo esc_js((string) $edit_id); ?>');
                        }
                    } else {
                        xabiaSetSyncFeedback((r.data && r.data.message) ? r.data.message : 'Error', 'error');
                    }
                });
            });

            $('#btn-train-ajax').click(function(){
                if ($(this).prop('disabled')) {
                    xabiaSetSyncFeedback('<?php echo esc_js(__('Saldo de tokens agotado. Recarga en Cartera / Wallet antes de entrenar.', 'xabia-intelligence')); ?>', 'error');
                    return;
                }
                xabiaAdminPost({ action: 'xabia_train_estimate', project_id: '<?php echo esc_js((string) $edit_id); ?>' }, function(est) {
                    if (!est.success || !est.data) {
                        xabiaSetSyncFeedback((est.data && est.data.message) ? est.data.message : 'Error', 'error');
                        return;
                    }
                    var d = est.data;
                    xabiaApplyPipelinePayload(d);
                    if (!d.pending || d.pending < 1) {
                        var vTotal = parseInt(d.vector_total, 10) || 0;
                        var vReady = parseInt(d.vector_ready, 10) || 0;
                        if (vTotal > 0 && vReady < vTotal) {
                            xabiaSetSyncFeedback(
                                '<?php echo esc_js(__('Hay %1$s registros sincronizados pero solo %2$s con embedding local. Pulsa de nuevo «Entrenar IA» o usa «Subir cerebro al Hub» (modo texto).', 'xabia-intelligence')); ?>'
                                    .replace('%1$s', String(vTotal))
                                    .replace('%2$s', String(vReady)),
                                'error'
                            );
                            return;
                        }
                        if (vTotal > 0 && d.hub_rag_hint) {
                            xabiaSetSyncFeedback(
                                '<?php echo esc_js(__('Memoria local al día. Con Hub RAG puedes subir el cerebro sin entrenar aquí: usa «Subir cerebro al Hub».', 'xabia-intelligence')); ?>',
                                'success'
                            );
                            return;
                        }
                        xabiaSetSyncFeedback('<?php echo esc_js(__('No hay registros pendientes de entrenar.', 'xabia-intelligence')); ?>', 'success');
                        return;
                    }
                    if (d.tokens_depleted) {
                        xabiaApplyPipelinePayload(d);
                        xabiaSetSyncFeedback('<?php echo esc_js(__('Saldo de tokens agotado.', 'xabia-intelligence')); ?>', 'error');
                        return;
                    }
                    var batchTok = d.batch_estimated_tokens || 0;
                    var rem = d.tokens_remaining;
                    if (rem != null && batchTok > rem) {
                        xabiaSetSyncFeedback('<?php echo esc_js(__('Saldo insuficiente para un lote (~', 'xabia-intelligence')); ?>' + batchTok + ' <?php echo esc_js(__('tokens). Recarga en Cartera / Wallet.', 'xabia-intelligence')); ?>', 'error');
                        return;
                    }
                    var msg = '<?php echo esc_js(__('Pendientes:', 'xabia-intelligence')); ?> ' + d.pending
                        + '\n<?php echo esc_js(__('Este lote (máx.', 'xabia-intelligence')); ?> ' + (d.batch_size || 20) + '): ~' + batchTok + ' <?php echo esc_js(__('tokens', 'xabia-intelligence')); ?>';
                    if (rem != null) {
                        msg += '\n<?php echo esc_js(__('Saldo actual:', 'xabia-intelligence')); ?> ' + rem;
                    }
                    if (d.hub_rag_hint) {
                        msg += '\n\n<?php echo esc_js(__('Nota: si el chat usa Hub RAG, puede que no necesites terminar el entrenamiento local.', 'xabia-intelligence')); ?>';
                    }
                    msg += '\n\n<?php echo esc_js(__('¿Entrenar solo este lote?', 'xabia-intelligence')); ?>';
                    if (!window.confirm(msg)) {
                        return;
                    }
                    xabiaSetSyncFeedback('', 'pending');
                    xabiaStartWorkProgress({
                        title: '<?php echo esc_js(__('Entrenando IA (1 lote)', 'xabia-intelligence')); ?>',
                        steps: [
                            '<?php echo esc_js(__('Preparando el lote de registros…', 'xabia-intelligence')); ?>',
                            '<?php echo esc_js(__('Generando embeddings con el modelo de IA…', 'xabia-intelligence')); ?>',
                            '<?php echo esc_js(__('Guardando vectores en la memoria…', 'xabia-intelligence')); ?>'
                        ]
                    });
                    xabiaAdminPost({ action: 'xabia_train_ai', project_id: '<?php echo esc_js((string) $edit_id); ?>' }, function(r) {
                        xabiaStopWorkProgress();
                        xabiaApplyPipelinePayload(r.data);
                        if (!r.success) {
                            var errMsg = (r.data && r.data.message) ? r.data.message : 'Error';
                            if (r.data && r.data.digixop_insufficient) {
                                if (r.data.pipeline_alert) {
                                    xabiaSetSyncFeedback(r.data.pipeline_alert, 'error', true);
                                } else {
                                    xabiaSetSyncFeedback('⚠️ ' + errMsg, 'error');
                                }
                                return;
                            }
                            xabiaSetSyncFeedback(errMsg, 'error');
                            return;
                        }
                        var feedback = r.data.message || 'OK';
                        if (r.data.pending > 0) {
                            feedback += ' — <?php echo esc_js(__('Pulsa de nuevo para el siguiente lote.', 'xabia-intelligence')); ?>';
                            xabiaSetSyncFeedback(feedback, 'pending');
                        } else {
                            xabiaSetSyncFeedback(feedback, 'success');
                            window.alert('✅ <?php echo esc_js(__('Cerebro al 100%', 'xabia-intelligence')); ?>');
                        }
                    });
                });
            });

            $('#btn-sync-brain-cloud').click(function(){
                xabiaSetSyncFeedback('', 'pending');
                xabiaStartWorkProgress({
                    title: '<?php echo esc_js(__('Subiendo cerebro al Hub', 'xabia-intelligence')); ?>',
                    steps: [
                        '<?php echo esc_js(__('Empaquetando la memoria del agente…', 'xabia-intelligence')); ?>',
                        '<?php echo esc_js(__('Enviando datos al Hub central…', 'xabia-intelligence')); ?>',
                        '<?php echo esc_js(__('Confirmando recepción en el servidor…', 'xabia-intelligence')); ?>'
                    ]
                });
                xabiaAdminPost({ action: 'xabia_sync_brain_cloud', project_id: '<?php echo esc_js((string) $edit_id); ?>' }, function(r) {
                    xabiaStopWorkProgress();
                    if (!r.success) {
                        xabiaSetSyncFeedback((r.data && r.data.message) ? r.data.message : 'Error', 'error');
                        return;
                    }
                    xabiaSetSyncFeedback(r.data.message || 'OK', 'success');
                });
            });

            $('#btn-knowledge-preview').click(function(){
                var $box = $('#xabia-knowledge-preview');
                $box.removeClass('xabia-knowledge-preview--table').html('<?php echo esc_js(__('Cargando…', 'xabia-intelligence')); ?>').show();
                xabiaAdminPost({ action: 'xabia_knowledge_preview', project_id: '<?php echo esc_js((string) $edit_id); ?>' }, function(r) {
                    if (!r.success || !r.data) {
                        $box.text((r.data && r.data.message) ? r.data.message : 'Error');
                        return;
                    }
                    var d = r.data;
                    var lines = [];
                    lines.push('<?php echo esc_js(__('Tabla', 'xabia-intelligence')); ?>: ' + (d.table || ''));
                    lines.push('<?php echo esc_js(__('Filas totales (este agente)', 'xabia-intelligence')); ?>: ' + (d.total != null ? d.total : '0'));
                    lines.push('<?php echo esc_js(__('Con embedding (RAG semántico)', 'xabia-intelligence')); ?>: ' + (d.ready != null ? d.ready : '0'));
                    lines.push('<?php echo esc_js(__('Solo texto / pendiente de entrenar', 'xabia-intelligence')); ?>: ' + (d.pending_embedding != null ? d.pending_embedding : '0'));
                    if (d.hint) {
                        lines.push('');
                        lines.push(d.hint);
                    }
                    var summaryHtml = '<div class="xabia-kp-summary" style="margin-bottom:10px;white-space:pre-wrap;word-break:break-word;">' + $('<div>').text(lines.join('\n')).html() + '</div>';
                    var showQr = window.xabiaSmartQr && window.xabiaSmartQr.showKnowledgeColumn !== false;
                    var tableHtml = '<table class="widefat striped" style="margin-top:4px;"><thead><tr><th><?php echo esc_js(__('ID', 'xabia-intelligence')); ?></th><th><?php echo esc_js(__('Ente', 'xabia-intelligence')); ?></th><th><?php echo esc_js(__('Extracto', 'xabia-intelligence')); ?></th>';
                    if (showQr) {
                        tableHtml += '<th><?php echo esc_js(__('Smart QR', 'xabia-intelligence')); ?></th>';
                    }
                    tableHtml += '</tr></thead><tbody>';
                    (d.samples || []).forEach(function(s) {
                        var id = s.id != null ? String(s.id) : '';
                        var ente = s.ente_id != null ? String(s.ente_id) : '';
                        var enteLabel = s.ente_display != null && String(s.ente_display) !== '' ? String(s.ente_display) : ente;
                        var ex = String(s.excerpt || '').replace(/\s+/g, ' ');
                        tableHtml += '<tr><td>' + $('<div>').text(id).html() + '</td><td>' + $('<div>').text(enteLabel).html() + '</td><td style="max-width:360px;">' + $('<div>').text(ex).html() + '</td>';
                        if (showQr) {
                            if (ente === '' || ente === 'global') {
                                tableHtml += '<td>—</td>';
                            } else {
                                tableHtml += '<td><button type="button" class="button xabia-smart-qr-open" data-ente-id="' + $('<div>').text(ente).html() + '" data-ente-name="' + $('<div>').text(enteLabel).html() + '"><span class="dashicons dashicons-grid-view" style="vertical-align:text-bottom;line-height:1.2;" aria-hidden="true"></span> QR</button></td>';
                            }
                        }
                        tableHtml += '</tr>';
                    });
                    if (!(d.samples || []).length && d.total > 0) {
                        tableHtml += '<tr><td colspan="' + (showQr ? '4' : '3') + '"><em><?php echo esc_js(__('sin muestra', 'xabia-intelligence')); ?></em></td></tr>';
                    }
                    tableHtml += '</tbody></table>';
                    $box.addClass('xabia-knowledge-preview--table').html(summaryHtml + tableHtml);
                });
            });

            $('#btn-clear-ajax').click(function(){
                if(!confirm('¿Estás seguro de que quieres borrar toda la memoria del agente? Esta acción no se puede deshacer.')) return;
                xabiaSetSyncFeedback('', 'pending');
                xabiaStartWorkProgress({
                    title: '<?php echo esc_js(__('Borrando memoria vectorial', 'xabia-intelligence')); ?>',
                    steps: [
                        '<?php echo esc_js(__('Eliminando registros locales…', 'xabia-intelligence')); ?>',
                        '<?php echo esc_js(__('Limpiando datos en el Hub si aplica…', 'xabia-intelligence')); ?>'
                    ]
                });
                xabiaAdminPost({ action: 'xabia_clear_memory', project_id: '<?php echo $edit_id; ?>' }, function(r) {
                    xabiaStopWorkProgress();
                    if (r.success) {
                        xabiaSetSyncFeedback('✅ ' + r.data.message + ' (el mapeo no se modifica)', 'success');
                    } else {
                        xabiaSetSyncFeedback('❌ Error: ' + (r.data && r.data.message ? r.data.message : 'Error desconocido'), 'error');
                    }
                });
            });

            function xabiaPlaygroundHistory() {
                var hist = [];
                $('#p-chat-canvas .xabia-chat-msg').each(function(){
                    var $row = $(this);
                    var role = $row.hasClass('user') ? 'user' : 'assistant';
                    var content = $row.attr('data-raw') || $row.text();
                    content = String(content || '').replace(/^(Tú|Xabia|Nora|Laura|[^:]{1,80}):\s*/i, '').trim();
                    if (content) hist.push({ role: role, content: content });
                });
                return hist.slice(-6);
            }
            function xabiaPlaygroundSend(q) {
                q = $.trim(String(q || ''));
                if (!q) return;
                var historyPayload = xabiaPlaygroundHistory();
                var isContinue = q === 'Continúa exactamente desde donde lo dejaste, sin repetir lo anterior.';
                if (!isContinue) {
                    $('#p-chat-canvas').append($('<div class="xabia-chat-msg user"></div>').append($('<b>').text('Tú: ')).append(document.createTextNode(q)));
                }
                xabiaAdminPost({
                    action: 'xabia_ask_ai',
                    project_id: '<?php echo esc_js($edit_id); ?>',
                    message: q,
                    history: JSON.stringify(historyPayload),
                    x_continue: isContinue ? '1' : '',
                    nonce: xabiaCurrentNonce
                }, function(r) {
                    xabiaSyncNonce(r);
                    if (r.success && r.data && r.data.response) {
                        var raw = String(r.data.response || '');
                        var $row = $('<div class="xabia-chat-msg xabia-from-bot"></div>').attr('data-raw', raw);
                        $row.append($('<b>').text(xabiaPlaygroundBotName + ': ')).append(parseChatVisualTags(raw));
                        if (r.data.truncated) {
                            $row.append(' ').append($('<button type="button" class="button button-small xabia-playground-continue">Continuar</button>'));
                        }
                        $('#p-chat-canvas').append($row);
                    } else {
                        var em = (r.data && r.data.message) ? r.data.message : 'Error';
                        var $err = $('<div class="xabia-chat-msg xabia-from-bot"></div>');
                        $err.append($('<b>').text(xabiaPlaygroundBotName + ': ')).append(document.createTextNode(em));
                        $('#p-chat-canvas').append($err);
                    }
                    let d = $('#p-chat-canvas'); d.scrollTop(d[0].scrollHeight);
                });
                $('#p-input').val('');
            }
            $('#p-send').click(function(){
                xabiaPlaygroundSend($('#p-input').val());
            });
            $(document).on('click', '.xabia-playground-continue', function(e){
                e.preventDefault();
                $(this).prop('disabled', true);
                xabiaPlaygroundSend('Continúa exactamente desde donde lo dejaste, sin repetir lo anterior.');
            });
            $('#p-input').on('keydown', function(e){
                var isEnter = e.key === 'Enter' || e.which === 13 || e.keyCode === 13;
                if (isEnter && !e.shiftKey && !e.isComposing) { e.preventDefault(); $('#p-send').click(); }
            });
        });
        </script>
        <?php
    }
    
    

    /**
     * @param array<string, mixed> $config
     * @return array{train_pending:int,tokens_remaining:?int,tokens_depleted:bool,pipeline_error:string,table:string}
     */
    private static function get_agent_pipeline_status(string $project_id, array $config = []): array {
        $project_id = sanitize_key($project_id);
        $train_pending = 0;
        if ($project_id !== '' && class_exists('Xabia_Knowledge_Train', false)) {
            $train_pending = Xabia_Knowledge_Train::count_pending($project_id);
        }

        $tokens_remaining = null;
        $tokens_depleted = false;
        if (class_exists('Xabia_Digixop_Client', false)) {
            Xabia_Digixop_Client::refresh_license_meta_from_hub_if_stale();
            $tokens_remaining = Xabia_Digixop_Client::license_tokens_remaining();
            $uses_proxy = $config !== []
                ? Xabia_Digixop_Client::should_use_openai_proxy($project_id, $config)
                : (Xabia_Digixop_Client::is_xabia_cloud_mode() && Xabia_Digixop_Client::is_license_configured());
            $tokens_depleted = $uses_proxy && Xabia_Digixop_Client::proxy_tokens_depleted();
        }

        $pipeline_error = '';
        if ($project_id !== '' && class_exists('Xabia_Auto_Sync', false)) {
            $state = Xabia_Auto_Sync::get_state();
            if (isset($state[$project_id]['pipeline_error']) && is_string($state[$project_id]['pipeline_error'])) {
                $pipeline_error = trim($state[$project_id]['pipeline_error']);
            }
        }

        return [
            'train_pending'      => $train_pending,
            'tokens_remaining'   => $tokens_remaining,
            'tokens_depleted'    => $tokens_depleted,
            'pipeline_error'     => $pipeline_error,
            'table'              => class_exists('Xabia_DB', false) ? Xabia_DB::table('knowledge_vectors') : 'xabia_knowledge_vectors',
        ];
    }

    /**
     * @param array{train_pending?:int,tokens_remaining?:?int,tokens_depleted?:bool,pipeline_error?:string} $status
     */
    private static function build_pipeline_alert_html(array $status): string {
        $wallet_url = admin_url('admin.php?page=xabia-wallet');
        $wallet_link = '<a href="' . esc_url($wallet_url) . '">' . esc_html__('Recargar en Cartera / Wallet →', 'xabia-intelligence') . '</a>';

        if (!empty($status['tokens_depleted'])) {
            $pending = (int) ($status['train_pending'] ?? 0);
            $body = esc_html__('El saldo de tokens de tu licencia Xabia está agotado.', 'xabia-intelligence');
            if ($pending > 0) {
                $body .= ' ' . sprintf(
                    /* translators: %s: número de registros sin embedding */
                    esc_html__('Hay %s registros ya sincronizados pero sin embedir: el paso «Entrenar IA» y el pipeline automático están pausados hasta recargar.', 'xabia-intelligence'),
                    number_format_i18n($pending)
                );
            } else {
                $body .= ' ' . esc_html__('El chat y el entrenamiento vía Xabia Cloud no funcionarán hasta recargar.', 'xabia-intelligence');
            }

            return '<strong>' . esc_html__('Tokens agotados', 'xabia-intelligence') . '</strong>'
                . '<p>' . $body . '</p>'
                . '<p>' . $wallet_link . '</p>';
        }

        $pipeline_error = trim((string) ($status['pipeline_error'] ?? ''));
        if ($pipeline_error !== '' && self::pipeline_error_looks_like_tokens($pipeline_error)) {
            return '<strong>' . esc_html__('Entrenamiento detenido', 'xabia-intelligence') . '</strong>'
                . '<p>' . esc_html($pipeline_error) . '</p>'
                . '<p>' . $wallet_link . '</p>';
        }

        $pending = (int) ($status['train_pending'] ?? 0);
        $tokens = $status['tokens_remaining'] ?? null;
        if ($pending > 0 && $tokens !== null && $tokens > 0 && $tokens < 50000) {
            return '<strong>' . esc_html__('Saldo bajo', 'xabia-intelligence') . '</strong>'
                . '<p>' . sprintf(
                    /* translators: 1: pending rows, 2: token balance */
                    esc_html__('Quedan %1$s registros por entrenar y solo %2$s tokens en licencia. El entrenamiento puede pararse antes de terminar.', 'xabia-intelligence'),
                    number_format_i18n($pending),
                    number_format_i18n((int) $tokens)
                ) . '</p>'
                . '<p>' . $wallet_link . '</p>';
        }

        return '';
    }

    private static function pipeline_error_looks_like_tokens(string $message): bool {
        $m = function_exists('mb_strtolower') ? mb_strtolower($message) : strtolower($message);

        return str_contains($m, 'token')
            || str_contains($m, 'saldo')
            || str_contains($m, 'agotad')
            || str_contains($m, 'insufficient')
            || str_contains($m, 'recarg');
    }

    /**
     * @return array<string, mixed>
     */
    private static function agent_pipeline_ajax_payload(string $project_id): array {
        $projects = get_option('xabia_projects_config', []);
        $config = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];
        $status = self::get_agent_pipeline_status($project_id, $config);
        $alert_html = self::build_pipeline_alert_html($status);
        $vector_stats = self::get_vector_counts($project_id, $config);
        $vector_search_enabled = !empty($config['rules']['use_vector_search']);

        return [
            'train_pending'          => (int) ($status['train_pending'] ?? 0),
            'tokens_remaining'       => $status['tokens_remaining'],
            'tokens_depleted'        => !empty($status['tokens_depleted']),
            'pipeline_alert'         => $alert_html,
            'knowledge_table'        => (string) ($status['table'] ?? 'xabia_knowledge_vectors'),
            'vector_total'           => (int) ($vector_stats['total'] ?? 0),
            'vector_ready'           => (int) ($vector_stats['ready'] ?? 0),
            'vector_search_enabled'  => $vector_search_enabled,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{total:int,ready:int}
     */
    private static function get_vector_counts($pid, array $config = []) {
        global $wpdb;
        $t = Xabia_DB::table('knowledge_vectors');
        if ($config === []) {
            $projects = get_option('xabia_projects_config', []);
            $config = isset($projects[$pid]) && is_array($projects[$pid]) ? $projects[$pid] : [];
        }
        $use_vector = !empty($config['rules']['use_vector_search']);
        $ready_sql = $use_vector && class_exists('Xabia_DB', false)
            ? Xabia_DB::knowledge_vectors_sql_has_embedding()
            : (class_exists('Xabia_DB', false)
                ? Xabia_DB::knowledge_vectors_sql_has_usable_content()
                : 'content_chunk IS NOT NULL AND TRIM(content_chunk) <> \'\'');
        $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE project_id=%s", $pid));
        $ready = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE project_id=%s AND ({$ready_sql})", $pid));

        return ['total' => (int) $total, 'ready' => (int) $ready];
    }

    private static function get_today_token_usage($pid) {
        global $wpdb;
        $t = Xabia_DB::table('usage_logs');
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) {
            return 0;
        }
        $start = gmdate('Y-m-d 00:00:00');
        $end = gmdate('Y-m-d 23:59:59');
        $sum = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(tokens_input + tokens_output),0) FROM $t WHERE project_id=%s AND created_at BETWEEN %s AND %s",
            $pid,
            $start,
            $end
        ));
        return (int) $sum;
    }

    private static function get_wallet_summary(bool $ensure = true): ?array {
        global $wpdb;
        if (!class_exists('Xabia_DB', false)) {
            return null;
        }
        $table = Xabia_DB::table('wallets');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            Xabia_DB::install_tables();
        }
        if ($ensure) {
            Xabia_DB::sync_wallet_balance(null);
        }
        $licenseId = Xabia_DB::wallet_license_id();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE license_id = %s", $licenseId), ARRAY_A);
        if (!is_array($row)) {
            if (!$ensure) {
                return null;
            }
            $wpdb->insert($table, [
                'license_id' => $licenseId,
                'license_key_hash' => '',
                'tokens_remaining' => 0,
                'tokens_used_total' => 0,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE license_id = %s", $licenseId), ARRAY_A);
        }

        return is_array($row) ? $row : null;
    }

    private static function get_wallet_usage_30_days(): array {
        global $wpdb;
        $table = Xabia_DB::table('usage_logs');
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = gmdate('Y-m-d', strtotime('-' . $i . ' days'));
            $days[$date] = ['date' => $date, 'tokens' => 0];
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return ['days' => array_values($days), 'total' => 0, 'max' => 0];
        }
        $columns = $wpdb->get_col("SHOW COLUMNS FROM $table", 0);
        $columns = is_array($columns) ? array_map('strval', $columns) : [];
        $dateCol = in_array('created_at', $columns, true) ? 'created_at' : (in_array('timestamp', $columns, true) ? 'timestamp' : '');
        if ($dateCol === '') {
            return ['days' => array_values($days), 'total' => 0, 'max' => 0];
        }
        $tokenExpr = in_array('tokens_count', $columns, true)
            ? 'tokens_count'
            : '(tokens_input + tokens_output)';
        $since = gmdate('Y-m-d 00:00:00', strtotime('-29 days'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE($dateCol) AS day_key, COALESCE(SUM($tokenExpr),0) AS tokens
             FROM $table
             WHERE $dateCol >= %s
             GROUP BY DATE($dateCol)",
            $since
        ), ARRAY_A);
        $total = 0;
        $max = 0;
        foreach ((array) $rows as $row) {
            $key = (string) ($row['day_key'] ?? '');
            if (!isset($days[$key])) {
                continue;
            }
            $tokens = (int) ($row['tokens'] ?? 0);
            $days[$key]['tokens'] = $tokens;
            $total += $tokens;
            $max = max($max, $tokens);
        }

        return ['days' => array_values($days), 'total' => $total, 'max' => $max];
    }

    private static function get_project_logs($pid) { 
        global $wpdb; 
        $t = Xabia_DB::table('logs'); 
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE project_id=%s ORDER BY id DESC LIMIT 20",$pid)); 
    }
}
