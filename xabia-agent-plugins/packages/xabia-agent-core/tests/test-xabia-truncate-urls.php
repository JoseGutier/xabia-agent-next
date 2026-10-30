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

$priced = Xabia_Brain::format_generic_entity_contract(
    "ID del producto: 55 | Nombre del producto: Citrobiotic Bio | Precio actual: 18.90 | Link: https://saludavida.com/producto/citrobiotic/"
);
assert(strpos($priced, '[Entidad: Citrobiotic Bio]') !== false, 'product name stays the entity title');
assert(strpos($priced, '[Dato clave: 18.90]') !== false, 'Precio actual becomes the card highlight');
assert(strpos($priced, '[Enlace: https://saludavida.com/producto/citrobiotic/]') !== false, 'product link stays on the contract');
assert(strpos($priced, '[ID: 55]') !== false, 'product id stays on the contract');

$long_body = str_repeat('Descripcion larga del extracto de semillas. ', 40);
$cut = Xabia_Brain::truncate_chunk_preserving_imagen(
    "Nombre del producto: Citrobiotic Bio | " . $long_body . " | Precio actual: 18.90 | Link: https://saludavida.com/producto/citrobiotic/",
    400
);
assert(strpos($cut, 'Precio actual: 18.90') !== false, 'price survives chunk truncation');
assert(strpos($cut, 'https://saludavida.com/producto/citrobiotic/') !== false, 'product url survives chunk truncation');

$indexed = 'ID del producto (Identificador del post product (carrito).): 55 | '
    . 'Nombre del producto (Título comercial para listados y búsqueda.): Citrobiotic Bio | '
    . 'Precio actual (Meta _price (en oferta suele ser el precio rebajado; en variables puede ser «desde»).): 18.90 | '
    . 'Link (URL pública de la ficha.): https://saludavida.com/producto/citrobiotic/';
$from_index = Xabia_Brain::format_generic_entity_contract($indexed);
assert(strpos($from_index, '[Entidad: Citrobiotic Bio]') !== false, 'mapped label with instruction still yields the product name');
assert(strpos($from_index, '[Dato clave: 18.90]') !== false, 'Precio actual with mapping instruction becomes the price');
assert(strpos($from_index, '[Enlace: https://saludavida.com/producto/citrobiotic/]') !== false, 'link with mapping instruction stays');
assert(strpos($from_index, '[ID: 55]') !== false, 'mapped product id stays on the contract');

echo "OK test-xabia-truncate-urls\n";
