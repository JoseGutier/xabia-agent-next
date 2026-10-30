<?php
/**
 * CLI: php packages/xabia-agent-core/tests/test-xabia-action-card.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s) {
        return strip_tags((string) $s);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        $args = func_get_args();
        if ($tag === 'xabia_card_payload' && isset($GLOBALS['xabia_test_card_filter']) && is_callable($GLOBALS['xabia_test_card_filter'])) {
            return call_user_func($GLOBALS['xabia_test_card_filter'], $value, isset($args[2]) && is_array($args[2]) ? $args[2] : []);
        }
        return $value;
    }
}

require_once dirname(__DIR__) . '/core/class-xabia-action-card.php';

$tag = Xabia_Action_Card::encode([
    't' => 'VENALIGHT PLUS botilak',
    'm' => 'Circulación · En stock',
    'p' => '24.90 €',
    'i' => 'https://example.com/img.jpg',
    'a' => 'CART:101',
    'l' => 'Comprar',
    'u' => 'https://example.com/product/vena/',
    'cu' => 'https://example.com/cart/',
    'ck' => 'https://example.com/checkout/',
    'rd' => '1',
    'mx' => '12',
]);
assert(str_starts_with($tag, '[ACTION:CARD:') && str_ends_with($tag, ']'), 'tag shape');
$raw = substr($tag, strlen('[ACTION:CARD:'), -1);
$decoded = Xabia_Action_Card::decode($raw);
assert(is_array($decoded), 'decode ok');
assert($decoded['t'] === 'VENALIGHT PLUS botilak', 'title');
assert($decoded['a'] === 'CART:101', 'action');
assert($decoded['i'] === 'https://example.com/img.jpg', 'img');
assert($decoded['u'] === 'https://example.com/product/vena/', 'permalink');
assert($decoded['cu'] === 'https://example.com/cart/', 'cart url');
assert($decoded['ck'] === 'https://example.com/checkout/', 'checkout url');
assert($decoded['rd'] === '1', 'redirect flag');
assert($decoded['mx'] === '12', 'max qty');
assert($decoded['d'] === '', 'legacy payload has no description');
assert($decoded['at'] === 'cart', 'legacy CART derives action_type');
assert($decoded['m'] === 'Circulación · En stock', 'legacy meta kept');

$bad = Xabia_Action_Card::encode(['t' => '']);
assert($bad === '', 'empty title rejected');

$aliases = Xabia_Action_Card::normalize([
    'title' => 'Habitación marina',
    'image' => 'https://example.com/room.jpg',
    'highlight' => '89 €/noche',
    'meta' => 'Vistas al mar',
    'description' => 'Cama king y terraza.',
    'action_type' => 'link',
    'action_text' => 'Reservar',
    'url' => 'https://example.com/book',
]);
assert($aliases['t'] === 'Habitación marina', 'alias title');
assert($aliases['i'] === 'https://example.com/room.jpg', 'alias image');
assert($aliases['p'] === '89 €/noche', 'alias highlight');
assert($aliases['m'] === 'Vistas al mar', 'alias meta');
assert($aliases['d'] === 'Cama king y terraza.', 'alias description');
assert($aliases['l'] === 'Reservar', 'alias action_text');
assert($aliases['u'] === 'https://example.com/book', 'alias url');
assert($aliases['a'] === 'URL:https://example.com/book', 'link action built from url');
assert($aliases['at'] === 'link', 'explicit link');

$aliasTag = Xabia_Action_Card::encode([
    'title' => 'Habitación marina',
    'description' => 'Cama king y terraza.',
    'highlight' => '89 €/noche',
    'meta' => 'Vistas al mar',
    'action_type' => 'link',
    'action_text' => 'Reservar',
    'url' => 'https://example.com/book',
]);
$aliasRaw = substr($aliasTag, strlen('[ACTION:CARD:'), -1);
$aliasDecoded = Xabia_Action_Card::decode($aliasRaw);
assert(is_array($aliasDecoded), 'alias decode');
assert($aliasDecoded['d'] === 'Cama king y terraza.', 'roundtrip description');
assert($aliasDecoded['at'] === 'link', 'roundtrip action_type');
assert($aliasDecoded['p'] === '89 €/noche', 'roundtrip highlight');

$byId = Xabia_Action_Card::normalize([
    'title' => 'Caléndula',
    'action_type' => 'cart',
    'product_id' => 711,
]);
assert($byId['a'] === 'CART:711', 'cart id from product_id');
assert($byId['at'] === 'cart', 'cart type from product_id');

$noCart = Xabia_Action_Card::normalize(['title' => 'Sin id', 'action_type' => 'cart']);
assert($noCart['a'] === '' && $noCart['at'] === '', 'cart without id is dropped');

$noLink = Xabia_Action_Card::normalize(['title' => 'Sin url', 'action_type' => 'link']);
assert($noLink['a'] === '' && $noLink['at'] === '', 'link without url is dropped');

$book = Xabia_Action_Card::normalize([
    'title' => 'Fisioterapia',
    'action' => 'BOOK:amelia:15',
    'action_text' => 'Pedir cita',
    'highlight' => '45 min',
]);
assert($book['a'] === 'BOOK:amelia:15', 'book action preserved');
assert($book['at'] === 'link', 'book is a link-shaped action');

$info = Xabia_Action_Card::normalize([
    't' => 'Solo ficha',
    'u' => 'https://example.com/product/solo/',
    'l' => 'Comprar',
]);
assert($info['a'] === '' && $info['at'] === '', 'permalink alone does not invent a button');

$GLOBALS['xabia_test_card_filter'] = static function ($payload, $context) {
    assert(($context['source'] ?? '') === 'woo', 'filter receives context');
    $payload['description'] = 'Extracto suave';
    return $payload;
};
$filteredTag = Xabia_Action_Card::encode(['t' => 'Caléndula', 'a' => 'CART:7'], ['source' => 'woo']);
unset($GLOBALS['xabia_test_card_filter']);
$filtered = Xabia_Action_Card::decode(substr($filteredTag, strlen('[ACTION:CARD:'), -1));
assert(is_array($filtered) && $filtered['d'] === 'Extracto suave', 'filter can add description');
assert($filtered['at'] === 'cart', 'filter keeps cart type');

$thin = Xabia_Action_Card::encode(['t' => 'Caléndula', 'a' => 'CART:7', 'd' => 'Extracto']);
$context = "[Entidad: Caléndula] [Clasificación: Extractos] [Dato clave: 10,60 €] [Estado: agotado] [Resumen: Extracto de flor] [Enlace: https://saludavida.com/producto/calendula/]\n"
    . "[Imagen disponible: https://saludavida.com/cal.jpg]\n\n"
    . "[Entidad: Concierto de mayo] [Dato clave: 12 de mayo] [Enlace: https://example.com/mayo]\n"
    . "[Imagen disponible: https://example.com/mayo.jpg]";
$enriched = Xabia_Action_Card::enrich_response_cards("Te recomiendo esto.\n\n" . $thin, $context);
assert(preg_match('/\[ACTION:CARD:([^\]]+)\]/', $enriched, $em) === 1, 'enriched card present');
$filled = Xabia_Action_Card::decode((string) $em[1]);
assert(is_array($filled), 'enriched card decodes');
assert($filled['p'] === '10,60 €', 'price from dato clave');
assert($filled['i'] === 'https://saludavida.com/cal.jpg', 'image from imagen disponible');
assert($filled['u'] === 'https://saludavida.com/producto/calendula/', 'permalink from enlace');
assert($filled['a'] === 'CART:7', 'cart action kept');

$dated = Xabia_Action_Card::encode(['t' => 'Concierto de mayo', 'a' => 'URL:https://example.com/mayo']);
$datedOut = Xabia_Action_Card::enrich_response_cards($dated, $context);
$datedCard = null;
if (preg_match('/\[ACTION:CARD:([^\]]+)\]/', $datedOut, $dm)) {
    $datedCard = Xabia_Action_Card::decode((string) $dm[1]);
}
assert(is_array($datedCard) && $datedCard['p'] === '', 'a date is not a price');
assert($datedCard['i'] === 'https://example.com/mayo.jpg', 'event image still attaches');

echo "OK xabia action card\n";
