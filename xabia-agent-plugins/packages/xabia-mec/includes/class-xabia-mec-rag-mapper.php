<?php
/**
 * Persistencia de ajustes RAG y funciones públicas de compatibilidad.
 *
 * @package Xabia_MEC
 */

if (!defined('ABSPATH')) {
    exit;
}

const XABIA_MEC_RAG_MAPPING_OPTION = 'xabia_mec_rag_mapping';

const XABIA_MEC_SLOT_MUNICIPALITY = 'municipality';
const XABIA_MEC_SLOT_VENUE        = 'venue';

/**
 * @return array{municipality:string,venue:string}
 */
function xabia_mec_rag_default_settings(): array {
    return [
        XABIA_MEC_SLOT_MUNICIPALITY => 'auto',
        XABIA_MEC_SLOT_VENUE        => 'auto',
    ];
}

/**
 * @return array{municipality:string,venue:string}
 */
function xabia_mec_rag_get_settings(): array {
    $stored = get_option(XABIA_MEC_RAG_MAPPING_OPTION, []);
    if (!is_array($stored)) {
        $stored = [];
    }

    $merged = array_merge(xabia_mec_rag_default_settings(), array_intersect_key($stored, xabia_mec_rag_default_settings()));
    if (class_exists('Xabia_MEC_Admin', false)) {
        foreach ($merged as $slot => $value) {
            $merged[$slot] = Xabia_MEC_Admin::normalize_source_key((string) $value);
        }
    }

    return $merged;
}

/**
 * @param array<string, string> $settings
 */
function xabia_mec_rag_save_settings(array $settings): void {
    $clean = xabia_mec_rag_default_settings();
    foreach ($clean as $slot => $default) {
        if (!isset($settings[$slot])) {
            continue;
        }
        $clean[$slot] = Xabia_MEC_Admin::sanitize_source_key((string) $settings[$slot]);
    }
    update_option(XABIA_MEC_RAG_MAPPING_OPTION, $clean, false);
}

function xabia_mec_rag_sanitize_source_key(string $key): string {
    return Xabia_MEC_Admin::sanitize_source_key($key);
}

function xabia_mec_rag_get_mec_tag_method(): string {
    $settings = get_option('mec_options', []);
    if (!is_array($settings)) {
        return 'post_tag';
    }
    $method = isset($settings['settings']['tag_method']) ? (string) $settings['settings']['tag_method'] : 'post_tag';

    return apply_filters('xabia_mec_rag_tag_method', $method);
}

function xabia_mec_rag_resolve_source(string $slot): string {
    return Xabia_MEC_Admin::resolve_source($slot);
}

/**
 * @return array<string, string>
 */
function xabia_mec_rag_source_options(): array {
    return Xabia_MEC_Admin::get_source_options();
}

/**
 * @return list<string>
 */
function xabia_mec_rag_extract_term_names(int $post_id, string $source_key): array {
    return Xabia_MEC_Builder::extract_source_values($post_id, $source_key);
}

/**
 * @return array{value:string,values:list<string>,source:string}
 */
function xabia_mec_rag_extract_slot(int $post_id, string $slot): array {
    return Xabia_MEC_Builder::extract_slot($post_id, $slot);
}

/**
 * @param list<string> $names
 * @return list<string>
 */
function xabia_mec_rag_locative_variants(array $names): array {
    return Xabia_MEC_Builder::apply_location_variants($names, 0, XABIA_MEC_SLOT_MUNICIPALITY);
}

/**
 * @param array<string, mixed> $payload
 */
function xabia_mec_rag_build_semantic_header(array $payload): string {
    return Xabia_MEC_Builder::build_semantic_header($payload);
}

/**
 * @return array<string, mixed>
 */
function xabia_mec_get_event_payload(int $post_id): array {
    return Xabia_MEC_Builder::build_event_payload($post_id);
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function xabia_mec_rag_enrich_sync_row(array $row): array {
    $pid = 0;
    if (isset($row['ID'])) {
        $pid = absint($row['ID']);
    } elseif (isset($row['id'])) {
        $pid = absint($row['id']);
    }
    if ($pid < 1 || get_post_type($pid) !== Xabia_MEC_Admin::event_post_type()) {
        return $row;
    }

    $payload = xabia_mec_get_event_payload($pid);
    if ($payload === []) {
        return $row;
    }

    $slot_labels = apply_filters(
        'xabia_mec_rag_sync_column_map',
        [
            XABIA_MEC_SLOT_MUNICIPALITY => 'Municipio',
            XABIA_MEC_SLOT_VENUE        => 'Recinto',
        ]
    );

    if (is_array($slot_labels)) {
        foreach ($slot_labels as $slot_id => $column) {
            if (!is_string($column) || $column === '') {
                continue;
            }
            $row[$column] = (string) ($payload[$slot_id] ?? '');
        }
    }

    $row['Municipio_Variantes'] = isset($payload['municipality_variants']) && is_array($payload['municipality_variants'])
        ? implode(', ', $payload['municipality_variants'])
        : '';
    $row['semantic_header'] = (string) ($payload['semantic_header'] ?? '');
    $row['rag_chunk'] = (string) ($payload['rag_chunk'] ?? '');

    $municipio_col = is_array($slot_labels) ? (string) ($slot_labels[XABIA_MEC_SLOT_MUNICIPALITY] ?? 'Municipio') : 'Municipio';
    $recinto_col = is_array($slot_labels) ? (string) ($slot_labels[XABIA_MEC_SLOT_VENUE] ?? 'Recinto') : 'Recinto';

    if (!empty($row[$municipio_col]) && empty($row['Categorias_Tags'])) {
        $row['Categorias_Tags'] = $row[$municipio_col];
    } elseif (!empty($row[$municipio_col]) && strpos((string) ($row['Categorias_Tags'] ?? ''), (string) $row[$municipio_col]) === false) {
        $row['Categorias_Tags'] = trim((string) ($row['Categorias_Tags'] ?? '') . ', ' . $row[$municipio_col], ', ');
    }

    if (!empty($row[$recinto_col]) && (empty($row['Lugar']) || trim((string) $row['Lugar']) === '')) {
        $row['Lugar'] = $row[$recinto_col];
    }

    return $row;
}

/**
 * Variantes locativas por defecto (p. ej. euskera) desacopladas del builder.
 *
 * @param list<string> $values
 * @return list<string>
 */
function xabia_mec_default_location_variants(array $values, int $post_id, string $slot): array {
    unset($post_id, $slot);

    $out = [];
    $seen = [];
    foreach ($values as $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }

        $canonical = $name;
        if ($name === mb_strtoupper($name, 'UTF-8') && mb_strlen($name, 'UTF-8') > 2) {
            $canonical = mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }

        foreach ([$name, $canonical, mb_strtolower($name, 'UTF-8'), mb_strtolower($canonical, 'UTF-8')] as $variant) {
            if ($variant !== '' && !isset($seen[$variant])) {
                $seen[$variant] = true;
                $out[] = $variant;
            }
        }

        $base = $canonical;
        if (preg_match('/^(.+?)(an|en)$/iu', $canonical, $m)) {
            $base = (string) $m[1];
        }
        $base = rtrim($base);
        if ($base === '') {
            continue;
        }

        $locale = function_exists('get_locale') ? (string) get_locale() : '';
        $apply_locative = apply_filters('xabia_mec_apply_default_locative_variants', strpos($locale, 'eu') === 0, $values, $locale);
        if (!$apply_locative) {
            continue;
        }

        foreach (['an', 'en'] as $suffix) {
            if ($suffix === 'an' && preg_match('/a$/iu', $base)) {
                $loc = $base . 'n';
            } elseif ($suffix === 'en' && preg_match('/a$/iu', $base)) {
                $loc = $base . 'n';
            } else {
                $loc = $base . $suffix;
            }
            foreach ([$loc, mb_strtolower($loc, 'UTF-8')] as $loc_variant) {
                if ($loc_variant !== '' && !isset($seen[$loc_variant])) {
                    $seen[$loc_variant] = true;
                    $out[] = $loc_variant;
                }
            }
        }
    }

    return array_values($out);
}

add_filter('xabia_mec_location_variants', 'xabia_mec_default_location_variants', 10, 3);

add_filter(
    'xabia_mec_rag_meta_source_candidates',
    static function ($candidates, $post_type) {
        if ($post_type !== Xabia_MEC_Admin::event_post_type()) {
            return $candidates;
        }
        if (!is_array($candidates)) {
            $candidates = [];
        }

        return apply_filters(
            'xabia_mec_default_meta_source_candidates',
            array_merge(
                $candidates,
                [
                    [
                        'key'      => 'mec_location',
                        'label'    => __('Ubicación MEC (meta)', 'xabia-intelligence'),
                        'synopsis' => __('Texto o referencia de recinto almacenada en meta del evento.', 'xabia-intelligence'),
                    ],
                    [
                        'key'      => 'mec_location_id',
                        'label'    => __('ID recinto MEC (meta)', 'xabia-intelligence'),
                        'synopsis' => __('Identificador de término/meta de recinto cuando MEC no usa taxonomía.', 'xabia-intelligence'),
                    ],
                ]
            ),
            $post_type
        );
    },
    10,
    2
);

add_filter(
    'xabia_mec_semantic_header_parts',
    static function ($parts, $payload) {
        if (!is_array($parts)) {
            $parts = [];
        }

        return apply_filters('xabia_mec_default_semantic_header_parts', $parts, $payload);
    },
    10,
    2
);
