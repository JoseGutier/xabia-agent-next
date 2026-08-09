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
     * @return array{has_gemini_api_key:bool,system_instructions:string,csv_basename:string,csv_uploaded_at:int}
     */
    public static function get_lite_settings(): array {
        $raw = get_option(self::OPTION_LITE_SETTINGS, []);
        if (!is_array($raw)) {
            $raw = [];
        }

        self::maybe_migrate_plaintext_api_key($raw);

        return [
            'has_gemini_api_key'  => self::raw_has_gemini_api_key($raw),
            'system_instructions' => isset($raw['system_instructions']) && is_string($raw['system_instructions'])
                ? $raw['system_instructions']
                : '',
            'csv_basename'        => isset($raw['csv_basename']) && is_string($raw['csv_basename'])
                ? sanitize_file_name($raw['csv_basename'])
                : '',
            'csv_uploaded_at'     => isset($raw['csv_uploaded_at']) && is_numeric($raw['csv_uploaded_at'])
                ? (int) $raw['csv_uploaded_at']
                : 0,
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
        if (isset($settings['csv_basename']) && is_string($settings['csv_basename'])) {
            $current['csv_basename'] = sanitize_file_name($settings['csv_basename']);
        }
        if (isset($settings['csv_uploaded_at']) && is_numeric($settings['csv_uploaded_at'])) {
            $current['csv_uploaded_at'] = (int) $settings['csv_uploaded_at'];
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

        if (isset($value['csv_basename']) && is_string($value['csv_basename'])) {
            $basename = sanitize_file_name($value['csv_basename']);
            if ($basename !== '') {
                $out['csv_basename'] = $basename;
            }
        }

        if (isset($value['csv_uploaded_at']) && is_numeric($value['csv_uploaded_at'])) {
            $out['csv_uploaded_at'] = max(0, (int) $value['csv_uploaded_at']);
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
