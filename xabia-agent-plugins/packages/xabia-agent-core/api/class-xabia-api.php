<?php
/**
 * XABIA API — motor de chat, endpoints AJAX, OpenAI / Vertex / Digixop.
 * FILOSOFÍA: Motor agnóstico con capacidades de Google Cloud Enterprise.
 * SEGURIDAD: Nonces, Sanitización y Gestión de Sesiones.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('Xabia_API')) {

    class Xabia_API {

        /** @var array{prompt: int, completion: int, total: int} */
        private static $digixop_session_usage = ['prompt' => 0, 'completion' => 0, 'total' => 0];

        private static $digixop_session_proxy_used = false;

        /** @var array{prompt_tokens:int, completion_tokens:int, model:string, estimated_cost:float}|null */
        private static $last_generation_metrics = null;

        /** @var string Último motivo de parada devuelto por el proveedor (p. ej. length / MAX_TOKENS). */
        private static $last_generation_finish_reason = '';

        /** @var array<string, mixed> Diagnóstico RAG del último turno (Playground / admin). */
        private static $last_rag_debug = [];

        /** Bypass de caché RAG durante la petición actual (modo desarrollo). */
        private static $rag_development_mode_active = false;

        /** Vertex directo (JSON en WordPress): alineado con Hub / modelo económico. */
        private const VERTEX_LOCAL_LOCATION = 'europe-west1';
        private const VERTEX_LOCAL_CHAT_MODEL = 'gemini-2.5-flash';
        private const VERTEX_EMBEDDING_MODEL = 'gemini-embedding-001';

        public static function init() {
            if (!has_action('wp_ajax_xabia_ask_ai', [__CLASS__, 'handle_chat_request'])) {
                add_action('wp_ajax_xabia_ask_ai', [__CLASS__, 'handle_chat_request']);
                add_action('wp_ajax_nopriv_xabia_ask_ai', [__CLASS__, 'handle_chat_request']);
            }
            add_action('wp_ajax_xabia_clear_session', [__CLASS__, 'handle_clear_session']);
            add_action('wp_ajax_nopriv_xabia_clear_session', [__CLASS__, 'handle_clear_session']);
            add_action('wp_ajax_xabia_resolve_image', [__CLASS__, 'handle_resolve_image']);
            add_action('wp_ajax_nopriv_xabia_resolve_image', [__CLASS__, 'handle_resolve_image']);
            add_action('wp_ajax_xabia_tts', [__CLASS__, 'handle_tts_request']);
            add_action('wp_ajax_nopriv_xabia_tts', [__CLASS__, 'handle_tts_request']);
        }

        /**
         * Bloquea endpoints PRO si el runtime es LITE (licencia ausente o build WP.org).
         */
        private static function assert_pro_runtime(string $feature = 'pro_only'): void {
            if (class_exists('Xabia_Features', false) && Xabia_Features::is_lite()) {
                Xabia_Features::reject_pro_json($feature);
            }
        }

        /**
         * Compatibilidad: delega en {@see xabia_trace()}.
         *
         * Desactivar fichero: define('XABIA_DISABLE_CHAT_TRACE', true) en wp-config.php.
         */
        public static function chat_trace(string $message, $data = null): void {
            if (function_exists('xabia_trace')) {
                xabia_trace($message, $data);
            } else {
                error_log($message);
            }
        }

        /**
         * Resuelve rutas de imagen (relativas o solo nombre) a URLs en wp-content/uploads.
         * Acepta path (uno) o paths[] (lote). Devuelve { urls: { path: url } }.
         * Rutas con '/' → baseurl + path. Solo nombre → búsqueda en biblioteca de medios (una query por lote).
         */
        public static function handle_resolve_image() {
            self::assert_pro_runtime('vector_rag');
            $paths = [];
            if (isset($_REQUEST['paths']) && is_array($_REQUEST['paths'])) {
                $paths = array_map(function ($p) {
                    return self::sanitize_image_path($p);
                }, wp_unslash($_REQUEST['paths']));
            } elseif (isset($_REQUEST['path'])) {
                $one = self::sanitize_image_path(wp_unslash($_REQUEST['path']));
                if ($one !== '') {
                    $paths = [$one];
                }
            }
            $paths = array_unique(array_filter($paths, 'strlen'));
            $paths = array_slice($paths, 0, 100); 
            if (empty($paths)) {
                wp_send_json_success(['urls' => (object) []]);
                return;
            }

            $uploads = wp_upload_dir();
            $baseurl = isset($uploads['baseurl']) ? rtrim($uploads['baseurl'], '/') . '/' : '';
            $urls = self::resolve_image_paths_batch($paths, $baseurl);
            wp_send_json_success(['urls' => $urls]);
        }

        /**
         * TTS servidor → audio real (HTML5). Evita speechSynthesis mudo en Chrome/macOS.
         * Orden: Google Cloud TTS → OpenAI audio/speech → say (solo Darwin local).
         */
        public static function handle_tts_request(): void {
            self::assert_pro_runtime('voice_tts');
            $text = isset($_POST['text']) ? wp_strip_all_tags(wp_unslash((string) $_POST['text'])) : '';
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            if ($text === '') {
                wp_send_json_error(['message' => 'empty'], 400);
                return;
            }
            if (function_exists('mb_strlen') ? mb_strlen($text) > 1200 : strlen($text) > 1200) {
                $text = function_exists('mb_substr') ? mb_substr($text, 0, 1200) : substr($text, 0, 1200);
            }

            $project_id = isset($_POST['project_id']) ? sanitize_key(wp_unslash((string) $_POST['project_id'])) : '';
            $lang = isset($_POST['lang']) ? sanitize_text_field(wp_unslash((string) $_POST['lang'])) : 'es';
            $lang = strtolower(preg_replace('/[^a-z]/', '', $lang) ?: 'es');
            $voice_pref = isset($_POST['voice']) ? sanitize_key(wp_unslash((string) $_POST['voice'])) : 'default';
            $rate = isset($_POST['rate']) ? (float) $_POST['rate'] : 1.0;
            $rate = max(0.5, min(2.0, $rate > 0 ? $rate : 1.0));

            $projects = get_option('xabia_projects_config', []);
            $config = (is_array($projects) && isset($projects[$project_id]) && is_array($projects[$project_id]))
                ? $projects[$project_id]
                : [];

            $audio = self::synthesize_tts_audio($text, $lang, $voice_pref, $rate, $project_id, $config);
            if ($audio === null || empty($audio['base64']) || empty($audio['mime'])) {
                wp_send_json_error(['message' => 'tts_unavailable'], 503);
                return;
            }

            wp_send_json_success([
                'mime'   => $audio['mime'],
                'base64' => $audio['base64'],
                'engine' => $audio['engine'] ?? 'unknown',
            ]);
        }

        /**
         * @param array<string, mixed> $config
         * @return array{base64: string, mime: string, engine: string}|null
         */
        private static function synthesize_tts_audio(string $text, string $lang, string $voice_pref, float $rate, string $project_id, array $config): ?array {
            $g = self::synthesize_tts_google_cloud($text, $lang, $voice_pref, $rate, $config);
            if ($g !== null) {
                return $g;
            }
            $o = self::synthesize_tts_openai($text, $lang, $voice_pref, $rate, $project_id, $config);
            if ($o !== null) {
                return $o;
            }
            return self::synthesize_tts_macos_say($text, $lang, $voice_pref, $rate);
        }

        /**
         * @param array<string, mixed> $config
         * @return array{base64: string, mime: string, engine: string}|null
         */
        private static function synthesize_tts_google_cloud(string $text, string $lang, string $voice_pref, float $rate, array $config): ?array {
            $auth = self::get_google_vertex_auth($config);
            if ($auth === null) {
                return null;
            }
            $lang_map = [
                'es' => 'es-ES',
                'en' => 'en-US',
                'eu' => 'eu-ES',
                'fr' => 'fr-FR',
                'de' => 'de-DE',
                'it' => 'it-IT',
                'pt' => 'pt-PT',
            ];
            $language = $lang_map[$lang] ?? ($lang . '-' . strtoupper($lang));
            $voice_name = '';
            if ($lang === 'es') {
                $voice_name = ($voice_pref === 'male') ? 'es-ES-Neural2-B' : 'es-ES-Neural2-A';
            } elseif ($lang === 'en') {
                $voice_name = ($voice_pref === 'male') ? 'en-US-Neural2-D' : 'en-US-Neural2-C';
            }
            $body = [
                'input'       => ['text' => $text],
                'voice'       => array_filter([
                    'languageCode' => $language,
                    'name'         => $voice_name !== '' ? $voice_name : null,
                ]),
                'audioConfig' => [
                    'audioEncoding' => 'MP3',
                    'speakingRate'  => $rate,
                ],
            ];
            $resp = wp_remote_post('https://texttospeech.googleapis.com/v1/text:synthesize', [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $auth['access_token'],
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode($body),
            ]);
            if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
                return null;
            }
            $json = json_decode((string) wp_remote_retrieve_body($resp), true);
            $b64 = is_array($json) ? (string) ($json['audioContent'] ?? '') : '';
            if ($b64 === '') {
                return null;
            }

            return ['base64' => $b64, 'mime' => 'audio/mpeg', 'engine' => 'google_cloud'];
        }

        /**
         * @param array<string, mixed> $config
         * @return array{base64: string, mime: string, engine: string}|null
         */
        private static function synthesize_tts_openai(string $text, string $lang, string $voice_pref, float $rate, string $project_id, array $config): ?array {
            if (!class_exists('Xabia_Digixop_Client', false)) {
                return null;
            }
            $key = Xabia_Digixop_Client::get_effective_openai_key($project_id, $config);
            if ($key === '') {
                return null;
            }
            $voice = 'nova';
            if ($voice_pref === 'male') {
                $voice = 'onyx';
            } elseif ($voice_pref === 'female') {
                $voice = 'nova';
            }
            $resp = wp_remote_post('https://api.openai.com/v1/audio/speech', [
                'timeout' => 45,
                'headers' => [
                    'Authorization' => 'Bearer ' . $key,
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode([
                    'model'           => 'tts-1',
                    'input'           => $text,
                    'voice'           => $voice,
                    'response_format' => 'mp3',
                    'speed'           => $rate,
                ]),
            ]);
            if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
                return null;
            }
            $bin = (string) wp_remote_retrieve_body($resp);
            if ($bin === '' || strncmp($bin, '{', 1) === 0) {
                return null;
            }

            return ['base64' => base64_encode($bin), 'mime' => 'audio/mpeg', 'engine' => 'openai'];
        }

        /**
         * Fallback local macOS (Local by Flywheel / Darwin): genera audio audible vía `say`.
         *
         * @return array{base64: string, mime: string, engine: string}|null
         */
        private static function synthesize_tts_macos_say(string $text, string $lang, string $voice_pref, float $rate): ?array {
            if (PHP_OS_FAMILY !== 'Darwin') {
                return null;
            }
            $host = '';
            if (!empty($_SERVER['HTTP_HOST'])) {
                $host = strtolower((string) $_SERVER['HTTP_HOST']);
            }
            $is_local = str_contains($host, '.local') || $host === 'localhost' || $host === '127.0.0.1';
            if (!$is_local && !(defined('WP_DEBUG') && WP_DEBUG)) {
                return null;
            }
            if (!function_exists('exec') || !is_executable('/usr/bin/say')) {
                return null;
            }

            $voice = 'Monica';
            if ($lang === 'es') {
                $voice = ($voice_pref === 'male') ? 'Jorge' : 'Monica';
            } elseif ($lang === 'en') {
                $voice = ($voice_pref === 'male') ? 'Alex' : 'Samantha';
            } elseif ($lang === 'eu') {
                $voice = 'Monica';
            }

            $rate_wpm = (int) round(175 * $rate);
            $rate_wpm = max(90, min(350, $rate_wpm));

            $dir = trailingslashit(get_temp_dir()) . 'xabia-tts';
            if (!is_dir($dir) && !wp_mkdir_p($dir)) {
                return null;
            }
            $id = uniqid('tts_', true);
            $aiff = $dir . '/' . $id . '.aiff';
            $m4a = $dir . '/' . $id . '.m4a';

            $cmd = sprintf(
                '/usr/bin/say -v %s -r %d -o %s %s 2>/dev/null',
                escapeshellarg($voice),
                $rate_wpm,
                escapeshellarg($aiff),
                escapeshellarg($text)
            );
            exec($cmd, $out, $code);
            if ($code !== 0 || !is_readable($aiff) || filesize($aiff) < 32) {
                @unlink($aiff);
                return null;
            }

            $mime = 'audio/aiff';
            $file = $aiff;
            if (is_executable('/usr/bin/afconvert')) {
                $conv = sprintf(
                    '/usr/bin/afconvert -f m4af -d aac %s %s 2>/dev/null',
                    escapeshellarg($aiff),
                    escapeshellarg($m4a)
                );
                exec($conv, $out2, $code2);
                if ($code2 === 0 && is_readable($m4a) && filesize($m4a) > 32) {
                    $mime = 'audio/mp4';
                    $file = $m4a;
                    @unlink($aiff);
                }
            }

            $bin = file_get_contents($file);
            @unlink($file);
            if (!is_string($bin) || $bin === '') {
                return null;
            }

            return ['base64' => base64_encode($bin), 'mime' => $mime, 'engine' => 'macos_say'];
        }

        /**
         * Sanitiza una ruta de imagen: sin '..', sin protocolo, solo caracteres seguros.
         */
        private static function sanitize_image_path($path) {
            $path = sanitize_text_field(is_string($path) ? $path : '');
            $path = trim($path, "/ \t\n\r");
            if ($path === '' || strpos($path, '..') !== false || preg_match('#^https?://#i', $path)) {
                return '';
            }
            return $path;
        }

        /**
         * Resuelve un lote de paths a URLs. Una sola query para todos los "solo nombre de archivo".
         */
        private static function resolve_image_paths_batch(array $paths, $baseurl) {
            $urls = [];
            $filenames_only = [];

            foreach ($paths as $path) {
                if (strpos($path, '/') !== false) {
                    $urls[$path] = $baseurl . $path;
                } else {
                    $filenames_only[] = $path;
                    $urls[$path] = $baseurl . $path; 
                }
            }

            if (empty($filenames_only)) {
                return $urls;
            }

            global $wpdb;
            $meta_table = $wpdb->postmeta;
            $posts_table = $wpdb->posts;

            
            $placeholders = [];
            $values = [];
            foreach ($filenames_only as $name) {
                $placeholders[] = '(m.meta_value = %s OR m.meta_value LIKE %s)';
                $values[] = $name;
                $values[] = '%/' . $wpdb->esc_like($name);
            }
            $where = implode(' OR ', $placeholders);
            $sql = "SELECT m.meta_value AS file_path, p.ID
                    FROM {$meta_table} m
                    INNER JOIN {$posts_table} p ON p.ID = m.post_id AND p.post_type = 'attachment'
                    WHERE m.meta_key = '_wp_attached_file' AND ({$where})
                    ORDER BY p.ID DESC";

            $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);
            $seen = [];
            foreach ($rows as $row) {
                $file_path = $row['file_path'];
                $basename = basename($file_path);
                if (!isset($seen[$basename])) {
                    $seen[$basename] = true;
                    $url = wp_get_attachment_url((int) $row['ID']);
                    if ($url && isset($urls[$basename])) {
                        $urls[$basename] = $url;
                    }
                }
            }
            
            foreach ($filenames_only as $name) {
                if (isset($seen[$name])) {
                    continue;
                }
                $id = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$meta_table} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
                    $name
                ));
                if ($id) {
                    $url = wp_get_attachment_url((int) $id);
                    if ($url) {
                        $urls[$name] = $url;
                    }
                }
            }

            return $urls;
        }

        /**
         * Resuelve una pista textual (p. ej. "Garaixe", nombre de casa) a URL de imagen.
         * Orden: filtro xabia_resolve_img_hint → adjunto por título → miniatura de entrada publicada → nombre de archivo en uploads.
         *
         * @param string $hint Texto dentro de [ACTION:IMG:…] que no es ID ni URL.
         */
        private static function resolve_img_hint_to_url($hint) {
            $hint = trim((string) $hint);
            if ($hint === '' || strlen($hint) > 120) {
                return '';
            }
            global $wpdb;
            $like = '%' . $wpdb->esc_like($hint) . '%';

            $aid = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'
                 AND post_title LIKE %s ORDER BY ID DESC LIMIT 1",
                $like
            ));
            if ($aid) {
                $u = wp_get_attachment_url((int) $aid);
                if (is_string($u) && $u !== '') {
                    return $u;
                }
            }

            $thumb = $wpdb->get_var($wpdb->prepare(
                "SELECT pm.meta_value FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id'
                 WHERE p.post_status = 'publish' AND p.post_title LIKE %s
                 ORDER BY p.post_modified DESC LIMIT 1",
                $like
            ));
            if ($thumb) {
                $u = wp_get_attachment_url((int) $thumb);
                if (is_string($u) && $u !== '') {
                    return $u;
                }
            }

            $uploads = wp_upload_dir();
            $baseurl = isset($uploads['baseurl']) ? rtrim((string) $uploads['baseurl'], '/') . '/' : '';
            if ($baseurl !== '') {
                $slug = sanitize_file_name($hint);
                if ($slug !== '') {
                    foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $ext) {
                        $try = $slug . '.' . $ext;
                        $batch = self::resolve_image_paths_batch([$try], $baseurl);
                        if (!empty($batch[$try]) && preg_match('#^https?://#i', (string) $batch[$try])) {
                            return (string) $batch[$try];
                        }
                    }
                }
            }

            return '';
        }

        /**
         * Limpia la sesión de chat del cliente (historial y última búsqueda).
         * Usado por Modo Tótem para reiniciar sin rastro del usuario anterior.
         */
        public static function handle_clear_session() {
            $project_id = sanitize_text_field($_POST['project_id'] ?? '');
            if (empty($project_id)) {
                wp_send_json_success(['ok' => true]);
                return;
            }
            if (!session_id() && !headers_sent()) {
                session_start();
            }
            if (isset($_SESSION['xabia_chat_history'][$project_id])) {
                unset($_SESSION['xabia_chat_history'][$project_id]);
            }
            if (isset($_SESSION['xabia_last_search'][$project_id])) {
                unset($_SESSION['xabia_last_search'][$project_id]);
            }
            if (isset($_SESSION['xabia_last_entity'][$project_id])) {
                unset($_SESSION['xabia_last_entity'][$project_id]);
            }
            wp_send_json_success(['ok' => true]);
        }

        private static function daily_token_limit_for_project(array $config): int {
            $limit = isset($config['rules']['daily_token_limit']) ? (int) $config['rules']['daily_token_limit'] : 0;
            if ($limit < 0) {
                $limit = 0;
            }
            if ($limit === 0) {
                $limit = (int) apply_filters('xabia_daily_token_limit_default', 20000, $config);
            }
            return max(0, $limit);
        }

        /**
         * Mensaje al visitante cuando se supera el límite diario de tokens del agente.
         *
         * @param array<string, mixed> $config
         */
        private static function daily_token_limit_user_message(array $config): string {
            $custom = apply_filters('xabia_daily_token_limit_message', '', $config);
            if (is_string($custom) && $custom !== '') {
                return $custom;
            }

            return __('El asistente está en mantenimiento por hoy. Puedes seguir explorando y comprando en la web con normalidad; vuelve a escribirme mañana.', 'xabia-intelligence');
        }

        private static function consumed_tokens_today(string $project_id): int {
            global $wpdb;
            $table = Xabia_DB::table('usage_logs');
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
                return 0;
            }
            $start = gmdate('Y-m-d 00:00:00');
            $end = gmdate('Y-m-d 23:59:59');
            $sum = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(tokens_input + tokens_output),0) FROM $table WHERE project_id = %s AND created_at BETWEEN %s AND %s",
                $project_id,
                $start,
                $end
            ));
            return (int) $sum;
        }

        private static function estimate_cost_usd(string $model, int $in, int $out): float {
            $m = strtolower($model);
            
            $in_rate = 0.00015;
            $out_rate = 0.00060;
            if (str_contains($m, 'mini') || str_contains($m, 'flash')) {
                $in_rate = 0.00005;
                $out_rate = 0.00020;
            }
            $in_rate = (float) apply_filters('xabia_model_input_rate_per_1k', $in_rate, $model);
            $out_rate = (float) apply_filters('xabia_model_output_rate_per_1k', $out_rate, $model);
            return (($in / 1000) * $in_rate) + (($out / 1000) * $out_rate);
        }

        private static function log_usage_metrics(string $project_id, string $user_message = ''): void {
            if (!is_array(self::$last_generation_metrics)) {
                return;
            }
            global $wpdb;
            $table = Xabia_DB::table('usage_logs');
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
                return;
            }
            $sensitive = class_exists('Xabia_Router') ? Xabia_Router::detect_sensitive($user_message) : false;
            $fingerprint = hash('sha256', strtolower(trim($user_message)));
            $tokens_input = (int) (self::$last_generation_metrics['prompt_tokens'] ?? 0);
            $tokens_output = (int) (self::$last_generation_metrics['completion_tokens'] ?? 0);
            $tokens_count = $tokens_input + $tokens_output;
            $wpdb->insert($table, [
                'project_id'     => $project_id,
                'model_used'     => (string) (self::$last_generation_metrics['model'] ?? 'unknown'),
                'tokens_input'   => $tokens_input,
                'tokens_output'  => $tokens_output,
                'tokens_count'   => $tokens_count,
                'estimated_cost' => (float) (self::$last_generation_metrics['estimated_cost'] ?? 0.0),
                'sensitive_detected' => $sensitive ? 1 : 0,
                'query_fingerprint' => $fingerprint,
                'created_at'     => gmdate('Y-m-d H:i:s'),
            ]);
            if ($wpdb->insert_id && class_exists('Xabia_DB', false)) {
                Xabia_DB::deduct_wallet_tokens($tokens_count);
            }
        }

        public static function classify_route_with_mini(string $project_id, string $message, array $config = [], string $lang_code = 'es'): string {
            unset($lang_code);
            $message = trim($message);
            if ($message === '') {
                return class_exists('Xabia_Router') ? Xabia_Router::ROUTE_GENERAL : 'ROUTE_GENERAL';
            }
            $schema = "Devuelve solo una etiqueta exacta: ROUTE_ACTION, ROUTE_KNOWLEDGE o ROUTE_GENERAL.\n"
                . "- ROUTE_ACTION: requiere herramienta externa o dato dinámico en tiempo real.\n"
                . "- ROUTE_KNOWLEDGE: consulta de conocimiento local/documental.\n"
                . "- ROUTE_GENERAL: saludo/charla breve.";
            $messages = [
                ['role' => 'system', 'content' => $schema],
                ['role' => 'user', 'content' => $message],
            ];
            $messages = self::sanitize_llm_messages_for_external_api($messages, $project_id, $config);
            $raw = self::call_auxiliary_llm($messages, 20, 0.0, $project_id, $config);
            $route = self::parse_route_label_from_llm_output($raw);
            if ($route !== null) {
                return $route;
            }
            return class_exists('Xabia_Router') ? Xabia_Router::ROUTE_KNOWLEDGE : 'ROUTE_KNOWLEDGE';
        }

        private static function maybe_summarize_history(array $history, string $project_id, array $config): array {
            if (count($history) <= 10) {
                return $history;
            }
            $slice = array_slice($history, -10);
            $lines = [];
            foreach ($slice as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $r = ($m['role'] ?? '') === 'assistant' ? 'Asistente' : 'Usuario';
                $c = trim((string) ($m['content'] ?? ''));
                if ($c === '') {
                    continue;
                }
                $lines[] = $r . ': ' . substr($c, 0, 220);
            }
            if ($lines === []) {
                return array_slice($history, -6);
            }
            $summary_prompt = [
                ['role' => 'system', 'content' => 'Resume en exactamente 2 líneas los acuerdos y contexto útil de la conversación. No inventes datos.'],
                ['role' => 'user', 'content' => implode("\n", $lines)],
            ];
            $summary_prompt = self::sanitize_llm_messages_for_external_api($summary_prompt, $project_id, $config);
            $summary = self::call_auxiliary_llm($summary_prompt, 120, 0.1, $project_id, $config);
            if (!is_string($summary) || trim($summary) === '') {
                return array_slice($history, -6);
            }
            return array_merge([
                ['role' => 'system', 'content' => 'Resumen de contexto previo: ' . trim($summary)],
            ], array_slice($history, -6));
        }

        /**
         * Vertex local (gemini-2.5-flash) o gpt-4o-mini según ai_driver del proyecto.
         *
         * @param array<int, array{role: string, content: string}> $messages
         */
        private static function call_auxiliary_llm(array $messages, int $max_tokens, float $temperature, string $project_id, array $config): string {
            if (($config['ai_driver'] ?? '') === 'google_cloud'
                && class_exists('Xabia_Digixop_Client', false)
                && Xabia_Digixop_Client::should_use_local_vertex($config)) {
                $raw = self::call_google_vertex($messages, $max_tokens, $config, $temperature, $project_id, false);
            } else {
                $raw = self::call_openai($messages, $max_tokens, 'gpt-4o-mini', $temperature, $project_id, $config);
            }

            return is_string($raw) ? $raw : '';
        }

        private static function parse_route_label_from_llm_output(string $raw): ?string {
            $normalized = strtoupper(trim($raw));
            if (in_array($normalized, ['ROUTE_ACTION', 'ROUTE_KNOWLEDGE', 'ROUTE_GENERAL'], true)) {
                return $normalized;
            }
            foreach (['ROUTE_ACTION', 'ROUTE_KNOWLEDGE', 'ROUTE_GENERAL'] as $label) {
                if (strpos($normalized, $label) !== false) {
                    return $label;
                }
            }

            return null;
        }

        private static function normalize_history_messages($raw): array {
            if (!is_array($raw)) {
                return [];
            }
            $out = [];
            foreach ($raw as $m) {
                if (!is_array($m) || !isset($m['role'], $m['content'])) {
                    continue;
                }
                $role = (string) $m['role'];
                if (!in_array($role, ['user', 'assistant', 'system'], true)) {
                    continue;
                }
                $content = trim(wp_strip_all_tags((string) $m['content']));
                if ($content === '') {
                    continue;
                }
                $out[] = ['role' => $role, 'content' => $content];
            }
            return $out;
        }

        private static function merge_history_messages(array $session_history, array $posted_history): array {
            $merged = array_merge(
                self::normalize_history_messages($session_history),
                self::normalize_history_messages($posted_history)
            );
            $deduped = [];
            foreach ($merged as $msg) {
                $last = end($deduped);
                if (is_array($last) && ($last['role'] ?? '') === $msg['role'] && ($last['content'] ?? '') === $msg['content']) {
                    continue;
                }
                $deduped[] = $msg;
            }
            return array_slice($deduped, -12);
        }

        private static function chat_max_tokens($max_tokens): int {
            $n = (int) $max_tokens;
            if ($n > 3000) {
                return 3000;
            }
            return max(1200, $n);
        }

        /**
         * Límites de salida para peticiones estilo OpenAI (proxy Digixop / OpenAI directo).
         *
         * @return array{max_tokens:int, max_completion_tokens:int}
         */
        private static function openai_chat_token_limit_fields(int $max_tokens): array {
            $budget = self::chat_max_tokens($max_tokens);

            return [
                'max_tokens'             => $budget,
                'max_completion_tokens'  => $budget,
            ];
        }

        private static function finish_reason_is_provider_max_tokens(string $reason): bool {
            $r = strtolower(trim($reason));
            return in_array($r, ['max_tokens', 'max_output_tokens', 'max_tokens_reached', 'max_output_tokens_reached'], true);
        }

        private static function finish_reason_is_natural_stop(string $reason): bool {
            $r = strtolower(trim($reason));
            return in_array($r, ['stop', 'end_turn', 'completed', 'finish_reason_unspecified'], true);
        }

        private static function finish_reason_indicates_truncation(string $reason): bool {
            $r = strtolower(trim($reason));
            return in_array($r, ['length', 'max_tokens', 'max_output_tokens', 'max_tokens_reached', 'max_output_tokens_reached', 'max_tokens_exceeded'], true);
        }

        /**
         * Corte prematuro: el proveedor declaró length/MAX_TOKENS muy por debajo del presupuesto (p. ej. proxy capado a ~60).
         *
         * @param array<string, mixed>|null $metrics
         */
        private static function is_premature_length_cut(string $finish_reason, ?array $metrics, int $max_tokens): bool {
            if (!self::finish_reason_indicates_truncation($finish_reason)) {
                return false;
            }
            $completion_tokens = 0;
            if (is_array($metrics) && isset($metrics['completion_tokens'])) {
                $completion_tokens = max(0, (int) $metrics['completion_tokens']);
            }
            $budget = max(1, self::chat_max_tokens($max_tokens));
            if ($completion_tokens <= 0) {
                return true;
            }

            return $completion_tokens < (int) floor($budget * 0.85);
        }

        private static function response_looks_truncated(string $text): bool {
            return function_exists('xabia_response_looks_truncated')
                ? xabia_response_looks_truncated($text)
                : false;
        }

        /**
         * Solo marcar truncated=true cuando el proveedor declaró límite de tokens Y el output agotó el presupuesto.
         *
         * @param array<string, mixed>|null $metrics
         */
        private static function should_mark_response_truncated(
            string $finish_reason,
            string $response,
            int $max_tokens,
            ?array $metrics = null
        ): bool {
            if (self::finish_reason_indicates_truncation($finish_reason)) {
                return true;
            }

            if (self::finish_reason_is_natural_stop($finish_reason)) {
                return false;
            }

            return self::response_looks_truncated($response);
        }

        private static function is_rag_development_mode(array $config = []): bool {
            if (defined('XABIA_RAG_DEV') && XABIA_RAG_DEV) {
                return true;
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                return true;
            }
            if (!empty($config['rules']['rag_dev_mode'])) {
                return true;
            }

            return (bool) apply_filters('xabia_rag_development_mode', false, $config);
        }

        private static function enable_rag_dev_fresh_queries(): void {
            static $armed = false;
            if ($armed) {
                return;
            }
            $armed = true;
            self::$rag_development_mode_active = true;
            add_filter('xabia_response_cache_version', static function ($ver) {
                return (string) $ver . '|dev_' . microtime(true);
            }, 999, 1);
        }

        private static function should_log_rag_context_chivato(array $config = []): bool {
            if (defined('XABIA_RAG_CONTEXT_LOG') && XABIA_RAG_CONTEXT_LOG) {
                return true;
            }
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                return true;
            }

            return (bool) apply_filters('xabia_rag_context_debug_log', false, $config);
        }

        /**
         * @param array<string, mixed> $config
         */
        private static function log_rag_context_chivato(string $project_id, string $assembled_payload, array $config): void {
            if (!self::should_log_rag_context_chivato($config)) {
                return;
            }
            $dbg = self::$last_rag_debug;
            $datos_pos = mb_stripos($assembled_payload, '[DATOS]');
            $datos_body = $datos_pos !== false ? mb_substr($assembled_payload, $datos_pos) : $assembled_payload;
            $marmitako = mb_stripos($datos_body, 'marmitako') !== false ? 'yes' : 'no';
            $velero = mb_stripos($datos_body, 'velero') !== false ? 'yes' : 'no';
            $ente_sample = (string) ($dbg['ente_sample'] ?? '');
            error_log(
                '[XABIA RAG CHIVATO] project=' . $project_id
                . ' chunks=' . (int) ($dbg['chunk_count'] ?? 0)
                . ' search_term=' . (string) ($dbg['search_term'] ?? '')
                . ' needles=' . (string) ($dbg['needles_csv'] ?? '')
                . ' lexical=' . (string) ($dbg['lexical_query'] ?? '')
                . ' velero_in_raw=' . (string) ($dbg['velero_in_raw_context'] ?? 'n/a')
                . ' keyword_boost=' . (string) ($dbg['keyword_boost_status'] ?? 'n/a')
                . ' rescue_needle=' . (string) ($dbg['rescue_needle'] ?? '')
                . ' ente_sample=' . ($ente_sample !== '' ? $ente_sample : 'n/a')
                . ' velero_in_datos=' . $velero
                . ' marmitako_in_datos=' . $marmitako
                . ' payload_len=' . strlen($assembled_payload)
            );
        }

        /**
         * Diagnóstico transporte Hub RAG (HTTP, WP_Error, timeout cURL) para error_log.
         *
         * @param array<string, mixed> $hub_out Respuesta de hub_signed_json_post.
         */
        public static function log_hub_rag_transport_failure(
            string $project_id,
            string $operation,
            array $hub_out,
            string $url = ''
        ): void {
            if (!empty($hub_out['ok'])) {
                return;
            }
            $http_code = (int) ($hub_out['code'] ?? 0);
            $wp_error = isset($hub_out['wp_error']) && is_array($hub_out['wp_error']) ? $hub_out['wp_error'] : null;
            $parts = [
                'project=' . $project_id,
                'operation=' . $operation,
                'http_code=' . $http_code,
            ];
            if ($url !== '') {
                $parts[] = 'url=' . $url;
            }
            if (is_array($wp_error)) {
                $parts[] = 'wp_error_code=' . (string) ($wp_error['code'] ?? '');
                $parts[] = 'wp_error_message=' . substr((string) ($wp_error['message'] ?? ''), 0, 500);
                if (isset($wp_error['data']) && $wp_error['data'] !== null && $wp_error['data'] !== '') {
                    $data_json = wp_json_encode($wp_error['data']);
                    if (is_string($data_json)) {
                        $parts[] = 'wp_error_data=' . substr($data_json, 0, 400);
                    }
                }
            }
            $raw = (string) ($hub_out['raw'] ?? '');
            if ($raw !== '') {
                $parts[] = 'response_snippet=' . substr($raw, 0, 1200);
            }
            error_log('[XABIA HUB RAG TRANSPORT] ' . implode(' ', $parts));
        }

        /**
         * Fallback local cuando el Hub no devuelve contexto utilizable.
         */
        private static function fallback_local_rag_when_hub_empty(
            string $project_id,
            string $search_term,
            string $ente_scope,
            bool $strict_ente,
            int $max_chunks
        ): string {
            if (!self::is_hub_rag_enabled_for_project($project_id)) {
                return '';
            }

            return self::local_knowledge_rescue_like_search($project_id, $search_term, $ente_scope, $strict_ente, $max_chunks);
        }

        /**
         * Une una continuación automática cuando el proveedor corta sin declarar finish_reason=length.
         *
         * @param array<int, array<string, mixed>> $messages
         * @return array{response:string, continued:bool, finish_reason:string, metrics:array<string, int>|null}
         */
        private static function auto_continue_response_once(
            array $messages,
            string $partial_response,
            string $ai_driver,
            int $max_tokens,
            array $config,
            float $temperature,
            string $project_id,
            bool $force_premature_continue = false
        ): array {
            $partial_response = trim($partial_response);
            $initial_finish_reason = (string) self::$last_generation_finish_reason;
            $initial_metrics = is_array(self::$last_generation_metrics) ? self::$last_generation_metrics : null;

            if ($partial_response === '') {
                return [
                    'response'      => $partial_response,
                    'continued'     => false,
                    'finish_reason' => $initial_finish_reason,
                    'metrics'       => $initial_metrics,
                ];
            }

            $continue_messages = $messages;
            $continue_messages[] = ['role' => 'assistant', 'content' => $partial_response];
            $continue_messages[] = [
                'role'    => 'user',
                'content' => $force_premature_continue
                    ? 'La respuesta anterior se cortó por un límite técnico del proveedor (MAX_TOKENS). Continúa EXACTAMENTE desde la siguiente palabra, sin repetir nada de lo anterior, sin saludo ni temas nuevos. Escribe solo el resto del texto.'
                    : 'Continúa exactamente desde donde se cortó la frase anterior (solo si quedó a medias). Empieza por la siguiente palabra, sin repetir lo anterior, sin saludo, sin temas nuevos ni otros eventos. Si la respuesta del asistente ya era completa, responde únicamente: __COMPLETE__',
            ];

            if ($ai_driver === 'google_cloud' && class_exists('Xabia_Digixop_Client') && Xabia_Digixop_Client::should_use_local_vertex($config)) {
                $vertex_fed = self::should_use_federation_tools_for_project($project_id);
                $continuation = self::call_google_vertex($continue_messages, $max_tokens, $config, $temperature, $project_id, $vertex_fed);
            } elseif (self::should_use_federation_tools_for_project($project_id)) {
                $continuation = self::call_openai_with_federation_tools($continue_messages, $max_tokens, 'gpt-4o', $temperature, $project_id, $config);
            } else {
                $continuation = self::call_openai($continue_messages, $max_tokens, 'gpt-4o', $temperature, $project_id, $config);
            }

            $continuation = trim(self::sanitizeTechnicalFailureForUser((string) $continuation));
            $continuation_is_complete_token = ($continuation === '__COMPLETE__' || stripos($continuation, '__COMPLETE__') === 0);
            if ($continuation === '' || $continuation_is_complete_token) {
                self::$last_generation_finish_reason = $initial_finish_reason;
                self::$last_generation_metrics = $initial_metrics;
                return [
                    'response'      => $partial_response,
                    'continued'     => false,
                    'finish_reason' => $initial_finish_reason,
                    'metrics'       => $initial_metrics,
                ];
            }

            $continuation_finish_reason = (string) self::$last_generation_finish_reason;
            $continuation_metrics = is_array(self::$last_generation_metrics) ? self::$last_generation_metrics : null;
            $merged_metrics = self::merge_generation_metrics($initial_metrics, $continuation_metrics);
            self::$last_generation_metrics = $merged_metrics;

            return [
                'response'      => self::merge_chat_response_fragments($partial_response, $continuation),
                'continued'     => true,
                'finish_reason' => $continuation_finish_reason,
                'metrics'       => $merged_metrics,
            ];
        }

        /**
         * Une fragmentos de respuesta sin cortar palabras a medias ni duplicar espacios.
         */
        private static function merge_chat_response_fragments(string $head, string $tail): string {
            $head = rtrim((string) $head);
            $tail = ltrim((string) $tail);
            if ($head === '') {
                return $tail;
            }
            if ($tail === '') {
                return $head;
            }
            if (preg_match('/\p{L}$/u', $head) && preg_match('/^\p{Ll}/u', $tail)) {
                return $head . $tail;
            }
            if (preg_match('/[\s\-—]$/u', $head) || preg_match('/^[\s\-—,.;:!?¿¡]/u', $tail)) {
                return rtrim($head) . ltrim($tail);
            }

            return $head . ' ' . $tail;
        }

        /**
         * Auto-continúa en servidor cuando el proveedor declara corte por límite (length / MAX_TOKENS).
         *
         * @param array<int, array<string, mixed>> $messages
         * @return array{response:string, ran:bool, attempted:bool, finish_reason:string}
         */
        private static function maybe_auto_continue_chat_response(
            array $messages,
            string $response,
            string $ai_driver,
            int $max_tokens,
            array $config,
            float $temperature,
            string $project_id,
            bool $is_continue_request
        ): array {
            if ($is_continue_request) {
                return [
                    'response'      => $response,
                    'ran'           => false,
                    'attempted'     => false,
                    'finish_reason' => (string) self::$last_generation_finish_reason,
                ];
            }

            $finish_reason = (string) self::$last_generation_finish_reason;
            $metrics = is_array(self::$last_generation_metrics) ? self::$last_generation_metrics : null;
            $premature_cut = self::is_premature_length_cut($finish_reason, $metrics, $max_tokens);
            $ran = false;
            $attempted = false;
            $max_attempts = $premature_cut ? 5 : 2;

            for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
                if (!self::finish_reason_indicates_truncation($finish_reason)) {
                    break;
                }

                $attempted = true;
                $result = self::auto_continue_response_once(
                    $messages,
                    (string) $response,
                    $ai_driver,
                    $max_tokens,
                    $config,
                    $temperature,
                    $project_id,
                    $premature_cut
                );
                $finish_reason = (string) ($result['finish_reason'] ?? $finish_reason);
                if (empty($result['continued'])) {
                    if (!$premature_cut) {
                        break;
                    }
                    continue;
                }

                $response = (string) $result['response'];
                $ran = true;
                $metrics = is_array(self::$last_generation_metrics) ? self::$last_generation_metrics : $metrics;
                $premature_cut = self::is_premature_length_cut($finish_reason, $metrics, $max_tokens);
                xabia_trace('[XABIA_CORE] auto-continued truncated LLM response', [
                    'project_id'    => $project_id,
                    'attempt'       => $attempt + 1,
                    'finish_reason' => $finish_reason,
                    'premature_cut' => $premature_cut,
                ]);
            }

            if ($attempted && !$ran && $premature_cut && self::should_log_rag_context_chivato($config)) {
                error_log(
                    '[XABIA RAG CHIVATO LLM] auto_continue attempted=yes merged=no project=' . $project_id
                    . ' finish_reason=' . $finish_reason
                    . ' completion_tokens=' . (int) ($metrics['completion_tokens'] ?? 0)
                );
            }

            return [
                'response'      => $response,
                'ran'           => $ran || ($attempted && self::finish_reason_indicates_truncation($finish_reason)),
                'attempted'     => $attempted,
                'finish_reason' => $finish_reason,
            ];
        }

        /**
         * @param array<string, int>|null $first
         * @param array<string, int>|null $second
         * @return array<string, int>|null
         */
        private static function merge_generation_metrics(?array $first, ?array $second): ?array {
            if ($first === null) {
                return $second;
            }
            if ($second === null) {
                return $first;
            }
            $keys = array_unique(array_merge(array_keys($first), array_keys($second)));
            $merged = [];
            foreach ($keys as $key) {
                $merged[$key] = (int) ($first[$key] ?? 0) + (int) ($second[$key] ?? 0);
            }
            return $merged;
        }

        private static function is_photo_request(string $msg): bool {
            $t = mb_strtolower(trim(wp_strip_all_tags($msg)), 'UTF-8');
            if ($t === '') {
                return false;
            }

            return preg_match(
                '/\b(foto|fotos|imagen|imagenes|imágenes|picture|pictures|ver\s+(la\s+)?(casa|alojamiento|habitaci[oó]n)|ens[eé]ñar|mu[eé]strar|enseñar|mostrar)\b/u',
                $t
            ) === 1;
        }

        /**
         * Extrae URLs/IDs de imagen del contexto RAG (bloques === IMAGEN (mapeo) ===).
         *
         * @return array<int, array{value:string, label:string}>
         */
        private static function extract_imagen_entries_from_context(string $context): array {
            $entries = [];
            if ($context === '') {
                return $entries;
            }
            if (preg_match_all('/\b(imagen(?:_\d+)?|logotipo(?:_\d+)?):\s*([^\s\n\r]+)/iu', $context, $lines, PREG_SET_ORDER)) {
                foreach ($lines as $line) {
                    $key = mb_strtolower(trim((string) ($line[1] ?? '')), 'UTF-8');
                    $val = trim((string) ($line[2] ?? ''));
                    if ($val === '' || preg_match('/^(ninguna|n\/a|null|none)$/iu', $val)) {
                        continue;
                    }
                    $kind = str_starts_with($key, 'logotipo') ? 'logotipo' : 'imagen';
                    $entries[] = ['value' => $val, 'label' => '', 'kind' => $kind];
                }
            }
            $dedup = [];
            $out = [];
            foreach ($entries as $e) {
                $k = ($e['kind'] ?? 'imagen') . '|' . $e['value'];
                if (!isset($dedup[$k])) {
                    $dedup[$k] = true;
                    if (!isset($e['kind'])) {
                        $e['kind'] = 'imagen';
                    }
                    $out[] = $e;
                }
            }

            return $out;
        }

        private static function pick_imagen_for_hint(array $entries, string $hint, bool $prefer_photos = true): string {
            if ($entries === []) {
                return '';
            }
            if ($prefer_photos) {
                $photos = array_values(array_filter($entries, static function (array $entry): bool {
                    return ($entry['kind'] ?? 'imagen') === 'imagen';
                }));
                if ($photos !== []) {
                    $entries = $photos;
                }
            }
            $hint_norm = mb_strtolower(remove_accents($hint), 'UTF-8');
            $tokens = array_filter(preg_split('/\s+/u', $hint_norm) ?: [], static function ($w) {
                return strlen($w) >= 4;
            });
            $best = '';
            $best_score = 0;
            foreach ($entries as $entry) {
                $val = $entry['value'];
                $label = $entry['label'];
                $hay = mb_strtolower(remove_accents($val . ' ' . $label), 'UTF-8');
                $score = 0;
                foreach ($tokens as $tok) {
                    if ($tok !== '' && strpos($hay, $tok) !== false) {
                        $score += 3;
                    }
                }
                if ($score > $best_score) {
                    $best_score = $score;
                    $best = $val;
                }
            }
            if ($best !== '') {
                return $best;
            }

            return $entries[0]['value'];
        }

        private static function maybe_append_photo_from_context(
            string $response,
            string $context,
            string $user_msg,
            string $last_search
        ): string {
            if (strpos($response, '[ACTION:IMG:') !== false) {
                return $response;
            }
            $hint = trim($user_msg . ' ' . $last_search);
            if (!self::is_photo_request($user_msg) && !self::is_photo_request($last_search)) {
                return $response;
            }
            $entries = self::extract_imagen_entries_from_context($context);
            if ($entries === []) {
                return $response;
            }
            $raw = self::pick_imagen_for_hint($entries, $hint);
            if ($raw === '') {
                return $response;
            }
            $resolved = self::resolve_action_img_ids_in_response('[ACTION:IMG:' . $raw . ']');
            if ($resolved === '' || strpos($resolved, '[ACTION:IMG:') === false) {
                return $response;
            }

            return rtrim($response) . "\n\n" . trim($resolved);
        }

        private static function is_hub_rag_enabled_for_project(string $project_id): bool {
            return class_exists('Xabia_Hub_Knowledge', false) && Xabia_Hub_Knowledge::is_hub_rag_enabled($project_id);
        }

        /**
         * Nodos federados solo si el cerebro no está ya centralizado en el Hub (evita timeouts en sitios cloud).
         */
        private static function should_use_federation_tools_for_project(string $project_id): bool {
            if (!class_exists('Xabia_Federation_Nexus', false) || Xabia_Federation_Nexus::get_friend_nodes() === []) {
                return false;
            }

            return !self::is_hub_rag_enabled_for_project($project_id);
        }

        /**
         * Durante xabia_ask_ai: acota espera al Hub (evita bloquear PHP 120s; el hosting suele matar antes).
         *
         * @param array<string, mixed> $args
         * @return array<string, mixed>
         */
        public static function filter_hub_signed_post_args_for_chat(array $args, string $url): array {
            if (strpos($url, 'knowledge/search') === false) {
                return $args;
            }
            $args['timeout'] = 28;

            return $args;
        }

        /**
         * Término RAG: texto plano (sin HTML), sin caracteres de control, longitud acotada.
         * Usar en todo término que vaya a Hub, embedding o LIKE local.
         */
        private static function sanitize_rag_search_term(string $term): string
        {
            $term = wp_strip_all_tags((string) $term);
            $term = sanitize_text_field($term);
            $term = is_string($term) ? preg_replace('/\s+/u', ' ', trim($term)) : '';
            if (!is_string($term) || $term === '') {
                return '';
            }
            if (mb_strlen($term, 'UTF-8') > 80) {
                $term = mb_substr($term, 0, 80, 'UTF-8');
            }

            return $term;
        }

        /**
         * Término ampliado para embedding / vectorial (acrónimos y variantes morfológicas; sin APIs externas).
         */
        private static function rag_retrieval_search_term(string $search_term, string $user_msg_clean): string {
            $base = trim($search_term !== '' ? $search_term : $user_msg_clean);
            if ($base === '') {
                return '';
            }
            if (!class_exists('Xabia_Rag_Language_Bridge', false)) {
                return self::sanitize_rag_search_term($base);
            }
            $source = trim($user_msg_clean !== '' ? $user_msg_clean : $base);
            $variants = Xabia_Rag_Language_Bridge::retrieval_term_variants($source);
            $parts = [$base];
            foreach ($variants as $variant) {
                $parts[] = $variant;
            }
            $parts = array_values(array_unique(array_filter(array_map('trim', $parts))));
            $combined = trim(implode(' ', $parts));

            return self::sanitize_rag_search_term($combined !== '' ? $combined : $base);
        }

        /**
         * En modo Hub cloud no ejecutar refuerzos LIKE en WordPress: el Hub ya hace léxico + vectorial.
         */
        private static function should_skip_local_lexical_rag(string $project_id, string $context, int $chunk_count): bool {
            return self::is_hub_rag_enabled_for_project($project_id);
        }

        private static function safe_local_knowledge_search(
            string $project_id,
            string $search_term,
            string $ente_scope,
            bool $strict_ente,
            int $max_chunks
        ): string {
            if (self::is_hub_rag_enabled_for_project($project_id)) {
                return '';
            }

            return self::local_knowledge_rescue_like_search($project_id, $search_term, $ente_scope, $strict_ente, $max_chunks);
        }

        /**
         * Búsqueda LIKE de último recurso en la tabla local de conocimiento (ignora el guard Hub-only).
         */
        private static function local_knowledge_rescue_like_search(
            string $project_id,
            string $search_term,
            string $ente_scope,
            bool $strict_ente,
            int $max_chunks
        ): string {
            if (!class_exists('Xabia_Brain', false)) {
                return '';
            }
            $search_term = self::sanitize_rag_search_term($search_term);
            if ($search_term === '') {
                return '';
            }
            try {
                return trim((string) Xabia_Brain::search_knowledge(
                    $project_id,
                    $search_term,
                    $ente_scope,
                    $strict_ente,
                    $max_chunks
                ));
            } catch (\Throwable $e) {
                if (function_exists('xabia_trace')) {
                    xabia_trace('[XABIA_CORE] local knowledge rescue search failed', [
                        'message' => $e->getMessage(),
                        'term'    => substr($search_term, 0, 120),
                    ]);
                }

                return '';
            }
        }

        public static function handle_chat_request() {
            self::assert_pro_runtime('vector_rag');
            xabia_trace('[XABIA_CORE] xabia_ask_ai entry', [
                'project_id'  => sanitize_text_field((string) ($_POST['project_id'] ?? '')),
                'message_len' => strlen((string) wp_unslash($_POST['message'] ?? '')),
                'has_nonce'   => isset($_POST['nonce']) && (string) wp_unslash($_POST['nonce']) !== '',
            ]);

            add_filter('xabia_hub_signed_post_args', [__CLASS__, 'filter_hub_signed_post_args_for_chat'], 999, 2);
            try {
                self::handle_chat_request_impl();
            } catch (\Throwable $e) {
                if (function_exists('xabia_trace')) {
                    xabia_trace('[XABIA_CORE] xabia_ask_ai uncaught', ['message' => $e->getMessage()]);
                }
                error_log('[XABIA_CORE] xabia_ask_ai uncaught: ' . $e->getMessage());
                wp_send_json_error([
                    'message' => __('Error interno del servidor. Inténtalo de nuevo.', 'xabia-intelligence'),
                ]);
            } finally {
                remove_filter('xabia_hub_signed_post_args', [__CLASS__, 'filter_hub_signed_post_args_for_chat'], 999);
            }
        }

        private static function handle_chat_request_impl(): void {
            // Embedding + Hub (28s) + LLM; sin refuerzo LIKE local cuando RAG cloud está activo.
            if (function_exists('set_time_limit')) {
                @set_time_limit(90);
            }
            @ini_set('max_execution_time', '90');

            $skip_response_cache = false;
            if (isset($_POST['nonce']) && (string) wp_unslash($_POST['nonce']) !== '') {
                $raw_nonce = sanitize_text_field(wp_unslash($_POST['nonce']));
                $ok_admin = wp_verify_nonce($raw_nonce, 'xabia_admin_nonce');
                $ok_public = wp_verify_nonce($raw_nonce, 'xabia_nonce');
                if ($ok_admin) {
                    if (!current_user_can('manage_options')) {
                        xabia_trace('[XABIA_CORE] xabia_ask_ai aborted: admin nonce but user lacks manage_options.');
                        wp_send_json_error(['message' => __('Permiso denegado.', 'xabia-intelligence')]);
                        return;
                    }
                    $skip_response_cache = true;
                } elseif ($ok_public) {
                    
                } else {
                    xabia_trace('[XABIA_CORE] xabia_ask_ai aborted: nonce check failed (not xabia_admin_nonce nor xabia_nonce).');
                    wp_send_json_error(['message' => __('La comprobación de seguridad falló.', 'xabia-intelligence')]);
                    return;
                }
            }

            $project_id = sanitize_text_field($_POST['project_id'] ?? 'default');
            $scope      = sanitize_text_field($_POST['x_scope'] ?? 'global');
            $ente_id_param_raw = sanitize_text_field(wp_unslash($_POST['ente_id'] ?? ''));
            $user_msg   = sanitize_text_field(wp_unslash($_POST['message'] ?? ''));
            $is_continue_request = !empty($_POST['x_continue']);
            $lang_raw   = isset($_POST['lang']) ? sanitize_text_field(wp_unslash($_POST['lang'])) : '';
            if (class_exists('Xabia_I18n_Bridge', false)) {
                $lang_code = Xabia_I18n_Bridge::to_short_iso($lang_raw);
                if ($lang_code === '') {
                    $lang_code = Xabia_I18n_Bridge::get_current_language();
                }
            } else {
                $lang_code = strtolower(substr(preg_replace('/[^a-zA-Z]/', '', $lang_raw), 0, 2));
                if ($lang_code === '') {
                    $lang_code = strtolower(substr(get_locale(), 0, 2));
                }
            }
            if ($lang_code === '') {
                $lang_code = 'es';
            }

            $client_user_lang = !empty($_POST['user_lang'])
                ? (string) wp_unslash($_POST['user_lang'])
                : '';
            if (class_exists('Xabia_I18n_Bridge', false)) {
                $user_lang = Xabia_I18n_Bridge::resolve_user_lang($client_user_lang);
            } else {
                $user_lang = '';
                if ($client_user_lang !== '') {
                    $ul = preg_replace('/[^a-zA-Z0-9\-]/', '', $client_user_lang);
                    $user_lang = substr($ul, 0, 35);
                }
                if ($user_lang === '') {
                    $user_lang = $lang_code;
                }
            }
            
            if (empty($user_msg)) {
                xabia_trace('[XABIA_CORE] xabia_ask_ai empty message, short-circuit success.');
                wp_send_json_success(['response' => '...']);
                return;
            }

            self::reset_digixop_session_counters();

            
            if (!session_id() && !headers_sent()) session_start();
            $history = $_SESSION['xabia_chat_history'][$project_id] ?? [];
            $posted_history = [];
            if (!empty($_POST['history'])) {
                $raw = json_decode(wp_unslash((string) $_POST['history']), true);
                $posted_history = self::normalize_history_messages(is_array($raw) ? $raw : []);
            }
            $history = self::merge_history_messages(is_array($history) ? $history : [], $posted_history);
            self::ensure_catalog_manifest_from_history($project_id, $history);
            $last_search = self::sanitize_rag_search_term((string) ($_SESSION['xabia_last_search'][$project_id] ?? ''));
            $last_entity = self::sanitize_rag_search_term((string) ($_SESSION['xabia_last_entity'][$project_id] ?? ''));
            $last_search = self::sanitize_persisted_entity_reference($last_search, $project_id, $history);
            $last_entity = self::sanitize_persisted_entity_reference($last_entity, $project_id, $history);
            session_write_close();

            $keyword_intercept = function_exists('xabia_intercept_keywords')
                ? xabia_intercept_keywords($project_id, $user_msg, ['history' => $history])
                : null;
            if (is_array($keyword_intercept)) {
                if (($keyword_intercept['type'] ?? '') === 'response' && isset($keyword_intercept['response'])) {
                    $fixed_response = (string) $keyword_intercept['response'];
                    if (!session_id() && !headers_sent()) session_start();
                    $_SESSION['xabia_chat_history'][$project_id][] = ['role' => 'user', 'content' => $user_msg];
                    $_SESSION['xabia_chat_history'][$project_id][] = ['role' => 'assistant', 'content' => $fixed_response];
                    $_SESSION['xabia_chat_history'][$project_id] = array_slice($_SESSION['xabia_chat_history'][$project_id], -6);
                    $_SESSION['xabia_last_response_meta'][$project_id] = [
                        'truncated' => false,
                        'finish_reason' => 'php_intercept',
                        'response' => $fixed_response,
                    ];
                    session_write_close();
                    wp_send_json_success([
                        'response' => $fixed_response,
                        'finish_reason' => 'php_intercept',
                        'truncated' => false,
                    ]);
                    return;
                }
                if (($keyword_intercept['type'] ?? '') === 'continue' && !empty($keyword_intercept['message'])) {
                    $user_msg = (string) $keyword_intercept['message'];
                    $is_continue_request = true;
                }
            }

            
            $current_human_date = date_i18n('l, j \d\e F \d\e Y');
            $current_sql_date   = function_exists('current_time') ? current_time('Y-m-d') : date('Y-m-d');
            $current_temporal   = $current_human_date . ' (ISO ' . $current_sql_date . ')';

            
            $projects = get_option('xabia_projects_config', []);
            $config = $projects[$project_id] ?? [];
            $config['_xabia_proxy_user_lang'] = $user_lang;
            if (self::is_rag_development_mode($config)) {
                $skip_response_cache = true;
                self::enable_rag_dev_fresh_queries();
            }
            $daily_limit = self::daily_token_limit_for_project($config);
            if ($daily_limit > 0 && !$skip_response_cache) {
                $today_tokens = self::consumed_tokens_today($project_id);
                if ($today_tokens >= $daily_limit) {
                    wp_send_json_success(['response' => self::daily_token_limit_user_message($config)]);
                    return;
                }
            }
            if (!$skip_response_cache && class_exists('Xabia_Router')) {
                $early_cached = Xabia_Router::find_cached_response_for_query($project_id, $user_msg, $lang_code);
                if (is_array($early_cached) && !empty($early_cached['response'])) {
                    $early_text = (string) $early_cached['response'];
                    if (!self::responseLooksLikeTechnicalFailure($early_text)
                        && $early_text !== self::friendlyTemporaryFailureMessage()) {
                        if (function_exists('xabia_trace')) {
                            xabia_trace('[XABIA_CORE] response_cache early hit (pre-router)', [
                                'project_id' => $project_id,
                                'source_type' => (string) ($early_cached['source_type'] ?? ''),
                            ]);
                        }
                        wp_send_json_success(['response' => $early_text]);
                        return;
                    }
                }
            }
            $route = class_exists('Xabia_Router')
                ? Xabia_Router::classify($project_id, $user_msg, $config, $lang_code)
                : 'ROUTE_KNOWLEDGE';
            $cache_hash = class_exists('Xabia_Router')
                ? Xabia_Router::query_hash($project_id, $user_msg, $route, $lang_code)
                : '';
            if (!$skip_response_cache && $cache_hash !== '' && class_exists('Xabia_Router') && $route !== 'ROUTE_ACTION') {
                $cached = Xabia_Router::get_cached_response($project_id, $cache_hash);
                if (is_array($cached) && !empty($cached['response'])) {
                    wp_send_json_success(['response' => (string) $cached['response']]);
                    return;
                }
            }
            if ($route === 'ROUTE_ACTION' && class_exists('Xabia_Router')) {
                $actionResponse = Xabia_Router::maybe_handle_action_route($project_id, $user_msg, $config, [
                    'lang_code' => $lang_code,
                    'user_lang' => $user_lang,
                ]);
                if (is_string($actionResponse) && trim($actionResponse) !== '') {
                    if (!session_id() && !headers_sent()) session_start();
                    $_SESSION['xabia_chat_history'][$project_id][] = ['role' => 'user', 'content' => $user_msg];
                    $_SESSION['xabia_chat_history'][$project_id][] = ['role' => 'assistant', 'content' => $actionResponse];
                    $_SESSION['xabia_chat_history'][$project_id] = array_slice($_SESSION['xabia_chat_history'][$project_id], -6);
                    $_SESSION['xabia_last_response_meta'][$project_id] = [
                        'truncated' => false,
                        'finish_reason' => 'php_action',
                        'response' => $actionResponse,
                    ];
                    session_write_close();
                    wp_send_json_success([
                        'response' => $actionResponse,
                        'finish_reason' => 'php_action',
                        'truncated' => false,
                    ]);
                    return;
                }
            }
            $use_vector = !empty($config['rules']['use_vector_search']);

            $user_msg_clean = self::sanitize_rag_search_term(trim(strip_tags($user_msg)) ?: $user_msg);
            $named_entity = self::resolve_named_entity_from_user_message($user_msg_clean);
            $manifest_entity = self::resolve_manifest_entity_reference($user_msg_clean, $project_id, $named_entity, $history);
            if ($manifest_entity !== '') {
                $named_entity = $manifest_entity;
            } elseif ($named_entity === '') {
                $named_entity = self::resolve_entity_from_catalog_manifest($user_msg_clean, $project_id, $history);
            } elseif (self::is_manifest_ordinal_phrase($named_entity)) {
                $ordinal = self::resolve_entity_ordinal_from_manifest($user_msg_clean, $project_id, $history);
                if ($ordinal === '') {
                    $ordinal = self::resolve_entity_ordinal_from_manifest($named_entity, $project_id, $history);
                }
                if ($ordinal !== '') {
                    $named_entity = $ordinal;
                }
            }
            $utility_request = self::query_implies_entity_utility_request($user_msg_clean);
            if ($named_entity === '' && $utility_request) {
                if ($last_entity !== '') {
                    $named_entity = $last_entity;
                } else {
                    $hist_entity = self::resolve_entity_from_conversation_history($history, $project_id);
                    if ($hist_entity !== '') {
                        $named_entity = $hist_entity;
                    } else {
                        $ordinal = self::resolve_entity_ordinal_from_manifest($user_msg_clean, $project_id, $history);
                        if ($ordinal !== '') {
                            $named_entity = $ordinal;
                        }
                    }
                }
            }
            $search_source = $user_msg_clean;
            if ($named_entity !== '') {
                $search_source = self::sanitize_rag_search_term($named_entity);
            } elseif ($is_continue_request && $last_search !== '') {
                $search_source = $last_search;
            } elseif ($utility_request && $last_search !== '' && !self::query_implies_entity_utility_request($last_search)) {
                $search_source = $last_search;
            }
            $search_term = self::sanitize_rag_search_term(
                self::augment_rag_search_term($search_source, $last_search)
            );
            $entity_anchor = self::resolve_entity_anchor_from_history($user_msg_clean, $history, $last_entity, $project_id);
            if ($entity_anchor === '' && $utility_request) {
                $entity_anchor = $named_entity !== ''
                    ? $named_entity
                    : self::resolve_entity_from_conversation_history($history, $project_id);
            }
            $entity_anchor = self::sanitize_persisted_entity_reference($entity_anchor, $project_id, $history);
            $named_entity = self::sanitize_persisted_entity_reference($named_entity, $project_id, $history);
            if ($entity_anchor !== '') {
                $search_term = self::sanitize_rag_search_term($entity_anchor);
            } elseif ($named_entity !== '') {
                $search_term = self::sanitize_rag_search_term($named_entity);
            }
            if (!session_id() && !headers_sent()) session_start();
            $_SESSION['xabia_last_search'][$project_id] = $search_term;
            $session_entity = $named_entity !== '' ? $named_entity : $entity_anchor;
            $session_entity = self::sanitize_persisted_entity_reference($session_entity, $project_id, $history);
            if ($session_entity !== '') {
                $_SESSION['xabia_last_entity'][$project_id] = $session_entity;
                $_SESSION['xabia_last_search'][$project_id] = $session_entity;
            }
            session_write_close();

            if (class_exists('Xabia_Digixop_Client') && Xabia_Digixop_Client::was_insufficient_balance()) {
                wp_send_json_error([
                    'message'              => Xabia_Digixop_Client::get_insufficient_balance_user_message(),
                    'digixop_insufficient' => true,
                ]);
                return;
            }

            
            if (!class_exists('Xabia_Brain')) {
                $brain_path = (defined('XABIA_PATH') ? XABIA_PATH : plugin_dir_path(dirname(dirname(__FILE__)))) . 'core/class-xabia-brain.php';
                if (file_exists($brain_path)) {
                    require_once $brain_path;
                }
            }

            
            $strict_ente = false;
            $ente_scope = $scope;
            if (!empty($ente_id_param_raw)) {
                $strict_ente = true;
                $ente_scope = sanitize_title($ente_id_param_raw);
                if (empty($ente_scope)) $ente_scope = $scope;
            }

            $max_chunks = class_exists('Xabia_Brain', false) ? Xabia_Brain::effective_rag_max_chunks_from_project_config($config) : 4;
            $similarity_threshold = isset($config['rules']['similarity_threshold']) ? max(0, min(1, (float) $config['rules']['similarity_threshold'])) : 0.2;
            $rag_fetch_limit = $max_chunks;
            $hub_rag_opts = [];
            $keyword_expansions = self::resolve_rag_keyword_expansions($config);
            if ($keyword_expansions !== []) {
                $hub_rag_opts['keyword_expansions'] = $keyword_expansions;
            }
            $rag_keyword_needles = self::extract_rag_keyword_needles(
                $user_msg_clean !== '' ? $user_msg_clean : $search_term
            );
            $rag_lexical_query = self::build_rag_lexical_query_text($user_msg_clean, $search_term);
            if ($rag_lexical_query !== '') {
                $hub_rag_opts['lexical_query_text'] = $rag_lexical_query;
            }
            $retrieval_search_term = self::rag_retrieval_search_term($search_term, $user_msg_clean);

            self::$last_rag_debug = [
                'chunk_count'          => 0,
                'keyword_boost_status' => 'not_evaluated',
                'search_term'          => $search_term,
                'retrieval_term'       => $retrieval_search_term,
                'keyword_needles'      => $rag_keyword_needles,
                'needles_csv'          => implode(',', $rag_keyword_needles),
                'lexical_query'        => $rag_lexical_query,
                'velero_in_raw_context'=> 'n/a',
                'rescue_needle'        => '',
                'rag_dev_mode'         => self::$rag_development_mode_active,
            ];

            $context = "";
            $chunk_count = 0;
            $had_knowledge_rows = false;
            $rag_total_found = null;
            $rag_vector_chunk_count = null;
            if (class_exists('Xabia_Brain')) {
                if ($use_vector && !$strict_ente) {
                    $query_vector = self::get_query_embedding($retrieval_search_term, $config, $project_id);
                    self::digixop_absorb_query_embedding_usage($project_id, $config);
                    if (class_exists('Xabia_Digixop_Client') && Xabia_Digixop_Client::was_insufficient_balance()) {
                        wp_send_json_error([
                            'message'              => Xabia_Digixop_Client::get_insufficient_balance_user_message(),
                            'digixop_insufficient' => true,
                        ]);
                        return;
                    }
                    $out = Xabia_Brain::search_knowledge_vector($project_id, $retrieval_search_term, $ente_scope, false, $rag_fetch_limit, $similarity_threshold, $query_vector, $hub_rag_opts);
                    if (!empty($out['_hub_meta']) && is_array($out['_hub_meta']) && empty($out['_hub_meta']['ok'])) {
                        self::log_hub_rag_transport_failure(
                            $project_id,
                            'primary_search',
                            [
                                'ok'       => false,
                                'code'     => (int) ($out['_hub_meta']['http_code'] ?? 0),
                                'raw'      => (string) ($out['_hub_meta']['raw'] ?? ''),
                                'wp_error' => isset($out['_hub_meta']['wp_error']) && is_array($out['_hub_meta']['wp_error'])
                                    ? $out['_hub_meta']['wp_error']
                                    : null,
                                'body'     => $out['_hub_meta']['body'] ?? null,
                            ],
                            (string) ($out['_hub_meta']['url'] ?? '')
                        );
                    }
                    $context = $out['context'] ?? '';
                    $chunk_count = (int) ($out['chunk_count'] ?? 0);
                    // Preferir chunks tipados: el context del Hub suele ser anónimo (solo CATEGORÍA…).
                    // Reformatear antepone EMPRESA/ente desde ente_id o parent_title.
                    if (!empty($out['chunks']) && is_array($out['chunks'])) {
                        $formatted_chunks = self::format_hub_rag_chunks_for_prompt($out['chunks'], $config);
                        if (strlen(trim($formatted_chunks)) >= 10) {
                            $context = $formatted_chunks;
                        }
                        $ente_ids = [];
                        foreach ($out['chunks'] as $ch) {
                            if (!is_array($ch)) {
                                continue;
                            }
                            $eid = trim((string) ($ch['ente_id'] ?? ''));
                            if ($eid !== '' && $eid !== 'global') {
                                $ente_ids[$eid] = true;
                            }
                        }
                        self::$last_rag_debug['ente_sample'] = $ente_ids === []
                            ? '(none)'
                            : implode(',', array_slice(array_keys($ente_ids), 0, 8));
                    }
                    self::$last_rag_debug['velero_in_raw_context'] = mb_stripos((string) $context, 'velero') !== false ? 'yes' : 'no';
                    if ($chunk_count > 0) {
                        $rag_total_found = isset($out['total_found']) && $out['total_found'] !== null && $out['total_found'] !== '' ? (int) $out['total_found'] : null;
                        $rag_vector_chunk_count = $chunk_count;
                    }
                    $had_knowledge_rows = ($chunk_count > 0);
                    self::$last_rag_debug['chunk_count'] = $chunk_count;
                    if (empty($context) || strlen(trim((string) $context)) < 10) {
                        $local_rescue = self::fallback_local_rag_when_hub_empty(
                            $project_id,
                            $search_term,
                            $ente_scope,
                            $strict_ente,
                            $rag_fetch_limit
                        );
                        if (strlen(trim($local_rescue)) >= 10) {
                            $context = $local_rescue;
                            $had_knowledge_rows = true;
                            self::$last_rag_debug['keyword_boost_status'] = 'executed_local_hub_failover';
                            if (function_exists('xabia_trace')) {
                                xabia_trace('[XABIA_CORE] hub primary empty; local catalog failover', [
                                    'project_id' => $project_id,
                                    'term'       => substr($search_term, 0, 80),
                                ]);
                            }
                        } elseif (!self::is_hub_rag_enabled_for_project($project_id)) {
                            $context = self::safe_local_knowledge_search($project_id, $search_term, $ente_scope, $strict_ente, $rag_fetch_limit);
                            $chunk_count = 0;
                            $had_knowledge_rows = false;
                            $rag_total_found = null;
                            $rag_vector_chunk_count = null;
                            if (strlen(trim($context)) >= 10) {
                                $had_knowledge_rows = true;
                            }
                        }
                    } elseif (!self::should_skip_local_lexical_rag($project_id, (string) $context, $chunk_count)
                        && self::rag_context_misses_query_signal_terms($search_term, $context)) {
                        $lex = self::safe_local_knowledge_search($project_id, $search_term, $ente_scope, $strict_ente, $max_chunks);
                        if (strlen(trim($lex)) >= 10) {
                            $context .= "\n\n### Búsqueda por palabras clave (refuerzo) ###\n" . $lex;
                            $had_knowledge_rows = true;
                        }
                    }
                    if ($rag_keyword_needles !== []
                        && self::context_lacks_keyword_needles((string) $context, $rag_keyword_needles)) {
                        $rescue_needles = self::select_keyword_needles_for_boost($rag_keyword_needles, (string) $context);
                        self::$last_rag_debug['rescue_needle'] = $rescue_needles[0] ?? '';
                        self::$last_rag_debug['keyword_boost_status'] = self::is_hub_rag_enabled_for_project($project_id)
                            ? 'executing_hub'
                            : 'executing_local';
                        $keyword_boost = self::fetch_keyword_boost_context(
                            $project_id,
                            $rag_keyword_needles,
                            $ente_scope,
                            $strict_ente,
                            $max_chunks,
                            $config,
                            $similarity_threshold,
                            (string) $context,
                            null
                        );
                        if ($keyword_boost !== '') {
                            $boost_status = (string) (self::$last_rag_debug['keyword_boost_status'] ?? '');
                            if ($boost_status !== 'executed_local_rescue') {
                                self::$last_rag_debug['keyword_boost_status'] = self::is_hub_rag_enabled_for_project($project_id)
                                    ? 'executed_hub'
                                    : 'executed_local';
                            }
                            $mark = '### Coincidencias exactas por palabra clave ###';
                            if (mb_stripos((string) $context, $mark) === false) {
                                $block = $mark . "\n" . $keyword_boost;
                                $context = ($context !== '' && trim((string) $context) !== '')
                                    ? ($block . "\n\n" . $context)
                                    : $block;
                                $had_knowledge_rows = true;
                            }
                        } else {
                            self::$last_rag_debug['keyword_boost_status'] = self::is_hub_rag_enabled_for_project($project_id)
                                ? 'executed_hub_empty'
                                : 'executed_local_empty';
                        }
                    } elseif ($rag_keyword_needles !== []) {
                        self::$last_rag_debug['keyword_boost_status'] = 'cancelled_keyword_already_in_context';
                    } else {
                        self::$last_rag_debug['keyword_boost_status'] = 'cancelled_no_needles';
                    }
                } else {
                    // Sin vectores: el catálogo vive en knowledge_vectors local (p. ej. MEC remoto tras «Sincronizar»).
                    // No bloquear LIKE local aunque el Hub RAG esté activo — si no, cloud + vector off = cero contexto.
                    $context = self::local_knowledge_rescue_like_search(
                        $project_id,
                        $search_term,
                        $ente_scope,
                        $strict_ente,
                        $rag_fetch_limit
                    );
                    $had_knowledge_rows = strlen(trim($context)) >= 10;
                }

                if ($use_vector && !$strict_ente && !self::should_skip_local_lexical_rag($project_id, (string) $context, $chunk_count)
                    && self::is_rag_topic_followup_utterance($user_msg_clean)
                    && $last_search !== '' && strlen(trim((string) $context)) >= 10) {
                    $wide_limit = min(Xabia_Brain::MAX_RAG_CHUNKS, max((int) $max_chunks, 12));
                    $lex_wide_key = self::sanitize_rag_search_term($search_term);
                    if ($lex_wide_key === '') {
                        $lex_wide_key = $last_search;
                    }
                    $lex_wide = self::safe_local_knowledge_search($project_id, $lex_wide_key, $ente_scope, $strict_ente, $wide_limit);
                    $lex_wide = trim((string) $lex_wide);
                    if ($lex_wide !== '' && strlen($lex_wide) >= 10) {
                        $mark = '### Búsqueda por palabras clave (seguimiento; más filas) ###';
                        if (mb_stripos((string) $context, $mark) === false) {
                            $probe = mb_substr($lex_wide, 0, 280);
                            if ($probe !== '' && mb_stripos((string) $context, $probe) === false) {
                                $context .= "\n\n" . $mark . "\n" . $lex_wide;
                                $had_knowledge_rows = true;
                            }
                        }
                    }
                }
            }

            if ($entity_anchor !== '' && class_exists('Xabia_Brain', false)
                && !self::is_hub_rag_enabled_for_project($project_id)) {
                if (mb_stripos((string) $context, $entity_anchor) === false) {
                    $entity_ctx = self::safe_local_knowledge_search(
                        $project_id,
                        $entity_anchor,
                        $ente_scope,
                        $strict_ente,
                        3
                    );
                    if ($entity_ctx !== '') {
                        $context = "### Ficha solicitada (seguimiento) ###\n" . $entity_ctx
                            . ($context !== '' ? "\n\n" . $context : '');
                        $had_knowledge_rows = true;
                        $chunk_count = max($chunk_count, 1);
                    }
                }
            }

            $entity_focus = $named_entity !== '' ? $named_entity : $entity_anchor;
            if ($entity_focus === '' && $utility_request && $last_entity !== '') {
                $entity_focus = $last_entity;
            }
            if ($entity_focus !== '' && class_exists('Xabia_Catalog_List', false)) {
                $native_entity_ctx = trim((string) Xabia_Catalog_List::fetch_entity_passport_context(
                    $project_id,
                    $config,
                    $entity_focus
                ));
                if ($native_entity_ctx !== '') {
                    $context = $native_entity_ctx . (trim((string) $context) !== '' ? "\n\n" . trim((string) $context) : '');
                    $had_knowledge_rows = true;
                    $chunk_count = max(1, (int) $chunk_count);
                }
            }

            $discovery_blocks = apply_filters('xabia_chat_addon_discovery_blocks', [], $project_id, $config);
            if (is_array($discovery_blocks) && $discovery_blocks !== []) {
                $disc_text = trim(implode("\n\n", array_filter(array_map('strval', $discovery_blocks))));
                if ($disc_text !== '') {
                    $context .= "\n\n### ADDON DISCOVERY ###\n" . $disc_text;
                }
            }

            $context_trim_limit = 6000;
            if (strlen($context) > $context_trim_limit) {
                $context = self::truncate_chat_rag_context($context, $context_trim_limit);
            }
            
            if (empty($context) || strlen($context) < 10) {
                $had_knowledge_rows = false;
                $context = "SYSTEM_NOTE: La búsqueda de '$search_term' no arrojó resultados directos en la base de datos local.";
            }

            $date_context_parts = [];
            $histForDates = [];
            if (!empty($_POST['history'])) {
                $raw_hist = json_decode(wp_unslash((string) $_POST['history']), true);
                if (is_array($raw_hist)) {
                    $histForDates = array_slice(array_filter($raw_hist, static function ($m) {
                        return isset($m['role'], $m['content']) && in_array($m['role'], ['user', 'assistant'], true);
                    }), -12);
                }
            }
            if ($histForDates === []) {
                $histForDates = $history;
            }
            foreach ($histForDates as $hist_msg) {
                if (!is_array($hist_msg) || ($hist_msg['role'] ?? '') !== 'user') {
                    continue;
                }
                $c = isset($hist_msg['content']) ? trim((string) $hist_msg['content']) : '';
                if ($c !== '') {
                    $date_context_parts[] = $c;
                }
            }
            if ($user_msg !== '') {
                $date_context_parts[] = $user_msg;
            }
            $date_context = trim(implode(' ', $date_context_parts));
            if ($date_context === '' && $user_msg !== '') {
                $date_context = $user_msg;
            }
            xabia_trace('[XABIA_CORE] date_context built', [
                'date_context_len' => strlen($date_context),
                'post_history'     => !empty($_POST['history']),
                'session_msgs'     => is_array($history) ? count($history) : 0,
            ]);

            $context = apply_filters(
                'xabia_agent_context_injection',
                $context,
                $project_id,
                $config,
                [
                    'user_message'  => $user_msg,
                    'date_context'  => $date_context,
                    'search_term'   => $search_term,
                    'scope'         => $ente_scope,
                    'lang_code'     => $lang_code,
                    'user_lang'     => $user_lang,
                ]
            );
            xabia_trace('[XABIA_CORE] after xabia_agent_context_injection', [
                'context_len' => is_string($context) ? strlen($context) : 0,
            ]);
            if (!is_string($context) || trim($context) === '') {
                $context = "SYSTEM_NOTE: El contexto modular no devolvió contenido util para '$search_term'.";
                $had_knowledge_rows = false;
            }
            $pre_assembly_context = trim((string) $context);
            $context = self::assemble_semantic_context_payload($project_id, $config, (string) $context);
            if (trim($context) === '' && $pre_assembly_context !== '') {
                $meta = self::default_semantic_source_meta($config);
                $context = self::format_semantic_context_block(
                    $meta['source'],
                    $meta['description'],
                    self::scrub_internal_secrets_in_text($pre_assembly_context)
                );
                if (function_exists('xabia_trace')) {
                    xabia_trace('[XABIA_CORE] assemble_semantic_context empty; using pre-assembly fallback', [
                        'project_id' => $project_id,
                        'pre_len'    => strlen($pre_assembly_context),
                    ]);
                }
            }

            $response_mode = 'list';
            if ($named_entity !== '' || self::query_implies_single_item_depth($user_msg_clean)) {
                if ($entity_anchor !== ''
                    || ($use_vector && $chunk_count > 0 && $chunk_count <= 2
                        && ($rag_total_found === null || (int) $rag_total_found <= 1)
                        && self::query_implies_single_item_depth($user_msg_clean))) {
                    $response_mode = 'development';
                }
            }
            // Pedidos de contacto/ficha concreta: modo desarrollo (conservar anexo completo).
            if ($response_mode === 'list'
                && self::query_implies_entity_utility_request($user_msg_clean)
                && ($entity_focus !== '' || $entity_anchor !== '' || $named_entity !== '')
            ) {
                $response_mode = 'development';
            }

            // En listados, no inyectar el anexo de atributos mapeados (bloque tras ---).
            if ($response_mode === 'list') {
                $context = self::strip_mapped_attributes_annex_from_context((string) $context);
            }

            
            if (empty($config)) {
                $projects = get_option('xabia_projects_config', []);
                $config = $projects[$project_id] ?? [];
            }
            $config['_xabia_proxy_user_lang'] = $user_lang;
            $temperature = isset($config['rules']['min_score']) ? floatval($config['rules']['min_score']) : 0.2;
            $ai_driver = $config['ai_driver'] ?? 'openai';
            $max_tokens = self::chat_max_tokens($config['rules']['max_output_tokens'] ?? 1200);

            $ente_display = '';
            if ($ente_scope !== 'global' && !empty($ente_scope)) {
                $ente_display = self::get_ente_display_name($project_id, $ente_scope, $ente_id_param_raw);
            }

            $tunnel_ente_from_request = $ente_id_param_raw !== '';
            $system_prompt = self::build_system_prompt($project_id, $config, $context, $current_temporal, $ente_display, $strict_ente, $response_mode, $lang_code, $tunnel_ente_from_request, $ente_scope, $had_knowledge_rows, $rag_total_found, $rag_vector_chunk_count);
            
            $history = self::maybe_summarize_history(is_array($history) ? $history : [], $project_id, $config);
            if (!session_id() && !headers_sent()) {
                session_start();
            }
            $qr_scan = function_exists('xabia_qr_scan_active_for_project') ? xabia_qr_scan_active_for_project($project_id) : null;
            $qr_id_session = is_array($qr_scan) && !empty($qr_scan['qr_id']) ? (string) $qr_scan['qr_id'] : '';
            $tunnel_pre = apply_filters('xabia_chat_tunnel_system_preamble', '', $project_id, [
                'strict_ente'  => $strict_ente,
                'ente_scope'   => $ente_scope,
                'ente_display' => $ente_display,
                'ente_id_raw'  => $ente_id_param_raw,
                'qr_scan'      => is_array($qr_scan) ? $qr_scan : null,
                'qr_id'        => $qr_id_session,
            ]);
            $system_prompt_final = $system_prompt;
            $has_qr_scan_context = $qr_id_session !== '';
            if (($strict_ente || $has_qr_scan_context) && is_string($tunnel_pre) && trim($tunnel_pre) !== '') {
                $system_prompt_final = trim($tunnel_pre) . "\n\n" . $system_prompt;
            }
            if ($is_continue_request) {
                if (!session_id() && !headers_sent()) {
                    session_start();
                }
                $meta_cont = $_SESSION['xabia_last_response_meta'][$project_id] ?? [];
                $prev_resp = trim((string) ($meta_cont['response'] ?? ''));
                if ($prev_resp !== '') {
                    $tail = mb_strlen($prev_resp) > 500 ? mb_substr($prev_resp, -500) : $prev_resp;
                    $system_prompt_final .= "\n\nCONTINUACIÓN OBLIGATORIA: La respuesta anterior quedó incompleta. Sigue EXACTAMENTE donde se cortó, sin repetir saludos ni párrafos ya escritos. Fragmento final previo:\n---\n" . $tail . "\n---";
                }
            }
            $messages = [['role' => 'system', 'content' => $system_prompt_final]];
            foreach (array_slice($history, -6) as $msg) {
                $messages[] = $msg;
            }
            $messages[] = ['role' => 'user', 'content' => $user_msg];
            $messages = self::sanitize_llm_messages_for_external_api($messages, $project_id, $config);
            xabia_trace('[XABIA_CORE] messages built for LLM', [
                'history_count' => is_array($history) ? count($history) : 0,
                'messages_count' => count($messages),
                'max_tokens' => $max_tokens,
            ]);

            $user_turns_in_thread = 0;
            foreach ($history as $hist_row) {
                if (is_array($hist_row) && (($hist_row['role'] ?? '') === 'user')) {
                    $user_turns_in_thread++;
                }
            }
            $is_new_conversation = ($user_turns_in_thread === 0 && !$is_continue_request);

            $response = '';
            if ($ai_driver === 'google_cloud' && class_exists('Xabia_Digixop_Client') && Xabia_Digixop_Client::should_use_local_vertex($config)) {
                $vertex_fed = self::should_use_federation_tools_for_project($project_id);
                $response = self::call_google_vertex($messages, $max_tokens, $config, $temperature, $project_id, $vertex_fed);
            } elseif (self::should_use_federation_tools_for_project($project_id)) {
                $response = self::call_openai_with_federation_tools($messages, $max_tokens, 'gpt-4o', $temperature, $project_id, $config);
            } else {
                $response = self::call_openai($messages, $max_tokens, 'gpt-4o', $temperature, $project_id, $config);
            }
            $response = self::sanitizeTechnicalFailureForUser($response);

            if (class_exists('Xabia_Digixop_Client') && Xabia_Digixop_Client::was_insufficient_balance()) {
                wp_send_json_error([
                    'message'              => Xabia_Digixop_Client::get_insufficient_balance_user_message(),
                    'digixop_insufficient' => true,
                ]);
                return;
            }

            $auto_continue_result = self::maybe_auto_continue_chat_response(
                $messages,
                (string) $response,
                $ai_driver,
                $max_tokens,
                $config,
                $temperature,
                $project_id,
                $is_continue_request
            );
            $response = (string) $auto_continue_result['response'];
            $finish_reason = (string) $auto_continue_result['finish_reason'];
            self::$last_generation_finish_reason = $finish_reason;

            
            $response = self::resolve_action_img_ids_in_response($response);
            $response = self::resolve_action_book_tags_in_response($response, $project_id);
            $response = self::resolve_action_url_tags_in_response($response, $project_id);
            $response = self::rewrite_mec_remote_hosts_in_response($response, $project_id);
            $response = self::maybe_append_photo_from_context($response, $context, $user_msg, $search_term);
            $response = self::format_chat_markdown_for_display((string) $response);
            $finish_reason = strtolower(trim((string) self::$last_generation_finish_reason));
            self::$last_generation_finish_reason = $finish_reason;
            self::$last_rag_debug['finish_reason'] = $finish_reason;
            $metrics = is_array(self::$last_generation_metrics) ? self::$last_generation_metrics : null;
            $truncated = self::should_mark_response_truncated(
                $finish_reason,
                (string) $response,
                $max_tokens,
                $metrics
            );
            if (self::should_log_rag_context_chivato($config)) {
                error_log(
                    '[XABIA RAG CHIVATO LLM] project=' . $project_id
                    . ' finish_reason=' . $finish_reason
                    . ' truncated=' . ($truncated ? 'yes' : 'no')
                    . ' max_output_tokens=' . (int) $max_tokens
                    . ' completion_tokens=' . (int) ($metrics['completion_tokens'] ?? 0)
                    . ' auto_continue=' . (!empty($auto_continue_result['ran']) ? 'yes' : (!empty($auto_continue_result['attempted']) ? 'attempted' : 'no'))
                );
            }

            
            if (!session_id() && !headers_sent()) session_start();
            if (!$is_continue_request) {
                $_SESSION['xabia_chat_history'][$project_id][] = ['role' => 'user', 'content' => $user_msg];
                $_SESSION['xabia_chat_history'][$project_id][] = ['role' => 'assistant', 'content' => $response];
            } else {
                $hist = isset($_SESSION['xabia_chat_history'][$project_id]) && is_array($_SESSION['xabia_chat_history'][$project_id])
                    ? $_SESSION['xabia_chat_history'][$project_id]
                    : [];
                $merged_response = $response;
                if ($hist !== []) {
                    $last_idx = count($hist) - 1;
                    $last = $hist[$last_idx];
                    if (is_array($last) && ($last['role'] ?? '') === 'assistant') {
                        $prev = trim((string) ($last['content'] ?? ''));
                        $merged_response = $prev !== '' ? ($prev . "\n\n" . $response) : $response;
                        $hist[$last_idx] = ['role' => 'assistant', 'content' => $merged_response];
                        $_SESSION['xabia_chat_history'][$project_id] = $hist;
                    } else {
                        $_SESSION['xabia_chat_history'][$project_id][] = ['role' => 'assistant', 'content' => $response];
                    }
                } else {
                    $_SESSION['xabia_chat_history'][$project_id][] = ['role' => 'assistant', 'content' => $response];
                }
                $response = $merged_response;
            }
            $_SESSION['xabia_chat_history'][$project_id] = array_slice($_SESSION['xabia_chat_history'][$project_id], -6);
            $_SESSION['xabia_last_response_meta'][$project_id] = [
                'truncated' => $truncated,
                'finish_reason' => $finish_reason,
                'response' => $response,
            ];
            $mentioned_entity = self::extract_primary_entity_name_from_assistant_text($response);
            $mentioned_entity = self::sanitize_persisted_entity_reference($mentioned_entity, $project_id, is_array($history ?? null) ? $history : []);
            if ($mentioned_entity !== '' && self::$last_generation_finish_reason !== 'native_catalog') {
                $_SESSION['xabia_last_entity'][$project_id] = $mentioned_entity;
            }
            session_write_close();

            
            if (!empty($response)) {
                global $wpdb;
                $table_logs = Xabia_DB::table('logs');
                if ($wpdb->get_var("SHOW TABLES LIKE '$table_logs'") === $table_logs) {
                    $wpdb->insert($table_logs, [
                        'project_id'    => $project_id, 
                        'ente_id'       => $ente_scope, 
                        'user_question' => $user_msg,
                        'ai_response'   => $response, 
                        'timestamp'     => current_time('mysql')
                    ]);
                }
            }

            self::digixop_report_chat_session_if_needed($project_id);
            self::log_usage_metrics($project_id, $user_msg);
            if (class_exists('Xabia_Analytics', false)) {
                $ch = Xabia_Analytics::detect_channel($project_id, $ente_id_param_raw);
                $tokens_used = 0;
                if (is_array(self::$last_generation_metrics)) {
                    $tokens_used = (int) (self::$last_generation_metrics['prompt_tokens'] ?? 0)
                        + (int) (self::$last_generation_metrics['completion_tokens'] ?? 0);
                }
                $visitor_key = Xabia_Analytics::visitor_key_for_request($project_id);
                $analytics_lang = is_string($user_lang) && $user_lang !== '' ? $user_lang : $lang_code;
                if ($is_new_conversation) {
                    Xabia_Analytics::record_chat_event($project_id, [
                        'event_type'  => 'conversation_start',
                        'source'      => $ch['source'],
                        'qr_id'       => $ch['qr_id'],
                        'tokens_used' => 0,
                        'lang'        => $analytics_lang,
                        'visitor_key' => $visitor_key,
                    ]);
                }
                $outcome = Xabia_Analytics::classify_outcome(
                    is_string($response) ? $response : '',
                    !empty($had_knowledge_rows),
                    is_string($context) ? $context : ''
                );
                Xabia_Analytics::record_chat_event($project_id, [
                    'event_type'    => 'message',
                    'source'        => $ch['source'],
                    'qr_id'         => $ch['qr_id'],
                    'rag_source'    => Xabia_Analytics::infer_rag_source($config),
                    'rag_hit'       => $had_knowledge_rows,
                    'tokens_used'   => $tokens_used,
                    'lang'          => $analytics_lang,
                    'visitor_key'   => $visitor_key,
                    'outcome'       => $outcome,
                    'user_question' => $user_msg,
                ]);
            }
            if (!$skip_response_cache && $cache_hash !== '' && class_exists('Xabia_Router') && in_array($route, ['ROUTE_KNOWLEDGE', 'ROUTE_GENERAL'], true)
                && !self::$rag_development_mode_active) {
                $cacheable = is_string($response) && trim($response) !== ''
                    && $response !== self::friendlyTemporaryFailureMessage()
                    && !self::responseLooksLikeTechnicalFailure($response);
                if ($cacheable) {
                    Xabia_Router::put_cached_response($project_id, $cache_hash, (string) $response, $route);
                }
            }

            wp_send_json_success([
                'response' => $response,
                'finish_reason' => $finish_reason,
                'truncated' => $truncated,
            ]);
        }

        /**
         * Texto base del Intérprete (router), neutro y compacto. Ampliable vía filtro xabia_system_prompt_rules (contexto 'interpreter').
         */
        private static function get_default_interpreter_rules($current_ymd) {
            return 'Eres el Intérprete. Tu salida son palabras clave separadas por espacios para buscar en la base de conocimiento: términos que puedan aparecer en los datos indexados (etiquetas, valores, categorías). No repitas la pregunta del usuario de forma literal. Corrige errores tipográficos evidentes. Para fechas relativas, usa como referencia HOY: ' . $current_ymd . '.';
        }

        /**
         * Presets base de comportamiento RAG (marca blanca). Ampliable vía filtro xabia_rag_behavior_presets.
         *
         * @return array{neutral: string, compact: string}
         */
        private static function get_rag_behavior_presets(): array {
            $presets = [
                'neutral' => "REGLAS RAG:\n"
                    . "ANCLAJE: Usa SOLO la información del CONTEXTO inyectado. PROHIBIDO inventar datos o apoyarte en conocimiento externo.\n"
                    . "IGNORANCIA: Si el dato no está en el CONTEXTO, indícalo de forma breve y directa (una frase). No inventes alternativas.\n"
                    . "INTEGRIDAD: No cites nombres, URLs, teléfonos, precios ni referencias que no aparezcan literalmente en el CONTEXTO.\n"
                    . "FORMATO: Respeta el modo LISTA o DESARROLLO indicado arriba. Sé claro y conciso.\n"
                    . "LISTADOS: Si respondes con varias entidades, no vuelques el anexo de detalle (bloque tras «---») ni campos de contacto/atributos extendidos salvo que el usuario los pida. Cierra invitando a elegir o profundizar en una.\n"
                    . "COHERENCIA: Si la conversación hace referencia a un ítem ya mencionado, mantén el mismo sujeto; no lo sustituyas por otro del CONTEXTO sin motivo.\n"
                    . "MEMORIA: Usa el historial solo para resolver pronombres o referencias a ítems ya recuperados.\n"
                    . "IMÁGENES: Si el usuario pide una imagen y hay «imagen:» en el CONTEXTO, emite [ACTION:IMG:VALOR] con ese valor exacto.\n",
                'compact' => "REGLAS RAG: Solo CONTEXTO. Sin inventar ni conocimiento externo. Si falta el dato → «No dispongo de esa información». Breve y directo. En listados no des el anexo de detalle hasta que lo pidan; invita a profundizar. [ACTION:IMG:] solo con valor literal del mapeo.\n",
            ];

            $filtered = apply_filters('xabia_rag_behavior_presets', $presets);
            if (!is_array($filtered)) {
                return $presets;
            }
            foreach (['neutral', 'compact'] as $key) {
                if (isset($filtered[$key]) && is_string($filtered[$key]) && trim($filtered[$key]) !== '') {
                    $presets[$key] = $filtered[$key];
                }
            }

            return $presets;
        }

        /**
         * Resuelve el bloque RAG desde rules.rag_behavior_preset / rules.rag_custom_behavior.
         */
        private static function resolve_rag_behavior_from_config(array $config): string {
            $rules = is_array($config['rules'] ?? null) ? $config['rules'] : [];
            $preset = sanitize_key((string) ($rules['rag_behavior_preset'] ?? ''));
            $custom = isset($rules['rag_custom_behavior']) ? trim((string) $rules['rag_custom_behavior']) : '';

            if ($preset === 'custom' && $custom !== '') {
                return $custom;
            }

            $presets = self::get_rag_behavior_presets();
            if ($preset === 'compact') {
                return $presets['compact'];
            }

            return $presets['neutral'];
        }

        /**
         * Router semántico (Intérprete): traduce la intención del usuario a términos de búsqueda.
         * Texto del intérprete: filtro {@see 'xabia_system_prompt_rules'} con contexto 'interpreter'.
         * Usa el mismo proveedor que el chat (OpenAI o Google Vertex) según $config['ai_driver'].
         */
        private static function expand_user_query_generic($user_msg, $last_search, $current_ymd, $project_id, $config = []) {
            $base = apply_filters(
                'xabia_system_prompt_rules',
                self::get_default_interpreter_rules($current_ymd),
                'interpreter',
                [
                    'project_id'  => $project_id,
                    'config'      => $config,
                    'current_ymd' => $current_ymd,
                    'user_msg'    => $user_msg,
                    'last_search' => $last_search,
                ]
            );
            $base = is_string($base) ? $base : self::get_default_interpreter_rules($current_ymd);
            $addon = apply_filters('xabia_router_search_logic', '', $project_id, $current_ymd);
            $prompt = "Intérprete (mapeo a ontología). $base $addon ENTRADA: \"$user_msg\". Búsqueda anterior: \"$last_search\". SALIDA: solo palabras clave separadas por espacios para que el Buscador encuentre las filas correctas.";
            $messages = self::sanitize_llm_messages_for_external_api(
                [['role' => 'system', 'content' => $prompt]],
                (string) $project_id,
                is_array($config) ? $config : []
            );
            if (($config['ai_driver'] ?? '') === 'google_cloud' && class_exists('Xabia_Digixop_Client') && Xabia_Digixop_Client::should_use_local_vertex($config)) {
                return self::call_google_vertex($messages, 200, $config, 0.0, $project_id, false);
            }
            return self::call_openai($messages, 200, 'gpt-4o-mini', 0.0, $project_id, $config);
        }

        private static function get_ente_display_name($project_id, $ente_scope, $fallback_raw = '') {
            global $wpdb;
            $table = Xabia_DB::table('knowledge_vectors');

            
            $meta = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_data FROM $table WHERE project_id = %s AND ente_id = %s ORDER BY id DESC LIMIT 1",
                $project_id,
                $ente_scope
            ));

            if (!empty($meta)) {
                $decoded = json_decode($meta, true);
                if (is_array($decoded) && !empty($decoded['__ente_display'])) {
                    return (string)$decoded['__ente_display'];
                }
            }

            
            if (!empty($fallback_raw)) return (string)$fallback_raw;
            return (string)$ente_scope;
        }

        /**
         * Bloque políglota alineado con central-api VertexForwarder (hub).
         * $origin_lang = user_lang / lang del sitio; solo fallback, no fuerza idioma de respuesta.
         */
        private static function xabia_polyglot_language_rule($origin_lang = null): string {
            $block = "# GESTIÓN DE IDIOMAS Y TRADUCCIÓN AUTOMÁTICA:\n"
                . "- Responde siempre en el mismo idioma en el que te ha hablado el usuario en su último mensaje ('user_lang').\n"
                . "- Si el contexto RAG recuperado está en un idioma diferente, procésalo internamente, tradúcelo y redacta la respuesta final en el idioma del interlocutor de forma nativa.\n"
                . "- Usa el idioma de origen solo como fallback si el mensaje del usuario es ambiguo.";

            if (!is_string($origin_lang)) {
                return $block;
            }
            $tag = trim($origin_lang);
            $tag = preg_replace('/[^a-zA-Z0-9\-]/', '', $tag) ?? '';
            $tag = substr(strtolower($tag), 0, 35);
            if ($tag === '') {
                return $block;
            }

            return $block . "\n- Idioma de origen (fallback del sitio/shortcode): " . $tag . '.';
        }

        /**
         * Bloque auto-descriptivo para una fuente de datos (meta-schema).
         */
        private static function format_semantic_context_block(string $source_label, string $description, string $data): string
        {
            $data = trim($data);
            if ($data === '') {
                return '';
            }
            $source_label = trim($source_label) !== '' ? trim($source_label) : __('Fuente de datos', 'xabia-intelligence');
            $description = trim($description) !== '' ? trim($description) : __('Datos estructurados disponibles para este agente.', 'xabia-intelligence');

            return '[FUENTE: ' . $source_label . "]\n"
                . '[DESCRIPCIÓN: ' . $description . "]\n"
                . "[DATOS]:\n" . $data;
        }

        /**
         * =============================================================================
         * MURO DE PRIVACIDAD DE DATOS DE XABIA (PII Shield — OBLIGATORIO)
         * =============================================================================
         *
         * Todo texto de contexto o historial que salga hacia APIs externas (Gemini, OpenAI,
         * Vertex, etc.) DEBE pasar por apply_context_privacy_shield() antes del envío.
         *
         * Garantías:
         * - Ofusca PII genérica (correo, teléfono en texto libre, tokens, rutas de servidor).
         * - Elimina líneas de metadatos privados de comercio/reservas (WooCommerce, MEC,
         *   Avirato, Amelia): clientes, facturación, envío, notas de pedido, transacciones.
         * - Preserva contacto público de catálogo (fichas EMPRESA:, bloques [DATOS], Woo/MEC/Avirato).
         * - Zonas de catálogo: solo secretos/servidor + líneas privadas de comercio; sin ofuscar tel/email públicos.
         * - Texto libre del usuario: PII completa. Asistente/sistema: modo catálogo por bloques cuando aplica.
         *
         * @param array<string, mixed> $config
         * @param array<string, mixed> $opts channel: context|user|assistant|system
         */
        private static function apply_context_privacy_shield(
            string $text,
            string $project_id = '',
            array $config = [],
            array $opts = []
        ): string {
            $text = (string) $text;
            if ($text === '') {
                return '';
            }
            $channel = isset($opts['channel']) ? (string) $opts['channel'] : 'context';
            if (!in_array($channel, ['context', 'user', 'assistant', 'system'], true)) {
                $channel = 'context';
            }

            $text = self::sanitize_privacy_shield_by_blocks($text, $project_id, $config, $channel);

            $filtered = apply_filters('xabia_context_privacy_sanitize', $text, $project_id, $config, $channel);
            if (!is_string($filtered)) {
                return $text;
            }

            return $filtered;
        }

        /**
         * @param array<string, mixed> $config
         */
        private static function sanitize_privacy_shield_by_blocks(
            string $text,
            string $project_id,
            array $config,
            string $channel
        ): string {
            $text = self::strip_commerce_private_context_lines($text);
            if ($text === '') {
                return '';
            }

            if ($channel === 'user') {
                return self::scrub_pii_and_secrets_in_text($text);
            }

            if (self::context_block_is_public_catalog($text)) {
                return self::scrub_internal_secrets_in_text($text);
            }

            if ($channel !== 'user' && !self::outbound_text_needs_pii_scrub($text)) {
                return self::scrub_internal_secrets_in_text($text);
            }

            $blocks = preg_split('/\n\n+/u', $text);
            if (!is_array($blocks) || count($blocks) <= 1) {
                return self::scrub_privacy_shield_single_block($text, $project_id, $config, $channel);
            }

            $sanitized = [];
            foreach ($blocks as $block) {
                $block = trim((string) $block);
                if ($block === '') {
                    continue;
                }
                $sanitized[] = self::scrub_privacy_shield_single_block($block, $project_id, $config, $channel);
            }

            return implode("\n\n", $sanitized);
        }

        /**
         * @param array<string, mixed> $config
         */
        private static function scrub_privacy_shield_single_block(
            string $block,
            string $project_id,
            array $config,
            string $channel
        ): string {
            unset($project_id, $config);
            if (self::context_block_is_public_catalog($block)) {
                return self::scrub_internal_secrets_in_text($block);
            }
            if ($channel === 'user') {
                return self::scrub_pii_and_secrets_in_text($block);
            }
            if (!self::outbound_text_needs_pii_scrub($block)) {
                return self::scrub_internal_secrets_in_text($block);
            }

            $lines = preg_split('/\R/u', $block);
            if (!is_array($lines)) {
                return self::scrub_pii_and_secrets_in_text($block);
            }
            $sanitized = [];
            foreach ($lines as $line) {
                $line = (string) $line;
                if ($line === '') {
                    $sanitized[] = '';
                    continue;
                }
                if (self::context_line_allows_public_contact($line) || self::context_block_is_public_catalog($line)) {
                    $sanitized[] = self::scrub_internal_secrets_in_text($line);
                } else {
                    $sanitized[] = self::scrub_pii_and_secrets_in_text($line);
                }
            }

            return self::scrub_internal_secrets_in_text(implode("\n", $sanitized));
        }

        /**
         * @param list<array{role?: string, content?: string}> $messages
         *
         * @return list<array{role: string, content: string}>
         */
        private static function sanitize_llm_messages_for_external_api(array $messages, string $project_id = '', array $config = []): array
        {
            $out = [];
            foreach ($messages as $msg) {
                if (!is_array($msg)) {
                    continue;
                }
                $role = isset($msg['role']) ? (string) $msg['role'] : 'user';
                $content = isset($msg['content']) ? (string) $msg['content'] : '';
                $channel = $role === 'user' ? 'user' : ($role === 'assistant' ? 'assistant' : 'context');
                $out[] = [
                    'role'    => $role,
                    'content' => self::apply_context_privacy_shield($content, $project_id, $config, ['channel' => $channel]),
                ];
            }

            return $out;
        }

        /**
         * Bloque indexado / catálogo público: datos de negocio, no PII de clientes.
         */
        private static function context_block_is_public_catalog(string $block): bool
        {
            $block = trim($block);
            if ($block === '') {
                return false;
            }
            if (preg_match('/\bEMPRESA\s*:/iu', $block)) {
                return true;
            }
            if (preg_match('/\[(?:FUENTE|DESCRIPCIÓN|DATOS)\]/iu', $block)) {
                return true;
            }
            if (preg_match('/\b(?:DESCRIPCI[ÓO]N GENERAL|EXPERIENCIAS|PROPUESTAS|PRODUCTO|SKU|PRECIO(?:_REGULAR|_OFERTA)?|STOCK|CATEGOR[IÍ]A)\s*:/iu', $block)) {
                return true;
            }
            if (preg_match('/^###\s+(?:ADDON DISCOVERY|CARRITO CONVERSACIONAL|IMAGEN \(mapeo\))/imu', $block)) {
                return true;
            }

            return (bool) apply_filters('xabia_context_privacy_is_catalog_block', false, $block);
        }

        /**
         * Señales mínimas antes de ejecutar regex PII costosas (hosting compartido).
         */
        private static function outbound_text_needs_pii_scrub(string $text): bool
        {
            if ($text === '') {
                return false;
            }
            if (strpos($text, '@') !== false) {
                return true;
            }
            if (stripos($text, 'Bearer ') !== false || stripos($text, 'IBAN') !== false) {
                return true;
            }
            if (preg_match('#/(?:var|home|usr|opt|tmp)/#i', $text)) {
                return true;
            }
            if (preg_match('/\b(?:billing_|shipping_|customer_email|order_note|guest_email)\b/i', $text)) {
                return true;
            }
            if (preg_match('/\beyJ[A-Za-z0-9\-_]+\./', $text)) {
                return true;
            }
            if (preg_match('/\+?\d[\d\s.\-]{8,}\d/u', $text) && !preg_match('/\bEMPRESA\s*:/iu', $text)) {
                return true;
            }

            return false;
        }

        /**
         * Contacto público de catálogo (cualquier etiqueta habitual en CSV/WP/addons).
         */
        private static function context_line_allows_public_contact(string $line): bool
        {
            return (bool) preg_match(
                '/\b(?:EMPRESA|ENTE|ORGANIZACI[ÓO]N|ASOCIACI[ÓO]N|'
                . 'TEL(?:[EÉ]FONO|EFONO)?|TLF|TFNO|TF\.?|M[ÓO]VIL|MOVIL|FAX|WHATSAPP|WSP|WA|PHONE|'
                . 'EMAIL|E-?MAIL|CORREO|MAIL|CONTACTO|WEB|SITIO|URL|LINK|'
                . 'IMAGEN|LOGOTIPO|CATEGOR[IÍ]A|SUBCATEGOR[IÍ]AS|LOCALIDAD|UBICACI[ÓO]N|MUNICIPIO|ZONA|'
                . 'PRECIO|STOCK|SKU|PRODUCTO|DESCRIPCI[ÓO]N)\s*:/iu',
                $line
            );
        }

        /**
         * Elimina líneas con metadatos privados de pedidos/reservas (Woo, MEC, Avirato, Amelia…).
         */
        private static function strip_commerce_private_context_lines(string $text): string
        {
            $text = trim($text);
            if ($text === '') {
                return '';
            }
            $drop_patterns = apply_filters('xabia_context_privacy_commerce_line_patterns', [
                '/^\s*(?:billing|shipping)_(?:first|last|last_)?name\b/im',
                '/^\s*(?:billing|shipping)_(?:email|phone|postcode|zip|address|city|state|country)\b/im',
                '/^\s*(?:customer|cliente)(?:_|\s)(?:name|nombre|email|correo|id|note|nota)\b/im',
                '/^\s*(?:order|pedido)(?:_|\s)?(?:note|nota|customer|cliente)\b/im',
                '/^\s*(?:nota\s+del\s+cliente|customer\s+note|private\s+note|order\s+note)\b/im',
                '/^\s*direcci[oó]n\s+de\s+(?:facturaci[oó]n|env[ií]o)\b/im',
                '/^\s*(?:billing|shipping)\s+address\b/im',
                '/^\s*(?:_customer_user|customer_user_id|user_id\s*:\s*\d+)\b/im',
                '/^\s*(?:transaction|transacci[oó]n)(?:_|\s)(?:id|ref|meta)\b/im',
                '/^\s*(?:payment|pago)(?:_|\s)(?:method|token|card|last4)\b/im',
                '/^\s*(?:guest|invitado)(?:_|\s)(?:email|name|datos)\b/im',
                '/^\s*(?:reservation|reserva)(?:_|\s)(?:guest|cliente|customer|holder)\b/im',
                '/^\s*###\s*(?:DATOS\s+(?:CLIENTE|PRIVADOS)|CLIENTE|FACTURACIÓN|BILLING)\s*###\s*$/im',
            ]);
            if (!is_array($drop_patterns)) {
                $drop_patterns = [];
            }

            $lines = preg_split('/\R/u', $text);
            if (!is_array($lines)) {
                return $text;
            }
            $kept = [];
            foreach ($lines as $line) {
                $drop = false;
                foreach ($drop_patterns as $pattern) {
                    if (!is_string($pattern) || $pattern === '') {
                        continue;
                    }
                    if (@preg_match($pattern, $line) === 1) {
                        $drop = true;
                        break;
                    }
                }
                if (!$drop) {
                    $kept[] = $line;
                }
            }

            return implode("\n", $kept);
        }

        /**
         * Ofusca PII y secretos en texto libre (no contacto público etiquetado).
         */
        private static function scrub_pii_and_secrets_in_text(string $text): string
        {
            $text = self::scrub_internal_secrets_in_text($text);

            $text = (string) preg_replace(
                '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,63}\b/iu',
                '[correo ofuscado]',
                $text
            );
            $text = (string) preg_replace(
                '/\+(?:\d[\s.\-]?){7,18}\d\b/u',
                '[teléfono ofuscado]',
                $text
            );
            $text = (string) preg_replace(
                '/\b(?:\+34[\s.\-]?)?[6789]\d{2}[\s.\-]?\d{3}[\s.\-]?\d{3}\b/u',
                '[teléfono ofuscado]',
                $text
            );
            $text = (string) preg_replace(
                '/\b(?:IBAN|iban)\s*[A-Z]{2}\d{2}[\s]?[\dA-Z]{4,30}\b/u',
                '[iban ofuscado]',
                $text
            );
            $text = (string) preg_replace(
                '/\b(?:\d[ -]*?){13,19}\b/u',
                '[tarjeta ofuscada]',
                $text
            );

            return $text;
        }

        /**
         * Rutas internas, tokens de sesión/API y credenciales embebidas.
         */
        private static function scrub_internal_secrets_in_text(string $text): string
        {
            if ($text === '') {
                return '';
            }

            $text = (string) preg_replace(
                '#(?:/var/www|/home/[\w.\-]+|/usr/(?:local/)?www|/opt/(?:bitnami|lampp)|/private/var|/tmp/[\w.\-/]+)[^\s\]\'"<>]*#iu',
                '[ruta interna]',
                $text
            );
            $text = (string) preg_replace(
                '#[A-Za-z]:\\\\(?:[^\\\\\s\]\'"<>]+\\\\)*[^\\\\\s\]\'"<>]*#u',
                '[ruta interna]',
                $text
            );
            $text = (string) preg_replace(
                '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/u',
                'Bearer [token ofuscado]',
                $text
            );
            $text = (string) preg_replace(
                '/\b(?:session[_-]?(?:id|token)|auth[_-]?token|api[_-]?key|nonce|secret|password)\s*[=:]\s*\S+/iu',
                '[token ofuscado]',
                $text
            );
            $text = (string) preg_replace(
                '/\b[a-f0-9]{40,}\b/i',
                '[token ofuscado]',
                $text
            );
            $text = (string) preg_replace(
                '/\beyJ[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\b/',
                '[token ofuscado]',
                $text
            );

            return $text;
        }

        /**
         * Descripción semántica del catálogo: primero rules.context_source_description (admin / wp_options).
         *
         * @param array<string, mixed> $config
         */
        private static function resolve_context_source_description(array $config): string
        {
            $rules = is_array($config['rules'] ?? null) ? $config['rules'] : [];
            if (!isset($rules['context_source_description']) && $config !== []) {
                $project_id = trim((string) ($config['project_id'] ?? ''));
                if ($project_id === '' && isset($config['id'])) {
                    $project_id = trim((string) $config['id']);
                }
                if ($project_id !== '' && function_exists('get_option')) {
                    $projects = get_option('xabia_projects_config', []);
                    if (is_array($projects[$project_id]['rules'] ?? null)) {
                        $rules = $projects[$project_id]['rules'];
                    }
                }
            }
            $desc = isset($rules['context_source_description'])
                ? trim((string) $rules['context_source_description'])
                : '';

            return $desc;
        }

        /**
         * Resuelve etiqueta y descripción semántica por tipo de fuente del proyecto.
         *
         * @param array<string, mixed> $config
         *
         * @return array{source:string,description:string}
         */
        private static function default_semantic_source_meta(array $config): array
        {
            $custom_desc = self::resolve_context_source_description($config);
            $source_type = trim((string) ($config['source_type'] ?? 'multi'));
            $source_label = trim((string) ($config['name'] ?? ''));
            if ($source_label === '') {
                $source_label = __('Base de conocimientos del agente', 'xabia-intelligence');
            }

            $by_type = [
                'csv'   => __('Catálogo CSV / fichas indexadas', 'xabia-intelligence'),
                'sql'   => __('Datos en vivo (SQL / conector remoto)', 'xabia-intelligence'),
                'multi' => __('Catálogo federado multi-fuente', 'xabia-intelligence'),
            ];
            if (isset($by_type[$source_type])) {
                $source_label = $by_type[$source_type];
            }

            $description = $custom_desc !== ''
                ? $custom_desc
                : __('Contiene entidades, productos o servicios indexados para este agente. Solo datos públicos de catálogo (nombre, precio, stock, descripción, disponibilidad). Nunca datos de clientes, facturación ni transacciones privadas.', 'xabia-intelligence');

            return [
                'source'      => $source_label,
                'description' => $description,
            ];
        }

        /**
         * Ensambla el contexto RAG/conectores en bloques [FUENTE]/[DESCRIPCIÓN]/[DATOS].
         *
         * Punto de control del Muro de Privacidad: ningún bloque sale sin sanitizar.
         *
         * @param array<string, mixed> $config
         */
        private static function assemble_semantic_context_payload(string $project_id, array $config, string $raw_context): string
        {
            $raw_context = trim($raw_context);
            if ($raw_context === '') {
                return '';
            }
            $raw_backup = $raw_context;
            $raw_context = self::apply_context_privacy_shield($raw_context, $project_id, $config);

            $meta = self::default_semantic_source_meta($config);
            $schemas = apply_filters('xabia_context_source_schemas', [
                [
                    'source'      => $meta['source'],
                    'description' => $meta['description'],
                    'context'     => $raw_context,
                ],
            ], $project_id, $config, $raw_context);

            if (!is_array($schemas)) {
                $schemas = [[
                    'source'      => $meta['source'],
                    'description' => $meta['description'],
                    'context'     => $raw_context,
                ]];
            }

            $ui_description = self::resolve_context_source_description($config);
            if ($ui_description !== '') {
                foreach ($schemas as $idx => $schema) {
                    if (!is_array($schema) || !empty($schema['xabia_dev_lock'])) {
                        continue;
                    }
                    $schemas[$idx]['description'] = $ui_description;
                }
            }

            $blocks = [];
            foreach ($schemas as $schema) {
                if (!is_array($schema)) {
                    continue;
                }
                $source = self::apply_context_privacy_shield(
                    (string) ($schema['source'] ?? $meta['source']),
                    $project_id,
                    $config
                );
                $description = self::apply_context_privacy_shield(
                    (string) ($schema['description'] ?? $meta['description']),
                    $project_id,
                    $config
                );
                $data = self::apply_context_privacy_shield(
                    (string) ($schema['context'] ?? ''),
                    $project_id,
                    $config
                );
                $block = self::format_semantic_context_block($source, $description, $data);
                if ($block !== '') {
                    $blocks[] = $block;
                }
            }

            $extra = apply_filters('xabia_semantic_context_data_blocks', [], $project_id, $config, $raw_context);
            if (is_array($extra)) {
                foreach ($extra as $extra_block) {
                    if (is_string($extra_block) && trim($extra_block) !== '') {
                        $blocks[] = self::apply_context_privacy_shield(trim($extra_block), $project_id, $config);
                    }
                }
            }

            if ($blocks === []) {
                $assembled = self::format_semantic_context_block(
                    $meta['source'],
                    $meta['description'],
                    $raw_context
                );
            } else {
                $assembled = self::apply_context_privacy_shield(implode("\n\n", $blocks), $project_id, $config);
            }

            if (trim($assembled) === '' && trim($raw_backup) !== '') {
                $assembled = self::format_semantic_context_block(
                    $meta['source'],
                    $meta['description'],
                    self::scrub_internal_secrets_in_text($raw_backup)
                );
            }

            self::log_rag_context_chivato($project_id, $assembled, $config);

            return $assembled;
        }

        /**
         * Consigna de navegación semántica delegada al LLM (sin reglas procedimentales PHP).
         */
        private static function semantic_navigation_system_rule(): string
        {
            return 'NAVEGACIÓN SEMÁNTICA (OBLIGATORIO): Tienes acceso a múltiples fuentes de datos auto-descritas en bloques [FUENTE], [DESCRIPCIÓN] y [DATOS]. '
                . 'Es tu responsabilidad exclusiva interpretar las intenciones del usuario, gestionar refinamientos, preguntas de seguimiento y negaciones '
                . '(p. ej. si el usuario dice «no hay ninguna de…», analiza el contexto previo y confirma basándote estrictamente en las descripciones y datos provistos). '
                . 'Responde sobre las entidades del CONTEXTO (empresas, asociaciones, productos o servicios indexados) sin asumir nombres de plataforma, portal o CMS salvo que consten en el CONTEXTO o el usuario los cite. '
                . 'Si [DATOS] incluye una línea con prefijo de entidad (p. ej. ENTIDAD:, FICHA:) seguida de un nombre, cítalo explícitamente; no parafrasees con «una empresa» ni inventes marcas. '
                . 'Responde siempre en el mismo idioma del último mensaje del usuario.';
        }

        /**
         * @param string $response_mode 'list' = varios resultados, formato lista comparativa; 'development' = 1-2 entes, respuesta narrativa en profundidad.
         * @param string $lang_code      ISO 639-1 (p. ej. es, eu); interfaz/voz/fallback — no fuerza idioma de respuesta del modelo.
         * @param bool   $tunnel_ente_active Si viene POST `ente_id` (modo estricto / Smart QR): primera persona obligatoria con prioridad sobre instrucciones del proyecto.
         * @param string $ente_scope     Scope de búsqueda (slug del ente); fallback para nombre en modo túnel.
         * @param int|null $rag_total_found    Metadato del Hub/local: candidatos clasificados por encima del top-k enviado.
         * @param int|null $rag_chunks_returned Nº de chunks vectoriales incluidos en el contexto (chunk_count de la búsqueda vectorial).
         */
        private static function build_system_prompt($project_id, $config, $context, $current_date, $ente_display = '', $strict_ente = false, $response_mode = 'list', $lang_code = 'es', $tunnel_ente_active = false, $ente_scope = 'global', $has_catalog_rows = true, $rag_total_found = null, $rag_chunks_returned = null) {
            $instructions = $config['rules']['instructions'] ?? 'Eres un asistente inteligente.';
            $assistant_name = trim((string) ($config['design']['avatar_name'] ?? ''));
            $identity_rule = $assistant_name !== ''
                ? 'IDENTIDAD DEL ASISTENTE: Tu nombre visible y persona de conversación es "' . $assistant_name . '". Si saludas o te presentas, usa exactamente ese nombre y no otro.'
                : '';
            $origin_lang = isset($config['_xabia_proxy_user_lang']) ? (string) $config['_xabia_proxy_user_lang'] : $lang_code;
            $language_rule = self::xabia_polyglot_language_rule($origin_lang);
            $time_awareness = "REFERENCIA TEMPORAL OBLIGATORIA: Hoy es " . strtoupper($current_date) . ". "
                . "Ancla todas las expresiones relativas («hoy», «mañana», «esta semana», «este fin de semana», «próximos», «futuro», «qué hay») a la fecha ISO de hoy. "
                . "Si el CONTEXTO incluye campos Fecha/fecha en AAAA-MM-DD, un ítem es FUTURO solo si Fecha >= la ISO de hoy; es PASADO si Fecha < hoy. "
                . "PROHIBIDO decir que no hay eventos o actividades futuras si el CONTEXTO trae filas con Fecha >= hoy. "
                . "PROHIBIDO presentar como disponibles eventos con Fecha < hoy, salvo que el usuario pida pasado o histórico.";
            $time_awareness = (string) apply_filters(
                'xabia_system_time_awareness',
                $time_awareness,
                $project_id,
                $config,
                $current_date
            );
            $imagen_mapeo_rule = 'IMAGEN Y MAPEO (OBLIGATORIO): Cuando el usuario pida ver una imagen o foto, usa las líneas «imagen: …» del bloque «=== IMAGEN (mapeo) ===». '
                . 'Si pide el logotipo o logo, usa las líneas «logotipo: …» del mismo bloque. '
                . 'Debes emitir exactamente [ACTION:IMG:VALOR] donde VALOR es únicamente ese contenido, carácter a carácter (URL completa o ID numérico), copiado del mapeo. '
                . 'PROHIBIDO inventar o construir VALOR: nombres de casas, slugs, etiquetas, nombres de archivo (.jpg), rutas inventadas, palabras sueltas o «lo que parezca lógico». '
                . 'PROHIBIDO sustituir VALOR por el título del alojamiento, por marcas comerciales o por descripciones. Si no aparece un valor «imagen» o «logotipo» explícito en el CONTEXTO para esa entidad, no uses [ACTION:IMG:] y dilo con palabras.';

            $visual_protocols = 'PROTOCOLOS VISUALES: [ACTION:URL:url_completa] [ACTION:CALL:telefono] [ACTION:MAP:texto_consulta]. '
                . '[ACTION:IMG:VALOR] solo si VALOR está en el contexto como dato «imagen» del mapeo (véase IMAGEN Y MAPEO).'
                . "\n\n" . $imagen_mapeo_rule;

            $scope_str = (string) $ente_scope;
            $persona = '';
            if ($tunnel_ente_active) {
                $dn = !empty($ente_display) ? $ente_display : '';
                if ($dn === '' && $scope_str !== '' && $scope_str !== 'global') {
                    $dn = ucwords(str_replace(['-', '_'], ' ', $scope_str));
                }
                if ($dn === '') {
                    $dn = __('este ente', 'xabia-intelligence');
                }
                $persona = 'OBLIGATORIO — RESPUESTA EN PRIMERA PERSONA: Respondes exclusivamente en primera persona como si fueras "' . $dn . '". Saluda y desenvuelve el discurso como el objeto, obra o ente representado. Esta regla tiene PRIORIDAD ABSOLUTA sobre las instrucciones generales del proyecto si contradijeran el uso de primera persona para este acceso.' . "\n\n";
            } elseif (!empty($ente_display)) {
                $persona = "PERSONA (MUSEO): A partir de ahora respondes en PRIMERA PERSONA como si fueras \"$ente_display\". Saluda y habla como el objeto/obra/ente. Ejemplo: \"Hola, soy $ente_display...\".\n";
            }

            $qr_label = !empty($ente_display) ? $ente_display : '';
            if ($qr_label === '' && $scope_str !== '' && $scope_str !== 'global') {
                $qr_label = ucwords(str_replace(['-', '_'], ' ', $scope_str));
            }

            $qr_rules = '';
            if ($strict_ente && $qr_label !== '') {
                $qr_rules = "MODO QR (FILTRO ESTRICTO): Solo puedes usar la información del CONTEXTO de \"$qr_label\". Si te preguntan por otra pieza u otra entidad, di que no tienes acceso a esa información en este modo.\n";
            } elseif ($strict_ente) {
                $qr_rules = "MODO QR (FILTRO ESTRICTO): Solo puedes usar la información del CONTEXTO disponible. Si te preguntan por otra entidad, indica que no tienes acceso a esa información en este modo.\n";
            }

            $format_instruction = "";
            if ($response_mode === 'list') {
                $format_instruction = "FORMATO LISTA: Si el usuario pide comparar o listar opciones, genera viñetas concisas (•), una entidad por línea. "
                    . "Incluye solo el identificador/nombre y un detalle breve del CONTEXTO. "
                    . "PROHIBIDO en el listado: volcar el anexo de detalle (bloque tras «---») ni atributos extendidos de ficha. "
                    . "Reserva esos datos para cuando el usuario pida profundizar en una entidad concreta. "
                    . "Cierra invitando a elegir o pedir más detalle de una opción.\n";
            } elseif ($response_mode === 'development') {
                $format_instruction = "FORMATO DESARROLLO: El usuario quiere profundizar en uno o muy pocos ítems. Responde en 1-2 párrafos cortos, con empatía y sin repetir datos ya dichos. Si pide datos de ficha/contacto y están en el CONTEXTO, úsalos. Objetivo: resolver dudas y conversión.\n";
            }

            $semantic_navigation = self::semantic_navigation_system_rule();

            $rag_behavior = self::resolve_rag_behavior_from_config($config);

            $rag_behavior = apply_filters(
                'xabia_system_prompt_rules',
                $rag_behavior,
                'rag_behavior',
                [
                    'project_id'     => $project_id,
                    'config'         => $config,
                    'response_mode'  => $response_mode,
                    'strict_ente'    => $strict_ente,
                    'ente_display'   => $ente_display,
                    'context'        => $context,
                ]
            );
            if (!is_string($rag_behavior)) {
                $rag_behavior = self::resolve_rag_behavior_from_config($config);
            }

            $rag_behavior = apply_filters(
                'xabia_system_rag_behavior',
                $rag_behavior,
                $project_id,
                $config,
                $response_mode
            );
            if (!is_string($rag_behavior)) {
                $rag_behavior = '';
            }
            $rag_behavior = trim($rag_behavior);
            if ($rag_behavior !== '') {
                $rag_behavior .= "\n";
            }

            $more_available_rule = '';
            if ($rag_total_found !== null && $rag_chunks_returned !== null
                && (int) $rag_total_found > (int) $rag_chunks_returned) {
                $more_available_rule = 'Hay más resultados disponibles en la base de datos. Si el usuario desea más opciones, invítale a pedirlas.' . "\n\n";
            }

            $no_catalog_guard = '';
            if (!$has_catalog_rows) {
                $no_catalog_guard = "SIN DATOS DE CATÁLOGO: No se recuperó ninguna fila del índice para esta consulta. PROHIBIDO inventar o sugerir nombres, URLs, teléfonos, emails o datos concretos. PROHIBIDO usar conocimiento general o de Internet. Responde de forma breve que no tienes información en la base de datos de este proyecto para ese criterio.\n\n";
            }

            $precedence = '';
            if ($tunnel_ente_active) {
                $precedence = 'PRECEDENCIA DEL SISTEMA (ítem QR / modo estricto): Las reglas OBLIGATORIO — PRIMERA PERSONA y MODO QR de este prompt prevalecen sobre las instrucciones personalizadas del proyecto si entraran en conflicto.' . "\n\n";
            }

            return $precedence . "$instructions\n\n$identity_rule\n\n$language_rule\n\n$semantic_navigation\n\n$time_awareness\n\n$visual_protocols\n\n$persona$qr_rules\n$format_instruction\n$rag_behavior$more_available_rule$no_catalog_guard\n\nCONTEXTO DISPONIBLE:\n###\n$context\n###";
        }

        /**
         * Normaliza [ACTION:IMG:…]: prioridad 1) URL absoluta o // (sin tocar medios WP);
         * 2) ruta absoluta /… → URL del sitio; 3) solo dígitos → adjunto; 4) pistas / filtros.
         */
        private static function resolve_action_img_ids_in_response($response) {
            if (!is_string($response) || strpos($response, '[ACTION:IMG:') === false) {
                return $response;
            }

            return preg_replace_callback('/\[ACTION:IMG:([^\]]+)\]/', static function (array $m) {
                $raw = trim($m[1]);
                if ($raw === '') {
                    return $m[0];
                }
                $raw = rtrim($raw, " \t\n\r.,;)]}\"'" . '”’');

                if (preg_match('#^https?://#i', $raw)) {
                    return '[ACTION:IMG:' . $raw . ']';
                }
                if (strpos($raw, '//') === 0 && strlen($raw) > 2) {
                    return '[ACTION:IMG:https:' . $raw . ']';
                }
                if ($raw[0] === '/') {
                    $abs = esc_url(home_url($raw));

                    return $abs !== '' ? '[ACTION:IMG:' . $abs . ']' : $m[0];
                }
                if (ctype_digit($raw)) {
                    $id = (int) $raw;
                    if ($id < 1) {
                        return $m[0];
                    }
                    $url = wp_get_attachment_url($id);

                    return $url ? '[ACTION:IMG:' . $url . ']' : $m[0];
                }

                $url = apply_filters('xabia_resolve_img_hint', '', $raw);
                if (!is_string($url) || $url === '') {
                    $url = self::resolve_img_hint_to_url($raw);
                }
                if (is_string($url) && $url !== '') {
                    return '[ACTION:IMG:' . $url . ']';
                }

                return $m[0];
            }, $response);
        }

        /**
         * Enriquece [ACTION:BOOK:ID] según motor del proyecto (permalink MEC en Base64 URL-safe; Amelia sin URL).
         */
        private static function resolve_action_book_tags_in_response($response, $project_id) {
            if (!is_string($response) || strpos($response, '[ACTION:BOOK:') === false) {
                return $response;
            }
            if (!class_exists('Xabia_Reservas_Handler', false)) {
                return $response;
            }
            $engine = Xabia_Reservas_Handler::engine_for_project($project_id);
            if ($engine === '') {
                return $response;
            }
            $projects = get_option('xabia_projects_config', []);
            $cfg = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];
            $is_remote_mec = $engine === 'mec'
                && class_exists('Xabia_MEC_Public_Link', false)
                && Xabia_MEC_Public_Link::is_remote_catalog($cfg);

            return preg_replace_callback('/\[ACTION:BOOK:(\d+)\]/', function ($m) use ($engine, $project_id, $cfg, $is_remote_mec) {
                $id = (int) $m[1];
                if ($id < 1) {
                    return $m[0];
                }
                if ($engine === 'mec') {
                    if ($is_remote_mec) {
                        $url = Xabia_MEC_Public_Link::resolve_for_project((string) $project_id, $id);
                        if ($url === '') {
                            return $m[0];
                        }
                        $url = (string) apply_filters('xabia_mec_event_reservation_url', $url, $id);
                        $b64 = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');

                        return '[ACTION:BOOK:mec:' . $id . ':' . $b64 . ']';
                    }
                    if (get_post_type($id) !== 'mec-events') {
                        return $m[0];
                    }
                    if (function_exists('xabia_mec_is_booking_enabled') && !xabia_mec_is_booking_enabled($id)) {
                        return '';
                    }
                    $url = get_permalink($id);
                    if (!is_string($url) || $url === '') {
                        return $m[0];
                    }
                    if (class_exists('Xabia_MEC_Public_Link', false)) {
                        $url = Xabia_MEC_Public_Link::fix_url($url, $cfg);
                    }
                    $url = (string) apply_filters('xabia_mec_event_reservation_url', $url, $id);
                    $b64 = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');

                    return '[ACTION:BOOK:mec:' . $id . ':' . $b64 . ']';
                }
                if ($engine === 'amelia') {
                    return '[ACTION:BOOK:amelia:' . $id . ']';
                }
                return $m[0];
            }, $response);
        }

        /**
         * Corrige [ACTION:URL:…] con enlaces MEC obsoletos (guid / ?p=ID / entidades HTML).
         */
        private static function resolve_action_url_tags_in_response($response, $project_id) {
            if (!is_string($response) || strpos($response, '[ACTION:URL:') === false) {
                return $response;
            }
            if (!class_exists('Xabia_MEC_Public_Link', false)) {
                return $response;
            }
            $projects = get_option('xabia_projects_config', []);
            $cfg = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : null;

            return preg_replace_callback('/\[ACTION:URL:([^\]]+)\]/', static function ($m) use ($cfg) {
                $raw = trim((string) ($m[1] ?? ''));
                if ($raw === '') {
                    return $m[0];
                }
                $fixed = Xabia_MEC_Public_Link::fix_url($raw, $cfg);
                if ($fixed === '' || $fixed === $raw) {
                    return $m[0];
                }

                return '[ACTION:URL:' . $fixed . ']';
            }, $response);
        }

        /**
         * Reescribe https://{agente}/actividades/... → sitio MEC remoto cuando aplica.
         */
        private static function rewrite_mec_remote_hosts_in_response($response, $project_id) {
            if (!is_string($response) || $response === '' || !class_exists('Xabia_MEC_Public_Link', false)) {
                return $response;
            }
            $projects = get_option('xabia_projects_config', []);
            $cfg = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : null;
            $remote = Xabia_MEC_Public_Link::configured_remote_site_url($cfg);
            if ($remote === '') {
                return $response;
            }
            $local_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
            if (!is_string($local_host) || $local_host === '') {
                return $response;
            }
            $rewrite = preg_quote(Xabia_MEC_Public_Link::get_events_rewrite_slug($cfg), '/');
            $host_re = preg_quote($local_host, '/');

            return (string) preg_replace_callback(
                '/https?:\/\/' . $host_re . '(\/' . $rewrite . '\/[^\s\]<"\']+)/i',
                static function ($m) use ($cfg) {
                    $full = (string) ($m[0] ?? '');

                    return Xabia_MEC_Public_Link::fix_url($full, $cfg);
                },
                $response
            );
        }

        private static function reset_digixop_session_counters(): void {
            self::$digixop_session_usage = ['prompt' => 0, 'completion' => 0, 'total' => 0];
            self::$digixop_session_proxy_used = false;
            if (class_exists('Xabia_Digixop_Client')) {
                Xabia_Digixop_Client::reset_session_flags();
            }
        }

        /**
         * @param array<string, int>|null $usage Respuesta usage de OpenAI.
         */
        private static function digixop_note_chat_usage(?array $usage, bool $via_proxy): void {
            if ($usage === null || !is_array($usage)) {
                return;
            }
            if ($via_proxy) {
                self::$digixop_session_proxy_used = true;
            }
            self::$digixop_session_usage['prompt'] += (int) ($usage['prompt_tokens'] ?? 0);
            self::$digixop_session_usage['completion'] += (int) ($usage['completion_tokens'] ?? 0);
            $add = (int) ($usage['total_tokens'] ?? 0);
            if ($add < 1) {
                $add = (int) ($usage['prompt_tokens'] ?? 0) + (int) ($usage['completion_tokens'] ?? 0);
            }
            self::$digixop_session_usage['total'] += $add;
        }

        private static function digixop_report_chat_session_if_needed(string $project_id): void {
            if (!class_exists('Xabia_Digixop_Client') || Xabia_Digixop_Client::was_insufficient_balance()) {
                return;
            }
            if (self::$digixop_session_proxy_used) {
                // Cada mensaje vía proxy ya descontó en el hub; un /usage agregado duplicaba el cargo.
                return;
            }
            // Sin proxy (OpenAI/Vertex locales) esta ruta históricamente no llamaba a /usage.
        }

        public static function digixop_reset_session_for_federation(): void {
            self::reset_digixop_session_counters();
        }

        public static function digixop_report_after_federation_bridge(string $project_id): void {
            self::digixop_report_chat_session_if_needed($project_id);
        }

        /**
         * Informe de tokens al hub con contexto distinto del chat (puente /federate).
         */
        public static function digixop_report_federation_session(string $project_id): void {
            if (!class_exists('Xabia_Digixop_Client') || Xabia_Digixop_Client::was_insufficient_balance()) {
                return;
            }
            if (self::$digixop_session_proxy_used) {
                return;
            }
            // Sin proxy: no se consolidaba federación vía /usage en el flujo anterior.
        }

        public static function digixop_absorb_embedding_for_federation(string $project_id, array $config): void {
            self::digixop_absorb_query_embedding_usage($project_id, $config);
        }

        /**
         * Resumen para el endpoint REST /federate (máx. ~300 palabras + enlaces).
         *
         * @return array{summary:string, links:array<int, array{title:string, url:string}>}
         */
        public static function federation_summarize_context(string $q, string $raw_context, string $project_id, array $config): array {
            $ctx = trim($raw_context);
            if (strlen($ctx) < 20) {
                return [
                    'summary' => __('No hay datos indexados en este proyecto para esa consulta.', 'xabia-intelligence'),
                    'links'   => [],
                ];
            }
            if (strlen($ctx) > 14000) {
                $ctx = substr($ctx, 0, 14000) . '...';
            }
            $sys = 'Eres un resumidor técnico para una API de federación entre sitios WordPress. '
                . 'Produce como máximo 300 palabras de texto útil. Sé fiel al contexto: no inventes hechos. '
                . 'Incluye al final una sección titulada exactamente "Enlaces de interés:" con viñetas (Markdown) solo con URLs que aparezcan en el contexto o en tu resumen.';
            $user = "Consulta remota: {$q}\n\nContexto recuperado del índice local:\n{$ctx}";
            $messages = [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user', 'content' => $user],
            ];
            $out = self::call_openai($messages, 900, 'gpt-4o-mini', 0.15, $project_id, $config);
            $summary = is_string($out) ? $out : '';
            $links = class_exists('Xabia_Federation_Nexus', false)
                ? Xabia_Federation_Nexus::extract_urls_from_text($ctx . "\n" . $summary)
                : [];
            $plain = wp_strip_all_tags($summary);
            $words = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY);
            if (is_array($words) && count($words) > 300) {
                $summary = implode(' ', array_slice($words, 0, 300)) . '…';
            }

            return ['summary' => $summary, 'links' => $links];
        }

        /**
         * Chat OpenAI con function calling (ask_federated_node).
         */
        private static function call_openai_with_federation_tools(array $messages, $max_tokens, $model, $temperature, $project_id, $config) {
            $tools = Xabia_Federation_Nexus::federation_tool_definitions();
            $extra = Xabia_Federation_Nexus::federation_tool_instruction_block();
            if ($extra !== '' && isset($messages[0]) && ($messages[0]['role'] ?? '') === 'system') {
                $messages[0]['content'] = ($messages[0]['content'] ?? '')
                    . "\n\n" . $extra
                    . "\n\n" . __('Herramienta disponible: ask_federated_node. Úsala solo si el contexto local no cubre la pregunta sobre un lugar o servicio que un nodo federado podría conocer mejor.', 'xabia-intelligence');
            }
            $max_steps = 3;
            for ($step = 0; $step < $max_steps; $step++) {
                $r = self::openai_chat_request($messages, $max_tokens, $model, (float) $temperature, $project_id, $config, $tools);
                if (!empty($r['insufficient'])) {
                    return Xabia_Digixop_Client::get_insufficient_balance_user_message();
                }
                if (!empty($r['error'])) {
                    return (string) $r['error'];
                }
                $tool_calls = isset($r['tool_calls']) && is_array($r['tool_calls']) ? $r['tool_calls'] : null;
                if (!empty($tool_calls)) {
                    $assistant_row = [
                        'role'         => 'assistant',
                        'tool_calls'   => $tool_calls,
                    ];
                    if (isset($r['content']) && $r['content'] !== null && $r['content'] !== '') {
                        $assistant_row['content'] = (string) $r['content'];
                    }
                    $messages[] = $assistant_row;
                    foreach ($tool_calls as $tc) {
                        if (!is_array($tc)) {
                            continue;
                        }
                        $fn = $tc['function']['name'] ?? '';
                        $args_raw = $tc['function']['arguments'] ?? '{}';
                        $args = json_decode((string) $args_raw, true);
                        $tid = $tc['id'] ?? '';
                        if ($fn === 'ask_federated_node' && is_array($args)) {
                            $node = sanitize_title((string) ($args['node'] ?? ''));
                            $q = sanitize_text_field((string) ($args['query'] ?? ''));
                            $tool_text = $node !== '' && $q !== ''
                                ? (function_exists('xabia_federation_ask_node')
                                    ? xabia_federation_ask_node($node, $q, $project_id)
                                    : Xabia_Federation_Nexus::ask_federated_node($node, $q, $project_id))
                                : __('Parámetros node o query vacíos en ask_federated_node.', 'xabia-intelligence');
                        } else {
                            $tool_text = __('Función no reconocida.', 'xabia-intelligence');
                        }
                        $messages[] = [
                            'role'         => 'tool',
                            'tool_call_id' => (string) $tid,
                            'content'      => $tool_text,
                        ];
                    }
                    continue;
                }
                return (string) ($r['content'] ?? __('Sin respuesta del modelo.', 'xabia-intelligence'));
            }

            return __('Demasiadas rondas de herramientas federadas.', 'xabia-intelligence');
        }

        /**
         * @param list<array<string, mixed>> $messages
         * @param list<array<string, mixed>>|null $tools
         * @return array{content:?string, tool_calls:?array, error:string, insufficient:bool}
         */
        private static function openai_chat_request(array $messages, int $max_tokens, string $model, float $temperature, string $project_id, array $config, ?array $tools): array {
            self::$last_generation_metrics = null;
            self::$last_generation_finish_reason = '';
            $max_tokens = self::chat_max_tokens($max_tokens);
            $err = '';
            $insufficient = false;
            if (!is_array($config)) {
                $config = [];
            }
            if ($project_id !== '' && empty($config)) {
                $projects = get_option('xabia_projects_config', []);
                $config = $projects[$project_id] ?? [];
            }

            if (class_exists('Xabia_Digixop_Client') && Xabia_Digixop_Client::should_use_openai_proxy($project_id, $config)) {
                $token_limits = self::openai_chat_token_limit_fields($max_tokens);
                $payload = [
                    'model'       => $model,
                    'messages'    => $messages,
                    'temperature' => $temperature,
                    'max_tokens'  => $token_limits['max_tokens'],
                    'max_completion_tokens' => $token_limits['max_completion_tokens'],
                    'user'        => 'xabia_user_' . get_current_user_id(),
                ];
                if ($tools !== null) {
                    $payload['tools'] = $tools;
                    $payload['tool_choice'] = 'auto';
                }
                $ul = isset($config['_xabia_proxy_user_lang']) ? (string) $config['_xabia_proxy_user_lang'] : '';
                if ($ul !== '') {
                    $payload['user_lang'] = $ul;
                }
                $payload = apply_filters('xabia_digixop_proxy_payload', $payload, $project_id, $config, $messages);
                if (!is_array($payload)) {
                    $payload = [
                        'model'       => $model,
                        'messages'    => $messages,
                        'temperature' => $temperature,
                        'max_tokens'  => $token_limits['max_tokens'],
                        'max_completion_tokens' => $token_limits['max_completion_tokens'],
                        'user'        => 'xabia_user_' . get_current_user_id(),
                    ];
                } else {
                    if (!isset($payload['max_tokens']) || (int) $payload['max_tokens'] < 1) {
                        $payload['max_tokens'] = $token_limits['max_tokens'];
                    }
                    if (!isset($payload['max_completion_tokens']) || (int) $payload['max_completion_tokens'] < 1) {
                        $payload['max_completion_tokens'] = $token_limits['max_completion_tokens'];
                    }
                }
                $out = Xabia_Digixop_Client::proxy_openai_post($payload, $project_id, $config);
                if (!empty($out['insufficient_balance'])) {
                    return ['content' => null, 'tool_calls' => null, 'error' => '', 'insufficient' => true];
                }
                $parsed = self::parse_chat_completion_response_full($out['body']);
                if ($parsed !== null) {
                    self::$last_generation_finish_reason = strtolower(trim((string) ($parsed['finish_reason'] ?? '')));
                    self::digixop_note_chat_usage($parsed['usage'], true);
                    $pt = (int) ($parsed['usage']['prompt_tokens'] ?? 0);
                    $ct = (int) ($parsed['usage']['completion_tokens'] ?? 0);
                    self::$last_generation_metrics = [
                        'prompt_tokens' => $pt,
                        'completion_tokens' => $ct,
                        'model' => (string) $model,
                        'estimated_cost' => self::estimate_cost_usd((string) $model, $pt, $ct),
                    ];

                    return [
                        'content'    => $parsed['content'],
                        'tool_calls' => $parsed['tool_calls'],
                        'error'      => '',
                        'insufficient' => false,
                    ];
                }
                if (self::isQuotaOrThrottleError($out)) {
                    $retryTokens = self::chat_max_tokens((int) floor($max_tokens / 2));
                    if ($retryTokens < $max_tokens) {
                        usleep(500000);
                        $payload['max_tokens'] = $retryTokens;
                        $retry = Xabia_Digixop_Client::proxy_openai_post($payload, $project_id, $config);
                        $retryParsed = self::parse_chat_completion_response_full($retry['body'] ?? null);
                        if ($retryParsed !== null) {
                            self::$last_generation_finish_reason = (string) ($retryParsed['finish_reason'] ?? '');
                            self::digixop_note_chat_usage($retryParsed['usage'], true);
                            $pt = (int) ($retryParsed['usage']['prompt_tokens'] ?? 0);
                            $ct = (int) ($retryParsed['usage']['completion_tokens'] ?? 0);
                            self::$last_generation_metrics = [
                                'prompt_tokens' => $pt,
                                'completion_tokens' => $ct,
                                'model' => (string) $model,
                                'estimated_cost' => self::estimate_cost_usd((string) $model, $pt, $ct),
                            ];

                            return [
                                'content'    => $retryParsed['content'],
                                'tool_calls' => $retryParsed['tool_calls'],
                                'error'      => '',
                                'insufficient' => false,
                            ];
                        }
                        error_log('[XABIA_CORE] Proxy retry failed after 429. Code=' . (int) ($retry['code'] ?? 0) . ' Body=' . substr(wp_json_encode($retry['body']), 0, 1200));
                    }

                    return [
                        'content' => null,
                        'tool_calls' => null,
                        'error' => self::friendlyTemporaryFailureMessage(),
                        'insufficient' => false,
                    ];
                }
                if (is_array($out['body']) && isset($out['body']['error']['message'])) {
                    return ['content' => null, 'tool_calls' => null, 'error' => 'Error API (proxy): ' . (string) $out['body']['error']['message'], 'insufficient' => false];
                }

                return ['content' => null, 'tool_calls' => null, 'error' => 'Error de respuesta (proxy Xabia). Código HTTP ' . (int) $out['code'] . '.', 'insufficient' => false];
            }

            $api_key = Xabia_Digixop_Client::get_effective_openai_key($project_id, $config);
            if ($api_key === '') {
                return [
                    'content'    => null,
                    'tool_calls' => null,
                    'error'      => __('Error: Falta la API Key de OpenAI (global o del agente) o una licencia Xabia activa.', 'xabia-intelligence'),
                    'insufficient' => false,
                ];
            }

            $token_limits = self::openai_chat_token_limit_fields($max_tokens);
            $body_arr = [
                'model'       => $model,
                'messages'    => $messages,
                'temperature' => $temperature,
                'user'        => 'xabia_user_' . get_current_user_id(),
            ];
            // Algunos modelos rechazan enviar max_tokens y max_completion_tokens a la vez.
            if (preg_match('/^(o1|o3|o4|gpt-5)/i', (string) $model)) {
                $body_arr['max_completion_tokens'] = $token_limits['max_completion_tokens'];
            } else {
                $body_arr['max_tokens'] = $token_limits['max_tokens'];
            }
            if ($tools !== null) {
                $body_arr['tools'] = $tools;
                $body_arr['tool_choice'] = 'auto';
            }
            $body = json_encode($body_arr);
            if ($body === false) {
                return ['content' => null, 'tool_calls' => null, 'error' => 'Error: no se pudo preparar la petición (encoding).', 'insufficient' => false];
            }

            $args = [
                'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
                'body'    => $body,
                'timeout' => 60,
            ];
            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', $args);
            if (is_wp_error($response)) {
                return ['content' => null, 'tool_calls' => null, 'error' => 'Error conexión: ' . $response->get_error_message(), 'insufficient' => false];
            }
            $httpCode = (int) wp_remote_retrieve_response_code($response);
            $raw_body = (string) wp_remote_retrieve_body($response);
            $decoded = json_decode($raw_body, true);
            if ($httpCode === 429) {
                $retryMax = self::chat_max_tokens((int) floor($max_tokens / 2));
                usleep(500000);
                $body_arr['max_tokens'] = $retryMax;
                if (isset($body_arr['max_completion_tokens'])) {
                    $body_arr['max_completion_tokens'] = $retryMax;
                }
                $retryBody = json_encode($body_arr);
                if ($retryBody !== false) {
                    $retryResponse = wp_remote_post('https://api.openai.com/v1/chat/completions', [
                        'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
                        'body'    => $retryBody,
                        'timeout' => 60,
                    ]);
                    if (!is_wp_error($retryResponse)) {
                        $raw_body = (string) wp_remote_retrieve_body($retryResponse);
                        $decoded = json_decode($raw_body, true);
                        $httpCode = (int) wp_remote_retrieve_response_code($retryResponse);
                    } else {
                        error_log('[XABIA_CORE] OpenAI retry failed after 429: ' . $retryResponse->get_error_message());
                    }
                }
            }
            if (is_array($decoded) && isset($decoded['error']['message'])) {
                $api_err = (string) $decoded['error']['message'];
                error_log('[XABIA_CORE] OpenAI API error http=' . $httpCode . ' msg=' . substr($api_err, 0, 400));
                return ['content' => null, 'tool_calls' => null, 'error' => 'Error API: ' . $api_err, 'insufficient' => false];
            }
            if (is_array($decoded) && isset($decoded['usage'])) {
                self::digixop_note_chat_usage($decoded['usage'], false);
            }
            $parsed = is_array($decoded) ? self::parse_chat_completion_response_full($decoded) : null;
            if ($parsed !== null && (($parsed['content'] ?? null) !== null || !empty($parsed['tool_calls']))) {
                self::$last_generation_finish_reason = (string) ($parsed['finish_reason'] ?? '');
                $pt = (int) ($parsed['usage']['prompt_tokens'] ?? 0);
                $ct = (int) ($parsed['usage']['completion_tokens'] ?? 0);
                self::$last_generation_metrics = [
                    'prompt_tokens' => $pt,
                    'completion_tokens' => $ct,
                    'model' => (string) $model,
                    'estimated_cost' => self::estimate_cost_usd((string) $model, $pt, $ct),
                ];
                return [
                    'content'    => $parsed['content'],
                    'tool_calls' => $parsed['tool_calls'],
                    'error'      => '',
                    'insufficient' => false,
                ];
            }

            error_log('[XABIA_CORE] OpenAI unparseable http=' . $httpCode . ' body=' . substr($raw_body, 0, 500));
            return ['content' => null, 'tool_calls' => null, 'error' => 'Error de respuesta.', 'insufficient' => false];
        }

        /**
         * @param array<string, mixed> $out
         */
        private static function isQuotaOrThrottleError(array $out): bool {
            $code = (int) ($out['code'] ?? 0);
            if ($code === 429) {
                return true;
            }
            $msg = '';
            if (is_array($out['body']) && isset($out['body']['error']['message']) && is_string($out['body']['error']['message'])) {
                $msg = strtolower($out['body']['error']['message']);
            }

            return str_contains($msg, 'resource exhausted')
                || str_contains($msg, 'error-code-429')
                || str_contains($msg, 'quota')
                || str_contains($msg, 'too many requests');
        }

        private static function friendlyTemporaryFailureMessage(): string {
            return '¡Ups! Parece que mi conexión está un poco lenta en este momento debido a que mucha gente me está consultando. Por favor, espera un segundito y vuelve a preguntarme, que estaré encantada de atenderte. Mila esker por tu paciencia. ✨';
        }

        private static function responseLooksLikeTechnicalFailure(string $response): bool {
            $t = strtolower(trim($response));
            if ($t === '') {
                return true;
            }
            if ($response === self::friendlyTemporaryFailureMessage()) {
                return true;
            }
            return str_contains($t, 'error api')
                || str_contains($t, 'error de respuesta')
                || str_contains($t, 'error conexión')
                || str_contains($t, 'falta la api key')
                || str_starts_with($t, 'error ');
        }

        /**
         * Misma voz que el add-on Avirato: sin mencionar licencias ni errores técnicos al visitante.
         */
        private static function aviratoOrLicensePublicFallbackForUser(): string {
            if (function_exists('xabia_avirato_public_availability_fallback_message')) {
                return xabia_avirato_public_availability_fallback_message();
            }

            return (string) apply_filters(
                'xabia_avirato_inactive_availability_message',
                __('Puedo informarte sobre nuestras casas, aunque para ver calendarios exactos te recomiendo contactarnos directamente.', 'xabia-intelligence')
            );
        }

        /**
         * Detecta respuestas que no deben mostrarse al usuario final (licencia, add-on, Avirato técnico).
         *
         * @param string $t Mensaje en minúsculas
         */
        private static function responseIsAviratoOrLicenseLeak(string $t): bool {
            if ($t === '') {
                return false;
            }
            if (str_contains($t, 'missing_avirato')
                || str_contains($t, 'avirato_missing')
                || str_contains($t, 'configuración de avirato')
                || str_contains($t, 'configuracion de avirato')
                || str_contains($t, 'módulo de disponibilidad')
                || str_contains($t, 'modulo de disponibilidad')
                || str_contains($t, 'módulo avirato')
                || str_contains($t, 'modulo avirato')
                || str_contains($t, 'el módulo avirato')
                || str_contains($t, 'el modulo avirato')
                || str_contains($t, 'no está activo para esta licencia')
                || str_contains($t, 'no esta activo para esta licencia')
                || str_contains($t, 'avirato no está activo')
                || str_contains($t, 'avirato no esta activo')) {
                return true;
            }
            if (str_contains($t, 'avirato')
                && (str_contains($t, 'licencia')
                    || str_contains($t, 'license')
                    || str_contains($t, 'add-on')
                    || str_contains($t, 'addon')
                    || str_contains($t, 'suscripci')
                    || str_contains($t, 'no está activo')
                    || str_contains($t, 'no esta activo'))) {
                return true;
            }
            if ((str_contains($t, 'error api') || str_contains($t, 'proxy')) && str_contains($t, 'avirato')) {
                return true;
            }

            return str_contains($t, 'polar.sh')
                && (str_contains($t, 'error') || str_contains($t, 'licen'));
        }

        /**
         * Intercepta errores técnicos antes de enviarlos al frontend.
         *
         * @param mixed $response
         */
        private static function sanitizeTechnicalFailureForUser($response): string {
            if (!is_string($response) || trim($response) === '') {
                error_log('[XABIA_CORE] Technical empty/non-string response intercepted.');
                return self::friendlyTemporaryFailureMessage();
            }
            $raw = trim($response);
            $t = strtolower($raw);
            if (self::responseIsAviratoOrLicenseLeak($t)) {
                error_log('[XABIA_CORE] Avirato/license content suppressed for visitor (detail in log only): ' . substr($raw, 0, 900));

                return self::aviratoOrLicensePublicFallbackForUser();
            }
            $isTechnical = str_contains($t, 'resource exhausted')
                || str_contains($t, 'rate limit')
                || str_contains($t, '429')
                || str_contains($t, 'cloudflare')
                || str_contains($t, 'error api')
                || str_contains($t, 'error de respuesta')
                || str_contains($t, 'error conexión')
                || str_contains($t, 'service unavailable')
                || str_contains($t, 'bad gateway')
                || str_contains($t, 'gateway timeout')
                || str_contains($t, 'temporarily unavailable')
                || str_contains($t, 'no está activo para esta licencia')
                || str_contains($t, 'módulo de disponibilidad')
                || str_contains($t, 'modulo de disponibilidad')
                || str_contains($t, 'suscripci')
                || str_contains($t, 'add-on')
                || str_contains($t, 'license key')
                || str_contains($t, 'clave de licencia')
                || str_contains($t, 'polar.sh')
                || str_starts_with($t, 'error ');
            if ($isTechnical) {
                error_log('[XABIA_CORE] Technical message intercepted for frontend: ' . substr($raw, 0, 1200));
                return self::friendlyTemporaryFailureMessage();
            }

            return $raw;
        }

        /**
         * @param array<string, mixed>|null $json
         * @return array{content:?string, tool_calls:?array, usage:array<string, int>|null, finish_reason:string}|null
         */
        private static function parse_chat_completion_response_full(?array $json): ?array {
            if ($json === null) {
                return null;
            }
            $choice = $json['choices'][0] ?? null;
            if (!is_array($choice)) {
                return null;
            }
            $msg = $choice['message'] ?? null;
            if (!is_array($msg)) {
                return null;
            }
            $content = $msg['content'] ?? null;
            if (is_array($content)) {
                $parts = [];
                foreach ($content as $part) {
                    if (is_string($part)) {
                        $parts[] = $part;
                    } elseif (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                        $parts[] = $part['text'];
                    }
                }
                $content = $parts !== [] ? implode('', $parts) : null;
            } elseif ($content !== null && !is_string($content)) {
                $content = null;
            }
            $tool_calls = $msg['tool_calls'] ?? null;
            if (!is_array($tool_calls) || $tool_calls === []) {
                $tool_calls = null;
            }
            $usage = isset($json['usage']) && is_array($json['usage']) ? $json['usage'] : null;
            $finish_reason = isset($choice['finish_reason']) ? (string) $choice['finish_reason'] : '';

            return ['content' => $content, 'tool_calls' => $tool_calls, 'usage' => $usage, 'finish_reason' => $finish_reason];
        }

        /**
         * Convierte negritas Markdown (**texto**) a <strong> para el widget y el playground.
         */
        private static function format_chat_markdown_for_display(string $text): string
        {
            if ($text === '' || strpos($text, '**') === false) {
                return $text;
            }
            if (stripos($text, '<strong>') !== false) {
                return $text;
            }

            $out = (string) preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $text);
            if (substr_count($out, '**') % 2 !== 0) {
                $out = (string) preg_replace('/\*\*[^*]*$/u', '', $out);
            }

            return rtrim($out);
        }

        /**
         * Trunca por tamaño sin borrar refuerzos RAG al final (substr(0,N) cortaba el bloque ecuestre).
         */
        private static function truncate_chat_rag_context(string $context, int $limit): string
        {
            if (strlen($context) <= $limit) {
                return $context;
            }
            $cut = '... [CORTADO POR LÍMITE]';
            $markers = [
                '### Búsqueda por palabras clave (refuerzo) ###',
            ];
            foreach ($markers as $m) {
                $pos = strpos($context, $m);
                if ($pos !== false) {
                    $suffix = substr($context, $pos);
                    $reserve = strlen($suffix) + strlen($cut) + 2;
                    if ($reserve >= $limit) {
                        return substr($suffix, 0, max(0, $limit - strlen($cut))) . $cut;
                    }
                    $head = substr($context, 0, $pos);
                    $headMax = $limit - $reserve;
                    if (strlen($head) <= $headMax) {
                        return rtrim($head) . "\n\n" . $suffix;
                    }

                    return rtrim(substr($head, 0, $headMax)) . $cut . "\n\n" . $suffix;
                }
            }

            return substr($context, 0, $limit) . $cut;
        }

        /**
         * Quita anexos estructurales «---» + líneas «- Label: value» del contexto en modo listado.
         * Agnóstico: no depende de textos de dominio ni de un cliente concreto.
         */
        private static function strip_mapped_attributes_annex_from_context(string $context): string
        {
            if ($context === '' || strpos($context, '---') === false) {
                return $context;
            }
            // Bloque: --- [título opcional] + líneas de viñeta "- …"
            $stripped = preg_replace(
                '/\n---\s*\n(?:[^\n-][^\n]*:\s*\n)?(?:-[^\n]*\n?)*/u',
                "\n",
                $context
            );

            return is_string($stripped) ? trim($stripped) : $context;
        }

        /** @deprecated Enrutamiento delegado al LLM; conservado por compatibilidad de API pública. */
        public static function query_implies_catalog_listing(string $text): bool
        {
            unset($text);

            return false;
        }

        /**
         * El usuario pide profundizar en un ítem concreto (no un listado comparativo).
         */
        public static function query_implies_single_item_depth(string $text): bool
        {
            if (self::resolve_named_entity_from_user_message($text) !== '') {
                return true;
            }
            $q = mb_strtolower(trim(wp_strip_all_tags((string) $text)), 'UTF-8');
            if ($q === '') {
                return false;
            }
            if (self::query_references_prior_entity($text)) {
                return true;
            }

            return (bool) preg_match(
                '/\b(cu[eé]ntame|m[aá]s\s+(informaci[oó]n|detalles?|sobre)|profundiza|detalla|esta\s+empresa|ese\s+alojamiento|esa\s+empresa|sobre\s+ell[ao])\b/u',
                $q
            );
        }

        /**
         * El usuario se refiere a una entidad ya mencionada (ellos, esa empresa, más de ellos…).
         */
        public static function query_references_prior_entity(string $text): bool
        {
            $q = mb_strtolower(trim(wp_strip_all_tags((string) $text)), 'UTF-8');
            if ($q === '') {
                return false;
            }

            return (bool) preg_match(
                '/\b(ellos|ellas)\b'
                . '|\b(este|esta|ese|esa|esto|aquell[oa])\s+(empresa|club|alojamiento|opci[oó]n)?\b'
                . '|\b(su|sus)\s+(contacto|tel[eé]fono|email|correo|web|foto|fotos|im[aá]gen|im[aá]genes)\b'
                . '|\b(una|alguna|algunas|tienes|tienen|hay)\s+(foto|fotos|im[aá]gen|im[aá]genes)\b'
                . '|\b(y\s+)?(foto|fotos|im[aá]gen|im[aá]genes)\s*\??$'
                . '|\b(el|la|su|sus|una|alguna)\s+(tel[eé]fono|contacto|email|correo|web|foto|fotos|im[aá]gen|im[aá]genes)\s*\??$'
                . '|\b(h[aá]bl(a|ame|arme)|cu[eé]nta(me)?|informaci[oó]n)\b[^.?!]{0,40}\b(ellos|ellas|eso|esta|ese)\b'
                . '|\b(m[aá]s\s+de\s+(ellos|ellas|eso|esta|ese))\b'
                . '|\b(sobre\s+ellos|de\s+ellos)\b/u',
                $q
            );
        }

        /**
         * Disponibilidad, reservas y calendario no son listados informativos de catálogo.
         */
        public static function query_implies_availability_or_booking_intent(string $text): bool
        {
            $q = mb_strtolower(trim(wp_strip_all_tags((string) $text)), 'UTF-8');
            if ($q === '') {
                return false;
            }
            $needles = apply_filters('xabia_native_catalog_availability_keywords', [
                'disponibilidad',
                'disponible',
                'libre',
                'libres',
                'fecha',
                'fechas',
                'calendario',
                'reserva',
                'reservar',
                'reservas',
                'ocupado',
            ], $text);
            if (!is_array($needles)) {
                return false;
            }
            foreach ($needles as $word) {
                $word = mb_strtolower(trim((string) $word), 'UTF-8');
                if ($word === '') {
                    continue;
                }
                if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $q)) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Foto, contacto, teléfono, web… no son listados de catálogo.
         */
        public static function query_implies_entity_utility_request(string $text): bool
        {
            $q = mb_strtolower(trim(wp_strip_all_tags((string) $text)), 'UTF-8');
            if ($q === '') {
                return false;
            }

            return (bool) preg_match(
                '/\b(foto|fotos|im[aá]gen|imagenes|imágenes|picture|pictures|contacto|tel[eé]fono|telefono|email|correo|e-?mail|web|p[aá]gina\s+web|whatsapp|llamar|llamad)\b/u',
                $q
            );
        }

        /**
         * Reconstruye el manifiesto de listado desde el historial POST (sesión PHP a menudo no persiste).
         *
         * @param list<array{role?: string, content?: string}> $history
         *
         * @return list<string>
         */
        public static function parse_catalog_manifest_lines_from_history(array $history): array
        {
            for ($i = count($history) - 1; $i >= 0; --$i) {
                if (!is_array($history[$i]) || ($history[$i]['role'] ?? '') !== 'assistant') {
                    continue;
                }
                $plain = html_entity_decode(trim(wp_strip_all_tags((string) ($history[$i]['content'] ?? ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($plain === '' || !preg_match('/^\s*•\s+/m', $plain)) {
                    continue;
                }
                $lines = [];
                foreach (preg_split('/\R/u', $plain) ?: [] as $row) {
                    $row = trim((string) $row);
                    if ($row === '' || !preg_match('/^\s*•\s+/u', $row)) {
                        continue;
                    }
                    $row = trim((string) preg_replace('/^\s*•\s+/u', '', $row));
                    $row = rtrim($row, '.');
                    if ($row === '') {
                        continue;
                    }
                    if (preg_match('/^\*\*([^*]+)\*\*\s*(.*)$/u', $row, $m)) {
                        $name = trim((string) $m[1]);
                        $tail = trim((string) ($m[2] ?? ''));
                        if ($name !== '') {
                            $lines[] = $tail !== '' && $tail[0] === '('
                                ? ('**' . $name . '** ' . $tail)
                                : ('**' . $name . '**' . ($tail !== '' ? ' (' . $tail . ')' : ''));
                        }
                        continue;
                    }
                    if (preg_match('/^(.+?)\s*\(([^)]+)\)\s*$/u', $row, $m)) {
                        $name = trim((string) $m[1]);
                        $loc = trim((string) $m[2]);
                        if ($name !== '') {
                            $lines[] = '**' . $name . '** (' . $loc . ')';
                        }
                        continue;
                    }
                    if (preg_match('/^(.+?)\s*[—–-]\s*(.+)$/u', $row, $m)) {
                        $name = trim((string) $m[1]);
                        $suffix = trim((string) $m[2]);
                        if ($name !== '') {
                            $lines[] = $suffix !== ''
                                ? ('**' . $name . '** — ' . $suffix)
                                : ('**' . $name . '**');
                        }
                    }
                }
                if (count($lines) >= 3) {
                    return $lines;
                }
            }

            return [];
        }

        /**
         * @param list<array{role?: string, content?: string}> $history
         */
        public static function ensure_catalog_manifest_from_history(string $project_id, array $history): void
        {
            if ($project_id === '' || $history === []) {
                return;
            }
            $lines = self::parse_catalog_manifest_lines_from_history($history);
            if ($lines === []) {
                return;
            }
            if (!session_id() && !headers_sent()) {
                session_start();
            }
            $stored = $_SESSION['xabia_catalog_manifest'][$project_id] ?? null;
            $existing = is_array($stored) && !empty($stored['manifest']) && is_array($stored['manifest'])
                ? $stored['manifest']
                : [];
            if (count($lines) >= count($existing)) {
                $_SESSION['xabia_catalog_manifest'][$project_id] = [
                    'activity' => is_array($stored) ? (string) ($stored['activity'] ?? '') : '',
                    'manifest' => $lines,
                ];
            }
            session_write_close();
        }

        /**
         * Líneas del manifiesto (sesión o historial POST del chatbox).
         *
         * @param list<array{role?: string, content?: string}> $history
         *
         * @return list<string>
         */
        public static function catalog_manifest_lines(string $project_id, array $history = []): array
        {
            if ($project_id === '') {
                return self::parse_catalog_manifest_lines_from_history($history);
            }
            if (!session_id() && !headers_sent()) {
                session_start();
            }
            $manifest = [];
            $stored = $_SESSION['xabia_catalog_manifest'][$project_id] ?? null;
            if (is_array($stored) && !empty($stored['manifest']) && is_array($stored['manifest'])) {
                $manifest = $stored['manifest'];
            }
            session_write_close();
            if ($manifest !== []) {
                return $manifest;
            }

            return self::parse_catalog_manifest_lines_from_history($history);
        }

        /**
         * Nombres de empresa del manifiesto de listado nativo reciente (sesión + historial POST).
         *
         * @param list<array{role?: string, content?: string}> $history
         *
         * @return list<string>
         */
        public static function catalog_manifest_entity_names(string $project_id, array $history = []): array
        {
            $names = [];
            foreach (self::catalog_manifest_lines($project_id, $history) as $line) {
                if (!preg_match('/\*\*([^*]+)\*\*/u', (string) $line, $m)) {
                    continue;
                }
                $name = trim((string) $m[1]);
                if ($name !== '') {
                    $names[] = $name;
                }
            }

            return $names;
        }

        /**
         * «La última», «la úlrima», «primera empresa»… frente al manifiesto reciente.
         */
        public static function is_manifest_ordinal_phrase(string $text): bool
        {
            $t = self::normalize_ordinal_reference_text($text);
            if ($t === '') {
                return false;
            }

            return (bool) preg_match(
                '/^(?:la|el|los|las|una|alguna)?\s*(?:empresa\s+)?(primera?|1ª|1a|segunda|tercera|cuarta|quinta|sexta|s[eé]ptima|octava|novena|d[eé]cima|penúltima|penultima|última?|ultimo|último)\s*[?¿!.]*$/u',
                $t
            ) || (bool) preg_match(
                '/\b(la|el|una|alguna)\s+(primera?|segunda|tercera|cuarta|quinta|sexta|s[eé]ptima|octava|novena|d[eé]cima|penúltima|penultima|última?|ultimo|último)\b/u',
                $t
            );
        }

        public static function normalize_ordinal_reference_text(string $text): string
        {
            $t = mb_strtolower(trim(wp_strip_all_tags((string) $text)), 'UTF-8');
            if ($t === '') {
                return '';
            }
            $t = (string) preg_replace('/\búlrima\b/u', 'última', $t);
            $t = (string) preg_replace('/\bulrima\b/u', 'ultima', $t);
            $t = (string) preg_replace('/\bulimo\b/u', 'ultimo', $t);
            $t = (string) preg_replace('/\bultima\b/u', 'última', $t);
            $t = (string) preg_replace('/\bultimo\b/u', 'último', $t);
            $t = (string) preg_replace('/\s+/u', ' ', $t);

            return trim($t);
        }

        /**
         * Resuelve referencias posicionales («la última», «la 3ª») contra el manifiesto de sesión.
         *
         * @param list<array{role?: string, content?: string}> $history
         */
        public static function resolve_entity_ordinal_from_manifest(string $user_msg, string $project_id, array $history = []): string
        {
            $t = self::normalize_ordinal_reference_text($user_msg);
            if ($t === '' || $project_id === '') {
                return '';
            }
            if (!preg_match(
                '/\b(primera?|1ª|1a|segunda|tercera|cuarta|quinta|sexta|s[eé]ptima|octava|novena|d[eé]cima|penúltima|penultima|última?|ultimo|último)\b/u',
                $t
            )) {
                return '';
            }
            $names = self::catalog_manifest_entity_names($project_id, $history);
            $n = count($names);
            if ($n === 0) {
                return '';
            }
            $index = null;
            if (preg_match('/\b(primera?|1ª|1a|primero)\b/u', $t)) {
                $index = 0;
            } elseif (preg_match('/\b(penúltima|penultima)\b/u', $t)) {
                $index = max(0, $n - 2);
            } elseif (preg_match('/\b(última?|ultimo|último)\b/u', $t)) {
                $index = $n - 1;
            } elseif (preg_match('/\bsegunda\b/u', $t)) {
                $index = min(1, $n - 1);
            } elseif (preg_match('/\btercera\b/u', $t)) {
                $index = min(2, $n - 1);
            } elseif (preg_match('/\bcuarta\b/u', $t)) {
                $index = min(3, $n - 1);
            } elseif (preg_match('/\bquinta\b/u', $t)) {
                $index = min(4, $n - 1);
            } elseif (preg_match('/\b(?:n[uú]mero?\s+)?(\d{1,2})\b/u', $t, $m)) {
                $num = (int) ($m[1] ?? 0);
                if ($num >= 1 && $num <= $n) {
                    $index = $num - 1;
                }
            }
            if ($index === null || !isset($names[$index])) {
                return '';
            }

            return $names[$index];
        }

        /**
         * Ordinal, nombre parcial o frase «contacto de la última» → empresa del manifiesto.
         *
         * @param list<array{role?: string, content?: string}> $history
         */
        public static function resolve_manifest_entity_reference(string $user_msg, string $project_id, string $named_candidate = '', array $history = []): string
        {
            $ordinal = self::resolve_entity_ordinal_from_manifest($user_msg, $project_id, $history);
            if ($ordinal !== '') {
                return $ordinal;
            }
            if ($named_candidate !== '' && self::is_manifest_ordinal_phrase($named_candidate)) {
                $ordinal = self::resolve_entity_ordinal_from_manifest($named_candidate, $project_id, $history);
                if ($ordinal !== '') {
                    return $ordinal;
                }
            }

            return self::resolve_entity_from_catalog_manifest($user_msg, $project_id, $history);
        }

        /**
         * Evita persistir «la última» como entidad RAG cuando el manifiesto sí la resuelve.
         *
         * @param list<array{role?: string, content?: string}> $history
         */
        public static function sanitize_persisted_entity_reference(string $entity, string $project_id, array $history = []): string
        {
            $entity = trim(self::sanitize_rag_search_term($entity));
            if ($entity === '') {
                return '';
            }
            if (self::is_manifest_ordinal_phrase($entity)) {
                $resolved = self::resolve_entity_ordinal_from_manifest($entity, $project_id, $history);

                return $resolved !== '' ? $resolved : '';
            }

            return $entity;
        }

        /**
         * Empresa citada en el mensaje frente al manifiesto de listado nativo reciente.
         *
         * @param list<array{role?: string, content?: string}> $history
         */
        public static function resolve_entity_from_catalog_manifest(string $user_msg, string $project_id, array $history = []): string
        {
            $blob = mb_strtolower(trim(wp_strip_all_tags($user_msg)), 'UTF-8');
            if ($blob === '' || $project_id === '') {
                return '';
            }
            $ordinal = self::resolve_entity_ordinal_from_manifest($user_msg, $project_id, $history);
            if ($ordinal !== '') {
                return $ordinal;
            }
            $best = '';
            $best_len = 0;
            foreach (self::catalog_manifest_entity_names($project_id, $history) as $name) {
                $slug = sanitize_title($name);
                $name_l = mb_strtolower($name, 'UTF-8');
                if ($slug !== '' && (mb_strpos($blob, $slug) !== false || mb_strpos($blob, str_replace('-', ' ', $slug)) !== false)) {
                    if (mb_strlen($name, 'UTF-8') > $best_len) {
                        $best = $name;
                        $best_len = mb_strlen($name, 'UTF-8');
                    }
                    continue;
                }
                if (mb_strlen($name_l, 'UTF-8') >= 4 && mb_strpos($blob, $name_l) !== false) {
                    if (mb_strlen($name, 'UTF-8') > $best_len) {
                        $best = $name;
                        $best_len = mb_strlen($name, 'UTF-8');
                    }
                }
            }

            return $best;
        }

        /**
         * Entidad citada en turnos anteriores (historial POST + sesión).
         *
         * @param list<array{role?: string, content?: string}> $history
         */
        public static function resolve_entity_from_conversation_history(array $history, string $project_id): string
        {
            for ($i = count($history) - 1; $i >= 0; --$i) {
                if (!is_array($history[$i]) || ($history[$i]['role'] ?? '') !== 'user') {
                    continue;
                }
                $content = trim((string) ($history[$i]['content'] ?? ''));
                if ($content === '') {
                    continue;
                }
                $named = self::resolve_named_entity_from_user_message($content);
                if ($named !== '') {
                    $manifest_named = self::resolve_manifest_entity_reference($content, $project_id, $named, $history);
                    if ($manifest_named !== '') {
                        return $manifest_named;
                    }
                    if (!self::is_manifest_ordinal_phrase($named)) {
                        return $named;
                    }
                }
                $manifest_named = self::resolve_manifest_entity_reference($content, $project_id, '', $history);
                if ($manifest_named !== '') {
                    return $manifest_named;
                }
                if ($project_id !== '') {
                    $named = self::resolve_entity_from_catalog_manifest($content, $project_id, $history);
                    if ($named !== '') {
                        return $named;
                    }
                }
            }
            for ($i = count($history) - 1; $i >= 0; --$i) {
                if (!is_array($history[$i]) || ($history[$i]['role'] ?? '') !== 'assistant') {
                    continue;
                }
                $name = self::extract_primary_entity_name_from_assistant_text((string) ($history[$i]['content'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }

            return '';
        }

        /**
         * @param list<array{role?: string, content?: string}> $history
         */
        public static function resolve_entity_anchor_from_history(string $user_msg, array $history, string $last_entity = '', string $project_id = ''): string
        {
            if (self::resolve_named_entity_from_user_message($user_msg) !== '') {
                return '';
            }
            if (self::query_implies_entity_utility_request($user_msg) || self::query_references_prior_entity($user_msg)) {
                if ($last_entity !== '') {
                    return $last_entity;
                }
                if ($project_id !== '') {
                    $from_hist = self::resolve_entity_from_conversation_history($history, $project_id);
                    if ($from_hist !== '') {
                        return $from_hist;
                    }
                }
            }
            if (!self::query_references_prior_entity($user_msg)) {
                return '';
            }
            if ($last_entity !== '') {
                return $last_entity;
            }
            for ($i = count($history) - 1; $i >= 0; --$i) {
                if (!is_array($history[$i]) || ($history[$i]['role'] ?? '') !== 'assistant') {
                    continue;
                }
                $name = self::extract_primary_entity_name_from_assistant_text((string) ($history[$i]['content'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
                break;
            }

            return '';
        }

        public static function extract_primary_entity_name_from_assistant_text(string $text): string
        {
            $plain = trim(wp_strip_all_tags((string) $text));
            if ($plain === '') {
                return '';
            }
            if (preg_match('/\bempresa\s*:\s*([^|\n]+)/iu', $plain, $m)) {
                $name = trim($m[1]);
                if ($name !== '') {
                    return $name;
                }
            }
            if (preg_match('/\bdatos de contacto de\s+([^:\n*]+)/iu', $plain, $m)) {
                $name = trim((string) preg_replace('/[?¿!.,;:]+$/u', '', trim($m[1])));
                if (mb_strlen($name, 'UTF-8') >= 3 && mb_strlen($name, 'UTF-8') <= 120) {
                    return $name;
                }
            }
            if (preg_match('/\b(?:buscar|busca|encuentra|localiza)\s+[«""\']([^«""\'\n]{3,120})[«""\']/iu', $plain, $m)) {
                $name = trim((string) preg_replace('/[?¿!.,;:]+$/u', '', trim($m[1])));
                if (mb_strlen($name, 'UTF-8') >= 3 && mb_strlen($name, 'UTF-8') <= 120) {
                    return $name;
                }
            }
            if (preg_match('/\b"([^"\n]{3,120})"/u', $plain, $m)) {
                $name = trim((string) preg_replace('/[?¿!.,;:]+$/u', '', trim($m[1])));
                if (mb_strlen($name, 'UTF-8') >= 3 && mb_strlen($name, 'UTF-8') <= 120
                    && !self::entity_name_extract_candidate_rejected($name)) {
                    return $name;
                }
            }
            if (preg_match('/\bcontacto de\s+([^:\n*]+)/iu', $plain, $m)) {
                $name = trim((string) preg_replace('/[?¿!.,;:]+$/u', '', trim($m[1])));
                if (mb_strlen($name, 'UTF-8') >= 3 && mb_strlen($name, 'UTF-8') <= 120) {
                    return $name;
                }
            }
            if (preg_match('/\brecomiendo\s+(?:el|la|los|las)\s+([^,\.\n]+)/iu', $plain, $m)) {
                $name = trim(preg_replace('/\s+/', ' ', $m[1]));
                if (mb_strlen($name) >= 3 && mb_strlen($name) <= 120) {
                    return $name;
                }
            }
            if (preg_match('/\bte\s+sugiero\s+(?:el|la|los|las)\s+([^,\.\n]+)/iu', $plain, $m)) {
                $name = trim(preg_replace('/\s+/', ' ', $m[1]));
                if (mb_strlen($name) >= 3 && mb_strlen($name) <= 120) {
                    return $name;
                }
            }
            if (preg_match('/\*\*([^*]+)\*\*/u', $plain, $m) || preg_match('/\*([^*\n]+)\*/u', $plain, $m)) {
                $name = trim($m[1]);
                if (mb_strlen($name) >= 3 && mb_strlen($name) <= 120) {
                    return $name;
                }
            }

            return '';
        }

        /**
         * Rechaza candidatos a nombre de ente que son URLs, dominios o ruido genérico (extensible por filtro).
         */
        private static function entity_name_extract_candidate_rejected(string $name): bool
        {
            $name = trim($name);
            if ($name === '') {
                return true;
            }
            if (preg_match('#\b(?:https?://|www\.)#iu', $name)) {
                return true;
            }
            if (preg_match('/@/u', $name)) {
                return true;
            }
            $patterns = apply_filters('xabia_entity_name_reject_patterns', [
                '/^\s*(?:web|http|https|www)\s*$/iu',
            ], $name);
            if (!is_array($patterns)) {
                return false;
            }
            foreach ($patterns as $pattern) {
                if (is_string($pattern) && $pattern !== '' && @preg_match($pattern, $name) === 1) {
                    return true;
                }
            }

            return false;
        }

        /**
         * «Háblame de Marmitako Selling» / chips «¿Qué me puedes contar sobre «Título»?» → término RAG exacto.
         */
        public static function resolve_named_entity_from_user_message(string $user_msg): string
        {
            $plain = trim(wp_strip_all_tags((string) $user_msg));
            if ($plain === '') {
                return '';
            }
            // Prioridad: título entre comillas tipográficas (chips de starter questions).
            if (preg_match('/[«“\"„]([^»”\"]{3,160})[»”\"]/u', $plain, $qm)) {
                $quoted = self::normalize_resolved_entity_name((string) ($qm[1] ?? ''));
                if ($quoted !== '') {
                    return $quoted;
                }
            }
            $patterns = [
                '/\b(qu[eé]\s+me\s+puedes\s+contar)\s+(?:de|sobre|acerca\s+de)\s+(.+)$/iu',
                '/\b(h[aá]blame|hablame|cu[eé]ntame|cuentame|dime|expl[ií]came)\s+(?:de|sobre|acerca\s+de)\s+(.+)$/iu',
                '/\b(informaci[oó]n|info)\s+(?:de|sobre|acerca\s+de)\s+(.+)$/iu',
                '/\b(qu[eé]\s+sabes|sabes\s+algo)\s+(?:de|sobre)\s+(.+)$/iu',
                '/\b(presenta|presentame|conoce|con[oó]ceme|describe)\s+(?:la\s+empresa\s+)?(.+)$/iu',
                '/\b(?:tienes|tiene|me\s+das|dame|busco|necesito)\s+(?:el\s+|la\s+)?(?:contacto|tel[eé]fono|email|correo|web|fotos?|im[aá]genes?)\s+(?:de|del)\s+(.+)$/iu',
                '/\b(?:fotos?|im[aá]genes?|contacto|tel[eé]fono|email|correo|web)\s+(?:de|del|la|el)\s+(.+)$/iu',
                '/\b(?:quiero decir|me refiero a|digo)\s+(?:el\s+)?(?:contacto|fotos?|im[aá]genes?)\s+(?:de|del)\s+(.+)$/iu',
            ];
            foreach ($patterns as $re) {
                if (!preg_match($re, $plain, $m)) {
                    continue;
                }
                $name = self::normalize_resolved_entity_name((string) ($m[count($m) - 1] ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }

            return '';
        }

        private static function normalize_resolved_entity_name(string $name): string
        {
            $name = trim($name);
            $name = trim($name, " \t\n\r\0\x0B«»\"“”„'");
            $name = trim((string) preg_replace('/[?¿!.,;:]+$/u', '', $name));
            if ($name === '') {
                return '';
            }
            $len = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
            if ($len < 3 || $len > 160) {
                return '';
            }

            return $name;
        }

        /** @deprecated Refuerzo léxico vertical eliminado; conservado por compatibilidad de API pública. */
        public static function query_matches_equestrian_lexical_boost(string $search_term): bool
        {
            unset($search_term);

            return false;
        }

        /**
         * Stop-words genéricas (ES/EN) para extracción agnóstica de términos clave RAG.
         *
         * @return list<string>
         */
        private static function default_rag_keyword_stop_words(): array {
            return [
                'para', 'como', 'esta', 'este', 'estos', 'estas', 'esto', 'esas', 'esos', 'esa', 'ese',
                'aqui', 'allí', 'alli', 'donde', 'cuando', 'quien', 'qué', 'que', 'cual', 'cuales',
                'tiene', 'tienen', 'hay', 'ninguna', 'ninguno', 'alguna', 'alguno', 'algunas', 'algunos',
                'solo', 'sola', 'más', 'mas', 'muy', 'por', 'con', 'sin', 'una', 'uno', 'unas', 'unos',
                'del', 'los', 'las', 'les', 'the', 'and', 'for', 'with', 'from', 'that', 'this', 'what',
                'which', 'have', 'has', 'are', 'was', 'were', 'any', 'some', 'about', 'into', 'your',
                'their', 'there', 'here', 'would', 'could', 'should', 'quiero', 'busco', 'necesito',
                'dime', 'dame', 'mostrar', 'muestra', 'empresas', 'empresa', 'opciones', 'actividad',
                'actividades', 'servicio', 'servicios', 'ningun', 'ningún', 'algun', 'algún',
                'salidas', 'salida', 'rutas', 'ruta', 'paseos', 'paseo', 'tours', 'tour', 'excursiones',
                'excursion', 'experiencias', 'experiencia', 'ofrece', 'ofrecen', 'hacéis', 'haceis', 'hacen',
                'tenéis', 'teneis', 'tienen', 'disfrutar', 'disfruta',
            ];
        }

        /**
         * Extrae términos clave agnósticos de la pregunta (≥4 caracteres, sin stop-words).
         *
         * @return list<string>
         */
        public static function extract_rag_keyword_needles(string $text): array {
            $raw_text = trim(wp_strip_all_tags((string) $text));
            $text = mb_strtolower($raw_text, 'UTF-8');
            if ($text === '') {
                return [];
            }

            $stop = apply_filters('xabia_rag_keyword_stop_words', self::default_rag_keyword_stop_words(), $text);
            $stop_map = [];
            foreach ((array) $stop as $word) {
                $word = trim((string) $word);
                if ($word !== '') {
                    $stop_map[$word] = true;
                }
            }

            $needles = [];
            if ($raw_text !== '' && preg_match_all('/\b[\p{Lu}]{2,12}\b/u', $raw_text, $acro_matches)) {
                foreach ($acro_matches[0] as $acro) {
                    $needles[] = mb_strtolower((string) $acro, 'UTF-8');
                }
            }

            if (preg_match_all('/\p{L}[\p{L}\p{M}\'-]{2,}/u', $text, $matches)) {
                foreach ($matches[0] as $raw) {
                    $word = trim((string) $raw, "'-");
                    if ($word === '' || mb_strlen($word, 'UTF-8') < 4) {
                        continue;
                    }
                    if (isset($stop_map[$word])) {
                        continue;
                    }
                    $needles[] = $word;
                }
            }

            $needles = array_values(array_unique($needles));
            if (class_exists('Xabia_Rag_Language_Bridge', false)) {
                $needles = Xabia_Rag_Language_Bridge::expand_keyword_needles($needles, $raw_text);
            }

            return apply_filters('xabia_rag_keyword_needles', $needles, $text);
        }

        /**
         * Texto léxico para refuerzo híbrido (Hub o SQL local).
         */
        private static function build_rag_lexical_query_text(string $user_msg, string $search_term): string {
            $source = trim(wp_strip_all_tags($user_msg !== '' ? $user_msg : $search_term));
            $needles = self::extract_rag_keyword_needles($source);
            $parts = [];
            $st = trim(wp_strip_all_tags($search_term));
            if ($st !== '') {
                $parts[] = $st;
            }
            foreach ($needles as $needle) {
                $parts[] = $needle;
            }
            $parts = array_values(array_unique($parts));
            $out = trim(implode(' ', $parts));
            if ($out === '') {
                return '';
            }

            return mb_substr($out, 0, 2000);
        }

        /**
         * Prefijo agnóstico para la línea de ente matriz en bloques RAG (marca blanca).
         *
         * @param array<string, mixed> $config
         */
        private static function resolve_entity_field_prefix(array $config): string {
            $label = trim((string) ($config['rules']['entity_label'] ?? ''));
            $label = apply_filters('xabia_entity_field_prefix_label', $label, $config);
            if ($label !== '') {
                return rtrim($label, ': ') . ':';
            }
            if (class_exists('Xabia_Knowledge_Ingest', false)) {
                $singular = trim((string) Xabia_Knowledge_Ingest::resolve_catalog_entity_singular_label($config));
                if ($singular !== '') {
                    $generic_ente = __('ente', 'xabia-intelligence');
                    $generic_entes = __('entes', 'xabia-intelligence');
                    if ($singular !== $generic_ente && $singular !== $generic_entes) {
                        if (function_exists('mb_convert_case')) {
                            return mb_convert_case($singular, MB_CASE_TITLE, 'UTF-8') . ':';
                        }

                        return ucfirst($singular) . ':';
                    }
                }
            }

            return 'ENTIDAD:';
        }

        private static function infer_parent_title_from_chunk_body(string $chunk_body): string {
            $chunk_body = trim($chunk_body);
            if ($chunk_body === ''
                || !preg_match('/^([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑa-záéíóúüñ\s]{0,40}):\s*([^|\n]+)/u', $chunk_body, $match)) {
                return '';
            }
            $label = mb_strtoupper(trim((string) ($match[1] ?? '')), 'UTF-8');
            $candidate = trim((string) ($match[2] ?? ''));
            // Etiquetas de ficha/atributo: no son el nombre del ente.
            $field_labels = [
                'CATEGORÍA', 'CATEGORIA', 'SUBCATEGORÍAS', 'SUBCATEGORIAS', 'SUBCATEGORÍA', 'SUBCATEGORIA',
                'EXPERIENCIAS', 'PROPUESTAS', 'DESCRIPCIÓN', 'DESCRIPCION', 'DESCRIPCIÓN GENERAL', 'DESCRIPCION GENERAL',
                'LOCALIDAD', 'UBICACIÓN', 'UBICACION', 'MUNICIPIO', 'ZONA', 'TERRITORIO', 'DATOS', 'DATOS RECUPERADOS',
            ];
            if ($candidate === '' || in_array($label, $field_labels, true)) {
                return '';
            }

            return $candidate;
        }

        /**
         * Nombre legible a partir de slug ente_id (marmitako-sailing → Marmitako Sailing).
         */
        private static function humanize_ente_id_slug(string $ente_id): string {
            $ente_id = trim($ente_id);
            if ($ente_id === '' || $ente_id === 'global') {
                return '';
            }
            $human = str_replace(['-', '_'], ' ', $ente_id);
            $human = preg_replace('/\s+/u', ' ', $human);
            $human = trim((string) $human);
            if ($human === '') {
                return '';
            }
            if (function_exists('mb_convert_case')) {
                return mb_convert_case($human, MB_CASE_TITLE, 'UTF-8');
            }

            return ucwords($human);
        }

        /**
         * Resuelve el nombre del ente para el prompt: ente_id (autoritativo) → parent_title limpio → heurística.
         *
         * @param array<string, mixed> $chunk
         */
        private static function resolve_chunk_entity_display_name(array $chunk, string $chunk_body = ''): string {
            $enteId = trim((string) ($chunk['ente_id'] ?? ''));
            $fromSlug = self::humanize_ente_id_slug($enteId);
            // ente_id es la identidad canónica en el Hub; parent_title a menudo se infiere mal de «CATEGORÍA: Agua».
            if ($fromSlug !== '') {
                return $fromSlug;
            }
            $parent = trim((string) ($chunk['parent_title'] ?? ''));
            if ($parent !== ''
                && mb_strtolower($parent, 'UTF-8') !== 'global'
                && !self::looks_like_catalog_field_value($parent, $chunk_body !== '' ? $chunk_body : (string) ($chunk['content'] ?? ''))) {
                return $parent;
            }
            if ($chunk_body === '') {
                $chunk_body = trim((string) ($chunk['content'] ?? ''));
            }

            return self::infer_parent_title_from_chunk_body($chunk_body);
        }

        /**
         * True si el «título» es en realidad el valor de un campo de ficha (p. ej. Agua tras CATEGORÍA:).
         */
        private static function looks_like_catalog_field_value(string $title, string $chunk_body): bool {
            $title = trim($title);
            $chunk_body = trim($chunk_body);
            if ($title === '' || $chunk_body === '') {
                return false;
            }
            if (preg_match('/^(CATEGOR[ÍI]A|SUBCATEGOR[ÍI]AS?|EXPERIENCIAS|PROPUESTAS|LOCALIDAD|UBICACI[ÓO]N)\s*:\s*'
                . preg_quote($title, '/') . '\b/iu', $chunk_body)) {
                return true;
            }

            return (bool) preg_match('/\bCATEGOR[ÍI]A\s*:\s*' . preg_quote($title, '/') . '\b/iu', $chunk_body);
        }

        /**
         * @param array<string, mixed> $config
         */
        private static function format_rag_data_block_with_parent(string $chunk_body, string $parent_title, array $config): string {
            $body = trim($chunk_body);
            if ($body === '') {
                return '';
            }
            // Identidad ya sellada por el Hub (ENTIDAD:/EMPRESA:…): no duplicar cabecera.
            if (preg_match('/^(?:EMPRESA|ENTIDAD|PROVEEDOR|FICHA|HOTEL|PRODUCTO)\s*:\s*\S/iu', $body)) {
                return $body;
            }
            $title = trim($parent_title);
            if ($title === '') {
                $title = self::infer_parent_title_from_chunk_body($body);
            }
            if ($title === '') {
                return $body;
            }
            $prefix = self::resolve_entity_field_prefix($config);
            $header = $prefix . ' ' . $title;
            if (preg_match('/^' . preg_quote($prefix, '/') . '\s*' . preg_quote($title, '/') . '/iu', $body)) {
                return $body;
            }

            return $header . "\nDATOS: " . $body;
        }

        /**
         * @param list<array<string, mixed>> $chunks
         * @param array<string, mixed>       $config
         */
        private static function format_hub_rag_chunks_for_prompt(array $chunks, array $config): string {
            $chunks = self::prioritize_entity_hub_chunks($chunks);
            $blocks = [];
            foreach ($chunks as $chunk) {
                if (!is_array($chunk)) {
                    continue;
                }
                $content = trim((string) ($chunk['content'] ?? ''));
                if ($content === '') {
                    continue;
                }
                $title = self::resolve_chunk_entity_display_name($chunk, $content);
                $block = self::format_rag_data_block_with_parent($content, $title, $config);
                if ($block !== '') {
                    $blocks[] = $block;
                }
            }

            return implode("\n\n", $blocks);
        }

        /**
         * @param list<array<string, mixed>> $chunks
         *
         * @return list<array<string, mixed>>
         */
        private static function prioritize_entity_hub_chunks(array $chunks): array {
            if ($chunks === []) {
                return $chunks;
            }
            usort($chunks, static function (array $a, array $b): int {
                $ae = self::is_entity_record_hub_chunk($a);
                $be = self::is_entity_record_hub_chunk($b);

                return (int) $be <=> (int) $ae;
            });

            return $chunks;
        }

        private static function is_entity_record_hub_chunk(array $chunk): bool {
            $enteId = trim((string) ($chunk['ente_id'] ?? ''));

            return $enteId !== '' && $enteId !== 'global';
        }

        /**
         * True si ninguna aguja clave aparece en el contexto (disparar refuerzo).
         *
         * @param list<string> $needles
         */
        private static function context_lacks_keyword_needles(string $context, array $needles): bool {
            if ($needles === []) {
                return false;
            }
            $ctx = mb_strtolower((string) $context, 'UTF-8');
            if (trim($ctx) === '') {
                return true;
            }
            foreach ($needles as $needle) {
                $needle = mb_strtolower(trim((string) $needle), 'UTF-8');
                if ($needle === '' || mb_strlen($needle, 'UTF-8') < 4) {
                    continue;
                }
                if (mb_strpos($ctx, $needle) !== false) {
                    return false;
                }
            }

            return true;
        }

        /**
         * True si alguna aguja clave no aparece en el contexto.
         *
         * @param list<string> $needles
         */
        private static function context_misses_keyword_needles(string $context, array $needles): bool {
            if ($needles === []) {
                return false;
            }
            $ctx = mb_strtolower((string) $context, 'UTF-8');
            if (trim($ctx) === '') {
                return true;
            }
            foreach ($needles as $needle) {
                $needle = mb_strtolower(trim((string) $needle), 'UTF-8');
                if ($needle === '' || mb_strlen($needle, 'UTF-8') < 4) {
                    continue;
                }
                if (mb_strpos($ctx, $needle) === false) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Mapa aguja → patrón REGEXP para rescate RAG (rules.keyword_expansions). Agnóstico de dominio.
         *
         * @param array<string, mixed> $config
         *
         * @return array<string, string>
         */
        private static function resolve_rag_keyword_expansions(array $config): array {
            $raw = $config['rules']['keyword_expansions'] ?? null;
            if (!is_array($raw)) {
                return [];
            }
            $out = [];
            foreach ($raw as $key => $pattern) {
                $key = mb_strtolower(trim(sanitize_text_field((string) $key)), 'UTF-8');
                $pattern = trim(sanitize_text_field((string) $pattern));
                if ($key === '' || $pattern === '' || mb_strlen($key, 'UTF-8') < 2) {
                    continue;
                }
                if (mb_strlen($pattern, 'UTF-8') > 500) {
                    $pattern = mb_substr($pattern, 0, 500);
                }
                if (preg_match('/[\r\n]/', $pattern)) {
                    continue;
                }
                $out[$key] = $pattern;
            }

            return (array) apply_filters('xabia_rag_keyword_expansions', $out, $config);
        }

        /**
         * @param array<string, mixed> $config
         */
        private static function keyword_expansion_regexp_for_needle(string $needle, array $config): ?string {
            $expansions = self::resolve_rag_keyword_expansions($config);
            $nl = mb_strtolower(trim($needle), 'UTF-8');

            return isset($expansions[$nl]) ? $expansions[$nl] : null;
        }

        /**
         * Términos que satisfacen una aguja de refuerzo (expansiones de rules + filtro opcional).
         *
         * @param array<string, mixed> $config
         *
         * @return list<string>
         */
        private static function keyword_needle_match_terms(string $needle, string $project_id = '', array $config = []): array {
            $needle = mb_strtolower(trim($needle), 'UTF-8');
            if ($needle === '') {
                return [];
            }
            $terms = [ $needle ];
            $regexp = self::keyword_expansion_regexp_for_needle($needle, $config);
            if ($regexp !== null) {
                foreach (preg_split('/\|/', $regexp) as $part) {
                    $part = mb_strtolower(trim((string) $part), 'UTF-8');
                    if ($part !== '' && mb_strlen($part, 'UTF-8') >= 3) {
                        $terms[] = $part;
                    }
                }
            }
            $aliases = apply_filters('xabia_rag_needle_rescue_aliases', [], $needle, $project_id, $config);
            if (is_array($aliases)) {
                foreach ($aliases as $alias) {
                    $alias = mb_strtolower(trim((string) $alias), 'UTF-8');
                    if ($alias !== '') {
                        $terms[] = $alias;
                    }
                }
            }

            return array_values(array_unique(array_filter($terms)));
        }

        private static function text_contains_any_needle_term(string $haystack, string $needle, string $project_id = '', array $config = []): bool {
            foreach (self::keyword_needle_match_terms($needle, $project_id, $config) as $term) {
                if ($term !== '' && mb_stripos($haystack, $term) !== false) {
                    return true;
                }
            }
            $regexp = self::keyword_expansion_regexp_for_needle($needle, $config);
            if ($regexp !== null && @preg_match('/' . $regexp . '/iu', $haystack)) {
                return true;
            }

            return false;
        }

        /**
         * Refuerzo por coincidencia exacta de palabras clave (SQL local o Hub cloud).
         *
         * @param list<string>         $needles
         * @param array<string, mixed> $config
         */
        private static function fetch_keyword_boost_context(
            string $project_id,
            array $needles,
            string $ente_scope,
            bool $strict_ente,
            int $max_chunks,
            array $config = [],
            float $similarity_threshold = 0.2,
            string $context_so_far = '',
            ?array $query_vector = null
        ): string {
            if (!class_exists('Xabia_Brain', false) || $needles === []) {
                return '';
            }

            $can_local = !self::is_hub_rag_enabled_for_project($project_id)
                || (class_exists('Xabia_DB', false) && Xabia_DB::uses_co_located_hub_store($project_id));
            if ($can_local) {
                return self::fetch_keyword_boost_local(
                    $project_id,
                    self::select_keyword_needles_for_boost($needles, $context_so_far),
                    $ente_scope,
                    $strict_ente,
                    $max_chunks,
                    $config
                );
            }
            if (self::is_hub_rag_enabled_for_project($project_id)) {
                $hub_hit = self::fetch_keyword_boost_via_hub(
                    $project_id,
                    self::select_keyword_needles_for_boost($needles, $context_so_far),
                    $ente_scope,
                    $strict_ente,
                    $max_chunks,
                    $config,
                    $similarity_threshold,
                    $context_so_far,
                    $query_vector
                );
                if ($hub_hit !== '') {
                    return $hub_hit;
                }

                $local_rescue = self::fetch_keyword_boost_local(
                    $project_id,
                    self::select_keyword_needles_for_boost($needles, $context_so_far),
                    $ente_scope,
                    $strict_ente,
                    $max_chunks,
                    $config
                );
                if ($local_rescue !== '') {
                    self::$last_rag_debug['keyword_boost_status'] = 'executed_local_rescue';
                    if (function_exists('xabia_trace')) {
                        xabia_trace('[XABIA_CORE] keyword boost hub empty; local LIKE rescue', [
                            'project_id' => $project_id,
                        ]);
                    }
                }

                return $local_rescue;
            }

            return '';
        }

        /**
         * Aguja(s) aún ausentes del contexto; como máximo una para evitar latencia N+1.
         *
         * @param list<string> $needles
         *
         * @return list<string>
         */
        private static function select_keyword_needles_for_boost(array $needles, string $context_so_far): array {
            $missing = [];
            foreach ($needles as $needle) {
                $needle = self::sanitize_rag_search_term((string) $needle);
                if ($needle === '' || mb_strlen($needle, 'UTF-8') < 4) {
                    continue;
                }
                if (self::context_misses_keyword_needles($context_so_far, [$needle])) {
                    $missing[] = $needle;
                }
            }
            if ($missing === []) {
                return [];
            }
            usort($missing, static function (string $a, string $b): int {
                return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
            });

            return [ $missing[0] ];
        }

        /**
         * @param list<string>         $needles
         * @param array<string, mixed> $config
         */
        private static function fetch_keyword_boost_local(
            string $project_id,
            array $needles,
            string $ente_scope,
            bool $strict_ente,
            int $max_chunks,
            array $config = []
        ): string {
            $merged = '';
            $limit_per = max(2, min(6, (int) ceil($max_chunks / max(1, count($needles)))));
            foreach ($needles as $needle) {
                $needle = self::sanitize_rag_search_term((string) $needle);
                if ($needle === '') {
                    continue;
                }
                $search_terms = self::keyword_needle_match_terms($needle, $project_id, $config);
                foreach ($search_terms as $search_term) {
                    if ($search_term === '' || mb_strlen($search_term, 'UTF-8') < 4) {
                        continue;
                    }
                    try {
                        $hit = trim((string) Xabia_Brain::search_knowledge(
                            $project_id,
                            $search_term,
                            $ente_scope,
                            $strict_ente,
                            $limit_per
                        ));
                    } catch (\Throwable $e) {
                        if (function_exists('xabia_trace')) {
                            xabia_trace('[XABIA_CORE] keyword boost search failed', [
                                'message' => $e->getMessage(),
                                'needle'  => substr($search_term, 0, 80),
                            ]);
                        }
                        continue;
                    }
                    if ($hit === '' || strlen($hit) < 10) {
                        continue;
                    }
                    if (!self::text_contains_any_needle_term($hit, $needle, $project_id, $config)) {
                        continue;
                    }
                    $block = self::format_rag_data_block_with_parent($hit, '', $config);
                    $probe = mb_substr($block, 0, 120);
                    if ($probe !== '' && mb_stripos($merged, $probe) !== false) {
                        continue 2;
                    }
                    $merged .= ($merged === '' ? '' : "\n\n") . $block;
                    continue 2;
                }
            }

            return trim($merged);
        }

        /**
         * Segunda pasada Hub por aguja cuando el top-k vectorial omitió términos literales (p. ej. «velero»).
         *
         * @param list<string>         $needles
         * @param array<string, mixed> $config
         */
        private static function fetch_keyword_boost_via_hub(
            string $project_id,
            array $needles,
            string $ente_scope,
            bool $strict_ente,
            int $max_chunks,
            array $config,
            float $similarity_threshold,
            string $context_so_far,
            ?array $query_vector = null
        ): string {
            if (!class_exists('Xabia_Hub_Knowledge', false) || !Xabia_Hub_Knowledge::is_hub_rag_enabled($project_id)) {
                return '';
            }

            $needles = self::select_keyword_needles_for_boost($needles, $context_so_far);
            if ($needles === []) {
                return '';
            }

            $needle = self::sanitize_rag_search_term((string) $needles[0]);
            if ($needle === '' || mb_strlen($needle, 'UTF-8') < 4) {
                return '';
            }

            $limit = max(3, min(8, $max_chunks));
            $threshold = 0.01;

            $query_vector = self::get_query_embedding($needle, $config, $project_id);
            self::digixop_absorb_query_embedding_usage($project_id, $config);
            if (class_exists('Xabia_Digixop_Client') && Xabia_Digixop_Client::was_insufficient_balance()) {
                return '';
            }
            if (!is_array($query_vector) || $query_vector === []) {
                return '';
            }

            $hub_opts = [
                'lexical_query_text' => $needle,
                'keyword_boost_only' => true,
            ];
            $keyword_expansions = self::resolve_rag_keyword_expansions($config);
            if ($keyword_expansions !== []) {
                $hub_opts['keyword_expansions'] = $keyword_expansions;
            }

            try {
                if (class_exists('Xabia_Hub_Knowledge', false) && Xabia_Hub_Knowledge::is_hub_rag_enabled($project_id)) {
                    $out = Xabia_Hub_Knowledge::search_vector(
                        $project_id,
                        $query_vector,
                        $ente_scope,
                        $strict_ente,
                        $limit,
                        $threshold,
                        $needle,
                        $hub_opts
                    );
                } else {
                    $out = Xabia_Brain::search_knowledge_vector(
                        $project_id,
                        $needle,
                        $ente_scope,
                        $strict_ente,
                        $limit,
                        $threshold,
                        $query_vector,
                        $hub_opts
                    );
                }
            } catch (\Throwable $e) {
                if (function_exists('xabia_trace')) {
                    xabia_trace('[XABIA_CORE] hub keyword boost failed', [
                        'message' => $e->getMessage(),
                        'needle'  => substr($needle, 0, 80),
                    ]);
                }

                return '';
            }

            if (isset($out['_hub_meta']) && is_array($out['_hub_meta']) && empty($out['_hub_meta']['ok'])) {
                self::log_hub_rag_transport_failure(
                    $project_id,
                    'keyword_boost',
                    [
                        'ok'       => false,
                        'code'     => (int) ($out['_hub_meta']['http_code'] ?? 0),
                        'raw'      => (string) ($out['_hub_meta']['raw'] ?? ''),
                        'wp_error' => isset($out['_hub_meta']['wp_error']) && is_array($out['_hub_meta']['wp_error'])
                            ? $out['_hub_meta']['wp_error']
                            : null,
                        'body'     => $out['_hub_meta']['body'] ?? null,
                    ],
                    (string) ($out['_hub_meta']['url'] ?? '')
                );
            }

            $formatted = self::format_hub_keyword_boost_hit($out, $needle, $config, $project_id);
            if ($formatted !== '') {
                return $formatted;
            }

            $alias_needles = apply_filters('xabia_rag_needle_rescue_aliases', [], $needle, $project_id, $config);
            if (is_array($alias_needles)) {
                foreach ($alias_needles as $alias) {
                    $alias = self::sanitize_rag_search_term((string) $alias);
                    if ($alias === '' || $alias === $needle || mb_strlen($alias, 'UTF-8') < 4) {
                        continue;
                    }
                    $alias_opts = $hub_opts;
                    $alias_opts['lexical_query_text'] = $alias;
                    $alias_out = Xabia_Hub_Knowledge::search_vector(
                        $project_id,
                        self::get_query_embedding($alias, $config, $project_id) ?: $query_vector,
                        $ente_scope,
                        $strict_ente,
                        $limit,
                        $threshold,
                        $alias,
                        $alias_opts
                    );
                    $alias_hit = self::format_hub_keyword_boost_hit(is_array($alias_out) ? $alias_out : [], $needle, $config, $project_id);
                    if ($alias_hit !== '' && self::text_contains_any_needle_term($alias_hit, $needle, $project_id, $config)) {
                        return $alias_hit;
                    }
                    $alias_hit = self::format_hub_keyword_boost_hit(is_array($alias_out) ? $alias_out : [], $alias, $config, $project_id);
                    if ($alias_hit !== '') {
                        return $alias_hit;
                    }
                }
            }

            if (self::should_log_rag_context_chivato($config)) {
                $chunks = isset($out['chunks']) && is_array($out['chunks']) ? $out['chunks'] : [];
                if ($chunks !== []) {
                    error_log(
                        '[XABIA RAG HUB RESCUE NEEDLE MISS] project=' . $project_id
                        . ' needle=' . $needle
                        . ' chunk_count=' . count($chunks)
                    );
                }
            }

            $hit = trim((string) ($out['context'] ?? ''));
            if ($hit === '' || strlen($hit) < 10 || !self::text_contains_any_needle_term($hit, $needle, $project_id, $config)) {
                if (self::should_log_rag_context_chivato($config)) {
                    $hub_meta = isset($out['_hub_meta']) && is_array($out['_hub_meta']) ? $out['_hub_meta'] : [];
                    $hub_url = (string) ($hub_meta['url'] ?? '');
                    if ($hub_url === '' && class_exists('Xabia_Digixop_Client', false)) {
                        $hub_url = (string) apply_filters(
                            'xabia_hub_knowledge_search_url',
                            Xabia_Digixop_Client::default_knowledge_search_url(),
                            $project_id
                        );
                    }
                    $hub_body = $hub_meta['body'] ?? $out;
                    $hub_json = wp_json_encode($hub_body);
                    if (!is_string($hub_json)) {
                        $hub_json = '{}';
                    }
                    error_log(
                        '[XABIA RAG HUB RESCUE EMPTY] project=' . $project_id
                        . ' needle=' . $needle
                        . ' url=' . $hub_url
                        . ' http_code=' . (int) ($hub_meta['http_code'] ?? 0)
                        . ' chunk_count=' . (int) ($out['chunk_count'] ?? 0)
                        . ' response=' . substr($hub_json, 0, 1800)
                    );
                }

                return '';
            }

            return self::format_rag_data_block_with_parent($hit, '', $config);
        }

        /**
         * @param array<string, mixed> $out
         * @param array<string, mixed> $config
         */
        private static function format_hub_keyword_boost_hit(array $out, string $needle, array $config, string $project_id = ''): string {
            $chunks = isset($out['chunks']) && is_array($out['chunks']) ? $out['chunks'] : [];
            if ($chunks !== []) {
                $formatted = self::format_hub_rag_chunks_for_prompt($chunks, $config);
                if ($formatted !== '' && self::text_contains_any_needle_term($formatted, $needle, $project_id, $config)) {
                    return $formatted;
                }
            }
            $hit = trim((string) ($out['context'] ?? ''));
            if ($hit !== '' && strlen($hit) >= 10 && self::text_contains_any_needle_term($hit, $needle, $project_id, $config)) {
                return self::format_rag_data_block_with_parent($hit, '', $config);
            }

            return '';
        }

        /**
         * Si el vector/RAG recuperó chunks genéricos pero la pregunta incluye términos inequívocos que no
         * aparecen en el contexto, devuelve true para disparar un refuerzo por LIKE (mismo catálogo en WP).
         * Filtrable: `xabia_rag_query_signal_terms`. Sin filtro, usa extracción agnóstica de palabras clave.
         */
        public static function rag_context_misses_query_signal_terms(string $search_term, string $context): bool
        {
            $terms = apply_filters(
                'xabia_rag_query_signal_terms',
                [],
                $search_term,
                $context
            );
            if (!is_array($terms) || $terms === []) {
                $terms = self::extract_rag_keyword_needles((string) $search_term);
            }
            $q = mb_strtolower(wp_strip_all_tags((string) $search_term), 'UTF-8');
            $c = mb_strtolower((string) $context, 'UTF-8');
            $any_signal_in_query = false;
            $any_signal_missing_in_context = false;
            foreach ($terms as $t) {
                $t = trim((string) $t);
                if ($t === '' || mb_strlen($t, 'UTF-8') < 3) {
                    continue;
                }
                $tl = mb_strtolower($t, 'UTF-8');
                if (mb_strpos($q, $tl) === false) {
                    continue;
                }
                $any_signal_in_query = true;
                if (mb_strpos($c, $tl) === false) {
                    if (class_exists('Xabia_Rag_Language_Bridge', false)
                        && Xabia_Rag_Language_Bridge::context_contains_term_variant($tl, (string) $context)) {
                        continue;
                    }
                    $any_signal_missing_in_context = true;
                }
            }

            return $any_signal_in_query && $any_signal_missing_in_context;
        }

        /**
         * Mensaje muy corto o de continuación («más empresas», «¿y hípica?») que debe anclarse al último
         * criterio de búsqueda de la sesión para no degradar el embedding ni el LIKE.
         */
        private static function is_rag_topic_followup_utterance(string $msg): bool
        {
            if (self::resolve_named_entity_from_user_message($msg) !== '') {
                return false;
            }
            $t = mb_strtolower(trim(wp_strip_all_tags($msg)), 'UTF-8');
            if ($t === '') {
                return false;
            }
            if (preg_match('/^(hola|kaixo|aupa|buenas|hey|hi|hello|gracias|thanks|thank\s+you|vale|ok|genial|agur)\b/u', $t)) {
                return false;
            }
            if (preg_match('/\b(m[aá]s|otras?|otros?|algun[ao]s?\s+m[aá]s|adem[aá]s|tambi[eé]n|siguiente|contin[uú]a|algo\s+m[aá]s)\s+/u', $t)) {
                return true;
            }
            if (preg_match('/\b(m[aá]s\s+(empresas|opci|resultados|datos|informaci[oó]n))\b/u', $t)) {
                return true;
            }
            if (preg_match('/\b(more|other|others|another|additional)\s+/u', $t)) {
                return true;
            }
            if (preg_match('/^(si|sí|yes|bai|ba|au|vale|ok)\.?!?$/u', $t)) {
                return true;
            }
            if (mb_strlen($t) <= 36) {
                if (preg_match('/[?¿]/u', $t) || preg_match('/^(y|and)\s+/u', $t)) {
                    return true;
                }
            }

            return false;
        }

        /** @deprecated Enrutamiento delegado al LLM; conservado por compatibilidad de API pública. */
        public static function query_is_negated_catalog_discovery(string $text): bool
        {
            unset($text);

            return false;
        }

        /** @deprecated Enrutamiento delegado al LLM; conservado por compatibilidad de API pública. */
        public static function query_implies_catalog_subset_followup(
            string $text,
            string $last_search = '',
            array $prior_manifest = []
        ): bool {
            unset($text, $last_search, $prior_manifest);

            return false;
        }

        /** @deprecated Enrutamiento delegado al LLM; conservado por compatibilidad de API pública. */
        public static function query_implies_activity_catalog_discovery(string $text): bool
        {
            unset($text);

            return false;
        }

        /** @deprecated Enrutamiento delegado al LLM; conservado por compatibilidad de API pública. */
        public static function query_implies_activity_catalog_followup(string $text, string $last_search = ''): bool
        {
            unset($text, $last_search);

            return false;
        }

        /** @deprecated Enrutamiento delegado al LLM; conservado por compatibilidad de API pública. */
        public static function query_expects_multiple_catalog_companies(string $user_msg, string $search_term = ''): bool
        {
            unset($user_msg, $search_term);

            return false;
        }

        /**
         * Reduce utterances largas a término canónico para embedding (extensible vía filtro; sin mapa vertical en Core).
         */
        private static function strip_leading_greeting_for_rag(string $text): string
        {
            $t = trim(wp_strip_all_tags((string) $text));
            if ($t === '') {
                return '';
            }
            $stripped = preg_replace('/^(hola|kaixo|aupa|buenas|hey|hi|hello)\s*,?\s*/iu', '', $t);

            return is_string($stripped) && trim($stripped) !== '' ? trim($stripped) : $t;
        }

        private static function distill_activity_search_term(string $text): string
        {
            $q = mb_strtolower(trim(wp_strip_all_tags((string) self::strip_leading_greeting_for_rag($text))), 'UTF-8');
            if ($q === '') {
                return '';
            }
            $map = apply_filters('xabia_distill_activity_search_term_map', [], $text);
            if (!is_array($map)) {
                $map = [];
            }
            foreach ($map as $needle => $canonical) {
                if (mb_strpos($q, $needle) !== false) {
                    return $canonical;
                }
            }

            return '';
        }

        /**
         * @param string $user_msg_clean  Mensaje actual ya recortado (sin HTML).
         * @param string $last_search     Valor previo de xabia_last_search (turno anterior).
         */
        private static function augment_rag_search_term(string $user_msg_clean, string $last_search): string
        {
            $user_msg_clean = trim($user_msg_clean);
            $last_search = trim(wp_strip_all_tags((string) $last_search));
            if ($last_search === '') {
                $seed = self::distill_activity_search_term($user_msg_clean);

                return $seed !== '' ? $seed : ($user_msg_clean !== '' ? $user_msg_clean : '');
            }
            if ($user_msg_clean === '') {
                $seed = self::distill_activity_search_term($last_search);

                return $seed !== '' ? $seed : $last_search;
            }
            if (self::query_implies_entity_utility_request($user_msg_clean)
                && !self::resolve_named_entity_from_user_message($user_msg_clean)
                && !self::query_implies_entity_utility_request($last_search)) {
                return self::sanitize_rag_search_term($last_search);
            }
            if (!self::is_rag_topic_followup_utterance($user_msg_clean)) {
                $seed = self::distill_activity_search_term($user_msg_clean);
                if ($seed !== '' && mb_strlen($user_msg_clean, 'UTF-8') > mb_strlen($seed, 'UTF-8') + 6) {
                    return $seed;
                }

                return $user_msg_clean;
            }
            // «y actividades a caballo» → nuevo pivote temático, no «hacéis hípica + …» (rompe embedding y dispara LIKE local).
            $pivot = trim((string) preg_replace('/^(y|and)\s+/iu', '', $user_msg_clean));
            if ($pivot !== '' && $pivot !== $user_msg_clean) {
                $seed = self::distill_activity_search_term($pivot);

                return $seed !== '' ? $seed : $pivot;
            }
            $seed = self::distill_activity_search_term($user_msg_clean);
            if ($seed !== '') {
                return $seed;
            }
            if (mb_stripos($last_search, $user_msg_clean) !== false) {
                return self::sanitize_rag_search_term($last_search);
            }

            // Nunca concatenar utterances: un solo término destilado (evita LIKE multi-palabra en WP).
            $fallback = self::distill_activity_search_term($user_msg_clean);
            if ($fallback !== '') {
                return self::sanitize_rag_search_term($fallback);
            }

            return self::sanitize_rag_search_term($last_search !== '' ? $last_search : $user_msg_clean);
        }

        /**
         * Tras un embedding de consulta (RAG vector), suma tokens al mismo informe de sesión que el chat.
         *
         * @param array<string, mixed> $config
         */
        private static function digixop_absorb_query_embedding_usage(string $project_id, array $config): void {
            if (!class_exists('Xabia_Digixop_Client') || !Xabia_Digixop_Client::should_use_openai_proxy($project_id, $config)) {
                return;
            }
            $t = Xabia_Digixop_Client::get_last_embedding_total_tokens();
            if ($t === null || $t < 1) {
                return;
            }
            self::$digixop_session_proxy_used = true;
            self::$digixop_session_usage['total'] += $t;
        }

        /**
         * @param array<string, mixed> $config Config del proyecto (claves locales / driver).
         */
        private static function call_openai($messages, $max_tokens, $model, $temperature, $project_id = '', $config = []) {
            $r = self::openai_chat_request($messages, (int) $max_tokens, (string) $model, (float) $temperature, (string) $project_id, is_array($config) ? $config : [], null);
            if (!empty($r['insufficient'])) {
                return Xabia_Digixop_Client::get_insufficient_balance_user_message();
            }
            if (!empty($r['error'])) {
                return (string) $r['error'];
            }
            if (!empty($r['tool_calls'])) {
                return 'Error de respuesta (tool_calls inesperadas).';
            }
            if (($r['content'] ?? null) === null) {
                return 'Error de respuesta.';
            }

            return (string) $r['content'];
        }
        /**
         * Resuelve la ruta del JSON de Google Cloud: proyecto tiene prioridad; si está vacía, usa la global.
         */
        private static function resolve_gcloud_json_path($config) {
            $path = $config['gcloud_json_path'] ?? '';
            if ($path !== '' && $path !== null) return $path;
            return (string) get_option('xabia_gcloud_json_path', '');
        }

        /**
         * MOTOR 2: GOOGLE CLOUD VERTEX AI (Gemini 2.5 Flash vía {@see self::VERTEX_LOCAL_CHAT_MODEL}).
         * Sin herramientas: un solo turno user con historial concatenado (Intérprete y chat sin nodos).
         * Con nodos federados: generateContent con functionDeclarations (ask_federated_node) y bucle functionCall → functionResponse.
         *
         * @param array  $messages
         * @param int    $max_tokens
         * @param array  $config
         * @param float  $temperature
         * @param string $wp_project_id ID del proyecto WordPress (ask_federated_node).
         * @param bool   $enable_vertex_federation_tools Activar bucle de herramientas solo si hay nodos amigos.
         */
        private static function call_google_vertex($messages, $max_tokens, $config, $temperature, $wp_project_id = '', $enable_vertex_federation_tools = false) {
            self::$last_generation_finish_reason = '';
            $max_tokens = self::chat_max_tokens($max_tokens);
            if ($enable_vertex_federation_tools && class_exists('Xabia_Federation_Nexus', false) && Xabia_Federation_Nexus::get_friend_nodes() !== []) {
                return self::call_google_vertex_federation_tool_loop($messages, (int) $max_tokens, $config, (float) $temperature, (string) $wp_project_id);
            }

            $json_path = self::resolve_gcloud_json_path($config);

            
            if (empty($json_path) || !file_exists($json_path)) {
                return "Error Config: No encuentro el archivo JSON en: " . $json_path;
            }

            
            $engine_root = dirname(dirname($json_path));
            $vendor_path = $engine_root . '/vendor/autoload.php';

            if (!file_exists($vendor_path)) {
                $plugin_vendor = plugin_dir_path(dirname(dirname(__FILE__))) . 'vendor/autoload.php';
                if(file_exists($plugin_vendor)) {
                    $vendor_path = $plugin_vendor;
                } else {
                    return "Error Crítico: No encuentro las librerías de Google (vendor/autoload.php).";
                }
            }
            
            require_once $vendor_path;
            putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $json_path);

            try {
                $content_json = file_get_contents($json_path);
                $project_data = json_decode($content_json, true);
                
                if (!isset($project_data['project_id'])) return "Error: JSON inválido.";
                
                $project_id = $project_data['project_id'];
                
                $location = self::VERTEX_LOCAL_LOCATION;

                
                $full_prompt = self::xabia_polyglot_language_rule($config['_xabia_proxy_user_lang'] ?? null) . "\n\n";
                foreach ($messages as $m) {
                    $content = isset($m['content']) ? (string) $m['content'] : '';
                    if (function_exists('mb_convert_encoding')) {
                        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
                    }
                    if ($m['role'] === 'system') $full_prompt .= "INSTRUCCIONES: " . $content . "\n";
                    else $full_prompt .= strtoupper($m['role']) . ": " . $content . "\n";
                }

                
                if (!class_exists('Google\Auth\Credentials\ServiceAccountCredentials')) return "Error: Librería Google Auth no cargada.";
                
                $creds = new \Google\Auth\Credentials\ServiceAccountCredentials('https://www.googleapis.com/auth/cloud-platform', $json_path);
                $token_data = $creds->fetchAuthToken();
                $access_token = $token_data['access_token'] ?? '';

                if(empty($access_token)) return "Error: Autenticación fallida.";

                $model_id = self::VERTEX_LOCAL_CHAT_MODEL;
                $url = "https://{$location}-aiplatform.googleapis.com/v1/projects/{$project_id}/locations/{$location}/publishers/google/models/{$model_id}:generateContent";

                $body = json_encode([
                    "contents" => [["role" => "user", "parts" => [["text" => $full_prompt]]]],
                    "generationConfig" => [
                        "temperature" => (float) $temperature,
                        "maxOutputTokens" => (int) $max_tokens,
                        "candidateCount" => 1
                    ]
                ]);
                if ($body === false) return "Error: no se pudo preparar la petición a la IA (encoding).";

                $response = wp_remote_post($url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $access_token,
                        'Content-Type' => 'application/json'
                    ],
                    'body' => $body,
                    'timeout' => 45
                ]);

                if (is_wp_error($response)) return "Error Conexión: " . $response->get_error_message();

                $raw_body = wp_remote_retrieve_body($response);
                $data = json_decode($raw_body, true);
                
                if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                    $textOut = (string) $data['candidates'][0]['content']['parts'][0]['text'];
                    self::$last_generation_finish_reason = strtolower(trim((string) ($data['candidates'][0]['finishReason'] ?? '')));
                    $promptTokensApprox = (int) max(1, floor(strlen($full_prompt) / 4));
                    $completionTokensApprox = (int) max(1, floor(strlen($textOut) / 4));
                    self::$last_generation_metrics = [
                        'prompt_tokens' => $promptTokensApprox,
                        'completion_tokens' => $completionTokensApprox,
                        'model' => self::VERTEX_LOCAL_CHAT_MODEL,
                        'estimated_cost' => self::estimate_cost_usd('flash', $promptTokensApprox, $completionTokensApprox),
                    ];
                    return $textOut;
                } elseif (isset($data['error'])) {
                    return "Error Google ({$data['error']['code']}): " . $data['error']['message'];
                } else {
                    return "Respuesta vacía. Raw: " . substr($raw_body, 0, 150);
                }

            } catch (Exception $e) { 
                return "Excepción PHP: " . $e->getMessage(); 
            }
        }

        /**
         * Convierte tools OpenAI (type function) a functionDeclarations de Vertex.
         *
         * @param array<int, array<string, mixed>> $tools
         * @return array<int, array<string, mixed>>
         */
        private static function vertex_openai_tools_to_declarations($tools) {
            $declarations = [];
            if (!is_array($tools)) {
                return $declarations;
            }
            foreach ($tools as $tool) {
                if (!is_array($tool) || ($tool['type'] ?? '') !== 'function') {
                    continue;
                }
                $fn = $tool['function'] ?? null;
                if (!is_array($fn)) {
                    continue;
                }
                $name = isset($fn['name']) ? (string) $fn['name'] : '';
                if ($name === '') {
                    continue;
                }
                $desc = isset($fn['description']) ? (string) $fn['description'] : '';
                $params = $fn['parameters'] ?? null;
                if (!is_array($params)) {
                    $params = ['type' => 'object', 'properties' => []];
                }
                $declarations[] = [
                    'name'        => $name,
                    'description' => $desc,
                    'parameters'  => $params,
                ];
            }
            return $declarations;
        }

        /**
         * @param array<int, array<string, mixed>> $messages
         * @return array<string, mixed>
         */
        private static function vertex_build_gemini_body_from_openai_messages(array $messages, array $config, array $generation_config) {
            $system_parts = [];
            $system_parts[] = [
                'text' => self::xabia_polyglot_language_rule($config['_xabia_proxy_user_lang'] ?? null),
            ];

            $contents = [];
            foreach ($messages as $msg) {
                if (!is_array($msg)) {
                    continue;
                }
                $role = $msg['role'] ?? '';
                $text = isset($msg['content']) ? (string) $msg['content'] : '';
                if (function_exists('mb_convert_encoding')) {
                    $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
                }
                if ($role === 'system') {
                    if ($text !== '') {
                        $system_parts[] = ['text' => $text];
                    }
                    continue;
                }
                if ($role === 'user' || $role === 'assistant') {
                    $gem_role = $role === 'assistant' ? 'model' : 'user';
                    if ($text === '') {
                        continue;
                    }
                    $contents[] = [
                        'role'  => $gem_role,
                        'parts' => [['text' => $text]],
                    ];
                }
            }

            $out = [
                'contents'         => $contents,
                'generationConfig' => $generation_config,
            ];
            if ($system_parts !== []) {
                $out['systemInstruction'] = ['parts' => $system_parts];
            }
            return $out;
        }

        /**
         * @param array<string, mixed> $data
         * @return array<int, array{name: string, args: array<string, mixed>}>
         */
        private static function vertex_extract_function_calls(array $data) {
            $calls = [];
            $parts = $data['candidates'][0]['content']['parts'] ?? null;
            if (!is_array($parts)) {
                return $calls;
            }
            foreach ($parts as $part) {
                if (!is_array($part) || !isset($part['functionCall']) || !is_array($part['functionCall'])) {
                    continue;
                }
                $fc = $part['functionCall'];
                $name = isset($fc['name']) ? (string) $fc['name'] : '';
                if ($name === '') {
                    continue;
                }
                $args = $fc['args'] ?? [];
                if (!is_array($args)) {
                    $args = [];
                }
                $calls[] = ['name' => $name, 'args' => $args];
            }
            return $calls;
        }

        /**
         * @param array<string, mixed> $data
         */
        private static function vertex_extract_text_from_candidate(array $data) {
            $parts = $data['candidates'][0]['content']['parts'] ?? null;
            if (!is_array($parts)) {
                return '';
            }
            $buf = '';
            foreach ($parts as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $buf .= (string) $part['text'];
                }
            }
            return $buf;
        }

        /**
         * Bucle Vertex: functionCall → xabia_federation_ask_node → functionResponse → siguiente generateContent.
         *
         * @param array<int, array<string, mixed>> $messages
         */
        private static function call_google_vertex_federation_tool_loop(array $messages, int $max_tokens, array $config, float $temperature, string $wp_project_id) {
            self::$last_generation_finish_reason = '';
            $max_tokens = self::chat_max_tokens($max_tokens);
            $auth = self::get_google_vertex_auth($config);
            if ($auth === null) {
                return 'Error: credenciales Vertex no disponibles (JSON o vendor).';
            }

            $msgs = array_values($messages);
            $conscious = class_exists('Xabia_Federation_Nexus', false) ? Xabia_Federation_Nexus::federation_vertex_consciousness_block() : '';
            $instr = class_exists('Xabia_Federation_Nexus', false) ? Xabia_Federation_Nexus::federation_tool_instruction_block() : '';
            $extra = [];
            if ($conscious !== '') {
                $extra[] = $conscious;
            }
            if ($instr !== '') {
                $extra[] = $instr;
            }
            $extra_s = implode("\n\n", array_filter($extra, 'strlen'));
            if ($extra_s !== '') {
                $system_i = null;
                foreach ($msgs as $i => $m) {
                    if (is_array($m) && (($m['role'] ?? '') === 'system')) {
                        $system_i = $i;
                        break;
                    }
                }
                if ($system_i !== null) {
                    $msgs[$system_i]['content'] = trim((string) ($msgs[$system_i]['content'] ?? '')) . "\n\n" . $extra_s;
                } else {
                    array_unshift($msgs, ['role' => 'system', 'content' => $extra_s]);
                }
            }

            $model = self::VERTEX_LOCAL_CHAT_MODEL;
            $url = "https://{$auth['location']}-aiplatform.googleapis.com/v1/projects/{$auth['project_id']}/locations/{$auth['location']}/publishers/google/models/{$model}:generateContent";

            $tools_ai = class_exists('Xabia_Federation_Nexus', false) ? Xabia_Federation_Nexus::federation_tool_definitions() : [];
            $decls = self::vertex_openai_tools_to_declarations($tools_ai);
            $tool_block = [];
            if ($decls !== []) {
                $tool_block['tools'] = [['functionDeclarations' => $decls]];
                $tool_block['toolConfig'] = ['functionCallingConfig' => ['mode' => 'AUTO']];
            }

            $generation_config = [
                'temperature'     => (float) $temperature,
                'maxOutputTokens' => $max_tokens,
                'candidateCount'  => 1,
            ];

            $body = self::vertex_build_gemini_body_from_openai_messages($msgs, $config, $generation_config);
            $body = array_merge($body, $tool_block);

            for ($step = 0; $step < 3; $step++) {
                $json_body = wp_json_encode($body, JSON_UNESCAPED_UNICODE);
                if ($json_body === false) {
                    return 'Error al codificar el cuerpo de la petición Vertex.';
                }

                $response = wp_remote_post($url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $auth['access_token'],
                        'Content-Type'  => 'application/json',
                    ],
                    'body'    => $json_body,
                    'timeout' => 90,
                ]);

                if (is_wp_error($response)) {
                    return 'Error de conexión con Google Vertex: ' . $response->get_error_message();
                }

                $code = wp_remote_retrieve_response_code($response);
                $raw_body = wp_remote_retrieve_body($response);

                if ($code !== 200) {
                    $err = json_decode($raw_body, true);
                    $msg = is_array($err) && isset($err['error']['message']) ? (string) $err['error']['message'] : $raw_body;
                    return 'Error Google Vertex (' . (string) $code . '): ' . $msg;
                }

                $data = json_decode($raw_body, true);
                if (!is_array($data)) {
                    return 'Respuesta JSON inválida de Vertex.';
                }
                if (isset($data['error']['message'])) {
                    return 'Error Google Vertex: ' . (string) $data['error']['message'];
                }

                $calls = self::vertex_extract_function_calls($data);
                $text_out = self::vertex_extract_text_from_candidate($data);
                self::$last_generation_finish_reason = strtolower(trim((string) ($data['candidates'][0]['finishReason'] ?? '')));

                if ($calls === []) {
                    return $text_out !== '' ? $text_out : __('Sin respuesta del modelo.', 'xabia-intelligence');
                }

                $cand0 = $data['candidates'][0] ?? null;
                if (!is_array($cand0) || empty($cand0['content']['parts']) || !is_array($cand0['content']['parts'])) {
                    return $text_out !== '' ? $text_out : __('Respuesta incompleta de Vertex.', 'xabia-intelligence');
                }

                $body['contents'][] = [
                    'role'  => 'model',
                    'parts' => $cand0['content']['parts'],
                ];

                $fr_parts = [];
                foreach ($calls as $fc) {
                    $name = $fc['name'];
                    $args = $fc['args'];
                    $tool_text = '';
                    if ($name === 'ask_federated_node') {
                        $node = sanitize_title((string) ($args['node'] ?? ''));
                        $q = sanitize_text_field((string) ($args['query'] ?? ''));
                        if ($node !== '' && $q !== '' && function_exists('xabia_federation_ask_node')) {
                            $tool_text = xabia_federation_ask_node($node, $q, $wp_project_id);
                        } elseif ($node !== '' && $q !== '' && class_exists('Xabia_Federation_Nexus', false)) {
                            $tool_text = Xabia_Federation_Nexus::ask_federated_node($node, $q, $wp_project_id);
                        } else {
                            $tool_text = __('Parámetros node o query vacíos.', 'xabia-intelligence');
                        }
                    } else {
                        $tool_text = __('Función no reconocida.', 'xabia-intelligence');
                    }
                    $fr_parts[] = [
                        'functionResponse' => [
                            'name'     => $name,
                            'response' => ['result' => $tool_text],
                        ],
                    ];
                }
                if ($fr_parts !== []) {
                    $body['contents'][] = [
                        'role'  => 'user',
                        'parts' => $fr_parts,
                    ];
                }
            }

            return __('Demasiadas rondas de herramientas (Vertex).', 'xabia-intelligence');
        }

        /**
         * Devuelve access_token, project_id y location para Vertex (reutilizable para chat y embeddings).
         * @return array{access_token: string, project_id: string, location: string}|null
         */
        private static function get_google_vertex_auth($config) {
            $json_path = self::resolve_gcloud_json_path($config);
            if (empty($json_path) || !file_exists($json_path)) return null;
            $engine_root = dirname(dirname($json_path));
            $vendor_path = $engine_root . '/vendor/autoload.php';
            if (!file_exists($vendor_path)) {
                $plugin_vendor = plugin_dir_path(dirname(dirname(__FILE__))) . 'vendor/autoload.php';
                if (file_exists($plugin_vendor)) $vendor_path = $plugin_vendor;
                else return null;
            }
            require_once $vendor_path;
            putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $json_path);
            $content = file_get_contents($json_path);
            $project_data = json_decode($content, true);
            if (!isset($project_data['project_id'])) return null;
            if (!class_exists('Google\Auth\Credentials\ServiceAccountCredentials')) return null;
            $creds = new \Google\Auth\Credentials\ServiceAccountCredentials('https://www.googleapis.com/auth/cloud-platform', $json_path);
            $token_data = $creds->fetchAuthToken();
            $access_token = $token_data['access_token'] ?? '';
            if (empty($access_token)) return null;
            return [
                'access_token' => $access_token,
                'project_id'   => $project_data['project_id'],
                'location'     => self::VERTEX_LOCAL_LOCATION,
            ];
        }

        /**
         * Embedding con Vertex AI (gemini-embedding-001). Mismo proveedor que el chat cuando ai_driver es google_cloud.
         * @return array|null Vector de floats o null si falla.
         */
        private static function get_vertex_embedding($text, $config) {
            if (class_exists('Xabia_Knowledge_Text', false)) {
                $text = Xabia_Knowledge_Text::embedding_input((string) $text);
            } else {
                $text = trim(strip_tags((string) $text));
            }
            if ($text === '') return null;
            $auth = self::get_google_vertex_auth($config);
            if ($auth === null) return null;
            $url = "https://{$auth['location']}-aiplatform.googleapis.com/v1/projects/{$auth['project_id']}/locations/{$auth['location']}/publishers/google/models/" . self::VERTEX_EMBEDDING_MODEL . ':predict';
            $body = json_encode([
                'instances' => [['content' => $text]],
            ]);
            if ($body === false) return null;
            $response = wp_remote_post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $auth['access_token'],
                    'Content-Type'  => 'application/json',
                ],
                'body'    => $body,
                'timeout' => 20,
            ]);
            if (is_wp_error($response)) return null;
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($data['predictions'][0]['embeddings']['values'])) {
                return $data['predictions'][0]['embeddings']['values'];
            }
            if (isset($data['predictions'][0]['embeddings'])) {
                $emb = $data['predictions'][0]['embeddings'];
                return isset($emb['values']) ? $emb['values'] : (is_array($emb) ? array_values($emb) : null);
            }
            return null;
        }

        /**
         * Embedding según ai_driver del proyecto: Google Vertex o OpenAI.
         * Usado por la API en búsqueda vectorial y por Admin en entrenar.
         * @param array $config Config del proyecto (debe incluir ai_driver y, si google_cloud, gcloud_json_path).
         * @return array|null Vector de floats o null.
         */
        public static function get_query_embedding($text, $config, $project_id = '') {
            if (class_exists('Xabia_Knowledge_Text', false)) {
                $text = Xabia_Knowledge_Text::embedding_input((string) $text);
            } else {
                $text = trim(strip_tags((string) $text));
            }
            if ($text === '') {
                return null;
            }
            $model = self::resolve_query_embedding_model(is_array($config) ? $config : []);
            if (class_exists('Xabia_Embedding_Cache', false)) {
                $cached = Xabia_Embedding_Cache::get($model, $text);
                if ($cached !== null) {
                    return $cached;
                }
            }
            $vector = null;
            if (($config['ai_driver'] ?? '') === 'google_cloud' && class_exists('Xabia_Digixop_Client') && Xabia_Digixop_Client::should_use_local_vertex($config)) {
                $vector = self::get_vertex_embedding($text, $config);
            } else {
                if (!class_exists('Xabia_Brain')) {
                    $brain_path = plugin_dir_path(dirname(__FILE__)) . 'core/class-xabia-brain.php';
                    if (file_exists($brain_path)) {
                        require_once $brain_path;
                    }
                }
                $vector = class_exists('Xabia_Brain') ? Xabia_Brain::get_embedding($text, $project_id) : null;
            }
            if (is_array($vector) && $vector !== [] && class_exists('Xabia_Embedding_Cache', false)) {
                Xabia_Embedding_Cache::set($model, $text, $vector);
            }

            return $vector;
        }

        /**
         * Modelo de embeddings usado en la ruta actual (clave de caché).
         */
        private static function resolve_query_embedding_model(array $config): string {
            if (($config['ai_driver'] ?? '') === 'google_cloud'
                && class_exists('Xabia_Digixop_Client', false)
                && Xabia_Digixop_Client::should_use_local_vertex($config)) {
                return self::VERTEX_EMBEDDING_MODEL;
            }

            return class_exists('Xabia_Brain', false) ? Xabia_Brain::EMBEDDING_MODEL : 'text-embedding-3-small';
        }

        /**
         * Para Admin/entrenar: obtiene el embedding según el proveedor configurado en el proyecto.
         */
        public static function get_embedding_for_project($text, $project_id) {
            $projects = get_option('xabia_projects_config', []);
            $config = $projects[$project_id] ?? [];
            return self::get_query_embedding($text, $config, $project_id);
        }
    }
    Xabia_API::init();
}