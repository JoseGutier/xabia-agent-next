<?php
/**
 * Tests mínimos de hash de caché de respuestas (pre-router).
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
}

require_once dirname(__DIR__) . '/core/class-xabia-router.php';

$project = 'demo';
$query = '¿Qué horario tenéis?';
$lang = 'es';

$hash_knowledge = Xabia_Router::query_hash($project, $query, Xabia_Router::ROUTE_KNOWLEDGE, $lang);
$hash_general = Xabia_Router::query_hash($project, $query, Xabia_Router::ROUTE_GENERAL, $lang);

assert($hash_knowledge !== $hash_general, 'route affects hash');
assert(
    Xabia_Router::normalize_query('  Hola   Mundo ') === 'hola mundo',
    'normalize_query'
);

$hash_repeat = Xabia_Router::query_hash($project, $query, Xabia_Router::ROUTE_KNOWLEDGE, $lang);
assert($hash_knowledge === $hash_repeat, 'hash stable');

echo "OK test-xabia-response-cache.php\n";
