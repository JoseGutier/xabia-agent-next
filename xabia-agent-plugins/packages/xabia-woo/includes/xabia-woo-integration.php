<?php
/**
 * Xabia Woo — conector WooCommerce (cargado por xabia-woo.php).
 *
 * @package Xabia_Woo
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('xabia_router_search_logic', ['Xabia_Woo_Connector', 'inject_search_logic'], 15, 3);

/**
 * Suscripción Hub/Polar. Desactivar el requisito con `add_filter('xabia_woo_require_hub_subscription', '__return_false')`.
 */
function xabia_woo_license_gate(): bool {
    if (apply_filters('xabia_woo_require_hub_subscription', true) === false) {
        return true;
    }
    if (!class_exists('Xabia_Addons', false)) {
        return true;
    }
    if (!Xabia_Addons::is_registered_slug('xabia-woo')) {
        return true;
    }

    return Xabia_Addons::is_active('xabia-woo');
}

/**
 * Proyecto Woo «remoto»: catálogo por SQL/tienda remota, o sin WooCommerce local.
 * Host SQL o URL de tienda remota tienen prioridad aunque WC esté instalado.
 */
function xabia_woo_is_remote_catalog(?array $cfg): bool {
    if (!is_array($cfg)
        || ($cfg['source_type'] ?? '') !== 'addon'
        || ($cfg['addon_slug'] ?? '') !== 'woo'
    ) {
        return false;
    }
    $host = trim((string) (($cfg['sql_config']['host'] ?? '') ?: ''));
    if ($host !== '') {
        return true;
    }
    $shop = isset($cfg['rules']['woo_remote_shop_url']) ? trim((string) $cfg['rules']['woo_remote_shop_url']) : '';
    if ($shop !== '') {
        return true;
    }

    return !class_exists('WooCommerce', false);
}

/**
 * Metas Woo relevantes para el Asistente CPT / deep schema (incluyen prefijo _).
 *
 * @return list<string>
 */
function xabia_woo_deep_schema_meta_keys(): array {
    return [
        '_price',
        '_regular_price',
        '_sale_price',
        '_sku',
        '_stock',
        '_stock_status',
        '_thumbnail_id',
        '_upsell_ids',
        '_crosssell_ids',
        '_product_attributes',
        '_weight',
        '_length',
        '_width',
        '_height',
        '_manage_stock',
        '_virtual',
        '_downloadable',
    ];
}

/**
 * @return array<string, string>
 */
function xabia_woo_schema_meta_label_map(): array {
    return [
        '_price'               => __('Precio actual', 'xabia-intelligence'),
        '_regular_price'       => __('Precio regular', 'xabia-intelligence'),
        '_sale_price'          => __('Precio oferta', 'xabia-intelligence'),
        '_sku'                 => __('SKU', 'xabia-intelligence'),
        '_stock'               => __('Stock (cantidad)', 'xabia-intelligence'),
        '_stock_status'        => __('Estado de stock', 'xabia-intelligence'),
        '_thumbnail_id'        => __('Imagen destacada (ID)', 'xabia-intelligence'),
        '_upsell_ids'          => __('Upsells (IDs)', 'xabia-intelligence'),
        '_crosssell_ids'       => __('Cross-sells (IDs)', 'xabia-intelligence'),
        '_product_attributes'  => __('Atributos', 'xabia-intelligence'),
        '_weight'              => __('Peso', 'xabia-intelligence'),
        '_length'              => __('Largo', 'xabia-intelligence'),
        '_width'               => __('Ancho', 'xabia-intelligence'),
        '_height'              => __('Alto', 'xabia-intelligence'),
        '_manage_stock'        => __('Gestionar stock', 'xabia-intelligence'),
        '_virtual'             => __('Virtual', 'xabia-intelligence'),
        '_downloadable'        => __('Descargable', 'xabia-intelligence'),
    ];
}

/**
 * URL pública de la tienda Woo remota (finalización de compra / add-to-cart).
 *
 * @param array<string, mixed> $cfg Proyecto (xabia_projects_config[id]).
 */
function xabia_woo_get_remote_shop_url(?array $cfg): string {
    if (!is_array($cfg)) {
        return '';
    }
    $raw = isset($cfg['rules']['woo_remote_shop_url']) ? trim((string) $cfg['rules']['woo_remote_shop_url']) : '';
    if ($raw === '') {
        return '';
    }
    $url = esc_url_raw($raw);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return '';
    }

    return rtrim($url, '/');
}

/**
 * Base usada para construir enlaces ?add-to-cart= (filtro extensible).
 *
 * @param array<string, mixed> $cfg
 */
function xabia_woo_get_remote_checkout_base(?array $cfg, string $project_id = ''): string {
    $base = xabia_woo_get_remote_shop_url($cfg);
    /** @var string $filtered */
    $filtered = apply_filters('xabia_woo_remote_checkout_base', $base, $project_id, $cfg);

    return is_string($filtered) ? rtrim($filtered, '/') : '';
}

/**
 * @param array<string, mixed> $sql_cfg sql_config del proyecto.
 * @return \wpdb|null
 */
function xabia_woo_remote_wpdb(array $sql_cfg) {
    $host = isset($sql_cfg['host']) ? trim((string) $sql_cfg['host']) : '';
    if ($host !== '' && $host !== 'localhost' && $host !== '127.0.0.1') {
        $db = new \wpdb($sql_cfg['user'] ?? '', $sql_cfg['pass'] ?? '', $sql_cfg['name'] ?? '', $host);
        if (!empty($db->error)) {
            return null;
        }

        return $db;
    }
    global $wpdb;

    return $wpdb instanceof \wpdb ? $wpdb : null;
}

/**
 * @param \wpdb $db
 * @param array<string, mixed> $sql_cfg
 */
function xabia_woo_remote_table_prefix($db, array $sql_cfg): string {
    global $wpdb;
    if ($wpdb instanceof \wpdb && $db === $wpdb) {
        return $wpdb->prefix;
    }

    $manual = isset($sql_cfg['prefix']) ? trim((string) $sql_cfg['prefix']) : '';
    if ($manual !== '') {
        $pref = substr($manual, -1) === '_' ? $manual : $manual . '_';
        $posts_table = $pref . 'posts';
        if ($db->get_var($db->prepare('SHOW TABLES LIKE %s', $posts_table)) === $posts_table) {
            return $pref;
        }
    }

    if ($wpdb instanceof \wpdb) {
        $live_posts = $wpdb->prefix . 'posts';
        if ($db->get_var($db->prepare('SHOW TABLES LIKE %s', $live_posts)) === $live_posts) {
            return $wpdb->prefix;
        }
    }

    $tables = $db->get_results("SHOW TABLES LIKE '%posts'", ARRAY_N);
    if (!is_array($tables) || $tables === []) {
        return ($wpdb instanceof \wpdb) ? $wpdb->prefix : 'wp_';
    }

    $candidates = [];
    foreach ($tables as $row) {
        if (!isset($row[0]) || !is_string($row[0])) {
            continue;
        }
        $name = (string) $row[0];
        if (!str_ends_with($name, 'posts')) {
            continue;
        }
        $prefix = substr($name, 0, -strlen('posts'));
        if ($prefix === '') {
            continue;
        }
        $candidates[] = $prefix;
    }
    if ($candidates === []) {
        return ($wpdb instanceof \wpdb) ? $wpdb->prefix : 'wp_';
    }
    usort($candidates, static function (string $a, string $b): int {
        $score = static function (string $p): int {
            $score = 100 - strlen($p);
            if (str_starts_with($p, 'wp')) {
                $score += 50;
            }

            return $score;
        };

        return $score($b) <=> $score($a);
    });

    return $candidates[0];
}

/**
 * @param array<int|string>|\WP_Error|mixed $data
 * @return list<int>
 */
function xabia_woo_parse_meta_id_list($data): array {
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $id) {
        $id = absint($id);
        if ($id > 0) {
            $out[] = $id;
        }
    }

    return array_values(array_unique($out));
}

/**
 * @return list<int>
 */
function xabia_woo_parse_upsell_crosssell_raw(string $raw_upsell, string $raw_crosssell): array {
    $merged = [];
    foreach ([$raw_upsell, $raw_crosssell] as $raw) {
        $raw = trim($raw);
        if ($raw === '') {
            continue;
        }
        $data = maybe_unserialize($raw);
        foreach (xabia_woo_parse_meta_id_list($data) as $id) {
            $merged[$id] = true;
        }
    }

    return array_keys($merged);
}

/**
 * URLs públicas de ficha producto para conocimiento / recomendaciones.
 */
function xabia_woo_product_public_url(int $product_id, array $cfg): string {
    if ($product_id < 1) {
        return '';
    }
    if (xabia_woo_is_remote_catalog($cfg)) {
        $shop = xabia_woo_get_remote_shop_url($cfg);

        return $shop !== '' ? ($shop . '/?p=' . $product_id) : '';
    }
    if (function_exists('get_permalink')) {
        $u = get_permalink($product_id);

        return is_string($u) ? $u : '';
    }

    return '';
}

/**
 * Títulos de productos por ID (local o misma BD remota que el SQL del proyecto).
 *
 * @param list<int> $ids
 * @return array<int, string>
 */
function xabia_woo_fetch_product_titles(array $ids, array $cfg): array {
    $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
    if ($ids === []) {
        return [];
    }
    if (count($ids) > 24) {
        $ids = array_slice($ids, 0, 24);
    }
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    if (xabia_woo_is_remote_catalog($cfg)) {
        $sql_cfg = isset($cfg['sql_config']) && is_array($cfg['sql_config']) ? $cfg['sql_config'] : [];
        $db = xabia_woo_remote_wpdb($sql_cfg);
        if (!$db) {
            return [];
        }
        $p = xabia_woo_remote_table_prefix($db, $sql_cfg);
        $posts = "{$p}posts";
        /** @var string $sql */
        $sql = "SELECT ID, post_title FROM {$posts} WHERE post_type = 'product' AND post_status = 'publish' AND ID IN ($placeholders)";
        $rows = $db->get_results($db->prepare($sql, $ids), ARRAY_A);
    } else {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND ID IN ($placeholders)",
            $ids
        ), ARRAY_A);
    }
    $out = [];
    if (!is_array($rows)) {
        return [];
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = isset($row['ID']) ? absint($row['ID']) : 0;
        if ($id < 1) {
            continue;
        }
        $out[$id] = trim(wp_strip_all_tags((string) ($row['post_title'] ?? '')));
    }

    return $out;
}

/**
 * Texto para columna Productos_Recomendados (RAG).
 */
function xabia_woo_build_productos_recomendados_text(array $related_ids, array $cfg): string {
    $related_ids = xabia_woo_parse_meta_id_list($related_ids);
    if ($related_ids === []) {
        return '';
    }
    $titles = xabia_woo_fetch_product_titles($related_ids, $cfg);
    $parts = [];
    foreach ($related_ids as $rid) {
        $name = $titles[$rid] ?? '';
        if ($name === '') {
            $name = 'ID ' . $rid;
        }
        $url = xabia_woo_product_public_url((int) $rid, $cfg);
        $parts[] = $url !== '' ? ($name . ' — ' . $url) : $name;
    }

    return implode(' || ', $parts);
}

/**
 * Extrae IDs de producto desde metadatos habituales de packs/bundles.
 *
 * @return list<int>
 */
function xabia_woo_parse_pack_component_ids(string $raw_meta, string $raw_children): array {
    $ids = [];
    $collect = null;
    $collect = static function ($value) use (&$ids, &$collect): void {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_numeric($k) && (int) $k > 0) {
                    $ids[(int) $k] = true;
                }
                $collect($v);
            }

            return;
        }
        if (is_numeric($value) && (int) $value > 0) {
            $ids[(int) $value] = true;

            return;
        }
        if (!is_string($value) || trim($value) === '') {
            return;
        }
        if (preg_match_all('/\b\d+\b/', $value, $m)) {
            foreach ($m[0] as $n) {
                $id = (int) $n;
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }
    };

    foreach ([$raw_children, $raw_meta] as $raw) {
        $raw = trim($raw);
        if ($raw === '') {
            continue;
        }
        $decoded = maybe_unserialize($raw);
        $collect($decoded);
        if ($decoded === $raw) {
            $collect($raw);
        }
    }

    $out = array_keys($ids);
    sort($out, SORT_NUMERIC);

    return array_slice($out, 0, 40);
}

/**
 * Texto para columna Componentes del pack (RAG).
 */
function xabia_woo_build_pack_components_text(array $component_ids, array $cfg): string {
    $component_ids = xabia_woo_parse_meta_id_list($component_ids);
    if ($component_ids === []) {
        return '';
    }
    $titles = xabia_woo_fetch_product_titles($component_ids, $cfg);
    $parts = [];
    foreach ($component_ids as $cid) {
        $name = $titles[$cid] ?? '';
        if ($name === '') {
            continue;
        }
        $url = xabia_woo_product_public_url((int) $cid, $cfg);
        $parts[] = $url !== '' ? ($name . ' — ' . $url) : $name;
    }

    return implode(' || ', $parts);
}

/**
 * URL checkout remoto/local con varios add-to-cart (p. ej. pack camisa + gorro).
 *
 * @param list<int>    $ids
 * @param list<int>    $qtys
 */
function xabia_woo_build_pack_checkout_url(array $ids, array $qtys, array $cfg, string $project_id = ''): string {
    $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
    if ($ids === []) {
        return '';
    }
    $qtys = array_values(array_map('absint', $qtys));
    while (count($qtys) < count($ids)) {
        $qtys[] = 1;
    }
    $qtys = array_slice($qtys, 0, count($ids));
    foreach ($qtys as $i => $q) {
        if ($q < 1) {
            $qtys[$i] = 1;
        }
    }
    $base = xabia_woo_get_remote_checkout_base($cfg, $project_id);
    if ($base === '' && function_exists('home_url')) {
        $base = rtrim((string) home_url(), '/');
    }
    if ($base === '') {
        return '';
    }
    $tpl = $base . '/checkout/?add-to-cart={ids}&quantity={quantities}';
    /** @var string $tpl */
    $tpl = apply_filters('xabia_woo_pack_checkout_url_template', $tpl, $cfg, $project_id, $ids, $qtys);

    return str_replace(
        ['{ids}', '{quantities}'],
        [implode(',', $ids), implode(',', $qtys)],
        is_string($tpl) ? $tpl : ($base . '/checkout/?add-to-cart={ids}&quantity={quantities}')
    );
}

/**
 * Resume clics de carrito enviados desde el widget (JSON en POST).
 *
 * @param list<mixed> $items
 */
function xabia_woo_format_client_cart_intent_block(array $items): string {
    $lines = [];
    $counts = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        if (!empty($it['ids']) && is_array($it['ids'])) {
            $pids = array_values(array_unique(array_filter(array_map('absint', $it['ids']))));
            sort($pids);
            if ($pids === []) {
                continue;
            }
            $label = isset($it['label']) ? trim(sanitize_text_field((string) $it['label'])) : '';
            $key = 'pack:' . implode('-', $pids);
            if (!isset($counts[$key])) {
                $counts[$key] = ['ids' => $pids, 'label' => $label, 'is_pack' => true, 'n' => 0];
            }
            $counts[$key]['n']++;
            if ($label !== '' && $counts[$key]['label'] === '') {
                $counts[$key]['label'] = $label;
            }
            continue;
        }
        $id = isset($it['id']) ? absint($it['id']) : 0;
        if ($id < 1) {
            continue;
        }
        $label = isset($it['label']) ? trim(sanitize_text_field((string) $it['label'])) : '';
        $kind = isset($it['kind']) ? sanitize_key((string) $it['kind']) : 'single';
        $key = $kind . ':' . $id;
        if (!isset($counts[$key])) {
            $counts[$key] = ['id' => $id, 'label' => $label, 'kind' => $kind, 'is_pack' => false, 'n' => 0];
        }
        $counts[$key]['n']++;
        if ($label !== '' && $counts[$key]['label'] === '') {
            $counts[$key]['label'] = $label;
        }
    }
    foreach ($counts as $c) {
        if (!empty($c['is_pack']) && !empty($c['ids'])) {
            $idl = implode(', ', array_map('strval', $c['ids']));
            $lbl = $c['label'] !== '' ? $c['label'] : ('Pack ' . $idl);
            $lines[] = '- ' . $lbl . ' (IDs ' . $idl . '): ' . $c['n'] . ' interacción(es) de compra conjunta';
            continue;
        }
        $lbl = $c['label'] !== '' ? $c['label'] : ('ID ' . $c['id']);
        $kind = $c['kind'] ?? 'single';
        $lines[] = '- Producto ' . $lbl . ' (ID ' . $c['id'] . ', ' . ($kind === 'pack' ? 'pack' : 'unidad') . '): ' . $c['n'] . ' interacción(es) de compra';
    }
    if ($lines === []) {
        return '';
    }

    return implode("\n", $lines);
}

/**
 * Patrones de intención positiva de compra (no insinuaciones débiles tipo solo «sí»).
 */
function xabia_woo_message_signals_purchase_intent(string $user_msg): bool {
    $t = function_exists('mb_strtolower') ? mb_strtolower(trim($user_msg), 'UTF-8') : strtolower(trim($user_msg));
    if ($t === '') {
        return false;
    }
    $patterns = [
        '/\b(me interesa|me interesan|lo quiero|la quiero|los quiero|las quiero)\b/u',
        '/\bquiero (comprar|pedirlo|pedirla|pedirlos|llevármelo|llevármela|este|esta|eso|esa)\b/u',
        '/\b(añadir al carrito|agregar al carrito|añádelo|agregalo|añadirlo|meter(lo)? en el carrito)\b/u',
        '/\b(compr(arlo|arla|amos|o)|hazme el pedido|proces(ar|o) (el )?pedido)\b/u',
        '/\b(me lo llevo|me la llevo|pongo pedido|hago el pedido)\b/u',
        '/\b(i want (it|to buy)|add to cart|buy (it|now))\b/u',
    ];
    foreach ($patterns as $re) {
        if (@preg_match($re, $t) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $args Argumentos del filtro `xabia_agent_context_injection`
 */
function xabia_woo_evaluate_purchase_intent(string $user_msg, bool $has_cart_click_history, array $args = []): bool {
    $forced = apply_filters('xabia_woo_user_purchase_intent', null, $user_msg, $has_cart_click_history, $args);
    if ($forced !== null) {
        return (bool) $forced;
    }
    if ($has_cart_click_history && apply_filters('xabia_woo_cart_clicks_imply_purchase_intent', false, $user_msg, $args)) {
        return true;
    }

    return xabia_woo_message_signals_purchase_intent($user_msg);
}

function xabia_woo_build_crosssell_control_preamble(bool $intent_active): string {
    $mochila = "### MOCHILA_CROSS_SELL (solo metadato del Hub/catálogo)\n"
        . "Las filas de producto pueden incluir la columna Productos_Recomendados (cross/upsell reales). El usuario no ve esta etiqueta; sirve como reserva silenciosa.\n\n"
        . "### CONTROL_DE_INTENCION_COMPRA (esta petición)\n"
        . 'intención_activa: ' . ($intent_active ? 'SÍ' : 'NO') . "\n";
    if (!$intent_active) {
        $mochila .= "Instrucción: NO cites Productos_Recomendados ni propongas complementos (ni en la primera respuesta informativa ni si el usuario solo pide datos). Mantén el foco en el producto preguntado.\n";

        return $mochila;
    }
    $mochila .= "Instrucción: Ya hay señal clara de compra o interés activo. Puedes abrir la mochila con UNA frase breve y natural al final, sin eclipsar el producto principal. Si acepta conjunto (principal + complemento), un solo enlace: [ACTION:CART_PACK:ID_principal,ID_complemento] con los IDs correctos del catálogo.\n";

    return $mochila;
}

/**
 * Cupones vigentes leyendo la misma BD que el conector SQL (sin WC_Coupon).
 *
 * @param array<string, mixed> $cfg Proyecto completo.
 * @return list<array{code: string, description: string}>
 */
function xabia_woo_get_active_coupons_from_sql(array $cfg, int $limit = 40): array {
    $sql_cfg = isset($cfg['sql_config']) && is_array($cfg['sql_config']) ? $cfg['sql_config'] : [];
    $db = xabia_woo_remote_wpdb($sql_cfg);
    if (!$db) {
        return [];
    }
    $limit = max(1, min(80, $limit));
    $p = xabia_woo_remote_table_prefix($db, $sql_cfg);
    $posts = "{$p}posts";
    $pm = "{$p}postmeta";
    $sql = "
SELECT p.post_title AS code,
  COALESCE(NULLIF(TRIM(p.post_excerpt), ''), '') AS description,
  COALESCE(um.meta_value, '') AS usage_limit_raw,
  COALESCE(uc.meta_value, '') AS usage_count_raw,
  COALESCE(de.meta_value, '') AS date_expires_raw
FROM {$posts} p
LEFT JOIN {$pm} um ON p.ID = um.post_id AND um.meta_key = 'usage_limit'
LEFT JOIN {$pm} uc ON p.ID = uc.post_id AND uc.meta_key = 'usage_count'
LEFT JOIN {$pm} de ON p.ID = de.post_id AND de.meta_key = 'date_expires'
WHERE p.post_type = 'shop_coupon' AND p.post_status = 'publish'
ORDER BY p.post_modified DESC
LIMIT %d
";
    $rows = $db->get_results($db->prepare($sql, $limit), ARRAY_A);
    if (!is_array($rows) || $rows === []) {
        return [];
    }
    $now = time();
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = isset($row['code']) ? trim((string) $row['code']) : '';
        if ($code === '') {
            continue;
        }
        $lim = isset($row['usage_limit_raw']) ? (int) $row['usage_limit_raw'] : 0;
        $used = isset($row['usage_count_raw']) ? (int) $row['usage_count_raw'] : 0;
        if ($lim > 0 && $used >= $lim) {
            continue;
        }
        $exp_raw = isset($row['date_expires_raw']) ? trim((string) $row['date_expires_raw']) : '';
        if ($exp_raw !== '' && ctype_digit($exp_raw)) {
            $ts = (int) $exp_raw;
            if ($ts > 0 && $ts < $now) {
                continue;
            }
        } elseif ($exp_raw !== '') {
            $parsed = strtotime($exp_raw);
            if ($parsed !== false && $parsed < $now) {
                continue;
            }
        }
        $out[] = [
            'code'        => $code,
            'description' => trim(wp_strip_all_tags((string) ($row['description'] ?? ''))),
        ];
    }

    return $out;
}

/**
 * Cupones WooCommerce publicados y vigentes (límite de uso y fecha de caducidad).
 * No evalúa restricciones por email ni elegibilidad de productos concretos.
 *
 * @param array<string, mixed>|null $project_config Proyecto; si es remoto Woo, lee cupones vía SQL.
 * @return list<array{code: string, description: string}>
 */
function xabia_woo_get_active_coupons(int $limit = 40, ?array $project_config = null): array {
    if ($project_config !== null && xabia_woo_is_remote_catalog($project_config)) {
        return xabia_woo_get_active_coupons_from_sql($project_config, $limit);
    }
    if (!class_exists('WooCommerce', false) || !class_exists('WC_Coupon', false)) {
        return [];
    }
    $limit = max(1, min(80, $limit));
    $ids = get_posts([
        'post_type'              => 'shop_coupon',
        'post_status'            => 'publish',
        'posts_per_page'         => $limit,
        'orderby'                => 'modified',
        'order'                  => 'DESC',
        'fields'                 => 'ids',
        'suppress_filters'       => true,
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ]);
    if (!is_array($ids) || $ids === []) {
        return [];
    }
    $out = [];
    $now = time();
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id < 1) {
            continue;
        }
        $c = new WC_Coupon($id);
        if (!$c->get_id()) {
            continue;
        }
        $lim = $c->get_usage_limit();
        if ($lim && $c->get_usage_count() >= $lim) {
            continue;
        }
        $exp = $c->get_date_expires();
        if ($exp && method_exists($exp, 'getTimestamp') && $exp->getTimestamp() < $now) {
            continue;
        }
        $code = (string) $c->get_code();
        if ($code === '') {
            continue;
        }
        $out[] = [
            'code'        => $code,
            'description' => trim(wp_strip_all_tags((string) $c->get_description())),
        ];
    }

    return $out;
}

add_action('xabia_register_addons', static function (): void {
    // WooCommerce local no es obligatorio: la fuente addon puede apuntar a una BD Woo remota (SQL).
    if (!xabia_woo_license_gate()) {
        return;
    }
    register_xabia_addon('woo', [
        'name'        => __('WooCommerce — catálogo y ventas', 'xabia-intelligence'),
        'description' => __('Productos WooCommerce, carrito [ACTION:CART:ID] y seguimiento de conversiones.', 'xabia-intelligence'),
        'icon'        => 'shopping_cart',
        'callback'    => ['Xabia_Woo_Connector', 'get_sync_sql'],
    ]);
}, 10);

/**
 * SQL por defecto para sincronización RAG (productos publicados).
 *
 * @deprecated 1.0.0 Use {@see Xabia_Woo_Connector::get_sync_sql()}
 */
function Xabia_Woo_Handler() {
    return Xabia_Woo_Connector::get_sync_sql();
}

class Xabia_Woo_Connector {

    public static function inject_search_logic($current_logic, $project_id, $current_ymd) {
        unset($current_ymd);
        $projects = get_option('xabia_projects_config', []);
        $cfg = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];
        if (($cfg['source_type'] ?? '') !== 'addon' || ($cfg['addon_slug'] ?? '') !== 'woo') {
            return is_string($current_logic) ? $current_logic : '';
        }
        $base = is_string($current_logic) ? $current_logic : '';
        $extra = "\n - REGLA WOO — PRODUCTO: Cada fila es un producto publicado; usa columnas Titulo, Descripcion, SKU, Categorias, tipo_producto, Precio, Precio_regular, descuento_porcentaje, Stock_estado, Stock_cantidad; la columna Productos_Recomendados es metadato de mochila (ver bloque CONTROL_DE_INTENCION_COMPRA en contexto)."
            . "\n - REGLA WOO — MOCHILA / CROSS-SELL: Los datos en Productos_Recomendados están en el contexto de forma silenciosa. NO los uses en respuestas solo informativas ni en el primer contacto sobre el artículo. Solo si CONTROL_DE_INTENCION_COMPRA indica intención_activa: SÍ (o el mensaje del usuario lo revela con frases del tipo «me interesa», «lo quiero», «añadir al carrito»), puedes sugerir como máximo un complemento, breve y al final."
            . "\n - REGLA WOO — VARIABLES: Si tipo_producto es variable, el campo Resumen incluye las opciones de variación (atributos) y puede incluir stock por variante; ayuda a responder talla/color sin inventar combinaciones no listadas."
            . "\n - REGLA WOO — PACKS: Si tipo_producto es grouped, bundle, composite, woosb o similar, usa Pack_Componentes para explicar qué incluye el pack. No inventes componentes no listados."
            . "\n - REGLA WOO — OFERTAS: Si descuento_porcentaje tiene valor numérico, el producto tiene precio rebajado frente a Precio_regular. Preséntalo siempre como **Oferta especial** (no como simple «descuento» genérico) e indica el porcentaje y el precio actual (Precio).";
        if (xabia_woo_is_remote_catalog($cfg)) {
            $extra .= "\n - REGLA WOO — CARRITO (TIENDA REMOTA): Usa [ACTION:CART:ID] con el ID numérico de la fila (columna ID). En el chat se mostrará un enlace de compra directa (add-to-cart) hacia la URL de tienda configurada en el proyecto, no el carrito de este sitio. Los variables requieren elegir variante en la ficha remota; orienta al enlace de producto cuando aplique.";
        } else {
            $extra .= "\n - REGLA WOO — CARRITO: Para añadir al carrito desde el chat usa solo [ACTION:CART:ID] con el ID numérico de la fila (columna ID). Los productos variables suelen requerir elegir variante en la ficha; el ID de la fila es el del producto padre salvo que en Resumen se indique otra variación.";
        }

        return $base . $extra;
    }

    public static function get_sync_sql(): string {
        $p = '{prefix}';

        return "
SELECT
  p.ID AS ID,
  p.post_title AS Titulo,
  NULLIF(TRIM(COALESCE(p.post_excerpt, '')), '') AS Resumen,
  NULLIF(TRIM(COALESCE(p.post_content, '')), '') AS Descripcion,
  (
    SELECT t.slug FROM {$p}term_relationships tr
    INNER JOIN {$p}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_type'
    INNER JOIN {$p}terms t ON tt.term_id = t.term_id
    WHERE tr.object_id = p.ID
    LIMIT 1
  ) AS tipo_producto,
  COALESCE(sku.meta_value, '') AS SKU,
  COALESCE(price.meta_value, '') AS Precio,
  COALESCE(regular.meta_value, '') AS Precio_regular,
  COALESCE(sale.meta_value, '') AS Precio_oferta,
  CASE
    WHEN CAST(NULLIF(TRIM(COALESCE(regular.meta_value, '')), '') AS DECIMAL(14,4)) > 0
      AND CAST(NULLIF(TRIM(COALESCE(sale.meta_value, '')), '') AS DECIMAL(14,4)) > 0
      AND CAST(NULLIF(TRIM(COALESCE(regular.meta_value, '')), '') AS DECIMAL(14,4))
          > CAST(NULLIF(TRIM(COALESCE(sale.meta_value, '')), '') AS DECIMAL(14,4))
    THEN ROUND(
      100 * (
        CAST(NULLIF(TRIM(COALESCE(regular.meta_value, '')), '') AS DECIMAL(14,4))
        - CAST(NULLIF(TRIM(COALESCE(sale.meta_value, '')), '') AS DECIMAL(14,4))
      ) / CAST(NULLIF(TRIM(COALESCE(regular.meta_value, '')), '') AS DECIMAL(14,4)),
      0
    )
    ELSE NULL
  END AS descuento_porcentaje,
  COALESCE(stock_stat.meta_value, '') AS Stock_estado,
  COALESCE(stock_qty.meta_value, '') AS Stock_cantidad,
  COALESCE(upsell.meta_value, '') AS upsell_ids_raw,
  COALESCE(crosssell.meta_value, '') AS crosssell_ids_raw,
  (
    SELECT GROUP_CONCAT(CONCAT(pm.meta_key, '=', pm.meta_value) SEPARATOR ' || ')
    FROM {$p}postmeta pm
    WHERE pm.post_id = p.ID
      AND pm.meta_key IN ('_bundled_ids', '_bundle_data', '_bto_data', '_yith_wcpb_bundle_data', 'woosb_ids', '_woosb_ids', '_wc_pb_bundle_data')
  ) AS pack_meta_raw,
  (
    SELECT GROUP_CONCAT(CONCAT(c.ID, ':', c.post_title) SEPARATOR ' || ')
    FROM {$p}posts c
    WHERE c.post_parent = p.ID
      AND c.post_type = 'product'
      AND c.post_status = 'publish'
  ) AS grouped_children_raw,
  p.guid AS Link,
  (SELECT guid FROM {$p}posts WHERE ID = CAST(NULLIF(thumb.meta_value, '') AS UNSIGNED)) AS Imagen_URL,
  (
    SELECT GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ')
    FROM {$p}term_relationships tr
    JOIN {$p}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
      AND tt.taxonomy IN ('product_cat', 'product_tag')
    JOIN {$p}terms t ON tt.term_id = t.term_id
    WHERE tr.object_id = p.ID
  ) AS Categorias
FROM {$p}posts p
LEFT JOIN {$p}postmeta sku ON p.ID = sku.post_id AND sku.meta_key = '_sku'
LEFT JOIN {$p}postmeta price ON p.ID = price.post_id AND price.meta_key = '_price'
LEFT JOIN {$p}postmeta regular ON p.ID = regular.post_id AND regular.meta_key = '_regular_price'
LEFT JOIN {$p}postmeta sale ON p.ID = sale.post_id AND sale.meta_key = '_sale_price'
LEFT JOIN {$p}postmeta stock_stat ON p.ID = stock_stat.post_id AND stock_stat.meta_key = '_stock_status'
LEFT JOIN {$p}postmeta stock_qty ON p.ID = stock_qty.post_id AND stock_qty.meta_key = '_stock'
LEFT JOIN {$p}postmeta thumb ON p.ID = thumb.post_id AND thumb.meta_key = '_thumbnail_id'
LEFT JOIN {$p}postmeta upsell ON p.ID = upsell.post_id AND upsell.meta_key = '_upsell_ids'
LEFT JOIN {$p}postmeta crosssell ON p.ID = crosssell.post_id AND crosssell.meta_key = '_crosssell_ids'
WHERE p.post_type = 'product' AND p.post_status = 'publish'
ORDER BY p.post_modified DESC
";
    }

    /**
     * Texto de atributos de variación (etiqueta: opciones) para productos variables.
     */
    public static function format_variable_attribute_summary($product): string {
        if (!$product instanceof WC_Product_Variable) {
            return '';
        }
        $parts = [];
        foreach ($product->get_attributes() as $attribute) {
            if (!$attribute->get_variation()) {
                continue;
            }
            $label = wc_attribute_label($attribute->get_name());
            $options = [];
            if ($attribute->is_taxonomy()) {
                foreach ($attribute->get_options() as $term_id) {
                    $term = get_term((int) $term_id);
                    if ($term && !is_wp_error($term)) {
                        $options[] = $term->name;
                    }
                }
            } else {
                foreach ($attribute->get_options() as $opt) {
                    $opt = is_string($opt) ? trim($opt) : '';
                    if ($opt !== '') {
                        $options[] = $opt;
                    }
                }
            }
            $options = array_values(array_unique($options));
            if ($options !== [] && $label !== '') {
                $parts[] = $label . ': ' . implode(', ', $options);
            }
        }

        return implode(' | ', $parts);
    }

    /**
     * Detalle por variación (ID, atributos, precio, stock) para enriquecer Resumen.
     */
    public static function format_variable_variations_stock($product): string {
        if (!$product instanceof WC_Product_Variable) {
            return '';
        }
        $lines = [];
        foreach ($product->get_children() as $vid) {
            $vid = (int) $vid;
            if ($vid < 1) {
                continue;
            }
            $v = wc_get_product($vid);
            if (!$v || !$v->exists()) {
                continue;
            }
            $attrs = [];
            foreach ($v->get_attributes() as $tax => $slug) {
                if ($slug === '' || $slug === null) {
                    continue;
                }
                $tax = (string) $tax;
                if (strpos($tax, 'pa_') === 0) {
                    $term = get_term_by('slug', (string) $slug, $tax);
                    $attrs[] = ($term && !is_wp_error($term) ? $term->name : $slug);
                } else {
                    $attrs[] = (string) $slug;
                }
            }
            $attr_str = implode(' / ', $attrs);
            $price = $v->get_price();
            $st = $v->get_stock_status();
            $qty = $v->get_manage_stock() ? (string) $v->get_stock_quantity() : '';
            $line = sprintf('variación ID %d', $vid);
            if ($attr_str !== '') {
                $line .= ' — ' . $attr_str;
            }
            if ($price !== '') {
                $line .= ' — precio ' . $price;
            }
            $line .= ' — stock ' . $st;
            if ($qty !== '') {
                $line .= ' (uds: ' . $qty . ')';
            }
            $lines[] = $line;
        }

        return implode('; ', $lines);
    }

    /**
     * Preset de mapeo para la UI (Conectar y Mapear).
     *
     * @return list<array{csv_col:string,label:string,visual_role:string,is_ente:int,instruction:string}>
     */
    public static function default_mapping_fields(): array {
        return [
            [
                'csv_col'     => 'ID',
                'label'       => __('ID del producto', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 1,
                'instruction' => __('Identificador del post product (carrito [ACTION:CART:ID], deduplicación).', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'Titulo',
                'label'       => __('Nombre del producto', 'xabia-intelligence'),
                'visual_role' => 'title',
                'is_ente'     => 0,
                'instruction' => __('Título comercial para listados y búsqueda.', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'Resumen',
                'label'       => __('Resumen / extracto', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => __('Extracto del producto; en variables se amplía al indexar con opciones y stock por variante.', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'Descripcion',
                'label'       => __('Descripción larga', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => __('Contenido largo del producto (post_content), útil para beneficios, ingredientes, instrucciones y detalles comerciales.', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'tipo_producto',
                'label'       => __('Tipo (simple, variable…)', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => __('Slug de taxonomía product_type.', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'SKU',
                'label'       => __('SKU', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => __('Referencia de inventario.', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'Precio',
                'label'       => __('Precio actual', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => __('Meta _price (en oferta suele ser el precio rebajado; en variables puede ser «desde»).', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'Precio_regular',
                'label'       => __('Precio de referencia', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => __('Precio regular (_regular_price).', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'Precio_oferta',
                'label'       => __('Precio oferta', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => __('Meta _sale_price cuando hay rebaja.', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'descuento_porcentaje',
                'label'       => __('% descuento', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => __('Porcentaje entre regular y oferta; la IA debe presentarlo como Oferta especial.', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'Stock_estado',
                'label'       => __('Estado de stock', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => __('instock, outofstock, onbackorder…', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'Stock_cantidad',
                'label'       => __('Cantidad en stock', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => __('_stock cuando la gestión de inventario está activa.', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'Link',
                'label'       => __('URL de compra', 'xabia-intelligence'),
                'visual_role' => 'url',
                'is_ente'     => 0,
                'instruction' => __('Enlace al producto (se sustituye por permalink al indexar).', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'Imagen_URL',
                'label'       => __('Imagen', 'xabia-intelligence'),
                'visual_role' => 'image',
                'is_ente'     => 0,
                'instruction' => __('GUID del adjunto destacado; enriquecible en plantilla.', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'Categorias',
                'label'       => __('Categorías y etiquetas', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => __('Taxonomías product_cat y product_tag.', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'Productos_Recomendados',
                'label'       => __('Productos recomendados (up-sell / cross-sell)', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => __('Generado al indexar: nombres y URLs desde _upsell_ids y _crosssell_ids.', 'xabia-intelligence'),
            ],
            [
                'csv_col'     => 'Pack_Componentes',
                'label'       => __('Componentes del pack', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 0,
                'instruction' => __('Generado al indexar: productos incluidos en packs, bundles o agrupados cuando la BD expone esos metadatos.', 'xabia-intelligence'),
            ],
        ];
    }
}

/**
 * Descubre productos para el bloque ADDON DISCOVERY del chat.
 */
function xabia_woo_discover_products($limit = 80) {
    if (!class_exists('WooCommerce', false)) {
        return '';
    }
    global $wpdb;
    $limit = max(1, min(200, (int) $limit));
    $posts = $wpdb->posts;
    $pm = $wpdb->postmeta;

    $sql = "
SELECT p.ID, p.post_title,
  MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END) AS price_val,
  MAX(CASE WHEN pm.meta_key = '_stock_status' THEN pm.meta_value END) AS stock_status,
  MAX(CASE WHEN pm.meta_key = '_thumbnail_id' THEN pm.meta_value END) AS thumb_id
FROM {$posts} p
INNER JOIN {$pm} pm ON p.ID = pm.post_id AND pm.meta_key IN ('_price','_stock_status','_thumbnail_id')
WHERE p.post_type = 'product' AND p.post_status = 'publish'
GROUP BY p.ID, p.post_title
ORDER BY MAX(p.post_modified) DESC
LIMIT %d
";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $limit), ARRAY_A);
    if (!is_array($rows) || $rows === []) {
        return '';
    }

    $lines = ['[WooCommerce] Catálogo local (productos publicados, datos en la idioma/tienda del sitio):'];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = isset($row['ID']) ? (int) $row['ID'] : 0;
        $title = isset($row['post_title']) ? trim((string) $row['post_title']) : '';
        if ($id < 1 || $title === '') {
            continue;
        }
        $price = isset($row['price_val']) ? (string) $row['price_val'] : '';
        $stock = isset($row['stock_status']) ? (string) $row['stock_status'] : '';
        $thumb_id = isset($row['thumb_id']) ? (int) $row['thumb_id'] : 0;
        $img_url = $thumb_id > 0 ? (string) wp_get_attachment_image_url($thumb_id, 'medium') : '';
        $url = (string) get_permalink($id);
        $lines[] = sprintf(
            '- ID %d — %s — Precio (_price): %s — Stock (_stock_status): %s — URL compra: %s%s',
            $id,
            $title,
            $price !== '' ? $price : '—',
            $stock !== '' ? $stock : '—',
            $url !== '' ? $url : '—',
            $img_url !== '' ? ' — Imagen: ' . $img_url : ''
        );
    }

    return implode("\n", $lines);
}

/**
 * Muestra de catálogo vía SQL (misma BD que el conector) para ADDON DISCOVERY sin Woo local.
 *
 * @param array<string, mixed> $cfg
 */
function xabia_woo_discover_products_remote(array $cfg, $limit = 80): string {
    $sql_cfg = isset($cfg['sql_config']) && is_array($cfg['sql_config']) ? $cfg['sql_config'] : [];
    $db = xabia_woo_remote_wpdb($sql_cfg);
    if (!$db) {
        return '';
    }
    $limit = max(1, min(200, (int) $limit));
    $p = xabia_woo_remote_table_prefix($db, $sql_cfg);
    $posts = "{$p}posts";
    $pm = "{$p}postmeta";
    $sql = "
SELECT p.ID, p.post_title, p.post_name,
  MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END) AS price_val,
  MAX(CASE WHEN pm.meta_key = '_stock_status' THEN pm.meta_value END) AS stock_status
FROM {$posts} p
INNER JOIN {$pm} pm ON p.ID = pm.post_id AND pm.meta_key IN ('_price','_stock_status')
WHERE p.post_type = 'product' AND p.post_status = 'publish'
GROUP BY p.ID, p.post_title, p.post_name
ORDER BY MAX(p.post_modified) DESC
LIMIT %d
";
    $rows = $db->get_results($db->prepare($sql, $limit), ARRAY_A);
    if (!is_array($rows) || $rows === []) {
        return '';
    }
    $shop = xabia_woo_get_remote_shop_url($cfg);
    $lines = ['[WooCommerce] Catálogo remoto (BD conector SQL, URL tienda según proyecto):'];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = isset($row['ID']) ? (int) $row['ID'] : 0;
        $title = isset($row['post_title']) ? trim((string) $row['post_title']) : '';
        if ($id < 1 || $title === '') {
            continue;
        }
        $price = isset($row['price_val']) ? (string) $row['price_val'] : '';
        $stock = isset($row['stock_status']) ? (string) $row['stock_status'] : '';
        $url = '—';
        if ($shop !== '') {
            $url = $shop . '/?p=' . $id;
        }
        $lines[] = sprintf(
            '- ID %d — %s — Precio (_price): %s — Stock (_stock_status): %s — URL compra: %s',
            $id,
            $title,
            $price !== '' ? $price : '—',
            $stock !== '' ? $stock : '—',
            $url
        );
    }

    return implode("\n", $lines);
}

add_filter(
    'xabia_chat_addon_discovery_blocks',
    static function ($blocks, $project_id, $config) {
        if (!is_array($blocks)) {
            $blocks = [];
        }
        if (!is_array($config)) {
            return $blocks;
        }
        if (($config['source_type'] ?? '') !== 'addon' || ($config['addon_slug'] ?? '') !== 'woo') {
            return $blocks;
        }
        $summary = '';
        if (class_exists('WooCommerce', false)) {
            $summary = xabia_woo_discover_products(80);
        } elseif (xabia_woo_is_remote_catalog($config)) {
            $summary = xabia_woo_discover_products_remote($config, 80);
        }
        if ($summary !== '') {
            $blocks[] = $summary;
        }

        return $blocks;
    },
    10,
    3
);

add_filter(
    'xabia_system_prompt_rules',
    static function ($rules, $context, $args) {
        if (!in_array($context, ['rag_behavior', 'interpreter'], true)) {
            return $rules;
        }
        $config = isset($args['config']) && is_array($args['config']) ? $args['config'] : [];
        if (($config['source_type'] ?? '') !== 'addon' || ($config['addon_slug'] ?? '') !== 'woo') {
            return $rules;
        }
        $woo_ok = class_exists('WooCommerce', false) || xabia_woo_is_remote_catalog($config);
        if (!$woo_ok) {
            return $rules;
        }

        $coupons = xabia_woo_get_active_coupons(30, $config);
        $coupon_line = '';
        if ($coupons !== []) {
            $parts = [];
            foreach ($coupons as $c) {
                $code = $c['code'];
                $desc = isset($c['description']) ? trim((string) $c['description']) : '';
                $parts[] = $desc !== '' ? $code . ' (' . $desc . ')' : $code;
            }
            $coupon_line = 'CUPONES DE LA TIENDA (solo estos códigos existen; no inventes otros): ' . implode('; ', $parts)
                . '. Cuando encaje la conversación, puedes presentarlo con entusiasmo, por ejemplo: «¡Tengo un cupón de descuento para ti! Usa el código [CÓDIGO]» — usando exactamente el código indicado.';
        }

        $rules = is_string($rules) ? trim($rules) : '';

        if ($context === 'interpreter') {
            $append = 'WOO — Intérprete: prioriza Titulo, Categorias, Resumen, SKU, tipo_producto. Productos_Recomendados existe como mochila silenciosa: no priorices esos términos salvo que la petición lleve intención de compra (véase CONTROL_DE_INTENCION_COMPRA en contexto).'
                . ' Si el usuario pregunta por ofertas, busca descuento_porcentaje o Precio_oferta vs Precio_regular.';
            if ($coupon_line !== '') {
                $append .= "\n" . $coupon_line;
            }

            return $rules === '' ? $append : $rules . "\n\n" . $append;
        }

        $sales = 'PERSONALIDAD DE VENTAS (Woo, no intrusivo): Responde primero a la necesidad informativa; destaca precio/stock/detalles del producto consultado. Los complementos (Productos_Recomendados) están en contexto como mochila silenciosa: respeta CONTROL_DE_INTENCION_COMPRA — si intención_activa es NO, no propongas cross-sell aunque veas la columna.'
            . ' Si intención_activa es SÍ, una sola mención breve (una frase) al final, opcional, sin robar protagonismo. Oferta especial cuando descuento_porcentaje aplique.'
            . ' Si aparece «CARRITO CONVERSACIONAL», úsalo con tacto para continuidad, sin rellenar con sugerencias no pedidas.'
            . ' Formato producto principal: **Nombre** — precio — stock/variantes — URL.';
        if (xabia_woo_is_remote_catalog($config)) {
            $sales .= ' Cierre: [ACTION:CART:ID] para un solo artículo hacia la tienda remota; si acepta principal + complemento, un enlace: [ACTION:CART_PACK:ID_principal,ID_complemento].';
        } else {
            $sales .= ' Cierre: [ACTION:CART:ID]; pack conjunto: [ACTION:CART_PACK:ID_principal,ID_complemento] cuando el usuario acepte ambos.';
        }
        $sales .= ' MULTILINGÜE: respeta user_lang; no inventes precios, cupones ni stock.';

        $append = $sales;
        if ($coupon_line !== '') {
            $append .= "\n\n" . $coupon_line;
        }
        $append .= "\n" . 'REGLA DE CIERRE: Tu objetivo es la conversión. Usa solo datos del contexto.';

        return $rules === '' ? $append : $rules . "\n\n" . $append;
    },
    20,
    3
);

add_filter(
    'xabia_mapping_column_suggestions',
    static function ($list, $project_id, $post_type) {
        if ($post_type !== 'product') {
            return $list;
        }
        $projects = get_option('xabia_projects_config', []);
        $cfg = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];
        $woo_ctx = (($cfg['source_type'] ?? '') === 'addon' && ($cfg['addon_slug'] ?? '') === 'woo');
        if (!$woo_ctx && !class_exists('WooCommerce', false)) {
            return $list;
        }
        $list = is_array($list) ? $list : [];
        foreach (xabia_woo_deep_schema_meta_keys() as $k) {
            if (!in_array($k, $list, true)) {
                $list[] = $k;
            }
        }
        $list = array_values(array_unique(array_map('strval', $list)));
        sort($list, SORT_STRING);

        return $list;
    },
    10,
    3
);

add_filter(
    'xabia_deep_schema_meta_fields',
    static function ($meta_list, $post_type, $project_id) {
        if ($post_type !== 'product' || !is_array($meta_list)) {
            return $meta_list;
        }
        $labels = xabia_woo_schema_meta_label_map();
        $seen = [];
        foreach ($meta_list as $i => $row) {
            if (!is_array($row) || empty($row['key'])) {
                continue;
            }
            $key = (string) $row['key'];
            $seen[$key] = true;
            if (isset($labels[$key])) {
                $meta_list[$i]['label'] = $labels[$key];
            }
        }
        foreach (xabia_woo_deep_schema_meta_keys() as $key) {
            if (isset($seen[$key])) {
                continue;
            }
            $meta_list[] = [
                'key'   => $key,
                'label' => $labels[$key] ?? $key,
            ];
            $seen[$key] = true;
        }
        usort(
            $meta_list,
            static function ($a, $b) {
                return strcasecmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? ''));
            }
        );

        return $meta_list;
    },
    20,
    3
);

add_filter(
    'xabia_deep_schema_mapping_hints',
    static function ($hints, $post_type, $project_id) {
        if ($post_type !== 'product') {
            return $hints;
        }
        $hints = is_array($hints) ? $hints : [];
        foreach (['ID', 'post_title', 'post_excerpt', 'post_content', '_price', '_sku', '_stock_status', '_thumbnail_id', 'tax_product_cat'] as $h) {
            if (!in_array($h, $hints, true)) {
                $hints[] = $h;
            }
        }

        return array_values(array_unique(array_map('strval', $hints)));
    },
    20,
    3
);

add_filter(
    'xabia_discover_cpt_fields_result',
    static function ($payload, $post_type, $args) {
        if ($post_type !== 'product' || !is_array($payload)) {
            return $payload;
        }
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $labels = xabia_woo_schema_meta_label_map();
        $seen = [];
        foreach ($meta as $row) {
            if (is_array($row) && !empty($row['key'])) {
                $seen[(string) $row['key']] = true;
            }
        }
        foreach (xabia_woo_deep_schema_meta_keys() as $key) {
            if (isset($seen[$key])) {
                continue;
            }
            $meta[] = [
                'key'   => $key,
                'label' => $labels[$key] ?? $key,
            ];
        }
        $payload['meta'] = $meta;
        if (empty($payload['mapping_hints']) || !is_array($payload['mapping_hints'])) {
            $payload['mapping_hints'] = ['ID', 'post_title', '_price', '_sku', '_stock_status'];
        }

        return $payload;
    },
    20,
    3
);

function xabia_woo_add_to_cart_ajax() {
    if (!class_exists('WooCommerce', false)) {
        wp_send_json_error(['message' => __('WooCommerce no está activo.', 'xabia-intelligence')]);
    }
    check_ajax_referer('xabia_woo_cart', 'nonce');

    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    if ($product_id < 1) {
        wp_send_json_error(['message' => __('Producto no válido.', 'xabia-intelligence')]);
    }

    $product = wc_get_product($product_id);
    if (!$product || !$product->is_purchasable()) {
        wp_send_json_error(['message' => __('Producto no disponible.', 'xabia-intelligence')]);
    }

    if (WC()->cart === null && function_exists('wc_load_cart')) {
        wc_load_cart();
    }

    $added = WC()->cart->add_to_cart($product_id);
    if ($added) {
        if (!session_id() && !headers_sent()) {
            session_start();
        }
        $_SESSION['xabia_last_recommendation'] = true;
        $_SESSION['xabia_last_project'] = sanitize_text_field(wp_unslash($_POST['xabia_project_id'] ?? 'default'));

        wp_send_json_success([
            'message'   => __('Producto añadido al carrito.', 'xabia-intelligence'),
            'cart_hash' => WC()->cart->get_cart_hash(),
            'fragments' => apply_filters('woocommerce_add_to_cart_fragments', []),
        ]);
    }

    $notices = wc_get_notices('error');
    wc_clear_notices();
    $msg = !empty($notices[0]['notice']) ? wp_strip_all_tags($notices[0]['notice']) : __('No se pudo añadir al carrito.', 'xabia-intelligence');
    wp_send_json_error(['message' => $msg]);
}

add_action('wp_ajax_xabia_woo_add_to_cart', 'xabia_woo_add_to_cart_ajax');
add_action('wp_ajax_nopriv_xabia_woo_add_to_cart', 'xabia_woo_add_to_cart_ajax');

/**
 * Carrito en front: AJAX con WC local o enlace add-to-cart hacia tienda remota.
 *
 * @param array<string, mixed>|null $payload
 * @param array<string, mixed>|null $project_data
 * @return array<string, mixed>|null
 */
add_filter(
    'xabia_chatbox_woo_cart_payload',
    static function ($payload, $project_id, $project_data) {
        if (!is_array($project_data)) {
            return null;
        }
        if (($project_data['source_type'] ?? '') !== 'addon' || ($project_data['addon_slug'] ?? '') !== 'woo') {
            return null;
        }
        if (class_exists('WooCommerce', false)) {
            $pack_base = rtrim((string) home_url(), '/');
            /** @var string $pack_tpl */
            $pack_tpl = apply_filters('xabia_woo_local_pack_url_template', $pack_base . '/checkout/?add-to-cart={ids}&quantity={quantities}', (string) $project_id, $project_data);

            return [
                'mode'              => 'ajax',
                'ajaxUrl'           => admin_url('admin-ajax.php'),
                'nonce'             => wp_create_nonce('xabia_woo_cart'),
                'action'            => 'xabia_woo_add_to_cart',
                'addedMsg'          => __('¡Producto añadido!', 'xabia-intelligence'),
                'packUrlTemplate'   => is_string($pack_tpl) ? $pack_tpl : ($pack_base . '/checkout/?add-to-cart={ids}&quantity={quantities}'),
                'packCheckoutLabel' => __('Comprar pack', 'xabia-intelligence'),
            ];
        }
        $base = xabia_woo_get_remote_checkout_base($project_data, (string) $project_id);
        if ($base === '') {
            return null;
        }
        $template = $base . '/?add-to-cart={id}';
        /** @var string $template */
        $template = apply_filters('xabia_woo_remote_add_to_cart_url_template', $template, (string) $project_id, $project_data);
        /** @var string $pack_remote */
        $pack_remote = apply_filters('xabia_woo_remote_pack_url_template', $base . '/checkout/?add-to-cart={ids}&quantity={quantities}', (string) $project_id, $project_data);

        return [
            'mode'              => 'redirect',
            'urlTemplate'       => is_string($template) ? $template : ($base . '/?add-to-cart={id}'),
            'checkoutLabel'     => __('Comprar ahora', 'xabia-intelligence'),
            'packUrlTemplate'   => is_string($pack_remote) ? $pack_remote : ($base . '/checkout/?add-to-cart={ids}&quantity={quantities}'),
            'packCheckoutLabel' => __('Comprar pack', 'xabia-intelligence'),
        ];
    },
    10,
    3
);

add_filter(
    'xabia_agent_context_injection',
    static function ($context, $project_id, $config, $args) {
        unset($project_id);
        if (!is_string($context)) {
            $context = '';
        }
        if (!is_array($config) || ($config['source_type'] ?? '') !== 'addon' || ($config['addon_slug'] ?? '') !== 'woo') {
            return $context;
        }
        $user_msg = isset($args['user_message']) ? (string) $args['user_message'] : '';
        $cart_section = '';
        $raw = isset($_POST['woo_cart_intent']) ? wp_unslash((string) $_POST['woo_cart_intent']) : '';
        if ($raw !== '' && strlen($raw) <= 32000) {
            $items = json_decode($raw, true);
            if (is_array($items) && $items !== []) {
                $items = array_slice($items, 0, 50);
                $block = xabia_woo_format_client_cart_intent_block($items);
                if ($block !== '') {
                    $cart_section = "\n\n### CARRITO CONVERSACIONAL (clics del usuario en esta sesión) ###\n" . $block
                        . "\nÚsalo solo para continuidad; no fuerces ventas cruzadas si CONTROL_DE_INTENCION_COMPRA desactiva sugerencias.";
                }
            }
        }
        $has_cart_clicks = $cart_section !== '';
        $intent = xabia_woo_evaluate_purchase_intent($user_msg, $has_cart_clicks, is_array($args) ? $args : []);
        $preamble = xabia_woo_build_crosssell_control_preamble($intent);

        return $preamble . "\n\n" . $context . $cart_section;
    },
    18,
    4
);

add_action(
    'xabia_install_addon_tables',
    static function (): void {
        if (!class_exists('Xabia_DB', false)) {
            return;
        }
        global $wpdb;
        $table_name = Xabia_DB::table('conversions');
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        project_id varchar(50) NOT NULL,
        log_id bigint(20),
        order_id bigint(20) NOT NULL,
        order_total decimal(10,2) NOT NULL,
        product_ids text NOT NULL,
        status varchar(20) DEFAULT 'completed',
        PRIMARY KEY  (id),
        KEY order_id (order_id)
    ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
);

add_action(
    'woocommerce_thankyou',
    static function ($order_id) {
        if (!$order_id) {
            return;
        }

        global $wpdb;

        if (!isset($_SESSION['xabia_last_recommendation']) || $_SESSION['xabia_last_recommendation'] !== true) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $product_ids = [];
        foreach ($order->get_items() as $item) {
            $product_ids[] = $item->get_product_id();
        }

        if (!class_exists('Xabia_DB', false)) {
            return;
        }

        $wpdb->insert(
            Xabia_DB::table('conversions'),
            [
                'time'        => current_time('mysql'),
                'project_id'  => $_SESSION['xabia_last_project'] ?? 'default',
                'log_id'      => $_SESSION['xabia_last_log_id'] ?? 0,
                'order_id'    => $order_id,
                'order_total' => $order->get_total(),
                'product_ids' => implode(',', $product_ids),
                'status'      => $order->get_status(),
            ]
        );

        unset($_SESSION['xabia_last_recommendation']);
    },
    10,
    1
);

add_filter(
    'xabia_knowledge_sync_enrich_row',
    static function ($row, $project_id, $mapping) {
        if (!is_array($row) || !is_array($mapping)) {
            return $row;
        }
        $projects = get_option('xabia_projects_config', []);
        $cfg = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];
        $is_woo = (($cfg['source_type'] ?? '') === 'addon' && ($cfg['addon_slug'] ?? '') === 'woo');
        if (!$is_woo) {
            return $row;
        }
        $pid = 0;
        if (isset($row['ID'])) {
            $pid = absint($row['ID']);
        } elseif (isset($row['id'])) {
            $pid = absint($row['id']);
        }
        if ($pid <= 0) {
            return $row;
        }

        $raw_up = isset($row['upsell_ids_raw']) ? (string) $row['upsell_ids_raw'] : '';
        $raw_x = isset($row['crosssell_ids_raw']) ? (string) $row['crosssell_ids_raw'] : '';
        if ($raw_up === '' && $raw_x === '' && !xabia_woo_is_remote_catalog($cfg) && function_exists('get_post_meta') && get_post_type($pid) === 'product') {
            $maybe_up = get_post_meta($pid, '_upsell_ids', true);
            $maybe_x = get_post_meta($pid, '_crosssell_ids', true);
            if (is_array($maybe_up)) {
                $raw_up = maybe_serialize($maybe_up);
            } elseif (is_string($maybe_up)) {
                $raw_up = $maybe_up;
            }
            if (is_array($maybe_x)) {
                $raw_x = maybe_serialize($maybe_x);
            } elseif (is_string($maybe_x)) {
                $raw_x = $maybe_x;
            }
        }
        $rel_ids = xabia_woo_parse_upsell_crosssell_raw($raw_up, $raw_x);
        if ($rel_ids !== []) {
            $row['Productos_Recomendados'] = xabia_woo_build_productos_recomendados_text($rel_ids, $cfg);
        }
        $pack_raw = isset($row['pack_meta_raw']) ? (string) $row['pack_meta_raw'] : '';
        $grouped_raw = isset($row['grouped_children_raw']) ? (string) $row['grouped_children_raw'] : '';
        $pack_ids = xabia_woo_parse_pack_component_ids($pack_raw, $grouped_raw);
        if ($pack_ids !== []) {
            $pack_text = xabia_woo_build_pack_components_text($pack_ids, $cfg);
            if ($pack_text !== '') {
                $row['Pack_Componentes'] = $pack_text;
            }
        }
        unset($row['upsell_ids_raw'], $row['crosssell_ids_raw'], $row['pack_meta_raw'], $row['grouped_children_raw']);

        if (xabia_woo_is_remote_catalog($cfg)) {
            $shop = xabia_woo_get_remote_shop_url($cfg);
            foreach ($mapping as $m) {
                if (($m['csv_col'] ?? '') === 'Link' && $shop !== '') {
                    $row['Link'] = $shop . '/?p=' . $pid;
                    break;
                }
            }

            return $row;
        }

        if (get_post_type($pid) !== 'product') {
            return $row;
        }
        if (!function_exists('wc_get_product')) {
            return $row;
        }
        foreach ($mapping as $m) {
            if (($m['csv_col'] ?? '') === 'Link') {
                $u = get_permalink($pid);
                if (is_string($u) && $u !== '') {
                    $row['Link'] = $u;
                }
                break;
            }
        }
        foreach ($mapping as $m) {
            if (($m['csv_col'] ?? '') !== 'Imagen_URL') {
                continue;
            }
            $raw = isset($row['Imagen_URL']) ? trim((string) $row['Imagen_URL']) : '';
            if ($raw === '') {
                break;
            }
            $att = attachment_url_to_postid($raw);
            if ($att > 0) {
                $img = wp_get_attachment_image_url($att, 'large');
                if (is_string($img) && $img !== '') {
                    $row['Imagen_URL'] = $img;
                }
            }
            break;
        }

        $product = wc_get_product($pid);
        if ($product && $product->is_type('variable')) {
            $attr_line = Xabia_Woo_Connector::format_variable_attribute_summary($product);
            $var_line = Xabia_Woo_Connector::format_variable_variations_stock($product);
            $blocks = [];
            if ($attr_line !== '') {
                $blocks[] = $attr_line;
            }
            if ($var_line !== '') {
                $blocks[] = __('Detalle por variante', 'xabia-intelligence') . ': ' . $var_line;
            }
            if ($blocks !== []) {
                $resumen = isset($row['Resumen']) && $row['Resumen'] !== null ? trim((string) $row['Resumen']) : '';
                $label = __('Opciones de variación', 'xabia-intelligence');
                $body = implode("\n", $blocks);
                $row['Resumen'] = $resumen === '' ? ($label . ': ' . $body) : $resumen . "\n\n" . $label . ":\n" . $body;
            }
        }

        return $row;
    },
    10,
    3
);
