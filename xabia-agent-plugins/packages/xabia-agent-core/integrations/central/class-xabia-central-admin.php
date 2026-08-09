<?php
/**
 * Xabia Central — Admin: nodos federados
 * Eficacia: una pantalla; CRUD nodos; sincronizar por proyecto.
 */

if (!defined('ABSPATH')) exit;

class Xabia_Central_Admin {

    public static function render_page() {
        if (function_exists('xabia_central_hub_includes_federation_addon') && !xabia_central_hub_includes_federation_addon()) {
            echo '<div class="wrap"><p>' . esc_html__('Esta pantalla solo está disponible con el add-on de federación activo en tu licencia.', 'xabia-intelligence') . '</p></div>';

            return;
        }
        self::handle_actions();
        $projects = get_option('xabia_projects_config', []);
        $central_projects = [];
        foreach ($projects as $id => $p) {
            if (($p['source_type'] ?? '') === 'addon' && ($p['addon_slug'] ?? '') === 'xabia_central') {
                $central_projects[$id] = $p['name'] ?? $id;
            }
        }
        $current_project = isset($_GET['project_id']) ? sanitize_key($_GET['project_id']) : (array_key_first($central_projects) ?: '');
        $nodes = $current_project ? Xabia_Central_Nodes::get_for_project($current_project) : [];
        $edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
        $node_edit = $edit_id ? Xabia_Central_Nodes::get_by_id($edit_id) : null;

        if (defined('XABIA_URL')) {
            wp_enqueue_style('dashicons');
            wp_enqueue_style(
                'xabia-admin',
                XABIA_URL . 'admin/css/xabia-admin.css',
                [],
                defined('XABIA_VERSION') ? XABIA_VERSION : '1.0'
            );
        }
        $central_base = admin_url('admin.php?page=xabia-central');
        ?>
        <div class="wrap xabia-wrapper xabia-admin-app">
            <div class="xabia-card xabia-admin-header">
                <div class="xabia-admin-header__text">
                    <h1 class="xabia-page-title"><?php echo esc_html__('Federación (Xabia Central)', 'xabia-intelligence'); ?></h1>
                    <p class="xabia-page-subtitle"><?php echo esc_html__('Nodos que envían o aportan conocimiento unificado. Los datos permanecen en esta instalación.', 'xabia-intelligence'); ?></p>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=xabia-settings')); ?>" class="button xabia-btn--ghost"><?php echo esc_html__('← Ajustes principales', 'xabia-intelligence'); ?></a>
            </div>

            <div class="xabia-card xabia-central-project-card">
                <h2 class="xabia-card-title"><?php echo esc_html__('Proyecto federado', 'xabia-intelligence'); ?></h2>
                <p class="xabia-card-desc"><?php echo esc_html__('Selecciona el agente que use la integración federada Xabia Central (proyectos ya configurados con esa fuente).', 'xabia-intelligence'); ?></p>
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="xabia-central-project-form">
                    <input type="hidden" name="page" value="xabia-central">
                    <label class="xabia-central-project-form__label" for="xabia_central_project_id"><?php echo esc_html__('Proyecto', 'xabia-intelligence'); ?></label>
                    <select name="project_id" id="xabia_central_project_id" class="widefat" style="max-width:420px;" onchange="this.form.submit()">
                        <option value=""><?php echo esc_html__('— Seleccionar —', 'xabia-intelligence'); ?></option>
                        <?php foreach ($central_projects as $id => $name) : ?>
                            <option value="<?php echo esc_attr($id); ?>" <?php selected($current_project, $id); ?>><?php echo esc_html($name); ?> (<?php echo esc_html($id); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php if (empty($central_projects)) : ?>
                    <p class="description" style="margin-top:12px;margin-bottom:0;"><?php echo esc_html__('No hay agentes con fuente federada Xabia Central. La configuración de nodos está en Xabia Agent → Avanzado. La sincronización ordinaria de MEC o Woo usa la pestaña General → Addon nativo.', 'xabia-intelligence'); ?></p>
                <?php endif; ?>
            </div>

            <?php if ($current_project) : ?>
                <div class="xabia-card xabia-card--flush xabia-central-nodes-card">
                    <div class="xabia-central-nodes-card__inner">
                        <h2 class="xabia-card-title"><?php echo esc_html__('Nodos', 'xabia-intelligence'); ?></h2>
                        <p class="xabia-card-desc"><?php echo esc_html__('Pull: la central obtiene datos. Push: el nodo envía registros al endpoint de abajo.', 'xabia-intelligence'); ?></p>
                    </div>
                    <div class="xabia-table-scroll">
                        <table class="widefat striped xabia-table-elegant">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('ID nodo', 'xabia-intelligence'); ?></th>
                                    <th><?php echo esc_html__('Nombre', 'xabia-intelligence'); ?></th>
                                    <th><?php echo esc_html__('Tipo', 'xabia-intelligence'); ?></th>
                                    <th><?php echo esc_html__('Última sync', 'xabia-intelligence'); ?></th>
                                    <th><?php echo esc_html__('Error', 'xabia-intelligence'); ?></th>
                                    <th class="xabia-table-elegant__actions"><?php echo esc_html__('Acciones', 'xabia-intelligence'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($nodes as $n) : ?>
                                    <tr>
                                        <td><code><?php echo esc_html($n['node_id']); ?></code></td>
                                        <td><?php echo esc_html($n['name']); ?></td>
                                        <td><span class="xabia-addon-card__badge xabia-addon-card__badge--installed"><?php echo esc_html($n['type']); ?></span></td>
                                        <td><?php echo $n['last_sync_at'] ? esc_html($n['last_sync_at']) : '—'; ?></td>
                                        <td><?php echo $n['last_error'] ? '<span class="xabia-central-error">' . esc_html($n['last_error']) . '</span>' : '—'; ?></td>
                                        <td class="xabia-table-elegant__actions">
                                            <a href="<?php echo esc_url(add_query_arg(['project_id' => $current_project, 'edit' => (int) $n['id']], $central_base)); ?>" class="button button-small"><?php echo esc_html__('Editar', 'xabia-intelligence'); ?></a>
                                            <a href="<?php echo esc_url(add_query_arg(['project_id' => $current_project, 'delete' => (int) $n['id']], $central_base)); ?>" class="button button-small" onclick="return confirm('¿Borrar este nodo?');"><?php echo esc_html__('Borrar', 'xabia-intelligence'); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($nodes)) : ?>
                                    <tr><td colspan="6"><?php echo esc_html__('Aún no hay nodos. Añade uno con el formulario inferior o el botón «Añadir nodo».', 'xabia-intelligence'); ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="xabia-central-toolbar">
                        <a href="<?php echo esc_url(add_query_arg(['project_id' => $current_project, 'edit' => '0'], $central_base)); ?>" class="button button-primary"><?php echo esc_html__('+ Añadir nodo', 'xabia-intelligence'); ?></a>
                        <a href="#" id="xabia-central-sync-now" class="button" data-project="<?php echo esc_attr($current_project); ?>"><?php echo esc_html__('Sincronizar ahora (pull)', 'xabia-intelligence'); ?></a>
                        <span id="xabia-central-sync-feedback" class="xabia-central-sync-feedback"></span>
                    </div>
                    <p class="xabia-central-footnote description"><?php echo esc_html__('La sincronización automática (intervalo configurable en el agente) usa el mismo flujo que «Sincronizar ahora»: cada fila indexada incluye el prefijo «Fuente: [nombre del nodo] | » para el RAG.', 'xabia-intelligence'); ?></p>
                </div>

                <?php if ($edit_id !== 0 || isset($_GET['edit'])) : ?>
                    <div class="xabia-card xabia-central-form-card">
                        <h2 class="xabia-card-title"><?php echo $node_edit ? esc_html__('Editar nodo', 'xabia-intelligence') : esc_html__('Nuevo nodo', 'xabia-intelligence'); ?></h2>
                        <?php self::render_node_form($current_project, $node_edit); ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="xabia-card xabia-central-endpoint-card">
                <h2 class="xabia-card-title"><?php echo esc_html__('Endpoint de ingest (push)', 'xabia-intelligence'); ?></h2>
                <p class="xabia-card-desc"><?php echo esc_html__('Los nodos pueden enviar datos con POST. Cuerpo JSON: node_id, project_id, api_key, records (array de filas).', 'xabia-intelligence'); ?></p>
                <p class="xabia-central-endpoint-url"><code><?php echo esc_html(admin_url('admin-ajax.php?action=xabia_central_ingest')); ?></code></p>
            </div>
        </div>
        <script>
        jQuery(function($) {
            $('#xabia-central-sync-now').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this), $fb = $('#xabia-central-sync-feedback');
                $fb.text('Sincronizando…').css('color','');
                $.post(ajaxurl, {
                    action: 'xabia_sync_content',
                    nonce: '<?php echo esc_js(wp_create_nonce('xabia_admin_nonce')); ?>',
                    project_id: $btn.data('project')
                }).done(function(r) {
                    if (r.success && r.data) {
                        var msg = r.data.message ? r.data.message : 'OK';
                        if (r.data.count !== undefined) {
                            msg += ' (' + r.data.count + ')';
                        }
                        $fb.text(msg).css('color', 'green');
                    } else {
                        var err = (r.data && r.data.message) ? r.data.message : 'Error';
                        $fb.text(err).css('color', 'red');
                    }
                }).fail(function() {
                    $fb.text('Error').css('color','red');
                });
            });
        });
        </script>
        <?php
    }

    private static function render_node_form($project_id, $node) {
        $node = $node ?: [];
        $config = $node['config'] ?? [];
        $endpoint = $config['endpoint_url'] ?? '';
        $format = $config['format'] ?? 'json';
        $mapping = $config['mapping'] ?? [];
        $mapping_json = is_array($mapping) ? wp_json_encode($mapping) : '[]';
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=xabia-central&project_id=' . rawurlencode((string) $project_id))); ?>">
            <?php wp_nonce_field('xabia_central_save_node', 'xabia_central_nonce'); ?>
            <input type="hidden" name="xabia_central_action" value="save_node">
            <input type="hidden" name="project_id" value="<?php echo esc_attr($project_id); ?>">
            <?php if (!empty($node['id'])) : ?><input type="hidden" name="id" value="<?php echo (int) $node['id']; ?>"><?php endif; ?>
            <table class="form-table">
                <tr>
                    <th><label for="node_id"><?php echo esc_html__('ID nodo', 'xabia-intelligence'); ?></label></th>
                    <td><input type="text" name="node_id" id="node_id" value="<?php echo esc_attr($node['node_id'] ?? ''); ?>" class="regular-text" required pattern="[a-z0-9_-]+" placeholder="ej: web-ayto-1"> <span class="description"><?php echo esc_html__('Solo letras minúsculas, números, _ y -', 'xabia-intelligence'); ?></span></td>
                </tr>
                <tr>
                    <th><label for="name"><?php echo esc_html__('Nombre', 'xabia-intelligence'); ?></label></th>
                    <td><input type="text" name="name" id="name" value="<?php echo esc_attr($node['name'] ?? ''); ?>" class="regular-text" placeholder="Ej: Web Ayuntamiento"></td>
                </tr>
                <tr>
                    <th><label for="type"><?php echo esc_html__('Tipo', 'xabia-intelligence'); ?></label></th>
                    <td>
                        <select name="type" id="type">
                            <option value="pull" <?php selected($node['type'] ?? 'pull', 'pull'); ?>><?php echo esc_html__('Pull (central obtiene datos)', 'xabia-intelligence'); ?></option>
                            <option value="push" <?php selected($node['type'] ?? '', 'push'); ?>><?php echo esc_html__('Push (nodo envía datos)', 'xabia-intelligence'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="endpoint_url"><?php echo esc_html__('URL endpoint (pull)', 'xabia-intelligence'); ?></label></th>
                    <td><input type="url" name="config[endpoint_url]" id="endpoint_url" value="<?php echo esc_attr($endpoint); ?>" class="large-text" placeholder="https://nodo.ejemplo.com/api/export"> <span class="description"><?php echo esc_html__('GET; debe devolver JSON con records o CSV', 'xabia-intelligence'); ?></span></td>
                </tr>
                <tr>
                    <th><label for="format"><?php echo esc_html__('Formato respuesta (pull)', 'xabia-intelligence'); ?></label></th>
                    <td>
                        <select name="config[format]" id="format">
                            <option value="json" <?php selected($format, 'json'); ?>><?php echo esc_html__('JSON', 'xabia-intelligence'); ?></option>
                            <option value="csv" <?php selected($format, 'csv'); ?>><?php echo esc_html__('CSV', 'xabia-intelligence'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="api_key"><?php echo esc_html__('API key (push)', 'xabia-intelligence'); ?></label></th>
                    <td><input type="password" name="api_key" id="api_key" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $node ? 'Dejar en blanco para no cambiar' : 'Generar y guardar'; ?>"> <span class="description"><?php echo esc_html__('El nodo la envía en POST para autorizar; se guarda hasheada.', 'xabia-intelligence'); ?></span></td>
                </tr>
                <tr>
                    <th><label for="mapping"><?php echo esc_html__('Mapeo (opcional)', 'xabia-intelligence'); ?></label></th>
                    <td><textarea name="config[mapping]" id="mapping" rows="4" class="large-text code"><?php echo esc_textarea($mapping_json); ?></textarea> <span class="description"><?php echo esc_html__('JSON: array de { "source_key": "columna", "label": "Etiqueta", "is_ente": true }. Si vacío y el nodo envía ente_id/content_chunk, se usa tal cual.', 'xabia-intelligence'); ?></span></td>
                </tr>
            </table>
            <p class="xabia-central-form-actions"><button type="submit" class="button button-primary"><?php echo esc_html__('Guardar nodo', 'xabia-intelligence'); ?></button> <a href="<?php echo esc_url(admin_url('admin.php?page=xabia-central&project_id=' . rawurlencode((string) $project_id))); ?>" class="button"><?php echo esc_html__('Cancelar', 'xabia-intelligence'); ?></a></p>
        </form>
        <?php
    }

    private static function handle_actions() {
        if (!current_user_can('manage_options')) return;
        if (isset($_GET['delete'])) {
            $id = (int) $_GET['delete'];
            if ($id > 0) {
                Xabia_Central_Nodes::delete($id);
                wp_safe_redirect(remove_query_arg('delete') . '&deleted=1');
                exit;
            }
        }
        if (!empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['xabia_central_action']) && $_POST['xabia_central_action'] === 'save_node') {
            if (!wp_verify_nonce($_POST['xabia_central_nonce'] ?? '', 'xabia_central_save_node')) return;
            $config = [
                'endpoint_url' => esc_url_raw($_POST['config']['endpoint_url'] ?? ''),
                'format' => sanitize_key($_POST['config']['format'] ?? 'json'),
                'mapping' => [],
            ];
            if (!empty($_POST['config']['mapping'])) {
                $dec = json_decode(stripslashes($_POST['config']['mapping']), true);
                if (is_array($dec)) $config['mapping'] = $dec;
            }
            $data = [
                'id' => (int) ($_POST['id'] ?? 0),
                'project_id' => sanitize_key($_POST['project_id'] ?? ''),
                'node_id' => sanitize_key($_POST['node_id'] ?? ''),
                'name' => sanitize_text_field($_POST['name'] ?? ''),
                'type' => in_array($_POST['type'] ?? '', ['pull', 'push'], true) ? $_POST['type'] : 'pull',
                'config' => $config,
                'api_key' => $_POST['api_key'] ?? '',
            ];
            Xabia_Central_Nodes::save($data);
            $project_id = sanitize_key($_POST['project_id'] ?? '');
            wp_safe_redirect(admin_url('admin.php?page=xabia-central&project_id=' . $project_id . '&saved=1'));
            exit;
        }
    }
}
