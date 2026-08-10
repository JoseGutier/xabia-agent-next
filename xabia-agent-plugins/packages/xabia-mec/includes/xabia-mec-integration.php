<?php
/**
 * Xabia MEC — conector Modern Events Calendar (cargado por xabia-mec.php).
 *
 * @package Xabia_MEC
 */

if (!defined('ABSPATH')) exit;

add_filter('xabia_router_search_logic', ['Xabia_MEC_Connector', 'inject_search_logic'], 10, 3);

/**
 * Plugin Xabia MEC — integración con Xabia Agent Core (SQL, RAG, reservas, enriquecimiento).
 * Suscripción Hub/Polar: ver `xabia_mec_license_gate`. Desactivar el requisito Hub con:
 * `add_filter('xabia_mec_require_hub_subscription', '__return_false')`.
 */
function xabia_mec_license_gate(): bool {
    if (apply_filters('xabia_mec_require_hub_subscription', true) === false) {
        return true;
    }
    if (!class_exists('Xabia_Addons', false)) {
        return true;
    }
    if (!Xabia_Addons::is_registered_slug('xabia-mec')) {
        return true;
    }

    return Xabia_Addons::is_active('xabia-mec');
}

/**
 * Proyecto MEC «remoto»: el catálogo se lee por SQL (host configurado) o no hay MEC local.
 * Si hay host SQL, tiene prioridad aunque el plugin MEC esté instalado (evita híbridos confusos).
 */
function xabia_mec_is_remote_catalog(?array $cfg): bool {
    if (!is_array($cfg)
        || ($cfg['source_type'] ?? '') !== 'addon'
        || ($cfg['addon_slug'] ?? '') !== 'mec'
    ) {
        return false;
    }
    $host = trim((string) (($cfg['sql_config']['host'] ?? '') ?: ''));
    if ($host !== '') {
        return true;
    }
    $remote_site = trim((string) (($cfg['rules']['mec_remote_site_url'] ?? '') ?: ''));
    if ($remote_site !== '') {
        return true;
    }

    return !defined('MEC_VERSION') && !class_exists('MEC', false);
}

add_action('xabia_register_addons', static function (): void {
    if (!xabia_mec_license_gate()) {
        return;
    }
    register_xabia_addon('mec', [
        'name'        => __('Modern Events Calendar (MEC)', 'xabia-intelligence'),
        'description' => __('Consultas orientadas a tablas MEC (mec-events, postmeta). Sirve también con base de datos externa.', 'xabia-intelligence'),
        'callback'    => ['Xabia_MEC_Connector', 'get_sync_sql'],
    ]);
}, 10);

/**
 * Etiqueta legible para una meta key MEC (descubrimiento de esquema / UI).
 *
 * @param string $meta_key Clave en postmeta (p. ej. mec_start_date).
 */
function xabia_mec_schema_meta_label($meta_key) {
    $key = (string) $meta_key;
    $defaults = [
        'mec_start_date'           => __('Fecha de inicio', 'xabia-intelligence'),
        'mec_end_date'             => __('Fecha de fin', 'xabia-intelligence'),
        'mec_start_date_day'       => __('Día de inicio (texto)', 'xabia-intelligence'),
        'mec_end_date_day'         => __('Día de fin (texto)', 'xabia-intelligence'),
        'mec_start_time_hour'      => __('Hora inicio (hora)', 'xabia-intelligence'),
        'mec_start_time_minutes'   => __('Hora inicio (minutos)', 'xabia-intelligence'),
        'mec_start_time_ampm'      => __('Hora inicio (AM/PM)', 'xabia-intelligence'),
        'mec_end_time_hour'        => __('Hora fin (hora)', 'xabia-intelligence'),
        'mec_end_time_minutes'     => __('Hora fin (minutos)', 'xabia-intelligence'),
        'mec_end_time_ampm'        => __('Hora fin (AM/PM)', 'xabia-intelligence'),
        'mec_cost'                 => __('Precio / coste', 'xabia-intelligence'),
        'mec_currency'             => __('Moneda', 'xabia-intelligence'),
        'mec_location'             => __('Ubicación (texto)', 'xabia-intelligence'),
        'mec_location_id'          => __('Ubicación (ID)', 'xabia-intelligence'),
        'mec_organizer_id'         => __('Organizador (ID)', 'xabia-intelligence'),
        'mec_label'                => __('Etiquetas MEC', 'xabia-intelligence'),
        'mec_tickets'              => __('Entradas / tickets (serializado)', 'xabia-intelligence'),
        'mec_booking_limit'        => __('Límite de reservas', 'xabia-intelligence'),
        'mec_ticket_limit'         => __('Límite por ticket', 'xabia-intelligence'),
        'mec_total_booking_limit'  => __('Límite total de reservas', 'xabia-intelligence'),
        'mec_timezone'             => __('Zona horaria', 'xabia-intelligence'),
        'mec_allday'               => __('Todo el día', 'xabia-intelligence'),
        'mec_read_more'            => __('Enlace “leer más”', 'xabia-intelligence'),
        'mec_more_info'            => __('Más información', 'xabia-intelligence'),
        'mec_public'               => __('Visible / público', 'xabia-intelligence'),
        'mec_featured'             => __('Destacado', 'xabia-intelligence'),
        'mec_banner'               => __('Banner', 'xabia-intelligence'),
        'mec_cover_image'          => __('Imagen de portada', 'xabia-intelligence'),
    ];
    $map = apply_filters('xabia_mec_schema_meta_label_map', $defaults);
    if (isset($map[$key])) {
        return (string) $map[$key];
    }
    $stripped = preg_replace('/^mec_/i', '', $key);
    $stripped = str_replace('_', ' ', (string) $stripped);

    return $stripped !== '' ? (string) ucwords($stripped) : $key;
}

add_filter(
    'xabia_deep_schema_meta_fields',
    static function ($meta_list, $post_type, $project_id) {
        if ($post_type !== 'mec-events' || !is_array($meta_list)) {
            return $meta_list;
        }
        foreach ($meta_list as $i => $row) {
            if (!is_array($row) || empty($row['key'])) {
                continue;
            }
            $meta_list[$i]['label'] = xabia_mec_schema_meta_label((string) $row['key']);
        }

        return $meta_list;
    },
    20,
    3
);

class Xabia_MEC_Connector {

    public static function inject_search_logic($current_logic, $project_id, $current_ymd) {
        $ymd = is_string($current_ymd) ? $current_ymd : gmdate('Y-m-d');
        $projects = get_option('xabia_projects_config', []);
        $cfg = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];
        $extra = "\n - REGLA MEC — CALENDARIO: Cada fila es un evento raíz MEC (una sola fila por serie; 'Fecha' refleja la próxima fecha en meta, no cada repetición). Para «este fin de semana»: calcula sábado y domingo en la semana que contiene HOY (" . $ymd . " según referencia del intérprete) y filtra eventos cuya Fecha cae en esos días."
            . "\n - REGLA MEC — PLAZAS: Si existe la columna 'mec_available_slots' en contexto, úsala como plazas libres (número entero). Si está vacía o ausente, no afirmes cupo; indica disponibilidad cualitativa y el enlace al evento si aplica."
            . "\n - REGLA MEC — TAXONOMÍA: Público, idioma, municipio y etiquetas suelen aparecer en 'Categorias_Tags'."
            . "\n - REGLA MEC — LUGAR: El campo 'Lugar' (mec_location) complementa el título para preguntas de dónde se celebra.";
        if (xabia_mec_is_remote_catalog($cfg)) {
            $extra .= "\n - REGLA DE RESERVAS MEC REMOTAS: Si un evento procede de un catálogo/nodo SQL remoto (no local), está ESTRICTAMENTE PROHIBIDO emitir [ACTION:BOOK:ID]. Debes utilizar siempre [ACTION:URL:Link] usando la propiedad 'Link' del evento.";
        } else {
            $extra .= "\n - REGLA MEC — RESERVA: Solo usa [ACTION:BOOK:ID] si el evento tiene reserva/entradas activas en MEC; si no hay formulario de reserva en ese evento, no generes el tag: describe el evento y la disponibilidad sin botón de reserva.";
            $extra .= "\n - REGLA MEC — ENLACE: Para apuntarse o reservar un evento con ID conocido, usa [ACTION:BOOK:ID] (ID = columna ID del evento). No copies el campo Link en [ACTION:URL] salvo que sea la única opción.";
        }

        return $current_logic . $extra;
    }

    /**
     * Columnas en el mismo orden que {@see default_mapping_fields()} (catálogo oficial).
     * Solo eventos raíz (post_parent = 0) para evitar filas duplicadas de ocurrencias materializadas como hijos.
     */
    public static function get_sync_sql() {
        return "
            SELECT 
                p.ID,
                p.post_title AS Evento,
                m_date.meta_value AS Fecha,
                CONCAT(
                    COALESCE(m_h.meta_value, '00'), ':', 
                    COALESCE(m_m.meta_value, '00'), ' ', 
                    COALESCE(m_ap.meta_value, '')
                ) AS Hora,
                COALESCE(m_loc.meta_value, '') AS Lugar,
                NULL AS mec_available_slots,
                p.post_name AS post_slug,
                '' AS Link,
                COALESCE(m_cost.meta_value, 'Consultar') AS Precio,
                p.post_content AS Descripcion,
                (
                    SELECT GROUP_CONCAT(t.name SEPARATOR ', ')
                    FROM {prefix}term_relationships tr
                    JOIN {prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    JOIN {prefix}terms t ON tt.term_id = t.term_id
                    WHERE tr.object_id = p.ID
                ) AS Categorias_Tags,
                (SELECT guid FROM {prefix}posts WHERE ID = m_thumb.meta_value) AS Imagen_URL
            FROM {prefix}posts p
            INNER JOIN {prefix}postmeta m_date ON (p.ID = m_date.post_id AND m_date.meta_key = 'mec_start_date')
            LEFT JOIN {prefix}postmeta m_h ON (p.ID = m_h.post_id AND m_h.meta_key = 'mec_start_time_hour')
            LEFT JOIN {prefix}postmeta m_m ON (p.ID = m_m.post_id AND m_m.meta_key = 'mec_start_time_minutes')
            LEFT JOIN {prefix}postmeta m_ap ON (p.ID = m_ap.post_id AND m_ap.meta_key = 'mec_start_time_ampm')
            LEFT JOIN {prefix}postmeta m_cost ON (p.ID = m_cost.post_id AND m_cost.meta_key = 'mec_cost')
            LEFT JOIN {prefix}postmeta m_thumb ON (p.ID = m_thumb.post_id AND m_thumb.meta_key = '_thumbnail_id')
            LEFT JOIN {prefix}postmeta m_loc ON (p.ID = m_loc.post_id AND m_loc.meta_key = 'mec_location')
            WHERE p.post_type = 'mec-events' 
            AND p.post_status IN ('publish', 'future')
            AND p.post_parent = 0
            AND m_date.meta_value >= CURDATE()
            ORDER BY m_date.meta_value ASC
            LIMIT 100
        ";
    }

    /**
     * Preset de mapeo para la UI del agente (Conectar y Mapear).
     *
     * @return list<array{csv_col:string,label:string,visual_role:string,is_ente:int,instruction:string}>
     */
    public static function default_mapping_fields(): array {
        return [
            [
                'csv_col'     => 'ID',
                'label'       => __('ID del evento (MEC)', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 1,
                'instruction' => __('Identificador del post mec-events (reservas, deduplicación).', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'Evento',
                'label'       => __('Título del evento', 'xabia-intelligence'),
                'visual_role' => 'title',
                'is_ente'     => 0,
                'instruction' => __('Nombre comercial del evento para listados y búsqueda.', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'Fecha',
                'label'       => __('Fecha de inicio', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => __('Fecha Y-m-d; usar para «este fin de semana», «próximos», etc.', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'Hora',
                'label'       => __('Hora', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => '',
            ],
            [
                'csv_col'     => 'Lugar',
                'label'       => __('Ubicación', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => __('Lugar o sede desde MEC (mec_location).', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'mec_available_slots',
                'label'       => __('Plazas libres', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => __('Número de plazas disponibles (capacidad − reservas confirmadas). Vacío si no hay tope definido.', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'Link',
                'label'       => __('Enlace / reserva', 'xabia-intelligence'),
                'visual_role' => 'url',
                'is_ente'     => 0,
                'instruction' => __('URL del evento; el botón Reservar llevará al formulario en esta página.', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'Precio',
                'label'       => __('Precio', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => '',
            ],
            [
                'csv_col'     => 'Descripcion',
                'label'       => __('Descripción', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => '',
            ],
            [
                'csv_col'     => 'Categorias_Tags',
                'label'       => __('Categorías y etiquetas', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => __('Taxonomías MEC (público, idioma, municipio, etc.).', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'Imagen_URL',
                'label'       => __('Imagen', 'xabia-intelligence'),
                'visual_role' => 'image',
                'is_ente'     => 0,
                'instruction' => '',
            ],
        ];
    }
}

/**
 * Capacidad total declarada en el evento MEC (suma de límites de tickets o meta conocidos).
 */
function xabia_mec_get_event_total_capacity(int $event_post_id): int {
    if ($event_post_id <= 0) {
        return 0;
    }
    $tickets = get_post_meta($event_post_id, 'mec_tickets', true);
    if (is_array($tickets)) {
        $sum = 0;
        foreach ($tickets as $t) {
            if (!is_array($t)) {
                continue;
            }
            foreach (['limit', 'seats', 'seat_limit'] as $lk) {
                if (isset($t[$lk]) && is_numeric($t[$lk])) {
                    $sum += (int) $t[$lk];
                    break;
                }
            }
        }
        if ($sum > 0) {
            return $sum;
        }
    }
    foreach (['mec_booking_limit', 'mec_ticket_limit', 'mec_total_booking_limit'] as $k) {
        $v = (int) get_post_meta($event_post_id, $k, true);
        if ($v > 0) {
            return $v;
        }
    }
    return (int) apply_filters('xabia_mec_total_capacity_fallback', 0, $event_post_id);
}

/**
 * Asientos reservados vía CPT mec-books enlazados al evento (mec_event_id).
 */
function xabia_mec_get_event_booked_seats(int $event_post_id): int {
    if ($event_post_id <= 0) {
        return 0;
    }
    $q = new WP_Query(
        [
            'post_type'              => 'mec-books',
            'post_status'            => 'any',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'meta_query'             => [
                [
                    'key'   => 'mec_event_id',
                    'value' => (string) $event_post_id,
                ],
            ],
        ]
    );
    $total = 0;
    foreach ($q->posts as $bid) {
        $bid = (int) $bid;
        $st = get_post_status($bid);
        if ($st === 'trash') {
            continue;
        }
        $bstatus = get_post_meta($bid, 'mec_booking_status', true);
        if (in_array((string) $bstatus, ['cancelled', 'rejected', 'canceled'], true)) {
            continue;
        }
        $att = get_post_meta($bid, 'mec_attendees', true);
        $n = is_numeric($att) ? max(1, (int) $att) : 1;
        $total += $n;
    }
    wp_reset_postdata();
    return $total;
}

/**
 * True si el evento tiene reserva/entradas configuradas en MEC (heurística por meta habitual).
 * Anular con el filtro `xabia_mec_is_booking_enabled`.
 */
function xabia_mec_is_booking_enabled(int $event_post_id): bool {
    $filtered = apply_filters('xabia_mec_is_booking_enabled', null, $event_post_id);
    if (is_bool($filtered)) {
        return $filtered;
    }
    if ($event_post_id < 1 || get_post_type($event_post_id) !== 'mec-events') {
        return false;
    }
    $tickets = get_post_meta($event_post_id, 'mec_tickets', true);
    if (is_array($tickets)) {
        foreach ($tickets as $t) {
            if (is_array($t) && $t !== []) {
                return true;
            }
        }
    }
    $booking = get_post_meta($event_post_id, 'mec_booking', true);
    if ($booking === 1 || $booking === true || in_array((string) $booking, ['1', 'on', 'yes', 'true'], true)) {
        return true;
    }
    if ((int) get_post_meta($event_post_id, 'mec_booking_limit', true) > 0) {
        return true;
    }

    return false;
}

/**
 * Campo virtual: plazas libres (capacidad − reservas). Se recalcula en cada sincronización.
 */
function xabia_mec_compute_available_slots(int $event_post_id): string {
    if ($event_post_id <= 0 || get_post_type($event_post_id) !== 'mec-events') {
        return '';
    }
    $capacity = (int) apply_filters('xabia_mec_total_capacity', xabia_mec_get_event_total_capacity($event_post_id), $event_post_id);
    $booked = (int) apply_filters('xabia_mec_booked_seats', xabia_mec_get_event_booked_seats($event_post_id), $event_post_id);
    if ($capacity <= 0) {
        return '';
    }
    return (string) max(0, $capacity - $booked);
}

add_filter(
    'xabia_knowledge_sync_enrich_row',
    static function ($row, $project_id, $mapping) {
        if (!is_array($row) || !is_array($mapping)) {
            return $row;
        }
        $projects = get_option('xabia_projects_config', []);
        $cfg = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];
        $is_mec = (($cfg['source_type'] ?? '') === 'addon' && ($cfg['addon_slug'] ?? '') === 'mec');
        if (!$is_mec) {
            return $row;
        }
        $pid = 0;
        if (isset($row['ID'])) {
            $pid = absint($row['ID']);
        } elseif (isset($row['id'])) {
            $pid = absint($row['id']);
        }
        if ($pid <= 0 || xabia_mec_is_remote_catalog($cfg) || get_post_type($pid) !== 'mec-events') {
            return $row;
        }
        $wants_slots = false;
        foreach ($mapping as $m) {
            if (($m['csv_col'] ?? '') === 'mec_available_slots') {
                $wants_slots = true;
                break;
            }
        }
        if ($wants_slots) {
            $row['mec_available_slots'] = xabia_mec_compute_available_slots($pid);
        }

        return $row;
    },
    10,
    3
);

add_filter(
    'xabia_system_prompt_rules',
    static function ($rules, $context, $args) {
        if ($context !== 'rag_behavior') {
            return $rules;
        }
        $config = isset($args['config']) && is_array($args['config']) ? $args['config'] : [];
        if (($config['source_type'] ?? '') !== 'addon' || ($config['addon_slug'] ?? '') !== 'mec') {
            return $rules;
        }
        if (!function_exists('xabia_mec_is_remote_catalog') || !xabia_mec_is_remote_catalog($config)) {
            return $rules;
        }
        $strict = "REGLA DE RESERVAS MEC REMOTAS: Si un evento procede de un catálogo/nodo SQL remoto (no local), está ESTRICTAMENTE PROHIBIDO emitir [ACTION:BOOK:ID]. Debes utilizar siempre [ACTION:URL:Link] usando la propiedad 'Link' del evento.";
        $rules = is_string($rules) ? trim($rules) : '';

        return $rules === '' ? $strict : $rules . "\n" . $strict;
    },
    15,
    3
);

$mec_federation = __DIR__ . '/xabia-federation-bridge-mec.php';
if (is_readable($mec_federation)) {
    require_once $mec_federation;
}

add_filter(
    'xabia_mec_event_reservation_url',
    static function ($url, $post_id) {
        unset($post_id);
        if (!is_string($url) || $url === '' || strpos($url, '#') !== false) {
            return $url;
        }

        return $url . '#book';
    },
    5,
    2
);