<?php

declare(strict_types=1);

namespace XabiaCentral;

final class ProxyHandler
{
    private const MSG_402 = 'Saldo insuficiente en Digixop';

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
        $licenseKey = $ctx['license_key'];
        $sourceUrl = $ctx['source_url'];
        $row = $ctx['row'];
        $billingLicenseId = $ctx['billing_license_id'];

        $remaining = (int) $row['tokens_remaining'];
        if ($remaining <= 0) {
            self::respond402();

            return;
        }

        $input = $rawBody === '' ? [] : json_decode($rawBody, true);
        if (!is_array($input)) {
            Json::respond(400, ['error' => ['message' => 'JSON inválido', 'type' => 'invalid_request']]);

            return;
        }

        $route = OpenAiForwarder::resolveOpenAiRoute($input);
        if ($route === null) {
            Json::respond(400, [
                'error' => [
                    'message' => 'Cuerpo no reconocido: se espera chat (messages) o embeddings (model+input)',
                    'type'    => 'invalid_request',
                ],
            ]);

            return;
        }
        $activityType = ($route['path'] ?? '') === '/v1/embeddings' ? 'embedding' : 'chat';

        // Preflight crítico: DSN para wallet/log.
        $dsn = Env::str('XABIA_DB_DSN');
        if ($dsn === '') {
            Json::respond(500, ['error' => ['message' => 'XABIA_DB_DSN no configurado', 'type' => 'xabia_hub_config']]);
            return;
        }

        // Idioma preferido del cliente (p. ej. BCP-47 desde document.documentElement.lang); lo consume VertexForwarder.
        if (array_key_exists('user_lang', $input)) {
            $input['user_lang'] = self::normalizeUserLang($input['user_lang']);
        }
        $aviratoConfig = self::extractAviratoConfig($input);
        if ($aviratoConfig !== []) {
            $input['avirato'] = $aviratoConfig;
        }

        if (isset($input['messages']) && is_array($input['messages']) && self::inputRequestsFederationTool($input)) {
            $input['messages'] = self::injectFederatedMasterSystemInstruction($input['messages']);
        }

        // Motor Gemini según plan contratado en BD (no se confía en cabeceras del cliente).
        $planType = strtolower(trim((string) ($row['plan_type'] ?? '')));
        $vertexTier = ($planType === 'enterprise') ? 'pro' : 'flash';
        $up = $activityType === 'chat' ? VertexForwarder::tryAviratoLiteOpenAiResponse($input, $aviratoConfig) : null;
        if ($up === null) {
            $gPath = Env::str('GOOGLE_APPLICATION_CREDENTIALS');
            $gJson = Env::str('GOOGLE_APPLICATION_CREDENTIALS_JSON');
            if ($gPath === '' && $gJson === '') {
                Json::respond(500, ['error' => ['message' => 'GOOGLE_APPLICATION_CREDENTIALS no configurado', 'type' => 'xabia_hub_config']]);
                return;
            }
            $up = VertexForwarder::forwardOpenAiCompatible($input, $vertexTier);
        }
        $code = $up['http_code'];
        $decoded = $up['decoded'];
        if ($decoded === null) {
            try {
                WalletRepository::logUsage(
                    (int) $row['id'],
                    $activityType . '_error',
                    0,
                    $sourceUrl,
                    null,
                    ['vertex_http' => $code, 'upstream_raw' => substr($up['raw'] ?? '', 0, 500)]
                );
            } catch (\Throwable) {
                // Evitar bloquear la respuesta por fallo de logging.
            }
            Json::respond($code >= 400 ? $code : 502, ['error' => ['message' => 'Respuesta no JSON del upstream', 'type' => 'xabia_hub']]);

            return;
        }

        $usageTokens = self::extractUsageTotalTokens($decoded);
        $isIaLite = !empty($decoded['xabia_ia_lite']);
        $expIso = LicenseRepository::expiryAsIso($row['expiry_date'] !== null ? (string) $row['expiry_date'] : null);
        if ($code >= 200 && $code < 300) {
            if ($usageTokens > 0) {
                $after = WalletRepository::deduct(
                    $billingLicenseId,
                    $usageTokens,
                    $activityType,
                    $sourceUrl,
                    null,
                    [
                        'vertex_http' => $code,
                        'plan_type'   => substr((string) ($row['plan_type'] ?? ''), 0, 64),
                        'vertex_tier' => $vertexTier,
                        'usage_source'=> 'google_vertex_usage_metadata',
                    ]
                );
                if ($after === null) {
                    Json::respond(500, ['error' => ['message' => 'Error al actualizar saldo', 'type' => 'xabia_hub']]);

                    return;
                }
                $decoded['tokens_remaining'] = $after;
            } else {
                try {
                    WalletRepository::logUsage(
                        $billingLicenseId,
                        $isIaLite ? 'chat_ia_lite' : $activityType,
                        0,
                        $sourceUrl,
                        null,
                        [
                            'upstream_http' => $code,
                            'plan_type'   => substr((string) ($row['plan_type'] ?? ''), 0, 64),
                            'vertex_tier' => $isIaLite ? 'ia_lite' : $vertexTier,
                            'note'        => $isIaLite ? 'Respuesta resuelta por IA-Lite Avirato sin llamada a Vertex' : 'Respuesta sin usage total_tokens',
                        ]
                    );
                } catch (\Throwable) {
                    // Evitar bloquear la respuesta por fallo de logging.
                }
                $decoded['tokens_remaining'] = $remaining;
            }
            if ($expIso !== null) {
                $decoded['expiry_date'] = $expIso;
            }
        } else {
            try {
                $errMsg = '';
                if (is_array($decoded) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                    $errMsg = $decoded['error']['message'];
                }
                WalletRepository::logUsage(
                    $billingLicenseId,
                    $activityType . '_error',
                    max(0, $usageTokens),
                    $sourceUrl,
                    null,
                    [
                        'vertex_http'  => $code,
                        'plan_type'    => substr((string) ($row['plan_type'] ?? ''), 0, 64),
                        'vertex_tier'  => $vertexTier,
                        'error_message'=> substr($errMsg, 0, 500),
                    ]
                );
            } catch (\Throwable) {
                // Evitar bloquear la respuesta por fallo de logging.
            }
            $decoded['tokens_remaining'] = $remaining;
            if ($expIso !== null) {
                $decoded['expiry_date'] = $expIso;
            }
        }

        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function respond402(): void
    {
        Json::respond(402, [
            'error' => [
                'message' => self::MSG_402,
                'type'    => 'insufficient_balance',
                'code'    => 'insufficient_balance',
            ],
            'digixop_insufficient_balance' => true,
        ]);
    }

    /**
     * Normaliza user_lang del cuerpo JSON (BCP-47 reducido: letras, dígitos, guion).
     */
    /**
     * @param array<string, mixed> $input
     */
    private static function inputRequestsFederationTool(array $input): bool
    {
        $tools = $input['tools'] ?? null;
        if (!is_array($tools) || $tools === []) {
            return false;
        }
        foreach ($tools as $t) {
            if (!is_array($t) || ($t['type'] ?? '') !== 'function') {
                continue;
            }
            $fn = $t['function'] ?? null;
            if (!is_array($fn)) {
                continue;
            }
            if (($fn['name'] ?? '') === 'ask_federated_node') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    private static function injectFederatedMasterSystemInstruction(array $messages): array
    {
        $snippet = 'Eres el nodo maestro de una red federada. Tienes permiso para consultar otros nodos usando la herramienta ask_federated_node si la respuesta no está en tu conocimiento local. Sé transparente: si usas información de un nodo externo, menciona brevemente la fuente (ej.: "Según el nodo remoto…").';
        $out = [];
        $done = false;
        foreach ($messages as $m) {
            if (!$done && is_array($m) && ($m['role'] ?? '') === 'system') {
                $prev = isset($m['content']) ? (string) $m['content'] : '';
                $m['content'] = $prev === '' ? $snippet : $prev . "\n\n" . $snippet;
                $done = true;
            }
            $out[] = $m;
        }
        if (!$done) {
            array_unshift($out, ['role' => 'system', 'content' => $snippet]);
        }

        return $out;
    }

    private static function normalizeUserLang(mixed $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }
        $tag = trim($raw);
        $tag = preg_replace('/[^a-zA-Z0-9\-]/', '', $tag) ?? '';
        $tag = substr($tag, 0, 35);

        return $tag;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private static function extractAviratoConfig(array $input): array
    {
        $source = [];
        if (isset($input['avirato']) && is_array($input['avirato'])) {
            $source = $input['avirato'];
        }
        $establishmentId = self::firstScalar([
            $source['establishment_id'] ?? null,
            $source['establishmentId'] ?? null,
            $source['webcode'] ?? null,
            $source['code'] ?? null,
            $input['avirato_establishment_id'] ?? null,
            $input['establishment_id'] ?? null,
        ]);
        $roomFilter = self::firstScalar([
            $source['room_filter'] ?? null,
            $source['filter_keyword'] ?? null,
            $source['inclusion_filter'] ?? null,
            $input['avirato_room_filter'] ?? null,
            $input['room_filter'] ?? null,
        ]);
        $out = [];
        if ($establishmentId !== '') {
            $out['establishment_id'] = substr($establishmentId, 0, 80);
        }
        if ($roomFilter !== '') {
            $out['room_filter'] = substr($roomFilter, 0, 120);
        }

        return $out;
    }

    /**
     * @param list<mixed> $values
     */
    private static function firstScalar(array $values): string
    {
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function extractUsageTotalTokens(array $decoded): int
    {
        $u = $decoded['usage'] ?? null;
        if (!is_array($u)) {
            return 0;
        }
        $total = (int) ($u['total_tokens'] ?? 0);
        if ($total > 0) {
            return $total;
        }
        $p = (int) ($u['prompt_tokens'] ?? 0);
        $c = (int) ($u['completion_tokens'] ?? 0);

        return $p + $c;
    }
}
