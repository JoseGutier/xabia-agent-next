<?php
/**
 * Xabia Agent — embeddings.php (v7.6 ULTRA-PRO)
 * Entrenamiento estable: batching, cache, validación, progreso + reintentos.
 */

if (!defined('ABSPATH')) exit;

/* ============================================================
 * 0) Rutas
 * ============================================================ */
function xabia_embeddings_file(): string {
    return xabia_path('embeddings.json');
}

function xabia_embeddings_meta_file(): string {
    return xabia_path('embeddings.meta.json');
}

/* ============================================================
 * 1) API key
 * ============================================================ */
function xabia_get_openai_api_key(): string {

    // ÚNICA fuente oficial -> wp-config.php
    if (defined('XABIA_OPENAI_KEY') && !empty(XABIA_OPENAI_KEY)) {
        return XABIA_OPENAI_KEY;
    }

    error_log('[Xabia EMB] ❌ No existe XABIA_OPENAI_KEY (defínelo en wp-config.php)');
    return '';
}

/* ============================================================
 * 2) Llamada batch segura al endpoint embeddings (con reintentos)
 * ============================================================ */
function xabia_openai_embed_batch(array $texts, int $max_retries = 3, int $retry_delay_base = 2): array {

    $key = xabia_get_openai_api_key();
    if (!$key) {
        error_log('[Xabia EMB] ❌ Sin API KEY');
        return [];
    }

    // Limpieza extrema
    $texts = array_values(array_filter(array_map(
        fn($t) => trim(xabia_sanitize_text((string) $t)),
        $texts
    )));

    if (empty($texts)) {
        error_log('[Xabia EMB] ⚠ Lote vacío tras sanitizar (0 textos).');
        return [];
    }

    $body = [
        'model' => 'text-embedding-3-large',
        'input' => $texts,
    ];

    $attempt = 0;

    while ($attempt < $max_retries) {

        $attempt++;

        $res = wp_remote_post('https://api.openai.com/v1/embeddings', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 40,
        ]);

        // Error de transporte
        if (is_wp_error($res)) {
            error_log('[Xabia EMB] ❌ HTTP ERROR intento ' . $attempt . ': ' . $res->get_error_message());

        } else {

            $code = wp_remote_retrieve_response_code($res);
            $raw  = wp_remote_retrieve_body($res);

            // 200 OK
            if ($code === 200) {
                $json = json_decode($raw, true);

                if (!isset($json['data']) || !is_array($json['data'])) {
                    error_log('[Xabia EMB] ❌ Respuesta inválida (sin data) en intento ' . $attempt);
                    // seguimos a reintento
                } else {
                    $vecs = [];
                    foreach ($json['data'] as $d) {
                        if (!empty($d['embedding']) && is_array($d['embedding'])) {
                            $vecs[] = $d['embedding'];
                        }
                    }

                    if (empty($vecs)) {
                        error_log('[Xabia EMB] ❌ Respuesta sin vectores en intento ' . $attempt);
                        // seguimos a reintento
                    } else {
                        // ÉXITO
                        return $vecs;
                    }
                }
            }
            // Rate limit u otros errores de servidor
            elseif (in_array($code, [429, 500, 502, 503, 504], true)) {
                error_log("[Xabia EMB] ⚠ Código {$code} en intento {$attempt}, se reintentará…");
            } else {
                error_log("[Xabia EMB] ❌ Código HTTP inesperado {$code} en intento {$attempt}");
                // Para códigos raros que no son temporales, salimos
                break;
            }
        }

        // Si no fue éxito, aplicar backoff antes del siguiente intento
        if ($attempt < $max_retries) {
            $delay = min(10, $retry_delay_base * $attempt);
            sleep($delay);
        }
    }

    error_log('[Xabia EMB] ❌ Fallaron todos los intentos de embeddings para el lote.');
    return [];
}

/* ============================================================
 * 3) Construcción de texto (sanitizado)
 * ============================================================ */
function xabia_embeddings_build_text(array $e): string {

    // 1) Texto principal
    $t = trim($e['text'] ?? '');

    // 2) Si está vacío, usar text_embed si existe
    if ($t === '' && !empty($e['text_embed'])) {
        $t = trim($e['text_embed']);
    }

    // 3) SANITIZAR
    $t = $t ? xabia_sanitize_text($t) : '';

    // 4) LOG antes de retornar
    error_log('[Xabia EMB] Texto usado para ' . ($e['empresa'] ?? '???') . ' = ' . substr($t, 0, 60));

    return $t;
}

/* ============================================================
 * 4) DETECTOR DE CAMBIOS (cache inteligente)
 * ============================================================ */
function xabia_embeddings_sources_hash(array $entities): string {
    return md5(json_encode($entities));
}

function xabia_embeddings_should_retrain(array $entities): bool {

    $metaFile = xabia_embeddings_meta_file();
    if (!file_exists($metaFile)) return true; // no meta → entrenar

    $meta = json_decode(file_get_contents($metaFile), true);
    if (!is_array($meta)) return true;

    $oldHash = $meta['sources_hash'] ?? '';
    $newHash = xabia_embeddings_sources_hash($entities);

    return $oldHash !== $newHash;
}

/* ============================================================
 * 5) Validación previa de columnas
 * ============================================================ */
function xabia_embeddings_validate_entities(array $entities) {

    foreach ($entities as $idx => $e) {

        if (empty($e['empresa'])) {
            return new WP_Error(
                'missing_empresa',
                "La fila {$idx} no tiene campo 'empresa'."
            );
        }

        if (empty(trim($e['text'] ?? ''))) {
            return new WP_Error(
                'missing_text',
                "La empresa «{$e['empresa']}» no tiene texto normalizado para embeddings."
            );
        }
    }

    return true;
}

/* ============================================================
 * 6) ENTRENAMIENTO COMPLETO
 * ============================================================ */
function xabia_train_embeddings(array $opts = []) {

    // DEBUG — rutas reales + API key parcial
    error_log('[Xabia DEBUG] base.json path = ' . xabia_path('base.json'));
    error_log('[Xabia DEBUG] embeddings.json path = ' . xabia_path('embeddings.json'));
    $apiKeyPreview = substr(xabia_get_openai_api_key(), 0, 10);
    error_log('[Xabia DEBUG] API KEY (primeros 10) = ' . $apiKeyPreview . '...');

    $send_progress = !empty($opts['send_progress']);

    if (!function_exists('xabia_load_knowledge')) {
        return new WP_Error('missing_knowledge', 'Falta xabia_load_knowledge()');
    }

    // Permitimos opcionalmente recibir entidades por parámetro,
    // pero si no, usamos xabia_load_knowledge() como siempre.
    $entities = $opts['entities'] ?? null;
    if (!is_array($entities) || empty($entities)) {
        $entities = xabia_load_knowledge();
    }

    if (!is_array($entities) || empty($entities)) {
        return new WP_Error('no_entities', 'No hay entidades cargadas.');
    }

    /* --- Validación previa --- */
    $valid = xabia_embeddings_validate_entities($entities);
    if (is_wp_error($valid)) return $valid;

    /* --- Cache / evitar re-entrenar --- */
// FORZAR ENTRENAMIENTO SIEMPRE (para debug)
$FORZAR = true;

if (!$FORZAR && !xabia_embeddings_should_retrain($entities)) {
        error_log('[Xabia EMB] 🟡 Sin cambios → se reaprovecha embeddings.json');
        return [
            'ok'      => true,
            'cached'  => true,
            'message' => 'No ha cambiado ninguna fuente, se conserva embeddings.json'
        ];
    }

    error_log('[Xabia EMB] 🔵 Entrenamiento NUEVO requerido…');

    $texts = [];
    $names = [];

    foreach ($entities as $e) {
        $names[] = trim($e['empresa']);
        $texts[] = xabia_embeddings_build_text($e);
    }

    $total     = count($texts);
    $batchSize = 50;
    $batches   = (int) ceil($total / $batchSize);

    $vectors = [];

    /* --- Batching real --- */
    for ($b = 0; $b < $batches; $b++) {

        $offset = $b * $batchSize;
        $batch  = array_slice($texts, $offset, $batchSize);

        $vecs   = xabia_openai_embed_batch($batch);

        if ($send_progress && function_exists('xabia_send_progress')) {
            xabia_send_progress($b + 1, $batches);
        }

        if (empty($vecs)) {
            error_log("[Xabia EMB] ❌ Lote " . ($b+1) . "/$batches sin vectores. Se continúa con los siguientes lotes.");
            continue;
        }

        error_log("[Xabia EMB] Lote " . ($b+1) . "/$batches OK (" . count($vecs) . " vectores)");

        foreach ($vecs as $v) {
            $vectors[] = $v;
        }
    }

    if (empty($vectors)) {
        return new WP_Error('no_vectors', 'La API devolvió 0 vectores en total.');
    }

    if (count($vectors) < count($names)) {
        error_log('[Xabia EMB] ⚠ Warning: faltan vectores. Recibidos ' . count($vectors) . ' de ' . count($names));
    }

    $N = min(count($vectors), count($names));

    $items = [];
    for ($i = 0; $i < $N; $i++) {
        $items[] = [
            'empresa' => $names[$i],
            'vector'  => $vectors[$i],
        ];
    }

    /* --- Guardar embeddings.json --- */
    $file = xabia_embeddings_file();

    $json = [
        'model'   => 'text-embedding-3-large',
        'created' => time(),
        'count'   => $N,
        'items'   => $items,
    ];

    xabia_json_save($file, $json);

    /* --- Guardar meta con hash de contenido --- */
    $meta = [
        'sources_hash' => xabia_embeddings_sources_hash($entities),
        'updated'      => time(),
    ];
    xabia_json_save(xabia_embeddings_meta_file(), $meta);

    error_log('[Xabia EMB] 🟢 Entrenamiento COMPLETO: ' . $N . ' vectores.');

    return [
        'ok'    => true,
        'count' => $N,
        'file'  => $file,
        'cached'=> false,
    ];
}

/* ============================================================
 * 7) Búsqueda semántica ultrarrápida
 * ============================================================ */
function xabia_cosine_similarity(array $a, array $b): float {

    $len = min(count($a), count($b));
    if ($len === 0) return 0.0;

    $dot = $na = $nb = 0.0;

    for ($i = 0; $i < $len; $i++) {
        $dot += $a[$i] * $b[$i];
        $na  += $a[$i] * $a[$i];
        $nb  += $b[$i] * $b[$i];
    }

    return ($na > 0 && $nb > 0)
        ? $dot / (sqrt($na) * sqrt($nb))
        : 0.0;
}

function xabia_vector_search(string $query, int $limit = 10, float $min = 0.55) {

    $ef = xabia_embeddings_file();
    if (!file_exists($ef)) return [];

    $data = json_decode(file_get_contents($ef), true);
    if (empty($data['items']) || !is_array($data['items'])) return [];

    $items = $data['items'];

    /* --- Vectorizar consulta --- */
    $qv_all = xabia_openai_embed_batch([$query]);
    $qv     = $qv_all[0] ?? null;

    if (!$qv) {
        error_log('[Xabia EMB] ❌ No se pudo vectorizar la consulta para búsqueda.');
        return [];
    }

    /* --- Cargar entidades completas --- */
    $entities = xabia_load_knowledge();
    if (!is_array($entities) || empty($entities)) {
        error_log('[Xabia EMB] ❌ No hay conocimiento cargado para hacer vector_search.');
        return [];
    }

    $map = [];
    foreach ($entities as $e) {
        if (!empty($e['empresa'])) {
            $map[mb_strtolower($e['empresa'])] = $e;
        }
    }

    /* --- Comparación rápida --- */
    $out = [];
    foreach ($items as $item) {

        $name = mb_strtolower($item['empresa'] ?? '');
        if (!$name || !isset($map[$name])) continue;

        $vec = $item['vector'] ?? null;
        if (!$vec || !is_array($vec)) continue;

        $score = xabia_cosine_similarity($qv, $vec);

        if ($score >= $min) {
            $e = $map[$name];
            $e['_score'] = $score;
            $out[] = $e;
        }
    }

    usort($out, fn($a, $b) => $b['_score'] <=> $a['_score']);
    return array_slice($out, 0, $limit);
}