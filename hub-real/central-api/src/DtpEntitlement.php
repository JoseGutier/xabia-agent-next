<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * Comprueba si una licencia incluye el servicio DTP (traducción automática vía Hub).
 *
 * DTP va **incluido en la licencia Core** (no es producto Polar ni addon de pago).
 * Cualquier licencia activa validada por SignedHubPostAuth tiene derecho a traducción de saludos.
 */
final class DtpEntitlement
{
    /** @deprecated Solo referencia histórica / pruebas; no se exige en producción retail. */
    public const DEFAULT_ADDON_SLUG = 'xabia-dtp';

    public static function addonSlug(): string
    {
        $slug = trim(Env::str('XABIA_DTP_ADDON_SLUG', self::DEFAULT_ADDON_SLUG));

        return $slug !== '' ? $slug : self::DEFAULT_ADDON_SLUG;
    }

    /**
     * @param array<string, mixed>|null $licenseRow Fila activa de licencia (status, plan_type).
     */
    public static function licenseHasDtp(string $licenseKey, ?array $licenseRow = null): bool
    {
        if (Env::str('XABIA_DTP_ALLOW_ALL') === '1') {
            return true;
        }

        $licenseKey = trim($licenseKey);
        if ($licenseKey === '') {
            return false;
        }

        if ($licenseRow !== null && self::rowIsActiveLicense($licenseRow)) {
            return true;
        }

        foreach (LicenseRepository::findAllByLicenseKey($licenseKey) as $row) {
            if (is_array($row) && self::rowIsActiveLicense($row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rowIsActiveLicense(array $row): bool
    {
        return strtolower(trim((string) ($row['status'] ?? ''))) === 'active';
    }
}
