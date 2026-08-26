<?php
/**
 * One-shot: amplia content_hash a VARCHAR(64) en Hub (SHA-256).
 * Uso: php bin/migrate-018-content-hash.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

$pdo = XabiaCentral\Db::pdo();
$tables = ['xabia_knowledge_vectors', 'xabia_knowledge_store'];
foreach ($tables as $table) {
    $st = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
    if ($st === false || $st->fetch() === false) {
        echo "SKIP {$table} (no existe)\n";
        continue;
    }
    $pdo->exec(
        "ALTER TABLE `{$table}` MODIFY COLUMN content_hash VARCHAR(64) NULL DEFAULT NULL"
    );
    $col = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'content_hash'");
    $info = $col ? $col->fetch(PDO::FETCH_ASSOC) : [];
    echo "OK {$table} Type=" . (string) ($info['Type'] ?? '?') . "\n";
}

echo "DONE\n";
