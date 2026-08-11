<?php
/**
 * Modo de ejecución PRO vs LITE (WordPress.org / BYOK).
 *
 * Sin referencias a Hub, HMAC ni integrations/central/.
 * Secretos LITE: cifrados en opción mediante Xabia_Lite_Secrets.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Mode {

    public const OPTION_LITE_SETTINGS = 'xabia_lite_settings';

    private const LITE_HTACCESS_RULES = "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";

    /** @var bool|null */
    private static $is_pro = null;

    public static function is_pro(): bool {
        if (self::$is_pro !== null) {
            return self::$is_pro;
        }

        if (defined('XABIA_LITE_BUILD') && XABIA_LITE_BUILD) {
            return self::$is_pro = false;
        }

        if (defined('XABIA_AGENT_LITE') && XABIA_AGENT_LITE) {
            return self::$is_pro = false;
        }

        $forced = apply_filters('xabia_is_pro_mode', null);
        if ($forced === true) {
            return self::$is_pro = true;
        }
        if ($forced === false) {
            return self::$is_pro = false;
        }

        if (defined('XABIA_FORCE_LITE_MODE') && XABIA_FORCE_LITE_MODE) {
            return self::$is_pro = false;
        }

        if (defined('XABIA_PRO_VERSION') && (string) XABIA_PRO_VERSION !== '') {
            return self::$is_pro = true;
        }

        if (self::has_valid_stored_license()) {
            return self::$is_pro = true;
        }

        return self::$is_pro = false;
    }

    public static function is_lite(): bool {
        return !self::is_pro();
    }

    /**
     * Detección PRO sin cargar clases Premium (solo opciones/transientes WP).
     */
    private static function has_valid_stored_license(): bool {
        if ((defined('XABIA_LITE_BUILD') && XABIA_LITE_BUILD) || (defined('XABIA_AGENT_LITE') && XABIA_AGENT_LITE)) {
            return false;
        }

        $key = get_option('xabia_digixop_license_key', '');
        if (!is_string($key) || trim($key) === '') {
            return false;
        }

        $meta = get_transient('xabia_digixop_license_meta');
        if (is_array($meta) && array_key_exists('valid', $meta)) {
            return !empty($meta['valid']);
        }

        return (bool) apply_filters('xabia_mode_license_key_unlocks_pro', true);
    }

    /**
     * Ajustes LITE para UI (nunca incluye la API key en claro).
     *
     * @return array{
     *   auth_mode:string,
     *   has_gemini_api_key:bool,
     *   has_xabia_api_key:bool,
     *   system_instructions:string,
     *   csv_basename:string,
     *   csv_uploaded_at:int,
     *   web_pages_count:int,
     *   web_synced_at:int
     * }
     */
    public static function get_lite_settings(): array {
        $raw = get_option(self::OPTION_LITE_SETTINGS, []);
        if (!is_array($raw)) {
            $raw = [];
        }

        self::maybe_migrate_plaintext_api_key($raw);

        $auth_mode = isset($raw['auth_mode']) && is_string($raw['auth_mode'])
            ? sanitize_key($raw['auth_mode'])
            : 'byok';
        if (!in_array($auth_mode, ['xabia_cloud', 'byok'], true)) {
            $auth_mode = 'byok';
        }

        $web_pages = isset($raw['web_pages_count']) && is_numeric($raw['web_pages_count'])
            ? max(0, (int) $raw['web_pages_count'])
            : 0;
        $web_synced = isset($raw['web_synced_at']) && is_numeric($raw['web_synced_at'])
            ? max(0, (int) $raw['web_synced_at'])
            : 0;

        if ($web_pages === 0 && $web_synced === 0 && class_exists('Xabia_Lite_Scraper', false)) {
            $index = Xabia_Lite_Scraper::get_index();
            $web_pages = (int) $index['pages'];
            $web_synced = (int) $index['synced_at'];
        }

        return [
            'auth_mode'           => $auth_mode,
            'has_gemini_api_key'  => self::raw_has_gemini_api_key($raw),
            'has_xabia_api_key'   => self::raw_has_xabia_api_key($raw),
            'system_instructions' => isset($raw['system_instructions']) && is_string($raw['system_instructions'])
                ? $raw['system_instructions']
                : '',
            'csv_basename'        => isset($raw['csv_basename']) && is_string($raw['csv_basename'])
                ? sanitize_file_name($raw['csv_basename'])
                : '',
            'csv_uploaded_at'     => isset($raw['csv_uploaded_at']) && is_numeric($raw['csv_uploaded_at'])
                ? (int) $raw['csv_uploaded_at']
                : 0,
            'web_pages_count'     => $web_pages,
            'web_synced_at'       => $web_synced,
        ];
    }

    /**
     * API key Gemini descifrada — solo uso servidor (nunca frontend / HTML / JS).
     */
    public static function get_lite_gemini_api_key(): string {
        if (!class_exists('Xabia_Lite_Secrets', false)) {
            return '';
        }

        $raw = get_option(self::OPTION_LITE_SETTINGS, []);
        if (!is_array($raw)) {
            return '';
        }

        self::maybe_migrate_plaintext_api_key($raw);

        $enc = isset($raw['gemini_api_key_enc']) && is_string($raw['gemini_api_key_enc'])
            ? trim($raw['gemini_api_key_enc'])
            : '';
        if ($enc === '') {
            return '';
        }

        return trim(Xabia_Lite_Secrets::decrypt($enc));
    }

    public static function has_lite_gemini_api_key(): bool {
        return self::get_lite_settings()['has_gemini_api_key'];
    }

    public static function get_lite_xabia_api_key(): string {
        if (!class_exists('Xabia_Lite_Secrets', false)) {
            return '';
        }

        $raw = get_option(self::OPTION_LITE_SETTINGS, []);
        if (!is_array($raw)) {
            return '';
        }

        $enc = isset($raw['xabia_api_key_enc']) && is_string($raw['xabia_api_key_enc'])
            ? trim($raw['xabia_api_key_enc'])
            : '';
        if ($enc === '') {
            return '';
        }

        return trim(Xabia_Lite_Secrets::decrypt($enc));
    }

    public static function has_lite_xabia_api_key(): bool {
        return self::get_lite_settings()['has_xabia_api_key'];
    }

    public static function get_lite_auth_mode(): string {
        return self::get_lite_settings()['auth_mode'];
    }

    public static function store_lite_xabia_api_key(string $api_key): bool {
        if (!class_exists('Xabia_Lite_Secrets', false)) {
            return false;
        }

        $api_key = sanitize_text_field($api_key);
        if ($api_key === '') {
            return true;
        }

        $enc = Xabia_Lite_Secrets::encrypt($api_key);
        if ($enc === '') {
            return false;
        }

        return self::save_lite_settings(['xabia_api_key_enc' => $enc]);
    }

    /**
     * @param array<string, mixed> $settings Parcial desde formulario (sin clave en claro persistente).
     */
    public static function save_lite_settings(array $settings): bool {
        $current = get_option(self::OPTION_LITE_SETTINGS, []);
        if (!is_array($current)) {
            $current = [];
        }

        self::maybe_migrate_plaintext_api_key($current);

        if (isset($settings['gemini_api_key_enc']) && is_string($settings['gemini_api_key_enc'])) {
            $enc = trim($settings['gemini_api_key_enc']);
            if ($enc !== '') {
                $current['gemini_api_key_enc'] = $enc;
            }
        }

        unset($current['gemini_api_key']);

        if (array_key_exists('system_instructions', $settings)) {
            $current['system_instructions'] = sanitize_textarea_field((string) $settings['system_instructions']);
        }
        if (isset($settings['auth_mode']) && is_string($settings['auth_mode'])) {
            $mode = sanitize_key($settings['auth_mode']);
            $current['auth_mode'] = in_array($mode, ['xabia_cloud', 'byok'], true) ? $mode : 'byok';
        }
        if (isset($settings['xabia_api_key_enc']) && is_string($settings['xabia_api_key_enc'])) {
            $enc = trim($settings['xabia_api_key_enc']);
            if ($enc !== '') {
                $current['xabia_api_key_enc'] = $enc;
            }
        }
        if (isset($settings['csv_basename']) && is_string($settings['csv_basename'])) {
            $current['csv_basename'] = sanitize_file_name($settings['csv_basename']);
        }
        if (isset($settings['csv_uploaded_at']) && is_numeric($settings['csv_uploaded_at'])) {
            $current['csv_uploaded_at'] = (int) $settings['csv_uploaded_at'];
        }
        if (isset($settings['web_pages_count']) && is_numeric($settings['web_pages_count'])) {
            $current['web_pages_count'] = max(0, (int) $settings['web_pages_count']);
        }
        if (isset($settings['web_synced_at']) && is_numeric($settings['web_synced_at'])) {
            $current['web_synced_at'] = max(0, (int) $settings['web_synced_at']);
        }

        $clean = self::sanitize_lite_settings_option($current);

        return update_option(self::OPTION_LITE_SETTINGS, $clean, false);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    public static function sanitize_lite_settings_option($value): array {
        if (!is_array($value)) {
            return [];
        }

        $out = [];

        if (isset($value['gemini_api_key_enc']) && is_string($value['gemini_api_key_enc'])) {
            $enc = trim($value['gemini_api_key_enc']);
            if ($enc !== '') {
                $out['gemini_api_key_enc'] = $enc;
            }
        }

        if (isset($value['system_instructions']) && is_string($value['system_instructions'])) {
            $out['system_instructions'] = sanitize_textarea_field($value['system_instructions']);
        }

        if (isset($value['auth_mode']) && is_string($value['auth_mode'])) {
            $mode = sanitize_key($value['auth_mode']);
            if (in_array($mode, ['xabia_cloud', 'byok'], true)) {
                $out['auth_mode'] = $mode;
            }
        }

        if (isset($value['xabia_api_key_enc']) && is_string($value['xabia_api_key_enc'])) {
            $enc = trim($value['xabia_api_key_enc']);
            if ($enc !== '') {
                $out['xabia_api_key_enc'] = $enc;
            }
        }

        if (isset($value['csv_basename']) && is_string($value['csv_basename'])) {
            $basename = sanitize_file_name($value['csv_basename']);
            if ($basename !== '') {
                $out['csv_basename'] = $basename;
            }
        }

        if (isset($value['csv_uploaded_at']) && is_numeric($value['csv_uploaded_at'])) {
            $out['csv_uploaded_at'] = max(0, (int) $value['csv_uploaded_at']);
        }

        if (isset($value['web_pages_count']) && is_numeric($value['web_pages_count'])) {
            $out['web_pages_count'] = max(0, (int) $value['web_pages_count']);
        }

        if (isset($value['web_synced_at']) && is_numeric($value['web_synced_at'])) {
            $out['web_synced_at'] = max(0, (int) $value['web_synced_at']);
        }

        return $out;
    }

    public static function store_lite_gemini_api_key(string $api_key): bool {
        if (!class_exists('Xabia_Lite_Secrets', false)) {
            return false;
        }

        $api_key = sanitize_text_field($api_key);
        if ($api_key === '') {
            return true;
        }

        $enc = Xabia_Lite_Secrets::encrypt($api_key);
        if ($enc === '') {
            return false;
        }

        return self::save_lite_settings(['gemini_api_key_enc' => $enc]);
    }

    public static function lite_csv_dir(): string {
        if (!function_exists('wp_upload_dir')) {
            return '';
        }
        $uploads = wp_upload_dir();
        $base = rtrim((string) ($uploads['basedir'] ?? ''), '/\\');
        if ($base === '') {
            return '';
        }

        return $base . '/xabia/lite';
    }

    public static function lite_csv_path(): string {
        $settings = self::get_lite_settings();
        $basename = $settings['csv_basename'];
        if ($basename === '') {
            return '';
        }
        $dir = self::lite_csv_dir();
        if ($dir === '') {
            return '';
        }

        $path = $dir . '/' . $basename;
        $real_dir = realpath($dir);
        $real_path = realpath($path);
        if ($real_dir === false || $real_path === false || !str_starts_with($real_path, $real_dir)) {
            return '';
        }

        return $real_path;
    }

    public static function ensure_lite_storage_dir(): void {
        $dir = self::lite_csv_dir();
        if ($dir === '') {
            return;
        }
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return;
        }

        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, self::LITE_HTACCESS_RULES, LOCK_EX);
        }

        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n", LOCK_EX);
        }
    }

    public static function pro_upgrade_url(): string {
        return (string) apply_filters('xabia_lite_pro_upgrade_url', 'https://xabia.ai/precio');
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function raw_has_gemini_api_key(array $raw): bool {
        if (isset($raw['gemini_api_key_enc']) && is_string($raw['gemini_api_key_enc']) && trim($raw['gemini_api_key_enc']) !== '') {
            return true;
        }

        return isset($raw['gemini_api_key']) && is_string($raw['gemini_api_key']) && trim($raw['gemini_api_key']) !== '';
    }

    private static function raw_has_xabia_api_key(array $raw): bool {
        return isset($raw['xabia_api_key_enc']) && is_string($raw['xabia_api_key_enc']) && trim($raw['xabia_api_key_enc']) !== '';
    }

    /**
     * Migra claves legacy en texto plano → cifrado y purga el campo antiguo.
     *
     * @param array<string, mixed> $raw
     */
    private static function maybe_migrate_plaintext_api_key(array &$raw): void {
        if (!class_exists('Xabia_Lite_Secrets', false)) {
            return;
        }
        if (empty($raw['gemini_api_key']) || !is_string($raw['gemini_api_key'])) {
            return;
        }
        $plain = trim($raw['gemini_api_key']);
        if ($plain === '') {
            unset($raw['gemini_api_key']);
            return;
        }

        $enc = Xabia_Lite_Secrets::encrypt($plain);
        unset($raw['gemini_api_key']);
        if ($enc !== '') {
            $raw['gemini_api_key_enc'] = $enc;
            update_option(self::OPTION_LITE_SETTINGS, self::sanitize_lite_settings_option($raw), false);
        }
    }
}
