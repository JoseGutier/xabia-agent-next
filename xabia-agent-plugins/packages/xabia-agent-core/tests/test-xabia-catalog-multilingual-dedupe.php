<?php
/**
 * CLI: php packages/xabia-agent-core/tests/test-xabia-catalog-multilingual-dedupe.php
 *
 * Deduplicación agnóstica por sanitize_title(empresa) — sin plugins multilingüe.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        $title = strtolower((string) $title);
        $title = preg_replace('/[^a-z0-9\s-]/', '', $title) ?? $title;
        $title = preg_replace('/[\s_]+/', '-', trim($title)) ?? $title;

        return $title;
    }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($v) {
        return is_string($v) ? stripslashes($v) : $v;
    }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return parse_url((string) $url, $component);
    }
}

require_once dirname(__DIR__) . '/core/class-xabia-catalog-token-match.php';
require_once dirname(__DIR__) . '/core/class-xabia-catalog-list.php';

$config = ['_xabia_request_url' => 'https://aktiba.eus/es/explora/'];
$raw = [
    ['ID' => 17658, 'post_title' => 'Club Hípico Castillo de Butrón', 'empresa' => 'Club Hípico Castillo de Butrón', 'permalink' => 'https://aktiba.eus/es/empresa/club-hipico-butron/'],
    ['ID' => 20001, 'post_title' => 'Club Hípico Castillo de Butrón', 'empresa' => 'Club Hípico Castillo de Butrón', 'permalink' => 'https://aktiba.eus/fr/entreprise/club-hipique-butron/'],
    ['ID' => 20002, 'post_title' => 'Club Hípico Castillo de Butrón', 'empresa' => 'Club Hípico Castillo de Butrón', 'localidad' => 'Biscaye', 'permalink' => 'https://aktiba.eus/fr/entreprise/club-hipique-butron-2/'],
    ['ID' => 17659, 'post_title' => 'Cumbre Guías de Montaña', 'empresa' => 'Cumbre Guías de Montaña', 'permalink' => 'https://aktiba.eus/es/empresa/cumbre-guias-montana/'],
    ['ID' => 30001, 'post_title' => 'Cumbre Guías de Montaña', 'empresa' => 'Cumbre Guías de Montaña', 'permalink' => 'https://aktiba.eus/eu/'],
    ['ID' => 30002, 'post_title' => 'Cumbre Guías de Montaña', 'empresa' => 'Cumbre Guías de Montaña', 'permalink' => 'https://aktiba.eus/fr/'],
    ['ID' => 17658, 'post_title' => 'Club Hípico Castillo de Butrón', 'empresa' => 'Club Hípico Castillo de Butrón', 'permalink' => 'https://aktiba.eus/es/empresa/club-hipico-butron/'],
];

$deduped = Xabia_Catalog_List::dedupe_catalog_entity_rows($raw, 'aktiba-xabia', $config, 'empresa');
assert(count($deduped) === 2, 'dedupe collapses to two unique businesses');

assert(
    Xabia_Catalog_List::catalog_entity_canonical_key(['post_title' => 'Club Hípico Castillo de Butrón', 'empresa' => 'Club Hípico Castillo de Butrón'])
    === Xabia_Catalog_List::catalog_entity_canonical_key(['post_title' => 'Club Hípico Castillo de Butrón', 'empresa' => 'Club Hípico Castillo de Butrón']),
    'identical titles share canonical key'
);

$names = array_map(static fn(array $r): string => (string) ($r['empresa'] ?? ''), $deduped);
$joined = implode('|', $names);
assert(str_contains($joined, 'Butrón'), 'Butrón present');
assert(str_contains($joined, 'Cumbre'), 'Cumbre present');
assert((int) ($deduped[0]['ID'] ?? 0) === 17658, 'lowest ID when permalink scores tie for Butrón group');

$body = Xabia_Catalog_List::build_native_catalog_message_body(
    array_map(static fn(array $r): array => [
        'ID'        => (int) ($r['ID'] ?? 0),
        'empresa'   => (string) ($r['empresa'] ?? ''),
        'localidad' => (string) ($r['localidad'] ?? ''),
        'categoria' => 'Hípica',
    ], $deduped),
    'Hípica',
    'Aktiba'
);
assert(substr_count($body, '• **') === 2, 'exactly two bullets for two unique entities');

echo "OK test-xabia-catalog-multilingual-dedupe\n";
