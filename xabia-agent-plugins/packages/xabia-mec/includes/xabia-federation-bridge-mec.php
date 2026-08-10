<?php
/**
 * Puente de federación MEC → Xabia Central.
 * GET /wp-json/xabia/v1/federation-events — eventos MEC publicados (con licencia y clave de puente).
 */

if (!defined('ABSPATH')) {
    exit;
}

const XABIA_FED_MEC_LOG_OPTION = 'xabia_federation_mec_last_access';

add_action('rest_api_init', 'xabia_federation_mec_register_rest_routes');
add_action('admin_head', 'xabia_federation_mec_admin_inline_styles');

/**
 * Acceso: administrador conectado O cabecera X-Xabia-Key (o X-Xabia-Fed-Key) = secreto del puente de federación.
 */
function xabia_federation_mec_rest_permission_check($request): bool {
    if (is_user_logged_in() && current_user_can('manage_options')) {
        return true;
    }
    $secret = class_exists('Xabia_Federation_Nexus', false) ? (string) Xabia_Federation_Nexus::get_bridge_secret() : '';
    if ($secret === '') {
        return false;
    }
    $key = '';
    if ($request instanceof WP_REST_Request) {
        $key = (string) $request->get_header('X-Xabia-Key');
        if ($key === '') {
            $key = (string) $request->get_header('X-Xabia-Fed-Key');
        }
    }
    if ($key === '' && !empty($_SERVER['HTTP_X_XABIA_KEY'])) {
        $key = sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_X_XABIA_KEY']));
    }
    if ($key === '' && !empty($_SERVER['HTTP_X_XABIA_FED_KEY'])) {
        $key = sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_X_XABIA_FED_KEY']));
    }

    return $key !== '' && hash_equals($secret, $key);
}

/**
 * @return array<int, array{source_key:string, label:string, is_ente:bool}>
 */
function xabia_federation_mec_mapping_hint() {
    return [
        [
            'source_key' => 'event_title',
            'label'      => __('Título del evento', 'xabia-intelligence'),
            'is_ente'    => true,
        ],
        [
            'source_key' => 'event_content',
            'label'      => __('Descripción', 'xabia-intelligence'),
            'is_ente'    => false,
        ],
        [
            'source_key' => 'mec_start_date',
            'label'      => __('Fecha de inicio', 'xabia-intelligence'),
            'is_ente'    => false,
        ],
        [
            'source_key' => 'mec_location',
            'label'      => __('Lugar', 'xabia-intelligence'),
            'is_ente'    => false,
        ],
        [
            'source_key' => 'mec_cost',
            'label'      => __('Precio', 'xabia-intelligence'),
            'is_ente'    => false,
        ],
        [
            'source_key' => 'mec_available_slots',
            'label'      => __('Plazas libres', 'xabia-intelligence'),
            'is_ente'    => false,
        ],
        [
            'source_key' => 'permalink',
            'label'      => __('Enlace (reserva)', 'xabia-intelligence'),
            'is_ente'    => false,
        ],
    ];
}

function xabia_federation_mec_register_rest_routes() {
    register_rest_route(
        'xabia/v1',
        '/federation-events',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'xabia_federation_mec_rest_callback',
            'permission_callback' => 'xabia_federation_mec_rest_permission_check',
            'args'                => [
                'per_page' => [
                    'type'              => 'integer',
                    'default'           => 100,
                    'minimum'           => 1,
                    'maximum'           => 200,
                    'sanitize_callback' => 'absint',
                ],
                'page'     => [
                    'type'              => 'integer',
                    'default'           => 1,
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]
    );
}

function xabia_federation_mec_touch_access_log() {
    $ip = '';
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
    }
    $ua = '';
    if (!empty($_SERVER['HTTP_USER_AGENT'])) {
        $ua = sanitize_text_field(wp_unslash(substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 240)));
    }
    update_option(
        XABIA_FED_MEC_LOG_OPTION,
        [
            'time'       => current_time('mysql'),
            'time_gmt'   => current_time('mysql', true),
            'ip'         => $ip,
            'user_agent' => $ua,
        ],
        false
    );
}

/**
 * Texto plano del contenido del post (sin HTML).
 */
function xabia_federation_mec_plain_content($post_id) {
    $raw = get_post_field('post_content', (int) $post_id, 'raw');
    $plain = wp_strip_all_tags((string) $raw);
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim(preg_replace('/\s+/u', ' ', $plain));
}

/**
 * URL pública del evento orientada a reserva (mismo patrón que [ACTION:BOOK] en el chat).
 *
 * @param int $post_id
 */
function xabia_federation_mec_reservation_url(int $post_id): string {
    $url = get_permalink($post_id);
    if (!is_string($url) || $url === '') {
        return '';
    }

    return (string) apply_filters('xabia_mec_event_reservation_url', $url, $post_id);
}

/**
 * @param WP_REST_Request $request
 */
function xabia_federation_mec_rest_callback($request) {
    if (!function_exists('xabia_mec_license_gate') || !xabia_mec_license_gate()) {
        return new WP_Error(
            'xabia_mec_license',
            __('La suscripción Xabia MEC no está activa en el Hub para este sitio.', 'xabia-intelligence'),
            ['status' => 403]
        );
    }

    xabia_federation_mec_touch_access_log();

    $per_page = (int) $request->get_param('per_page');
    $per_page = min(200, max(1, $per_page));
    $page = max(1, (int) $request->get_param('page'));

    $records = [];
    $found = 0;
    $max_pages = 0;

    if (post_type_exists('mec-events')) {
        $q = new WP_Query(
            [
                'post_type'              => 'mec-events',
                'post_status'            => ['publish', 'future'],
                'post_parent'            => 0,
                'posts_per_page'         => $per_page,
                'paged'                  => $page,
                'orderby'                => 'meta_value',
                'meta_key'               => 'mec_start_date',
                'order'                  => 'ASC',
                'no_found_rows'          => false,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => false,
            ]
        );
        $found = (int) $q->found_posts;
        $max_pages = (int) $q->max_num_pages;

        foreach ($q->posts as $post) {
            if (!($post instanceof WP_Post)) {
                continue;
            }
            $id = (int) $post->ID;
            $slots = function_exists('xabia_mec_compute_available_slots') ? xabia_mec_compute_available_slots($id) : '';
            $records[] = [
                'ID'                  => $id,
                'source_id'           => 'mec-event:' . $id,
                'event_title'         => get_the_title($id),
                'event_content'       => xabia_federation_mec_plain_content($id),
                'mec_start_date'      => (string) get_post_meta($id, 'mec_start_date', true),
                'mec_location'        => (string) get_post_meta($id, 'mec_location', true),
                'mec_cost'            => (string) get_post_meta($id, 'mec_cost', true),
                'mec_available_slots' => $slots,
                'permalink'           => xabia_federation_mec_reservation_url($id),
            ];
        }
        wp_reset_postdata();
    }

    return new WP_REST_Response(
        [
            'records'       => $records,
            'mapping_hint'  => xabia_federation_mec_mapping_hint(),
            'total_records' => $found,
            'total_pages'   => $max_pages,
        ],
        200
    );
}

/**
 * Contenido del feed REST (eventos MEC para Xabia Central). Se muestra en la pestaña MEC del agente.
 *
 * @param bool $embed true = omitir envoltorio .wrap (encajado en otra pantalla).
 */
function xabia_federation_mec_render_feed_panel(bool $embed = false): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    $url = rest_url('xabia/v1/federation-events');
    $log = get_option(XABIA_FED_MEC_LOG_OPTION, []);
    if (!is_array($log)) {
        $log = [];
    }
    $bridge_ok = class_exists('Xabia_Federation_Nexus', false) && Xabia_Federation_Nexus::get_bridge_secret() !== '';
    $lic_ok = function_exists('xabia_mec_license_gate') && xabia_mec_license_gate();

    $mapping_json = wp_json_encode(xabia_federation_mec_mapping_hint(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if (!$embed) {
        echo '<div class="wrap xabia-fed-mec-wrap">';
        echo '<h1>' . esc_html__('Feed REST — eventos MEC (Xabia Central)', 'xabia-intelligence') . '</h1>';
        echo '<p class="description">' . esc_html__('Endpoint para que Xabia Central consuma eventos de este sitio. Requiere suscripción MEC activa en el Hub; peticiones externas usan la cabecera X-Xabia-Key (misma clave que el puente de federación, si lo tienes habilitado).', 'xabia-intelligence') . '</p>';
    } else {
        echo '<p class="description" style="margin:0 0 12px;">' . esc_html__('Solo necesario si integras este sitio como nodo con Xabia Central u otras integraciones vía REST.', 'xabia-intelligence') . '</p>';
    }

    if (!$lic_ok) {
        echo '<div class="notice notice-warning inline"><p>' . esc_html__('Sin suscripción MEC activa en el Hub, el endpoint responderá 403 a clientes externos hasta que actives la licencia en Addons.', 'xabia-intelligence') . '</p></div>';
    }
    if (!$bridge_ok) {
        echo '<div class="notice notice-warning inline"><p>' . esc_html__('Para peticiones anónimas con cabecera, configura la clave del puente de federación (si utilizas el módulo Federación en este sitio).', 'xabia-intelligence') . '</p></div>';
    }

    echo '<div class="xabia-fed-mec-card xabia-fed-mec-endpoint">';
    echo '<h2>' . esc_html__('URL del endpoint', 'xabia-intelligence') . '</h2>';
    echo '<code>' . esc_html($url) . '</code>';
    echo '<p class="xabia-fed-mec-note">' . esc_html__('Petición GET con cabecera HTTP: X-Xabia-Key: &lt;clave del puente&gt;. También se admite X-Xabia-Fed-Key.', 'xabia-intelligence') . '</p>';
    echo '</div>';

    echo '<div class="xabia-fed-mec-card">';
    echo '<h2 class="xabia-fed-mec-mapping-h2">' . esc_html__('Mapeo sugerido (mapping_hint)', 'xabia-intelligence');
    if ($lic_ok) {
        echo ' <span class="dashicons dashicons-info" title="' . esc_attr__('Referencias de campos para integradores.', 'xabia-intelligence') . '"></span>';
    }
    echo '</h2>';
    if ($lic_ok) {
        echo '<p class="xabia-fed-mec-mapping-hint">' . esc_html__('Referencia de campos que devuelve el feed; alinea con el mapeo del agente si sincronizas la misma fuente MEC.', 'xabia-intelligence') . '</p>';
    } else {
        echo '<p class="xabia-fed-mec-mapping-hint">' . esc_html__('Activa la suscripción MEC en Addons para usar el endpoint en producción.', 'xabia-intelligence') . '</p>';
    }
    echo '<textarea class="large-text code" rows="8" readonly>' . esc_textarea((string) $mapping_json) . '</textarea>';
    echo '</div>';

    echo '<div class="xabia-fed-mec-card xabia-fed-mec-log">';
    echo '<h2>' . esc_html__('Último acceso al endpoint', 'xabia-intelligence') . '</h2>';
    if (empty($log['time'])) {
        echo '<p>' . esc_html__('Aún no se ha registrado ningún acceso.', 'xabia-intelligence') . '</p>';
    } else {
        echo '<dl>';
        echo '<dt>' . esc_html__('Fecha y hora (sitio)', 'xabia-intelligence') . '</dt><dd>' . esc_html((string) ($log['time'] ?? '')) . '</dd>';
        echo '<dt>' . esc_html__('Fecha y hora (GMT)', 'xabia-intelligence') . '</dt><dd>' . esc_html((string) ($log['time_gmt'] ?? '')) . '</dd>';
        echo '<dt>' . esc_html__('IP', 'xabia-intelligence') . '</dt><dd>' . esc_html((string) ($log['ip'] ?? '')) . '</dd>';
        echo '<dt>' . esc_html__('User-Agent', 'xabia-intelligence') . '</dt><dd>' . esc_html((string) ($log['user_agent'] ?? '')) . '</dd>';
        echo '</dl>';
    }
    echo '</div>';

    if (!$embed) {
        echo '</div>';
    }
}

/**
 * @deprecated El menú raíz se retiró; el panel vive en la pestaña MEC del agente.
 */
function xabia_federation_mec_render_admin_page(): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    xabia_federation_mec_render_feed_panel(false);
}

function xabia_federation_mec_admin_inline_styles() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $page = isset($_GET['page']) ? (string) wp_unslash($_GET['page']) : '';
    $on_agent_edit = $page === 'xabia-settings' && isset($_GET['edit']) && (string) wp_unslash($_GET['edit']) !== '';
    if (!$on_agent_edit && $page !== 'xabia-federation-mec-bridge') {
        return;
    }
    ?>
    <style id="xabia-fed-mec-bridge-styles">
        .xabia-fed-mec-wrap { max-width: 920px; }
        .xabia-fed-mec-wrap h1 {
            color: #c2185b;
            border-bottom: 3px solid #c2185b;
            padding-bottom: 0.35em;
        }
        .xabia-fed-mec-card {
            background: #fff;
            border: 1px solid #c2185b;
            border-left-width: 4px;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            padding: 16px 20px;
            margin: 18px 0;
        }
        .xabia-fed-mec-card h2 {
            margin-top: 0;
            color: #c2185b;
            font-size: 1.1em;
        }
        .xabia-fed-mec-endpoint code {
            display: block;
            padding: 10px 12px;
            background: #faf7f9;
            border: 1px solid #e8d0dc;
            word-break: break-all;
            font-size: 13px;
        }
        .xabia-fed-mec-log dt { font-weight: 600; margin-top: 8px; color: #333; }
        .xabia-fed-mec-log dd { margin: 0 0 0 1em; }
        .xabia-fed-mec-mapping-hint { font-size: 13px; color: #555; margin: -6px 0 12px; max-width: 720px; }
        .xabia-fed-mec-mapping-h2 { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .xabia-fed-mec-mapping-h2 .dashicons { color: #c2185b; width: 18px; height: 18px; font-size: 18px; }
    </style>
    <?php
}
