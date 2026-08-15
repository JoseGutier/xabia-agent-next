<?php
/**
 * Analítica nativa (eventos por agente). Datos locales; el Hub puede agregar tablas vía migración 011.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Analytics {

    public const OUTCOME_COMPLETE = 'complete';
    public const OUTCOME_NO_INFO = 'no_info';
    public const OUTCOME_ERROR = 'error';
    public const OUTCOME_PARTIAL = 'partial';

    /** Historial amplio de «sin información» por agente. */
    public const NO_INFO_HISTORY_LIMIT = 200;

    public static function init(): void {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_chart_assets'], 25);
        add_action('wp_ajax_xabia_analytics_stats', [self::class, 'ajax_stats']);
        add_action('wp_ajax_xabia_analytics_feedback', [self::class, 'ajax_feedback']);
        add_action('wp_ajax_nopriv_xabia_analytics_feedback', [self::class, 'ajax_feedback']);
        add_action('plugins_loaded', [self::class, 'maybe_upgrade_schema'], 25);
    }

    public static function maybe_upgrade_schema(): void {
        if (!class_exists('Xabia_DB', false) || !is_admin()) {
            return;
        }
        Xabia_DB::ensure_analytics_events_columns();
    }

    public static function enqueue_chart_assets(string $hook): void {
        if ($hook !== 'toplevel_page_xabia-settings') {
            return;
        }
        if (empty($_GET['edit']) || !is_string($_GET['edit'])) {
            return;
        }
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            [],
            '4.4.1',
            true
        );
    }

    /**
     * Visitante anónimo estable por sesión (sin PII).
     */
    public static function visitor_key_for_request(string $project_id = ''): string {
        if (!session_id() && !headers_sent()) {
            session_start();
        }
        $sid = session_id();
        if (!is_string($sid) || $sid === '') {
            $sid = (string) wp_generate_uuid4();
        }
        $raw = $sid . '|' . sanitize_key($project_id) . '|' . (string) (isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 80) : '');

        return substr(hash('sha256', $raw), 0, 32);
    }

    /**
     * Clasifica la respuesta del agente (agnóstico de dominio / idioma).
     */
    public static function classify_outcome(string $response, bool $had_knowledge, string $context = ''): string {
        $response = trim(wp_strip_all_tags($response));
        $forced = apply_filters('xabia_analytics_outcome', null, $response, $had_knowledge, $context);
        if (is_string($forced) && in_array($forced, [self::OUTCOME_COMPLETE, self::OUTCOME_NO_INFO, self::OUTCOME_ERROR, self::OUTCOME_PARTIAL], true)) {
            return $forced;
        }
        if ($response === '') {
            return self::OUTCOME_ERROR;
        }

        $lower = mb_strtolower($response, 'UTF-8');
        $error_needles = [
            'error de respuesta',
            'error api',
            'error conexión',
            'error conexion',
            'temporary failure',
            'saldo insuficiente',
            'insufficient',
            'tokens insuficientes',
        ];
        foreach ($error_needles as $needle) {
            if (mb_strpos($lower, $needle) !== false) {
                return self::OUTCOME_ERROR;
            }
        }

        $no_info_patterns = (array) apply_filters('xabia_analytics_no_info_patterns', [
            '/\bno tengo (información|informacion|datos|acceso|esa información|esa informacion)\b/u',
            '/\bno (encuentro|dispongo|cuento) con\b/u',
            '/\bno (está|esta) en (mis|los) datos\b/u',
            '/\bfuera de (mi|mis) (datos|conocimiento|alcance)\b/u',
            '/\bno (puedo|sé|se) (ayudar|responder) (con|sobre)?\s*(eso|esa|ese)?\b/u',
            '/\bez daukat\b/u',
            '/\bez dut (informaziorik|datorik|sarrerarik)\b/u',
            '/\bnire datuetan\b.*\bez\b/u',
            '/\bdon\'?t have\b/u',
            '/\bdo not have\b/u',
            '/\bno (information|data) (available|in|about)\b/u',
            '/\bi (don\'?t|do not) (know|have)\b/u',
            '/\bnot (in|within) (my|the) (data|knowledge|records)\b/u',
        ]);
        foreach ($no_info_patterns as $pattern) {
            if (is_string($pattern) && $pattern !== '' && @preg_match($pattern, $lower)) {
                return self::OUTCOME_NO_INFO;
            }
        }

        $ctx = mb_strtolower((string) $context, 'UTF-8');
        $system_miss = (str_contains($ctx, 'system_note:') && (
            str_contains($ctx, 'no arrojó')
            || str_contains($ctx, 'no arrojo')
            || str_contains($ctx, 'no devolvió')
            || str_contains($ctx, 'no devolvio')
            || str_contains($ctx, 'sin resultados')
        ));
        if ($system_miss && !$had_knowledge) {
            // Si el modelo inventó una respuesta útil sin RAG, no la marcamos como no_info.
            if (mb_strlen($response, 'UTF-8') < 280) {
                return self::OUTCOME_NO_INFO;
            }

            return self::OUTCOME_PARTIAL;
        }

        if (!$had_knowledge && mb_strlen($response, 'UTF-8') < 120) {
            return self::OUTCOME_PARTIAL;
        }

        return self::OUTCOME_COMPLETE;
    }

    public static function record_chat_event(string $project_id, array $args): void {
        global $wpdb;
        $project_id = sanitize_key($project_id);
        if ($project_id === '') {
            return;
        }
        if (class_exists('Xabia_DB', false)) {
            Xabia_DB::ensure_analytics_events_columns();
        }
        $table = Xabia_DB::table('analytics_events');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }
        $event_type = sanitize_key((string) ($args['event_type'] ?? 'message'));
        if (!in_array($event_type, ['conversation_start', 'message', 'feedback'], true)) {
            $event_type = 'message';
        }
        $source = substr(sanitize_text_field((string) ($args['source'] ?? 'web')), 0, 191);
        if ($source === '') {
            $source = 'web';
        }
        $qr_id = substr(sanitize_text_field((string) ($args['qr_id'] ?? '')), 0, 191);
        $rag_source = substr(sanitize_key((string) ($args['rag_source'] ?? '')), 0, 64);
        $rag_hit = !empty($args['rag_hit']) ? 1 : 0;
        $tokens_used = isset($args['tokens_used']) ? max(0, (int) $args['tokens_used']) : 0;
        $feedback = '';
        if (isset($args['feedback']) && $args['feedback'] !== '') {
            $fb = strtolower((string) $args['feedback']);
            $feedback = in_array($fb, ['up', 'down'], true) ? $fb : '';
        }
        $lang = substr(sanitize_key(strtolower((string) ($args['lang'] ?? ''))), 0, 16);
        if ($lang === '' && !empty($args['user_lang'])) {
            $ul = strtolower(preg_replace('/[^a-zA-Z\-]/', '', (string) $args['user_lang']) ?? '');
            $lang = substr($ul, 0, 16);
        }
        if (strlen($lang) > 2 && str_contains($lang, '-')) {
            $lang = substr($lang, 0, 2);
        }
        $visitor_key = substr(sanitize_text_field((string) ($args['visitor_key'] ?? '')), 0, 64);
        if ($visitor_key === '' && $event_type !== 'feedback') {
            $visitor_key = self::visitor_key_for_request($project_id);
        }
        $outcome = sanitize_key((string) ($args['outcome'] ?? ''));
        if ($outcome !== '' && !in_array($outcome, [self::OUTCOME_COMPLETE, self::OUTCOME_NO_INFO, self::OUTCOME_ERROR, self::OUTCOME_PARTIAL], true)) {
            $outcome = '';
        }
        $query_excerpt = '';
        if ($outcome === self::OUTCOME_NO_INFO || !empty($args['store_query_excerpt'])) {
            $query_excerpt = self::sanitize_query_excerpt((string) ($args['query_excerpt'] ?? $args['user_question'] ?? ''));
        }

        $wpdb->insert(
            $table,
            [
                'project_id'    => $project_id,
                'event_type'    => $event_type,
                'source'        => $source,
                'qr_id'         => $qr_id,
                'rag_source'    => $rag_source,
                'rag_hit'       => $rag_hit,
                'feedback'      => $feedback,
                'tokens_used'   => $tokens_used,
                'lang'          => $lang,
                'visitor_key'   => $visitor_key,
                'outcome'       => $outcome,
                'query_excerpt' => $query_excerpt,
                'created_at'    => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        if ($outcome === self::OUTCOME_NO_INFO) {
            self::prune_no_info_history($project_id);
        }
    }

    private static function sanitize_query_excerpt(string $text): string {
        $text = trim(wp_strip_all_tags($text));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        if (function_exists('mb_substr')) {
            $text = mb_substr($text, 0, 480, 'UTF-8');
        } else {
            $text = substr($text, 0, 480);
        }

        return sanitize_text_field($text);
    }

    /**
     * Conserva un historial amplio pero acotado de consultas sin información.
     */
    private static function prune_no_info_history(string $project_id): void {
        global $wpdb;
        $table = Xabia_DB::table('analytics_events');
        $limit = (int) apply_filters('xabia_analytics_no_info_history_limit', self::NO_INFO_HISTORY_LIMIT, $project_id);
        $limit = max(50, min(2000, $limit));
        $keep_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE project_id = %s AND outcome = %s ORDER BY id DESC LIMIT %d",
            $project_id,
            self::OUTCOME_NO_INFO,
            $limit
        ));
        if (!is_array($keep_ids) || count($keep_ids) < $limit) {
            return;
        }
        $min_keep = (int) min(array_map('intval', $keep_ids));
        if ($min_keep < 1) {
            return;
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET query_excerpt = '' WHERE project_id = %s AND outcome = %s AND id < %d AND query_excerpt != ''",
            $project_id,
            self::OUTCOME_NO_INFO,
            $min_keep
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public static function aggregate_for_project(string $project_id, int $days): array {
        global $wpdb;
        $project_id = sanitize_key($project_id);
        $days = max(1, min(366, $days));
        $table = Xabia_DB::table('analytics_events');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return self::empty_stats();
        }
        Xabia_DB::ensure_analytics_events_columns();
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

        $conversations = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE project_id = %s AND created_at >= %s AND event_type = 'conversation_start'",
            $project_id,
            $since
        ));
        $messages = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE project_id = %s AND created_at >= %s AND event_type = 'message'",
            $project_id,
            $since
        ));

        $users = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT visitor_key) FROM $table WHERE project_id = %s AND created_at >= %s AND event_type = 'message' AND visitor_key != ''",
            $project_id,
            $since
        ));

        $web = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE project_id = %s AND created_at >= %s AND event_type = 'message' AND source = 'web'",
            $project_id,
            $since
        ));
        $qr = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE project_id = %s AND created_at >= %s AND event_type = 'message' AND source IN ('qr_scan','qr_tunnel')",
            $project_id,
            $since
        ));

        $complete = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE project_id = %s AND created_at >= %s AND event_type = 'message' AND outcome = %s",
            $project_id,
            $since,
            self::OUTCOME_COMPLETE
        ));
        $no_info = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE project_id = %s AND created_at >= %s AND event_type = 'message' AND outcome = %s",
            $project_id,
            $since,
            self::OUTCOME_NO_INFO
        ));
        $errors = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE project_id = %s AND created_at >= %s AND event_type = 'message' AND outcome = %s",
            $project_id,
            $since,
            self::OUTCOME_ERROR
        ));
        $partial = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE project_id = %s AND created_at >= %s AND event_type = 'message' AND outcome = %s",
            $project_id,
            $since,
            self::OUTCOME_PARTIAL
        ));
        // Mensajes antiguos sin outcome: aproximar con rag_hit.
        $legacy_complete = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE project_id = %s AND created_at >= %s AND event_type = 'message' AND outcome = '' AND rag_hit = 1",
            $project_id,
            $since
        ));
        $legacy_no_info = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE project_id = %s AND created_at >= %s AND event_type = 'message' AND outcome = '' AND rag_hit = 0",
            $project_id,
            $since
        ));
        $complete += $legacy_complete;
        $no_info += $legacy_no_info;

        $tokens_sum = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(tokens_used),0) FROM $table WHERE project_id = %s AND created_at >= %s AND event_type = 'message'",
            $project_id,
            $since
        ));
        $avg_msgs = $users > 0 ? round($messages / $users, 1) : 0.0;
        $no_info_rate = $messages > 0 ? round(100 * $no_info / $messages, 1) : 0.0;

        $rag_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT rag_source, COUNT(*) AS c FROM $table WHERE project_id = %s AND created_at >= %s AND event_type = 'message' AND rag_hit = 1 AND rag_source != '' GROUP BY rag_source",
                $project_id,
                $since
            ),
            ARRAY_A
        );
        $rag_by_source = [];
        if (is_array($rag_rows)) {
            foreach ($rag_rows as $row) {
                $k = (string) ($row['rag_source'] ?? '');
                $rag_by_source[$k] = (int) ($row['c'] ?? 0);
            }
        }

        $lang_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT CASE WHEN lang = '' THEN '—' ELSE lang END AS lang_code, COUNT(*) AS c
                 FROM $table WHERE project_id = %s AND created_at >= %s AND event_type = 'message'
                 GROUP BY lang_code ORDER BY c DESC LIMIT 12",
                $project_id,
                $since
            ),
            ARRAY_A
        );
        $by_lang = [];
        if (is_array($lang_rows)) {
            foreach ($lang_rows as $row) {
                $by_lang[(string) ($row['lang_code'] ?? '—')] = (int) ($row['c'] ?? 0);
            }
        }

        $feedback_up = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE project_id = %s AND created_at >= %s AND event_type = 'feedback' AND feedback = 'up'",
            $project_id,
            $since
        ));
        $feedback_down = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE project_id = %s AND created_at >= %s AND event_type = 'feedback' AND feedback = 'down'",
            $project_id,
            $since
        ));

        $top_qr = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT qr_id, COUNT(*) AS c FROM $table WHERE project_id = %s AND created_at >= %s AND event_type = 'message' AND qr_id != '' GROUP BY qr_id ORDER BY c DESC LIMIT 12",
                $project_id,
                $since
            ),
            ARRAY_A
        );

        $daily = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM $table
                 WHERE project_id = %s AND created_at >= %s AND event_type = 'message'
                 GROUP BY DATE(created_at) ORDER BY d ASC",
                $project_id,
                $since
            ),
            ARRAY_A
        );

        $no_info_history = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT created_at, lang, source, qr_id, query_excerpt
                 FROM $table
                 WHERE project_id = %s AND outcome = %s AND query_excerpt != ''
                 ORDER BY id DESC LIMIT 80",
                $project_id,
                self::OUTCOME_NO_INFO
            ),
            ARRAY_A
        );

        return [
            'days'             => $days,
            'conversations'    => $conversations,
            'messages'         => $messages,
            'users'            => $users,
            'avg_msgs_user'    => $avg_msgs,
            'web_messages'     => $web,
            'qr_messages'      => $qr,
            'complete'         => $complete,
            'no_info'          => $no_info,
            'errors'           => $errors,
            'partial'          => $partial,
            'no_info_rate'     => $no_info_rate,
            'tokens_sum'       => $tokens_sum,
            'rag_by_source'    => $rag_by_source,
            'by_lang'          => $by_lang,
            'feedback_up'      => $feedback_up,
            'feedback_down'    => $feedback_down,
            'top_qr'           => is_array($top_qr) ? $top_qr : [],
            'daily'            => is_array($daily) ? $daily : [],
            'no_info_history'  => is_array($no_info_history) ? $no_info_history : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function empty_stats(): array {
        return [
            'days'            => 0,
            'conversations'   => 0,
            'messages'        => 0,
            'users'           => 0,
            'avg_msgs_user'   => 0,
            'web_messages'    => 0,
            'qr_messages'     => 0,
            'complete'        => 0,
            'no_info'         => 0,
            'errors'          => 0,
            'partial'         => 0,
            'no_info_rate'    => 0,
            'tokens_sum'      => 0,
            'rag_by_source'   => [],
            'by_lang'         => [],
            'feedback_up'     => 0,
            'feedback_down'   => 0,
            'top_qr'          => [],
            'daily'           => [],
            'no_info_history' => [],
        ];
    }

    public static function render_tab(string $edit_id, array $data): void {
        unset($data);
        if ($edit_id === '' || $edit_id === 'new') {
            echo '<p class="description">' . esc_html__('Guarda el agente para ver analítica.', 'xabia-intelligence') . '</p>';

            return;
        }
        ?>
        <div id="tab-analytics" class="xabia-tab-content">
            <h3 class="xabia-section-title"><?php echo esc_html__('Analítica', 'xabia-intelligence'); ?></h3>
            <p class="description"><?php echo esc_html__('Rendimiento del agente en este sitio. Los datos se guardan en local (sin PII): visitante anónimo por sesión, idioma, origen QR y tipo de respuesta.', 'xabia-intelligence'); ?></p>
            <p style="margin:12px 0;">
                <button type="button" class="button xabia-analytics-range" data-days="7"><?php echo esc_html__('7 días', 'xabia-intelligence'); ?></button>
                <button type="button" class="button button-primary xabia-analytics-range" data-days="30"><?php echo esc_html__('30 días', 'xabia-intelligence'); ?></button>
                <button type="button" class="button xabia-analytics-range" data-days="90"><?php echo esc_html__('90 días', 'xabia-intelligence'); ?></button>
            </p>
            <div id="xabia-analytics-root" data-project="<?php echo esc_attr($edit_id); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('xabia_admin_nonce')); ?>">
                <div class="xabia-analytics-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin:16px 0;">
                    <div class="xabia-panel-muted" style="padding:12px;border-radius:8px;"><strong><?php echo esc_html__('Usuarios', 'xabia-intelligence'); ?></strong><div id="xabia-stat-users" style="font-size:1.6rem;margin-top:6px;">—</div></div>
                    <div class="xabia-panel-muted" style="padding:12px;border-radius:8px;"><strong><?php echo esc_html__('Mensajes', 'xabia-intelligence'); ?></strong><div id="xabia-stat-messages" style="font-size:1.6rem;margin-top:6px;">—</div></div>
                    <div class="xabia-panel-muted" style="padding:12px;border-radius:8px;"><strong><?php echo esc_html__('Conversaciones', 'xabia-intelligence'); ?></strong><div id="xabia-stat-conv" style="font-size:1.6rem;margin-top:6px;">—</div></div>
                    <div class="xabia-panel-muted" style="padding:12px;border-radius:8px;"><strong><?php echo esc_html__('Respuestas completas', 'xabia-intelligence'); ?></strong><div id="xabia-stat-complete" style="font-size:1.6rem;margin-top:6px;">—</div></div>
                    <div class="xabia-panel-muted" style="padding:12px;border-radius:8px;"><strong><?php echo esc_html__('Sin información', 'xabia-intelligence'); ?></strong><div id="xabia-stat-noinfo" style="font-size:1.6rem;margin-top:6px;">—</div><div id="xabia-stat-noinfo-rate" class="description" style="margin-top:4px;"></div></div>
                    <div class="xabia-panel-muted" style="padding:12px;border-radius:8px;"><strong><?php echo esc_html__('Valoraciones +/-', 'xabia-intelligence'); ?></strong><div id="xabia-stat-feedback" style="font-size:1rem;margin-top:6px;">—</div></div>
                    <div class="xabia-panel-muted" style="padding:12px;border-radius:8px;"><strong><?php echo esc_html__('Media msgs/usuario', 'xabia-intelligence'); ?></strong><div id="xabia-stat-avg" style="font-size:1.6rem;margin-top:6px;">—</div></div>
                    <div class="xabia-panel-muted" style="padding:12px;border-radius:8px;"><strong><?php echo esc_html__('Tokens (periodo)', 'xabia-intelligence'); ?></strong><div id="xabia-stat-tokens" style="font-size:1.2rem;margin-top:6px;">—</div></div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px;margin:20px 0;">
                    <div style="max-width:520px;"><canvas id="xabia-chart-origin" height="220" aria-label="<?php echo esc_attr__('Origen: web vs QR', 'xabia-intelligence'); ?>"></canvas></div>
                    <div style="max-width:520px;"><canvas id="xabia-chart-outcome" height="220" aria-label="<?php echo esc_attr__('Tipo de respuesta', 'xabia-intelligence'); ?>"></canvas></div>
                    <div style="max-width:520px;"><canvas id="xabia-chart-lang" height="220" aria-label="<?php echo esc_attr__('Idioma', 'xabia-intelligence'); ?>"></canvas></div>
                    <div style="max-width:520px;"><canvas id="xabia-chart-rag" height="220" aria-label="<?php echo esc_attr__('RAG por fuente', 'xabia-intelligence'); ?>"></canvas></div>
                </div>
                <div style="max-width:920px;margin:20px 0;">
                    <canvas id="xabia-chart-daily" height="120" aria-label="<?php echo esc_attr__('Mensajes por día', 'xabia-intelligence'); ?>"></canvas>
                </div>
                <div id="xabia-top-qr" class="description" style="margin:16px 0;"></div>
                <div style="margin:24px 0;">
                    <h4 style="margin:0 0 8px;"><?php echo esc_html__('Historial «sin información»', 'xabia-intelligence'); ?></h4>
                    <p class="description" style="margin:0 0 10px;"><?php echo esc_html__('Consultas en las que el agente indicó no disponer de datos (se conservan de forma amplia y anónima para mejorar el catálogo).', 'xabia-intelligence'); ?></p>
                    <div style="max-height:360px;overflow:auto;border:1px solid #c3c4c7;border-radius:8px;">
                        <table class="widefat striped" id="xabia-noinfo-table" style="margin:0;">
                            <thead><tr>
                                <th><?php echo esc_html__('Fecha', 'xabia-intelligence'); ?></th>
                                <th><?php echo esc_html__('Idioma', 'xabia-intelligence'); ?></th>
                                <th><?php echo esc_html__('Origen', 'xabia-intelligence'); ?></th>
                                <th><?php echo esc_html__('QR', 'xabia-intelligence'); ?></th>
                                <th><?php echo esc_html__('Consulta', 'xabia-intelligence'); ?></th>
                            </tr></thead>
                            <tbody id="xabia-noinfo-tbody"><tr><td colspan="5">—</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <script>
        (function($){
            var originChart, outcomeChart, langChart, ragChart, dailyChart;
            function esc(s){ return $('<div/>').text(String(s == null ? '' : s)).html(); }
            function paint(root, s) {
                $('#xabia-stat-users').text(s.users != null ? s.users : '0');
                $('#xabia-stat-messages').text(s.messages != null ? s.messages : '0');
                $('#xabia-stat-conv').text(s.conversations != null ? s.conversations : '0');
                $('#xabia-stat-complete').text(s.complete != null ? s.complete : '0');
                $('#xabia-stat-noinfo').text(s.no_info != null ? s.no_info : '0');
                $('#xabia-stat-noinfo-rate').text((s.no_info_rate != null ? s.no_info_rate : 0) + '% <?php echo esc_js(__('de mensajes', 'xabia-intelligence')); ?>');
                $('#xabia-stat-feedback').text('+' + (s.feedback_up || 0) + ' / −' + (s.feedback_down || 0));
                $('#xabia-stat-avg').text(s.avg_msgs_user != null ? s.avg_msgs_user : '0');
                $('#xabia-stat-tokens').text(s.tokens_sum != null ? Number(s.tokens_sum).toLocaleString() : '0');
                var web = s.web_messages || 0;
                var qr = s.qr_messages || 0;
                if (window.Chart) {
                    var ctxO = document.getElementById('xabia-chart-origin');
                    if (ctxO) {
                        if (originChart) originChart.destroy();
                        originChart = new Chart(ctxO, {
                            type: 'doughnut',
                            data: {
                                labels: ['<?php echo esc_js(__('Web', 'xabia-intelligence')); ?>', '<?php echo esc_js(__('Smart QR / túnel', 'xabia-intelligence')); ?>'],
                                datasets: [{ data: [web, qr], backgroundColor: ['#2271b1', '#00a32a'] }]
                            },
                            options: { plugins: { title: { display: true, text: '<?php echo esc_js(__('Origen del tráfico', 'xabia-intelligence')); ?>' } } }
                        });
                    }
                    var ctxOut = document.getElementById('xabia-chart-outcome');
                    if (ctxOut) {
                        if (outcomeChart) outcomeChart.destroy();
                        outcomeChart = new Chart(ctxOut, {
                            type: 'doughnut',
                            data: {
                                labels: [
                                    '<?php echo esc_js(__('Completas', 'xabia-intelligence')); ?>',
                                    '<?php echo esc_js(__('Sin información', 'xabia-intelligence')); ?>',
                                    '<?php echo esc_js(__('Parciales', 'xabia-intelligence')); ?>',
                                    '<?php echo esc_js(__('Errores', 'xabia-intelligence')); ?>'
                                ],
                                datasets: [{ data: [s.complete||0, s.no_info||0, s.partial||0, s.errors||0], backgroundColor: ['#00a32a', '#d63638', '#dba617', '#646970'] }]
                            },
                            options: { plugins: { title: { display: true, text: '<?php echo esc_js(__('Tipo de respuesta', 'xabia-intelligence')); ?>' } } }
                        });
                    }
                    var byLang = s.by_lang || {};
                    var langLabels = Object.keys(byLang);
                    var langVals = langLabels.map(function(k){ return byLang[k]; });
                    var ctxL = document.getElementById('xabia-chart-lang');
                    if (ctxL) {
                        if (langChart) langChart.destroy();
                        langChart = new Chart(ctxL, {
                            type: 'bar',
                            data: {
                                labels: langLabels.length ? langLabels : ['—'],
                                datasets: [{ label: '<?php echo esc_js(__('Mensajes', 'xabia-intelligence')); ?>', data: langVals.length ? langVals : [0], backgroundColor: '#3858e9' }]
                            },
                            options: { scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }, plugins: { title: { display: true, text: '<?php echo esc_js(__('Por idioma', 'xabia-intelligence')); ?>' } } }
                        });
                    }
                    var rag = s.rag_by_source || {};
                    var labels = Object.keys(rag);
                    var vals = labels.map(function(k){ return rag[k]; });
                    var ctxR = document.getElementById('xabia-chart-rag');
                    if (ctxR) {
                        if (ragChart) ragChart.destroy();
                        ragChart = new Chart(ctxR, {
                            type: 'bar',
                            data: {
                                labels: labels.length ? labels : ['—'],
                                datasets: [{ label: '<?php echo esc_js(__('Hits RAG', 'xabia-intelligence')); ?>', data: vals.length ? vals : [0], backgroundColor: '#50575e' }]
                            },
                            options: { scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }, plugins: { title: { display: true, text: '<?php echo esc_js(__('RAG por fuente', 'xabia-intelligence')); ?>' } } }
                        });
                    }
                    var daily = s.daily || [];
                    var ctxD = document.getElementById('xabia-chart-daily');
                    if (ctxD) {
                        if (dailyChart) dailyChart.destroy();
                        dailyChart = new Chart(ctxD, {
                            type: 'line',
                            data: {
                                labels: daily.map(function(r){ return r.d; }),
                                datasets: [{ label: '<?php echo esc_js(__('Mensajes / día', 'xabia-intelligence')); ?>', data: daily.map(function(r){ return Number(r.c)||0; }), borderColor: '#2271b1', tension: 0.2, fill: false }]
                            },
                            options: { scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }, plugins: { title: { display: true, text: '<?php echo esc_js(__('Actividad diaria', 'xabia-intelligence')); ?>' } } }
                        });
                    }
                }
                var rows = s.top_qr || [];
                var h = '';
                if (rows.length) {
                    h += '<strong><?php echo esc_js(__('QRs con más interacciones', 'xabia-intelligence')); ?></strong><ul style="margin:8px 0 0 1.2em;">';
                    rows.forEach(function(r){
                        h += '<li><code>' + esc(r.qr_id || '') + '</code>: ' + esc(r.c || 0) + '</li>';
                    });
                    h += '</ul>';
                } else {
                    h = '<span class="description"><?php echo esc_js(__('Sin tráfico atribuido a QR en este periodo.', 'xabia-intelligence')); ?></span>';
                }
                $('#xabia-top-qr').html(h);
                var hist = s.no_info_history || [];
                var tb = '';
                if (!hist.length) {
                    tb = '<tr><td colspan="5"><?php echo esc_js(__('Sin consultas «sin información» registradas aún.', 'xabia-intelligence')); ?></td></tr>';
                } else {
                    hist.forEach(function(r){
                        var src = r.source === 'qr_scan' || r.source === 'qr_tunnel' ? 'QR' : 'Web';
                        tb += '<tr><td>' + esc(r.created_at || '') + '</td><td>' + esc(r.lang || '—') + '</td><td>' + esc(src) + '</td><td><code>' + esc(r.qr_id || '—') + '</code></td><td>' + esc(r.query_excerpt || '') + '</td></tr>';
                    });
                }
                $('#xabia-noinfo-tbody').html(tb);
            }
            function load(days) {
                var $root = $('#xabia-analytics-root');
                $.post(ajaxurl, {
                    action: 'xabia_analytics_stats',
                    nonce: $root.data('nonce'),
                    project_id: $root.data('project'),
                    days: days
                }).done(function(r){
                    if (r && r.success && r.data && r.data.stats) {
                        paint($root, r.data.stats);
                    }
                });
            }
            $(function(){
                $(document).on('click', '.xabia-analytics-range', function(){
                    $('.xabia-analytics-range').removeClass('button-primary');
                    $(this).addClass('button-primary');
                    load(parseInt($(this).data('days'), 10) || 30);
                });
                if ($('#xabia-analytics-root').length) {
                    load(30);
                }
            });
        })(jQuery);
        </script>
        <?php
    }

    public static function ajax_stats(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permiso denegado.', 'xabia-intelligence')]);
        }
        check_ajax_referer('xabia_admin_nonce', 'nonce');
        $pid = isset($_POST['project_id']) ? sanitize_key(wp_unslash((string) $_POST['project_id'])) : '';
        $days = isset($_POST['days']) ? (int) $_POST['days'] : 30;
        if ($pid === '') {
            wp_send_json_error(['message' => __('Bad request.', 'xabia-intelligence')]);
        }
        wp_send_json_success(['stats' => self::aggregate_for_project($pid, $days)]);
    }

    public static function ajax_feedback(): void {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['nonce'])), 'xabia_nonce')) {
            wp_send_json_error(['message' => __('La comprobación de seguridad falló.', 'xabia-intelligence')]);
        }
        $pid = isset($_POST['project_id']) ? sanitize_key(wp_unslash((string) $_POST['project_id'])) : '';
        $fb = isset($_POST['feedback']) ? strtolower(sanitize_text_field(wp_unslash((string) $_POST['feedback']))) : '';
        if ($pid === '' || !in_array($fb, ['up', 'down'], true)) {
            wp_send_json_error(['message' => __('Bad request.', 'xabia-intelligence')]);
        }
        self::record_chat_event($pid, [
            'event_type'  => 'feedback',
            'feedback'    => $fb,
            'source'      => 'web',
            'visitor_key' => self::visitor_key_for_request($pid),
        ]);
        wp_send_json_success(['ok' => true]);
    }

    /**
     * Canal de origen y qr_id opcional.
     *
     * @return array{source: string, qr_id: string}
     */
    public static function detect_channel(string $project_id, string $ente_id_param_raw): array {
        $ente_id_param_raw = trim($ente_id_param_raw);
        if (function_exists('xabia_qr_scan_active_for_project')) {
            $scan = xabia_qr_scan_active_for_project($project_id);
            if (is_array($scan) && !empty($scan['qr_id'])) {
                return [
                    'source' => 'qr_scan',
                    'qr_id'  => (string) $scan['qr_id'],
                ];
            }
        }
        if ($ente_id_param_raw !== '') {
            return [
                'source' => 'qr_tunnel',
                'qr_id'  => substr(sanitize_text_field($ente_id_param_raw), 0, 191),
            ];
        }

        return ['source' => 'web', 'qr_id' => ''];
    }

    public static function infer_rag_source(array $config): string {
        $st = sanitize_key((string) ($config['source_type'] ?? ''));
        if ($st === 'addon') {
            return sanitize_key((string) ($config['addon_slug'] ?? 'addon')) ?: 'addon';
        }
        if ($st === 'multi') {
            return 'multi';
        }
        if ($st === 'csv') {
            return 'csv';
        }
        if ($st === 'sql' || $st === 'local_sql') {
            return 'sql';
        }
        if ($st === 'web_pages') {
            return 'web_pages';
        }

        return $st !== '' ? $st : 'unknown';
    }
}
