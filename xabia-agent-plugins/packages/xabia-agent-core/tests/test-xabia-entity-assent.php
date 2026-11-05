<?php
/**
 * Anclaje de entidad en «Sí» y extracción de casas recomendadas.
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
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text) {
        return strip_tags((string) $text);
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) $title) ?? '');
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('has_action')) {
    function has_action($tag, $callback = false) {
        return false;
    }
}
if (!function_exists('add_action')) {
    function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}
if (!function_exists('remove_action')) {
    function remove_action($tag, $callback, $priority = 10) {
        return true;
    }
}

require_once dirname(__DIR__) . '/api/class-xabia-api.php';

$assistant = <<<'TXT'
Kaixo! Mirentxu a tu servicio.

Claro que sí. Para dos personas, os puedo recomendar:
Casa Garaixe: Un nido diseñado exclusivamente para parejas.

¿Te gustaría ver fotos de esta casita?
TXT;

$name = Xabia_API::extract_primary_entity_name_from_assistant_text($assistant);
assert($name === 'Casa Garaixe', 'lodging recommendation line yields Casa Garaixe, got: ' . $name);

$history = [
    ['role' => 'user', 'content' => '¿Puedes recomendarme una casita para dos personas?'],
    ['role' => 'assistant', 'content' => $assistant],
];
assert(Xabia_API::query_implies_assent_followup('Sí'), 'Sí is assent followup');
assert(Xabia_API::query_implies_assent_to_assistant_offer('Sí', $history), 'Sí after photo invite is utility assent');

$anchor = Xabia_API::resolve_entity_anchor_from_history('Sí', $history, '', 'mirentxu');
assert(stripos($anchor, 'Garaixe') !== false, 'Sí anchors to Garaixe, got: ' . $anchor);

echo "OK test-xabia-entity-assent\n";
