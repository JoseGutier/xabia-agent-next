<?php
/**
 * Scraper superficial LITE: páginas y entradas publicadas del propio WordPress.
 * Sin Hub, sin embeddings, sin RAG vectorial.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Lite_Scraper {

    public const OPTION_INDEX = 'xabia_lite_web_index';
    private const MAX_POSTS = 80;
    private const MAX_CHARS_PER_POST = 1200;
    private const MAX_TOTAL_CHARS = 24000;

    /**
     * Indexa contenido público local (pages + posts).
     *
     * @return array{ok:bool,pages:int,synced_at:int,message:string}
     */
    public static function index_local_site(): array {
        $query = new WP_Query([
            'post_type'              => ['page', 'post'],
            'post_status'            => 'publish',
            'posts_per_page'         => self::MAX_POSTS,
            'orderby'                => 'modified',
            'order'                  => 'DESC',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        $entries = [];
        $total_chars = 0;

        foreach ($query->posts as $post) {
            if (!($post instanceof WP_Post)) {
                continue;
            }

            $title = trim(wp_strip_all_tags(get_the_title($post)));
            $url = get_permalink($post);
            $excerpt = trim(wp_strip_all_tags((string) get_the_excerpt($post)));
            $content = trim(wp_strip_all_tags((string) $post->post_content));
            $body = $excerpt !== '' ? $excerpt : $content;
            $body = preg_replace('/\s+/u', ' ', $body ?? '') ?? '';
            if (function_exists('mb_substr')) {
                $body = mb_substr($body, 0, self::MAX_CHARS_PER_POST);
            } else {
                $body = substr($body, 0, self::MAX_CHARS_PER_POST);
            }

            if ($title === '' && $body === '') {
                continue;
            }

            $line = sprintf(
                'Title: %s | Type: %s | URL: %s | Content: %s',
                $title !== '' ? $title : '(sin título)',
                $post->post_type,
                is_string($url) ? $url : '',
                $body
            );

            $len = strlen($line);
            if ($total_chars + $len > self::MAX_TOTAL_CHARS) {
                break;
            }

            $entries[] = [
                'id'      => (int) $post->ID,
                'type'    => (string) $post->post_type,
                'title'   => $title,
                'url'     => is_string($url) ? $url : '',
                'excerpt' => $body,
            ];
            $total_chars += $len;
        }

        wp_reset_postdata();

        $synced_at = time();
        $payload = [
            'pages'     => count($entries),
            'synced_at' => $synced_at,
            'entries'   => $entries,
        ];

        update_option(self::OPTION_INDEX, $payload, false);

        Xabia_Mode::save_lite_settings([
            'web_pages_count' => count($entries),
            'web_synced_at'   => $synced_at,
        ]);

        return [
            'ok'        => true,
            'pages'     => count($entries),
            'synced_at' => $synced_at,
            'message'   => sprintf(
                /* translators: %d: number of indexed pages */
                __('Indexadas %d páginas/entradas públicas de esta web.', 'xabia-intelligence'),
                count($entries)
            ),
        ];
    }

    /**
     * @return array{pages:int,synced_at:int,entries:list<array<string,mixed>>}
     */
    public static function get_index(): array {
        $raw = get_option(self::OPTION_INDEX, []);
        if (!is_array($raw)) {
            $raw = [];
        }

        $entries = isset($raw['entries']) && is_array($raw['entries']) ? $raw['entries'] : [];

        return [
            'pages'     => isset($raw['pages']) ? max(0, (int) $raw['pages']) : count($entries),
            'synced_at' => isset($raw['synced_at']) ? max(0, (int) $raw['synced_at']) : 0,
            'entries'   => $entries,
        ];
    }

    /**
     * Bloque de texto plano para inyectar en el system prompt LITE.
     */
    public static function build_context_block(): string {
        $index = self::get_index();
        if ($index['entries'] === []) {
            return '';
        }

        $lines = [];
        foreach ($index['entries'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $title = isset($entry['title']) ? sanitize_text_field((string) $entry['title']) : '';
            $type = isset($entry['type']) ? sanitize_key((string) $entry['type']) : 'page';
            $url = isset($entry['url']) ? esc_url_raw((string) $entry['url']) : '';
            $excerpt = isset($entry['excerpt']) ? sanitize_text_field((string) $entry['excerpt']) : '';
            if ($title === '' && $excerpt === '') {
                continue;
            }
            $lines[] = sprintf(
                'Title: %s | Type: %s | URL: %s | Content: %s',
                $title !== '' ? $title : '(sin título)',
                $type,
                $url,
                $excerpt
            );
        }

        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines);
    }
}
