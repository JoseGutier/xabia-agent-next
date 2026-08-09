<?php
/**
 * Internacionalización nativa: gettext + puente agnóstico (Xabia_I18n_Bridge).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Xabia_I18n')) :

final class Xabia_I18n {
    public const WPML_CONTEXT = 'Xabia AI';

    public static function init(): void {
        add_action('init', [Xabia_I18n_Bridge::class, 'load_plugin_textdomain'], 0);
        add_action('init', [self::class, 'maybe_sync_ui_strings_to_wpml'], 5);
        add_action('admin_init', [Xabia_I18n_Bridge::class, 'register_all_stored_greetings'], 20);
        if (Xabia_I18n_Bridge::is_wpml_active()) {
            add_action('wpml_switch_language', [Xabia_I18n_Bridge::class, 'load_plugin_textdomain'], 0);
        }
        if (Xabia_I18n_Bridge::is_polylang_active()) {
            add_action('pll_language_defined', [Xabia_I18n_Bridge::class, 'load_plugin_textdomain'], 0);
        }
        if (Xabia_I18n_Bridge::is_translatepress_active()) {
            add_action('trp_language_changed', [Xabia_I18n_Bridge::class, 'load_plugin_textdomain'], 0);
        }
    }

    /** @deprecated Use Xabia_I18n_Bridge::load_plugin_textdomain() */
    public static function load_textdomain(): void {
        Xabia_I18n_Bridge::load_plugin_textdomain();
    }

    /**
     * Texto de UI del chat (gettext + WPML String Translation en front).
     */
    public static function t(string $text): string {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $domain = Xabia_I18n_Bridge::TEXT_DOMAIN;
        if (!is_textdomain_loaded($domain)) {
            Xabia_I18n_Bridge::load_plugin_textdomain();
        }

        if (self::wpml_available()) {
            $source_lang = self::agent_greeting_source_language();
            if (function_exists('wpml_register_single_string')) {
                wpml_register_single_string($domain, $text, $text, false, $source_lang);
            } elseif (has_action('wpml_register_single_string')) {
                do_action('wpml_register_single_string', $domain, $text, $text, false, $source_lang);
            }
            $translated = null;
            if (function_exists('wpml_translate_single_string')) {
                $translated = wpml_translate_single_string($text, $domain, $text);
            } elseif (has_filter('wpml_translate_single_string')) {
                $translated = apply_filters('wpml_translate_single_string', $text, $domain, $text);
            }
            if (is_string($translated) && $translated !== '' && $translated !== $text) {
                return $translated;
            }
        }

        $translated = __($text, $domain);
        if (is_string($translated) && $translated !== '' && $translated !== $text) {
            return $translated;
        }

        return $text;
    }

    /**
     * Matriz ES → traducciones de respaldo (EU / EN) para WPML String Translation.
     *
     * Las cadenas en español deben coincidir con los valores por defecto de chatbox_js_string_keys()
     * y con los textos envueltos en Xabia_I18n::t() / __() en plantillas PHP del frontend.
     *
     * @return array<string, array{eu: string, en: string}>
     */
    private static function ui_translation_matrix(): array {
        return [
            // — Chatbox PHP (plantilla)
            'Minimizar chat'                  => ['eu' => 'Txata minimizatu', 'en' => 'Minimize chat'],
            'Hablar'                          => ['eu' => 'Hitz egin', 'en' => 'Speak'],
            'Pulsar para hablar'              => ['eu' => 'Sakatu hitz egiteko', 'en' => 'Tap to speak'],
            'Escribe aquí...'                 => ['eu' => 'Idatzi hemen...', 'en' => 'Write here...'],
            'Activar voz (lectura en alto)'   => ['eu' => 'Ahotsa aktibatu (olo bizi)', 'en' => 'Enable voice (read aloud)'],
            'Activar voz'                     => ['eu' => 'Ahotsa aktibatu', 'en' => 'Enable voice'],
            'Enviar'                          => ['eu' => 'Bidali', 'en' => 'Send'],
            'Enviar mensaje'                  => ['eu' => 'Mezua bidali', 'en' => 'Send message'],
            'Hola, soy Xabia.'                => ['eu' => 'Kaixo, Xabia naiz.', 'en' => 'Hello, I\'m Xabia.'],
            'Powered by Xabia AI'             => ['eu' => 'Powered by Xabia AI', 'en' => 'Powered by Xabia AI'],

            // — Acciones (chatbox.js)
            'Llamar'                          => ['eu' => 'Deitu', 'en' => 'Call'],
            'Abrir enlace'                    => ['eu' => 'Esteka ireki', 'en' => 'Open link'],
            'Ver en mapa'                     => ['eu' => 'Mapan ikusi', 'en' => 'View on map'],
            'Añadir al carrito'               => ['eu' => 'Saskira gehitu', 'en' => 'Add to cart'],
            'Comprar ahora'                   => ['eu' => 'Orain erosi', 'en' => 'Buy now'],
            'Comprar pack'                    => ['eu' => 'Packa erosi', 'en' => 'Buy pack'],
            'Reservar'                        => ['eu' => 'Erreserbatu', 'en' => 'Book'],
            'Continuar'                       => ['eu' => 'Jarraitu', 'en' => 'Continue'],

            // — Panel / interfaz nativa
            'Cerrar chat'                     => ['eu' => 'Txata itxi', 'en' => 'Close chat'],
            'Abrir chat'                      => ['eu' => 'Txata ireki', 'en' => 'Open chat'],
            'Abrir asistente Xabia'           => ['eu' => 'Xabia laguntzailea ireki', 'en' => 'Open Xabia assistant'],
            '¿Te puedo ayudar?'               => ['eu' => 'Lagundu diezazuket?', 'en' => 'Can I help you?'],
            '¿Hablamos?'                      => ['eu' => 'Hitz egiten dugu?', 'en' => 'Shall we talk?'],

            // — Voz y micrófono (chatbox.js)
            'Desactivar voz'                  => ['eu' => 'Ahotsa desaktibatu', 'en' => 'Disable voice'],
            'El micrófono requiere HTTPS.'    => ['eu' => 'Mikrofonoak HTTPS behar du.', 'en' => 'Microphone requires HTTPS.'],
            'Tu navegador no soporta voz. Prueba Chrome o Edge.' => [
                'eu' => 'Zure nabigatzaileak ez du ahotsa onartzen. Saiatu Chrome edo Edge-rekin.',
                'en' => 'Your browser does not support voice. Try Chrome or Edge.',
            ],

            // — Flujo de chat (chatbox.js)
            'Continúa exactamente desde donde lo dejaste, sin repetir lo anterior.' => [
                'eu' => 'Utzi zenuen lekutik jarraitu, aurrekoa errepikatu gabe.',
                'en' => 'Continue exactly where you left off, without repeating what came before.',
            ],
            'Tú'                              => ['eu' => 'Zu', 'en' => 'You'],
            'Clics de compra en esta sesión:'  => ['eu' => 'Erosketa-klikak saio honetan:', 'en' => 'Purchase clicks this session:'],

            // — Errores y estados (chatbox.js)
            'Error'                           => ['eu' => 'Errorea', 'en' => 'Error'],
            'Error servidor.'                 => ['eu' => 'Zerbitzari-errorea.', 'en' => 'Server error.'],
            'Respuesta inválida del servidor. Actualiza Xabia Core o revisa el log PHP del hosting.' => [
                'eu' => 'Zerbitzariaren erantzun baliogabea. Eguneratu Xabia Core edo berrikusi hostingeko PHP loga.',
                'en' => 'Invalid response from the server. Update Xabia Core or check your hosting PHP log.',
            ],
            'El servidor tardó demasiado. Inténtalo de nuevo en unos segundos.' => [
                'eu' => 'Zerbitzariak denbora gehiegi behar izan du. Saiatu berriro segundo batzuk barru.',
                'en' => 'The server took too long. Try again in a few seconds.',
            ],
            'Carrito no disponible.'          => ['eu' => 'Saskia ez dago erabilgarri.', 'en' => 'Cart unavailable.'],
            'Error de red.'                   => ['eu' => 'Sare-errorea.', 'en' => 'Network error.'],
            '¡Producto añadido!'              => ['eu' => 'Produktua gehituta!', 'en' => 'Product added!'],
            'La sesión se cerrará pronto por inactividad.' => [
                'eu' => 'Saioa laster itxiko da inaktibitateagatik.',
                'en' => 'The session will close soon due to inactivity.',
            ],
        ];
    }

    /**
     * Claves JS → texto fuente en español (chatbox.js vía wp_localize_script).
     *
     * @return array<string, string>
     */
    private static function chatbox_js_string_keys(): array {
        return [
            'actionCall'           => 'Llamar',
            'actionOpenLink'       => 'Abrir enlace',
            'actionViewMap'        => 'Ver en mapa',
            'actionAddToCart'      => 'Añadir al carrito',
            'actionBuyNow'         => 'Comprar ahora',
            'actionBuyPack'        => 'Comprar pack',
            'actionBook'           => 'Reservar',
            'continue'             => 'Continuar',
            'closeChat'            => 'Cerrar chat',
            'voiceEnable'          => 'Activar voz (lectura en alto)',
            'voiceEnableShort'     => 'Activar voz',
            'voiceDisable'         => 'Desactivar voz',
            'micHttps'             => 'El micrófono requiere HTTPS.',
            'micUnsupported'       => 'Tu navegador no soporta voz. Prueba Chrome o Edge.',
            'continuePrompt'       => 'Continúa exactamente desde donde lo dejaste, sin repetir lo anterior.',
            'errorGeneric'         => 'Error',
            'errorServer'          => 'Error servidor.',
            'errorInvalidResponse' => 'Respuesta inválida del servidor. Actualiza Xabia Core o revisa el log PHP del hosting.',
            'errorTimeout'         => 'El servidor tardó demasiado. Inténtalo de nuevo en unos segundos.',
            'cartUnavailable'      => 'Carrito no disponible.',
            'networkError'         => 'Error de red.',
            'productAdded'         => '¡Producto añadido!',
            'totemWarning'         => 'La sesión se cerrará pronto por inactividad.',
            'userYou'              => 'Tú',
            'sessionCartClicks'    => 'Clics de compra en esta sesión:',
            'poweredBy'            => 'Powered by Xabia AI',
        ];
    }

    /**
     * Cadenas del widget chat (chatbox.js), traducidas vía gettext / WPML / Polylang.
     *
     * @return array<string, string>
     */
    public static function chatbox_js_strings(): array {
        $out = [];
        foreach (self::chatbox_js_string_keys() as $key => $text) {
            $out[$key] = self::t($text);
        }

        return apply_filters('xabia_chatbox_i18n_strings', $out);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function ui_string_catalog(): array {
        return self::ui_translation_matrix();
    }

    public static function maybe_sync_ui_strings_to_wpml(): void {
        if (!self::wpml_available() || count(self::wpml_active_language_codes()) < 2) {
            return;
        }

        $flag = '1.0.73';
        if (get_option('xabia_i18n_ui_wpml_sync', '') === $flag) {
            return;
        }

        $domain = Xabia_I18n_Bridge::TEXT_DOMAIN;
        $source_lang = self::agent_greeting_source_language();
        $status = defined('ICL_TM_COMPLETE') ? (int) ICL_TM_COMPLETE : 10;

        foreach (self::ui_string_catalog() as $text => $by_lang) {
            if (function_exists('wpml_register_single_string')) {
                wpml_register_single_string($domain, $text, $text, false, $source_lang);
            } elseif (has_action('wpml_register_single_string')) {
                do_action('wpml_register_single_string', $domain, $text, $text, false, $source_lang);
            }

            $string_id = Xabia_I18n_Bridge::resolve_gettext_string_id($text);
            if ($string_id < 1) {
                continue;
            }

            Xabia_I18n_Bridge::ensure_gettext_string_source_language($text, $source_lang, $string_id);

            foreach ($by_lang as $lang => $translation) {
                $lang = substr(sanitize_key((string) $lang), 0, 10);
                $translation = trim((string) $translation);
                if ($lang === '' || $translation === '' || $lang === $source_lang) {
                    continue;
                }
                if (function_exists('icl_add_string_translation')) {
                    icl_add_string_translation($string_id, $lang, $translation, $status);
                } elseif (has_action('wpml_add_string_translation')) {
                    do_action('wpml_add_string_translation', $string_id, $lang, $translation, $status);
                }
            }
        }

        update_option('xabia_i18n_ui_wpml_sync', $flag, false);
    }

    public static function agent_greeting_string_name(string $project_id): string {
        return Xabia_I18n_Bridge::agent_greeting_string_name($project_id);
    }

    public static function wpml_available(): bool {
        return Xabia_I18n_Bridge::is_wpml_active();
    }

    public static function agent_greeting_source_language(string $project_id = ''): string {
        return Xabia_I18n_Bridge::agent_greeting_source_language($project_id);
    }

    public static function register_agent_greeting(string $project_id, string $greeting): void {
        Xabia_I18n_Bridge::register_agent_greeting($project_id, $greeting);
    }

    public static function translate_agent_greeting(string $greeting, string $project_id): string {
        return Xabia_I18n_Bridge::translate_agent_greeting($greeting, $project_id);
    }

    public static function register_all_stored_greetings(): void {
        Xabia_I18n_Bridge::register_all_stored_greetings();
    }

    /** @return list<string> */
    public static function wpml_active_language_codes(): array {
        return Xabia_I18n_Bridge::get_active_language_codes();
    }

    public static function wpml_default_language_code(): string {
        return Xabia_I18n_Bridge::get_default_language_code();
    }

    /**
     * @param array<string, string> $translations_by_lang
     */
    public static function set_agent_greeting_translations(string $project_id, string $source_greeting, array $translations_by_lang): void {
        Xabia_I18n_Bridge::set_agent_greeting_translations($project_id, $source_greeting, $translations_by_lang);
    }

    public static function maybe_sync_greeting_via_hub(string $project_id, string $greeting): void {
        Xabia_I18n_Bridge::maybe_sync_greeting_via_hub($project_id, $greeting);
    }
}

endif;
