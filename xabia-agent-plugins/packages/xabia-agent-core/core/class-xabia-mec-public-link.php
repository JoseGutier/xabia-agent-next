<?php
/**
 * Permalinks públicos de eventos MEC (sync RAG y respuestas del chat).
 *
 * Catálogo remoto: la URL pública es la del sitio fuente (mec_remote_site_url),
 * nunca el home del agente (p. ej. xabia.ai hospedando la demo de bilbaobizkaiapride.com).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_MEC_Public_Link {

    /**
     * Solo la URL configurada en el proyecto (sin fallback a home_url).
     *
     * @param array<string, mixed>|null $cfg
     */
    public static function configured_remote_site_url(?array $cfg): string {
        if (!is_array($cfg)) {
            return '';
        }
        $raw = isset($cfg['rules']['mec_remote_site_url'])
            ? trim((string) $cfg['rules']['mec_remote_site_url'])
            : '';
        if ($raw === '') {
            return '';
        }

        return untrailingslashit(esc_url_raw($raw));
    }

    /**
     * Base pública para construir permalinks MEC.
     * Remoto: solo mec_remote_site_url (vacío si falta, para no inventar el host del agente).
     * Local: home_url.
     *
     * @param array<string, mixed>|null $cfg
     */
    public static function get_remote_site_url(?array $cfg): string {
        $configured = self::configured_remote_site_url($cfg);
        if ($configured !== '') {
            return $configured;
        }
        if (self::is_remote_catalog($cfg)) {
            return '';
        }
        if (function_exists('home_url')) {
            return untrailingslashit(home_url('/'));
        }

        return '';
    }

    /**
     * @param array<string, mixed>|null $cfg
     */
    public static function is_remote_catalog(?array $cfg): bool {
        if (!is_array($cfg)) {
            return false;
        }
        if (function_exists('xabia_mec_is_remote_catalog')) {
            return (bool) xabia_mec_is_remote_catalog($cfg);
        }
        if (($cfg['source_type'] ?? '') === 'addon' && ($cfg['addon_slug'] ?? '') === 'mec') {
            return trim((string) (($cfg['sql_config']['host'] ?? '') ?: '')) !== ''
                || self::configured_remote_site_url($cfg) !== '';
        }
        if (($cfg['source_type'] ?? '') === 'sql' && ($cfg['sql_preset'] ?? '') === 'mec_remote') {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $cfg
     */
    public static function get_events_rewrite_slug(?array $cfg): string {
        if (!is_array($cfg)) {
            return 'actividades';
        }
        $slug = isset($cfg['rules']['mec_events_rewrite_slug'])
            ? trim((string) $cfg['rules']['mec_events_rewrite_slug'])
            : '';
        if ($slug !== '') {
            return trim($slug, '/');
        }

        return 'actividades';
    }

    /**
     * @param array<string, mixed>|null $cfg
     */
    public static function project_uses_mec_catalog(?array $cfg, array $mapping = []): bool {
        if (!is_array($cfg)) {
            return false;
        }
        if (($cfg['source_type'] ?? '') === 'addon' && ($cfg['addon_slug'] ?? '') === 'mec') {
            return true;
        }
        if (($cfg['source_type'] ?? '') === 'sql' && ($cfg['sql_preset'] ?? '') === 'mec_remote') {
            return true;
        }
        foreach ($mapping as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $col = (string) ($attr['csv_col'] ?? '');
            if (in_array($col, ['mec_available_slots', 'Evento'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $cfg
     */
    public static function resolve(int $post_id, ?array $cfg = null, string $post_slug = ''): string {
        if ($post_id < 1) {
            return '';
        }

        $post_slug = trim($post_slug);
        $is_remote = self::is_remote_catalog($cfg);
        $post_type = function_exists('get_post_type') ? (string) get_post_type($post_id) : '';

        if (!$is_remote) {
            if ($post_type === 'product' || ($post_type !== '' && $post_type !== 'mec-events')) {
                if (function_exists('get_permalink')) {
                    $url = get_permalink($post_id);

                    return is_string($url) && $url !== '' ? $url : '';
                }

                return '';
            }
            if ($post_type === 'mec-events' && function_exists('get_permalink')) {
                $url = get_permalink($post_id);
                if (is_string($url) && $url !== '') {
                    return self::apply_reservation_url_filter($url, $post_id);
                }
            }
        }

        if ($post_slug === '' && !$is_remote && function_exists('get_post_field')) {
            $maybe = get_post_field('post_name', $post_id);
            if (is_string($maybe) && $maybe !== '') {
                $post_slug = $maybe;
            }
        }

        $base = self::get_remote_site_url($cfg);
        if ($base !== '' && $post_slug !== '') {
            $rewrite = self::get_events_rewrite_slug($cfg);

            return self::apply_reservation_url_filter(
                $base . '/' . $rewrite . '/' . trim($post_slug, '/') . '/',
                $post_id
            );
        }

        return '';
    }

    /**
     * Resuelve URL de ficha/reserva para un ID (local o remoto) usando knowledge si hace falta.
     */
    public static function resolve_for_project(string $project_id, int $post_id): string {
        $project_id = sanitize_key($project_id);
        if ($project_id === '' || $post_id < 1) {
            return '';
        }
        $projects = get_option('xabia_projects_config', []);
        $cfg = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];
        $from_kb = self::find_event_refs_in_knowledge($project_id, $post_id);
        $link = trim((string) ($from_kb['link'] ?? ''));
        if ($link !== '') {
            $fixed = self::fix_url($link, $cfg);

            return $fixed !== '' ? $fixed : $link;
        }
        $slug = trim((string) ($from_kb['slug'] ?? ''));

        return self::resolve($post_id, $cfg, $slug);
    }

    /**
     * @return array{link:string,slug:string}
     */
    public static function find_event_refs_in_knowledge(string $project_id, int $event_id): array {
        $out = ['link' => '', 'slug' => ''];
        if (!class_exists('Xabia_DB', false) || $event_id < 1) {
            return $out;
        }
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return $out;
        }
        $table = Xabia_DB::table('knowledge_vectors');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return $out;
        }
        $meta_col = Xabia_DB::knowledge_vectors_meta_column();
        $sid = (string) $event_id;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT content_chunk, `{$meta_col}` AS meta_blob, source_record_id, ente_id
                 FROM {$table}
                 WHERE project_id = %s AND (source_record_id = %s OR ente_id = %s)
                 ORDER BY id DESC
                 LIMIT 1",
                sanitize_key($project_id),
                $sid,
                $sid
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            return $out;
        }
        $meta = [];
        if (!empty($row['meta_blob'])) {
            $decoded = json_decode((string) $row['meta_blob'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        foreach (['Link', 'link', 'url', 'URL'] as $k) {
            if (!empty($meta[$k]) && is_string($meta[$k]) && trim($meta[$k]) !== '') {
                $out['link'] = trim($meta[$k]);
                break;
            }
        }
        foreach (['post_slug', 'post_name', 'slug'] as $k) {
            if (!empty($meta[$k]) && is_string($meta[$k]) && trim($meta[$k]) !== '') {
                $out['slug'] = trim($meta[$k]);
                break;
            }
        }
        if ($out['link'] === '' && !empty($row['content_chunk'])) {
            if (preg_match('/^Link:\s*(\S+)/mi', (string) $row['content_chunk'], $m)
                || preg_match('/^-\s*Link:\s*(\S+)/mi', (string) $row['content_chunk'], $m)
            ) {
                $out['link'] = trim($m[1]);
            }
        }
        if ($out['slug'] === '' && !empty($row['content_chunk'])) {
            if (preg_match('/^post_slug:\s*(\S+)/mi', (string) $row['content_chunk'], $m)
                || preg_match('/^-\s*post_slug:\s*(\S+)/mi', (string) $row['content_chunk'], $m)
            ) {
                $out['slug'] = trim($m[1]);
            }
        }

        return $out;
    }

    public static function link_needs_fix(string $url): bool {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return true;
        }
        if (stripos($url, 'post_type=mec-events') !== false) {
            return true;
        }
        if (preg_match('#\?(?:p=|post_type=)#', $url)) {
            return true;
        }
        if (preg_match('~(?:^|[?&])(?:amp;|#038;|&#038;)p=\d+~', $url)) {
            return true;
        }

        return false;
    }

    public static function extract_post_id_from_url(string $url): int {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return 0;
        }
        if (preg_match('/(?:^|[?&#])(?:amp;|#038;|&#038;)?p=(\d+)/', $url, $m)) {
            return absint($m[1]);
        }

        return 0;
    }

    /**
     * Si el enlace apunta al host del agente pero el catálogo es remoto, reescribe al sitio fuente.
     *
     * @param array<string, mixed>|null $cfg
     */
    public static function rewrite_local_host_to_remote(string $url, ?array $cfg): string {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $remote = self::configured_remote_site_url($cfg);
        if ($url === '' || $remote === '') {
            return $url;
        }
        $parsed = wp_parse_url($url);
        $remote_host = wp_parse_url($remote, PHP_URL_HOST);
        if (!is_array($parsed) || empty($parsed['host']) || !is_string($remote_host) || $remote_host === '') {
            return $url;
        }
        if (strcasecmp((string) $parsed['host'], $remote_host) === 0) {
            return $url;
        }
        $local_host = function_exists('home_url') ? wp_parse_url(home_url('/'), PHP_URL_HOST) : '';
        if (!is_string($local_host) || $local_host === '' || strcasecmp((string) $parsed['host'], $local_host) !== 0) {
            return $url;
        }
        $path = (string) ($parsed['path'] ?? '/');
        $out = untrailingslashit($remote) . ($path !== '' ? $path : '/');
        if (!empty($parsed['query'])) {
            $out .= '?' . $parsed['query'];
        }
        if (!empty($parsed['fragment'])) {
            $out .= '#' . $parsed['fragment'];
        }

        return $out;
    }

    public static function fix_url(string $url, ?array $cfg = null): string {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return $url;
        }
        $rewritten = self::rewrite_local_host_to_remote($url, $cfg);
        if ($rewritten !== $url) {
            return $rewritten;
        }
        if (!self::link_needs_fix($url)) {
            return $url;
        }
        $post_id = self::extract_post_id_from_url($url);
        if ($post_id < 1) {
            return $url;
        }
        if (!self::is_remote_catalog($cfg)) {
            $post_type = function_exists('get_post_type') ? (string) get_post_type($post_id) : '';
            if ($post_type !== '' && $post_type !== 'mec-events') {
                if (function_exists('get_permalink')) {
                    $permalink = get_permalink($post_id);
                    if (is_string($permalink) && $permalink !== '') {
                        return $permalink;
                    }
                }

                return $url;
            }
        }
        $resolved = self::resolve($post_id, $cfg);
        if ($resolved === '') {
            return $url;
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $mapping
     * @return array<string, mixed>
     */
    public static function enrich_row(array $row, string $project_id, array $mapping): array {
        $projects = get_option('xabia_projects_config', []);
        $cfg = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];

        $pid = absint($row['ID'] ?? $row['id'] ?? 0);
        if ($pid < 1) {
            return $row;
        }

        $post_type = function_exists('get_post_type') ? (string) get_post_type($pid) : '';
        if ($post_type === 'product') {
            return $row;
        }

        $is_mec_row = self::project_uses_mec_catalog($cfg, $mapping)
            || $post_type === 'mec-events'
            || isset($row['Evento']);

        if (!$is_mec_row) {
            return $row;
        }

        $wants_link = false;
        foreach ($mapping as $m) {
            if (is_array($m) && ($m['csv_col'] ?? '') === 'Link') {
                $wants_link = true;
                break;
            }
        }
        if (!$wants_link && !isset($row['Link'])) {
            return $row;
        }

        $current = trim((string) ($row['Link'] ?? ''));
        if ($current !== '') {
            $fixed = self::fix_url($current, $cfg);
            if ($fixed !== '' && $fixed !== $current) {
                $row['Link'] = $fixed;

                return $row;
            }
            if ($current !== '' && !self::link_needs_fix($current) && self::rewrite_local_host_to_remote($current, $cfg) === $current) {
                return $row;
            }
        }

        $post_slug = trim((string) ($row['post_slug'] ?? ''));
        $resolved = self::resolve($pid, $cfg, $post_slug);
        if ($resolved !== '') {
            $row['Link'] = $resolved;
        }

        return $row;
    }

    private static function apply_reservation_url_filter(string $url, int $post_id): string {
        return (string) apply_filters('xabia_mec_event_reservation_url', $url, $post_id);
    }
}

add_filter(
    'xabia_knowledge_sync_enrich_row',
    static function ($row, $project_id, $mapping) {
        if (!is_array($row)) {
            return $row;
        }

        return Xabia_MEC_Public_Link::enrich_row($row, (string) $project_id, is_array($mapping) ? $mapping : []);
    },
    12,
    3
);
