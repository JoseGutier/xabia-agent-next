<?php
/**
 * Comprobación de actualizaciones del Core vía Hub Xabia + integración con el listado nativo de plugins WP.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Updater {

    public const TRANSIENT_KEY = 'xabia_update_check';

    private const TRANSIENT_TTL = 43200; // 12 horas

    /** Si la caché decía «al día», reconsultar el Hub tras este intervalo (nueva versión publicada). */
    private const STALE_REVALIDATE_SECONDS = 900; // 15 min

    public static function init(): void {
        add_filter('site_transient_update_plugins', 'xabia_check_forced_update');
        add_filter('pre_set_site_transient_update_plugins', 'xabia_check_forced_update');
        add_filter('plugins_api', [self::class, 'filter_plugins_api'], 10, 3);
        add_action('upgrader_process_complete', [self::class, 'clear_cache_after_upgrade'], 10, 2);
    }

    public static function plugin_file(): string {
        return plugin_basename(trailingslashit(XABIA_PATH) . 'xabia-intelligence.php');
    }

    public static function plugin_slug(): string {
        return dirname(self::plugin_file());
    }

    public static function hub_updates_url(): string {
        $default = class_exists('Xabia_Digixop_Client', false)
            ? Xabia_Digixop_Client::URL_UPDATES
            : 'https://xabia.ai/api/xabia/v1/updates';

        return (string) apply_filters('xabia_updates_hub_url', $default);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function fetch_remote_metadata(bool $force_refresh = false): ?array {
        if (!$force_refresh) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if (is_array($cached)) {
                if (!empty($cached['ok']) && self::should_revalidate_success_cache($cached)) {
                    $force_refresh = true;
                } else {
                    return $cached;
                }
            }
        }

        $url = add_query_arg(
            [
                'plugin' => self::plugin_slug(),
                'installed' => defined('XABIA_VERSION') ? (string) XABIA_VERSION : '',
            ],
            self::hub_updates_url()
        );

        $json = self::request_hub_updates_json($url);
        $fallback_slug = 'xabia-agent-core';
        if (
            $json !== null
            && isset($json['error']['type'])
            && (string) $json['error']['type'] === 'not_found'
            && self::plugin_slug() !== $fallback_slug
        ) {
            $json = null;
        }
        if ($json === null && self::plugin_slug() !== $fallback_slug) {
            $fallback_url = add_query_arg(
                [
                    'plugin' => $fallback_slug,
                    'installed' => defined('XABIA_VERSION') ? (string) XABIA_VERSION : '',
                ],
                self::hub_updates_url()
            );
            $json = self::request_hub_updates_json($fallback_url);
        }

        if ($json === null) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if (is_array($cached)) {
                return $cached;
            }
            $payload = [
                'ok'         => false,
                'error'      => 'hub_unreachable',
                'error_kind' => 'network',
                'http_code'  => 0,
                'checked'    => time(),
            ];
            set_transient(self::TRANSIENT_KEY, $payload, MINUTE_IN_SECONDS * 15);

            return $payload;
        }

        if (isset($json['error']) && is_array($json['error'])) {
            $msg = isset($json['error']['message']) ? (string) $json['error']['message'] : 'hub_error';
            $error_type = isset($json['error']['type']) ? (string) $json['error']['type'] : '';
            $error_kind = 'hub_error';
            if ($error_type === 'misconfigured') {
                $error_kind = 'hub_misconfigured';
            } elseif ($error_type === 'not_found') {
                $error_kind = 'updates_not_published';
            }
            $payload = [
                'ok'         => false,
                'error'      => $msg,
                'error_kind' => $error_kind,
                'http_code'  => 0,
                'checked'    => time(),
            ];
            set_transient(self::TRANSIENT_KEY, $payload, MINUTE_IN_SECONDS * 15);

            return $payload;
        }

        $remote_version = self::sanitize_version((string) ($json['version'] ?? ''));
        $package = self::sanitize_https_url((string) ($json['package'] ?? ($json['download_url'] ?? '')));
        if ($remote_version === '' || $package === '') {
            $payload = [
                'ok'         => false,
                'error'      => 'incomplete_payload',
                'error_kind' => 'hub_misconfigured',
                'http_code'  => 0,
                'checked'    => time(),
            ];
            set_transient(self::TRANSIENT_KEY, $payload, MINUTE_IN_SECONDS * 15);

            return $payload;
        }

        $payload = [
            'ok'             => true,
            'slug'           => sanitize_key((string) ($json['slug'] ?? self::plugin_slug())),
            'name'           => sanitize_text_field((string) ($json['name'] ?? 'Xabia Agent Core')),
            'version'        => $remote_version,
            'requires'       => self::sanitize_version((string) ($json['requires'] ?? '6.0')) ?: '6.0',
            'requires_php'   => self::sanitize_version((string) ($json['requires_php'] ?? '7.4')) ?: '7.4',
            'tested'         => self::sanitize_version((string) ($json['tested'] ?? '')),
            'package'        => $package,
            'homepage'       => self::sanitize_https_url((string) ($json['homepage'] ?? 'https://xabia.ai')),
            'last_updated'   => sanitize_text_field((string) ($json['last_updated'] ?? '')),
            'checked'        => time(),
        ];
        set_transient(self::TRANSIENT_KEY, $payload, self::TRANSIENT_TTL);

        return $payload;
    }

    /**
     * Filtro WP: inyecta el paquete remoto cuando hay versión más reciente en el Hub.
     *
     * @param mixed $transient
     * @return mixed
     */
    public static function inject_update_transient($transient) {
        if (!is_object($transient)) {
            return $transient;
        }

        $remote = self::fetch_remote_metadata();
        if (!is_array($remote) || empty($remote['ok'])) {
            return $transient;
        }

        $installed = defined('XABIA_VERSION') ? (string) XABIA_VERSION : '';
        $remote_version = (string) ($remote['version'] ?? '');
        if ($installed === '' || $remote_version === '' || version_compare($remote_version, $installed, '<=')) {
            return $transient;
        }

        $plugin_file = self::plugin_file();
        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }

        $update = (object) [
            'slug'        => (string) ($remote['slug'] ?? self::plugin_slug()),
            'plugin'      => $plugin_file,
            'new_version' => $remote_version,
            'url'         => (string) ($remote['homepage'] ?? 'https://xabia.ai'),
            'package'     => (string) ($remote['package'] ?? ''),
            'tested'      => (string) ($remote['tested'] ?? ''),
            'requires'    => (string) ($remote['requires'] ?? ''),
            'requires_php'=> (string) ($remote['requires_php'] ?? ''),
        ];
        $transient->response[$plugin_file] = $update;

        return $transient;
    }

    /**
     * Modal «Ver detalles» en Plugins (catálogo propio, no wordpress.org).
     *
     * @param mixed       $result
     * @param string      $action
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
        if ($slug === '' || $slug !== self::plugin_slug()) {
            return $result;
        }

        $remote = self::fetch_remote_metadata();
        $installed = defined('XABIA_VERSION') ? (string) XABIA_VERSION : '';
        $version = (is_array($remote) && !empty($remote['version']))
            ? (string) $remote['version']
            : $installed;

        return (object) [
            'name'           => 'Xabia Agent Core',
            'slug'           => self::plugin_slug(),
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
                'description' => '<p>' . esc_html(
                    'Agente de Inteligencia Artificial de última generación con voz, texto y acciones en la web. Perfecciona la UX mediante interacciones conversacionales inteligentes, hiperpersonalizadas y políglotas. Smart QRs integrados, addons para Woo, MEC, Amelia, etc.',
                    'xabia-intelligence'
                ) . '</p>' . self::plugin_details_links_html(),
                'changelog'   => '<p><a href="https://xabia.ai/docs/" target="_blank" rel="noopener noreferrer">'
                    . esc_html__('Notas de versión y manuales en xabia.ai/docs', 'xabia-intelligence') . '</a></p>'
                    . self::plugin_details_links_html(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null Decoded JSON on success; null if request/parse failed.
     */
    private static function request_hub_updates_json(string $url): ?array {
        $response = wp_remote_get($url, [
            'timeout'     => 15,
            'redirection' => 3,
            'headers'     => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300 || $body === '') {
            return null;
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return null;
        }

        if (isset($json['error']) && is_array($json['error'])) {
            return $json;
        }

        $remote_version = self::sanitize_version((string) ($json['version'] ?? ''));
        $package = self::sanitize_https_url((string) ($json['package'] ?? ($json['download_url'] ?? '')));
        if ($remote_version === '' || $package === '') {
            return null;
        }

        return $json;
    }

    private static function plugin_details_links_html(): string {
        return '<p><a href="https://xabia.ai" target="_blank" rel="noopener noreferrer">xabia.ai</a> · '
            . '<a href="https://digixop.com" target="_blank" rel="noopener noreferrer">digixop.com</a></p>';
    }

    /**
     * @return array{
     *   installed:string,
     *   remote_version:string,
     *   update_available:bool,
     *   checked_at:int,
     *   error:string,
     *   error_kind:string,
     *   package:string,
     *   homepage:string
     * }
     */
    public static function get_ui_status(bool $force_refresh = false): array {
        $installed = defined('XABIA_VERSION') ? (string) XABIA_VERSION : '';
        $remote = self::fetch_remote_metadata($force_refresh);
        $remote_version = is_array($remote) ? (string) ($remote['version'] ?? '') : '';
        $error = is_array($remote) && empty($remote['ok']) ? (string) ($remote['error'] ?? '') : '';
        $error_kind = is_array($remote) && empty($remote['ok']) ? (string) ($remote['error_kind'] ?? '') : '';
        $checked_at = is_array($remote) ? (int) ($remote['checked'] ?? 0) : 0;
        $update_available = $installed !== '' && $remote_version !== '' && version_compare($remote_version, $installed, '>');

        return [
            'installed'        => $installed,
            'remote_version'   => $remote_version,
            'update_available' => $update_available,
            'checked_at'       => $checked_at,
            'error'            => $error,
            'error_kind'       => $error_kind,
            'package'          => is_array($remote) ? (string) ($remote['package'] ?? '') : '',
            'homepage'         => is_array($remote) ? (string) ($remote['homepage'] ?? '') : '',
        ];
    }

    public static function render_version_panel(): void {
        if (!current_user_can('update_plugins')) {
            return;
        }

        $force = isset($_GET['xabia_check_updates']) && $_GET['xabia_check_updates'] === '1'
            && isset($_GET['_wpnonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])), 'xabia_check_updates');
        if ($force) {
            delete_transient(self::TRANSIENT_KEY);
            delete_site_transient('update_plugins');
        }
        $status = self::get_ui_status($force);
        $plugins_url = admin_url('plugins.php');
        $check_page = Xabia_Features::is_pro() ? 'xabia-settings' : 'xabia-lite';
        $check_url = wp_nonce_url(
            add_query_arg('xabia_check_updates', '1', admin_url('admin.php?page=' . $check_page)),
            'xabia_check_updates'
        );
        ?>
        <div class="xabia-card xabia-update-panel" style="margin:0 0 18px;padding:16px 18px;border-radius:12px;border:1px solid #e2e4e7;">
            <div style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:12px;">
                <div>
                    <h2 class="xabia-card-title" style="margin:0 0 6px;"><?php echo esc_html__('Versión de Xabia Agent Core', 'xabia-intelligence'); ?></h2>
                    <p style="margin:0;color:#5f6368;font-size:13px;line-height:1.5;">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %s: installed plugin version */
                                __('Instalada: %s', 'xabia-intelligence'),
                                $status['installed'] !== '' ? $status['installed'] : '—'
                            )
                        );
                        ?>
                    </p>
                </div>
                <a class="button button-secondary" href="<?php echo esc_url($check_url); ?>"><?php echo esc_html__('Comprobar ahora', 'xabia-intelligence'); ?></a>
            </div>

            <?php if ($status['update_available']) : ?>
                <div style="margin-top:14px;padding:12px 14px;border-radius:10px;border:1px solid #f0c36d;background:#fff8e6;color:#7a4e00;">
                    <p style="margin:0 0 8px;font-weight:600;">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %s: remote version number */
                                __('Hay una actualización disponible: %s', 'xabia-intelligence'),
                                $status['remote_version']
                            )
                        );
                        ?>
                    </p>
                    <p style="margin:0 0 10px;font-size:13px;line-height:1.45;">
                        <?php echo esc_html__('Puedes actualizar desde el listado nativo de plugins de WordPress.', 'xabia-intelligence'); ?>
                    </p>
                    <a class="button button-primary" href="<?php echo esc_url($plugins_url); ?>"><?php echo esc_html__('Ir a Plugins → Actualizar', 'xabia-intelligence'); ?></a>
                </div>
            <?php elseif ($status['error'] !== '' && in_array($status['error_kind'], ['updates_not_published', 'hub_misconfigured'], true)) : ?>
                <div style="margin-top:14px;padding:10px 12px;border-radius:10px;border:1px solid #c6dafc;background:#f8fbff;color:#174ea6;font-size:13px;line-height:1.5;">
                    <?php echo esc_html__('El catálogo de actualizaciones del Hub aún no está publicado. Tu instalación sigue funcionando con normalidad; cuando el Hub active /updates podrás ver aquí si hay versiones nuevas.', 'xabia-intelligence'); ?>
                </div>
            <?php elseif ($status['error'] !== '') : ?>
                <div style="margin-top:14px;padding:10px 12px;border-radius:10px;border:1px solid #e2e4e7;background:#f8f9fa;color:#5f6368;font-size:13px;">
                    <?php echo esc_html__('No se pudo contactar con el Hub en este momento. Vuelve a intentarlo más tarde.', 'xabia-intelligence'); ?>
                </div>
            <?php else : ?>
                <div style="margin-top:14px;display:flex;align-items:center;gap:8px;color:#137333;font-size:14px;font-weight:500;">
                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <span><?php echo esc_html__('Tu versión está actualizada.', 'xabia-intelligence'); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($status['checked_at'] > 0) : ?>
                <p class="description" style="margin:10px 0 0;">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %s: localized datetime */
                            __('Última comprobación: %s', 'xabia-intelligence'),
                            wp_date(get_option('date_format') . ' ' . get_option('time_format'), $status['checked_at'])
                        )
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function clear_cache_after_upgrade($upgrader, $options): void {
        if (!is_array($options) || ($options['action'] ?? '') !== 'update' || ($options['type'] ?? '') !== 'plugin') {
            return;
        }
        if (empty($options['plugins']) || !is_array($options['plugins'])) {
            return;
        }
        if (!in_array(self::plugin_file(), $options['plugins'], true)) {
            return;
        }
        delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * @param array<string, mixed> $cached
     */
    private static function should_revalidate_success_cache(array $cached): bool {
        if (empty($cached['ok'])) {
            return false;
        }
        $checked = (int) ($cached['checked'] ?? 0);
        if ($checked <= 0 || (time() - $checked) < self::STALE_REVALIDATE_SECONDS) {
            return false;
        }
        $installed = defined('XABIA_VERSION') ? (string) XABIA_VERSION : '';
        $cached_version = (string) ($cached['version'] ?? '');
        if ($installed === '' || $cached_version === '') {
            return false;
        }

        return version_compare($cached_version, $installed, '<=');
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

/**
 * Enganche del filtro nativo de actualizaciones de WordPress (nombre estable para integraciones).
 *
 * @param mixed $transient
 * @return mixed
 */
function xabia_check_forced_update($transient) {
    return Xabia_Updater::inject_update_transient($transient);
}
