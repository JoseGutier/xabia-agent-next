<?php
/**
 * Plugin Name: Xabia Agent Core
 * Plugin URI: https://xabia.ai
 * Description: Agente de Inteligencia Artificial de última generación con voz, texto y acciones en la web. Perfecciona la UX mediante interacciones conversacionales inteligentes, hiperpersonalizadas y políglotas. Smart QRs integrados, addons para Woo, MEC, Amelia, etc.
 * Version: 1.0.217
 * Author: Digixop
 * Author URI: https://digixop.com
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('str_contains')) {
    /**
     * Polyfill PHP 8.0 (Hostinger / WP aún en PHP 7.4).
     */
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    /**
     * Polyfill PHP 8.0.
     */
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

define('XABIA_PATH', plugin_dir_path(__FILE__));
define('XABIA_URL', plugin_dir_url(__FILE__));
/** Única fuente de versión: la cabecera * Version: de este archivo (evita caché de assets desincronizada). */
$_xabia_ver = get_file_data(__FILE__, ['Version' => 'Version'], 'plugin')['Version'] ?? '';
define('XABIA_VERSION', $_xabia_ver !== '' ? trim((string) $_xabia_ver) : '1.0.0');
unset($_xabia_ver);

require_once XABIA_PATH . 'core/class-xabia-mode.php';
require_once XABIA_PATH . 'includes/class-xabia-features.php';

if (!function_exists('xabia_api_dir')) {
    /**
     * Clases bajo api/: en instalación WordPress van en XABIA_PATH/api; en monorepo, xabia-agent-plugins/api/.
     */
    function xabia_api_dir(): string {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }
        $primary = XABIA_PATH . 'api/';
        if (is_readable($primary . 'class-xabia-api.php')) {
            $resolved = $primary;
            return $resolved;
        }
        $base = rtrim(XABIA_PATH, '/\\');
        $mono = dirname(dirname($base)) . '/api/';
        if (is_readable($mono . 'class-xabia-api.php')) {
            $resolved = $mono;
            return $resolved;
        }
        $resolved = $primary;
        return $resolved;
    }
}

require_once XABIA_PATH . 'core/class-xabia-agent-core.php';
require_once XABIA_PATH . 'core/class-xabia-i18n-bridge.php';
require_once XABIA_PATH . 'core/class-xabia-i18n.php';
Xabia_I18n::init();

add_action('init', function() {
    if (!session_id() && !headers_sent()) {
        session_start();
    }
}, 1);

require_once XABIA_PATH . 'core/class-xabia-trace.php';
require_once XABIA_PATH . 'core/class-xabia-db.php';
require_once XABIA_PATH . 'core/interface-xabia-database.php';
require_once XABIA_PATH . 'core/class-xabia-pdo-db-adapter.php';
require_once XABIA_PATH . 'core/class-xabia-wp-db-adapter.php';
if (Xabia_Features::is_pro()) {
    require_once XABIA_PATH . 'core/class-xabia-document-ingest.php';
    require_once XABIA_PATH . 'core/class-xabia-document-ingest-bridge.php';
}
require_once XABIA_PATH . 'core/class-xabia-embedding-cache.php';
require_once XABIA_PATH . 'core/class-xabia-brain.php';
require_once XABIA_PATH . 'core/class-xabia-router.php';
require_once XABIA_PATH . 'core/class-xabia-knowledge-text.php';
require_once XABIA_PATH . 'core/class-xabia-starter-questions.php';
require_once XABIA_PATH . 'core/class-xabia-knowledge-ingest.php';
require_once XABIA_PATH . 'core/class-xabia-knowledge-language-driver.php';
require_once XABIA_PATH . 'core/class-xabia-rag-language-bridge.php';
require_once XABIA_PATH . 'core/class-xabia-knowledge-relations.php';
require_once XABIA_PATH . 'core/class-xabia-relation-entity-catalog.php';
require_once XABIA_PATH . 'core/class-xabia-catalog-list.php';
require_once XABIA_PATH . 'core/class-xabia-mec-public-link.php';
require_once XABIA_PATH . 'core/class-xabia-db-bridge.php';
require_once XABIA_PATH . 'core/class-xabia-knowledge-sync.php';
require_once XABIA_PATH . 'core/class-xabia-knowledge-optimizer.php';
require_once XABIA_PATH . 'core/class-xabia-knowledge-train.php';
require_once XABIA_PATH . 'core/class-xabia-knowledge-orphans.php';
require_once XABIA_PATH . 'core/class-xabia-auto-sync.php';
require_once XABIA_PATH . 'core/class-xabia-cloud-cron-rest.php';
require_once XABIA_PATH . 'core/class-xabia-cpt-schema-discovery.php';

if (Xabia_Features::is_pro()) {
    require_once XABIA_PATH . 'core/class-xabia-web-scraper.php';
    require_once XABIA_PATH . 'core/class-xabia-web-pages-source.php';
}

if (Xabia_Features::is_pro()) {
    require_once XABIA_PATH . 'core/class-xabia-digixop-client.php';
    require_once XABIA_PATH . 'core/class-xabia-hub-knowledge.php';
} else {
    require_once XABIA_PATH . 'core/class-xabia-lite-secrets.php';
    require_once XABIA_PATH . 'core/class-xabia-lite-context.php';
    require_once XABIA_PATH . 'core/class-xabia-lite-scraper.php';
    require_once XABIA_PATH . 'core/class-xabia-lite-hub-client.php';
    require_once XABIA_PATH . 'core/class-xabia-lite-api-handler.php';
    require_once XABIA_PATH . 'core/class-xabia-lite-guard.php';
    require_once XABIA_PATH . 'core/class-xabia-lite-notices.php';
    Xabia_Lite_Guard::init();
    Xabia_Lite_Notices::boot_for_lite();
}

require_once XABIA_PATH . 'core/class-xabia-addons-manager.php';
require_once XABIA_PATH . 'core/class-xabia-analytics.php';
require_once XABIA_PATH . 'core/class-xabia-updater.php';

if (Xabia_Features::is_pro()) {
    require_once XABIA_PATH . 'core/class-xabia-addon-updater.php';
}

if (Xabia_Features::is_pro()) {
    require_once xabia_api_dir() . 'class-xabia-api.php';
}

if (Xabia_Features::is_pro()) {
    require_once xabia_api_dir() . 'class-xabia-wallet-api.php';
    require_once XABIA_PATH . 'core/class-xabia-federation-nexus.php';
}

Xabia_Analytics::init();
add_action('xabia_agent_admin_extra_tabs_content', [Xabia_Analytics::class, 'render_tab'], 30, 2);

/**
 * Contenedor global de addons registrados.
 * Se define ANTES de cargar los archivos para que esté disponible de inmediato.
 */
global $xabia_available_addons;
$xabia_available_addons = [];

/**
 * Función global para registrar addons de forma visual.
 * Almacena en la global y también en un filtro por compatibilidad.
 */
function register_xabia_addon($slug, $args) {
    global $xabia_available_addons;
    $xabia_available_addons[$slug] = $args;
    
    
    add_filter('xabia_register_sql_sources', function($sources) use ($slug, $args) {
        $sources[$slug] = $args;
        return $sources;
    });
}

if (Xabia_Features::is_pro()) {
    foreach (glob(XABIA_PATH . 'integrations/*.php') as $file) {
        require_once $file;
    }
    foreach (['central', 'reservas'] as $xabia_integration_subdir) {
        $subdir_files = glob(XABIA_PATH . 'integrations/' . $xabia_integration_subdir . '/*.php');
        if (is_array($subdir_files)) {
            foreach ($subdir_files as $file) {
                require_once $file;
            }
        }
    }
}

if (Xabia_Features::is_pro()) {
    $xabia_addon_glob = glob(XABIA_PATH . 'addons/*/xabia-addon-*.php');
    if (is_array($xabia_addon_glob)) {
        foreach ($xabia_addon_glob as $file) {
            if (is_readable($file)) {
                require_once $file;
            }
        }
    }
    unset($xabia_addon_glob);
}

if (Xabia_Features::is_pro()) {
    require_once XABIA_PATH . 'core/class-xabia-smart-qr.php';
    Xabia_Smart_QR::init();
}

require_once XABIA_PATH . 'core/class-xabia-box-route.php';
Xabia_Box_Route::init();

require_once XABIA_PATH . 'frontend/class-xabia-interface.php';
add_action('plugins_loaded', static function (): void {
    if (!is_admin()) {
        Xabia_Interface::init();
    }
}, 25);

/** Tras actualizar el plugin, regenerar reglas si cambió la versión (p. ej. nueva ruta xabia-box). */
add_action('admin_init', static function (): void {
    if (!is_admin() || !class_exists('Xabia_Box_Route', false)) {
        return;
    }
    $stored = (string) get_option('xabia_box_rewrite_pkg', '');
    if ($stored === XABIA_VERSION) {
        return;
    }
    Xabia_Box_Route::activate_flush();
    update_option('xabia_box_rewrite_pkg', XABIA_VERSION, false);
}, 30);

/**
 * Tras que los demás plugins registren CPTs (MEC, Woo, Amelia…), publicar addons SQL al core.
 */
add_action('init', static function (): void {
    do_action('xabia_register_addons');
}, 20);

add_action('init', static function (): void {
    if (!Xabia_Features::is_pro()) {
        return;
    }
    if (class_exists('Xabia_Auto_Sync', false)) {
        Xabia_Auto_Sync::init();
    }
    if (class_exists('Xabia_Cloud_Cron_Rest', false)) {
        Xabia_Cloud_Cron_Rest::init();
    }
}, 25);

/**
 * Punto de extension modular para que plugins-addon inyecten contexto antes de enviar al Hub/LLM.
 * El Core no consulta plugins externos de forma nativa; solo consume este filtro.
 */
add_filter('xabia_agent_context_injection', static function ($context, $project_id, $config, $request) {
    unset($project_id, $config, $request);

    return is_string($context) ? $context : '';
}, 1, 4);

add_filter('xabia_router_search_logic', static function ($current_logic, $project_id, $current_ymd) {
    $projects = get_option('xabia_projects_config', []);
    $cfg = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];
    if (($cfg['source_type'] ?? '') !== 'sql' || ($cfg['sql_preset'] ?? '') !== 'mec_remote') {
        return $current_logic;
    }

    $ymd = is_string($current_ymd) ? $current_ymd : gmdate('Y-m-d');
    return (string) $current_logic
        . "\n - REGLA MEC REMOTO — CALENDARIO: Cada fila es un evento raíz MEC de una base WordPress remota. Para «este fin de semana»: calcula sábado y domingo en la semana que contiene HOY (" . $ymd . ") y filtra eventos cuya Fecha cae en esos días."
        . "\n - REGLA MEC REMOTO — PLAZAS: Si existe la columna 'mec_available_slots', úsala como plazas libres. Si está vacía o ausente, no afirmes cupo exacto."
        . "\n - REGLA MEC REMOTO — RESERVA: En SQL remoto no confirmas disponibilidad transaccional. Usa el campo Link como URL pública del evento o reserva cuando aparezca en el contexto; no inventes botones ni disponibilidad.";
}, 12, 3);

add_filter('xabia_system_time_awareness', static function ($time_awareness, $project_id, $config, $current_date) {
    unset($project_id);
    if (!is_array($config)) {
        return $time_awareness;
    }
    $is_mec = class_exists('Xabia_Knowledge_Sync', false)
        ? Xabia_Knowledge_Sync::is_mec_catalog_config($config)
        : (
            (($config['source_type'] ?? '') === 'addon' && ($config['addon_slug'] ?? '') === 'mec')
            || (($config['source_type'] ?? '') === 'sql' && ($config['sql_preset'] ?? '') === 'mec_remote')
        );
    if (!$is_mec) {
        return $time_awareness;
    }
    $ymd = function_exists('current_time') ? current_time('Y-m-d') : gmdate('Y-m-d');
    if (is_string($current_date) && preg_match('/\bISO\s+(\d{4}-\d{2}-\d{2})\b/', $current_date, $m)) {
        $ymd = $m[1];
    } elseif (preg_match('/\bISO\s+(\d{4}-\d{2}-\d{2})\b/', (string) $time_awareness, $m)) {
        $ymd = $m[1];
    }

    return rtrim((string) $time_awareness) . ' '
        . sprintf(
            __('REGLA CALENDARIO — FUTURO: Lista o recomienda solo ítems del CONTEXTO cuya Fecha (AAAA-MM-DD) sea ≥ %s. Ante «próximos» o «qué hay», ordena por Fecha ascendente y omite los ya pasados.', 'xabia-intelligence'),
            $ymd
        );
}, 12, 4);

add_filter('xabia_router_classify_route', static function ($route, $project_id, $message, $config, $lang) {
    if (!class_exists('Xabia_API')) {
        return $route;
    }
    if ($route === 'ROUTE_ACTION' || $route === 'ROUTE_GENERAL') {
        return $route;
    }
    $classified = Xabia_API::classify_route_with_mini((string) $project_id, (string) $message, is_array($config) ? $config : [], (string) $lang);
    if (in_array($classified, ['ROUTE_ACTION', 'ROUTE_KNOWLEDGE', 'ROUTE_GENERAL'], true)) {
        return $classified;
    }
    return $route;
}, 20, 5);

add_filter('xabia_router_action_response', static function ($response, $project_id, $message, $config, $request) {
    unset($project_id, $message, $config, $request);
    return $response;
}, 1, 5);

/**
 * Respuesta del asistente que parece cortada a mitad de frase (sin punto final, etc.).
 */
function xabia_response_looks_truncated(string $text): bool {
    $r = trim(wp_strip_all_tags($text));
    if ($r === '' || strlen($r) < 60) {
        return false;
    }
    $lines = preg_split('/\r\n|\r|\n/', $r) ?: [];
    $non_empty = array_values(array_filter(array_map('trim', $lines), static function ($l) {
        return $l !== '';
    }));
    if ($non_empty !== []) {
        $bullets = 0;
        foreach ($non_empty as $line) {
            if (preg_match('/^•\s+/u', $line) || preg_match('/^[-*]\s+/u', $line)) {
                ++$bullets;
            }
        }
        $last = $non_empty[count($non_empty) - 1];
        $last_core = preg_replace('/[\s\x{FE0F}\x{200D}\x{2600}-\x{27BF}\x{1F300}-\x{1FAFF}]+$/u', '', $last);
        if (!is_string($last_core) || $last_core === '') {
            $last_core = $last;
        }
        $last_incomplete = $last_core !== ''
            && !preg_match('/[.!?…](?:\s*|<br\s*\/?>)*$/iu', $last_core)
            && !preg_match('/\[(?:ACTION|BOOK):[^\]]+\](?:\s*|<br\s*\/?>)*$/i', $last_core);
        if ($bullets >= 2 && $bullets >= (int) floor(count($non_empty) * 0.6) && !$last_incomplete) {
            return false;
        }
    }
    // Emojis u otros sufijos tras «!» / «.» no deben disparar auto-continuación.
    // Sin \p{Extended_Pictographic}: PCRE antiguo en algunos hostings (p. ej. aktiba.eus) rompe el JSON del chat.
    $core = preg_replace('/[\s\x{FE0F}\x{200D}\x{2600}-\x{27BF}\x{1F300}-\x{1FAFF}]+$/u', '', $r);
    if (!is_string($core) || $core === '') {
        $core = $r;
    }
    if (preg_match('/[.!?…](?:\s*|<br\s*\/?>)*$/iu', $core)) {
        return false;
    }
    if (preg_match('/\[(?:ACTION|BOOK):[^\]]+\](?:\s*|<br\s*\/?>)*$/i', $core)) {
        return false;
    }

    return true;
}

/**
 * IA-Lite: intercepta continuaciones y reservas obvias antes de router/RAG/LLM.
 *
 * @return array{type:string,response?:string,message?:string,x_continue?:bool}|null
 */
function xabia_intercept_keywords(string $project_id, string $message, array $context = []): ?array {
    $msg = trim($message);
    if ($msg === '') {
        return null;
    }
    $norm = strtolower(remove_accents($msg));
    $norm_stripped = @preg_replace('/[^\p{L}\p{N}\?]+/u', ' ', $norm);
    if (!is_string($norm_stripped) || $norm_stripped === '') {
        $norm_stripped = preg_replace('/[^a-z0-9?]+/i', ' ', $norm);
    }
    $norm = trim(preg_replace('/\s+/', ' ', (string) $norm_stripped));

    if (!session_id() && !headers_sent()) {
        session_start();
    }
    $meta = $_SESSION['xabia_last_response_meta'][$project_id] ?? [];
    $availability = $_SESSION['xabia_last_availability'][$project_id] ?? [];

    $is_continue = in_array($norm, ['sigue', 'continua', 'continue', 'y', 'y?', 'mas', 'dale'], true);
    $prev_response = trim((string) ($meta['response'] ?? ''));
    $looks_cut = $prev_response !== '' && function_exists('xabia_response_looks_truncated')
        ? xabia_response_looks_truncated($prev_response)
        : false;
    if ($is_continue && (!empty($meta['truncated']) || $looks_cut)) {
        $prev_plain = trim(wp_strip_all_tags($prev_response));
        if ($prev_plain !== '' && preg_match('/^•\s+/m', $prev_plain)) {
            return null;
        }
        return [
            'type'       => 'continue',
            'message'    => 'Continúa exactamente desde donde lo dejaste, sin repetir lo anterior.',
            'x_continue' => true,
        ];
    }

    $is_booking = preg_match('/\b(reservar|reserva|reservalo|resérvalo|quiero reservar|hacer reserva|book)\b/u', $msg) === 1;
    $booking_url = '';
    if (is_array($availability)) {
        $booking_url = trim((string) ($availability['booking_url'] ?? ''));
    }
    if ($booking_url === '' && is_array($meta)) {
        $booking_url = trim((string) ($meta['booking_url'] ?? ''));
    }
    if ($is_booking && $booking_url !== '') {
        $room = is_array($availability) ? trim((string) ($availability['requested_room'] ?? '')) : '';
        $prefix = $room !== ''
            ? 'Perfecto. Para reservar ' . $room . ', puedes hacerlo aquí:'
            : 'Perfecto. Puedes hacer la reserva aquí:';
        return [
            'type'     => 'response',
            'response' => $prefix . "\n\n[ACTION:URL:" . esc_url_raw($booking_url) . "]",
        ];
    }

    return null;
}

if (class_exists('Xabia_Federation_Nexus') && Xabia_Features::is_pro()) {
    Xabia_Federation_Nexus::init();
}

if (is_admin()) {
    require_once XABIA_PATH . 'admin/class-xabia-admin-ui.php';
    Xabia_Updater::init();
    if (Xabia_Features::is_pro()) {
        require_once XABIA_PATH . 'admin/class-xabia-admin.php';
        Xabia_Admin::init();
        if (class_exists('Xabia_Addon_Updater', false)) {
            Xabia_Addon_Updater::init();
        }
    } else {
        require_once XABIA_PATH . 'admin/class-xabia-lite-admin.php';
        Xabia_Lite_Admin::init();
    }
}

add_action('plugins_loaded', static function (): void {
    if (!class_exists('Xabia_Agent_Core')) {
        return;
    }
    if (Xabia_Agent_Core::maybe_sync_mu_plugin()) {
        Xabia_Agent_Core::load_mu_plugin_for_current_request();
    }
}, 5);

add_action('plugins_loaded', static function (): void {
    if (class_exists('Xabia_DB') && method_exists('Xabia_DB', 'init')) {
        Xabia_DB::init();
    }
}, 15);

/**
 * Activación: tablas locales (dbDelta), federación Central y addons.
 */
function xabia_agent_ensure_secure_uploads_dir() {
    if (!function_exists('wp_upload_dir')) {
        return;
    }
    $uploads = wp_upload_dir();
    $base = rtrim((string) ($uploads['basedir'] ?? ''), '/');
    if ($base === '') {
        return;
    }
    $xabia_uploads = $base . '/xabia';
    if (!is_dir($xabia_uploads) && !wp_mkdir_p($xabia_uploads)) {
        return;
    }
    $htaccess = $xabia_uploads . '/.htaccess';
    if (!file_exists($htaccess)) {
        $rules = "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
        @file_put_contents($htaccess, $rules, LOCK_EX);
    }
    $index = $xabia_uploads . '/index.php';
    if (!file_exists($index)) {
        @file_put_contents($index, "<?php\n// Silence is golden.\n", LOCK_EX);
    }
}

function xabia_agent_next_activate() {
    if (!function_exists('dbDelta')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    if (class_exists('Xabia_Agent_Core')) {
        Xabia_Agent_Core::maybe_sync_mu_plugin();
        Xabia_Agent_Core::load_mu_plugin_for_current_request();
    }

    if (class_exists('Xabia_DB')) {
        Xabia_DB::install_tables();
    }

    if (class_exists('Xabia_Central_Setup') && Xabia_Features::is_pro()) {
        Xabia_Central_Setup::install();
    }

    do_action('xabia_install_addon_tables');

    if (class_exists('Xabia_Auto_Sync', false)) {
        Xabia_Auto_Sync::ensure_cron_scheduled();
    }

    xabia_agent_ensure_secure_uploads_dir();
    if (class_exists('Xabia_Mode', false)) {
        Xabia_Mode::ensure_lite_storage_dir();
    }

    if (class_exists('Xabia_Box_Route', false)) {
        Xabia_Box_Route::activate_flush();
    }
}

register_activation_hook(__FILE__, 'xabia_agent_next_activate');

add_action(
    'upgrader_process_complete',
    static function ($upgrader_object, $options): void {
        unset($upgrader_object);
        if (!isset($options['action'], $options['type']) || $options['action'] !== 'update' || $options['type'] !== 'plugin') {
            return;
        }
        if (empty($options['plugins']) || !is_array($options['plugins'])) {
            return;
        }
        $ours = plugin_basename(__FILE__);
        foreach ($options['plugins'] as $plugin) {
            if ((string) $plugin === $ours && class_exists('Xabia_Agent_Core')) {
                Xabia_Agent_Core::maybe_sync_mu_plugin();
                Xabia_Agent_Core::load_mu_plugin_for_current_request();
                break;
            }
        }
    },
    10,
    2
);

add_action('init', 'xabia_agent_ensure_secure_uploads_dir', 5);
add_action('init', static function (): void {
    if (class_exists('Xabia_Mode', false) && Xabia_Mode::is_lite()) {
        Xabia_Mode::ensure_lite_storage_dir();
    }
}, 6);
add_action('init', static function (): void {
    if (class_exists('Xabia_Starter_Questions', false)) {
        Xabia_Starter_Questions::maybe_seed_project_defaults();
    }
}, 20);

// Limpieza de duplicados de manuales (migración antigua Elementor → páginas nuevas /documentacion/).
// Elementor suele renderizar desde `_elementor_data`, así que resolvemos duplicados por `post_name`.
add_action('template_redirect', static function (): void {
    if (is_admin()) {
        return;
    }

    $obj = function_exists('get_queried_object') ? get_queried_object() : null;
    $post_name = is_object($obj) && isset($obj->post_name) ? (string) $obj->post_name : '';

    if ($post_name === 'guia-de-usuario-xabia-agent-core') {
        wp_redirect(home_url('/documentacion/xabia-agent-core/'), 301);
        exit;
    }

    if ($post_name === 'guia-de-usuario-xabia-para-avirato') {
        wp_redirect(home_url('/documentacion/avirato/'), 301);
        exit;
    }
});

$xabia_chat_shortcode_cb = static function ($atts) {
    $file = XABIA_PATH . 'frontend/widgets/chatbox.php';
    if (file_exists($file)) {
        require_once $file;
        if (function_exists('shortcode_xabia_agent_renderer')) {
            return shortcode_xabia_agent_renderer($atts);
        }
    }

    return '';
};
add_shortcode('xabia_agent', $xabia_chat_shortcode_cb);
add_shortcode('xabia_chat', $xabia_chat_shortcode_cb);

$xabia_launcher_shortcode_cb = static function ($atts) {
    if (!class_exists('Xabia_Interface', false)) {
        return '';
    }
    return Xabia_Interface::shortcode_launcher($atts);
};
add_shortcode('xabia_launcher', $xabia_launcher_shortcode_cb);
add_shortcode('xabia_avatar', $xabia_launcher_shortcode_cb);