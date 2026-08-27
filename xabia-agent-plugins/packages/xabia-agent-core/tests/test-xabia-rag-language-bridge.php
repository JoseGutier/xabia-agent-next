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

assert(
    Xabia_Rag_Language_Bridge::tokens_soft_match('urbano', 'SUBCATEGORÍAS: Experiencias urbanas | Partybike'),
    'urbano soft-matches urbanas via shared prefix'
);
$urban_needles = Xabia_Rag_Language_Bridge::expand_keyword_needles(['urbano'], 'entorno urbano');
assert(in_array('urbanas', $urban_needles, true), 'romance inflection adds urbanas from urbano');
assert(in_array('urbana', $urban_needles, true), 'romance inflection adds urbana from urbano');
$entorno_flex = Xabia_Rag_Language_Bridge::romance_inflection_variants('entorno');
assert($entorno_flex === [] || !in_array('entorna', $entorno_flex, true), 'entorno must not invent entorna');
assert(
    Xabia_Rag_Language_Bridge::tokens_soft_match('bicicleta', 'alquiler de bicicletas electricas'),
    'bicicleta soft-matches bicicletas'
);
assert(
    !Xabia_Rag_Language_Bridge::tokens_soft_match('surf', 'naturaleza montana senderismo'),
    'short unrelated tokens do not false-positive'
);
assert(
    !Xabia_Rag_Language_Bridge::tokens_soft_match('vela', 'servicio de velatorio municipal'),
    'vela must not soft-match velatorio (length guard)'
);
assert(
    Xabia_Rag_Language_Bridge::tokens_soft_match('vela', 'alquiler de velas y kayaks'),
    'vela still matches plural velas'
);

require_once dirname(__DIR__) . '/core/class-xabia-rag-hybrid-ranker.php';
$fused = Xabia_Rag_Hybrid_Ranker::rrf_fuse([
    [
        ['id' => 'a', 'content' => 'chunk A', 'score' => 0.9],
        ['id' => 'b', 'content' => 'chunk B', 'score' => 0.8],
    ],
    [
        ['id' => 'b', 'content' => 'chunk B', 'score' => 0.95],
        ['id' => 'c', 'content' => 'chunk C', 'score' => 0.7],
    ],
], 60, 5);
assert(count($fused) === 3, 'rrf returns three unique ids');
assert($fused[0]['id'] === 'b', 'rrf prefers id present in both lists');

$weighted = Xabia_Rag_Hybrid_Ranker::rrf_fuse_weighted(
    [
        ['id' => 'vec', 'content' => 'semantic hit', 'score' => 0.99],
        ['id' => 'both', 'content' => 'both', 'score' => 0.8],
    ],
    [
        ['id' => 'lex', 'content' => 'lexical only', 'score' => 0.99],
        ['id' => 'both', 'content' => 'both', 'score' => 0.9],
    ],
    60,
    5,
    1.0,
    0.4
);
assert($weighted[0]['id'] === 'both' || $weighted[0]['id'] === 'vec', 'weighted RRF elevates vector channel');
assert($weighted[0]['id'] !== 'lex', 'pure lexical rank-1 should not beat vector under 0.4 weight');

$prio_hits = [
    ['id' => 'txosna', 'content' => "Hora: 20:00\nLugar: Txosna menor\nActividad: DJ local", 'score' => 0.90, 'rrf' => 0.03],
    ['id' => 'main', 'content' => "Hora: 23:30\nLugar: Auditorio Central\nIntérprete: Headliner\n[Prioridad: Alta]", 'score' => 0.50, 'rrf' => 0.02],
];
$boosted = Xabia_Rag_Hybrid_Ranker::apply_priority_boost($prio_hits, 0.20, ['Auditorio Central']);
assert($boosted[0]['id'] === 'main', 'priority boost elevates main stage over secondary');
assert(!empty($boosted[0]['priority_boost']), 'priority flag set');
assert(Xabia_Rag_Hybrid_Ranker::chunk_has_priority_signal($prio_hits[1]['content'], []), 'marker alone is enough');
assert(
    Xabia_Rag_Hybrid_Ranker::chunk_has_priority_signal("Lugar: Plaza Mayor\nActividad: Orquesta", ['Plaza Mayor']),
    'priority_venues match Lugar without ingest tag'
);
$norm = Xabia_Rag_Hybrid_Ranker::normalize_priority_venues("Plaza Mayor\nAuditorio\n");
assert(count($norm) === 2, 'normalize priority venues from multiline');

assert(Xabia_Rag_Hybrid_Ranker::VECTOR_WEIGHT === 0.7, 'vector weight 70%');
assert(Xabia_Rag_Hybrid_Ranker::LEXICAL_WEIGHT === 0.3, 'lexical weight 30%');

$div = Xabia_Rag_Hybrid_Ranker::diversify_catalog_top_k([
    ['id' => 'a1', 'ente_id' => 'venue-a', 'content' => 'chunk A1 about concert', 'score' => 0.9],
    ['id' => 'a2', 'ente_id' => 'venue-a', 'content' => 'chunk A2 variant concert', 'score' => 0.8],
    ['id' => 'a3', 'ente_id' => 'venue-a', 'content' => 'chunk A3 should drop', 'score' => 0.7],
    ['id' => 'b1', 'ente_id' => 'venue-b', 'content' => 'chunk B1 main stage', 'score' => 0.6],
], 10, 2);
assert(count($div) === 3, 'diversify keeps max 2 per ente + others');
$venue_a = 0;
foreach ($div as $row) {
    if (($row['ente_id'] ?? '') === 'venue-a') {
        ++$venue_a;
    }
}
assert($venue_a === 2, 'venue-a capped at 2');

require_once dirname(__DIR__) . '/core/class-xabia-rag-chunk-enricher.php';
$prio_enriched = Xabia_Rag_Chunk_Enricher::enrich(
    "EMPRESA: Headliner\nLugar: Plaza Mayor\nHora: 22:00",
    ['lugar' => 'Plaza Mayor', 'nombre' => 'Headliner'],
    [['csv_col' => 'lugar', 'label' => 'Lugar'], ['csv_col' => 'nombre', 'label' => 'Nombre']],
    ['project_config' => ['rules' => [
        'rag_chunk_enrichment' => 'on',
        'priority_venues' => ['Plaza Mayor'],
    ]]]
);
assert(strpos($prio_enriched, '[Prioridad: Alta]') !== false, 'enricher tags priority venue');
assert(strpos($prio_enriched, '[Tipo: Escenario Principal]') !== false, 'enricher tags main stage type');

$enriched = Xabia_Rag_Chunk_Enricher::enrich(
    'EMPRESA: Demo | CATEGORÍA: Otro tipo',
    [
        'empresa' => 'Demo Co',
        'categoria' => 'Otro tipo',
        'subcategoria_01' => 'Experiencias urbanas',
        'empresa_localizacion' => 'Bilbao',
    ],
    [],
    ['project_config' => ['rules' => ['rag_chunk_enrichment' => 'on']]]
);
assert(strpos($enriched, '[Entidad:') !== false, 'enricher adds Entidad prefix');
assert(strpos($enriched, '[Clasificación:') !== false, 'enricher adds Clasificación prefix');
assert(strpos($enriched, 'Experiencias urbanas') !== false, 'enricher keeps taxonomy values');
assert(strpos($enriched, '[KEYWORDS:') !== false, 'enricher adds KEYWORDS from taxonomy labels');

$skip = Xabia_Rag_Chunk_Enricher::enrich(
    'EMPRESA: Demo',
    ['empresa' => 'Demo'],
    [],
    ['project_config' => ['rules' => ['rag_chunk_enrichment' => 'off']]]
);
assert($skip === 'EMPRESA: Demo' || strpos($skip, '[Entidad:') === false, 'enricher respects off flag');

require_once dirname(__DIR__) . '/core/class-xabia-rag-query-rewriter.php';
$prep = Xabia_Rag_Query_Rewriter::prepare(
    'actividades entorno urbano',
    '',
    ['rules' => ['rag_query_rewrite' => 'off']],
    null
);
assert($prep['embed_text'] !== '', 'rewriter fail-open keeps base embed text');
assert($prep['rewritten'] === false, 'rewriter off does not claim rewrite');
assert(strpos($prep['embed_text'], 'urbanas') !== false, 'criterion flexions boosted via language bridge');
assert(!preg_match('/\bentorna\b/u', $prep['embed_text']), 'generic hyperonym noise dropped from embed');
assert(in_array('urbanas', $prep['needles'], true) || in_array('urbano', $prep['needles'], true), 'needles keep criterion tokens');
assert(!in_array('entorno', $prep['needles'], true), 'needles drop generic hyperonym entorno');
assert(
    !Xabia_Rag_Query_Rewriter::prepare(
        'actividades entorno urbano',
        '',
        ['rules' => ['rag_query_rewrite' => 'on']],
        static function () {
            return 'Error API (proxy): Sin mensajes user/assistant válidos para Gemini';
        }
    )['rewritten'],
    'transport error must not count as rewrite'
);
$poisoned = Xabia_Rag_Query_Rewriter::prepare(
    'actividades entorno urbano',
    '',
    ['rules' => ['rag_query_rewrite' => 'on']],
    static function () {
        return 'Error API (proxy): Sin mensajes user/assistant válidos para Gemini';
    }
);
assert(strpos($poisoned['embed_text'], 'Error API') === false, 'transport error must not enter embed');

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
