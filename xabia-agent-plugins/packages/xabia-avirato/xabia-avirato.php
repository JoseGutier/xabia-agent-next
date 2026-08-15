<?php
/**
 * Plugin Name: Xabia Avirato
 * Plugin URI: https://xabia.ai
 * Description: Addon modular de scraping y disponibilidad Avirato para Xabia Agent Core.
 * Version: 1.0.19
 * Author: Digixop
 * Author URI: https://digixop.com
 */

if (!defined('ABSPATH')) {
    exit;
}

function xabia_avirato_requires_core_notice(): void
{
    echo '<div class="notice notice-error"><p>Xabia Avirato requiere tener activo el plugin xabia-agent-core.</p></div>';
}

$core_bootstrap = WP_PLUGIN_DIR . '/xabia-agent-core/xabia-intelligence.php';
if (!function_exists('is_plugin_active')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if (!file_exists($core_bootstrap) || !is_plugin_active('xabia-agent-core/xabia-intelligence.php')) {
    add_action('admin_notices', 'xabia_avirato_requires_core_notice');

    return;
}

add_filter('xabia_agent_native_connectors', static function ($plugins) {
    if (!is_array($plugins)) {
        $plugins = [];
    }
    $plugins[] = 'xabia-avirato/xabia-avirato.php';

    return array_values(array_unique(array_map('strval', $plugins)));
}, 10, 1);

add_filter('xabia_agent_known_addons', static function ($addons) {
    if (!is_array($addons)) {
        $addons = [];
    }
    $addons[] = ['plugin_file' => 'xabia-avirato/xabia-avirato.php', 'label' => 'Xabia Avirato'];

    return $addons;
}, 10, 1);

const XABIA_AVIRATO_OPTIONS_KEY = 'xabia_avirato_settings';

function xabia_avirato_subscription_active(): bool
{
    $forced = apply_filters('xabia_avirato_subscription_active', null, null);
    if (is_bool($forced)) {
        return $forced;
    }
    if (class_exists('Xabia_Addons', false)) {
        return Xabia_Addons::is_active('avirato');
    }

    return false;
}

add_action(
    'xabia_addon_hub_status_refreshed',
    static function (string $slug, $st = null): void {
        unset($st);
        if ($slug !== 'avirato') {
            return;
        }
        delete_transient('xabia_avirato_subscription_probe');
    },
    10,
    2
);

/**
 * Respuesta única y amable para el visitante cuando no hay disponibilidad en vivo
 * (suscripción add-on, hub, etc.). No menciona licencias ni fallos técnicos.
 */
function xabia_avirato_public_availability_fallback_message(): string
{
    $default = __('Puedo informarte sobre nuestras casas, aunque para ver calendarios exactos te recomiendo contactarnos directamente.', 'xabia-intelligence');

    return (string) apply_filters('xabia_avirato_inactive_availability_message', $default);
}

function xabia_avirato_get_settings(): array
{
    $defaults = [
        'establishment_id' => '',
        'availability_label' => '',
        'inclusion_filter' => '',
        'exclusion_list'   => '',
        'engine_url'       => 'https://booking.avirato.com/',
        'id_habitacion'    => '',
        'cod_promocional'  => '',
    ];
    $saved = get_option(XABIA_AVIRATO_OPTIONS_KEY, []);
    if (!is_array($saved)) {
        $saved = [];
    }
    $settings = array_merge($defaults, $saved);
    $settings['engine_url'] = trim((string) $settings['engine_url']);
    if ($settings['engine_url'] === '') {
        $settings['engine_url'] = $defaults['engine_url'];
    }
    if (substr($settings['engine_url'], -1) !== '/') {
        $settings['engine_url'] .= '/';
    }
    $settings['id_habitacion'] = isset($settings['id_habitacion']) ? trim((string) $settings['id_habitacion']) : '';
    $settings['cod_promocional'] = isset($settings['cod_promocional']) ? trim((string) $settings['cod_promocional']) : '';

    return $settings;
}

function xabia_avirato_availability_label(array $settings): string
{
    $label = trim((string) ($settings['availability_label'] ?? ''));
    if ($label !== '') {
        return $label;
    }
    $inclusion = trim((string) ($settings['inclusion_filter'] ?? ''));
    if ($inclusion !== '') {
        return $inclusion;
    }

    return 'el alojamiento configurado';
}

/**
 * Ajustes efectivos: si establishment_id está vacío, intenta webcode desde Avirato Calendar (tabla avc_establishment).
 */
function xabia_avirato_resolve_settings(): array
{
    $settings = xabia_avirato_get_settings();
    $id = trim((string) ($settings['establishment_id'] ?? ''));
    if ($id !== '' && trim((string) ($settings['id_habitacion'] ?? '')) === '') {
        $settings['id_habitacion'] = xabia_avirato_resolve_room_ids_from_calendar($id, $settings);
    }
    if ($id !== '') {
        return $settings;
    }
    global $wpdb;
    $table = xabia_avirato_calendar_table('avc_establishment');
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return $settings;
    }
    $webcode = $wpdb->get_var("SELECT webcode FROM {$table} WHERE active = 1 ORDER BY id ASC LIMIT 1");
    if ($webcode === null || $webcode === '') {
        $webcode = $wpdb->get_var("SELECT webcode FROM {$table} ORDER BY id ASC LIMIT 1");
    }
    if ($webcode !== null && (string) $webcode !== '') {
        $settings['establishment_id'] = (string) (int) $webcode;
        if (function_exists('xabia_trace')) {
            xabia_trace('[XABIA_AVIRATO] establishment_id zero-config from avc_establishment', ['webcode' => $settings['establishment_id']]);
        }
    }
    if (trim((string) ($settings['id_habitacion'] ?? '')) === '') {
        $settings['id_habitacion'] = xabia_avirato_resolve_room_ids_from_calendar($settings['establishment_id'], $settings);
    }

    return $settings;
}

function xabia_avirato_calendar_table(string $name): string
{
    global $wpdb;
    $prefixed = $wpdb->prefix . $name;
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prefixed)) === $prefixed) {
        return $prefixed;
    }
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) === $name) {
        return $name;
    }

    return $prefixed;
}

function xabia_avirato_resolve_room_ids_from_calendar(string $webcode, array $settings): string
{
    $webcode = trim($webcode);
    $inclusion = xabia_avirato_normalize_text((string) ($settings['inclusion_filter'] ?? ''));
    if ($webcode === '' || $inclusion === '') {
        return '';
    }

    $subtypes = xabia_avirato_get_calendar_subtypes($webcode);
    if ($subtypes === []) {
        return '';
    }

    $exclusions = [];
    foreach (explode(',', (string) ($settings['exclusion_list'] ?? '')) as $word) {
        $word = xabia_avirato_normalize_text((string) $word);
        if ($word !== '') {
            $exclusions[] = $word;
        }
    }

    $ids = [];
    foreach ($subtypes as $subtype) {
        if (!is_array($subtype)) {
            continue;
        }
        $name = (string) ($subtype['spaceSubtypeName'] ?? $subtype['name'] ?? '');
        $roomId = (string) ($subtype['spaceSubtypeId'] ?? $subtype['id'] ?? '');
        if ($name === '' || $roomId === '') {
            continue;
        }
        $normalizedName = xabia_avirato_normalize_text($name);
        if (!str_contains($normalizedName, $inclusion)) {
            continue;
        }
        $blocked = false;
        foreach ($exclusions as $excluded) {
            if (str_contains($normalizedName, $excluded)) {
                $blocked = true;
                break;
            }
        }
        if (!$blocked && preg_match('/^\d+$/', $roomId) === 1) {
            $ids[] = $roomId;
        }
    }

    $ids = array_values(array_unique($ids));
    if ($ids !== [] && function_exists('xabia_trace')) {
        xabia_trace('[XABIA_AVIRATO] resolved id_habitacion from avc_establishment', [
            'webcode' => $webcode,
            'inclusion' => $inclusion,
            'ids' => implode('-', $ids),
        ]);
    }

    return implode('-', $ids);
}

function xabia_avirato_get_calendar_subtypes(string $webcode): array
{
    $webcode = trim($webcode);
    if ($webcode === '') {
        return [];
    }

    global $wpdb;
    $table = xabia_avirato_calendar_table('avc_establishment');
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return [];
    }
    $subtypesJson = $wpdb->get_var($wpdb->prepare(
        "SELECT subtypes FROM {$table} WHERE webcode = %s ORDER BY active DESC, id ASC LIMIT 1",
        $webcode
    ));
    if (!is_string($subtypesJson) || trim($subtypesJson) === '') {
        return [];
    }
    $subtypes = json_decode($subtypesJson, true);
    if (!is_array($subtypes)) {
        return [];
    }

    return array_values(array_filter($subtypes, 'is_array'));
}

/**
 * @return array{id:int,name:string}|null
 */
function xabia_avirato_normalize_subtype_record(array $subtype): ?array
{
    $id = (int) ($subtype['id'] ?? $subtype['spaceSubtypeId'] ?? $subtype['idSubtipoEspacio'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $name = trim((string) ($subtype['name'] ?? $subtype['spaceSubtypeName'] ?? $subtype['subtipoEspacio'] ?? $subtype['nombreEspacio'] ?? ''));
    if ($name === '') {
        $name = 'Habitación ' . $id;
    }

    return ['id' => $id, 'name' => $name];
}

/**
 * @return list<array{id:int,name:string}>
 */
function xabia_avirato_subtypes_from_calendar(string $webcode): array
{
    $calendarSubtypes = xabia_avirato_get_calendar_subtypes($webcode);
    if ($calendarSubtypes === []) {
        return [];
    }
    $normalized = [];
    foreach ($calendarSubtypes as $subtype) {
        if (!is_array($subtype)) {
            continue;
        }
        $row = xabia_avirato_normalize_subtype_record($subtype);
        if ($row !== null) {
            $normalized[] = $row;
        }
    }
    if ($normalized !== []) {
        xabia_trace('[XABIA_AVIRATO] using avc_establishment subtypes', ['count' => count($normalized)]);
    }

    return $normalized;
}

/**
 * @return list<array{id:int,name:string}>
 */
function xabia_avirato_resolve_booking_subtypes(string $webcode, $apiPayload = null): array
{
    $fromCalendar = xabia_avirato_subtypes_from_calendar($webcode);
    if ($fromCalendar !== []) {
        return $fromCalendar;
    }

    $subtypes = xabia_avirato_extract_api_subtypes($apiPayload);
    if ($subtypes === []) {
        return [];
    }
    $normalized = [];
    foreach ($subtypes as $subtype) {
        if (!is_array($subtype)) {
            continue;
        }
        $row = xabia_avirato_normalize_subtype_record($subtype);
        if ($row !== null) {
            $normalized[] = $row;
        }
    }

    return $normalized;
}

function xabia_avirato_resolve_hotel_id(string $webcode, string $apiBase): int
{
    $webcode = trim($webcode);
    if ($webcode === '') {
        return 0;
    }
    $cacheKey = 'xabia_avirato_hotel_' . md5($webcode);
    $cached = get_transient($cacheKey);
    if (is_numeric($cached) && (int) $cached > 0) {
        return (int) $cached;
    }

    $info = xabia_avirato_api_json($apiBase . '/v1/access/info/' . rawurlencode($webcode), $webcode);
    $hotel = xabia_avirato_extract_api_data($info);
    $hotelId = (int) ($hotel['hotelId'] ?? 0);
    if ($hotelId > 0) {
        set_transient($cacheKey, $hotelId, DAY_IN_SECONDS);
    }

    return $hotelId;
}

/**
 * Detecta si el usuario pidió una casa concreta del catálogo Avirato.
 *
 * @return array{id:string,name:string}|null
 */
function xabia_avirato_room_match_stop_words(): array
{
    return [
        'reserva', 'reservar', 'reservas', 'proceso', 'disponible', 'disponibilidad',
        'booking', 'book', 'consultar', 'comprobar', 'mirar', 'informacion', 'información',
        'quiero', 'quisiera', 'gustaria', 'gustaría', 'algo', 'alguna', 'alguno',
        'novia', 'novio', 'pareja', 'familia', 'personas', 'adultos', 'ninos', 'niños',
        'casa', 'casas', 'alojamiento', 'estancia', 'habitacion', 'habitación',
        'semana', 'noches', 'finde', 'weekend', 'agosto', 'septiembre', 'marzo',
        'abril', 'mayo', 'junio', 'julio', 'enero', 'febrero', 'octubre', 'noviembre', 'diciembre',
        'finales', 'principios', 'mediados', 'quincena', 'primera', 'segunda', 'tercera',
    ];
}

function xabia_avirato_is_generic_room_label(string $name): bool
{
    $norm = xabia_avirato_normalize_text($name);
    if ($norm === '') {
        return true;
    }
    foreach ([
        'proceso de reserva', 'proceso reserva', 'como reservar', 'politica de reserva',
        'condiciones de reserva', 'informacion general', 'contacto', 'preguntas frecuentes',
    ] as $blocked) {
        if ($norm === $blocked || str_contains($norm, $blocked)) {
            return true;
        }
    }
    $words = xabia_avirato_room_significant_words($norm);
    if ($words === []) {
        return true;
    }
    $stop = xabia_avirato_room_match_stop_words();
    foreach ($words as $word) {
        if (!in_array($word, $stop, true)) {
            return false;
        }
    }

    return true;
}

function xabia_avirato_room_matches_catalog(string $name, string $webcode): bool
{
    $normName = xabia_avirato_normalize_text(xabia_avirato_display_room_name($name));
    if ($normName === '') {
        return false;
    }
    foreach (xabia_avirato_get_calendar_subtypes($webcode) as $subtype) {
        $catalogName = xabia_avirato_normalize_text(xabia_avirato_display_room_name((string) ($subtype['spaceSubtypeName'] ?? $subtype['name'] ?? '')));
        if ($catalogName === '') {
            continue;
        }
        if ($catalogName === $normName || str_contains($catalogName, $normName) || str_contains($normName, $catalogName)) {
            return true;
        }
        foreach (xabia_avirato_room_significant_words($catalogName) as $word) {
            if ((function_exists('mb_strlen') ? mb_strlen($word) : strlen($word)) >= 5 && $word === $normName) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Evita falsos positivos (p. ej. ente "Proceso de reserva" al decir "quiero reservar").
 *
 * @param array{id?: string, name?: string}|null $requested
 * @param array<int, array<string, mixed>> $rooms
 * @return array{id: string, name: string}|null
 */
function xabia_avirato_confirm_requested_room(?array $requested, array $rooms, string $webcode): ?array
{
    if (!is_array($requested)) {
        return null;
    }
    $name = trim((string) ($requested['name'] ?? ''));
    if ($name === '' || xabia_avirato_is_generic_room_label($name) || !xabia_avirato_room_matches_catalog($name, $webcode)) {
        return null;
    }
    if ($rooms === []) {
        return ['id' => (string) ($requested['id'] ?? ''), 'name' => $name];
    }
    $normRequested = xabia_avirato_normalize_text(xabia_avirato_display_room_name($name));
    $matches = 0;
    foreach ($rooms as $room) {
        if (!is_array($room)) {
            continue;
        }
        $normRoom = xabia_avirato_normalize_text(xabia_avirato_display_room_name(xabia_avirato_room_name($room)));
        if ($normRoom === $normRequested || str_contains($normRoom, $normRequested) || str_contains($normRequested, $normRoom)) {
            $matches++;
        }
    }
    if (count($rooms) > 1 && $matches !== 1) {
        return null;
    }

    return ['id' => (string) ($requested['id'] ?? ''), 'name' => $name];
}

function xabia_avirato_resolve_requested_room_from_calendar(string $webcode, array $settings, string $userText): ?array
{
    $needle = xabia_avirato_normalize_text($userText);
    if ($needle === '') {
        return null;
    }
    $subtypes = xabia_avirato_get_calendar_subtypes($webcode);
    if ($subtypes === []) {
        return null;
    }

    $inclusion = xabia_avirato_normalize_text((string) ($settings['inclusion_filter'] ?? ''));
    $exclusions = [];
    foreach (explode(',', (string) ($settings['exclusion_list'] ?? '')) as $word) {
        $word = xabia_avirato_normalize_text((string) $word);
        if ($word !== '') {
            $exclusions[] = $word;
        }
    }

    $candidates = [];
    foreach ($subtypes as $subtype) {
        $name = (string) ($subtype['spaceSubtypeName'] ?? $subtype['name'] ?? '');
        $roomId = (string) ($subtype['spaceSubtypeId'] ?? $subtype['id'] ?? '');
        if ($name === '' || $roomId === '' || preg_match('/^\d+$/', $roomId) !== 1) {
            continue;
        }
        $normalizedName = xabia_avirato_normalize_text($name);
        if ($inclusion !== '' && !str_contains($normalizedName, $inclusion)) {
            continue;
        }
        $blocked = false;
        foreach ($exclusions as $excluded) {
            if (str_contains($normalizedName, $excluded)) {
                $blocked = true;
                break;
            }
        }
        if ($blocked) {
            continue;
        }
        $words = xabia_avirato_room_significant_words($normalizedName);
        $stop = xabia_avirato_room_match_stop_words();
        foreach ($words as $word) {
            if (in_array($word, $stop, true)) {
                continue;
            }
            if (str_contains($needle, $word)) {
                $candidates[] = ['id' => $roomId, 'name' => $name, 'score' => 100 + strlen($word)];
                continue 2;
            }
        }
        if (str_contains($needle, $normalizedName)) {
            $candidates[] = ['id' => $roomId, 'name' => $name, 'score' => 80];
        }
    }

    if ($candidates === []) {
        return null;
    }
    usort($candidates, static function (array $a, array $b): int {
        return ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0));
    });

    $match = $candidates[0];
    if (function_exists('xabia_trace')) {
        xabia_trace('[XABIA_AVIRATO] requested room resolved from user text', [
            'name' => (string) $match['name'],
            'id'   => (string) $match['id'],
        ]);
    }

    return ['id' => (string) $match['id'], 'name' => (string) $match['name']];
}

/**
 * Reconoce alojamientos definidos como ENTE en el índice del core.
 *
 * @return array{id:string,name:string}|null
 */
function xabia_avirato_resolve_requested_room_from_knowledge(string $projectId, string $userText): ?array
{
    $projectId = trim($projectId);
    $needle = xabia_avirato_normalize_text($userText);
    if ($projectId === '' || $needle === '') {
        return null;
    }
    if (!class_exists('Xabia_DB', false)) {
        return null;
    }

    global $wpdb;
    $table = Xabia_DB::table('knowledge_vectors');
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return null;
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ente_id, meta_data FROM {$table} WHERE project_id = %s AND ente_id IS NOT NULL AND ente_id <> '' AND ente_id <> 'global' ORDER BY id DESC LIMIT 500",
        $projectId
    ), ARRAY_A);
    if (!is_array($rows) || $rows === []) {
        return null;
    }

    $candidates = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $enteId = trim((string) ($row['ente_id'] ?? ''));
        $display = '';
        $metaRaw = $row['meta_data'] ?? '';
        if (is_string($metaRaw) && $metaRaw !== '') {
            $meta = json_decode($metaRaw, true);
            if (is_array($meta)) {
                $display = trim((string) ($meta['__ente_display'] ?? $meta['ente_display'] ?? $meta['nombre'] ?? $meta['name'] ?? ''));
            }
        }
        $name = $display !== '' ? $display : str_replace('-', ' ', $enteId);
        if (xabia_avirato_is_generic_room_label($name)) {
            continue;
        }
        $normalized = xabia_avirato_normalize_text($name . ' ' . str_replace('-', ' ', $enteId));
        if ($normalized === '') {
            continue;
        }
        $words = xabia_avirato_room_significant_words($normalized);
        $stop = xabia_avirato_room_match_stop_words();
        foreach ($words as $word) {
            if (in_array($word, $stop, true)) {
                continue;
            }
            if (str_contains($needle, $word)) {
                $candidates[] = ['id' => '', 'name' => $name, 'score' => 90 + strlen($word)];
                continue 2;
            }
        }
        if (str_contains($needle, $normalized)) {
            $candidates[] = ['id' => '', 'name' => $name, 'score' => 70];
        }
    }

    if ($candidates === []) {
        return null;
    }
    usort($candidates, static function (array $a, array $b): int {
        return ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0));
    });

    $match = $candidates[0];
    if (function_exists('xabia_trace')) {
        xabia_trace('[XABIA_AVIRATO] requested room resolved from core ente', [
            'name' => (string) $match['name'],
        ]);
    }

    return ['id' => '', 'name' => (string) $match['name']];
}

function xabia_avirato_purge_action_cache(): void
{
    global $wpdb;
    $table = class_exists('Xabia_DB', false) ? Xabia_DB::table('response_cache') : $wpdb->prefix . 'xabia_response_cache';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        return;
    }
    
    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE source_type = %s", 'ROUTE_ACTION'));
}

add_action('admin_init', static function () {
    if (!current_user_can('manage_options')) {
        return;
    }
    $action = isset($_POST['xabia_action']) ? (string) wp_unslash($_POST['xabia_action']) : '';
    if ($action !== 'save_project' && $action !== 'save_avirato_settings') {
        return;
    }
    $settings = [
        'establishment_id' => sanitize_text_field((string) wp_unslash($_POST['xabia_avirato_establishment_id'] ?? '')),
        'availability_label' => sanitize_text_field((string) wp_unslash($_POST['xabia_avirato_availability_label'] ?? '')),
        'inclusion_filter' => sanitize_text_field((string) wp_unslash($_POST['xabia_avirato_inclusion_filter'] ?? '')),
        'exclusion_list'   => sanitize_textarea_field((string) wp_unslash($_POST['xabia_avirato_exclusion_list'] ?? '')),
        'engine_url'       => esc_url_raw((string) wp_unslash($_POST['xabia_avirato_engine_url'] ?? '')),
        'id_habitacion'    => sanitize_text_field((string) wp_unslash($_POST['xabia_avirato_id_habitacion'] ?? '')),
        'cod_promocional'  => sanitize_text_field((string) wp_unslash($_POST['xabia_avirato_cod_promocional'] ?? '')),
    ];
    update_option(XABIA_AVIRATO_OPTIONS_KEY, $settings, false);
    xabia_avirato_purge_action_cache();
}, 1);

add_filter('xabia_agent_admin_tabs', static function ($tabs) {
    if (!is_array($tabs)) {
        $tabs = [];
    }
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (!is_plugin_active('xabia-avirato/xabia-avirato.php')) {
        return $tabs;
    }
    $tabs[] = [
        'id'    => 'tab-avirato',
        'label' => __('Avirato', 'xabia-intelligence'),
    ];

    return $tabs;
}, 10, 1);

add_action('xabia_agent_admin_extra_tabs_content', static function ($edit_id) {
    unset($edit_id);
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (!is_plugin_active('xabia-avirato/xabia-avirato.php')) {
        return;
    }
    $settings = xabia_avirato_get_settings();
    ?>
    <div id="tab-avirato" class="xabia-tab-content">
        <h3 class="xabia-section-title"><?php echo esc_html__('Avirato', 'xabia-intelligence'); ?></h3>
        <p class="description"><?php echo esc_html__('Configuración de scraping público para disponibilidad hotelera.', 'xabia-intelligence'); ?></p>
        <p>
            <label for="xabia-avirato-establishment"><strong><?php echo esc_html__('ID de Establecimiento', 'xabia-intelligence'); ?></strong></label>
            <input type="text" id="xabia-avirato-establishment" name="xabia_avirato_establishment_id" class="widefat" value="<?php echo esc_attr($settings['establishment_id']); ?>" placeholder="Webcode del establecimiento">
        </p>
        <p>
            <label for="xabia-avirato-label"><strong><?php echo esc_html__('Nombre público del alojamiento', 'xabia-intelligence'); ?></strong></label>
            <input type="text" id="xabia-avirato-label" name="xabia_avirato_availability_label" class="widefat" value="<?php echo esc_attr($settings['availability_label']); ?>" placeholder="Nombre que debe usar el asistente al hablar de disponibilidad">
            <span class="description"><?php echo esc_html__('Si se deja vacío, se usa el filtro de inclusión.', 'xabia-intelligence'); ?></span>
        </p>
        <p>
            <label for="xabia-avirato-inclusion"><strong><?php echo esc_html__('Filtro de Inclusión', 'xabia-intelligence'); ?></strong></label>
            <input type="text" id="xabia-avirato-inclusion" name="xabia_avirato_inclusion_filter" class="widefat" value="<?php echo esc_attr($settings['inclusion_filter']); ?>" placeholder="Texto que deben contener los alojamientos incluidos">
        </p>
        <p>
            <label for="xabia-avirato-room-ids"><strong><?php echo esc_html__('IDs de habitación/casa (opcional)', 'xabia-intelligence'); ?></strong></label>
            <input type="text" id="xabia-avirato-room-ids" name="xabia_avirato_id_habitacion" class="widefat" value="<?php echo esc_attr($settings['id_habitacion']); ?>" placeholder="Se autocompleta desde Avirato Calendar usando el filtro de inclusión">
        </p>
        <p>
            <label for="xabia-avirato-exclusion"><strong><?php echo esc_html__('Lista de Exclusión', 'xabia-intelligence'); ?></strong></label>
            <textarea id="xabia-avirato-exclusion" name="xabia_avirato_exclusion_list" class="widefat" rows="4" placeholder="Palabras separadas por coma que se deben excluir"><?php echo esc_textarea($settings['exclusion_list']); ?></textarea>
        </p>
        <p>
            <label for="xabia-avirato-promo"><strong><?php echo esc_html__('Código promocional (opcional)', 'xabia-intelligence'); ?></strong></label>
            <input type="text" id="xabia-avirato-promo" name="xabia_avirato_cod_promocional" class="widefat" value="<?php echo esc_attr($settings['cod_promocional']); ?>">
        </p>
        <p>
            <label for="xabia-avirato-engine-url"><strong><?php echo esc_html__('URL del Motor', 'xabia-intelligence'); ?></strong></label>
            <input type="url" id="xabia-avirato-engine-url" name="xabia_avirato_engine_url" class="widefat" value="<?php echo esc_attr($settings['engine_url']); ?>" placeholder="https://booking.avirato.com/">
        </p>
        <p class="description"><?php echo esc_html__('La configuración Avirato se guarda al pulsar «Guardar agente».', 'xabia-intelligence'); ?></p>
    </div>
    <?php
}, 10, 1);

function xabia_avirato_normalize_text(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function xabia_avirato_detect_response_lang(string $userText, string $fallback = 'es'): string
{
    $t = xabia_avirato_normalize_text($userText);
    if ($t === '') {
        return 'es';
    }
    if (preg_match('/\b(hola|buenas|ten[eé]is|hay|casa|casas|libre|libres|disponibilidad|disponible|reserva|reservar|septiembre|setiembre|finales|principios)\b/u', $t) === 1) {
        return 'es';
    }
    if (preg_match('/\b(kaixo|agur|eskerrik|mesedez|etxe|etxeak|libreak|iraila|urria|abendua|asteburua|egun|gau)\b/u', $t) === 1) {
        return 'eu';
    }
    if (preg_match('/\b(hello|hi|availability|available|free|house|houses|booking|september|weekend)\b/u', $t) === 1) {
        return 'en';
    }
    if (preg_match('/\b(bonjour|disponibilit|disponible|maison|maisons|réserver|reserver|septembre)\b/u', $t) === 1) {
        return 'fr';
    }

    return 'es';
}

function xabia_avirato_i18n(string $key, string $lang, array $vars = []): string
{
    $lang = strtolower(substr(preg_replace('/[^a-zA-Z]/', '', $lang), 0, 2));
    $messages = [
        'technical_failure' => [
            'es' => 'Ahora mismo no he podido comprobar la disponibilidad en el motor de reservas. Puedes probar de nuevo en unos segundos o consultar el enlace de reserva.',
            'eu' => 'Une honetan ezin izan dut erreserba-motorrean erabilgarritasuna egiaztatu. Saiatu berriro segundo batzuk barru edo kontsultatu erreserba-esteka.',
            'en' => 'I could not check live availability in the booking engine right now. Please try again in a few seconds or use the booking link.',
            'fr' => 'Je n’ai pas pu vérifier la disponibilité en direct dans le moteur de réservation pour le moment. Réessayez dans quelques secondes ou utilisez le lien de réservation.',
        ],
        'requested_unavailable' => [
            'es' => '{room} está ocupado para {label}{range}.',
            'eu' => '{room} ez dago libre {label}{range}.',
            'en' => '{room} is not available for {label}{range}.',
            'fr' => '{room} n’est pas disponible pour {label}{range}.',
        ],
        'alternatives' => [
            'es' => 'En esas fechas tienes estas opciones disponibles:',
            'eu' => 'Data horietan aukera hauek daude libre:',
            'en' => 'These options are available for those dates:',
            'fr' => 'Ces options sont disponibles pour ces dates :',
        ],
        'next_available' => [
            'es' => '{room} podrías reservarlo a partir del {checkin} hasta el {checkout}.',
            'eu' => '{room} {checkin}tik {checkout}ra erreserbatu ahal izango zenuke.',
            'en' => 'You could book {room} from {checkin} to {checkout}.',
            'fr' => 'Vous pourriez réserver {room} du {checkin} au {checkout}.',
        ],
        'book_alternatives' => [
            'es' => 'Puedes reservar las alternativas aquí:',
            'eu' => 'Alternatibak hemen erreserba ditzakezu:',
            'en' => 'You can book the alternatives here:',
            'fr' => 'Vous pouvez réserver les alternatives ici :',
        ],
        'book_room' => [
            'es' => 'Puedes reservar {room} aquí:',
            'eu' => '{room} hemen erreserba dezakezu:',
            'en' => 'You can book {room} here:',
            'fr' => 'Vous pouvez réserver {room} ici :',
        ],
        'requested_empty' => [
            'es' => '{room} no me aparece disponible para {label}{range}.',
            'eu' => '{room} ez zait libre agertzen {label}{range}.',
            'en' => '{room} does not appear available for {label}{range}.',
            'fr' => '{room} ne semble pas disponible pour {label}{range}.',
        ],
        'empty' => [
            'es' => 'Para {label}{range} no me aparece disponibilidad en {place}.',
            'eu' => '{label}{range} ez zait erabilgarritasunik agertzen {place}n.',
            'en' => 'I do not see availability at {place} for {label}{range}.',
            'fr' => 'Je ne vois pas de disponibilité à {place} pour {label}{range}.',
        ],
        'requested_available' => [
            'es' => 'Sí, {room} está libre {range}.',
            'eu' => 'Bai, {room} libre dago {range}.',
            'en' => 'Yes, {room} is available {range}.',
            'fr' => 'Oui, {room} est disponible {range}.',
        ],
        'available_list' => [
            'es' => 'Sí, hay casas libres {range}:',
            'eu' => 'Bai, {range} etxe libre daude:',
            'en' => 'Yes, there are houses available {range}:',
            'fr' => 'Oui, des maisons sont disponibles {range} :',
        ],
        'more_info' => [
            'es' => '¿Te cuento más sobre alguna?',
            'eu' => 'Nahi duzu baten bati buruz gehiago kontatzea?',
            'en' => 'Would you like me to tell you more about any of them?',
            'fr' => 'Souhaitez-vous plus d’informations sur l’une d’elles ?',
        ],
        'book_here' => [
            'es' => '¿Te interesa alguna? Reserva aquí:',
            'eu' => 'Ba al duzu interesik? Hemen erreserba dezakezu:',
            'en' => 'Interested in one? Book here:',
            'fr' => 'Une vous intéresse ? Réservez ici :',
        ],
        'rooms_more' => [
            'es' => '…y {count} más.',
            'eu' => '…eta {count} gehiago.',
            'en' => '…and {count} more.',
            'fr' => '…et {count} de plus.',
        ],
        'price_note' => [
            'es' => 'Precios orientativos para {nights} noches.',
            'eu' => '{nights} gauetarako orientatzaile-prezioak.',
            'en' => 'Indicative prices for {nights} nights.',
            'fr' => 'Prix indicatifs pour {nights} nuits.',
        ],
    ];
    $template = $messages[$key][$lang] ?? $messages[$key]['es'] ?? '';
    foreach ($vars as $k => $v) {
        $template = str_replace('{' . $k . '}', (string) $v, $template);
    }

    return $template;
}

function xabia_avirato_extract_dates(string $text): array
{
    $normalized = xabia_avirato_normalize_text($text);
    
    $normalized = preg_replace('/\bqincena\b/u', 'quincena', $normalized) ?? $normalized;
    $normalized = preg_replace('/\bfines\s+de\b/u', 'finales de', $normalized) ?? $normalized;
    $today = new DateTimeImmutable('today');
    $months = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
        'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
    ];
    $monthPattern = implode('|', array_keys($months));

    
    if (str_contains($normalized, 'semana santa')) {
        return ['checkin' => '2026-03-29', 'checkout' => '2026-04-06', 'label' => 'LA SEMANA SANTA'];
    }
    if (str_contains($normalized, 'puente de mayo')) {
        return ['checkin' => '2026-05-01', 'checkout' => '2026-05-03', 'label' => 'EL PUENTE DE MAYO'];
    }

    
    if (str_contains($normalized, 'la semana que viene')) {
        $start = new DateTimeImmutable('monday next week');
        $end = new DateTimeImmutable('sunday next week');

        return ['checkin' => $start->format('Y-m-d'), 'checkout' => $end->format('Y-m-d'), 'label' => 'LA SEMANA QUE VIENE'];
    }
    if (str_contains($normalized, 'esta semana')) {
        $end = new DateTimeImmutable('sunday this week');

        return ['checkin' => $today->format('Y-m-d'), 'checkout' => $end->format('Y-m-d'), 'label' => 'ESTA SEMANA'];
    }
    if (str_contains($normalized, 'el mes que viene')) {
        $start = new DateTimeImmutable('first day of next month');
        $end = new DateTimeImmutable('last day of next month');

        return ['checkin' => $start->format('Y-m-d'), 'checkout' => $end->format('Y-m-d'), 'label' => 'EL MES QUE VIENE'];
    }
    if (str_contains($normalized, 'este fin de semana')) {
        $weekDay = (int) $today->format('N');
        $start = $weekDay <= 5 ? new DateTimeImmutable('friday this week') : new DateTimeImmutable('friday next week');
        $end = $weekDay <= 5 ? new DateTimeImmutable('sunday this week') : new DateTimeImmutable('sunday next week');

        return ['checkin' => $start->format('Y-m-d'), 'checkout' => $end->format('Y-m-d'), 'label' => 'ESTE FIN DE SEMANA'];
    }

    $resolveYearForMonth = static function (int $monthNum, DateTimeImmutable $ref): int {
        $currentMonth = (int) $ref->format('n');
        $currentYear = (int) $ref->format('Y');

        return $monthNum < $currentMonth ? $currentYear + 1 : $currentYear;
    };
    if (preg_match('/\bprimera quincena de (' . $monthPattern . ')\b/u', $normalized, $mq) === 1) {
        $monthNum = (int) ($months[$mq[1]] ?? 0);
        if ($monthNum > 0) {
            $year = $resolveYearForMonth($monthNum, $today);
            $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $monthNum));
            $end = new DateTimeImmutable(sprintf('%04d-%02d-15', $year, $monthNum));

            return ['checkin' => $start->format('Y-m-d'), 'checkout' => $end->format('Y-m-d'), 'label' => 'LA PRIMERA QUINCENA DE ' . strtoupper($mq[1])];
        }
    }
    if (preg_match('/\bsegunda quincena de (' . $monthPattern . ')\b/u', $normalized, $mq2) === 1) {
        $monthNum = (int) ($months[$mq2[1]] ?? 0);
        if ($monthNum > 0) {
            $year = $resolveYearForMonth($monthNum, $today);
            $start = new DateTimeImmutable(sprintf('%04d-%02d-16', $year, $monthNum));
            $end = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $monthNum)))->modify('last day of this month');

            return ['checkin' => $start->format('Y-m-d'), 'checkout' => $end->format('Y-m-d'), 'label' => 'LA SEGUNDA QUINCENA DE ' . strtoupper($mq2[1])];
        }
    }
    if (preg_match('/\bmediados de (' . $monthPattern . ')\b/u', $normalized, $mm) === 1) {
        $monthNum = (int) ($months[$mm[1]] ?? 0);
        if ($monthNum > 0) {
            $year = $resolveYearForMonth($monthNum, $today);
            $start = new DateTimeImmutable(sprintf('%04d-%02d-10', $year, $monthNum));
            $end = new DateTimeImmutable(sprintf('%04d-%02d-20', $year, $monthNum));

            return ['checkin' => $start->format('Y-m-d'), 'checkout' => $end->format('Y-m-d'), 'label' => 'MEDIADOS DE ' . strtoupper($mm[1])];
        }
    }

    
    if (preg_match('/\b(primera|segunda|tercera|cuarta|quinta)\s+semana\s+de\s+(' . $monthPattern . ')\b/u', $normalized, $wk) === 1) {
        $ord = $wk[1];
        $monthNum = (int) ($months[$wk[2]] ?? 0);
        if ($monthNum > 0) {
            $year = $resolveYearForMonth($monthNum, $today);
            $monthStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $monthNum));
            $lastDay = (int) $monthStart->modify('last day of this month')->format('d');
            $ranges = [
                'primera' => [1, 7],
                'segunda' => [8, 14],
                'tercera' => [15, 21],
                'cuarta'  => [22, 28],
            ];
            if ($ord === 'quinta') {
                $dStart = min(29, $lastDay);
                $dEnd = $lastDay;
            } else {
                [$dStart, $dEnd] = $ranges[$ord];
                $dEnd = min($dEnd, $lastDay);
                $dStart = min($dStart, $lastDay);
            }
            $start = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $monthNum, $dStart));
            $end = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $monthNum, $dEnd));

            return [
                'checkin'  => $start->format('Y-m-d'),
                'checkout' => $end->format('Y-m-d'),
                'label'    => 'LA ' . strtoupper($ord) . ' SEMANA DE ' . strtoupper($wk[2]),
            ];
        }
    }

    
    if (preg_match('/\b(?:en|durante|para)\s+(' . $monthPattern . ')\b/u', $normalized, $mo) === 1) {
        $monthNum = (int) ($months[$mo[1]] ?? 0);
        if ($monthNum > 0) {
            $year = $resolveYearForMonth($monthNum, $today);
            $weekend = xabia_avirato_first_weekend_in_month($year, $monthNum);

            return [
                'checkin'     => $weekend['checkin'],
                'checkout'    => $weekend['checkout'],
                'label'       => strtoupper($mo[1]),
                'sample_stay' => true,
                'month_num'   => $monthNum,
                'year'        => $year,
            ];
        }
    }

    
    if (preg_match('/\b(?:finales|final)\s+de\s+(' . $monthPattern . ')\b/u', $normalized, $mf) === 1) {
        $monthNum = (int) ($months[$mf[1]] ?? 0);
        if ($monthNum > 0) {
            $year = $resolveYearForMonth($monthNum, $today);
            $start = new DateTimeImmutable(sprintf('%04d-%02d-21', $year, $monthNum));
            $end = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $monthNum)))->modify('last day of this month');

            return [
                'checkin'  => $start->format('Y-m-d'),
                'checkout' => $end->format('Y-m-d'),
                'label'    => 'FINALES DE ' . strtoupper($mf[1]),
                'month_num' => $monthNum,
                'year'     => $year,
            ];
        }
    }

    
    if (preg_match('/\b(?:principios?|comienzos?|comienzo)\s+de\s+(' . $monthPattern . ')\b/u', $normalized, $mp) === 1) {
        $monthNum = (int) ($months[$mp[1]] ?? 0);
        if ($monthNum > 0) {
            $year = $resolveYearForMonth($monthNum, $today);
            $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $monthNum));
            $end = new DateTimeImmutable(sprintf('%04d-%02d-10', $year, $monthNum));

            return [
                'checkin'  => $start->format('Y-m-d'),
                'checkout' => $end->format('Y-m-d'),
                'label'    => 'PRINCIPIOS DE ' . strtoupper($mp[1]),
            ];
        }
    }

    
    if (preg_match('/\ba\s+(?:partir|prtir)\s+del\s+(\d{1,2})\s+de\s+(' . $monthPattern . ')\b/u', $normalized, $map) === 1) {
        $monthNum = (int) ($months[$map[2]] ?? 0);
        $day = (int) $map[1];
        if ($monthNum > 0 && $day >= 1 && $day <= 31) {
            $year = $resolveYearForMonth($monthNum, $today);
            $start = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $monthNum, $day));
            $end = $start->modify('last day of this month');

            return [
                'checkin'  => $start->format('Y-m-d'),
                'checkout' => $end->format('Y-m-d'),
                'label'    => 'A PARTIR DEL ' . sprintf('%02d', $day) . ' DE ' . strtoupper($map[2]),
            ];
        }
    }

    $dates = [];
    if (preg_match_all('/\b(\d{4})-(\d{2})-(\d{2})\b/', $text, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $dates[] = sprintf('%04d-%02d-%02d', (int) $hit[1], (int) $hit[2], (int) $hit[3]);
        }
    }
    if (count($dates) < 2 && preg_match_all('/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\b/', $text, $m2, PREG_SET_ORDER)) {
        foreach ($m2 as $hit) {
            $dates[] = sprintf('%04d-%02d-%02d', (int) $hit[3], (int) $hit[2], (int) $hit[1]);
        }
    }
    if (count($dates) < 2) {
        if (preg_match('/\b(?:del\s+)?(\d{1,2})\s*(?:al|hasta|-|a)\s*(\d{1,2})\s+de\s+(' . $monthPattern . ')\b/u', $normalized, $rm) === 1) {
            $monthNum = (int) ($months[$rm[3]] ?? 0);
            $year = $monthNum > 0 ? $resolveYearForMonth($monthNum, $today) : (int) (new DateTimeImmutable('now'))->format('Y');
            if ($monthNum > 0) {
                $dates[] = sprintf('%04d-%02d-%02d', $year, $monthNum, (int) $rm[1]);
                $dates[] = sprintf('%04d-%02d-%02d', $year, $monthNum, (int) $rm[2]);
            }
        } elseif (preg_match_all('/\b(\d{1,2})\s*de\s*(' . $monthPattern . ')(?:\s*de\s*(\d{4}))?\b/u', $normalized, $dm, PREG_SET_ORDER)) {
            foreach ($dm as $hit) {
                $monthNum = (int) ($months[$hit[2]] ?? 0);
                if ($monthNum < 1) {
                    continue;
                }
                $year = !empty($hit[3]) ? (int) $hit[3] : $resolveYearForMonth($monthNum, $today);
                $dates[] = sprintf('%04d-%02d-%02d', $year, $monthNum, (int) $hit[1]);
            }
        }
    }
    $checkin = $dates[0] ?? $today->modify('+1 day')->format('Y-m-d');
    $checkout = $dates[1] ?? (new DateTimeImmutable($checkin))->modify('+2 days')->format('Y-m-d');

    return xabia_avirato_enforce_min_stay(['checkin' => $checkin, 'checkout' => $checkout, 'label' => 'ESTAS FECHAS']);
}

/**
 * Primer fin de semana (vie–dom, 2 noches) dentro del mes; referencia para consultas genéricas tipo "en marzo".
 *
 * @return array{checkin: string, checkout: string}
 */
function xabia_avirato_first_weekend_in_month(int $year, int $monthNum): array
{
    $monthStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $monthNum));
    $monthEnd = $monthStart->modify('last day of this month');
    $cursor = $monthStart;
    while ((int) $cursor->format('N') !== 5 && $cursor <= $monthEnd) {
        $cursor = $cursor->modify('+1 day');
    }
    if ($cursor > $monthEnd) {
        $checkin = $monthStart->format('Y-m-d');

        return ['checkin' => $checkin, 'checkout' => $monthStart->modify('+2 days')->format('Y-m-d')];
    }

    return [
        'checkin'  => $cursor->format('Y-m-d'),
        'checkout' => $cursor->modify('+2 days')->format('Y-m-d'),
    ];
}

function xabia_avirato_stay_nights(array $dates): int
{
    $ci = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($dates['checkin'] ?? ''));
    $co = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($dates['checkout'] ?? ''));
    if (!$ci instanceof DateTimeImmutable || !$co instanceof DateTimeImmutable || $co <= $ci) {
        return 0;
    }

    return max(1, (int) $ci->diff($co)->format('%a'));
}

function xabia_avirato_month_name(int $monthNum, string $lang = 'es'): string
{
    $names = [
        'es' => [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'],
        'eu' => [1 => 'urtarrila', 2 => 'otsaila', 3 => 'martxoa', 4 => 'apirila', 5 => 'maiatza', 6 => 'ekaina', 7 => 'uztaila', 8 => 'abuztua', 9 => 'iraila', 10 => 'urria', 11 => 'azaroa', 12 => 'abendua'],
        'en' => [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'],
        'fr' => [1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre'],
    ];
    $lang = strtolower(substr(preg_replace('/[^a-zA-Z]/', '', $lang), 0, 2));
    $table = $names[$lang] ?? $names['es'];

    return $table[$monthNum] ?? $names['es'][$monthNum] ?? '';
}

function xabia_avirato_format_availability_range(array $dates, string $lang = 'es'): string
{
    $ci = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($dates['checkin'] ?? ''));
    $co = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($dates['checkout'] ?? ''));
    if (!$ci instanceof DateTimeImmutable || !$co instanceof DateTimeImmutable) {
        return 'estas fechas';
    }
    $nights = xabia_avirato_stay_nights($dates);
    $lang = strtolower(substr(preg_replace('/[^a-zA-Z]/', '', $lang), 0, 2)) ?: 'es';
    $label = trim((string) ($dates['label'] ?? ''));

    if (!empty($dates['sample_stay'])) {
        $monthNum = (int) ($dates['month_num'] ?? (int) $ci->format('n'));
        $monthName = xabia_avirato_month_name($monthNum, $lang);
        $year = (int) ($dates['year'] ?? (int) $ci->format('Y'));
        $d1 = (int) $ci->format('j');
        $d2 = (int) $co->format('j');
        if ($lang === 'eu') {
            return $year . 'ko ' . $monthName . ' (asteburua: ' . $d1 . '–' . $d2 . ', ' . $nights . ' gau)';
        }
        if ($lang === 'en') {
            return $monthName . ' ' . $year . ' (weekend ' . $d1 . '–' . $d2 . ', ' . $nights . ' nights)';
        }
        if ($lang === 'fr') {
            return $monthName . ' ' . $year . ' (week-end du ' . $d1 . ' au ' . $d2 . ', ' . $nights . ' nuits)';
        }

        return $monthName . ' de ' . $year . ' (fin de semana del ' . $d1 . ' al ' . $d2 . ', ' . $nights . ' noches)';
    }

    if ($label !== '' && $label !== 'ESTAS FECHAS') {
        $d1 = (int) $ci->format('j');
        $d2 = (int) $co->format('j');
        $labelLower = strtolower($label);
        $year = (int) ($dates['year'] ?? (int) $ci->format('Y'));
        if ($lang === 'eu') {
            return $year . 'ko ' . $labelLower . ' (' . $d1 . '–' . $d2 . ')';
        }
        if ($lang === 'en') {
            return $labelLower . ' ' . $year . ' (' . $d1 . '–' . $d2 . ')';
        }
        if ($lang === 'fr') {
            return $labelLower . ' ' . $year . ' (du ' . $d1 . ' au ' . $d2 . ')';
        }

        return 'a ' . $labelLower . ' de ' . $year . ' (del ' . $d1 . ' al ' . $d2 . ')';
    }

    if ($ci->format('Y-m') === $co->format('Y-m')) {
        $monthNum = (int) $ci->format('n');
        $monthName = xabia_avirato_month_name($monthNum, $lang);
        $year = (int) $ci->format('Y');
        $d1 = (int) $ci->format('j');
        $d2 = (int) $co->format('j');
        if ($lang === 'eu') {
            return $year . 'ko ' . $monthName . 'aren ' . $d1 . 'etik ' . $d2 . 'ra';
        }
        if ($lang === 'en') {
            return $d1 . '–' . $d2 . ' ' . $monthName . ' ' . $year;
        }
        if ($lang === 'fr') {
            return 'du ' . $d1 . ' au ' . $d2 . ' ' . $monthName . ' ' . $year;
        }

        return 'del ' . $d1 . ' al ' . $d2 . ' de ' . $monthName . ' de ' . $year;
    }

    return 'del ' . xabia_avirato_to_booking_date((string) $dates['checkin']) . ' al ' . xabia_avirato_to_booking_date((string) $dates['checkout']);
}

function xabia_avirato_display_room_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return $name;
    }
    $name = preg_replace('/^ea\s+astei\s*[-–—]\s*/iu', '', $name) ?? $name;
    $name = preg_replace('/^ea\s+astei\s+/iu', '', $name) ?? $name;

    return trim($name);
}

function xabia_avirato_room_price_value(array $room): ?float
{
    $priceRaw = $room['precio'] ?? ($room['price'] ?? ($room['importe'] ?? null));
    if (!is_numeric($priceRaw)) {
        return null;
    }

    return (float) $priceRaw;
}

/**
 * Avirato en Astei exige mínimo 2 noches; evita rangos de 1 noche que devuelven HTTP 500.
 *
 * @param array{checkin: string, checkout: string, label?: string} $dates
 * @return array{checkin: string, checkout: string, label?: string}
 */
function xabia_avirato_enforce_min_stay(array $dates, int $minNights = 2): array
{
    $ci = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($dates['checkin'] ?? ''));
    $co = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($dates['checkout'] ?? ''));
    if (!$ci instanceof DateTimeImmutable || !$co instanceof DateTimeImmutable) {
        return $dates;
    }
    if ($co <= $ci) {
        $dates['checkout'] = $ci->modify('+' . $minNights . ' days')->format('Y-m-d');

        return $dates;
    }
    $nights = (int) $ci->diff($co)->format('%a');
    if ($nights < $minNights) {
        $dates['checkout'] = $ci->modify('+' . $minNights . ' days')->format('Y-m-d');
    }

    return $dates;
}

/**
 * Ajusta checkout si el usuario indica duración explícita (N noches / N días).
 *
 * @param array{checkin: string, checkout: string, label?: string} $dates
 * @return array{checkin: string, checkout: string, label?: string}
 */
function xabia_avirato_refine_dates_with_duration(string $normalized, array $dates): array
{
    $normalized = preg_replace('/d[ií]a+s?/u', 'dias', $normalized) ?? $normalized;
    $ci = DateTimeImmutable::createFromFormat('Y-m-d', $dates['checkin'] ?? '');
    if (!$ci instanceof DateTimeImmutable) {
        return $dates;
    }
    $nNoches = null;
    $nDias = null;
    if (preg_match('/\b(\d+)\s*noches?\b/u', $normalized, $m) === 1) {
        $nNoches = max(1, min(60, (int) $m[1]));
    }
    if (preg_match('/\b(\d+)\s*d[ií]as?\b/u', $normalized, $m2) === 1) {
        $nDias = max(1, min(60, (int) $m2[1]));
    }
    if ($nNoches !== null) {
        $dates['checkout'] = $ci->modify('+' . $nNoches . ' days')->format('Y-m-d');

        return xabia_avirato_enforce_min_stay($dates);
    }
    if ($nDias !== null) {
        $dates['checkout'] = $ci->modify('+' . $nDias . ' days')->format('Y-m-d');

        return xabia_avirato_enforce_min_stay($dates);
    }

    return xabia_avirato_enforce_min_stay($dates);
}

function xabia_avirato_to_booking_date(string $date): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if ($dt === false) {
        return $date;
    }

    return $dt->format('d-m-Y');
}

function xabia_avirato_to_api_date(string $date): string
{
    $date = trim($date);
    if ($date === '') {
        return $date;
    }
    foreach (['Y-m-d', 'd-m-Y', 'd/m/Y'] as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $date);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->format('Y-m-d');
        }
    }

    return $date;
}

function xabia_avirato_rate_is_applicable(array $rate): bool
{
    if (!array_key_exists('applicable', $rate)) {
        return true;
    }
    $applicable = $rate['applicable'];

    return $applicable === true || $applicable === 1 || $applicable === '1' || $applicable === 'true';
}

function xabia_avirato_room_id(array $room): string
{
    foreach (['idSubtipoEspacio', 'spaceSubtypeId', 'id_habitacion', 'idHabitacion', 'id'] as $key) {
        if (isset($room[$key]) && preg_match('/^\d+$/', (string) $room[$key]) === 1) {
            return (string) $room[$key];
        }
    }

    return '';
}

function xabia_avirato_room_name(array $room): string
{
    return trim((string) ($room['subtipoEspacio'] ?? ($room['nombreEspacio'] ?? ($room['spaceSubtypeName'] ?? ($room['name'] ?? '')))));
}

function xabia_avirato_room_is_available(array $room): bool
{
    $negativeKeys = ['noDisponible', 'notAvailable', 'blocked', 'ocupado', 'soldOut', 'closed'];
    foreach ($negativeKeys as $key) {
        if (isset($room[$key]) && in_array(strtolower((string) $room[$key]), ['1', 'true', 'yes', 'si', 'sí'], true)) {
            return false;
        }
    }

    foreach (['originalFreeRooms', 'freeRooms', 'numRooms', 'availableRooms', 'availability', 'disponibles'] as $key) {
        if (isset($room[$key]) && is_numeric($room[$key])) {
            return ((int) $room[$key]) > 0;
        }
    }

    foreach (['available', 'disponible'] as $key) {
        if (isset($room[$key])) {
            return in_array(strtolower((string) $room[$key]), ['1', 'true', 'yes', 'si', 'sí'], true);
        }
    }

    
    return false;
}

function xabia_avirato_booking_url_for_room_ids(string $base, array $parts, array $rooms): string
{
    $ids = [];
    foreach ($rooms as $room) {
        if (!is_array($room)) {
            continue;
        }
        $id = xabia_avirato_room_id($room);
        if ($id !== '') {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));
    if ($ids !== []) {
        $parts['id_habitacion'] = implode('-', $ids);
    } else {
        unset($parts['id_habitacion']);
    }

    return rtrim($base, '/') . '/?' . http_build_query($parts, '', '&', PHP_QUERY_RFC3986);
}

function xabia_avirato_booking_url_parts_for_new_engine(array $parts, string $checkin, string $checkout): array
{
    $parts['startDate'] = $checkin;
    $parts['endDate'] = $checkout;
    if (empty($parts['adults'])) {
        $parts['adults'] = 1;
    }
    if (!isset($parts['children'])) {
        $parts['children'] = 0;
    }
    if (empty($parts['rooms'])) {
        $parts['rooms'] = 1;
    }

    return $parts;
}

function xabia_avirato_extract_rooms_from_html(string $html): array
{
    $candidates = [];
    if (preg_match('/:rooms_from_server\s*=\s*(["\'])(.*?)\1/s', $html, $m) === 1) {
        $candidates[] = (string) $m[2];
    }
    if (preg_match('/rooms_from_server\s*=\s*(["\'])(.*?)\1/s', $html, $m2) === 1) {
        $candidates[] = (string) $m2[2];
    }
    if (preg_match('/:rooms_from_server\s*=\s*&quot;(.*?)&quot;/s', $html, $m3) === 1) {
        $candidates[] = (string) $m3[1];
    }
    $decodedHtml = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($decodedHtml !== $html && preg_match('/:rooms_from_server\s*=\s*(["\'])(.*?)\1/s', $decodedHtml, $m4) === 1) {
        $candidates[] = (string) $m4[2];
    }

    foreach (array_values(array_unique($candidates)) as $candidate) {
        $json = html_entity_decode((string) $candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rooms = json_decode($json, true);
        if (is_array($rooms)) {
            return array_values(array_filter($rooms, 'is_array'));
        }
    }

    return [];
}

function xabia_avirato_api_request_headers(string $webcode): array
{
    return [
        'Accept'     => 'application/json',
        'X-Web-Code' => $webcode,
    ];
}

function xabia_avirato_api_json(string $url, string $webcode, array $opts = []): ?array
{
    $retries = max(1, (int) ($opts['retries'] ?? 2));
    $preferCurl = !empty($opts['prefer_curl']);
    $wpOnly = !empty($opts['wp_only']);
    $timeout = max(10, (int) ($opts['timeout'] ?? 25));
    $cacheKey = 'xabia_avirato_api_' . md5($url . '|' . $webcode);
    if (empty($opts['no_cache'])) {
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
    }
    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        $decoded = xabia_avirato_api_json_once($url, $webcode, $preferCurl, $timeout, $wpOnly);
        if (is_array($decoded)) {
            if (empty($opts['no_cache'])) {
                set_transient($cacheKey, $decoded, 5 * MINUTE_IN_SECONDS);
            }

            return $decoded;
        }
        if ($attempt < $retries) {
            usleep(800000);
        }
    }

    return null;
}

function xabia_avirato_api_json_once(string $url, string $webcode, bool $preferCurl = false, int $timeout = 25, bool $wpOnly = false): ?array
{
    if ($preferCurl && function_exists('curl_init')) {
        $decoded = xabia_avirato_api_json_curl($url, $webcode, $timeout);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    $headers = xabia_avirato_api_request_headers($webcode);
    $httpCode = 0;
    if (function_exists('wp_remote_get')) {
        $response = wp_remote_get($url, [
            'timeout'            => $timeout,
            'headers'            => $headers,
            'reject_unsafe_urls' => false,
            'sslverify'          => true,
        ]);
        if (!is_wp_error($response)) {
            $httpCode = (int) wp_remote_retrieve_response_code($response);
            $raw = (string) wp_remote_retrieve_body($response);
            if ($raw !== '' && $httpCode >= 200 && $httpCode < 300) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
                xabia_trace('[XABIA_AVIRATO] bookingapi WP HTTP JSON decode failed', ['url' => $url, 'body_len' => strlen($raw)]);
            } else {
                error_log('[XABIA_AVIRATO] bookingapi WP HTTP request failed http_code=' . $httpCode . ' body=' . substr($raw, 0, 280) . ' url=' . $url);
                xabia_trace('[XABIA_AVIRATO] bookingapi WP HTTP request failed', [
                    'url'       => $url,
                    'http_code' => $httpCode,
                    'body_len'  => strlen($raw),
                    'body_head' => substr($raw, 0, 180),
                ]);
            }
        } else {
            xabia_trace('[XABIA_AVIRATO] bookingapi WP HTTP error', ['url' => $url, 'error' => $response->get_error_message()]);
        }
    }

    if ($wpOnly || $httpCode >= 400) {
        return null;
    }

    if (!function_exists('curl_init')) {
        return null;
    }

    return xabia_avirato_api_json_curl($url, $webcode, $timeout);
}

function xabia_avirato_api_header_lines(string $webcode): array
{
    $headers = xabia_avirato_api_request_headers($webcode);

    return array_map(
        static function (string $key, string $value): string {
            return $key . ': ' . $value;
        },
        array_keys($headers),
        array_values($headers)
    );
}

function xabia_avirato_api_json_curl(string $url, string $webcode, int $timeout = 25): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => xabia_avirato_api_header_lines($webcode),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_ENCODING       => '',
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if (!is_string($raw) || $raw === '' || $code < 200 || $code >= 300) {
        error_log('[XABIA_AVIRATO] bookingapi curl request failed http_code=' . $code . ' body=' . (is_string($raw) ? substr($raw, 0, 280) : '') . ' curl_error=' . $curlError . ' url=' . $url);
        xabia_trace('[XABIA_AVIRATO] bookingapi request failed', [
            'url'        => $url,
            'http_code'  => $code,
            'body_len'   => is_string($raw) ? strlen($raw) : 0,
            'curl_error' => $curlError,
            'body_head'  => is_string($raw) ? substr($raw, 0, 180) : '',
        ]);

        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        xabia_trace('[XABIA_AVIRATO] bookingapi JSON decode failed', ['url' => $url, 'body_len' => strlen($raw)]);

        return null;
    }

    return $decoded;
}

/**
 * @param array<int, string> $urls
 * @return array<string, ?array>
 */
function xabia_avirato_api_json_multi(array $urls, string $webcode): array
{
    $results = [];
    foreach ($urls as $url) {
        $results[$url] = null;
    }
    if ($urls === []) {
        return $results;
    }

    // En hosting compartido wp_remote_get suelto funciona mejor que ráfagas curl_multi.
    foreach ($urls as $index => $url) {
        if ($index > 0) {
            usleep(1200000);
        }
        $results[$url] = xabia_avirato_api_json($url, $webcode, ['retries' => 1, 'timeout' => 25, 'wp_only' => true]);
    }

    return $results;
}

function xabia_avirato_extract_api_data($decoded): array
{
    if (!is_array($decoded)) {
        return [];
    }
    $data = $decoded['data'] ?? $decoded;

    return is_array($data) ? $data : [];
}

function xabia_avirato_extract_api_subtypes($decoded): array
{
    $data = xabia_avirato_extract_api_data($decoded);
    if ($data === []) {
        return [];
    }
    if (isset($data[0]) && is_array($data[0])) {
        return array_values(array_filter($data, static function ($item): bool {
            return is_array($item) && isset($item['id']);
        }));
    }
    if (isset($data['subtypes']) && is_array($data['subtypes'])) {
        return array_values(array_filter($data['subtypes'], static function ($item): bool {
            return is_array($item) && isset($item['id']);
        }));
    }
    if (isset($data['id']) && is_array($data)) {
        return [$data];
    }

    return [];
}

function xabia_avirato_scrape_rooms_bookingapi(string $checkin, string $checkout, array $settings, array $parts, string $base): array
{
    $checkin = xabia_avirato_to_api_date($checkin);
    $checkout = xabia_avirato_to_api_date($checkout);
    $stay = xabia_avirato_enforce_min_stay(['checkin' => $checkin, 'checkout' => $checkout]);
    $checkin = (string) $stay['checkin'];
    $checkout = (string) $stay['checkout'];
    error_log('[XABIA_AVIRATO] bookingapi stay range ' . $checkin . ' -> ' . $checkout);
    $webcode = trim((string) ($settings['establishment_id'] ?? ''));
    if ($webcode === '') {
        return ['ok' => false, 'rooms' => [], 'message' => 'Sin webcode para bookingapi.', 'source' => 'bookingapi'];
    }
    $apiBase = 'https://bookingapi.avirato.com';
    $hotelId = xabia_avirato_resolve_hotel_id($webcode, $apiBase);
    if ($hotelId <= 0) {
        return ['ok' => false, 'rooms' => [], 'message' => 'bookingapi no devolvió hotelId.', 'source' => 'bookingapi'];
    }

    $subtypes = xabia_avirato_resolve_booking_subtypes($webcode);
    if ($subtypes === []) {
        usleep(200000);
        $subtypesUrl = $apiBase . '/v1/spaces/subtypes?' . http_build_query(['hotelId' => $hotelId], '', '&', PHP_QUERY_RFC3986);
        $subtypesPayload = xabia_avirato_api_json($subtypesUrl, $webcode, ['retries' => 2, 'timeout' => 45]);
        $subtypes = xabia_avirato_resolve_booking_subtypes($webcode, $subtypesPayload);
    }
    if ($subtypes === []) {
        xabia_trace('[XABIA_AVIRATO] bookingapi subtypes empty', [
            'hotelId'           => $hotelId,
            'calendar_fallback' => count(xabia_avirato_get_calendar_subtypes($webcode)),
        ]);

        return ['ok' => false, 'rooms' => [], 'message' => 'bookingapi no devolvió subtipos.', 'source' => 'bookingapi'];
    }

    $allowedIds = [];
    $idHabitacion = trim((string) ($settings['id_habitacion'] ?? ''));
    if ($idHabitacion !== '') {
        foreach (preg_split('/[^0-9]+/', $idHabitacion, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $id) {
            $allowedIds[] = (int) $id;
        }
        $allowedIds = array_values(array_unique(array_filter($allowedIds)));
    }

    $targets = [];
    foreach ($subtypes as $subtype) {
        $sid = (int) ($subtype['id'] ?? 0);
        if ($sid <= 0 || ($allowedIds !== [] && !in_array($sid, $allowedIds, true))) {
            continue;
        }
        $targets[$sid] = $subtype;
    }

    $rateUrls = [];
    foreach (array_keys($targets) as $sid) {
        $rateUrls[$sid] = $apiBase . '/v1/rates/for-room?' . http_build_query([
            'hotelId'        => $hotelId,
            'spaceSubtypeId' => $sid,
            'checkInDate'    => $checkin,
            'checkOutDate'   => $checkout,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    $rateAttempts = count($rateUrls);
    xabia_trace('[XABIA_AVIRATO] bookingapi rate targets', [
        'targets'       => count($targets),
        'rate_attempts' => $rateAttempts,
        'checkin'       => $checkin,
        'checkout'      => $checkout,
    ]);
    $rateFailures = 0;
    $rateUnavailable = 0;
    $rooms = [];
    $ratePayloads = xabia_avirato_api_json_multi(array_values($rateUrls), $webcode);
    $ratePayloadsBySid = [];
    foreach ($rateUrls as $sid => $rateUrl) {
        $ratePayloadsBySid[$sid] = $ratePayloads[$rateUrl] ?? null;
    }

    foreach ($targets as $sid => $subtype) {
        $ratesPayload = $ratePayloadsBySid[$sid] ?? null;
        if (!is_array($ratesPayload)) {
            $rateFailures++;
            xabia_trace('[XABIA_AVIRATO] bookingapi rates request failed', [
                'spaceSubtypeId' => $sid,
                'url'            => $rateUrls[$sid] ?? '',
            ]);
            continue;
        }
        $ratesData = xabia_avirato_extract_api_data($ratesPayload);
        $rates = isset($ratesData['rates']) && is_array($ratesData['rates']) ? $ratesData['rates'] : [];
        $applicable = [];
        foreach ($rates as $rate) {
            if (is_array($rate) && xabia_avirato_rate_is_applicable($rate)) {
                $applicable[] = $rate;
            }
        }
        if ($applicable === []) {
            $rateUnavailable++;
            continue;
        }
        $best = $applicable[0];
        foreach ($applicable as $rate) {
            if (isset($rate['minPrice'], $best['minPrice']) && is_numeric($rate['minPrice']) && is_numeric($best['minPrice']) && (float) $rate['minPrice'] < (float) $best['minPrice']) {
                $best = $rate;
            }
        }
        $name = trim((string) ($subtype['name'] ?? ('Habitación ' . $sid)));
        $rooms[] = [
            'idSubtipoEspacio'  => (string) $sid,
            'spaceSubtypeId'    => (string) $sid,
            'subtipoEspacio'    => $name,
            'nombreEspacio'     => $name,
            'precio'            => $best['minPrice'] ?? null,
            'currency'          => (string) ($best['currency'] ?? 'EUR'),
            'originalFreeRooms' => 1,
            'freeRooms'         => 1,
            'available'         => true,
        ];
    }

    if ($rooms === [] && $rateAttempts > 0 && $rateFailures === $rateAttempts) {
        xabia_trace('[XABIA_AVIRATO] bookingapi all rates requests failed', [
            'rate_attempts' => $rateAttempts,
            'checkin'       => $checkin,
            'checkout'      => $checkout,
            'webcode'       => $webcode,
        ]);

        $fallbackRooms = [];
        foreach ($targets as $sid => $subtype) {
            $name = trim((string) ($subtype['name'] ?? ('Habitación ' . $sid)));
            $fallbackRooms[] = [
                'idSubtipoEspacio'  => (string) $sid,
                'spaceSubtypeId'    => (string) $sid,
                'subtipoEspacio'    => $name,
                'nombreEspacio'     => $name,
                'precio'            => null,
                'price_unknown'     => true,
                'originalFreeRooms' => 0,
                'freeRooms'         => 0,
                'available'         => null,
            ];
        }
        if ($fallbackRooms !== []) {
            $filterSettings = $settings;
            if ($allowedIds !== []) {
                $filterSettings['inclusion_filter'] = '';
            }
            $filteredFallback = xabia_avirato_filter_rooms($fallbackRooms, $filterSettings);
            $newEngineParts = xabia_avirato_booking_url_parts_for_new_engine($parts, $checkin, $checkout);
            $bookingUrl = xabia_avirato_booking_url_for_room_ids($base, $newEngineParts, $filteredFallback);

            return [
                'ok'             => true,
                'rooms'          => $filteredFallback,
                'raw_rooms'      => $fallbackRooms,
                'message'        => 'bookingapi no devolvió tarifas; listado desde calendario local.',
                'raw_room_count' => count($subtypes),
                'source'         => 'bookingapi+calendar',
                'booking_url'    => $bookingUrl,
                'rates_failed'   => true,
            ];
        }

        return [
            'ok'             => false,
            'rooms'          => [],
            'message'        => 'bookingapi no devolvió tarifas aplicables.',
            'raw_room_count' => count($subtypes),
            'source'         => 'bookingapi',
        ];
    }

    // Hay tarifas reales pero ninguna pasa filtros de exclusión/nombre.
    if ($rooms === [] && $rateAttempts > 0 && ($rateFailures + $rateUnavailable) === $rateAttempts) {
        return [
            'ok'              => true,
            'rooms'           => [],
            'raw_rooms'       => [],
            'raw_room_count'  => count($subtypes),
            'filter_active'   => trim((string) ($settings['inclusion_filter'] ?? '')) !== '' || trim((string) ($settings['exclusion_list'] ?? '')) !== '',
            'requested_room'  => '',
            'booking_url'     => '',
            'raw_booking_url' => $base . '/?' . http_build_query(xabia_avirato_booking_url_parts_for_new_engine($parts, $checkin, $checkout), '', '&', PHP_QUERY_RFC3986),
            'booking_base'    => $base,
            'booking_parts'   => xabia_avirato_booking_url_parts_for_new_engine($parts, $checkin, $checkout),
            'message'         => '',
            'source'          => 'bookingapi',
        ];
    }

    $filterSettings = $settings;
    if ($allowedIds !== []) {
        // Una lista explícita de habitaciones ya es el filtro canónico del motor.
        $filterSettings['inclusion_filter'] = '';
    }
    $filteredRooms = xabia_avirato_filter_rooms($rooms, $filterSettings);
    $newEngineParts = xabia_avirato_booking_url_parts_for_new_engine($parts, $checkin, $checkout);
    $bookingUrl = xabia_avirato_booking_url_for_room_ids($base, $newEngineParts, $filteredRooms);
    xabia_trace('[XABIA_AVIRATO] bookingapi rates summary', [
        'rate_attempts'    => $rateAttempts,
        'rate_failures'  => $rateFailures,
        'rate_unavailable'=> $rateUnavailable,
        'rooms_found'    => count($rooms),
        'rooms_filtered' => count($filteredRooms),
        'checkin'        => $checkin,
        'checkout'       => $checkout,
    ]);

    return [
        'ok'              => true,
        'rooms'           => $filteredRooms,
        'raw_rooms'       => $rooms,
        'raw_room_count'  => count($subtypes),
        'filter_active'   => trim((string) ($filterSettings['inclusion_filter'] ?? '')) !== '' || trim((string) ($filterSettings['exclusion_list'] ?? '')) !== '',
        'requested_room'  => '',
        'booking_url'     => $filteredRooms !== [] ? $bookingUrl : '',
        'raw_booking_url' => $base . '/?' . http_build_query($newEngineParts, '', '&', PHP_QUERY_RFC3986),
        'booking_base'    => $base,
        'booking_parts'   => $newEngineParts,
        'message'         => '',
        'source'          => 'bookingapi',
    ];
}

function xabia_avirato_cache_identity(): string
{
    if (!session_id() && !headers_sent()) {
        session_start();
    }
    $sid = session_id();
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

    return md5(($sid !== '' ? $sid : 'no-session') . '|' . $ip);
}

function xabia_avirato_should_bypass_cache(string $text): bool
{
    $t = xabia_avirato_normalize_text($text);
    if ($t === '') {
        return false;
    }

    return preg_match('/\b(actualiza|actualizar|refresca|refrescar|revisa otra vez|mira otra vez|miralo otra vez|míralo otra vez|comprueba otra vez|vuelve a mirar|vuelve a comprobar)\b/u', $t) === 1;
}

function xabia_avirato_next_room_availability(array $dates, string $bookingLang, string $roomId, string $roomName, bool $bypassCache = false): ?array
{
    if ($roomId === '') {
        return null;
    }
    $checkin = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($dates['checkin'] ?? ''));
    $checkout = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($dates['checkout'] ?? ''));
    if (!$checkin instanceof DateTimeImmutable || !$checkout instanceof DateTimeImmutable || $checkout <= $checkin) {
        return null;
    }
    $nights = max(1, (int) $checkin->diff($checkout)->format('%a'));
    $cacheKey = 'xabia_avirato_next_' . md5(xabia_avirato_cache_identity() . '|' . $roomId . '|' . $checkin->format('Y-m-d') . '|' . $nights . '|' . $bookingLang);
    $cached = $bypassCache ? false : get_transient($cacheKey);
    if (!$bypassCache && is_array($cached)) {
        return $cached !== [] ? $cached : null;
    }

    
    for ($offset = 1; $offset <= 14; $offset++) {
        $nextCheckin = $checkin->modify('+' . $offset . ' days');
        $nextCheckout = $nextCheckin->modify('+' . $nights . ' days');
        $result = xabia_avirato_scrape_rooms(
            $nextCheckin->format('Y-m-d'),
            $nextCheckout->format('Y-m-d'),
            $bookingLang,
            $roomId,
            $roomName
        );
        $rooms = isset($result['rooms']) && is_array($result['rooms']) ? (array) $result['rooms'] : [];
        if (($result['ok'] ?? false) && $rooms !== []) {
            $found = [
                'checkin' => $nextCheckin->format('Y-m-d'),
                'checkout' => $nextCheckout->format('Y-m-d'),
                'booking_url' => (string) ($result['booking_url'] ?? ''),
            ];
            set_transient($cacheKey, $found, 2 * MINUTE_IN_SECONDS);

            return $found;
        }
    }
    set_transient($cacheKey, [], 2 * MINUTE_IN_SECONDS);

    return null;
}

function xabia_avirato_looks_like_intent(string $text): bool
{
    $t = xabia_avirato_normalize_text($text);
    foreach ([
        'disponibilidad', 'disponible', 'libre', 'dormir', 'reserva', 'alojamiento',
        'que tienes', 'qué tienes', 'que hay', 'qué hay', 'busco sitio', 'queremos ir',
        'esta semana', 'la semana que viene', 'el mes que viene', 'este fin de semana',
        'primera quincena', 'segunda quincena', 'mediados de', 'verano', 'semana santa', 'puente de mayo',
        'primera semana', 'segunda semana', 'tercera semana', 'cuarta semana', 'quinta semana',
        'una semana', 'una noche', 'varias noches', 'estancia', 'días', 'dias', 'noches',
        'finales de', 'fines de', 'principios de', 'comienzo de', 'comienzos de', 'partir del', 'prtir del',
        'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'setiembre', 'octubre', 'noviembre', 'diciembre',
    ] as $kw) {
        if (str_contains($t, $kw)) {
            return true;
        }
    }

    if (preg_match('/\b\d{1,2}\s*(?:al|hasta|-|a)\s*\d{1,2}\s+de\s+(?:' . implode('|', array_keys([
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
        'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
    ])) . ')\b/u', $t) === 1) {
        return true;
    }

    return preg_match('/\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}\/\d{1,2}\/\d{4}\b/', $text) === 1;
}

/**
 * Dispara scraping + inyección ante intención de disponibilidad o cualquier señal clara de fechas.
 */
function xabia_avirato_should_attempt_injection(string $combined): bool
{
    $combined = trim($combined);
    if ($combined === '') {
        return false;
    }
    if (xabia_avirato_looks_like_intent($combined)) {
        xabia_trace('[XABIA_AVIRATO] intent detected (availability keywords)', ['snippet' => substr($combined, 0, 220)]);

        return true;
    }
    $t = xabia_avirato_normalize_text($combined);
    $byDate = preg_match('/\b\d{4}-\d{2}-\d{2}\b/u', $t) === 1
        || preg_match('/\b\d{1,2}\/\d{1,2}\/\d{4}\b/u', $t) === 1
        || preg_match('/\b\d{1,2}\s+de\s+(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre)\b/u', $t) === 1
        || preg_match('/\b(?:del|desde|hasta|entre)\s+\d{1,2}\b/u', $t) === 1;
    if ($byDate) {
        xabia_trace('[XABIA_AVIRATO] intent detected (date-like text)', ['snippet' => substr($combined, 0, 220)]);
    }

    return $byDate;
}

/**
 * Misma secuencia de query que avirato-calendar/public/js/av-calendar-public.js (code, startDate, endDate, lang, id_habitacion?, cod_promocional?).
 *
 * @param string $bookingLang Código ISO de 2 letras para el parámetro lang (p. ej. es, en, eu).
 */
function xabia_avirato_scrape_rooms(string $checkin, string $checkout, string $bookingLang = 'es', string $roomIdOverride = '', string $requestedRoomName = ''): array
{
    $settings = xabia_avirato_resolve_settings();
    $establishmentId = trim((string) $settings['establishment_id']);
    if ($establishmentId === '') {
        return ['ok' => false, 'rooms' => [], 'message' => 'Sin ID de establecimiento configurado.'];
    }
    $lang = strtolower(substr(preg_replace('/[^a-zA-Z]/', '', $bookingLang), 0, 2));
    if ($lang === '' || strlen($lang) !== 2) {
        $lang = 'es';
    }
    $startDm = xabia_avirato_to_booking_date($checkin);
    $endDm = xabia_avirato_to_booking_date($checkout);
    xabia_trace('[XABIA_AVIRATO] scrape date range (DD-MM-YYYY)', ['startDate' => $startDm, 'endDate' => $endDm, 'lang' => $lang]);
    $parts = [
        'code'      => $establishmentId,
        'startDate' => $startDm,
        'endDate'   => $endDm,
        'lang'      => $lang,
    ];
    $idHabitacion = trim($roomIdOverride) !== '' ? trim($roomIdOverride) : trim((string) ($settings['id_habitacion'] ?? ''));
    $codPromo = trim((string) ($settings['cod_promocional'] ?? ''));
    if ($idHabitacion !== '') {
        $parts['id_habitacion'] = $idHabitacion;
    }
    if ($codPromo !== '') {
        $parts['cod_promocional'] = $codPromo;
    }
    $base = rtrim((string) $settings['engine_url'], '/');
    $url = $base . '/?' . http_build_query($parts, '', '&', PHP_QUERY_RFC3986);
    xabia_trace('[XABIA_AVIRATO] final scrape URL', ['url' => $url]);

    $apiPrimary = xabia_avirato_scrape_rooms_bookingapi($checkin, $checkout, $settings, $parts, $base);
    if (($apiPrimary['ok'] ?? false) === true) {
        xabia_trace('[XABIA_AVIRATO] bookingapi primary success', [
            'room_count' => isset($apiPrimary['rooms']) && is_array($apiPrimary['rooms']) ? count($apiPrimary['rooms']) : 0,
            'raw_room_count' => (int) ($apiPrimary['raw_room_count'] ?? 0),
        ]);

        return $apiPrimary;
    }
    $apiMessage = trim((string) ($apiPrimary['message'] ?? ''));
    if (($apiPrimary['source'] ?? '') === 'bookingapi') {
        xabia_trace('[XABIA_AVIRATO] bookingapi primary failed; returning API result without HTML fallback', ['message' => $apiMessage]);

        return $apiPrimary;
    }
    xabia_trace('[XABIA_AVIRATO] bookingapi primary failed; trying legacy HTML', ['message' => $apiMessage]);

    $ch = curl_init($url);
    $origin = function_exists('home_url') ? home_url('/') : '';
    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: es-ES,es;q=0.9',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
    ];
    if ($origin !== '') {
        $headers[] = 'Origin: ' . $origin;
    }
    $headers = array_merge($headers, [
        'Upgrade-Insecure-Requests: 1',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-User: ?1',
        'Sec-Fetch-Dest: document',
        'sec-ch-ua: "Google Chrome";v="124", "Chromium";v="124", "Not-A.Brand";v="99"',
        'sec-ch-ua-mobile: ?0',
        'sec-ch-ua-platform: "macOS"',
    ]);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_ENCODING       => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
    ]);
    $dates = $startDm . ' → ' . $endDm . ' (entrada/salida: ' . $checkin . ' / ' . $checkout . ')';
    error_log('Xabia Avirato: Consultando disponibilidad para ID ' . $establishmentId . ' en las fechas ' . $dates);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    xabia_trace('[XABIA_AVIRATO] scrape HTTP response', ['http_code' => $code, 'body_len' => is_string($raw) ? strlen($raw) : 0]);
    if (!is_string($raw) || $raw === '' || $code < 200 || $code >= 300) {
        error_log('Xabia Avirato Response: ' . wp_json_encode(['error' => 'http', 'http_code' => $code, 'body_len' => is_string($raw) ? strlen($raw) : 0]));
        $apiFallback = xabia_avirato_scrape_rooms_bookingapi($checkin, $checkout, $settings, $parts, $base);
        if (($apiFallback['ok'] ?? false) === true) {
            xabia_trace('[XABIA_AVIRATO] bookingapi fallback success after HTML failure', [
                'room_count' => isset($apiFallback['rooms']) && is_array($apiFallback['rooms']) ? count($apiFallback['rooms']) : 0,
                'raw_room_count' => (int) ($apiFallback['raw_room_count'] ?? 0),
            ]);

            return $apiFallback;
        }
        xabia_trace('[XABIA_AVIRATO] bookingapi fallback failed after HTML failure', ['message' => (string) ($apiFallback['message'] ?? '')]);

        return ['ok' => false, 'rooms' => [], 'message' => 'No se pudo obtener HTML del motor de reservas.'];
    }
    $rooms = xabia_avirato_extract_rooms_from_html($raw);
    $hasRoomsFromServer = $rooms !== [];
    xabia_trace('[XABIA_AVIRATO] rooms_from_server marker', ['found' => $hasRoomsFromServer, 'room_count' => count($rooms)]);
    if (!$hasRoomsFromServer) {
        xabia_trace('[XABIA_AVIRATO] HTML sample (no rooms_from_server)', ['head' => substr($raw, 0, 500)]);
        error_log('Xabia Avirato Response: ' . wp_json_encode(['error' => 'no_rooms_from_server', 'http_code' => $code]));
        $apiFallback = xabia_avirato_scrape_rooms_bookingapi($checkin, $checkout, $settings, $parts, $base);
        if (($apiFallback['ok'] ?? false) === true) {
            xabia_trace('[XABIA_AVIRATO] bookingapi fallback success', [
                'room_count' => isset($apiFallback['rooms']) && is_array($apiFallback['rooms']) ? count($apiFallback['rooms']) : 0,
                'raw_room_count' => (int) ($apiFallback['raw_room_count'] ?? 0),
            ]);

            return $apiFallback;
        }
        xabia_trace('[XABIA_AVIRATO] bookingapi fallback failed', ['message' => (string) ($apiFallback['message'] ?? '')]);

        return ['ok' => false, 'rooms' => [], 'message' => 'No se encontró rooms_from_server.'];
    }
    $api_response = $rooms;
    error_log('Xabia Avirato Response: ' . wp_json_encode($api_response));
    $availableRooms = [];
    foreach ($rooms as $room) {
        if (!is_array($room)) {
            continue;
        }
        $isAvailable = xabia_avirato_room_is_available($room);
        $roomName = xabia_avirato_room_name($room);
        $roomId = xabia_avirato_room_id($room);
        xabia_trace('[XABIA_AVIRATO] Casa: ' . ($roomName !== '' ? $roomName : '(sin nombre)') . ' | ID: ' . ($roomId !== '' ? $roomId : '(sin id)') . ' | Estado: ' . ($isAvailable ? 'Libre' : 'Ocupado'), [
            'numRooms' => $room['numRooms'] ?? null,
            'originalFreeRooms' => $room['originalFreeRooms'] ?? null,
        ]);
        if ($isAvailable) {
            $availableRooms[] = $room;
        }
    }
    $filteredRooms = xabia_avirato_filter_rooms($availableRooms, $settings);
    $newEngineParts = xabia_avirato_booking_url_parts_for_new_engine($parts, $checkin, $checkout);
    $bookingUrl = xabia_avirato_booking_url_for_room_ids($base, $newEngineParts, $filteredRooms);
    xabia_trace('[XABIA_AVIRATO] rooms_from_server JSON OK', [
        'raw_room_count'      => count($rooms),
        'available_room_count' => count($availableRooms),
        'filtered_room_count' => count($filteredRooms),
        'inclusion_filter'    => (string) ($settings['inclusion_filter'] ?? ''),
        'exclusion_list'      => (string) ($settings['exclusion_list'] ?? ''),
    ]);

    return [
        'ok'              => true,
        'rooms'           => $filteredRooms,
        'raw_rooms'       => array_values(array_filter($availableRooms, 'is_array')),
        'raw_room_count'  => count($rooms),
        'filter_active'   => trim((string) ($settings['inclusion_filter'] ?? '')) !== '' || trim((string) ($settings['exclusion_list'] ?? '')) !== '',
        'requested_room'  => trim($requestedRoomName),
        'booking_url'     => $filteredRooms !== [] ? $bookingUrl : '',
        'raw_booking_url' => $base . '/?' . http_build_query($newEngineParts, '', '&', PHP_QUERY_RFC3986),
        'booking_base'    => $base,
        'booking_parts'   => $newEngineParts,
        'message'         => '',
    ];
}

function xabia_avirato_filter_rooms(array $rooms, array $settings): array
{
    $inclusion = xabia_avirato_normalize_text((string) ($settings['inclusion_filter'] ?? ''));
    $rawExclusions = explode(',', (string) ($settings['exclusion_list'] ?? ''));
    $exclusions = [];
    foreach ($rawExclusions as $word) {
        $word = xabia_avirato_normalize_text((string) $word);
        if ($word !== '') {
            $exclusions[] = $word;
        }
    }

    $filtered = [];
    foreach ($rooms as $room) {
        if (!is_array($room)) {
            continue;
        }
        $subtipo = (string) ($room['subtipoEspacio'] ?? '');
        $name = (string) ($room['nombreEspacio'] ?? ($room['name'] ?? ''));
        if ($subtipo === '') {
            $subtipo = xabia_avirato_room_name($room);
        }
        $haystack = xabia_avirato_normalize_text($subtipo . ' ' . $name);
        if ($haystack === '') {
            continue;
        }
        if ($inclusion !== '' && !str_contains(xabia_avirato_normalize_text($subtipo), $inclusion)) {
            continue;
        }
        $blocked = false;
        foreach ($exclusions as $excluded) {
            if (str_contains($haystack, $excluded)) {
                $blocked = true;
                break;
            }
        }
        if ($blocked) {
            continue;
        }
        $filtered[] = $room;
    }

    return $filtered;
}

function xabia_avirato_rooms_to_text(array $rooms, array $opts = []): string
{
    $lang = isset($opts['lang']) ? (string) $opts['lang'] : 'es';
    $maxRooms = max(0, (int) ($opts['max'] ?? 0));
    $sorted = [];
    foreach ($rooms as $room) {
        if (!is_array($room)) {
            continue;
        }
        $sorted[] = $room;
    }
    usort($sorted, static function (array $a, array $b): int {
        $pa = xabia_avirato_room_price_value($a);
        $pb = xabia_avirato_room_price_value($b);
        if ($pa === null && $pb === null) {
            return strcasecmp(xabia_avirato_display_room_name(xabia_avirato_room_name($a)), xabia_avirato_display_room_name(xabia_avirato_room_name($b)));
        }
        if ($pa === null) {
            return 1;
        }
        if ($pb === null) {
            return -1;
        }

        return $pa <=> $pb;
    });

    $lines = [];
    $shown = 0;
    $total = count($sorted);
    foreach ($sorted as $room) {
        if ($maxRooms > 0 && $shown >= $maxRooms) {
            break;
        }
        $name = xabia_avirato_display_room_name(xabia_avirato_room_name($room));
        if ($name === '') {
            $name = 'Casa';
        }
        $price = xabia_avirato_room_price_value($room);
        if ($price !== null) {
            $priceText = fmod($price, 1.0) === 0.0 ? (string) (int) $price : (string) round($price, 2);
            $lines[] = '• ' . $name . ' — ' . $priceText . ' €';
        } else {
            $lines[] = '• ' . $name;
        }
        $shown++;
    }
    if ($maxRooms > 0 && $total > $maxRooms) {
        $lines[] = xabia_avirato_i18n('rooms_more', $lang, ['count' => (string) ($total - $maxRooms)]);
    }

    return implode("\n", $lines);
}

function xabia_avirato_extract_room_names(array $rooms): array
{
    $names = [];
    foreach ($rooms as $room) {
        if (!is_array($room)) {
            continue;
        }
        $name = xabia_avirato_room_name($room);
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return array_values(array_unique($names));
}

function xabia_avirato_store_last_availability(string $projectId, array $state): void
{
    if (!session_id() && !headers_sent()) {
        session_start();
    }
    $_SESSION['xabia_last_availability'][$projectId] = $state;
    if (!isset($_SESSION['xabia_last_response_meta'][$projectId]) || !is_array($_SESSION['xabia_last_response_meta'][$projectId])) {
        $_SESSION['xabia_last_response_meta'][$projectId] = [];
    }
    if (!empty($state['booking_url'])) {
        $_SESSION['xabia_last_response_meta'][$projectId]['booking_url'] = (string) $state['booking_url'];
    }
    session_write_close();

    $identity = xabia_avirato_cache_identity();
    if ($identity !== '') {
        set_transient('xabia_avirato_last_' . md5($projectId . '|' . $identity), $state, 2 * MINUTE_IN_SECONDS);
    }
}

function xabia_avirato_build_structured_availability(string $projectId, array $config, array $request): ?array
{
    if (!xabia_avirato_subscription_active()) {
        return null;
    }
    $userText = isset($request['user_message']) ? trim((string) $request['user_message']) : '';
    $dateContext = isset($request['date_context']) ? trim((string) $request['date_context']) : $userText;
    if ($dateContext === '') {
        $dateContext = $userText;
    }
    $combined = trim($dateContext . ' ' . $userText);
    if (!xabia_avirato_should_attempt_injection($combined)) {
        return null;
    }
    $bypassCache = xabia_avirato_should_bypass_cache($combined);
    if ($bypassCache) {
        xabia_trace('[XABIA_AVIRATO] availability cache bypass requested', ['snippet' => substr($combined, 0, 220)]);
    }

    $settings = xabia_avirato_resolve_settings();
    if (trim((string) $settings['establishment_id']) === '') {
        return null;
    }

    $dates = xabia_avirato_extract_dates($dateContext !== '' ? $dateContext : $combined);
    $dn = xabia_avirato_normalize_text($dateContext !== '' ? $dateContext : $combined);
    if (str_contains($dn, 'una semana') || str_contains($dn, '1 semana') || preg_match('/\b\d+\s*noch/', $dn) === 1) {
        $ci = DateTimeImmutable::createFromFormat('Y-m-d', $dates['checkin'] ?? '');
        $co = DateTimeImmutable::createFromFormat('Y-m-d', $dates['checkout'] ?? '');
        if ($ci instanceof DateTimeImmutable && $co instanceof DateTimeImmutable && $co > $ci) {
            $span = (int) $ci->diff($co)->format('%a');
            if ($span > 7 && (str_contains($dn, 'una semana') || str_contains($dn, '1 semana'))) {
                $dates['checkout'] = $ci->modify('+7 days')->format('Y-m-d');
            }
        }
    }
    $dates = xabia_avirato_refine_dates_with_duration($dn, $dates);
    $dates = xabia_avirato_enforce_min_stay($dates);
    error_log('[XABIA_AVIRATO] extracted dates ' . ($dates['checkin'] ?? '') . ' -> ' . ($dates['checkout'] ?? '') . ' label=' . ($dates['label'] ?? ''));

    $bookingLang = 'es';
    $ul = isset($config['_xabia_proxy_user_lang']) ? (string) $config['_xabia_proxy_user_lang'] : '';
    if ($ul !== '') {
        $bookingLang = strtolower(substr(preg_replace('/[^a-zA-Z]/', '', $ul), 0, 2)) ?: 'es';
    }
    if (isset($request['lang_code']) && (string) $request['lang_code'] !== '') {
        $bookingLang = strtolower(substr(preg_replace('/[^a-zA-Z]/', '', (string) $request['lang_code']), 0, 2)) ?: $bookingLang;
    }
    $responseLang = xabia_avirato_detect_response_lang($userText, 'es');

    $requestedRoom = xabia_avirato_resolve_requested_room_from_calendar((string) ($settings['establishment_id'] ?? ''), $settings, $combined);
    if ($requestedRoom === null) {
        $requestedRoom = xabia_avirato_resolve_requested_room_from_knowledge($projectId, $combined);
    }
    $requestedRoomId = is_array($requestedRoom) ? (string) ($requestedRoom['id'] ?? '') : '';
    $requestedRoomName = is_array($requestedRoom) ? (string) ($requestedRoom['name'] ?? '') : '';

    $result = xabia_avirato_scrape_rooms($dates['checkin'], $dates['checkout'], $bookingLang, $requestedRoomId, $requestedRoomName);
    $rooms = isset($result['rooms']) && is_array($result['rooms']) ? (array) $result['rooms'] : [];
    if ($rooms === [] && empty($result['filter_active']) && !empty($result['raw_rooms']) && is_array($result['raw_rooms'])) {
        $rooms = (array) $result['raw_rooms'];
    }
    $roomsBeforeNameHint = $rooms;
    if ($requestedRoomName !== '' && $rooms !== []) {
        $rooms = xabia_avirato_filter_rooms_by_name_hint($requestedRoomName, $rooms, '');
    } elseif ($rooms !== []) {
        $rooms = xabia_avirato_filter_rooms_by_name_hint($userText, $rooms, $dateContext);
    }
    $confirmedRoom = xabia_avirato_confirm_requested_room(
        $requestedRoomName !== '' ? ['id' => $requestedRoomId, 'name' => $requestedRoomName] : null,
        $rooms,
        (string) ($settings['establishment_id'] ?? '')
    );
    $requestedRoomId = is_array($confirmedRoom) ? (string) ($confirmedRoom['id'] ?? '') : '';
    $requestedRoomName = is_array($confirmedRoom) ? (string) ($confirmedRoom['name'] ?? '') : '';
    $requestedUnavailable = $requestedRoomName !== '' && ($result['ok'] ?? false) && $rooms === [];
    $alternativeRooms = [];
    $alternativeBookingUrl = '';
    $nextAvailable = null;
    if ($requestedUnavailable) {
        if ($roomsBeforeNameHint !== []) {
            $alternativeRooms = $roomsBeforeNameHint;
            if (!empty($result['booking_parts']) && is_array($result['booking_parts']) && !empty($result['booking_base'])) {
                $alternativeBookingUrl = xabia_avirato_booking_url_for_room_ids((string) $result['booking_base'], (array) $result['booking_parts'], $alternativeRooms);
            }
        } else {
            $alternativesResult = xabia_avirato_scrape_rooms($dates['checkin'], $dates['checkout'], $bookingLang, '', '');
            if (($alternativesResult['ok'] ?? false)) {
                $alternativeRooms = isset($alternativesResult['rooms']) && is_array($alternativesResult['rooms']) ? (array) $alternativesResult['rooms'] : [];
                if ($alternativeRooms === [] && empty($alternativesResult['filter_active']) && !empty($alternativesResult['raw_rooms']) && is_array($alternativesResult['raw_rooms'])) {
                    $alternativeRooms = (array) $alternativesResult['raw_rooms'];
                }
                if ($alternativeRooms !== [] && !empty($alternativesResult['booking_parts']) && is_array($alternativesResult['booking_parts']) && !empty($alternativesResult['booking_base'])) {
                    $alternativeBookingUrl = xabia_avirato_booking_url_for_room_ids((string) $alternativesResult['booking_base'], (array) $alternativesResult['booking_parts'], $alternativeRooms);
                }
            }
        }
        $nextAvailable = xabia_avirato_next_room_availability($dates, $bookingLang, $requestedRoomId, $requestedRoomName, $bypassCache);
    }
    $bookingUrl = (string) ($result['booking_url'] ?? '');
    if ($rooms === []) {
        $bookingUrl = '';
    } elseif (!empty($result['booking_parts']) && is_array($result['booking_parts']) && !empty($result['booking_base'])) {
        $bookingUrl = xabia_avirato_booking_url_for_room_ids((string) $result['booking_base'], (array) $result['booking_parts'], $rooms);
    }

    return [
        'ok' => (bool) ($result['ok'] ?? false),
        'dates' => $dates,
        'rooms' => $rooms,
        'room_names' => xabia_avirato_extract_room_names($rooms),
        'requested_room' => $requestedRoomName,
        'requested_unavailable' => $requestedUnavailable,
        'alternative_rooms' => $alternativeRooms,
        'alternative_room_names' => xabia_avirato_extract_room_names($alternativeRooms),
        'alternative_booking_url' => $alternativeBookingUrl,
        'next_available' => $nextAvailable,
        'booking_url' => $bookingUrl,
        'message' => (string) ($result['message'] ?? ''),
        'response_lang' => $responseLang,
        'raw_room_count' => (int) ($result['raw_room_count'] ?? 0),
        'filter_active' => (bool) ($result['filter_active'] ?? false),
        'availability_label' => xabia_avirato_availability_label($settings),
    ];
}

function xabia_avirato_template_response(array $payload): string
{
    $lang = isset($payload['response_lang']) ? (string) $payload['response_lang'] : 'es';
    $dates = is_array($payload['dates'] ?? null) ? $payload['dates'] : [];
    $rangeText = xabia_avirato_format_availability_range($dates, $lang);
    $nights = xabia_avirato_stay_nights($dates);
    $legacyRange = '';
    if (!empty($dates['checkin']) && !empty($dates['checkout'])) {
        $legacyRange = ' (' . $rangeText . ')';
    }
    $label = isset($dates['label']) && is_string($dates['label']) && trim($dates['label']) !== '' ? strtolower(trim($dates['label'])) : 'estas fechas';
    $requested = trim((string) ($payload['requested_room'] ?? ''));
    $rooms = is_array($payload['rooms'] ?? null) ? (array) $payload['rooms'] : [];
    $bookingUrl = trim((string) ($payload['booking_url'] ?? ''));

    if (!($payload['ok'] ?? false)) {
        xabia_trace('[XABIA_AVIRATO] public technical fallback', [
            'message' => (string) ($payload['message'] ?? ''),
            'lang' => $lang,
            'raw_room_count' => (int) ($payload['raw_room_count'] ?? 0),
        ]);

        return xabia_avirato_i18n('technical_failure', $lang);
    }
    if (!empty($payload['requested_unavailable']) && $requested !== '') {
        $alternativeRooms = is_array($payload['alternative_rooms'] ?? null) ? (array) $payload['alternative_rooms'] : [];
        $alternativeUrl = trim((string) ($payload['alternative_booking_url'] ?? ''));
        $nextAvailable = is_array($payload['next_available'] ?? null) ? (array) $payload['next_available'] : null;
        $response = xabia_avirato_i18n('requested_unavailable', $lang, ['room' => $requested, 'label' => $label, 'range' => $legacyRange]);
        if ($alternativeRooms !== []) {
            $response .= "\n\n" . xabia_avirato_i18n('alternatives', $lang) . "\n\n" . xabia_avirato_rooms_to_text($alternativeRooms, ['lang' => $lang]);
        }
        if ($nextAvailable !== null && !empty($nextAvailable['checkin']) && !empty($nextAvailable['checkout'])) {
            $response .= "\n\n" . xabia_avirato_i18n('next_available', $lang, [
                'room' => $requested,
                'checkin' => xabia_avirato_to_booking_date((string) $nextAvailable['checkin']),
                'checkout' => xabia_avirato_to_booking_date((string) $nextAvailable['checkout']),
            ]);
        }
        if ($alternativeUrl !== '') {
            $response .= "\n\n" . xabia_avirato_i18n('book_alternatives', $lang) . ' [ACTION:URL:' . $alternativeUrl . ']';
        } elseif ($nextAvailable !== null && !empty($nextAvailable['booking_url'])) {
            $response .= "\n\n" . xabia_avirato_i18n('book_room', $lang, ['room' => $requested]) . ' [ACTION:URL:' . (string) $nextAvailable['booking_url'] . ']';
        }

        return $response;
    }
    if ($rooms === []) {
        if ($requested !== '') {
            return xabia_avirato_i18n('requested_empty', $lang, ['room' => $requested, 'label' => $label, 'range' => $legacyRange]);
        }
        return xabia_avirato_i18n('empty', $lang, [
            'label' => $label,
            'range' => $legacyRange,
            'place' => (string) ($payload['availability_label'] ?? 'el alojamiento configurado'),
        ]);
    }

    $roomsText = xabia_avirato_rooms_to_text($rooms, ['lang' => $lang, 'max' => 8]);
    $specificRoom = $requested !== '' && count($rooms) === 1;
    if ($specificRoom) {
        $response = xabia_avirato_i18n('requested_available', $lang, [
            'room'  => xabia_avirato_display_room_name($requested),
            'range' => $legacyRange,
            'label' => $label,
        ]);
        if ($roomsText !== '') {
            $response .= "\n\n" . $roomsText;
        }
    } else {
        $response = xabia_avirato_i18n('available_list', $lang, ['range' => $rangeText]) . "\n\n" . $roomsText;
        $hasPrices = false;
        foreach ($rooms as $room) {
            if (is_array($room) && xabia_avirato_room_price_value($room) !== null) {
                $hasPrices = true;
                break;
            }
        }
        if ($nights > 0 && $hasPrices) {
            $response .= "\n\n" . xabia_avirato_i18n('price_note', $lang, ['nights' => (string) $nights]);
        }
    }
    if ($bookingUrl !== '') {
        $response .= "\n\n" . xabia_avirato_i18n('book_here', $lang) . ' [ACTION:URL:' . $bookingUrl . ']';
    } elseif ($specificRoom) {
        $response .= "\n\n" . xabia_avirato_i18n('more_info', $lang);
    }
    return $response;
}

/**
 * Palabras del nombre de habitación/casa para comparar con lo que escribe el usuario (typos).
 *
 * @return list<string>
 */
function xabia_avirato_room_significant_words(string $blob): array
{
    $blob = xabia_avirato_normalize_text($blob);
    if ($blob === '') {
        return [];
    }
    $parts = preg_split('/[^a-z0-9áéíóúüñ]+/u', $blob, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts)) {
        return [];
    }
    $skip = [
        'casa', 'suite', 'loft', 'apartamento', 'refugio', 'estancia', 'habitacion', 'habitación',
        'dormir', 'planta', 'diseno', 'diseño', 'familiar', 'familias', 'parejas', 'pareja',
        'reserva', 'reservar', 'proceso', 'disponible', 'disponibilidad',
    ];
    $words = [];
    foreach ($parts as $p) {
        $p = (string) $p;
        if (function_exists('mb_strlen') ? mb_strlen($p) >= 4 : strlen($p) >= 4) {
            if (!in_array($p, $skip, true)) {
                $words[] = $p;
            }
        }
    }

    return array_values(array_unique($words));
}

/**
 * Si el usuario nombra una casa concreta, reduce la lista a coincidencias únicas.
 * Incluye coincidencia aproximada para pequeños errores tipográficos.
 *
 * @param array<int, array<string, mixed>> $rooms
 * @return array<int, array<string, mixed>>
 */
function xabia_avirato_filter_rooms_by_name_hint(string $userNow, array $rooms, string $extraUserText = ''): array
{
    $needle = trim(trim($userNow) . ' ' . trim($extraUserText));
    if ($rooms === [] || $needle === '') {
        return $rooms;
    }
    $stop = [
        'disponibilidad', 'disponible', 'puedes', 'puedo', 'ver', 'mirar', 'mira', 'comprobar',
        'libre', 'reserva', 'reservas', 'consultar', 'motor', 'informacion', 'información',
        'casa', 'casas', 'sitio', 'sitios', 'alojamiento', 'estancia', 'semana', 'noches',
        'segunda', 'primera', 'tercera', 'cuarta', 'quinta', 'quincena', 'mediados', 'junio', 'julio', 'mayo', 'abril',
        'dias', 'días', 'gracias', 'aclaracion', 'aclaración', 'detalles',
    ];
    $normUser = xabia_avirato_normalize_text($needle);
    if (preg_match_all('/[\p{L}\p{N}]{5,}/u', $normUser, $m) < 1 || empty($m[0])) {
        return $rooms;
    }
    $tokens = array_unique($m[0]);
    usort($tokens, static function (string $a, string $b): int {
        return strlen($b) <=> strlen($a);
    });
    foreach ($tokens as $tok) {
        if (in_array($tok, $stop, true)) {
            continue;
        }
        $hits = [];
        foreach ($rooms as $idx => $room) {
            if (!is_array($room)) {
                continue;
            }
            $blob = xabia_avirato_normalize_text(
                (string) ($room['subtipoEspacio'] ?? '') . ' ' . (string) ($room['nombreEspacio'] ?? '') . ' ' . (string) ($room['name'] ?? '')
            );
            if ($blob !== '' && str_contains($blob, $tok)) {
                $hits[$idx] = true;
            }
        }
        if (count($hits) === 1) {
            $only = array_values(array_intersect_key($rooms, $hits));

            return $only;
        }
    }

    $fuzzyHits = [];
    foreach ($tokens as $tok) {
        if (in_array($tok, $stop, true) || (function_exists('mb_strlen') ? mb_strlen($tok) < 5 : strlen($tok) < 5)) {
            continue;
        }
        foreach ($rooms as $idx => $room) {
            if (!is_array($room)) {
                continue;
            }
            $blob = xabia_avirato_normalize_text(
                (string) ($room['subtipoEspacio'] ?? '') . ' ' . (string) ($room['nombreEspacio'] ?? '') . ' ' . (string) ($room['name'] ?? '')
            );
            foreach (xabia_avirato_room_significant_words($blob) as $w) {
                $len = max(
                    function_exists('mb_strlen') ? mb_strlen($tok) : strlen($tok),
                    function_exists('mb_strlen') ? mb_strlen($w) : strlen($w)
                );
                $maxD = (int) max(3, min(5, (int) ceil(0.45 * $len)));
                if (strlen($tok) <= 255 && strlen($w) <= 255 && levenshtein($tok, $w) <= $maxD) {
                    $fuzzyHits[$idx] = true;
                    break;
                }
            }
        }
    }
    if (count($fuzzyHits) === 1) {
        return array_values(array_intersect_key($rooms, $fuzzyHits));
    }

    return $rooms;
}

add_filter('xabia_router_action_response', static function ($response, $project_id, $message, $config, $request) {
    if (is_string($response) && trim($response) !== '') {
        return $response;
    }
    if (!xabia_avirato_subscription_active() && xabia_avirato_should_attempt_injection((string) $message)) {
        if (function_exists('xabia_trace')) {
            xabia_trace('[XABIA_AVIRATO] Public fallback: subscription inactive; suppressed technical message for visitor.');
        }
        error_log('[XABIA_AVIRATO] availability_intent_with_inactive_subscription: user receives public fallback only.');

        return xabia_avirato_public_availability_fallback_message();
    }
    $projectId = (string) $project_id;
    $request = is_array($request) ? $request : [];
    $request['user_message'] = (string) $message;
    if (empty($request['date_context'])) {
        $request['date_context'] = (string) $message;
    }
    $payload = xabia_avirato_build_structured_availability($projectId, is_array($config) ? $config : [], $request);
    if (!is_array($payload)) {
        return $response;
    }
    $stateBookingUrl = (string) ($payload['booking_url'] ?? '');
    if ($stateBookingUrl === '' && !empty($payload['alternative_booking_url'])) {
        $stateBookingUrl = (string) $payload['alternative_booking_url'];
    }
    if ($stateBookingUrl === '' && !empty($payload['next_available']) && is_array($payload['next_available']) && !empty($payload['next_available']['booking_url'])) {
        $stateBookingUrl = (string) $payload['next_available']['booking_url'];
    }
    xabia_avirato_store_last_availability($projectId, [
        'dates' => $payload['dates'] ?? [],
        'ids_disponibles' => $payload['room_names'] ?? [],
        'rooms' => $payload['room_names'] ?? [],
        'requested_room' => (string) ($payload['requested_room'] ?? ''),
        'requested_unavailable' => !empty($payload['requested_unavailable']),
        'alternative_rooms' => $payload['alternative_room_names'] ?? [],
        'next_available' => $payload['next_available'] ?? null,
        'booking_url' => $stateBookingUrl,
        'urls_reserva' => array_filter([$stateBookingUrl]),
        'created_at' => time(),
    ]);

    return xabia_avirato_template_response($payload);
}, 20, 5);

add_filter('xabia_agent_context_injection', static function ($context, $project_id, $config, $request) {
    xabia_trace('[XABIA_AVIRATO] context_injection filter invoked', ['project_id' => $project_id]);
    if (class_exists('Xabia_Addons', false) && !Xabia_Addons::is_active('avirato')) {
        return is_string($context) ? $context : '';
    }
    if (!xabia_avirato_subscription_active()) {
        return is_string($context) ? $context : '';
    }
    $projectId = (string) $project_id;
    $context = is_string($context) ? $context : '';
    $userText = '';
    $dateContext = '';
    if (is_array($request)) {
        $userText = isset($request['user_message']) ? trim((string) $request['user_message']) : '';
        $dateContext = isset($request['date_context']) ? trim((string) $request['date_context']) : '';
    }
    if ($dateContext === '') {
        $dateContext = $userText;
    }
    if ($userText === '' && $dateContext === '') {
        xabia_trace('[XABIA_AVIRATO] injection skip: empty user_message and date_context');

        return $context;
    }
    $combinedForTrigger = trim($dateContext . ' ' . $userText);
    if (!xabia_avirato_should_attempt_injection($combinedForTrigger)) {
        xabia_trace('[XABIA_AVIRATO] injection skip: no date/availability signal', ['snippet' => substr($combinedForTrigger, 0, 200)]);

        return $context;
    }
    xabia_trace('[XABIA_AVIRATO] processing injection after intent', ['date_context' => substr($dateContext, 0, 300)]);
    $settings = xabia_avirato_resolve_settings();
    if (trim((string) $settings['establishment_id']) === '') {
        xabia_trace('[XABIA_AVIRATO] injection skip: establishment_id empty (settings + avc_establishment)');

        return $context;
    }
    $dates = xabia_avirato_extract_dates($dateContext !== '' ? $dateContext : $combinedForTrigger);
    $dn = xabia_avirato_normalize_text($dateContext !== '' ? $dateContext : $combinedForTrigger);
    if (str_contains($dn, 'una semana') || str_contains($dn, '1 semana') || preg_match('/\b\d+\s*noch/', $dn) === 1) {
        $ci = DateTimeImmutable::createFromFormat('Y-m-d', $dates['checkin'] ?? '');
        $co = DateTimeImmutable::createFromFormat('Y-m-d', $dates['checkout'] ?? '');
        if ($ci instanceof DateTimeImmutable && $co instanceof DateTimeImmutable && $co > $ci) {
            $span = (int) $ci->diff($co)->format('%a');
            if ($span > 7 && (str_contains($dn, 'una semana') || str_contains($dn, '1 semana'))) {
                $dates['checkout'] = $ci->modify('+7 days')->format('Y-m-d');
            }
        }
    }
    $dates = xabia_avirato_refine_dates_with_duration($dn, $dates);
    $dates = xabia_avirato_enforce_min_stay($dates);
    xabia_trace('[XABIA_AVIRATO] calculated dates', [
        'checkin_ymd'  => $dates['checkin'] ?? '',
        'checkout_ymd' => $dates['checkout'] ?? '',
        'checkin_dm'   => isset($dates['checkin']) ? xabia_avirato_to_booking_date($dates['checkin']) : '',
        'checkout_dm'  => isset($dates['checkout']) ? xabia_avirato_to_booking_date($dates['checkout']) : '',
    ]);
    $bookingLang = 'es';
    if (is_array($config)) {
        $ul = isset($config['_xabia_proxy_user_lang']) ? (string) $config['_xabia_proxy_user_lang'] : '';
        if ($ul !== '') {
            $bookingLang = strtolower(substr(preg_replace('/[^a-zA-Z]/', '', $ul), 0, 2)) ?: 'es';
        }
    }
    if (is_array($request) && isset($request['lang_code']) && (string) $request['lang_code'] !== '') {
        $bookingLang = strtolower(substr(preg_replace('/[^a-zA-Z]/', '', (string) $request['lang_code']), 0, 2)) ?: $bookingLang;
    }
    $requestedRoom = xabia_avirato_resolve_requested_room_from_calendar(
        (string) ($settings['establishment_id'] ?? ''),
        $settings,
        $combinedForTrigger
    );
    if ($requestedRoom === null) {
        $requestedRoom = xabia_avirato_resolve_requested_room_from_knowledge($projectId, $combinedForTrigger);
    }
    $requestedRoomId = is_array($requestedRoom) ? (string) ($requestedRoom['id'] ?? '') : '';
    $requestedRoomName = is_array($requestedRoom) ? (string) ($requestedRoom['name'] ?? '') : '';
    $result = xabia_avirato_scrape_rooms($dates['checkin'], $dates['checkout'], $bookingLang, $requestedRoomId, $requestedRoomName);
    $availabilityLabel = xabia_avirato_availability_label($settings);
    $emptyAvailabilitySystem = "\n\nSISTEMA: El usuario ha preguntado por disponibilidad y el motor Avirato ha respondido correctamente, pero no ha devuelto alojamientos disponibles para esas fechas. Dile que para esas fechas no te aparece disponibilidad.";
    if ($requestedRoomName !== '') {
        $emptyAvailabilitySystem = "\n\nSISTEMA: El usuario ha preguntado por la disponibilidad de un alojamiento concreto: " . $requestedRoomName . ". El catálogo del proyecto reconoce ese alojamiento, pero el motor no lo devuelve como disponible para esas fechas. Dile claramente que " . $requestedRoomName . " no te aparece disponible para esas fechas. No digas que no lo reconoces ni que no sabes qué alojamiento es.";
    }
    $filteredOutSystem = "\n\nSISTEMA: El usuario ha preguntado por disponibilidad y el motor Avirato sí devolvió alojamientos, pero todos fueron descartados por los filtros configurados en administración (inclusión/exclusión). Respeta esos filtros: NO menciones alojamientos excluidos ni alternativas fuera del filtro. Dile que para esas fechas no te aparece disponibilidad en " . $availabilityLabel . ".";
    $technicalFailureSystem = "\n\nSISTEMA: El usuario ha preguntado por disponibilidad, pero no se ha podido leer correctamente el motor Avirato en esta consulta. NO digas que está todo completo. Pide probar con otras fechas o revisar la disponibilidad en el motor de reservas.";
    if (!($result['ok'] ?? false)) {
        xabia_trace('[XABIA_AVIRATO] scrape result (pre name-hint)', [
            'ok'  => (bool) ($result['ok'] ?? false),
            'msg' => (string) ($result['message'] ?? ''),
            'rooms' => isset($result['rooms']) && is_array($result['rooms']) ? count($result['rooms']) : 0,
        ]);

        return $context . $technicalFailureSystem;
    }
    $roomsForResponse = isset($result['rooms']) && is_array($result['rooms']) ? (array) $result['rooms'] : [];
    $usingRawFallback = false;
    if ($roomsForResponse === [] && !empty($result['raw_rooms']) && is_array($result['raw_rooms']) && empty($result['filter_active'])) {
        $roomsForResponse = (array) $result['raw_rooms'];
        $usingRawFallback = true;
        xabia_trace('[XABIA_AVIRATO] using raw rooms fallback without active filters', [
            'raw_room_count' => count($roomsForResponse),
        ]);
    }
    if ($roomsForResponse === []) {
        xabia_trace('[XABIA_AVIRATO] scrape result empty availability', [
            'raw_room_count' => (int) ($result['raw_room_count'] ?? 0),
            'filter_active'  => (bool) ($result['filter_active'] ?? false),
        ]);

        if (!empty($result['filter_active']) && (int) ($result['raw_room_count'] ?? 0) > 0) {
            return $context . $filteredOutSystem;
        }

        return $context . $emptyAvailabilitySystem;
    }
    $confirmedRoom = xabia_avirato_confirm_requested_room(
        $requestedRoomName !== '' ? ['id' => $requestedRoomId, 'name' => $requestedRoomName] : null,
        $roomsForResponse,
        (string) ($settings['establishment_id'] ?? '')
    );
    $requestedRoomName = is_array($confirmedRoom) ? (string) ($confirmedRoom['name'] ?? '') : '';
    $rooms = $requestedRoomName !== '' ? $roomsForResponse : xabia_avirato_filter_rooms_by_name_hint($userText, $roomsForResponse, $dateContext);
    $roomsText = xabia_avirato_rooms_to_text($rooms, ['lang' => 'es', 'max' => 8]);
    if ($roomsText === '') {
        xabia_trace('[XABIA_AVIRATO] rooms list empty after name hint / price formatting');

        return $context . $emptyAvailabilitySystem;
    }

    $label = isset($dates['label']) && is_string($dates['label']) && trim($dates['label']) !== '' ? $dates['label'] : 'ESTAS FECHAS';
    $range = xabia_avirato_format_availability_range($dates, 'es');

    $filterNote = $usingRawFallback ? "\nNOTA: No hay filtros de inclusión/exclusión activos; se muestran las habitaciones reales devueltas por Avirato." : '';
    $specificNote = $requestedRoomName !== '' ? "\nCASA SOLICITADA: " . $requestedRoomName . ". Si aparece en la lista, responde solo sobre esta casa." : '';
    $bookingUrl = isset($result['booking_url']) ? trim((string) $result['booking_url']) : '';
    $bookingLine = $bookingUrl !== '' ? "\nENLACE DE RESERVA: [ACTION:URL:" . $bookingUrl . "]" : '';
    $injected = $context . "\n\nSISTEMA: El bloque siguiente es la disponibilidad y precios reales devueltos por el motor Avirato. Es la fuente de verdad: debes basarte en él. NO digas que no tienes información en tiempo real ni que solo puedes remitir a la web si ya aparecen habitaciones o precios aquí. Si el usuario pide reservar, responde con el enlace de reserva indicado. Si el usuario pide una casa concreta y esa casa no aparece en el listado, di que esa casa concreta no te aparece disponible y ofrece las alternativas listadas.\n\nDISPONIBILIDAD REAL PARA " . $label . ' (' . $range . "):\n" . $roomsText . $specificNote . $filterNote . $bookingLine . "\nDile al usuario que puede reservar ahora mismo.";
    xabia_trace('[XABIA_AVIRATO] context injection success', ['injected_len' => strlen($injected), 'rooms_in_text' => count($rooms)]);

    return $injected;
}, 99, 4);

add_filter('xabia_digixop_proxy_payload', static function ($payload, $project_id, $config, $messages) {
    if (!is_array($payload) || !xabia_avirato_subscription_active()) {
        return $payload;
    }
    $settings = xabia_avirato_resolve_settings();
    $establishmentId = trim((string) ($settings['establishment_id'] ?? ''));
    if ($establishmentId === '') {
        return $payload;
    }
    $payload['avirato'] = [
        'establishment_id' => $establishmentId,
        'room_filter'      => trim((string) ($settings['inclusion_filter'] ?? '')),
    ];

    return $payload;
}, 20, 4);
