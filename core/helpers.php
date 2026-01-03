<?php
/**
 * Xabia — helpers.php (v3.6 PRO)
 * Funciones auxiliares para:
 * - rutas internas
 * - logs
 * - lectura/escritura JSON
 * - envío de progreso
 */

if (!defined('ABSPATH')) exit;


/* ======================================================
 * 1) Ruta estándar dentro de /uploads/xabia/
 * ====================================================== */
if (!function_exists('xabia_path')) {
    function xabia_path(string $file = ''): string {
        $uploads = wp_upload_dir();
        $dir     = trailingslashit($uploads['basedir']) . 'xabia/';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        return $dir . ltrim($file, '/');
    }
}


/* ======================================================
 * 2) Registro seguro en debug.log
 * ====================================================== */
if (!function_exists('xabia_log')) {
    function xabia_log($message, $context = []) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) return;

        if (!is_string($message)) {
            $message = print_r($message, true);
        }

        if (!empty($context)) {
            $message .= ' ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE);
        }

        error_log('[Xabia] ' . $message);
    }
}


/* ======================================================
 * 3) Log dedicado para procesos grandes (embeddings)
 * ====================================================== */
if (!function_exists('xabia_debug')) {
    function xabia_debug($msg) {
        error_log('[XABIA DEBUG] ' . $msg);
    }
}


/* ======================================================
 * 4) Cargar JSON seguro
 * ====================================================== */
if (!function_exists('xabia_json_load')) {
    function xabia_json_load(string $file, $default = []) {
        if (!file_exists($file)) return $default;

        $json = file_get_contents($file);
        if (!$json) return $default;

        $data = json_decode($json, true);
        return is_array($data) ? $data : $default;
    }
}


/* ======================================================
 * 5) Guardar JSON seguro
 * ====================================================== */
if (!function_exists('xabia_json_save')) {
    function xabia_json_save(string $file, array $data) {

        $dir = dirname($file);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $bytes = file_put_contents(
            $file,
            wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        return ($bytes !== false);
    }
}


/* ======================================================
 * 6) Enviar progreso (para lotes)
 *    train.php lo usa si 'send_progress' = true
 * ====================================================== */
if (!function_exists('xabia_send_progress')) {
    function xabia_send_progress(int $current, int $total) {

        // Nada que enviar en modo CLI/cron
        if (wp_doing_cron() || defined('DOING_CRON')) return;

        // Enviar a Output Buffer (Fetch parseará esto)
        $msg = [
            'progress' => [
                'current' => $current,
                'total'   => $total,
                'text'    => "Procesando lote {$current}/{$total}"
            ]
        ];

        echo json_encode($msg) . "\n";
        @ob_flush();
        @flush();
    }
}
/* ======================================================
 * 7) Resolver nombre de empresa difuso (OBSOLETO)
 * Mantener por compatibilidad pero SIEMPRE devuelve null.
 * ====================================================== */
function xabia_match_company_name(string $name, array $entities) {
    return null;
}



/* ============================================================
 * 8) Fuzzy helpers — 100% generalistas
 * ============================================================ */

/**
 * Normaliza strings para comparar.
 */
function xabia_norm($s): string {
    $s = strtolower(trim($s ?? ''));
    $s = str_replace(['á','é','í','ó','ú','ñ'], ['a','e','i','o','u','n'], $s);
    $s = preg_replace('/[^a-z0-9 ]/i', '', $s);
    return $s;
}

/**
 * Fuzzy score (0 a 1).
 */
function xabia_fuzzy_score($a, $b): float {
    $a = xabia_norm($a);
    $b = xabia_norm($b);
    if ($a === '' || $b === '') return 0;

    similar_text($a, $b, $p);
    return $p / 100;
}

/**
 * Devuelve la mejor coincidencia fuzzy.
 */
function xabia_fuzzy_best(string $query, array $list, float $min = 0.52): ?string {
    $best = null;
    $bscore = $min;

    foreach ($list as $item) {
        $s = xabia_fuzzy_score($query, $item);
        if ($s > $bscore) {
            $bscore = $s;
            $best   = $item;
        }
    }
    return $best;
}
function xabia_answer_from_faq(string $question, array $companies): ?array {

    $q = mb_strtolower($question, 'UTF-8');

    // núcleos semánticos genéricos (condiciones)
    preg_match_all(
    '/\b(
        embaraz\w*|
        niñ\w*|
        edad|
        nivel(\s+f[ií]sico)?|
        forma\s+f[ií]sica|
        accesibl\w*|
        discapacidad|
        movilidad|
        riesgo|
        segur\w*|
        vertig\w*|
        miedo|
        perro|
        mascota
    )\b/ux',
    $q,
    $m
);

    $cores = array_unique($m[1] ?? []);
    if (!$cores) return null;

    foreach ($companies as $e) {

        $raw = $e['raw'] ?? [];
        $empresa = $e['empresa'] ?? '';

        for ($i = 1; $i <= 5; $i++) {

            $faq = mb_strtolower(trim($raw["faq_{$i}"] ?? ''), 'UTF-8');
            $ans = trim($raw["respuesta_faq_{$i}"] ?? '');

            if (!$faq || !$ans) continue;

            foreach ($cores as $c) {
                if (mb_stripos($faq, $c) !== false) {
                    return [
                        'empresa'   => $empresa,
                        'pregunta' => $raw["faq_{$i}"],
                        'respuesta'=> $ans,
                        'entity'   => $e,
                    ];
                }
            }
        }
    }

    return null;
}

/* ======================================================
 * 9) Limpieza y normalización de texto (CORE)
 * ====================================================== */
if (!function_exists('xabia_clean')) {
    function xabia_clean(string $msg): string {

        $msg = mb_strtolower(trim($msg), 'UTF-8');

        // Correcciones frecuentes (marca / asistente)
        $map = [
            'savia'  => 'xabia',
            'sabia'  => 'xabia',
            'xavia'  => 'xabia',
            'javia'  => 'xabia',
            'javía'  => 'xabia',
            'activa' => 'aktiba',
            'actiba' => 'aktiba',
            'atiba'  => 'aktiba',
            'aktiva' => 'aktiba',
        ];

        foreach ($map as $wrong => $correct) {
            $msg = preg_replace(
                '/\b' . preg_quote($wrong, '/') . '\b/u',
                $correct,
                $msg
            );
        }

        // Limpieza de símbolos raros
        $msg = preg_replace('/[^\p{L}\p{N}\s\-]+/u', ' ', $msg);
        $msg = preg_replace('/\s+/u', ' ', $msg);

        return trim($msg);
    }
}