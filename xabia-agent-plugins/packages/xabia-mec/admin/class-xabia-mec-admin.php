<?php
/**
 * Admin: descubrimiento dinámico de fuentes RAG para eventos MEC.
 *
 * @package Xabia_MEC
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_MEC_Admin {

    /**
     * @return string
     */
    public static function event_post_type(): string {
        return (string) apply_filters('xabia_mec_event_post_type', 'mec-events');
    }

    /**
     * Definición de slots semánticos (etiquetas de cabecera y resolución auto).
     *
     * @return array<string, array{label:string,header_key:string,auto_resolver:string}>
     */
    public static function slot_definitions(): array {
        $defaults = [
            XABIA_MEC_SLOT_MUNICIPALITY => [
                'label'          => __('Ubicación principal', 'xabia-intelligence'),
                'header_key'     => __('Ubicación', 'xabia-intelligence'),
                'auto_resolver'  => 'mec_primary_taxonomy',
            ],
            XABIA_MEC_SLOT_VENUE => [
                'label'          => __('Recinto físico', 'xabia-intelligence'),
                'header_key'     => __('Recinto', 'xabia-intelligence'),
                'auto_resolver'  => 'mec_venue',
            ],
        ];

        return apply_filters('xabia_mec_rag_slot_definitions', $defaults);
    }

    /**
     * @return array<string, array{type:string,key:string,label:string,synopsis:string}>
     */
    public static function discover_sources(): array {
        $sources = [];
        $sources = array_merge($sources, self::discover_taxonomy_sources());
        $sources = array_merge($sources, self::discover_mec_field_sources());
        $sources = array_merge($sources, self::discover_acf_sources());
        $sources = array_merge($sources, self::discover_meta_sources());

        return apply_filters('xabia_mec_rag_discovered_sources', $sources, self::event_post_type());
    }

    /**
     * Definiciones de campos personalizados nativos de MEC (mec_options.custom_fields o API MEC).
     *
     * @return array<string, array{id:string,label:string,type:string,raw:array}>
     */
    public static function get_mec_custom_field_definitions(): array {
        $custom_fields = self::get_mec_custom_fields_raw();
        if ($custom_fields === []) {
            return [];
        }

        $definitions = [];
        foreach ($custom_fields as $field_key => $field_def) {
            if (!is_array($field_def)) {
                continue;
            }

            $id = self::resolve_mec_custom_field_id($field_key, $field_def);
            if ($id === '') {
                continue;
            }

            $label = stripslashes(trim((string) ($field_def['label'] ?? '')));
            if ($label === '') {
                $label = stripslashes(trim((string) ($field_def['name'] ?? '')));
            }
            if ($label === '') {
                $label = sprintf(
                    /* translators: %s: MEC custom field id */
                    __('Campo MEC #%s', 'xabia-intelligence'),
                    $id
                );
            }

            $type = !empty($field_def['type']) ? sanitize_key((string) $field_def['type']) : 'text';
            $definitions[$id] = [
                'id'    => $id,
                'label' => $label,
                'type'  => $type,
                'raw'   => $field_def,
            ];
        }

        uasort(
            $definitions,
            static function ($a, $b) {
                return strnatcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
            }
        );

        return apply_filters('xabia_mec_custom_field_definitions', $definitions);
    }

    /**
     * Array crudo de definiciones MEC (custom_fields).
     *
     * @return array<int|string, array<string, mixed>>
     */
    public static function get_mec_custom_fields_raw(): array {
        if (class_exists('MEC', false)) {
            try {
                $main = MEC::getInstance('app.libraries.main');
                if (is_object($main) && method_exists($main, 'get_event_fields')) {
                    $fields = $main->get_event_fields();
                    if (is_array($fields) && $fields !== []) {
                        return $fields;
                    }
                }
            } catch (Throwable $e) {
                unset($e);
            }
        }

        $options = get_option('mec_options', []);
        if (!is_array($options)) {
            return [];
        }

        $custom_fields = $options['custom_fields'] ?? [];

        return is_array($custom_fields) ? $custom_fields : [];
    }

    /**
     * ID numérico de un campo MEC (MEC ignora claves no numéricas).
     *
     * @param mixed $field_key
     * @param array<string, mixed> $field_def
     */
    public static function resolve_mec_custom_field_id($field_key, array $field_def): string {
        if (is_numeric($field_key)) {
            return (string) (int) $field_key;
        }

        if (isset($field_def['id']) && is_numeric($field_def['id'])) {
            return (string) (int) $field_def['id'];
        }

        return '';
    }

    /**
     * Nombre de columna SQL/mapeo para un campo nativo MEC.
     */
    public static function mec_field_column_name(string $field_id): string {
        $field_id = preg_replace('/[^0-9]/', '', $field_id);

        return $field_id !== '' ? ('mec_field_' . $field_id) : '';
    }

    /**
     * Filas de mapeo de atributos para campos nativos MEC.
     *
     * @return list<array<string, mixed>>
     */
    public static function mapping_attribute_fields(): array {
        $rows = [];
        foreach (self::get_mec_custom_field_definitions() as $field_id => $definition) {
            $col = self::mec_field_column_name($field_id);
            if ($col === '') {
                continue;
            }

            $type = (string) ($definition['type'] ?? 'text');
            $role = 'none';
            if ($type === 'url') {
                $role = 'web';
            } elseif ($type === 'tel') {
                $role = 'tel';
            } elseif ($type === 'email') {
                $role = 'email';
            } elseif ($type === 'textarea') {
                $role = 'info';
            }

            $rows[] = [
                'csv_col'      => $col,
                'label'        => sprintf(
                    /* translators: %s: MEC field label */
                    __('MEC: %s', 'xabia-intelligence'),
                    (string) ($definition['label'] ?? $field_id)
                ),
                'visual_role'  => $role,
                'is_ente'      => 0,
                'instruction'  => sprintf(
                    /* translators: 1: field id, 2: field type */
                    __('Campo nativo MEC (ID %1$s, tipo %2$s) desde meta mec_fields.', 'xabia-intelligence'),
                    $field_id,
                    $type
                ),
                'import_rag'   => 1,
                'mec_field_id' => $field_id,
            ];
        }

        return apply_filters('xabia_mec_mapping_attribute_fields', $rows);
    }

    /**
     * Etiqueta legible de un campo nativo MEC por ID.
     */
    public static function mec_field_label(string $field_id): string {
        $field_id = preg_replace('/[^0-9a-zA-Z_-]/', '', $field_id);
        if ($field_id === '') {
            return '';
        }

        $definitions = self::get_mec_custom_field_definitions();

        return (string) ($definitions[$field_id]['label'] ?? '');
    }

    /**
     * @return array<string, array{type:string,key:string,label:string,synopsis:string}>
     */
    public static function discover_mec_field_sources(): array {
        $sources = [];

        foreach (self::get_mec_custom_field_definitions() as $field_id => $definition) {
            $label = (string) ($definition['label'] ?? $field_id);
            $type = (string) ($definition['type'] ?? 'text');
            $key = 'mec_field:' . $field_id;
            $sources[$key] = [
                'type'     => 'mec_field',
                'key'      => $field_id,
                'label'    => sprintf(
                    /* translators: %s: MEC custom field label */
                    __('MEC Field: %s', 'xabia-intelligence'),
                    $label
                ),
                'synopsis' => sprintf(
                    /* translators: 1: MEC field type, 2: field id */
                    __('Campo nativo MEC (%1$s, ID %2$s) almacenado en meta mec_fields.', 'xabia-intelligence'),
                    $type,
                    $field_id
                ),
            ];
        }

        return $sources;
    }

    /**
     * @return array<string, string> option value => label
     */
    public static function get_source_options(): array {
        $options = [
            'auto' => __('Auto (preferencias MEC / inferencia)', 'xabia-intelligence'),
        ];

        foreach (self::discover_sources() as $source_key => $meta) {
            $options[$source_key] = (string) ($meta['label'] ?? $source_key);
        }

        return apply_filters('xabia_mec_rag_source_options', $options);
    }

    /**
     * Sinopsis legible para la UI admin (agrupada por tipo).
     *
     * @return array<string, list<array{type:string,key:string,label:string,synopsis:string}>>
     */
    public static function get_source_synopsis(): array {
        $grouped = [
            'taxonomy'  => [],
            'mec_field' => [],
            'acf'       => [],
            'meta'      => [],
        ];

        foreach (self::discover_sources() as $source_key => $source) {
            $type = (string) ($source['type'] ?? 'meta');
            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            $source['source_key'] = $source_key;
            $source['mapping_column'] = self::attribute_column_for_source_key($source_key);
            $grouped[$type][] = $source;
        }

        foreach ($grouped as $type => $items) {
            usort(
                $items,
                static function ($a, $b) {
                    return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
                }
            );
            $grouped[$type] = $items;
        }

        return apply_filters('xabia_mec_rag_source_synopsis', $grouped);
    }

    /**
     * Nombre de columna sugerido en «Mapeo de atributos» para una clave de fuente RAG.
     */
    public static function attribute_column_for_source_key(string $source_key): string {
        $source_key = self::normalize_source_key($source_key);
        if ($source_key === 'auto' || $source_key === '') {
            return '';
        }

        if (strpos($source_key, 'mec_field:') === 0) {
            return self::mec_field_column_name(substr($source_key, 10));
        }

        if (strpos($source_key, 'taxonomy:') === 0) {
            $tax = sanitize_key(substr($source_key, 9));
            if ($tax === 'post_tag') {
                return 'Categorias_Tags';
            }

            return 'tax_' . $tax;
        }

        if (strpos($source_key, 'acf:') === 0) {
            return 'acf_' . sanitize_key(substr($source_key, 4));
        }

        if (strpos($source_key, 'meta:') === 0) {
            $meta = sanitize_key(substr($source_key, 5));
            if ($meta === 'mec_location') {
                return 'Lugar';
            }

            return $meta;
        }

        return '';
    }

    /**
     * Columnas csv_col ya presentes en el mapeo de atributos del agente.
     *
     * @param array<string, mixed> $agent_data
     * @return list<string>
     */
    public static function mapped_attribute_columns(array $agent_data): array {
        $attributes = $agent_data['attributes'] ?? [];
        if (!is_array($attributes)) {
            return [];
        }

        $cols = [];
        foreach ($attributes as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $col = trim((string) ($attr['csv_col'] ?? ''));
            if ($col !== '') {
                $cols[] = $col;
            }
        }

        return array_values(array_unique($cols));
    }

    /**
     * @return array<string, array{type:string,key:string,label:string,synopsis:string}>
     */
    public static function discover_taxonomy_sources(): array {
        $sources = [];
        $post_type = self::event_post_type();

        if (!function_exists('get_object_taxonomies')) {
            return $sources;
        }

        $taxonomies = get_object_taxonomies($post_type, 'objects');
        if (!is_array($taxonomies)) {
            return $sources;
        }

        foreach ($taxonomies as $tax) {
            if (!is_object($tax) || empty($tax->name)) {
                continue;
            }
            $name = sanitize_key((string) $tax->name);
            if ($name === '') {
                continue;
            }
            $label = !empty($tax->labels->singular_name)
                ? (string) $tax->labels->singular_name
                : $name;
            $synopsis = !empty($tax->description)
                ? (string) $tax->description
                : sprintf(
                    /* translators: %s: taxonomy slug */
                    __('Taxonomía «%s» registrada en el tipo de contenido del evento.', 'xabia-intelligence'),
                    $name
                );
            $key = 'taxonomy:' . $name;
            $sources[$key] = [
                'type'     => 'taxonomy',
                'key'      => $name,
                'label'    => sprintf(
                    /* translators: %s: taxonomy label */
                    __('Taxonomía: %s', 'xabia-intelligence'),
                    $label
                ),
                'synopsis' => $synopsis,
            ];
        }

        return $sources;
    }

    /**
     * @return array<string, array{type:string,key:string,label:string,synopsis:string}>
     */
    public static function discover_acf_sources(): array {
        $sources = [];
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return $sources;
        }

        $groups = acf_get_field_groups(['post_type' => self::event_post_type()]);
        if (!is_array($groups)) {
            return $sources;
        }

        $seen = [];
        foreach ($groups as $group) {
            if (!is_array($group) || empty($group['key'])) {
                continue;
            }
            $fields = acf_get_fields($group['key']);
            if (!is_array($fields)) {
                continue;
            }
            foreach ($fields as $field) {
                if (!is_array($field) || empty($field['name'])) {
                    continue;
                }
                $name = sanitize_key((string) $field['name']);
                if ($name === '' || isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                $label = !empty($field['label']) ? (string) $field['label'] : $name;
                $type = !empty($field['type']) ? (string) $field['type'] : 'field';
                $group_title = !empty($group['title']) ? (string) $group['title'] : '';
                $synopsis = $group_title !== ''
                    ? sprintf(
                        /* translators: 1: ACF field type, 2: field group title */
                        __('Campo ACF (%1$s) del grupo «%2$s».', 'xabia-intelligence'),
                        $type,
                        $group_title
                    )
                    : sprintf(
                        /* translators: %s: ACF field type */
                        __('Campo ACF (%s).', 'xabia-intelligence'),
                        $type
                    );
                $key = 'acf:' . $name;
                $sources[$key] = [
                    'type'     => 'acf',
                    'key'      => $name,
                    'label'    => sprintf(
                        /* translators: %s: ACF field label */
                        __('ACF: %s', 'xabia-intelligence'),
                        $label
                    ),
                    'synopsis' => $synopsis,
                ];
            }
        }

        return $sources;
    }

    /**
     * Meta keys expuestas vía filtro (sin listas fijas en el núcleo).
     *
     * @return array<string, array{type:string,key:string,label:string,synopsis:string}>
     */
    public static function discover_meta_sources(): array {
        $sources = [];
        $candidates = apply_filters('xabia_mec_rag_meta_source_candidates', [], self::event_post_type());
        if (!is_array($candidates)) {
            return $sources;
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate)) {
                $candidate = ['key' => $candidate];
            }
            if (!is_array($candidate) || empty($candidate['key'])) {
                continue;
            }
            $name = sanitize_key((string) $candidate['key']);
            if ($name === '') {
                continue;
            }
            $label = !empty($candidate['label'])
                ? (string) $candidate['label']
                : $name;
            $synopsis = !empty($candidate['synopsis'])
                ? (string) $candidate['synopsis']
                : sprintf(
                    /* translators: %s: meta key */
                    __('Meta del evento: %s', 'xabia-intelligence'),
                    $name
                );
            $key = 'meta:' . $name;
            $sources[$key] = [
                'type'     => 'meta',
                'key'      => $name,
                'label'    => sprintf(
                    /* translators: %s: meta label */
                    __('Meta: %s', 'xabia-intelligence'),
                    $label
                ),
                'synopsis' => $synopsis,
            ];
        }

        return $sources;
    }

    /**
     * Normaliza claves legacy (post_tag, mec-tag, …) al formato taxonomy:/acf:/meta:.
     */
    public static function normalize_source_key(string $key): string {
        $key = sanitize_text_field(trim($key));
        if ($key === '' || $key === 'auto') {
            return 'auto';
        }

        $legacy_map = apply_filters(
            'xabia_mec_rag_legacy_source_map',
            [
                'post_tag'          => 'taxonomy:post_tag',
                'mec-tag'           => 'taxonomy:mec_tag',
                'mec_location'      => taxonomy_exists('mec_location') ? 'taxonomy:mec_location' : 'meta:mec_location',
                'mec_location_meta' => 'meta:mec_location',
                'mec_category'      => 'taxonomy:mec_category',
            ]
        );

        if (isset($legacy_map[$key])) {
            return (string) $legacy_map[$key];
        }

        if (preg_match('/^(taxonomy|acf|meta):[a-zA-Z0-9_-]{1,64}$/', $key)) {
            return $key;
        }

        if (preg_match('/^mec_field:[0-9a-zA-Z_-]{1,32}$/', $key)) {
            return $key;
        }

        return 'auto';
    }

    /**
     * Etiqueta de cabecera semántica según fuente (p. ej. label nativo MEC).
     */
    public static function header_key_for_source(string $source_key, string $slot_id, array $slot_definition = []): string {
        $source_key = self::normalize_source_key($source_key);
        if (strpos($source_key, 'mec_field:') === 0) {
            $field_id = substr($source_key, 10);
            $mec_label = self::mec_field_label($field_id);
            if ($mec_label !== '') {
                return $mec_label;
            }
        }

        $header_key = trim((string) ($slot_definition['header_key'] ?? ''));
        if ($header_key !== '') {
            return $header_key;
        }

        return sanitize_key($slot_id);
    }

    /**
     * Valida contra fuentes descubiertas dinámicamente.
     */
    public static function sanitize_source_key(string $key): string {
        $normalized = self::normalize_source_key($key);
        if ($normalized === 'auto') {
            return 'auto';
        }

        $allowed = self::discover_sources();
        if (isset($allowed[$normalized])) {
            return $normalized;
        }

        return 'auto';
    }

    /**
     * Resuelve fuente efectiva para un slot.
     */
    public static function resolve_source(string $slot): string {
        $settings = xabia_mec_rag_get_settings();
        $configured = isset($settings[$slot]) ? self::normalize_source_key((string) $settings[$slot]) : 'auto';

        if ($configured !== 'auto') {
            return $configured;
        }

        $definitions = self::slot_definitions();
        $resolver = isset($definitions[$slot]['auto_resolver'])
            ? (string) $definitions[$slot]['auto_resolver']
            : '';

        $resolved = apply_filters('xabia_mec_rag_resolve_auto_source', '', $slot, $resolver);
        if (is_string($resolved) && $resolved !== '' && $resolved !== 'auto') {
            return self::normalize_source_key($resolved);
        }

        if ($resolver === 'mec_primary_taxonomy') {
            return self::resolve_mec_primary_taxonomy_source();
        }

        if ($resolver === 'mec_venue') {
            return self::resolve_mec_venue_source();
        }

        return 'auto';
    }

    /**
     * Taxonomía principal según tag_method de MEC (sin asumir nombres fijos en la UI).
     */
    public static function resolve_mec_primary_taxonomy_source(): string {
        $method = xabia_mec_rag_get_mec_tag_method();
        $taxonomy = $method === 'post_tag' ? 'post_tag' : 'mec_tag';
        if (!taxonomy_exists($taxonomy)) {
            $taxonomy = 'post_tag';
        }

        return 'taxonomy:' . $taxonomy;
    }

    /**
     * Inferencia de recinto: filtro → heurística por slug → fallback filtrable.
     */
    public static function resolve_mec_venue_source(): string {
        $filtered = apply_filters('xabia_mec_rag_auto_venue_source', null, self::discover_taxonomy_sources());
        if (is_string($filtered) && $filtered !== '') {
            return self::normalize_source_key($filtered);
        }

        foreach (self::discover_taxonomy_sources() as $source_key => $meta) {
            $tax = (string) ($meta['key'] ?? '');
            if ($tax === '') {
                continue;
            }
            if (preg_match('/(location|venue|lugar|recinto)/i', $tax)) {
                return $source_key;
            }
        }

        $fallback = apply_filters('xabia_mec_rag_fallback_venue_source', 'meta:mec_location');
        return self::normalize_source_key((string) $fallback);
    }

    /**
     * Etiqueta legible de una clave de fuente.
     */
    public static function source_label(string $source_key): string {
        $normalized = self::normalize_source_key($source_key);
        if ($normalized === 'auto') {
            return __('Auto', 'xabia-intelligence');
        }
        $sources = self::discover_sources();

        return (string) ($sources[$normalized]['label'] ?? $normalized);
    }
}
