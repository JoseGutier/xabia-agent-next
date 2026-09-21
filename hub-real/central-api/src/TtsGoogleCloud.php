<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * Google Cloud Text-to-Speech en el Hub (credenciales centralizadas).
 */
final class TtsGoogleCloud
{
    private const API_URL = 'https://texttospeech.googleapis.com/v1/text:synthesize';

    /** @var array<string, mixed>|null */
    private static ?array $lastError = null;

    /**
     * @return array{ready: bool, source: string, project_id: ?string, path_readable: bool}
     */
    public static function credentialsDiagnostics(): array
    {
        $jsonEnv = Env::str('GOOGLE_APPLICATION_CREDENTIALS_JSON');
        $path = Env::str('GOOGLE_APPLICATION_CREDENTIALS');
        $acc = GoogleServiceAccountAuth::loadServiceAccountJson();
        $source = 'missing';
        $pathReadable = false;
        if ($jsonEnv !== '') {
            $source = 'GOOGLE_APPLICATION_CREDENTIALS_JSON';
        } elseif ($path !== '') {
            $source = 'GOOGLE_APPLICATION_CREDENTIALS';
            $pathReadable = is_readable($path);
        }

        return [
            'ready'          => $acc !== null,
            'source'         => $source,
            'project_id'     => $acc['project_id'] ?? null,
            'path_readable'  => $pathReadable,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function consumeLastError(): ?array
    {
        $err = self::$lastError;
        self::$lastError = null;

        return $err;
    }

    /**
     * @return array{
     *   ok: bool,
     *   base64?: string,
     *   mime?: string,
     *   engine?: string,
     *   error?: array{code: string, message: string, http_code?: int, detail?: string}
     * }
     */
    public static function synthesizeDetailed(string $text, string $locale, string $voicePref, float $rate): array
    {
        self::$lastError = null;
        $text = trim($text);
        if ($text === '') {
            return self::fail('empty_text', 'Texto vacío');
        }

        $creds = self::credentialsDiagnostics();
        if (!$creds['ready']) {
            return self::fail(
                'google_credentials_missing',
                'Credenciales Google Cloud no disponibles en el Hub',
                0,
                'source=' . $creds['source'] . ' path_readable=' . ($creds['path_readable'] ? 'yes' : 'no')
            );
        }

        $token = GoogleServiceAccountAuth::fetchAccessToken();
        if ($token === null || $token === '') {
            return self::fail(
                'google_token_failed',
                'No se pudo obtener access_token de Google Cloud',
                0,
                'project_id=' . (string) ($creds['project_id'] ?? '')
            );
        }

        $short = self::shortLang($locale);
        $language = self::normalizeLocale($locale, $short);
        $voiceName = self::voiceName($short, $voicePref);
        $rate = max(0.5, min(2.0, $rate > 0 ? $rate : 1.0));

        $attempts = [];
        $body = [
            'input'       => ['ssml' => self::wrapSsml($text, $language)],
            'voice'       => array_filter([
                'languageCode' => $language,
                'name'         => $voiceName !== '' ? $voiceName : null,
            ]),
            'audioConfig' => [
                'audioEncoding' => 'MP3',
                'speakingRate'  => $rate,
            ],
        ];
        $attempts[] = self::postDetailed($token, $body, 'ssml_named_voice');
        if (!$attempts[count($attempts) - 1]['ok'] && $voiceName !== '' && $short === 'eu') {
            $body['voice'] = ['languageCode' => $language];
            $attempts[] = self::postDetailed($token, $body, 'ssml_locale_only');
        }
        if (!$attempts[count($attempts) - 1]['ok']) {
            $body['input'] = ['text' => $text];
            $attempts[] = self::postDetailed($token, $body, 'plain_text');
        }

        foreach ($attempts as $attempt) {
            if (!empty($attempt['ok']) && !empty($attempt['base64'])) {
                return [
                    'ok'     => true,
                    'base64' => (string) $attempt['base64'],
                    'mime'   => 'audio/mpeg',
                    'engine' => 'google_cloud',
                ];
            }
        }

        $last = $attempts[count($attempts) - 1] ?? self::fail('google_tts_failed', 'Google Cloud TTS no devolvió audio');

        return [
            'ok'    => false,
            'error' => is_array($last['error'] ?? null) ? $last['error'] : [
                'code'    => 'google_tts_failed',
                'message' => 'Google Cloud TTS no devolvió audio',
            ],
        ];
    }

    /**
     * @return array{base64: string, mime: string, engine: string}|null
     */
    public static function synthesize(string $text, string $locale, string $voicePref, float $rate): ?array
    {
        $result = self::synthesizeDetailed($text, $locale, $voicePref, $rate);
        if (empty($result['ok'])) {
            return null;
        }

        return [
            'base64' => (string) ($result['base64'] ?? ''),
            'mime'   => (string) ($result['mime'] ?? 'audio/mpeg'),
            'engine' => (string) ($result['engine'] ?? 'google_cloud'),
        ];
    }

    /**
     * @return array{
     *   ok: bool,
     *   base64?: string,
     *   error?: array{code: string, message: string, http_code?: int, detail?: string}
     * }
     */
    private static function fail(string $code, string $message, int $httpCode = 0, string $detail = ''): array
    {
        $error = [
            'code'    => $code,
            'message' => $message,
        ];
        if ($httpCode > 0) {
            $error['http_code'] = $httpCode;
        }
        if ($detail !== '') {
            $error['detail'] = $detail;
        }
        self::$lastError = $error;
        error_log('[xabia-tts-google] ' . $code . ' ' . $message . ($detail !== '' ? ' | ' . $detail : ''));

        return ['ok' => false, 'error' => $error];
    }

    private static function shortLang(string $locale): string
    {
        $locale = trim(strtolower(str_replace('_', '-', $locale)));
        if ($locale === '') {
            return 'es';
        }
        $parts = explode('-', $locale);

        return $parts[0] !== '' ? $parts[0] : 'es';
    }

    private static function normalizeLocale(string $locale, string $short): string
    {
        $locale = trim(str_replace('_', '-', $locale));
        if ($locale !== '' && str_contains($locale, '-')) {
            $parts = explode('-', $locale, 2);
            if (($parts[0] ?? '') !== '' && ($parts[1] ?? '') !== '') {
                return strtolower($parts[0]) . '-' . strtoupper($parts[1]);
            }
        }

        $map = [
            'es' => 'es-ES',
            'eu' => 'eu-ES',
            'en' => 'en-US',
            'fr' => 'fr-FR',
            'de' => 'de-DE',
            'it' => 'it-IT',
            'pt' => 'pt-PT',
            'ca' => 'ca-ES',
            'gl' => 'gl-ES',
        ];

        return $map[$short] ?? ($short . '-' . strtoupper($short));
    }

    private static function voiceName(string $short, string $voicePref): string
    {
        $male = ($voicePref === 'male');
        $map = [
            'es' => $male ? 'es-ES-Neural2-B' : 'es-ES-Neural2-A',
            'en' => $male ? 'en-US-Neural2-D' : 'en-US-Neural2-C',
            'fr' => $male ? 'fr-FR-Neural2-B' : 'fr-FR-Neural2-A',
            'de' => $male ? 'de-DE-Neural2-B' : 'de-DE-Neural2-A',
            'it' => $male ? 'it-IT-Neural2-C' : 'it-IT-Neural2-A',
            'pt' => $male ? 'pt-BR-Neural2-B' : 'pt-BR-Neural2-A',
            'eu' => $male ? 'eu-ES-Wavenet-B' : 'eu-ES-Wavenet-A',
        ];

        return (string) ($map[$short] ?? '');
    }

    private static function wrapSsml(string $text, string $locale): string
    {
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $escaped = preg_replace('/\n+| … /u', '<break time="750ms"/>', $escaped) ?? $escaped;
        $langAttr = htmlspecialchars($locale, ENT_QUOTES, 'UTF-8');

        return '<speak xml:lang="' . $langAttr . '">' . $escaped . '</speak>';
    }

    /**
     * @param array<string, mixed> $body
     * @return array{
     *   ok: bool,
     *   base64?: string,
     *   error?: array{code: string, message: string, http_code?: int, detail?: string}
     * }
     */
    private static function postDetailed(string $accessToken, array $body, string $attemptLabel): array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return self::fail('google_request_encode', 'No se pudo serializar la petición TTS', 0, $attemptLabel);
        }

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return self::fail(
                'google_curl_error',
                'Error de red hacia Google Cloud TTS',
                0,
                $attemptLabel . ' curl=' . $curlErr
            );
        }

        if ($code < 200 || $code >= 300) {
            $detail = self::extractGoogleErrorMessage($raw);
            error_log('[xabia-tts-google] http_' . $code . ' attempt=' . $attemptLabel . ' detail=' . $detail);

            return self::fail(
                'google_http_' . $code,
                'Google Cloud TTS respondió con HTTP ' . $code,
                $code,
                $attemptLabel . ': ' . $detail
            );
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::fail('google_invalid_json', 'Respuesta JSON inválida de Google Cloud TTS', $code, $attemptLabel);
        }

        $b64 = (string) ($decoded['audioContent'] ?? '');
        if ($b64 === '') {
            return self::fail('google_empty_audio', 'Google Cloud TTS no incluyó audioContent', $code, $attemptLabel);
        }

        return ['ok' => true, 'base64' => $b64];
    }

    private static function extractGoogleErrorMessage(string $raw): string
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return substr(trim($raw), 0, 500);
        }
        if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
            $msg = trim($decoded['error']['message']);
            $status = isset($decoded['error']['status']) ? (string) $decoded['error']['status'] : '';

            return $status !== '' ? ($status . ': ' . $msg) : $msg;
        }

        return substr(trim($raw), 0, 500);
    }
}
