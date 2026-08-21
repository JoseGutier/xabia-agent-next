<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * Motor exclusivo Vertex AI (Gemini + text-embedding-004).
 * Traduce peticiones estilo OpenAI y devuelve JSON compatible con el plugin Xabia.
 */
final class VertexForwarder
{
    private const EMBEDDING_MODEL = 'text-embedding-004';

    /**
     * @param string $vertexTier Nivel en hub: 'pro' o 'flash' (por defecto ambos resuelven a gemini-2.5-flash por coste). Derivado de xabia_licenses.plan_type en ProxyHandler.
     *
     * @return array{http_code:int, decoded:?array, raw:string}
     */
    public static function forwardOpenAiCompatible(array $input, string $vertexTier): array
    {
        $acc = GoogleServiceAccountAuth::loadServiceAccountJson();
        if ($acc === null) {
            return self::err(503, 'GOOGLE_APPLICATION_CREDENTIALS o GOOGLE_APPLICATION_CREDENTIALS_JSON no configurado');
        }
        $token = GoogleServiceAccountAuth::fetchAccessToken();
        if ($token === null) {
            return self::err(503, 'No se pudo obtener access_token de Google');
        }
        $vertexProjectId = Env::str('XABIA_VERTEX_PROJECT_ID');
        if ($vertexProjectId === '') {
            $vertexProjectId = $acc['project_id'];
        }
        $location = Env::str('XABIA_VERTEX_LOCATION', 'europe-west1');
        $location = trim($location);
        if ($location === '') {
            $location = 'europe-west1';
        }
        $locations = self::locationCandidates($location);

        $usePro = ($vertexTier === 'pro');
        $flashModel = Env::str('XABIA_VERTEX_MODEL_FLASH', 'gemini-2.5-flash');
        $proModel = Env::str('XABIA_VERTEX_MODEL_PRO', 'gemini-2.5-flash');
        $geminiModel = $usePro ? trim((string) $proModel) : trim((string) $flashModel);
        if ($geminiModel === '') {
            $geminiModel = 'gemini-2.5-flash';
        }
        $models = self::geminiModelCandidates($geminiModel, $usePro);

        if (isset($input['messages']) && is_array($input['messages'])) {
            return self::forwardChatWithFallback($vertexProjectId, $locations, $token, $models, $input);
        }
        if (isset($input['input']) && isset($input['model'])) {
            // RGPD: por defecto misma región que el chat (p. ej. europe-west1). Opcional: XABIA_VERTEX_EMBEDDING_LOCATION.
            $embLoc = trim(Env::str('XABIA_VERTEX_EMBEDDING_LOCATION'));
            if ($embLoc === '') {
                $embLoc = trim(Env::str('XABIA_VERTEX_LOCATION', 'europe-west1'));
            }
            if ($embLoc === '') {
                $embLoc = 'europe-west1';
            }
            $embLocations = self::embeddingLocationCandidates($embLoc);

            return self::forwardEmbeddingWithFallback($vertexProjectId, $embLocations, $token, $input);
        }

        return self::err(400, 'Cuerpo no reconocido para Vertex');
    }

    /**
     * Resuelve consultas simples de disponibilidad/precios con Avirato sin llamar a Vertex.
     *
     * @param array<string, mixed> $input OpenAI chat completions shape
     * @return array{http_code:int, decoded:array, raw:string}|null
     */
    public static function tryAviratoLiteOpenAiResponse(array $input, array $aviratoConfig = []): ?array
    {
        if (!isset($input['messages']) || !is_array($input['messages'])) {
            return null;
        }
        $userText = self::latestUserMessageText($input['messages']);
        if ($userText === '' || !self::looksLikeAvailabilityIntent($userText)) {
            return null;
        }
        $params = self::extractAvailabilityParams($userText);
        $establishmentId = self::aviratoEstablishmentId($aviratoConfig);
        $roomFilter = self::aviratoRoomFilter($aviratoConfig);
        if ($establishmentId === '') {
            return self::aviratoLiteError(AviratoHandler::missingConfig(
                $params['checkin'],
                $params['checkout'],
                $params['adults'],
                $params['children'],
                $roomFilter
            ));
        }
        $availability = AviratoHandler::getAvailability(
            $establishmentId,
            $params['checkin'],
            $params['checkout'],
            $params['adults'],
            $params['children'],
            $roomFilter
        );
        if (!AviratoHandler::canAnswerAvailability($availability)) {
            return null;
        }
        $text = AviratoHandler::formatAvailabilityAnswer($availability);
        $decoded = [
            'id'            => 'avirato-lite-' . bin2hex(random_bytes(6)),
            'object'        => 'chat.completion',
            'model'         => 'xabia-ia-lite-avirato',
            'choices'       => [
                [
                    'index'         => 0,
                    'message'       => [
                        'role'    => 'assistant',
                        'content' => $text,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage'         => [
                'prompt_tokens'     => 0,
                'completion_tokens' => 0,
                'total_tokens'      => 0,
            ],
            'xabia_ia_lite' => true,
            'avirato'       => [
                'source' => $availability['source'] ?? 'avirato',
                'status' => $availability['status'] ?? 'success',
            ],
        ];

        return ['http_code' => 200, 'decoded' => $decoded, 'raw' => json_encode($decoded, JSON_UNESCAPED_UNICODE) ?: '{}'];
    }

    private static function aviratoLiteError(array $availability): array
    {
        $decoded = [
            'error' => [
                'message' => (string) ($availability['message'] ?? 'Falta la configuración de Avirato.'),
                'type'    => 'avirato_missing_config',
                'code'    => 'missing_avirato_establishment_id',
            ],
            'xabia_ia_lite' => true,
            'avirato'       => [
                'source' => $availability['source'] ?? 'avirato_missing_config',
                'status' => $availability['status'] ?? 'error',
            ],
        ];

        return ['http_code' => 400, 'decoded' => $decoded, 'raw' => json_encode($decoded, JSON_UNESCAPED_UNICODE) ?: '{}'];
    }

    private static function aviratoEstablishmentId(array $config): string
    {
        foreach (['establishment_id', 'establishmentId', 'webcode', 'code'] as $key) {
            if (isset($config[$key]) && is_scalar($config[$key])) {
                $value = trim((string) $config[$key]);
                if ($value !== '') {
                    return substr($value, 0, 80);
                }
            }
        }

        return '';
    }

    private static function aviratoRoomFilter(array $config): string
    {
        foreach (['room_filter', 'filter_keyword', 'inclusion_filter'] as $key) {
            if (isset($config[$key]) && is_scalar($config[$key])) {
                $value = trim((string) $config[$key]);
                if ($value !== '') {
                    return substr($value, 0, 120);
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $input OpenAI chat completions shape
     * @return array{http_code:int, decoded:?array, raw:string}
     */
    private static function forwardChatWithFallback(string $projectId, array $locations, string $token, array $models, array $input): array
    {
        $last = self::err(503, 'No se pudo resolver modelo/región Vertex');
        foreach ($locations as $loc) {
            foreach ($models as $model) {
                $r = self::forwardChat($projectId, (string) $loc, $token, (string) $model, $input);
                if ($r['http_code'] >= 200 && $r['http_code'] < 300) {
                    return $r;
                }
                $last = $r;
                if (!self::shouldRetryVertexFailure($r)) {
                    return $r;
                }
            }
        }

        return $last;
    }

    /**
     * @param array<string, mixed> $input OpenAI embeddings shape
     * @return array{http_code:int, decoded:?array, raw:string}
     */
    private static function forwardEmbeddingWithFallback(string $projectId, array $locations, string $token, array $input): array
    {
        $last = self::err(503, 'No se pudo resolver endpoint de embeddings en Vertex');
        foreach ($locations as $loc) {
            $r = self::forwardEmbedding($projectId, (string) $loc, $token, $input);
            if ($r['http_code'] >= 200 && $r['http_code'] < 300) {
                return $r;
            }
            $last = $r;
            if (!self::shouldRetryVertexFailure($r)) {
                return $r;
            }
        }

        return $last;
    }

    /**
     * @param array<string, mixed> $input OpenAI chat completions shape
     * @return array{http_code:int, decoded:?array, raw:string}
     */
    private static function forwardChat(string $projectId, string $location, string $token, string $geminiModel, array $input): array
    {
        $input = self::injectAviratoAvailabilityIfNeeded($input);
        $body = self::openAiMessagesToGeminiGenerateContent($input);
        if (($body['contents'] ?? []) === []) {
            return self::err(400, 'Sin mensajes user/assistant válidos para Gemini');
        }
        $url = sprintf(
            'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:generateContent',
            $location,
            rawurlencode($projectId),
            rawurlencode($location),
            rawurlencode($geminiModel)
        );
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return self::err(400, 'No se pudo serializar la petición Gemini');
        }
        $raw = self::curlJson($url, $token, $json);
        $gem = json_decode($raw['body'], true);
        if (!is_array($gem)) {
            return ['http_code' => $raw['code'] > 0 ? $raw['code'] : 502, 'decoded' => null, 'raw' => $raw['body']];
        }
        if ($raw['code'] < 200 || $raw['code'] >= 300) {
            return [
                'http_code' => $raw['code'],
                'decoded'   => self::geminiErrorToOpenAiShape($gem),
                'raw'       => $raw['body'],
            ];
        }
        $openAi = self::geminiGenerateContentToOpenAiChat($gem, (string) ($input['model'] ?? $geminiModel));

        return ['http_code' => 200, 'decoded' => $openAi, 'raw' => json_encode($openAi, JSON_UNESCAPED_UNICODE)];
    }

    /**
     * @param array<string, mixed> $input OpenAI embeddings shape
     * @return array{http_code:int, decoded:?array, raw:string}
     */
    private static function forwardEmbedding(string $projectId, string $location, string $token, array $input): array
    {
        $instances = self::openAiInputToEmbeddingInstances($input['input'] ?? '');
        if ($instances === []) {
            return self::err(400, 'input vacío para embeddings');
        }
        $vertexBody = ['instances' => $instances];
        $m = self::EMBEDDING_MODEL;
        $url = sprintf(
            'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:predict',
            $location,
            rawurlencode($projectId),
            rawurlencode($location),
            rawurlencode($m)
        );
        $json = json_encode($vertexBody, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return self::err(400, 'JSON predict inválido');
        }
        $raw = self::curlJson($url, $token, $json);
        $pred = json_decode($raw['body'], true);
        if (!is_array($pred)) {
            return ['http_code' => $raw['code'] > 0 ? $raw['code'] : 502, 'decoded' => null, 'raw' => $raw['body']];
        }
        if ($raw['code'] < 200 || $raw['code'] >= 300) {
            return [
                'http_code' => $raw['code'],
                'decoded'   => self::vertexPredictErrorToOpenAi($pred),
                'raw'       => $raw['body'],
            ];
        }
        $usageTokens = self::extractEmbeddingBillableTokens($pred, $instances);
        $openAi = self::vertexPredictToOpenAiEmbeddings($pred, $input, $usageTokens);

        return ['http_code' => 200, 'decoded' => $openAi, 'raw' => json_encode($openAi, JSON_UNESCAPED_UNICODE)];
    }

    /**
     * @return array{code:int, body:string}
     */
    private static function curlJson(string $url, string $token, string $json): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 180,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            return ['code' => 502, 'body' => '{"error":{"message":"curl failure","type":"xabia_vertex"}}'];
        }

        return ['code' => $code > 0 ? $code : 502, 'body' => $body];
    }

    /**
     * @param array<string, mixed> $openAi
     * @return array<string, mixed>
     */
    private static function openAiMessagesToGeminiGenerateContent(array $openAi): array
    {
        $messages = $openAi['messages'] ?? [];
        if (!is_array($messages)) {
            $messages = [];
        }
        $systemParts = [];
        $systemParts[] = ['text' => self::userLangGeminiSystemSnippet($openAi['user_lang'] ?? null)];
        $contents = [];
        /** @var array<string, string> */
        $toolCallIdToName = [];
        foreach ($messages as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = (string) ($m['role'] ?? 'user');
            if ($role === 'system') {
                $text = self::openAiContentToPlainText($m['content'] ?? '');
                if ($text !== '') {
                    $systemParts[] = ['text' => $text];
                }

                continue;
            }
            if ($role === 'assistant') {
                $parts = [];
                $text = self::openAiContentToPlainText($m['content'] ?? '');
                if ($text !== '') {
                    $parts[] = ['text' => $text];
                }
                $tcs = $m['tool_calls'] ?? null;
                if (is_array($tcs)) {
                    foreach ($tcs as $tc) {
                        if (!is_array($tc)) {
                            continue;
                        }
                        $fn = $tc['function'] ?? null;
                        if (!is_array($fn)) {
                            continue;
                        }
                        $fname = (string) ($fn['name'] ?? '');
                        $argsRaw = $fn['arguments'] ?? '{}';
                        $args = is_string($argsRaw) ? json_decode($argsRaw, true) : $argsRaw;
                        if (!is_array($args)) {
                            $args = [];
                        }
                        $tid = (string) ($tc['id'] ?? '');
                        if ($tid !== '' && $fname !== '') {
                            $toolCallIdToName[$tid] = $fname;
                        }
                        if ($fname !== '') {
                            $parts[] = [
                                'functionCall' => [
                                    'name' => $fname,
                                    'args' => $args,
                                ],
                            ];
                        }
                    }
                }
                if ($parts !== []) {
                    $contents[] = ['role' => 'model', 'parts' => $parts];
                }

                continue;
            }
            if ($role === 'tool') {
                $tid = (string) ($m['tool_call_id'] ?? '');
                $fname = $toolCallIdToName[$tid] ?? (string) ($m['name'] ?? '');
                if ($fname === '') {
                    continue;
                }
                $toolText = self::openAiContentToPlainText($m['content'] ?? '');
                $contents[] = [
                    'role'  => 'user',
                    'parts' => [
                        [
                            'functionResponse' => [
                                'name'     => $fname,
                                'response' => ['result' => $toolText],
                            ],
                        ],
                    ],
                ];

                continue;
            }
            $text = self::openAiContentToPlainText($m['content'] ?? '');
            if ($text === '') {
                continue;
            }
            $gRole = 'user';
            $contents[] = [
                'role'  => $gRole,
                'parts' => [['text' => $text]],
            ];
        }
        $out = ['contents' => $contents];
        if ($systemParts !== []) {
            $out['systemInstruction'] = ['parts' => $systemParts];
        }
        $temp = isset($openAi['temperature']) ? (float) $openAi['temperature'] : 0.2;
        $maxOut = isset($openAi['max_tokens']) ? (int) $openAi['max_tokens'] : (isset($openAi['max_completion_tokens']) ? (int) $openAi['max_completion_tokens'] : 1024);
        if ($maxOut < 1) {
            $maxOut = 1024;
        }
        if ($maxOut > 8192) {
            $maxOut = 8192;
        }
        $out['generationConfig'] = [
            'temperature'     => max(0.0, min(2.0, $temp)),
            'maxOutputTokens' => $maxOut,
            'candidateCount'  => 1,
        ];

        $decls = self::openAiToolsToVertexFunctionDeclarations($openAi['tools'] ?? null);
        if ($decls !== []) {
            $out['tools'] = [['functionDeclarations' => $decls]];
            $out['toolConfig'] = [
                'functionCallingConfig' => [
                    'mode' => 'AUTO',
                ],
            ];
        }

        return $out;
    }

    /**
     * @param mixed $tools OpenAI chat "tools" array (type + function.name/description/parameters)
     *
     * @return list<array<string, mixed>>
     */
    private static function openAiToolsToVertexFunctionDeclarations(mixed $tools): array
    {
        if (!is_array($tools) || $tools === []) {
            return [];
        }
        $decls = [];
        foreach ($tools as $t) {
            if (!is_array($t)) {
                continue;
            }
            if (($t['type'] ?? '') !== 'function') {
                continue;
            }
            $fn = $t['function'] ?? null;
            if (!is_array($fn)) {
                continue;
            }
            $name = trim((string) ($fn['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $desc = isset($fn['description']) ? (string) $fn['description'] : '';
            $params = $fn['parameters'] ?? null;
            if (!is_array($params)) {
                $params = ['type' => 'object', 'properties' => new \stdClass()];
            }
            $params = self::normalizeOpenAiJsonSchemaForGemini($params);
            $decls[] = [
                'name'        => $name,
                'description' => $desc,
                'parameters'  => $params,
            ];
        }

        return $decls;
    }

    /**
     * Ajusta JSON Schema estilo OpenAI a lo que Gemini (Vertex) acepta de forma fiable:
     * objetos con `properties` vacío deben serializarse como `{}`, no `[]`.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function normalizeOpenAiJsonSchemaForGemini(array $schema): array
    {
        $out = [];
        foreach ($schema as $k => $v) {
            if ($k === 'properties' && is_array($v) && $v === []) {
                $out[$k] = new \stdClass();

                continue;
            }
            if ($k === 'properties' && is_array($v)) {
                $nested = [];
                foreach ($v as $pk => $pv) {
                    $nested[$pk] = is_array($pv) ? self::normalizeOpenAiJsonSchemaForGemini($pv) : $pv;
                }
                $out[$k] = $nested;

                continue;
            }
            if (($k === 'items' || $k === 'additionalProperties') && is_array($v)) {
                $out[$k] = self::normalizeOpenAiJsonSchemaForGemini($v);

                continue;
            }
            if (($k === 'anyOf' || $k === 'oneOf' || $k === 'allOf') && is_array($v)) {
                $branches = [];
                foreach ($v as $branch) {
                    $branches[] = is_array($branch) ? self::normalizeOpenAiJsonSchemaForGemini($branch) : $branch;
                }
                $out[$k] = $branches;

                continue;
            }
            if (is_array($v)) {
                $out[$k] = self::normalizeOpenAiJsonSchemaForGemini($v);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * Bloque políglota inyectado al inicio de systemInstruction hacia Vertex (Gemini).
     * user_lang del payload = fallback de interfaz/sitio; NO fuerza el idioma de respuesta.
     */
    private static function userLangGeminiSystemSnippet(mixed $raw): string
    {
        $block = "# GESTIÓN DE IDIOMAS Y TRADUCCIÓN AUTOMÁTICA:\n"
            . "- Responde siempre en el mismo idioma en el que te ha hablado el usuario en su último mensaje ('user_lang').\n"
            . "- Si el contexto RAG recuperado está en un idioma diferente, procésalo internamente, tradúcelo y redacta la respuesta final en el idioma del interlocutor de forma nativa.\n"
            . "- Usa el idioma de origen solo como fallback si el mensaje del usuario es ambiguo.";

        if (!is_string($raw)) {
            return $block;
        }
        $tag = trim($raw);
        $tag = preg_replace('/[^a-zA-Z0-9\-]/', '', $tag) ?? '';
        $tag = substr(strtolower($tag), 0, 35);
        if ($tag === '') {
            return $block;
        }

        return $block . "\n- Idioma de origen (fallback del sitio/shortcode): " . $tag . '.';
    }

    /**
     * @param mixed $content
     */
    private static function openAiContentToPlainText($content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }
        $s = '';
        foreach ($content as $part) {
            if (is_string($part)) {
                $s .= $part;
            } elseif (is_array($part) && isset($part['text'])) {
                $s .= (string) $part['text'];
            }
        }

        return $s;
    }

    /**
     * @param array<string, mixed> $gem
     * @return array<string, mixed>
     */
    private static function geminiGenerateContentToOpenAiChat(array $gem, string $modelEcho): array
    {
        $text = '';
        $toolCalls = [];
        $cand = $gem['candidates'][0] ?? null;
        if (is_array($cand)) {
            $parts = $cand['content']['parts'] ?? [];
            if (is_array($parts)) {
                foreach ($parts as $p) {
                    if (!is_array($p)) {
                        continue;
                    }
                    if (isset($p['text'])) {
                        $text .= (string) $p['text'];
                    }
                    if (isset($p['functionCall']) && is_array($p['functionCall'])) {
                        $fc = $p['functionCall'];
                        $name = (string) ($fc['name'] ?? '');
                        $args = $fc['args'] ?? [];
                        if (!is_array($args)) {
                            $args = [];
                        }
                        $argsJson = json_encode($args, JSON_UNESCAPED_UNICODE);
                        if ($argsJson === false) {
                            $argsJson = '{}';
                        }
                        $toolCalls[] = [
                            'id'       => 'call_vertex_' . bin2hex(random_bytes(6)),
                            'type'     => 'function',
                            'function' => [
                                'name'      => $name,
                                'arguments' => $argsJson,
                            ],
                        ];
                    }
                }
            }
        }
        $um = $gem['usageMetadata'] ?? [];
        $pt = is_array($um) ? (int) ($um['promptTokenCount'] ?? 0) : 0;
        $ct = is_array($um) ? (int) ($um['candidatesTokenCount'] ?? 0) : 0;
        $tt = is_array($um) ? (int) ($um['totalTokenCount'] ?? 0) : 0;
        if ($tt < 1) {
            $tt = $pt + $ct;
        }

        $msg = ['role' => 'assistant', 'content' => $text !== '' ? $text : null];
        if ($toolCalls !== []) {
            $msg['tool_calls'] = $toolCalls;
        }
        $finish = $toolCalls !== [] ? 'tool_calls' : 'stop';
        if (is_array($cand) && isset($cand['finishReason']) && is_string($cand['finishReason']) && $cand['finishReason'] !== '') {
            $fr = strtolower($cand['finishReason']);
            if ($fr === 'stop' || $fr === 'tool_calls' || $fr === 'length' || $fr === 'max_tokens') {
                $finish = $fr === 'max_tokens' ? 'length' : $fr;
            }
        }

        return [
            'id'      => 'vertex-chatcmpl-' . bin2hex(random_bytes(6)),
            'object'  => 'chat.completion',
            'model'   => $modelEcho,
            'choices' => [
                [
                    'index'         => 0,
                    'message'       => $msg,
                    'finish_reason' => $finish,
                ],
            ],
            'usage' => [
                'prompt_tokens'     => $pt,
                'completion_tokens' => $ct,
                'total_tokens'      => $tt > 0 ? $tt : $pt + $ct,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $gem
     * @return array<string, mixed>
     */
    private static function geminiErrorToOpenAiShape(array $gem): array
    {
        $msg = 'Vertex error';
        if (isset($gem['error']['message'])) {
            $msg = (string) $gem['error']['message'];
        }

        return [
            'error' => [
                'message' => $msg,
                'type'    => 'vertex_error',
                'code'    => $gem['error']['code'] ?? null,
            ],
        ];
    }

    /**
     * @return list<array{content:string}>
     */
    private static function openAiInputToEmbeddingInstances($input): array
    {
        if (is_string($input)) {
            $t = trim($input);

            return $t === '' ? [] : [['content' => $t]];
        }
        if (!is_array($input)) {
            return [];
        }
        $out = [];
        foreach ($input as $line) {
            if (is_string($line)) {
                $line = trim($line);
                if ($line !== '') {
                    $out[] = ['content' => $line];
                }
            }
        }

        return $out;
    }

    /**
     * Campos de Vertex que cuentan TOKENS (saldo Xabia = unidades tipo LLM).
     *
     * @param array<string, mixed> $bag
     */
    private static function readEmbeddingTokenFieldsFromBag(array $bag): int
    {
        foreach (['totalTokenCount', 'promptTokenCount', 'token_count'] as $k) {
            if (isset($bag[$k]) && is_numeric($bag[$k])) {
                $n = (int) $bag[$k];

                return $n > 0 ? $n : 0;
            }
        }

        return 0;
    }

    /**
     * Campos de Vertex en CARACTERES facturables: no son tokens; aproximación conservadora ÷4.
     *
     * @param array<string, mixed> $bag
     */
    private static function readEmbeddingCharsAsApproxTokensFromBag(array $bag): int
    {
        foreach (['billableCharacterCount', 'totalBillableCharacters', 'billable_character_count'] as $k) {
            if (isset($bag[$k]) && is_numeric($bag[$k])) {
                $chars = max(0, (int) $bag[$k]);

                return max(1, (int) ceil($chars / 4));
            }
        }

        return 0;
    }

    /**
     * @param list<array{content:string}> $instances
     */
    private static function extractEmbeddingBillableTokens(array $pred, array $instances): int
    {
        $meta = $pred['metadata'] ?? null;
        if (is_array($meta)) {
            $fromTok = self::readEmbeddingTokenFieldsFromBag($meta);
            if ($fromTok > 0) {
                return $fromTok;
            }
            $fromChars = self::readEmbeddingCharsAsApproxTokensFromBag($meta);
            if ($fromChars > 0) {
                return $fromChars;
            }
        }
        $sum = 0;
        foreach ($pred['predictions'] ?? [] as $p) {
            if (!is_array($p)) {
                continue;
            }
            $fromTok = self::readEmbeddingTokenFieldsFromBag($p);
            if ($fromTok > 0) {
                $sum += $fromTok;

                continue;
            }
            $fromChars = self::readEmbeddingCharsAsApproxTokensFromBag($p);
            if ($fromChars > 0) {
                $sum += $fromChars;

                continue;
            }
            $emb = $p['embeddings'] ?? null;
            if (is_array($emb) && isset($emb['statistics']['token_count']) && is_numeric($emb['statistics']['token_count'])) {
                $sum += max(1, (int) $emb['statistics']['token_count']);
            }
        }
        if ($sum > 0) {
            return $sum;
        }
        $len = 0;
        foreach ($instances as $ins) {
            $len += strlen($ins['content'] ?? '');
        }

        return max(1, (int) ceil($len / 4));
    }

    /**
     * @param array<string, mixed> $pred
     * @param array<string, mixed> $inputOriginal
     * @return array<string, mixed>
     */
    private static function vertexPredictToOpenAiEmbeddings(array $pred, array $inputOriginal, int $usageTokens): array
    {
        $data = [];
        $predictions = $pred['predictions'] ?? [];
        if (is_array($predictions)) {
            foreach ($predictions as $idx => $p) {
                if (!is_array($p)) {
                    continue;
                }
                $vals = null;
                if (isset($p['embeddings']['values']) && is_array($p['embeddings']['values'])) {
                    $vals = $p['embeddings']['values'];
                } elseif (isset($p['embeddings']) && is_array($p['embeddings']) && isset($p['embeddings'][0])) {
                    $vals = $p['embeddings'];
                }
                if ($vals !== null) {
                    $data[] = ['object' => 'embedding', 'embedding' => array_map('floatval', $vals), 'index' => $idx];
                }
            }
        }

        return [
            'object' => 'list',
            'model'  => self::EMBEDDING_MODEL,
            'data'   => $data,
            'usage'  => [
                'prompt_tokens'     => $usageTokens,
                'completion_tokens' => 0,
                'total_tokens'      => $usageTokens,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $pred
     * @return array<string, mixed>
     */
    private static function vertexPredictErrorToOpenAi(array $pred): array
    {
        $msg = 'Embedding Vertex error';
        if (isset($pred['error']['message'])) {
            $msg = (string) $pred['error']['message'];
        }

        return ['error' => ['message' => $msg, 'type' => 'vertex_embedding']];
    }

    /**
     * @return list<string>
     */
    private static function locationCandidates(string $primary): array
    {
        $primary = trim($primary);
        $candidates = [$primary];
        // Segunda región por defecto (compatibilidad). En .env: XABIA_VERTEX_LOCATION_FALLBACK=none para no probar USA.
        $fallback = Env::str('XABIA_VERTEX_LOCATION_FALLBACK', 'us-central1');
        if (strcasecmp($fallback, 'none') === 0) {
            $fallback = '';
        }
        if ($fallback !== '' && $fallback !== $primary) {
            $candidates[] = $fallback;
        }

        return array_values(array_unique(array_filter($candidates, static fn ($v) => is_string($v) && $v !== '')));
    }

    /**
     * Regiones para embeddings: por defecto solo la primaria (sin reintento automático en USA).
     * Opcional: XABIA_VERTEX_EMBEDDING_LOCATION_FALLBACK (p. ej. otra región UE) o none.
     *
     * @return list<string>
     */
    private static function embeddingLocationCandidates(string $primary): array
    {
        $primary = trim($primary);
        if ($primary === '') {
            $primary = 'europe-west1';
        }
        $candidates = [$primary];
        $fallback = trim(Env::str('XABIA_VERTEX_EMBEDDING_LOCATION_FALLBACK'));
        if ($fallback === '' || strcasecmp($fallback, 'none') === 0) {
            return array_values(array_unique(array_filter($candidates, static fn ($v) => is_string($v) && $v !== '')));
        }
        if ($fallback !== $primary) {
            $candidates[] = $fallback;
        }

        return array_values(array_unique(array_filter($candidates, static fn ($v) => is_string($v) && $v !== '')));
    }

    /**
     * @return list<string>
     */
    private static function geminiModelCandidates(string $primary, bool $usePro): array
    {
        $primary = trim($primary);
        $models = [];
        if ($primary !== '') {
            $models[] = $primary;
            // Alias legado 1.5 → catálogo Vertex actual (Aktiba / 2026).
            if (preg_match('/^gemini-1\.5-flash(-001|-002)?$/i', $primary) === 1) {
                $models[] = 'gemini-2.5-flash';
            }
            if (preg_match('/^gemini-1\.5-pro(-001|-002)?$/i', $primary) === 1) {
                $models[] = 'gemini-2.5-flash';
            }
            if (preg_match('/^gemini-2\.5-flash$/i', $primary) === 1) {
                $models[] = 'gemini-2.5-flash-001';
            }
            if (preg_match('/^gemini-2\.5-flash-001$/i', $primary) === 1) {
                $models[] = 'gemini-2.5-flash';
            }
            if (preg_match('/^gemini-2\.5-pro$/i', $primary) === 1) {
                $models[] = 'gemini-2.5-pro-001';
            }
            if (preg_match('/^gemini-2\.5-pro-001$/i', $primary) === 1) {
                $models[] = 'gemini-2.5-pro';
            }
            if (preg_match('/^gemini-2\.0-flash$/i', $primary) === 1) {
                $models[] = 'gemini-2.0-flash-001';
            }
            if (preg_match('/^gemini-2\.0-flash-lite$/i', $primary) === 1) {
                $models[] = 'gemini-2.0-flash-lite-001';
            }
            if (preg_match('/^gemini-2\.0-flash-001$/i', $primary) === 1) {
                $models[] = 'gemini-2.0-flash';
            }
            if (preg_match('/^gemini-2\.0-flash-lite-001$/i', $primary) === 1) {
                $models[] = 'gemini-2.0-flash-lite';
            }
        }
        $primaryIsGemini2 = $primary !== '' && preg_match('/^gemini-2\./i', $primary) === 1;
        if ($usePro) {
            if (!$primaryIsGemini2) {
                $models[] = 'gemini-2.5-flash';
                $models[] = 'gemini-2.5-flash-001';
            }
        } elseif (!$primaryIsGemini2) {
            $models[] = 'gemini-2.5-flash';
            $models[] = 'gemini-2.5-flash-001';
        }

        return array_values(array_unique(array_filter($models, static fn ($v) => is_string($v) && $v !== '')));
    }

    /**
     * @param array{http_code:int, decoded:?array, raw:string} $res
     */
    private static function shouldRetryVertexFailure(array $res): bool
    {
        if (($res['http_code'] ?? 0) < 400) {
            return false;
        }
        $msg = '';
        if (is_array($res['decoded']) && isset($res['decoded']['error']['message']) && is_string($res['decoded']['error']['message'])) {
            $msg = strtolower($res['decoded']['error']['message']);
        }

        return str_contains($msg, 'not found')
            || str_contains($msg, 'does not have access')
            || str_contains($msg, 'permission')
            || str_contains($msg, 'publisher model');
    }

    /**
     * Si el usuario pregunta por disponibilidad/fechas, consultamos Avirato antes de enviar a Gemini.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function injectAviratoAvailabilityIfNeeded(array $input): array
    {
        $userText = self::latestUserMessageText($input['messages'] ?? null);
        if ($userText === '' || !self::looksLikeAvailabilityIntent($userText)) {
            return $input;
        }
        $params = self::extractAvailabilityParams($userText);
        $aviratoConfig = isset($input['avirato']) && is_array($input['avirato']) ? $input['avirato'] : [];
        $establishmentId = self::aviratoEstablishmentId($aviratoConfig);
        if ($establishmentId === '') {
            return $input;
        }
        $roomFilterKeyword = self::aviratoRoomFilter($aviratoConfig);
        $availability = AviratoHandler::getAvailability(
            $establishmentId,
            $params['checkin'],
            $params['checkout'],
            $params['adults'],
            $params['children'],
            $roomFilterKeyword
        );
        $note = 'DATOS DE DISPONIBILIDAD (Avirato): ' . json_encode($availability, JSON_UNESCAPED_UNICODE)
            . ' Usa estos datos antes de responder. No inventes disponibilidad ni precios.';

        $messages = $input['messages'] ?? [];
        if (!is_array($messages)) {
            $messages = [];
        }
        array_unshift($messages, ['role' => 'system', 'content' => $note]);
        $input['messages'] = $messages;

        return $input;
    }

    /**
     * @param mixed $messages
     */
    private static function latestUserMessageText(mixed $messages): string
    {
        if (!is_array($messages)) {
            return '';
        }
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $m = $messages[$i] ?? null;
            if (!is_array($m) || (string) ($m['role'] ?? '') !== 'user') {
                continue;
            }
            return trim(self::openAiContentToPlainText($m['content'] ?? ''));
        }

        return '';
    }

    private static function looksLikeAvailabilityIntent(string $text): bool
    {
        $t = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        foreach (['disponibilidad', 'disponible', 'fecha', 'fechas', 'reserva', 'reservar', 'habitacion', 'dormir', 'booking'] as $kw) {
            if (str_contains($t, $kw)) {
                return true;
            }
        }
        if (preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $text) === 1) {
            return true;
        }
        if (preg_match('/\b\d{1,2}\/\d{1,2}\/\d{4}\b/', $text) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @return array{checkin:string, checkout:string, adults:int, children:int}
     */
    private static function extractAvailabilityParams(string $text): array
    {
        $dates = [];
        if (preg_match_all('/\b(\d{4})-(\d{2})-(\d{2})\b/', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $dates[] = sprintf('%04d-%02d-%02d', (int) $hit[1], (int) $hit[2], (int) $hit[3]);
            }
        }
        if (count($dates) < 2 && preg_match_all('/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\b/', $text, $m2, PREG_SET_ORDER)) {
            foreach ($m2 as $hit) {
                $dates[] = sprintf('%04d-%02d-%02d', (int) $hit[3], (int) $hit[2], (int) $hit[1]);
            }
        }
        $today = new \DateTimeImmutable('now');
        $checkin = $dates[0] ?? $today->modify('+1 day')->format('Y-m-d');
        $checkout = $dates[1] ?? (new \DateTimeImmutable($checkin))->modify('+1 day')->format('Y-m-d');

        $adults = 2;
        if (preg_match('/(\d+)\s*(adult|adulto|adultos|persona|personas)/iu', $text, $am) === 1) {
            $adults = max(1, (int) $am[1]);
        }
        $children = 0;
        if (preg_match('/(\d+)\s*(niñ|nino|child|children)/iu', $text, $cm) === 1) {
            $children = max(0, (int) $cm[1]);
        }

        return [
            'checkin'  => $checkin,
            'checkout' => $checkout,
            'adults'   => $adults,
            'children' => $children,
        ];
    }

    /**
     * @return array{http_code:int, decoded:array, raw:string}
     */
    private static function err(int $code, string $message): array
    {
        $d = ['error' => ['message' => $message, 'type' => 'xabia_vertex_config']];

        return ['http_code' => $code, 'decoded' => $d, 'raw' => json_encode($d)];
    }
}
