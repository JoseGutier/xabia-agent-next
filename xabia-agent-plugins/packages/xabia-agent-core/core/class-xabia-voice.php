<?php
/**
 * Voz TTS: locales BCP-47, resolución de idioma de página y directiva de formato hablado.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Xabia_Voice')) :

class Xabia_Voice {

    /**
     * Estado de motores TTS en servidor (sin exponer claves).
     *
     * @param array<string, mixed> $config
     * @return array{
     *   google_cloud_tts: bool,
     *   openai_tts: bool,
     *   hub_tts: bool,
     *   browser_fallback_only: bool,
     *   gcloud_path_set: bool,
     *   gcloud_path_readable: bool,
     *   recommended_eu: string
     * }
     */
    public static function tts_engine_status(string $project_id, array $config): array {
        $gcloud_path = trim((string) ($config['gcloud_json_path'] ?? ''));
        if ($gcloud_path === '') {
            $gcloud_path = trim((string) get_option('xabia_gcloud_json_path', ''));
        }
        $google_ready = $gcloud_path !== '' && is_readable($gcloud_path);
        $openai_ready = false;
        $hub_ready = class_exists('Xabia_Hub_Client', false) && Xabia_Hub_Client::is_available();
        if (class_exists('Xabia_Digixop_Client', false)) {
            $openai_ready = Xabia_Digixop_Client::get_effective_openai_key($project_id, $config) !== '';
        }

        return [
            'google_cloud_tts'       => $google_ready,
            'openai_tts'             => $openai_ready,
            'hub_tts'                => $hub_ready,
            'browser_fallback_only'    => !$google_ready && !$openai_ready && !$hub_ready,
            'gcloud_path_set'        => $gcloud_path !== '',
            'gcloud_path_readable'   => $google_ready,
            'recommended_eu'         => $google_ready ? 'google' : ($hub_ready ? 'hub' : ($openai_ready ? 'openai' : 'browser')),
        ];
    }

    /**
     * Códigos ISO 639-1 → locale BCP-47 para TTS (Google, Web Speech, etc.).
     *
     * @return array<string, string>
     */
    public static function locale_map(): array {
        $map = [
            'es' => 'es-ES',
            'eu' => 'eu-ES',
            'en' => 'en-US',
            'fr' => 'fr-FR',
            'de' => 'de-DE',
            'it' => 'it-IT',
            'pt' => 'pt-PT',
            'ca' => 'ca-ES',
            'gl' => 'gl-ES',
        ];

        $filtered = apply_filters('xabia_voice_locale_map', $map);

        return is_array($filtered) ? $filtered : $map;
    }

    /**
     * Normaliza etiqueta de idioma (es, eu-ES, fr_FR…) a código ISO corto.
     */
    public static function normalize_lang_code(string $tag): string {
        $tag = trim(str_replace('_', '-', $tag));
        if ($tag === '') {
            return '';
        }
        if (class_exists('Xabia_I18n_Bridge', false)) {
            return Xabia_I18n_Bridge::to_short_iso($tag);
        }
        $parts = explode('-', strtolower($tag));
        $primary = preg_replace('/[^a-z]/', '', $parts[0] ?? '') ?? '';

        return substr($primary, 0, 10);
    }

    /**
     * Locale BCP-47 para un código ISO corto o etiqueta completa.
     */
    public static function lang_code_to_locale(string $lang_code): string {
        $short = self::normalize_lang_code($lang_code);
        if ($short === '') {
            $short = 'es';
        }
        $map = self::locale_map();
        if (isset($map[$short])) {
            return (string) $map[$short];
        }

        return $short . '-' . strtoupper($short);
    }

    /**
     * Idioma activo de la página: shortcode > Polylang/WPML/locale.
     */
    public static function resolve_page_lang_code(string $shortcode_lang = ''): string {
        $shortcode_lang = trim($shortcode_lang);
        if ($shortcode_lang !== '') {
            $from_attr = self::normalize_lang_code($shortcode_lang);
            if ($from_attr !== '') {
                return $from_attr;
            }
        }

        if (class_exists('Xabia_I18n_Bridge', false)) {
            $code = Xabia_I18n_Bridge::get_current_language();
            if ($code !== '') {
                return $code;
            }
        }

        $locale = function_exists('determine_locale') ? determine_locale() : (function_exists('get_locale') ? get_locale() : 'es_ES');
        $fallback = self::normalize_lang_code((string) $locale);

        return $fallback !== '' ? $fallback : 'es';
    }

    /**
     * Directiva universal para que el LLM redacte fechas/horas/cifras en forma hablable (TTS).
     */
    public static function spoken_format_directive(): string {
        $block = "# FORMATO HABLADO PARA SÍNTESIS DE VOZ (TTS — OBLIGATORIO):\n"
            . "Redacta siempre las fechas, horas y cantidades monetarias en la forma hablada natural correspondiente al idioma en el que estás respondiendo. "
            . "Evita formatos ISO (2026-10-17), abreviaturas técnicas y símbolos que no se lean bien en voz alta.\n"
            . "Ejemplos de adaptación al idioma de respuesta:\n"
            . "- Francés: «le 17 octobre 2026», «à 19h30», «15 euros».\n"
            . "- Inglés: «October 17th, 2026», «at 7:30 PM», «15 euros».\n"
            . "- Euskera: «2026ko urriaren 17an», «19:30ean», «15 euro».\n"
            . "- Español: «17 de octubre de 2026», «a las 19:30», «15 euros».\n"
            . "Aplica el mismo criterio a cualquier otro idioma de respuesta: meses, ordinales, horas y moneda en forma nativa y fluida para TTS.\n"
            . "En fichas de eventos, listas y datos estructurados, escribe cada campo en una línea nueva (p. ej. «Data: …» en una línea, «Lekua: …» en la siguiente). "
            . "Así la voz hará una pausa natural entre campos.";

        return (string) apply_filters('xabia_voice_spoken_format_directive', $block);
    }

    /**
     * Inserta saltos de línea antes de etiquetas de ficha/lista aunque el LLM escriba todo en una sola línea.
     */
    public static function insert_structural_tts_breaks(string $text): string {
        if ($text === '') {
            return $text;
        }

        $labels = 'Data|Lekua|Noiz|Non|Deskribapena|Antolatzailea|Informazioa(?: eta erreserbak)?|Helbidea|Ordua|Sarrera|Prezioa|'
            . 'Fecha|Lugar|Hora|Descripción|Descripcion|Organizador|Información|Informacion|Ubicación|Ubicacion|'
            . 'Date|Time|Location|Place|Description|Organizer|Price|Address';

        // Campos de ficha aunque vengan en una sola línea.
        $text = preg_replace('/\s+(?=(?:' . $labels . ')\s*:)/iu', "\n", $text) ?? $text;
        // Tras punto/final de frase, si sigue una etiqueta de campo.
        $text = preg_replace('/([.!?…])\s+(?=(?:' . $labels . ')\s*:)/u', "$1\n", $text) ?? $text;
        // Título de evento seguido de campo (p. ej. «…proiekzioa\nNoiz:»).
        $text = preg_replace('/(:)\s+(?=(?:' . $labels . ')\s*:)/u', "$1\n", $text) ?? $text;
        $text = preg_replace('/\s+(?=[-*•]\s)/u', "\n", $text) ?? $text;
        $text = preg_replace('/\s+(?=\d+\.\s)/u', "\n", $text) ?? $text;
        $text = preg_replace('/\s+(?=(?:Ba al duzu|¿|Zer |Nola |Non |Zein ))/iu', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Voz Google Cloud Neural2 (si existe) según idioma y preferencia de género.
     */
    public static function google_neural_voice_name(string $lang_code, string $voice_pref = 'default'): string {
        $lang = self::normalize_lang_code($lang_code);
        $male = ($voice_pref === 'male');
        $map = [
            'es' => $male ? 'es-ES-Neural2-B' : 'es-ES-Neural2-A',
            'en' => $male ? 'en-US-Neural2-D' : 'en-US-Neural2-C',
            'fr' => $male ? 'fr-FR-Neural2-B' : 'fr-FR-Neural2-A',
            'de' => $male ? 'de-DE-Neural2-B' : 'de-DE-Neural2-A',
            'it' => $male ? 'it-IT-Neural2-C' : 'it-IT-Neural2-A',
            'pt' => $male ? 'pt-BR-Neural2-B' : 'pt-BR-Neural2-A',
            'eu' => $male ? 'eu-ES-Wavenet-B' : 'eu-ES-Wavenet-A',
        ];

        return (string) ($map[$lang] ?? '');
    }

    /**
     * Locale ICU (eu_ES, es_ES…) para NumberFormatter / IntlDateFormatter.
     */
    public static function intl_locale(string $lang_code): string {
        $short = self::normalize_lang_code($lang_code);
        if ($short === '') {
            $short = 'es';
        }
        $map = [
            'es' => 'es_ES',
            'eu' => 'eu_ES',
            'en' => 'en_US',
            'fr' => 'fr_FR',
            'de' => 'de_DE',
            'it' => 'it_IT',
            'pt' => 'pt_PT',
            'ca' => 'ca_ES',
            'gl' => 'gl_ES',
        ];
        if (isset($map[$short])) {
            return $map[$short];
        }

        return $short . '_' . strtoupper($short);
    }

    /**
     * Idioma efectivo para TTS: POST lang + user_lang (página / html[lang]).
     */
    public static function resolve_tts_lang_code(string $post_lang = '', string $user_lang = ''): string {
        $user = self::normalize_lang_code($user_lang);
        if ($user !== '') {
            return $user;
        }
        $post = self::normalize_lang_code($post_lang);
        if ($post !== '') {
            return $post;
        }

        return self::resolve_page_lang_code('');
    }

    /**
     * ICU no incluye spellout fiable para euskera (suele caer en ES/EN).
     */
    public static function intl_spellout_is_unreliable(string $lang_code): bool {
        $short = self::normalize_lang_code($lang_code);

        return in_array($short, ['eu', 'ca', 'gl'], true);
    }

    /**
     * Preprocesa texto para TTS: fechas, horas y cifras en forma hablable según idioma (Intl).
     */
    public static function localize_text_for_tts(string $text, string $lang_code): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = trim(preg_replace('/[^\S\n]+/u', ' ', $text) ?? '');
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        if ($text === '') {
            return '';
        }

        $locale = self::intl_locale($lang_code);
        $short = self::normalize_lang_code($lang_code) ?: 'es';
        $unreliable_spellout = self::intl_spellout_is_unreliable($short);

        $placeholders = [];
        $text = preg_replace_callback('#https?://[^\s\]\[]+#i', static function (array $m) use (&$placeholders): string {
            $key = '<<XURL' . count($placeholders) . '>>';
            $placeholders[$key] = $m[0];

            return $key;
        }, $text) ?? $text;

        if ($short === 'eu') {
            $text = preg_replace('/\ba las (\d{1,2}):(\d{2})\b/iu', '$1:$2ean', $text) ?? $text;
            $text = preg_replace('/\ba las (\d{1,2})\b/iu', '$1etan', $text) ?? $text;
            $text = self::replace_spanish_number_words($text, $short);
            $text = preg_replace_callback(
                '/\b(\d{2,3}(?:\s+\d{2,3}){2,})\b/u',
                static function (array $m): string {
                    $parts = preg_split('/\s+/u', trim($m[1])) ?: [];

                    return implode(' … ', array_map(
                        static fn(string $p): string => self::spell_number_eu((int) $p),
                        array_filter($parts, static fn(string $p): bool => $p !== '')
                    ));
                },
                $text
            ) ?? $text;
        }

        $text = preg_replace_callback(
            '/\b(\d{4})-(\d{2})-(\d{2})\b/',
            static function (array $m) use ($locale): string {
                return self::format_date_spoken((int) $m[1], (int) $m[2], (int) $m[3], $locale);
            },
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/\b(\d{1,2})[\/\.](\d{1,2})[\/\.](\d{4})\b/',
            static function (array $m) use ($locale, $short): string {
                $a = (int) $m[1];
                $b = (int) $m[2];
                $y = (int) $m[3];
                if ($short === 'en') {
                    return self::format_date_spoken($y, $b, $a, $locale);
                }

                return self::format_date_spoken($y, $a, $b, $locale);
            },
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/\b(\d{1,2}):(\d{2})\b/',
            static function (array $m) use ($locale, $short): string {
                return self::format_time_spoken((int) $m[1], (int) $m[2], $locale, $short);
            },
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '/(\d+(?:[.,]\d{1,2})?)\s*€|€\s*(\d+(?:[.,]\d{1,2})?)/u',
            static function (array $m) use ($locale, $short): string {
                $raw = ($m[1] !== '' ? $m[1] : $m[2]);
                $raw = str_replace(',', '.', $raw);
                $parts = explode('.', $raw, 2);
                $whole = self::spell_number((int) $parts[0], $locale, $short);
                $suffix = self::currency_word($short);
                if (isset($parts[1]) && $parts[1] !== '') {
                    $cents = self::spell_number((int) substr(str_pad($parts[1], 2, '0'), 0, 2), $locale, $short);

                    return trim($whole . ' ' . $suffix . ' ' . $cents);
                }

                return trim($whole . ' ' . $suffix);
            },
            $text
        ) ?? $text;

        if (!$unreliable_spellout && class_exists('NumberFormatter', false)) {
            $text = preg_replace_callback(
                '/\b(\d{1,5})\b/',
                static function (array $m) use ($locale, $short): string {
                    return self::spell_number((int) $m[1], $locale, $short);
                },
                $text
            ) ?? $text;
        } elseif ($short === 'eu') {
            $text = preg_replace_callback(
                '/\b(\d{1,5})\b/',
                static function (array $m): string {
                    return self::spell_number_eu((int) $m[1]);
                },
                $text
            ) ?? $text;
        }

        foreach ($placeholders as $key => $_url) {
            $text = str_replace($key, '', $text);
        }

        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Pausas al hablar: saltos de línea → respiración (SSML Google o puntos en motores planos).
     */
    public static function inject_speech_pauses(string $text, string $mode = 'plain'): string {
        if ($text === '') {
            return $text;
        }
        if ($mode === 'google') {
            return $text;
        }
        $parts = preg_split('/\n+| … /u', $text) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn($p) => $p !== ''));
        if ($parts === []) {
            return $text;
        }

        return implode(' … ', $parts);
    }

    /**
     * SSML Google Cloud con pausas en saltos de línea e idioma explícito.
     */
    public static function wrap_google_ssml(string $text, string $lang_code): string {
        $locale = self::lang_code_to_locale($lang_code);
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $escaped = preg_replace('/\n+| … /u', '<break time="750ms"/>', $escaped) ?? $escaped;

        return '<speak xml:lang="' . esc_attr($locale) . '">' . $escaped . '</speak>';
    }

    /**
     * Sustituye palabras numéricas castellanas frecuentes cuando el TTS es en euskera.
     */
    private static function replace_spanish_number_words(string $text, string $lang): string {
        if ($lang !== 'eu') {
            return $text;
        }
        $map = [
            'cero' => 'zero', 'uno' => 'bat', 'una' => 'bat', 'dos' => 'bi', 'tres' => 'hiru', 'cuatro' => 'lau',
            'cinco' => 'bost', 'seis' => 'sei', 'siete' => 'zazpi', 'ocho' => 'zortzi', 'nueve' => 'bederatzi',
            'diez' => 'hamar', 'once' => 'hamaika', 'doce' => 'hamabi', 'trece' => 'hamahiru', 'catorce' => 'hamalau',
            'quince' => 'hamabost', 'dieciséis' => 'hamasei', 'dieciseis' => 'hamasei', 'diecisiete' => 'hamazortzi',
            'dieciocho' => 'hemezortzi', 'diecinueve' => 'hemeretzi', 'veinte' => 'hogei', 'treinta' => 'hogeita hamar',
            'cuarenta' => 'berrogei', 'cincuenta' => 'berrogeita hamar', 'sesenta' => 'hirurogei',
            'setenta' => 'hirurogeita hamar', 'ochenta' => 'laurogei', 'noventa' => 'laurogeita hamar',
            'cien' => 'ehun', 'ciento' => 'ehun', 'mil' => 'mila',
        ];
        foreach ($map as $es => $eu) {
            $text = preg_replace('/\b' . preg_quote($es, '/') . '\b/iu', $eu, $text) ?? $text;
        }

        return $text;
    }

    private static function spell_number(int $n, string $locale, string $lang = ''): string {
        $lang = $lang !== '' ? self::normalize_lang_code($lang) : self::normalize_lang_code(strtok($locale, '_') ?: $locale);
        if ($lang === 'eu') {
            return self::spell_number_eu($n);
        }
        if (!class_exists('NumberFormatter', false)) {
            return (string) $n;
        }
        $fmt = new NumberFormatter($locale, NumberFormatter::SPELLOUT);
        $out = $fmt->format($n);
        if (!is_string($out) || $out === '') {
            return (string) $n;
        }
        if ($lang === 'eu' && preg_match('/\b(two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety|hundred|thousand)\b/i', $out)) {
            return self::spell_number_eu($n);
        }
        if ($lang === 'eu' && preg_match('/\b(dieci|veinti|treinta|cuarenta|cincuenta|sesenta|setenta|ochenta|noventa|cien|mil)\b/iu', $out)) {
            return self::spell_number_eu($n);
        }

        return $out;
    }

    /**
     * Cardinales 0–999999 en euskera (ICU no ofrece spellout eu fiable).
     */
    public static function spell_number_eu(int $n): string {
        if ($n < 0) {
            return (string) $n;
        }
        if ($n === 0) {
            return 'zero';
        }
        if ($n < 20) {
            $map = [
                1 => 'bat', 2 => 'bi', 3 => 'hiru', 4 => 'lau', 5 => 'bost', 6 => 'sei', 7 => 'zazpi', 8 => 'zortzi', 9 => 'bederatzi',
                10 => 'hamar', 11 => 'hamaika', 12 => 'hamabi', 13 => 'hamahiru', 14 => 'hamalau', 15 => 'hamabost', 16 => 'hamasei',
                17 => 'hamazortzi', 18 => 'hemezortzi', 19 => 'hemeretzi',
            ];

            return $map[$n] ?? (string) $n;
        }
        if ($n < 100) {
            if ($n === 20) {
                return 'hogei';
            }
            if ($n === 30) {
                return 'hogeita hamar';
            }
            if ($n === 40) {
                return 'berrogei';
            }
            if ($n === 50) {
                return 'berrogeita hamar';
            }
            if ($n === 60) {
                return 'hirurogei';
            }
            if ($n === 70) {
                return 'hirurogeita hamar';
            }
            if ($n === 80) {
                return 'laurogei';
            }
            if ($n === 90) {
                return 'laurogeita hamar';
            }
            $t = intdiv($n, 10);
            $u = $n % 10;
            $prefix = [
                2 => 'hogeita', 3 => 'hogeita hamar', 4 => 'berrogeita', 5 => 'berrogeita hamar',
                6 => 'hirurogeita', 7 => 'hirurogeita hamar', 8 => 'laurogeita', 9 => 'laurogeita hamar',
            ][$t] ?? '';
            if ($prefix === '') {
                return (string) $n;
            }
            if ($t === 3) {
                return 'hogeita ' . self::spell_number_eu(10 + $u);
            }
            if ($t === 5) {
                return 'berrogeita ' . self::spell_number_eu(10 + $u);
            }
            if ($t === 7) {
                return 'hirurogeita ' . self::spell_number_eu(10 + $u);
            }
            if ($t === 9) {
                return 'laurogeita ' . self::spell_number_eu(10 + $u);
            }
            $unit = $u === 1 ? 'bat' : self::spell_number_eu($u);

            return trim($prefix . ' ' . $unit);
        }
        if ($n < 1000) {
            $h = intdiv($n, 100);
            $rest = $n % 100;
            $hundred = [
                1 => 'ehun', 2 => 'berrehun', 3 => 'hirurehun', 4 => 'laurehun', 5 => 'bostehun',
                6 => 'seiehun', 7 => 'zazpiehun', 8 => 'zortzirehun', 9 => 'bederatziehun',
            ][$h] ?? ($h . 'ehun');
            if ($rest === 0) {
                return $hundred;
            }
            if ($rest === 1) {
                return $hundred . ' eta bat';
            }

            return $hundred . ' eta ' . self::spell_number_eu($rest);
        }
        if ($n < 1000000) {
            $th = intdiv($n, 1000);
            $rest = $n % 1000;
            $thousand = ($th === 1) ? 'mila' : (self::spell_number_eu($th) . ' mila');
            if ($rest === 0) {
                return $thousand;
            }

            return $thousand . ' ' . self::spell_number_eu($rest);
        }

        return (string) $n;
    }

    private static function format_date_spoken(int $year, int $month, int $day, string $locale): string {
        if (!class_exists('IntlDateFormatter', false)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-n-j', sprintf('%d-%d-%d', $year, $month, $day));
        if (!$dt) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
        $fmt = new IntlDateFormatter(
            $locale,
            IntlDateFormatter::LONG,
            IntlDateFormatter::NONE,
            $dt->getTimezone(),
            IntlDateFormatter::GREGORIAN
        );
        $out = $fmt->format($dt);

        return is_string($out) && $out !== '' ? self::clean_spoken_date($out, $locale) : sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private static function clean_spoken_date(string $out, string $locale): string {
        if (str_starts_with(strtolower($locale), 'eu')) {
            $out = preg_replace('/\(([a-z])\)/iu', '$1', $out) ?? $out;
        }

        return $out;
    }

    private static function format_time_spoken(int $hour, int $minute, string $locale, string $lang): string {
        $hour = max(0, min(23, $hour));
        $minute = max(0, min(59, $minute));
        if ($lang === 'eu') {
            if ($minute === 0) {
                return $hour . 'etan';
            }

            return $hour . ':' . str_pad((string) $minute, 2, '0', STR_PAD_LEFT) . 'ean';
        }
        $h = self::spell_number($hour, $locale, $lang);
        if ($minute === 0) {
            $templates = [
                'en' => '{h} o\'clock',
                'fr' => '{h} heures',
                'de' => '{h} Uhr',
                'es' => 'a las {h}',
            ];
            $tpl = $templates[$lang] ?? 'a las {h}';

            return str_replace('{h}', $h, $tpl);
        }
        $m = self::spell_number($minute, $locale, $lang);
        $templates = [
            'en' => '{hour} {min}',
            'fr' => '{hour} heures {min}',
            'de' => '{hour} Uhr {min}',
            'es' => 'a las {hour} y {min}',
        ];
        $tpl = $templates[$lang] ?? 'a las {hour} y {min}';

        return str_replace(['{hour}', '{min}', '{h}'], [$h, $m, $h], $tpl);
    }

    private static function currency_word(string $lang): string {
        $map = [
            'eu' => 'euro',
            'en' => 'euros',
            'fr' => 'euros',
            'de' => 'Euro',
            'es' => 'euros',
        ];

        return $map[$lang] ?? 'euros';
    }

    /**
     * Voz macOS `say` para desarrollo local.
     */
    public static function macos_say_voice(string $lang_code, string $voice_pref = 'default'): string {
        $lang = self::normalize_lang_code($lang_code);
        $male = ($voice_pref === 'male');
        $map = [
            'es' => $male ? 'Jorge' : 'Monica',
            'en' => $male ? 'Alex' : 'Samantha',
            'fr' => $male ? 'Thomas' : 'Amelie',
            'de' => $male ? 'Markus' : 'Anna',
            'eu' => $male ? 'Monica' : 'Monica',
        ];

        return (string) ($map[$lang] ?? ($male ? 'Alex' : 'Samantha'));
    }
}

endif;
