<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * Lectura de variables de entorno compatible con hostings donde {@see putenv()} está deshabilitado:
 * {@see bootstrap.php} rellena $_ENV desde .env, pero {@see getenv()} puede seguir vacío.
 */
final class Env
{
    public static function str(string $key, string $default = ''): string
    {
        if (isset($_ENV[$key]) && is_string($_ENV[$key])) {
            $v = trim($_ENV[$key]);
            if ($v !== '') {
                return $v;
            }
        }
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
            $v = trim($_SERVER[$key]);
            if ($v !== '') {
                return $v;
            }
        }
        $g = getenv($key);
        if (is_string($g)) {
            $v = trim($g);
            if ($v !== '') {
                return $v;
            }
        }

        return $default;
    }
}
