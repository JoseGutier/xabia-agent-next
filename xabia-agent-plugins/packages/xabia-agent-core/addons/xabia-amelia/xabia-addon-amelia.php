<?php
/**
 * Addon: Amelia Appointments (Xabia Core extension).
 * Ruta: addons/xabia-amelia/xabia-addon-amelia.php
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 1. REGISTRO DEL ADDON EN EL CORE
 */
add_action('xabia_register_addons', function () {
    if (class_exists('AmeliaBooking\\Infrastructure\\WP\\Plugin', false)) {
        register_xabia_addon('amelia', [
            'name'     => 'Amelia Appointments',
            'icon'     => 'calendar_month',
            'desc'     => 'Gestión de citas, servicios y disponibilidad.',
            'callback' => ['Xabia_Amelia_Connector', 'get_sync_sql'],
        ]);
    }
});

/**
 * 2. TABLA DE ATRIBUCIÓN DE RESERVAS (ROI)
 */
add_action('xabia_install_addon_tables', function () {
    global $wpdb;
    $table_name = Xabia_DB::table('amelia_bookings');
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        amelia_appointment_id bigint(20) NOT NULL,
        service_name varchar(255) NOT NULL,
        price decimal(10,2) DEFAULT 0.00,
        customer_email varchar(100),
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
});

/**
 * Inyecta un resumen legible de servicios y especialistas Amelia en el contexto del chat
 * cuando el proyecto usa motor Amelia.
 */
add_filter('xabia_chat_addon_discovery_blocks', static function ($blocks, $project_id, $config) {
    if (!is_array($blocks)) {
        $blocks = [];
    }
    if (!class_exists('Xabia_Reservas_Handler', false)) {
        return $blocks;
    }
    if (Xabia_Reservas_Handler::engine_for_project((string) $project_id) !== 'amelia') {
        return $blocks;
    }
    $summary = Xabia_Amelia_Connector::get_discovery_summary_text();
    if ($summary !== '') {
        $blocks[] = $summary;
    }

    return $blocks;
}, 10, 3);

/**
 * 3. CLASE CONECTORA (BRIDGE + DESCUBRIMIENTO)
 */
class Xabia_Amelia_Connector {

    /**
     * SQL de sincronización (servicios visibles) para el conector nativo.
     */
    public static function get_sync_sql() {
        $p = '{prefix}';

        return "SELECT id AS ID, name AS Titulo, description AS Descripcion, price AS Precio, status AS Estado
FROM {$p}amelia_services
WHERE status = 'visible'
ORDER BY name ASC
LIMIT 500";
    }

    /**
     * Servicios visibles (estructura para la IA / herramientas).
     *
     * @return array<int, object|array<string, mixed>>
     */
    public static function discover_services() {
        global $wpdb;
        $table = $wpdb->prefix . 'amelia_services';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return [];
        }

        return $wpdb->get_results(
            "SELECT id, name, price, description, status FROM {$table} WHERE status = 'visible' ORDER BY name ASC LIMIT 500",
            ARRAY_A
        ) ?: [];
    }

    /**
     * Especialistas / proveedores (tabla amelia_users; tipo provider por convención Amelia).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function discover_providers() {
        global $wpdb;
        $table = $wpdb->prefix . 'amelia_users';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return [];
        }

        $cols = [];
        foreach ($wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A) ?: [] as $row) {
            if (!empty($row['Field'])) {
                $cols[] = (string) $row['Field'];
            }
        }
        if ($cols === []) {
            return [];
        }

        $select = ['id'];
        foreach (['firstName', 'lastName', 'email', 'type', 'status'] as $c) {
            if (in_array($c, $cols, true)) {
                $select[] = $c;
            }
        }
        $typeCol = in_array('type', $cols, true);
        $statusCol = in_array('status', $cols, true);

        $sql = 'SELECT ' . implode(', ', $select) . " FROM `{$table}` WHERE 1=1";
        if ($typeCol) {
            $sql .= " AND type = 'provider'";
        }
        if ($statusCol) {
            $sql .= " AND status = 'visible'";
        }
        $sql .= ' ORDER BY id ASC LIMIT 200';

        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Texto compacto para system context / descubrimiento (servicios + especialistas).
     */
    public static function get_discovery_summary_text(): string {
        $services = self::discover_services();
        $providers = self::discover_providers();
        if ($services === [] && $providers === []) {
            return '';
        }

        $lines = ['[Amelia] Catálogo local (solo lectura):'];
        if ($services !== []) {
            $lines[] = 'Servicios:';
            foreach ($services as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = isset($row['name']) ? (string) $row['name'] : '';
                $id = isset($row['id']) ? (string) $row['id'] : '';
                $price = isset($row['price']) ? (string) $row['price'] : '';
                if ($name === '' && $id === '') {
                    continue;
                }
                $lines[] = '- ID ' . $id . ': ' . $name . ($price !== '' ? ' (precio: ' . $price . ')' : '');
            }
        }
        if ($providers !== []) {
            $lines[] = 'Especialistas (proveedores):';
            foreach ($providers as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $fn = isset($row['firstName']) ? trim((string) $row['firstName']) : '';
                $ln = isset($row['lastName']) ? trim((string) $row['lastName']) : '';
                $label = trim($fn . ' ' . $ln);
                $id = isset($row['id']) ? (string) $row['id'] : '';
                if ($label === '' && $id === '') {
                    continue;
                }
                $lines[] = '- ID ' . $id . ': ' . ($label !== '' ? $label : __('(sin nombre)', 'xabia-intelligence'));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @deprecated Use discover_services().
     */
    public static function get_services() {
        return self::discover_services();
    }

    /**
     * Registra una reserva cuando detectamos que se ha completado vía IA
     */
    public static function track_booking($appointment_id, $service_name, $price, $email) {
        global $wpdb;
        $wpdb->insert(
            Xabia_DB::table('amelia_bookings'),
            [
                'amelia_appointment_id' => $appointment_id,
                'service_name'          => $service_name,
                'price'                 => $price,
                'customer_email'        => $email,
            ]
        );
    }
}
