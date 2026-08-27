<?php
/**
 * Detección de intención de listado de catálogo: Capa 1 (regex) + Capa 2 (micro-LLM).
 * Agnóstica de dominio: sin vocabularios de vertical.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Catalog_Intent {

    /**
     * Top-K mínimo al detectar listado (regex o semántico), antes del recorte elástico.
     */
    public const RAG_CHUNK_FLOOR = 20;

    /**
     * Suelo cuando la intención viene solo del micro-LLM (spec: 15).
     */
    public const SEMANTIC_RAG_CHUNK_FLOOR = 15;

    /**
     * Listados anclados a hoy/esta noche/mañana: caben los eventos de un día completo.
     */
    public const TEMPORAL_RAG_CHUNK_FLOOR = 25;

    /**
     * Tope del floor configurable (filter); el cap duro sigue en Brain::MAX_CATALOG_RAG_CHUNKS.
     */
    public const RAG_CHUNK_FLOOR_MAX = 25;

    public const LABEL_CATALOG = 'CATALOG';
    public const LABEL_GENERAL = 'GENERAL';
    public const KIND_CATALOG = 'catalog';
    public const KIND_TEMPORAL = 'temporal';

    /**
     * Capa 1 (fast-path): patrones estructurales. Sin llamada LLM.
     */
    public static function is_listing_query(string $text): bool {
        $q = self::normalize($text);
        if ($q === '') {
            return false;
        }
        if (self::looks_like_utility_only($q)) {
            return false;
        }

        $hit = self::matches_structural_listing($q) || self::is_temporal_query($text);
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('xabia_catalog_listing_intent', $hit, $text, $q);
            if (is_bool($filtered)) {
                return $filtered;
            }
        }

        return $hit;
    }

    /**
     * Consulta con anclaje temporal relativo (hoy / esta noche / mañana / este finde).
     * Agnóstica de dominio: no asume conciertos ni un vertical concreto.
     */
    public static function is_temporal_query(string $text): bool {
        $q = self::normalize($text);
        if ($q === '' || self::looks_like_utility_only($q)) {
            return false;
        }
        $hit = self::matches_temporal_listing($q);
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('xabia_catalog_temporal_intent', $hit, $text, $q);
            if (is_bool($filtered)) {
                return $filtered;
            }
        }

        return $hit;
    }

    /**
     * Enrutador híbrido: TEMPORAL → listado regex → micro-LLM (CATALOG|GENERAL).
     *
     * @param array{
     *   project_id?: string,
     *   config?: array<string, mixed>,
     *   llm_classify?: callable|null
     * } $ctx llm_classify: fn(string $user_msg): string
     * @return array{hit: bool, source: string, kind: string}
     */
    public static function resolve(string $text, array $ctx = []): array {
        $text = trim(function_exists('wp_strip_all_tags') ? wp_strip_all_tags($text) : strip_tags($text));
        if ($text === '') {
            return ['hit' => false, 'source' => 'none', 'kind' => ''];
        }

        if (self::is_temporal_query($text)) {
            return ['hit' => true, 'source' => 'temporal', 'kind' => self::KIND_TEMPORAL];
        }

        if (self::is_listing_query($text)) {
            return ['hit' => true, 'source' => 'regex', 'kind' => self::KIND_CATALOG];
        }

        $q = self::normalize($text);
        if ($q === '' || self::looks_like_utility_only($q)) {
            return ['hit' => false, 'source' => 'none', 'kind' => ''];
        }

        $config = isset($ctx['config']) && is_array($ctx['config']) ? $ctx['config'] : [];
        if (!self::micro_llm_enabled($config)) {
            return ['hit' => false, 'source' => 'disabled', 'kind' => ''];
        }

        $classify = $ctx['llm_classify'] ?? null;
        if (!is_callable($classify)) {
            return ['hit' => false, 'source' => 'no_classifier', 'kind' => ''];
        }

        $cache_key = 'xabia_cat_intent_' . md5(mb_strtolower($text, 'UTF-8'));
        if (function_exists('get_transient')) {
            $cached = get_transient($cache_key);
            if ($cached === self::LABEL_CATALOG || $cached === self::LABEL_GENERAL) {
                $hit = $cached === self::LABEL_CATALOG;

                return [
                    'hit'    => $hit,
                    'source' => 'llm_cache',
                    'kind'   => $hit ? self::KIND_CATALOG : '',
                ];
            }
        }

        try {
            $raw = (string) call_user_func($classify, $text);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[Xabia] catalog intent micro-LLM: ' . $e->getMessage());
            }

            return ['hit' => false, 'source' => 'llm_error', 'kind' => ''];
        }

        $label = self::parse_router_label($raw);
        if ($label === null) {
            return ['hit' => false, 'source' => 'llm_unparsed', 'kind' => ''];
        }

        if (function_exists('set_transient')) {
            $ttl = defined('MINUTE_IN_SECONDS') ? 10 * MINUTE_IN_SECONDS : 600;
            set_transient($cache_key, $label, $ttl);
        }

        $hit = $label === self::LABEL_CATALOG;

        return [
            'hit'    => $hit,
            'source' => 'llm',
            'kind'   => $hit ? self::KIND_CATALOG : '',
        ];
    }

    /**
     * Top-K a usar en recuperación cuando hay intención de catálogo / temporal.
     *
     * @param string $source regex|temporal|llm|llm_cache|…
     */
    public static function rag_chunk_limit(int $base, string $source = 'regex'): int {
        $floor = self::RAG_CHUNK_FLOOR;
        if ($source === 'temporal') {
            $floor = self::TEMPORAL_RAG_CHUNK_FLOOR;
        } elseif ($source === 'llm' || $source === 'llm_cache') {
            $floor = self::SEMANTIC_RAG_CHUNK_FLOOR;
        }
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('xabia_catalog_intent_rag_chunk_floor', $floor, $source);
            if (is_numeric($filtered)) {
                $floor = (int) $filtered;
            }
        }
        $floor = max(10, min(self::RAG_CHUNK_FLOOR_MAX, $floor));
        $cap = 50;
        if (class_exists('Xabia_Brain', false)) {
            $cap = (int) Xabia_Brain::MAX_CATALOG_RAG_CHUNKS;
        }

        return max(1, min($cap, max($base, $floor)));
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function micro_llm_enabled(array $config): bool {
        $rules = isset($config['rules']) && is_array($config['rules']) ? $config['rules'] : [];
        $flag = $rules['catalog_intent_micro_llm'] ?? true;
        if ($flag === false || $flag === 0 || $flag === '0' || $flag === 'off' || $flag === 'no') {
            $enabled = false;
        } else {
            $enabled = true;
        }
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('xabia_catalog_intent_micro_llm_enabled', $enabled, $config);
            if (is_bool($filtered)) {
                return $filtered;
            }
        }

        return $enabled;
    }

    public static function parse_router_label(string $raw): ?string {
        $normalized = strtoupper(trim(preg_replace('/[^A-Za-z]/', ' ', $raw) ?? $raw));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        if ($normalized === self::LABEL_CATALOG || $normalized === self::LABEL_GENERAL) {
            return $normalized;
        }
        if (preg_match('/\bCATALOG\b/', $normalized)) {
            return self::LABEL_CATALOG;
        }
        if (preg_match('/\bGENERAL\b/', $normalized)) {
            return self::LABEL_GENERAL;
        }

        return null;
    }

    /**
     * Prompt de sistema del micro-enrutador (instrucción fija; la pregunta va en el user turn).
     */
    public static function micro_router_system_prompt(): string {
        return "Eres un enrutador semántico. Tu tarea es clasificar la intención del usuario. "
            . "¿Está el usuario buscando entidades, servicios, opciones o productos de un catálogo? "
            . "Responde ÚNICAMENTE con la palabra 'CATALOG' si es así, o 'GENERAL' si es una charla casual o saludo.";
    }

    /**
     * @return list<string>
     */
    public static function temporal_patterns(): array {
        return [
            // «hoy / esta noche / mañana / este finde» con pedido de agenda o listado
            '/\b(hoy|esta\s+noche|esta\s+tarde|ma[nñ]ana|ahora|este\s+finde|este\s+fin\s+de\s+semana|esta\s+semana)\b/u',
            // EN/EU: today / tonight / tomorrow
            '/\b(today|tonight|tomorrow|this\s+weekend)\b/u',
            '/\b(gaur|gaur\s+gauean|bihar)\b/u',
        ];
    }

    /**
     * Requiere ancla temporal + señal de listado/agenda (evita «hoy estoy bien»).
     */
    private static function matches_temporal_listing(string $q): bool {
        $has_temporal = false;
        foreach (self::temporal_patterns() as $pattern) {
            if (preg_match($pattern, $q)) {
                $has_temporal = true;
                break;
            }
        }
        if (!$has_temporal) {
            return false;
        }
        // Señal de catálogo/agenda/listado (agnóstica).
        if (preg_match(
            '/\b(hay|existen|teneis|tienen|qu[eé]|cu[aá]l|cu[aá]les|list|lista|conciertos?|eventos?|actuaciones?|verbenas?|planes?|actividades?|opciones?|experiencias?|empresas?|servicios?|m[uú]sica|programa|agenda|horario|cartelera)\b/u',
            $q
        )) {
            return true;
        }
        // «qué hay hoy/esta noche»
        if (preg_match('/\b(qu[eé]\s+hay|hay\s+algo|qu[eé]\s+se\s+puede\s+hacer)\b/u', $q)) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function structural_patterns(): array {
        // Nota: is_listing_query() normaliza sin tildes → patrones en forma plana (haceis, teneis…).
        return [
            // «hay/existen/busco … en/de/tipo …»
            '/\b(hay|existen|teneis|ten[eé]is|ofrec[eé]is|busco|quiero|alguna|algunas|qu[eé])\b.{0,100}\b(en|de|tipo|categor[ií]a|entorno|ambiente)\b/u',
            // «actividades/empresas … en/de …»
            '/\b(actividades?|opciones?|experiencias?|empresas?|servicios?)\b.{0,80}\b(en|de|tipo|categor[ií]a|entorno|ambiente|para|con)\b/u',
            // «alguna empresa», «qué empresas», «qué actividades», «qué conciertos»
            '/\b(alguna|algunas|alg[uú]n|qu[eé]|cu[aá]l|cu[aá]les)\s+(empresa|empresas|actividad|actividades|experiencia|experiencias|opci[oó]n|opciones|servicio|servicios|conciertos?|eventos?|actuaciones?|verbenas?|planes?)\b/u',
            // «conciertos/eventos hoy|esta noche|mañana»
            '/\b(conciertos?|eventos?|actuaciones?|verbenas?|planes?)\b.{0,40}\b(hoy|esta\s+noche|esta\s+tarde|ma[nñ]ana|ahora)\b/u',
            // «qué hay esta noche/hoy» (programa / agenda)
            '/\b(qu[eé]\s+hay|hay\s+algo|qu[eé]\s+se\s+puede\s+hacer)\b.{0,40}\b(hoy|esta\s+noche|esta\s+tarde|ma[nñ]ana)\b/u',
            // «hay conciertos…»
            '/\bhay\s+(conciertos?|eventos?|actuaciones?|verbenas?|m[uú]sica)\b/u',
            // «dónde puedo», «donde hacer», «where can I»
            '/\b(d[oó]nde|where)\s+(puedo|podemos|hacer|encontrar|can|do)\b/u',
            // «busco hacer», «quiero hacer», «looking for a company»
            '/\b(busco|quiero|necesito|looking\s+for)\s+(hacer\b|(an?\s+|una?\s+)?(empresa|actividad|experiencia|opci[oó]n|servicio|company|activity)\b)/u',
            // «quién ofrece», «quien organiza», «who offers»
            '/\b(qui[eé]n|qui[eé]nes|who)\s+(ofrece|ofrecen|organiza|organizan|hace|hacen|tiene|tienen|offers?|provides?)\b/u',
            // «empresa con la que», «empresas que», «empresas para»
            '/\bempresas?\b.{0,40}\b(con|para|que|para\s+hacer)\b/u',
            // «recomiéndame una empresa/actividad»
            '/\brecomiend\w*.{0,50}\b(empresa|empresas|actividad|actividades|experiencia|experiencias|opcion|servicio)\b/u',
            // Implícito: «¿hacéis / hacen / ofrecéis excursiones|actividades|…?»
            '/\b(haceis|hacen|ofreceis|ofrecen)\b.{0,80}\b(excursiones?|actividades?|experiencias?|empresas?|opciones?|servicios?|rutas?|paseos?|tours?|salidas?)\b/u',
            // Implícito corto: «¿hacéis hípica?», «¿ofrecéis kayak?»
            '/\b(haceis|hacen|ofreceis|ofrecen)\s+(?!falta\b|bien\b|mal\b)[\p{L}\-]{3,}/u',
            // Implícito: «¿tenéis empresas|opciones|actividades…?»
            '/\b(teneis|tienen)\b.{0,80}\b(empresas?|opciones?|actividades?|experiencias?|excursiones?|servicios?|rutas?|paseos?)\b/u',
            // Implícito corto: «¿tenéis hípica?»
            '/\b(teneis|tienen)\s+(?!razon\b|sentido\b|cuenta\b|ganas\b|idea\b)[\p{L}\-]{3,}/u',
            // «hay alguna», «hay opciones», «hay empresas de…»
            '/\bhay\s+(alguna|algunas|algun|opciones?|empresas?|actividades?|experiencias?)\b/u',
            // «busco …» / «estoy buscando …»
            '/\b(estoy\s+)?buscando\b/u',
            '/\bbusco\b.{0,60}\b(empresa|actividad|experiencia|opcion|servicio|excursion|hacer|paseo|ruta|tour)\b/u',
            // «me recomiendas…», «recomendación de…»
            '/\b(me\s+)?recomienda(s|n)?\b/u',
            '/\brecomendacion(es)?\s+(de|para)\b/u',
            // EU (intención, no dominio): «non egin», «nork eskaintzen»
            '/\b(non)\s+(egin|aurkitu)\b/u',
            '/\b(nork)\s+(eskaintzen|antolatzen)\b/u',
        ];
    }

    private static function matches_structural_listing(string $q): bool {
        foreach (self::structural_patterns() as $pattern) {
            if (preg_match($pattern, $q)) {
                return true;
            }
        }

        return false;
    }

    private static function looks_like_utility_only(string $q): bool {
        if (preg_match('/\b(alguna|algunas|una|unas|the)\s+(foto|fotos|im[aá]gen|imagenes|imágenes|picture|pictures)\b/u', $q)) {
            return true;
        }
        $asks_catalog_noun = (bool) preg_match(
            '/\b(empresas?|actividades?|experiencias?|opciones?|servicios?|compan(y|ies)|activities)\b/u',
            $q
        );
        if ($asks_catalog_noun && preg_match(
            '/\b(alguna|algunas|qu[eé]|cu[aá]l|d[oó]nde|busco|quiero|quién)\b.{0,40}\b(empresas?|actividades?|experiencias?)\b/u',
            $q
        )) {
            return false;
        }

        return (bool) preg_match(
            '/\b(foto|fotos|im[aá]gen|imagenes|imágenes|picture|pictures|contacto|contactar|tel[eé]fono|telefono|email|correo|e-?mail|web|whatsapp|llamar)\b/u',
            $q
        );
    }

    private static function normalize(string $text): string {
        $q = function_exists('wp_strip_all_tags')
            ? wp_strip_all_tags((string) $text)
            : strip_tags((string) $text);
        $q = mb_strtolower(trim($q), 'UTF-8');
        $map = [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n',
        ];

        return strtr($q, $map);
    }
}
