<?php
/**
 * Bootstrap PHPUnit — solo componentes LITE (sin Hub ni Xabia_API).
 *
 * Localiza automáticamente la WordPress Test Suite vía WP_TESTS_DIR o vendor/wp-phpunit.
 */

$plugin_root = dirname(__DIR__);

// Localizar la librería de pruebas nativa de WordPress de forma dinámica.
$_tests_dir = getenv('WP_TESTS_DIR');

if (!$_tests_dir) {
    // Fallback automático si wp-phpunit está instalado localmente vía Composer.
    $vendor_tests_dir = $plugin_root . '/vendor/wp-phpunit/wp-phpunit';
    if (file_exists($vendor_tests_dir . '/includes/bootstrap.php')) {
        $_tests_dir = $vendor_tests_dir;
    }
}

if (!$_tests_dir || !file_exists(rtrim((string) $_tests_dir, '/') . '/includes/bootstrap.php')) {
    echo "Error: No se ha podido localizar la librería de pruebas de WordPress (WP_TESTS_DIR o vendor).\n";
    exit(1);
}

$_tests_dir = rtrim((string) $_tests_dir, '/');

require_once $_tests_dir . '/includes/functions.php';

if (!function_exists('str_starts_with')) {
    /**
     * Polyfill PHP 8.0 para entornos de prueba en PHP 7.4.
     */
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

tests_add_filter('muplugins_loaded', static function () use ($plugin_root): void {
    if (!defined('XABIA_PATH')) {
        define('XABIA_PATH', $plugin_root . '/');
    }
    if (!defined('XABIA_FORCE_LITE_MODE')) {
        define('XABIA_FORCE_LITE_MODE', true);
    }

    require_once XABIA_PATH . 'core/class-xabia-mode.php';
    require_once XABIA_PATH . 'core/class-xabia-lite-secrets.php';
    require_once XABIA_PATH . 'core/class-xabia-lite-context.php';

    if (!class_exists('Xabia_Lite_API_Handler', false)) {
        require_once XABIA_PATH . 'core/class-xabia-lite-api-handler.php';
    }
});

require $_tests_dir . '/includes/bootstrap.php';
