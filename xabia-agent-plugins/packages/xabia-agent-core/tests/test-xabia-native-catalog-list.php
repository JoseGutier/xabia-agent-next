<?php
/**
 * Tests agnósticos del destilado de listado nativo (sin vocabulario de vertical).
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($t) {
        return strip_tags((string) $t);
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        if ($tag === 'xabia_catalog_activity_alias_map') {
            return $GLOBALS['xabia_test_alias_map'] ?? $value;
        }
        return $value;
    }
}
if (!class_exists('Xabia_Catalog_Token_Match', false)) {
    require_once dirname(__DIR__) . '/core/class-xabia-catalog-token-match.php';
}

require_once dirname(__DIR__) . '/core/class-xabia-catalog-list.php';

// Sin alias de catálogo: no se destila actividad heurística (flujo RAG).
assert(
    Xabia_Catalog_List::distill_canonical_activity('que empresas hacen experiencias de vela?', '') === '',
    'no heuristic distill without alias map'
);
$GLOBALS['xabia_test_alias_map'] = ['vela' => 'vela'];
assert(
    Xabia_Catalog_List::distill_canonical_activity('que empresas hacen experiencias de vela?', '') === 'vela',
    'whole-word alias map distill'
);
unset($GLOBALS['xabia_test_alias_map']);
assert(
    Xabia_Catalog_List::distill_canonical_activity('hola', '') === '',
    'greeting alone yields empty'
);

echo "OK test-xabia-native-catalog-list\n";
