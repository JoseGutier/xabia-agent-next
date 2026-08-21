<?php

declare(strict_types=1);

/**
 * Arranque del hub central Xabia (fuera de WordPress).
 */

$centralRoot = __DIR__;

spl_autoload_register(static function (string $class): void {
    $prefix = 'XabiaCentral\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $rel) . '.php';
    if (is_readable($path)) {
        require $path;
    }
});

/** Carga .env desde central-api/.env (KEY=valor, sin comillas obligatorias) */
$envFile = $centralRoot . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if ($k !== '' && getenv($k) === false) {
            putenv(sprintf('%s=%s', $k, $v));
            $_ENV[$k] = $v;
        }
    }
}
