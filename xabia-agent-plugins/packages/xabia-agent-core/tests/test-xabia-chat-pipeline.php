<?php
/**
 * Decisiones de nonce y tiempos del pipeline, sin WordPress.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

require_once dirname(__DIR__) . '/core/class-xabia-chat-pipeline.php';

assert(Xabia_Chat_Pipeline::nonce_decision('', false, false, false) === 'missing', 'empty nonce is missing');
assert(Xabia_Chat_Pipeline::nonce_decision('abc', false, false, false) === 'invalid', 'unknown nonce is invalid');
assert(Xabia_Chat_Pipeline::nonce_decision('abc', true, false, false) === 'forbidden', 'admin nonce without manage_options');
assert(Xabia_Chat_Pipeline::nonce_decision('abc', true, false, true) === 'ok', 'admin nonce with manage_options');
assert(Xabia_Chat_Pipeline::nonce_decision('abc', false, true, false) === 'ok', 'public nonce');

Xabia_Chat_Pipeline::begin();
Xabia_Chat_Pipeline::mark('nonce');
Xabia_Chat_Pipeline::mark('route');
$durations = Xabia_Chat_Pipeline::durations_ms();
assert(isset($durations['start_to_nonce'], $durations['nonce_to_route'], $durations['total']), 'stages are timed');
assert($durations['total'] >= 0, 'total is non-negative');

Xabia_Chat_Pipeline::reset();
Xabia_Chat_Pipeline::begin();
Xabia_Chat_Pipeline::mark('nonce');
Xabia_Chat_Pipeline::mark('route');
Xabia_Chat_Pipeline::mark('rewrite');
Xabia_Chat_Pipeline::mark('retrieve');
Xabia_Chat_Pipeline::mark('generate');
Xabia_Chat_Pipeline::note_first_token();
$phases = Xabia_Chat_Pipeline::phase_ms();
foreach (['nonce', 'route', 'rewrite', 'retrieve', 'generate', 'total', 'first_token'] as $key) {
    assert(array_key_exists($key, $phases), 'phase ' . $key);
    assert($phases[$key] >= 0, 'phase ' . $key . ' is non-negative');
}
$line = Xabia_Chat_Pipeline::debug_line();
assert(strpos($line, '[XABIA_PIPELINE] nonce=') === 0, 'debug line starts with the pipeline tag');
assert(strpos($line, 'generate=') !== false && strpos($line, 'total=') !== false, 'debug line lists generate and total');

require_once dirname(__DIR__) . '/core/class-xabia-chat-stream.php';
require_once dirname(__DIR__) . '/core/class-xabia-llm-stream.php';

$event = Xabia_Chat_Stream::encode_event('done', ['response' => 'hola', 'pipeline' => ['total' => 1]]);
assert(strpos($event, "event: done\n") === 0, 'done event name');
assert(strpos($event, '"response":"hola"') !== false, 'done payload');
assert(substr($event, -2) === "\n\n", 'sse frame ends with a blank line');

$first = Xabia_Llm_Stream::parse_vertex_event('{"candidates":[{"content":{"parts":[{"text":"Hola"}]}}]}', '');
assert($first['delta'] === 'Hola' && $first['text'] === 'Hola', 'vertex first chunk');
$second = Xabia_Llm_Stream::parse_vertex_event('{"candidates":[{"content":{"parts":[{"text":" mundo"}]},"finishReason":"STOP"}],"usageMetadata":{"promptTokenCount":3,"candidatesTokenCount":2,"totalTokenCount":5}}', 'Hola');
assert($second['delta'] === ' mundo' && $second['finish_reason'] === 'stop', 'vertex incremental chunk');
assert($second['usage']['total_tokens'] === 5, 'vertex usage');
$cumulative = Xabia_Llm_Stream::parse_vertex_event('{"candidates":[{"content":{"parts":[{"text":"Hola mundo"}]}}]}', 'Hola');
assert($cumulative['delta'] === ' mundo' && $cumulative['text'] === 'Hola mundo', 'vertex cumulative chunk');

$openai = Xabia_Llm_Stream::parse_openai_event('{"choices":[{"delta":{"content":"Hi"},"finish_reason":null}],"usage":{"prompt_tokens":1,"completion_tokens":1,"total_tokens":2}}');
assert($openai['delta'] === 'Hi' && $openai['usage']['total_tokens'] === 2, 'openai delta');
$tail = Xabia_Llm_Stream::parse_openai_event('{"choices":[{"delta":{},"finish_reason":"stop"}]}');
assert($tail['delta'] === '' && $tail['finish_reason'] === 'stop', 'openai finish');

echo "OK test-xabia-chat-pipeline.php\n";
