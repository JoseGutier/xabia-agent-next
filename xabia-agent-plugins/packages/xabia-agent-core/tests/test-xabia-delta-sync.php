<?php
/**
 * Delta Sync SHA-256 + sanitizado FULLTEXT BOOLEAN MODE (sin WordPress Test Suite).
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
}

require_once dirname(__DIR__) . '/core/class-xabia-knowledge-optimizer.php';
require_once dirname(__DIR__) . '/core/class-xabia-db.php';
require_once dirname(__DIR__) . '/core/class-xabia-brain.php';

$chunk = "Club Hípico Castillo\n";
$trim = trim($chunk);
$sha = hash('sha256', $trim);
$md5_raw = md5($chunk);
$md5_trim = md5($trim);

assert(strlen($sha) === 64, 'sha256 hex length is 64');
assert(Xabia_Knowledge_Optimizer::content_hash($chunk) === $sha, 'optimizer uses sha256(trim)');
assert(Xabia_Knowledge_Optimizer::content_hash($chunk) !== $md5_raw, 'optimizer no longer uses md5');
assert(Xabia_DB::compute_content_hash($chunk) === $sha, 'db compute_content_hash delegates to optimizer');
assert(Xabia_DB::content_hash_matches($sha, $chunk), 'sha256 matches');
assert(Xabia_DB::content_hash_matches($md5_raw, $chunk), 'legacy md5(raw) still matches');
assert(Xabia_DB::content_hash_matches($md5_trim, $chunk), 'legacy md5(trim) still matches');
assert(!Xabia_DB::content_hash_matches($sha, $trim . ' changed'), 'different text does not match');

$q = Xabia_Brain::build_fulltext_boolean_query('excursiones a caballo +"DROP TABLE"', ['excursiones', 'caballo']);
assert(strpos($q, '"') === false, 'boolean query strips quotes');
assert(strpos($q, '+excursiones*') !== false, 'boolean query requires excursiones prefix');
assert(strpos($q, '+caballo*') !== false, 'boolean query requires caballo prefix');
assert(strpos($q, 'excursionesacaballo') === false, 'compound phrase is not glued into one FT token');
assert(!preg_match('/(?<![A-Za-z])a\*/', $q), 'two-letter tokens are omitted');

$ops = Xabia_Brain::build_fulltext_boolean_query('++foo --bar ~baz @user (x)', []);
assert(strpos($ops, '+foo*') !== false, 'foo kept after stripping operators');
assert(strpos($ops, '--') === false, 'boolean minus operators stripped');
assert(strpos($ops, '~') === false, 'tilde operator stripped');
assert(strpos($ops, '@') === false, 'against @ operator stripped');

$empty = Xabia_Brain::build_fulltext_boolean_query('sí no', []);
assert($empty === '', 'short tokens yield empty boolean query (LIKE fallback)');

echo "OK test-xabia-delta-sync.php\n";
