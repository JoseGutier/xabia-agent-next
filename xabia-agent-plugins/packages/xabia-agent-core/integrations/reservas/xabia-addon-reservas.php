<?php
/**
 * Xabia — Reservas (MEC / Amelia): handler unificado, mapeo, disponibilidad y admin.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detección de motor, mapeo, disponibilidad y hooks compartidos MEC + Amelia.
 */
class Xabia_Reservas_Handler {

    public const VIRTUAL_POST_TYPE_AMELIA = 'xabia_amelia_services';

    public static function is_mec(): bool {
        return class_exists('MEC', false) || defined('MEC_DIR');
    }

    public static function is_amelia(): bool {
        return class_exists('AmeliaBooking\\Infrastructure\\WP\\Plugin', false);
    }

    /**
     * Motor global: MEC tiene prioridad si ambos están activos (coincide con data-type del BOOK).
     */
    public static function detect_engine(): string {
        if (self::is_mec()) {
            return 'mec';
        }
        if (self::is_amelia()) {
            return 'amelia';
        }
        return '';
    }

    /**
     * @param string $project_id
     */
    public static function project_uses_addon(array $config, string $slug): bool {
        if (($config['source_type'] ?? '') === 'addon' && ($config['addon_slug'] ?? '') === $slug) {
            return true;
        }
        foreach ($config['sources'] ?? [] as $src) {
            if (!is_array($src)) {
                continue;
            }
            if (($src['source_type'] ?? '') === 'addon' && ($src['addon_slug'] ?? '') === $slug) {
                return true;
            }
        }
        return false;
    }

    /**
     * Motor efectivo para el chat / expansión de tags (respeta addon del proyecto).
     *
     * @param string $project_id
     */
    public static function engine_for_project(string $project_id): string {
        $projects = get_option('xabia_projects_config', []);
        $data     = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];
        if (self::project_uses_addon($data, 'mec') && self::is_mec()) {
            return 'mec';
        }
        if (self::project_uses_addon($data, 'amelia') && self::is_amelia()) {
            return 'amelia';
        }
        return self::detect_engine();
    }

    /**
     * Lista servicios Amelia (tabla amelia_services) para admin / buscador.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public static function get_amelia_services_list(string $search = '', int $limit = 200): array {
        global $wpdb;
        if (!self::is_amelia()) {
            return [];
        }
        $table = $wpdb->prefix . 'amelia_services';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $like  = '%' . $wpdb->esc_like($search) . '%';
        $sql   = "SELECT id, name FROM {$table} WHERE status = 'visible'";
        if ($search !== '') {
            $sql .= $wpdb->prepare(' AND name LIKE %s', $like);
        }
        $sql .= $wpdb->prepare(" ORDER BY name ASC LIMIT %d", $limit);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'   => isset($r['id']) ? (int) $r['id'] : 0,
                'name' => isset($r['name']) ? (string) $r['name'] : '',
            ];
        }
        return $out;
    }

    /**
     * Comprueba si hay “hueco” lógico antes de recomendar (extensible vía filtro).
     *
     * @param string               $engine   mec|amelia
     * @param int                  $entity_id ID de evento MEC (post) o servicio Amelia
     * @param array<string, mixed> $args      from, to (Y-m-d), timezone…
     *
     * @return array{available: ?bool, message: string, engine: string}
     */
    public static function check_availability(string $engine, int $entity_id, array $args = []): array {
        $engine = strtolower($engine);
        $result = apply_filters('xabia_reservas_check_availability', null, $engine, $entity_id, $args);
        if (is_array($result) && array_key_exists('available', $result)) {
            return $result + ['engine' => $engine, 'message' => (string) ($result['message'] ?? '')];
        }
        if ($entity_id < 1) {
            return ['available' => false, 'message' => __('ID no válido.', 'xabia-intelligence'), 'engine' => $engine];
        }
        if ($engine === 'mec') {
            return self::check_availability_mec($entity_id, $args);
        }
        if ($engine === 'amelia') {
            return self::check_availability_amelia($entity_id, $args);
        }
        return ['available' => null, 'message' => __('Motor de reservas no reconocido.', 'xabia-intelligence'), 'engine' => $engine];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{available: ?bool, message: string, engine: string}
     */
    private static function check_availability_mec(int $post_id, array $args): array {
        $pt = get_post_type($post_id);
        if ($pt !== 'mec-events') {
            return ['available' => false, 'message' => __('No es un evento MEC.', 'xabia-intelligence'), 'engine' => 'mec'];
        }
        $start = get_post_meta($post_id, 'mec_start_date', true);
        $start = is_string($start) ? trim($start) : '';
        if ($start === '') {
            return ['available' => null, 'message' => __('Evento sin mec_start_date en meta.', 'xabia-intelligence'), 'engine' => 'mec'];
        }
        $day = preg_replace('/\s.*/', '', $start);
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? $day : '';
        if ($day === '') {
            return ['available' => null, 'message' => __('Fecha de evento no interpretable.', 'xabia-intelligence'), 'engine' => 'mec'];
        }
        $event_ts = strtotime($day . ' 00:00:00');
        $today_ts = strtotime(current_time('Y-m-d') . ' 00:00:00');
        if ($event_ts === false || $today_ts === false) {
            return ['available' => null, 'message' => __('Fecha de evento no interpretable.', 'xabia-intelligence'), 'engine' => 'mec'];
        }
        if ($event_ts < $today_ts) {
            return ['available' => false, 'message' => __('El evento ya pasó (fecha de inicio anterior a hoy).', 'xabia-intelligence'), 'engine' => 'mec'];
        }
        if (function_exists('xabia_mec_compute_available_slots')) {
            $slots_raw = xabia_mec_compute_available_slots($post_id);
            if ($slots_raw !== '' && is_numeric($slots_raw) && (int) $slots_raw <= 0) {
                return [
                    'available' => false,
                    'message'   => __('No quedan plazas disponibles para este evento.', 'xabia-intelligence'),
                    'engine'    => 'mec',
                ];
            }
        }
        return ['available' => true, 'message' => '', 'engine' => 'mec'];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{available: ?bool, message: string, engine: string}
     */
    private static function check_availability_amelia(int $service_id, array $args): array {
        global $wpdb;
        $table = $wpdb->prefix . 'amelia_services';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return ['available' => null, 'message' => __('Tabla Amelia no encontrada.', 'xabia-intelligence'), 'engine' => 'amelia'];
        }
        $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE id = %d", $service_id));
        if ($status === null) {
            return ['available' => false, 'message' => __('Servicio no encontrado.', 'xabia-intelligence'), 'engine' => 'amelia'];
        }
        if ((string) $status !== 'visible') {
            return ['available' => false, 'message' => __('Servicio no visible para reserva.', 'xabia-intelligence'), 'engine' => 'amelia'];
        }
        return ['available' => true, 'message' => '', 'engine' => 'amelia'];
    }
}

add_filter('xabia_mapping_column_suggestions', function ($list, $project_id, $post_type) {
    $list = is_array($list) ? $list : [];
    if ($post_type === 'mec-events' && Xabia_Reservas_Handler::is_mec()) {
        foreach (['mec_start_date', 'mec_cost', 'mec_location'] as $k) {
            if (!in_array($k, $list, true)) {
                $list[] = $k;
            }
        }
    }
    if ($post_type === Xabia_Reservas_Handler::VIRTUAL_POST_TYPE_AMELIA && Xabia_Reservas_Handler::is_amelia()) {
        foreach (['id', 'name', 'price', 'description', 'status'] as $k) {
            if (!in_array($k, $list, true)) {
                $list[] = $k;
            }
        }
    }
    $list = array_values(array_unique(array_map('strval', $list)));
    sort($list, SORT_STRING);
    return $list;
}, 15, 3);

add_filter('xabia_wp_schema_post_types', function ($post_types) {
    if (!Xabia_Reservas_Handler::is_amelia()) {
        return $post_types;
    }
    $post_types   = is_array($post_types) ? $post_types : [];
    $post_types[] = [
        'name'  => Xabia_Reservas_Handler::VIRTUAL_POST_TYPE_AMELIA,
        'label' => __('Amelia — servicios (tabla)', 'xabia-intelligence'),
    ];
    return $post_types;
}, 10, 1);

add_filter('xabia_wp_schema_for_post_type', function ($result, $post_type, $project_id) {
    if ($post_type !== Xabia_Reservas_Handler::VIRTUAL_POST_TYPE_AMELIA || !Xabia_Reservas_Handler::is_amelia()) {
        return $result;
    }
    return [
        'meta_keys' => ['id', 'name', 'price', 'description', 'status'],
    ];
}, 10, 3);

add_filter('xabia_deep_schema_for_post_type', function ($result, $post_type, $project_id) {
    if ($post_type !== Xabia_Reservas_Handler::VIRTUAL_POST_TYPE_AMELIA || !Xabia_Reservas_Handler::is_amelia()) {
        return $result;
    }
    return [
        'core'           => [],
        'meta'           => [
            ['key' => 'id', 'label' => 'ID'],
            ['key' => 'name', 'label' => __('Nombre / Ente', 'xabia-intelligence')],
            ['key' => 'description', 'label' => __('Descripción', 'xabia-intelligence')],
            ['key' => 'price', 'label' => __('Precio', 'xabia-intelligence')],
            ['key' => 'status', 'label' => __('Estado', 'xabia-intelligence')],
        ],
        'taxonomies'     => [],
        'virtual'        => [],
        'mapping_hints'  => ['id', 'name', 'description', 'price', 'status'],
    ];
}, 10, 3);

add_filter('xabia_system_prompt_rules', function ($rules, $context, $args) {
    if ($context !== 'rag_behavior') {
        return $rules;
    }
    $config = isset($args['config']) && is_array($args['config']) ? $args['config'] : [];
    $extra  = '';
    if (Xabia_Reservas_Handler::project_uses_addon($config, 'mec') && Xabia_Reservas_Handler::is_mec()) {
        $extra = 'REGLA RESERVAS MEC: Usa [ACTION:BOOK:ID] solo si el evento tiene reserva/entradas activas en MEC (ID = post mec-events). Si no hay reserva online, no uses el tag: informa de fechas, lugar y plazas sin botón. Antes de afirmar disponibilidad exacta, recuerda que MEC confirma en la ficha.';
    } elseif (Xabia_Reservas_Handler::project_uses_addon($config, 'amelia') && Xabia_Reservas_Handler::is_amelia()) {
        $extra = 'REGLA RESERVAS AMELIA: Si el usuario quiere cita, usa obligatoriamente [ACTION:BOOK:ID] con el ID numérico del servicio Amelia. El asistente puede comprobar si el servicio existe y es visible; la franja exacta se elige en el formulario Amelia.';
    }
    if ($extra === '') {
        return $rules;
    }
    $rules = is_string($rules) ? trim($rules) : '';
    return $rules === '' ? $extra : $rules . "\n" . $extra;
}, 21, 3);

/**
 * Admin: listado / búsqueda de servicios Amelia (ID + nombre para Ente / mapeo).
 */
function xabia_reservas_ajax_amelia_services(): void {
    check_ajax_referer('xabia_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permiso denegado.', 'xabia-intelligence')]);
    }
    if (!Xabia_Reservas_Handler::is_amelia()) {
        wp_send_json_error(['message' => __('Amelia no está activo.', 'xabia-intelligence')]);
    }
    $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
    $list = Xabia_Reservas_Handler::get_amelia_services_list($q, 250);
    wp_send_json_success(['services' => $list]);
}

add_action('wp_ajax_xabia_reservas_amelia_services', 'xabia_reservas_ajax_amelia_services');
