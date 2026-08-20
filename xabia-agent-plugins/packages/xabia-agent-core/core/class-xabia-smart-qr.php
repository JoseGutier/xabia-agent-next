<?php
/**
 * Smart QR integrado en Core: túnel por ente, generador admin, URLs de aterrizaje.
 *
 * Módulos relacionados (carga separada):
 * - addons/xabia-qr/xabia-addon-qr.php — POI, sesión ?xqr=, pestaña admin
 * - core/class-xabia-box-route.php — ruta pública /xabia-box/
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Smart_QR {

    public static function init(): void {
        add_filter('xabia_chat_tunnel_system_preamble', [self::class, 'filter_tunnel_preamble'], 10, 3);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets'], 20);
        add_filter('xabia_agent_admin_tabs', [self::class, 'reorder_admin_tabs'], 999, 3);
        add_action('admin_notices', [self::class, 'legacy_plugin_notice']);
    }

    /**
     * @param mixed $pre
     * @param mixed $project_id
     * @param mixed $args
     * @return mixed
     */
    public static function filter_tunnel_preamble($pre, $project_id, $args) {
        if (!is_string($project_id) || $project_id === '') {
            return $pre;
        }

        $lines = [];
        $qr_id = '';
        if (is_array($args)) {
            $qr_id = isset($args['qr_id']) ? trim((string) $args['qr_id']) : '';
            if ($qr_id === '' && !empty($args['qr_scan']['qr_id'])) {
                $qr_id = trim((string) $args['qr_scan']['qr_id']);
            }
        }

        if ($qr_id !== '') {
            $poi_name = $qr_id;
            if (function_exists('xabia_qr_get_poi_data')) {
                $poi = xabia_qr_get_poi_data($qr_id, (string) $project_id);
                if (is_array($poi) && (($poi['name'] ?? '') !== '')) {
                    $poi_name = (string) $poi['name'];
                }
            }
            $lines[] = 'Contexto (interno): El usuario ha llegado escaneando un código QR físico (id «'
                . $qr_id
                . '», punto «'
                . $poi_name
                . '»). Personaliza el saludo y las respuestas a este emplazamiento usando el contexto RAG y las reglas del proyecto.';
        }

        $strict = is_array($args) && !empty($args['strict_ente']);
        $ente_scope = is_array($args) ? trim((string) ($args['ente_scope'] ?? '')) : '';
        if ($strict && $ente_scope !== '' && $ente_scope !== 'global') {
            $name = is_array($args) ? trim((string) ($args['ente_display'] ?? '')) : '';
            if ($name === '') {
                $name = $ente_scope;
            }
            $lines[] = 'Contexto (interno, no lo cites literalmente como «mensaje de sistema»): El usuario ha llegado por enlace de túnel Smart QR al ente «'
                . $name
                . '». Salúdale de forma específica y cercana según esta ubicación u objeto; céntrate en la información de este ente en el contexto RAG.';
        }

        if ($lines === []) {
            return $pre;
        }

        $block = implode("\n\n", $lines);
        if (!is_string($pre) || trim($pre) === '') {
            return $block;
        }

        return trim($pre) . "\n\n" . $block;
    }

    public static function enqueue_admin_assets(string $hook): void {
        unset($hook);
        if (!self::is_agent_editor_screen()) {
            return;
        }

        $project_id = sanitize_key((string) wp_unslash($_GET['edit'] ?? ''));
        if ($project_id === '') {
            return;
        }

        $bundle = XABIA_PATH . 'admin/js/qrcode.bundle.min.js';
        if (!is_readable($bundle)) {
            return;
        }

        $ver = defined('XABIA_VERSION') ? XABIA_VERSION : '1.0';
        $ver .= '.' . (string) filemtime($bundle);

        wp_enqueue_script(
            'xabia-qrcode-lib',
            XABIA_URL . 'admin/js/qrcode.bundle.min.js',
            [],
            $ver,
            true
        );
        wp_enqueue_script('jquery');

        $localized = [
            'projectId'           => $project_id,
            'landingUrl'          => xabia_agent_smart_qr_resolved_landing_url($project_id),
            'showKnowledgeColumn' => true,
            'i18n'                => [
                'modalTitle'    => __('Smart QR', 'xabia-intelligence'),
                'target'        => __('URL del QR', 'xabia-intelligence'),
                'shortcode'     => __('Shortcode', 'xabia-intelligence'),
                'downloadPng'   => __('Descargar PNG', 'xabia-intelligence'),
                'downloadSvg'   => __('Descargar SVG', 'xabia-intelligence'),
                'copyLink'      => __('Copiar enlace', 'xabia-intelligence'),
                'copyShortcode' => __('Copiar shortcode', 'xabia-intelligence'),
                'copyImage'     => __('Copiar imagen', 'xabia-intelligence'),
                'close'         => __('Cerrar', 'xabia-intelligence'),
                'loading'       => __('Generando QR…', 'xabia-intelligence'),
                'libMissing'    => __('No se pudo cargar el generador QR. Recarga la página o contacta con soporte.', 'xabia-intelligence'),
                'genError'      => __('No se pudo generar el código QR.', 'xabia-intelligence'),
                'copied'        => __('Copiado al portapapeles.', 'xabia-intelligence'),
                'copyFail'      => __('No se pudo copiar. Selecciona el texto manualmente.', 'xabia-intelligence'),
                'selectLanding' => __('Configura la página de aterrizaje en Smart QR / Tótems.', 'xabia-intelligence'),
            ],
        ];
        wp_add_inline_script(
            'xabia-qrcode-lib',
            'window.xabiaSmartQr = ' . wp_json_encode($localized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';',
            'before'
        );

        $js_path = XABIA_PATH . 'admin/js/xabia-smart-qr-admin.js';
        if (is_readable($js_path)) {
            wp_enqueue_script(
                'xabia-smart-qr-admin',
                XABIA_URL . 'admin/js/xabia-smart-qr-admin.js',
                ['jquery', 'xabia-qrcode-lib'],
                $ver . '.' . (string) filemtime($js_path),
                true
            );
        }
    }

    /**
     * @param mixed $tabs
     * @param mixed $edit_id
     * @param mixed $data
     * @return mixed
     */
    public static function reorder_admin_tabs($tabs, $edit_id, $data) {
        unset($edit_id, $data);
        if (!is_array($tabs)) {
            $tabs = [];
        }

        $order = [
            'tab-data',
            'tab-mec',
            'tab-avirato',
            'tab-qrs',
            'tab-analytics',
            'tab-design',
            'tab-ai',
            'tab-history',
        ];
        $by_id = [];
        foreach ($tabs as $t) {
            if (!is_array($t) || empty($t['id'])) {
                continue;
            }
            $by_id[(string) $t['id']] = $t;
        }
        $out = [];
        foreach ($order as $id) {
            if (isset($by_id[$id])) {
                $out[] = $by_id[$id];
                unset($by_id[$id]);
            }
        }
        foreach ($by_id as $t) {
            $out[] = $t;
        }

        return $out;
    }

    public static function legacy_plugin_notice(): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!is_plugin_active('xabia-smart-qr/xabia-smart-qr.php')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__(
            'Xabia Smart QR ya está integrado en Xabia Agent Core. Desactiva y elimina el plugin «Xabia Smart QR» para evitar conflictos.',
            'xabia-intelligence'
        );
        echo '</p></div>';
    }

    private static function is_agent_editor_screen(): bool {
        if (!is_admin() || !current_user_can('manage_options')) {
            return false;
        }
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        $xabia_pages = ['xabia-settings', 'xabia-addons', 'xabia-wallet', 'xabia-central'];
        if (!in_array($page, $xabia_pages, true)) {
            return false;
        }
        $edit = isset($_GET['edit']) ? sanitize_key((string) wp_unslash($_GET['edit'])) : '';

        return $edit !== '' && $edit !== 'new';
    }
}

/**
 * Permalink de la página de aterrizaje Smart QR configurada para el agente (post publicado).
 */
function xabia_agent_smart_qr_landing_permalink(string $project_id): string {
    $project_id = sanitize_key($project_id);
    if ($project_id === '') {
        return '';
    }
    $projects = get_option('xabia_projects_config', []);
    if (!isset($projects[$project_id]) || !is_array($projects[$project_id])) {
        return '';
    }
    $page_id = isset($projects[$project_id]['smart_qr_landing_page_id']) ? absint($projects[$project_id]['smart_qr_landing_page_id']) : 0;
    if ($page_id < 1) {
        return '';
    }
    if (get_post_status($page_id) !== 'publish') {
        return '';
    }
    $url = get_permalink($page_id);

    return is_string($url) ? $url : '';
}

/**
 * URL base del túnel Smart QR: página de aterrizaje o /xabia-box/ (+ x_project si aplica).
 */
function xabia_agent_smart_qr_resolved_landing_url(string $project_id): string {
    $permalink = xabia_agent_smart_qr_landing_permalink($project_id);
    if ($permalink !== '') {
        return $permalink;
    }
    $base = (string) apply_filters('xabia_qr_tunnel_base_url', home_url('/xabia-box/'));
    $pid = sanitize_key($project_id);
    if ($pid !== '') {
        return add_query_arg(['x_project' => $pid], $base);
    }

    return $base;
}
