<?php
/**
 * Xabia Agent — llm-intent.php
 * Motor LLM opcional (ChatGPT) para interpretar la intención del usuario.
 * Si falla → fallback completo al motor local.
 */

if (!defined('ABSPATH')) exit;

/**
 * Llama a ChatGPT para interpretar la intención del usuario.
 *
 * @param string $msg
 * @param array  $context
 * @return array{
 *   ok: bool,
 *   intent?: string,
 *   target?: string|null,
 *   rewrite?: string|null,
 *   action?: array|null,
 *   raw?: mixed
 * }
 */
function xabia_llm_understand(string $msg, array $context = []) : array {

    // 1) ¿Tenemos API Key?
    $key = get_option('xabia_openai_key');
    if (!$key) {
        return ['ok' => false];
    }

    // 2) Preparamos prompt simple (no RAG, no embeddings)
    $system = "Eres Xabia, un analizador de intención. Devuelves JSON. No escribes texto humano.";

    $prompt = [
        [
            "role" => "system",
            "content" => $system
        ],
        [
            "role" => "user",
            "content" =>
                "Mensaje: " . $msg . "\n\n" .
                "Contexto:\n" . json_encode($context, JSON_UNESCAPED_UNICODE) . "\n\n" .
                "Devuelve STRICTAMENTE un JSON con esta forma:\n" .
                "{ \"intent\": \"call|web|book|price|ficha|list|planner|find|none\", " .
                "\"target\": \"nombre empresa o null\", " .
                "\"rewrite\": \"versión clara de la consulta\", " .
                "\"action\": null }"
        ]
    ];

    // 3) Llamada a la API de OpenAI
    $url = "https://api.openai.com/v1/chat/completions";

    $args = [
        "headers" => [
            "Content-Type"  => "application/json",
            "Authorization" => "Bearer {$key}",
        ],
        "body" => json_encode([
            "model" => "gpt-4o-mini",
            "messages" => $prompt,
            "temperature" => 0.2,
        ])
    ];

    $res = wp_remote_post($url, $args);

    // 4) Si falla → fallback local
    if (is_wp_error($res)) {
        return ['ok' => false];
    }

    $json = json_decode(wp_remote_retrieve_body($res), true);

    if (!is_array($json) || empty($json['choices'][0]['message']['content'])) {
        return ['ok' => false];
    }

    $content = trim($json['choices'][0]['message']['content']);

    // 5) Intentamos decodificar el JSON generado
    $parsed = json_decode($content, true);

    if (!is_array($parsed)) {
        return ['ok' => false];
    }

    // 6) Normalizar y devolver
    return [
        'ok'     => true,
        'intent' => $parsed['intent']  ?? 'none',
        'target' => $parsed['target']  ?? null,
        'rewrite'=> $parsed['rewrite'] ?? null,
        'action' => $parsed['action']  ?? null,
        'raw'    => $parsed
    ];
}