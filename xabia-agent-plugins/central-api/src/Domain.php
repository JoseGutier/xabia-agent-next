<?php

declare(strict_types=1);

namespace XabiaCentral;

final class Domain
{
    /**
     * Normaliza URL completa o host a hostname en minúsculas (sin puerto opcional se conserva host).
     */
    public static function normalize(string $urlOrHost): string
    {
        $s = trim($urlOrHost);
        if ($s === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $s) === 1) {
            $host = parse_url($s, PHP_URL_HOST);
            return $host !== null && $host !== false ? strtolower($host) : '';
        }
        $s = preg_replace('#/.*$#', '', $s) ?? $s;
        $s = preg_replace('#:\d+$#', '', $s) ?? $s;

        return strtolower($s);
    }

    public static function domainsMatch(string $registered, string $incoming): bool
    {
        $stripWww = static function (string $h): string {
            return str_starts_with($h, 'www.') ? substr($h, 4) : $h;
        };

        $a = $stripWww(self::normalize($registered));
        $b = $stripWww(self::normalize($incoming));
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        // Licencia de “sitio” (apex): cualquier subdominio del host registrado cuenta como el mismo dominio.
        if (str_ends_with($b, '.' . $a)) {
            return true;
        }

        return false;
    }
}
