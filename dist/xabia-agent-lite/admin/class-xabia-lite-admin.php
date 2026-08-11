<?php
/**
 * Panel de administración Xabia LITE — hero, cards y autenticación dual.
 *
 * Aislado del panel Premium: no registra hooks de RAG, wallet, addons ni sincronización Hub.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Lite_Admin {

    private const PAGE_SLUG = 'xabia-lite';
    private const NONCE_ACTION = 'xabia_lite_settings_save';
    private const NONCE_FIELD = 'xabia_lite_nonce';
    private const RECHARGES_URL = 'https://xabia.ai/#wallet';
    private const GOOGLE_AI_STUDIO_URL = 'https://aistudio.google.com/app/apikey';
    private const INDEX_WEB_ACTION = 'xabia_lite_index_web';

    public static function init(): void {
        if (!class_exists('Xabia_Features', false) || !Xabia_Features::is_lite()) {
            return;
        }

        $self = new self();
        add_action('admin_menu', [$self, 'register_menu']);
        add_action('admin_init', [$self, 'register_settings']);
        add_action('admin_init', [$self, 'handle_post']);
        add_action('admin_enqueue_scripts', [$self, 'enqueue_assets']);
    }

    public function register_settings(): void {
        register_setting(
            'xabia_lite_settings_group',
            Xabia_Mode::OPTION_LITE_SETTINGS,
            [
                'type'              => 'array',
                'sanitize_callback' => [Xabia_Mode::class, 'sanitize_lite_settings_option'],
                'default'           => [],
            ]
        );
    }

    public function register_menu(): void {
        $icon = class_exists('Xabia_Admin_UI', false)
            ? Xabia_Admin_UI::menu_icon_url()
            : 'dashicons-superhero';

        add_menu_page(
            __('Xabia LITE', 'xabia-intelligence'),
            __('Xabia LITE', 'xabia-intelligence'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page'],
            $icon,
            25
        );

        global $submenu;
        if (isset($submenu[self::PAGE_SLUG]) && is_array($submenu[self::PAGE_SLUG])) {
            $help_url = (string) apply_filters('xabia_admin_help_docs_url', 'https://xabia.ai/docs/');
            $submenu[self::PAGE_SLUG][] = [
                __('Ayuda', 'xabia-intelligence'),
                'manage_options',
                $help_url,
                __('Ayuda', 'xabia-intelligence'),
            ];
        }
        add_action('admin_head', [$this, 'admin_help_menu_open_blank'], 20);
        add_action('admin_head', ['Xabia_Admin_UI', 'print_menu_icon_styles'], 5);
    }

    /**
     * Abre «Ayuda» en pestaña nueva.
     */
    public function admin_help_menu_open_blank(): void {
        $help_url = (string) apply_filters('xabia_admin_help_docs_url', 'https://xabia.ai/docs/');
        if ($help_url === '') {
            return;
        }
        $js_url = wp_json_encode($help_url);
        echo '<script>jQuery(function($){var u=' . $js_url . ';$("#adminmenu a").filter(function(){return this.href===u||this.getAttribute("href")===u;}).attr({target:"_blank",rel:"noopener noreferrer"});});</script>' . "\n";
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'toplevel_page_' . self::PAGE_SLUG) {
            return;
        }

        $base_ver = defined('XABIA_VERSION') ? XABIA_VERSION : '1.0.0';
        $lite_css = XABIA_PATH . 'admin/css/xabia-lite-admin.css';
        $lite_js = XABIA_PATH . 'admin/js/xabia-lite-admin.js';

        if (is_readable($lite_css)) {
            $css_ver = $base_ver . '.' . (string) filemtime($lite_css);
            wp_enqueue_style(
                'xabia-lite-admin',
                plugins_url('admin/css/xabia-lite-admin.css', XABIA_PATH . 'xabia-intelligence.php'),
                [],
                $css_ver
            );
        }

        if (is_readable($lite_js)) {
            $js_ver = $base_ver . '.' . (string) filemtime($lite_js);
            wp_enqueue_script(
                'xabia-lite-admin',
                plugins_url('admin/js/xabia-lite-admin.js', XABIA_PATH . 'xabia-intelligence.php'),
                ['jquery'],
                $js_ver,
                true
            );
        }
    }

    public function handle_post(): void {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        if (!isset($_POST[self::NONCE_FIELD])) {
            return;
        }
        if (!isset($_GET['page']) || sanitize_key(wp_unslash((string) $_GET['page'])) !== self::PAGE_SLUG) {
            return;
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        if (!empty($_POST[self::INDEX_WEB_ACTION])) {
            $this->handle_web_index();
            return;
        }

        $auth_mode = isset($_POST['xabia_lite_auth_mode'])
            ? sanitize_key(wp_unslash((string) $_POST['xabia_lite_auth_mode']))
            : 'byok';
        if (!in_array($auth_mode, ['xabia_cloud', 'byok'], true)) {
            $auth_mode = 'byok';
        }

        $settings = [
            'auth_mode'           => $auth_mode,
            'system_instructions' => isset($_POST['xabia_lite_system_instructions'])
                ? sanitize_textarea_field(wp_unslash((string) $_POST['xabia_lite_system_instructions']))
                : '',
        ];

        $current = get_option(Xabia_Mode::OPTION_LITE_SETTINGS, []);
        if (!is_array($current)) {
            $current = [];
        }

        if (isset($current['gemini_api_key_enc']) && is_string($current['gemini_api_key_enc'])) {
            $settings['gemini_api_key_enc'] = $current['gemini_api_key_enc'];
        }
        if (isset($current['xabia_api_key_enc']) && is_string($current['xabia_api_key_enc'])) {
            $settings['xabia_api_key_enc'] = $current['xabia_api_key_enc'];
        }

        $settings['csv_basename'] = isset($current['csv_basename']) && is_string($current['csv_basename'])
            ? $current['csv_basename']
            : '';
        $settings['csv_uploaded_at'] = isset($current['csv_uploaded_at']) ? (int) $current['csv_uploaded_at'] : 0;
        $settings['web_pages_count'] = isset($current['web_pages_count']) ? (int) $current['web_pages_count'] : 0;
        $settings['web_synced_at'] = isset($current['web_synced_at']) ? (int) $current['web_synced_at'] : 0;

        $xabia_key = isset($_POST['xabia_lite_xabia_api_key'])
            ? sanitize_text_field(wp_unslash((string) $_POST['xabia_lite_xabia_api_key']))
            : '';
        if ($xabia_key !== '' && !Xabia_Mode::store_lite_xabia_api_key($xabia_key)) {
            add_settings_error(
                'xabia_lite',
                'xabia_lite_xabia_key',
                __('No se pudo guardar la Xabia API Key de forma segura. Comprueba que OpenSSL esté disponible en el servidor.', 'xabia-intelligence'),
                'error'
            );
            return;
        }

        $gemini_key = isset($_POST['xabia_lite_gemini_api_key'])
            ? sanitize_text_field(wp_unslash((string) $_POST['xabia_lite_gemini_api_key']))
            : '';
        if ($gemini_key !== '' && !Xabia_Mode::store_lite_gemini_api_key($gemini_key)) {
            add_settings_error(
                'xabia_lite',
                'xabia_lite_gemini_key',
                __('No se pudo guardar la API Key de Gemini de forma segura. Comprueba que OpenSSL esté disponible en el servidor.', 'xabia-intelligence'),
                'error'
            );
            return;
        }

        $csv_error = $this->maybe_handle_csv_upload($settings);
        if ($csv_error !== '') {
            add_settings_error('xabia_lite', 'xabia_lite_csv', $csv_error, 'error');
        }

        Xabia_Mode::save_lite_settings($settings);

        if ($csv_error === '') {
            add_settings_error('xabia_lite', 'xabia_lite_saved', __('Ajustes guardados.', 'xabia-intelligence'), 'updated');
        }
    }

    private function handle_web_index(): void {
        if (!class_exists('Xabia_Lite_Scraper', false)) {
            add_settings_error(
                'xabia_lite',
                'xabia_lite_web_index',
                __('El scraper local no está disponible en esta instalación.', 'xabia-intelligence'),
                'error'
            );
            return;
        }

        $result = Xabia_Lite_Scraper::index_local_site();
        add_settings_error(
            'xabia_lite',
            'xabia_lite_web_index',
            (string) ($result['message'] ?? __('Contenido de la web indexado.', 'xabia-intelligence')),
            !empty($result['ok']) ? 'updated' : 'error'
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function maybe_handle_csv_upload(array &$settings): string {
        if (empty($_FILES['xabia_lite_csv']['name']) || !is_array($_FILES['xabia_lite_csv'])) {
            return '';
        }

        $file = $_FILES['xabia_lite_csv'];
        if (!empty($file['error']) && (int) $file['error'] !== UPLOAD_ERR_NO_FILE) {
            return __('No se pudo subir el CSV. Inténtalo de nuevo.', 'xabia-intelligence');
        }
        if (empty($file['tmp_name']) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return '';
        }

        $name = isset($file['name']) ? sanitize_file_name(wp_unslash((string) $file['name'])) : '';
        if ($name === '' || !preg_match('/\.csv$/i', $name)) {
            return __('Solo se permiten archivos .csv', 'xabia-intelligence');
        }

        $max_bytes = (int) apply_filters('xabia_lite_csv_max_bytes', 5 * 1024 * 1024);
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($max_bytes > 0 && $size > $max_bytes) {
            return __('El CSV supera el tamaño máximo permitido.', 'xabia-intelligence');
        }

        Xabia_Mode::ensure_lite_storage_dir();
        $dir = Xabia_Mode::lite_csv_dir();
        if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
            return __('No se pudo preparar la carpeta de subidas de Xabia.', 'xabia-intelligence');
        }

        $dest_name = 'catalog-' . gmdate('Ymd-His') . '.csv';
        $dest_path = $dir . '/' . $dest_name;

        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $overrides = [
            'test_form' => false,
            'mimes'     => [
                'csv' => 'text/csv',
                'txt' => 'text/plain',
            ],
        ];
        $uploaded = wp_handle_upload($file, $overrides);
        if (!is_array($uploaded) || !empty($uploaded['error'])) {
            $msg = is_array($uploaded) && !empty($uploaded['error']) ? (string) $uploaded['error'] : '';
            return $msg !== '' ? $msg : __('Error al procesar el CSV.', 'xabia-intelligence');
        }

        $tmp_path = (string) ($uploaded['file'] ?? '');
        if ($tmp_path === '' || !is_readable($tmp_path)) {
            return __('El archivo subido no es legible.', 'xabia-intelligence');
        }

        if (!@rename($tmp_path, $dest_path)) {
            if (!@copy($tmp_path, $dest_path)) {
                @unlink($tmp_path);
                return __('No se pudo guardar el CSV en el servidor.', 'xabia-intelligence');
            }
            @unlink($tmp_path);
        }

        $old_path = Xabia_Mode::lite_csv_path();
        if ($old_path !== '' && $old_path !== $dest_path && is_file($old_path)) {
            @unlink($old_path);
        }

        $settings['csv_basename'] = $dest_name;
        $settings['csv_uploaded_at'] = time();

        return '';
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = Xabia_Mode::get_lite_settings();
        $auth_mode = $settings['auth_mode'];
        $has_xabia_key = !empty($settings['has_xabia_api_key']);
        $has_gemini_key = !empty($settings['has_gemini_api_key']);
        $csv_path = Xabia_Mode::lite_csv_path();
        $csv_exists = $csv_path !== '' && is_readable($csv_path);
        $csv_size = $csv_exists ? (int) filesize($csv_path) : 0;
        $upgrade_url = Xabia_Mode::pro_upgrade_url();
        $version = defined('XABIA_VERSION') ? (string) XABIA_VERSION : '1.0.0';
        ?>
        <div class="wrap xabia-page-lite">
            <header class="xabia-lite-hero">
                <div class="xabia-lite-hero__brand">
                    <div class="xabia-lite-hero__mark" aria-hidden="true">
                        <?php
                        if (class_exists('Xabia_Admin_UI', false)) {
                            Xabia_Admin_UI::render_brand_icon('xabia-lite-hero__icon', 52);
                        }
                        ?>
                    </div>
                    <div class="xabia-lite-hero__text">
                        <div class="xabia-lite-hero__wordmark-row">
                            <?php
                            if (class_exists('Xabia_Admin_UI', false)) {
                                Xabia_Admin_UI::render_brand_logo('xabia-lite-hero__wordmark', 34);
                            } else {
                                echo '<h1 class="xabia-lite-hero__title">' . esc_html__('xabia', 'xabia-intelligence') . '</h1>';
                            }
                            ?>
                            <span class="xabia-lite-badge"><?php echo esc_html(sprintf(__('LITE %s', 'xabia-intelligence'), $version)); ?></span>
                        </div>
                        <p class="xabia-lite-hero__subtitle">
                            <?php echo esc_html__('Plugin independiente y gratuito con BYOK de Google Gemini. Opcionalmente puedes usar recargas Xabia Cloud sin configurar Google Cloud.', 'xabia-intelligence'); ?>
                        </p>
                    </div>
                </div>
                <a class="xabia-lite-btn-pro" href="<?php echo esc_url($upgrade_url); ?>" target="_blank" rel="noopener noreferrer">
                    <?php echo esc_html__('Pásate a PRO', 'xabia-intelligence'); ?>
                </a>
            </header>

            <div class="xabia-lite-notices">
                <?php settings_errors('xabia_lite'); ?>
            </div>

            <form method="post" action="" enctype="multipart/form-data" class="xabia-lite-form">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <section class="xabia-lite-card">
                    <div class="xabia-lite-card__head">
                        <span class="xabia-lite-card__icon"><?php echo self::icon('settings'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                        <h2 class="xabia-lite-card__title"><?php echo esc_html__('Conexión y Personalización del Chatbot', 'xabia-intelligence'); ?></h2>
                    </div>
                    <p class="xabia-lite-card__desc">
                        <?php echo esc_html__('Elige cómo conectar la IA. No necesitas cuenta en Xabia si usas tu propia clave de Google (Opción A).', 'xabia-intelligence'); ?>
                    </p>

                    <div class="xabia-lite-auth-tabs" role="radiogroup" aria-label="<?php echo esc_attr__('Modo de autenticación IA', 'xabia-intelligence'); ?>">
                        <label class="xabia-lite-auth-tab">
                            <input
                                type="radio"
                                name="xabia_lite_auth_mode"
                                value="byok"
                                <?php checked($auth_mode, 'byok'); ?>
                            />
                            <span class="xabia-lite-auth-tab__inner">
                                <span class="xabia-lite-auth-tab__label-row">
                                    <?php echo self::icon('key'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <span class="xabia-lite-auth-tab__title"><?php echo esc_html__('Opción A: Tu propia clave de Google Gemini (BYOK - Gratis)', 'xabia-intelligence'); ?></span>
                                </span>
                                <span class="xabia-lite-auth-tab__subtitle"><?php echo esc_html__('100% Gratuito. NO requiere cuenta en Xabia. Solo pega tu clave de Google AI Studio.', 'xabia-intelligence'); ?></span>
                                <span class="xabia-lite-auth-tab__explain"><?php echo esc_html__('Tus datos van directamente de tu WordPress a Google Gemini. Se guarda en tu servidor.', 'xabia-intelligence'); ?></span>
                            </span>
                        </label>
                        <label class="xabia-lite-auth-tab">
                            <input
                                type="radio"
                                name="xabia_lite_auth_mode"
                                value="xabia_cloud"
                                <?php checked($auth_mode, 'xabia_cloud'); ?>
                            />
                            <span class="xabia-lite-auth-tab__inner">
                                <span class="xabia-lite-auth-tab__label-row">
                                    <?php echo self::icon('cloud'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <span class="xabia-lite-auth-tab__title"><?php echo esc_html__('Opción B: Sistema de Recargas Xabia Cloud', 'xabia-intelligence'); ?></span>
                                </span>
                                <span class="xabia-lite-auth-tab__subtitle"><?php echo esc_html__('Para usar el chatbot sin configurar Google Cloud.', 'xabia-intelligence'); ?></span>
                                <span class="xabia-lite-auth-tab__explain"><?php echo esc_html__('Solo necesitas registrar la URL de tu web en xabia.ai para vincular tu saldo de recargas.', 'xabia-intelligence'); ?></span>
                            </span>
                        </label>
                    </div>

                    <div class="xabia-lite-auth-panel<?php echo $auth_mode === 'byok' ? ' is-active' : ''; ?>" data-auth-mode="byok"<?php echo $auth_mode !== 'byok' ? ' hidden' : ''; ?>>
                        <label class="xabia-lite-field-label" for="xabia_lite_gemini_api_key"><?php echo esc_html__('Google Gemini API Key', 'xabia-intelligence'); ?></label>
                        <?php if ($has_gemini_key) : ?>
                            <p class="xabia-lite-muted"><?php echo esc_html__('Clave configurada. Deja el campo vacío para mantenerla.', 'xabia-intelligence'); ?></p>
                        <?php endif; ?>
                        <input
                            type="password"
                            class="xabia-lite-input"
                            id="xabia_lite_gemini_api_key"
                            name="xabia_lite_gemini_api_key"
                            value=""
                            autocomplete="off"
                            placeholder="<?php echo esc_attr__('Pega tu API Key de Gemini…', 'xabia-intelligence'); ?>"
                        />
                        <details class="xabia-lite-details">
                            <summary><?php echo esc_html__('Cómo obtener tu clave gratuita en 2 minutos', 'xabia-intelligence'); ?></summary>
                            <div class="xabia-lite-details__body">
                                <ol>
                                    <li>
                                        <?php
                                        printf(
                                            /* translators: %s: link to Google AI Studio */
                                            esc_html__('Entra en %s.', 'xabia-intelligence'),
                                            '<a href="' . esc_url(self::GOOGLE_AI_STUDIO_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Google AI Studio', 'xabia-intelligence') . '</a>'
                                        );
                                        ?>
                                    </li>
                                    <li><?php echo esc_html__('Inicia sesión con tu cuenta de Google y haz clic en "Create API key".', 'xabia-intelligence'); ?></li>
                                    <li><?php echo esc_html__('Copia la clave generada y pégala en el campo superior.', 'xabia-intelligence'); ?></li>
                                </ol>
                            </div>
                        </details>
                    </div>

                    <div class="xabia-lite-auth-panel<?php echo $auth_mode === 'xabia_cloud' ? ' is-active' : ''; ?>" data-auth-mode="xabia_cloud"<?php echo $auth_mode !== 'xabia_cloud' ? ' hidden' : ''; ?>>
                        <label class="xabia-lite-field-label" for="xabia_lite_xabia_api_key"><?php echo esc_html__('Xabia API Key (recargas)', 'xabia-intelligence'); ?></label>
                        <?php if ($has_xabia_key) : ?>
                            <p class="xabia-lite-muted"><?php echo esc_html__('Clave configurada. Deja el campo vacío para mantenerla.', 'xabia-intelligence'); ?></p>
                        <?php endif; ?>
                        <input
                            type="password"
                            class="xabia-lite-input"
                            id="xabia_lite_xabia_api_key"
                            name="xabia_lite_xabia_api_key"
                            value=""
                            autocomplete="off"
                            placeholder="<?php echo esc_attr__('Pega tu Xabia API Key…', 'xabia-intelligence'); ?>"
                        />
                        <a class="xabia-lite-link-btn" href="<?php echo esc_url(self::RECHARGES_URL); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo esc_html__('Obtener recargas / Ver saldo en xabia.ai', 'xabia-intelligence'); ?>
                        </a>
                    </div>

                    <label class="xabia-lite-field-label" for="xabia_lite_system_instructions"><?php echo esc_html__('System Instructions (Personalidad del bot)', 'xabia-intelligence'); ?></label>
                    <textarea
                        class="xabia-lite-textarea"
                        id="xabia_lite_system_instructions"
                        name="xabia_lite_system_instructions"
                        rows="8"
                        placeholder="<?php echo esc_attr__('Ejemplo: Eres el asistente virtual de [Nombre de tu negocio]. Responde siempre en el idioma del usuario, con tono cercano y profesional. Usa el catálogo CSV para precios y disponibilidad. Si no sabes algo, invita al visitante a contactar por email o teléfono.', 'xabia-intelligence'); ?>"
                    ><?php echo esc_textarea($settings['system_instructions']); ?></textarea>
                </section>

                <section class="xabia-lite-card">
                    <div class="xabia-lite-card__head">
                        <span class="xabia-lite-card__icon"><?php echo self::icon('table'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                        <h2 class="xabia-lite-card__title"><?php echo esc_html__('Base de Conocimiento Local (CSV y Web)', 'xabia-intelligence'); ?></h2>
                    </div>
                    <p class="xabia-lite-card__desc">
                        <?php echo esc_html__('Combina un CSV de catálogo con el contenido público de tu propia web. Todo se inyecta en cada petición (sin embeddings ni Hub).', 'xabia-intelligence'); ?>
                    </p>

                    <div class="xabia-lite-knowledge-block">
                        <h3 class="xabia-lite-knowledge-block__title">
                            <span class="xabia-lite-knowledge-block__icon"><?php echo self::icon('table'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                            <?php echo esc_html__('Catálogo CSV', 'xabia-intelligence'); ?>
                        </h3>
                        <p class="xabia-lite-card__desc">
                            <?php echo esc_html__('Sube un CSV con tu catálogo de productos, servicios o precios.', 'xabia-intelligence'); ?>
                        </p>

                        <?php if ($csv_exists) : ?>
                            <p class="xabia-lite-csv-status">
                                <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                                <?php
                                echo esc_html(
                                    sprintf(
                                        /* translators: 1: file name, 2: human file size */
                                        __('CSV cargado: %1$s (%2$s)', 'xabia-intelligence'),
                                        $settings['csv_basename'],
                                        size_format($csv_size)
                                    )
                                );
                                ?>
                                <?php if ($settings['csv_uploaded_at'] > 0) : ?>
                                    <span class="xabia-lite-muted">
                                        — <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $settings['csv_uploaded_at'])); ?>
                                    </span>
                                <?php endif; ?>
                            </p>
                        <?php else : ?>
                            <p class="xabia-lite-csv-status xabia-lite-csv-status--empty">
                                <span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span>
                                <?php echo esc_html__('Sin catálogo cargado', 'xabia-intelligence'); ?>
                            </p>
                        <?php endif; ?>

                        <input type="file" name="xabia_lite_csv" accept=".csv,text/csv" />
                    </div>

                    <div class="xabia-lite-knowledge-block">
                        <h3 class="xabia-lite-knowledge-block__title">
                            <span class="xabia-lite-knowledge-block__icon"><?php echo self::icon('globe'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                            <?php echo esc_html__('Scraper Superficial de la Web Local', 'xabia-intelligence'); ?>
                        </h3>
                        <p class="xabia-lite-card__desc">
                            <?php echo esc_html__('Extrae y analiza el contenido público de las páginas y entradas de tu propio sitio WordPress para dar contexto al asistente (sin RAG vectorial ni Hub).', 'xabia-intelligence'); ?>
                        </p>

                        <?php
                        $web_pages = isset($settings['web_pages_count']) ? (int) $settings['web_pages_count'] : 0;
                        $web_synced = isset($settings['web_synced_at']) ? (int) $settings['web_synced_at'] : 0;
                        $web_status_class = $web_pages > 0 ? 'xabia-lite-csv-status' : 'xabia-lite-csv-status xabia-lite-csv-status--empty';
                        ?>
                        <p class="<?php echo esc_attr($web_status_class); ?>">
                            <?php if ($web_pages > 0) : ?>
                                <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                            <?php else : ?>
                                <span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
                            <?php endif; ?>
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: %d: indexed page count */
                                    __('Páginas detectadas/indexadas: %d páginas', 'xabia-intelligence'),
                                    $web_pages
                                )
                            );
                            ?>
                        </p>
                        <p class="xabia-lite-muted xabia-lite-web-sync">
                            <?php
                            if ($web_synced > 0) {
                                echo esc_html(
                                    sprintf(
                                        /* translators: %s: localized datetime */
                                        __('Última sincronización: %s', 'xabia-intelligence'),
                                        date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $web_synced)
                                    )
                                );
                            } else {
                                echo esc_html__('Última sincronización: No indexado', 'xabia-intelligence');
                            }
                            ?>
                        </p>

                        <button
                            type="submit"
                            class="button xabia-lite-index-web-btn"
                            name="<?php echo esc_attr(self::INDEX_WEB_ACTION); ?>"
                            value="1"
                        >
                            <?php echo esc_html__('Indexar contenido de esta web', 'xabia-intelligence'); ?>
                        </button>
                    </div>

                    <div class="xabia-lite-pro-banner">
                        <?php
                        printf(
                            /* translators: %s: link to PRO upgrade */
                            esc_html__('¿Cansado de actualizar el CSV a mano? Xabia PRO sincroniza WooCommerce, stock y reservas en tiempo real. %s', 'xabia-intelligence'),
                            '<a href="' . esc_url($upgrade_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Descubre Xabia PRO →', 'xabia-intelligence') . '</a>'
                        );
                        ?>
                    </div>
                </section>

                <p class="submit xabia-lite-submit">
                    <button type="submit" class="button button-primary"><?php echo esc_html__('Guardar ajustes', 'xabia-intelligence'); ?></button>
                </p>
            </form>

            <?php self::render_pro_catalog_grid($upgrade_url); ?>
        </div>
        <?php
    }

    /**
     * Grid de funcionalidades PRO (informativo, sin avisos de error de addons).
     */
    private static function render_pro_catalog_grid(string $upgrade_url): void {
        $features = [
            [
                'icon'  => 'cart',
                'title' => __('WooCommerce', 'xabia-intelligence'),
                'desc'  => __('Catálogo en vivo, carrito asistido y acciones de compra.', 'xabia-intelligence'),
            ],
            [
                'icon'  => 'calendar',
                'title' => __('Amelia Booking', 'xabia-intelligence'),
                'desc'  => __('Citas, servicios y disponibilidad Amelia en el chat.', 'xabia-intelligence'),
            ],
            [
                'icon'  => 'calendar',
                'title' => __('Modern Events Calendar', 'xabia-intelligence'),
                'desc'  => __('Eventos, plazas y reservas MEC sincronizadas.', 'xabia-intelligence'),
            ],
            [
                'icon'  => 'building',
                'title' => __('Avirato', 'xabia-intelligence'),
                'desc'  => __('Motor de reservas hotelero con disponibilidad real.', 'xabia-intelligence'),
            ],
            [
                'icon'  => 'document',
                'title' => __('Ingesta PDF', 'xabia-intelligence'),
                'desc'  => __('Extrae e indexa PDFs para RAG vectorial avanzado.', 'xabia-intelligence'),
            ],
            [
                'icon'  => 'palette',
                'title' => __('Color y Voz', 'xabia-intelligence'),
                'desc'  => __('Personaliza colores del widget y síntesis de voz TTS.', 'xabia-intelligence'),
            ],
        ];
        ?>
        <section class="xabia-lite-card">
            <div class="xabia-lite-card__head">
                <span class="xabia-lite-card__icon"><?php echo self::icon('apps'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <h2 class="xabia-lite-card__title"><?php echo esc_html__('Catálogo de Funcionalidades PRO', 'xabia-intelligence'); ?></h2>
            </div>
            <p class="xabia-lite-card__desc">
                <?php echo esc_html__('Estas integraciones y capacidades avanzadas están disponibles al actualizar a Xabia Agent PRO.', 'xabia-intelligence'); ?>
            </p>

            <div class="xabia-lite-pro-grid">
                <?php foreach ($features as $feature) : ?>
                    <article class="xabia-lite-pro-tile">
                        <span class="xabia-lite-pro-tile__icon"><?php echo self::icon((string) $feature['icon']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                        <div class="xabia-lite-pro-tile__head">
                            <h3 class="xabia-lite-pro-tile__title"><?php echo esc_html($feature['title']); ?></h3>
                            <span class="xabia-lite-pro-badge">PRO</span>
                        </div>
                        <p class="xabia-lite-pro-tile__desc"><?php echo esc_html($feature['desc']); ?></p>
                        <a class="xabia-lite-pro-tile__btn" href="<?php echo esc_url($upgrade_url); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo esc_html__('Desbloquear en PRO', 'xabia-intelligence'); ?>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    /**
     * Iconos SVG minimalistas estilo Material (monocromo).
     */
    private static function icon(string $name): string {
        $stroke = ' stroke="currentColor" fill="none" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"';
        $wrap = static function (string $paths) {
            return '<svg class="xabia-lite-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths . '</svg>';
        };

        switch ($name) {
            case 'settings':
                return $wrap('<circle cx="12" cy="12" r="3"' . $stroke . '/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"' . $stroke . '/>');
            case 'table':
                return $wrap('<rect x="3" y="3" width="18" height="18" rx="2"' . $stroke . '/><path d="M3 9h18M3 15h18M9 3v18M15 3v18"' . $stroke . '/>');
            case 'apps':
                return $wrap('<rect x="3" y="3" width="7" height="7" rx="1.5"' . $stroke . '/><rect x="14" y="3" width="7" height="7" rx="1.5"' . $stroke . '/><rect x="3" y="14" width="7" height="7" rx="1.5"' . $stroke . '/><rect x="14" y="14" width="7" height="7" rx="1.5"' . $stroke . '/>');
            case 'cart':
                return $wrap('<path d="M6 6h15l-1.5 9h-12L6 6zM6 6L5 3H2M9 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm9 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"' . $stroke . '/>');
            case 'calendar':
                return $wrap('<rect x="3" y="4" width="18" height="18" rx="2"' . $stroke . '/><path d="M16 2v4M8 2v4M3 10h18"' . $stroke . '/>');
            case 'building':
                return $wrap('<path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-4h6v4M9 10h.01M15 10h.01M9 14h.01M15 14h.01"' . $stroke . '/>');
            case 'document':
                return $wrap('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5"' . $stroke . '/>');
            case 'palette':
                return $wrap('<path d="M12 3a9 9 0 1 0 8.5 12.2 2.5 2.5 0 0 1-2.8-2.8A9 9 0 0 0 12 3zM8 10.5h.01M12 8h.01M16 10.5h.01M10.5 14.5h.01"' . $stroke . '/><path d="M7 17.5c1.2 1 2.7 1.5 4.3 1.5"' . $stroke . '/>');
            case 'key':
                return $wrap('<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"' . $stroke . '/>');
            case 'cloud':
                return $wrap('<path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"' . $stroke . '/>');
            case 'globe':
                return $wrap('<circle cx="12" cy="12" r="10"' . $stroke . '/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"' . $stroke . '/>');
            default:
                return $wrap('<circle cx="12" cy="12" r="9"' . $stroke . '/>');
        }
    }
}
