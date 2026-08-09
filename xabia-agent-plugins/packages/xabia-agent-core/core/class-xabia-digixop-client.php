<?php
/**
 * Xabia SaaS: licencia, proxy OpenAI-compatible y contabilidad por tokens.
 * La misma cadena que el usuario pega aquí se envía al hub (trim) en validate/proxy; no hay traducción de claves.
 * Las claves maestras del proveedor solo existen en el proxy; el sitio firma con su licencia guardada.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Digixop_Client {

    public const OPTION_LICENSE = 'xabia_digixop_license_key';
    public const TRANSIENT_META = 'xabia_digixop_license_meta';

    /**
     * Hub SaaS — URL pública del gateway (p. ej. public_html/api con gateway-public-html).
     * Sobrescribible vía opciones o filtros.
     */
    public const URL_PROXY = 'https://xabia.ai/api/xabia/v1/proxy';

    public const URL_LICENSE_VALIDATE = 'https://xabia.ai/api/xabia/v1/license/validate';

    public const URL_USAGE_REPORT = 'https://xabia.ai/api/xabia/v1/usage';

    public const URL_UPDATES = 'https://xabia.ai/api/xabia/v1/updates';

    public const URL_KNOWLEDGE_SYNC = 'https://xabia.ai/api/v1/knowledge/sync';

    public const URL_KNOWLEDGE_SEARCH = 'https://xabia.ai/api/v1/knowledge/search';

    public const URL_I18N_GREETING_TRANSLATE = 'https://xabia.ai/api/xabia/v1/i18n/greeting-translate';

    /** Opción WordPress: URL completa del proxy (vacío = usar URL_PROXY). */
    public const OPTION_PROXY_URL = 'xabia_digixop_proxy_url';

    public const OPTION_LICENSE_VALIDATE_URL = 'xabia_digixop_license_validate_url';

    public const OPTION_USAGE_REPORT_URL = 'xabia_digixop_usage_report_url';

    public const OPTION_KNOWLEDGE_SYNC_URL = 'xabia_digixop_knowledge_sync_url';

    public const OPTION_KNOWLEDGE_SEARCH_URL = 'xabia_digixop_knowledge_search_url';

    public const OPTION_I18N_GREETING_TRANSLATE_URL = 'xabia_digixop_i18n_greeting_translate_url';

    /**
     * Modo de conexión del sitio: xabia_cloud (por defecto) u own_infra (claves propias).
     */
    public const OPTION_CONNECTION_MODE = 'xabia_connection_mode';

    /** @var int|null */
    private static $last_embedding_total_tokens = null;

    /** Saldo agotado en esta petición (chat o entrenamiento). */
    private static $session_insufficient_balance = false;

    /**
     * Último fallo del proxy (p. ej. entrenar embeddings) para mostrar detalle en admin.
     *
     * @var array{http_code:int,message:string,type:string,code:mixed}|null
     */
    private static $last_proxy_failure = null;

    public static function get_license_key(): string {
        $k = get_option(self::OPTION_LICENSE, '');
        return is_string($k) ? trim($k) : '';
    }

    public static function is_license_configured(): bool {
        return self::get_license_key() !== '';
    }

    public static function reset_session_flags(): void {
        self::$session_insufficient_balance = false;
        self::$last_embedding_total_tokens = null;
        self::$last_proxy_failure = null;
    }

    /**
     * @return array{http_code:int,message:string,type:string,code:mixed}|null
     */
    public static function get_last_proxy_failure(): ?array {
        return self::$last_proxy_failure;
    }

    /**
     * @param array{ok?:bool, body?:array|null, raw?:string, code?:int, insufficient_balance?:bool} $out
     */
    private static function record_proxy_failure(array $out): void {
        $body = $out['body'] ?? null;
        $msg = '';
        $type = '';
        $errCode = null;
        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            $msg = (string) ($body['error']['message'] ?? '');
            $type = (string) ($body['error']['type'] ?? '');
            $errCode = $body['error']['code'] ?? null;
        }
        if ($msg === '' && isset($out['raw']) && is_string($out['raw'])) {
            $msg = $out['raw'];
        }
        self::$last_proxy_failure = [
            'http_code' => (int) ($out['code'] ?? 0),
            'message'   => substr($msg, 0, 600),
            'type'      => $type,
            'code'      => $errCode,
        ];
    }

    public static function mark_insufficient_balance(): void {
        self::$session_insufficient_balance = true;
    }

    public static function was_insufficient_balance(): bool {
        return self::$session_insufficient_balance;
    }

    /**
     * Saldo de licencia en caché (null si no hay dato fiable).
     */
    public static function license_tokens_remaining(): ?int {
        self::refresh_license_meta_from_hub_if_stale();
        $meta = self::get_cached_license_meta();
        if (!is_array($meta) || !isset($meta['tokens_remaining']) || !is_numeric($meta['tokens_remaining'])) {
            return null;
        }

        return max(0, (int) $meta['tokens_remaining']);
    }

    /**
     * True si el modo cloud usa proxy con licencia y el saldo conocido es 0.
     */
    public static function proxy_tokens_depleted(): bool {
        if (!self::is_license_configured() || !self::is_xabia_cloud_mode()) {
            return false;
        }
        $remaining = self::license_tokens_remaining();

        return $remaining !== null && $remaining <= 0;
    }

    /**
     * URL del sitio cliente para cabecera X-Xabia-Source (identificación en panel central).
     */
    public static function get_client_source_url(): string {
        $site = untrailingslashit((string) get_site_url());
        return (string) apply_filters('xabia_digixop_client_source_url', $site);
    }

    /**
     * Valida peticiones firmadas entrantes del Hub (Reloj Maestro, etc.).
     * Mismo algoritmo que default_proxy_headers / HubClientSignature::sign.
     */
    public static function verify_hub_inbound_signature(string $raw_body): bool {
        $license = self::get_license_key();
        if ($license === '') {
            return false;
        }

        $header_license = trim((string) ($_SERVER['HTTP_X_XABIA_LICENSE'] ?? ''));
        if ($header_license !== '' && !hash_equals($license, $header_license)) {
            return false;
        }

        $source = trim((string) ($_SERVER['HTTP_X_XABIA_SOURCE'] ?? ''));
        $timestamp = trim((string) ($_SERVER['HTTP_X_XABIA_TIMESTAMP'] ?? ''));
        $signature = trim((string) ($_SERVER['HTTP_X_XABIA_SIGNATURE'] ?? ''));
        if ($source === '' || $timestamp === '' || $signature === '') {
            return false;
        }

        if (!self::hub_source_urls_match($source, self::get_client_source_url())) {
            return false;
        }

        $ts = is_numeric($timestamp) ? (int) $timestamp : 0;
        if ($ts < 1 || abs(time() - $ts) > 15 * MINUTE_IN_SECONDS) {
            return false;
        }

        $payload = $license . "\n" . $source . "\n" . $timestamp . "\n" . $raw_body;
        $expected = hash_hmac('sha256', $payload, $license);
        $candidate = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;

        return hash_equals($expected, $candidate);
    }

    private static function hub_source_urls_match(string $claimed, string $local): bool {
        $claimed = untrailingslashit(strtolower(trim($claimed)));
        $local = untrailingslashit(strtolower(trim($local)));
        if ($claimed === '' || $local === '') {
            return false;
        }
        if ($claimed === $local) {
            return true;
        }
        $claimed_host = wp_parse_url($claimed, PHP_URL_HOST);
        $local_host = wp_parse_url($local, PHP_URL_HOST);
        if (!is_string($claimed_host) || !is_string($local_host)) {
            return false;
        }
        $claimed_host = strtolower($claimed_host);
        $local_host = strtolower($local_host);
        if ($claimed_host === $local_host) {
            return true;
        }
        $strip_www = static function (string $host): string {
            return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        };

        return $strip_www($claimed_host) === $strip_www($local_host);
    }

    public static function get_insufficient_balance_user_message(): string {
        return (string) apply_filters(
            'xabia_digixop_insufficient_balance_message',
            __('Saldo de tokens agotado. Por favor, recargue su licencia Xabia.', 'xabia-intelligence')
        );
    }

    /**
     * Clave OpenAI efectiva: por agente si existe; si no, global.
     *
     * @param array<string, mixed> $config
     */
    public static function get_effective_openai_key(string $project_id, array $config): string {
        $local = isset($config['openai_api_key']) ? trim((string) $config['openai_api_key']) : '';
        if ($local !== '') {
            return $local;
        }
        $g = get_option('xabia_openai_key', '');
        return is_string($g) ? trim($g) : '';
    }

    /**
     * JSON de servicio Vertex resuelto y legible en disco.
     *
     * @param array<string, mixed> $config
     */
    public static function vertex_credentials_ready(array $config): bool {
        if (($config['ai_driver'] ?? '') !== 'google_cloud') {
            return false;
        }
        $path = $config['gcloud_json_path'] ?? '';
        if ($path === '' || $path === null) {
            $path = (string) get_option('xabia_gcloud_json_path', '');
        }
        $path = trim((string) $path);
        return $path !== '' && is_readable($path);
    }

    /**
     * Modo simplificado: IA vía hub Xabia (licencia); no se usan Vertex/OpenAI locales aunque estén guardados.
     */
    public static function is_xabia_cloud_mode(): bool {
        $m = get_option(self::OPTION_CONNECTION_MODE, 'xabia_cloud');
        $m = is_string($m) ? sanitize_key($m) : 'xabia_cloud';

        return $m !== 'own_infra';
    }

    /**
     * Vertex en servidor del cliente solo en modo infraestructura propia.
     *
     * @param array<string, mixed> $config
     */
    public static function should_use_local_vertex(array $config): bool {
        if (self::is_xabia_cloud_mode()) {
            return false;
        }

        return self::vertex_credentials_ready($config);
    }

    /**
     * Usar proxy Xabia para llamadas estilo OpenAI (chat / embeddings) cuando hay licencia y no hay clave OpenAI local.
     *
     * @param array<string, mixed> $config
     */
    public static function should_use_openai_proxy(string $project_id, array $config): bool {
        if (!self::is_license_configured()) {
            return false;
        }
        if (self::is_xabia_cloud_mode()) {
            return true;
        }
        if (self::get_effective_openai_key($project_id, $config) !== '') {
            return false;
        }
        if (self::vertex_credentials_ready($config)) {
            return false;
        }
        return true;
    }

    public static function default_proxy_url(): string {
        $o = get_option(self::OPTION_PROXY_URL, '');
        if (is_string($o) && trim($o) !== '') {
            return trim($o);
        }

        return self::URL_PROXY;
    }

    public static function default_license_validate_url(): string {
        $o = get_option(self::OPTION_LICENSE_VALIDATE_URL, '');
        if (is_string($o) && trim($o) !== '') {
            return trim($o);
        }

        return self::URL_LICENSE_VALIDATE;
    }

    public static function default_usage_report_url(): string {
        $o = get_option(self::OPTION_USAGE_REPORT_URL, '');
        if (is_string($o) && trim($o) !== '') {
            return trim($o);
        }

        return self::URL_USAGE_REPORT;
    }

    public static function default_knowledge_sync_url(): string {
        $o = get_option(self::OPTION_KNOWLEDGE_SYNC_URL, '');
        if (is_string($o) && trim($o) !== '') {
            return trim($o);
        }

        return self::URL_KNOWLEDGE_SYNC;
    }

    public static function default_knowledge_search_url(): string {
        $o = get_option(self::OPTION_KNOWLEDGE_SEARCH_URL, '');
        if (is_string($o) && trim($o) !== '') {
            return trim($o);
        }

        return self::URL_KNOWLEDGE_SEARCH;
    }

    public static function default_i18n_greeting_translate_url(): string {
        $o = get_option(self::OPTION_I18N_GREETING_TRANSLATE_URL, '');
        if (is_string($o) && trim($o) !== '') {
            return trim($o);
        }

        return self::URL_I18N_GREETING_TRANSLATE;
    }

    /**
     * Solicita al Hub traducciones DTP del saludo del agente (WPML).
     *
     * @param array<string, mixed> $body text, source_lang, target_langs, project_id
     * @return array{ok: bool, code: int, body: ?array, raw: string, insufficient_balance: bool}
     */
    public static function hub_translate_agent_greeting(array $body, string $project_id = ''): array {
        $url = (string) apply_filters(
            'xabia_hub_i18n_greeting_translate_url',
            self::default_i18n_greeting_translate_url(),
            $project_id
        );
        $license = self::get_license_key();
        $json = wp_json_encode($body);
        if (!is_string($json)) {
            $json = '{}';
        }
        $cred = [
            'site_url'   => self::get_client_source_url(),
            'project_id' => $project_id,
        ];
        $headers = apply_filters('xabia_hub_signed_post_headers', self::default_proxy_headers($license, $cred, $json), $url, $body, $cred);
        if (!is_array($headers)) {
            $headers = self::default_proxy_headers($license, $cred, $json);
        }
        $args = apply_filters(
            'xabia_hub_signed_post_args',
            [
                'headers' => $headers,
                'body'    => $json,
                'timeout' => 30,
            ],
            $url,
            $body
        );
        $resp = wp_remote_post($url, is_array($args) ? $args : []);
        $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
        $raw = is_wp_error($resp) ? $resp->get_error_message() : (string) wp_remote_retrieve_body($resp);
        $parsed = null;
        if (!is_wp_error($resp)) {
            $parsed = json_decode($raw, true);
            if (!is_array($parsed)) {
                $parsed = null;
            }
        }

        return [
            'ok'                   => !is_wp_error($resp) && $code >= 200 && $code < 300,
            'code'                 => $code,
            'body'                 => $parsed,
            'raw'                  => $raw,
            'insufficient_balance' => false,
        ];
    }

    /**
     * POST JSON firmado (X-Xabia-*) hacia rutas del hub que no son el proxy OpenAI.
     *
     * @param array<string, mixed> $body
     * @return array{ok: bool, code: int, body: ?array, raw: string, insufficient_balance: bool, wp_error: ?array{code: string, message: string, data: mixed}}
     */
    public static function hub_signed_json_post(string $url, array $body, string $project_id = ''): array {
        $license = self::get_license_key();
        $json = wp_json_encode($body);
        if (!is_string($json)) {
            $json = '{}';
        }
        $cred = [
            'site_url'   => self::get_client_source_url(),
            'project_id' => $project_id,
        ];
        $headers = apply_filters('xabia_hub_signed_post_headers', self::default_proxy_headers($license, $cred, $json), $url, $body, $cred);
        if (!is_array($headers)) {
            $headers = self::default_proxy_headers($license, $cred, $json);
        }
        $args = apply_filters(
            'xabia_hub_signed_post_args',
            [
                'headers' => $headers,
                'body'    => $json,
                'timeout' => 120,
            ],
            $url,
            $body
        );
        $resp = wp_remote_post($url, is_array($args) ? $args : []);
        $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
        $raw = is_wp_error($resp) ? $resp->get_error_message() : (string) wp_remote_retrieve_body($resp);
        $wp_error_payload = null;
        if (is_wp_error($resp)) {
            $wp_error_payload = [
                'code'    => (string) $resp->get_error_code(),
                'message' => (string) $resp->get_error_message(),
                'data'    => $resp->get_error_data(),
            ];
        }
        $parsed = null;
        if (!is_wp_error($resp)) {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                self::merge_license_meta_from_api_payload($parsed);
            } else {
                $parsed = null;
            }
        }
        $out = [
            'ok'                   => !is_wp_error($resp) && $code >= 200 && $code < 300,
            'code'                 => $code,
            'body'                 => $parsed,
            'raw'                  => $raw,
            'wp_error'             => $wp_error_payload,
            'insufficient_balance' => false,
        ];
        $out['insufficient_balance'] = self::is_insufficient_proxy_response([
            'code' => $code,
            'body' => is_array($parsed) && isset($parsed['error']) ? $parsed : (is_array($parsed) ? $parsed : null),
        ]);
        if ($out['insufficient_balance']) {
            self::mark_insufficient_balance();
        }

        return $out;
    }

    /**
     * Cabeceras obligatorias hacia el proxy: licencia + origen del sitio.
     *
     * @param array<string, mixed> $cred Contexto (site_url, project_id) para filtros.
     * @return array<string, string>
     */
    private static function default_proxy_headers(string $license, array $cred, string $body = ''): array {
        $headers = [
            'Content-Type'     => 'application/json',
            'Accept'           => 'application/json',
            'X-Xabia-License'  => $license,
            'X-Xabia-Source'   => self::get_client_source_url(),
        ];
        if ($license !== '') {
            $timestamp = (string) time();
            $payload = $license . "\n" . self::get_client_source_url() . "\n" . $timestamp . "\n" . $body;
            $headers['X-Xabia-Timestamp'] = $timestamp;
            $headers['X-Xabia-Signature'] = 'sha256=' . hash_hmac('sha256', $payload, $license);
        }

        return $headers;
    }

    /**
     * Cabeceras para validate / usage (misma identificación que el proxy).
     *
     * @return array<string, string>
     */
    private static function default_hub_headers(): array {
        $license = self::get_license_key();
        return [
            'Content-Type'     => 'application/json',
            'Accept'           => 'application/json',
            'X-Xabia-License'  => $license,
            'X-Xabia-Source'   => self::get_client_source_url(),
        ];
    }

    /**
     * Cabeceras para validar una clave distinta de la licencia Core (p. ej. add-ons Polar).
     *
     * @return array<string, string>
     */
    public static function default_hub_headers_for_license(string $license_key): array {
        $license_key = trim($license_key);

        return [
            'Content-Type'     => 'application/json',
            'Accept'           => 'application/json',
            'X-Xabia-License'  => $license_key,
            'X-Xabia-Source'   => self::get_client_source_url(),
        ];
    }

    /**
     * POST /license/validate: envía en cuerpo y cabecera exactamente $license_key (tras trim), sin normalizar.
     * No actualiza el transiente global del Core.
     *
     * @return array<string, mixed>|null JSON decodificado o null si falla la red o el parseo.
     */
    public static function license_validate_json_for_key(string $license_key): ?array {
        $license_key = trim($license_key);
        if ($license_key === '') {
            return null;
        }

        $siteUrl = self::get_client_source_url();
        $host = wp_parse_url($siteUrl, PHP_URL_HOST);
        $domain = is_string($host) && $host !== '' ? strtolower($host) : '';
        $baseUrl = (string) apply_filters('xabia_digixop_license_validate_url', self::default_license_validate_url());
        $urls = self::build_license_validate_url_candidates($baseUrl);
        $body = apply_filters(
            'xabia_digixop_license_validate_body',
            [
                'license_key' => $license_key,
                'site_url'    => $siteUrl,
                'domain'      => $domain,
            ],
            $baseUrl
        );
        $headers = apply_filters(
            'xabia_digixop_license_validate_headers',
            self::default_hub_headers_for_license($license_key),
            $baseUrl,
            $body
        );
        if (!is_array($headers)) {
            $headers = self::default_hub_headers_for_license($license_key);
        }
        $headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=utf-8';

        $json = null;
        foreach ($urls as $url) {
            $args = apply_filters(
                'xabia_digixop_license_validate_http_args',
                [
                    'headers' => $headers,
                    'body'    => is_array($body) ? $body : [],
                    'timeout' => 12,
                ],
                $url,
                $body
            );
            $resp = wp_remote_post($url, is_array($args) ? $args : []);
            if (is_wp_error($resp)) {
                continue;
            }
            $raw = (string) wp_remote_retrieve_body($resp);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $json = $decoded;
                break;
            }
        }

        return is_array($json) ? $json : null;
    }

    /**
     * HTTP 402 o cuerpo JSON de error de saldo (proxy espejo OpenAI + extensiones Xabia).
     *
     * @param array{code?: int, body?: array|null} $out Resultado de proxy_openai_post.
     */
    public static function is_insufficient_proxy_response(array $out): bool {
        if ((int) ($out['code'] ?? 0) === 402) {
            return true;
        }
        $b = $out['body'] ?? null;
        if (!is_array($b)) {
            return false;
        }
        if (!empty($b['digixop_insufficient_balance']) || !empty($b['insufficient_balance'])) {
            return true;
        }
        $err = $b['error'] ?? null;
        if (!is_array($err)) {
            return false;
        }
        $code = $err['code'] ?? '';
        if ($code === 'insufficient_balance' || $code === 'insufficient_quota' || $code === 'billing_hard_limit_reached') {
            return true;
        }
        $msg = strtolower((string) ($err['message'] ?? ''));
        if ($msg !== '' && (strpos($msg, 'saldo insuficiente') !== false || strpos($msg, 'insufficient balance') !== false)) {
            return true;
        }
        return false;
    }

    /**
     * Fusiona tokens_remaining y expiry_date del hub en el transiente de saldo (Ajustes generales).
     *
     * @param array<string, mixed>|null $json Respuesta JSON del hub o del proxy.
     */
    public static function merge_license_meta_from_api_payload(?array $json): void {
        if ($json === null) {
            return;
        }
        $tokens = $json['tokens_remaining'] ?? $json['remaining_tokens'] ?? $json['balance_tokens'] ?? $json['tokens'] ?? null;
        if ($tokens !== null && !is_numeric($tokens)) {
            $tokens = null;
        }
        $expiry = $json['expiry_date'] ?? $json['expires_at'] ?? $json['license_expires'] ?? null;
        if ($expiry !== null && !is_string($expiry)) {
            $expiry = (string) $expiry;
        }
        if ($tokens === null && ($expiry === null || $expiry === '')) {
            return;
        }

        $prev = get_transient(self::TRANSIENT_META);
        if (!is_array($prev)) {
            $prev = [];
        }
        if ($tokens !== null) {
            $prev['tokens_remaining'] = (int) $tokens;
        }
        if ($expiry !== null && $expiry !== '') {
            $prev['expiry_date'] = $expiry;
        }
        $prev['checked_at'] = time();
        set_transient(self::TRANSIENT_META, $prev, HOUR_IN_SECONDS * 12);
        if ($tokens !== null && class_exists('Xabia_DB', false)) {
            Xabia_DB::sync_wallet_balance((int) $tokens);
        }
    }

    /**
     * Traduce códigos `reason` del hub a texto legible en el escritorio (evita mensajes técnicos XABIA_DB_DSN a clientes).
     *
     * @param array<string, mixed> $json
     */
    private static function userFacingLicenseValidateMessage(array $json, string $defaultMsg): string {
        $reason = isset($json['reason']) && is_string($json['reason']) ? trim($json['reason']) : '';
        switch ($reason) {
            case 'license_key_unknown':
                return __('El hub no reconoce esta clave. Copia y pega exactamente la licencia que te muestra Polar (misma cadena, sin espacios de más). Si acabas de comprar, espera unos segundos a que se registre el pago o escribe a soporte indicando el email de la compra.', 'xabia-intelligence');
            case 'unauthorized_domain':
            case 'domain_mismatch': {
                $reg = '';
                if (isset($json['registered_domain']) && is_string($json['registered_domain'])) {
                    $reg = trim($json['registered_domain']);
                }
                if ($reg === '') {
                    $domains = $json['registered_domains'] ?? null;
                    if (is_array($domains) && $domains !== []) {
                        $clean = [];
                        foreach ($domains as $d) {
                            if (is_string($d) || is_numeric($d)) {
                                $t = trim((string) $d);
                                if ($t !== '') {
                                    $clean[] = $t;
                                }
                            }
                        }
                        $clean = array_values(array_unique($clean));
                        if ($clean !== []) {
                            $reg = $clean[0];
                        }
                    }
                }

                return sprintf(
                    /* translators: %s: registered domain for the license */
                    __('Esta licencia está vinculada a otro dominio (%s). Contacta con soporte para mover tu licencia.', 'xabia-intelligence'),
                    $reg !== '' ? $reg : __('desconocido', 'xabia-intelligence')
                );
            }
            case 'license_not_active':
                return __('La licencia consta en el hub pero está inactiva, suspendida o caducada.', 'xabia-intelligence');
            case 'wallet_missing':
                return __('La licencia y el dominio encajan, pero falta la cartera de tokens en el hub. Contacta con soporte técnico.', 'xabia-intelligence');
            default:
                return $defaultMsg;
        }
    }

    /**
     * POST JSON al proxy con el cuerpo exacto de OpenAI (chat o embeddings). Respuesta: formato estándar OpenAI (choices, usage, data…).
     *
     * @param array<string, mixed> $openai_body
     * @return array{ok: bool, body: array|null, raw: string, code: int, insufficient_balance: bool}
     */
    public static function proxy_openai_post(array $openai_body, string $project_id, array $config = []): array {
        $license = self::get_license_key();
        $url = (string) apply_filters('xabia_digixop_proxy_url', self::default_proxy_url(), $openai_body, $project_id, $config);
        $cred = [
            'site_url'   => self::get_client_source_url(),
            'project_id' => $project_id,
        ];
        $body = wp_json_encode($openai_body);
        if (!is_string($body)) {
            $body = '{}';
        }
        $headers = apply_filters('xabia_digixop_proxy_headers', self::default_proxy_headers($license, $cred, $body), $openai_body, $project_id, $config, $cred);
        if (!is_array($headers)) {
            $headers = self::default_proxy_headers($license, $cred, $body);
        }
        $args = [
            'headers' => $headers,
            'body'    => $body,
            'timeout' => 60,
        ];
        $args = apply_filters('xabia_digixop_proxy_http_args', $args, $openai_body, $project_id, $config);
        $resp = wp_remote_post($url, is_array($args) ? $args : []);
        $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
        $raw = is_wp_error($resp) ? $resp->get_error_message() : (string) wp_remote_retrieve_body($resp);
        $json = null;
        if (!is_wp_error($resp)) {
            $json = json_decode($raw, true);
            if (!is_array($json)) {
                $json = null;
            } else {
                self::merge_license_meta_from_api_payload($json);
            }
        }
        $out = [
            'ok'                   => !is_wp_error($resp) && $code >= 200 && $code < 300,
            'body'                 => $json,
            'raw'                  => $raw,
            'code'                 => $code,
            'insufficient_balance' => false,
        ];
        $out['insufficient_balance'] = self::is_insufficient_proxy_response($out);
        if ($out['insufficient_balance']) {
            self::mark_insufficient_balance();
        }
        if (!$out['ok'] || $out['insufficient_balance']) {
            self::record_proxy_failure($out);
        }
        return $out;
    }

    /**
     * @return array{content: string, usage: array<string, int>|null}|null
     */
    public static function parse_chat_completion_response(?array $json): ?array {
        if ($json === null) {
            return null;
        }
        $content = $json['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            return null;
        }
        $usage = isset($json['usage']) && is_array($json['usage']) ? $json['usage'] : null;
        return ['content' => $content, 'usage' => $usage];
    }

    /**
     * @return array{embedding: array<int, float>|null, usage: array<string, int>|null}
     */
    public static function parse_embedding_response(?array $json): array {
        $emb = null;
        if (isset($json['data'][0]['embedding']) && is_array($json['data'][0]['embedding'])) {
            $emb = $json['data'][0]['embedding'];
        }
        $usage = isset($json['usage']) && is_array($json['usage']) ? $json['usage'] : null;
        return ['embedding' => $emb, 'usage' => $usage];
    }

    public static function get_last_embedding_total_tokens(): ?int {
        return self::$last_embedding_total_tokens;
    }

    public static function reset_last_embedding_total_tokens(): void {
        self::$last_embedding_total_tokens = null;
    }

    /**
     * Embeddings vía proxy o null.
     *
     * @return array<int, float>|null
     */
    public static function embedding_via_proxy(string $text, string $model, string $project_id, array $config = []): ?array {
        self::$last_embedding_total_tokens = null;
        $body = [
            'model' => $model,
            'input' => $text,
        ];
        $out = self::proxy_openai_post($body, $project_id, $config);
        if (!empty($out['insufficient_balance'])) {
            return null;
        }
        if (!$out['ok']) {
            return null;
        }
        $parsed = self::parse_embedding_response($out['body']);
        if ($parsed['embedding'] === null) {
            self::record_proxy_failure([
                'ok'   => false,
                'code' => $out['code'],
                'body' => $out['body'],
                'raw'  => $out['raw'],
            ]);
            if (is_array(self::$last_proxy_failure) && self::$last_proxy_failure['message'] === '') {
                self::$last_proxy_failure['message'] = __('El hub respondió sin vectores válidos (revisa embeddings Vertex en el servidor central).', 'xabia-intelligence');
            }
            return null;
        }
        if (!empty($parsed['usage']['total_tokens'])) {
            self::$last_embedding_total_tokens = (int) $parsed['usage']['total_tokens'];
        }
        return $parsed['embedding'];
    }

    /**
     * Valida la licencia guardada en opciones contra el hub (misma cadena en POST/cabecera que en el campo).
     * Guarda saldo en transiente.
     *
     * @return array{valid: bool, tokens_remaining: int|null, expiry_date: string|null, message: string, raw?: string}
     */
    public static function validate_license_remote(): array {
        if (!self::is_license_configured()) {
            return ['valid' => false, 'tokens_remaining' => null, 'expiry_date' => null, 'message' => __('No hay licencia configurada.', 'xabia-intelligence')];
        }
        $siteUrl = self::get_client_source_url();
        $host = wp_parse_url($siteUrl, PHP_URL_HOST);
        $domain = is_string($host) && $host !== '' ? strtolower($host) : '';
        $baseUrl = (string) apply_filters('xabia_digixop_license_validate_url', self::default_license_validate_url());
        $urls = self::build_license_validate_url_candidates($baseUrl);
        $body = apply_filters(
            'xabia_digixop_license_validate_body',
            [
                'license_key' => self::get_license_key(),
                'site_url'    => $siteUrl,
                'domain'      => $domain,
            ],
            $baseUrl
        );
        $headers = apply_filters('xabia_digixop_license_validate_headers', self::default_hub_headers(), $baseUrl, $body);
        if (!is_array($headers)) {
            $headers = self::default_hub_headers();
        }
        
        $headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=utf-8';

        $lastRaw = '';
        $lastErr = '';
        $json = null;
        foreach ($urls as $url) {
            $args = apply_filters(
                'xabia_digixop_license_validate_http_args',
                [
                    'headers' => $headers,
                    'body'    => is_array($body) ? $body : [],
                    'timeout' => 15,
                ],
                $url,
                $body
            );
            $resp = wp_remote_post($url, is_array($args) ? $args : []);
            if (is_wp_error($resp)) {
                $lastErr = $resp->get_error_message();
                continue;
            }
            $lastRaw = (string) wp_remote_retrieve_body($resp);
            $decoded = json_decode($lastRaw, true);
            if (is_array($decoded)) {
                $json = $decoded;
                break;
            }
        }

        if (!is_array($json)) {
            $fallbackMsg = $lastErr !== '' ? $lastErr : trim(wp_strip_all_tags((string) $lastRaw));
            if ($fallbackMsg === '') {
                $fallbackMsg = __('Respuesta no válida del servidor de licencias.', 'xabia-intelligence');
            }
            return ['valid' => false, 'tokens_remaining' => null, 'expiry_date' => null, 'message' => $fallbackMsg, 'raw' => $lastRaw];
        }
        self::merge_license_meta_from_api_payload($json);

        $valid = !empty($json['valid']) || !empty($json['success']) || !empty($json['active']);
        $tokens = $json['tokens_remaining'] ?? $json['remaining_tokens'] ?? $json['balance_tokens'] ?? $json['tokens'] ?? null;
        if ($tokens !== null && !is_numeric($tokens)) {
            $tokens = null;
        }
        if ($tokens !== null) {
            $tokens = (int) $tokens;
        }
        $expiry = $json['expiry_date'] ?? $json['expires_at'] ?? null;
        if ($expiry !== null && !is_string($expiry)) {
            $expiry = (string) $expiry;
        }
        $hubMsg = isset($json['message']) && is_string($json['message']) ? trim($json['message']) : '';
        $defaultMsg = $hubMsg !== '' ? $hubMsg : ($valid ? __('Licencia activa.', 'xabia-intelligence') : __('Licencia no reconocida.', 'xabia-intelligence'));
        $msg = (string) apply_filters(
            'xabia_digixop_license_validate_user_message',
            self::userFacingLicenseValidateMessage($json, $defaultMsg),
            $json,
            $valid
        );

        $cached = get_transient(self::TRANSIENT_META);
        if (!is_array($cached)) {
            $cached = [];
        }
        $cached['valid'] = $valid;
        $cached['tokens_remaining'] = $tokens;
        $cached['message'] = $msg;
        $cached['checked_at'] = time();
        if ($expiry !== null && $expiry !== '') {
            $cached['expiry_date'] = $expiry;
        }
        $cached['validate_reason'] = isset($json['reason']) && is_string($json['reason']) ? $json['reason'] : '';
        $cached['license_validate_response'] = $json;
        set_transient(self::TRANSIENT_META, $cached, HOUR_IN_SECONDS * 12);

        return ['valid' => $valid, 'tokens_remaining' => $tokens, 'expiry_date' => $expiry, 'message' => $msg, 'raw' => (string) wp_json_encode($json)];
    }

    /**
     * @return list<string>
     */
    private static function build_license_validate_url_candidates(string $baseUrl): array {
        $u = trim($baseUrl);
        $candidates = [];
        if ($u !== '') {
            $candidates[] = $u;
        }
        $pairs = [
            ['/api/xabia/v1/license/validate', '/api/index.php/xabia/v1/license/validate'],
            ['/api/xabia/v1/license/validate', '/api/public/index.php/xabia/v1/license/validate'],
            ['/api/index.php/xabia/v1/license/validate', '/api/xabia/v1/license/validate'],
            ['/api-xabia-saas/xabia/v1/license/validate', '/api-xabia-saas/public/index.php/xabia/v1/license/validate'],
            ['/api-xabia-saas/public/index.php/xabia/v1/license/validate', '/api-xabia-saas/xabia/v1/license/validate'],
        ];
        foreach ($pairs as [$a, $b]) {
            if ($u !== '' && strpos($u, $a) !== false) {
                $candidates[] = str_replace($a, $b, $u);
            }
        }

        $candidates[] = 'https://xabia.ai/api/xabia/v1/license/validate';
        $candidates[] = 'https://xabia.ai/api/index.php/xabia/v1/license/validate';
        $candidates[] = 'https://xabia.ai/api/public/index.php/xabia/v1/license/validate';
        $candidates[] = 'https://xabia.ai/api-xabia-saas/public/index.php/xabia/v1/license/validate';
        $candidates[] = 'https://xabia.ai/api-xabia-saas/xabia/v1/license/validate';

        return array_values(array_unique(array_filter($candidates, static fn ($v) => is_string($v) && $v !== '')));
    }

    /**
     * Si hay licencia pero el caché no tiene saldo del hub, valida una vez para rellenar tokens_remaining (xabia_wallets en el hub).
     */
    public static function refresh_license_meta_from_hub_if_stale(): void {
        if (!self::is_license_configured()) {
            return;
        }
        $m = self::get_cached_license_meta();
        if (is_array($m)) {
            $has_tokens = array_key_exists('tokens_remaining', $m)
                && $m['tokens_remaining'] !== null
                && $m['tokens_remaining'] !== '';
            $is_valid = !empty($m['valid']);
            $checked_at = isset($m['checked_at']) && is_numeric($m['checked_at']) ? (int) $m['checked_at'] : 0;
            $age = $checked_at > 0 ? (time() - $checked_at) : PHP_INT_MAX;

            // Cache con licencia activa y saldo presente: respetar hasta 12h.
            if ($has_tokens && $is_valid && $age < (12 * HOUR_IN_SECONDS)) {
                return;
            }

            // Cache marcada como inactiva: revalidar antes (cada 10 min) para evitar falsos negativos persistentes.
            if (!$is_valid && $age < (10 * MINUTE_IN_SECONDS)) {
                return;
            }
        }
        self::validate_license_remote();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get_cached_license_meta(): ?array {
        $m = get_transient(self::TRANSIENT_META);
        return is_array($m) ? $m : null;
    }

    /**
     * Informa consumo de tokens al hub; si la respuesta incluye saldo, actualiza el transiente.
     *
     * @param array<string, mixed> $payload
     */
    public static function report_usage(array $payload): void {
        if (!self::is_license_configured()) {
            return;
        }
        $pt = (int) ($payload['prompt_tokens'] ?? 0);
        $ct = (int) ($payload['completion_tokens'] ?? 0);
        $total = (int) ($payload['total_tokens'] ?? 0);
        if ($total < 1) {
            $total = $pt + $ct;
        }
        if ($total < 1 && empty($payload['force'])) {
            return;
        }
        $url = (string) apply_filters('xabia_digixop_usage_report_url', self::default_usage_report_url(), $payload);
        $base_report = [
            'license_key'        => self::get_license_key(),
            'site_url'           => self::get_client_source_url(),
            'plugin_version'     => defined('XABIA_VERSION') ? (string) XABIA_VERSION : '',
            'prompt_tokens'      => $pt,
            'completion_tokens'  => $ct,
            'total_tokens'       => $total,
            'context'            => sanitize_key((string) ($payload['context'] ?? 'chat')),
            'project_id'         => sanitize_text_field((string) ($payload['project_id'] ?? '')),
        ];
        $body = apply_filters('xabia_digixop_usage_report_body', array_merge($payload, $base_report), $url);
        $headers = apply_filters('xabia_digixop_usage_report_headers', self::default_hub_headers(), $url, $body);
        if (!is_array($headers)) {
            $headers = self::default_hub_headers();
        }
        $args = apply_filters(
            'xabia_digixop_usage_http_args',
            [
                'headers'  => $headers,
                'body'     => wp_json_encode($body),
                'timeout'  => 8,
                'blocking' => true,
            ],
            $url,
            $body
        );
        $resp = wp_remote_post($url, is_array($args) ? $args : []);
        if (!is_wp_error($resp)) {
            $raw = (string) wp_remote_retrieve_body($resp);
            $json = json_decode($raw, true);
            if (is_array($json)) {
                self::merge_license_meta_from_api_payload($json);
            }
        }
        do_action('xabia_digixop_usage_reported', $body, $url);
    }
}
