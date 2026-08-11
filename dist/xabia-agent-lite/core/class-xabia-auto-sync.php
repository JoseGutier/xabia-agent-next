<?php
/**
 * Xabia — sincronización automática (inmediata en local, cron ajustable en remoto).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Auto_Sync {

    public const CRON_HOOK = 'xabia_auto_sync_tick';
    public const DEBOUNCE_HOOK = 'xabia_debounced_project_sync';
    public const TRAIN_HOOK = 'xabia_auto_train_project';
    public const CLOUD_HOOK = 'xabia_auto_cloud_project';
    public const CLOUD_CRON_HOOK = 'xabia_hub_cloud_cron_run';
    public const OPTION_STATE = 'xabia_auto_sync_state';

    /** @var array<string, array{label:string,seconds:int}> */
    public const INTERVALS = [
        'off'       => ['label' => 'Desactivada', 'seconds' => 0],
        'immediate' => ['label' => 'En cuanto haya cambios (unos 1–2 min)', 'seconds' => 90],
        '5min'      => ['label' => 'Cada 5 minutos', 'seconds' => 300],
        '15min'     => ['label' => 'Cada 15 minutos', 'seconds' => 900],
        '30min'     => ['label' => 'Cada 30 minutos', 'seconds' => 1800],
        '1hour'     => ['label' => 'Cada hora', 'seconds' => 3600],
        '6hours'    => ['label' => 'Cada 6 horas', 'seconds' => 21600],
        '12hours'   => ['label' => 'Cada 12 horas', 'seconds' => 43200],
        'daily'     => ['label' => 'Una vez al día', 'seconds' => 86400],
    ];

    /**
     * @return array<string, string>
     */
    public static function interval_options(): array {
        $out = [];
        foreach (self::INTERVALS as $key => $def) {
            $out[$key] = __($def['label'], 'xabia-intelligence');
        }

        return $out;
    }

    public static function record_manual_sync(string $project_id, int $count): void {
        self::mark_synced(sanitize_key($project_id), 'manual', $count);
    }

    public static function init(): void {
        add_filter('cron_schedules', [self::class, 'register_cron_schedules']);
        add_action(self::CRON_HOOK, [self::class, 'run_scheduled_syncs']);
        add_action(self::DEBOUNCE_HOOK, [self::class, 'run_debounced_project'], 10, 1);
        if (class_exists('Xabia_Knowledge_Optimizer', false)) {
            add_action(Xabia_Knowledge_Optimizer::COOLDOWN_HOOK, [Xabia_Knowledge_Optimizer::class, 'fire_cooldown_sync'], 10, 1);
        }
        add_action(self::TRAIN_HOOK, [self::class, 'run_scheduled_train'], 10, 1);
        add_action(self::CLOUD_HOOK, [self::class, 'run_scheduled_cloud'], 10, 1);
        add_action(self::CLOUD_CRON_HOOK, [self::class, 'run_cloud_cron_pipeline']);
        add_action('xabia_after_knowledge_sync', [self::class, 'on_after_knowledge_sync'], 20, 3);
        add_action('init', [self::class, 'ensure_cron_scheduled'], 30);
        add_action('save_post', [self::class, 'on_save_post'], 20, 3);
        add_action('woocommerce_update_product', [self::class, 'on_woocommerce_product_change'], 20, 1);
        add_action('woocommerce_product_set_stock', [self::class, 'on_woocommerce_product_change'], 20, 1);
    }

    /**
     * @param array<string, array{interval:int,display:string}> $schedules
     * @return array<string, array{interval:int,display:string}>
     */
    public static function register_cron_schedules(array $schedules): array {
        $schedules['xabia_every_5_minutes'] = [
            'interval' => 300,
            'display'  => __('Cada 5 minutos (Xabia auto-sync)', 'xabia-intelligence'),
        ];

        return $schedules;
    }

    public static function ensure_cron_scheduled(): void {
        self::retire_legacy_central_cron();
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 120, 'xabia_every_5_minutes', self::CRON_HOOK);
        }
    }

    private static function retire_legacy_central_cron(): void {
        while ($ts = wp_next_scheduled('xabia_central_hourly_sync')) {
            wp_unschedule_event($ts, 'xabia_central_hourly_sync');
        }
    }

    public static function run_scheduled_syncs(): void {
        if (!class_exists('Xabia_Knowledge_Sync', false)) {
            return;
        }
        foreach (self::iter_syncable_projects() as $project_id => $config) {
            if (!self::is_auto_sync_enabled($config)) {
                continue;
            }
            if (!self::is_due_for_sync($project_id, $config)) {
                continue;
            }
            self::execute_project_sync($project_id, 'cron', true);
        }
        self::run_scheduled_pipeline_batches();
    }

    public static function run_scheduled_pipeline_batches(): void {
        foreach (self::iter_syncable_projects() as $project_id => $config) {
            if (!self::is_auto_train_enabled($config)) {
                continue;
            }
            if (!class_exists('Xabia_Knowledge_Train', false)) {
                continue;
            }
            if (Xabia_Knowledge_Train::count_pending($project_id) <= 0) {
                continue;
            }
            if (self::pipeline_step_too_soon($project_id, 'last_train_at', 45)) {
                continue;
            }
            self::run_train_step($project_id, 'cron');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function on_after_knowledge_sync(string $project_id, array $config, int $count): void {
        unset($count);
        if (!self::pipeline_enabled_for_config($config)) {
            return;
        }
        self::start_pipeline($project_id, $config);
    }

    /**
     * @param mixed $project_id
     */
    public static function run_scheduled_train($project_id): void {
        $project_id = sanitize_key((string) $project_id);
        if ($project_id === '') {
            return;
        }
        self::run_train_step($project_id, 'scheduled');
    }

    /**
     * @param mixed $project_id
     */
    public static function run_scheduled_cloud($project_id): void {
        $project_id = sanitize_key((string) $project_id);
        if ($project_id === '') {
            return;
        }
        self::run_cloud_step($project_id, 'scheduled');
    }

    /**
     * @param mixed $post_id
     */
    public static function run_debounced_project($post_id): void {
        $project_id = sanitize_key((string) $post_id);
        if ($project_id === '') {
            return;
        }
        $projects = get_option('xabia_projects_config', []);
        $config = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : null;
        if ($config === null || !self::is_auto_sync_enabled($config)) {
            return;
        }
        if (self::get_interval_key($config) !== 'immediate' || Xabia_Knowledge_Sync::is_remote_config($config)) {
            return;
        }
        self::execute_project_sync($project_id, 'debounce', true);
    }

    /**
     * Sync incremental (auto): sin borrado masivo + filtro SQL por last_successful_sync.
     */
    public static function execute_incremental_sync(string $project_id, string $trigger): void {
        self::execute_project_sync($project_id, $trigger, true);
    }

    /**
     * Pipeline completo de un agente (sync → train → cloud). Invocado por el Reloj Maestro del Hub.
     */
    public static function run_project(string $project_id, bool $incremental = true): void {
        $project_id = sanitize_key($project_id);
        if ($project_id === '') {
            return;
        }
        self::execute_project_sync($project_id, 'hub_cloud_cron', $incremental);
    }

    /**
     * Todos los agentes con auto-sync activo (disparo desde cloud-cron-trigger).
     */
    public static function run_cloud_cron_pipeline(bool $incremental = true): void {
        foreach (self::iter_syncable_projects() as $project_id => $config) {
            if (!self::is_auto_sync_enabled($config)) {
                continue;
            }
            self::execute_project_sync($project_id, 'hub_cloud_cron', $incremental);
        }
        self::run_scheduled_pipeline_batches();
    }

    /**
     * Encola el pipeline y dispara wp-cron en loopback no bloqueante.
     */
    public static function dispatch_cloud_cron_async(): void {
        if (!wp_next_scheduled(self::CLOUD_CRON_HOOK)) {
            wp_schedule_single_event(time(), self::CLOUD_CRON_HOOK);
        }

        wp_remote_post(
            site_url('wp-cron.php'),
            [
                'timeout'   => 0.01,
                'blocking'  => false,
                'sslverify' => apply_filters('https_local_ssl_verify', false),
                'body'      => [
                    'doing_wp_cron' => sprintf('%.22F', microtime(true)),
                ],
            ]
        );
    }

    /**
     * @param int      $post_id
     * @param \WP_Post $post
     * @param bool     $update
     */
    public static function on_save_post(int $post_id, $post, bool $update): void {
        unset($update);
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!is_object($post) || !isset($post->post_type, $post->post_status)) {
            return;
        }
        if (in_array($post->post_status, ['auto-draft', 'trash'], true)) {
            return;
        }

        foreach (self::iter_syncable_projects() as $project_id => $config) {
            if (!self::is_auto_sync_enabled($config)) {
                continue;
            }
            if (self::get_interval_key($config) !== 'immediate') {
                continue;
            }
            if (Xabia_Knowledge_Sync::is_remote_config($config)) {
                continue;
            }
            if (!self::post_matches_project((string) $post->post_type, $config)) {
                continue;
            }
            self::request_cooldown_sync($project_id);
        }
    }

    /**
     * @param int|string $product_id
     */
    public static function on_woocommerce_product_change($product_id): void {
        unset($product_id);
        foreach (self::iter_syncable_projects() as $project_id => $config) {
            if (!self::is_auto_sync_enabled($config)) {
                continue;
            }
            if (self::get_interval_key($config) !== 'immediate') {
                continue;
            }
            if (Xabia_Knowledge_Sync::is_remote_config($config)) {
                continue;
            }
            if (($config['source_type'] ?? '') !== 'addon' || ($config['addon_slug'] ?? '') !== 'woo') {
                continue;
            }
            self::request_cooldown_sync($project_id);
        }
    }

    private static function request_cooldown_sync(string $project_id): void {
        if (class_exists('Xabia_Knowledge_Optimizer', false)) {
            Xabia_Knowledge_Optimizer::request_cooldown_sync($project_id);

            return;
        }
        self::request_debounced_sync($project_id);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function is_auto_sync_enabled(array $config): bool {
        $auto = $config['auto_sync'] ?? [];
        if (!is_array($auto)) {
            return self::get_interval_key($config) !== 'off';
        }
        if (array_key_exists('enabled', $auto)) {
            return !empty($auto['enabled']) && self::get_interval_key($config) !== 'off';
        }

        return self::get_interval_key($config) !== 'off';
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function get_interval_key(array $config): string {
        $auto = $config['auto_sync'] ?? [];
        $key = is_array($auto) ? sanitize_key((string) ($auto['interval'] ?? '')) : '';
        if ($key === '' || !isset(self::INTERVALS[$key])) {
            return Xabia_Knowledge_Sync::is_remote_config($config) ? '1hour' : 'immediate';
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function sanitize_interval_for_config(string $interval, array $config): string {
        $interval = sanitize_key($interval);
        if ($interval === '' || !isset(self::INTERVALS[$interval])) {
            $interval = Xabia_Knowledge_Sync::is_remote_config($config) ? '1hour' : 'immediate';
        }
        if ($interval === 'immediate' && Xabia_Knowledge_Sync::is_remote_config($config)) {
            $interval = '1hour';
        }

        return $interval;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function default_auto_sync_config(array $config): array {
        $remote = Xabia_Knowledge_Sync::is_remote_config($config);

        return [
            'enabled'    => 1,
            'interval'   => $remote ? '1hour' : 'immediate',
            'auto_train' => self::default_auto_train($config),
            'auto_cloud' => self::default_auto_cloud($config),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function default_auto_train(array $config): int {
        return class_exists('Xabia_Knowledge_Train', false) && Xabia_Knowledge_Train::should_train_for_config($config) ? 1 : 0;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function default_auto_cloud(array $config): int {
        return class_exists('Xabia_Hub_Knowledge', false) && Xabia_Hub_Knowledge::is_hub_rag_enabled('') ? 1 : 0;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function is_auto_train_enabled(array $config): bool {
        $auto = $config['auto_sync'] ?? [];
        if (is_array($auto) && array_key_exists('auto_train', $auto)) {
            return !empty($auto['auto_train']);
        }

        return (bool) self::default_auto_train($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function is_auto_cloud_enabled(array $config): bool {
        $auto = $config['auto_sync'] ?? [];
        if (is_array($auto) && array_key_exists('auto_cloud', $auto)) {
            return !empty($auto['auto_cloud']);
        }

        return (bool) self::default_auto_cloud($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function pipeline_enabled_for_config(array $config): bool {
        return self::is_auto_train_enabled($config) || self::is_auto_cloud_enabled($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function start_pipeline(string $project_id, array $config): void {
        if (self::is_auto_train_enabled($config)
            && class_exists('Xabia_Knowledge_Train', false)
            && Xabia_Knowledge_Train::should_train_for_config($config)
            && Xabia_Knowledge_Train::count_pending($project_id) > 0
        ) {
            self::request_train($project_id);

            return;
        }
        if (self::is_auto_cloud_enabled($config)) {
            self::request_cloud($project_id);
        }
    }

    private static function request_train(string $project_id, int $delay = 15): void {
        $args = [$project_id];
        if (!wp_next_scheduled(self::TRAIN_HOOK, $args)) {
            wp_schedule_single_event(time() + max(10, $delay), self::TRAIN_HOOK, $args);
        }
    }

    private static function request_cloud(string $project_id, int $delay = 20): void {
        $args = [$project_id];
        if (!wp_next_scheduled(self::CLOUD_HOOK, $args)) {
            wp_schedule_single_event(time() + max(10, $delay), self::CLOUD_HOOK, $args);
        }
    }

    /** Tras entrenamiento manual o último lote del pipeline. */
    public static function continue_pipeline_after_train(string $project_id): void {
        $project_id = sanitize_key($project_id);
        if ($project_id === '' || !class_exists('Xabia_Knowledge_Train', false)) {
            return;
        }
        if (Xabia_Knowledge_Train::count_pending($project_id) > 0) {
            return;
        }
        $projects = get_option('xabia_projects_config', []);
        $config = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];
        if ($config !== [] && self::is_auto_cloud_enabled($config)) {
            self::request_cloud($project_id);
        }
    }

    private static function run_train_step(string $project_id, string $trigger): void {
        $projects = get_option('xabia_projects_config', []);
        $config = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];
        if ($config === [] || !self::is_auto_train_enabled($config)) {
            return;
        }
        if (!class_exists('Xabia_Knowledge_Train', false)) {
            return;
        }
        if (
            class_exists('Xabia_Digixop_Client', false)
            && Xabia_Digixop_Client::should_use_openai_proxy($project_id, $config)
            && Xabia_Digixop_Client::proxy_tokens_depleted()
        ) {
            self::mark_pipeline_step($project_id, 'train', $trigger, [
                'ok'      => false,
                'pending' => Xabia_Knowledge_Train::count_pending($project_id),
                'updated' => 0,
                'message' => Xabia_Digixop_Client::get_insufficient_balance_user_message(),
            ]);

            return;
        }

        $result = Xabia_Knowledge_Train::run_batch($project_id);
        self::mark_pipeline_step($project_id, 'train', $trigger, $result);

        if (empty($result['ok'])) {
            return;
        }

        if ((int) ($result['pending'] ?? 0) > 0) {
            self::request_train($project_id, 45);

            return;
        }

        if (self::is_auto_cloud_enabled($config)) {
            self::request_cloud($project_id);
        }
    }

    private static function run_cloud_step(string $project_id, string $trigger): void {
        $projects = get_option('xabia_projects_config', []);
        $config = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];
        if ($config === [] || !self::is_auto_cloud_enabled($config)) {
            return;
        }
        if (!class_exists('Xabia_Hub_Knowledge', false)) {
            return;
        }
        if (!Xabia_Hub_Knowledge::is_hub_rag_enabled($project_id)) {
            return;
        }
        if (class_exists('Xabia_Knowledge_Train', false) && Xabia_Knowledge_Train::count_pending($project_id) > 0) {
            self::request_train($project_id);

            return;
        }

        $result = Xabia_Hub_Knowledge::sync_vectors_to_hub($project_id, ['incremental' => true]);
        self::mark_pipeline_step($project_id, 'cloud', $trigger, $result);
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function mark_pipeline_step(string $project_id, string $step, string $trigger, array $result): void {
        $state = self::get_state();
        $prev = isset($state[$project_id]) && is_array($state[$project_id]) ? $state[$project_id] : [];
        $entry = array_merge($prev, [
            'last_trigger' => sanitize_key($trigger),
        ]);
        if ($step === 'train') {
            $entry['last_train_at'] = time();
            $entry['train_pending'] = (int) ($result['pending'] ?? 0);
            $entry['last_train_updated'] = (int) ($result['updated'] ?? 0);
            if (empty($result['ok'])) {
                $entry['pipeline_error'] = substr(sanitize_text_field((string) ($result['message'] ?? '')), 0, 255);
            } else {
                $entry['pipeline_error'] = '';
            }
        } elseif ($step === 'cloud') {
            $entry['last_cloud_at'] = time();
            $entry['last_cloud_inserted'] = (int) ($result['inserted'] ?? 0);
            if (empty($result['ok'])) {
                $entry['pipeline_error'] = substr(sanitize_text_field((string) ($result['message'] ?? '')), 0, 255);
            } else {
                $entry['pipeline_error'] = '';
            }
        }
        $state[$project_id] = $entry;
        update_option(self::OPTION_STATE, $state, false);
    }

    private static function pipeline_step_too_soon(string $project_id, string $field, int $min_seconds): bool {
        $state = self::get_state();
        $last = isset($state[$project_id][$field]) ? (int) $state[$project_id][$field] : 0;

        return $last > 0 && (time() - $last) < $min_seconds;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function iter_syncable_projects(): array {
        $projects = get_option('xabia_projects_config', []);
        if (!is_array($projects)) {
            return [];
        }
        $out = [];
        foreach ($projects as $id => $config) {
            if (!is_string($id) || $id === '' || !is_array($config)) {
                continue;
            }
            if (!empty($config['paused'])) {
                continue;
            }
            $out[sanitize_key($id)] = $config;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function is_due_for_sync(string $project_id, array $config): bool {
        $interval_key = self::get_interval_key($config);
        if ($interval_key === 'off') {
            return false;
        }
        $seconds = (int) (self::INTERVALS[$interval_key]['seconds'] ?? 3600);
        if ($seconds <= 0) {
            return false;
        }
        $state = self::get_state();
        $last = isset($state[$project_id]['last_at']) ? (int) $state[$project_id]['last_at'] : 0;

        return (time() - $last) >= $seconds;
    }

    private static function request_debounced_sync(string $project_id, int $delay = 60): void {
        $project_id = sanitize_key($project_id);
        if ($project_id === '') {
            return;
        }
        $args = [$project_id];
        if (!wp_next_scheduled(self::DEBOUNCE_HOOK, $args)) {
            wp_schedule_single_event(time() + max(30, $delay), self::DEBOUNCE_HOOK, $args);
        }
    }

    private static function execute_project_sync(string $project_id, string $trigger, bool $incremental = false): void {
        $projects = get_option('xabia_projects_config', []);
        $config = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];
        if ($config === [] || Xabia_Knowledge_Sync::project_sync_blocked($config)) {
            return;
        }
        try {
            $result = Xabia_Knowledge_Sync::run_project($project_id, ['incremental' => $incremental]);
            self::mark_synced($project_id, $trigger, (int) ($result['count'] ?? 0));
        } catch (Exception $e) {
            self::mark_error($project_id, $trigger, $e->getMessage());
        }
    }

    private static function mark_synced(string $project_id, string $trigger, int $count): void {
        $state = self::get_state();
        $prev = isset($state[$project_id]) && is_array($state[$project_id]) ? $state[$project_id] : [];
        $state[$project_id] = [
            'last_at'               => time(),
            'last_count'            => $count,
            'last_trigger'          => sanitize_key($trigger),
            'last_error'            => '',
            'last_successful_sync'  => isset($prev['last_successful_sync']) ? (string) $prev['last_successful_sync'] : '',
        ];
        update_option(self::OPTION_STATE, $state, false);
    }

    private static function mark_error(string $project_id, string $trigger, string $message): void {
        $state = self::get_state();
        $prev = isset($state[$project_id]) && is_array($state[$project_id]) ? $state[$project_id] : [];
        $state[$project_id] = array_merge($prev, [
            'last_at'      => time(),
            'last_trigger' => sanitize_key($trigger),
            'last_error'   => substr(sanitize_text_field($message), 0, 255),
        ]);
        update_option(self::OPTION_STATE, $state, false);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_state(): array {
        $state = get_option(self::OPTION_STATE, []);
        if (!is_array($state)) {
            return [];
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function post_matches_project(string $post_type, array $config): bool {
        if (($config['source_type'] ?? '') !== 'addon') {
            return false;
        }
        $slug = (string) ($config['addon_slug'] ?? '');
        if ($slug === 'mec' && $post_type === 'mec-events') {
            return true;
        }
        if ($slug === 'woo' && $post_type === 'product') {
            return true;
        }

        return (bool) apply_filters('xabia_auto_sync_post_matches_project', false, $post_type, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function status_line(string $project_id, array $config): string {
        $state = self::get_state();
        $row = isset($state[$project_id]) && is_array($state[$project_id]) ? $state[$project_id] : [];
        $interval_key = self::get_interval_key($config);
        if (!self::is_auto_sync_enabled($config) || $interval_key === 'off') {
            return __('Actualización automática: desactivada.', 'xabia-intelligence');
        }
        $interval_label = self::INTERVALS[$interval_key]['label'] ?? $interval_key;
        $source_hint = Xabia_Knowledge_Sync::is_remote_config($config)
            ? __('datos en otra web', 'xabia-intelligence')
            : __('datos de esta web', 'xabia-intelligence');
        $parts = [
            sprintf(
                __('Actualización automática: %1$s (%2$s).', 'xabia-intelligence'),
                $interval_label,
                $source_hint
            ),
        ];
        if (!empty($row['last_at'])) {
            $parts[] = sprintf(
                __('Última comprobación: %s', 'xabia-intelligence'),
                wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $row['last_at'])
            );
        }
        if (!empty($row['last_successful_sync'])) {
            $parts[] = sprintf(
                __('Datos actualizados hasta: %s', 'xabia-intelligence'),
                (string) $row['last_successful_sync']
            );
        }
        if (!empty($row['last_error'])) {
            $parts[] = __('Hubo un error en la última actualización.', 'xabia-intelligence');
        }
        if (!empty($row['train_pending'])) {
            $parts[] = sprintf(
                __('%d fichas pendientes de preparar para el chat.', 'xabia-intelligence'),
                (int) $row['train_pending']
            );
        } elseif (!empty($row['last_cloud_at']) && empty($row['pipeline_error'])) {
            $parts[] = __('Todo al día: datos, chat y Hub.', 'xabia-intelligence');
        }
        if (!empty($row['pipeline_error'])) {
            $parts[] = sprintf(
                __('Aviso: %s', 'xabia-intelligence'),
                wp_strip_all_tags((string) $row['pipeline_error'])
            );
        }

        return implode(' ', $parts);
    }
}
