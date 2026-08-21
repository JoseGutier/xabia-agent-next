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

echo "OK test-xabia-embedding-cache.php\n";
