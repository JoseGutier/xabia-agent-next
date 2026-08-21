<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * Firma HMAC de peticiones POST desde WordPress (misma clave que el proxy).
 */
final class HubClientSignature
{
    public static function verify(string $rawBody, string $licenseKey, string $sourceUrl, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }
        $timestamp = trim((string) ($_SERVER['HTTP_X_XABIA_TIMESTAMP'] ?? ''));
        $signature = trim((string) ($_SERVER['HTTP_X_XABIA_SIGNATURE'] ?? ''));
        if ($timestamp === '' || $signature === '') {
            return false;
        }
        $ts = is_numeric($timestamp) ? (int) $timestamp : 0;
        if ($ts < 1 || abs(time() - $ts) > 15 * 60) {
            return false;
        }
        $payload = $licenseKey . "\n" . $sourceUrl . "\n" . $timestamp . "\n" . $rawBody;
        $expected = hash_hmac('sha256', $payload, $secret);
        $candidate = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;

        return hash_equals($expected, $candidate);
    }

    /**
     * Firma saliente (Hub → WordPress). Mismo algoritmo que el plugin Core (secreto = license_key).
     *
     * @return array<string, string>
     */
    public static function sign(string $rawBody, string $licenseKey, string $sourceUrl): array
    {
        $licenseKey = trim($licenseKey);
        $sourceUrl = trim($sourceUrl);
        $timestamp = (string) time();
        $payload = $licenseKey . "\n" . $sourceUrl . "\n" . $timestamp . "\n" . $rawBody;

        return [
            'X-Xabia-License'   => $licenseKey,
            'X-Xabia-Source'    => $sourceUrl,
            'X-Xabia-Timestamp' => $timestamp,
            'X-Xabia-Signature' => 'sha256=' . hash_hmac('sha256', $payload, $licenseKey),
        ];
    }
}
