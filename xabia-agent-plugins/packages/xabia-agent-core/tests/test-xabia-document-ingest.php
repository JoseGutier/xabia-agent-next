<?php
/**
 * Tests mínimos del motor Document-to-RAG (sin WordPress).
 */

require_once dirname(__DIR__) . '/core/interface-xabia-database.php';
require_once dirname(__DIR__) . '/core/class-xabia-document-ingest.php';

final class Xabia_Document_Ingest_Test_Adapter implements Xabia_Database_Interface {

    /** @var list<array{op: string, table: string, payload: mixed}> */
    public $log = [];

    public function delete(string $table, array $where) {
        $this->log[] = ['op' => 'delete', 'table' => $table, 'payload' => $where];

        return 1;
    }

    public function insert(string $table, array $data): bool {
        $this->log[] = ['op' => 'insert', 'table' => $table, 'payload' => $data];

        return true;
    }

    public function query(string $sql) {
        $this->log[] = ['op' => 'query', 'table' => '', 'payload' => $sql];

        return true;
    }
}

$tmp = sys_get_temp_dir() . '/xabia-doc-ingest-test-' . getmypid();
@mkdir($tmp, 0755, true);

$adapter = new Xabia_Document_Ingest_Test_Adapter();
$ingest = new Xabia_Document_Ingest($adapter, $tmp);

$project = 'demo-agent';
$dir = $ingest->ensure_project_dir_security($project);
assert(is_dir($dir), 'project dir created');
assert(is_file($dir . 'index.php'), 'index.php guard');
assert(is_file($dir . '.htaccess'), 'htaccess guard');

file_put_contents($dir . 'faq.md', "## Reservas\n\nPuedes reservar online.\n\n## Contacto\n\nLlámanos al 900.");
file_put_contents($dir . 'data.json', '[{"title":"Horario","body":"De 9 a 18"}]');

$scan = $ingest->scan_project_directory($project);
assert(count($scan) === 2, 'two files scanned');
assert($scan[0]['status'] === 'compatible' || $scan[1]['status'] === 'compatible', 'compatible status');

$result = $ingest->ingest_project_directory($project, 'test_');
assert($result['success'] === true, 'ingest success');
assert($result['chunks'] >= 3, 'chunks from md+json');

$inserts = array_filter($adapter->log, static function ($row) {
    return ($row['op'] ?? '') === 'insert';
});
assert(count($inserts) >= 3, 'inserts logged');

array_map('unlink', glob($dir . '*') ?: []);
@rmdir($dir);
@rmdir($tmp);

echo "OK test-xabia-document-ingest.php\n";
