<?php
/**
 * El SQL de catálogo wp_posts se regenera desde el mapeo (sin WordPress Test Suite).
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args) {
        return $value;
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $key));
    }
}

require_once dirname(__DIR__) . '/core/class-xabia-knowledge-ingest.php';

$skinny = "SELECT\n    p.`ID`,\n    p.`post_content`,\n    p.`post_title`,\n    p.`post_excerpt`\nFROM {prefix}posts p\nWHERE p.post_type = 'catalog_item'\n  AND p.post_status = 'publish'";

$config = [
    'source_type' => 'local_sql',
    'sql_config'  => ['query' => $skinny],
    'attributes'  => [
        ['csv_col' => 'ID'],
        ['csv_col' => 'post_title'],
        ['csv_col' => 'ficha_descripcion'],
        ['csv_col' => 'tax_tema'],
    ],
];
$out = Xabia_Knowledge_Ingest::maybe_rebuild_wp_catalog_sql($config);
$sql = (string) ($out['sql_config']['query'] ?? '');
assert(strpos($sql, 'ficha_descripcion') !== false, 'mapped meta field appears in generated SQL');
assert(strpos($sql, 'tax_tema') !== false, 'taxonomy subquery is in SQL');
assert(strpos($sql, 'post_title') !== false, 'core post field stays in SQL');
assert(strpos($sql, "post_type = 'catalog_item'") !== false, 'post_type is preserved');

$preset = $config;
$preset['sql_preset'] = 'mec_remote';
$preset_out = Xabia_Knowledge_Ingest::maybe_rebuild_wp_catalog_sql($preset);
assert(($preset_out['sql_config']['query'] ?? '') === $skinny, 'SQL presets are not overwritten');

$amelia = [
    'source_type' => 'local_sql',
    'sql_config'  => ['query' => "SELECT id AS ID FROM {prefix}amelia_services"],
    'attributes'  => [['csv_col' => 'ID'], ['csv_col' => 'name']],
];
$amelia_out = Xabia_Knowledge_Ingest::maybe_rebuild_wp_catalog_sql($amelia);
assert(strpos((string) ($amelia_out['sql_config']['query'] ?? ''), 'amelia_services') !== false, 'Amelia SQL is left intact');

echo "OK test-xabia-mapping-sql.php\n";
