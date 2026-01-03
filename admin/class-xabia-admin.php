<?php
/**
 * CAPA 2 — ADMINISTRACIÓN (v8.7)
 * Gestión de Agentes, Personalización y Business Intelligence.
 */

if (!defined('ABSPATH')) exit;

class Xabia_Admin {

    public static function init() {
        add_action('admin_init', [__CLASS__, 'handle_export']);
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function handle_export() {
        if (isset($_POST['xabia_export_logs']) && current_user_can('manage_options')) {
            global $wpdb;
            if (ob_get_length()) ob_clean();

            $results = $wpdb->get_results("SELECT time, project_id, user_query, max_score FROM {$wpdb->prefix}xabia_logs ORDER BY time DESC", ARRAY_A);
            if (empty($results)) return;

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=Xabia_Intelligence_' . date('Y-m-d') . '.csv');
            
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM para Excel
            fputcsv($output, ['Fecha', 'Agente', 'Pregunta', 'Score'], ';');

            foreach ($results as $row) { fputcsv($output, $row, ';'); }
            fclose($output);
            exit;
        }
    }

    public static function add_menu() {
        add_menu_page('Xabia Agent', 'Xabia Agent', 'manage_options', 'xabia-settings', [__CLASS__, 'render_admin_page'], 'dashicons-smart-assistant', 25);
    }

    public static function register_settings() {
        register_setting('xabia_settings_group', 'xabia_openai_key');
        register_setting('xabia_settings_group', 'xabia_projects_config');
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $projects = get_option('xabia_projects_config', []);
        $edit_id = $_GET['edit'] ?? '';
        $edit_data = $projects[$edit_id] ?? null;

        // Lógica de Guardado resumida
        if (isset($_POST['xabia_save_project'])) {
            $pid = sanitize_key($_POST['project_id']);
            $projects[$pid] = [
                'name'    => sanitize_text_field($_POST['name']),
                'rules'   => ['instructions' => sanitize_textarea_field($_POST['instructions'])],
                'style'   => [
                    'font_size'   => $_POST['font_size'],
                    'accent_color'=> $_POST['accent_color'],
                    'container_w' => $_POST['container_w']
                ]
            ];
            update_option('xabia_projects_config', $projects);
            echo '<div class="updated"><p>Agente <b>Xabia</b> actualizado.</p></div>';
        }
        ?>

        <div class="wrap">
            <h1>Motor Xabia Agent <small>v8.7</small></h1>

            <div style="display: grid; grid-template-columns: 1fr 400px; gap: 20px; margin-top: 20px;">
                
                <div>
                    <div class="postbox" style="padding: 15px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
                            <p style="margin:0; color: #666;">Analiza qué buscan tus usuarios en tiempo real.</p>
                            <form method="post">
                                <input type="hidden" name="xabia_export_logs" value="1">
                                <button type="submit" class="button button-primary" style="display:flex; align-items:center; gap:5px;">
                                    <span class="dashicons dashicons-download"></span> Descargar Data Mining (CSV)
                                </button>
                            </form>
                        </div>

                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th width="80">Hora</th>
                                    <th>Pregunta Detectada</th>
                                    <th width="100">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $logs = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}xabia_logs ORDER BY time DESC LIMIT 10");
                                foreach($logs as $log): 
                                    $low_score = ($log->max_score == 0);
                                ?>
                                    <tr style="<?php echo $low_score ? 'background:#fff5f5;' : ''; ?>">
                                        <td><small><?php echo date('H:i', strtotime($log->time)); ?></small></td>
                                        <td><strong><?php echo esc_html($log->user_query); ?></strong></td>
                                        <td>
                                            <?php if($low_score): ?>
                                                <span style="color:#d32f2f;">⚠️ Sin Datos</span>
                                            <?php else: ?>
                                                <span style="color:#2e7d32;">✅ <?php echo $log->max_score; ?> pts</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="postbox" style="padding: 20px;">
                    <h2>Configuración Estilo y Prompt</h2>
                    <form method="post">
                        <input type="hidden" name="project_id" value="<?php echo esc_attr($edit_id); ?>">
                        <label>Nombre del Agente</label>
                        <input type="text" name="name" value="<?php echo esc_attr($edit_data['name'] ?? ''); ?>" class="large-text" required>
                        
                        <div style="background:#f0f0f1; padding:15px; border-radius:4px; margin:15px 0;">
                            <label style="display:block; margin-bottom:5px;"><b>Personalización Visual</b></label>
                            <select name="font_size" style="width:100%; margin-bottom:10px;">
                                <option value="16px" <?php selected($edit_data['style']['font_size'] ?? '', '16px'); ?>>Letra Normal</option>
                                <option value="22px" <?php selected($edit_data['style']['font_size'] ?? '', '22px'); ?>>Letra Grande (Espectacular)</option>
                            </select>
                            <input type="color" name="accent_color" value="<?php echo $edit_data['style']['accent_color'] ?? '#8b004f'; ?>" style="width:100%;">
                        </div>

                        <textarea name="instructions" rows="6" class="large-text" placeholder="Prompt del sistema..."><?php echo esc_textarea($edit_data['rules']['instructions'] ?? ''); ?></textarea>
                        
                        <div style="margin-top:15px;">
                            <?php submit_button('Guardar Cambios', 'primary', 'xabia_save_project', false); ?>
                        </div>
                    </form>
                </div>

            </div>
        </div>
        <?php
    }
}