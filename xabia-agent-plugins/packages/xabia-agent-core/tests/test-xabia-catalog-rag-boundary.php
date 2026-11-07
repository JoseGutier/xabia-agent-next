<?php
/**
 * CLI: php packages/xabia-agent-core/tests/test-xabia-catalog-rag-boundary.php
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        $title = strtolower((string) $title);
        $title = preg_replace('/[^a-z0-9\s-]/', '', $title) ?? $title;
        return preg_replace('/[\s_]+/', '-', trim($title)) ?? $title;
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s) {
        return strip_tags((string) $s);
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

require_once dirname(__DIR__) . '/core/class-xabia-catalog-token-match.php';
require_once dirname(__DIR__) . '/core/class-xabia-catalog-list.php';

$msg_caballo = '¿Hay alguna empresa para montar a caballo?';
$msg_parapente = 'empresas de parapente en bizkaia';

assert(
    !Xabia_Catalog_Token_Match::message_contains_whole_term($msg_caballo, 'parapente'),
    'caballo query must not whole-match parapente'
);
assert(
    !Xabia_Catalog_Token_Match::message_contains_whole_term('parapente', 'para'),
    'para must not match inside parapente (no inverted substring)'
);
assert(
    Xabia_Catalog_Token_Match::message_contains_whole_term($msg_caballo, 'caballo'),
    'caballo is a whole-word token in conversational query'
);
assert(
    Xabia_Catalog_Token_Match::message_contains_whole_term($msg_parapente, 'parapente'),
    'explicit category word matches whole term'
);

$api_src = file_get_contents(dirname(__DIR__) . '/api/class-xabia-api.php');
assert(
    strpos($api_src, "'para', 'con', 'sin'") === false,
    'no hardcoded stopword arrays in API catalog path'
);
assert(
    strpos($api_src, '[XABIA_CATALOG_SUCCESS] Term:') !== false,
    'native catalog success audit log present'
);

$GLOBALS['xabia_test_terms'] = [
    (object) ['term_id' => 1208, 'name' => 'Hípica', 'slug' => 'hipica'],
    (object) ['term_id' => 1210, 'name' => 'Parapente', 'slug' => 'parapente'],
];
if (!function_exists('taxonomy_exists')) {
    function taxonomy_exists($tax) {
        return $tax === 'categoria-de-actividad';
    }
}
if (!function_exists('get_terms')) {
    function get_terms($args) {
        return $GLOBALS['xabia_test_terms'] ?? [];
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return false;
    }
}
if (!class_exists('WP_Term')) {
    class WP_Term {
        public $term_id;
        public $name;
        public $slug;
        public function __construct($id, $name, $slug) {
            $this->term_id = $id;
            $this->name = $name;
            $this->slug = $slug;
        }
    }
    $GLOBALS['xabia_test_terms'] = [
        new WP_Term(1208, 'Hípica', 'hipica'),
        new WP_Term(1210, 'Parapente', 'parapente'),
    ];
}
if (!class_exists('Xabia_Knowledge_Ingest', false)) {
    class Xabia_Knowledge_Ingest {
        public static function resolve_catalog_activity_taxonomy(array $config): string {
            return 'categoria-de-actividad';
        }
    }
}

$config = ['catalog_post_type' => 'empresa'];

$match_caballo_bare = Xabia_Catalog_List::resolve_native_catalog_term_match($config, $msg_caballo, '');
assert($match_caballo_bare['term_ids'] === [], 'caballo without admin synonyms stays off native');

$config_syn = [
    'catalog_post_type' => 'empresa',
    'rules'             => [
        'keyword_expansions' => [
            'hípica' => 'hípica|caballo|equitación',
        ],
    ],
];
if (!function_exists('get_term_by')) {
    function get_term_by($field, $value, $taxonomy) {
        unset($field);
        if ($taxonomy !== 'categoria-de-actividad') {
            return null;
        }
        $needle = is_string($value) ? mb_strtolower($value, 'UTF-8') : '';
        if ($needle === 'hipica' || $needle === 'hípica' || $value === 'Hípica') {
            return new WP_Term(1208, 'Hípica', 'hipica');
        }

        return null;
    }
}
if (!function_exists('get_term_children')) {
    function get_term_children($parent, $taxonomy) {
        return [];
    }
}

$match_caballo = Xabia_Catalog_List::resolve_native_catalog_term_match($config_syn, $msg_caballo, '');
assert(in_array(1208, $match_caballo['term_ids'], true), 'admin synonym caballo resolves hípica term 1208');
assert(str_contains((string) $match_caballo['matched_term'], 'Hípica'), 'caballo maps to Hípica label');

$match_hipica = Xabia_Catalog_List::resolve_native_catalog_term_match(
    $config,
    '¿Qué empresas de hípica hay?',
    ''
);
assert($match_hipica['matched_term'] !== '', 'explicit taxonomy label triggers native match');
assert(in_array(1208, $match_hipica['term_ids'], true), 'hípica resolves term 1208');

echo "OK test-xabia-catalog-rag-boundary\n";
