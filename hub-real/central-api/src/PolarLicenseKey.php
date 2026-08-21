<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * Formato de clave emitida por Polar en portal / beneficios (prefijo XABIA--).
 */
final class PolarLicenseKey
{
    /**
     * Trim, quita BOM y espacios raros al inicio/final.
     */
    public static function normalize(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        $key = preg_replace('/^\x{FEFF}/u', '', $key) ?? $key;

        return trim($key);
    }

    /**
     * Valida formato tras normalizar. Polar puede usar UUID; permitimos . _ en el sufijo por evolución del proveedor.
     */
    public static function isValidFormat(string $key): bool
    {
        $key = self::normalize($key);

        return $key !== '' && preg_match('/^XABIA--[A-Z0-9][A-Z0-9._-]*$/i', $key) === 1;
    }

    /**
     * Extrae la primera coincidencia XABIA--… dentro de un texto más largo (metadata, JSON serializado).
     */
    public static function extractFromText(string $haystack): string
    {
        $haystack = trim($haystack);
        if ($haystack === '') {
            return '';
        }
        if (self::isValidFormat($haystack)) {
            return self::normalize($haystack);
        }
        if (preg_match('/\b(XABIA--[A-Z0-9][A-Z0-9._-]*)\b/i', $haystack, $m)) {
            return self::normalize($m[1]);
        }

        return '';
    }
}
