<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * Puente DTP: traduce saludos de agente para WPML String Translation.
 */
final class I18nGreetingHandler
{
    public static function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            Json::respond(405, ['error' => ['message' => 'Method Not Allowed', 'type' => 'method']]);

            return;
        }

        $rawBody = (string) file_get_contents('php://input');
        $ctx = SignedHubPostAuth::validate($rawBody);
        if ($ctx === null) {
            return;
        }

        $input = $rawBody === '' ? [] : json_decode($rawBody, true);
        if (!is_array($input)) {
            Json::respond(400, ['error' => ['message' => 'JSON inválido', 'type' => 'invalid_request']]);

            return;
        }

        $text = trim((string) ($input['text'] ?? $input['greeting'] ?? ''));
        $sourceLang = trim((string) ($input['source_lang'] ?? 'es'));
        $targetLangs = $input['target_langs'] ?? [];
        if (!is_array($targetLangs)) {
            $targetLangs = [];
        }

        if ($text === '') {
            Json::respond(400, [
                'error' => ['message' => 'text o greeting obligatorio', 'type' => 'invalid_request'],
            ]);

            return;
        }
        if ($targetLangs === []) {
            Json::respond(400, [
                'error' => ['message' => 'target_langs[] obligatorio', 'type' => 'invalid_request'],
            ]);

            return;
        }

        $licenseKey = $ctx['license_key'];
        if (!DtpEntitlement::licenseHasDtp($licenseKey, $ctx['row'])) {
            Json::respond(403, [
                'ok'    => false,
                'dtp'   => false,
                'error' => [
                    'code'    => 'dtp_not_available',
                    'message' => 'La licencia no incluye el servicio DTP',
                    'type'    => 'forbidden',
                ],
            ]);

            return;
        }

        $translations = DtpTranslator::translateGreeting($text, $sourceLang, $targetLangs);
        if ($translations === null) {
            Json::respond(502, [
                'ok'    => false,
                'dtp'   => true,
                'error' => [
                    'code'    => 'translation_failed',
                    'message' => 'No se pudo generar las traducciones',
                    'type'    => 'upstream_error',
                ],
            ]);

            return;
        }

        Json::respond(200, [
            'ok'           => true,
            'dtp'          => true,
            'source_lang'  => $sourceLang !== '' ? $sourceLang : 'es',
            'translations' => $translations,
        ]);
    }
}
