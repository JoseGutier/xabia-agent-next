<?php
/**
 * Tests mínimos de clasificación de outcome analítica.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
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
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $key));
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags((string) $str));
    }
}
if (!function_exists('__')) {
    function __($s) {
        return $s;
    }
}

require_once dirname(__DIR__) . '/core/class-xabia-analytics.php';

assert(
    Xabia_Analytics::classify_outcome('Las EOJ empiezan el 26 de septiembre.', true, 'Fecha: 2026-09-26') === Xabia_Analytics::OUTCOME_COMPLETE,
    'complete with rag'
);
assert(
    Xabia_Analytics::classify_outcome('Barkatu, baina ez daukat informaziorik nire datuetan.', false, 'SYSTEM_NOTE: La búsqueda no arrojó resultados') === Xabia_Analytics::OUTCOME_NO_INFO,
    'basque no_info'
);
assert(
    Xabia_Analytics::classify_outcome('No tengo información sobre eso en mis datos.', false, '') === Xabia_Analytics::OUTCOME_NO_INFO,
    'spanish no_info'
);
assert(
    Xabia_Analytics::classify_outcome('Error API: timeout', false, '') === Xabia_Analytics::OUTCOME_ERROR,
    'error outcome'
);

echo "OK test-xabia-analytics-outcome.php\n";
