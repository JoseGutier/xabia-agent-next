<?php
if (!defined('ABSPATH')) exit;

/**
 * XabiaContext v2.0 PRO
 *
 * - Contexto aislado por usuario (browser session → hashed key)
 * - TTL configurable clave por clave
 * - Reset limpio
 * - 100% compatible con query, planner, explain, acciones...
 */
class XabiaContext
{
    const PREFIX = 'xabia_ctx_';
    const TTL    = 30; // minutos por defecto

    /**
     * Identificador único por usuario (browser)
     * Evita que todos los usuarios compartan el mismo contexto.
     */
   protected static function user_id(): string
{
    // REST / AJAX-safe: sin sesiones PHP
    if (is_user_logged_in()) {
        return 'user_' . get_current_user_id();
    }

    // Anónimo: cookie persistente
    if (empty($_COOKIE['xabia_uid'])) {
        $uid = wp_generate_uuid4();
        setcookie(
            'xabia_uid',
            $uid,
            time() + MONTH_IN_SECONDS,
            COOKIEPATH,
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );
        $_COOKIE['xabia_uid'] = $uid;
    }

    return sanitize_key($_COOKIE['xabia_uid']);
}

    /**
     * Clave completa en transients
     */
    protected static function full_key(string $key): string
    {
        return self::PREFIX . self::user_id() . '_' . sanitize_key($key);
    }

    /**
     * GET — Devuelve valor o default.
     */
    public static function get(string $key, $default = null)
    {
        $full = self::full_key($key);
        $val  = get_transient($full);

        return ($val === false) ? $default : $val;
    }

    /**
     * SET — Guarda valor con TTL en minutos.
     */
    public static function set(string $key, $value, int $minutes = self::TTL): void
    {
        $full = self::full_key($key);
        set_transient(
            $full,
            $value,
            $minutes * MINUTE_IN_SECONDS
        );
    }

    /**
     * CLEAR — Borra solo una clave.
     */
    public static function clear(string $key): void
    {
        delete_transient(self::full_key($key));
    }

    /**
     * RESET_ALL — elimina TODO el contexto de este usuario.
     */
    public static function reset_all(): void
    {
        global $wpdb;

        $uid  = self::user_id();
        $like = '_transient_' . self::PREFIX . $uid . '%';

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            )
        );
    }
    
    /**
     * CTA — devuelve payload estructurado si existe
     */
    public static function get_cta(): ?array
    {
        return self::get('cta_payload');
    }
}