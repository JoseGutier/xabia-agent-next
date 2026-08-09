<?php
/**
 * Desnormalización bidireccional de relaciones en Index-Time (sync).
 * El Hub solo recibe content_chunk ya enriquecido; no conoce el grafo.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Knowledge_Relations {

    private const MAX_TITLES = 20;

    /** @var array<string, array{forward: array<int, list<string>>, reverse: array<int, list<string>>, source_pt: string, connected_pt: string, warmed: bool}> */
    private static $caches = [];

    /**
     * @param array<string, mixed>|null $sql_config Connection + prefix (same as sync). Null = project sql_config.
     */
    public static function warm_for_project(string $project_id, $sql_config = null): void {
        $project_id = sanitize_text_field($project_id);
        if ($project_id === '') {
            return;
        }
        $projects = get_option('xabia_projects_config', []);
        $config = is_array($projects[$project_id] ?? null) ? $projects[$project_id] : [];
        $relations = self::normalize_relations_config($config['rules']['knowledge_relations'] ?? null);
        if ($relations === []) {
            self::$caches[$project_id] = [
                'forward'         => [],
                'reverse'         => [],
                'source_pt'       => '',
                'connected_pt'    => '',
                'source_label'    => '',
                'connected_label' => '',
                'catalog_pt'      => sanitize_key((string) ($config['catalog_post_type'] ?? '')),
                'warmed'          => true,
            ];

            return;
        }

        if (!is_array($sql_config) || $sql_config === []) {
            $sql_config = is_array($config['sql_config'] ?? null) ? $config['sql_config'] : [];
        }

        $forward = [];
        $reverse = [];
        $source_pt = '';
        $connected_pt = '';

        foreach ($relations as $map) {
            $source_pt = (string) ($map['source_post_type'] ?? $source_pt);
            $connected_pt = (string) ($map['connected_post_type'] ?? $connected_pt);
            $edges = self::fetch_relation_edges($map, $sql_config);
            foreach ($edges as $source_id => $target_ids) {
                $source_id = (int) $source_id;
                if ($source_id < 1 || !is_array($target_ids)) {
                    continue;
                }
                if (!isset($forward[$source_id])) {
                    $forward[$source_id] = [];
                }
                foreach ($target_ids as $tid) {
                    $tid = (int) $tid;
                    if ($tid < 1) {
                        continue;
                    }
                    $forward[$source_id][$tid] = true;
                    if (!isset($reverse[$tid])) {
                        $reverse[$tid] = [];
                    }
                    $reverse[$tid][$source_id] = true;
                }
            }
        }

        $all_ids = array_unique(array_merge(array_keys($forward), array_keys($reverse)));
        foreach ($forward as $ids) {
            $all_ids = array_merge($all_ids, array_keys($ids));
        }
        foreach ($reverse as $ids) {
            $all_ids = array_merge($all_ids, array_keys($ids));
        }
        $all_ids = array_values(array_unique(array_map('intval', $all_ids)));
        $titles = self::fetch_post_titles($all_ids, $sql_config);

        $forward_titles = [];
        foreach ($forward as $sid => $tid_map) {
            $list = [];
            foreach (array_keys($tid_map) as $tid) {
                $t = $titles[(int) $tid] ?? '';
                if ($t !== '') {
                    $list[] = $t;
                }
                if (count($list) >= self::MAX_TITLES) {
                    break;
                }
            }
            if ($list !== []) {
                $forward_titles[(int) $sid] = $list;
            }
        }
        $reverse_titles = [];
        foreach ($reverse as $cid => $sid_map) {
            $list = [];
            foreach (array_keys($sid_map) as $sid) {
                $t = $titles[(int) $sid] ?? '';
                if ($t !== '') {
                    $list[] = $t;
                }
                if (count($list) >= self::MAX_TITLES) {
                    break;
                }
            }
            if ($list !== []) {
                $reverse_titles[(int) $cid] = $list;
            }
        }

        self::$caches[$project_id] = [
            'forward'         => $forward_titles,
            'reverse'         => $reverse_titles,
            'source_pt'       => $source_pt,
            'connected_pt'    => $connected_pt,
            'source_label'    => self::post_type_display_label($source_pt),
            'connected_label' => self::post_type_display_label($connected_pt),
            'catalog_pt'      => sanitize_key((string) ($config['catalog_post_type'] ?? '')),
            'warmed'          => true,
        ];
    }

    public static function clear_cache(?string $project_id = null): void {
        if ($project_id === null || $project_id === '') {
            self::$caches = [];

            return;
        }
        unset(self::$caches[$project_id]);
    }

    /**
     * Envuelve el blob con identidad + títulos relacionados (Index-Time).
     * Etiquetas dinámicas desde source_post_type / connected_post_type (SaaS agnóstico).
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $project_config Unused if cache already warmed; kept for API clarity.
     */
    public static function enrich_text_blob(string $text_blob, array $row, string $project_id, array $project_config = []): string {
        $text_blob = trim($text_blob);
        if ($text_blob === '') {
            return '';
        }

        $cache = self::$caches[$project_id] ?? null;
        if (!is_array($cache) || empty($cache['warmed'])) {
            return $text_blob;
        }

        $source_label = trim((string) ($cache['source_label'] ?? ''));
        $connected_label = trim((string) ($cache['connected_label'] ?? ''));
        if ($source_label === '') {
            $source_label = self::post_type_display_label((string) ($cache['source_pt'] ?? ''));
        }
        if ($connected_label === '') {
            $connected_label = self::post_type_display_label((string) ($cache['connected_pt'] ?? ''));
        }
        if ($source_label === '' && $connected_label === '') {
            return $text_blob;
        }

        // Idempotencia: ya envuelto con estas etiquetas dinámicas.
        if (($source_label !== '' && preg_match('/^' . preg_quote($source_label, '/') . '\s*:/iu', $text_blob))
            || ($connected_label !== '' && preg_match('/^' . preg_quote($connected_label, '/') . '\s*:/iu', $text_blob))
        ) {
            return $text_blob;
        }

        $post_id = self::row_post_id($row);
        if ($post_id < 1) {
            return $text_blob;
        }

        if ($project_config === [] && !empty($cache['catalog_pt'])) {
            $project_config = ['catalog_post_type' => (string) $cache['catalog_pt']];
        }

        $role = self::resolve_row_role($row, $project_config, $cache);
        $title = self::row_display_title($row);

        if ($role === 'entity' || ($role === '' && !empty($cache['forward'][$post_id]))) {
            $related = $cache['forward'][$post_id] ?? [];
            if ($related === [] && $title === '') {
                return $text_blob;
            }

            return self::format_related_chunk(
                $source_label !== '' ? $source_label : 'Item',
                $title !== '' ? $title : __('Sin nombre', 'xabia-intelligence'),
                $connected_label,
                $related,
                $text_blob
            );
        }

        if ($role === 'activity' || ($role === '' && !empty($cache['reverse'][$post_id]))) {
            $related = $cache['reverse'][$post_id] ?? [];
            if ($related === [] && $title === '') {
                return $text_blob;
            }

            return self::format_related_chunk(
                $connected_label !== '' ? $connected_label : 'Item',
                $title !== '' ? $title : __('Sin nombre', 'xabia-intelligence'),
                $source_label,
                $related,
                $text_blob
            );
        }

        return $text_blob;
    }

    /**
     * @param list<string> $related_titles
     */
    private static function format_related_chunk(
        string $self_label,
        string $self_title,
        string $related_label,
        array $related_titles,
        string $original_blob
    ): string {
        $parts = [$self_label . ': ' . $self_title];
        if ($related_titles !== [] && $related_label !== '') {
            $parts[] = $related_label . ': ' . implode(', ', $related_titles);
        } elseif ($related_titles !== []) {
            $parts[] = implode(', ', $related_titles);
        }
        $parts[] = $original_blob;

        return implode(' | ', $parts);
    }

    /**
     * Etiqueta singular legible: labels WP si existen, si no ucwords(slug).
     */
    public static function post_type_display_label(string $post_type): string {
        $post_type = sanitize_key($post_type);
        if ($post_type === '') {
            return '';
        }
        if (function_exists('get_post_type_object')) {
            $obj = get_post_type_object($post_type);
            if (is_object($obj) && isset($obj->labels) && is_object($obj->labels) && !empty($obj->labels->singular_name)) {
                return trim((string) $obj->labels->singular_name);
            }
            if (is_object($obj) && !empty($obj->label)) {
                return trim((string) $obj->label);
            }
        }
        if (function_exists('get_taxonomy')) {
            $tax = get_taxonomy($post_type);
            if (is_object($tax) && isset($tax->labels) && is_object($tax->labels) && !empty($tax->labels->singular_name)) {
                return trim((string) $tax->labels->singular_name);
            }
        }

        return ucwords(str_replace(['-', '_'], ' ', $post_type));
    }

    /**
     * @param mixed $raw
     * @return list<array<string, string>>
     */
    public static function normalize_relations_config($raw): array {
        if (!is_array($raw) || $raw === []) {
            return [];
        }
        $list = $raw;
        if (array_keys($raw) !== range(0, count($raw) - 1)) {
            $list = [$raw];
        }
        $out = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = sanitize_key((string) ($item['relation_type'] ?? ''));
            if (!in_array($type, ['meta_key', 'table'], true)) {
                continue;
            }
            $key = sanitize_text_field((string) ($item['relation_key'] ?? ''));
            $source = sanitize_key((string) ($item['source_post_type'] ?? ''));
            $connected = sanitize_key((string) ($item['connected_post_type'] ?? ''));
            if ($key === '' || $source === '' || $connected === '') {
                continue;
            }
            $entry = [
                'relation_type'       => $type,
                'relation_key'        => $key,
                'source_post_type'    => $source,
                'connected_post_type' => $connected,
            ];
            if ($type === 'table') {
                $table = sanitize_text_field((string) ($item['relation_table'] ?? ''));
                if ($table === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                    continue;
                }
                if (!preg_match('/^[a-zA-Z0-9_]+:[a-zA-Z0-9_]+$/', $key)) {
                    continue;
                }
                $entry['relation_table'] = $table;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param array<string, string> $map
     * @param array<string, mixed>  $sql_config
     * @return array<int, list<int>>
     */
    private static function fetch_relation_edges(array $map, array $sql_config): array {
        $type = (string) ($map['relation_type'] ?? '');
        if ($type === 'meta_key') {
            return self::fetch_meta_key_edges($map, $sql_config);
        }
        if ($type === 'table') {
            return self::fetch_table_edges($map, $sql_config);
        }

        return [];
    }

    /**
     * @param array<string, string> $map
     * @param array<string, mixed>  $sql_config
     * @return array<int, list<int>>
     */
    private static function fetch_meta_key_edges(array $map, array $sql_config): array {
        $prefix = self::resolve_prefix($sql_config);
        $posts = $prefix . 'posts';
        $meta = $prefix . 'postmeta';
        $meta_key = (string) $map['relation_key'];
        $source_pt = (string) $map['source_post_type'];
        $sql = 'SELECT p.ID AS source_id, pm.meta_value AS related_raw
            FROM `' . self::esc_ident($posts) . '` p
            INNER JOIN `' . self::esc_ident($meta) . '` pm ON pm.post_id = p.ID
            WHERE p.post_type = \'' . esc_sql($source_pt) . '\'
              AND p.post_status = \'publish\'
              AND pm.meta_key = \'' . esc_sql($meta_key) . '\'
              AND pm.meta_value IS NOT NULL
              AND pm.meta_value != \'\'';
        $rows = self::run_query($sql, $sql_config);
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sid = (int) ($row['source_id'] ?? 0);
            if ($sid < 1) {
                continue;
            }
            $ids = self::parse_related_ids((string) ($row['related_raw'] ?? ''));
            if ($ids === []) {
                continue;
            }
            if (!isset($out[$sid])) {
                $out[$sid] = [];
            }
            foreach ($ids as $tid) {
                $out[$sid][] = $tid;
            }
            $out[$sid] = array_values(array_unique($out[$sid]));
        }

        return $out;
    }

    /**
     * @param array<string, string> $map
     * @param array<string, mixed>  $sql_config
     * @return array<int, list<int>>
     */
    private static function fetch_table_edges(array $map, array $sql_config): array {
        $prefix = self::resolve_prefix($sql_config);
        $table = $prefix . (string) ($map['relation_table'] ?? '');
        $cols = explode(':', (string) ($map['relation_key'] ?? ''), 2);
        if (count($cols) !== 2) {
            return [];
        }
        $from = preg_replace('/[^a-zA-Z0-9_]/', '', $cols[0]);
        $to = preg_replace('/[^a-zA-Z0-9_]/', '', $cols[1]);
        if ($from === '' || $to === '') {
            return [];
        }
        $sql = 'SELECT `' . self::esc_ident($from) . '` AS source_id, `' . self::esc_ident($to) . '` AS target_id
            FROM `' . self::esc_ident($table) . '`
            WHERE `' . self::esc_ident($from) . '` IS NOT NULL
              AND `' . self::esc_ident($to) . '` IS NOT NULL';
        $rows = self::run_query($sql, $sql_config);
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sid = (int) ($row['source_id'] ?? 0);
            $tid = (int) ($row['target_id'] ?? 0);
            if ($sid < 1 || $tid < 1) {
                continue;
            }
            if (!isset($out[$sid])) {
                $out[$sid] = [];
            }
            $out[$sid][] = $tid;
        }
        foreach ($out as $sid => $ids) {
            $out[$sid] = array_values(array_unique($ids));
        }

        return $out;
    }

    /**
     * @param list<int>            $ids
     * @param array<string, mixed> $sql_config
     * @return array<int, string>
     */
    private static function fetch_post_titles(array $ids, array $sql_config): array {
        $ids = array_values(array_filter(array_map('intval', $ids), static function ($id) {
            return $id > 0;
        }));
        if ($ids === []) {
            return [];
        }
        $prefix = self::resolve_prefix($sql_config);
        $posts = $prefix . 'posts';
        $titles = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $in = implode(',', $chunk);
            $sql = 'SELECT ID, post_title FROM `' . self::esc_ident($posts) . '`
                WHERE ID IN (' . $in . ') AND post_status = \'publish\'';
            foreach (self::run_query($sql, $sql_config) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) ($row['ID'] ?? 0);
                $title = trim(wp_strip_all_tags((string) ($row['post_title'] ?? '')));
                if ($id > 0 && $title !== '') {
                    $titles[$id] = $title;
                }
            }
        }

        return $titles;
    }

    /**
     * @param array<string, mixed> $sql_config
     * @return list<array<string, mixed>>
     */
    private static function run_query(string $sql, array $sql_config): array {
        if (!class_exists('Xabia_SQL_Connector', false)) {
            $path = plugin_dir_path(dirname(__FILE__)) . 'integrations/class-xabia-sql-connector.php';
            if (is_readable($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Xabia_SQL_Connector', false)) {
            return [];
        }
        $cfg = $sql_config;
        $cfg['query'] = $sql;
        $rows = Xabia_SQL_Connector::fetch_data($cfg);
        if (is_wp_error($rows) || !is_array($rows)) {
            return [];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $sql_config
     */
    private static function resolve_prefix(array $sql_config): string {
        global $wpdb;
        $manual = trim((string) ($sql_config['prefix'] ?? ''));
        if ($manual !== '') {
            return $manual;
        }
        $is_remote = !empty($sql_config['host']);
        if ($is_remote && class_exists('Xabia_SQL_Connector', false)) {
            $resolved = Xabia_SQL_Connector::resolve_table_prefix($sql_config, null);

            return $resolved !== '' ? $resolved : 'wp_';
        }

        return isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_';
    }

    /**
     * Extrae IDs relacionados de un meta_value ACF/nativo (agnóstico).
     * Soporta ID suelto, arrays PHP serializados (a:/s:) vía maybe_unserialize, y JSON.
     *
     * @return list<int>
     */
    private static function parse_related_ids(string $raw): array {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        if (ctype_digit($raw)) {
            $id = (int) $raw;

            return $id > 0 ? [$id] : [];
        }

        $decoded = null;
        if ((function_exists('is_serialized') && is_serialized($raw))
            || preg_match('/^[as]:\d+:/', $raw)
        ) {
            $decoded = function_exists('maybe_unserialize')
                ? maybe_unserialize($raw)
                : @unserialize($raw, ['allowed_classes' => false]);
        } elseif (($raw[0] === '[' || $raw[0] === '{') && function_exists('json_decode')) {
            $json = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decoded = $json;
            }
        }

        if (is_numeric($decoded)) {
            $id = (int) $decoded;

            return $id > 0 ? [$id] : [];
        }

        if (is_array($decoded)) {
            $ids = [];
            $flat = self::flatten_related_id_values($decoded);
            foreach ($flat as $v) {
                if (is_numeric($v)) {
                    $ids[] = (int) $v;
                } elseif (is_string($v) && ctype_digit(trim($v))) {
                    $ids[] = (int) trim($v);
                }
            }

            return array_values(array_unique(array_filter($ids, static function ($id) {
                return $id > 0;
            })));
        }

        if (preg_match_all('/\d+/', $raw, $m)) {
            $ids = array_map('intval', $m[0]);

            return array_values(array_unique(array_filter($ids, static function ($id) {
                return $id > 0;
            })));
        }

        return [];
    }

    /**
     * @param array<mixed> $values
     * @return list<mixed>
     */
    private static function flatten_related_id_values(array $values): array {
        $out = [];
        foreach ($values as $v) {
            if (is_array($v)) {
                // ACF a veces anida; también objetos post con clave ID.
                if (isset($v['ID']) && is_numeric($v['ID'])) {
                    $out[] = $v['ID'];
                } elseif (isset($v['id']) && is_numeric($v['id'])) {
                    $out[] = $v['id'];
                } else {
                    foreach (self::flatten_related_id_values($v) as $inner) {
                        $out[] = $inner;
                    }
                }
            } else {
                $out[] = $v;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function row_post_id(array $row): int {
        foreach (['ID', 'id', 'post_id', 'Post_ID'] as $k) {
            if (isset($row[$k]) && is_numeric($row[$k])) {
                $id = (int) $row[$k];
                if ($id > 0) {
                    return $id;
                }
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function row_display_title(array $row): string {
        foreach (['post_title', 'title', 'Titulo', 'nombre', 'Nombre', 'name', 'label', 'Label'] as $k) {
            if (!isset($row[$k])) {
                continue;
            }
            $t = trim(wp_strip_all_tags((string) $row[$k]));
            if ($t !== '') {
                return $t;
            }
        }
        // Cualquier columna no técnica con texto usable (mapeo CSV/SQL agnóstico).
        foreach ($row as $k => $v) {
            $key = strtolower((string) $k);
            if (in_array($key, ['id', 'post_id', 'post_name', 'slug', 'url', 'link'], true)) {
                continue;
            }
            if (is_scalar($v)) {
                $t = trim(wp_strip_all_tags((string) $v));
                if ($t !== '' && !ctype_digit($t) && strlen($t) <= 200) {
                    return $t;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed>                                                                                                                                   $row
     * @param array<string, mixed>                                                                                                                                   $project_config
     * @param array{forward: array<int, list<string>>, reverse: array<int, list<string>>, source_pt: string, connected_pt: string, warmed: bool} $cache
     */
    private static function resolve_row_role(array $row, array $project_config, array $cache): string {
        $row_pt = '';
        foreach (['post_type', 'Post_Type', 'tipo'] as $k) {
            if (!empty($row[$k]) && is_scalar($row[$k])) {
                $row_pt = sanitize_key((string) $row[$k]);
                break;
            }
        }
        $catalog_pt = sanitize_key((string) ($project_config['catalog_post_type'] ?? ''));
        $source_pt = sanitize_key((string) ($cache['source_pt'] ?? ''));
        $connected_pt = sanitize_key((string) ($cache['connected_pt'] ?? ''));

        $probe = $row_pt !== '' ? $row_pt : $catalog_pt;
        if ($probe !== '' && $source_pt !== '' && $probe === $source_pt) {
            return 'entity';
        }
        if ($probe !== '' && $connected_pt !== '' && $probe === $connected_pt) {
            return 'activity';
        }

        return '';
    }

    private static function esc_ident(string $ident): string {
        return str_replace('`', '``', $ident);
    }
}
