<?php
/**
 * Fusión híbrida vectorial + léxica vía Reciprocal Rank Fusion (RRF).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Rag_Hybrid_Ranker {

    public const DEFAULT_K = 60;

    /** Peso RRF canal vectorial (dense) en fusión híbrida. */
    public const VECTOR_WEIGHT = 0.7;

    /** Peso RRF canal léxico (FULLTEXT/LIKE) en fusión híbrida. */
    public const LEXICAL_WEIGHT = 0.3;

    /** Bonus de score post-RRF para chunks de escenario/prioridad alta (catalog/agenda). */
    public const PRIORITY_SCORE_BOOST = 0.20;

    /**
     * @param list<list<array{id?: string, key?: string, content?: string, score?: float}>> $ranked_lists
     * @param list<float>|array<int, float> $weights Peso por lista (default 1.0). Vector > léxico recomendado.
     * @return list<array{id: string, content: string, score: float, rrf: float}>
     */
    public static function rrf_fuse(array $ranked_lists, int $k = self::DEFAULT_K, int $limit = 20, array $weights = []): array {
        $k = max(1, $k);
        $limit = max(1, $limit);
        $scores = [];
        $payloads = [];

        foreach ($ranked_lists as $list_idx => $list) {
            if (!is_array($list)) {
                continue;
            }
            $weight = isset($weights[$list_idx]) ? (float) $weights[$list_idx] : 1.0;
            if ($weight <= 0) {
                continue;
            }
            $rank = 0;
            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = self::item_id($item);
                $content = trim((string) ($item['content'] ?? $item['chunk'] ?? ''));
                if ($id === '' && $content === '') {
                    continue;
                }
                if ($id === '') {
                    $id = self::content_key($content);
                }
                ++$rank;
                $scores[$id] = ($scores[$id] ?? 0.0) + ($weight / ($k + $rank));
                if (!isset($payloads[$id]) || $content !== '') {
                    $payloads[$id] = [
                        'id'      => $id,
                        'content' => $content !== '' ? $content : (string) ($payloads[$id]['content'] ?? ''),
                        'score'   => isset($item['score']) ? (float) $item['score'] : 0.0,
                    ];
                }
            }
        }

        if ($scores === []) {
            return [];
        }

        arsort($scores, SORT_NUMERIC);
        $out = [];
        foreach ($scores as $id => $rrf) {
            $row = $payloads[$id] ?? ['id' => $id, 'content' => '', 'score' => 0.0];
            $row['rrf'] = (float) $rrf;
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * RRF ponderado: mayor confianza al canal vectorial frente al léxico.
     *
     * @param list<array{id?: string, content?: string, score?: float}> $vector_list
     * @param list<array{id?: string, content?: string, score?: float}> $lexical_list
     * @return list<array{id: string, content: string, score: float, rrf: float}>
     */
    public static function rrf_fuse_weighted(
        array $vector_list,
        array $lexical_list,
        int $k = self::DEFAULT_K,
        int $limit = 20,
        float $vector_weight = self::VECTOR_WEIGHT,
        float $lexical_weight = self::LEXICAL_WEIGHT
    ): array {
        return self::rrf_fuse(
            [$vector_list, $lexical_list],
            $k,
            $limit,
            [$vector_weight, $lexical_weight]
        );
    }

    /**
     * Diversidad en Top-K de catálogo: como máximo N slots por ente / huella blanda.
     *
     * @param list<array{id?: string, content?: string, score?: float, rrf?: float, ente_id?: string}> $hits
     * @return list<array{id?: string, content?: string, score?: float, rrf?: float, ente_id?: string}>
     */
    public static function diversify_catalog_top_k(array $hits, int $limit, int $max_per_entity = 2): array {
        $limit = max(1, $limit);
        $max_per_entity = max(1, $max_per_entity);
        $out = [];
        $counts = [];
        $seen_exact = [];
        foreach ($hits as $h) {
            if (!is_array($h)) {
                continue;
            }
            $content = trim((string) ($h['content'] ?? $h['chunk'] ?? ''));
            if ($content === '') {
                continue;
            }
            $exact = self::content_key($content);
            if (isset($seen_exact[$exact])) {
                continue;
            }
            $ente = trim((string) ($h['ente_id'] ?? $h['id'] ?? ''));
            if ($ente === '') {
                $ente = self::soft_entity_fingerprint($content);
            }
            $n = $counts[$ente] ?? 0;
            if ($n >= $max_per_entity) {
                continue;
            }
            $counts[$ente] = $n + 1;
            $seen_exact[$exact] = true;
            $out[] = $h;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Huella blanda para variantes casi idénticas del mismo texto.
     */
    public static function soft_entity_fingerprint(string $content): string {
        $t = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $content) ?? $content), 'UTF-8');
        $t = preg_replace('/\[(?:imagen disponible|keywords|prioridad|tipo|clasificaci[oó]n|atributos|ubicaci[oó]n|entidad)[^\]]*\]/iu', ' ', $t) ?? $t;
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? $t);

        return hash('sha256', mb_substr($t, 0, 180, 'UTF-8'));
    }

    /**
     * @param list<array{id?: string, content?: string, score?: float, rrf?: float}> $fused
     */
    public static function format_context(array $fused, int $max_chunk_chars = 900): string {
        $chunks = [];
        $seen = [];
        foreach ($fused as $item) {
            $chunk = trim(strip_tags((string) ($item['content'] ?? '')));
            if ($chunk === '' || isset($seen[$chunk])) {
                continue;
            }
            $seen[$chunk] = true;
            if ($max_chunk_chars > 0 && strlen($chunk) > $max_chunk_chars) {
                if (class_exists('Xabia_Brain', false) && method_exists('Xabia_Brain', 'truncate_chunk_preserving_imagen')) {
                    $chunk = Xabia_Brain::truncate_chunk_preserving_imagen($chunk, $max_chunk_chars);
                } else {
                    $chunk = substr($chunk, 0, $max_chunk_chars) . '…';
                }
            }
            if (class_exists('Xabia_Brain', false) && method_exists('Xabia_Brain', 'densify_rag_chunk')) {
                $chunk = Xabia_Brain::densify_rag_chunk($chunk);
            }
            if ($chunk === '') {
                continue;
            }
            $chunks[] = $chunk;
        }

        return implode("\n\n", $chunks);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function is_enabled(array $config): bool {
        $rules = is_array($config['rules'] ?? null) ? $config['rules'] : [];
        if (!array_key_exists('rag_hybrid_rrf', $rules)) {
            return true;
        }
        $v = $rules['rag_hybrid_rrf'];
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string) $v));

        return !in_array($s, ['0', 'off', 'false', 'no'], true);
    }

    /**
     * ¿El chunk marca prioridad alta o coincide con escenarios principales del proyecto?
     * Agnóstico: metadatos canónicos + lista configurable (rules.priority_venues).
     *
     * @param list<string> $priority_venues
     */
    public static function chunk_has_priority_signal(string $content, array $priority_venues = []): bool {
        $content = trim($content);
        if ($content === '') {
            return false;
        }
        if (preg_match('/\[\s*Prioridad\s*:\s*Alta\s*\]/iu', $content)
            || preg_match('/\[\s*Tipo\s*:\s*Escenario\s+Principal\s*\]/iu', $content)
            || preg_match('/\bPrioridad\s*:\s*Alta\b/iu', $content)
            || preg_match('/\b(?:Tipo|Clasificaci[oó]n)\s*:\s*[^\n\]]*Escenario\s+Principal/iu', $content)
            || preg_match('/\bMain\s+Stage\b/iu', $content)
        ) {
            return true;
        }

        foreach ($priority_venues as $venue) {
            $venue = trim((string) $venue);
            if ($venue === '' || mb_strlen($venue, 'UTF-8') < 4) {
                continue;
            }
            // Preferir coincidencia en campos de lugar; si no, coincidencia literal en el chunk.
            if (preg_match(
                '/\b(?:Lugar|Ubicaci[oó]n|Venue|Location|Site|Escenario)\s*:\s*[^\n\]]*?' . preg_quote($venue, '/') . '/iu',
                $content
            )) {
                return true;
            }
            if (mb_stripos($content, $venue, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tras RRF (o ranking híbrido): +boost al score de chunks prioritarios y reordena.
     *
     * @param list<array{id?: string, content?: string, score?: float, rrf?: float}> $hits
     * @param list<string> $priority_venues
     * @return list<array{id?: string, content?: string, score?: float, rrf?: float}>
     */
    public static function apply_priority_boost(
        array $hits,
        float $bonus = self::PRIORITY_SCORE_BOOST,
        array $priority_venues = []
    ): array {
        if ($hits === [] || $bonus <= 0) {
            return $hits;
        }
        $priority_venues = array_values(array_filter(array_map('strval', $priority_venues)));
        $changed = false;
        foreach ($hits as $i => $h) {
            if (!is_array($h)) {
                continue;
            }
            $content = (string) ($h['content'] ?? $h['chunk'] ?? '');
            if ($content === '' || !self::chunk_has_priority_signal($content, $priority_venues)) {
                continue;
            }
            $hits[$i]['score'] = (float) ($h['score'] ?? 0) + $bonus;
            if (isset($h['rrf'])) {
                $hits[$i]['rrf'] = (float) $h['rrf'] + $bonus;
            }
            $hits[$i]['priority_boost'] = true;
            $changed = true;
        }
        if (!$changed) {
            return $hits;
        }
        usort($hits, static function (array $a, array $b): int {
            $sa = isset($a['rrf']) ? (float) $a['rrf'] : (float) ($a['score'] ?? 0);
            $sb = isset($b['rrf']) ? (float) $b['rrf'] : (float) ($b['score'] ?? 0);
            if ($sa === $sb) {
                return 0;
            }

            return ($sb <=> $sa);
        });

        return array_values($hits);
    }

    /**
     * Normaliza rules.priority_venues (array o texto multilínea/coma).
     *
     * @param mixed $raw
     * @return list<string>
     */
    public static function normalize_priority_venues($raw): array {
        if (is_string($raw)) {
            $raw = preg_split('/[\n,;|]+/u', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $item = (string) ($item['name'] ?? $item['venue'] ?? $item['label'] ?? '');
            }
            $v = trim(preg_replace('/\s+/u', ' ', (string) $item) ?? '');
            if ($v === '' || mb_strlen($v, 'UTF-8') < 3) {
                continue;
            }
            $key = mb_strtolower($v, 'UTF-8');
            $out[$key] = $v;
        }

        return array_values($out);
    }

    /**
     * @param array{id?: string, key?: string, content?: string, chunk?: string} $item
     */
    private static function item_id(array $item): string {
        foreach (['id', 'key', 'ente_id'] as $k) {
            if (!empty($item[$k]) && is_scalar($item[$k])) {
                return (string) $item[$k];
            }
        }
        $content = (string) ($item['content'] ?? $item['chunk'] ?? '');

        return $content !== '' ? self::content_key($content) : '';
    }

    public static function content_key(string $content): string {
        $t = mb_strtolower(trim($content), 'UTF-8');

        return hash('sha256', mb_substr($t, 0, 600, 'UTF-8'));
    }
}
