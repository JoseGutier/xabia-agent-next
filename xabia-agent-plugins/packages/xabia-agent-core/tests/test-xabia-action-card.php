<?php
/**
 * CLI: php packages/xabia-agent-core/tests/test-xabia-action-card.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s) {
        return strip_tags((string) $s);
    }
}

require_once dirname(__DIR__) . '/core/class-xabia-action-card.php';

$tag = Xabia_Action_Card::encode([
    't' => 'VENALIGHT PLUS botilak',
    'm' => 'Circulación · En stock',
    'p' => '24.90 €',
    'i' => 'https://example.com/img.jpg',
    'a' => 'CART:101',
    'l' => 'Comprar ahora',
]);
assert(str_starts_with($tag, '[ACTION:CARD:') && str_ends_with($tag, ']'), 'tag shape');
$raw = substr($tag, strlen('[ACTION:CARD:'), -1);
$decoded = Xabia_Action_Card::decode($raw);
assert(is_array($decoded), 'decode ok');
assert($decoded['t'] === 'VENALIGHT PLUS botilak', 'title');
assert($decoded['a'] === 'CART:101', 'action');
assert($decoded['i'] === 'https://example.com/img.jpg', 'img');

$bad = Xabia_Action_Card::encode(['t' => '']);
assert($bad === '', 'empty title rejected');

echo "OK xabia action card\n";
