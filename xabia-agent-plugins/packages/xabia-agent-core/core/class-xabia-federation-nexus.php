<?php
/**
 * Federación Nexus — puente REST, nodos amigos y consulta remota (ask_federated_node).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Federation_Nexus {

    public const OPTION_NODES = 'xabia_federation_nodes';
    public const OPTION_BRIDGE_KEY = 'xabia_federation_bridge_key';
    public const OPTION_BRIDGE_ONLY = 'xabia_federation_bridge_only';

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_rest_routes']);
    }

    public static function is_bridge_only_mode(): bool {
        return (bool) get_option(self::OPTION_BRIDGE_ONLY, false);
    }

    /**
     * @return list<array{name:string,url:string,category:string,api_key:string,project_id:string,slug:string}>
     */
    public static function get_friend_nodes(): array {
        $raw = get_option(self::OPTION_NODES, []);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = isset($row['name']) ? sanitize_text_field((string) $row['name']) : '';
            $url = isset($row['url']) ? esc_url_raw((string) $row['url']) : '';
            if ($name === '' || $url === '') {
                continue;
            }
            $slug = isset($row['slug']) ? sanitize_title((string) $row['slug']) : '';
            if ($slug === '') {
                $slug = sanitize_title($name);
            }
            $out[] = [
                'name'       => $name,
                'url'        => $url,
                'category'   => isset($row['category']) ? sanitize_text_field((string) $row['category']) : '',
                'api_key'    => isset($row['api_key']) ? (string) $row['api_key'] : '',
                'project_id' => isset($row['project_id']) ? sanitize_key((string) $row['project_id']) : '',
                'slug'       => $slug,
            ];
        }

        return $out;
    }

    public static function get_node_by_slug(string $slug): ?array {
        $slug = sanitize_title($slug);
        if ($slug === '') {
            return null;
        }
        foreach (self::get_friend_nodes() as $n) {
            if (($n['slug'] ?? '') === $slug) {
                return $n;
            }
        }

        return null;
    }

    public static function get_bridge_secret(): string {
        $k = get_option(self::OPTION_BRIDGE_KEY, '');
        return is_string($k) ? trim($k) : '';
    }

    /**
     * Clave para que nodos remotos llamen a este sitio (GET /federate).
     */
    public static function ensure_bridge_secret(): string {
        $k = self::get_bridge_secret();
        if ($k !== '') {
            return $k;
        }
        $k = bin2hex(random_bytes(24));
        update_option(self::OPTION_BRIDGE_KEY, $k, false);

        return $k;
    }

    public static function register_rest_routes(): void {
        register_rest_route(
            'xabia/v1',
            '/federate',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [self::class, 'rest_federate'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'q'       => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => static function ($v) {
                            return sanitize_text_field((string) $v);
                        },
                    ],
                    'project' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => static function ($v) {
                            return sanitize_key((string) $v);
                        },
                    ],
                ],
            ]
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_federate($request) {
        $provided = '';
        if (!empty($_SERVER['HTTP_X_XABIA_FED_KEY'])) {
            $provided = sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_X_XABIA_FED_KEY']));
        }
        $secret = self::get_bridge_secret();
        if ($secret === '' || !hash_equals($secret, $provided)) {
            return new WP_Error('xabia_fed_forbidden', __('Clave de federación no válida.', 'xabia-intelligence'), ['status' => 403]);
        }

        $q = (string) $request->get_param('q');
        $project_id = (string) $request->get_param('project');
        if ($q === '' || $project_id === '') {
            return new WP_Error('xabia_fed_bad_request', __('Parámetros q y project son obligatorios.', 'xabia-intelligence'), ['status' => 400]);
        }

        if (class_exists('Xabia_API')) {
            Xabia_API::digixop_reset_session_for_federation();
        }

        $projects = get_option('xabia_projects_config', []);
        $config = is_array($projects) && isset($projects[$project_id]) ? $projects[$project_id] : null;
        if (!is_array($config)) {
            return new WP_Error('xabia_fed_unknown_project', __('Proyecto no encontrado.', 'xabia-intelligence'), ['status' => 404]);
        }

        $bundle = self::run_semantic_search_bundle($project_id, $q, $config);
        if (class_exists('Xabia_Digixop_Client') && Xabia_Digixop_Client::was_insufficient_balance()) {
            return new WP_Error(
                'digixop_insufficient',
                Xabia_Digixop_Client::get_insufficient_balance_user_message(),
                ['status' => 402]
            );
        }
        $raw_context = $bundle['context'];

        if (!class_exists('Xabia_API')) {
            return new WP_REST_Response(
                [
                    'summary' => __('Motor de IA no disponible.', 'xabia-intelligence'),
                    'links'   => [],
                    'query'   => $q,
                    'project' => $project_id,
                ],
                200
            );
        }

        $sum = Xabia_API::federation_summarize_context($q, $raw_context, $project_id, $config);
        if (class_exists('Xabia_API')) {
            Xabia_API::digixop_report_federation_session($project_id);
        }

        return new WP_REST_Response(
            [
                'summary' => $sum['summary'],
                'links'   => $sum['links'],
                'query'   => $q,
                'project' => $project_id,
            ],
            200
        );
    }

    /**
     * @return array{context:string,chunk_count:int,had_rows:bool}
     */
    public static function run_semantic_search_bundle(string $project_id, string $search_term, array $config): array {
        if (!class_exists('Xabia_Brain')) {
            $p = defined('XABIA_PATH') ? XABIA_PATH : dirname(__DIR__) . '/';
            $bp = $p . 'core/class-xabia-brain.php';
            if (file_exists($bp)) {
                require_once $bp;
            }
        }
        $use_vector = !empty($config['rules']['use_vector_search']);
        $max_chunks = class_exists('Xabia_Brain', false) ? Xabia_Brain::effective_rag_max_chunks_from_project_config($config) : 4;
        $similarity_threshold = isset($config['rules']['similarity_threshold']) ? max(0, min(1, (float) $config['rules']['similarity_threshold'])) : 0.2;
        $ente_scope = 'global';
        $strict_ente = false;

        $context = '';
        $chunk_count = 0;
        $had_rows = false;

        if (class_exists('Xabia_Brain')) {
            if ($use_vector) {
                $query_vector = null;
                if (class_exists('Xabia_API')) {
                    $query_vector = Xabia_API::get_query_embedding($search_term, $config, $project_id);
                    Xabia_API::digixop_absorb_embedding_for_federation($project_id, $config);
                    if (class_exists('Xabia_Digixop_Client') && Xabia_Digixop_Client::was_insufficient_balance()) {
                        return [
                            'context'     => '',
                            'chunk_count' => 0,
                            'had_rows'    => false,
                        ];
                    }
                }
                $out = Xabia_Brain::search_knowledge_vector($project_id, $search_term, $ente_scope, false, $max_chunks, $similarity_threshold, $query_vector);
                $context = $out['context'] ?? '';
                $chunk_count = (int) ($out['chunk_count'] ?? 0);
                $had_rows = ($chunk_count > 0);
                if (strlen(trim((string) $context)) < 10) {
                    $context = Xabia_Brain::search_knowledge($project_id, $search_term, $ente_scope, $strict_ente, $max_chunks);
                    $chunk_count = 0;
                    $had_rows = strlen(trim($context)) >= 10;
                } elseif (class_exists('Xabia_API', false) && Xabia_API::rag_context_misses_query_signal_terms($search_term, $context)) {
                    $lex = Xabia_Brain::search_knowledge($project_id, $search_term, $ente_scope, $strict_ente, $max_chunks);
                    if (strlen(trim($lex)) >= 10) {
                        $context .= "\n\n### Búsqueda por palabras clave (refuerzo) ###\n" . $lex;
                        $had_rows = true;
                    }
                }
            } else {
                $context = Xabia_Brain::search_knowledge($project_id, $search_term, $ente_scope, $strict_ente, $max_chunks);
                $had_rows = strlen(trim($context)) >= 10;
            }
        }

        $discovery = apply_filters('xabia_chat_addon_discovery_blocks', [], $project_id, $config);
        if (is_array($discovery) && $discovery !== []) {
            $disc_text = trim(implode("\n\n", array_filter(array_map('strval', $discovery))));
            if ($disc_text !== '') {
                $context .= "\n\n### ADDON DISCOVERY ###\n" . $disc_text;
            }
        }

        if (strlen($context) > 20000) {
            $context = substr($context, 0, 20000) . '...';
        }

        return [
            'context'     => $context,
            'chunk_count' => $chunk_count,
            'had_rows'    => $had_rows,
        ];
    }

    public static function extract_urls_from_text(string $text): array {
        $links = [];
        if ($text === '') {
            return $links;
        }
        if (!preg_match_all('#https?://[^\s\]\)>"\'<>]+#iu', $text, $m)) {
            return $links;
        }
        foreach ($m[0] as $u) {
            $u = esc_url_raw(rtrim($u, '.,;:)'));
            if ($u !== '' && strlen($u) < 2000) {
                $links[$u] = ['title' => $u, 'url' => $u];
            }
        }

        return array_values($links);
    }

    /**
     * Consulta un nodo amigo (GET federate en el remoto).
     */
    public static function ask_federated_node(string $node_slug, string $query, string $caller_project_id = ''): string {
        $node = self::get_node_by_slug($node_slug);
        if ($node === null) {
            return __('Nodo federado no encontrado o slug inválido.', 'xabia-intelligence');
        }
        $remote_project = (string) ($node['project_id'] ?? '');
        if ($remote_project === '') {
            return __('El nodo no tiene project_id remoto configurado.', 'xabia-intelligence');
        }
        $base = rtrim((string) $node['url'], '/');
        $url = $base . '/wp-json/xabia/v1/federate?' . http_build_query(
            [
                'q'       => $query,
                'project' => $remote_project,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $headers = [
            'Accept' => 'application/json',
        ];
        $api_key = (string) ($node['api_key'] ?? '');
        if ($api_key !== '') {
            $headers['X-Xabia-Fed-Key'] = $api_key;
        }

        $resp = wp_remote_get(
            $url,
            [
                'headers' => $headers,
                'timeout' => 25,
            ]
        );
        if (is_wp_error($resp)) {
            return sprintf(
                
                __('Error al contactar el nodo: %s', 'xabia-intelligence'),
                $resp->get_error_message()
            );
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        if ($code === 403) {
            return __('El nodo rechazó la clave de federación (403).', 'xabia-intelligence');
        }
        if ($code < 200 || $code >= 300) {
            return sprintf(
                
                __('Respuesta HTTP no válida del nodo (%d).', 'xabia-intelligence'),
                $code
            );
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return __('El nodo devolvió un cuerpo no JSON.', 'xabia-intelligence');
        }
        $summary = isset($json['summary']) ? (string) $json['summary'] : '';
        $links = isset($json['links']) && is_array($json['links']) ? $json['links'] : [];
        $lines = [];
        $lines[] = '--- ' . __('Contexto del nodo federado', 'xabia-intelligence') . ' "' . ($node['name'] ?? $node_slug) . '" ---';
        if ($summary !== '') {
            $lines[] = $summary;
        }
        if ($links !== []) {
            $lines[] = __('Enlaces de interés (remoto):', 'xabia-intelligence');
            foreach ($links as $L) {
                if (!is_array($L)) {
                    continue;
                }
                $t = isset($L['title']) ? (string) $L['title'] : '';
                $u = isset($L['url']) ? (string) $L['url'] : '';
                if ($u !== '') {
                    $lines[] = '- ' . ($t !== '' ? $t . ': ' : '') . $u;
                }
            }
        }
        if (count($lines) < 2) {
            return __('El nodo no devolvió resumen útil.', 'xabia-intelligence');
        }

        return implode("\n", $lines);
    }

    /**
     * Bloque de sistema para Gemini (Vertex): obligación de usar la herramienta federada cuando falte contexto local.
     */
    public static function federation_vertex_consciousness_block(): string {
        if (self::get_friend_nodes() === []) {
            return '';
        }
        return __(
            'Tienes acceso a una red de nodos federados. Si te preguntan por algo que no está en tu base de datos local, DEBES usar la herramienta ask_federated_node consultando el nodo más relevante.',
            'xabia-intelligence'
        );
    }

    public static function federation_tool_definitions(): array {
        $nodes = self::get_friend_nodes();
        if ($nodes === []) {
            return [];
        }
        $desc = __('Slugs disponibles: ', 'xabia-intelligence');
        $desc .= implode(', ', array_map(static function ($n) {
            return (string) ($n['slug'] ?? '');
        }, $nodes));

        return [
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'ask_federated_node',
                    'description' => __('Consulta un nodo experto federado cuando el contexto local no cubre un lugar o servicio. Devuelve un resumen y enlaces del sitio remoto.', 'xabia-intelligence'),
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'node' => [
                                'type'        => 'string',
                                'description' => $desc,
                            ],
                            'query' => [
                                'type'        => 'string',
                                'description' => __('Pregunta o término de búsqueda para el nodo remoto.', 'xabia-intelligence'),
                            ],
                        ],
                        'required'   => ['node', 'query'],
                    ],
                ],
            ],
        ];
    }

    public static function federation_tool_instruction_block(): string {
        $nodes = self::get_friend_nodes();
        if ($nodes === []) {
            return '';
        }
        $lines = [__('NODOS FEDERADOS (Nexus):', 'xabia-intelligence')];
        foreach ($nodes as $n) {
            $lines[] = '- ' . sprintf(
                
                __('%1$s (slug: %2$s) — categoría: %3$s', 'xabia-intelligence'),
                $n['name'],
                $n['slug'],
                $n['category'] !== '' ? $n['category'] : '—'
            );
        }
        $lines[] = __('Si no tienes información local suficiente sobre un lugar o servicio que podría cubrir uno de estos nodos, usa la herramienta ask_federated_node con el slug correcto y una query clara en español o en el idioma del usuario.', 'xabia-intelligence');

        return implode("\n", $lines);
    }
}

/**
 * Función global invocable desde extensiones / pruebas.
 */
function xabia_federation_ask_node(string $node_slug, string $query, string $caller_project_id = ''): string {
    return Xabia_Federation_Nexus::ask_federated_node($node_slug, $query, $caller_project_id);
}
