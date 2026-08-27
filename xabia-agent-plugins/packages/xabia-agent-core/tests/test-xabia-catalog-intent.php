<?php
/**
 * Tests de intención de catálogo y keywords de taxonomía (sin diccionarios de dominio).
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

require_once dirname(__DIR__) . '/core/class-xabia-catalog-intent.php';
require_once dirname(__DIR__) . '/core/class-xabia-rag-chunk-enricher.php';
require_once dirname(__DIR__) . '/core/class-xabia-brain.php';

$listing = [
    'alguna empresa con la que hacer excursiones a caballo',
    'dónde puedo hacer kayak',
    'busco hacer paddle surf',
    'quién ofrece rutas en globo',
    'qué empresas hay de surf',
    'recomiéndame una actividad de montaña',
    'where can I find a company',
    'non egin surf',
    'haceis excursiones a caballo?',
    '¿hacéis excursiones a caballo?',
    'tenéis hípica?',
    'hay opciones de kayak',
    'estoy buscando empresas de surf',
    'me recomiendas una actividad',
    'recomendacion de paseos',
];
foreach ($listing as $q) {
    assert(
        Xabia_Catalog_Intent::is_listing_query($q),
        'should detect catalog listing: ' . $q
    );
}

$not_listing = [
    'hola, cómo estás',
    'qué es aktiba',
    'quiero ir al cine',
    'alguna foto de esa empresa',
    'cuál es tu nombre',
];
foreach ($not_listing as $q) {
    assert(
        !Xabia_Catalog_Intent::is_listing_query($q),
        'should not detect catalog listing: ' . $q
    );
}

$limit = Xabia_Catalog_Intent::rag_chunk_limit(4);
assert($limit >= 15 && $limit <= 25, 'catalog intent floor is 15–25, got ' . $limit);
assert($limit === 20, 'default regex floor is 20');

$sem = Xabia_Catalog_Intent::rag_chunk_limit(4, 'llm');
assert($sem === 15, 'semantic floor is 15, got ' . $sem);

$temporal_qs = [
    'que conciertos hay esta noche?',
    'qué hay hoy',
    'eventos mañana',
    'qué planes hay este finde',
];
foreach ($temporal_qs as $q) {
    assert(Xabia_Catalog_Intent::is_temporal_query($q), 'should detect temporal: ' . $q);
    $r = Xabia_Catalog_Intent::resolve($q, []);
    assert($r['hit'] === true && ($r['kind'] ?? '') === 'temporal', 'temporal resolve: ' . $q);
}
$tlimit = Xabia_Catalog_Intent::rag_chunk_limit(4, 'temporal');
assert($tlimit === 25, 'temporal floor is 25, got ' . $tlimit);

assert(!Xabia_Catalog_Intent::is_temporal_query('hoy estoy bien'), 'casual hoy should not be temporal');

$exp = Xabia_Catalog_Intent::expand_lexical_query_text('que conciertos hay esta noche');
assert(stripos($exp, 'actuacion') !== false || stripos($exp, 'actuación') !== false, 'expand concierto → actuación');
assert(stripos($exp, 'musica') !== false || stripos($exp, 'música') !== false, 'expand concierto → música');
$ft_concierto = Xabia_Brain::build_fulltext_boolean_query('conciertos esta noche', []);
assert(strpos($ft_concierto, '+(') !== false, 'FT uses OR group for semantic field');
assert(stripos($ft_concierto, 'actuacion') !== false || stripos($ft_concierto, 'recital') !== false, 'FT group includes synonyms');

// Capa 2: micro-LLM fallback (mock) — frase que no pilla la regex.
$paraphrase = 'quiero ir al trote por el monte';
assert(
    !Xabia_Catalog_Intent::is_listing_query($paraphrase),
    'paraphrase should miss regex fast-path'
);
$resolved = Xabia_Catalog_Intent::resolve($paraphrase, [
    'config' => ['rules' => ['catalog_intent_micro_llm' => true]],
    'llm_classify' => static function () {
        return 'CATALOG';
    },
]);
assert($resolved['hit'] === true, 'micro-LLM CATALOG should hit');
assert($resolved['source'] === 'llm', 'source should be llm');

$general = Xabia_Catalog_Intent::resolve('hola qué tal', [
    'config' => ['rules' => ['catalog_intent_micro_llm' => true]],
    'llm_classify' => static function () {
        return 'GENERAL';
    },
]);
assert($general['hit'] === false, 'micro-LLM GENERAL should not hit');
assert(in_array($general['source'], ['llm', 'none'], true), 'general source ok');

assert(Xabia_Catalog_Intent::parse_router_label('catalog') === 'CATALOG', 'parse catalog');
assert(Xabia_Catalog_Intent::parse_router_label('GENERAL.') === 'GENERAL', 'parse general');
assert(Xabia_Catalog_Intent::parse_router_label('maybe') === null, 'parse unknown');

$enriched = Xabia_Rag_Chunk_Enricher::enrich(
    'EMPRESA: Club Demo | CATEGORÍA: Hípica',
    [
        'empresa' => 'Club Demo',
        'categoria' => 'Hípica',
        'subcategoria_01' => 'Rutas y Excursiones guiadas',
    ],
    [],
    ['project_config' => ['rules' => ['rag_chunk_enrichment' => 'on']]]
);
assert(strpos($enriched, '[Clasificación:') !== false, 'classification prefix present');
assert(strpos($enriched, '[KEYWORDS:') !== false, 'keywords prefix present');
assert(
    (bool) preg_match('/hipica/i', $enriched),
    'folded taxonomy label hipica is indexed without synonym dictionaries'
);
assert(strpos($enriched, 'caballo') === false, 'enricher must not invent caballo from hípica');

$tokens = Xabia_Rag_Chunk_Enricher::taxonomy_keyword_tokens(['Hípica', 'Rutas y Excursiones guiadas']);
$lower = array_map('strtolower', $tokens);
assert(in_array('hipica', $lower, true), 'keyword tokens include folded hipica');
assert(!in_array('caballo', $lower, true), 'keyword tokens do not add domain synonyms');

echo "OK test-xabia-catalog-intent.php\n";
