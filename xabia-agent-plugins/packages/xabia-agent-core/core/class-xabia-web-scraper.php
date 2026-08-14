<?php
/**
 * Scraping superficial de HTML público (motor compartido con la demo xabia.ai).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Web_Scraper {

    /**
     * Extrae texto útil de una URL pública http(s).
     *
     * @return array{title:string,description:string,paragraphs:array<int,string>}|WP_Error
     */
    public static function scrape_url(string $url) {
        $url = esc_url_raw(trim($url));
        if ($url === '' || !self::is_public_http_url($url)) {
            return new WP_Error('bad_url', __('URL pública no válida.', 'xabia-intelligence'));
        }

        $response = wp_remote_get($url, [
            'timeout'             => 12,
            'redirection'         => 3,
            'user-agent'          => 'XabiaWebSource/1.0 (+https://xabia.ai)',
            'headers'             => ['Accept' => 'text/html'],
            'limit_response_size' => 900000,
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('fetch_failed', __('No se pudo descargar la página.', 'xabia-intelligence'));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) {
            return new WP_Error('bad_status', sprintf(__('HTTP %d al leer la página.', 'xabia-intelligence'), $code));
        }

        $html = (string) wp_remote_retrieve_body($response);
        if ($html === '') {
            return new WP_Error('empty', __('La URL no devolvió contenido.', 'xabia-intelligence'));
        }

        $html = preg_replace('#<(script|style|noscript|svg|iframe)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(header|footer|nav)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;

        $title = '';
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $title = self::clean_text($m[1]);
        }

        $description = '';
        if (preg_match('#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m)) {
            $description = self::clean_text($m[1]);
        }

        $paragraphs = [];
        if (preg_match_all('#<p[^>]*>(.*?)</p>#is', $html, $matches)) {
            foreach ($matches[1] as $p) {
                $t = self::clean_text($p);
                if (strlen($t) < 30) {
                    continue;
                }
                $paragraphs[] = $t;
                if (count($paragraphs) >= 24) {
                    break;
                }
            }
        }

        if ($title === '' && $paragraphs === []) {
            return new WP_Error('no_text', __('No se encontró texto útil en la página.', 'xabia-intelligence'));
        }

        return [
            'title'       => $title,
            'description' => $description,
            'paragraphs'  => array_values(array_unique($paragraphs)),
        ];
    }

    public static function paragraphs_to_body(array $paragraphs, int $max_chars = 8000): string {
        $body = implode("\n\n", $paragraphs);
        $body = preg_replace('/\s+/u', ' ', $body) ?? $body;
        $body = trim($body);
        if (function_exists('mb_strlen') && mb_strlen($body) > $max_chars) {
            return (string) mb_substr($body, 0, $max_chars);
        }
        if (strlen($body) > $max_chars) {
            return substr($body, 0, $max_chars);
        }

        return $body;
    }

    public static function is_public_http_url(string $url): bool {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return false;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $host = strtolower((string) $parts['host']);
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }
        if (preg_match('/\.local$|\.internal$|\.localhost$/i', $host)) {
            return false;
        }
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $resolved = gethostbynamel($host);
            if (is_array($resolved)) {
                $ips = $resolved;
            }
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    private static function clean_text(string $html): string {
        $text = wp_strip_all_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
