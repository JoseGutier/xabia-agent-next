<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * OAuth2 access_token a partir de JSON de cuenta de servicio (Vertex / Google Cloud).
 */
final class GoogleServiceAccountAuth
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * @return array{project_id:string, client_email:string, private_key:string}|null
     */
    public static function loadServiceAccountJson(): ?array
    {
        $rawJson = Env::str('GOOGLE_APPLICATION_CREDENTIALS_JSON');
        if ($rawJson !== '') {
            $data = json_decode($rawJson, true);
        } else {
            $path = Env::str('GOOGLE_APPLICATION_CREDENTIALS');
            if (!is_string($path) || trim($path) === '' || !is_readable($path)) {
                return null;
            }
            $data = json_decode((string) file_get_contents($path), true);
        }
        if (!is_array($data)) {
            return null;
        }
        $pid = $data['project_id'] ?? '';
        $email = $data['client_email'] ?? '';
        $pk = $data['private_key'] ?? '';
        if ($pid === '' || $email === '' || $pk === '') {
            return null;
        }
        if (is_string($pk) && strpos($pk, '\n') !== false) {
            $pk = str_replace('\n', "\n", $pk);
        }

        return [
            'project_id'   => (string) $pid,
            'client_email'   => (string) $email,
            'private_key'    => (string) $pk,
        ];
    }

    public static function fetchAccessToken(): ?string
    {
        $acc = self::loadServiceAccountJson();
        if ($acc === null) {
            return null;
        }
        $jwt = self::signJwt($acc['client_email'], $acc['private_key']);
        if ($jwt === null) {
            return null;
        }
        $post = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ], '', '&');
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code < 200 || $code >= 300) {
            return null;
        }
        $j = json_decode($raw, true);
        if (!is_array($j) || empty($j['access_token'])) {
            return null;
        }

        return (string) $j['access_token'];
    }

    private static function signJwt(string $clientEmail, string $privateKeyPem): ?string
    {
        $now = time();
        $header = self::b64url(json_encode(['typ' => 'JWT', 'alg' => 'RS256'], JSON_UNESCAPED_SLASHES));
        $claims = self::b64url(json_encode([
            'iss'   => $clientEmail,
            'sub'   => $clientEmail,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
            'scope' => 'https://www.googleapis.com/auth/cloud-platform',
        ], JSON_UNESCAPED_SLASHES));
        $signInput = $header . '.' . $claims;
        $res = openssl_pkey_get_private($privateKeyPem);
        if ($res === false) {
            return null;
        }
        $sig = '';
        if (!openssl_sign($signInput, $sig, $res, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return $signInput . '.' . self::b64url($sig);
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
