<?php
/**
 * Suprime avisos admin de error (addons PRO, licencias) en runtime LITE.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Lite_Notices {

    /** @var list<string> */
    private const ADDON_NOTICE_FUNCTIONS = [
        'xabia_mec_requires_core_notice',
        'xabia_woo_requires_core_notice',
        'xabia_avirato_requires_core_notice',
        'xabia_amelia_requires_core_notice',
        'xabia_federation_requires_core_notice',
    ];

    /** @var list<string> */
    private const LICENSE_NOTICE_PATTERNS = [
        'licencia',
        'license',
        'reactivar',
        'no coincide',
        'xabia agent core',
        'xabia-agent-core',
        'requiere el plugin xabia',
        'requiere tener activo',
        'digixop',
        'saldo de tokens',
        'recargue su licencia',
    ];

    public static function boot_for_lite(): void {
        if (!class_exists('Xabia_Features', false) || !Xabia_Features::is_lite()) {
            return;
        }

        add_filter('xabia_mode_license_key_unlocks_pro', '__return_false', 999);
        add_filter('xabia_is_pro_mode', static function ($forced) {
            if ($forced === true) {
                return false;
            }
            return $forced;
        }, 999);

        add_action('plugins_loaded', [self::class, 'unregister_addon_error_notices'], 999);
        add_action('admin_init', [self::class, 'unregister_addon_error_notices'], 1);
        add_action('admin_init', [self::class, 'unregister_addon_error_notices'], 999);
        add_filter('admin_body_class', [self::class, 'admin_body_class']);
        add_action('admin_print_styles', [self::class, 'print_lite_admin_notice_styles'], 99);
        add_action('admin_print_footer_scripts', [self::class, 'print_lite_notice_guard_script'], 99);
    }

    public static function admin_body_class(string $classes): string {
        if (!self::is_lite_admin_screen()) {
            return $classes;
        }

        return trim($classes . ' xabia-lite-admin-screen');
    }

    public static function unregister_addon_error_notices(): void {
        foreach (self::ADDON_NOTICE_FUNCTIONS as $fn) {
            if (function_exists($fn)) {
                remove_action('admin_notices', $fn);
                remove_action('network_admin_notices', $fn);
            }
        }

        if (class_exists('Xabia_Smart_QR', false)) {
            remove_action('admin_notices', [Xabia_Smart_QR::class, 'legacy_plugin_notice']);
        }
        if (class_exists('Xabia_Addon_Updater', false)) {
            remove_action('admin_notices', [Xabia_Addon_Updater::class, 'render_global_admin_notice'], 15);
        }
        if (class_exists('Xabia_Admin', false)) {
            remove_action('admin_notices', [Xabia_Admin::class, 'render_low_wallet_notice']);
        }
    }

    public static function print_lite_admin_notice_styles(): void {
        if (!is_admin()) {
            return;
        }

        echo '<style id="xabia-lite-notice-guard">';
        echo 'body.xabia-lite-admin-screen .notice.notice-error,';
        echo 'body.xabia-lite-admin-screen .notice-error,';
        echo 'body.xabia-lite-admin-screen .update-nag.notice-error,';
        echo 'body.xabia-lite-admin-screen #setting-error-tgmpa { display: none !important; }';
        echo 'body.wp-admin .xabia-lite-suppressed-notice { display: none !important; }';
        echo '</style>';
    }

    public static function print_lite_notice_guard_script(): void {
        if (!is_admin()) {
            return;
        }

        $patterns = wp_json_encode(self::LICENSE_NOTICE_PATTERNS, JSON_UNESCAPED_UNICODE);
        $hide_all_on_lite = self::is_lite_admin_screen() ? 'true' : 'false';

        echo '<script id="xabia-lite-notice-guard-js">';
        echo '(function(){';
        echo 'if(!document.body||!document.body.classList.contains("wp-admin"))return;';
        echo 'var patterns=' . $patterns . ';';
        echo 'var hideAllOnLite=' . $hide_all_on_lite . ';';
        echo 'function shouldHide(node){';
        echo 'if(!node||!node.classList)return false;';
        echo 'if(hideAllOnLite&&node.classList.contains("notice-error"))return true;';
        echo 'var t=(node.textContent||"").toLowerCase();';
        echo 'for(var i=0;i<patterns.length;i++){if(t.indexOf(patterns[i])!==-1)return true;}';
        echo 'return /xabia\\s*(mec|woo|avirato|amelia|federation|agent)/i.test(t)&&node.classList.contains("notice-error");';
        echo '}';
        echo 'document.querySelectorAll(".notice, .update-nag").forEach(function(n){';
        echo 'if(shouldHide(n)){n.classList.add("xabia-lite-suppressed-notice");}';
        echo '});';
        echo '})();';
        echo '</script>';
    }

    private static function is_lite_admin_screen(): bool {
        if (!is_admin()) {
            return false;
        }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';

        return $page === 'xabia-lite';
    }
}
