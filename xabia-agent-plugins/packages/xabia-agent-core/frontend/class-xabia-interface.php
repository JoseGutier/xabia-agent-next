<?php
/**
 * Interfaz nativa por agente: avatar cinético / imagen + panel flotante.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Interface {

    /** @var list<string> */
    private static $page_projects = [];

    /**
     * Proyectos con botón lanzador vía shortcode (assets + localize; no fuerza avatar flotante).
     *
     * @var list<string>
     */
    private static $launcher_projects = [];

    /** IDs detectados en el contenido de la página (Elementor, bloques, etc.). */
    /** @var list<string> */
    private static $primed_projects = [];

    /** @var list<string> */
    private static $rendered_chatbox = [];

    /** Proyectos citados por [xabia_search_bar] en esta petición. */
    /** @var list<string> */
    private static $search_bar_projects = [];

    private static bool $park_next_chatbox = false;

    /** @var list<string> */
    private static $overlays_rendered = [];

    private static int $trigger_instance = 0;

    private static bool $assets_enqueued = false;

    private static bool $footer_shortcode_fallback_done = false;

    public const OPT_TRIGGER_TYPE            = 'trigger_type';
    public const OPT_CUSTOM_TRIGGER          = 'custom_trigger_url';
    public const OPT_AVATAR_COLORS           = 'avatar_colors';
    public const OPT_TRIGGER_POSITION        = 'trigger_position';
    public const OPT_TRIGGER_MARGIN_X        = 'margin_x';
    public const OPT_TRIGGER_MARGIN_Y        = 'margin_y';
    public const OPT_PANEL_LAYOUT            = 'panel_layout';
    public const OPT_EXCLUDE_POST_TYPES      = 'exclude_post_types';
    public const OPT_EXCLUDE_IDS             = 'exclude_ids';
    public const OPT_EXCLUDE_WOO_CART_CHECKOUT = 'exclude_woo_cart_checkout';
    public const OPT_AUTOLOAD_WITHOUT_SHORTCODE = 'autoload_without_shortcode';
    public const OPT_INCLUDE_PAGE_IDS           = 'include_page_ids';
    public const OPT_MOBILE_PRESET              = 'mobile_preset';
    /** Avatar parlante (modo teatro / lip-sync inmersivo) junto al chat. */
    public const OPT_SPEAKING_AVATAR            = 'speaking_avatar';
    /** web_adaptive | totem_transparent_vertical | totem_transparent_horizontal | screen_vertical | screen_horizontal */
    public const OPT_PRESENTATION_MODE          = 'presentation_mode';
    /** light | dark | auto. Claro por defecto. */
    public const OPT_COLOR_SCHEME               = 'color_scheme';
    /** Frase visible del botón lanzador en escritorio. */
    public const OPT_LAUNCHER_LABEL             = 'launcher_label';

    /** @return list<string> */
    public static function presentation_mode_keys(): array {
        return [
            'web_adaptive',
            'totem_transparent_vertical',
            'totem_transparent_horizontal',
            'screen_vertical',
            'screen_horizontal',
        ];
    }

    /**
     * Metadatos de cada perfil (admin: cuadrícula visual).
     *
     * @return array<string, array{group: string, group_label: string, title: string, summary: string, hint: string, thumb: string}>
     */
    public static function presentation_mode_catalog(): array {
        return [
            'web_adaptive' => [
                'group'       => 'web',
                'group_label' => __('Sitio web', 'xabia-intelligence'),
                'title'       => __('Botón de chat', 'xabia-intelligence'),
                'summary'     => __('Una barra pequeña abre el chat a pantalla completa.', 'xabia-intelligence'),
                'hint'        => __('En el móvil se queda en un círculo', 'xabia-intelligence'),
                'thumb'       => 'web',
            ],
            'totem_transparent_vertical' => [
                'group'       => 'kiosk',
                'group_label' => __('Pantalla dedicada (shortcode)', 'xabia-intelligence'),
                'title'       => __('Tótem transparente · vertical', 'xabia-intelligence'),
                'summary'     => __('Avatar arriba, chat abajo. Sin fondo opaco.', 'xabia-intelligence'),
                'hint'        => __('/xabia-box/ o [xabia_agent]', 'xabia-intelligence'),
                'thumb'       => 'totem-v',
            ],
            'totem_transparent_horizontal' => [
                'group'       => 'kiosk',
                'group_label' => __('Pantalla dedicada (shortcode)', 'xabia-intelligence'),
                'title'       => __('Tótem transparente · horizontal', 'xabia-intelligence'),
                'summary'     => __('Avatar a la izquierda, chat a la derecha. Sin fondo opaco.', 'xabia-intelligence'),
                'hint'        => __('/xabia-box/ o [xabia_agent]', 'xabia-intelligence'),
                'thumb'       => 'totem-h',
            ],
            'screen_vertical' => [
                'group'       => 'kiosk',
                'group_label' => __('Pantalla dedicada (shortcode)', 'xabia-intelligence'),
                'title'       => __('Pantalla vertical', 'xabia-intelligence'),
                'summary'     => __('Avatar arriba, chat abajo. Fondo sólido.', 'xabia-intelligence'),
                'hint'        => __('/xabia-box/ o [xabia_agent]', 'xabia-intelligence'),
                'thumb'       => 'screen-v',
            ],
            'screen_horizontal' => [
                'group'       => 'kiosk',
                'group_label' => __('Pantalla dedicada (shortcode)', 'xabia-intelligence'),
                'title'       => __('Pantalla horizontal', 'xabia-intelligence'),
                'summary'     => __('Avatar a la izquierda, chat a la derecha. Fondo sólido.', 'xabia-intelligence'),
                'hint'        => __('/xabia-box/ o [xabia_agent]', 'xabia-intelligence'),
                'thumb'       => 'screen-h',
            ],
        ];
    }

    public static function sanitize_presentation_mode(string $value): string {
        $value = sanitize_key($value);
        return in_array($value, self::presentation_mode_keys(), true) ? $value : 'web_adaptive';
    }

    public static function is_kiosk_presentation_mode(string $mode): bool {
        return $mode !== 'web_adaptive';
    }

    public static function is_transparent_presentation_mode(string $mode): bool {
        return str_starts_with($mode, 'totem_transparent_');
    }

    public static function presentation_split_axis(string $mode): string {
        return str_ends_with($mode, '_horizontal') ? 'horizontal' : 'vertical';
    }

    /**
     * Clases CSS del perfil de presentación (shortcode / xabia-box).
     *
     * @return list<string>
     */
    public static function presentation_mode_classes(string $mode): array {
        $mode = self::sanitize_presentation_mode($mode);
        if ($mode === 'web_adaptive') {
            return [];
        }
        $classes = ['xabia-present-kiosk', 'xabia-present-' . self::presentation_split_axis($mode)];
        if (self::is_transparent_presentation_mode($mode)) {
            $classes[] = 'xabia-present-transparent';
            $classes[] = 'xabia-present-totem';
        } else {
            $classes[] = 'xabia-present-screen';
        }
        return $classes;
    }

    public static function project_uses_web_launcher(string $project_id): bool {
        $settings = self::get_project_settings($project_id);
        if (self::is_kiosk_presentation_mode($settings[self::OPT_PRESENTATION_MODE])) {
            return false;
        }
        if (($settings[self::OPT_TRIGGER_POSITION] ?? '') === 'shortcode') {
            return false;
        }
        return true;
    }

    public static function init(): void {
        if (is_admin()) {
            return;
        }
        if (class_exists('Xabia_Federation_Nexus', false) && Xabia_Federation_Nexus::is_bridge_only_mode()) {
            return;
        }
        add_action('wp', [self::class, 'prime_projects_for_request'], 10);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_chatbox_scripts_early'], 15);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue_assets_early'], 20);
        add_action('wp_footer', [self::class, 'localize_footer_script'], 5);
        add_action('wp_footer', [self::class, 'render_footer'], 25);

        if (function_exists('add_filter')) {
            add_filter('rocket_delay_js_exclusions', [self::class, 'rocket_delay_js_exclusions']);
            add_filter('rocket_exclude_js', [self::class, 'rocket_exclude_js']);
        }
    }

    /**
     * Evita que WP Rocket parsee CSS inline de Elementor (errores querySelectorAll en bucle).
     */
    public static function maybe_disable_rocket_css_lazyload(): void {
        if (self::$page_projects === [] && self::$primed_projects === [] && self::$launcher_projects === []) {
            return;
        }
        add_filter('do_rocket_lazyload_css', '__return_false', 999);
    }

    /**
     * @param list<string> $patterns
     * @return list<string>
     */
    public static function rocket_delay_js_exclusions(array $patterns): array {
        $patterns[] = 'xabia-chatbox';
        $patterns[] = 'xabia-interface';
        $patterns[] = 'xabiaChatbox';
        return $patterns;
    }

    /**
     * @param list<string> $excluded
     * @return list<string>
     */
    public static function rocket_exclude_js(array $excluded): array {
        $excluded[] = '/xabia-agent-core/.*/chatbox\.js';
        $excluded[] = '/xabia-agent-core/.*/xabia-interface\.js';
        return $excluded;
    }

    public static function ping_chatbox_mounted(): void {
        echo '<script>(function(){try{document.dispatchEvent(new CustomEvent("xabia:chatbox:mounted"));if(window.xabiaChatboxInitAll){window.xabiaChatboxInitAll();}}catch(e){}})();</script>';
    }

    public static function mark_chatbox_rendered(string $project_id): void {
        $project_id = sanitize_key($project_id);
        if ($project_id === '') {
            return;
        }
        if (!in_array($project_id, self::$rendered_chatbox, true)) {
            self::$rendered_chatbox[] = $project_id;
        }
    }

    /**
     * Detecta shortcodes de chat y lanzador en el post actual (incl. JSON de Elementor).
     */
    public static function prime_projects_for_request(): void {
        if (is_admin() || self::is_box_route()) {
            return;
        }

        $texts = [];
        $post = get_queried_object();
        if ($post instanceof WP_Post) {
            $texts[] = (string) $post->post_content;
            $elementor = get_post_meta($post->ID, '_elementor_data', true);
            if (is_string($elementor) && $elementor !== '') {
                $texts[] = $elementor;
            }
        }

        $texts = apply_filters('xabia_interface_prime_content_sources', $texts, $post ?? null);
        foreach ($texts as $text) {
            self::prime_projects_from_text($text);
        }

        self::prime_autoload_projects();
    }

    /**
     * Agentes con «mostrar sin shortcode» (sustituye al avatar de Elementor en la página).
     */
    private static function prime_autoload_projects(): void {
        $projects = get_option('xabia_projects_config', []);
        if (!is_array($projects)) {
            return;
        }

        foreach (array_keys($projects) as $project_id) {
            $project_id = sanitize_key((string) $project_id);
            if ($project_id === '' || self::is_project_paused($project_id)) {
                continue;
            }
            if (!self::project_autoloads_without_shortcode($project_id)) {
                continue;
            }
            if (!self::should_render_for_project($project_id)) {
                continue;
            }
            if (!in_array($project_id, self::$primed_projects, true)) {
                self::$primed_projects[] = $project_id;
            }
            self::register_page_project($project_id);
        }
    }

    /**
     * @param string $text
     */
    private static function prime_projects_from_text(string $text): void {
        if ($text === '') {
            return;
        }
        if (preg_match_all('/\[xabia_search_bar\b([^\]]*)\]/i', $text, $search_matches, PREG_SET_ORDER)) {
            $search_file = XABIA_PATH . 'frontend/widgets/search-bar.php';
            if (is_readable($search_file)) {
                require_once $search_file;
            }
            foreach ($search_matches as $search_match) {
                $search_atts = shortcode_parse_atts($search_match[1] ?? '');
                if (!is_array($search_atts)) {
                    $search_atts = [];
                }
                $search_id = function_exists('xabia_search_bar_project_id_from_atts')
                    ? xabia_search_bar_project_id_from_atts($search_atts)
                    : 'default';
                self::note_search_bar_project($search_id);
            }
        }

        if (!preg_match_all('/\[xabia_(?:agent|chat|launcher|avatar|chat_button)\b([^\]]*)\]/i', $text, $matches, PREG_SET_ORDER)) {
            return;
        }
        foreach ($matches as $match) {
            $atts = shortcode_parse_atts($match[1] ?? '') ?: [];
            $id = isset($atts['id']) ? sanitize_key((string) $atts['id']) : 'default';
            if ($id === '' || self::is_project_paused($id)) {
                continue;
            }
            if (!in_array($id, self::$primed_projects, true)) {
                self::$primed_projects[] = $id;
            }
            $tag = strtolower((string) ($match[0] ?? ''));
            if (str_contains($tag, 'xabia_launcher') || str_contains($tag, 'xabia_avatar') || str_contains($tag, 'xabia_chat_button')) {
                self::register_launcher_project($id);
            }
        }
    }

    public static function maybe_enqueue_assets_early(): void {
        self::maybe_disable_rocket_css_lazyload();
        if (self::$page_projects !== [] || self::$launcher_projects !== []) {
            self::enqueue_assets();
        }
    }

    /**
     * Encola chatbox.js antes del footer (autoload sin shortcode en contenido).
     */
    public static function enqueue_chatbox_scripts_early(): void {
        if (is_admin() || self::is_box_route()) {
            return;
        }
        $file = XABIA_PATH . 'frontend/widgets/chatbox.php';
        if (!is_readable($file)) {
            return;
        }
        require_once $file;
        if (!function_exists('xabia_enqueue_chatbox_assets_for_project')) {
            return;
        }
        $ids = array_values(array_unique(array_merge(self::$primed_projects, self::$page_projects, self::$launcher_projects)));
        foreach ($ids as $project_id) {
            if (self::is_project_paused($project_id)) {
                continue;
            }
            $projects = get_option('xabia_projects_config', []);
            $pdata = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];
            xabia_enqueue_chatbox_assets_for_project($project_id, $pdata);
        }
    }

    public static function print_chatbox_scripts_fallback(): void {
        if (wp_style_is('xabia-frontend-styles', 'enqueued') && !wp_style_is('xabia-frontend-styles', 'done')) {
            wp_print_styles(['xabia-interface', 'xabia-frontend-styles']);
        }
        if (!wp_script_is('xabia-chatbox', 'enqueued') || wp_script_is('xabia-chatbox', 'done')) {
            return;
        }
        wp_print_scripts(['jquery', 'xabia-chatbox']);
    }

    public static function is_box_route(): bool {
        return class_exists('Xabia_Box_Route', false)
            && (int) get_query_var(Xabia_Box_Route::QUERY_VAR) === 1;
    }

    public static function is_project_paused(string $project_id): bool {
        $project_id = sanitize_key($project_id);
        if ($project_id === '') {
            return false;
        }
        $projects = get_option('xabia_projects_config', []);
        if (!isset($projects[$project_id]) || !is_array($projects[$project_id])) {
            return false;
        }
        return (int) ($projects[$project_id]['paused'] ?? 0) === 1;
    }

    /**
     * Invalida cachés de página habituales tras pausar/activar (el HTML sin shortcode suele quedar cacheado).
     */
    public static function purge_frontend_caches(): void {
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        if (function_exists('litespeed_purge_all')) {
            litespeed_purge_all();
        }
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }
        do_action('xabia_purge_frontend_caches');
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array {
        return [
            self::OPT_TRIGGER_TYPE              => 'native_avatar',
            self::OPT_CUSTOM_TRIGGER            => '',
            self::OPT_AVATAR_COLORS             => [
                'bg'     => '#99ccff',
                'shadow' => '#ffffff',
                'dots'   => '#ff9966',
            ],
            self::OPT_TRIGGER_POSITION          => 'bottom_right',
            self::OPT_TRIGGER_MARGIN_X          => '24px',
            self::OPT_TRIGGER_MARGIN_Y          => '24px',
            self::OPT_PANEL_LAYOUT              => 'full_screen',
            self::OPT_EXCLUDE_POST_TYPES        => [],
            self::OPT_EXCLUDE_IDS               => [],
            self::OPT_EXCLUDE_WOO_CART_CHECKOUT => 1,
            self::OPT_AUTOLOAD_WITHOUT_SHORTCODE => 1,
            self::OPT_INCLUDE_PAGE_IDS           => [],
            self::OPT_MOBILE_PRESET              => 'compact',
            self::OPT_SPEAKING_AVATAR            => 1,
            self::OPT_PRESENTATION_MODE          => 'web_adaptive',
            self::OPT_COLOR_SCHEME               => 'light',
            self::OPT_LAUNCHER_LABEL             => '¿Cómo puedo ayudarte?',
        ];
    }

    public static function sanitize_color_scheme(string $value): string {
        $value = sanitize_key($value);
        return in_array($value, ['light', 'dark', 'auto'], true) ? $value : 'light';
    }

    /**
     * Atributos de tema. Claro no pone data-theme; Oscuro pone data-theme="dark";
     * Automático lo resuelve un script con prefers-color-scheme.
     */
    public static function color_scheme_attributes(string $scheme): string {
        $scheme = self::sanitize_color_scheme($scheme);
        $html = ' data-color-scheme="' . esc_attr($scheme) . '"';
        if ($scheme === 'dark') {
            $html .= ' data-theme="dark"';
        }
        return $html;
    }

    public static function has_rendered_chatbox(string $project_id): bool {
        return in_array(sanitize_key($project_id), self::$rendered_chatbox, true);
    }

    public static function park_next_chatbox(): void {
        self::$park_next_chatbox = true;
    }

    public static function consume_park_next_chatbox(): bool {
        $park = self::$park_next_chatbox;
        self::$park_next_chatbox = false;
        return $park;
    }

    /**
     * @return list<string>
     */
    public static function search_bar_project_ids(): array {
        return self::$search_bar_projects;
    }

    public static function note_search_bar_project(string $project_id): void {
        $project_id = sanitize_key($project_id);
        if ($project_id === '' || self::is_project_paused($project_id)) {
            return;
        }
        if (!in_array($project_id, self::$search_bar_projects, true)) {
            self::$search_bar_projects[] = $project_id;
        }
        self::enqueue_chatbox_assets_for_registered($project_id);
    }

    /** @return list<string> */
    public static function panel_layout_keys(): array {
        return ['drawer', 'right_float', 'left_float', 'centered_modal', 'full_screen'];
    }

    public static function sanitize_panel_layout(string $value): string {
        unset($value);
        return 'full_screen';
    }

    /**
     * @return list<string>
     */
    public static function launcher_position_keys(): array {
        return ['bottom_right', 'bottom_left', 'bottom_center', 'shortcode'];
    }

    public static function sanitize_launcher_position(string $value): string {
        $value = sanitize_key($value);
        if ($value === 'custom') {
            $value = 'bottom_right';
        }
        return in_array($value, self::launcher_position_keys(), true) ? $value : 'bottom_right';
    }

    public static function project_is_totem(string $project_id): bool {
        $project_id = sanitize_key($project_id);
        $projects = get_option('xabia_projects_config', []);
        if ($project_id !== '' && isset($projects[$project_id]) && is_array($projects[$project_id])) {
            $totem = $projects[$project_id]['totem'] ?? [];
            if (is_array($totem) && (!empty($totem['enabled']) || !empty($totem['totem_mode']) || !empty($totem['is_totem']))) {
                return true;
            }
            if (!empty($projects[$project_id]['is_totem']) || !empty($projects[$project_id]['totem_mode'])) {
                return true;
            }
            $interface = $projects[$project_id]['interface'] ?? [];
            if (is_array($interface)) {
                $mode = self::sanitize_presentation_mode((string) ($interface[self::OPT_PRESENTATION_MODE] ?? ''));
                if (self::is_kiosk_presentation_mode($mode)) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function sanitize_mobile_preset(string $value): string {
        $value = sanitize_key($value);
        if (!in_array($value, ['compact', 'ultra_compact'], true)) {
            return 'compact';
        }
        return $value;
    }

    public static function mobile_preset_class(string $preset): string {
        return 'xabia-mobile-preset-' . str_replace('_', '-', self::sanitize_mobile_preset($preset));
    }

    public static function project_autoloads_without_shortcode(string $project_id): bool {
        return self::project_uses_web_launcher($project_id);
    }

    /**
     * Migra opciones globales legacy (v1.0.27) al agente si aún no tiene interfaz propia.
     *
     * @param array<string, mixed> $interface
     * @return array<string, mixed>
     */
    private static function merge_legacy_global_options(array $interface): array {
        if ($interface !== []) {
            return $interface;
        }
        $legacy = [
            self::OPT_TRIGGER_TYPE       => get_option('xabia_trigger_type', ''),
            self::OPT_CUSTOM_TRIGGER     => get_option('xabia_custom_trigger_url', ''),
            self::OPT_AVATAR_COLORS      => get_option('xabia_avatar_colors', []),
            self::OPT_TRIGGER_POSITION   => get_option('xabia_trigger_position', ''),
            self::OPT_TRIGGER_MARGIN_X   => get_option('xabia_trigger_margin_x', ''),
            self::OPT_TRIGGER_MARGIN_Y   => get_option('xabia_trigger_margin_y', ''),
            self::OPT_PANEL_LAYOUT       => get_option('xabia_panel_layout', ''),
            self::OPT_EXCLUDE_POST_TYPES => get_option('xabia_exclude_post_types', []),
            self::OPT_EXCLUDE_IDS        => get_option('xabia_exclude_ids', []),
            self::OPT_EXCLUDE_WOO_CART_CHECKOUT => get_option('xabia_exclude_woo_cart_checkout', null),
        ];
        $has_legacy = false;
        foreach ($legacy as $v) {
            if ($v !== '' && $v !== [] && $v !== null) {
                $has_legacy = true;
                break;
            }
        }
        if (!$has_legacy) {
            return [];
        }
        $out = [];
        if ($legacy[self::OPT_TRIGGER_TYPE] !== '') {
            $out[self::OPT_TRIGGER_TYPE] = $legacy[self::OPT_TRIGGER_TYPE];
        }
        if ($legacy[self::OPT_CUSTOM_TRIGGER] !== '') {
            $out[self::OPT_CUSTOM_TRIGGER] = $legacy[self::OPT_CUSTOM_TRIGGER];
        }
        if (is_array($legacy[self::OPT_AVATAR_COLORS]) && $legacy[self::OPT_AVATAR_COLORS] !== []) {
            $out[self::OPT_AVATAR_COLORS] = $legacy[self::OPT_AVATAR_COLORS];
        }
        if ($legacy[self::OPT_TRIGGER_POSITION] !== '') {
            $out[self::OPT_TRIGGER_POSITION] = $legacy[self::OPT_TRIGGER_POSITION];
        }
        if ($legacy[self::OPT_TRIGGER_MARGIN_X] !== '') {
            $out[self::OPT_TRIGGER_MARGIN_X] = $legacy[self::OPT_TRIGGER_MARGIN_X];
        }
        if ($legacy[self::OPT_TRIGGER_MARGIN_Y] !== '') {
            $out[self::OPT_TRIGGER_MARGIN_Y] = $legacy[self::OPT_TRIGGER_MARGIN_Y];
        }
        if ($legacy[self::OPT_PANEL_LAYOUT] !== '') {
            $out[self::OPT_PANEL_LAYOUT] = $legacy[self::OPT_PANEL_LAYOUT];
        }
        if (is_array($legacy[self::OPT_EXCLUDE_POST_TYPES]) && $legacy[self::OPT_EXCLUDE_POST_TYPES] !== []) {
            $out[self::OPT_EXCLUDE_POST_TYPES] = $legacy[self::OPT_EXCLUDE_POST_TYPES];
        }
        if (is_array($legacy[self::OPT_EXCLUDE_IDS]) && $legacy[self::OPT_EXCLUDE_IDS] !== []) {
            $out[self::OPT_EXCLUDE_IDS] = $legacy[self::OPT_EXCLUDE_IDS];
        }
        if ($legacy[self::OPT_EXCLUDE_WOO_CART_CHECKOUT] !== null) {
            $out[self::OPT_EXCLUDE_WOO_CART_CHECKOUT] = (int) $legacy[self::OPT_EXCLUDE_WOO_CART_CHECKOUT];
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_project_settings(string $project_id): array {
        $defaults = self::defaults();
        $project_id = sanitize_key($project_id);
        $projects = get_option('xabia_projects_config', []);
        $raw = [];
        if ($project_id !== '' && isset($projects[$project_id]['interface']) && is_array($projects[$project_id]['interface'])) {
            $raw = $projects[$project_id]['interface'];
        }
        $raw = self::merge_legacy_global_options($raw);

        $colors = isset($raw[self::OPT_AVATAR_COLORS]) && is_array($raw[self::OPT_AVATAR_COLORS])
            ? $raw[self::OPT_AVATAR_COLORS]
            : [];
        $colors = wp_parse_args($colors, $defaults[self::OPT_AVATAR_COLORS]);

        $trigger_type = sanitize_key((string) ($raw[self::OPT_TRIGGER_TYPE] ?? $defaults[self::OPT_TRIGGER_TYPE]));
        if (!in_array($trigger_type, ['native_avatar', 'custom_image'], true)) {
            $trigger_type = 'native_avatar';
        }

        $position = self::sanitize_launcher_position((string) ($raw[self::OPT_TRIGGER_POSITION] ?? $defaults[self::OPT_TRIGGER_POSITION]));

        $layout = 'full_screen';

        $excluded_types = [];
        if (!empty($raw[self::OPT_EXCLUDE_POST_TYPES]) && is_array($raw[self::OPT_EXCLUDE_POST_TYPES])) {
            foreach ($raw[self::OPT_EXCLUDE_POST_TYPES] as $pt) {
                $pt = sanitize_key((string) $pt);
                if ($pt !== '' && post_type_exists($pt)) {
                    $excluded_types[] = $pt;
                }
            }
        }

        $excluded_ids = [];
        if (!empty($raw[self::OPT_EXCLUDE_IDS])) {
            if (is_string($raw[self::OPT_EXCLUDE_IDS])) {
                $excluded_ids = self::parse_exclude_ids($raw[self::OPT_EXCLUDE_IDS]);
            } elseif (is_array($raw[self::OPT_EXCLUDE_IDS])) {
                foreach ($raw[self::OPT_EXCLUDE_IDS] as $id) {
                    $id = absint($id);
                    if ($id > 0) {
                        $excluded_ids[] = $id;
                    }
                }
                $excluded_ids = array_values(array_unique($excluded_ids));
            }
        }

        $included_ids = [];
        if (!empty($raw[self::OPT_INCLUDE_PAGE_IDS])) {
            if (is_string($raw[self::OPT_INCLUDE_PAGE_IDS])) {
                $included_ids = self::parse_exclude_ids($raw[self::OPT_INCLUDE_PAGE_IDS]);
            } elseif (is_array($raw[self::OPT_INCLUDE_PAGE_IDS])) {
                foreach ($raw[self::OPT_INCLUDE_PAGE_IDS] as $id) {
                    $id = absint($id);
                    if ($id > 0) {
                        $included_ids[] = $id;
                    }
                }
                $included_ids = array_values(array_unique($included_ids));
            }
        }

        $exclude_woo = array_key_exists(self::OPT_EXCLUDE_WOO_CART_CHECKOUT, $raw)
            ? !empty($raw[self::OPT_EXCLUDE_WOO_CART_CHECKOUT])
            : (bool) $defaults[self::OPT_EXCLUDE_WOO_CART_CHECKOUT];

        $autoload = array_key_exists(self::OPT_AUTOLOAD_WITHOUT_SHORTCODE, $raw)
            ? !empty($raw[self::OPT_AUTOLOAD_WITHOUT_SHORTCODE])
            : (bool) $defaults[self::OPT_AUTOLOAD_WITHOUT_SHORTCODE];

        $mobile_preset = self::sanitize_mobile_preset(
            (string) ($raw[self::OPT_MOBILE_PRESET] ?? $defaults[self::OPT_MOBILE_PRESET])
        );

        $speaking_avatar = array_key_exists(self::OPT_SPEAKING_AVATAR, $raw)
            ? (!empty($raw[self::OPT_SPEAKING_AVATAR]) ? 1 : 0)
            : (int) $defaults[self::OPT_SPEAKING_AVATAR];

        $presentation_mode = self::sanitize_presentation_mode(
            (string) ($raw[self::OPT_PRESENTATION_MODE] ?? $defaults[self::OPT_PRESENTATION_MODE])
        );
        $color_scheme = self::sanitize_color_scheme(
            (string) ($raw[self::OPT_COLOR_SCHEME] ?? $defaults[self::OPT_COLOR_SCHEME])
        );
        $totem_on = self::is_kiosk_presentation_mode($presentation_mode) || self::project_is_totem($project_id);
        if ($totem_on) {
            $speaking_avatar = 1;
            if (self::is_kiosk_presentation_mode($presentation_mode)) {
                $autoload = false;
            }
        } else {
            $speaking_avatar = 0;
        }
        if ($position === 'shortcode') {
            $autoload = false;
        }

        return [
            self::OPT_TRIGGER_TYPE              => $trigger_type,
            self::OPT_CUSTOM_TRIGGER            => self::sanitize_custom_trigger_url((string) ($raw[self::OPT_CUSTOM_TRIGGER] ?? '')),
            self::OPT_AVATAR_COLORS             => [
                'bg'     => self::sanitize_color($colors['bg'] ?? $defaults[self::OPT_AVATAR_COLORS]['bg']),
                'shadow' => self::sanitize_color($colors['shadow'] ?? $defaults[self::OPT_AVATAR_COLORS]['shadow']),
                'dots'   => self::sanitize_color($colors['dots'] ?? $defaults[self::OPT_AVATAR_COLORS]['dots']),
            ],
            self::OPT_TRIGGER_POSITION          => $position,
            self::OPT_TRIGGER_MARGIN_X          => self::sanitize_css_length(
                (string) ($raw[self::OPT_TRIGGER_MARGIN_X] ?? $defaults[self::OPT_TRIGGER_MARGIN_X]),
                $defaults[self::OPT_TRIGGER_MARGIN_X]
            ),
            self::OPT_TRIGGER_MARGIN_Y          => self::sanitize_css_length(
                (string) ($raw[self::OPT_TRIGGER_MARGIN_Y] ?? $defaults[self::OPT_TRIGGER_MARGIN_Y]),
                $defaults[self::OPT_TRIGGER_MARGIN_Y]
            ),
            self::OPT_PANEL_LAYOUT              => $layout,
            self::OPT_EXCLUDE_POST_TYPES        => array_values(array_unique($excluded_types)),
            self::OPT_EXCLUDE_IDS               => $excluded_ids,
            self::OPT_INCLUDE_PAGE_IDS          => $included_ids,
            self::OPT_EXCLUDE_WOO_CART_CHECKOUT => $exclude_woo,
            self::OPT_AUTOLOAD_WITHOUT_SHORTCODE => $autoload,
            self::OPT_MOBILE_PRESET              => $mobile_preset,
            self::OPT_SPEAKING_AVATAR            => $speaking_avatar,
            self::OPT_PRESENTATION_MODE          => $presentation_mode,
            self::OPT_COLOR_SCHEME               => $color_scheme,
            self::OPT_LAUNCHER_LABEL             => self::sanitize_launcher_label(
                (string) ($raw[self::OPT_LAUNCHER_LABEL] ?? $defaults[self::OPT_LAUNCHER_LABEL])
            ),
        ];
    }

    public static function sanitize_launcher_label(string $raw): string {
        $raw = trim(wp_strip_all_tags($raw));
        if (function_exists('mb_substr')) {
            $raw = mb_substr($raw, 0, 80);
        } else {
            $raw = substr($raw, 0, 80);
        }
        $raw = trim($raw);
        if ($raw === '') {
            return '¿Cómo puedo ayudarte?';
        }
        return $raw;
    }

    /**
     * @return list<int>
     */
    public static function parse_exclude_ids(string $raw): array {
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return [];
        }
        $out = [];
        foreach ($parts as $part) {
            $id = absint($part);
            if ($id > 0) {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * ID del contenido que se está viendo (página, entrada, portada estática).
     */
    public static function get_current_view_post_id(): int {
        if (is_singular()) {
            return (int) get_queried_object_id();
        }
        if (is_front_page()) {
            $id = (int) get_queried_object_id();
            if ($id > 0) {
                return $id;
            }
            $page_on_front = (int) get_option('page_on_front');
            if ($page_on_front > 0) {
                return $page_on_front;
            }
        }
        return 0;
    }

    /**
     * Código del idioma original (WPML o Polylang). Vacío si el sitio no es multilingüe.
     */
    private static function original_content_language(): string {
        $wpml = apply_filters('wpml_default_language', null);
        if (is_string($wpml) && $wpml !== '') {
            return $wpml;
        }
        if (function_exists('pll_default_language')) {
            $pll = pll_default_language();
            if (is_string($pll) && $pll !== '') {
                return $pll;
            }
        }
        return '';
    }

    /**
     * ID de la página o entrada en el idioma original. Las traducciones usan ese mismo ID.
     */
    private static function original_language_post_id(int $post_id): int {
        if ($post_id < 1) {
            return 0;
        }
        $post_type = get_post_type($post_id);
        if (!is_string($post_type) || $post_type === '') {
            return $post_id;
        }
        $wpml = apply_filters('wpml_default_language', null);
        if (is_string($wpml) && $wpml !== '') {
            $mapped = apply_filters('wpml_object_id', $post_id, $post_type, true, $wpml);
            if (is_numeric($mapped) && (int) $mapped > 0) {
                return (int) $mapped;
            }
            return $post_id;
        }
        if (function_exists('pll_get_post') && function_exists('pll_default_language')) {
            $pll = pll_default_language();
            if (is_string($pll) && $pll !== '') {
                $mapped = pll_get_post($post_id, $pll);
                if (is_numeric($mapped) && (int) $mapped > 0) {
                    return (int) $mapped;
                }
            }
        }
        return $post_id;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function original_language_post_ids(array $ids): array {
        $out = [];
        foreach ($ids as $id) {
            $mapped = self::original_language_post_id(absint($id));
            if ($mapped > 0) {
                $out[] = $mapped;
            }
        }
        return array_values(array_unique($out));
    }

    public static function should_render_for_project(string $project_id): bool {
        if (self::is_box_route() || self::is_project_paused($project_id)) {
            return false;
        }

        if (apply_filters('xabia_interface_force_hide', false, $project_id)) {
            return false;
        }

        $settings = self::get_project_settings($project_id);

        $include_ids = self::original_language_post_ids($settings[self::OPT_INCLUDE_PAGE_IDS]);
        if ($include_ids !== []) {
            $view_id = self::original_language_post_id(self::get_current_view_post_id());
            if ($view_id < 1 || !in_array($view_id, $include_ids, true)) {
                return false;
            }
            return (bool) apply_filters('xabia_interface_should_render', true, $project_id);
        }

        if (!empty($settings[self::OPT_EXCLUDE_WOO_CART_CHECKOUT])
            && function_exists('is_cart') && function_exists('is_checkout')
            && (is_cart() || is_checkout())) {
            return false;
        }

        if (is_singular()) {
            $post_type = get_post_type();
            if (is_string($post_type) && $post_type !== ''
                && in_array($post_type, $settings[self::OPT_EXCLUDE_POST_TYPES], true)) {
                return false;
            }
            $post_id = self::original_language_post_id((int) get_the_ID());
            $excluded_ids = self::original_language_post_ids($settings[self::OPT_EXCLUDE_IDS]);
            if ($post_id > 0 && in_array($post_id, $excluded_ids, true)) {
                return false;
            }
        }

        return (bool) apply_filters('xabia_interface_should_render', true, $project_id);
    }

    public static function register_page_project(string $project_id): void {
        $project_id = sanitize_key($project_id);
        if ($project_id === '' || self::is_project_paused($project_id)) {
            return;
        }
        if (!in_array($project_id, self::$page_projects, true)) {
            self::$page_projects[] = $project_id;
        }
        self::enqueue_assets();
    }

    /**
     * Registra un agente cuyo botón se incrusta vía shortcode [xabia_launcher] / [xabia_avatar].
     * No aplica reglas de include/exclude del avatar flotante.
     */
    public static function register_launcher_project(string $project_id): void {
        $project_id = sanitize_key($project_id);
        if ($project_id === '' || self::is_project_paused($project_id)) {
            return;
        }
        if (!in_array($project_id, self::$launcher_projects, true)) {
            self::$launcher_projects[] = $project_id;
        }
        if (!in_array($project_id, self::$primed_projects, true)) {
            self::$primed_projects[] = $project_id;
        }
        self::enqueue_assets();
        self::enqueue_chatbox_assets_for_registered($project_id);
    }

    /**
     * Asegura styles.css / chatbox.js aunque el shortcode se ejecute después de wp_enqueue_scripts.
     */
    private static function enqueue_chatbox_assets_for_registered(string $project_id): void {
        $file = XABIA_PATH . 'frontend/widgets/chatbox.php';
        if (!is_readable($file)) {
            return;
        }
        require_once $file;
        if (!function_exists('xabia_enqueue_chatbox_assets_for_project')) {
            return;
        }
        $projects = get_option('xabia_projects_config', []);
        $pdata = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];
        xabia_enqueue_chatbox_assets_for_project($project_id, $pdata);
    }

    /**
     * Shortcode: botón avatar (inline) que abre el panel del agente.
     *
     * Ejemplos:
     *   [xabia_launcher id="conoce-xabia" size="lg"]
     *   [xabia_avatar id="conoce-xabia" size="42%"]
     *   [xabia_launcher id="conoce-xabia" size="280" size_mobile="120" size_tablet="180"]
     *   [xabia_launcher id="conoce-xabia" align="center"]  (center|left|right; por defecto center)
     *
     * size / size_mobile / size_tablet / size_desktop:
     *   sm|md|lg|xl  ·  N%  ·  N (px)
     *
     * @param array<string, mixed>|string $atts
     */
    public static function shortcode_launcher($atts): string {
        $atts = shortcode_atts([
            'id'           => 'default',
            'class'        => '',
            'align'        => 'center',
            'size'         => 'md',
            'size_mobile'  => '',
            'size_tablet'  => '',
            'size_desktop' => '',
            'mobile'       => '',
            'tablet'       => '',
            'desktop'      => '',
        ], is_array($atts) ? $atts : [], 'xabia_launcher');

        $project_id = sanitize_key((string) $atts['id']);
        if ($project_id === '' || self::is_project_paused($project_id)) {
            return '';
        }

        $projects = get_option('xabia_projects_config', []);
        if (!isset($projects[$project_id]) || !is_array($projects[$project_id])) {
            return '';
        }

        self::register_launcher_project($project_id);

        $extra_class = sanitize_html_class((string) $atts['class']);
        $align = sanitize_key((string) $atts['align']);
        if (!in_array($align, ['left', 'center', 'right'], true)) {
            $align = 'center';
        }
        $desktop_raw = trim((string) ($atts['size_desktop'] !== '' ? $atts['size_desktop'] : ($atts['desktop'] !== '' ? $atts['desktop'] : $atts['size'])));
        $tablet_raw  = trim((string) ($atts['size_tablet'] !== '' ? $atts['size_tablet'] : $atts['tablet']));
        $mobile_raw  = trim((string) ($atts['size_mobile'] !== '' ? $atts['size_mobile'] : $atts['mobile']));

        $desktop = self::parse_launcher_size_token($desktop_raw, 'md');
        $tablet  = $tablet_raw !== '' ? self::parse_launcher_size_token($tablet_raw, '') : null;
        $mobile  = $mobile_raw !== '' ? self::parse_launcher_size_token($mobile_raw, '') : null;

        ob_start();
        echo '<div class="xabia-launcher-host xabia-launcher-host--' . esc_attr($align) . '">';
        self::render_trigger_markup($project_id, [
            'placement'   => 'inline',
            'extra_class' => $extra_class,
            'size'        => $desktop['preset'],
            'size_value'  => $desktop['css'] !== '' ? $desktop['css'] : self::launcher_preset_css($desktop['preset']),
            'size_unit'   => $desktop['unit'] !== '' ? $desktop['unit'] : 'preset',
            'size_tablet' => is_array($tablet)
                ? ($tablet['css'] !== '' ? $tablet['css'] : self::launcher_preset_css($tablet['preset']))
                : '',
            'size_mobile' => is_array($mobile)
                ? ($mobile['css'] !== '' ? $mobile['css'] : self::launcher_preset_css($mobile['preset']))
                : '',
            'size_tablet_unit' => is_array($tablet) ? (string) $tablet['unit'] : '',
            'size_mobile_unit' => is_array($mobile) ? (string) $mobile['unit'] : '',
            'size_tablet_preset' => '',
            'size_mobile_preset' => '',
        ]);
        echo '</div>';
        return (string) ob_get_clean();
    }

    /**
     * Valor CSS concreto para presets (evita depender solo de clases en móvil).
     */
    private static function launcher_preset_css(string $preset): string {
        $map = [
            'sm' => 'clamp(64px, 18vw, 96px)',
            'md' => 'clamp(110px, 28vw, 180px)',
            'lg' => 'clamp(160px, 42vw, 300px)',
            'xl' => 'clamp(200px, 52vw, 400px)',
        ];
        $preset = sanitize_key($preset);
        return $map[$preset] ?? $map['md'];
    }

    /**
     * @return array{preset:string,css:string,unit:string}
     */
    private static function parse_launcher_size_token(string $raw, string $fallback_preset): array {
        $raw = trim($raw);
        if ($raw === '') {
            return [
                'preset' => $fallback_preset,
                'css'    => '',
                'unit'   => $fallback_preset !== '' ? 'preset' : '',
            ];
        }
        if (preg_match('/^(\d{1,3}(?:\.\d+)?)%$/', $raw, $m)) {
            $pct = max(8, min(100, (float) $m[1]));
            $css = rtrim(rtrim(sprintf('%.2F', $pct), '0'), '.') . '%';
            return [
                'preset' => '',
                'css'    => $css,
                'unit'   => 'pct',
            ];
        }
        if (preg_match('/^(\d{2,4})(?:px)?$/i', $raw, $m)) {
            $px = max(48, min(560, (int) $m[1]));
            return [
                'preset' => '',
                'css'    => $px . 'px',
                'unit'   => 'px',
            ];
        }
        $preset = sanitize_key($raw);
        if (!in_array($preset, ['sm', 'md', 'lg', 'xl'], true)) {
            $preset = $fallback_preset !== '' ? $fallback_preset : 'md';
        }
        return [
            'preset' => $preset,
            'css'    => '',
            'unit'   => 'preset',
        ];
    }

    /**
     * Datos JS antes de wp_print_footer_scripts (prioridad 5 en wp_footer).
     */
    public static function localize_footer_script(): void {
        if (!self::$assets_enqueued) {
            return;
        }

        $projects_js = [];

        foreach (self::$page_projects as $project_id) {
            if (!self::should_render_for_project($project_id)) {
                continue;
            }
            $settings = self::get_project_settings($project_id);
            $projects_js[$project_id] = [
                'triggerType'     => $settings[self::OPT_TRIGGER_TYPE],
                'panelLayout'     => $settings[self::OPT_PANEL_LAYOUT],
                'triggerPosition' => $settings[self::OPT_TRIGGER_POSITION],
                'speakingAvatar'  => !empty($settings[self::OPT_SPEAKING_AVATAR]) ? 1 : 0,
            ];
        }

        foreach (self::$launcher_projects as $project_id) {
            if (isset($projects_js[$project_id])) {
                continue;
            }
            $settings = self::get_project_settings($project_id);
            $projects_js[$project_id] = [
                'triggerType'     => $settings[self::OPT_TRIGGER_TYPE],
                'panelLayout'     => $settings[self::OPT_PANEL_LAYOUT],
                'triggerPosition' => $settings[self::OPT_TRIGGER_POSITION],
                'speakingAvatar'  => !empty($settings[self::OPT_SPEAKING_AVATAR]) ? 1 : 0,
            ];
        }

        if ($projects_js === []) {
            return;
        }

        wp_localize_script('xabia-interface', 'XabiaInterface', [
            'projects' => $projects_js,
            'gsapUrl'  => 'https://cdn.jsdelivr.net/npm/gsap@3/dist/gsap.min.js',
            'i18n'     => [
                'openChat'   => __('Abrir chat', 'xabia-intelligence'),
                'closeChat'  => __('Cerrar chat', 'xabia-intelligence'),
                'avatarHint' => __('¿Te puedo ayudar?', 'xabia-intelligence'),
            ],
        ]);
    }

    public static function sanitize_color(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '#006cff';
        }
        $hex = sanitize_hex_color($value);
        return $hex !== null ? $hex : '#006cff';
    }

    public static function sanitize_css_length(string $value, string $fallback): string {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }
        if (preg_match('/^\d+(\.\d+)?(px|vh|vw|rem|%)$/i', $value)) {
            return strtolower($value);
        }
        if (preg_match('/^\d+(\.\d+)?$/', $value)) {
            return $value . 'px';
        }
        return $fallback;
    }

    /**
     * URL de imagen de avatar (PNG/GIF/JPG/WebP u otra URL de medios WP).
     */
    public static function sanitize_custom_trigger_url(string $raw): string {
        $url = esc_url_raw(trim($raw));
        if ($url === '') {
            return '';
        }
        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '');
        if ($path !== '' && !preg_match('/\.(png|jpe?g|gif|webp|svg)(\?.*)?$/i', $path)) {
            // Permitir URLs de medios WP sin extensión clara en el path (cdn / resized).
            if (!preg_match('#/wp-content/uploads/#i', $url) && !preg_match('#^https?://#i', $url)) {
                return '';
            }
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function build_config_from_post(array $post): array {
        $defaults = self::defaults();

        $trigger_type = sanitize_key((string) ($post['xabia_trigger_type'] ?? $defaults[self::OPT_TRIGGER_TYPE]));
        if (!in_array($trigger_type, ['native_avatar', 'custom_image'], true)) {
            $trigger_type = 'native_avatar';
        }

        $position = self::sanitize_launcher_position((string) ($post['xabia_trigger_position'] ?? $defaults[self::OPT_TRIGGER_POSITION]));

        $layout = 'full_screen';

        $excluded_types = [];
        if (!empty($post['xabia_exclude_post_types']) && is_array($post['xabia_exclude_post_types'])) {
            foreach ($post['xabia_exclude_post_types'] as $pt) {
                $pt = sanitize_key((string) $pt);
                if ($pt !== '' && post_type_exists($pt)) {
                    $excluded_types[] = $pt;
                }
            }
        }

        $presentation_mode = self::sanitize_presentation_mode(
            (string) ($post['xabia_presentation_mode'] ?? $defaults[self::OPT_PRESENTATION_MODE])
        );
        $autoload = $position !== 'shortcode' ? 1 : 0;
        $speaking_avatar = 0;
        $pid = sanitize_key((string) ($post['project_id'] ?? ''));
        if (self::is_kiosk_presentation_mode($presentation_mode) || ($pid !== '' && self::project_is_totem($pid))) {
            $speaking_avatar = 1;
        }
        if (self::is_kiosk_presentation_mode($presentation_mode)) {
            $autoload = 0;
        }

        $kept_colors = $defaults[self::OPT_AVATAR_COLORS];
        $kept_trigger = $trigger_type;
        $kept_custom = (string) ($post['xabia_custom_trigger_url'] ?? '');
        $pid_keep = sanitize_key((string) ($post['project_id'] ?? ''));
        if ($pid_keep !== '' && !array_key_exists('xabia_avatar_color_bg', $post)) {
            $projects_keep = get_option('xabia_projects_config', []);
            $prev = (isset($projects_keep[$pid_keep]['interface']) && is_array($projects_keep[$pid_keep]['interface']))
                ? $projects_keep[$pid_keep]['interface']
                : [];
            if (isset($prev[self::OPT_AVATAR_COLORS]) && is_array($prev[self::OPT_AVATAR_COLORS])) {
                $kept_colors = wp_parse_args($prev[self::OPT_AVATAR_COLORS], $kept_colors);
            }
            if (!empty($prev[self::OPT_TRIGGER_TYPE])) {
                $kept_trigger = sanitize_key((string) $prev[self::OPT_TRIGGER_TYPE]);
            }
            if (!array_key_exists('xabia_custom_trigger_url', $post)) {
                $kept_custom = (string) ($prev[self::OPT_CUSTOM_TRIGGER] ?? '');
            }
        } else {
            $kept_colors = [
                'bg'     => self::sanitize_color((string) ($post['xabia_avatar_color_bg'] ?? '')),
                'shadow' => self::sanitize_color((string) ($post['xabia_avatar_color_shadow'] ?? '')),
                'dots'   => self::sanitize_color((string) ($post['xabia_avatar_color_dots'] ?? '')),
            ];
        }

        return [
            self::OPT_TRIGGER_TYPE              => $kept_trigger,
            self::OPT_CUSTOM_TRIGGER            => self::sanitize_custom_trigger_url($kept_custom),
            self::OPT_AVATAR_COLORS             => [
                'bg'     => self::sanitize_color((string) ($kept_colors['bg'] ?? '')),
                'shadow' => self::sanitize_color((string) ($kept_colors['shadow'] ?? '')),
                'dots'   => self::sanitize_color((string) ($kept_colors['dots'] ?? '')),
            ],
            self::OPT_TRIGGER_POSITION          => $position,
            self::OPT_TRIGGER_MARGIN_X          => self::sanitize_css_length(
                (string) ($post['xabia_trigger_margin_x'] ?? ''),
                $defaults[self::OPT_TRIGGER_MARGIN_X]
            ),
            self::OPT_TRIGGER_MARGIN_Y          => self::sanitize_css_length(
                (string) ($post['xabia_trigger_margin_y'] ?? ''),
                $defaults[self::OPT_TRIGGER_MARGIN_Y]
            ),
            self::OPT_PANEL_LAYOUT              => $layout,
            self::OPT_EXCLUDE_POST_TYPES        => array_values(array_unique($excluded_types)),
            self::OPT_EXCLUDE_IDS               => self::parse_ids_from_post($post, 'xabia_exclude_page_ids', 'xabia_exclude_ids_manual'),
            self::OPT_INCLUDE_PAGE_IDS          => self::parse_ids_from_post($post, 'xabia_include_page_ids', 'xabia_include_page_ids_manual'),
            self::OPT_EXCLUDE_WOO_CART_CHECKOUT => !empty($post['xabia_exclude_woo_cart_checkout']) ? 1 : 0,
            self::OPT_AUTOLOAD_WITHOUT_SHORTCODE => $autoload,
            self::OPT_MOBILE_PRESET              => self::sanitize_mobile_preset(
                (string) ($post['xabia_mobile_preset'] ?? $defaults[self::OPT_MOBILE_PRESET])
            ),
            self::OPT_SPEAKING_AVATAR            => $speaking_avatar,
            self::OPT_PRESENTATION_MODE          => $presentation_mode,
            self::OPT_COLOR_SCHEME               => self::sanitize_color_scheme(
                (string) ($post['xabia_color_scheme'] ?? $defaults[self::OPT_COLOR_SCHEME])
            ),
            self::OPT_LAUNCHER_LABEL             => self::sanitize_launcher_label(
                array_key_exists('xabia_launcher_label', $post)
                    ? (string) $post['xabia_launcher_label']
                    : (string) ($defaults[self::OPT_LAUNCHER_LABEL])
            ),
        ];
    }

    public static function asset_url(string $relative): string {
        return trailingslashit(XABIA_URL) . ltrim($relative, '/');
    }

    /**
     * @param array<string, mixed> $post
     * @return list<int>
     */
    private static function parse_ids_from_post(array $post, string $array_key, string $text_key): array {
        $ids = [];
        if (!empty($post[$array_key]) && is_array($post[$array_key])) {
            foreach ($post[$array_key] as $id) {
                $id = absint($id);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        $text = trim((string) ($post[$text_key] ?? ''));
        if ($text !== '') {
            $ids = array_merge($ids, self::parse_exclude_ids($text));
        }
        return self::original_language_post_ids(array_values(array_unique($ids)));
    }

    /**
     * @param list<int> $selected_ids
     */
    private static function render_page_id_checklist(string $array_field, string $text_field, array $selected_ids, string $legend): void {
        $selected_ids = self::original_language_post_ids(array_map('absint', $selected_ids));
        $query = [
            'post_type'              => ['page', 'post'],
            'post_status'            => 'publish',
            'posts_per_page'         => 200,
            'orderby'                => 'title',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];
        $original_lang = self::original_content_language();
        $restore_lang = '';
        if ($original_lang !== '') {
            $query['lang'] = $original_lang;
            $current_lang = apply_filters('wpml_current_language', null);
            if (is_string($current_lang) && $current_lang !== '' && $current_lang !== $original_lang) {
                do_action('wpml_switch_language', $original_lang);
                $restore_lang = $current_lang;
            }
        }
        $posts = get_posts($query);
        if ($restore_lang !== '') {
            do_action('wpml_switch_language', $restore_lang);
        }
        if (!is_array($posts)) {
            $posts = [];
        }
        ?>
        <fieldset class="xabia-page-id-picker" style="margin:12px 0;padding:12px;border:1px solid #dcdcde;border-radius:6px;max-width:560px;">
            <legend style="font-weight:600;padding:0 6px;"><?php echo esc_html($legend); ?></legend>
            <?php if ($posts === []) : ?>
                <p class="description"><?php echo esc_html__('No hay páginas o entradas publicadas.', 'xabia-intelligence'); ?></p>
            <?php else : ?>
                <div style="max-height:200px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;">
                <?php foreach ($posts as $p) :
                    if (!($p instanceof WP_Post)) {
                        continue;
                    }
                    $pid = (int) $p->ID;
                    $type_obj = get_post_type_object($p->post_type);
                    $type_label = $type_obj && isset($type_obj->labels->singular_name)
                        ? (string) $type_obj->labels->singular_name
                        : $p->post_type;
                    ?>
                    <label style="display:flex;align-items:center;gap:8px;margin:0;">
                        <input type="checkbox" name="<?php echo esc_attr($array_field); ?>[]" value="<?php echo esc_attr((string) $pid); ?>" <?php checked(in_array($pid, $selected_ids, true)); ?>>
                        <span><?php echo esc_html($p->post_title); ?> <code style="font-size:11px;">ID <?php echo (int) $pid; ?></code> <span style="color:#646970;">(<?php echo esc_html($type_label); ?>)</span></span>
                    </label>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <p style="margin:10px 0 4px;font-size:12px;color:#646970;"><?php echo esc_html__('IDs adicionales (comas)', 'xabia-intelligence'); ?></p>
            <input type="text" name="<?php echo esc_attr($text_field); ?>" class="widefat" style="max-width:100%;" placeholder="123, 456" value="<?php echo esc_attr($selected_ids !== [] ? implode(', ', $selected_ids) : ''); ?>">
        </fieldset>
        <?php
    }

    public static function enqueue_assets(): void {
        if (is_admin() || self::$assets_enqueued) {
            return;
        }

        $css_path = XABIA_PATH . 'frontend/assets/css/xabia-interface.css';
        $js_path  = XABIA_PATH . 'frontend/assets/js/xabia-interface.js';
        $ver      = defined('XABIA_VERSION') ? XABIA_VERSION : '1.0';
        if (is_readable($css_path)) {
            $ver .= '.' . (string) filemtime($css_path);
        }
        wp_enqueue_style('xabia-interface', self::asset_url('frontend/assets/css/xabia-interface.css'), [], $ver);

        $js_ver = defined('XABIA_VERSION') ? XABIA_VERSION : '1.0';
        if (is_readable($js_path)) {
            $js_ver .= '.' . (string) filemtime($js_path);
        }
        wp_enqueue_script('xabia-interface', self::asset_url('frontend/assets/js/xabia-interface.js'), [], $js_ver, true);

        self::$assets_enqueued = true;
    }

    public static function render_footer(): void {
        self::prime_projects_for_request();
        self::maybe_render_missing_shortcodes();
        self::print_chatbox_scripts_fallback();
        if (self::$rendered_chatbox !== [] || self::$primed_projects !== []) {
            self::ping_chatbox_mounted();
        }

        $project_ids = array_values(array_unique(array_merge(
            self::$page_projects,
            self::$search_bar_projects,
            self::$rendered_chatbox
        )));
        if ($project_ids === []) {
            return;
        }

        foreach ($project_ids as $project_id) {
            $with_search_bar = in_array($project_id, self::$search_bar_projects, true);
            $with_chatbox = in_array($project_id, self::$rendered_chatbox, true);
            if (!$with_search_bar && !$with_chatbox && !self::should_render_for_project($project_id)) {
                continue;
            }
            $settings = self::get_project_settings($project_id);
            if (self::is_kiosk_presentation_mode($settings[self::OPT_PRESENTATION_MODE])) {
                continue;
            }
            /* shortcode: el botón solo sale donde se inserte [xabia_chat_button], salvo en la home con barra Hero. */
            if (!$with_search_bar && ($settings[self::OPT_TRIGGER_POSITION] ?? '') === 'shortcode') {
                continue;
            }
            if (!$with_search_bar && !$with_chatbox && !self::project_uses_web_launcher($project_id) && !in_array($project_id, self::$launcher_projects, true)) {
                continue;
            }
            self::render_trigger_markup($project_id);
        }
    }

    /**
     * Si el shortcode está en la página pero no se ejecutó en the_content (builders), lo renderiza al pie.
     */
    private static function maybe_render_missing_shortcodes(): void {
        if (self::$footer_shortcode_fallback_done || self::$primed_projects === []) {
            return;
        }
        self::$footer_shortcode_fallback_done = true;

        foreach (self::$primed_projects as $project_id) {
            if (in_array($project_id, self::$rendered_chatbox, true)) {
                continue;
            }
            if (self::is_project_paused($project_id)) {
                continue;
            }
            $projects = get_option('xabia_projects_config', []);
            if (!isset($projects[$project_id]) || !is_array($projects[$project_id])) {
                continue;
            }
            echo do_shortcode('[xabia_agent id="' . esc_attr($project_id) . '"]');
        }
    }

    /**
     * @param array{placement?:string,extra_class?:string,size?:string,size_value?:string,size_unit?:string,size_tablet?:string,size_mobile?:string,size_tablet_unit?:string,size_mobile_unit?:string,size_tablet_preset?:string,size_mobile_preset?:string} $args
     */
    private static function render_trigger_markup(string $project_id, array $args = []): void {
        $placement = isset($args['placement']) ? sanitize_key((string) $args['placement']) : 'fixed';
        if ($placement !== 'inline') {
            $placement = 'fixed';
        }
        $extra_class = isset($args['extra_class']) ? sanitize_html_class((string) $args['extra_class']) : '';
        $size = isset($args['size']) ? sanitize_key((string) $args['size']) : '';
        $size_value = isset($args['size_value']) ? (string) $args['size_value'] : '';
        $size_unit = isset($args['size_unit']) ? sanitize_key((string) $args['size_unit']) : '';
        $size_tablet = isset($args['size_tablet']) ? (string) $args['size_tablet'] : '';
        $size_mobile = isset($args['size_mobile']) ? (string) $args['size_mobile'] : '';
        $size_tablet_unit = isset($args['size_tablet_unit']) ? sanitize_key((string) $args['size_tablet_unit']) : '';
        $size_mobile_unit = isset($args['size_mobile_unit']) ? sanitize_key((string) $args['size_mobile_unit']) : '';
        $size_tablet_preset = isset($args['size_tablet_preset']) ? sanitize_key((string) $args['size_tablet_preset']) : '';
        $size_mobile_preset = isset($args['size_mobile_preset']) ? sanitize_key((string) $args['size_mobile_preset']) : '';

        $settings = self::get_project_settings($project_id);
        $colors   = $settings[self::OPT_AVATAR_COLORS];
        $bg_color = esc_attr($colors['bg']);
        $shadow_color = esc_attr($colors['shadow']);
        $dots_color = esc_attr($colors['dots']);
        $position = $settings[self::OPT_TRIGGER_POSITION];
        $margin_x = $settings[self::OPT_TRIGGER_MARGIN_X];
        $margin_y = $settings[self::OPT_TRIGGER_MARGIN_Y];
        $layout   = $settings[self::OPT_PANEL_LAYOUT];
        $mobile_preset = $settings[self::OPT_MOBILE_PRESET];
        /* El preset compact del flotante NO debe aplicarse al launcher inline. */
        $mobile_preset_class = $placement === 'inline' ? '' : self::mobile_preset_class($mobile_preset);

        self::$trigger_instance++;
        $instance = self::$trigger_instance;

        $trigger_id     = 'xabia-interface-trigger-' . $project_id . ($placement === 'inline' ? '-i' . $instance : '');
        $overlay_id     = 'xabia-blur-overlay-' . $project_id;
        $panel_selector = '#xabia-chatbox-' . $project_id;
        $position_class = $placement === 'inline'
            ? 'xabia-launcher--inline'
            : 'xabia-launcher--' . str_replace('_', '-', $position);
        $layout = 'full_screen';
        $layout_class   = 'layout-full-screen';
        $size_class = '';
        $size_mobile_class = '';
        if ($placement === 'inline') {
            if ($size_unit === 'pct' || (substr($size_value, -1) === '%')) {
                $size_class = 'xabia-trigger-size--pct';
            } elseif ($size !== '' && $size_value === '') {
                $size_class = 'xabia-trigger-size--' . $size;
            } elseif ($size_value !== '') {
                $size_class = 'xabia-trigger-size--custom';
            } else {
                $size_class = 'xabia-trigger-size--md';
            }
            if ($size_mobile_unit === 'pct' || ($size_mobile !== '' && substr($size_mobile, -1) === '%')) {
                $size_mobile_class = 'xabia-trigger-size-mobile--pct';
            }
        }

        $box_classes = trim(implode(' ', array_filter([
            'xabia-launcher',
            $position_class,
            $extra_class,
        ])));

        $box_id = $placement === 'inline'
            ? 'xabia-launcher-' . $project_id . '-' . $instance
            : ($project_id === 'default' ? 'sticky-footer-box' : 'sticky-footer-box-' . $project_id);

        $inline_style = '';
        if ($placement === 'inline') {
            $vars = [];
            $desktop_css = $size_value !== '' ? $size_value : ($size !== '' ? self::launcher_preset_css($size) : self::launcher_preset_css('md'));
            $vars[] = '--xabia-trigger-size-desktop:' . $desktop_css;
            $vars[] = '--xabia-trigger-size:' . $desktop_css;

            $tablet_css = $size_tablet !== ''
                ? $size_tablet
                : ($size_tablet_preset !== '' ? self::launcher_preset_css($size_tablet_preset) : $desktop_css);
            $vars[] = '--xabia-trigger-size-tablet:' . $tablet_css;

            $mobile_css = $size_mobile !== ''
                ? $size_mobile
                : ($size_mobile_preset !== '' ? self::launcher_preset_css($size_mobile_preset) : $desktop_css);
            $vars[] = '--xabia-trigger-size-mobile:' . $mobile_css;

            $inline_style = trim($inline_style . ' ' . implode(';', $vars) . ';');
        }
        $label = self::sanitize_launcher_label((string) ($settings[self::OPT_LAUNCHER_LABEL] ?? ''));
        ?>
        <style id="xabia-interface-vars-<?php echo esc_attr($project_id . ($placement === 'inline' ? '-i' . $instance : '')); ?>">
            .xabia-interface-trigger[data-project="<?php echo esc_attr($project_id); ?>"] {
                --xabia-avatar-bg: <?php echo $bg_color; ?>;
                --xabia-avatar-shadow: <?php echo $shadow_color; ?>;
                --xabia-dots-color: <?php echo $dots_color; ?>;
            }
        </style>

        <?php if (!in_array($project_id, self::$overlays_rendered, true)) :
            self::$overlays_rendered[] = $project_id;
            ?>
        <div
            id="<?php echo esc_attr($overlay_id); ?>"
            class="xabia-blur-overlay <?php echo esc_attr($layout_class); ?>"
            data-project="<?php echo esc_attr($project_id); ?>"
            aria-hidden="true"
            hidden
        ></div>
        <?php endif; ?>

        <button
            type="button"
            id="<?php echo esc_attr($trigger_id); ?>"
            class="<?php echo esc_attr($box_classes . ' xabia-interface-trigger ' . $layout_class); ?>"
            data-project="<?php echo esc_attr($project_id); ?>"
            data-panel-selector="<?php echo esc_attr($panel_selector); ?>"
            data-overlay-id="<?php echo esc_attr($overlay_id); ?>"
            data-panel-layout="full_screen"
            data-placement="<?php echo esc_attr($placement); ?>"
            data-speaking-avatar="<?php echo !empty($settings[self::OPT_SPEAKING_AVATAR]) ? '1' : '0'; ?>"
            aria-expanded="false"
            aria-controls="xabia-chatbox-<?php echo esc_attr($project_id); ?>"
            aria-label="<?php echo esc_attr($label); ?>"
            <?php if ($inline_style !== '') : ?>style="<?php echo esc_attr($inline_style); ?>"<?php endif; ?>
        >
            <span class="material-symbols-outlined xabia-launcher__spark" aria-hidden="true">auto_awesome</span>
            <span class="xabia-launcher__label"><?php echo esc_html($label); ?></span>
            <span class="xabia-launcher__desk"><?php echo esc_html__('Mostrador Virtual', 'xabia-intelligence'); ?></span>
            <span class="xabia-launcher__mic" aria-hidden="true"><span class="material-symbols-outlined">mic</span></span>
        </button>
        <?php
    }

    /**
     * Cuadrícula visual del modo de presentación (pestaña Apariencia).
     */
    public static function render_presentation_mode_picker(string $edit_id): void {
        if ($edit_id === '' || $edit_id === 'new') {
            return;
        }
        $s = self::get_project_settings($edit_id);
        $presentation_mode = self::sanitize_presentation_mode((string) ($s[self::OPT_PRESENTATION_MODE] ?? 'web_adaptive'));
        $catalog = self::presentation_mode_catalog();
        $groups = [
            'web'   => ['label' => __('Para tu web', 'xabia-intelligence'), 'modes' => []],
            'kiosk' => ['label' => __('Para tótem o pantalla fija', 'xabia-intelligence'), 'modes' => []],
        ];
        foreach ($catalog as $mode_key => $meta) {
            $group = $meta['group'] ?? 'web';
            if (!isset($groups[$group])) {
                $groups[$group] = ['label' => $meta['group_label'], 'modes' => []];
            }
            $groups[$group]['modes'][$mode_key] = $meta;
        }
        ?>
        <div class="xabia-admin-section xabia-presentation-section">
            <div class="xabia-admin-section__head">
                <h3 class="xabia-admin-section__title"><?php echo esc_html__('¿Dónde se verá el agente?', 'xabia-intelligence'); ?></h3>
                <p class="description"><?php echo esc_html__('Burbuja flotante para la web, o modos de pantalla dedicada (tótem). En móvil el chat pasa siempre a pantalla completa.', 'xabia-intelligence'); ?></p>
            </div>
            <input type="hidden" name="xabia_presentation_mode" id="xabia_presentation_mode" value="<?php echo esc_attr($presentation_mode); ?>">
            <?php foreach ($groups as $group_key => $group) :
                if ($group['modes'] === []) {
                    continue;
                }
                ?>
                <p class="xabia-presentation-group-label"><?php echo esc_html($group['label']); ?></p>
                <div class="xabia-presentation-grid" role="radiogroup" aria-label="<?php echo esc_attr($group['label']); ?>">
                    <?php foreach ($group['modes'] as $mode_key => $meta) :
                        $selected = $presentation_mode === $mode_key;
                        $thumb = (string) ($meta['thumb'] ?? 'web');
                        ?>
                        <button
                            type="button"
                            class="xabia-pres-card<?php echo $selected ? ' is-selected' : ''; ?>"
                            data-presentation-mode="<?php echo esc_attr($mode_key); ?>"
                            aria-pressed="<?php echo $selected ? 'true' : 'false'; ?>"
                        >
                            <span class="xabia-pres-thumb xabia-pres-thumb--<?php echo esc_attr($thumb); ?>" aria-hidden="true">
                                <span class="xabia-pres-thumb__avatar"></span>
                                <span class="xabia-pres-thumb__chat">
                                    <span></span><span></span><span></span>
                                </span>
                                <?php if ($group_key === 'web') : ?>
                                    <span class="xabia-pres-thumb__bubble"></span>
                                <?php endif; ?>
                            </span>
                            <span class="xabia-pres-card__title"><?php echo esc_html($meta['title']); ?></span>
                            <span class="xabia-pres-card__summary"><?php echo esc_html($meta['summary']); ?></span>
                            <span class="xabia-pres-card__hint"><?php echo esc_html($meta['hint']); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <div class="xabia-presentation-detail xabia-presentation-kiosk-note" style="<?php echo self::is_kiosk_presentation_mode($presentation_mode) ? '' : 'display:none;'; ?>">
                <strong><?php echo esc_html__('Pantalla dedicada', 'xabia-intelligence'); ?></strong>
                <p><?php echo esc_html__('No aparece el botón flotante. Usa una página con el shortcode o la URL dedicada del agente. El avatar parlante y el chat ocupan la pantalla.', 'xabia-intelligence'); ?></p>
                <p><code>[xabia_agent id="<?php echo esc_html($edit_id); ?>"]</code> · <code>/xabia-box/?x_project=<?php echo esc_html($edit_id); ?></code></p>
            </div>
            <div class="xabia-presentation-detail xabia-presentation-web-note" style="<?php echo $presentation_mode === 'web_adaptive' ? '' : 'display:none;'; ?>">
                <strong><?php echo esc_html__('Botón de chat', 'xabia-intelligence'); ?></strong>
                <p><?php echo esc_html__('En escritorio es una barra pequeña, como la barra hero. En el móvil se queda en un círculo. Siempre abre el chat a pantalla completa.', 'xabia-intelligence'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Campos en pestaña Apariencia del agente (dentro del formulario save_project).
     *
     * @param array<string, mixed> $data
     */
    public static function render_admin_fields(string $edit_id, array $data): void {
        if ($edit_id === '' || $edit_id === 'new') {
            return;
        }
        $s = self::get_project_settings($edit_id);
        $colors = $s[self::OPT_AVATAR_COLORS];
        $trigger_type = $s[self::OPT_TRIGGER_TYPE];
        $custom_url = $s[self::OPT_CUSTOM_TRIGGER];
        $position = $s[self::OPT_TRIGGER_POSITION];
        $layout = $s[self::OPT_PANEL_LAYOUT];
        $mobile_preset = $s[self::OPT_MOBILE_PRESET];
        $presentation_mode = self::sanitize_presentation_mode((string) ($s[self::OPT_PRESENTATION_MODE] ?? 'web_adaptive'));
        $is_kiosk = self::is_kiosk_presentation_mode($presentation_mode);
        $excluded_types = $s[self::OPT_EXCLUDE_POST_TYPES];
        $excluded_ids = $s[self::OPT_EXCLUDE_IDS];
        $included_ids = $s[self::OPT_INCLUDE_PAGE_IDS];
        $exclude_woo = !empty($s[self::OPT_EXCLUDE_WOO_CART_CHECKOUT]);
        $public_post_types = get_post_types(['public' => true], 'objects');
        if (!is_array($public_post_types)) {
            $public_post_types = [];
        }
        $color_scheme = self::sanitize_color_scheme((string) ($s[self::OPT_COLOR_SCHEME] ?? 'light'));
        ?>
        <div class="xabia-admin-section">
            <div class="xabia-admin-section__head">
                <h3 class="xabia-admin-section__title"><?php echo esc_html__('Apariencia', 'xabia-intelligence'); ?></h3>
                <p class="description"><?php echo esc_html__('Claro es el tema por defecto. Oscuro fuerza el tema oscuro. Automático sigue al sistema del visitante.', 'xabia-intelligence'); ?></p>
            </div>
            <label for="xabia_color_scheme"><strong><?php echo esc_html__('Apariencia', 'xabia-intelligence'); ?></strong></label>
            <select name="xabia_color_scheme" id="xabia_color_scheme" class="widefat" style="max-width:400px;">
                <option value="light" <?php selected($color_scheme, 'light'); ?>><?php echo esc_html__('Claro', 'xabia-intelligence'); ?></option>
                <option value="dark" <?php selected($color_scheme, 'dark'); ?>><?php echo esc_html__('Oscuro', 'xabia-intelligence'); ?></option>
                <option value="auto" <?php selected($color_scheme, 'auto'); ?>><?php echo esc_html__('Automático', 'xabia-intelligence'); ?></option>
            </select>
        </div>
        <div class="xabia-admin-section xabia-interface-web-only xabia-web-section" style="<?php echo $is_kiosk ? 'display:none;' : ''; ?>">
        <div class="xabia-admin-section__head">
            <h3 class="xabia-admin-section__title"><?php echo esc_html__('Posición del botón de chat', 'xabia-intelligence'); ?></h3>
            <p class="description"><?php echo esc_html__('El botón abre el chat a pantalla completa. En escritorio es una barra pequeña; en el móvil, un círculo. En modo tótem no se muestra: el avatar parlante vive en la pantalla dedicada.', 'xabia-intelligence'); ?></p>
        </div>
        <label for="xabia_trigger_position"><strong><?php echo esc_html__('Posición del botón de chat', 'xabia-intelligence'); ?></strong></label>
        <select name="xabia_trigger_position" id="xabia_trigger_position" class="widefat" style="max-width:400px;">
            <option value="bottom_right" <?php selected($position, 'bottom_right'); ?>><?php echo esc_html__('Abajo a la derecha', 'xabia-intelligence'); ?></option>
            <option value="bottom_center" <?php selected($position, 'bottom_center'); ?>><?php echo esc_html__('Abajo al centro', 'xabia-intelligence'); ?></option>
            <option value="bottom_left" <?php selected($position, 'bottom_left'); ?>><?php echo esc_html__('Abajo a la izquierda', 'xabia-intelligence'); ?></option>
            <option value="shortcode" <?php selected($position, 'shortcode'); ?>><?php echo esc_html__('Solo mediante shortcode', 'xabia-intelligence'); ?></option>
        </select>
        <p class="description"><?php echo esc_html__('Shortcode: no inyecta el botón fijo. Colócalo con [xabia_chat_button] o en un menú.', 'xabia-intelligence'); ?></p>
        <label for="xabia_launcher_label" style="display:block;margin-top:14px;"><strong><?php echo esc_html__('Frase del botón', 'xabia-intelligence'); ?></strong></label>
        <input type="text" name="xabia_launcher_label" id="xabia_launcher_label" class="widefat" style="max-width:400px;" maxlength="80" value="<?php echo esc_attr((string) ($s[self::OPT_LAUNCHER_LABEL] ?? '¿Cómo puedo ayudarte?')); ?>">
        <p class="description"><?php echo esc_html__('Se lee en la barra del escritorio. En el móvil solo se ve el símbolo de la barra hero.', 'xabia-intelligence'); ?></p>

        <div class="xabia-native-interface-options" style="<?php echo $position === 'shortcode' ? 'display:none;' : ''; ?>">
            <hr style="margin:16px 0;">
            <h4 style="margin:0 0 8px;"><?php echo esc_html__('¿En qué páginas?', 'xabia-intelligence'); ?></h4>

            <?php
            self::render_page_id_checklist(
                'xabia_include_page_ids',
                'xabia_include_page_ids_manual',
                $included_ids,
                __('Mostrar solo en estas páginas', 'xabia-intelligence')
            );
            ?>
            <p class="description"><?php echo esc_html__('Sin ninguna marcada = todo el sitio. Si marca páginas aquí, esta lista tiene prioridad y las exclusiones no se aplican.', 'xabia-intelligence'); ?></p>

            <div class="xabia-interface-exclusions-wrap">
                <strong><?php echo esc_html__('Excluir estas páginas o entradas', 'xabia-intelligence'); ?></strong>
                <p class="description"><?php echo esc_html__('Sin ninguna marcada = todo el sitio. Si marca páginas aquí, esta lista tiene prioridad y las exclusiones no se aplican.', 'xabia-intelligence'); ?></p>
                <div style="display:flex;flex-wrap:wrap;gap:8px 14px;margin:8px 0;">
                    <?php foreach ($public_post_types as $pt_obj) :
                        if (!is_object($pt_obj) || empty($pt_obj->name)) {
                            continue;
                        }
                        $pt_name = (string) $pt_obj->name;
                        $pt_label = !empty($pt_obj->labels->singular_name) ? (string) $pt_obj->labels->singular_name : $pt_name;
                        ?>
                        <label><input type="checkbox" name="xabia_exclude_post_types[]" value="<?php echo esc_attr($pt_name); ?>" <?php checked(in_array($pt_name, $excluded_types, true)); ?>> <?php echo esc_html($pt_label); ?></label>
                    <?php endforeach; ?>
                </div>
                <?php
                self::render_page_id_checklist(
                    'xabia_exclude_page_ids',
                    'xabia_exclude_ids_manual',
                    $excluded_ids,
                    __('Excluir estas páginas o entradas', 'xabia-intelligence')
                );
                ?>
                <?php if (class_exists('WooCommerce', false)) : ?>
                    <label style="display:block;margin-top:10px;"><input type="checkbox" name="xabia_exclude_woo_cart_checkout" value="1" <?php checked($exclude_woo); ?>> <?php echo esc_html__('Ocultar en carrito y pago WooCommerce', 'xabia-intelligence'); ?></label>
                <?php endif; ?>
            </div>
            <p class="description xabia-interface-exclusions-muted" style="display:none;"><?php echo esc_html__('Las exclusiones se ocultan porque ya se ha elegido una lista cerrada en «Mostrar solo en estas páginas».', 'xabia-intelligence'); ?></p>
        </div>
        </div>

        <script>
        jQuery(function($) {
            function xabiaSyncAgentInterfaceUi() {
                var pres = $('#xabia_presentation_mode').val() || 'web_adaptive';
                var isKiosk = pres !== 'web_adaptive';
                var isTransparent = pres.indexOf('totem_transparent_') === 0;
                var fixedButton = $('#xabia_trigger_position').val() !== 'shortcode';
                var hasIncludes = $('input[name="xabia_include_page_ids[]"]:checked').length > 0 || $.trim($('input[name="xabia_include_page_ids_manual"]').val() || '') !== '';
                $('.xabia-native-interface-options').toggle(!isKiosk && fixedButton);
                $('.xabia-presentation-kiosk-note').toggle(isKiosk);
                $('.xabia-presentation-web-note').toggle(!isKiosk);
                $('.xabia-interface-web-only').toggle(!isKiosk);
                $('.xabia-design-bg-note-transparent').toggle(isTransparent);
                $('.xabia-design-bg-note-kiosk').toggle(isKiosk && !isTransparent);
                $('.xabia-interface-exclusions-wrap').toggle(!hasIncludes);
                $('.xabia-interface-exclusions-muted').toggle(hasIncludes);
            }
            function xabiaSelectPresentationMode(mode) {
                mode = mode || 'web_adaptive';
                $('#xabia_presentation_mode').val(mode);
                $('.xabia-pres-card').each(function() {
                    var on = $(this).data('presentation-mode') === mode;
                    $(this).toggleClass('is-selected', on).attr('aria-pressed', on ? 'true' : 'false');
                });
                xabiaSyncAgentInterfaceUi();
            }
            $('.xabia-pres-card').on('click', function(e) {
                e.preventDefault();
                xabiaSelectPresentationMode($(this).data('presentation-mode'));
            });
            $('#xabia_trigger_position, input[name="xabia_include_page_ids[]"], input[name="xabia_include_page_ids_manual"]').on('change input', xabiaSyncAgentInterfaceUi);
            xabiaSyncAgentInterfaceUi();
        });
        </script>
        <?php
    }
}
