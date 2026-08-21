<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * Traducción de saludos de agente vía Vertex (Gemini) para WPML String Translation.
 */
final class DtpTranslator
{
    /**
     * @param list<string> $targetLangs Códigos ISO cortos (eu, en, fr…).
     * @return array<string, string>|null mapa lang => texto traducido
     */
    public static function translateGreeting(string $text, string $sourceLang, array $targetLangs): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $targets = [];
        foreach ($targetLangs as $lang) {
            if (!is_string($lang) && !is_numeric($lang)) {
                continue;
            }
            $code = strtolower(trim((string) $lang));
            if ($code !== '' && preg_match('/^[a-z]{2,3}(?:[-_][a-z]{2})?$/i', $code) === 1) {
                $targets[] = $code;
            }
        }
        $targets = array_values(array_unique($targets));
        if ($targets === []) {
            return null;
        }

        $sourceLang = trim($sourceLang) !== '' ? trim($sourceLang) : 'es';
        $langList = implode(', ', $targets);
        $userPrompt = 'Translate the following chat widget greeting from language "' . $sourceLang . '" '
            . 'into these target languages: ' . $langList . ".\n"
            . "Return ONLY a valid JSON object whose keys are the target language codes and values are the translated strings.\n"
            . "Preserve emojis, tone and line breaks. Do not wrap the JSON in markdown.\n\n"
            . $text;

        $input = [
            'messages' => [
                [
                    'role'    => 'system',
                    'content' => 'You are a professional translator for WordPress UI strings. Output raw JSON only.',
                ],
                [
                    'role'    => 'user',
                    'content' => $userPrompt,
                ],
            ],
            'max_tokens'  => 1200,
            'temperature' => 0.2,
        ];

        $tier = Env::str('XABIA_DTP_VERTEX_TIER', 'flash');
        $tier = strtolower(trim($tier));
        if ($tier !== 'pro') {
            $tier = 'flash';
        }

        $result = VertexForwarder::forwardOpenAiCompatible($input, $tier);
        $http = (int) ($result['http_code'] ?? 0);
        if ($http < 200 || $http >= 300) {
            return null;
        }

        $decoded = $result['decoded'] ?? null;
        if (!is_array($decoded)) {
            return null;
        }

        $content = (string) ($decoded['choices'][0]['message']['content'] ?? '');
        if ($content === '') {
            return null;
        }

        return self::parseTranslationsJson($content, $targets);
    }

    /**
     * @param list<string> $expectedLangs
     * @return array<string, string>|null
     */
    private static function parseTranslationsJson(string $raw, array $expectedLangs): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $m) === 1) {
            $raw = trim((string) ($m[1] ?? ''));
        }

        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            $start = strpos($raw, '{');
            $end = strrpos($raw, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $parsed = json_decode(substr($raw, $start, $end - $start + 1), true);
            }
        }
        if (!is_array($parsed)) {
            return null;
        }

        $out = [];
        foreach ($expectedLangs as $lang) {
            $candidates = [$lang, str_replace('_', '-', $lang), strtoupper($lang)];
            foreach ($candidates as $key) {
                if (!array_key_exists($key, $parsed)) {
                    continue;
                }
                $val = trim((string) $parsed[$key]);
                if ($val !== '') {
                    $out[$lang] = $val;
                    break;
                }
            }
        }

        return $out !== [] ? $out : null;
    }
}
