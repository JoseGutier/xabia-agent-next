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
        return $value;
    }
}

require_once dirname(__DIR__) . '/core/class-xabia-catalog-list.php';

// Sin mapa de aliases: toma la palabra significativa más larga de la pregunta.
assert(
    Xabia_Catalog_List::distill_canonical_activity('que empresas hacen experiencias de vela?', '') === 'vela',
    'agnostic distill picks content token vela'
);
assert(
    Xabia_Catalog_List::distill_canonical_activity('which companies offer spa treatments?', '') === 'treatments'
        || Xabia_Catalog_List::distill_canonical_activity('which companies offer spa treatments?', '') === 'spa',
    'agnostic distill works outside tourism vertical'
);
assert(
    Xabia_Catalog_List::distill_canonical_activity('hola', '') === '',
    'greeting alone yields empty'
);

echo "OK test-xabia-native-catalog-list\n";
