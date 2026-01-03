<?php
/**
 * Xabia — page-settings.php
 * Ajustes globales del agente (v4.1 PRO)
 */

if (!defined('ABSPATH')) exit;

/**
 * Registrar opciones
 */
add_action('admin_init', function () {

    // API Key
    register_setting('xabia_settings_group', 'xabia_openai_key');

    // Idioma
    register_setting('xabia_settings_group', 'xabia_language');

    // Modo debug
    register_setting('xabia_settings_group', 'xabia_debug_mode');
    
    // Base URL para imágenes de empresas
    register_setting('xabia_settings_group', 'xabia_images_base_url');

});


/**
 * Pantalla principal de ajustes
 */
function xabia_admin_page_settings() {

    $openai = get_option('xabia_openai_key', '');
    $lang   = get_option('xabia_language', 'auto');
    $debug  = get_option('xabia_debug_mode', '0');

    ?>
    <div class="wrap">
        <h1>🤖 Xabia Agent — Ajustes globales</h1>

        <p class="description" style="max-width:650px;">
            Configuración general del asistente.  
            Estos valores controlan el comportamiento de Xabia en todo el sitio.
        </p>

        <hr><br>

        <form method="post" action="options.php">
            <?php settings_fields('xabia_settings_group'); ?>

            <table class="form-table">

                <!-- API KEY -->
                <tr>
                    <th scope="row"><label for="xabia_openai_key">🔑 API Key OpenAI</label></th>
                    <td>
                        <input type="text" id="xabia_openai_key" name="xabia_openai_key"
                               class="regular-text"
                               value="<?php echo esc_attr($openai); ?>">
                        <p class="description">
                            Clave usada para embeddings, parser semántico y respuesta del agente.
                        </p>
                    </td>
                </tr>
                <!-- IMAGES BASE URL -->
                <tr>
                    <th scope="row"><label for="xabia_images_base_url">🖼 Carpeta base de imágenes</label></th>
                    <td>
                        <input type="text"
                               id="xabia_images_base_url"
                               name="xabia_images_base_url"
                               class="regular-text"
                               value="<?php echo esc_attr( get_option('xabia_images_base_url', '') ); ?>">
                        <p class="description">
                            Carpeta donde Xabia buscará logotipos y fotos de las empresas.<br>
                            Ejemplo: <code>https://aktiba.eus/wp-content/uploads/aktiba/empresas/</code>
                        </p>
                    </td>
                </tr>
                <!-- IDIOMA -->
                <tr>
                    <th scope="row"><label for="xabia_language">🌐 Idioma predeterminado</label></th>
                    <td>
                        <select id="xabia_language" name="xabia_language">
                            <option value="auto"  <?php selected($lang, 'auto');  ?>>Automático</option>
                            <option value="es"    <?php selected($lang, 'es');    ?>>Castellano</option>
                            <option value="eu"    <?php selected($lang, 'eu');    ?>>Euskera</option>
                            <option value="en"    <?php selected($lang, 'en');    ?>>Inglés</option>
                        </select>
                        <p class="description">
                            Xabia usará este idioma para respuestas, si no se detecta otro en el usuario.
                        </p>
                    </td>
                </tr>

                <!-- DEBUG -->
                <tr>
                    <th scope="row"><label for="xabia_debug_mode">🛠 Modo debug</label></th>
                    <td>
                        <label>
                            <input type="checkbox" id="xabia_debug_mode" name="xabia_debug_mode"
                                   value="1" <?php checked($debug, '1'); ?>>
                            Registrar logs avanzados
                        </label>
                        <p class="description">
                            Activa logs detallados del motor, embeddings, acciones y API.
                        </p>
                    </td>
                </tr>

            </table>

            <?php submit_button('Guardar ajustes'); ?>
        </form>

        <hr><br>

        <h2>🧹 Mantenimiento</h2>
        <p>Acciones rápidas de sistema.</p>

        <!-- RESET CONTEXTO -->
        <form method="post">
            <?php wp_nonce_field('xabia_reset_context'); ?>
            <input type="hidden" name="xabia_action" value="reset_context">
            <?php submit_button('Resetear contexto del usuario', 'secondary'); ?>
        </form>

        <!-- REGENERAR base.json -->
        <form method="post" style="margin-top:10px;">
            <?php wp_nonce_field('xabia_rebuild_base'); ?>
            <input type="hidden" name="xabia_action" value="rebuild_base">
            <?php submit_button('Regenerar base de conocimiento (base.json)', 'secondary'); ?>
        </form>

        <?php xabia_admin_page_settings_handle_actions(); ?>
    </div>
    <?php
}


/**
 * Acciones de mantenimiento (reset / rebuild)
 */
function xabia_admin_page_settings_handle_actions() {

    if (empty($_POST['xabia_action'])) return;

    $action = sanitize_key($_POST['xabia_action']);

    if ($action === 'reset_context') {

        check_admin_referer('xabia_reset_context');

        if (class_exists('XabiaContext')) {
            XabiaContext::reset_all();
            echo '<div class="updated"><p>🧽 Contexto reseteado correctamente.</p></div>';
        }

        return;
    }

    if ($action === 'rebuild_base') {

        check_admin_referer('xabia_rebuild_base');

        if (!function_exists('xabia_load_knowledge')) {
            echo '<div class="error"><p>❌ No existe xabia_load_knowledge().</p></div>';
            return;
        }

        $data = xabia_load_knowledge(); // esto ya regenera base.json si falta

        echo '<div class="updated"><p>📘 base.json regenerado (' . count($data) . ' entidades).</p></div>';
        return;
    }
}