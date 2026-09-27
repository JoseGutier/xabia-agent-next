<?php
/**
 * Lee un POST que responde en Server-Sent Events (Vertex o OpenAI)
 * y separa cada trozo de texto del cierre JSON.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Llm_Stream {

    /**
     * @param array<string, string> $headers
     * @param callable(string):void $on_delta
     * @return array{ok:bool, streamed:bool, text:string, finish_reason:string, http_code:int, error:string, usage:array<string, int>, json:array<string, mixed>|null}
     */
    public static function post(string $url, array $headers, string $body, string $mode, callable $on_delta): array {
        $result = [
            'ok'            => false,
            'streamed'      => false,
            'text'          => '',
            'finish_reason' => '',
            'http_code'     => 0,
            'error'         => '',
            'usage'         => [],
            'json'          => null,
        ];
        if (!function_exists('curl_init')) {
            $result['error'] = 'curl no disponible';

            return $result;
        }

        $raw = '';
        $carry = '';
        $acc = '';
        $saw_sse = false;
        $header_lines = [];
        foreach ($headers as $key => $value) {
            if (is_int($key)) {
                $header_lines[] = (string) $value;
                continue;
            }
            $header_lines[] = $key . ': ' . $value;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $header_lines,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => static function ($ch, $chunk) use (&$raw, &$carry, &$acc, &$saw_sse, &$result, $mode, $on_delta) {
                $raw .= $chunk;
                if ($result['http_code'] === 0) {
                    $result['http_code'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                }
                $carry .= $chunk;
                while (($pos = strpos($carry, "\n")) !== false) {
                    $line = rtrim(substr($carry, 0, $pos), "\r");
                    $carry = substr($carry, $pos + 1);
                    if ($line === '' || (isset($line[0]) && $line[0] === ':')) {
                        continue;
                    }
                    if (stripos($line, 'data:') !== 0) {
                        continue;
                    }
                    $data = trim(substr($line, 5));
                    if ($data === '' || $data === '[DONE]') {
                        $saw_sse = true;
                        continue;
                    }
                    $parsed = $mode === 'vertex'
                        ? self::parse_vertex_event($data, $acc)
                        : self::parse_openai_event($data);
                    $saw_sse = true;
                    if ($parsed['delta'] !== '') {
                        $acc = $mode === 'vertex' ? $parsed['text'] : ($acc . $parsed['delta']);
                        $result['streamed'] = true;
                        $on_delta($parsed['delta']);
                    } elseif ($mode === 'vertex' && $parsed['text'] !== '') {
                        $acc = $parsed['text'];
                    }
                    if ($parsed['finish_reason'] !== '') {
                        $result['finish_reason'] = $parsed['finish_reason'];
                    }
                    if ($parsed['usage'] !== []) {
                        $result['usage'] = $parsed['usage'];
                    }
                }

                return strlen($chunk);
            },
        ]);
        $exec = curl_exec($ch);
        $result['http_code'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($exec === false && !$saw_sse) {
            $result['error'] = $err !== '' ? $err : 'curl';

            return $result;
        }

        if (!$saw_sse) {
            $json = json_decode($raw, true);
            $result['json'] = is_array($json) ? $json : null;
            $result['ok'] = $result['http_code'] >= 200 && $result['http_code'] < 300 && is_array($json);

            return $result;
        }

        $result['text'] = $acc;
        $result['ok'] = $result['http_code'] >= 200 && $result['http_code'] < 300;
        if (!$result['ok'] && $result['error'] === '' && $acc === '') {
            $result['error'] = $err !== '' ? $err : 'stream vacío';
        }

        return $result;
    }

    /**
     * @return array{delta: string, text: string, finish_reason: string, usage: array<string, int>}
     */
    public static function parse_vertex_event(string $json, string $accumulated): array {
        $out = [
            'delta'         => '',
            'text'          => $accumulated,
            'finish_reason' => '',
            'usage'         => [],
        ];
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $out;
        }
        $piece = '';
        $parts = $data['candidates'][0]['content']['parts'] ?? null;
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $piece .= (string) $part['text'];
                }
            }
        }
        if ($piece !== '') {
            if ($accumulated !== '' && str_starts_with($piece, $accumulated)) {
                $out['delta'] = substr($piece, strlen($accumulated));
                $out['text'] = $piece;
            } else {
                $out['delta'] = $piece;
                $out['text'] = $accumulated . $piece;
            }
        }
        $finish = $data['candidates'][0]['finishReason'] ?? '';
        if (is_string($finish) && trim($finish) !== '') {
            $out['finish_reason'] = strtolower(trim($finish));
        }
        $usage = $data['usageMetadata'] ?? null;
        if (is_array($usage)) {
            $prompt = (int) ($usage['promptTokenCount'] ?? 0);
            $completion = (int) ($usage['candidatesTokenCount'] ?? 0);
            $total = (int) ($usage['totalTokenCount'] ?? 0);
            if ($prompt > 0 || $completion > 0 || $total > 0) {
                $out['usage'] = [
                    'prompt_tokens'     => $prompt,
                    'completion_tokens' => $completion,
                    'total_tokens'      => $total > 0 ? $total : ($prompt + $completion),
                ];
            }
        }

        return $out;
    }

    /**
     * @return array{delta: string, text: string, finish_reason: string, usage: array<string, int>}
     */
    public static function parse_openai_event(string $json): array {
        $out = [
            'delta'         => '',
            'text'          => '',
            'finish_reason' => '',
            'usage'         => [],
        ];
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $out;
        }
        $delta = $data['choices'][0]['delta']['content'] ?? null;
        if (is_string($delta) && $delta !== '') {
            $out['delta'] = $delta;
            $out['text'] = $delta;
        }
        $finish = $data['choices'][0]['finish_reason'] ?? null;
        if (is_string($finish) && trim($finish) !== '') {
            $out['finish_reason'] = strtolower(trim($finish));
        }
        $usage = $data['usage'] ?? null;
        if (is_array($usage)) {
            $prompt = (int) ($usage['prompt_tokens'] ?? 0);
            $completion = (int) ($usage['completion_tokens'] ?? 0);
            $total = (int) ($usage['total_tokens'] ?? 0);
            if ($prompt > 0 || $completion > 0 || $total > 0) {
                $out['usage'] = [
                    'prompt_tokens'     => $prompt,
                    'completion_tokens' => $completion,
                    'total_tokens'      => $total > 0 ? $total : ($prompt + $completion),
                ];
            }
        }

        return $out;
    }
}
