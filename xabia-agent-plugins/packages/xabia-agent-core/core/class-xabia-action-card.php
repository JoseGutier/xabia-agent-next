<?php
/**
 * Ficha rica agnóstica en chat: [ACTION:CARD:payload].
 *
 * Payload = base64url(JSON) con claves cortas. Los alias largos se aceptan
 * en normalize()/encode() y se guardan en forma corta:
 * Contrato v2 (claves cortas):
 * - e / id / entity_id / post_id: identificador de entidad
 * - t / title: título
 * - i / image / img: URL imagen
 * - b / badge: categoría o estado (nunca precio)
 * - p / price: precio monetario formateado (vacío si no aplica)
 * - d / description: extracto (máx. 180)
 * - u / permalink / url: URL interna WordPress
 * - x / external_url: web externa opcional (contacto oficial)
 * - m / meta: línea de tags secundarios (· separados)
 * - a / action: CART:… | BOOK:… | CALL:… | MAP:…
 * - at / action_type: view | cart | book | event | link (legacy)
 * - l / action_text / label: etiqueta del botón
 * - cu / cart_url, ck / checkout_url, rd / redirect_behavior, mx / max_qty
 *
 * Retrocompat v1: highlight en p que no sea divisa → b en decode().
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
     * @return array{e:string,t:string,b:string,m:string,d:string,p:string,i:string,a:string,at:string,l:string,u:string,x:string,cu:string,ck:string,rd:string,mx:string}
     */
    public static function normalize(array $fields): array {
        $t = self::clean_field(self::first_value($fields, ['t', 'title']));
        $m = self::clean_field(self::first_value($fields, ['m', 'meta']));
        $d = self::clean_description(self::first_value($fields, ['d', 'description']));
        $b = self::clean_field(self::first_value($fields, ['b', 'badge']));
        $p = self::clean_field(self::first_value($fields, ['p', 'price']));
        $highlight = self::clean_field(self::first_value($fields, ['highlight']));
        if ($p === '' && $highlight !== '') {
            if (self::highlight_is_price($highlight)) {
                $p = $highlight;
            } elseif ($b === '') {
                $b = $highlight;
            }
        }
        $i = self::clean_url(self::first_value($fields, ['i', 'image', 'img', 'imagen', 'imagen_url', 'Imagen_URL']));
        $l = self::clean_field(self::first_value($fields, ['l', 'action_text', 'label']));
        $u = self::clean_url(self::first_value($fields, ['u', 'url', 'permalink']));
        $x = self::clean_url(self::first_value($fields, ['x', 'external_url']));
        $cu = self::clean_url(self::first_value($fields, ['cu', 'cart_url']));
        $ck = self::clean_url(self::first_value($fields, ['ck', 'checkout_url']));
        $rd = self::clean_flag(self::first_value($fields, ['rd', 'redirect_behavior']));
        $mx = self::clean_qty(self::first_value($fields, ['mx', 'max_qty']));
        $a = self::clean_action(self::first_value($fields, ['a', 'action']));
        $e = trim(self::first_value($fields, ['e', 'id', 'entity_id', 'post_id']));
        if ($e === '' && isset($fields['product_id'])) {
            $e = trim((string) (int) $fields['product_id']);
        }
        if ($e === '' && preg_match('/^CART:(\d+)/i', $a, $cm)) {
            $e = (string) (int) ($cm[1] ?? 0);
        }
        if ($e !== '' && (int) $e < 1) {
            $e = '';
        }
        $explicit = strtolower(trim(self::first_value($fields, ['at', 'action_type'])));
        if ($explicit === 'link') {
            $explicit = 'view';
        }
        $allowed_at = ['cart', 'book', 'event', 'view', 'link'];
        if ($explicit !== '' && !in_array($explicit, $allowed_at, true)) {
            $explicit = '';
        }
        if ($a === '' && $explicit === 'cart') {
            $id = (int) preg_replace('/\D+/', '', (string) ($fields['id'] ?? $fields['product_id'] ?? $e));
            if ($id > 0) {
                $a = self::clean_action('CART:' . $id);
                if ($e === '') {
                    $e = (string) $id;
                }
            }
        }
        if (preg_match('/^URL:(.+)$/i', $a, $um)) {
            $url_action = self::clean_url($um[1]);
            if ($url_action !== '') {
                if ($x === '' && ($u === '' || !self::urls_same_host_path($u, $url_action))) {
                    $x = $url_action;
                } elseif ($x === '' && $u === '') {
                    $x = $url_action;
                }
            }
            if ($explicit !== 'cart' && $explicit !== 'book') {
                $a = '';
            }
        }
        $at = self::derive_action_type($a, $explicit, $u);
        if ($at === 'cart' && !preg_match('/^CART:\d+/i', $a)) {
            $a = '';
            $at = '';
        }
        if ($at === 'view' && $u === '' && $x === '') {
            $at = '';
        }
        if ($at === 'link' && !preg_match('/^(BOOK|CALL|MAP):/i', $a)) {
            $at = '';
            if ($a !== '' && preg_match('/^URL:/i', $a)) {
                $a = '';
            }
        }

        return [
            'e'  => $e,
            't'  => $t,
            'b'  => $b,
            'm'  => $m,
            'd'  => $d,
            'p'  => $p,
            'i'  => $i,
            'a'  => $a,
            'at' => $at,
            'l'  => $l,
            'u'  => $u,
            'x'  => $x,
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
     * Elimina marcadores [ACTION(-)CARD:…] inventados por el LLM (post-respuesta, pre-enrich).
     */
    public static function strip_llm_hallucinated_action_markers(string $content): string {
        if ($content === '') {
            return $content;
        }
        $content = preg_replace('/\[ACTION[-:\s]*CARD:[^\]]+\]/i', '', $content) ?? $content;
        $content = preg_replace('/(?:^|\n)\s*[*•\-]\s*\[ACTION[-:\s]*CARD:[^\]]+\]\s*/iu', "\n", $content) ?? $content;
        $content = preg_replace('/(?:^|\n)\s*•\s*[^*\n\[]+\]\s*(?=\n|$)/u', "\n", $content) ?? $content;
        $content = preg_replace("/\n{3,}/", "\n\n", $content) ?? $content;

        return trim($content);
    }

    /**
     * Rellena precio e imagen vacíos de cada [ACTION:CARD] con la ficha genérica del contexto.
     * Solo usa [Dato clave] cuando es un precio, y [Imagen disponible] cuando la hay.
     * No interpreta el origen (tienda, evento, alojamiento o servicio).
     */
    public static function enrich_response_cards(string $response, string $context): string {
        if ($response === '' || strpos($response, '[ACTION:CARD:') === false || $context === '') {
            return $response;
        }
        $index = self::entity_contract_index($context);
        if ($index === []) {
            return $response;
        }
        $out = preg_replace_callback(
            '/\[ACTION:CARD:([^\]]+)\]/u',
            static function (array $m) use ($index): string {
                $decoded = self::decode((string) ($m[1] ?? ''));
                if (!is_array($decoded)) {
                    return (string) ($m[0] ?? '');
                }
                $hit = self::match_entity_contract((string) ($decoded['t'] ?? ''), $index);
                if ($hit === null) {
                    return (string) ($m[0] ?? '');
                }
                $changed = false;
                if (($decoded['p'] ?? '') === '' && $hit['price'] !== '') {
                    $decoded['p'] = $hit['price'];
                    $changed = true;
                }
                if (($decoded['i'] ?? '') === '' && $hit['img'] !== '') {
                    $decoded['i'] = $hit['img'];
                    $changed = true;
                }
                if (($decoded['u'] ?? '') === '' && ($hit['url'] ?? '') !== '') {
                    $decoded['u'] = $hit['url'];
                    $changed = true;
                }
                $hit_ext = trim((string) ($hit['url_ext'] ?? ''));
                $decoded_u = trim((string) ($decoded['u'] ?? ''));
                if ($hit_ext !== '' && ($decoded['x'] ?? '') === ''
                    && ($decoded_u === '' || strcasecmp(rtrim($hit_ext, '/'), rtrim($decoded_u, '/')) !== 0)
                ) {
                    $decoded['x'] = $hit_ext;
                    $changed = true;
                }
                if (!$changed) {
                    return (string) ($m[0] ?? '');
                }
                $tag = self::encode($decoded);
                return $tag !== '' ? $tag : (string) ($m[0] ?? '');
            },
            $response
        );

        return is_string($out) ? $out : $response;
    }

    /**
     * @return list<array{title:string,title_n:string,price:string,img:string,url:string}>
     */
    private static function entity_contract_index(string $context): array {
        $parts = preg_split('/(?=\[Entidad:)/u', $context) ?: [];
        $out = [];
        foreach ($parts as $part) {
            if (!preg_match('/\[Entidad:\s*([^\]]+)\]/u', (string) $part, $tm)) {
                continue;
            }
            $title = trim((string) ($tm[1] ?? ''));
            if ($title === '') {
                continue;
            }
            $price = '';
            if (preg_match('/\[Dato clave:\s*([^\]]+)\]/u', (string) $part, $hm)) {
                $highlight = trim((string) ($hm[1] ?? ''));
                if (self::highlight_is_price($highlight)) {
                    $price = $highlight;
                }
            }
            $img = '';
            if (preg_match('/\[Imagen disponible:\s*(https?:\/\/[^\s\]]+)\s*\]/iu', (string) $part, $im)) {
                $img = trim((string) ($im[1] ?? ''));
            }
            $url = '';
            if (preg_match('/\[Enlace:\s*(https?:\/\/[^\s\]]+)\s*\]/iu', (string) $part, $um)) {
                $url = trim((string) ($um[1] ?? ''));
            }
            $url_ext = '';
            if (preg_match('/\[Enlace web:\s*(https?:\/\/[^\s\]]+)\s*\]/iu', (string) $part, $wm)) {
                $url_ext = trim((string) ($wm[1] ?? ''));
            }
            if ($price === '' && $img === '' && $url === '' && $url_ext === '') {
                continue;
            }
            $out[] = [
                'title'   => $title,
                'title_n' => self::fold_match_title($title),
                'price'   => $price,
                'img'     => $img,
                'url'     => $url,
                'url_ext' => $url_ext,
            ];
        }

        return $out;
    }

    /**
     * @param list<array{title:string,title_n:string,price:string,img:string,url:string}> $index
     * @return array{title:string,title_n:string,price:string,img:string,url:string}|null
     */
    private static function match_entity_contract(string $title, array $index): ?array {
        $needle = self::fold_match_title($title);
        if ($needle === '') {
            return null;
        }
        $best = null;
        $best_len = 0;
        foreach ($index as $row) {
            $hay = (string) ($row['title_n'] ?? '');
            if ($hay === '') {
                continue;
            }
            $ok = $hay === $needle;
            if (!$ok) {
                $hay_len = function_exists('mb_strlen') ? mb_strlen($hay, 'UTF-8') : strlen($hay);
                $needle_len = function_exists('mb_strlen') ? mb_strlen($needle, 'UTF-8') : strlen($needle);
                $ok = min($hay_len, $needle_len) >= 6 && (strpos($hay, $needle) !== false || strpos($needle, $hay) !== false);
            }
            if (!$ok) {
                continue;
            }
            $len = function_exists('mb_strlen') ? mb_strlen($hay, 'UTF-8') : strlen($hay);
            if ($best === null || $len > $best_len) {
                $best = $row;
                $best_len = $len;
            }
        }

        return $best;
    }

    public static function highlight_is_price_public(string $value): bool {
        return self::highlight_is_price($value);
    }

    private static function highlight_is_price(string $value): bool {
        $s = trim($value);
        if ($s === '' || !preg_match('/\d/u', $s)) {
            return false;
        }
        if (preg_match('/\d{4}-\d{2}-\d{2}|\d{1,2}:\d{2}\b/u', $s) && !preg_match('/[€$£]|eur/iu', $s)) {
            return false;
        }
        if (preg_match('/\b(?:enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre|lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bado|domingo)\b/iu', $s)) {
            return false;
        }
        if (preg_match('/[€$£]|eur|usd|gbp/iu', $s)) {
            return true;
        }

        return (bool) preg_match('/^\d{1,6}(?:[.,]\d{1,2})?(?:\s*\/\s*\p{L}+)?$/u', $s);
    }

    private static function fold_match_title(string $title): string {
        $s = trim(wp_strip_all_tags($title));
        if (function_exists('remove_accents')) {
            $s = remove_accents($s);
        } else {
            $s = strtr($s, [
                'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
                'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            ]);
        }
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return trim((string) $s);
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
        $out = self::apply_v1_payload_compat($out);
        if ($out['t'] === '') {
            return null;
        }

        return $out;
    }

    /**
     * Payload v1: p a veces llevaba badge/categoría en lugar de precio.
     *
     * @param array{e:string,t:string,b:string,m:string,d:string,p:string,i:string,a:string,at:string,l:string,u:string,x:string,cu:string,ck:string,rd:string,mx:string} $out
     * @return array{e:string,t:string,b:string,m:string,d:string,p:string,i:string,a:string,at:string,l:string,u:string,x:string,cu:string,ck:string,rd:string,mx:string}
     */
    private static function apply_v1_payload_compat(array $out): array {
        if (($out['b'] ?? '') === '' && ($out['p'] ?? '') !== '' && !self::highlight_is_price((string) $out['p'])) {
            $out['b'] = (string) $out['p'];
            $out['p'] = '';
        }

        return $out;
    }

    private static function derive_action_type(string $action, string $explicit, string $permalink = ''): string {
        if ($explicit === 'view' || $explicit === 'cart' || $explicit === 'book' || $explicit === 'event') {
            return $explicit;
        }
        if (preg_match('/^CART:\d+/i', $action)) {
            return 'cart';
        }
        if (preg_match('/^BOOK:/i', $action)) {
            return 'book';
        }
        if (preg_match('/^EVENT:/i', $action)) {
            return 'event';
        }
        if (preg_match('/^(CALL|MAP):/i', $action)) {
            return 'link';
        }
        if ($permalink !== '' && !preg_match('/^CART:/i', $action)) {
            return 'view';
        }

        return '';
    }

    private static function urls_same_host_path(string $a, string $b): bool {
        $a = trim($a);
        $b = trim($b);
        if ($a === '' || $b === '') {
            return false;
        }

        return strcasecmp(rtrim($a, '/'), rtrim($b, '/')) === 0;
    }

    private static function clean_description(string $v): string {
        $s = self::clean_field($v);
        if ($s === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($s, 0, 180, 'UTF-8') : substr($s, 0, 180);
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
