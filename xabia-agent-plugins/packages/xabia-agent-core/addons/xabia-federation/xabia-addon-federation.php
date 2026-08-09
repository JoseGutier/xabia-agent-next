<?php
/**
 * Addon: Federación Nexus (puente REST, nodos amigos, ask_federated_node).
 * Ruta: addons/xabia-federation/xabia-addon-federation.php
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Página «Federación Nexus»: siempre registrada como pantalla oculta (sin entrada en el menú).
 * Enlace desde Xabia Agent → Avanzado. Para mostrarla en el menú lateral:
 * add_filter('xabia_federation_show_admin_menu', '__return_true');
 * y registrar de nuevo el submenú bajo xabia-settings si lo necesitas.
 */
add_action('admin_menu', static function () {
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    add_submenu_page(
        null,
        __('Federación Nexus', 'xabia-intelligence'),
        '',
        'manage_options',
        'xabia-federation-nexus',
        'xabia_federation_nexus_render_admin_page'
    );
}, 30);

add_action('admin_init', static function () {
    if (!isset($_GET['page']) || $_GET['page'] !== 'xabia-federation-nexus' || !current_user_can('manage_options')) {
        return;
    }
    if (!empty($_GET['federation_gen_key']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'xabia_federation_gen_key')) {
        $k = bin2hex(random_bytes(24));
        update_option(Xabia_Federation_Nexus::OPTION_BRIDGE_KEY, $k, false);
        wp_safe_redirect(admin_url('admin.php?page=xabia-federation-nexus&keygen=1'));
        exit;
    }
}, 9);

add_action('admin_init', static function () {
    if (!isset($_POST['xabia_action']) || $_POST['xabia_action'] !== 'save_federation_nexus' || !current_user_can('manage_options')) {
        return;
    }
    check_admin_referer('xabia_federation_nexus_save', 'xabia_federation_nonce');

    if (Xabia_Federation_Nexus::get_bridge_secret() === '') {
        Xabia_Federation_Nexus::ensure_bridge_secret();
    }

    $nodes = [];
    $rows = isset($_POST['fed_node']) && is_array($_POST['fed_node']) ? $_POST['fed_node'] : [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = isset($row['name']) ? sanitize_text_field(wp_unslash($row['name'])) : '';
        $url = isset($row['url']) ? esc_url_raw(wp_unslash($row['url'])) : '';
        if ($name === '' || $url === '') {
            continue;
        }
        $slug_in = isset($row['slug']) ? sanitize_title(wp_unslash($row['slug'])) : '';
        $slug = $slug_in !== '' ? $slug_in : sanitize_title($name);
        $nodes[] = [
            'name'       => $name,
            'url'        => $url,
            'category'   => isset($row['category']) ? sanitize_text_field(wp_unslash($row['category'])) : '',
            'api_key'    => isset($row['api_key']) ? sanitize_text_field(wp_unslash($row['api_key'])) : '',
            'project_id' => isset($row['project_id']) ? sanitize_key(wp_unslash($row['project_id'])) : '',
            'slug'       => $slug,
        ];
    }
    $used = [];
    foreach ($nodes as &$n) {
        $base = $n['slug'];
        $s = $base;
        $i = 2;
        while (isset($used[$s])) {
            $s = $base . '-' . $i;
            $i++;
        }
        $used[$s] = true;
        $n['slug'] = $s;
    }
    unset($n);

    update_option(Xabia_Federation_Nexus::OPTION_NODES, $nodes, false);
    wp_safe_redirect(admin_url('admin.php?page=xabia-federation-nexus&updated=1'));
    exit;
}, 15);

function xabia_federation_nexus_render_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $nodes = Xabia_Federation_Nexus::get_friend_nodes();
    while (count($nodes) < 6) {
        $nodes[] = [
            'name'       => '',
            'url'        => '',
            'category'   => '',
            'api_key'    => '',
            'project_id' => '',
            'slug'       => '',
        ];
    }
    $key = Xabia_Federation_Nexus::get_bridge_secret();
    if ($key === '') {
        $key = Xabia_Federation_Nexus::ensure_bridge_secret();
    }
    $fed_url = rest_url('xabia/v1/federate');
    $gen_url = wp_nonce_url(
        admin_url('admin.php?page=xabia-federation-nexus&federation_gen_key=1'),
        'xabia_federation_gen_key'
    );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Federación Nexus', 'xabia-intelligence'); ?></h1>
        <p class="description"><?php echo esc_html__('Configura nodos amigos para que el chat (OpenAI) pueda consultar remotamente vía ask_federated_node, y la clave que deben usar otros sitios al llamar a tu endpoint /federate.', 'xabia-intelligence'); ?></p>

        <?php if (!empty($_GET['updated'])) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Configuración guardada.', 'xabia-intelligence'); ?></p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['keygen'])) : ?>
            <div class="notice notice-info is-dismissible"><p><?php echo esc_html__('Se generó una nueva clave de puente. Actualiza los nodos remotos que consumían la anterior.', 'xabia-intelligence'); ?></p></div>
        <?php endif; ?>

        <div class="card" style="max-width:920px;padding:16px 20px;margin:16px 0;">
            <h2><?php echo esc_html__('Endpoint de escucha (este sitio)', 'xabia-intelligence'); ?></h2>
            <p><code><?php echo esc_html($fed_url); ?></code></p>
            <p><strong><?php echo esc_html__('Cabecera obligatoria', 'xabia-intelligence'); ?>:</strong> <code>X-Xabia-Fed-Key</code></p>
            <p><strong><?php echo esc_html__('Clave actual', 'xabia-intelligence'); ?>:</strong> <code style="word-break:break-all;"><?php echo esc_html($key); ?></code></p>
            <p><a class="button" href="<?php echo esc_url($gen_url); ?>"><?php echo esc_html__('Regenerar clave', 'xabia-intelligence'); ?></a></p>
            <p class="description"><?php echo esc_html__('Ejemplo: GET con parámetros q (texto) y project (ID del agente en este sitio). Respuesta JSON: summary + links.', 'xabia-intelligence'); ?></p>
        </div>

        <form method="post" class="card" style="max-width:920px;padding:16px 20px;">
            <?php wp_nonce_field('xabia_federation_nexus_save', 'xabia_federation_nonce'); ?>
            <input type="hidden" name="xabia_action" value="save_federation_nexus">
            <h2><?php echo esc_html__('Nodos amigos', 'xabia-intelligence'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Nombre', 'xabia-intelligence'); ?></th>
                        <th><?php echo esc_html__('URL base', 'xabia-intelligence'); ?></th>
                        <th><?php echo esc_html__('Categoría', 'xabia-intelligence'); ?></th>
                        <th><?php echo esc_html__('API Key remota', 'xabia-intelligence'); ?></th>
                        <th><?php echo esc_html__('Project ID remoto', 'xabia-intelligence'); ?></th>
                        <th><?php echo esc_html__('Slug', 'xabia-intelligence'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($nodes as $idx => $n) : ?>
                        <tr>
                            <td><input type="text" class="widefat" name="fed_node[<?php echo (int) $idx; ?>][name]" value="<?php echo esc_attr($n['name'] ?? ''); ?>"></td>
                            <td><input type="url" class="widefat" name="fed_node[<?php echo (int) $idx; ?>][url]" value="<?php echo esc_attr($n['url'] ?? ''); ?>" placeholder="https://"></td>
                            <td><input type="text" class="widefat" name="fed_node[<?php echo (int) $idx; ?>][category]" value="<?php echo esc_attr($n['category'] ?? ''); ?>"></td>
                            <td><input type="text" class="widefat" name="fed_node[<?php echo (int) $idx; ?>][api_key]" value="<?php echo esc_attr($n['api_key'] ?? ''); ?>" autocomplete="off" placeholder="<?php echo esc_attr__('Clave del sitio remoto', 'xabia-intelligence'); ?>"></td>
                            <td><input type="text" class="widefat" name="fed_node[<?php echo (int) $idx; ?>][project_id]" value="<?php echo esc_attr($n['project_id'] ?? ''); ?>" placeholder="id-agente-remoto"></td>
                            <td><input type="text" class="widefat" name="fed_node[<?php echo (int) $idx; ?>][slug]" value="<?php echo esc_attr($n['slug'] ?? ''); ?>" placeholder="<?php echo esc_attr__('auto', 'xabia-intelligence'); ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="submit">
                <button type="submit" class="button button-primary"><?php echo esc_html__('Guardar nodos', 'xabia-intelligence'); ?></button>
            </p>
        </form>
    </div>
    <?php
}
