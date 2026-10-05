<?php
/**
 * Tests del parser del Intérprete RAG (sin LLM).
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text) {
        return strip_tags((string) $text);
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags((string) $str));
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args) {
        return $value;
    }
}
if (!function_exists('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

require_once dirname(__DIR__) . '/core/class-xabia-rag-language-bridge.php';
require_once dirname(__DIR__) . '/core/class-xabia-rag-query-rewriter.php';

$parsed = Xabia_Rag_Query_Rewriter::parse_interpreter_output(
    'ekitaldi balmaseda balmasedan entidad_normalizada: Balmaseda'
);
assert($parsed['keywords'] === 'ekitaldi balmaseda balmasedan', 'keywords stripped entity tag');
assert($parsed['canonical_entities'] === ['Balmaseda'], 'canonical entity parsed');

$parsed2 = Xabia_Rag_Query_Rewriter::parse_interpreter_output('eventos valmaseda entidad_normalizada: Balmaseda, BALMASEDA');
assert(count($parsed2['canonical_entities']) === 2, 'multiple canonical entities');

$prep = Xabia_Rag_Query_Rewriter::prepare(
    'Zer ekitaldi daude Balmasdan?',
    '',
    ['rules' => ['rag_query_rewrite' => 'on']],
    static function () {
        return 'ekitaldi balmaseda balmasedan entidad_normalizada: Balmaseda';
    }
);
assert(in_array('balmaseda', $prep['needles'], true) || in_array('Balmaseda', $prep['needles'], true), 'canonical feeds needles');
assert(strpos($prep['embed_text'], 'Balmaseda') !== false, 'canonical feeds embed');
assert(strpos($prep['lexical_text'], 'Balmaseda') !== false, 'canonical feeds lexical');
assert($prep['canonical_entities'] === ['Balmaseda'], 'canonical_entities returned');

assert(
    Xabia_Rag_Query_Rewriter::expansion_drops_query_tokens('infantil surf parque', 'norte lodge'),
    'expansion without the original name tokens is dropped'
);
assert(
    !Xabia_Rag_Query_Rewriter::expansion_drops_query_tokens('norte lodge kayak', 'norte lodge'),
    'expansion that keeps the name is kept'
);

$prep_name = Xabia_Rag_Query_Rewriter::prepare(
    'norte lodge?',
    '',
    ['rules' => ['rag_query_rewrite' => 'on']],
    static function () {
        return 'infantil surf parque aventura';
    }
);
assert(strpos(mb_strtolower($prep_name['embed_text'], 'UTF-8'), 'infantil') === false, 'bad kids expansion is ignored');
assert(strpos(mb_strtolower($prep_name['lexical_text'], 'UTF-8'), 'norte') !== false, 'original name stays in lexical');

assert(Xabia_Rag_Query_Rewriter::should_invoke_llm(['rules' => ['rag_query_rewrite' => 'on']], false) === false, 'first turn skips rewrite');
assert(Xabia_Rag_Query_Rewriter::should_invoke_llm(['rules' => ['rag_query_rewrite' => 'on']], true) === true, 'follow-up may rewrite');
assert(Xabia_Rag_Query_Rewriter::should_invoke_llm(['rules' => ['rag_query_rewrite' => 'off']], true) === false, 'rule off skips rewrite');
assert(Xabia_Rag_Query_Rewriter::history_has_prior_turns([]) === false, 'empty history is a first turn');
assert(Xabia_Rag_Query_Rewriter::history_has_prior_turns([
    ['role' => 'user', 'content' => 'hola'],
    ['role' => 'assistant', 'content' => 'buenas'],
]) === true, 'prior user turn counts as history');
$rewriteStarted = microtime(true);
assert(Xabia_Rag_Query_Rewriter::should_invoke_llm(['rules' => ['rag_query_rewrite' => 'on']], false) === false, 'turn 1 never rewrites');
$rewriteMs = (microtime(true) - $rewriteStarted) * 1000;
assert($rewriteMs < 10, 'turn 1 rewrite decision stays under 10ms, got ' . $rewriteMs);

$continue = 'Continúa exactamente desde donde lo dejaste, sin repetir lo anterior.';
assert(Xabia_Rag_Query_Rewriter::is_internal_instruction($continue) === true, 'continue sentence is internal');
assert(Xabia_Rag_Query_Rewriter::should_invoke_llm(['rules' => ['rag_query_rewrite' => 'on']], true, $continue) === false, 'continue skips rewrite');
$called = false;
$contStarted = microtime(true);
$prep = Xabia_Rag_Query_Rewriter::prepare($continue, 'Empresas de veleros?', ['rules' => ['rag_query_rewrite' => 'on']], static function () use (&$called) {
    $called = true;
    return 'should-not-run';
});
$contMs = (microtime(true) - $contStarted) * 1000;
assert($called === false, 'continue does not call the rewrite model');
assert($prep['rewritten'] === false, 'continue is not rewritten');
assert($contMs < 10, 'continue rewrite stays under 10ms, got ' . $contMs);
assert(Xabia_Rag_Query_Rewriter::should_invoke_llm(['rules' => ['rag_query_rewrite' => 'on']], true, 'Empresas de veleros?') === true, 'a visitor question may still rewrite');

echo "OK xabia-rag-query-rewriter tests\n";
