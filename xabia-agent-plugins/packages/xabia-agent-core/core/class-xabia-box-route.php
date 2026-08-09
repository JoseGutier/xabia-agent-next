<?php
/**
 * Ruta pública /xabia-box/ — experiencia a pantalla completa (Smart QR / túnel).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Box_Route {

    public const QUERY_VAR = 'xabia_box';

    public static function init(): void {
        add_action('init', [self::class, 'register_rewrite'], 5);
        add_filter('query_vars', [self::class, 'filter_query_vars']);
        add_filter('template_include', [self::class, 'filter_template_include'], 99);
    }

    public static function register_rewrite(): void {
        add_rewrite_rule('^xabia-box/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
    }

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public static function filter_query_vars(array $vars): array {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public static function filter_template_include(string $template): string {
        if ((int) get_query_var(self::QUERY_VAR) !== 1) {
            return $template;
        }
        $file = trailingslashit(XABIA_PATH) . 'frontend/xabia-box-template.php';
        return is_readable($file) ? $file : $template;
    }

    public static function activate_flush(): void {
        self::register_rewrite();
        flush_rewrite_rules(false);
        if (defined('XABIA_VERSION')) {
            update_option('xabia_box_rewrite_pkg', XABIA_VERSION, false);
        }
    }
}
