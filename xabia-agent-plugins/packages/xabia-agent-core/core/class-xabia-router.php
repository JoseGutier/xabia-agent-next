<?php
/**
 * XABIA ROUTER — clasificador de intención + caché de respuestas por proyecto.
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('Xabia_Router')) :
class Xabia_Router {
    const ROUTE_ACTION = 'ROUTE_ACTION';
    const ROUTE_KNOWLEDGE = 'ROUTE_KNOWLEDGE';
    const ROUTE_GENERAL = 'ROUTE_GENERAL';

    public static function classify(string $project_id, string $message, array $config = [], string $lang = 'es'): string {
        $msg = trim($message);
        if ($msg === '') {
            return self::ROUTE_GENERAL;
        }
        $heuristic = self::heuristic_classify($msg);
        $route = apply_filters('xabia_router_classify_route', $heuristic, $project_id, $msg, $config, $lang);
        if (!in_array($route, [self::ROUTE_ACTION, self::ROUTE_KNOWLEDGE, self::ROUTE_GENERAL], true)) {
            $route = $heuristic;
        }
        return $route;
    }

    private static function heuristic_classify(string $msg): string {
        $m = strtolower($msg);
        $pure_greeting = preg_match('/^(hola|buenas|kaixo|aupa|hey|hi|hello|gracias|adios|agur|qué tal|que tal|buenos dias|buenas tardes)\b/u', $m) === 1
            && strlen($m) < 48
            && !preg_match('/\b(hac[eé]is|hacen|ofrec[eé]is|ten[eé]is|empresas?|actividades?|hípica|hipica|caballo|turismo|aventura)\b/u', $m);
        if ($pure_greeting) {
            return self::ROUTE_GENERAL;
        }
        if (preg_match('/\b(disponibilidad|disponible|libre|reserv|precio|hueco|checkin|checkout|alojamiento|alojamientos|casa|casas|habitacion|habitación|estancia|noches?|dias?|días?|hoy|mañana|manana|fechas?|enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre|quincena|finales|principios)\b/u', $m) === 1) {
            return self::ROUTE_ACTION;
        }
        return self::ROUTE_KNOWLEDGE;
    }

    public static function maybe_handle_action_route(string $project_id, string $message, array $config = [], array $request = []): ?string {
        $response = apply_filters('xabia_router_action_response', null, $project_id, $message, $config, $request);
        if (is_string($response) && trim($response) !== '') {
            return $response;
        }
        return null;
    }

    /**
     * Normaliza la consulta para caché FAQ: une variantes cercanas sin fusionar intenciones distintas.
     */
    public static function normalize_query(string $q): string {
        $q = trim($q);
        if ($q === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            $q = mb_strtolower($q, 'UTF-8');
        } else {
            $q = strtolower($q);
        }

        // Quitar signos de interrogación/exclamación y puntuación superficial.
        $q = preg_replace('/[¿?¡!.,;:\"\'«»]+/u', ' ', $q);
        $q = is_string($q) ? $q : '';

        // Muletillas / cortesía que no cambian la intención factual.
        $fillers = [
            'por favor', 'porfa', 'gracias', 'hola', 'hey', 'buenas', 'buenos dias', 'buenas tardes', 'buenas noches',
            'me puedes decir', 'me podrias decir', 'puedes decirme', 'podrias decirme', 'puedes decir', 'podrias decir',
            'dime', 'sabes', 'quiero saber', 'necesito saber', 'me gustaria saber', 'me interesa saber',
            'a ver', 'oye', 'eh',
        ];
        foreach ($fillers as $f) {
            $q = str_replace($f, ' ', $q);
        }

        // Variantes FAQ frecuentes (agnósticas; ampliables por filtro).
        $synonyms = [
            'fuegos artificiales' => 'fuegos',
            'pirotecnia' => 'fuegos',
            'castillo de fuegos' => 'fuegos',
            'horario del metro' => 'metro horario',
            'hora del metro' => 'metro horario',
            'hasta que hora el metro' => 'metro horario',
            'hasta que hora metro' => 'metro horario',
            'a que hora son' => 'hora',
            'a que hora es' => 'hora',
            'a que hora' => 'hora',
            'que hora' => 'hora',
            'actividades infantiles' => 'infantil',
            'para ninos' => 'infantil',
            'para niños' => 'infantil',
            'para ninas' => 'infantil',
            'para niñas' => 'infantil',
        ];
        /** @var array<string, string> $synonyms */
        $synonyms = apply_filters('xabia_response_cache_query_synonyms', $synonyms, $q);
        if (is_array($synonyms)) {
            uksort($synonyms, static function ($a, $b) {
                return strlen((string) $b) <=> strlen((string) $a);
            });
            foreach ($synonyms as $from => $to) {
                $from = trim((string) $from);
                $to = trim((string) $to);
                if ($from === '') {
                    continue;
                }
                $q = str_replace($from, $to !== '' ? $to : ' ', $q);
            }
        }

        $q = preg_replace('/\s+/u', ' ', $q);
        $q = trim((string) $q);

        /** @var string $filtered */
        $filtered = apply_filters('xabia_response_cache_normalize_query', $q);
        if (is_string($filtered) && $filtered !== '') {
            $q = $filtered;
        }

        return $q;
    }

    public static function query_hash(string $project_id, string $query, string $route, string $lang): string {
        $ver = defined('XABIA_VERSION') ? (string) XABIA_VERSION : '1';
        /** @var string $ver */
        $ver = (string) apply_filters('xabia_response_cache_version', $ver, $project_id, $route);

        return hash('sha256', $project_id . '|' . self::normalize_query($query) . '|' . $route . '|' . strtolower($lang) . '|' . $ver);
    }

    /**
     * Busca respuesta cacheada sin conocer la ruta (evita mini-LLM del router en hits).
     *
     * @return array{response: string, source_type: string, expiry: string}|null
     */
    public static function find_cached_response_for_query(string $project_id, string $query, string $lang): ?array {
        if ($project_id === '' || trim($query) === '') {
            return null;
        }
        $hashes = [
            self::query_hash($project_id, $query, self::ROUTE_KNOWLEDGE, $lang),
            self::query_hash($project_id, $query, self::ROUTE_GENERAL, $lang),
        ];

        return self::get_cached_response_by_hashes($project_id, $hashes);
    }

    /**
     * @param list<string> $query_hashes
     * @return array{response: string, source_type: string, expiry: string}|null
     */
    public static function get_cached_response_by_hashes(string $project_id, array $query_hashes): ?array {
        $query_hashes = array_values(array_filter(array_map('strval', $query_hashes)));
        if ($project_id === '' || $query_hashes === []) {
            return null;
        }
        global $wpdb;
        $table = Xabia_DB::table('response_cache');
        $now = gmdate('Y-m-d H:i:s');
        $placeholders = implode(', ', array_fill(0, count($query_hashes), '%s'));
        $sql = "SELECT response, source_type, expiry FROM $table
            WHERE project_id = %s AND query_hash IN ($placeholders) AND expiry > %s
            ORDER BY id DESC LIMIT 1";
        $args = array_merge([$project_id], $query_hashes, [$now]);
        $row = $wpdb->get_row($wpdb->prepare($sql, $args), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public static function get_cached_response(string $project_id, string $query_hash): ?array {
        return self::get_cached_response_by_hashes($project_id, [$query_hash]);
    }

    public static function put_cached_response(string $project_id, string $query_hash, string $response, string $source_type): void {
        global $wpdb;
        $table = Xabia_DB::table('response_cache');
        $ttl = ($source_type === self::ROUTE_ACTION) ? (15 * MINUTE_IN_SECONDS) : DAY_IN_SECONDS;
        $expiry = gmdate('Y-m-d H:i:s', time() + $ttl);
        $wpdb->insert($table, [
            'project_id' => $project_id,
            'query_hash' => $query_hash,
            'response' => $response,
            'source_type' => $source_type,
            'expiry' => $expiry,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public static function detect_sensitive(string $text): bool {
        $hasEmail = preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', $text) === 1;
        $hasPhone = preg_match('/(?:\+?\d[\d\s().-]{7,}\d)/u', $text) === 1;
        return $hasEmail || $hasPhone;
    }
}
endif;

