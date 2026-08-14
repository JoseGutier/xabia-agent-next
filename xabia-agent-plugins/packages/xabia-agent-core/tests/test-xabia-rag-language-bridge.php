<?php
/**
 * Tests del puente RAG multilingüe (local, sin APIs).
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
        return strtolower(preg_replace('/[^a-z0-9_-]/', '', (string) $key));
    }
}

require_once dirname(__DIR__) . '/core/class-xabia-knowledge-language-driver.php';
require_once dirname(__DIR__) . '/core/class-xabia-rag-language-bridge.php';

$variants = Xabia_Rag_Language_Bridge::token_variants('EOJak');
assert(in_array('eoj', $variants, true), 'EOJak should expand to eoj');

$expanded = Xabia_Rag_Language_Bridge::expand_keyword_needles(['eojak'], 'Noiz hasten dira EOJak?');
assert(in_array('eoj', $expanded, true), 'expand_keyword_needles includes eoj stem');

$ctx = 'Las Jornadas EOJ empiezan el 26 de septiembre.';
assert(
    Xabia_Rag_Language_Bridge::context_contains_term_variant('eojak', $ctx),
    'context match via acronym variant'
);

$collapsed_primary = Xabia_Knowledge_Language_Driver::collapse_to_primary_or_fallback([
    ['ID' => 10, 'post_name' => 'evento-es', 'language_code' => 'es', 'xabia_translation_group' => 'wpml:99'],
    ['ID' => 11, 'post_name' => 'evento-eu', 'language_code' => 'eu', 'xabia_translation_group' => 'wpml:99'],
], 'eu');
assert(count($collapsed_primary) === 1, 'one row per translation group');
assert((int) ($collapsed_primary[0]['ID'] ?? 0) === 11, 'prefer catalog language eu');

$collapsed_fallback = Xabia_Knowledge_Language_Driver::collapse_to_primary_or_fallback([
    ['ID' => 10, 'post_name' => 'evento-es', 'language_code' => 'es', 'xabia_translation_group' => 'wpml:100'],
], 'eu');
assert((int) ($collapsed_fallback[0]['ID'] ?? 0) === 10, 'fallback to published translation in any language');

echo "OK test-xabia-rag-language-bridge.php\n";
