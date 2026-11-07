<?php
/**
 * CLI: php packages/xabia-agent-core/tests/test-xabia-knowledge-relations.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}

if (!function_exists('__')) {
    function __($s, $d = '') {
        return (string) $s;
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($s) {
        return trim(strip_tags((string) $s));
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($s) {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $s));
    }
}
if (!function_exists('esc_sql')) {
    function esc_sql($s) {
        return addslashes((string) $s);
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s) {
        return strip_tags((string) $s);
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        return $GLOBALS['xabia_test_projects_config'] ?? $default;
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false) {
        $all = $GLOBALS['xabia_test_post_meta'][$post_id][$key] ?? null;
        if ($all === null) {
            return $single ? '' : [];
        }
        return $single ? $all : [$all];
    }
}
if (!function_exists('get_field')) {
    function get_field($key, $post_id, $format = true) {
        return get_post_meta($post_id, $key, true);
    }
}
if (!function_exists('get_posts')) {
    function get_posts($args) {
        $pt = $args['post_type'] ?? '';
        $out = [];
        foreach (array_keys($GLOBALS['xabia_test_post_meta'] ?? []) as $pid) {
            $ptype = $GLOBALS['xabia_test_post_types'][$pid] ?? '';
            if ($ptype === $pt) {
                $out[] = $pid;
            }
        }
        return $out;
    }
}
if (!function_exists('get_post_type')) {
    function get_post_type($id) {
        return $GLOBALS['xabia_test_post_types'][$id] ?? '';
    }
}
if (!function_exists('get_the_title')) {
    function get_the_title($id) {
        return $GLOBALS['xabia_test_titles'][$id] ?? '';
    }
}
if (!function_exists('get_the_terms')) {
    function get_the_terms($post_id, $tax) {
        return $GLOBALS['xabia_test_terms'][$post_id][$tax] ?? false;
    }
}

require_once dirname(__DIR__) . '/core/class-xabia-knowledge-relations.php';

// --- parse ACF/meta payloads ---
$ser = 'a:3:{i:0;s:5:"17949";i:1;s:5:"17945";i:2;s:5:"17946";}';
$ref = new ReflectionClass('Xabia_Knowledge_Relations');
$parse = $ref->getMethod('parse_related_ids');
$parse->setAccessible(true);
$ids = $parse->invoke(null, $ser);
assert($ids === [17949, 17945, 17946], 'serialized relationship IDs');

$json = '[17939,17952,17941]';
$ids2 = $parse->invoke(null, $json);
assert($ids2 === [17939, 17952, 17941], 'json relationship IDs');

// --- simulate Cumbre Guías de Montaña (17659) + experiencias hípicas ---
$GLOBALS['xabia_test_projects_config'] = [
    'aktiba-xabia' => [
        'catalog_post_type' => 'empresa',
        'sql_config'        => [],
        'rules'             => [
            'knowledge_relations' => [
                [
                    'relation_type'       => 'meta_key',
                    'relation_key'        => 'experiencias_relacionadas',
                    'source_post_type'    => 'empresa',
                    'connected_post_type' => 'experiencia',
                ],
            ],
        ],
    ],
];

$GLOBALS['xabia_test_post_types'] = [
    17659 => 'empresa',
    17949 => 'experiencia',
    17945 => 'experiencia',
    17946 => 'experiencia',
    17939 => 'experiencia',
];
$GLOBALS['xabia_test_titles'] = [
    17659 => 'Cumbre Guías de Montaña',
    17949 => 'Pony games',
    17945 => 'Campamentos hípicos',
    17946 => 'Clases de equitación',
    17939 => 'Cursos de escalada',
];
$GLOBALS['xabia_test_post_meta'] = [
    17659 => [
        'experiencias_relacionadas' => [17949, 17945, 17946, 17939],
    ],
];
$term = (object) ['name' => 'Hípica'];
$tierra = (object) ['name' => 'Tierra'];
$GLOBALS['xabia_test_terms'] = [
    17949 => ['categoria-de-actividad' => [$term]],
    17945 => ['categoria-de-actividad' => [$term]],
    17946 => ['categoria-de-actividad' => [$term]],
    17939 => ['categoria-de-actividad' => [$tierra]],
];

Xabia_Knowledge_Relations::clear_cache();
Xabia_Knowledge_Relations::warm_for_project('aktiba-xabia', []);

$row = ['ID' => 17659, 'post_title' => 'Cumbre Guías de Montaña', 'post_type' => 'empresa'];
$blob = 'Descripción base de la empresa sin equitación en el cuerpo.';
$enriched = Xabia_Knowledge_Relations::enrich_text_blob($blob, $row, 'aktiba-xabia', $GLOBALS['xabia_test_projects_config']['aktiba-xabia']);

assert(str_contains($enriched, 'Pony games'), 'Pony games in blob');
assert(str_contains($enriched, 'Campamentos hípicos'), 'Campamentos hípicos in blob');
assert(str_contains($enriched, 'Clases de equitación'), 'Clases de equitación in blob');
assert(str_contains($enriched, 'Hípica'), 'taxonomy Hípica in blob');
assert(str_contains($enriched, 'Experiencia relacionada:'), 'detail block prefix');

echo "OK test-xabia-knowledge-relations\n";
echo "Preview:\n" . $enriched . "\n";
