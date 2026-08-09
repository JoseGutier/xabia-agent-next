<?php
/**
 * Panel de administración Xabia LITE (BYOK + CSV local).
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

    public static function init(): void {
        if (!class_exists('Xabia_Mode', false) || !Xabia_Mode::is_lite()) {
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
        add_menu_page(
            __('Xabia LITE', 'xabia-intelligence'),
            __('Xabia LITE', 'xabia-intelligence'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page'],
            'dashicons-superhero',
            25
        );
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'toplevel_page_' . self::PAGE_SLUG) {
            return;
        }

        $ver = defined('XABIA_VERSION') ? XABIA_VERSION : '1.0.0';
        $admin_css = XABIA_PATH . 'admin/css/xabia-admin.css';
        $lite_css = XABIA_PATH . 'admin/css/xabia-lite-admin.css';
        if (is_readable($admin_css)) {
            $ver .= '.' . (string) filemtime($admin_css);
            wp_enqueue_style('xabia-admin', plugins_url('admin/css/xabia-admin.css', XABIA_PATH . 'xabia-intelligence.php'), [], $ver);
        }
        if (is_readable($lite_css)) {
            $lite_ver = (defined('XABIA_VERSION') ? XABIA_VERSION : '1.0.0') . '.' . (string) filemtime($lite_css);
            wp_enqueue_style('xabia-lite-admin', plugins_url('admin/css/xabia-lite-admin.css', XABIA_PATH . 'xabia-intelligence.php'), ['xabia-admin'], $lite_ver);
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

        $settings = [
            'system_instructions' => isset($_POST['xabia_lite_system_instructions'])
                ? sanitize_textarea_field(wp_unslash((string) $_POST['xabia_lite_system_instructions']))
                : '',
        ];

        $api_key = isset($_POST['xabia_lite_gemini_api_key'])
            ? sanitize_text_field(wp_unslash((string) $_POST['xabia_lite_gemini_api_key']))
            : '';
        if ($api_key !== '') {
            if (!Xabia_Mode::store_lite_gemini_api_key($api_key)) {
                add_settings_error(
                    'xabia_lite',
                    'xabia_lite_key',
                    __('No se pudo guardar la API Key de forma segura. Comprueba que OpenSSL esté disponible en el servidor.', 'xabia-intelligence'),
                    'error'
                );
                return;
            }
        }

        $current = get_option(Xabia_Mode::OPTION_LITE_SETTINGS, []);
        if (!is_array($current)) {
            $current = [];
        }
        $settings['csv_basename'] = isset($current['csv_basename']) && is_string($current['csv_basename'])
            ? $current['csv_basename']
            : '';
        $settings['csv_uploaded_at'] = isset($current['csv_uploaded_at']) ? (int) $current['csv_uploaded_at'] : 0;
        if (isset($current['gemini_api_key_enc']) && is_string($current['gemini_api_key_enc'])) {
            $settings['gemini_api_key_enc'] = $current['gemini_api_key_enc'];
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
        $has_api_key = !empty($settings['has_gemini_api_key']);
        $csv_path = Xabia_Mode::lite_csv_path();
        $csv_exists = $csv_path !== '' && is_readable($csv_path);
        $csv_size = $csv_exists ? (int) filesize($csv_path) : 0;
        $upgrade_url = Xabia_Mode::pro_upgrade_url();
        ?>
        <div class="wrap xabia-wrapper xabia-admin-app xabia-page-lite">
            <div class="xabia-card xabia-admin-header xabia-lite-header">
                <div class="xabia-admin-header__text">
                    <div class="xabia-lite-header__badges">
                        <span class="xabia-lite-badge"><?php echo esc_html__('Versión LITE (Gratuita)', 'xabia-intelligence'); ?></span>
                    </div>
                    <h1 class="xabia-page-title"><?php echo esc_html__('Xabia LITE', 'xabia-intelligence'); ?></h1>
                    <p class="xabia-page-subtitle">
                        <?php echo esc_html__('Configura tu agente con tu propia API Key de Google Gemini y un CSV de catálogo. Sin servidores Xabia ni infraestructura vectorial.', 'xabia-intelligence'); ?>
                    </p>
                </div>
                <div class="xabia-lite-header__cta">
                    <a class="button button-primary xabia-btn xabia-lite-upsell-btn" href="<?php echo esc_url($upgrade_url); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html__('⚡ Pásate a Xabia PRO (Sincronización automática de stock, WooCommerce y reservas)', 'xabia-intelligence'); ?>
                    </a>
                </div>
            </div>

            <?php settings_errors('xabia_lite'); ?>

            <form method="post" action="" enctype="multipart/form-data" class="xabia-lite-form">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <div class="xabia-card">
                    <h2 class="xabia-card-title"><?php echo esc_html__('API Key de Gemini', 'xabia-intelligence'); ?></h2>
                    <p class="xabia-card-desc">
                        <?php echo esc_html__('Obtén tu clave en Google AI Studio. Se guarda solo en tu WordPress (BYOK).', 'xabia-intelligence'); ?>
                    </p>
                    <?php if ($has_api_key) : ?>
                        <p class="xabia-lite-muted">
                            <?php echo esc_html__('Clave configurada. Deja el campo vacío para mantenerla.', 'xabia-intelligence'); ?>
                        </p>
                    <?php endif; ?>
                    <input
                        type="password"
                        class="regular-text xabia-lite-input"
                        name="xabia_lite_gemini_api_key"
                        value=""
                        autocomplete="off"
                        placeholder="<?php echo esc_attr__('Pega tu API Key de Gemini…', 'xabia-intelligence'); ?>"
                    />
                </div>

                <div class="xabia-card">
                    <h2 class="xabia-card-title"><?php echo esc_html__('System Instructions (personalidad)', 'xabia-intelligence'); ?></h2>
                    <p class="xabia-card-desc">
                        <?php echo esc_html__('Define el tono y las reglas básicas del asistente (sin RAG vectorial en LITE).', 'xabia-intelligence'); ?>
                    </p>
                    <textarea
                        class="large-text xabia-lite-textarea"
                        name="xabia_lite_system_instructions"
                        rows="8"
                        placeholder="<?php echo esc_attr__('Ej.: Eres un asistente amable de esta tienda. Responde en el idioma del usuario…', 'xabia-intelligence'); ?>"
                    ><?php echo esc_textarea($settings['system_instructions']); ?></textarea>
                </div>

                <div class="xabia-card">
                    <h2 class="xabia-card-title"><?php echo esc_html__('Conocimiento (CSV)', 'xabia-intelligence'); ?></h2>
                    <p class="xabia-card-desc">
                        <?php echo esc_html__('Sube un CSV con tu catálogo. En LITE se inyecta en cada petición a Gemini (sin embeddings ni Hub).', 'xabia-intelligence'); ?>
                    </p>
                    <?php if ($csv_exists) : ?>
                        <p class="xabia-lite-csv-status">
                            <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: 1: file name, 2: human file size */
                                    __('CSV activo: %1$s (%2$s)', 'xabia-intelligence'),
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
                        <p class="xabia-lite-muted"><?php echo esc_html__('Aún no hay CSV guardado.', 'xabia-intelligence'); ?></p>
                    <?php endif; ?>
                    <input type="file" name="xabia_lite_csv" accept=".csv,text/csv" />
                    <p class="xabia-lite-upsell-copy">
                        <?php echo esc_html__('¿Cansado de actualizar este CSV a mano cada vez que cambias un precio o añades un producto? Xabia PRO automatiza tu catálogo leyendo directamente de WooCommerce en tiempo real.', 'xabia-intelligence'); ?>
                        <a href="<?php echo esc_url($upgrade_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Descubre Xabia PRO →', 'xabia-intelligence'); ?></a>
                    </p>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary xabia-btn"><?php echo esc_html__('Guardar ajustes', 'xabia-intelligence'); ?></button>
                </p>
            </form>
        </div>
        <?php
    }
}
