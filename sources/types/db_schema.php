<?php
/**
 * Xabia Sources — types/db_schema.php
 * Fuente DB gobernada por schema declarativo (CPT + ACF + tax)
 */

if (!defined('ABSPATH')) exit;

/**
 * Loader principal del tipo db_schema
 * Devuelve filas estructuradas (NO entidades legacy)
 */
function xabia_sources_load_db_schema(array $source): array {

    // 1) Schema requerido
    $schema_id = $source['options']['schema'] ?? null;
    if (!$schema_id || !class_exists('Xabia')) {
        error_log('[Xabia DB_SCHEMA] ❌ Schema no definido o Xabia no cargado.');
        return [];
    }

    try {
        $schema = Xabia::loadSchema($schema_id);
    } catch (Throwable $e) {
        error_log('[Xabia DB_SCHEMA] ❌ Error cargando schema: ' . $e->getMessage());
        return [];
    }

    if (empty($schema['cpt']) || empty($schema['map'])) {
        error_log('[Xabia DB_SCHEMA] ❌ Schema inválido (sin CPT o map).');
        return [];
    }

    // 2) Construir WP_Query desde schema
    $args = [
        'post_type'      => $schema['cpt'],
        'post_status'    => $schema['where']['post_status'] ?? 'publish',
        'posts_per_page' => -1,
    ];

    // meta_query
    if (!empty($schema['where']['meta']) && is_array($schema['where']['meta'])) {
        $args['meta_query'] = [];
        foreach ($schema['where']['meta'] as $key => $value) {
            $args['meta_query'][] = [
                'key'   => $key,
                'value' => $value,
            ];
        }
    }

    // tax_query (opcional)
    if (!empty($schema['where']['tax']) && is_array($schema['where']['tax'])) {
        $args['tax_query'] = ['relation' => 'AND'];
        foreach ($schema['where']['tax'] as $tax => $terms) {
            $args['tax_query'][] = [
                'taxonomy' => $tax,
                'field'    => 'slug',
                'terms'    => (array) $terms,
            ];
        }
    }

    $q = new WP_Query($args);
    if (!$q->have_posts()) {
        return [];
    }

    // 3) Mapear posts según schema
    $rows = [];

    foreach ($q->posts as $post) {
        if ($post instanceof WP_Post) {
            $rows[] = xabia_db_schema_map_post($post, $schema);
        }
    }

    error_log('[Xabia DB_SCHEMA] ✅ ' . count($rows) . ' registros cargados.');
    return $rows;
}

/**
 * Mapea un WP_Post a fila estructurada según schema
 */
function xabia_db_schema_map_post(WP_Post $post, array $schema): array {

    $out = [];

    foreach ($schema['map'] as $key => $path) {

        // Campo compuesto
        if (is_array($path)) {
            $out[$key] = [];
            foreach ($path as $subkey => $subpath) {
                $out[$key][$subkey] = xabia_db_schema_resolve($post, $subpath);
            }
            continue;
        }

        $out[$key] = xabia_db_schema_resolve($post, $path);
    }

    return $out;
}

/**
 * Resuelve una ruta del schema (ID, post_title, acf.*, tax.*)
 */
function xabia_db_schema_resolve(WP_Post $post, string $path) {

    // Core WP
    if ($path === 'ID') return $post->ID;
    if ($path === 'post_title') return $post->post_title;

    // ACF
    if (str_starts_with($path, 'acf.')) {
        return get_field(substr($path, 4), $post->ID);
    }

    // Taxonomías
    if (str_starts_with($path, 'tax.')) {
        [, $taxonomy, $prop] = explode('.', $path, 3);
        $terms = wp_get_post_terms($post->ID, $taxonomy);
        if (empty($terms) || is_wp_error($terms)) return null;
        return $prop === 'slug' ? $terms[0]->slug : $terms[0]->name;
    }

    return null;
}