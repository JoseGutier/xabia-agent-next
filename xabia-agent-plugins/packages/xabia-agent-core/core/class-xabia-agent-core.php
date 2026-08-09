<?php
/**
 * Bootstrap del core: instalación automática del mu-plugin de aislamiento de tablas (sitio vs Hub).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Agent_Core {

    public const MU_PLUGIN_FILE = 'xabia-hub-table-isolation.php';

    private const BUNDLED_RELATIVE = 'includes/mu-plugins/xabia-hub-table-isolation.php';

    public static function bundled_mu_source_path(): string {
        return trailingslashit(XABIA_PATH) . self::BUNDLED_RELATIVE;
    }

    public static function mu_plugin_destination_path(): string {
        if (defined('WPMU_PLUGIN_DIR') && is_string(WPMU_PLUGIN_DIR) && WPMU_PLUGIN_DIR !== '') {
            return trailingslashit(WPMU_PLUGIN_DIR) . self::MU_PLUGIN_FILE;
        }

        return trailingslashit(WP_CONTENT_DIR) . 'mu-plugins/' . self::MU_PLUGIN_FILE;
    }

    /**
     * Crea wp-content/mu-plugins si hace falta y copia (sobrescribe) el peacekeeper desde el bundle del core.
     */
    public static function install_mu_plugin_table_isolation(): bool {
        $src = self::bundled_mu_source_path();
        if (!is_readable($src)) {
            return false;
        }
        $dest = self::mu_plugin_destination_path();
        $dir = dirname($dest);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- única vía portable para despliegue MU.
        return @copy($src, $dest);
    }

    /**
     * Incluye el mu-plugin en esta petición si hace falta (p. ej. recién copiado antes de que WP lo cargara al inicio).
     */
    public static function load_mu_plugin_for_current_request(): void {
        $dest = self::mu_plugin_destination_path();
        if (!is_readable($dest)) {
            return;
        }
        require_once $dest;
    }

    /**
     * Sincroniza el archivo MU con el bundle del core cuando cambia la versión del plugin o el contenido del fuente.
     *
     * @return bool True si hubo copia nueva (conviene cargar el MU en la misma petición antes de dbDelta).
     */
    public static function maybe_sync_mu_plugin(): bool {
        $src = self::bundled_mu_source_path();
        if (!is_readable($src)) {
            return false;
        }
        $dest = self::mu_plugin_destination_path();
        $verOpt = (string) get_option('xabia_mu_table_isolation_pkg', '');
        $need = $verOpt !== XABIA_VERSION || !is_readable($dest);
        if (!$need && is_readable($dest)) {
            $sumSrc = @md5_file($src);
            $sumDst = @md5_file($dest);
            $need = is_string($sumSrc) && is_string($sumDst) && $sumSrc !== $sumDst;
        }
        if (!$need) {
            return false;
        }
        if (!self::install_mu_plugin_table_isolation()) {
            return false;
        }
        update_option('xabia_mu_table_isolation_pkg', XABIA_VERSION, false);

        return true;
    }

    /**
     * Tamaño de fuente del chat en em (1 = tamaño base del tema). Valores > 3 se tratan como px legacy.
     */
    public static function normalize_chat_font_size_em($raw): string {
        $s = trim((string) $raw);
        $min = 0.625;
        $step = 0.05;
        if ($s === '' || !is_numeric($s)) {
            return '1.30';
        }
        $n = (float) $s;
        if ($n > 3) {
            $n = round($n / 16, 4);
        }
        $n = max($min, min(2.5, $n));
        $steps = (int) round(($n - $min) / $step);
        $n = $min + ($steps * $step);

        return number_format($n, 2, '.', '');
    }
}
