#!/usr/bin/env php
<?php

declare(strict_types=1);

use XabiaCentral\Db;
use XabiaCentral\Workers\VectorizationWorker;

require __DIR__ . '/../bootstrap.php';

$batchSize = 50;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--batch-size=(\d+)$/', (string) $arg, $m)) {
        $batchSize = (int) $m[1];
    }
}
$batchSize = max(1, min(250, $batchSize));

try {
    $worker = new VectorizationWorker(Db::pdo());
    $result = $worker->process_pending_batch($batchSize);

    $ok = (int) $result['errors'] === 0;
    $prefix = $ok ? '[Éxito]' : '[Parcial]';
    echo $prefix
        . ' Procesados: ' . (string) $result['processed']
        . ' | Sincronizados: ' . (string) $result['synchronized']
        . ' | Errores: ' . (string) $result['errors']
        . ' | Omitidos: ' . (string) $result['skipped']
        . PHP_EOL;

    if (!$ok) {
        foreach ($result['error_details'] as $error) {
            echo '  - ID ' . (string) $error['id'] . ': ' . (string) $error['message'] . PHP_EOL;
        }
        exit(1);
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[Error] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
