<?php
/**
 * Reescritura / expansión de consulta RAG (LLM genérico + morfología; sin diccionarios de cliente).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Rag_Query_Rewriter {

    /**
     * @param array<string, mixed> $config
     * @param callable|null        $llm_expand fn(string $user_msg, string $last_search): string
     * @return array{embed_text: string, lexical_text: string, needles: list<string>, rewritten: bool, canonical_entities: list<string>}
     */
    public static function prepare(
        string $user_msg,
        string $last_search = '',
        array $config = [],
        $llm_expand = null
    ): array {
        $user_msg = trim(wp_strip_all_tags($user_msg));
        $last_search = trim(wp_strip_all_tags($last_search));
        $base = $user_msg !== '' ? $user_msg : $last_search;

        $out = [
            'embed_text'         => $base,
            'lexical_text'       => $base,
            'needles'            => [],
            'rewritten'          => false,
            'canonical_entities' => [],
        ];

        if ($base === '') {
            return $out;
        }

        $parts = [$base];
        $rewritten = false;
        $canonical_entities = [];

        if (self::is_enabled($config) && is_callable($llm_expand)) {
            $cache_key = 'xabia_rag_qr_' . md5($base . '|' . $last_search);
            $cached = function_exists('get_transient') ? get_transient($cache_key) : false;
            if (is_string($cached) && trim($cached) !== '' && !self::looks_like_bad_expansion(trim($cached), $base)) {
                $expanded_raw = trim($cached);
            } else {
                if (is_string($cached) && trim($cached) !== '' && function_exists('delete_transient')) {
                    delete_transient($cache_key);
                }
                try {
                    $expanded_raw = trim((string) call_user_func($llm_expand, $user_msg !== '' ? $user_msg : $base, $last_search));
                } catch (Throwable $e) {
                    $expanded_raw = '';
                }
                if ($expanded_raw !== '' && self::looks_like_bad_expansion($expanded_raw, $base)) {
                    $expanded_raw = '';
                }
                if ($expanded_raw !== '' && function_exists('set_transient')) {
                    set_transient($cache_key, $expanded_raw, 10 * MINUTE_IN_SECONDS);
                }
            }
            if ($expanded_raw !== '' && self::looks_like_bad_expansion($expanded_raw, $base)) {
                $expanded_raw = '';
            }
            if ($expanded_raw !== '') {
                $parsed = self::parse_interpreter_output($expanded_raw);
                $expanded = trim((string) ($parsed['keywords'] ?? ''));
                $canonical_entities = isset($parsed['canonical_entities']) && is_array($parsed['canonical_entities'])
                    ? array_values(array_filter(array_map('strval', $parsed['canonical_entities'])))
                    : [];
                if ($canonical_entities !== [] && function_exists('apply_filters')) {
                    $canonical_entities = apply_filters(
                        'xabia_rag_canonical_toponyms',
                        $canonical_entities,
                        $base,
                        $user_msg,
                        $config
                    );
                    if (!is_array($canonical_entities)) {
                        $canonical_entities = [];
                    }
                    $canonical_entities = array_values(array_unique(array_filter(array_map(
                        static function ($entity) {
                            return trim((string) $entity);
                        },
                        $canonical_entities
                    ))));
                }
                if ($expanded !== '' && mb_strtolower($expanded, 'UTF-8') !== mb_strtolower($base, 'UTF-8')) {
                    $parts[] = $expanded;
                    $rewritten = true;
                }
            }
        }

        if (class_exists('Xabia_Rag_Language_Bridge', false)) {
            foreach (Xabia_Rag_Language_Bridge::retrieval_term_variants($base) as $variant) {
                $parts[] = $variant;
            }
        }

        $parts = array_values(array_unique(array_filter(array_map(
            static function ($p) {
                return trim((string) $p);
            },
            $parts
        ))));

        // Si hay un criterio específico en la query, no diluir el embed con hiperónimos genéricos sueltos.
        if ($canonical_entities !== []) {
            foreach ($canonical_entities as $entity) {
                $parts[] = $entity;
            }
        }

        $embed_parts = self::focus_embed_parts($parts, $base);
        $combined = trim(implode(' ', $embed_parts));
        $embed = self::sanitize_retrieval_text($combined !== '' ? $combined : $base, 2000);
        $lexical_seed = $rewritten && isset($parts[1]) ? ($base . ' ' . $parts[1]) : $base;
        if ($canonical_entities !== []) {
            $lexical_seed = trim($lexical_seed . ' ' . implode(' ', $canonical_entities));
        }
        $lexical = self::sanitize_retrieval_text($lexical_seed, 2000);

        $needle_seed = self::extract_tokens($lexical !== '' ? $lexical : $base);
        if ($canonical_entities !== []) {
            foreach ($canonical_entities as $entity) {
                $needle_seed[] = mb_strtolower(trim((string) $entity), 'UTF-8');
            }
        }
        $needles = [];
        if (class_exists('Xabia_Rag_Language_Bridge', false)) {
            $needles = Xabia_Rag_Language_Bridge::expand_keyword_needles($needle_seed, $base);
            $needles = self::focus_lexical_needles($needles, $base);
        } else {
            $needles = array_values(array_unique($needle_seed));
        }

        return [
            'embed_text'         => $embed,
            'lexical_text'       => $lexical !== '' ? $lexical : $embed,
            'needles'            => array_values(array_slice($needles, 0, 40)),
            'rewritten'          => $rewritten || $canonical_entities !== [],
            'canonical_entities' => $canonical_entities,
        ];
    }

    /**
     * Parsea la salida del Intérprete: palabras clave + entidad_normalizada opcional.
     *
     * @return array{keywords: string, canonical_entities: list<string>}
     */
    public static function parse_interpreter_output(string $raw): array {
        $raw = trim(wp_strip_all_tags($raw));
        $entities = [];
        if (preg_match('/\bentidad_normalizada\s*:\s*(.+)$/iu', $raw, $match)) {
            $entity_blob = trim((string) ($match[1] ?? ''));
            $raw = trim((string) preg_replace('/\bentidad_normalizada\s*:.+$/iu', '', $raw));
            foreach (preg_split('/[,;|]/u', $entity_blob) as $entity) {
                $entity = trim((string) $entity);
                if ($entity === '' || mb_strlen($entity, 'UTF-8') > 80) {
                    continue;
                }
                $entities[] = $entity;
            }
        }

        return [
            'keywords'           => $raw,
            'canonical_entities' => array_values(array_unique($entities)),
        ];
    }

    /**
     * Prioriza tokens de criterio de la query; resta hiperónimos genéricos sueltos (entorno/ambiente/tipo).
     * Agnóstico de dominio: no asume vocabulario de un cliente.
     *
     * @param list<string> $parts
     * @return list<string>
     */
    private static function focus_embed_parts(array $parts, string $base): array {
        $base_l = mb_strtolower($base, 'UTF-8');
        $content = self::content_criterion_tokens($base);
        if ($content === []) {
            return $parts;
        }
        $hyper = self::generic_hyperonym_tokens();
        $out = [];
        foreach ($parts as $p) {
            $pl = mb_strtolower(trim((string) $p), 'UTF-8');
            if ($pl === '') {
                continue;
            }
            // Conserva la frase original; descarta morfología suelta de hiperónimos.
            if ($pl !== $base_l && in_array($pl, $hyper, true)) {
                continue;
            }
            $out[] = $p;
        }
        foreach ($content as $boost) {
            $out[] = $boost;
        }
        // Variante sin hiperónimos diluyentes.
        $stripped = $base;
        foreach ($hyper as $h) {
            $stripped = (string) preg_replace('/\b' . preg_quote($h, '/') . '\b/ui', ' ', $stripped);
        }
        $stripped = trim(preg_replace('/\s+/u', ' ', $stripped) ?? $stripped);
        if ($stripped !== '' && mb_strtolower($stripped, 'UTF-8') !== $base_l) {
            $out[] = $stripped;
        }

        return $out !== [] ? $out : $parts;
    }

    /**
     * @param list<string> $needles
     * @return list<string>
     */
    private static function focus_lexical_needles(array $needles, string $base): array {
        $hyper = self::generic_hyperonym_tokens();
        $content = self::content_criterion_tokens($base);
        $out = [];
        foreach ($needles as $n) {
            $n = mb_strtolower(trim((string) $n), 'UTF-8');
            if ($n === '' || in_array($n, $hyper, true)) {
                continue;
            }
            $out[] = $n;
        }
        foreach ($content as $c) {
            $out[] = $c;
        }

        return array_values(array_unique($out));
    }

    /**
     * Hiperónimos estructurales (no categorías de producto).
     *
     * @return list<string>
     */
    private static function generic_hyperonym_tokens(): array {
        return [
            'entorno', 'entornos', 'entorna', 'entornas',
            'ambiente', 'ambientes',
            'tipo', 'tipos',
            'categoria', 'categorias', 'categoría', 'categorías',
        ];
    }

    /**
     * Tokens de criterio de la query (contenido), con flexión vía Language Bridge.
     *
     * @return list<string>
     */
    private static function content_criterion_tokens(string $base): array {
        $tokens = self::extract_tokens($base);
        $hyper = array_fill_keys(self::generic_hyperonym_tokens(), true);
        $stop = [
            'para', 'como', 'esta', 'este', 'estos', 'estas', 'hay', 'alguna', 'algunas',
            'quiero', 'busco',
        ];
        $content = [];
        foreach ($tokens as $t) {
            $t = mb_strtolower(trim((string) $t), 'UTF-8');
            if ($t === '' || strlen($t) < 4 || isset($hyper[$t]) || in_array($t, $stop, true)) {
                continue;
            }
            $content[] = $t;
        }
        if ($content === []) {
            return [];
        }
        if (class_exists('Xabia_Rag_Language_Bridge', false)) {
            return Xabia_Rag_Language_Bridge::expand_keyword_needles($content, $base);
        }

        return array_values(array_unique($content));
    }

    /**
     * Expansiones LLM inválidas (errores de transporte, prosa, o que tiran el nombre propio de la query).
     */
    private static function looks_like_bad_expansion(string $raw, string $base = ''): bool {
        $raw = trim($raw);
        if ($raw === '') {
            return true;
        }
        if (mb_strlen($raw, 'UTF-8') > 500) {
            return true;
        }
        if (preg_match('/[.!?].*[.!?]/u', $raw)) {
            return true;
        }
        if (preg_match('/\b(error\s*api|error\s*config|error\s*crítico|wp_error|exception|traceback|curl error|http\s*[45]\d\d)\b/iu', $raw)) {
            return true;
        }
        if (preg_match('/\b(sin mensajes|user\/assistant|válidos para gemini|insufficient[_ ]balance|rate limit|proxy)\b/iu', $raw)) {
            return true;
        }
        if (preg_match('/^(error|failed|failure)\b/iu', $raw)) {
            return true;
        }
        if ($base !== '' && self::expansion_drops_query_tokens($raw, $base)) {
            return true;
        }

        return false;
    }

    /**
     * Una expansión que no conserva ningún token de contenido de la consulta
     * (2–5 tokens) se descarta. No hay lista de intención: basta el solapamiento.
     */
    public static function expansion_drops_query_tokens(string $expanded, string $base): bool {
        $plain = trim((string) preg_replace('/[?¿!.,;:]+/u', ' ', $base));
        $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
        $words = preg_split('/\s+/u', $plain) ?: [];
        if (count($words) < 2 || count($words) > 5) {
            return false;
        }
        $keep = [];
        $hyper = array_fill_keys(self::generic_hyperonym_tokens(), true);
        foreach (self::extract_tokens($base) as $t) {
            $t = mb_strtolower(trim((string) $t), 'UTF-8');
            if ($t === '' || strlen($t) < 4 || isset($hyper[$t])) {
                continue;
            }
            $keep[] = $t;
        }
        $keep = array_values(array_unique($keep));
        if ($keep === []) {
            return false;
        }
        $hay = mb_strtolower($expanded, 'UTF-8');
        foreach ($keep as $token) {
            $token = mb_strtolower(trim((string) $token), 'UTF-8');
            if ($token === '' || mb_strlen($token, 'UTF-8') < 4) {
                continue;
            }
            if (mb_strpos($hay, $token) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Turno 1: la consulta original va directa a la búsqueda. El micro-modelo
     * solo se llama si hay un turno previo que resolver (anáfora, referencia).
     *
     * @param list<array<string, mixed>> $history
     */
    public static function history_has_prior_turns(array $history): bool {
        foreach ($history as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role = (string) ($row['role'] ?? '');
            if ($role !== 'user' && $role !== 'assistant') {
                continue;
            }
            if (trim((string) ($row['content'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function should_invoke_llm(array $config, bool $has_prior_turns): bool {
        return $has_prior_turns && self::is_enabled($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function is_enabled(array $config): bool {
        $rules = is_array($config['rules'] ?? null) ? $config['rules'] : [];
        if (!array_key_exists('rag_query_rewrite', $rules)) {
            return true;
        }
        $v = $rules['rag_query_rewrite'];
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string) $v));

        return !in_array($s, ['0', 'off', 'false', 'no'], true);
    }

    public static function sanitize_retrieval_text(string $term, int $max_chars = 2000): string {
        $term = wp_strip_all_tags((string) $term);
        if (function_exists('sanitize_text_field')) {
            $term = sanitize_text_field($term);
        }
        $term = is_string($term) ? preg_replace('/\s+/u', ' ', trim($term)) : '';
        if (!is_string($term) || $term === '') {
            return '';
        }
        $max_chars = max(80, min(4000, $max_chars));
        if (mb_strlen($term, 'UTF-8') > $max_chars) {
            $term = mb_substr($term, 0, $max_chars, 'UTF-8');
        }

        return $term;
    }

    /**
     * @return list<string>
     */
    private static function extract_tokens(string $text): array {
        $text = mb_strtolower($text, 'UTF-8');
        if (!preg_match_all('/\p{L}[\p{L}\p{M}\'-]{2,}/u', $text, $m)) {
            return [];
        }
        $out = [];
        foreach ($m[0] as $tok) {
            $tok = trim((string) $tok, "'-");
            if (mb_strlen($tok, 'UTF-8') >= 3) {
                $out[] = $tok;
            }
        }

        return array_values(array_unique($out));
    }
}
