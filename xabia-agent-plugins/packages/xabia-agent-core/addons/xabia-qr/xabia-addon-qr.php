<?php
/**
 * Addon Core (bundled): QR / POI, túnel por ente y pestaña Smart QR / Tótems.
 * Ruta: addons/xabia-qr/xabia-addon-qr.php
 *
 * URLs: ?xqr=ID | ?xid=ID (opcional ?x_project=slug). Túnel por ente: ?ente_id= (sesión).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @return array{qr_id:string,ts:int,project_id:string}|null
 */
function xabia_qr_scan_active_for_project($project_id) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    $scan = $_SESSION['xabia_qr_scan'] ?? null;
    if (!is_array($scan) || empty($scan['qr_id'])) {
        return null;
    }
    $ts = (int) ($scan['ts'] ?? 0);
    if ($ts > 0 && (time() - $ts) > DAY_IN_SECONDS) {
        unset($_SESSION['xabia_qr_scan'], $_SESSION['xabia_qr_footer_payload']);
        return null;
    }
    $hint = isset($scan['project_id']) ? (string) $scan['project_id'] : '';
    if ($hint !== '' && $hint !== (string) $project_id) {
        return null;
    }

    return $scan;
}

/**
 * Si hay escaneo QR activo y el POI define ente_slug, devuelve ese slug para anclar el chat (modo estricto).
 */
function xabia_qr_maybe_inject_ente_id($project_id) {
    $scan = xabia_qr_scan_active_for_project($project_id);
    if (!$scan) {
        return '';
    }
    $poi = xabia_qr_get_poi_data((string) $scan['qr_id'], (string) $project_id);
    if (empty($poi['ente_slug'])) {
        return '';
    }

    return sanitize_title((string) $poi['ente_slug']);
}

/**
 * Consume el payload de saludo automático una sola vez por petición (primer shortcode que coincida con proyecto).
 *
 * @return array{active:bool,greeting:string,qr_id:string,target_project:string}|null
 */
function xabia_qr_take_footer_payload_for_project($project_id) {
    static $consumed = false;
    if ($consumed || session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    $p = $_SESSION['xabia_qr_footer_payload'] ?? null;
    if (!is_array($p) || empty($p['active'])) {
        return null;
    }
    $fts = (int) ($p['ts'] ?? 0);
    if ($fts > 0 && (time() - $fts) > HOUR_IN_SECONDS) {
        unset($_SESSION['xabia_qr_footer_payload']);

        return null;
    }
    $target = isset($p['target_project']) ? (string) $p['target_project'] : '';
    if ($target !== '' && $target !== (string) $project_id) {
        return null;
    }
    $consumed = true;
    unset($_SESSION['xabia_qr_footer_payload']);

    return [
        'active'          => true,
        'greeting'        => (string) ($p['greeting'] ?? ''),
        'qr_id'           => (string) ($p['qr_id'] ?? ''),
        'target_project'  => $target,
    ];
}

/**
 * Datos del punto de interés: opción + tabla local + fallback vectores del Core.
 *
 * @return array{qr_id:string,name:string,description:string,greeting:string,ente_slug:string,source:string}
 */
function xabia_qr_get_poi_data($qr_id, $project_id = '') {
    $qr_id = is_string($qr_id) ? trim($qr_id) : '';
    $project_id = is_string($project_id) ? sanitize_key($project_id) : '';
    $empty = [
        'qr_id'       => $qr_id,
        'name'        => '',
        'description' => '',
        'greeting'    => '',
        'ente_slug'   => '',
        'source'      => 'synthetic',
    ];
    if ($qr_id === '') {
        return $empty;
    }

    $registry = get_option('xabia_qr_poi_registry', []);
    $registry = is_array($registry) ? $registry : [];
    $registry = apply_filters('xabia_qr_poi_registry', $registry, $qr_id, $project_id);
    if (isset($registry[$qr_id]) && is_array($registry[$qr_id])) {
        $row = $registry[$qr_id];
        $out = [
            'qr_id'       => $qr_id,
            'name'        => isset($row['name']) ? (string) $row['name'] : '',
            'description' => isset($row['description']) ? (string) $row['description'] : '',
            'greeting'    => isset($row['greeting']) ? (string) $row['greeting'] : '',
            'ente_slug'   => isset($row['ente_slug']) ? sanitize_title((string) $row['ente_slug']) : '',
            'source'      => 'registry',
        ];
        if ($out['name'] === '') {
            $out['name'] = $qr_id;
        }
        if ($out['greeting'] === '' && $out['name'] !== '') {
            $out['greeting'] = sprintf(
                __('¡Bienvenido a %s! Soy tu asistente aquí. ¿En qué puedo ayudarte?', 'xabia-intelligence'),
                $out['name']
            );
        }

        return apply_filters('xabia_qr_poi_data', $out, $qr_id, $project_id);
    }

    global $wpdb;
    $table = Xabia_DB::table('qr_poi');
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
        $db_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT name, description, greeting, ente_slug FROM $table WHERE qr_id = %s AND (project_id = %s OR project_id = '') ORDER BY (project_id = %s) DESC LIMIT 1",
                $qr_id,
                $project_id,
                $project_id
            ),
            ARRAY_A
        );
        if (is_array($db_row) && (!empty($db_row['name']) || !empty($db_row['description']))) {
            $out = [
                'qr_id'       => $qr_id,
                'name'        => (string) ($db_row['name'] ?? ''),
                'description' => (string) ($db_row['description'] ?? ''),
                'greeting'    => (string) ($db_row['greeting'] ?? ''),
                'ente_slug'   => !empty($db_row['ente_slug']) ? sanitize_title((string) $db_row['ente_slug']) : '',
                'source'      => 'table',
            ];
            if ($out['greeting'] === '' && $out['name'] !== '') {
                $out['greeting'] = sprintf(
                    __('¡Bienvenido a %s! Soy tu asistente aquí. ¿En qué puedo ayudarte?', 'xabia-intelligence'),
                    $out['name']
                );
            }

            return apply_filters('xabia_qr_poi_data', $out, $qr_id, $project_id);
        }
    }

    $vec = Xabia_DB::table('knowledge_vectors');
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $vec)) !== $vec) {
        $out = $empty;
        $out['name'] = $qr_id;
        $out['greeting'] = sprintf(
            __('¡Hola! Estás en el punto «%s». ¿Qué necesitas saber?', 'xabia-intelligence'),
            $qr_id
        );

        return apply_filters('xabia_qr_poi_data', $out, $qr_id, $project_id);
    }

    $like = '%' . $wpdb->esc_like($qr_id) . '%';
    if ($project_id !== '') {
        $k = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ente_id, content_chunk, meta_data FROM $vec WHERE project_id = %s AND (ente_id = %s OR content_chunk LIKE %s OR meta_data LIKE %s) ORDER BY (ente_id = %s) DESC, id DESC LIMIT 1",
                $project_id,
                $qr_id,
                $like,
                $like,
                $qr_id
            ),
            ARRAY_A
        );
    } else {
        $k = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ente_id, content_chunk, meta_data FROM $vec WHERE ente_id = %s OR content_chunk LIKE %s OR meta_data LIKE %s ORDER BY id DESC LIMIT 1",
                $qr_id,
                $like,
                $like
            ),
            ARRAY_A
        );
    }

    if (!is_array($k)) {
        $out = $empty;
        $out['name'] = $qr_id;
        $out['greeting'] = sprintf(
            __('¡Hola! Estás en el punto «%s». ¿Qué necesitas saber?', 'xabia-intelligence'),
            $qr_id
        );

        return apply_filters('xabia_qr_poi_data', $out, $qr_id, $project_id);
    }

    $meta = [];
    if (!empty($k['meta_data'])) {
        $decoded = json_decode((string) $k['meta_data'], true);
        $meta = is_array($decoded) ? $decoded : [];
    }
    $display = isset($meta['__ente_display']) ? (string) $meta['__ente_display'] : '';
    $ente = isset($k['ente_id']) ? (string) $k['ente_id'] : '';
    $chunk = isset($k['content_chunk']) ? wp_strip_all_tags((string) $k['content_chunk']) : '';
    $chunk = strlen($chunk) > 800 ? substr($chunk, 0, 800) . '…' : $chunk;
    $name = $display !== '' ? $display : ($ente !== '' ? $ente : $qr_id);
    $out = [
        'qr_id'       => $qr_id,
        'name'        => $name,
        'description' => $chunk,
        'greeting'    => '',
        'ente_slug'   => $ente !== '' ? sanitize_title($ente) : '',
        'source'      => 'knowledge',
    ];
    $out['greeting'] = sprintf(
        __('¡Bienvenido a %1$s! Soy tu asistente aquí. %2$s', 'xabia-intelligence'),
        $name,
        $chunk !== '' ? __('¿Quieres que te resuma lo esencial o prefieres una pregunta concreta?', 'xabia-intelligence') : __('¿En qué puedo ayudarte?', 'xabia-intelligence')
    );

    return apply_filters('xabia_qr_poi_data', $out, $qr_id, $project_id);
}

add_action('xabia_install_addon_tables', static function () {
    global $wpdb;
    $table_name = Xabia_DB::table('qr_poi');
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        project_id varchar(64) NOT NULL DEFAULT '',
        qr_id varchar(191) NOT NULL,
        name varchar(255) NOT NULL DEFAULT '',
        description longtext,
        greeting text,
        ente_slug varchar(191) DEFAULT '',
        PRIMARY KEY  (id),
        KEY qr_project (qr_id, project_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
});

add_action('xabia_register_addons', static function () {
    register_xabia_addon('qr', [
        'name'     => __('QR — POI y contexto físico', 'xabia-intelligence'),
        'icon'     => 'qr_code_2',
        'desc'     => __('Mapeo de POI, URLs ?xqr= / ?xid=, contexto físico para Gemini y segmentación por ente.', 'xabia-intelligence'),
        'callback' => ['Xabia_QR_Engine', 'get_sync_sql'],
    ]);
});

/**
 * Interceptor ?xqr= / ?xid= + sesión de contexto físico.
 */
add_action('init', static function () {
    $xqr = isset($_GET['xqr']) ? sanitize_text_field(wp_unslash($_GET['xqr'])) : '';
    $xid = isset($_GET['xid']) ? sanitize_text_field(wp_unslash($_GET['xid'])) : '';
    $raw = $xqr !== '' ? $xqr : $xid;
    if ($raw === '') {
        return;
    }
    if (!session_id() && !headers_sent()) {
        session_start();
    }
    $qr_id = sanitize_key($raw);
    if ($qr_id === '') {
        $qr_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $raw);
    }
    if ($qr_id === '') {
        return;
    }
    $x_project = isset($_GET['x_project']) ? sanitize_key(wp_unslash($_GET['x_project'])) : '';
    $poi = xabia_qr_get_poi_data($qr_id, $x_project);
    $greeting = $poi['greeting'] ?? '';
    if ($greeting === '' && ($poi['name'] ?? '') !== '') {
        $greeting = sprintf(
            __('¡Bienvenido a %s! Soy tu asistente aquí. ¿En qué puedo ayudarte?', 'xabia-intelligence'),
            $poi['name']
        );
    }
    if ($greeting === '') {
        $greeting = sprintf(__('¡Hola! Estás en el punto «%s». ¿En qué puedo ayudarte?', 'xabia-intelligence'), $qr_id);
    }

    $_SESSION['xabia_qr_scan'] = [
        'qr_id'      => $qr_id,
        'ts'         => time(),
        'project_id' => $x_project,
    ];
    $_SESSION['xabia_qr_footer_payload'] = [
        'active'          => true,
        'greeting'        => $greeting,
        'qr_id'           => $qr_id,
        'target_project'  => $x_project,
        'ts'              => time(),
    ];

    if (!empty($poi['ente_slug'])) {
        $_SESSION['xabia_context'] = [
            'project_id' => $x_project,
            'ente_id'    => (string) $poi['ente_slug'],
            'strict'     => true,
        ];
    }
}, 4);

/**
 * Captura ?ente_id= (URLs por ente, alineado con el chatbox).
 */
add_action('init', static function () {
    $ente_raw = isset($_GET['ente_id']) ? sanitize_text_field(wp_unslash($_GET['ente_id'])) : '';
    $x_project = isset($_GET['x_project']) ? sanitize_key(wp_unslash($_GET['x_project'])) : '';

    if ($ente_raw === '') {
        return;
    }

    $ente_slug = sanitize_title($ente_raw);
    if ($ente_slug === '') {
        $ente_slug = sanitize_key($ente_raw);
    }
    if ($ente_slug === '') {
        return;
    }

    if (!session_id() && !headers_sent()) {
        session_start();
    }

    $_SESSION['xabia_context'] = [
        'project_id' => $x_project,
        'ente_id'    => $ente_slug,
        'strict'     => true,
    ];
}, 5);

add_filter('xabia_chat_addon_discovery_blocks', static function ($blocks, $project_id, $config) {
    if (!is_array($blocks)) {
        $blocks = [];
    }
    if (!is_array($config)) {
        return $blocks;
    }
    $slug = (string) ($config['addon_slug'] ?? '');
    if (($config['source_type'] ?? '') !== 'addon' || $slug !== 'qr') {
        return $blocks;
    }
    $scan = xabia_qr_scan_active_for_project((string) $project_id);
    if (!$scan) {
        return $blocks;
    }
    $poi = xabia_qr_get_poi_data((string) $scan['qr_id'], (string) $project_id);
    $name = $poi['name'] !== '' ? $poi['name'] : (string) $scan['qr_id'];
    $desc = $poi['description'] !== '' ? $poi['description'] : __('(Sin ficha detallada en el mapeador; usa el conocimiento ingerido y el contexto físico.)', 'xabia-intelligence');
    $blocks[] = "### CONTEXTO FÍSICO ACTUAL ###\n"
        . __('El usuario ha escaneado un QR y está físicamente en:', 'xabia-intelligence') . ' ' . $name . "\n"
        . __('Información clave:', 'xabia-intelligence') . ' ' . $desc;

    return $blocks;
}, 15, 3);

add_filter('xabia_system_prompt_rules', static function ($rules, $context, $args) {
    if ($context !== 'rag_behavior') {
        return $rules;
    }
    $config = isset($args['config']) && is_array($args['config']) ? $args['config'] : [];
    $slug = (string) ($config['addon_slug'] ?? '');
    if (($config['source_type'] ?? '') !== 'addon' || $slug !== 'qr') {
        return $rules;
    }
    $physical = 'CONTEXTO FÍSICO: Si el contexto incluye el bloque «### CONTEXTO FÍSICO ACTUAL ###», prioriza esa información sobre cualquier otra. Eres la guía del usuario en ese punto exacto; no contradigas instrucciones de seguridad o uso del lugar que aparezcan ahí.'
        . ' MULTILINGÜE: respeta el idioma de la conversación y las instrucciones de idioma del sistema (incluido user_lang vía hub o Vertex). Si la ficha técnica del POI está en otro idioma, explica con naturalidad en el idioma del usuario sin inventar datos que no figuren en el contexto.';

    $rules = is_string($rules) ? trim($rules) : '';

    return $rules === '' ? $physical : $rules . "\n\n" . $physical;
}, 22, 3);

class Xabia_QR_Engine {

    /**
     * SQL de sincronización: sin filas por defecto (el conocimiento POI vive en vectores / mapeador).
     * Columnas compatibles con el conector de ingesta.
     */
    public static function get_sync_sql() {
        return 'SELECT 0 AS ID, \'\' AS Titulo WHERE 1=0';
    }

    public static function generate_ente_url($project_id, $ente_slug) {
        $pid = sanitize_key((string) $project_id);
        $slug = is_string($ente_slug) ? trim((string) $ente_slug) : '';
        if ($slug === '') {
            return '';
        }
        $base = '';
        if (function_exists('xabia_agent_smart_qr_landing_permalink')) {
            $base = xabia_agent_smart_qr_landing_permalink($pid);
        }
        $args = ['ente_id' => $slug];
        if ($base !== '') {
            return add_query_arg($args, $base);
        }
        $base_url = (string) apply_filters('xabia_qr_tunnel_base_url', home_url('/xabia-box/'));
        if ($pid !== '') {
            $args['x_project'] = $pid;
        }

        return add_query_arg($args, $base_url);
    }

    public static function get_ente_filter() {
        if (!empty($_SESSION['xabia_context']['ente_id'])) {
            return (string) $_SESSION['xabia_context']['ente_id'];
        }

        return 'global';
    }

    public static function get_available_entes($project_id) {
        global $wpdb;
        $table = Xabia_DB::table('knowledge_vectors');

        return $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT ente_id FROM $table WHERE project_id = %s AND ente_id != 'global'",
            $project_id
        ));
    }

    /**
     * Nombre legible del ente (meta __ente_display), p. ej. nombre de empresa.
     */
    public static function get_ente_display_name(string $project_id, string $ente_id): string {
        if (class_exists('Xabia_DB_Bridge', false)) {
            return Xabia_DB_Bridge::get_stored_ente_display($project_id, $ente_id);
        }

        return $ente_id;
    }
}

add_filter('xabia_agent_frontend_injected_ente_id', static function ($ente_id, $project_id) {
    $ente_id = is_string($ente_id) ? trim($ente_id) : '';
    if ($ente_id !== '') {
        return $ente_id;
    }

    return xabia_qr_maybe_inject_ente_id((string) $project_id);
}, 10, 2);

add_filter('xabia_agent_frontend_auto_payload', static function ($payload, $project_id) {
    if (is_array($payload) && !empty($payload)) {
        return $payload;
    }

    return xabia_qr_take_footer_payload_for_project((string) $project_id);
}, 10, 2);

add_filter('xabia_agent_admin_tabs', static function ($tabs) {
    if (!is_array($tabs)) {
        $tabs = [];
    }
    $tabs[] = [
        'id'    => 'tab-qrs',
        'label' => __('Smart QR / Tótems', 'xabia-intelligence'),
    ];

    return $tabs;
}, 10, 1);

add_action('xabia_agent_admin_extra_tabs_content', static function ($edit_id, $data = []) {
    if (!is_array($data)) {
        $data = [];
    }
    $landing_id = isset($data['smart_qr_landing_page_id']) ? absint($data['smart_qr_landing_page_id']) : 0;
    $pages = get_pages(
        [
            'sort_column' => 'post_title',
            'sort_order'  => 'ASC',
            'post_status' => 'publish',
        ]
    );
    if (!is_array($pages)) {
        $pages = [];
    }
    $totem = isset($data['totem']) && is_array($data['totem']) ? $data['totem'] : ['enabled' => 0, 'tiempo_inactividad_defecto' => 0];
    $totem_on = !empty($totem['enabled']);
    $totem_min = absint($totem['tiempo_inactividad_defecto'] ?? 0);
    ?>
    <div id="tab-qrs" class="xabia-tab-content">
        <h3 class="xabia-section-title"><?php echo esc_html__('Smart QR / Tótems', 'xabia-intelligence'); ?></h3>
        <p class="description"><?php echo esc_html__('Gestión de puntos físicos: página de aterrizaje del chat, URLs ?ente_id= y códigos QR por ente (efecto túnel). El contexto del POI se inyecta automáticamente en el prompt.', 'xabia-intelligence'); ?></p>

        <label for="smart_qr_landing_page_id"><strong><?php echo esc_html__('Página de aterrizaje', 'xabia-intelligence'); ?></strong></label>
        <select name="smart_qr_landing_page_id" id="smart_qr_landing_page_id" class="widefat" style="max-width:560px;">
            <option value="0"><?php echo esc_html__('— Selecciona una página publicada —', 'xabia-intelligence'); ?></option>
            <?php foreach ($pages as $sq_page) : ?>
                <?php
                if (!is_object($sq_page) || empty($sq_page->ID)) {
                    continue;
                }
                ?>
                <option value="<?php echo esc_attr((string) (int) $sq_page->ID); ?>" <?php selected($landing_id, (int) $sq_page->ID); ?>><?php echo esc_html(get_the_title($sq_page)); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php echo esc_html__('Debe contener el shortcode del agente. Sirve de base para ?ente_id= y para generar PNG de QR.', 'xabia-intelligence'); ?></p>
        <hr>
        <label><input type="checkbox" name="modo_totem" value="1" <?php checked($totem_on); ?>> <?php echo esc_html__('Modo tótem (reinicio de sesión por inactividad)', 'xabia-intelligence'); ?></label>
        <p class="description"><?php echo esc_html__('En pantallas públicas, tras un tiempo sin interacción se borra la sesión del chat y vuelve al saludo inicial.', 'xabia-intelligence'); ?></p>
        <label style="display:block;margin-top:10px;"><?php echo esc_html__('Minutos de inactividad (0 = solo desactivar por eventos del shortcode)', 'xabia-intelligence'); ?></label>
        <input type="number" name="tiempo_inactividad_defecto" value="<?php echo esc_attr((string) $totem_min); ?>" class="small-text" min="0" step="1" style="width:100px;">
        <hr>

    <?php if ($edit_id === 'new') : ?>
            <p class="description"><?php echo esc_html__('Guarda el agente para listar entes y URLs de túnel.', 'xabia-intelligence'); ?></p>
    <?php
    else :
        $xabia_qr_entes = Xabia_QR_Engine::get_available_entes($edit_id);
        $xabia_qr_entes = is_array($xabia_qr_entes) ? $xabia_qr_entes : [];
        sort($xabia_qr_entes, SORT_STRING);
        if (empty($xabia_qr_entes)) :
            ?>
            <p><?php echo esc_html__('No hay entes distintos de «global» en la memoria de este proyecto. Sincroniza datos con filas marcadas como ENTE o con ente_id en la base de conocimiento.', 'xabia-intelligence'); ?></p>
        <?php else : ?>
            <table class="widefat striped xabia-qrs-table" style="margin-top:12px;">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('ente_id', 'xabia-intelligence'); ?></th>
                        <th><?php echo esc_html__('Nombre visible', 'xabia-intelligence'); ?></th>
                        <th><?php echo esc_html__('URL de túnel', 'xabia-intelligence'); ?></th>
                        <th><?php echo esc_html__('Smart QR', 'xabia-intelligence'); ?></th>
                        <th style="width:120px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($xabia_qr_entes as $xabia_eid) : ?>
                    <?php
                    $xabia_eid = (string) $xabia_eid;
                    $xabia_disp = Xabia_QR_Engine::get_ente_display_name($edit_id, $xabia_eid);
                    if ($xabia_disp === $xabia_eid || $xabia_disp === '') {
                        $xabia_disp = ucwords(str_replace(['-', '_'], ' ', $xabia_eid));
                    }
                    $xabia_tunnel = Xabia_QR_Engine::generate_ente_url($edit_id, $xabia_eid);
                    ?>
                    <tr>
                        <td><code><?php echo esc_html($xabia_eid); ?></code></td>
                        <td><?php echo esc_html($xabia_disp); ?></td>
                        <td><input type="text" class="widefat xabia-tunnel-url" readonly value="<?php echo esc_attr($xabia_tunnel); ?>" aria-label="<?php echo esc_attr__('URL de túnel', 'xabia-intelligence'); ?>"></td>
                        <td>
                            <button type="button" class="button xabia-smart-qr-open" data-ente-id="<?php echo esc_attr($xabia_eid); ?>" data-ente-name="<?php echo esc_attr($xabia_disp); ?>"><?php echo esc_html__('Generar QR', 'xabia-intelligence'); ?></button>
                        </td>
                        <td><button type="button" class="button xabia-copy-tunnel-url"><?php echo esc_html__('Copiar URL', 'xabia-intelligence'); ?></button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; endif; ?>
    </div>
    <?php
}, 10, 2);
