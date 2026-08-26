<?php
/**
 * Enriquecimiento estructural de chunks RAG (agnóstico: cabeceras/mapeo, sin sinónimos).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Rag_Chunk_Enricher {

    /**
     * @param array<string, mixed>             $row
     * @param array<int, array<string, mixed>> $mapping
     * @param array<string, mixed>             $options project_id, project_config, meta_array
     */
    public static function enrich(string $blob, array $row, array $mapping = [], array $options = []): string {
        $blob = trim($blob);
        if ($blob === '') {
            return $blob;
        }

        $config = self::resolve_config($options);
        if (!self::is_enabled($config)) {
            return $blob;
        }

        $prefix = self::build_structural_prefix($row, $mapping, $options);
        if ($prefix === '') {
            $out = $blob;
        } else {
            $out = $prefix . "\n" . $blob;
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('xabia_rag_enrich_content_chunk', $out, $blob, $row, $mapping, $options);
            if (is_string($filtered) && trim($filtered) !== '') {
                $out = $filtered;
            }
        }

        if (class_exists('Xabia_Knowledge_Text', false)) {
            $out = Xabia_Knowledge_Text::finalize_content_chunk($out);
        }

        return $out;
    }

    /**
     * Documentos locales: prefijo mínimo [Entidad] + cuerpo.
     */
    public static function enrich_document(string $title, string $body, array $options = []): string {
        $title = trim($title);
        $body = trim($body);
        $base = $title !== '' ? ('Tema: ' . $title . "\n\n" . $body) : $body;
        $config = self::resolve_config($options);
        if (!self::is_enabled($config) || $title === '') {
            return $base;
        }
        $prefix = '[Entidad: ' . $title . ']';
        $out = $prefix . "\n" . $base;
        if (function_exists('apply_filters')) {
            $filtered = apply_filters(
                'xabia_rag_enrich_content_chunk',
                $out,
                $base,
                ['title' => $title, 'body' => $body],
                [],
                $options
            );
            if (is_string($filtered) && trim($filtered) !== '') {
                $out = $filtered;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function is_enabled(array $config): bool {
        $rules = is_array($config['rules'] ?? null) ? $config['rules'] : [];
        if (!array_key_exists('rag_chunk_enrichment', $rules)) {
            return true;
        }
        $v = $rules['rag_chunk_enrichment'];
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string) $v));

        return !in_array($s, ['0', 'off', 'false', 'no'], true);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function resolve_config(array $options): array {
        if (isset($options['project_config']) && is_array($options['project_config'])) {
            return $options['project_config'];
        }
        $pid = isset($options['project_id']) ? sanitize_key((string) $options['project_id']) : '';
        if ($pid === '' || !function_exists('get_option')) {
            return [];
        }
        $projects = get_option('xabia_projects_config', []);
        if (!is_array($projects) || !isset($projects[$pid]) || !is_array($projects[$pid])) {
            return [];
        }

        return $projects[$pid];
    }

    /**
     * @param array<string, mixed>             $row
     * @param array<int, array<string, mixed>> $mapping
     * @param array<string, mixed>             $options
     */
    private static function build_structural_prefix(array $row, array $mapping, array $options): string {
        $entity = self::first_row_value($row, ['empresa', 'post_title', 'title', 'Titulo', 'nombre', 'Nombre', 'name']);
        $classification = [];
        $location = [];
        $attributes = [];

        $cat = self::first_row_value($row, ['categoria', 'Categoria', 'category', 'Categorias_Tags']);
        if ($cat !== '') {
            $classification[] = $cat;
        }
        for ($i = 1; $i <= 12; $i++) {
            $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            foreach (["subcategoria_{$n}", "subcategoria_{$i}"] as $col) {
                if (!empty($row[$col]) && trim((string) $row[$col]) !== '') {
                    $classification[] = trim((string) $row[$col]);
                }
            }
        }

        foreach (['term_slug', 'categoria_slug', 'slug'] as $slug_col) {
            if (!empty($row[$slug_col]) && trim((string) $row[$slug_col]) !== '') {
                $classification[] = str_replace('-', ' ', trim((string) $row[$slug_col]));
            }
        }

        $loc = self::first_row_value($row, [
            'empresa_localizacion', 'localizacion', 'ubicacion', 'location', 'ciudad', 'city', 'address', 'direccion',
        ]);
        if ($loc !== '') {
            $location[] = $loc;
        }

        if (!empty($mapping)) {
            foreach ($mapping as $attr) {
                if (!is_array($attr)) {
                    continue;
                }
                $col = (string) ($attr['csv_col'] ?? '');
                $label = (string) ($attr['label'] ?? $col);
                if ($col === '' || !isset($row[$col]) || trim((string) $row[$col]) === '') {
                    continue;
                }
                $val = trim(wp_strip_all_tags((string) $row[$col]));
                $val = preg_replace('/\s+/u', ' ', $val) ?? $val;
                if ($val === '') {
                    continue;
                }
                $role = self::field_semantic_role($col, $label);
                if ($role === 'taxonomy') {
                    $classification[] = $val;
                } elseif ($role === 'location') {
                    $location[] = $val;
                } elseif ($role === 'entity') {
                    if ($entity === '') {
                        $entity = $val;
                    }
                } else {
                    $attributes[] = $label . ': ' . $val;
                }
            }
        }

        $classification = array_values(array_unique(array_filter(array_map('strval', $classification))));
        $location = array_values(array_unique(array_filter(array_map('strval', $location))));
        $attributes = array_values(array_unique(array_filter(array_map('strval', $attributes))));

        foreach (self::taxonomy_labels_from_wp_post($row, $options) as $tax_label) {
            $classification[] = $tax_label;
        }
        $classification = array_values(array_unique(array_filter(array_map('strval', $classification))));

        $keyword_tokens = self::taxonomy_keyword_tokens($classification);
        if (function_exists('apply_filters')) {
            $filtered_kw = apply_filters('xabia_rag_taxonomy_keyword_tokens', $keyword_tokens, $classification, $row, $options);
            if (is_array($filtered_kw)) {
                $keyword_tokens = array_values(array_filter(array_map('strval', $filtered_kw)));
            }
        }

        $parts = [];
        if ($entity !== '') {
            $parts[] = '[Entidad: ' . $entity . ']';
        }
        if ($classification !== []) {
            $parts[] = '[Clasificación: ' . implode(', ', array_slice($classification, 0, 24)) . ']';
        }
        if ($keyword_tokens !== []) {
            $parts[] = '[KEYWORDS: ' . implode(' | ', array_slice($keyword_tokens, 0, 40)) . ']';
        }
        if ($location !== []) {
            $parts[] = '[Ubicación: ' . implode(', ', array_slice($location, 0, 6)) . ']';
        }
        if ($attributes !== []) {
            $parts[] = '[Atributos: ' . implode(' | ', array_slice($attributes, 0, 20)) . ']';
        }

        return implode(' ', $parts);
    }

    /**
     * @param list<string> $keys
     */
    private static function first_row_value(array $row, array $keys): string {
        foreach ($keys as $k) {
            if (!isset($row[$k])) {
                continue;
            }
            $v = trim(wp_strip_all_tags((string) $row[$k]));
            $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }

    /**
     * Rol semántico por nombre de campo/label (esquema), nunca por valor.
     *
     * @return 'entity'|'taxonomy'|'location'|'attr'
     */
    private static function field_semantic_role(string $col, string $label): string {
        $blob = mb_strtolower($col . ' ' . $label, 'UTF-8');
        $blob = str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $blob);

        if (preg_match('/\b(empresa|post_title|titulo|title|nombre|name|entity)\b/u', $blob)) {
            return 'entity';
        }
        if (preg_match('/(categ|subcateg|tag|tipo|taxonom|clasific)/u', $blob)) {
            return 'taxonomy';
        }
        if (preg_match('/(localiz|ubicaci|location|ciudad|city|address|direccion|municipio|provincia)/u', $blob)) {
            return 'location';
        }

        return 'attr';
    }

    /**
     * Etiquetas de taxonomía WP del post (nombres y slugs). Sin diccionarios: solo lo asignado al objeto.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $options
     * @return list<string>
     */
    private static function taxonomy_labels_from_wp_post(array $row, array $options): array {
        unset($options);
        $post_id = 0;
        foreach (['ID', 'id', 'post_id'] as $key) {
            if (!empty($row[$key]) && is_numeric($row[$key])) {
                $post_id = (int) $row[$key];
                break;
            }
        }
        if ($post_id < 1) {
            return [];
        }
        if (!function_exists('get_post_type') || !function_exists('get_object_taxonomies') || !function_exists('wp_get_object_terms')) {
            return [];
        }
        $post_type = get_post_type($post_id);
        if (!is_string($post_type) || $post_type === '') {
            return [];
        }
        $taxes = get_object_taxonomies($post_type, 'names');
        if (!is_array($taxes) || $taxes === []) {
            return [];
        }
        $skip = [
            'language', 'post_translations', 'term_language', 'term_translations',
            'translation_priority', 'origin', 'post_format',
        ];
        $out = [];
        foreach ($taxes as $tax) {
            $tax = (string) $tax;
            if ($tax === '' || in_array($tax, $skip, true)) {
                continue;
            }
            $terms = wp_get_object_terms($post_id, $tax);
            if ((function_exists('is_wp_error') && is_wp_error($terms)) || !is_array($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                if (!is_object($term)) {
                    continue;
                }
                $name = isset($term->name) ? trim((string) $term->name) : '';
                if ($name !== '') {
                    $out[] = $name;
                }
                $slug = isset($term->slug) ? trim((string) $term->slug) : '';
                if ($slug !== '') {
                    $out[] = str_replace('-', ' ', $slug);
                }
            }
        }

        return $out;
    }

    /**
     * Variantes de búsqueda derivadas del propio rótulo (minúsculas, sin acentos, tokens).
     * No introduce sinónimos de dominio.
     *
     * @param list<string> $labels
     * @return list<string>
     */
    public static function taxonomy_keyword_tokens(array $labels): array {
        $seen = [];
        $out = [];
        $function_words = [
            'para', 'como', 'esta', 'este', 'con', 'sin', 'del', 'las', 'los', 'una', 'uno',
            'and', 'the', 'for', 'with', 'from',
        ];
        foreach ($labels as $label) {
            $label = trim(wp_strip_all_tags((string) $label));
            if ($label === '') {
                continue;
            }
            foreach (self::label_search_variants($label) as $token) {
                $key = mb_strtolower($token, 'UTF-8');
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                if (mb_strlen($key, 'UTF-8') < 3) {
                    continue;
                }
                if (in_array($key, $function_words, true)) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = $token;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function label_search_variants(string $label): array {
        $out = [$label];
        $lower = mb_strtolower($label, 'UTF-8');
        $out[] = $lower;
        $folded = self::fold_latin_accents($lower);
        $out[] = $folded;
        $slugish = trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $folded));
        if ($slugish !== '') {
            $out[] = $slugish;
        }
        $pieces = preg_split('/[\s,\/|;·•\-]+/u', $folded) ?: [];
        foreach ($pieces as $piece) {
            $piece = trim((string) $piece);
            if (mb_strlen($piece, 'UTF-8') >= 4) {
                $out[] = $piece;
            }
        }

        return $out;
    }

    private static function fold_latin_accents(string $text): string {
        if (function_exists('remove_accents')) {
            return (string) remove_accents($text);
        }
        $map = [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ];

        return strtr($text, $map);
    }
}
