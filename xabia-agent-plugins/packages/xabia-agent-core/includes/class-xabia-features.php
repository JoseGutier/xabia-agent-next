<?php
/**
 * Gestor de características PRO vs LITE — desarrollo en paralelo (WordPress.org + retail).
 *
 * Punto único de comprobación para cargadores, addons y UI de upsell.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Features {

    /**
     * true = Xabia Agent PRO (licencia, build retail o constante XABIA_PRO_VERSION).
     * false = LITE (WordPress.org / BYOK / sin licencia).
     */
    public static function is_pro(): bool {
        if (self::is_lite_build()) {
            return false;
        }

        if (class_exists('Xabia_Mode', false)) {
            return Xabia_Mode::is_pro();
        }

        if (defined('XABIA_PRO_VERSION') && (string) XABIA_PRO_VERSION !== '') {
            return true;
        }

        return false;
    }

    public static function is_lite(): bool {
        return !self::is_pro();
    }

    /**
     * Paquete WordPress.org o build local LITE.
     */
    public static function is_lite_build(): bool {
        if (defined('XABIA_AGENT_LITE') && XABIA_AGENT_LITE) {
            return true;
        }

        return defined('XABIA_LITE_BUILD') && XABIA_LITE_BUILD;
    }

    public static function pro_upgrade_url(): string {
        if (class_exists('Xabia_Mode', false)) {
            return Xabia_Mode::pro_upgrade_url();
        }

        return (string) apply_filters('xabia_lite_pro_upgrade_url', 'https://xabia.ai');
    }

    /**
     * Respuesta JSON 403 estándar para endpoints PRO invocados en LITE.
     *
     * @param string $feature Clave opcional de característica (remote_sql, pdf_ingest…).
     */
    public static function reject_pro_json(string $feature = ''): void {
        $payload = [
            'message'     => __('Esta funcionalidad requiere Xabia Agent PRO.', 'xabia-intelligence'),
            'code'        => 'pro_required',
            'upgrade_url' => self::pro_upgrade_url(),
        ];

        if ($feature !== '') {
            $payload['feature'] = sanitize_key($feature);
        }

        wp_send_json_error($payload, 403);
    }
}
