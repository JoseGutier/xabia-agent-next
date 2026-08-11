<?php
/**
 * Capa de abstracción i18n agnóstica (WPML, Polylang, TranslatePress, gettext/Loco).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Xabia_I18n_Bridge')) :

final class Xabia_I18n_Bridge {
    public const WPML_CONTEXT = 'Xabia AI';
    public const TEXT_DOMAIN = 'xabia-intelligence';

    public static function is_wpml_active(): bool {
        return function_exists('wpml_translate_single_string')
            || function_exists('wpml_register_single_string')
            || has_filter('wpml_translate_single_string')
            || function_exists('icl_register_string')
            || function_exists('wpml_get_current_language')
            || defined('ICL_SITEPRESS_VERSION');
    }

    public static function is_polylang_active(): bool {
        return function_exists('pll_current_language')
            || function_exists('pll_register_string')
            || function_exists('pll_default_language');
    }

    public static function is_translatepress_active(): bool {
        return class_exists('TRP_Translate_Press', false)
            || function_exists('trp_get_language')
            || defined('TRP_PLUGIN_DIR');
    }

    /**
     * @return ''|'wpml'|'polylang'|'translatepress'
     */
    public static function get_active_provider(): string {
        if (self::is_wpml_active()) {
            return 'wpml';
        }
        if (self::is_polylang_active()) {
            return 'polylang';
        }
        if (self::is_translatepress_active()) {
            return 'translatepress';
        }

        return '';
    }

    /**
     * Código ISO corto del idioma actual (es, en, eu…).
     */
    public static function get_current_language(): string {
        $raw = '';

        switch (self::get_active_provider()) {
            case 'wpml':
                if (function_exists('wpml_get_current_language')) {
                    $raw = (string) wpml_get_current_language();
                } elseif (defined('ICL_LANGUAGE_CODE')) {
                    $raw = (string) ICL_LANGUAGE_CODE;
                } else {
                    $raw = (string) apply_filters('wpml_current_language', '');
                }
                break;
            case 'polylang':
                if (function_exists('pll_current_language')) {
                    $raw = (string) pll_current_language('slug');
                }
                break;
            case 'translatepress':
                if (function_exists('trp_get_language')) {
                    $raw = (string) trp_get_language();
                } else {
                    global $TRP_LANGUAGE;
                    $raw = is_string($TRP_LANGUAGE ?? null) ? $TRP_LANGUAGE : '';
                }
                if ($raw === '') {
                    $raw = is_admin() ? get_user_locale() : determine_locale();
                }
                break;
            default:
                $raw = is_admin() ? get_user_locale() : determine_locale();
                break;
        }

        $code = self::to_short_iso($raw);

        return (string) apply_filters('xabia_i18n_current_language', $code, $raw);
    }

    /**
     * Etiqueta BCP-47 reducida para user_lang (proxy/hub).
     */
    public static function normalize_locale_tag(string $tag): string {
        $tag = trim($tag);
        if ($tag === '') {
            return '';
        }
        $tag = preg_replace('/[^a-zA-Z0-9\-]/', '', $tag) ?? '';

        return substr($tag, 0, 35);
    }

    /**
     * Resuelve user_lang: prioriza hint del cliente; si falta, idioma activo del sitio.
     */
    public static function resolve_user_lang(string $client_hint = ''): string {
        $hint = self::normalize_locale_tag($client_hint);
        if ($hint !== '') {
            return $hint;
        }

        return self::get_current_language();
    }

    /**
     * @param string $locale Código WP, slug Polylang, BCP-47, etc.
     */
    public static function to_short_iso(string $locale): string {
        $locale = trim(str_replace('_', '-', $locale));
        if ($locale === '') {
            return '';
        }
        $parts = explode('-', strtolower($locale));
        $primary = $parts[0] ?? '';
        $primary = preg_replace('/[^a-z]/', '', $primary) ?? '';
        if ($primary === '') {
            return '';
        }

        return substr($primary, 0, 10);
    }

    public static function get_default_language_code(): string {
        if (self::is_wpml_active()) {
            if (function_exists('wpml_get_default_language')) {
                return self::to_short_iso((string) wpml_get_default_language());
            }
            $def = apply_filters('wpml_default_language', '');
            if (is_string($def) && $def !== '') {
                return self::to_short_iso($def);
            }
        }
        if (self::is_polylang_active() && function_exists('pll_default_language')) {
            return self::to_short_iso((string) pll_default_language('slug'));
        }
        if (self::is_translatepress_active() && class_exists('TRP_Translate_Press', false)) {
            $trp = TRP_Translate_Press::get_trp_instance();
            if (is_object($trp) && method_exists($trp, 'get_component')) {
                $languages = $trp->get_component('languages');
                if (is_object($languages) && method_exists($languages, 'get_default_language')) {
                    return self::to_short_iso((string) $languages->get_default_language());
                }
            }
        }

        return self::to_short_iso(determine_locale());
    }

    /**
     * @return list<string> Códigos ISO cortos de idiomas activos.
     */
    public static function get_active_language_codes(): array {
        $codes = [];

        if (self::is_wpml_active()) {
            if (function_exists('wpml_get_active_languages')) {
                $langs = wpml_get_active_languages();
                if (is_array($langs)) {
                    $codes = array_values(array_map('strval', array_keys($langs)));
                }
            }
            if ($codes === []) {
                $langs = apply_filters('wpml_active_languages', null);
                if (is_array($langs)) {
                    $codes = array_values(array_map('strval', array_keys($langs)));
                }
            }
        } elseif (self::is_polylang_active() && function_exists('pll_languages_list')) {
            $list = pll_languages_list(['fields' => 'slug']);
            if (is_array($list)) {
                $codes = array_map('strval', $list);
            }
        } elseif (self::is_translatepress_active() && class_exists('TRP_Translate_Press', false)) {
            $trp = TRP_Translate_Press::get_trp_instance();
            if (is_object($trp) && method_exists($trp, 'get_component')) {
                $settings = $trp->get_component('settings');
                if (is_object($settings) && method_exists($settings, 'get_settings')) {
                    $cfg = $settings->get_settings();
                    if (is_array($cfg) && !empty($cfg['translation-languages']) && is_array($cfg['translation-languages'])) {
                        $codes = array_map('strval', $cfg['translation-languages']);
                    }
                }
            }
        }

        $out = [];
        foreach ($codes as $code) {
            $short = self::to_short_iso((string) $code);
            if ($short !== '') {
                $out[] = $short;
            }
        }

        return array_values(array_unique($out));
    }

    public static function load_plugin_textdomain(): void {
        $domain = self::TEXT_DOMAIN;
        $relative = dirname(plugin_basename(XABIA_PATH . 'xabia-intelligence.php')) . '/languages';
        $locale = apply_filters(
            'plugin_locale',
            is_admin() ? get_user_locale() : determine_locale(),
            $domain
        );

        unload_textdomain($domain);
        foreach (self::mo_file_candidates($locale) as $mofile) {
            if (is_readable($mofile)) {
                load_textdomain($domain, $mofile);
                break;
            }
        }

        load_plugin_textdomain($domain, false, $relative);
    }

    public static function agent_greeting_string_name(string $project_id): string {
        return 'Agent Greeting - ' . sanitize_key($project_id);
    }

    public static function agent_greeting_source_language(string $project_id = ''): string {
        $lang = self::get_default_language_code();
        if ($lang === '') {
            $lang = 'es';
        }

        return substr(
            sanitize_key((string) apply_filters('xabia_agent_greeting_source_lang', $lang, $project_id)),
            0,
            10
        );
    }

    /**
     * Registra el saludo del agente en el motor de cadenas del proveedor activo.
     */
    public static function register_agent_greeting(string $project_id, string $greeting): void {
        $project_id = sanitize_key($project_id);
        $greeting = trim($greeting);
        if ($project_id === '' || $greeting === '') {
            return;
        }

        $name = self::agent_greeting_string_name($project_id);

        if (self::is_wpml_active()) {
            self::register_agent_greeting_wpml($project_id, $greeting, $name);

            return;
        }

        if (self::is_polylang_active() && function_exists('pll_register_string')) {
            pll_register_string($name, $greeting, self::WPML_CONTEXT, false);

            return;
        }

        /**
         * Sin plugin de terceros: el saludo vive en la config del agente;
         * gettext (.mo) solo aplica a cadenas estáticas del dominio xabia-intelligence.
         */
        do_action('xabia_i18n_register_agent_greeting', $project_id, $greeting, $name);
    }

    public static function translate_agent_greeting(string $greeting, string $project_id): string {
        $project_id = sanitize_key($project_id);
        $greeting = trim($greeting);
        if ($project_id === '' || $greeting === '') {
            return $greeting;
        }

        self::register_agent_greeting($project_id, $greeting);
        $name = self::agent_greeting_string_name($project_id);

        if (self::is_wpml_active()) {
            return self::translate_agent_greeting_wpml($greeting, $name);
        }

        if (self::is_polylang_active()) {
            if (function_exists('pll_translate_string')) {
                $lang = self::get_current_language();
                $translated = pll_translate_string($greeting, $lang);
                if (is_string($translated) && $translated !== '') {
                    return $translated;
                }
            }
            if (function_exists('pll__')) {
                $translated = pll__($greeting);
                if (is_string($translated) && $translated !== '' && $translated !== $greeting) {
                    return $translated;
                }
            }
        }

        $filtered = apply_filters('xabia_i18n_translate_agent_greeting', $greeting, $project_id, $name);

        return is_string($filtered) ? $filtered : $greeting;
    }

    public static function register_all_stored_greetings(): void {
        if (!is_admin()) {
            return;
        }
        $projects = get_option('xabia_projects_config', []);
        if (!is_array($projects)) {
            return;
        }
        foreach ($projects as $project_id => $cfg) {
            if (!is_string($project_id) || !is_array($cfg)) {
                continue;
            }
            $greeting = trim((string) ($cfg['rules']['greeting'] ?? ''));
            if ($greeting !== '') {
                self::register_agent_greeting($project_id, $greeting);
            }
        }
    }

    /**
     * Tras guardar agente: registra saludo y, con WPML multilingüe + licencia Hub, pide traducciones DTP.
     */
    public static function maybe_sync_greeting_via_hub(string $project_id, string $greeting): void {
        $project_id = sanitize_key($project_id);
        $greeting = trim($greeting);
        if ($project_id === '') {
            return;
        }

        self::register_agent_greeting($project_id, $greeting);
        if ($greeting === '' || !self::is_wpml_active()) {
            return;
        }

        $active = self::get_active_language_codes();
        if (count($active) < 2) {
            return;
        }

        $source_lang = self::agent_greeting_source_language($project_id);
        $targets = [];
        foreach ($active as $code) {
            $code = self::to_short_iso((string) $code);
            if ($code === '' || $code === $source_lang) {
                continue;
            }
            $targets[] = $code;
        }
        $targets = array_values(array_unique($targets));
        if ($targets === []) {
            return;
        }

        if (!class_exists('Xabia_Digixop_Client', false) || !Xabia_Digixop_Client::is_license_configured()) {
            return;
        }

        try {
            $resp = Xabia_Digixop_Client::hub_translate_agent_greeting([
                'text'         => $greeting,
                'source_lang'  => $source_lang,
                'target_langs' => $targets,
                'project_id'   => $project_id,
            ], $project_id);

            if (empty($resp['ok']) || !is_array($resp['body'] ?? null)) {
                return;
            }

            $body = $resp['body'];
            if (empty($body['dtp']) || empty($body['translations']) || !is_array($body['translations'])) {
                return;
            }

            self::set_agent_greeting_translations($project_id, $greeting, $body['translations']);
        } catch (Throwable $e) {
            // Fallback silencioso: el saludo base ya quedó registrado.
        }
    }

    /**
     * @param array<string, string> $translations_by_lang
     */
    public static function set_agent_greeting_translations(string $project_id, string $source_greeting, array $translations_by_lang): void {
        if (!self::is_wpml_active()) {
            return;
        }

        $project_id = sanitize_key($project_id);
        $source_greeting = trim($source_greeting);
        if ($project_id === '' || $source_greeting === '' || $translations_by_lang === []) {
            return;
        }

        self::register_agent_greeting($project_id, $source_greeting);

        $name = self::agent_greeting_string_name($project_id);
        $source_lang = self::agent_greeting_source_language($project_id);
        $string_id = self::resolve_wpml_string_id($source_greeting, $name);
        if ($string_id < 1) {
            return;
        }

        self::ensure_wpml_string_source_language($name, $source_lang, $string_id);

        $status = defined('ICL_TM_COMPLETE') ? (int) ICL_TM_COMPLETE : 10;

        foreach ($translations_by_lang as $lang => $text) {
            $lang = self::to_short_iso((string) $lang);
            $text = trim((string) $text);
            if ($lang === '' || $text === '' || $lang === $source_lang) {
                continue;
            }
            if (function_exists('icl_add_string_translation')) {
                icl_add_string_translation($string_id, $lang, $text, $status);
            } elseif (has_action('wpml_add_string_translation')) {
                do_action('wpml_add_string_translation', $string_id, $lang, $text, $status);
            }
        }
    }

    private static function register_agent_greeting_wpml(string $project_id, string $greeting, string $name): void {
        $source_lang = self::agent_greeting_source_language($project_id);

        if (function_exists('wpml_register_single_string')) {
            wpml_register_single_string(self::WPML_CONTEXT, $name, $greeting, false, $source_lang);
            self::ensure_wpml_string_source_language($name, $source_lang);

            return;
        }
        if (has_action('wpml_register_single_string')) {
            do_action('wpml_register_single_string', self::WPML_CONTEXT, $name, $greeting, false, $source_lang);
            self::ensure_wpml_string_source_language($name, $source_lang);

            return;
        }
        if (function_exists('icl_register_string')) {
            icl_register_string(self::WPML_CONTEXT, $name, $greeting, false, $source_lang);
            self::ensure_wpml_string_source_language($name, $source_lang);
        }
    }

    private static function translate_agent_greeting_wpml(string $greeting, string $name): string {
        if (function_exists('wpml_translate_single_string')) {
            return (string) wpml_translate_single_string($greeting, self::WPML_CONTEXT, $name);
        }
        if (has_filter('wpml_translate_single_string')) {
            return (string) apply_filters('wpml_translate_single_string', $greeting, self::WPML_CONTEXT, $name);
        }
        if (function_exists('icl_t')) {
            return (string) icl_t(self::WPML_CONTEXT, $name, $greeting);
        }

        return $greeting;
    }

  /**
     * @return list<string>
     */
    private static function mo_file_candidates(string $locale): array {
        $domain = self::TEXT_DOMAIN;
        $dir = XABIA_PATH . 'languages/';
        $candidates = [
            $dir . $domain . '-' . $locale . '.mo',
            $dir . 'xabia-' . $locale . '.mo',
        ];

        $short = self::get_current_language();
        $map = [
            'es' => 'es_ES',
            'en' => 'en_US',
            'eu' => 'eu_ES',
        ];
        if ($short !== '' && isset($map[$short])) {
            $mapped = $map[$short];
            $candidates[] = $dir . 'xabia-' . $mapped . '.mo';
            $candidates[] = $dir . $domain . '-' . $mapped . '.mo';
        }

        return array_values(array_unique($candidates));
    }

    private static function resolve_wpml_string_id(string $value, string $name): int {
        if (function_exists('icl_get_string_id')) {
            $id = icl_get_string_id($value, self::WPML_CONTEXT, $name);
            if (is_numeric($id) && (int) $id > 0) {
                return (int) $id;
            }
        }

        global $wpdb;
        if (isset($wpdb) && is_object($wpdb) && isset($wpdb->prefix)) {
            $table = $wpdb->prefix . 'icl_strings';
            $id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE context = %s AND name = %s ORDER BY id DESC LIMIT 1",
                    self::WPML_CONTEXT,
                    $name
                )
            );
            if (is_numeric($id) && (int) $id > 0) {
                return (int) $id;
            }
        }

        return 0;
    }

    public static function resolve_gettext_string_id(string $text): int {
        $domain = self::TEXT_DOMAIN;
        if (function_exists('icl_get_string_id')) {
            $id = icl_get_string_id($text, $domain, $text);
            if (is_numeric($id) && (int) $id > 0) {
                return (int) $id;
            }
        }

        global $wpdb;
        if (isset($wpdb) && is_object($wpdb) && isset($wpdb->prefix)) {
            $table = $wpdb->prefix . 'icl_strings';
            $id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE context = %s AND name = %s ORDER BY id DESC LIMIT 1",
                    $domain,
                    $text
                )
            );
            if (is_numeric($id) && (int) $id > 0) {
                return (int) $id;
            }
        }

        return 0;
    }

    public static function ensure_gettext_string_source_language(string $text, string $source_lang, int $string_id = 0): void {
        $source_lang = substr(sanitize_key($source_lang), 0, 10);
        if ($source_lang === '') {
            return;
        }

        if ($string_id < 1) {
            $string_id = self::resolve_gettext_string_id($text);
        }
        if ($string_id < 1) {
            return;
        }

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->prefix)) {
            return;
        }

        $table = $wpdb->prefix . 'icl_strings';
        $wpdb->update(
            $table,
            ['language' => $source_lang],
            [
                'id'      => $string_id,
                'context' => self::TEXT_DOMAIN,
                'name'    => $text,
            ],
            ['%s'],
            ['%d', '%s', '%s']
        );
    }

    private static function ensure_wpml_string_source_language(string $name, string $source_lang, int $string_id = 0): void {
        $source_lang = substr(sanitize_key($source_lang), 0, 10);
        if ($source_lang === '') {
            return;
        }

        if ($string_id < 1) {
            $string_id = self::resolve_wpml_string_id('', $name);
        }
        if ($string_id < 1) {
            return;
        }

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->prefix)) {
            return;
        }

        $table = $wpdb->prefix . 'icl_strings';
        $wpdb->update(
            $table,
            ['language' => $source_lang],
            [
                'id'      => $string_id,
                'context' => self::WPML_CONTEXT,
                'name'    => $name,
            ],
            ['%s'],
            ['%d', '%s', '%s']
        );
    }
}

endif;
