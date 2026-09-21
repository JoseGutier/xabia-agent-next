<?php
/**
 * CLI: php packages/xabia-woo/tests/test-xabia-woo-cart-append.php
 */

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s) {
        return strip_tags((string) $s);
    }
}
if (!function_exists('remove_accents')) {
    function remove_accents($s) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $s);
        return is_string($t) && $t !== '' ? $t : (string) $s;
    }
}
if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}
if (!function_exists('add_filter')) {
    function add_filter(...$args) {
        return true;
    }
}
if (!function_exists('add_action')) {
    function add_action(...$args) {
        return true;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args) {
        return $value;
    }
}
if (!function_exists('__')) {
    function __($s, $d = null) {
        return $s;
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__($s, $d = null) {
        return $s;
    }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename($f) {
        return $f;
    }
}
if (!function_exists('get_option')) {
    function get_option($k, $d = false) {
        return $d;
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

require_once dirname(__DIR__, 2) . '/xabia-agent-core/core/class-xabia-action-card.php';
require_once dirname(__DIR__) . '/includes/xabia-woo-integration.php';

$context = "ID del producto: 101 | Nombre del producto: VENALIGHT PLUS botilak | Precio: 24.90 | Categorias: Circulación | Stock_estado: instock | Imagen: https://cdn.example.com/vena.jpg\n\n"
    . "ID del producto: 202 | Nombre del producto: Hanka nekatuen tratamendu paketea | Precio: 39.90\n\n"
    . "ID del producto: 303 | Nombre del producto: Linfabri Gela | Precio: 12.00";

$response = "Te recomiendo VENALIGHT PLUS botilak y también el Hanka nekatuen tratamendu paketea.";
$out = xabia_woo_maybe_append_cart_actions($response, $context, 'quiero comprar venalight plus');
assert(strpos($out, '[ACTION:CARD:') !== false, 'must append ACTION:CARD');
assert(strpos($out, '[ACTION:CART:101]') === false, 'must not leave bare CART when card exists');
assert(preg_match_all('/\[ACTION:CARD:/', $out) >= 2, 'two product cards');

// CART inline mid-sentence (como en Saludavida) → CARD en su sitio, sin botón suelto.
$inline = "El Aprolis Erysim Forte Spray Bucal [ACTION:CART:555] es perfecto para la garganta.\n"
    . "El Protecsapin jarabe [ACTION:CART:556], ofrece confort.";
$out3 = xabia_woo_maybe_append_cart_actions($inline, '', '');
assert(strpos($out3, '[ACTION:CART:555]') === false, 'inline cart 555 upgraded');
assert(strpos($out3, '[ACTION:CART:556]') === false, 'inline cart 556 upgraded');
assert(substr_count($out3, '[ACTION:CARD:') >= 2, 'two cards from inline carts');
assert(strpos($out3, 'es perfecto') !== false, 'prose preserved around card');

$raw = null;
if (preg_match('/\[ACTION:CARD:([^\]]+)\]/', $out3, $m)) {
    $raw = $m[1];
}
$decoded = Xabia_Action_Card::decode((string) $raw);
assert(is_array($decoded) && strpos($decoded['a'], 'CART:') === 0, 'card CTA is CART');
assert($decoded['t'] !== '', 'guessed title from prose');

echo "OK xabia-woo cart append cards\n";
