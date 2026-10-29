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
if (!function_exists('has_filter')) {
    function has_filter($tag, $function_to_check = false) {
        if (!empty($GLOBALS['xabia_test_wpml_enabled']) && ($tag === 'wpml_object_id' || $tag === 'wpml_current_language')) {
            return true;
        }
        return false;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args) {
        // Simula WPML: eu 999 ↔ es 120.
        if ($tag === 'wpml_current_language') {
            return $GLOBALS['xabia_test_wpml_lang'] ?? null;
        }
        if ($tag === 'wpml_object_id') {
            if (empty($GLOBALS['xabia_test_wpml_enabled'])) {
                return $value;
            }
            $id = (int) $value;
            $lang = isset($args[2]) ? (string) $args[2] : '';
            $map_eu_to_es = [999 => 120, 998 => 121];
            $map_es_to_eu = [120 => 999, 121 => 998];
            if ($lang === 'es' && isset($map_eu_to_es[$id])) {
                return $map_eu_to_es[$id];
            }
            if ($lang === 'eu' && isset($map_es_to_eu[$id])) {
                return $map_es_to_eu[$id];
            }
            return $id;
        }
        return $value;
    }
}
if (!function_exists('do_action')) {
    function do_action(...$args) {
        return null;
    }
}
if (!function_exists('pll_current_language')) {
    function pll_current_language($field = 'slug') {
        return $GLOBALS['xabia_test_pll_lang'] ?? false;
    }
}
if (!function_exists('pll_get_post')) {
    function pll_get_post($post_id, $lang = '') {
        if (empty($GLOBALS['xabia_test_pll_enabled'])) {
            return false;
        }
        $id = (int) $post_id;
        $map_eu_to_es = [999 => 120];
        $map_es_to_eu = [120 => 999];
        if ($lang === 'es' && isset($map_eu_to_es[$id])) {
            return $map_eu_to_es[$id];
        }
        if ($lang === 'eu' && isset($map_es_to_eu[$id])) {
            return $map_es_to_eu[$id];
        }
        return $id;
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

// --- Saludavida: prosa Aloe + CART con ID de RealVit → autocorregir a Aloe ---
$salud_ctx = "ID del producto: 120 | Nombre del producto: Aloe Verum Premium | Precio: 18.90 | Categorias: Digestión | Stock_estado: instock\n\n"
    . "ID del producto: 999 | Nombre del producto: RealVit Errege Jelly paketea x2 | Precio: 47.10 | Categorias: BITAMINAK ETA MINERALAK | Stock_estado: instock";

$salud_legacy = "¡Hola! Para problemas de digestión, el Aloe Vera es una excelente opción.\n"
    . "Te recomiendo el Aloe Verum Premium. Este producto está especialmente formulado para apoyar la salud digestiva.\n"
    . "[ACTION:CART:999]";
$out_salud = xabia_woo_maybe_append_cart_actions($salud_legacy, $salud_ctx, 'Algo con aloe vera para problemas de digestión?');
assert(strpos($out_salud, '[ACTION:CART:999]') === false, 'wrong RealVit CART must not remain');
assert(strpos($out_salud, '[ACTION:CARD:') !== false, 'Saludavida must render a card');
assert(preg_match_all('/\[ACTION:CARD:([^\]]+)\]/', $out_salud, $salud_cards), 'Saludavida cards parseable');
$salud_ok = false;
foreach ($salud_cards[1] as $payload) {
    $d = Xabia_Action_Card::decode((string) $payload);
    if (!is_array($d)) {
        continue;
    }
    $title = (string) ($d['t'] ?? '');
    $action = (string) ($d['a'] ?? '');
    if (stripos($title, 'Aloe') !== false && $action === 'CART:120') {
        $salud_ok = true;
    }
    assert(stripos($title, 'RealVit') === false, 'must never show RealVit card for Aloe ask');
    assert($action !== 'CART:999', 'must never CTA RealVit for Aloe ask');
}
assert($salud_ok, 'Saludavida legacy CART must autocorrect to Aloe ID 120');

$salud_titled = "Te recomiendo el Aloe Verum Premium.\n[ACTION:CART:999:Aloe Verum Premium]";
$out_titled = xabia_woo_maybe_append_cart_actions($salud_titled, $salud_ctx, 'aloe vera digestion');
assert(strpos($out_titled, '[ACTION:CART:999:Aloe Verum Premium]') === false, 'titled wrong CART replaced');
$titled_ok = false;
if (preg_match_all('/\[ACTION:CARD:([^\]]+)\]/', $out_titled, $tc)) {
    foreach ($tc[1] as $payload) {
        $d = Xabia_Action_Card::decode((string) $payload);
        if (is_array($d) && ($d['a'] ?? '') === 'CART:120' && stripos((string) ($d['t'] ?? ''), 'Aloe') !== false) {
            $titled_ok = true;
        }
    }
}
assert($titled_ok, 'CART:ID:TITULO must autocorrect ID to matching catalog row');

// Formato correcto: ID y título de la misma fila → sin cambio de ID.
$salud_ok_tag = "Te recomiendo Aloe Verum Premium.\n[ACTION:CART:120:Aloe Verum Premium]";
$out_ok = xabia_woo_maybe_append_cart_actions($salud_ok_tag, $salud_ctx, '');
assert(strpos($out_ok, '[ACTION:CART:120:Aloe Verum Premium]') === false, 'correct titled CART upgraded to CARD');
$ok_card = false;
if (preg_match('/\[ACTION:CARD:([^\]]+)\]/', $out_ok, $om)) {
    $d = Xabia_Action_Card::decode((string) $om[1]);
    $ok_card = is_array($d) && ($d['a'] ?? '') === 'CART:120' && stripos((string) ($d['t'] ?? ''), 'Aloe') !== false;
}
assert($ok_card, 'matching ID+title keeps Aloe card');

// Sin match fiable en catálogo → no ficha contradictoria.
$mismatch_only = "Te recomiendo el Elixir Lunar Fantasma.\n[ACTION:CART:999:Elixir Lunar Fantasma]";
$out_drop = xabia_woo_maybe_append_cart_actions($mismatch_only, $salud_ctx, '');
assert(strpos($out_drop, '[ACTION:CARD:') === false, 'unmatched invented name must not render card');
assert(strpos($out_drop, '[ACTION:CART:999') === false, 'contradictory CART must be stripped');

// LLM recomienda en prosa sin etiqueta → red de seguridad Path 2 genera CARD.
$no_tag = "Para digestiones pesadas te recomiendo el Aloe Verum Premium, ideal para calmar la mucosa.";
$out_notag = xabia_woo_maybe_append_cart_actions($no_tag, $salud_ctx, 'algún producto con aloe vera para las digestiones pesadas');
assert(strpos($out_notag, '[ACTION:CARD:') !== false, 'prose-only recommendation must still get a card');
$notag_ok = false;
if (preg_match_all('/\[ACTION:CARD:([^\]]+)\]/', $out_notag, $nc)) {
    foreach ($nc[1] as $payload) {
        $d = Xabia_Action_Card::decode((string) $payload);
        if (is_array($d) && ($d['a'] ?? '') === 'CART:120' && stripos((string) ($d['t'] ?? ''), 'Aloe') !== false) {
            $notag_ok = true;
            assert(($d['l'] ?? '') !== '', 'buy label present');
        }
    }
}
assert($notag_ok, 'prose-only Aloe mention must map to CART:120 card');

// Markdown comercial sin CART: "- **Aloe Verum Premium**:" debe rescatarse.
$md_no_tag = "¡Claro! Para problemas estomacales y digestivos te propongo:\n\n"
    . "- **Aloe Verum Premium**: ayuda a calmar la mucosa y favorece una digestión más ligera.\n\n"
    . "Si quieres, también puedo orientarte sobre posología.";
$out_md = xabia_woo_maybe_append_cart_actions(
    $md_no_tag,
    $salud_ctx,
    'tienes algo de aloe vera para problemas estomacales y digestivos'
);
assert(strpos($out_md, '[ACTION:CARD:') !== false, 'markdown list without CART must get a card');
$md_ok = false;
if (preg_match_all('/\[ACTION:CARD:([^\]]+)\]/', $out_md, $mdc)) {
    foreach ($mdc[1] as $payload) {
        $d = Xabia_Action_Card::decode((string) $payload);
        if (is_array($d) && ($d['a'] ?? '') === 'CART:120' && stripos((string) ($d['t'] ?? ''), 'Aloe') !== false) {
            $md_ok = true;
        }
        assert(stripos((string) ($d['t'] ?? ''), 'RealVit') === false, 'markdown rescue must not pick RealVit');
    }
}
assert($md_ok, 'markdown **- Título:** must rescue Aloe Verum Premium card');
assert(xabia_woo_assistant_mentions_product($md_no_tag, 'Aloe Verum Premium'), 'mention helper detects markdown title');
assert(strpos($out_md, xabia_woo_conversion_few_shot_block()) === false, 'few-shot is prompt-only not injected into chat');

// Cascada agnóstica: monolingüe → ID original.
$GLOBALS['xabia_test_wpml_enabled'] = false;
$GLOBALS['xabia_test_pll_enabled'] = false;
$GLOBALS['xabia_test_wpml_lang'] = null;
$GLOBALS['xabia_test_pll_lang'] = false;
assert(xabia_woo_resolve_contextual_product_id(999) === 999, 'monolingual keeps original id');
assert(xabia_woo_resolve_contextual_product_id(0) === 0, 'invalid id returns 0');

// WPML: ID euskera → ID español del visitante.
$GLOBALS['xabia_test_wpml_enabled'] = true;
$GLOBALS['xabia_test_wpml_lang'] = 'es';
assert(xabia_woo_resolve_contextual_product_id(999) === 120, 'WPML maps EU 999 to ES 120');
assert(xabia_woo_resolve_contextual_product_id(120) === 120, 'WPML keeps ES id in ES session');
$GLOBALS['xabia_test_wpml_lang'] = 'eu';
assert(xabia_woo_resolve_contextual_product_id(120) === 999, 'WPML maps ES 120 to EU 999');
$GLOBALS['xabia_test_wpml_enabled'] = false;
$GLOBALS['xabia_test_wpml_lang'] = null;

// Polylang (sin WPML): misma cascada.
$GLOBALS['xabia_test_pll_enabled'] = true;
$GLOBALS['xabia_test_pll_lang'] = 'es';
assert(xabia_woo_resolve_contextual_product_id(999) === 120, 'Polylang maps EU 999 to ES 120');
$GLOBALS['xabia_test_pll_enabled'] = false;
$GLOBALS['xabia_test_pll_lang'] = false;

// Alias legacy.
assert(xabia_woo_localize_product_id(42) === 42, 'legacy alias works monolingual');

// El modelo copia la plantilla [ACTION:CART:ID:Titulo] sin número.
$literal = "En saludavida.com te recomiendo:\nPropolium Gola Spray: Este spray es ideal para la garganta.\n[ACTION:CART:ID:Propolium Gola Spray]";
$literal_ctx = "ID del producto: 880 | Nombre del producto: Propolium Gola Spray | Precio: 14.50 | Categorias: Garganta | Stock_estado: instock";
$out_literal = xabia_woo_maybe_append_cart_actions($literal, $literal_ctx, 'tienes propóleo para la garganta');
assert(strpos($out_literal, '[ACTION:CART:ID:') === false, 'literal ID token must be replaced');
assert(strpos($out_literal, '[ACTION:CARD:') !== false, 'literal ID token becomes a card');
$literal_ok = false;
if (preg_match_all('/\[ACTION:CARD:([^\]]+)\]/', $out_literal, $literal_cards)) {
    foreach ($literal_cards[1] as $payload) {
        $d = Xabia_Action_Card::decode((string) $payload);
        if (is_array($d) && ($d['a'] ?? '') === 'CART:880' && stripos((string) ($d['t'] ?? ''), 'Propolium') !== false) {
            $literal_ok = true;
        }
    }
}
assert($literal_ok, 'literal ID token resolves Propolium to 880');

$missing = "En saludavida.com te recomiendo:\nPropolium Gola Spray: Este spray es ideal para la garganta.\n[ACTION:CART:ID:Propolium Gola Spray]";
$out_missing = xabia_woo_maybe_append_cart_actions($missing, '', 'tienes propóleo');
assert(strpos($out_missing, '[ACTION:CART:ID:') === false, 'unresolved literal ID token is not left raw');
assert(strpos($out_missing, '[ACTION:CARD:') !== false, 'unresolved literal ID token still becomes a card');
assert(strpos($out_missing, 'Propolium Gola Spray: Este spray') === false, 'title sentence is folded into the card');
$missing_card = null;
if (preg_match('/\[ACTION:CARD:([^\]]+)\]/', $out_missing, $mm)) {
    $missing_card = Xabia_Action_Card::decode((string) $mm[1]);
}
assert(is_array($missing_card) && stripos((string) $missing_card['t'], 'Propolium') !== false, 'fallback card keeps the title');
assert((string) ($missing_card['d'] ?? '') !== '', 'fallback card keeps the sentence');
assert((string) ($missing_card['a'] ?? '') === '', 'fallback card does not invent a cart id');

echo "OK xabia-woo cart append cards\n";
