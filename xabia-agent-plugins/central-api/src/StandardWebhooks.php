<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * Verificación de firmas según Standard Webhooks (symmetric / v1, HMAC-SHA256).
 * Polar: {@link https://polar.sh/docs/integrate/webhooks/delivery}
 */
final class StandardWebhooks
{
    private const TIMESTAMP_TOLERANCE_SEC = 600;

    /**
     * @param array<string, mixed> $server típicamente $_SERVER
     */
    public static function verifySymmetricV1(string $rawBody, array $server, string $secretFromEnv): bool
    {
        $secretFromEnv = trim($secretFromEnv);
        if ($secretFromEnv === '') {
            return false;
        }
        $id = self::header($server, 'webhook-id');
        $ts = self::header($server, 'webhook-timestamp');
        $sigHeader = self::header($server, 'webhook-signature');
        if ($id === '' || $ts === '' || $sigHeader === '') {
            return false;
        }
        if (!ctype_digit($ts)) {
            return false;
        }
        $tsInt = (int) $ts;
        if (abs(time() - $tsInt) > self::TIMESTAMP_TOLERANCE_SEC) {
            return false;
        }
        $keyMaterial = self::decodeSigningSecret($secretFromEnv);
        if ($keyMaterial === '') {
            return false;
        }
        $signedContent = $id . '.' . $ts . '.' . $rawBody;
        $mac = hash_hmac('sha256', $signedContent, $keyMaterial, true);
        if ($mac === false) {
            return false;
        }
        $expectedB64 = base64_encode($mac);
        foreach (preg_split('/\s+/', trim($sigHeader)) ?: [] as $part) {
            $part = trim((string) $part);
            if ($part === '' || !str_starts_with($part, 'v1,')) {
                continue;
            }
            $their = substr($part, 3);
            if ($their !== '' && hash_equals($expectedB64, $their)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function header(array $server, string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return isset($server[$key]) && is_string($server[$key]) ? trim($server[$key]) : '';
    }

    private static function decodeSigningSecret(string $secret): string
    {
        if (str_starts_with($secret, 'whsec_')) {
            $b64 = substr($secret, strlen('whsec_'));
            $raw = base64_decode($b64, true);

            return $raw !== false ? $raw : '';
        }
        $try = base64_decode($secret, true);

        return ($try !== false && $try !== '') ? $try : $secret;
    }
}
