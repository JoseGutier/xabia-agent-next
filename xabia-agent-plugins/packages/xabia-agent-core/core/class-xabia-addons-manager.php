<?php
/**
 * Addons con suscripción Polar: opciones por slug, validación contra el hub y caché ligera.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Addons {

    public const OPTION_PREFIX = 'xabia_license_';

    public const POLAR_SHOP_BASE = 'https://polar.sh/xabia';

    public const POLAR_CUSTOMER_PORTAL = 'https://polar.sh/xabia/portal';

    /** @var int */
    private const HUB_CACHE_TTL = 900;

    /** @var int Cache corta cuando el hub dice «sin suscripción» para no bloquear Avirato tras activar en el panel. */
    private const HUB_CACHE_TTL_INACTIVE = 240;

    /**
     * Catálogo modular: añade filas aquí o vía filtro `xabia_addons_registry`.
     *
     * @return list<array{
     *   slug:string,
     *   title:string,
     *   description:string,
     *   price_label:string,
     *   lucide:string,
     *   polar_checkout_url:string,
     *   polar_portal_url?:string,
     *   hub_addon_slugs?:list<string>,
     *   plugin_file?:string
     * }>
     */
    public static function registry(): array {
        $defaults = [
            [
                'slug'               => 'avirato',
                'title'              => 'Xabia-Avirato',
                'description'        => __('Conecta disponibilidad real de Avirato con el chat (IA-Lite y contexto en vivo).', 'xabia-intelligence'),
                'price_label'        => __('Suscripción anual — tarifas en xabia.ai/precio', 'xabia-intelligence'),
                'lucide'             => 'calendar-check-2',
                'polar_checkout_url' => self::POLAR_SHOP_BASE,
                'polar_portal_url'   => self::POLAR_CUSTOMER_PORTAL,
                'hub_addon_slugs'    => ['xabia-avirato', 'avirato', 'xabia_avirato'],
                'plugin_file'        => 'xabia-avirato/xabia-avirato.php',
                'hub_update_slug'    => 'xabia-avirato',
            ],
            [
                'slug'               => 'xabia-mec',
                'title'              => __('Xabia — Modern Events Calendar', 'xabia-intelligence'),
                'description'        => __('Addon de especialización que dota a Xabia AI con inteligencia avanzada para la gestión de eventos, plazas y reservas de Modern Events Calendar, ofreciendo interacciones asistidas en tiempo real.', 'xabia-intelligence'),
                'price_label'        => __('Suscripción anual — tarifas en xabia.ai/precio', 'xabia-intelligence'),
                'lucide'             => 'calendar-days',
                'polar_checkout_url' => 'https://buy.polar.sh/polar_cl_wEzwnqMvZIrPelny1I5HNIsdcVjGs1UO12Roj3zzxIm',
                'polar_portal_url'   => self::POLAR_CUSTOMER_PORTAL,
                'hub_addon_slugs'    => ['xabia-mec', 'mec', 'xabia_mec', 'modern-events-calendar', 'mec-pro'],
                'plugin_file'        => 'xabia-mec/xabia-mec.php',
                'hub_update_slug'    => 'xabia-mec',
            ],
            [
                'slug'               => 'xabia-woo',
                'title'              => __('Xabia — WooCommerce', 'xabia-intelligence'),
                'description'        => __('Addon que transforma tu WooCommerce en una plataforma de comercio conversacional avanzado. Dota al Agente de IA con inteligencia sobre tu catálogo para carritos asistidos e interacciones de ventas hiperpersonalizadas.', 'xabia-intelligence'),
                'price_label'        => __('Suscripción anual — tarifas en xabia.ai/precio', 'xabia-intelligence'),
                'lucide'             => 'shopping-cart',
                // Checkout Woo: producto Polar propio (no reutilizar el enlace del addon MEC).
                'polar_checkout_url' => self::POLAR_SHOP_BASE,
                'polar_portal_url'   => self::POLAR_CUSTOMER_PORTAL,
                'hub_addon_slugs'    => ['xabia-woo', 'woo', 'woocommerce', 'xabia_woo'],
                'plugin_file'        => 'xabia-woo/xabia-woo.php',
                'hub_update_slug'    => 'xabia-woo',
            ],
            [
                'slug'               => 'xabia-amelia',
                'title'              => __('Xabia — Amelia', 'xabia-intelligence'),
                'description'        => __('Addon que dota a Xabia AI con inteligencia avanzada para la gestión de citas, servicios y calendarios de Amelia, automatizando la programación de reservas mediante interacciones conversacionales fluidas en tiempo real.', 'xabia-intelligence'),
                'price_label'        => __('Consulte tarifas en xabia.ai/precio', 'xabia-intelligence'),
                'lucide'             => 'calendar-clock',
                'polar_checkout_url' => self::POLAR_SHOP_BASE,
                'polar_portal_url'   => self::POLAR_CUSTOMER_PORTAL,
                'hub_addon_slugs'    => ['xabia-amelia', 'amelia', 'xabia_amelia'],
                'plugin_file'        => 'xabia-amelia/xabia-amelia.php',
                'hub_update_slug'    => 'xabia-amelia',
            ],
            [
                'slug'               => 'xabia-federation',
                'title'              => __('Xabia — Federation', 'xabia-intelligence'),
                'description'        => __('Addon avanzado que transforma a Xabia AI en un nodo centralizado de federación global, permitiendo la interconexión inteligente de datos, el intercambio de conocimiento y la sincronización omnicanal entre múltiples sitios webs y plataformas.', 'xabia-intelligence'),
                'price_label'        => __('Consulte tarifas en xabia.ai/precio', 'xabia-intelligence'),
                'lucide'             => 'network',
                'polar_checkout_url' => self::POLAR_SHOP_BASE,
                'polar_portal_url'   => self::POLAR_CUSTOMER_PORTAL,
                'hub_addon_slugs'    => ['xabia-federation', 'federation', 'xabia_federation'],
                'plugin_file'        => 'xabia-federation/xabia-federation.php',
                'hub_update_slug'    => 'xabia-federation',
            ],
        ];

        $list = apply_filters('xabia_addons_registry', $defaults);

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    public static function option_name(string $slug): string {
        return self::OPTION_PREFIX . sanitize_key($slug);
    }

    public static function transient_name(string $slug): string {
        return 'xabia_addon_hub_' . sanitize_key($slug);
    }

    public static function is_registered_slug(string $slug): bool {
        $slug = sanitize_key($slug);
        foreach (self::registry() as $row) {
            if (sanitize_key((string) ($row['slug'] ?? '')) === $slug) {
                return true;
            }
        }

        return false;
    }

    public static function get_definition(string $slug): ?array {
        $slug = sanitize_key($slug);
        foreach (self::registry() as $row) {
            if (sanitize_key((string) ($row['slug'] ?? '')) === $slug) {
                return $row;
            }
        }

        return null;
    }

    /** Slug del catálogo Hub GET /updates (carpeta del plugin ZIP). */
    public static function hub_update_slug(string $slug): string {
        $def = self::get_definition($slug);
        if ($def === null) {
            return '';
        }
        $hub = trim((string) ($def['hub_update_slug'] ?? ''));
        if ($hub !== '') {
            return sanitize_key($hub);
        }
        $pf = str_replace('\\', '/', trim((string) ($def['plugin_file'] ?? '')));
        if ($pf === '' || $pf === '.') {
            return '';
        }

        return sanitize_key(dirname($pf));
    }

    public static function get_stored_license_key(string $slug): string {
        $v = get_option(self::option_name($slug), '');

        return is_string($v) ? trim($v) : '';
    }

    /**
     * Checkout Polar con contexto del sitio (custom field «domain» + license_key).
     * Org Polar comparte el slug «domain» con Digixop Translator Pro: solo host, sin https/www.
     */
    public static function polar_checkout_url_with_site_context(string $checkoutUrl): string {
        $checkoutUrl = trim($checkoutUrl);
        if ($checkoutUrl === '') {
            return '';
        }
        $args = [];
        $site = home_url('/');
        if (is_string($site) && $site !== '') {
            $host = wp_parse_url($site, PHP_URL_HOST);
            if (!is_string($host) || $host === '') {
                $host = preg_replace('#^https?://#i', '', $site);
                $host = preg_replace('#/.*$#', '', (string) $host);
            }
            $host = strtolower(trim((string) $host));
            if (str_starts_with($host, 'www.')) {
                $host = substr($host, 4);
            }
            if ($host !== '') {
                $args['domain'] = $host;
            }
        }
        $coreLicense = trim((string) get_option('xabia_digixop_license_key', ''));
        if ($coreLicense !== '') {
            $args['license_key'] = $coreLicense;
        }
        if ($args === []) {
            return $checkoutUrl;
        }

        return add_query_arg($args, $checkoutUrl);
    }

    /**
     * Clave usada al validar en el hub: la del campo del addon; si está vacía, la licencia Core.
     */
    public static function effective_license_key(string $slug): string {
        $k = self::get_stored_license_key($slug);
        if ($k !== '') {
            return $k;
        }
        if (class_exists('Xabia_Digixop_Client', false)) {
            return Xabia_Digixop_Client::get_license_key();
        }

        return '';
    }

    /**
     * @param list<string> $needles Normalizados a minúsculas
     */
    public static function hub_payload_includes_addon(array $json, array $needles): ?array {
        if ($needles === []) {
            return null;
        }
        $rows = [];
        if (!empty($json['addon_activations']) && is_array($json['addon_activations'])) {
            foreach ($json['addon_activations'] as $r) {
                if (is_array($r) && isset($r['addon_slug'])) {
                    $rows[] = $r;
                }
            }
        }
        if ($rows === [] && !empty($json['active_addons']) && is_array($json['active_addons'])) {
            foreach ($json['active_addons'] as $s) {
                if (is_string($s) && $s !== '') {
                    $rows[] = ['addon_slug' => $s, 'expiry_date' => null];
                }
            }
        }
        foreach ($rows as $r) {
            $s = (string) ($r['addon_slug'] ?? '');
            foreach ($needles as $needle) {
                if (self::addon_slug_matches_hub($s, $needle)) {
                    return $r;
                }
            }
            $pid = isset($r['product_id']) ? (string) $r['product_id'] : '';
            if ($pid !== '') {
                foreach ($needles as $needle) {
                    if (self::addon_slug_matches_hub($pid, $needle)) {
                        return $r;
                    }
                }
            }
        }

        return null;
    }

    private static function normalize_hub_addon_token(string $s): string {
        $s = strtolower(trim($s));
        $s = str_replace('_', '-', preg_replace('/\s+/u', '-', $s));

        return $s;
    }

    /**
     * Coincide slug del hub (addon_slug, product_id) con agujas del registry aunque difieran guiones / prefijo xabia-.
     */
    private static function addon_slug_matches_hub(string $rowSlug, string $needle): bool {
        $a = self::normalize_hub_addon_token($rowSlug);
        $b = self::normalize_hub_addon_token($needle);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $strip = static function (string $x): string {
            return (string) preg_replace('#^xabia[-]#', '', $x);
        };

        return $strip($a) === $strip($b);
    }

    /**
     * @return array{
     *   subscription_active:bool,
     *   license_valid:bool,
     *   renewal_iso:?string,
     *   renewal_ts:int,
     *   addon_activated_iso:?string,
     *   addon_activated_ts:int,
     *   message:string,
     *   hub_addon_slugs:list<string>,
     *   checked_at:int,
     *   from_cache:bool,
     *   validated_with_core_fallback:bool
     * }
     */
    public static function get_hub_status(string $slug, bool $force_refresh = false): array {
        $slug = sanitize_key($slug);
        $def = self::get_definition($slug);
        $hubSlugsRaw = is_array($def) ? ($def['hub_addon_slugs'] ?? ['']) : [''];
        $hubSlugs = [];
        foreach ((array) $hubSlugsRaw as $h) {
            $h = strtolower(trim((string) $h));
            if ($h !== '') {
                $hubSlugs[] = $h;
            }
        }
        if ($hubSlugs === []) {
            $hubSlugs = [sanitize_key($slug)];
        }

        $empty = [
            'subscription_active'            => false,
            'license_valid'                  => false,
            'renewal_iso'                  => null,
            'renewal_ts'                   => 0,
            'addon_activated_iso'          => null,
            'addon_activated_ts'           => 0,
            'message'                      => __('Sin datos de suscripción.', 'xabia-intelligence'),
            'hub_addon_slugs'                => $hubSlugs,
            'checked_at'                     => 0,
            'from_cache'                     => false,
            'validated_with_core_fallback'   => false,
            'inactive_reason'                => '',
        ];

        $tname = self::transient_name($slug);
        if (!$force_refresh) {
            $cached = get_transient($tname);
            if (is_array($cached) && isset($cached['subscription_active'], $cached['checked_at'])) {
                $cached['from_cache'] = true;

                return wp_parse_args($cached, $empty);
            }
        }

        $storedAddonKey = self::get_stored_license_key($slug);
        $primaryKey = self::effective_license_key($slug);
        $coreKey = '';
        if (class_exists('Xabia_Digixop_Client', false)) {
            $coreKey = trim(Xabia_Digixop_Client::get_license_key());
        }
        $keysToTry = [];
        if ($primaryKey !== '') {
            $keysToTry[] = $primaryKey;
        }
        if ($coreKey !== '' && !in_array($coreKey, $keysToTry, true)) {
            $keysToTry[] = $coreKey;
        }
        $keysToTry = apply_filters('xabia_addons_hub_license_keys_to_try', $keysToTry, $slug, $hubSlugs);
        if (!is_array($keysToTry)) {
            $keysToTry = [];
        }
        $keysToTry = array_values(array_filter(array_map(static function ($k): string {
            return is_string($k) ? trim($k) : '';
        }, $keysToTry), static fn (string $k): bool => $k !== ''));

        if ($keysToTry === []) {
            $out = $empty;
            $out['message'] = __('Introduce la clave de licencia o configura la licencia Core.', 'xabia-intelligence');
            set_transient($tname, $out, self::HUB_CACHE_TTL_INACTIVE);

            return $out;
        }

        if (!class_exists('Xabia_Digixop_Client', false)) {
            set_transient($tname, $empty, self::HUB_CACHE_TTL_INACTIVE);

            return $empty;
        }

        $json = null;
        $subscriptionActive = false;
        $licenseValid = false;
        $row = null;
        $usedCoreFallback = false;
        foreach ($keysToTry as $idx => $tryKey) {
            $j = Xabia_Digixop_Client::license_validate_json_for_key($tryKey);
            if (!is_array($j)) {
                continue;
            }
            $json = $j;
            $lv = !empty($j['valid']) || !empty($j['success']);
            $licenseValid = $lv;
            $r = self::hub_payload_includes_addon($j, $hubSlugs);
            $row = $r;
            if ($lv && $r !== null) {
                $subscriptionActive = true;
                if ($idx > 0) {
                    $usedCoreFallback = true;
                }

                break;
            }
        }

        if (!is_array($json)) {
            $out = $empty;
            $out['message'] = __('No se pudo contactar con el hub de licencias en este momento.', 'xabia-intelligence');
            $out['inactive_reason'] = 'hub_unreachable';
            set_transient($tname, $out, min(self::HUB_CACHE_TTL_INACTIVE, 120));

            return $out;
        }

        $renewalIso = null;
        $renewalTs = 0;
        $activatedIso = null;
        $activatedTs = 0;
        if (is_array($row)) {
            $exp = $row['expiry_date'] ?? null;
            if (is_string($exp) && $exp !== '') {
                $renewalIso = $exp;
                $renewalTs = (int) strtotime($exp);
            }
            $act = $row['activated_at'] ?? null;
            if (is_string($act) && $act !== '') {
                $activatedIso = $act;
                $activatedTs = (int) strtotime($act);
            }
        }

        $hubReason = isset($json['reason']) ? (string) $json['reason'] : '';
        $msg = isset($json['message']) && is_string($json['message']) ? trim((string) $json['message']) : '';
        if ($msg === '') {
            $msg = $subscriptionActive
                ? __('Suscripción activa.', 'xabia-intelligence')
                : __('Suscripción no encontrada o caducada para este addon.', 'xabia-intelligence');
        }
        if (!$subscriptionActive) {
            if ($hubReason === 'unauthorized_domain') {
                $msg = __('Dominio no autorizado para esta licencia en el Hub.', 'xabia-intelligence');
            } elseif ($hubReason === 'license_key_unknown') {
                $msg = __('Clave de licencia desconocida en el Hub.', 'xabia-intelligence');
            } elseif ($licenseValid) {
                $msg = __('Licencia válida: el Hub no lista este add-on en tu cuenta (actívalo en Polar o contacta soporte).', 'xabia-intelligence');
            } elseif ($hubReason === '') {
                $msg = __('Licencia no válida o inactiva; revisa Conexión a la IA.', 'xabia-intelligence');
            }
        }
        if ($subscriptionActive && $usedCoreFallback && $storedAddonKey !== '') {
            $msg = __('Suscripción activa (el hub reconoce Avirato en tu licencia principal del sitio). Puedes borrar la clave de este campo y guardar para evitar confusiones.', 'xabia-intelligence');
        }

        $inactiveReason = '';
        if (!$subscriptionActive && $licenseValid) {
            $inactiveReason = 'addon_not_on_license';
        } elseif (!$subscriptionActive && !$licenseValid) {
            $inactiveReason = 'license_invalid';
        }
        if ($hubReason === 'unauthorized_domain') {
            $inactiveReason = 'license_invalid';
        }
        $out = [
            'subscription_active'          => $subscriptionActive,
            'license_valid'                => $licenseValid,
            'renewal_iso'                  => $renewalIso,
            'renewal_ts'                   => $renewalTs,
            'addon_activated_iso'          => $activatedIso,
            'addon_activated_ts'           => $activatedTs,
            'message'                      => $msg,
            'hub_addon_slugs'              => $hubSlugs,
            'checked_at'                   => time(),
            'from_cache'                   => false,
            'validated_with_core_fallback' => $usedCoreFallback,
            'inactive_reason'              => $inactiveReason,
        ];
        $ttl = $subscriptionActive ? self::HUB_CACHE_TTL : self::HUB_CACHE_TTL_INACTIVE;
        set_transient($tname, $out, $ttl);

        return $out;
    }

    public static function is_active(string $slug): bool {
        $slug = sanitize_key($slug);
        if ($slug === '') {
            return false;
        }

        return self::get_hub_status($slug, false)['subscription_active'];
    }

    public static function flush_status_cache(string $slug): void {
        delete_transient(self::transient_name(sanitize_key($slug)));
    }

    /**
     * @return array{expiring_soon:bool,days_left:?int,urgent:bool}
     */
    public static function renewal_hint(string $slug): array {
        $st = self::get_hub_status($slug, false);
        $ts = (int) ($st['renewal_ts'] ?? 0);
        if ($ts < 1) {
            return ['expiring_soon' => false, 'days_left' => null, 'urgent' => false];
        }
        $days = (int) floor(($ts - time()) / DAY_IN_SECONDS);

        return [
            'expiring_soon' => $days >= 0 && $days <= 30,
            'days_left'     => $days,
            'urgent'        => $days >= 0 && $days <= 7,
        ];
    }
}
