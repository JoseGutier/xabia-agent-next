<?php
/**
 * Actualizaciones de add-ons oficiales Xabia vía Hub (/xabia/v1/updates).
 * Hub-and-spoke: el Core lee el catálogo central (Xabia_Addons), cruza cabeceras WP
 * con el Hub e inyecta updates nativos + panel visual. Los add-ons no registran nada.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Addon_Updater {

    /** @var array<string, array{slug: string, plugin_file: string, name: string}> Add-ons de terceros (opcional). */
    private static $registered = [];

    private static $hooks_initialized = false;

    public static function init(): void {
        self::ensure_hooks();
        self::init_admin_ui();
    }

    /**
     * Registro opcional para add-ons de terceros fuera del catálogo oficial.
     *
     * @deprecated Los add-ons oficiales Xabia no deben llamar a este método.
     */
    public static function register(string $hub_slug, string $plugin_file, string $display_name): void {
        $hub_slug = sanitize_key($hub_slug);
        $plugin_file = trim(str_replace('\\', '/', $plugin_file));
        $display_name = trim($display_name);
        if ($hub_slug === '' || $plugin_file === '' || $display_name === '') {
            return;
        }

        self::$registered[$hub_slug] = [
            'slug'        => $hub_slug,
            'plugin_file' => $plugin_file,
            'name'        => $display_name,
        ];
    }

    public static function init_admin_ui(): void {
        add_action('admin_notices', [self::class, 'render_global_admin_notice'], 15);
    }

    /**
     * Catálogo unificado: add-ons oficiales instalados (+ terceros registrados opcionalmente).
     *
     * @return list<array{hub_slug: string, plugin_file: string, name: string, registry_slug: string}>
     */
    public static function catalog_entries(bool $installed_only = true): array {
        $entries = [];
        $seen_hub = [];

        if (class_exists('Xabia_Addons', false)) {
            foreach (Xabia_Addons::registry() as $def) {
                if (!is_array($def)) {
                    continue;
                }
                $registry_slug = sanitize_key((string) ($def['slug'] ?? ''));
                $hub_slug = Xabia_Addons::hub_update_slug($registry_slug);
                $plugin_file = trim(str_replace('\\', '/', (string) ($def['plugin_file'] ?? '')));
                if ($registry_slug === '' || $hub_slug === '' || $plugin_file === '') {
                    continue;
                }
                if ($installed_only && !is_readable(WP_PLUGIN_DIR . '/' . $plugin_file)) {
                    continue;
                }
                $title = trim((string) ($def['title'] ?? ''));
                $entries[] = [
                    'hub_slug'       => $hub_slug,
                    'plugin_file'    => $plugin_file,
                    'name'           => $title !== '' ? $title : $hub_slug,
                    'registry_slug'  => $registry_slug,
                ];
                $seen_hub[$hub_slug] = true;
            }
        }

        foreach (self::$registered as $hub_slug => $def) {
            if (isset($seen_hub[$hub_slug])) {
                continue;
            }
            $plugin_file = trim(str_replace('\\', '/', (string) ($def['plugin_file'] ?? '')));
            if ($plugin_file === '') {
                continue;
            }
            if ($installed_only && !is_readable(WP_PLUGIN_DIR . '/' . $plugin_file)) {
                continue;
            }
            $entries[] = [
                'hub_slug'      => $hub_slug,
                'plugin_file'   => $plugin_file,
                'name'          => (string) ($def['name'] ?? $hub_slug),
                'registry_slug' => '',
            ];
        }

        return $entries;
    }

    /**
     * @return array{
     *   hub_slug: string,
     *   name: string,
     *   plugin_file: string,
     *   installed: string,
     *   remote_version: string,
     *   update_available: bool,
     *   checked_at: int,
     *   error: string,
     *   error_kind: string
     * }
     */
    public static function get_ui_status(string $hub_slug, string $plugin_file = '', bool $force_refresh = false): array {
        $hub_slug = sanitize_key($hub_slug);
        $name = $hub_slug;

        if ($plugin_file === '') {
            foreach (self::catalog_entries(false) as $entry) {
                if ($entry['hub_slug'] === $hub_slug) {
                    $plugin_file = $entry['plugin_file'];
                    $name = $entry['name'];
                    break;
                }
            }
        } else {
            foreach (self::catalog_entries(false) as $entry) {
                if ($entry['hub_slug'] === $hub_slug || $entry['plugin_file'] === $plugin_file) {
                    $name = $entry['name'];
                    break;
                }
            }
        }

        $installed = $plugin_file !== '' ? self::read_installed_version($plugin_file) : '';
        $remote = self::fetch_remote_metadata($hub_slug, $plugin_file, $force_refresh);
        $remote_version = is_array($remote) ? (string) ($remote['version'] ?? '') : '';
        $error = is_array($remote) && empty($remote['ok']) ? (string) ($remote['error'] ?? '') : '';
        $error_kind = is_array($remote) && empty($remote['ok']) ? (string) ($remote['error_kind'] ?? '') : '';

        return [
            'hub_slug'         => $hub_slug,
            'name'             => $name,
            'plugin_file'      => $plugin_file,
            'installed'        => $installed,
            'remote_version'   => $remote_version,
            'update_available' => $installed !== '' && $remote_version !== '' && version_compare($remote_version, $installed, '>'),
            'checked_at'       => is_array($remote) ? (int) ($remote['checked'] ?? 0) : 0,
            'error'            => $error,
            'error_kind'       => $error_kind,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function collect_pending_updates(): array {
        $pending = [];
        foreach (self::catalog_entries(true) as $entry) {
            $status = self::get_ui_status($entry['hub_slug'], $entry['plugin_file']);
            if (!empty($status['update_available'])) {
                if ($entry['registry_slug'] !== '') {
                    $status['registry_slug'] = $entry['registry_slug'];
                }
                $pending[] = $status;
            }
        }

        return $pending;
    }

    public static function render_global_admin_notice(): void {
        if (!current_user_can('update_plugins')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || strpos((string) $screen->id, 'xabia') === false) {
            return;
        }
        if ($screen->id === 'plugins') {
            return;
        }

        $pending = self::collect_pending_updates();
        if ($pending === []) {
            return;
        }

        $plugins_url = admin_url('plugins.php');
        $lines = [];
        foreach ($pending as $row) {
            $lines[] = sprintf(
                '%s (%s → %s)',
                (string) ($row['name'] ?? ''),
                (string) ($row['installed'] ?? ''),
                (string) ($row['remote_version'] ?? '')
            );
        }
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php echo esc_html__('Actualizaciones de plugins Xabia pendientes', 'xabia-intelligence'); ?></strong>
            </p>
            <p><?php echo esc_html(implode(' · ', $lines)); ?></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url($plugins_url); ?>">
                    <?php echo esc_html__('Ir a Plugins → Actualizar', 'xabia-intelligence'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Panel resumen en Xabia Agent → Addons (misma UX que el Core en Ajustes).
     */
    public static function render_addons_updates_panel(): void {
        if (!current_user_can('update_plugins')) {
            return;
        }

        $rows = [];
        foreach (self::catalog_entries(true) as $entry) {
            $rows[] = self::get_ui_status($entry['hub_slug'], $entry['plugin_file']);
        }

        if ($rows === []) {
            return;
        }

        $plugins_url = admin_url('plugins.php');
        $any_pending = false;
        foreach ($rows as $row) {
            if (!empty($row['update_available'])) {
                $any_pending = true;
                break;
            }
        }
        ?>
        <div class="xabia-card xabia-update-panel xabia-addon-updates-panel" style="margin:0 0 18px;padding:16px 18px;border-radius:12px;border:1px solid #e2e4e7;">
            <h2 class="xabia-card-title" style="margin:0 0 10px;"><?php echo esc_html__('Versiones de add-ons (Hub)', 'xabia-intelligence'); ?></h2>
            <ul style="margin:0 0 12px;padding-left:18px;font-size:13px;line-height:1.55;">
                <?php foreach ($rows as $row) : ?>
                    <li>
                        <strong><?php echo esc_html((string) ($row['name'] ?? '')); ?></strong>:
                        <?php
                        echo esc_html(
                            sprintf(
                                __('Instalada %1$s', 'xabia-intelligence'),
                                (string) ($row['installed'] !== '' ? $row['installed'] : '—')
                            )
                        );
                        if (!empty($row['update_available'])) {
                            echo ' — ';
                            echo esc_html(
                                sprintf(
                                    __('disponible %s', 'xabia-intelligence'),
                                    (string) ($row['remote_version'] ?? '')
                                )
                            );
                        } elseif ((string) ($row['remote_version'] ?? '') !== '') {
                            echo ' — ';
                            echo esc_html__('al día', 'xabia-intelligence');
                        }
                        ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($any_pending) : ?>
                <p style="margin:0 0 10px;color:#7a4e00;font-weight:600;">
                    <?php echo esc_html__('Hay actualizaciones de código pendientes. La licencia Polar no sustituye este paso: actualiza el plugin en WordPress.', 'xabia-intelligence'); ?>
                </p>
                <a class="button button-primary" href="<?php echo esc_url($plugins_url); ?>"><?php echo esc_html__('Actualizar add-ons en Plugins', 'xabia-intelligence'); ?></a>
            <?php else : ?>
                <p style="margin:0;color:#137333;font-size:14px;">
                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <?php echo esc_html__('Todos los add-ons instalados están al día.', 'xabia-intelligence');
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Banner compacto dentro de la tarjeta Polar de un add-on.
     *
     * @param array<string, mixed> $status
     */
    public static function render_card_update_banner(array $status): void {
        if (empty($status['update_available'])) {
            return;
        }
        $plugins_url = admin_url('plugins.php');
        ?>
        <div class="xabia-addon-sub-card__renewal-banner xabia-addon-sub-card__renewal-banner--update" role="status" style="background:#fff8e6;border-color:#f0c36d;color:#7a4e00;">
            <?php
            echo esc_html(
                sprintf(
                    /* translators: 1: remote version, 2: installed version */
                    __('Actualización de código disponible: %1$s. Instalada: %2$s.', 'xabia-intelligence'),
                    (string) ($status['remote_version'] ?? ''),
                    (string) ($status['installed'] ?? '')
                )
            );
            ?>
            <a class="button button-secondary" style="margin-left:8px;vertical-align:middle;" href="<?php echo esc_url($plugins_url); ?>">
                <?php echo esc_html__('Actualizar ahora', 'xabia-intelligence'); ?>
            </a>
        </div>
        <?php
    }

    private static function ensure_hooks(): void {
        if (self::$hooks_initialized) {
            return;
        }
        self::$hooks_initialized = true;
        add_filter('site_transient_update_plugins', [self::class, 'inject_updates'], 20);
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'inject_updates'], 20);
        add_filter('plugins_api', [self::class, 'filter_plugins_api'], 10, 3);
        add_action('upgrader_process_complete', [self::class, 'clear_cache_after_upgrade'], 10, 2);
    }

    /**
     * Motor único: catálogo oficial instalado + Hub /updates → transient nativo de WordPress.
     *
     * @param mixed $transient
     * @return mixed
     */
    public static function inject_updates($transient) {
        if (!is_object($transient)) {
            return $transient;
        }

        foreach (self::catalog_entries(true) as $def) {
            $remote = self::fetch_remote_metadata($def['hub_slug'], $def['plugin_file']);
            if (!is_array($remote) || empty($remote['ok'])) {
                continue;
            }

            $plugin_file = $def['plugin_file'];
            $installed = self::read_installed_version($plugin_file);
            $remote_version = (string) ($remote['version'] ?? '');
            if ($installed === '' || $remote_version === '' || version_compare($remote_version, $installed, '<=')) {
                continue;
            }

            if (!isset($transient->response) || !is_array($transient->response)) {
                $transient->response = [];
            }

            $transient->response[$plugin_file] = (object) [
                'slug'         => (string) ($remote['slug'] ?? $def['hub_slug']),
                'plugin'       => $plugin_file,
                'new_version'  => $remote_version,
                'url'          => (string) ($remote['homepage'] ?? 'https://xabia.ai'),
                'package'      => (string) ($remote['package'] ?? ''),
                'tested'       => (string) ($remote['tested'] ?? ''),
                'requires'     => (string) ($remote['requires'] ?? ''),
                'requires_php' => (string) ($remote['requires_php'] ?? ''),
            ];
        }

        return $transient;
    }

    /**
     * @param mixed        $result
     * @param string       $action
     * @param object|array $args
     * @return mixed
     */
    public static function filter_plugins_api($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        $slug = '';
        if (is_object($args) && isset($args->slug)) {
            $slug = sanitize_key((string) $args->slug);
        } elseif (is_array($args) && isset($args['slug'])) {
            $slug = sanitize_key((string) $args['slug']);
        }
        if ($slug === '') {
            return $result;
        }

        $def = null;
        foreach (self::catalog_entries(true) as $entry) {
            if ($entry['hub_slug'] === $slug) {
                $def = $entry;
                break;
            }
        }
        if ($def === null) {
            return $result;
        }

        $remote = self::fetch_remote_metadata($def['hub_slug'], $def['plugin_file']);
        $installed = self::read_installed_version($def['plugin_file']);
        $version = (is_array($remote) && !empty($remote['version']))
            ? (string) $remote['version']
            : $installed;

        return (object) [
            'name'           => $def['name'],
            'slug'           => $slug,
            'version'        => $version !== '' ? $version : '1.0.0',
            'author'         => '<a href="https://digixop.com">Digixop</a>',
            'author_profile' => 'https://digixop.com',
            'homepage'       => 'https://xabia.ai',
            'requires'       => is_array($remote) ? (string) ($remote['requires'] ?? '6.0') : '6.0',
            'requires_php'   => is_array($remote) ? (string) ($remote['requires_php'] ?? '7.4') : '7.4',
            'tested'         => is_array($remote) ? (string) ($remote['tested'] ?? '') : '',
            'download_link'  => is_array($remote) ? (string) ($remote['package'] ?? '') : '',
            'last_updated'   => is_array($remote) ? (string) ($remote['last_updated'] ?? '') : gmdate('Y-m-d'),
            'sections'       => [
                'description' => '<p>' . esc_html(self::commercial_description($slug, $def['name'])) . '</p>'
                    . self::plugin_details_links_html(),
                'changelog'   => '<p><a href="https://xabia.ai/docs/" target="_blank" rel="noopener noreferrer">'
                    . esc_html__('Documentación en xabia.ai/docs', 'xabia-intelligence') . '</a></p>'
                    . self::plugin_details_links_html(),
            ],
        ];
    }

    private static function commercial_description(string $hub_slug, string $fallback_name): string {
        $map = [
            'xabia-mec'         => 'Addon de especialización que dota a Xabia AI con inteligencia avanzada para la gestión de eventos, plazas y reservas de Modern Events Calendar, ofreciendo interacciones asistidas en tiempo real.',
            'xabia-woo'         => 'Addon que transforma tu WooCommerce en una plataforma de comercio conversacional avanzado. Dota al Agente de IA con inteligencia sobre tu catálogo para carritos asistidos e interacciones de ventas hiperpersonalizadas.',
            'xabia-amelia'      => 'Addon que dota a Xabia AI con inteligencia avanzada para la gestión de citas, servicios y calendarios de Amelia, automatizando la programación de reservas mediante interacciones conversacionales fluidas en tiempo real.',
            'xabia-federation'  => 'Addon avanzado que transforma a Xabia AI en un nodo centralizado de federación global, permitiendo la interconexión inteligente de datos, el intercambio de conocimiento y la sincronización omnicanal entre múltiples sitios webs y plataformas.',
            'xabia-avirato'     => 'Addon modular de scraping y disponibilidad Avirato para Xabia Agent Core.',
        ];

        return $map[$hub_slug] ?? ($fallback_name . ' — addon comercial para Xabia Agent Core.');
    }

    private static function plugin_details_links_html(): string {
        return '<p><a href="https://xabia.ai" target="_blank" rel="noopener noreferrer">xabia.ai</a> · '
            . '<a href="https://digixop.com" target="_blank" rel="noopener noreferrer">digixop.com</a></p>';
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function clear_cache_after_upgrade($upgrader, $options): void {
        unset($upgrader);
        if (!is_array($options) || ($options['action'] ?? '') !== 'update' || ($options['type'] ?? '') !== 'plugin') {
            return;
        }
        if (empty($options['plugins']) || !is_array($options['plugins'])) {
            return;
        }
        foreach (self::catalog_entries(false) as $def) {
            if (in_array($def['plugin_file'], $options['plugins'], true)) {
                delete_transient(self::transient_key($def['hub_slug']));
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetch_remote_metadata(string $hub_slug, string $plugin_file = '', bool $force_refresh = false): ?array {
        $cache_key = self::transient_key($hub_slug);
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $updates_url = class_exists('Xabia_Updater', false)
            ? Xabia_Updater::hub_updates_url()
            : (class_exists('Xabia_Digixop_Client', false)
                ? Xabia_Digixop_Client::URL_UPDATES
                : 'https://xabia.ai/api/xabia/v1/updates');

        if ($plugin_file === '') {
            foreach (self::catalog_entries(false) as $entry) {
                if ($entry['hub_slug'] === $hub_slug) {
                    $plugin_file = $entry['plugin_file'];
                    break;
                }
            }
        }
        $installed = $plugin_file !== '' ? self::read_installed_version($plugin_file) : '';

        $url = add_query_arg(
            [
                'plugin'    => $hub_slug,
                'installed' => $installed,
            ],
            $updates_url
        );

        $response = wp_remote_get($url, [
            'timeout'     => 15,
            'redirection' => 3,
            'headers'     => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            $payload = [
                'ok'         => false,
                'error'      => $response->get_error_message(),
                'error_kind' => 'network',
                'checked'    => time(),
            ];
            set_transient($cache_key, $payload, MINUTE_IN_SECONDS * 15);

            return $payload;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300 || $body === '') {
            $payload = [
                'ok'         => false,
                'error'      => 'HTTP ' . $code,
                'error_kind' => $code === 404 ? 'updates_not_published' : 'http_error',
                'checked'    => time(),
            ];
            set_transient($cache_key, $payload, MINUTE_IN_SECONDS * 15);

            return $payload;
        }

        $json = json_decode($body, true);
        if (!is_array($json) || isset($json['error'])) {
            $payload = [
                'ok'         => false,
                'error'      => 'invalid_response',
                'error_kind' => 'invalid_response',
                'checked'    => time(),
            ];
            set_transient($cache_key, $payload, MINUTE_IN_SECONDS * 15);

            return $payload;
        }

        $remote_version = self::sanitize_version((string) ($json['version'] ?? ''));
        $package = self::sanitize_https_url((string) ($json['package'] ?? ($json['download_url'] ?? '')));
        if ($remote_version === '' || $package === '') {
            $payload = [
                'ok'         => false,
                'error'      => 'incomplete_payload',
                'error_kind' => 'hub_misconfigured',
                'checked'    => time(),
            ];
            set_transient($cache_key, $payload, MINUTE_IN_SECONDS * 15);

            return $payload;
        }

        $payload = [
            'ok'           => true,
            'slug'         => sanitize_key((string) ($json['slug'] ?? $hub_slug)),
            'name'         => sanitize_text_field((string) ($json['name'] ?? $hub_slug)),
            'version'      => $remote_version,
            'requires'     => self::sanitize_version((string) ($json['requires'] ?? '6.0')) ?: '6.0',
            'requires_php' => self::sanitize_version((string) ($json['requires_php'] ?? '7.4')) ?: '7.4',
            'tested'       => self::sanitize_version((string) ($json['tested'] ?? '')),
            'package'      => $package,
            'homepage'     => self::sanitize_https_url((string) ($json['homepage'] ?? 'https://xabia.ai')),
            'last_updated' => sanitize_text_field((string) ($json['last_updated'] ?? '')),
            'checked'      => time(),
        ];
        set_transient($cache_key, $payload, 43200);

        return $payload;
    }

    private static function read_installed_version(string $plugin_file): string {
        $path = WP_PLUGIN_DIR . '/' . ltrim($plugin_file, '/');
        if (!is_readable($path)) {
            return '';
        }
        $data = get_file_data($path, ['Version' => 'Version'], 'plugin');

        return isset($data['Version']) ? trim((string) $data['Version']) : '';
    }

    private static function transient_key(string $hub_slug): string {
        return 'xabia_addon_update_' . sanitize_key($hub_slug);
    }

    private static function sanitize_version(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^\d+(?:\.\d+)*$/', $raw) === 1) {
            return $raw;
        }

        return '';
    }

    private static function sanitize_https_url(string $raw): string {
        $raw = trim($raw);
        if ($raw === '' || !filter_var($raw, FILTER_VALIDATE_URL)) {
            return '';
        }
        if (!preg_match('#^https://#i', $raw)) {
            return '';
        }

        return esc_url_raw($raw);
    }
}
