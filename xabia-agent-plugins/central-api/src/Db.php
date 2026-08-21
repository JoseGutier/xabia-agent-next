<?php

declare(strict_types=1);

namespace XabiaCentral;

use PDO;
use PDOException;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $dsn = Env::str('XABIA_DB_DSN');
        $user = Env::str('XABIA_DB_USER');
        $pass = Env::str('XABIA_DB_PASS');
        if ($dsn === '') {
            throw new PDOException('XABIA_DB_DSN no configurado');
        }
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return self::$pdo;
    }
}
