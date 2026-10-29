<?php
/**
 * Ficha rica agnóstica en chat: [ACTION:CARD:payload].
 *
 * Payload = base64url(JSON) con claves cortas. Los alias largos se aceptan
 * en normalize()/encode() y se guardan en forma corta:
 * - t / title: título
 * - m / meta: badge, categoría o ubicación (· separados)
 * - d / description: extracto. Si falta, un payload antiguo puede traer el texto en m
 * - p / highlight / price: precio, precio/noche o fecha
 * - i / image / img: URL imagen
 * - a / action: CART:123 | CART_PACK:1,2 | URL:https://… | CALL:+34… | BOOK:… | MAP:…
 * - at / action_type: cart | link (derivado de a si no viene)
 * - l / action_text / label: etiqueta del botón
 * - u / url / permalink: ficha o destino
 * - cu / cart_url, ck / checkout_url, rd / redirect_behavior, mx / max_qty
 *
 * @package Xabia
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Action_Card {

    /**
     * Traduce alias largos a claves cortas y deriva action_type.
     *
     * @param array<string, mixed> $fields
     * @return array{t:string,m:string,d:string,p:string,i:string,a:string,at:string,l:string,u:string,cu:string,ck:string,rd:string,mx:string}
     */
    public static function normalize(array $fields): array {
        $t = self::clean_field(self::first_value($fields, ['t', 'title']));
        $m = self::clean_field(self::first_value($fields, ['m', 'meta']));
        $d = self::clean_field(self::first_value($fields, ['d', 'description']));
        $p = self::clean_field(self::first_value($fields, ['p', 'highlight', 'price']));
        $i = self::clean_url(self::first_value($fields, ['i', 'image', 'img']));
        $l = self::clean_field(self::first_value($fields, ['l', 'action_text', 'label']));
        $u = self::clean_url(self::first_value($fields, ['u', 'url', 'permalink']));
        $cu = self::clean_url(self::first_value($fields, ['cu', 'cart_url']));
        $ck = self::clean_url(self::first_value($fields, ['ck', 'checkout_url']));
        $rd = self::clean_flag(self::first_value($fields, ['rd', 'redirect_behavior']));
        $mx = self::clean_qty(self::first_value($fields, ['mx', 'max_qty']));
        $a = self::clean_action(self::first_value($fields, ['a', 'action']));
        $explicit = strtolower(trim(self::first_value($fields, ['at', 'action_type'])));
        if ($explicit !== 'cart' && $explicit !== 'link') {
            $explicit = '';
        }
        if ($a === '' && $explicit === 'cart') {
            $id = (int) preg_replace('/\D+/', '', (string) ($fields['id'] ?? $fields['product_id'] ?? ''));
            if ($id > 0) {
                $a = self::clean_action('CART:' . $id);
            }
        }
        if ($a === '' && $explicit === 'link' && $u !== '') {
            $a = self::clean_action('URL:' . $u);
        }
        $at = self::derive_action_type($a, $explicit);
        if ($at === 'cart' && !preg_match('/^CART:\d+/i', $a)) {
            $a = '';
            $at = '';
        }
        if ($at === 'link' && !preg_match('/^BOOK:/i', $a) && self::link_href($a, $u) === '') {
            $a = '';
            $at = '';
        }

        return [
            't'  => $t,
            'm'  => $m,
            'd'  => $d,
            'p'  => $p,
            'i'  => $i,
            'a'  => $a,
            'at' => $at,
            'l'  => $l,
            'u'  => $u,
            'cu' => $cu,
            'ck' => $ck,
            'rd' => $rd,
            'mx' => $mx,
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $context Datos opacos para los addons (source, project_id). El núcleo no los interpreta.
     */
    public static function encode(array $fields, array $context = []): string {
        $payload = self::normalize($fields);
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('xabia_card_payload', $payload, $context);
            if (is_array($filtered)) {
                $payload = self::normalize($filtered);
            }
        }
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
     * @return array{t:string,m:string,d:string,p:string,i:string,a:string,at:string,l:string,u:string,cu:string,ck:string,rd:string,mx:string}|null
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
        $out = self::normalize($data);
        if ($out['t'] === '') {
            return null;
        }

        return $out;
    }

    private static function derive_action_type(string $action, string $explicit): string {
        if (preg_match('/^CART:\d+/i', $action)) {
            return 'cart';
        }
        if (preg_match('/^(URL|BOOK):/i', $action)) {
            return 'link';
        }
        if ($explicit === 'cart' || $explicit === 'link') {
            return $explicit;
        }

        return '';
    }

    private static function link_href(string $action, string $permalink): string {
        if (preg_match('/^URL:(.+)$/i', $action, $m)) {
            return self::clean_url($m[1]);
        }

        return $permalink;
    }

    /**
     * Primera clave con valor no vacío. Así un filtro puede rellenar el alias largo
     * aunque la clave corta ya exista vacía.
     *
     * @param array<string, mixed> $fields
     * @param array<int, string> $keys
     */
    private static function first_value(array $fields, array $keys): string {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $value = $fields[$key];
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
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
