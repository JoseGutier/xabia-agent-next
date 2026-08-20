<?php
/**
 * FRONTEND — widget de chat (shortcodes `[xabia_agent]` y `[xabia_chat]`).
 * ESTRUCTURA: HTML Original (#xabia-chat-app) para compatibilidad CSS.
 * Renderiza el contenedor y datos; la lógica interactiva está en `chatbox.js`.
 *
 * Efecto túnel: solo query `ente_id` y atributo shortcode `ente_id` (sin `item`).
 */

if (!defined('ABSPATH')) exit;

/**
 * Iconos SVG embebidos (no dependen de Google Fonts; compatibles con caché/Rocket).
 */
/**
 * Iconos Lucide SVG inline (stroke nativo, sin dependencias externas).
 *
 * @param string $name
 * @param int    $size  Tamaño en px (ancho/alto del SVG).
 */
function xabia_chatbox_icon_svg(string $name, int $size = 20): string {
    $s = max(14, min(64, $size));
    $base = 'xmlns="http://www.w3.org/2000/svg" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';

    if ($name === 'volume_off') {
        $name = 'volume_x';
    }
    if ($name === 'volume_up') {
        $name = 'volume_2';
    }

    $extra_class = '';
    if ($name === 'volume_x') {
        $extra_class = ' xabia-icon-vol-off';
    } elseif ($name === 'volume_2') {
        $extra_class = ' xabia-icon-vol-on';
    }

    $icons = [
        'mic' => '<svg class="xabia-lucide' . $extra_class . '" ' . $base . '><path d="M12 19v3"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><rect x="9" y="2" width="6" height="13" rx="3"/></svg>',
        'arrow_up' => '<svg class="xabia-lucide' . $extra_class . '" ' . $base . '><path d="m5 12 7-7 7 7"/><path d="M12 19V5"/></svg>',
        'send' => '<svg class="xabia-lucide' . $extra_class . '" ' . $base . '><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>',
        'chevron_down' => '<svg class="xabia-lucide' . $extra_class . '" ' . $base . '><path d="m6 9 6 6 6-6"/></svg>',
        'volume_x' => '<svg class="xabia-lucide xabia-icon-vol-off" ' . $base . '><path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/><line x1="22" x2="16" y1="9" y2="15"/><line x1="16" x2="22" y1="9" y2="15"/></svg>',
        'volume_2' => '<svg class="xabia-lucide xabia-icon-vol-on" ' . $base . '><path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>',
    ];

    return $icons[$name] ?? '';
}

function xabia_agent_shortcode_debug_comment(string $message): string {
    if (!current_user_can('manage_options')) {
        return '';
    }
    return '<!-- Xabia Agent: ' . esc_html($message) . ' -->';
}

/**
 * Encola JS/CSS del chat (debe ejecutarse antes de wp_print_footer_scripts).
 */
/**
 * Payload JS del chat (incluye bridge LITE cuando aplica).
 *
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function xabia_chatbox_build_settings_payload(string $project_id, array $extra = []): array {
    $payload = array_merge(
        [
            'projectId'  => $project_id,
            'xProject'   => $project_id,
            'enteId'     => '',
            'scope'      => 'global',
            'strictMode' => false,
        ],
        $extra
    );

    if (class_exists('Xabia_Mode', false) && Xabia_Mode::is_lite()) {
        $payload['liteMode'] = true;
        $payload['ajaxAction'] = 'xabia_lite_ask_ai';
        $payload['nonce'] = wp_create_nonce('xabia_lite_nonce');
    }

    if (class_exists('Xabia_Chat_Input', false)) {
        $payload['inputMaxLines'] = Xabia_Chat_Input::max_lines();
        $payload['inputMaxChars'] = Xabia_Chat_Input::max_chars();
    }

    if (class_exists('Xabia_Interface', false)) {
        $iface = Xabia_Interface::get_project_settings($project_id);
        $payload['presentationMode'] = (string) ($iface[Xabia_Interface::OPT_PRESENTATION_MODE] ?? 'web_adaptive');
    }

    return $payload;
}

function xabia_enqueue_chatbox_assets_for_project(string $project_id, array $project_data = []): void {
    static $enqueued = [];
    $project_id = sanitize_key($project_id);
    if ($project_id === '' || isset($enqueued[$project_id])) {
        return;
    }
    $enqueued[$project_id] = true;

    $url_ente_id = isset($_GET['ente_id']) ? wp_unslash($_GET['ente_id']) : '';
    $data_ente_attr = is_string($url_ente_id) && $url_ente_id !== '' ? (string) $url_ente_id : '';

    $ver = defined('XABIA_VERSION') ? XABIA_VERSION : '1.0';
    $styles_path = __DIR__ . '/styles.css';
    $styles_ver = $ver;
    if (is_readable($styles_path)) {
        $styles_ver .= '.' . (string) filemtime($styles_path);
    }
    $js_path = __DIR__ . '/chatbox.js';
    $js_ver = $ver;
    if (is_readable($js_path)) {
        $js_ver .= '.' . (string) filemtime($js_path);
    }

    wp_enqueue_script('jquery');
    wp_enqueue_style('xabia-frontend-styles', plugins_url('styles.css', __FILE__), ['xabia-interface'], $styles_ver);
    wp_enqueue_script('xabia-chatbox', plugins_url('chatbox.js', __FILE__), ['jquery'], $js_ver, true);

    wp_localize_script(
        'xabia-chatbox',
        'XabiaSettings',
        xabia_chatbox_build_settings_payload(
            $project_id,
            [
                'projectId'  => $project_id,
                'xProject'   => $project_id,
                'enteId'     => $data_ente_attr,
                'scope'      => 'global',
                'strictMode' => false,
            ]
        )
    );

    $woo_cart = apply_filters('xabia_chatbox_woo_cart_payload', null, $project_id, $project_data);
    if (is_array($woo_cart) && !empty($woo_cart['mode'])) {
        wp_localize_script('xabia-chatbox', 'xabiaWooCart', $woo_cart);
    }
    if (class_exists('Xabia_Reservas_Handler', false)) {
        $projects_cfg = get_option('xabia_projects_config', []);
        $proj_cfg = isset($projects_cfg[$project_id]) && is_array($projects_cfg[$project_id]) ? $projects_cfg[$project_id] : [];
        $events_base = home_url('/');
        if (class_exists('Xabia_MEC_Public_Link', false)) {
            $remote_base = Xabia_MEC_Public_Link::configured_remote_site_url($proj_cfg);
            if ($remote_base !== '') {
                $events_base = $remote_base . '/';
            } elseif (!Xabia_MEC_Public_Link::is_remote_catalog($proj_cfg)) {
                $events_base = home_url('/');
            }
        }
        wp_localize_script('xabia-chatbox', 'xabiaReservas', [
            'engine'           => Xabia_Reservas_Handler::engine_for_project($project_id),
            'homeUrl'          => $events_base,
            'remoteSiteUrl'    => class_exists('Xabia_MEC_Public_Link', false)
                ? Xabia_MEC_Public_Link::configured_remote_site_url($proj_cfg)
                : '',
            'eventsPath'       => class_exists('Xabia_MEC_Public_Link', false)
                ? Xabia_MEC_Public_Link::get_events_rewrite_slug($proj_cfg)
                : 'actividades',
            'ameliaTriggerUrl' => (string) apply_filters('xabia_amelia_booking_trigger_url', ''),
        ]);
    }

    if (class_exists('Xabia_I18n', false)) {
        wp_localize_script('xabia-chatbox', 'xabiaI18n', Xabia_I18n::chatbox_js_strings());
    }
}

function shortcode_xabia_agent_renderer($atts) {
    if (class_exists('Xabia_Federation_Nexus', false) && Xabia_Federation_Nexus::is_bridge_only_mode()) {
        return xabia_agent_shortcode_debug_comment('modo solo Puente (federación): shortcode desactivado en front.');
    }

    $atts = shortcode_atts(['id' => 'default', 'lang' => '', 'scope' => 'global', 'ente_id' => '', 'totem' => '', 'avatar_name' => ''], $atts);
    $project_id = sanitize_key($atts['id']);
    $url_project = isset($_GET['x_project']) ? sanitize_key(wp_unslash($_GET['x_project'])) : '';
    if ($url_project !== '') {
        $project_id = $url_project;
    }

    $xabia_front_injected_ente = apply_filters('xabia_agent_frontend_injected_ente_id', '', $project_id, $atts);
    $xabia_front_injected_ente = is_string($xabia_front_injected_ente) ? trim($xabia_front_injected_ente) : '';

    $lang_attr = isset($atts['lang']) ? trim((string) $atts['lang']) : '';
    $current_lang = $lang_attr !== ''
        ? strtolower(substr(sanitize_key($lang_attr), 0, 2))
        : strtolower(substr(get_locale(), 0, 2));
    if ($current_lang === '') {
        $current_lang = 'es';
    }

    if (session_status() === PHP_SESSION_NONE && !headers_sent()) { session_start(); }

    $url_ente_id = isset($_GET['ente_id']) ? wp_unslash($_GET['ente_id']) : null;
    $url_tunnel = ($url_ente_id !== null && $url_ente_id !== '') ? $url_ente_id : null;
    $shortcode_tunnel = !empty($atts['ente_id']) ? sanitize_text_field((string) $atts['ente_id']) : '';

    if ($url_tunnel !== null && $url_tunnel !== '') {
        $current_scope = sanitize_title($url_tunnel);
        $is_strict_mode = true;
    } elseif ($xabia_front_injected_ente !== '') {
        $current_scope = $xabia_front_injected_ente;
        $is_strict_mode = true;
    } elseif ($shortcode_tunnel !== '') {
        $current_scope = sanitize_title($shortcode_tunnel);
        $is_strict_mode = true;
    } else {
        $current_scope = sanitize_text_field($atts['scope']);
        $is_strict_mode = false;
    }

    $is_lite = class_exists('Xabia_Mode', false) && Xabia_Mode::is_lite();

    $config_all = get_option('xabia_projects_config', []);
    $project_data = $config_all[$project_id] ?? null;

    if (!$project_data && !$is_lite && !is_admin()) {
        return xabia_agent_shortcode_debug_comment('proyecto no encontrado: id="' . $project_id . '".');
    }

    if ($is_lite && !is_array($project_data)) {
        $project_data = [];
    }

    $is_paused = !$is_lite
        && class_exists('Xabia_Interface', false)
        && Xabia_Interface::is_project_paused($project_id);

    $design = $project_data['design'] ?? [];
    $rules  = $project_data['rules'] ?? [];

    $accent_color = !empty($design['primary_color']) ? $design['primary_color'] : '#2271b1';
    $bg_color     = !empty($design['bg_color']) ? $design['bg_color'] : '#ffffff';
    $font_size_raw = $design['font_size'] ?? '1';
    $font_size_em  = class_exists('Xabia_Agent_Core', false)
        ? Xabia_Agent_Core::normalize_chat_font_size_em($font_size_raw)
        : '1';
    $avatar_name  = !empty($atts['avatar_name']) ? sanitize_text_field($atts['avatar_name']) : ($design['avatar_name'] ?? 'Xabia');
    if ($avatar_name === '') $avatar_name = 'Xabia';
    $greeting = !empty($rules['greeting']) ? $rules['greeting'] : Xabia_I18n::t('Hola, soy Xabia.');
    $greeting = Xabia_I18n::translate_agent_greeting($greeting, $project_id);

    $starter_scope = ($current_scope !== 'global' && $is_strict_mode) ? $current_scope : '';
    $starter_questions = class_exists('Xabia_Starter_Questions', false)
        ? Xabia_Starter_Questions::for_project($project_id, $starter_scope, is_array($rules) ? $rules : [])
        : [];
    $starter_questions_json = wp_json_encode(array_values($starter_questions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($starter_questions_json)) {
        $starter_questions_json = '[]';
    }

    if (!$is_lite && $current_scope !== 'global' && $is_strict_mode) {

        global $wpdb;
        $table = Xabia_DB::table('knowledge_vectors');
        $ente_display_name = '';

        $meta = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_data FROM $table WHERE project_id = %s AND ente_id = %s ORDER BY id DESC LIMIT 1",
            $project_id,
            $current_scope
        ));

        if (!empty($meta)) {
            $decoded = json_decode($meta, true);
            if (is_array($decoded) && !empty($decoded['__ente_display'])) {
                $ente_display_name = (string)$decoded['__ente_display'];
            }
        }

        if (empty($ente_display_name)) {
            $ente_display_name = $shortcode_tunnel !== '' ? $shortcode_tunnel : ($url_tunnel ?? $current_scope);

            $ente_display_name = ucwords(str_replace(['-', '_'], ' ', $ente_display_name));
        }

        if (!empty($ente_display_name)) {
            $greeting = "Hola, soy $ente_display_name. " . $greeting;
        }
    } elseif ($current_scope !== 'global') {
        $clean_name = ucfirst(str_replace(['med_', '_'], ['', ' '], $current_scope));
        $greeting = str_replace('Xabia', "Xabia ($clean_name)", $greeting);
    }

    $iso_map = ['es' => 'es-ES', 'eu' => 'eu-ES', 'en' => 'en-US', 'fr' => 'fr-FR', 'ca' => 'ca-ES', 'gl' => 'gl-ES', 'pt' => 'pt-PT', 'de' => 'de-DE', 'it' => 'it-IT'];
    $current_stt = $iso_map[$current_lang] ?? ($current_lang . '-' . strtoupper($current_lang));

    $totem_shortcode = isset($atts['totem']) ? absint($atts['totem']) : 0;
    $totem_project  = isset($project_data['totem']['enabled']) && !empty($project_data['totem']['enabled'])
        ? absint($project_data['totem']['tiempo_inactividad_defecto'] ?? 0)
        : 0;
    $totem_minutes  = $is_lite ? 0 : ($totem_shortcode > 0 ? $totem_shortcode : $totem_project);

    $tts_voice_pref = isset($design['tts_voice']) && in_array($design['tts_voice'], ['female', 'male'], true) ? $design['tts_voice'] : 'default';
    $tts_rate = isset($design['tts_rate']) ? max(0.5, min(2, (float) $design['tts_rate'])) : 1;
    $tts_clean = [
        'bold'    => !empty($design['tts_clean_bold']),
        'italic'  => !empty($design['tts_clean_italic']),
        'actions' => !empty($design['tts_clean_actions']),
        'emojis'  => !empty($design['tts_clean_emojis']),
        'patterns' => isset($design['tts_clean_patterns']) && is_array($design['tts_clean_patterns']) ? $design['tts_clean_patterns'] : [],
    ];
    $tts_config_json = wp_json_encode(['voice' => $tts_voice_pref, 'rate' => $tts_rate, 'clean' => $tts_clean]);

    $avatar_colors = [
        'bg'     => '#99ccff',
        'shadow' => '#ffffff',
        'dots'   => $accent_color,
        'mouth'  => '#FFFFFF',
    ];
    $speaking_avatar = 1;
    $custom_avatar_url = '';
    $presentation_mode = 'web_adaptive';
    $presentation_classes = [];
    $is_kiosk_presentation = false;
    $is_transparent_presentation = false;
    if (class_exists('Xabia_Interface', false)) {
        $iface = Xabia_Interface::get_project_settings($project_id);
        if (!empty($iface[Xabia_Interface::OPT_AVATAR_COLORS]) && is_array($iface[Xabia_Interface::OPT_AVATAR_COLORS])) {
            $avatar_colors = array_merge($avatar_colors, $iface[Xabia_Interface::OPT_AVATAR_COLORS]);
            $avatar_colors['mouth'] = '#FFFFFF';
        }
        $speaking_avatar = !empty($iface[Xabia_Interface::OPT_SPEAKING_AVATAR]) ? 1 : 0;
        $presentation_mode = (string) ($iface[Xabia_Interface::OPT_PRESENTATION_MODE] ?? 'web_adaptive');
        $presentation_classes = Xabia_Interface::presentation_mode_classes($presentation_mode);
        $is_kiosk_presentation = Xabia_Interface::is_kiosk_presentation_mode($presentation_mode);
        $is_transparent_presentation = Xabia_Interface::is_transparent_presentation_mode($presentation_mode);
        if ($is_kiosk_presentation) {
            $speaking_avatar = 1;
        }
        if (($iface[Xabia_Interface::OPT_TRIGGER_TYPE] ?? '') === 'custom_image') {
            $custom_avatar_url = esc_url((string) ($iface[Xabia_Interface::OPT_CUSTOM_TRIGGER] ?? ''));
        }
    }
    if ($is_transparent_presentation) {
        $bg_color = 'transparent';
    } elseif ($is_kiosk_presentation) {
        $bg_color = '#ffffff';
    }
    $chatbox_class_list = array_merge(
        ['xabia-chatbox', 'xabia-chatbot', 'xabia-ui-modern', 'xabia-state-empty'],
        $presentation_classes
    );
    if ($is_kiosk_presentation) {
        $chatbox_class_list[] = 'xabia-kiosk-embed';
        $chatbox_class_list[] = 'xabia-immersive-mode';
        $chatbox_class_list[] = 'xabia-chatbox--fullscreen';
        $chatbox_class_list[] = 'xabia-panel-shell';
        $chatbox_class_list[] = 'is-active';
    }
    $chatbox_classes = implode(' ', array_unique(array_filter($chatbox_class_list)));
    if (!function_exists('xabia_render_kinetic_avatar_svg')) {
        require_once dirname(__FILE__) . '/avatar-svg.php';
    }

    $uploads_base = wp_upload_dir();
    $images_base_url = !empty($uploads_base['baseurl']) ? rtrim($uploads_base['baseurl'], '/') . '/' : '';
    $container_id = 'xabia-chatbox-' . esc_attr($project_id);
    $ajax_url = esc_url(admin_url('admin-ajax.php'));
    $totem_reset_json = wp_json_encode(
        [
            'avatar'        => $avatar_name,
            'greeting_html' => wp_kses_post($greeting),
            'starter_questions' => array_values($starter_questions),
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (! is_string($totem_reset_json)) {
        $totem_reset_json = '{}';
    }

    $data_ente_attr = ($url_tunnel !== null && $url_tunnel !== '') ? $url_tunnel : $shortcode_tunnel;
    if ($data_ente_attr === '' && $xabia_front_injected_ente !== '') {
        $data_ente_attr = $xabia_front_injected_ente;
    }
    $qr_auto_json = '';
    $frontend_auto_payload = apply_filters('xabia_agent_frontend_auto_payload', null, $project_id, $atts);
    if (is_array($frontend_auto_payload) && !empty($frontend_auto_payload['active']) && !empty($frontend_auto_payload['greeting'])) {
        $qr_auto_json = wp_json_encode(
            [
                'qr_id'    => (string) ($frontend_auto_payload['qr_id'] ?? ''),
                'greeting' => (string) $frontend_auto_payload['greeting'],
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
    if (! is_string($qr_auto_json)) {
        $qr_auto_json = '';
    }

    if ($is_paused) {
        return xabia_agent_shortcode_debug_comment(
            'agente pausado: id="' . $project_id . '". Actívelo en Xabia Agent → Activar y vacíe la caché.'
        );
    }

    if (class_exists('Xabia_Interface', false)) {
        Xabia_Interface::mark_chatbox_rendered($project_id);
    }

    xabia_enqueue_chatbox_assets_for_project($project_id, is_array($project_data) ? $project_data : []);
    wp_localize_script(
        'xabia-chatbox',
        'XabiaSettings',
        xabia_chatbox_build_settings_payload(
            $project_id,
            [
                'projectId'  => $project_id,
                'xProject'   => $project_id,
                'enteId'     => (string) $data_ente_attr,
                'scope'      => (string) $current_scope,
                'strictMode' => (bool) $is_strict_mode,
            ]
        )
    );

    ob_start(); ?>

    <style>
        #<?php echo esc_attr($container_id); ?>.xabia-chatbox {
            --xabia-accent: <?php echo esc_attr($accent_color); ?>;
            --xabia-bg: <?php echo esc_attr($bg_color); ?>;
            --xabia-magenta: #c2185b;
            --xabia-primary-color: <?php echo esc_attr($accent_color); ?>;
            --xabia-bg-color: <?php echo esc_attr($bg_color); ?>;
            --xabia-mouth-color: #FFFFFF;
            --xabia-avatar-bg: <?php echo esc_attr($avatar_colors['bg']); ?>;
            --xabia-avatar-shadow: <?php echo esc_attr($avatar_colors['shadow']); ?>;
            --xabia-dots-color: <?php echo esc_attr($avatar_colors['dots']); ?>;
            font-size: <?php echo esc_attr($font_size_em); ?>em;
        }
    </style>

    <div id="<?php echo esc_attr($container_id); ?>" class="<?php echo esc_attr($chatbox_classes); ?>" data-project="<?php echo esc_attr($project_id); ?>" data-presentation-mode="<?php echo esc_attr($presentation_mode); ?>" data-endpoint="<?php echo esc_url($ajax_url); ?>" data-scope="<?php echo esc_attr($current_scope); ?>" data-strict-mode="<?php echo $is_strict_mode ? '1' : '0'; ?>" data-ente-id-raw="<?php echo esc_attr($shortcode_tunnel); ?>" data-ente-id="<?php echo esc_attr($data_ente_attr); ?>" data-qr-auto="<?php echo esc_attr($qr_auto_json); ?>" data-starter-questions="<?php echo esc_attr($starter_questions_json); ?>" data-totem-minutes="<?php echo (int) $totem_minutes; ?>" data-totem-reset="<?php echo esc_attr($totem_reset_json); ?>" data-images-base="<?php echo esc_url($images_base_url); ?>" data-lang="<?php echo esc_attr($current_lang); ?>" data-voice="1" data-tts="<?php echo esc_attr($tts_config_json); ?>" data-avatar-name="<?php echo esc_attr($avatar_name); ?>" data-speaking-avatar="<?php echo (int) $speaking_avatar; ?>">

        <?php if ($speaking_avatar) : ?>
        <div class="xabia-immersive-avatar-stage" aria-hidden="<?php echo $is_kiosk_presentation ? 'false' : 'true'; ?>">
            <?php if ($custom_avatar_url !== '') : ?>
                <div class="xabia-kinetic-wrapper xabia-kinetic-wrapper--immersive xabia-kinetic-wrapper--custom" aria-hidden="true">
                    <img src="<?php echo esc_url($custom_avatar_url); ?>" alt="" class="xabia-trigger-custom-img xabia-trigger-custom-img--immersive" width="280" height="280" loading="lazy" decoding="async" />
                </div>
            <?php else : ?>
                <?php echo xabia_render_kinetic_avatar_svg($avatar_colors, ['class' => 'xabia-kinetic-wrapper--immersive']); ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="xabia-immersive-panel">
        <header class="xabia-chat-header">
            <button type="button" class="xabia-panel-close" aria-label="<?php echo esc_attr(Xabia_I18n::t('Minimizar chat')); ?>">
                <?php echo xabia_chatbox_icon_svg('chevron_down', 22); ?>
            </button>
            <div class="xabia-chat-header__center">
                <span class="xabia-chat-header__status" aria-hidden="true"></span>
                <span class="xabia-chat-header__name"><?php echo esc_html($avatar_name); ?></span>
            </div>
            <span class="xabia-chat-header__spacer" aria-hidden="true"></span>
        </header>

        <div class="xabia-totem-warning" style="display:none;" role="alert"></div>

        <div class="xabia-chat-body">
            <div class="xabia-text-scroll">
                <div class="xabia-chat-messages xabia-chat-history" role="log" aria-live="polite" aria-relevant="additions">
                    <div class="xabia-messages-stream">
                        <div class="xabia-msg bot xabia-msg-greeting"><span class="xabia-msg-content"><?php echo wp_kses_post($greeting); ?></span></div>
                    </div>
                    <div id="xabia-voice-hero-<?php echo esc_attr($project_id); ?>" class="xabia-voice-hero" aria-hidden="false">
                        <button type="button" class="xabia-voice-hero__orb xabia-mic xabia-mic-hero" title="<?php echo esc_attr(Xabia_I18n::t('Toca para hablar o mantén pulsado')); ?>" aria-label="<?php echo esc_attr(Xabia_I18n::t('Toca para hablar o mantén pulsado')); ?>">
                            <?php echo xabia_chatbox_icon_svg('mic', 44); ?>
                        </button>
                    </div>
                    <div class="xabia-starter-suggestions" role="group" aria-label="<?php echo esc_attr(Xabia_I18n::t('Preguntas sugeridas')); ?>" hidden></div>
                </div>
                <div class="xabia-compose-area">
                    <textarea class="xabia-input-field" placeholder="<?php echo esc_attr(Xabia_I18n::t('Escribe aquí o pulsa el micro para hablar...')); ?>" autocomplete="off" rows="1"></textarea>
                </div>
            </div>

            <div class="xabia-typing-dots" style="display:none;" aria-hidden="true" role="status">
                <span class="xabia-typing-dot" aria-hidden="true"></span>
                <span class="xabia-typing-dot" aria-hidden="true"></span>
                <span class="xabia-typing-dot" aria-hidden="true"></span>
                <span class="xabia-waiting-message" aria-live="polite"></span>
            </div>
        </div>

        <div class="xabia-input-area">
            <div class="xabia-mic-listening-banner" role="status" aria-live="polite">
                <span class="xabia-mic-listening-banner__dot" aria-hidden="true"></span>
                <span class="xabia-mic-listening-banner__text"><?php echo esc_html(Xabia_I18n::t('Escuchando… Habla ahora')); ?></span>
            </div>
            <div class="xabia-input-pill">
                <button type="button" class="xabia-btn-icon xabia-mic" title="<?php echo esc_attr(Xabia_I18n::t('Toca para hablar o mantén pulsado')); ?>" aria-label="<?php echo esc_attr(Xabia_I18n::t('Toca para hablar o mantén pulsado')); ?>"><?php echo xabia_chatbox_icon_svg('mic', 18); ?></button>
                <button type="button" class="xabia-btn-icon xabia-mute" title="<?php echo esc_attr(Xabia_I18n::t('Activar voz (lectura en alto)')); ?>" aria-label="<?php echo esc_attr(Xabia_I18n::t('Activar voz')); ?>"><?php echo xabia_chatbox_icon_svg('volume_x', 18); ?><?php echo xabia_chatbox_icon_svg('volume_2', 18); ?></button>
                <button type="button" class="xabia-btn-icon xabia-send" title="<?php echo esc_attr(Xabia_I18n::t('Enviar')); ?>" aria-label="<?php echo esc_attr(Xabia_I18n::t('Enviar mensaje')); ?>"><?php echo xabia_chatbox_icon_svg('send', 18); ?></button>
            </div>
        </div>

        <div class="xabia-powered-by">
            <a href="https://xabia.ai" target="_blank" rel="noopener"><?php echo esc_html(Xabia_I18n::t('Powered by Xabia AI')); ?></a>
        </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
