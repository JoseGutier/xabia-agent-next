<?php
/**
 * CLI: php packages/xabia-agent-core/tests/test-xabia-catalog-native-synonyms.php
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        $title = strtolower((string) $title);
        $title = preg_replace('/[^a-z0-9\s-]/', '', $title) ?? $title;
        $title = preg_replace('/[\s_]+/', '-', trim($title)) ?? $title;

        return $title;
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s) {
        return strip_tags((string) $s);
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
}
if (!function_exists('__')) {
    function __($s) {
        return (string) $s;
    }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) {
        $url = trim((string) $url);
        return preg_match('#^https?://#i', $url) ? $url : '';
    }
}
if (!class_exists('WP_Term')) {
    class WP_Term {
        public $term_id;
        public $name;
        public $slug;
        public function __construct($id, $name, $slug) {
            $this->term_id = (int) $id;
            $this->name = (string) $name;
            $this->slug = (string) $slug;
        }
    }
}
if (!function_exists('taxonomy_exists')) {
    function taxonomy_exists($tax) {
        return $tax === 'categoria-de-actividad';
    }
}
if (!function_exists('get_terms')) {
    function get_terms($args) {
        return [
            new WP_Term(1208, 'Hípica', 'hipica'),
        ];
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($v) {
        return false;
    }
}
if (!function_exists('get_term_by')) {
    function get_term_by($field, $value, $taxonomy) {
        unset($field);
        if ($taxonomy !== 'categoria-de-actividad') {
            return null;
        }
        $v = is_string($value) ? mb_strtolower($value, 'UTF-8') : '';
        if ($v === 'hipica' || $v === 'hípica' || $value === 'Hípica') {
            return new WP_Term(1208, 'Hípica', 'hipica');
        }

        return null;
    }
}
if (!function_exists('get_term_children')) {
    function get_term_children($parent, $taxonomy) {
        unset($parent, $taxonomy);
        return [];
    }
}
if (!class_exists('Xabia_Knowledge_Ingest', false)) {
    class Xabia_Knowledge_Ingest {
        public static function resolve_catalog_activity_taxonomy(array $config): string {
            unset($config);
            return 'categoria-de-actividad';
        }

        public static function localize_taxonomy_term_label(string $label): string {
            return $label;
        }
    }
}

require_once dirname(__DIR__) . '/core/class-xabia-catalog-token-match.php';
require_once dirname(__DIR__) . '/core/class-xabia-catalog-list.php';
require_once dirname(__DIR__) . '/core/class-xabia-action-card.php';

$config = [
    'rules' => [
        'keyword_expansions' => [
            'hípica' => 'hípica|caballo|montar a caballo',
        ],
    ],
];

$entities = [
    ['ID' => 17658, 'empresa' => 'Club Hípico Castillo de Butrón', 'localidad' => 'Barrio Butrón, 42, Gatika, Bizkaia', 'categoria' => 'Tierra'],
    ['ID' => 17659, 'empresa' => 'Cumbre Guías de Montaña', 'localidad' => 'Ortuella, Bizkaia', 'categoria' => 'Tierra'],
];

$queries = [
    '¿Qué empresas hay para hacer hípica?'      => 'explicit hípica',
    '¿Hay alguna empresa para montar a caballo?' => 'admin synonym caballo',
];

foreach ($queries as $msg => $label) {
    $match = Xabia_Catalog_List::resolve_native_catalog_term_match($config, $msg, '');
    assert(in_array(1208, $match['term_ids'], true), $label . ': term 1208');
    assert(str_contains((string) $match['matched_term'], 'Hípica'), $label . ': matched label');

    $body = Xabia_Catalog_List::build_native_catalog_message_body($entities, 'Hípica', 'Aktiba');
    assert(substr_count($body, '• **') === 2, $label . ': two bullets');

    $badge = 'Hípica';
    $cards = [];
    foreach ($entities as $row) {
        $cards[] = Xabia_Action_Card::encode([
            'id'          => (int) $row['ID'],
            'title'       => $row['empresa'],
            'badge'       => $badge,
            'meta'        => $row['localidad'],
            'permalink'   => 'https://aktiba.eus/empresa/' . (int) $row['ID'] . '/',
            'action_type' => 'view',
            'label'       => 'Ver ficha',
        ], ['source' => 'native_catalog', 'project_id' => 'aktiba-xabia']);
    }
    assert(count($cards) === 2, $label . ': two cards');
    foreach ($cards as $tag) {
        if (!preg_match('/\[ACTION:CARD:([^\]]+)\]/u', (string) $tag, $m)) {
            continue;
        }
        $decoded = Xabia_Action_Card::decode((string) ($m[1] ?? ''));
        assert(is_array($decoded) && ($decoded['b'] ?? '') === 'Hípica', $label . ': card badge Hípica not parent taxonomy');
        assert(($decoded['b'] ?? '') !== 'Tierra', $label . ': badge must not be parent Tierra');
    }
}

echo "OK test-xabia-catalog-native-synonyms\n";
