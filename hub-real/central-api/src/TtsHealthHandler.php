<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * GET /xabia/v1/tts/health — comprobación de despliegue (sin auth).
 */
final class TtsHealthHandler
{
    public static function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            Json::respond(405, ['error' => ['message' => 'Method Not Allowed', 'type' => 'method', 'code' => 'method_not_allowed']]);

            return;
        }

        Json::respond(200, [
            'ok'                 => true,
            'service'            => 'xabia-hub-tts',
            'route_synthesize'   => '/xabia/v1/tts/synthesize',
            'google_credentials' => TtsGoogleCloud::credentialsDiagnostics(),
            'openai_configured'  => Env::str('OPENAI_API_KEY') !== '',
            'timestamp'          => gmdate('c'),
        ]);
    }
}
