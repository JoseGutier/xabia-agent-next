<?php
/**
 * Motor de ingesta v1.0.90+: idioma principal, pasaporte de empresa y clave canónica por slug.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Knowledge_Ingest {

    /** @var array<string, true>|null */
    private static $multilingual_detected = null;

    /**
     * ¿Aplicar pasaporte unificado (content_chunk canónico) para esta fila?
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $mapping
     */
    public static function uses_passport_chunk(string $project_id, array $mapping, array $row = []): bool {
        $default = self::row_has_passport_shape($row, $mapping);
        $use = (bool) apply_filters('xabia_knowledge_use_passport_chunk', $default, $project_id, $mapping, $row);

        return $use && self::row_has_passport_shape($row, $mapping);
    }

    /**
     * Clave estable 1:1 por empresa: slug WP (post_name), no ID numérico volátil.
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $mapping
     */
    public static function canonical_record_key(array $row, array $mapping = []): string {
        foreach (self::slug_column_candidates($mapping) as $col) {
            if ($col === '' || !isset($row[$col])) {
                continue;
            }
            $raw = trim((string) $row[$col]);
            if ($raw === '') {
                continue;
            }
            $slug = self::canonical_slug($raw);
            if ($slug !== '') {
                return $slug;
            }
        }

        foreach (['SKU', 'sku'] as $sku_col) {
            if (!isset($row[$sku_col])) {
                continue;
            }
            $raw = trim((string) $row[$sku_col]);
            if ($raw === '') {
                continue;
            }
            $slug = self::canonical_slug($raw);
            if ($slug !== '') {
                return $slug;
            }
        }

        return self::canonical_slug((string) apply_filters('xabia_knowledge_canonical_record_key', '', $row, $mapping));
    }

    /**
     * Slug único agnóstico: guiones medios, sin espacios ni guiones bajos (arriluze_nautica → arriluze-nautica).
     */
    public static function canonical_slug(string $raw): string {
        $raw = trim($raw);
        if ($raw === '' || $raw === 'global') {
            return $raw === 'global' ? 'global' : '';
        }
        $slug = sanitize_title(str_replace(['_', ' '], '-', $raw));
        if (!is_string($slug) || $slug === '') {
            return '';
        }

        return (string) apply_filters('xabia_knowledge_canonical_slug', $slug, $raw);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $mapping
     * @param array{
     *   project_id?: string,
     *   project_config?: array<string, mixed>,
     *   append_mapped_attributes?: bool
     * } $options
     * @return array{meta_array: array<string, string>, text_blob: string}
     */
    public static function build_passport_chunk(array $row, array $mapping, array $options = []): array {
        $clean = static function ($text): string {
            if (!class_exists('Xabia_Knowledge_Text', false)) {
                $text = strip_tags((string) $text);
                $text = preg_replace('/\s+/u', ' ', $text);

                return trim((string) $text);
            }

            return Xabia_Knowledge_Text::clean_field_value((string) $text);
        };

        $limit = static function ($text, string $col = '', string $label = '') use ($clean): string {
            $text = $clean($text);
            if ($text === '' || !class_exists('Xabia_Knowledge_Text', false)) {
                return $text;
            }
            $max = (int) apply_filters('xabia_rag_field_max_chars', Xabia_Knowledge_Text::FIELD_MAX_CHARS, $col, $label);

            return Xabia_Knowledge_Text::limit_field_value($text, $max);
        };

        $first = static function (array $cols) use ($row, $limit, &$used_cols): string {
            foreach ($cols as $col) {
                if (!isset($row[$col])) {
                    continue;
                }
                $val = $limit($row[$col], (string) $col, (string) $col);
                if ($val !== '') {
                    $used_cols[(string) $col] = true;

                    return $val;
                }
            }

            return '';
        };

        $join = static function (array $cols, string $sep = ', ') use ($row, $limit, &$used_cols): string {
            $parts = [];
            foreach ($cols as $col) {
                if (!isset($row[$col])) {
                    continue;
                }
                $val = $limit($row[$col], (string) $col, (string) $col);
                if ($val !== '') {
                    $used_cols[(string) $col] = true;
                    $parts[] = $val;
                }
            }

            return implode($sep, $parts);
        };

        $used_cols = [];
        $empresa = $first(['empresa', 'post_title', 'title', 'Titulo', 'nombre', 'Nombre']);
        $categoria = $first(['categoria', 'Categoria', 'category', 'Categorias_Tags']);
        $subcats = $join(self::numbered_columns('subcategoria_', 1, 12));
        $experiencias = $join(self::numbered_columns('experiencia_', 1, 12));
        $propuestas = self::compile_propuestas($row, $limit);
        if ($propuestas !== '') {
            foreach (self::numbered_columns('propuesta_', 1, 12) as $pc) {
                $used_cols[$pc] = true;
            }
            for ($i = 1; $i <= 12; $i++) {
                $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
                $used_cols["descripcion_propuesta_{$n}"] = true;
                $used_cols["descripcion_propuesta_{$i}"] = true;
            }
        }
        $descripcion = $first(['descripcion_empresa', 'post_content', 'Descripcion', 'description', 'content']);

        $sections = [];
        if ($empresa !== '') {
            $sections[] = 'EMPRESA: ' . $empresa;
        }
        if ($categoria !== '') {
            $sections[] = 'CATEGORÍA: ' . $categoria;
        }
        if ($subcats !== '') {
            $sections[] = 'SUBCATEGORÍAS: ' . $subcats;
        }
        if ($experiencias !== '') {
            $sections[] = 'EXPERIENCIAS: ' . $experiencias;
        }
        if ($propuestas !== '') {
            $sections[] = 'PROPUESTAS: ' . $propuestas;
        }
        if ($descripcion !== '') {
            $sections[] = 'DESCRIPCIÓN GENERAL: ' . $descripcion;
        }

        $blob = implode(' | ', $sections);
        if (class_exists('Xabia_Knowledge_Text', false)) {
            $blob = Xabia_Knowledge_Text::finalize_content_chunk($blob);
        }

        // Anexo dinámico (fuente remota): atributos mapeados con valor, sin hardcodear claves de cliente.
        if (self::should_append_mapped_attributes_annex($options)) {
            $blob = self::append_mapped_attributes_annex($blob, $row, $mapping, $limit, $used_cols);
        }

        $meta_array = self::build_passport_meta($row, $mapping, $clean, $limit);
        if ($empresa !== '') {
            $meta_array['empresa'] = $empresa;
        }
        $canonical = self::canonical_record_key($row, $mapping);
        if ($canonical !== '') {
            $meta_array['__canonical_key'] = $canonical;
        }

        if (class_exists('Xabia_Rag_Chunk_Enricher', false)) {
            $blob = Xabia_Rag_Chunk_Enricher::enrich($blob, $row, is_array($mapping) ? $mapping : [], $options);
        }

        $finalized = self::finalize_media_urls_in_ingest(
            $meta_array,
            $blob,
            is_array($mapping) ? $mapping : [],
            $options
        );

        return [
            'meta_array' => $finalized['meta_array'],
            'text_blob'  => $finalized['text_blob'],
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function should_append_mapped_attributes_annex(array $options): bool {
        if (array_key_exists('append_mapped_attributes', $options)) {
            return (bool) $options['append_mapped_attributes'];
        }
        $config = [];
        if (isset($options['project_config']) && is_array($options['project_config'])) {
            $config = $options['project_config'];
        } elseif (!empty($options['project_id']) && is_string($options['project_id'])) {
            $projects = get_option('xabia_projects_config', []);
            $pid = sanitize_key($options['project_id']);
            $config = is_array($projects[$pid] ?? null) ? $projects[$pid] : [];
        }
        if ($config === [] || !class_exists('Xabia_Knowledge_Sync', false)) {
            return false;
        }

        return Xabia_Knowledge_Sync::is_remote_config($config);
    }

    /**
     * Concatena al content_chunk atributos mapeados (pestaña General) con valor en la fila.
     * Agnóstico: label/csv_col del mapeo; exclusiones por rol visual o columnas ya usadas en la cabecera.
     *
     * @param array<string, mixed>             $row
     * @param array<int, array<string, mixed>> $mapping
     * @param callable(mixed, string, string): string $limit
     * @param array<string, true>              $used_cols Columnas ya consumidas por la cabecera del pasaporte.
     */
    public static function append_mapped_attributes_annex(
        string $blob,
        array $row,
        array $mapping,
        callable $limit,
        array $used_cols = []
    ): string {
        if ($mapping === []) {
            return $blob;
        }

        $lines = [];
        $seen_labels = [];
        foreach ($mapping as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $col = trim((string) ($attr['csv_col'] ?? ''));
            if ($col === '' || !isset($row[$col])) {
                continue;
            }
            if (isset($used_cols[$col]) || self::is_passport_annex_excluded_column($col, $attr)) {
                continue;
            }
            $label = trim((string) ($attr['label'] ?? ''));
            if ($label === '') {
                $label = $col;
            }
            $val = $limit($row[$col], $col, $label);
            if ($val === '') {
                continue;
            }
            $label_key = function_exists('mb_strtolower')
                ? mb_strtolower($label, 'UTF-8')
                : strtolower($label);
            if (isset($seen_labels[$label_key])) {
                continue;
            }
            $seen_labels[$label_key] = true;
            $lines[] = '- ' . $label . ': ' . $val;
        }

        /**
         * @param list<string>                 $lines
         * @param array<string, mixed>         $row
         * @param array<int, array<string, mixed>> $mapping
         */
        $lines = (array) apply_filters('xabia_knowledge_passport_annex_lines', $lines, $row, $mapping, $blob);
        $lines = array_values(array_filter(array_map('strval', $lines), static function ($l) {
            return trim($l) !== '';
        }));
        if ($lines === []) {
            return $blob;
        }

        $heading = (string) apply_filters(
            'xabia_knowledge_passport_annex_heading',
            __('Additional details', 'xabia-intelligence'),
            $row,
            $mapping
        );
        $heading = trim($heading);
        $annex = $heading !== ''
            ? ("\n---\n" . $heading . ":\n" . implode("\n", $lines))
            : ("\n---\n" . implode("\n", $lines));
        $out = rtrim($blob) . $annex;

        if (class_exists('Xabia_Knowledge_Text', false)) {
            $max = (int) apply_filters('xabia_knowledge_chunk_max_chars', Xabia_Knowledge_Text::CHUNK_MAX_CHARS);
            if ($max >= 500) {
                if (function_exists('mb_strlen') && mb_strlen($out) > $max) {
                    $out = mb_substr($out, 0, max(1, $max - 1)) . '…';
                } elseif (strlen($out) > $max) {
                    $out = substr($out, 0, max(1, $max - 1)) . '…';
                }
            }
        }

        return $out;
    }

    /**
     * Exclusiones estructurales del anexo (roles de media / IDs). Sin claves de dominio de cliente.
     *
     * @param array<string, mixed> $attr
     */
    public static function is_passport_annex_excluded_column(string $col, array $attr = []): bool {
        $role = strtolower(trim((string) ($attr['visual_role'] ?? 'none')));
        if (in_array($role, ['img', 'image', 'logo', 'logotipo', 'thumbnail', 'photo', 'foto'], true)) {
            return true;
        }
        $c = ltrim(trim($col), '@');
        $c_l = function_exists('mb_strtolower') ? mb_strtolower($c, 'UTF-8') : strtolower($c);
        if ($c_l === '' || $c_l === 'id' || $c_l === 'post_id') {
            return true;
        }
        // Identidad técnica / idioma (no atributos de negocio del mapeo).
        if (preg_match('/^(post_name|post_slug|slug|language_code|wpml_language|pll_lang|lang)$/u', $c_l) === 1) {
            return true;
        }
        if (preg_match('/(^|_)(img|image|logo|logotipo|thumbnail|foto|photo)(_|$)/u', $c_l) === 1) {
            return true;
        }

        return (bool) apply_filters('xabia_knowledge_passport_annex_exclude_column', false, $col, $attr);
    }

    /**
     * Filtra filas traducidas (WPML / Polylang): solo idioma principal del sitio.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    /**
     * Filtros WPML/Polylang del sitio del chat (mismas tablas locales).
     * El SQL remoto tiene su propio filtro vía {@see apply_primary_language_sql_filter()}.
     *
     * @param array<string, mixed> $config
     */
    public static function uses_chat_site_language_filters(array $config): bool {
        if (!class_exists('Xabia_Knowledge_Sync', false)) {
            return true;
        }

        return !Xabia_Knowledge_Sync::is_remote_config($config);
    }

    /**
     * Código ISO-639-1 de 2 letras (columna WPML language_code). Por defecto es.
     */
    public static function sanitize_project_language_code(string $raw): string {
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return 'es';
        }
        if (preg_match('/^([a-z]{2})(?:[_-].*)?$/', $raw, $m)) {
            return $m[1];
        }
        $clean = preg_replace('/[^a-z]/', '', $raw);

        return (is_string($clean) && strlen($clean) >= 2) ? substr($clean, 0, 2) : 'es';
    }

    /**
     * Idioma del catálogo del agente (`project_language`). Remoto: default es.
     * Local sin valor explícito: idioma WPML/Polylang del sitio, o es.
     *
     * @param array<string, mixed> $config
     */
    public static function project_language_code(array $config): string {
        $explicit = trim((string) ($config['project_language'] ?? ''));
        if ($explicit !== '') {
            $code = self::sanitize_project_language_code($explicit);

            return (string) apply_filters('xabia_project_language', $code, $config);
        }

        $is_remote = class_exists('Xabia_Knowledge_Sync', false)
            && Xabia_Knowledge_Sync::is_remote_config($config);
        if ($is_remote) {
            return (string) apply_filters('xabia_project_language', 'es', $config);
        }

        $primary = self::primary_language_code();
        $code = $primary !== '' ? self::sanitize_project_language_code($primary) : 'es';

        return (string) apply_filters('xabia_project_language', $code, $config);
    }

    public static function filter_primary_language_rows(array $rows, string $project_id, string $driver_type = '', $db = null, string $table_prefix = ''): array {
        if ($rows === []) {
            return $rows;
        }
        $projects = function_exists('get_option') ? get_option('xabia_projects_config', []) : [];
        $config = is_array($projects[$project_id] ?? null) ? $projects[$project_id] : [];
        $lang_scope = Xabia_Knowledge_Language_Driver::lang_scope($project_id, $config);
        if ($lang_scope === Xabia_Knowledge_Language_Driver::SCOPE_ALL) {
            return $rows;
        }

        if (class_exists('Xabia_Knowledge_Language_Driver', false)) {
            if ($driver_type === '') {
                $is_remote = class_exists('Xabia_Knowledge_Sync', false)
                    && Xabia_Knowledge_Sync::is_remote_config($config);
                $prefix = $table_prefix !== '' ? $table_prefix : 'wp_';
                $driver_type = Xabia_Knowledge_Language_Driver::detect($prefix, $db, $is_remote);
            }

            return Xabia_Knowledge_Language_Driver::prepare_fetched_rows(
                $rows,
                $project_id,
                $config,
                $driver_type,
                $db,
                $table_prefix
            );
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }
            if (self::should_skip_translated_row($row, $project_id)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function should_skip_translated_row(array $row, string $project_id): bool {
        $projects = function_exists('get_option') ? get_option('xabia_projects_config', []) : [];
        $config = is_array($projects[$project_id] ?? null) ? $projects[$project_id] : [];
        $lang_scope = Xabia_Knowledge_Language_Driver::lang_scope($project_id, $config);
        if ($lang_scope === Xabia_Knowledge_Language_Driver::SCOPE_ALL) {
            return (bool) apply_filters('xabia_knowledge_sync_skip_row', false, $row, $project_id);
        }
        if ($lang_scope === Xabia_Knowledge_Language_Driver::SCOPE_PRIMARY_FALLBACK) {
            return (bool) apply_filters('xabia_knowledge_sync_skip_row', false, $row, $project_id);
        }
        if (!self::uses_chat_site_language_filters($config) || !self::is_multilingual_site()) {
            return (bool) apply_filters('xabia_knowledge_sync_skip_row', false, $row, $project_id);
        }

        $lang = self::row_language_code($row);
        $primary = self::project_language_code($config);
        if ($lang !== '' && $primary !== '' && strcasecmp($lang, $primary) !== 0) {
            return true;
        }

        return (bool) apply_filters('xabia_knowledge_sync_skip_row', false, $row, $project_id);
    }

    /**
     * Delega al driver agnóstico (WPML / Polylang / none+dedupe).
     *
     * @param array<string, mixed> $config
     */
    public static function apply_primary_language_sql_filter(string $sql, string $project_id, array $config, string $table_prefix, $db = null): string {
        if (class_exists('Xabia_Knowledge_Language_Driver', false)) {
            return Xabia_Knowledge_Language_Driver::apply_sql_filter($sql, $project_id, $config, $table_prefix, $db);
        }

        return $sql;
    }

    /** Expuesto para el driver (Polylang local). */
    public static function polylang_plugin_active(): bool {
        return self::polylang_active();
    }

    /** @return bool */
    public static function wpml_cms_plugin_active(): bool {
        return self::wpml_cms_active();
    }

    /**
     * Catálogo de IDs para huérfanos: slugs cuando la fuente es tipo empresa/WP.
     *
     * @param array<string, mixed> $config
     */
    public static function derive_catalog_sql(string $sql): string {
        $posts_table = '';
        if (preg_match('/\bFROM\s+([`\'"]?(?:\{prefix\}|[\w]+)posts[`\'"]?)\s+(?:AS\s+)?p\b/i', $sql, $from_match)) {
            $posts_table = trim($from_match[1], "`'\" ");
        } else {
            return '';
        }

        $post_type = '';
        if (preg_match('/\bWHERE\b\s+[^;]*?p\.post_type\s*=\s*\'([^\']+)\'/is', $sql, $where_match)) {
            $post_type = $where_match[1];
        } elseif (preg_match('/\bp\.post_type\s*=\s*\'([^\']+)\'/i', $sql, $alias_match)) {
            $post_type = $alias_match[1];
        }

        if ($post_type === '') {
            return '';
        }

        $post_type = preg_replace('/[^a-z0-9_-]/i', '', $post_type);
        if ($post_type === '') {
            return '';
        }

        $base = "SELECT p.post_name FROM {$posts_table} p WHERE p.post_type = '{$post_type}' AND p.post_status = 'publish' AND p.post_name != ''";

        if (self::is_multilingual_site()) {
            $base = str_replace(' WHERE ', ' /* xabia_catalog */ WHERE ', $base);
            $base = preg_replace('/\bWHERE\b/i', 'WHERE', $base);
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $mapping
     */
    private static function row_has_passport_shape(array $row, array $mapping): bool {
        if (self::canonical_record_key($row, $mapping) !== '') {
            foreach (['empresa', 'post_title', 'title', 'categoria', 'Categoria'] as $col) {
                if (isset($row[$col]) && trim((string) $row[$col]) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function slug_column_candidates(array $mapping): array {
        $cols = ['Slug_Empresa', 'post_name', 'slug', 'post_slug'];
        foreach ($mapping as $attr) {
            $col = (string) ($attr['csv_col'] ?? '');
            if ($col === '') {
                continue;
            }
            $role = strtolower(trim((string) ($attr['visual_role'] ?? '')));
            if ($role === 'slug' || stripos($col, 'slug') !== false || $col === 'post_name') {
                $cols[] = $col;
            }
        }

        return array_values(array_unique($cols));
    }

    /**
     * @return list<string>
     */
    private static function numbered_columns(string $prefix, int $from, int $to): array {
        $cols = [];
        for ($i = $from; $i <= $to; $i++) {
            $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $cols[] = $prefix . $n;
            $cols[] = $prefix . $i;
        }

        return $cols;
    }

    /**
     * @param callable $limit
     * @param array<string, mixed> $row
     */
    private static function compile_propuestas(array $row, callable $limit): string {
        $parts = [];
        for ($i = 1; $i <= 12; $i++) {
            $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            foreach (["propuesta_{$n}", "propuesta_{$i}"] as $title_col) {
                if (!isset($row[$title_col])) {
                    continue;
                }
                $title = $limit($row[$title_col], $title_col, $title_col);
                if ($title === '') {
                    continue;
                }
                $desc = '';
                foreach (["descripcion_propuesta_{$n}", "descripcion_propuesta_{$i}"] as $desc_col) {
                    if (!isset($row[$desc_col])) {
                        continue;
                    }
                    $desc = $limit($row[$desc_col], $desc_col, $desc_col);
                    if ($desc !== '') {
                        break;
                    }
                }
                $parts[] = $desc !== '' ? "{$title} — {$desc}" : $title;
            }
        }

        return implode('; ', array_values(array_unique($parts)));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $mapping
     * @param callable $clean
     * @param callable $limit
     * @return array<string, string>
     */
    private static function build_passport_meta(array $row, array $mapping, callable $clean, callable $limit): array {
        $meta = [];
        $skip_rag_only = true;

        if (!empty($mapping)) {
            foreach ($mapping as $attr) {
                $col = (string) ($attr['csv_col'] ?? '');
                $label = (string) ($attr['label'] ?? $col);
                if ($col === '' || !isset($row[$col])) {
                    continue;
                }
                $val = $limit($row[$col], $col, $label);
                if ($val === '') {
                    continue;
                }
                $meta[sanitize_key($label !== '' ? $label : $col)] = $val;
            }

            return $meta;
        }

        foreach ($row as $k => $v) {
            $key = (string) $k;
            if ($key === '' || str_starts_with($key, '__')) {
                continue;
            }
            if ($skip_rag_only && class_exists('Xabia_Knowledge_Text', false) && !Xabia_Knowledge_Text::default_import_rag_for_column($key)) {
                continue;
            }
            $val = $limit($v, $key, $key);
            if ($val !== '') {
                $meta[sanitize_key($key)] = $val;
            }
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $row
     */
    /**
     * Idioma de una fila de catálogo (WPML/Polylang/meta). Público para fuentes PHP (páginas web).
     */
    public static function row_language_code(array $row): string {
        foreach (['language_code', 'wpml_language', 'lang', 'pll_lang', 'language', 'idioma'] as $col) {
            if (!isset($row[$col])) {
                continue;
            }
            $code = substr(sanitize_key((string) $row[$col]), 0, 10);
            if ($code !== '') {
                return $code;
            }
        }

        $post_id = 0;
        foreach (['ID', 'id', 'post_id'] as $id_col) {
            if (!isset($row[$id_col])) {
                continue;
            }
            $raw = trim((string) $row[$id_col]);
            if ($raw !== '' && ctype_digit($raw)) {
                $post_id = (int) $raw;
                break;
            }
        }

        if ($post_id < 1) {
            return '';
        }

        if (self::polylang_active() && function_exists('pll_get_post_language')) {
            $lang = pll_get_post_language($post_id, 'slug');

            return is_string($lang) ? substr(sanitize_key($lang), 0, 10) : '';
        }

        if (self::wpml_active()) {
            $post_type = 'post';
            foreach (['post_type', 'postType'] as $pt_col) {
                if (!empty($row[$pt_col])) {
                    $post_type = sanitize_key((string) $row[$pt_col]);
                    break;
                }
            }
            $details = apply_filters('wpml_element_language_details', null, [
                'element_id'   => $post_id,
                'element_type' => 'post_' . $post_type,
            ]);
            if (is_array($details) && !empty($details['language_code'])) {
                return substr(sanitize_key((string) $details['language_code']), 0, 10);
            }
            $code = apply_filters('wpml_element_language_code', null, [
                'element_id'   => $post_id,
                'element_type' => 'post_' . $post_type,
            ]);

            return is_string($code) ? substr(sanitize_key($code), 0, 10) : '';
        }

        return '';
    }

    public static function primary_language_code(): string {
        if (class_exists('Xabia_I18n', false)) {
            $wpml = Xabia_I18n::wpml_default_language_code();
            if ($wpml !== '') {
                return $wpml;
            }
        }
        if (self::polylang_active() && function_exists('pll_default_language')) {
            $pll = substr(sanitize_key((string) pll_default_language('slug')), 0, 10);
            if ($pll !== '') {
                return $pll;
            }
        }

        $locale = function_exists('get_locale') ? (string) get_locale() : 'es_ES';
        $short = substr(sanitize_key($locale), 0, 2);

        return $short !== '' ? $short : 'es';
    }

    public static function is_multilingual_site(): bool {
        if (self::$multilingual_detected !== null) {
            return self::$multilingual_detected !== [];
        }
        self::$multilingual_detected = [];
        if (self::wpml_cms_active()) {
            $icl = self::resolve_wpml_translations_table('');
            if ($icl !== '') {
                if (class_exists('Xabia_I18n', false)) {
                    $langs = Xabia_I18n::wpml_active_language_codes();
                    if (count($langs) >= 2) {
                        self::$multilingual_detected['wpml'] = true;
                    }
                } elseif (defined('ICL_SITEPRESS_VERSION')) {
                    self::$multilingual_detected['wpml'] = true;
                }
            }
        }
        if (self::polylang_active() && function_exists('pll_languages_list')) {
            $langs = pll_languages_list(['fields' => 'slug']);
            if (is_array($langs) && count($langs) >= 2) {
                self::$multilingual_detected['polylang'] = true;
            }
        }

        return self::$multilingual_detected !== [];
    }

    private static function wpml_active(): bool {
        return class_exists('Xabia_I18n', false)
            ? Xabia_I18n::wpml_available()
            : (function_exists('wpml_get_default_language') || defined('ICL_SITEPRESS_VERSION'));
    }

    /** WPML Multilingual CMS (traducción de posts), no solo String Translation. */
    private static function wpml_cms_active(): bool {
        if (defined('ICL_SITEPRESS_VERSION') || class_exists('SitePress', false)) {
            return true;
        }

        return function_exists('wpml_get_default_language')
            || function_exists('wpml_get_active_languages')
            || function_exists('icl_object_id');
    }

    /**
     * Nombre real de la tabla icl_translations en la BD de la consulta (prefijo WP o búsqueda).
     *
     * @param object|null $db wpdb local o remoto; null = global $wpdb.
     * @param bool        $allow_without_local_wpml Si true (SQL remoto), detecta la tabla aunque
     *                                              el sitio del chat no tenga WPML instalado.
     */
    public static function resolve_wpml_translations_table(string $table_prefix = '', $db = null, bool $allow_without_local_wpml = false): string {
        if (!$allow_without_local_wpml && !self::wpml_cms_active()) {
            return '';
        }
        global $wpdb;
        $db = (is_object($db) && method_exists($db, 'get_var')) ? $db : $wpdb;
        if (!is_object($db) || !method_exists($db, 'get_var')) {
            return '';
        }
        $prefix = $table_prefix !== ''
            ? preg_replace('/[^a-zA-Z0-9_]/', '', $table_prefix)
            : (string) ($db->prefix ?? 'wp_');
        if ($prefix === '') {
            $prefix = 'wp_';
        }
        $candidate = $prefix . 'icl_translations';
        try {
            $found = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($candidate)));
            if (is_string($found) && $found !== '') {
                return $found;
            }
            $tables = $db->get_results("SHOW TABLES LIKE '%icl\\_translations'", ARRAY_N);
            if (is_array($tables) && isset($tables[0][0]) && is_string($tables[0][0]) && $tables[0][0] !== '') {
                return $tables[0][0];
            }
        } catch (Throwable $e) {
            return '';
        }

        return '';
    }

    public static function wpml_schema_ready(string $table_prefix = '', $db = null, bool $allow_without_local_wpml = false): bool {
        return self::resolve_wpml_translations_table($table_prefix, $db, $allow_without_local_wpml) !== '';
    }

    private static function polylang_active(): bool {
        return function_exists('pll_default_language') || function_exists('pll_get_post_language');
    }

    /**
     * post_type del catálogo (ente principal): config explícita, addon, SQL del proyecto o filtro.
     *
     * @param array<string, mixed> $config
     */
    public static function resolve_catalog_post_type(array $config): string {
        foreach (['catalog_post_type', 'wp_post_type', 'entity_post_type'] as $key) {
            $explicit = trim((string) ($config[$key] ?? ''));
            if ($explicit === '') {
                continue;
            }
            $explicit = preg_replace('/[^a-z0-9_-]/i', '', $explicit);
            if (is_string($explicit) && $explicit !== '') {
                return $explicit;
            }
        }

        $addon = sanitize_key((string) ($config['addon_slug'] ?? ''));
        $addon_defaults = (array) apply_filters('xabia_catalog_post_type_by_addon', [
            'mec'  => 'mec-events',
            'woo'  => 'product',
        ], $config);
        if ($addon !== '' && !empty($addon_defaults[$addon])) {
            $pt = preg_replace('/[^a-z0-9_-]/i', '', (string) $addon_defaults[$addon]);
            if (is_string($pt) && $pt !== '') {
                return $pt;
            }
        }

        $sql = self::catalog_source_sql($config);
        if ($sql !== '') {
            $patterns = [
                "/\bp\.post_type\s*=\s*'([^']+)'/i",
                "/\bpost_type\s*=\s*'([^']+)'/i",
                "/\bp\.post_type\s*=\s*\"([^\"]+)\"/i",
            ];
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $sql, $m)) {
                    $pt = preg_replace('/[^a-z0-9_-]/i', '', (string) $m[1]);
                    if (is_string($pt) && $pt !== '') {
                        return $pt;
                    }
                }
            }
        }

        $filtered = apply_filters('xabia_catalog_post_type', '', $config);

        return is_string($filtered) ? preg_replace('/[^a-z0-9_-]/i', '', $filtered) : '';
    }

    /**
     * Etiqueta plural del ente del catálogo (agnóstico: productos, eventos, entes…).
     *
     * @param array<string, mixed> $config
     */
    public static function resolve_catalog_entity_plural_label(array $config): string {
        $custom = apply_filters('xabia_catalog_entity_plural_label', '', $config);
        if (is_string($custom) && $custom !== '') {
            return $custom;
        }

        foreach (['catalog_post_type', 'wp_post_type', 'entity_post_type'] as $key) {
            $explicit_pt = preg_replace('/[^a-z0-9_-]/i', '', trim((string) ($config[$key] ?? '')));
            if ($explicit_pt === 'empresa') {
                return __('empresas', 'xabia-intelligence');
            }
        }

        $addon = sanitize_key((string) ($config['addon_slug'] ?? ''));
        $central_slug = defined('XABIA_CENTRAL_SLUG') ? XABIA_CENTRAL_SLUG : 'xabia_central';
        if ($addon === $central_slug) {
            return __('empresas', 'xabia-intelligence');
        }

        $by_addon = (array) apply_filters('xabia_catalog_entity_plural_by_addon', [
            'woo'          => __('productos', 'xabia-intelligence'),
            'mec'          => __('eventos', 'xabia-intelligence'),
            'amelia'       => __('servicios', 'xabia-intelligence'),
            'qr'           => __('puntos de interés', 'xabia-intelligence'),
            'xabia_central'=> __('empresas', 'xabia-intelligence'),
        ], $config);
        if ($addon !== '' && !empty($by_addon[$addon])) {
            return (string) $by_addon[$addon];
        }

        $post_type = self::resolve_catalog_post_type($config);
        if ($post_type === 'empresa') {
            return __('empresas', 'xabia-intelligence');
        }
        if ($post_type !== '' && function_exists('get_post_type_object')) {
            $pto = get_post_type_object($post_type);
            if ($pto instanceof WP_Post_Type && !empty($pto->labels->name)) {
                return function_exists('mb_strtolower')
                    ? mb_strtolower((string) $pto->labels->name)
                    : strtolower((string) $pto->labels->name);
            }
        }

        return __('entes', 'xabia-intelligence');
    }

    /**
     * Etiqueta singular del ente del catálogo.
     *
     * @param array<string, mixed> $config
     */
    public static function resolve_catalog_entity_singular_label(array $config): string {
        $custom = apply_filters('xabia_catalog_entity_singular_label', '', $config);
        if (is_string($custom) && $custom !== '') {
            return $custom;
        }

        foreach (['catalog_post_type', 'wp_post_type', 'entity_post_type'] as $key) {
            $explicit_pt = preg_replace('/[^a-z0-9_-]/i', '', trim((string) ($config[$key] ?? '')));
            if ($explicit_pt === 'empresa') {
                return __('empresa', 'xabia-intelligence');
            }
        }

        $addon = sanitize_key((string) ($config['addon_slug'] ?? ''));
        $central_slug = defined('XABIA_CENTRAL_SLUG') ? XABIA_CENTRAL_SLUG : 'xabia_central';
        if ($addon === $central_slug) {
            return __('empresa', 'xabia-intelligence');
        }

        $by_addon = (array) apply_filters('xabia_catalog_entity_singular_by_addon', [
            'woo'          => __('producto', 'xabia-intelligence'),
            'mec'          => __('evento', 'xabia-intelligence'),
            'amelia'       => __('servicio', 'xabia-intelligence'),
            'qr'           => __('punto de interés', 'xabia-intelligence'),
            'xabia_central'=> __('empresa', 'xabia-intelligence'),
        ], $config);
        if ($addon !== '' && !empty($by_addon[$addon])) {
            return (string) $by_addon[$addon];
        }

        $post_type = self::resolve_catalog_post_type($config);
        if ($post_type === 'empresa') {
            return __('empresa', 'xabia-intelligence');
        }
        if ($post_type !== '' && function_exists('get_post_type_object')) {
            $pto = get_post_type_object($post_type);
            if ($pto instanceof WP_Post_Type && !empty($pto->labels->singular_name)) {
                return function_exists('mb_strtolower')
                    ? mb_strtolower((string) $pto->labels->singular_name)
                    : strtolower((string) $pto->labels->singular_name);
            }
        }

        return __('ente', 'xabia-intelligence');
    }

    /**
     * Etiqueta de taxonomía legible en el idioma del visitante (p. ej. Équitation → Equitación).
     */
    public static function localize_taxonomy_term_label(string $name, string $lang = ''): string {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        if ($lang === '' && function_exists('apply_filters')) {
            $lang = (string) apply_filters('wpml_current_language', '');
        }
        if ($lang === '' && function_exists('get_locale')) {
            $lang = substr((string) get_locale(), 0, 2);
        }
        $lang = substr(sanitize_key($lang), 0, 5);
        if ($lang === 'es' || $lang === 'ca' || $lang === 'gl' || $lang === '') {
            $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
            if (function_exists('remove_accents')) {
                $key = remove_accents($key);
            }
            $map = [
                'equitation'        => __('Equitación', 'xabia-intelligence'),
                'aquatiques'        => __('Actividades acuáticas', 'xabia-intelligence'),
                'nautisme'          => __('Náutica', 'xabia-intelligence'),
                'terre'             => __('Tierra', 'xabia-intelligence'),
                'air'               => __('Aire', 'xabia-intelligence'),
                'montagne'          => __('Montaña', 'xabia-intelligence'),
                'randonnee'         => __('Senderismo', 'xabia-intelligence'),
                'randonnee pedestre'=> __('Senderismo', 'xabia-intelligence'),
                'cyclisme'          => __('Ciclismo', 'xabia-intelligence'),
                'escalade'          => __('Escalada', 'xabia-intelligence'),
                'speleologie'       => __('Espeleología', 'xabia-intelligence'),
            ];
            if (isset($map[$key])) {
                return (string) $map[$key];
            }
        }
        if (function_exists('apply_filters') && $lang !== '') {
            $translated = apply_filters('wpml_translate_single_string', $name, 'WordPress', 'taxonomy term: ' . $name);
            if (is_string($translated) && trim($translated) !== '' && trim($translated) !== $name) {
                return trim($translated);
            }
        }

        return $name;
    }

    /**
     * Meta keys / columnas del ente para listados nativos (desde mapeo attributes del agente).
     *
     * @param array<string, mixed> $config
     *
     * @return array{title_meta: string, location_meta: string, slug_column: string}
     */
    public static function resolve_catalog_entity_meta_keys(array $config): array {
        $mapping = self::catalog_attributes_mapping($config);
        $title_meta = '';
        $location_meta = '';
        $slug_column = 'post_name';

        foreach ($mapping as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $col = trim((string) ($attr['csv_col'] ?? ''));
            if ($col === '') {
                continue;
            }
            if (!empty($attr['is_ente'])) {
                if (self::catalog_column_is_post_field($col)) {
                    if (strcasecmp($col, 'post_name') === 0 || strcasecmp($col, 'Slug_Empresa') === 0) {
                        $slug_column = 'post_name';
                    }
                } else {
                    $title_meta = $col;
                }
                $label_col = trim((string) ($attr['ente_label_col'] ?? ''));
                if ($label_col !== '') {
                    if (self::catalog_column_is_post_field($label_col)) {
                        if (strcasecmp($label_col, 'post_title') !== 0) {
                            $title_meta = '';
                        }
                    } else {
                        $title_meta = $label_col;
                    }
                }
            }
            $role = strtolower(trim((string) ($attr['visual_role'] ?? '')));
            $label = mb_strtolower(trim((string) ($attr['label'] ?? '')), 'UTF-8');
            $col_l = mb_strtolower($col, 'UTF-8');
            if ($location_meta === ''
                && (in_array($role, ['map', 'location', 'ubicacion', 'ubicación'], true)
                    || preg_match('/\b(local|ubic|municip|zona|territor|lugar|direccion|dirección)\b/u', $label)
                    || preg_match('/\b(local|ubic|municip|zona|territor|lugar|direccion|dirección|localiz)\b/u', $col_l))) {
                if (!self::catalog_column_is_post_field($col)) {
                    $location_meta = $col;
                }
            }
            if (strcasecmp($col, 'Slug_Empresa') === 0 || strcasecmp($col, 'post_name') === 0) {
                $slug_column = 'post_name';
            }
        }

        if ($title_meta === '' && $mapping !== []) {
            foreach ($mapping as $attr) {
                if (!is_array($attr)) {
                    continue;
                }
                $col = trim((string) ($attr['csv_col'] ?? ''));
                if ($col === '' || self::catalog_column_is_post_field($col)) {
                    continue;
                }
                $role = strtolower(trim((string) ($attr['visual_role'] ?? '')));
                if (in_array($role, ['title', 'título', 'titulo'], true)) {
                    $title_meta = $col;
                    break;
                }
            }
        }
        if ($title_meta === '' && $mapping !== []) {
            foreach ($mapping as $attr) {
                if (!is_array($attr)) {
                    continue;
                }
                $col = trim((string) ($attr['csv_col'] ?? ''));
                if ($col === '' || self::catalog_column_is_post_field($col)) {
                    continue;
                }
                $label = mb_strtolower(trim((string) ($attr['label'] ?? '')), 'UTF-8');
                if (preg_match('/\b(nombre|titulo|título|name|title|empresa)\b/u', $label)) {
                    $title_meta = $col;
                    break;
                }
            }
        }
        if ($location_meta === '' && $mapping !== []) {
            foreach ($mapping as $attr) {
                if (!is_array($attr)) {
                    continue;
                }
                $col = trim((string) ($attr['csv_col'] ?? ''));
                if ($col === '' || self::catalog_column_is_post_field($col)) {
                    continue;
                }
                $label = mb_strtolower(trim((string) ($attr['label'] ?? '')), 'UTF-8');
                if (preg_match('/\b(local|ubic|municip|zona|territor|lugar|direccion|dirección|location)\b/u', $label)) {
                    $location_meta = $col;
                    break;
                }
            }
        }

        /** @var array{title_meta: string, location_meta: string, slug_column: string} $out */
        $out = apply_filters('xabia_catalog_entity_meta_keys', [
            'title_meta'    => $title_meta,
            'location_meta' => $location_meta,
            'slug_column'   => $slug_column,
        ], $config);

        return [
            'title_meta'    => trim((string) ($out['title_meta'] ?? '')),
            'location_meta' => trim((string) ($out['location_meta'] ?? '')),
            'slug_column'   => trim((string) ($out['slug_column'] ?? 'post_name')) ?: 'post_name',
        ];
    }

    /**
     * Meta keys de contacto (teléfono, email, web…) desde visual_role / etiqueta del mapeo.
     *
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    public static function resolve_catalog_contact_meta_keys(array $config): array {
        $mapping = self::catalog_attributes_mapping($config);
        $roles = (array) apply_filters('xabia_catalog_contact_visual_roles', [
            'tel', 'phone', 'web', 'email', 'contact',
        ], $config);
        $keys = [];
        foreach ($mapping as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $col = trim((string) ($attr['csv_col'] ?? ''));
            if ($col === '' || self::catalog_column_is_post_field($col)) {
                continue;
            }
            $role = strtolower(trim((string) ($attr['visual_role'] ?? '')));
            $lab = mb_strtolower(trim((string) ($attr['label'] ?? '')), 'UTF-8');
            $col_l = mb_strtolower(ltrim($col, '@'), 'UTF-8');
            $role_hit = $role !== '' && in_array($role, array_map('strtolower', $roles), true);
            $label_hit = $lab !== '' && preg_match(
                '/\b(tel[eé]fono|telefono|phone|email|correo|e-?mail|web|url|contacto|reserva|whatsapp)\b/u',
                $lab
            ) === 1;
            $col_hit = $col_l !== '' && preg_match(
                '/\b(tel|phone|email|correo|mail|web|url|contact|reserva|whatsapp)\b/u',
                $col_l
            ) === 1;
            if ($role_hit || $label_hit || $col_hit) {
                $keys[] = ltrim($col, '@');
            }
        }

        return array_values(array_unique(array_filter(
            (array) apply_filters('xabia_catalog_contact_meta_keys', $keys, $config)
        )));
    }

    /**
     * Meta keys de imagen (fotos/galería) desde visual_role img / etiqueta del mapeo.
     *
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    public static function resolve_catalog_image_meta_keys(array $config): array {
        return self::resolve_catalog_visual_role_meta_keys($config, 'image', [
            'img', 'image', 'photo', 'thumbnail',
        ], '/\b(imagen|image|foto|photo|thumbnail|miniatura)\b/u', '/\b(img|image|imagen|foto|photo|thumbnail)\b/u');
    }

    /**
     * Meta keys de logotipo desde visual_role logotipo / etiqueta del mapeo.
     *
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    public static function resolve_catalog_logotipo_meta_keys(array $config): array {
        return self::resolve_catalog_visual_role_meta_keys($config, 'logotipo', [
            'logotipo',
        ], '/\b(logotipo|logo)\b/u', '/\b(logotipo|logo)\b/u');
    }

    /**
     * ¿El atributo mapeado es un campo de medio (imagen / logotipo)?
     *
     * @param array<string, mixed> $attr
     */
    public static function attribute_is_media_field(array $attr): bool {
        $role = strtolower(trim((string) ($attr['visual_role'] ?? 'none')));
        if (in_array($role, ['img', 'image', 'logotipo', 'logo', 'thumbnail', 'photo', 'foto'], true)) {
            return true;
        }
        $col = (string) ($attr['csv_col'] ?? '');
        $label = (string) ($attr['label'] ?? $col);

        return (bool) preg_match('/\b(empresa_logo|logotipo|logo|imagen|image|foto|photo|thumbnail)\b/iu', $col . ' ' . $label);
    }

    /**
     * Resuelve un valor de medio (ID de attachment o URL) a URL absoluta https.
     * En SQL remoto usa {@see Xabia_SQL_Connector::resolve_attachment_url}; local usa wp_get_attachment_url.
     *
     * @param array<string, mixed> $options project_id, sql_config, project_config
     */
    public static function resolve_media_value_to_absolute_url(string $raw, array $options = []): string {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $raw)) {
            $public = '';
            if (isset($options['sql_config']) && is_array($options['sql_config']) && class_exists('Xabia_SQL_Connector', false)) {
                $manual = trim((string) ($options['sql_config']['public_site_url'] ?? ''));
                if ($manual !== '') {
                    $public = untrailingslashit(esc_url_raw($manual));
                }
            }
            return $public !== '' ? Xabia_SQL_Connector::rewrite_media_url_to_public_base($raw, $public) : $raw;
        }
        if (strpos($raw, '//') === 0 && strlen($raw) > 2) {
            return 'https:' . $raw;
        }
        if (!ctype_digit($raw)) {
            return '';
        }
        $id = (int) $raw;
        if ($id < 1) {
            return '';
        }

        $sql = [];
        if (isset($options['sql_config']) && is_array($options['sql_config'])) {
            $sql = $options['sql_config'];
        } elseif (!empty($options['project_config']['sql_config']) && is_array($options['project_config']['sql_config'])) {
            $sql = $options['project_config']['sql_config'];
        } else {
            $pid = isset($options['project_id']) ? sanitize_key((string) $options['project_id']) : '';
            if ($pid !== '') {
                $projects = get_option('xabia_projects_config', []);
                if (is_array($projects[$pid]['sql_config'] ?? null)) {
                    $sql = $projects[$pid]['sql_config'];
                }
            }
        }

        if (!empty($sql['host']) && class_exists('Xabia_SQL_Connector', false)) {
            $url = Xabia_SQL_Connector::resolve_attachment_url($sql, $id);
            if (is_string($url) && preg_match('#^https?://#i', $url)) {
                return $url;
            }
        }

        $local = wp_get_attachment_url($id);

        return is_string($local) && preg_match('#^https?://#i', $local) ? $local : '';
    }

    /**
     * Durante la ingesta: convierte IDs de medios en URLs absolutas en meta y anexa
     * «[Imagen disponible: URL]» al chunk (una vez por URL).
     *
     * @param array<string, string>            $meta_array
     * @param array<int, array<string, mixed>> $mapping
     * @param array<string, mixed>             $options
     * @return array{meta_array: array<string, string>, text_blob: string}
     */
    public static function finalize_media_urls_in_ingest(array $meta_array, string $text_blob, array $mapping = [], array $options = []): array {
        $urls = [];
        $media_keys = [];

        if (!empty($mapping)) {
            foreach ($mapping as $attr) {
                if (!is_array($attr) || !self::attribute_is_media_field($attr)) {
                    continue;
                }
                $col = (string) ($attr['csv_col'] ?? '');
                $label = (string) ($attr['label'] ?? $col);
                $key = sanitize_key($label !== '' ? $label : $col);
                if ($key !== '') {
                    $media_keys[] = $key;
                }
                if ($col !== '') {
                    $media_keys[] = sanitize_key($col);
                }
            }
        }
        $media_keys = array_values(array_unique(array_merge($media_keys, [
            'empresa_logo', 'logotipo', 'logo', 'imagen', 'image', 'foto', 'photo', 'thumbnail', 'url_imagen', 'imagen_url',
        ])));

        foreach ($media_keys as $key) {
            if (empty($meta_array[$key])) {
                continue;
            }
            $raw = trim((string) $meta_array[$key]);
            if ($raw === '') {
                continue;
            }
            $url = self::resolve_media_value_to_absolute_url($raw, $options);
            if ($url === '') {
                // No persistir IDs huérfanos que el chat no podrá resolver.
                if (ctype_digit($raw)) {
                    unset($meta_array[$key]);
                }
                continue;
            }
            $meta_array[$key] = $url;
            $urls[] = $url;
        }

        // Heurística: cualquier meta restante con «logo|imagen» y valor numérico.
        foreach ($meta_array as $key => $val) {
            if (!is_string($key) || !is_scalar($val)) {
                continue;
            }
            if (!preg_match('/logo|imagen|image|foto|photo|thumbnail/i', $key)) {
                continue;
            }
            $raw = trim((string) $val);
            if ($raw === '' || preg_match('#^https?://#i', $raw)) {
                if (preg_match('#^https?://#i', $raw)) {
                    $urls[] = $raw;
                }
                continue;
            }
            $url = self::resolve_media_value_to_absolute_url($raw, $options);
            if ($url === '') {
                if (ctype_digit($raw)) {
                    unset($meta_array[$key]);
                }
                continue;
            }
            $meta_array[$key] = $url;
            $urls[] = $url;
        }

        $urls = array_values(array_unique(array_filter($urls)));
        if ($urls !== []) {
            $meta_array['__image_url'] = $urls[0];
            if (count($urls) > 1) {
                $meta_array['__image_urls'] = implode('|', $urls);
            }
            $text_blob = self::append_imagen_disponible_markers($text_blob, $urls);
        }

        return [
            'meta_array' => $meta_array,
            'text_blob'  => $text_blob,
        ];
    }

    /**
     * Marcador canónico en el chunk para el LLM / [ACTION:IMG:].
     *
     * @param list<string> $urls
     */
    public static function append_imagen_disponible_markers(string $content_chunk, array $urls): string {
        $content_chunk = (string) $content_chunk;
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            $marker = '[Imagen disponible: ' . $url . ']';
            if (stripos($content_chunk, $marker) !== false) {
                continue;
            }
            if (preg_match('/\[Imagen disponible:\s*' . preg_quote($url, '/') . '\s*\]/iu', $content_chunk)) {
                continue;
            }
            // Sustituir bloques legacy de mapeo con ID crudo.
            $content_chunk = preg_replace(
                '/\n*=== IMAGEN \(mapeo[^]]*\) ===\s*\n(?:imagen|logotipo|empresa_logo):\s*\d+\s*/iu',
                "\n",
                $content_chunk
            ) ?? $content_chunk;
            $content_chunk = rtrim($content_chunk) . "\n" . $marker;
        }

        return $content_chunk;
    }

    /**
     * Extrae URLs de marcadores [Imagen disponible: …] en un chunk/contexto.
     *
     * @return list<string>
     */
    public static function extract_imagen_disponible_urls(string $text): array {
        if ($text === '' || !preg_match_all('/\[Imagen disponible:\s*(https?:\/\/[^\s\]]+)\s*\]/iu', $text, $m)) {
            return [];
        }

        return array_values(array_unique(array_map('trim', $m[1])));
    }

    /**
     * @param list<string> $default_roles
     *
     * @return list<string>
     */
    private static function resolve_catalog_visual_role_meta_keys(
        array $config,
        string $kind,
        array $default_roles,
        string $label_pattern,
        string $col_pattern
    ): array {
        $mapping = self::catalog_attributes_mapping($config);
        $filter = $kind === 'logotipo' ? 'xabia_catalog_logotipo_visual_roles' : 'xabia_catalog_image_visual_roles';
        $meta_filter = $kind === 'logotipo' ? 'xabia_catalog_logotipo_meta_keys' : 'xabia_catalog_image_meta_keys';
        $roles = (array) apply_filters($filter, $default_roles, $config);
        $keys = [];
        foreach ($mapping as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $col = trim((string) ($attr['csv_col'] ?? ''));
            $col = ltrim($col, '@');
            if ($col === '' || self::catalog_column_is_post_field($col)) {
                continue;
            }
            $role = strtolower(trim((string) ($attr['visual_role'] ?? '')));
            $lab = mb_strtolower(trim((string) ($attr['label'] ?? '')), 'UTF-8');
            $col_l = mb_strtolower($col, 'UTF-8');
            $role_hit = $role !== '' && in_array($role, array_map('strtolower', $roles), true);
            $label_hit = $lab !== '' && @preg_match($label_pattern, $lab) === 1;
            $col_hit = $col_l !== '' && @preg_match($col_pattern, $col_l) === 1;
            if ($role_hit || $label_hit || $col_hit) {
                $keys[] = $col;
            }
        }

        return array_values(array_unique(array_filter(
            (array) apply_filters($meta_filter, $keys, $config)
        )));
    }

    /**
     * SQL principal del proyecto (single o primera fuente multi).
     *
     * @param array<string, mixed> $config
     */
    public static function catalog_source_sql(array $config): string {
        $sql = trim((string) (($config['sql_config']['query'] ?? '')));
        if ($sql !== '') {
            return $sql;
        }
        foreach ((array) ($config['sources'] ?? []) as $src) {
            if (!is_array($src)) {
                continue;
            }
            $q = trim((string) (($src['sql_config']['query'] ?? '') ?: ($src['query'] ?? '')));
            if ($q !== '') {
                return $q;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<array<string, mixed>>
     */
    public static function catalog_attributes_mapping(array $config): array {
        $mapping = is_array($config['attributes'] ?? null) ? $config['attributes'] : [];
        if ($mapping !== []) {
            return $mapping;
        }
        foreach ((array) ($config['sources'] ?? []) as $src) {
            if (!is_array($src)) {
                continue;
            }
            $attrs = is_array($src['attributes'] ?? null) ? $src['attributes'] : [];
            if ($attrs !== []) {
                return $attrs;
            }
        }

        return [];
    }

    /**
     * Prefijo de cabecera del ente en content_chunk (p. ej. «EMPRESA:», «HOTEL:») desde el mapeo is_ente.
     *
     * @param array<string, mixed> $config
     */
    public static function resolve_rag_entity_header_prefix(array $config): string {
        $custom = apply_filters('xabia_rag_entity_header_prefix', '', $config);
        if (is_string($custom) && trim($custom) !== '') {
            return self::normalize_rag_entity_header_prefix($custom);
        }
        foreach (self::catalog_attributes_mapping($config) as $attr) {
            if (!is_array($attr) || empty($attr['is_ente'])) {
                continue;
            }
            $label = trim((string) ($attr['label'] ?? ''));
            if ($label !== '') {
                return self::normalize_rag_entity_header_prefix($label);
            }
        }

        return '';
    }

    private static function normalize_rag_entity_header_prefix(string $label): string {
        $label = trim($label);
        if ($label === '') {
            return '';
        }
        if (mb_substr($label, -1) !== ':') {
            $label .= ':';
        }

        return $label;
    }

    public static function catalog_column_is_post_field(string $col): bool {
        $col = strtolower(trim($col));

        return in_array($col, [
            'id',
            'post_title',
            'post_name',
            'post_content',
            'post_excerpt',
            'post_status',
            'post_type',
            'slug_empresa',
        ], true);
    }

    /**
     * Empresas publicadas en WP según post_type del proyecto (diagnóstico / sync).
     *
     * @param array<string, mixed>|null $config
     */
    public static function count_published_source_posts(string $project_id, ?array $config = null): int {
        $project_id = trim(sanitize_text_field($project_id));
        if ($project_id === '' || !function_exists('get_option')) {
            return 0;
        }
        if ($config === null) {
            $projects = get_option('xabia_projects_config', []);
            $config = is_array($projects[$project_id] ?? null) ? $projects[$project_id] : [];
        }
        $post_type = self::resolve_catalog_post_type($config);
        if ($post_type === '' || !function_exists('post_type_exists') || !post_type_exists($post_type)) {
            return 0;
        }

        global $wpdb;
        $lang_scope = apply_filters('xabia_knowledge_lang_scope', 'primary', $project_id, $config);
        if ($lang_scope === 'all' || !self::is_multilingual_site()) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p WHERE p.post_type = %s AND p.post_status = 'publish'",
                $post_type
            ));
        }

        $primary = esc_sql(self::project_language_code($config));
        if ($primary === '') {
            return 0;
        }
        $prefix = $wpdb->prefix;
        $sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p";
        $icl = self::resolve_wpml_translations_table($prefix, $wpdb);
        if ($icl !== '') {
            $sql .= " INNER JOIN `{$icl}` xabia_wpml_tr"
                . " ON xabia_wpml_tr.element_id = p.ID"
                . " AND xabia_wpml_tr.element_type = CONCAT('post_', p.post_type)"
                . " AND xabia_wpml_tr.language_code = '{$primary}'";
        } elseif (self::polylang_active()) {
            $sql .= " INNER JOIN {$prefix}term_relationships xabia_pll_tr ON xabia_pll_tr.object_id = p.ID"
                . " INNER JOIN {$prefix}term_taxonomy xabia_pll_tt ON xabia_pll_tt.term_taxonomy_id = xabia_pll_tr.term_taxonomy_id AND xabia_pll_tt.taxonomy = 'language'"
                . " INNER JOIN {$prefix}terms xabia_pll_lang ON xabia_pll_lang.term_id = xabia_pll_tt.term_id AND xabia_pll_lang.slug = '{$primary}'";
        }
        $sql .= $wpdb->prepare(' WHERE p.post_type = %s AND p.post_status = %s', $post_type, 'publish');

        return (int) $wpdb->get_var($sql);
    }

    /** @var array{fetched: int, passports: int} */
    public static $last_lightweight_catalog_stats = ['fetched' => 0, 'passports' => 0];

    /**
     * Taxonomía de actividad del catálogo (desde mapeo / config del agente).
     *
     * @param array<string, mixed> $config
     */
    public static function resolve_catalog_activity_taxonomy(array $config): string {
        $explicit = trim((string) ($config['catalog_activity_taxonomy'] ?? ''));
        if ($explicit !== '') {
            $explicit = preg_replace('/[^a-z0-9_-]/i', '', $explicit);

            return is_string($explicit) ? $explicit : '';
        }
        $sql = self::catalog_source_sql($config);
        if (preg_match("/tt\.taxonomy\s*=\s*'([^']+)'/i", $sql, $m)) {
            $tax = preg_replace('/[^a-z0-9_-]/i', '', (string) $m[1]);

            return is_string($tax) && $tax !== '' ? $tax : 'categoria-de-actividad';
        }

        return (string) apply_filters('xabia_catalog_activity_taxonomy', 'categoria-de-actividad', $config);
    }

    /**
     * SQL ligera: ente + taxonomía de actividad (sin experiencias ni meta pesada).
     *
     * @param array{title_meta?: string, location_meta?: string} $entity_meta
     */
    public static function build_lightweight_empresa_catalog_sql(
        string $post_type,
        string $prefix,
        string $taxonomy,
        array $entity_meta = []
    ): string {
        $post_type = preg_replace('/[^a-z0-9_-]/i', '', $post_type);
        $taxonomy = preg_replace('/[^a-z0-9_-]/i', '', $taxonomy);
        if ($post_type === '' || $taxonomy === '') {
            return '';
        }
        $title_meta = trim((string) ($entity_meta['title_meta'] ?? ''));
        $location_meta = trim((string) ($entity_meta['location_meta'] ?? ''));
        $p = rtrim($prefix, '_') . '_';

        $title_expr = 'p.post_title';
        if ($title_meta !== '') {
            $title_key = esc_sql($title_meta);
            $title_expr = "COALESCE(NULLIF(TRIM(MAX(CASE WHEN pm.meta_key = '{$title_key}' THEN pm.meta_value END)), ''), p.post_title)";
        }
        $loc_expr = 'NULL';
        if ($location_meta !== '') {
            $loc_key = esc_sql($location_meta);
            $loc_expr = "MAX(CASE WHEN pm.meta_key = '{$loc_key}' THEN pm.meta_value END)";
        }
        $meta_in = array_values(array_unique(array_filter([$title_meta, $location_meta])));
        $pm_join = '';
        if ($meta_in !== []) {
            $quoted = array_map(static function (string $key): string {
                return "'" . esc_sql($key) . "'";
            }, $meta_in);
            $pm_join = 'LEFT JOIN ' . $p . 'postmeta pm ON pm.post_id = p.ID AND pm.meta_key IN (' . implode(', ', $quoted) . ')';
        }

        return "SELECT
  p.ID,
  {$title_expr} AS empresa,
  p.post_name AS Slug_Empresa,
  MAX(CASE WHEN tt.parent = 0 THEN t.name END) AS categoria,
  GROUP_CONCAT(DISTINCT CASE WHEN tt.parent > 0 THEN t.name END ORDER BY t.name SEPARATOR ', ') AS subcategorias_raw,
  {$loc_expr} AS empresa_localizacion
FROM {$p}posts p
{$pm_join}
LEFT JOIN {$p}term_relationships tr ON tr.object_id = p.ID
LEFT JOIN {$p}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = '{$taxonomy}'
LEFT JOIN {$p}terms t ON t.term_id = tt.term_id
WHERE p.post_type = '{$post_type}' AND p.post_status = 'publish'
GROUP BY p.ID, p.post_title, p.post_name
ORDER BY p.post_title";
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function map_lightweight_catalog_row(array &$row): void {
        if (!empty($row['subcategorias_raw'])) {
            $parts = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/u', (string) $row['subcategorias_raw']))));
            foreach ($parts as $i => $part) {
                $n = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
                $row['subcategoria_' . $n] = $part;
            }
        }
        unset($row['subcategorias_raw']);
        if (empty($row['categoria']) && !empty($row['subcategoria_01'])) {
            $row['categoria'] = $row['subcategoria_01'];
        }
    }

    /**
     * Filas de catálogo desde WP (local o SQL remota del proyecto), con caché breve.
     *
     * @param array<string, mixed> $config
     * @return array<int, array<string, mixed>>
     */
    public static function fetch_lightweight_catalog_rows(string $project_id, array $config): array {
        self::$last_lightweight_catalog_stats = ['fetched' => 0, 'passports' => 0];
        $post_type = self::resolve_catalog_post_type($config);
        if ($post_type === '') {
            return [];
        }
        $taxonomy = self::resolve_catalog_activity_taxonomy($config);
        $entity_meta = self::resolve_catalog_entity_meta_keys($config);
        $cache_key = 'xabia_lwcat_' . md5($project_id . '|' . $post_type . '|' . $taxonomy . '|' . ($entity_meta['title_meta'] ?? '') . '|' . ($entity_meta['location_meta'] ?? ''));
        if (function_exists('get_transient')) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                self::$last_lightweight_catalog_stats['fetched'] = count($cached);

                return $cached;
            }
        }

        global $wpdb;
        $sql_config = is_array($config['sql_config'] ?? null) ? $config['sql_config'] : [];
        $manual_prefix = trim((string) ($sql_config['prefix'] ?? ''));
        $remote_host = trim((string) ($sql_config['host'] ?? ''));
        if ($manual_prefix !== '') {
            $real_prefix = rtrim($manual_prefix, '_') . '_';
        } elseif ($remote_host !== '') {
            $real_prefix = 'wp_';
        } else {
            $real_prefix = $wpdb->prefix;
        }

        $sql = self::build_lightweight_empresa_catalog_sql($post_type, $real_prefix, $taxonomy, $entity_meta);
        if ($sql === '') {
            return [];
        }
        $sql = self::apply_primary_language_sql_filter($sql, $project_id, $config, $real_prefix);

        $raw_data = [];
        $use_local_wpdb = ($remote_host === '' && ($manual_prefix === '' || $manual_prefix . '_' === $wpdb->prefix || $manual_prefix === rtrim($wpdb->prefix, '_')));
        if ($use_local_wpdb) {
            $raw_data = $wpdb->get_results($sql, ARRAY_A);
        } else {
            if (!class_exists('Xabia_SQL_Connector')) {
                $path = (defined('XABIA_PATH') ? XABIA_PATH : plugin_dir_path(dirname(__FILE__)) . '../')
                    . 'integrations/class-xabia-sql-connector.php';
                if (is_readable($path)) {
                    require_once $path;
                }
            }
            if (!class_exists('Xabia_SQL_Connector')) {
                return [];
            }
            $sql_config['query'] = $sql;
            $raw_data = Xabia_SQL_Connector::fetch_data($sql_config);
            if (is_wp_error($raw_data)) {
                return [];
            }
        }
        if (!is_array($raw_data) || $raw_data === []) {
            return [];
        }

        $raw_data = self::filter_primary_language_rows($raw_data, $project_id);
        foreach ($raw_data as &$row) {
            if (is_array($row)) {
                self::map_lightweight_catalog_row($row);
            }
        }
        unset($row);

        self::$last_lightweight_catalog_stats['fetched'] = count($raw_data);
        if (function_exists('set_transient')) {
            set_transient($cache_key, $raw_data, 300);
        }

        return $raw_data;
    }

    /**
     * Hash SHA-256 de un chunk de conocimiento (Delta Sync v2.0).
     */
    public static function compute_chunk_hash(string $text_blob): string {
        if (class_exists('Xabia_DB', false)) {
            return Xabia_DB::compute_content_hash($text_blob);
        }
        if (class_exists('Xabia_Knowledge_Optimizer', false)) {
            return Xabia_Knowledge_Optimizer::content_hash($text_blob);
        }
        $text_blob = trim($text_blob);

        return $text_blob === '' ? '' : hash('sha256', $text_blob);
    }

    /**
     * Motor de Delta Sync:
     * - Hash idéntico (SHA-256 o MD5 legado): solo metadatos volátiles (0 tokens de embedding).
     * - Hash distinto: actualiza content_chunk, nuevo hash y anula el vector.
     * - Sin fila: inserta y deja el embedding pendiente.
     *
     * @param array<string, mixed> $meta_array
     * @param array{source_file?: string, federation_node_id?: string|null, identity?: array<string, string>} $extras
     * @param object|null $existing Fila ya resuelta (p. ej. lookup canónico/legado del bridge).
     * @return array{action: 'unchanged'|'content_update'|'insert'|'error', needs_embedding: bool, row_id: int}
     */
    public static function process_chunk_delta_sync(
        string $project_id,
        string $ente_id,
        string $text_blob,
        array $meta_array,
        string $source_record_id = '',
        array $extras = [],
        $existing = null
    ): array {
        $project_id = sanitize_key($project_id);
        $ente_id = self::canonical_slug($ente_id !== '' ? $ente_id : 'global');
        if ($ente_id === '') {
            $ente_id = 'global';
        }
        $text_blob = trim($text_blob);
        if ($project_id === '' || $text_blob === '') {
            return ['action' => 'error', 'needs_embedding' => false, 'row_id' => 0];
        }
        if (!class_exists('Xabia_DB', false)) {
            return ['action' => 'error', 'needs_embedding' => false, 'row_id' => 0];
        }

        $new_hash = self::compute_chunk_hash($text_blob);
        if (!is_object($existing) || !isset($existing->id)) {
            $existing = null;
            if ($ente_id !== 'global') {
                $existing = Xabia_DB::find_knowledge_row_by_ente($project_id, $ente_id);
            }
            if ($existing === null && $source_record_id !== '') {
                $existing = Xabia_DB::find_knowledge_row_by_source($project_id, $source_record_id);
            }
        }

        $identity = [
            'ente_id'          => $ente_id,
            'source_record_id' => $source_record_id !== '' ? $source_record_id : $ente_id,
        ];
        if (isset($extras['identity']) && is_array($extras['identity'])) {
            $identity = array_merge($identity, $extras['identity']);
        }

        if ($existing !== null && isset($existing->id)) {
            $stored_hash = (string) ($existing->content_hash ?? '');
            $row_id = (int) $existing->id;
            if ($stored_hash !== '' && Xabia_DB::content_hash_matches($stored_hash, $text_blob)) {
                Xabia_DB::maybe_upgrade_legacy_content_hash($row_id, $text_blob, $stored_hash);
                $prev_meta = [];
                if (!empty($existing->meta_blob)) {
                    $decoded = json_decode((string) $existing->meta_blob, true);
                    if (is_array($decoded)) {
                        $prev_meta = $decoded;
                    }
                }
                $merged = class_exists('Xabia_Knowledge_Optimizer', false)
                    ? Xabia_Knowledge_Optimizer::merge_volatile_meta($prev_meta, $meta_array)
                    : array_merge($prev_meta, $meta_array);
                Xabia_DB::update_knowledge_meta_only($row_id, $merged);

                return [
                    'action'          => 'unchanged',
                    'needs_embedding' => false,
                    'row_id'          => $row_id,
                ];
            }
            $ok = Xabia_DB::update_knowledge_content($row_id, $text_blob, $meta_array, $new_hash, $identity);

            return [
                'action'          => $ok ? 'content_update' : 'error',
                'needs_embedding' => $ok,
                'row_id'          => $row_id,
            ];
        }

        $insert_extras = $extras;
        unset($insert_extras['identity']);
        $insert_extras = array_merge($insert_extras, [
            'source_record_id' => $source_record_id !== '' ? $source_record_id : $ente_id,
            'content_hash'     => $new_hash,
        ]);
        $inserted = Xabia_DB::insert_knowledge_vector_row(
            $project_id,
            $ente_id,
            $text_blob,
            $meta_array,
            $insert_extras
        );
        global $wpdb;
        $row_id = (int) $wpdb->insert_id;

        return [
            'action'          => $inserted ? 'insert' : 'error',
            'needs_embedding' => (bool) $inserted,
            'row_id'          => $row_id,
        ];
    }
}
