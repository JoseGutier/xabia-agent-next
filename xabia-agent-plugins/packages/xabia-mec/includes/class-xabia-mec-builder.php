<?php
/**
 * Builder agnóstico del payload RAG / cabecera semántica de eventos MEC.
 *
 * @package Xabia_MEC
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_MEC_Builder {

    /**
     * @return list<string>
     */
    public static function extract_source_values(int $post_id, string $source_key): array {
        if ($post_id < 1) {
            return [];
        }

        $source_key = Xabia_MEC_Admin::normalize_source_key($source_key);
        if ($source_key === 'auto') {
            return [];
        }

        if (strpos($source_key, 'taxonomy:') === 0) {
            return self::extract_taxonomy_values($post_id, substr($source_key, 9));
        }

        if (strpos($source_key, 'acf:') === 0) {
            return self::extract_acf_values($post_id, substr($source_key, 4));
        }

        if (strpos($source_key, 'meta:') === 0) {
            return self::extract_meta_values($post_id, substr($source_key, 5));
        }

        if (strpos($source_key, 'mec_field:') === 0) {
            return self::extract_mec_field_values($post_id, substr($source_key, 10));
        }

        return apply_filters('xabia_mec_extract_source_values', [], $post_id, $source_key);
    }

    /**
     * @return array{value:string,values:list<string>,source:string}
     */
    public static function extract_slot(int $post_id, string $slot): array {
        $source = Xabia_MEC_Admin::resolve_source($slot);
        $values = self::extract_source_values($post_id, $source);
        $value = $values !== [] ? $values[0] : '';

        return [
            'value'  => $value,
            'values' => $values,
            'source' => $source,
        ];
    }

    /**
     * Variantes de ubicación vía filtro (sin lógica locativa en el núcleo).
     *
     * @param list<string> $raw_values
     * @return list<string>
     */
    public static function apply_location_variants(array $raw_values, int $post_id, string $slot): array {
        $seed = [];
        foreach ($raw_values as $raw) {
            $raw = trim((string) $raw);
            if ($raw !== '') {
                $seed[] = $raw;
            }
        }
        if ($seed === []) {
            return [];
        }

        $variants = apply_filters('xabia_mec_location_variants', $seed, $post_id, $slot);
        if (!is_array($variants)) {
            $variants = $seed;
        }

        $out = [];
        $seen = [];
        foreach ($variants as $variant) {
            $variant = trim((string) $variant);
            if ($variant === '' || isset($seen[$variant])) {
                continue;
            }
            $seen[$variant] = true;
            $out[] = $variant;
        }

        return apply_filters('xabia_mec_locative_variants', $out, $raw_values, $post_id, $slot);
    }

    /**
     * Cabecera semántica a partir de slots configurados (sin etiquetas fijas).
     *
     * @param array<string, mixed> $payload
     */
    public static function build_semantic_header(array $payload): string {
        $parts = [];
        $definitions = Xabia_MEC_Admin::slot_definitions();

        foreach ($definitions as $slot_id => $definition) {
            $value = trim((string) ($payload[$slot_id] ?? ''));
            if ($value === '') {
                continue;
            }
            $source_key = (string) ($payload[$slot_id . '_source'] ?? 'auto');
            $header_key = Xabia_MEC_Admin::header_key_for_source($source_key, $slot_id, $definition);
            $variant_key = $slot_id . '_variants';
            $variants = isset($payload[$variant_key]) && is_array($payload[$variant_key])
                ? $payload[$variant_key]
                : [];
            $blob = array_values(array_unique(array_merge([$value], $variants)));
            $parts[] = '[' . $header_key . ': ' . implode(', ', array_slice($blob, 0, 12)) . ']';
        }

        $post_id = isset($payload['ID']) ? absint($payload['ID']) : 0;
        if ($post_id > 0) {
            $slot_field_ids = self::collect_mec_field_ids_from_payload($payload);
            foreach (self::build_mec_field_header_parts($post_id, $slot_field_ids) as $part) {
                $parts[] = $part;
            }
        }

        $extra = apply_filters('xabia_mec_semantic_header_parts', [], $payload);
        if (is_array($extra)) {
            foreach ($extra as $part) {
                $part = trim((string) $part);
                if ($part !== '') {
                    $parts[] = $part;
                }
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public static function build_event_payload(int $post_id): array {
        if ($post_id < 1 || get_post_type($post_id) !== Xabia_MEC_Admin::event_post_type()) {
            return [];
        }

        $slots = [];
        foreach (array_keys(Xabia_MEC_Admin::slot_definitions()) as $slot_id) {
            $slots[$slot_id] = self::extract_slot($post_id, $slot_id);
        }

        $municipality_slot = $slots[XABIA_MEC_SLOT_MUNICIPALITY] ?? ['value' => '', 'values' => [], 'source' => 'auto'];
        $venue_slot = $slots[XABIA_MEC_SLOT_VENUE] ?? ['value' => '', 'values' => [], 'source' => 'auto'];

        $location_variants = self::apply_location_variants(
            $municipality_slot['values'],
            $post_id,
            XABIA_MEC_SLOT_MUNICIPALITY
        );

        $payload = [
            'ID'                  => $post_id,
            'source_id'           => 'mec-event:' . $post_id,
            'event_title'         => get_the_title($post_id),
            'event_content'       => function_exists('xabia_federation_mec_plain_content')
                ? xabia_federation_mec_plain_content($post_id)
                : wp_strip_all_tags((string) get_post_field('post_content', $post_id)),
            'mec_start_date'      => (string) get_post_meta($post_id, 'mec_start_date', true),
            'mec_end_date'        => (string) get_post_meta($post_id, 'mec_end_date', true),
            'mec_cost'            => (string) get_post_meta($post_id, 'mec_cost', true),
            'mec_available_slots' => function_exists('xabia_mec_compute_available_slots')
                ? xabia_mec_compute_available_slots($post_id)
                : '',
            'permalink'           => function_exists('xabia_federation_mec_reservation_url')
                ? xabia_federation_mec_reservation_url($post_id)
                : (string) get_permalink($post_id),
            'semantic_header'     => '',
            'semantic_body'       => '',
            'rag_chunk'           => '',
        ];

        foreach ($slots as $slot_id => $slot_data) {
            $payload[$slot_id] = (string) ($slot_data['value'] ?? '');
            $payload[$slot_id . '_all'] = implode(', ', (array) ($slot_data['values'] ?? []));
            $payload[$slot_id . '_source'] = (string) ($slot_data['source'] ?? 'auto');
        }

        $payload['municipality_variants'] = $location_variants;
        $payload['mec_location'] = $venue_slot['value'] !== ''
            ? $venue_slot['value']
            : (string) get_post_meta($post_id, 'mec_location', true);

        $payload['semantic_header'] = self::build_semantic_header($payload);

        $body_lines = array_filter([
            $payload['event_title'] !== '' ? ('Evento: ' . $payload['event_title']) : '',
            $payload['mec_start_date'] !== '' ? ('Fecha: ' . $payload['mec_start_date']) : '',
            $payload[XABIA_MEC_SLOT_VENUE] !== '' ? ('Recinto: ' . $payload[XABIA_MEC_SLOT_VENUE]) : '',
            $payload[XABIA_MEC_SLOT_MUNICIPALITY] !== '' ? ('Ubicación: ' . $payload[XABIA_MEC_SLOT_MUNICIPALITY]) : '',
            $payload['event_content'] !== '' ? $payload['event_content'] : '',
        ]);
        $payload['semantic_body'] = implode("\n", $body_lines);
        $payload['rag_chunk'] = trim($payload['semantic_header'] . "\n" . $payload['semantic_body']);

        return apply_filters('xabia_mec_event_chunk_payload', $payload, $post_id);
    }

    /**
     * @return list<string>
     */
    private static function extract_taxonomy_values(int $post_id, string $taxonomy): array {
        $taxonomy = sanitize_key($taxonomy);
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return [];
        }

        $terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'names']);
        if (is_wp_error($terms) || !is_array($terms)) {
            return [];
        }

        $names = [];
        foreach ($terms as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private static function extract_acf_values(int $post_id, string $field_name): array {
        $field_name = sanitize_key($field_name);
        if ($field_name === '' || !function_exists('get_field')) {
            return [];
        }

        return self::flatten_scalar_values(get_field($field_name, $post_id));
    }

    /**
     * @return list<string>
     */
    private static function extract_meta_values(int $post_id, string $meta_key): array {
        $meta_key = sanitize_key($meta_key);
        if ($meta_key === '') {
            return [];
        }

        $value = get_post_meta($post_id, $meta_key, true);
        $values = self::flatten_scalar_values($value);

        $term_id = absint($value);
        if ($values === [] && $term_id > 0 && taxonomy_exists($meta_key)) {
            $term = get_term($term_id, $meta_key);
            if ($term && !is_wp_error($term) && !empty($term->name)) {
                $values[] = trim((string) $term->name);
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function flatten_scalar_values($value): array {
        if (is_string($value) && trim($value) !== '') {
            return [trim($value)];
        }
        if (is_numeric($value)) {
            return [(string) $value];
        }
        if (!is_array($value)) {
            return [];
        }

        $flat = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $flat[] = trim($item);
            } elseif (is_array($item)) {
                if (!empty($item['label'])) {
                    $flat[] = trim((string) $item['label']);
                } elseif (!empty($item['name'])) {
                    $flat[] = trim((string) $item['name']);
                }
            }
        }

        return array_values(array_unique(array_filter($flat)));
    }

    /**
     * IDs de campos MEC ya representados en slots del payload.
     *
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function collect_mec_field_ids_from_payload(array $payload): array {
        $ids = [];
        foreach ($payload as $key => $value) {
            if (!is_string($key) || !str_ends_with($key, '_source')) {
                continue;
            }
            $source = (string) $value;
            if (strpos($source, 'mec_field:') !== 0) {
                continue;
            }
            $field_id = substr($source, 10);
            if ($field_id !== '') {
                $ids[] = $field_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Cabeceras semánticas para campos nativos MEC con valor (no duplicados en slots).
     *
     * @param list<string> $exclude_field_ids
     * @return list<string>
     */
    public static function build_mec_field_header_parts(int $post_id, array $exclude_field_ids = []): array {
        if ($post_id < 1) {
            return [];
        }

        $exclude = [];
        foreach ($exclude_field_ids as $field_id) {
            $field_id = preg_replace('/[^0-9a-zA-Z_-]/', '', (string) $field_id);
            if ($field_id !== '') {
                $exclude[$field_id] = true;
            }
        }

        $parts = [];
        $definitions = Xabia_MEC_Admin::get_mec_custom_field_definitions();
        foreach ($definitions as $field_id => $definition) {
            if (isset($exclude[$field_id])) {
                continue;
            }

            $values = self::extract_mec_field_values($post_id, $field_id);
            if ($values === []) {
                continue;
            }

            $label = trim((string) ($definition['label'] ?? ''));
            if ($label === '') {
                $label = 'MEC #' . $field_id;
            }

            $parts[] = '[' . $label . ': ' . implode(', ', array_slice($values, 0, 12)) . ']';
        }

        return apply_filters('xabia_mec_mec_field_header_parts', $parts, $post_id, $exclude_field_ids);
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function get_mec_fields_meta(int $post_id): array {
        $raw = get_post_meta($post_id, 'mec_fields', true);
        if (is_string($raw)) {
            $raw = maybe_unserialize($raw);
        }
        if (!is_array($raw)) {
            return [];
        }

        return $raw;
    }

    /**
     * @return list<string>
     */
    private static function extract_mec_field_values(int $post_id, string $field_id): array {
        $field_id = preg_replace('/[^0-9a-zA-Z_-]/', '', $field_id);
        if ($field_id === '' || $post_id < 1) {
            return [];
        }

        $mec_fields = self::get_mec_fields_meta($post_id);
        if ($mec_fields === []) {
            return [];
        }

        $value = null;
        if (array_key_exists($field_id, $mec_fields)) {
            $value = $mec_fields[$field_id];
        } elseif (array_key_exists((int) $field_id, $mec_fields)) {
            $value = $mec_fields[(int) $field_id];
        }

        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            $flat = [];
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $text = trim((string) $item);
                    if ($text !== '') {
                        $flat[] = $text;
                    }
                } elseif (is_array($item)) {
                    foreach (self::flatten_scalar_values($item) as $nested) {
                        $flat[] = $nested;
                    }
                }
            }
            $flat = array_values(array_unique(array_filter($flat)));
            if ($flat === []) {
                return [];
            }

            return [implode(', ', $flat)];
        }

        if (is_scalar($value)) {
            $text = trim((string) $value);
            return $text !== '' ? [$text] : [];
        }

        return [];
    }
}
