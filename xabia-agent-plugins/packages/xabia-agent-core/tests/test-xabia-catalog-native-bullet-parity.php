<?php
/**
 * CLI: php packages/xabia-agent-core/tests/test-xabia-catalog-native-bullet-parity.php
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!function_exists('__')) {
    function __($s, $d = '') {
        return (string) $s;
    }
}
if (!function_exists('esc_html')) {
    function esc_html($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
}

require_once dirname(__DIR__) . '/core/class-xabia-catalog-list.php';

$entities = [
    ['ID' => 1, 'empresa' => 'Solo Empresa Legacy', 'location' => 'Mungia'],
    ['ID' => 2, 'title' => 'Solo Title Mapeado', 'localidad' => 'Bilbao'],
    ['ID' => 3, 'name' => 'Solo Name', 'address' => 'Getxo'],
    ['ID' => 4, 'post_title' => 'Solo Post Title WP'],
];

$body = Xabia_Catalog_List::compose_native_catalog_message_from_entities(
    $entities,
    'Hípica|caballo',
    'Aktiba'
);

$bullet_count = count(Xabia_Catalog_List::build_native_catalog_bullet_lines_from_entities($entities));
assert($bullet_count === count($entities), 'one bullet per final entity (heterogeneous name keys)');
assert(str_contains($body, 'Solo Empresa Legacy'), 'empresa-only row');
assert(str_contains($body, 'Solo Title Mapeado'), 'title-only row');
assert(str_contains($body, 'Solo Name'), 'name-only row');
assert(str_contains($body, 'Solo Post Title WP'), 'post_title fallback row');
assert(str_contains($body, 'Hípica'), 'intro uses formatted term without pipe');
assert(!str_contains($body, 'caballo'), 'synonym needle not in intro');
assert(!str_contains($body, '|'), 'pipe composite absent from intro body');

echo "OK test-xabia-catalog-native-bullet-parity\n";
