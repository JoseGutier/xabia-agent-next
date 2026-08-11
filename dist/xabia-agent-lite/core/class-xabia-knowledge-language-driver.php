<?php
/**
 * Driver agnóstico de traducción para sync de conocimiento (WPML / Polylang / dedupe por slug).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Knowledge_Language_Driver {

    public const TYPE_WPML = 'wpml';
    public const TYPE_POLYLANG = 'polylang';
    public const TYPE_NONE = 'none';

    /** @var array<string, true> claves canónicas / slug vistas en el sync actual */
    private static $sync_seen_keys = [];

    public static function begin_sync_pass(): void {
        self::$sync_seen_keys = [];
    }

    public static function end_sync_pass(): void {
        self::$sync_seen_keys = [];
    }

    /**
     * Detecta el motor de traducciones en la BD de origen (local o remota).
     *
     * @param object|null $db wpdb
     */
    public static function detect(string $table_prefix, $db = null, bool $allow_without_local_plugins = false): string {
        $forced = apply_filters('xabia_knowledge_translation_type', null, $table_prefix, $db);
        if (is_string($forced) && in_array($forced, [self::TYPE_WPML, self::TYPE_POLYLANG, self::TYPE_NONE], true)) {
            return $forced;
        }

        $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $table_prefix);
        if ($prefix === '') {
            $prefix = 'wp_';
        }

        if (class_exists('Xabia_Knowledge_Ingest', false)) {
            $icl = Xabia_Knowledge_Ingest::resolve_wpml_translations_table($prefix, $db, $allow_without_local_plugins);
            if ($icl !== '') {
                return self::TYPE_WPML;
            }
        }

        if (self::remote_has_polylang_schema($prefix, $db)) {
            return self::TYPE_POLYLANG;
        }

        if (!$allow_without_local_plugins && self::local_polylang_active()) {
            return self::TYPE_POLYLANG;
        }

        return self::TYPE_NONE;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function apply_sql_filter(
        string $sql,
        string $project_id,
        array $config,
        string $table_prefix,
        $db = null
    ): string {
        if ($sql === '' || stripos($sql, 'xabia_lang_filter_applied') !== false) {
            return $sql;
        }
        $lang_scope = apply_filters('xabia_knowledge_lang_scope', 'primary', $project_id, $config);
        if ($lang_scope === 'all') {
            return (string) apply_filters('xabia_knowledge_primary_language_sql', $sql, $project_id, $config, $table_prefix);
        }
        if (!preg_match('/\bFROM\s+[`\']?[\w]*posts[`\']?\s+(?:AS\s+)?p\b/i', $sql)) {
            return (string) apply_filters('xabia_knowledge_primary_language_sql', $sql, $project_id, $config, $table_prefix);
        }

        $is_remote = class_exists('Xabia_Knowledge_Sync', false)
            && Xabia_Knowledge_Sync::is_remote_config($config);

        if (
            !$is_remote
            && class_exists('Xabia_Knowledge_Ingest', false)
            && (
                !Xabia_Knowledge_Ingest::uses_chat_site_language_filters($config)
                || !Xabia_Knowledge_Ingest::is_multilingual_site()
            )
        ) {
            // Local monolingüe: no JOIN; el dedupe en PHP cubrirá colisiones.
            return (string) apply_filters('xabia_knowledge_primary_language_sql', $sql, $project_id, $config, $table_prefix);
        }

        $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $table_prefix);
        if ($prefix === '') {
            $prefix = 'wp_';
        }

        $type = self::detect($prefix, $db, $is_remote);
        $primary = 'es';
        if (class_exists('Xabia_Knowledge_Ingest', false)) {
            $primary = Xabia_Knowledge_Ingest::project_language_code($config);
        }
        $primary = esc_sql($primary !== '' ? $primary : 'es');

        $clause = '';
        if ($type === self::TYPE_WPML && class_exists('Xabia_Knowledge_Ingest', false)) {
            $icl = Xabia_Knowledge_Ingest::resolve_wpml_translations_table($prefix, $db, $is_remote);
            if ($icl !== '') {
                $clause = " INNER JOIN `{$icl}` xabia_wpml_tr"
                    . " ON xabia_wpml_tr.element_id = p.ID"
                    . " AND xabia_wpml_tr.element_type = CONCAT('post_', p.post_type)"
                    . " AND xabia_wpml_tr.language_code = '{$primary}'";
            }
        } elseif ($type === self::TYPE_POLYLANG) {
            $tr = $prefix . 'term_relationships';
            $tt = $prefix . 'term_taxonomy';
            $terms = $prefix . 'terms';
            $clause = " INNER JOIN `{$tr}` xabia_pll_tr ON xabia_pll_tr.object_id = p.ID"
                . " INNER JOIN `{$tt}` xabia_pll_tt ON xabia_pll_tt.term_taxonomy_id = xabia_pll_tr.term_taxonomy_id AND xabia_pll_tt.taxonomy = 'language'"
                . " INNER JOIN `{$terms}` xabia_pll_lang ON xabia_pll_lang.term_id = xabia_pll_tt.term_id AND xabia_pll_lang.slug = '{$primary}'";
        }

        // TYPE_NONE: sin JOIN; dedupe determinista en prepare_fetched_rows.
        if ($clause === '') {
            return (string) apply_filters('xabia_knowledge_primary_language_sql', $sql, $project_id, $config, $table_prefix);
        }

        if (preg_match('/\bWHERE\b/i', $sql)) {
            $sql = preg_replace('/\bWHERE\b/i', $clause . ' WHERE /* xabia_lang_filter_applied */', $sql, 1);
        } else {
            $sql = rtrim(trim($sql), ';') . $clause . ' /* xabia_lang_filter_applied */';
        }

        return (string) apply_filters('xabia_knowledge_primary_language_sql', $sql, $project_id, $config, $table_prefix);
    }

    /**
     * Post-fetch: filtro por idioma de fila + dedupe por slug cuando no hay driver SQL.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $config
     * @return list<array<string, mixed>>
     */
    public static function prepare_fetched_rows(array $rows, string $project_id, array $config, string $driver_type): array {
        if ($rows === []) {
            return $rows;
        }

        $lang_scope = apply_filters('xabia_knowledge_lang_scope', 'primary', $project_id, $config);
        if ($lang_scope === 'all') {
            return $rows;
        }

        if (class_exists('Xabia_Knowledge_Ingest', false)) {
            $filtered = [];
            foreach ($rows as $row) {
                if (!is_array($row) || $row === []) {
                    continue;
                }
                if (Xabia_Knowledge_Ingest::should_skip_translated_row($row, $project_id)) {
                    continue;
                }
                $filtered[] = $row;
            }
            $rows = $filtered;
        }

        if ($driver_type === self::TYPE_NONE || $driver_type === '') {
            $rows = self::dedupe_by_slug_keep_first_id($rows);
        }

        return $rows;
    }

    /**
     * Sin driver de idioma: ordena por ID ASC y conserva solo la primera fila de cada slug.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function dedupe_by_slug_keep_first_id(array $rows): array {
        if (count($rows) < 2) {
            return array_values($rows);
        }

        $indexed = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $indexed[] = [
                'ord'  => $i,
                'id'   => self::row_numeric_id($row),
                'slug' => self::row_slug_key($row),
                'row'  => $row,
            ];
        }

        usort($indexed, static function (array $a, array $b): int {
            if ($a['id'] !== $b['id']) {
                return $a['id'] <=> $b['id'];
            }

            return $a['ord'] <=> $b['ord'];
        });

        $seen = [];
        $out = [];
        foreach ($indexed as $item) {
            $slug = (string) $item['slug'];
            if ($slug === '') {
                $out[] = $item['row'];
                continue;
            }
            if (isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = $item['row'];
        }

        return $out;
    }

    /**
     * Blindaje del bucle upsert: rechaza variantes posteriores del mismo slug/ente en el mismo pass.
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $mapping
     */
    public static function should_skip_duplicate_in_pass(array $row, array $mapping, string $project_id): bool {
        $bucket = self::pass_bucket_key($row, $mapping, $project_id);
        if ($bucket === '') {
            return false;
        }

        return isset(self::$sync_seen_keys[$bucket]);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $mapping
     */
    public static function mark_seen_in_pass(array $row, array $mapping, string $project_id): void {
        $bucket = self::pass_bucket_key($row, $mapping, $project_id);
        if ($bucket === '') {
            return;
        }
        self::$sync_seen_keys[$bucket] = true;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $mapping
     */
    private static function pass_bucket_key(array $row, array $mapping, string $project_id): string {
        $key = '';
        if (class_exists('Xabia_Knowledge_Ingest', false)) {
            $key = Xabia_Knowledge_Ingest::canonical_record_key($row, $mapping);
        }
        if ($key === '') {
            $key = self::row_slug_key($row);
        }
        if ($key === '') {
            return '';
        }
        $key = sanitize_title($key);
        if ($key === '') {
            return '';
        }

        return sanitize_key($project_id) . '|' . $key;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function row_numeric_id(array $row): int {
        foreach (['ID', 'id', 'post_id', 'postId'] as $col) {
            if (!isset($row[$col])) {
                continue;
            }
            $n = (int) $row[$col];
            if ($n > 0) {
                return $n;
            }
        }

        return PHP_INT_MAX;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function row_slug_key(array $row): string {
        foreach (['post_name', 'Slug_Empresa', 'slug', 'post_slug', 'ente_id'] as $col) {
            if (!isset($row[$col]) || trim((string) $row[$col]) === '') {
                continue;
            }
            $slug = sanitize_title((string) $row[$col]);
            if ($slug !== '') {
                return $slug;
            }
        }

        return '';
    }

    /**
     * @param object|null $db
     */
    private static function remote_has_polylang_schema(string $prefix, $db): bool {
        global $wpdb;
        $db = (is_object($db) && method_exists($db, 'get_var')) ? $db : $wpdb;
        if (!is_object($db) || !method_exists($db, 'get_var')) {
            return false;
        }
        $tt = $prefix . 'term_taxonomy';
        try {
            $found = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($tt)));
            if (!is_string($found) || $found === '') {
                return false;
            }
            $hit = $db->get_var(
                "SELECT 1 FROM `{$tt}` WHERE taxonomy = 'language' LIMIT 1"
            );

            return $hit !== null && $hit !== false && (string) $hit !== '';
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function local_polylang_active(): bool {
        return function_exists('pll_default_language') || function_exists('pll_get_post_language');
    }
}
