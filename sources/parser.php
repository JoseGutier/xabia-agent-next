<?php
/**
 * Normaliza claves de una fila CSV:
 *  - minúsculas
 *  - espacios y guiones → _
 *  - elimina símbolos raros (@, %, etc.)
 *  - mantiene sufijos numéricos (_01, _02…)
 */
function xabia_normalize_row_keys(array $row): array {

    $out = [];

    foreach ($row as $k => $v) {

        if (!is_string($k)) continue;

        $key = mb_strtolower(trim($k), 'UTF-8');

        // quitar prefijos raros tipo @empresa_logo
        $key = ltrim($key, '@');

        // reemplazos comunes
        $key = str_replace(
            [' ', '-', '__'],
            '_',
            $key
        );

        // normalizar acentos
        $key = iconv('UTF-8', 'ASCII//TRANSLIT', $key);
        $key = preg_replace('/[^a-z0-9_]/', '', $key);

        // colapsar underscores
        $key = preg_replace('/_+/', '_', $key);
        $key = trim($key, '_');

        $out[$key] = $v;
    }

    return $out;
}
/* ============================================================
 * 1) PARSER ESPECÍFICO AKTIBA — EMPRESA
 * ============================================================ */

/**
 * Normaliza UNA fila del CSV Aktiba → entidad "empresa".
 *
 * Requisitos clave que respeta:
 *  - id, slug, empresa, categoria
 *  - text      → usado para respuestas (más rico)
 *  - text_embed→ usado para embeddings (compacto)
 *  - web, telefono
 *  - raw       → fila cruda normalizada (claves con underscore)
 */
function xabia_parse_aktiba_empresa(array $row): array {

    /* 1. LIMPIEZA DE CLAVES (enterprise) */
    $row = xabia_normalize_row_keys($row);

    /* 2. EMPRESA + SLUG */

    $empresa = trim((string)($row['empresa'] ?? ''));
    if ($empresa === '') {
        $empresa = trim((string)(
            $row['nombre']         ??
            $row['title']          ??
            $row['nombre_empresa'] ??
            ''
        ));
    }

    // Fallback extremo si sigue vacío (no debería pasar, pero protegemos)
    if ($empresa === '') {
        $empresa = 'Empresa sin nombre';
    }

    $slug_source = $row['slug_empresa']
        ?? $row['slug']
        ?? $empresa
        ?? uniqid('empresa_', true);

    $slug = sanitize_title($slug_source);

    // Si sanitize_title deja vacío, generamos slug sintético estable
    if (!$slug) {
        $slug = 'empresa_' . substr(md5($empresa . wp_rand(0, PHP_INT_MAX)), 0, 10);
    }

    /* 3. DESCRIPCIÓN PRINCIPAL */

    $desc = trim((string)(
        $row['descripcion_empresa']
        ?? $row['descripcion']
        ?? $row['descripcion_es']
        ?? ''
    ));

    /* 4. CATEGORÍAS (categoria + subcategoria_01..07) */

    $categorias = [];

    if (!empty($row['categoria'])) {
        $categorias[] = trim((string)$row['categoria']);
    }
    if (!empty($row['tipo_de_empresa'])) {
        $categorias[] = trim((string)$row['tipo_de_empresa']);
    }

    for ($i = 1; $i <= 7; $i++) {
        $key = sprintf('subcategoria_%02d', $i);
        if (!empty($row[$key])) {
            $categorias[] = trim((string)$row[$key]);
        }
    }

    $categorias     = array_values(array_filter(array_unique($categorias)));
    $categoria_str  = implode(' · ', $categorias);
    $company_type   = $categorias[0] ?? ''; // primera categoría como tipo base

    /* 5. EXPERIENCIAS 01–10 */

    $exper = [];
    for ($i = 1; $i <= 10; $i++) {
        $key = sprintf('experiencia_%02d', $i);
        if (!empty($row[$key])) {
            $exper[] = trim((string)$row[$key]);
        }
    }

    /* 6. BENEFICIOS 01–04 */

    $benef = [];
    for ($i = 1; $i <= 4; $i++) {
        $key = sprintf('beneficio_%02d', $i);
        if (!empty($row[$key])) {
            $benef[] = trim((string)$row[$key]);
        }
    }

    /* 7. PROPUESTAS (titulo + descripcion) */

    $propuestas = [];
    for ($i = 1; $i <= 9; $i++) {
        $p = trim((string)($row[sprintf('propuesta_%02d', $i)] ?? ''));
        $d = trim((string)($row[sprintf('descripcion_propuesta_%02d', $i)] ?? ''));
        if ($p !== '' || $d !== '') {
            $propuestas[] = $p . ($d ? ' — ' . $d : '');
        }
    }

    /* 8. FAQ (01–05) */

    $faqs = [];
    for ($i = 1; $i <= 5; $i++) {
        $q = trim((string)($row[sprintf('faq_%02d', $i)] ?? ''));
        $a = trim((string)($row[sprintf('respuesta_faq_%02d', $i)] ?? ''));
        if ($q !== '' || $a !== '') {
            $faqs[] = "P: {$q}\nR: {$a}";
        }
    }

    /* 9. TESTIMONIOS (01–03) */

    $test = [];
    for ($i = 1; $i <= 3; $i++) {
        $key = sprintf('testimonio_%02d', $i);
        if (!empty($row[$key])) {
            $test[] = trim((string)$row[$key]);
        }
    }

    /* 10. TEXTO RICO / TEXTO PARA EMBEDDINGS */

    $compact_parts = [];

    if ($empresa)        $compact_parts[] = "Empresa: {$empresa}";
    if ($desc)           $compact_parts[] = $desc;
    if ($categoria_str)  $compact_parts[] = "Categorías: {$categoria_str}";
    if ($exper)          $compact_parts[] = "Experiencias: " . implode(' · ', $exper);
    if ($benef)          $compact_parts[] = "Beneficios: " . implode(' · ', $benef);
    // Refuerzo semántico generalista: "Empresa X ofrece Y"
    if ($empresa && !empty($exper)) {
        foreach ($exper as $exp) {
            if ($exp !== '') {
                $compact_parts[] = "{$empresa} ofrece {$exp}";
            }
        }
    }
    // Texto completo → respuestas ricas
    $full_parts = $compact_parts;

    if ($propuestas) {
        $full_parts[] = "Propuestas:\n" . implode("\n", $propuestas);
    }

    if ($test) {
        $full_parts[] = "Testimonios:\n" . implode("\n\n", $test);
    }

    if ($faqs) {
        $full_parts[] = "Preguntas frecuentes:\n" . implode("\n\n", $faqs);
    }

    // Embeddings → compacto, pero con algo de contexto
    $embed_parts = $compact_parts;

    if (!empty($test[0])) {
        $embed_parts[] = "Testimonio destacado:\n" . mb_substr($test[0], 0, 450);
    }

    if (!empty($faqs[0])) {
        $embed_parts[] = "FAQ destacada:\n" . $faqs[0];
    }

    $text_full  = xabia_sanitize_text(implode("\n\n", array_filter($full_parts)));
    $text_embed = xabia_sanitize_text(implode("\n\n", array_filter($embed_parts)));

    /* 11. CONTACTO + NORMALIZACIÓN LIGERA DE URLS */

    $web = trim((string)(
        $row['empresa_web']
        ?? $row['url_empresa']
        ?? $row['web']
        ?? ''
    ));

    // Si viene solo "www.xxx.com" → añadir esquema
    if ($web !== '' && !preg_match('#^https?://#i', $web)) {
        $web = 'https://' . ltrim($web, '/');
    }

    $telefono = trim((string)(
        $row['empresa_tel']
        ?? $row['telefono']
        ?? $row['telefono1']
        ?? ''
    ));

    /* 12. ENTIDAD FINAL */

   return [
  'id'           => $slug,
  'slug'         => $slug,
  'empresa'      => $empresa,
  'categoria'    => $categoria_str,
  'company_type' => $company_type,

  'text'         => $text_full,
  'text_embed'   => $text_embed,

  // ✅ arrays útiles para búsquedas/filtrado (generalista)
  'experiencias' => $exper,
  'propuestas'   => $propuestas,
  'beneficios'   => $benef,
  'categorias'   => $categorias,

  'web'          => $web,
  'telefono'     => $telefono,

  'raw'          => $row,
];
}


/**
 * Deduplica entidades por slug o por nombre normalizado.
 * Garantiza unicidad estable en todo el sistema.
 */
function xabia_dedupe_entities(array $entities): array {

    $out  = [];
    $seen = [];   // claves únicas

    foreach ($entities as $e) {

        if (!is_array($e)) continue;

        $slug = $e['slug'] ?? '';
        $name = mb_strtolower(trim($e['empresa'] ?? ''), 'UTF-8');

        // Clave principal – slug si existe, sino nombre normalizado
        $key = $slug ?: $name;

        if (!$key) continue;

        // Si ya la hemos añadido, saltamos
        if (isset($seen[$key])) continue;

        $seen[$key] = true;
        $out[]      = $e;
    }

    return $out;
}

/* ============================================================
 * 2) PARSER GENÉRICO (para core / embeddings)
 * ============================================================ */

/**
 * Parser genérico de conocimiento (no-empresa)
 * Usado para explicar Xabia, productos, servicios, conceptos, etc.
 */
function xabia_parse_generic_knowledge(array $row): array {

    // Normalizar claves
    if (function_exists('xabia_normalize_row_keys')) {
        $row = xabia_normalize_row_keys($row);
    }

    $id    = $row['id'] ?? uniqid('knowledge_', true);
    $title = trim((string)($row['title'] ?? 'Contenido'));

    /* -----------------------------
     * CONTENIDO
     * ----------------------------- */

    $short = $row['content']['short'] ?? '';
    $long  = $row['content']['long']  ?? '';

    $text_full  = trim($long ?: $short);
    $text_embed = trim($short ?: $long);

    /* -----------------------------
     * EJEMPLOS
     * ----------------------------- */
    $examples = [];
    if (!empty($row['examples']) && is_array($row['examples'])) {
        foreach ($row['examples'] as $ex) {
            if (is_string($ex) && $ex !== '') {
                $examples[] = $ex;
            }
        }
    }

    /* -----------------------------
     * ACCIONES EXPLICATIVAS
     * ----------------------------- */
    $actions = [];
    if (!empty($row['actions'])) {
        if (is_array($row['actions'])) {
            $actions = array_values(array_filter($row['actions']));
        } elseif (is_string($row['actions'])) {
            $actions = [$row['actions']];
        }
    }

    /* -----------------------------
     * TEXTO FINAL
     * ----------------------------- */
    $full_parts  = [];
    $embed_parts = [];

    $full_parts[]  = $text_full;
    $embed_parts[] = $text_embed;

    if ($examples) {
        $full_parts[]  = "Ejemplos:\n• " . implode("\n• ", $examples);
        $embed_parts[] = implode(' · ', array_slice($examples, 0, 2));
    }

    if ($actions) {
        $full_parts[]  = "Acciones posibles:\n• " . implode("\n• ", $actions);
        $embed_parts[] = implode(' · ', $actions);
    }

    $final_text  = xabia_sanitize_text(implode("\n\n", array_filter($full_parts)));
    $embed_text  = xabia_sanitize_text(implode("\n\n", array_filter($embed_parts)));

    return [
        'id'         => sanitize_title($id),
        'slug'       => sanitize_title($title),
        'title'      => $title,

        'type'       => $row['type']     ?? null,
        'audience'   => $row['audience'] ?? null,
        'priority'   => $row['priority'] ?? 0,

        'text'       => $final_text,
        'text_embed' => $embed_text,

        'examples'   => $examples,
        'actions'    => $actions,
        'cta'        => $row['cta'] ?? null,
        'keywords'   => $row['keywords'] ?? [],

        'entity'     => 'knowledge',
        'raw'        => $row,
    ];
}



/**
 * Punto de entrada genérico para convertir filas → entidades.
 * Por ahora solo "empresa", pero está listo para más entidades.
 */
function xabia_parse_records(array $rows, string $mode = 'auto', string $entity = 'empresa'): array {

    $entities = [];

    foreach ($rows as $row) {
        if (!is_array($row)) continue;

        switch ($entity) {

    case 'knowledge':
        $ent = xabia_parse_generic_knowledge($row);
        break;

    case 'empresa':
    default:
        $ent = xabia_parse_aktiba_empresa($row);
        break;
}

        if (!empty($ent['text'])) {
            $entities[] = $ent;
        }
    }

    // Dedupe local por si este parser se usa suelto
    return xabia_dedupe_entities($entities);
}

/* ============================================================
 * 3) NORMALIZADOR DE BUNDLES (para manager → embeddings)
 * ============================================================ */

/**
 * Recibe los bundles crudos de manager:
 * [
 *   'aire' => [
 *      'def'  => fuente completa,
 *      'rows' => filas crudas (CSV → arrays)
 *   ],
 *   ...
 * ]
 *
 * Devuelve todas las entidades normalizadas.
 */
function xabia_normalize_entities(array $all_raw): array {

    $entities = [];

    foreach ($all_raw as $bundle_id => $bundle) {
        if (!isset($bundle['rows']) || !is_array($bundle['rows'])) continue;

        foreach ($bundle['rows'] as $row) {
            if (!is_array($row)) continue;

            $ent = xabia_parse_aktiba_empresa($row);
            if (!empty($ent['text'])) {
                $entities[] = $ent;
            }
        }
    }

    // DEDUPE GLOBAL (toda la base)
    $entities = xabia_dedupe_entities($entities);

    error_log('[Xabia Parser] ✔ Total entidades normalizadas (dedupe): ' . count($entities));

    return $entities;
}