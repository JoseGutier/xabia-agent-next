<?php
if (!defined('ABSPATH')) exit;

error_log('[XABIA TRACE] search-text.php cargado');

/**
 * 🔍 BUSCADOR GENERAL POR TEXTO (NEUTRO)
 * - El score manda
 * - No hay atajos semánticos
 * - El núcleo de actividad SOLO refuerza
 */
function xabia_search_by_text(string $query, array $entities): array {

    error_log('[XABIA TRACE] xabia_search_by_text() -> ' . $query);

    $q = mb_strtolower(trim($query), 'UTF-8');
    if (mb_strlen($q) < 4) return [];

    /* =====================================================
     * 1) TOKENIZACIÓN LIMPIA
     * ===================================================== */
    $tokens = array_values(array_filter(
        preg_split('/\s+/u', $q),
        fn($t) => mb_strlen($t) >= 3
    ));
    if (!$tokens) return [];

    /* =====================================================
     * 2) EXTRAER NÚCLEO LÉXICO (NO DECISORIO)
     * ===================================================== */
    $activityCore = null;

    // patrones tipo: "a caballo", "en velero", "de globo"
    if (preg_match('/\b(a|en|de|con)\s+([a-záéíóúüñ]+)/u', $q, $m)) {
        $activityCore = preg_replace('/s$/u', '', $m[2]); // singular suave
    }

    $results = [];

    /* =====================================================
     * 3) EVALUACIÓN POR ENTIDAD
     * ===================================================== */
    foreach ($entities as $e) {

        $score = 0;

        $name = mb_strtolower($e['empresa'] ?? '', 'UTF-8');
        $text = mb_strtolower($e['text'] ?? '', 'UTF-8');
        $cat  = mb_strtolower($e['categoria'] ?? '', 'UTF-8');
        $raw  = $e['raw'] ?? [];

        /* ==========================
         * A) MATCH POR NOMBRE
         * ========================== */
        foreach ($tokens as $t) {
            if ($name && mb_stripos($name, $t) !== false) {
                $score += 3;
            }
        }

        /* ==========================
         * B) MATCH POR TEXTO DESCRIPTIVO
         * ========================== */
        foreach ($tokens as $t) {
            if ($text && mb_stripos($text, $t) !== false) {
                $score += 2;
            }
        }

        /* ==========================
         * C) MATCH POR PROPUESTAS / EXPERIENCIAS (CSV REAL)
         * ========================== */
        foreach ($raw as $k => $v) {
            if (!is_string($v)) continue;
            if (!preg_match('/propuesta|experiencia/i', $k)) continue;

            $v = mb_strtolower($v, 'UTF-8');
            foreach ($tokens as $t) {
                if (mb_stripos($v, $t) !== false) {
                    $score += 2;
                }
            }
        }

        /* ==========================
         * D) MATCH POR CATEGORÍA
         * ========================== */
        foreach ($tokens as $t) {
            if ($cat && mb_stripos($cat, $t) !== false) {
                $score += 1;
            }
        }

        /* ==========================
         * E) REFUERZO POR NÚCLEO (NO DECIDE)
         * ========================== */
        if ($activityCore) {

            $foundCore = false;

            if ($text && mb_stripos($text, $activityCore) !== false) {
                $foundCore = true;
            } else {
                foreach (($e['experiencias'] ?? []) as $exp) {
                    if (mb_stripos(mb_strtolower($exp, 'UTF-8'), $activityCore) !== false) {
                        $foundCore = true;
                        break;
                    }
                }
            }

            if ($foundCore) {
                $score += 2; // refuerzo moderado
            }
        }

        /* ==========================
         * F) MATCH TOLERANTE (SOLO NOMBRE)
         * ========================== */
        foreach ($tokens as $t) {
            foreach (preg_split('/\s+/u', $name) as $w) {
                if (mb_strlen($w) >= 4 && levenshtein($t, $w) <= 2) {
                    $score += 1;
                    break;
                }
            }
        }

        /* ==========================
         * G) FILTRO FINAL (NEUTRO)
         * ========================== */
        if ($score >= 4) {
            $results[] = [
                'entity' => $e,
                'score'  => $score,
                'source' => 'text',
            ];
        }
    }

    usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

    return $results;
}