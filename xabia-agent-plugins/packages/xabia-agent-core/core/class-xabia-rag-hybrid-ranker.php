<?php
/**
 * Fusión híbrida vectorial + léxica vía Reciprocal Rank Fusion (RRF).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Rag_Hybrid_Ranker {

    public const DEFAULT_K = 60;

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
        float $vector_weight = 1.0,
        float $lexical_weight = 0.4
    ): array {
        return self::rrf_fuse(
            [$vector_list, $lexical_list],
            $k,
            $limit,
            [$vector_weight, $lexical_weight]
        );
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
