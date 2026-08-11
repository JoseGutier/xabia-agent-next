<?php
/**
 * Helpers de UI — branding Xabia, campos PRO bloqueados con upsell.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Admin_UI {

    /**
     * URL de un asset de marca en admin/assets/brand/.
     */
    public static function brand_asset_url(string $filename): string {
        $filename = ltrim(str_replace(['..', '\\'], '', $filename), '/');
        if ($filename === '' || !defined('XABIA_URL')) {
            return '';
        }

        return trailingslashit(XABIA_URL) . 'admin/assets/brand/' . $filename;
    }

    /**
     * Icono oficial (X + tres puntos) para menú WP.
     * Usa PNG transparente (sin caja gris) para no romper el layout del menú.
     */
    public static function menu_icon_url(): string {
        $url = self::brand_asset_url('icono-xabia.png');
        return $url !== '' ? $url : 'dashicons-superhero';
    }

    /**
     * CSS obligatorio: WP no limita bien imágenes grandes en .wp-menu-image.
     */
    public static function print_menu_icon_styles(): void {
        echo '<style id="xabia-admin-menu-icon">'
            . '#adminmenu #toplevel_page_xabia-lite .wp-menu-image img,'
            . '#adminmenu #toplevel_page_xabia-settings .wp-menu-image img{'
            . 'width:20px!important;height:20px!important;padding:6px 0!important;object-fit:contain!important;'
            . 'opacity:1!important;max-width:20px!important;max-height:20px!important;'
            . '}'
            . '#adminmenu #toplevel_page_xabia-lite.current .wp-menu-image img,'
            . '#adminmenu #toplevel_page_xabia-settings.current .wp-menu-image img,'
            . '#adminmenu #toplevel_page_xabia-lite:hover .wp-menu-image img,'
            . '#adminmenu #toplevel_page_xabia-settings:hover .wp-menu-image img{'
            . 'opacity:1!important;'
            . '}'
            . '</style>';
    }

    /**
     * HTML del icono de marca (SVG preferido).
     */
    public static function render_brand_icon(string $class = 'xabia-brand-icon', int $size = 40): void {
        $url = self::brand_asset_url('icono-xabia.svg');
        if ($url === '') {
            $url = self::brand_asset_url('icono-xabia.png');
        }
        if ($url === '') {
            echo '<span class="' . esc_attr($class) . ' xabia-brand-icon--fallback" aria-hidden="true">X</span>';
            return;
        }

        printf(
            '<img class="%1$s" src="%2$s" width="%3$d" height="%3$d" alt="" decoding="async" />',
            esc_attr($class),
            esc_url($url),
            max(16, $size)
        );
    }

    /**
     * HTML del wordmark oficial «xabia».
     */
    public static function render_brand_logo(string $class = 'xabia-brand-logo', int $height = 36): void {
        $url = self::brand_asset_url('logo-xabia.svg');
        if ($url === '') {
            $url = self::brand_asset_url('logo-xabia.png');
        }
        if ($url === '') {
            echo '<span class="' . esc_attr($class) . ' xabia-brand-logo--text">' . esc_html__('xabia', 'xabia-intelligence') . '</span>';
            return;
        }

        printf(
            '<img class="%1$s" src="%2$s" height="%3$d" alt="%4$s" decoding="async" />',
            esc_attr($class),
            esc_url($url),
            max(20, $height),
            esc_attr__('xabia', 'xabia-intelligence')
        );
    }

    /**
     * Renderiza un control editable (PRO) o atenuado con badge PRO + CTA (LITE).
     *
     * @param string $field_key        Identificador interno (p. ej. chat_colors).
     * @param string $label            Etiqueta visible del campo.
     * @param string $input_html       HTML del control (input, select, textarea…).
     * @param string $pro_description  Texto opcional bajo el control en modo LITE.
     */
    public static function render_field_or_pro(
        string $field_key,
        string $label,
        string $input_html,
        string $pro_description = ''
    ): void {
        $field_key = sanitize_key($field_key);
        $upgrade_url = class_exists('Xabia_Features', false)
            ? Xabia_Features::pro_upgrade_url()
            : 'https://xabia.ai';
        $is_locked = class_exists('Xabia_Features', false) && Xabia_Features::is_lite();

        echo '<div class="xabia-field-row' . ($is_locked ? ' xabia-field-row--pro-locked' : '') . '" data-xabia-field="' . esc_attr($field_key) . '">';

        if ($label !== '') {
            echo '<div class="xabia-field-row__label">';
            echo '<span class="xabia-field-label">' . esc_html($label) . '</span>';
            if ($is_locked) {
                self::render_pro_badge();
            }
            echo '</div>';
        } elseif ($is_locked) {
            self::render_pro_badge();
        }

        if ($is_locked) {
            echo '<div class="xabia-pro-locked-control" aria-disabled="true">';
            echo self::disable_input_html($input_html);
            echo '</div>';
            self::render_pro_upsell_line($upgrade_url, $pro_description);
        } else {
            echo $input_html;
        }

        echo '</div>';
    }

    public static function render_pro_badge(): void {
        echo '<span class="xabia-pro-badge" aria-label="' . esc_attr__('Función PRO', 'xabia-intelligence') . '">PRO</span>';
    }

    /**
     * @param string $upgrade_url
     * @param string $pro_description
     */
    public static function render_pro_upsell_line(string $upgrade_url, string $pro_description = ''): void {
        echo '<p class="xabia-pro-upsell-line">';
        if ($pro_description !== '') {
            echo esc_html($pro_description) . ' ';
        }
        echo esc_html__('Funcionalidad exclusiva de Xabia Agent PRO.', 'xabia-intelligence');
        echo ' <a href="' . esc_url($upgrade_url) . '" target="_blank" rel="noopener noreferrer">';
        echo esc_html__('Descubrir versión PRO', 'xabia-intelligence');
        echo '</a>';
        echo '</p>';
    }

    /**
     * Deshabilita inputs dentro del HTML pasado (seguro para preview LITE).
     */
    private static function disable_input_html(string $html): string {
        if ($html === '') {
            return '';
        }

        $html = preg_replace('/\sdisabled(?:="[^"]*")?/i', '', $html) ?? $html;
        $html = preg_replace('/<((?:input|select|textarea|button)\b[^>]*)(>)/i', '<$1 disabled$2', $html) ?? $html;

        return $html;
    }
}
