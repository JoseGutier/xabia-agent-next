<?php

declare(strict_types=1);

namespace XabiaCentral;

use Throwable;

/**
 * Auditoría en xabia_logs (intentos con licencia conocida y dominio no autorizado).
 */
final class SecurityLog
{
    public const EVENT_LICENSE_UNAUTHORIZED_DOMAIN = 'license_unauthorized_domain';

    /**
     * @param array<string, mixed> $meta
     */
    public static function licenseUnauthorizedDomain(
        string $licenseKey,
        string $unauthorizedDomain,
        string $claimedDomain,
        string $registeredSummary,
        array $meta = []
    ): void {
        $licenseKey = trim($licenseKey);
        $suffix = $licenseKey !== '' && strlen($licenseKey) >= 8
            ? substr($licenseKey, -8)
            : $licenseKey;

        $originRaw = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        $refererRaw = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        $originHost = $originRaw !== '' ? Domain::normalize($originRaw) : '';
        $refererHost = $refererRaw !== '' ? Domain::normalize($refererRaw) : '';

        try {
            $st = Db::pdo()->prepare(
                'INSERT INTO xabia_logs (event_type, license_key_suffix, claimed_domain, http_origin_host, http_referer_host, unauthorized_domain, registered_domain, meta_json)
                 VALUES (:ev, :suf, :cl, :oh, :rh, :ud, :rd, :mj)'
            );
            $st->execute([
                ':ev'  => self::EVENT_LICENSE_UNAUTHORIZED_DOMAIN,
                ':suf' => $suffix !== '' ? $suffix : null,
                ':cl'  => $claimedDomain !== '' ? $claimedDomain : null,
                ':oh'  => $originHost !== '' ? $originHost : null,
                ':rh'  => $refererHost !== '' ? $refererHost : null,
                ':ud'  => $unauthorizedDomain !== '' ? $unauthorizedDomain : null,
                ':rd'  => $registeredSummary !== '' ? $registeredSummary : null,
                ':mj'  => self::encodeMeta($meta),
            ]);
        } catch (Throwable $e) {
            // Tabla ausente o error de BD: no bloquear la respuesta al cliente.
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function encodeMeta(array $meta): ?string
    {
        if ($meta === []) {
            return null;
        }
        $enc = json_encode($meta, JSON_UNESCAPED_UNICODE);

        return is_string($enc) && $enc !== '' ? $enc : null;
    }
}
