<?php
/**
 * Tests mínimos de claves de caché de embeddings.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
}

$GLOBALS['xabia_test_transients'] = [];

if (!function_exists('get_transient')) {
    function get_transient($key) {
        return $GLOBALS['xabia_test_transients'][$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($key, $value, $ttl) {
        unset($ttl);
        $GLOBALS['xabia_test_transients'][$key] = $value;

        return true;
    }
}

require_once dirname(__DIR__) . '/core/class-xabia-embedding-cache.php';

$model = 'text-embedding-004';
$text = '¿Qué horario tenéis?';

$key1 = Xabia_Embedding_Cache::transient_key($model, $text);
$key2 = Xabia_Embedding_Cache::transient_key($model, '  ¿QUÉ HORARIO TENÉIS?  ');
assert($key1 === $key2, 'case/trim insensitive key');

$key3 = Xabia_Embedding_Cache::transient_key('gemini-embedding-001', $text);
assert($key1 !== $key3, 'model changes key');

Xabia_Embedding_Cache::set($model, $text, [0.1, 0.2, 0.3]);
$cached = Xabia_Embedding_Cache::get($model, $text);
assert($cached === [0.1, 0.2, 0.3], 'runtime cache hit');

$stored = $GLOBALS['xabia_test_transients'][$key1] ?? null;
assert($stored === [0.1, 0.2, 0.3], 'set_transient stores the vector under the question hash');

$prop = new ReflectionProperty('Xabia_Embedding_Cache', 'runtime');
$prop->setAccessible(true);
$prop->setValue(null, []);
$from_transient = Xabia_Embedding_Cache::get($model, $text);
assert($from_transient === [0.1, 0.2, 0.3], 'get_transient hit skips a new embedding call');

echo "OK test-xabia-embedding-cache.php\n";
