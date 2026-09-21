<?php
/**
 * Truncate RAG debe preservar URLs largas.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
}

require_once dirname(__DIR__) . '/core/class-xabia-brain.php';

$long = 'https://www.example.com/es/activities/city/experiencia-de-navegacion-de-dia-completo-en-velero-pero-muy-largo-24637';
$chunk = str_repeat('EMPRESA: Demo | detalle ', 40) . ' empresa_web: ' . $long . ' | fin';
$out = Xabia_Brain::truncate_chunk_preserving_imagen($chunk, 900);
assert(strpos($out, $long) !== false, 'full URL preserved after truncate');
assert(strpos($out, 'experiencia-de-navegacion-de-dia-completo') !== false, 'slug not cut mid-way');

$short = Xabia_Brain::truncate_chunk_preserving_imagen('hola ' . $long, 50);
assert(strpos($short, $long) !== false, 'URL kept even when body budget is tiny');

echo "OK test-xabia-truncate-urls\n";
