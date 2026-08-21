<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * Reenvío a la API oficial OpenAI con la master key del hub.
 *
 * @return array{http_code:int, decoded:?array, raw:string}
 */
final class OpenAiForwarder
{
    private const OPENAI_BASE = 'https://api.openai.com';

    /**
     * @param array<string, mixed> $payload
     * @return array{http_code:int, decoded:?array, raw:string}
     */
    public static function forward(string $path, array $payload): array
    {
        $apiKey = Env::str('OPENAI_API_KEY');
        if ($apiKey === '') {
            return [
                'http_code' => 500,
                'decoded'   => ['error' => ['message' => 'OPENAI_API_KEY no configurada en el hub', 'type' => 'xabia_hub_config']],
                'raw'       => '',
            ];
        }
        $url = self::OPENAI_BASE . $path;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return [
                'http_code' => 400,
                'decoded'   => ['error' => ['message' => 'JSON inválido', 'type' => 'invalid_request']],
                'raw'       => '',
            ];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);

            return [
                'http_code' => 502,
                'decoded'   => ['error' => ['message' => $err ?: 'curl error', 'type' => 'xabia_hub_upstream']],
                'raw'       => '',
            ];
        }
        curl_close($ch);
        $decoded = json_decode($raw, true);

        return [
            'http_code' => $code > 0 ? $code : 502,
            'decoded'   => is_array($decoded) ? $decoded : null,
            'raw'       => $raw,
        ];
    }

    /**
     * Decide ruta OpenAI según cuerpo (espejo del contrato del plugin).
     *
     * @param array<string, mixed> $payload
     * @return array{path:string,payload:array<string,mixed>}|null
     */
    public static function resolveOpenAiRoute(array $payload): ?array
    {
        if (isset($payload['messages']) && is_array($payload['messages'])) {
            return ['path' => '/v1/chat/completions', 'payload' => $payload];
        }
        if (isset($payload['input']) && isset($payload['model'])) {
            return ['path' => '/v1/embeddings', 'payload' => $payload];
        }

        return null;
    }
}
