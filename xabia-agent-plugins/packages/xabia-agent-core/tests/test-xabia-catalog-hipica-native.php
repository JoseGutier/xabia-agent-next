<?php
/**
 * CLI: php packages/xabia-agent-core/tests/test-xabia-catalog-hipica-native.php
 *
 * Catálogo nativo solo con etiqueta de taxonomía explícita (palabra completa).
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
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s) {
        return strip_tags((string) $s);
    }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) {
        $url = trim((string) $url);
        return preg_match('#^https?://#i', $url) ? $url : '';
    }
}

require_once dirname(__DIR__) . '/core/class-xabia-catalog-token-match.php';
require_once dirname(__DIR__) . '/core/class-xabia-action-card.php';
require_once dirname(__DIR__) . '/core/class-xabia-catalog-list.php';

$msg = '¿Qué empresas de hípica hay en el País Vasco?';
assert(
    Xabia_Catalog_Token_Match::message_contains_whole_term($msg, 'hípica'),
    'hípica whole word in listing query'
);

$entities = [
    ['ID' => 17658, 'empresa' => 'Club Hípico Castillo de Butrón', 'localidad' => 'Barrio Butrón, 42, Gatika, Bizkaia', 'categoria' => 'Tierra'],
    ['ID' => 17659, 'empresa' => 'Cumbre Guías de Montaña', 'localidad' => 'Ortuella, Bizkaia', 'categoria' => 'Tierra'],
];
$matched_badge = 'Hípica';

assert(
    Xabia_Catalog_List::format_native_catalog_intro_term('Hípica|caballo') === 'Hípica',
    'pipe composite term collapses to Hípica in intro'
);
assert(
    Xabia_Catalog_List::format_native_catalog_intro_term('caballo|Hípica') === 'Hípica',
    'prefers taxonomy label over synonym in pipe list'
);

$body = Xabia_Catalog_List::compose_native_catalog_message_from_entities($entities, 'Hípica|caballo', 'Aktiba');
assert(str_contains($body, 'Aquí tienes las empresas de Hípica disponibles en Aktiba:'), 'native catalog intro');
assert(!str_contains($body, 'Hípica|caballo'), 'intro must not contain pipe composite');
assert(str_contains($body, 'Butrón'), 'Butrón bullet');
assert(str_contains($body, 'Cumbre'), 'Cumbre bullet');
assert(substr_count($body, '• **') === count($entities), 'one bullet per final entity (SSOT)');
assert(str_contains($body, 'Barrio Butrón, 42, Gatika, Bizkaia'), 'Butrón locality in bullet');
assert(str_contains($body, 'Ortuella, Bizkaia'), 'Cumbre locality in bullet');

$cards = [];
foreach ($entities as $row) {
    $cards[] = Xabia_Action_Card::encode([
        'id'          => (int) $row['ID'],
        'title'       => $row['empresa'],
        'badge'       => $matched_badge,
        'permalink'   => 'https://aktiba.eus/empresa/demo-' . (int) $row['ID'] . '/',
        'action_type' => 'view',
        'label'       => 'Ver ficha',
    ], ['source' => 'native_catalog', 'project_id' => 'aktiba-xabia']);
}
$titles = [];
foreach ($cards as $tag) {
    if (!preg_match('/\[ACTION:CARD:([^\]]+)\]/u', (string) $tag, $m)) {
        continue;
    }
    $decoded = Xabia_Action_Card::decode((string) ($m[1] ?? ''));
    if (is_array($decoded)) {
        $titles[] = (string) ($decoded['t'] ?? '');
    }
}
$joined = implode(' ', $titles);
assert(str_contains($joined, 'Butrón'), 'Butrón card');
assert(str_contains($joined, 'Cumbre'), 'Cumbre card');

$native_response = trim($body) . "\n\n" . implode("\n\n", $cards);
assert(str_contains($native_response, 'Aquí tienes las empresas de Hípica disponibles en Aktiba:'), 'full response intro');
assert(substr_count($native_response, '[ACTION:CARD:') === 2, 'two entity cards appended');

echo "OK test-xabia-catalog-hipica-native\n";
