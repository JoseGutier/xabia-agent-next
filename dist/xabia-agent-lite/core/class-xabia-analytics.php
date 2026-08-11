<?php
/**
 * Analítica nativa (eventos por agente). Datos locales; el Hub puede agregar tablas vía migración 011.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Analytics {

    public static function init(): void {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_chart_assets'], 25);
        add_action('wp_ajax_xabia_analytics_stats', [self::class, 'ajax_stats']);
        add_action('wp_ajax_xabia_analytics_feedback', [self::class, 'ajax_feedback']);
        add_action('wp_ajax_nopriv_xabia_analytics_feedback', [self::class, 'ajax_feedback']);
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

    public static function record_chat_event(string $project_id, array $args): void {
        global $wpdb;
        $project_id = sanitize_key($project_id);
        if ($project_id === '') {
            return;
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

        $wpdb->insert(
            $table,
            [
                'project_id'   => $project_id,
                'event_type'   => $event_type,
                'source'       => $source,
                'qr_id'        => $qr_id,
                'rag_source'   => $rag_source,
                'rag_hit'      => $rag_hit,
                'feedback'     => $feedback,
                'tokens_used'  => $tokens_used,
                'created_at'   => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s']
        );
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
                "SELECT qr_id, COUNT(*) AS c FROM $table WHERE project_id = %s AND created_at >= %s AND event_type = 'message' AND qr_id != '' GROUP BY qr_id ORDER BY c DESC LIMIT 8",
                $project_id,
                $since
            ),
            ARRAY_A
        );

        return [
            'days'             => $days,
            'conversations'    => $conversations,
            'messages'         => $messages,
            'web_messages'     => $web,
            'qr_messages'      => $qr,
            'rag_by_source'    => $rag_by_source,
            'feedback_up'      => $feedback_up,
            'feedback_down'    => $feedback_down,
            'top_qr'           => is_array($top_qr) ? $top_qr : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function empty_stats(): array {
        return [
            'days'          => 0,
            'conversations' => 0,
            'messages'      => 0,
            'web_messages'  => 0,
            'qr_messages'   => 0,
            'rag_by_source' => [],
            'feedback_up'   => 0,
            'feedback_down' => 0,
            'top_qr'        => [],
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
            <p class="description"><?php echo esc_html__('Rendimiento del agente en este sitio (últimos 7, 30 y 90 días). Los datos se registran con cada conversación del chat.', 'xabia-intelligence'); ?></p>
            <p style="margin:12px 0;">
                <button type="button" class="button xabia-analytics-range" data-days="7"><?php echo esc_html__('7 días', 'xabia-intelligence'); ?></button>
                <button type="button" class="button button-primary xabia-analytics-range" data-days="30"><?php echo esc_html__('30 días', 'xabia-intelligence'); ?></button>
                <button type="button" class="button xabia-analytics-range" data-days="90"><?php echo esc_html__('90 días', 'xabia-intelligence'); ?></button>
            </p>
            <div id="xabia-analytics-root" data-project="<?php echo esc_attr($edit_id); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('xabia_admin_nonce')); ?>">
                <div class="xabia-analytics-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin:16px 0;">
                    <div class="xabia-panel-muted" style="padding:12px;border-radius:8px;"><strong><?php echo esc_html__('Interacciones (mensajes)', 'xabia-intelligence'); ?></strong><div id="xabia-stat-messages" style="font-size:1.6rem;margin-top:6px;">—</div></div>
                    <div class="xabia-panel-muted" style="padding:12px;border-radius:8px;"><strong><?php echo esc_html__('Conversaciones nuevas', 'xabia-intelligence'); ?></strong><div id="xabia-stat-conv" style="font-size:1.6rem;margin-top:6px;">—</div></div>
                    <div class="xabia-panel-muted" style="padding:12px;border-radius:8px;"><strong><?php echo esc_html__('Valoraciones +/-', 'xabia-intelligence'); ?></strong><div id="xabia-stat-feedback" style="font-size:1rem;margin-top:6px;">—</div></div>
                </div>
                <div style="max-width:520px;margin:20px 0;">
                    <canvas id="xabia-chart-origin" height="220" aria-label="<?php echo esc_attr__('Origen: web vs QR', 'xabia-intelligence'); ?>"></canvas>
                </div>
                <div style="max-width:520px;margin:20px 0;">
                    <canvas id="xabia-chart-rag" height="240" aria-label="<?php echo esc_attr__('RAG por fuente', 'xabia-intelligence'); ?>"></canvas>
                </div>
                <div id="xabia-top-qr" class="description"></div>
            </div>
        </div>
        <script>
        (function($){
            var originChart, ragChart;
            function paint(root, s) {
                $('#xabia-stat-messages').text(s.messages != null ? s.messages : '0');
                $('#xabia-stat-conv').text(s.conversations != null ? s.conversations : '0');
                $('#xabia-stat-feedback').text('+' + (s.feedback_up || 0) + ' / −' + (s.feedback_down || 0));
                var web = s.web_messages || 0;
                var qr = s.qr_messages || 0;
                var ctxO = document.getElementById('xabia-chart-origin');
                if (ctxO && window.Chart) {
                    if (originChart) originChart.destroy();
                    originChart = new Chart(ctxO, {
                        type: 'doughnut',
                        data: {
                            labels: ['<?php echo esc_js(__('Web', 'xabia-intelligence')); ?>', '<?php echo esc_js(__('Smart QR / túnel', 'xabia-intelligence')); ?>'],
                            datasets: [{ data: [web, qr], backgroundColor: ['#2271b1', '#00a32a'] }]
                        },
                        options: { plugins: { title: { display: true, text: '<?php echo esc_js(__('Origen del tráfico (mensajes)', 'xabia-intelligence')); ?>' } } }
                    });
                }
                var rag = s.rag_by_source || {};
                var labels = Object.keys(rag);
                var vals = labels.map(function(k){ return rag[k]; });
                var ctxR = document.getElementById('xabia-chart-rag');
                if (ctxR && window.Chart && labels.length) {
                    if (ragChart) ragChart.destroy();
                    ragChart = new Chart(ctxR, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{ label: '<?php echo esc_js(__('Respuestas con contexto RAG', 'xabia-intelligence')); ?>', data: vals, backgroundColor: '#50575e' }]
                        },
                        options: { scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }, plugins: { title: { display: true, text: '<?php echo esc_js(__('RAG por tipo de fuente', 'xabia-intelligence')); ?>' } } }
                    });
                } else if (ctxR && window.Chart) {
                    if (ragChart) ragChart.destroy();
                    ragChart = new Chart(ctxR, {
                        type: 'bar',
                        data: { labels: ['—'], datasets: [{ label: 'RAG', data: [0], backgroundColor: '#c3c4c7' }] },
                        options: { plugins: { title: { display: true, text: '<?php echo esc_js(__('Sin hits RAG en el periodo', 'xabia-intelligence')); ?>' } } }
                    });
                }
                var rows = s.top_qr || [];
                var h = '';
                if (rows.length) {
                    h += '<strong><?php echo esc_js(__('QRs con más interacciones', 'xabia-intelligence')); ?></strong><ul style="margin:8px 0 0 1.2em;">';
                    rows.forEach(function(r){
                        h += '<li><code>' + $('<div/>').text(String(r.qr_id || '')).html() + '</code>: ' + String(r.c || 0) + '</li>';
                    });
                    h += '</ul>';
                }
                $('#xabia-top-qr').html(h);
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
            'event_type' => 'feedback',
            'feedback'   => $fb,
            'source'     => 'web',
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
                'qr_id'  => '',
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

        return $st !== '' ? $st : 'unknown';
    }
}
