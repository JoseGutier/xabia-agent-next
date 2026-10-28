<?php
/**
 * Ficha rica agnóstica en chat: [ACTION:CARD:payload].
 *
 * Payload = base64url(JSON) con claves cortas:
 * - t: título
 * - m: meta (atributos · separados)
 * - p: precio / highlight
 * - i: URL imagen
 * - a: acción CTA (CART:123 | CART_PACK:1,2 | URL:https://… | CALL:+34… | BOOK:…)
 * - l: etiqueta del botón
 * - u: permalink / ficha (opcional)
 * - cu: URL carrito (opcional)
 * - ck: URL checkout (opcional)
 * - rd: redirect tras add («1»/«0», opcional)
 * - mx: cantidad máxima (opcional)
 *
 * @package Xabia
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Action_Card {

    /**
     * @param array<string, mixed> $fields
     */
    public static function encode(array $fields): string {
        $payload = [
            't' => self::clean_field($fields['t'] ?? $fields['title'] ?? ''),
            'm' => self::clean_field($fields['m'] ?? $fields['meta'] ?? ''),
            'p' => self::clean_field($fields['p'] ?? $fields['price'] ?? ''),
            'i' => self::clean_url($fields['i'] ?? $fields['img'] ?? ''),
            'a' => self::clean_action($fields['a'] ?? $fields['action'] ?? ''),
            'l' => self::clean_field($fields['l'] ?? $fields['label'] ?? ''),
            'u' => self::clean_url($fields['u'] ?? $fields['permalink'] ?? ''),
            'cu' => self::clean_url($fields['cu'] ?? $fields['cart_url'] ?? ''),
            'ck' => self::clean_url($fields['ck'] ?? $fields['checkout_url'] ?? ''),
            'rd' => self::clean_flag($fields['rd'] ?? $fields['redirect_behavior'] ?? ''),
            'mx' => self::clean_qty($fields['mx'] ?? $fields['max_qty'] ?? ''),
        ];
        $payload = array_filter($payload, static function ($v) {
            return is_string($v) && $v !== '';
        });
        if ($payload === [] || empty($payload['t'])) {
            return '';
        }
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return '';
        }

        return '[ACTION:CARD:' . self::b64url_encode($json) . ']';
    }

    /**
     * @return array{t:string,m:string,p:string,i:string,a:string,l:string,u:string,cu:string,ck:string,rd:string,mx:string}|null
     */
    public static function decode(string $raw): ?array {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $json = self::b64url_decode($raw);
        if ($json === null || $json === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $out = [
            't'  => self::clean_field($data['t'] ?? $data['title'] ?? ''),
            'm'  => self::clean_field($data['m'] ?? $data['meta'] ?? ''),
            'p'  => self::clean_field($data['p'] ?? $data['price'] ?? ''),
            'i'  => self::clean_url($data['i'] ?? $data['img'] ?? ''),
            'a'  => self::clean_action($data['a'] ?? $data['action'] ?? ''),
            'l'  => self::clean_field($data['l'] ?? $data['label'] ?? ''),
            'u'  => self::clean_url($data['u'] ?? $data['permalink'] ?? ''),
            'cu' => self::clean_url($data['cu'] ?? $data['cart_url'] ?? ''),
            'ck' => self::clean_url($data['ck'] ?? $data['checkout_url'] ?? ''),
            'rd' => self::clean_flag($data['rd'] ?? $data['redirect_behavior'] ?? ''),
            'mx' => self::clean_qty($data['mx'] ?? $data['max_qty'] ?? ''),
        ];
        if ($out['t'] === '') {
            return null;
        }

        return $out;
    }

    private static function clean_field($v): string {
        $s = trim(wp_strip_all_tags((string) $v));
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        // Evitar que un ] rompa el tag ACTION.
        $s = str_replace(['[', ']'], ['(', ')'], $s);

        return function_exists('mb_substr') ? mb_substr($s, 0, 240, 'UTF-8') : substr($s, 0, 240);
    }

    private static function clean_url($v): string {
        $s = trim((string) $v);
        if ($s === '' || !preg_match('#^https?://#i', $s)) {
            return '';
        }
        $s = preg_replace('/[\s\[\]]+/u', '', $s) ?? $s;

        return function_exists('mb_substr') ? mb_substr($s, 0, 500, 'UTF-8') : substr($s, 0, 500);
    }

    private static function clean_action($v): string {
        $s = trim((string) $v);
        if ($s === '') {
            return '';
        }
        // CART:123 | CART_PACK:1,2|1,1 | URL:https://… | CALL:+34… | BOOK:id | MAP:query
        if (!preg_match('/^(CART|CART_PACK|URL|CALL|BOOK|MAP):/i', $s)) {
            return '';
        }
        $s = str_replace(['[', ']'], '', $s);

        return function_exists('mb_substr') ? mb_substr($s, 0, 600, 'UTF-8') : substr($s, 0, 600);
    }

    private static function clean_flag($v): string {
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        $s = strtolower(trim((string) $v));
        if ($s === '1' || $s === 'yes' || $s === 'true') {
            return '1';
        }
        if ($s === '0' || $s === 'no' || $s === 'false') {
            return '0';
        }

        return '';
    }

    private static function clean_qty($v): string {
        $n = (int) preg_replace('/\D+/', '', (string) $v);
        if ($n < 1) {
            return '';
        }

        return (string) min(99, $n);
    }

    private static function b64url_encode(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64url_decode(string $b64): ?string {
        $b64 = strtr($b64, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $bin = base64_decode($b64, true);
        if (!is_string($bin) || $bin === '') {
            return null;
        }

        return $bin;
    }
}
