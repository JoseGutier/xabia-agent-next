<?php
/**
 * Tests básicos del mapeador RAG MEC (ejecutar con php includes/test-xabia-mec-rag-mapper.php).
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$GLOBALS['xabia_mec_test_options'] = [
    'mec_options' => [
        'settings' => ['tag_method' => 'post_tag'],
        'custom_fields' => [
            '7' => ['id' => 7, 'label' => 'Punto de Encuentro', 'type' => 'text'],
            '11' => ['id' => 11, 'label' => 'Web de reservas', 'type' => 'url'],
            '4' => ['id' => 4, 'label' => 'Información de Reservas', 'type' => 'textarea'],
        ],
    ],
];

$GLOBALS['xabia_mec_test_post_meta'] = [
    42 => [
        'mec_fields' => [
            7 => 'Plaza del Ayuntamiento',
            11 => 'https://reservas.ejemplo.eus',
            4 => ['Opción A', 'Opción B'],
        ],
    ],
];

if (!function_exists('add_action')) {
    function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {
        unset($tag, $callback, $priority, $accepted_args);
    }
}
if (!function_exists('add_filter')) {
    function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {
        unset($tag, $callback, $priority, $accepted_args);
    }
}
if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args) {
        if ($tag === 'xabia_mec_location_variants' && function_exists('xabia_mec_default_location_variants')) {
            return xabia_mec_default_location_variants($value, ...(array) $args);
        }
        if ($tag === 'xabia_mec_locative_variants') {
            return $value;
        }
        if ($tag === 'xabia_mec_rag_slot_definitions') {
            return $value;
        }
        if ($tag === 'xabia_mec_custom_field_definitions') {
            return $value;
        }
        if ($tag === 'xabia_mec_mec_field_header_parts') {
            return $value;
        }
        return $value;
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags((string) $str));
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return strtolower(preg_replace('/[^a-z0-9_-]+/i', '-', (string) $key));
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) $title));
    }
}
if (!function_exists('taxonomy_exists')) {
    function taxonomy_exists($tax) {
        return in_array($tax, ['post_tag', 'mec_tag', 'mec_location', 'mec_category'], true);
    }
}
if (!function_exists('get_object_taxonomies')) {
    function get_object_taxonomies($post_type, $output = 'names') {
        unset($post_type, $output);
        return [];
    }
}
if (!function_exists('get_locale')) {
    function get_locale() {
        return 'eu';
    }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        global $xabia_mec_test_options;
        return $xabia_mec_test_options[$key] ?? $default;
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) {
        global $xabia_mec_test_post_meta;
        unset($single);
        $pid = (int) $post_id;
        if (!isset($xabia_mec_test_post_meta[$pid][$key])) {
            return '';
        }
        return $xabia_mec_test_post_meta[$pid][$key];
    }
}
if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize($data) {
        if (!is_string($data)) {
            return $data;
        }
        $unserialized = @unserialize($data);
        return $unserialized !== false || $data === 'b:0;' ? $unserialized : $data;
    }
}
if (!function_exists('absint')) {
    function absint($value) {
        return abs((int) $value);
    }
}

require_once __DIR__ . '/class-xabia-mec-rag-mapper.php';
require_once dirname(__DIR__) . '/admin/class-xabia-mec-admin.php';
require_once __DIR__ . '/class-xabia-mec-builder.php';
require_once __DIR__ . '/xabia-mec-integration.php';

$normalized = Xabia_MEC_Admin::normalize_source_key('post_tag');
assert($normalized === 'taxonomy:post_tag', 'legacy post_tag normalize');

$key = Xabia_MEC_Admin::normalize_source_key('acf:municipio');
assert($key === 'acf:municipio', 'acf key normalize');

$mec_key = Xabia_MEC_Admin::normalize_source_key('mec_field:11');
assert($mec_key === 'mec_field:11', 'mec_field key normalize');

$definitions = Xabia_MEC_Admin::get_mec_custom_field_definitions();
assert(isset($definitions['11']['label']) && $definitions['11']['label'] === 'Web de reservas', 'mec custom field discovery');

$col = Xabia_MEC_Admin::mec_field_column_name('11');
assert($col === 'mec_field_11', 'mec field column name');

assert(Xabia_MEC_Admin::attribute_column_for_source_key('mec_field:11') === 'mec_field_11', 'attribute column for mec field');
assert(Xabia_MEC_Admin::attribute_column_for_source_key('taxonomy:post_tag') === 'Categorias_Tags', 'attribute column for post_tag');

$mapping_fields = Xabia_MEC_Admin::mapping_attribute_fields();
assert(count($mapping_fields) >= 3, 'mapping attribute fields from MEC custom fields');

$default_fields = Xabia_MEC_Connector::default_mapping_fields();
$evento_ente = false;
$id_ente = false;
foreach ($default_fields as $field) {
    if (($field['csv_col'] ?? '') === 'Evento' && !empty($field['is_ente'])) {
        $evento_ente = true;
    }
    if (($field['csv_col'] ?? '') === 'ID' && !empty($field['is_ente'])) {
        $id_ente = true;
    }
}
assert($evento_ente && !$id_ente, 'Evento is ente, not ID');

$sources = Xabia_MEC_Admin::discover_mec_field_sources();
assert(isset($sources['mec_field:11']) && strpos($sources['mec_field:11']['label'], 'Web de reservas') !== false, 'mec_field source option label');

$values = Xabia_MEC_Builder::extract_source_values(42, 'mec_field:7');
assert($values === ['Plaza del Ayuntamiento'], 'mec_field scalar extract');

$multi = Xabia_MEC_Builder::extract_source_values(42, 'mec_field:4');
assert($multi === ['Opción A, Opción B'], 'mec_field multiselect implode');

$header = xabia_mec_rag_build_semantic_header([
    'ID'                        => 42,
    XABIA_MEC_SLOT_MUNICIPALITY => 'Balmaseda',
    'municipality_variants'     => ['Balmasedan'],
    XABIA_MEC_SLOT_VENUE        => 'Plaza del Ayuntamiento',
    XABIA_MEC_SLOT_VENUE . '_source' => 'mec_field:7',
]);
assert(strpos($header, '[Ubicación:') !== false || strpos($header, '[Ubicacion:') !== false, 'header ubicacion');
assert(strpos($header, 'Balmasedan') !== false, 'header variant');
assert(strpos($header, '[Punto de Encuentro:') !== false, 'mec field label in slot header');
assert(strpos($header, '[Web de reservas:') !== false, 'auto mec field header injection');

$variants = xabia_mec_rag_locative_variants(['BALMASEDA', 'Balmaseda']);
assert(in_array('Balmasedan', $variants, true) || in_array('balmasedan', $variants, true), 'locative Balmasedan');

echo "OK xabia-mec-rag-mapper tests\n";
