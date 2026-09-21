<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * POST /xabia/v1/tts/synthesize — TTS centralizado (Google Cloud en el Hub).
 */
final class TtsHandler
{
    private const MAX_TEXT_CHARS = 1200;

    public static function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            Json::respond(405, ['error' => ['message' => 'Method Not Allowed', 'type' => 'method', 'code' => 'method_not_allowed']]);

            return;
        }

        $rawBody = (string) file_get_contents('php://input');
        self::log('request_received', [
            'bytes'            => strlen($rawBody),
            'license_header'   => isset($_SERVER['HTTP_X_XABIA_LICENSE']) ? 'present' : 'missing',
            'source_header'    => self::headerSnippet('HTTP_X_XABIA_SOURCE'),
            'signature_header' => isset($_SERVER['HTTP_X_XABIA_SIGNATURE']) ? 'present' : 'missing',
            'timestamp_header' => isset($_SERVER['HTTP_X_XABIA_TIMESTAMP']) ? 'present' : 'missing',
        ]);

        $ctx = SignedHubPostAuth::validate($rawBody);
        if ($ctx === null) {
            self::log('auth_rejected', [
                'note' => 'SignedHubPostAuth ya respondió JSON descriptivo al cliente',
            ]);

            return;
        }

        $input = $rawBody === '' ? [] : json_decode($rawBody, true);
        if (!is_array($input)) {
            self::log('invalid_json', ['raw_snippet' => substr($rawBody, 0, 300)]);
            Json::respond(400, ['error' => ['message' => 'JSON inválido', 'type' => 'invalid_request', 'code' => 'invalid_json']]);

            return;
        }

        $text = trim((string) ($input['text'] ?? ''));
        if ($text === '') {
            Json::respond(400, [
                'error' => ['message' => 'text obligatorio', 'type' => 'invalid_request', 'code' => 'missing_text'],
            ]);

            return;
        }
        if (function_exists('mb_strlen') && mb_strlen($text) > self::MAX_TEXT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_TEXT_CHARS);
        } elseif (strlen($text) > self::MAX_TEXT_CHARS) {
            $text = substr($text, 0, self::MAX_TEXT_CHARS);
        }

        $locale = trim(str_replace('_', '-', (string) ($input['locale'] ?? $input['lang'] ?? 'es-ES')));
        if ($locale === '') {
            $locale = 'es-ES';
        }

        $voicePref = trim((string) ($input['voice'] ?? 'default'));
        if (!in_array($voicePref, ['default', 'female', 'male'], true)) {
            $voicePref = 'default';
        }

        $rate = isset($input['rate']) ? (float) $input['rate'] : 1.0;
        $rate = max(0.5, min(2.0, $rate > 0 ? $rate : 1.0));

        $projectId = trim((string) ($input['project_id'] ?? $input['agent_id'] ?? ''));
        $sourceUrl = $ctx['source_url'];
        $row = $ctx['row'];
        $billingLicenseId = $ctx['billing_license_id'];
        $remaining = (int) ($row['tokens_remaining'] ?? 0);

        $tokenCost = self::estimateTokenCost($text);
        if ($remaining < $tokenCost) {
            self::log('insufficient_balance', [
                'remaining'    => $remaining,
                'required'     => $tokenCost,
                'project_id'   => $projectId,
                'source_url'   => $sourceUrl,
            ]);
            Json::respond(402, [
                'ok'    => false,
                'error' => [
                    'message' => 'Saldo insuficiente en Digixop',
                    'type'    => 'insufficient_balance',
                    'code'    => 'insufficient_balance',
                ],
            ]);

            return;
        }

        $google = TtsGoogleCloud::synthesizeDetailed($text, $locale, $voicePref, $rate);
        $audio = null;
        $upstreamErrors = [];

        if (!empty($google['ok'])) {
            $audio = [
                'base64' => (string) ($google['base64'] ?? ''),
                'mime'   => (string) ($google['mime'] ?? 'audio/mpeg'),
                'engine' => (string) ($google['engine'] ?? 'google_cloud'),
            ];
        } elseif (isset($google['error']) && is_array($google['error'])) {
            $upstreamErrors['google'] = $google['error'];
        }

        if ($audio === null) {
            $openai = self::synthesizeOpenAiFallbackDetailed($text, $voicePref, $rate);
            if (!empty($openai['ok'])) {
                $audio = [
                    'base64' => (string) ($openai['base64'] ?? ''),
                    'mime'   => (string) ($openai['mime'] ?? 'audio/mpeg'),
                    'engine' => (string) ($openai['engine'] ?? 'openai'),
                ];
            } elseif (isset($openai['error']) && is_array($openai['error'])) {
                $upstreamErrors['openai'] = $openai['error'];
            }
        }

        if ($audio === null || ($audio['base64'] ?? '') === '') {
            self::log('tts_unavailable', [
                'locale'     => $locale,
                'project_id' => $projectId,
                'errors'     => $upstreamErrors,
                'google_creds' => TtsGoogleCloud::credentialsDiagnostics(),
            ]);
            Json::respond(503, [
                'ok'    => false,
                'error' => [
                    'message' => 'No se pudo sintetizar audio en el Hub',
                    'type'    => 'upstream_error',
                    'code'    => 'tts_unavailable',
                ],
                'upstream' => $upstreamErrors,
                'diagnostics' => [
                    'google_credentials' => TtsGoogleCloud::credentialsDiagnostics(),
                ],
            ]);

            return;
        }

        $after = WalletRepository::deduct(
            $billingLicenseId,
            $tokenCost,
            'tts',
            $sourceUrl,
            $projectId !== '' ? $projectId : null,
            [
                'locale'     => substr($locale, 0, 16),
                'engine'     => $audio['engine'] ?? 'unknown',
                'text_chars' => strlen($text),
            ]
        );
        if ($after === null) {
            self::log('wallet_deduct_failed', ['billing_license_id' => $billingLicenseId, 'token_cost' => $tokenCost]);
            Json::respond(500, [
                'error' => [
                    'message' => 'Error al actualizar saldo tras generar audio',
                    'type'    => 'xabia_hub',
                    'code'    => 'wallet_deduct_failed',
                ],
            ]);

            return;
        }

        self::log('success', [
            'engine'     => $audio['engine'] ?? 'unknown',
            'locale'     => $locale,
            'project_id' => $projectId,
            'text_chars' => strlen($text),
        ]);

        Json::respond(200, [
            'ok'               => true,
            'mime'             => $audio['mime'],
            'base64'           => $audio['base64'],
            'engine'           => $audio['engine'],
            'text'             => $text,
            'locale'           => $locale,
            'tokens_remaining' => $after,
            'tokens_charged'   => $tokenCost,
        ]);
    }

    private static function estimateTokenCost(string $text): int
    {
        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);

        return max(5, (int) ceil($len / 8));
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
    private static function synthesizeOpenAiFallbackDetailed(string $text, string $voicePref, float $rate): array
    {
        $voice = 'nova';
        if ($voicePref === 'male') {
            $voice = 'onyx';
        }
        $up = OpenAiForwarder::forward('/v1/audio/speech', [
            'model'           => 'tts-1',
            'input'           => $text,
            'voice'           => $voice,
            'response_format' => 'mp3',
            'speed'           => $rate,
        ]);
        $code = (int) ($up['http_code'] ?? 0);
        if ($code < 200 || $code >= 300) {
            $msg = 'OpenAI TTS respondió con HTTP ' . $code;
            if (is_array($up['decoded'] ?? null) && isset($up['decoded']['error']['message'])) {
                $msg = (string) $up['decoded']['error']['message'];
            }
            error_log('[xabia-tts-openai] http_' . $code . ' ' . $msg);

            return [
                'ok'    => false,
                'error' => [
                    'code'      => 'openai_http_' . $code,
                    'message'   => $msg,
                    'http_code' => $code,
                ],
            ];
        }
        $raw = (string) ($up['raw'] ?? '');
        if ($raw === '' || strncmp($raw, '{', 1) === 0) {
            return [
                'ok'    => false,
                'error' => [
                    'code'    => 'openai_empty_audio',
                    'message' => 'OpenAI TTS no devolvió binario MP3',
                ],
            ];
        }

        return [
            'ok'     => true,
            'base64' => base64_encode($raw),
            'mime'   => 'audio/mpeg',
            'engine' => 'openai',
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function log(string $event, array $context = []): void
    {
        $payload = json_encode(array_merge(['event' => $event], $context), JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            $payload = '{"event":"' . $event . '"}';
        }
        error_log('[xabia-tts] ' . $payload);
    }

    private static function headerSnippet(string $serverKey): string
    {
        $value = trim((string) ($_SERVER[$serverKey] ?? ''));
        if ($value === '') {
            return 'missing';
        }

        return substr($value, 0, 120);
    }
}
