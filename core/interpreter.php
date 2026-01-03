<?php
/**
 * Xabia — interpreter.php (v4.0 PRO)
 *
 * Traduce cualquier item crudo / entidad normalizada del sistema de fuentes
 * a un objeto semántico único y enriquecido.
 *
 * Diseñado para:
 * - Funcionar con entidades generadas por parser.php (Aktiba CSV → entidades).
 * - Aceptar también filas crudas de CSV como fallback.
 * - No romper el flujo actual (endpoint-query, embeddings, etc.).
 */

if (!defined('ABSPATH')) exit;

/**
 * Punto de entrada genérico.
 *
 * @param array  $item  Entidad normalizada (parser) o fila cruda.
 * @param string $type  Tipo semántico: empresa / categoria / experiencia / faq / generic
 * @return array
 */
function xabia_interpret_item(array $item, string $type = 'generic'): array
{
    $type = strtolower($type);

    switch ($type) {
        case 'empresa':
            return xabia_interpret_empresa($item);

        case 'categoria':
            return xabia_interpret_categoria($item);

        case 'experiencia':
            return xabia_interpret_experiencia($item);

        case 'faq':
            return xabia_interpret_faq($item);

        default:
            return xabia_interpret_generic($item);
    }
}

/* ============================================================
 * HELPERS INTERNOS
 * ============================================================ */

/**
 * Devuelve el "raw" de una entidad, tanto si viene ya normalizada
 * (entidad parser) como si es una fila cruda de CSV.
 *
 * @param array $item
 * @return array  Fila cruda (keys normalizadas por parser) o el propio $item.
 */
function xabia_interpret_get_raw(array $item): array
{
    if (!empty($item['raw']) && is_array($item['raw'])) {
        return $item['raw'];
    }
    return $item;
}

/**
 * Intenta normalizar una fila cruda usando el parser oficial, si existe.
 * Si ya es una entidad de parser, simplemente la devuelve.
 *
 * @param array $item
 * @return array entidad normalizada (con 'raw')
 */
function xabia_interpret_ensure_entity(array $item): array
{
    // Si ya parece una entidad normalizada del parser (tiene 'empresa' y 'raw'), la devolvemos tal cual
    if (!empty($item['empresa']) && !empty($item['raw']) && is_array($item['raw'])) {
        return $item;
    }

   // Intento REAL de normalizar entidad Aktiba
if (function_exists('xabia_parse_aktiba_empresa')) {

    // Si viene de sources → raw real está en $item
    $raw = $item['raw'] ?? $item;

    $ent = xabia_parse_aktiba_empresa($raw);

    if (!empty($ent['empresa'])) {
        $ent['raw'] = $raw;
        return $ent;
    }
}

    // Fallback: tratamos el item como entidad mínima
    $raw = xabia_interpret_get_raw($item);

    return [
        'id'        => $raw['slug']   ?? ($raw['id'] ?? uniqid('empresa_')),
        'slug'      => $raw['slug']   ?? ($raw['id'] ?? uniqid('empresa_')),
        'empresa'   => $raw['empresa'] ?? ($raw['title'] ?? ''),
        'categoria' => $raw['categoria'] ?? '',
        'text'      => $raw['text'] ?? implode(' ', array_map('strval', $raw)),
        'web'       => $raw['empresa_web'] ?? ($raw['web'] ?? ''),
        'telefono'  => $raw['empresa_tel'] ?? ($raw['telefono'] ?? ''),
        'raw'       => $raw,
    ];
}

/**
 * Helper: obtiene un campo de $raw probando varias claves posibles.
 *
 * @param array $raw
 * @param array $keys
 * @return string
 */
function xabia_interpret_field(array $raw, array $keys): string
{
    foreach ($keys as $k) {
        if (!empty($raw[$k])) {
            return trim((string) $raw[$k]);
        }
    }
    return '';
}

/**
 * Helper: recoge una serie (experiencia, beneficio, propuesta, faq...)
 * soportando nombres con guiones/underscores.
 *
 * $spec:
 *   [
 *      'base'        => 'experiencia',
 *      'max'         => 10,
 *      'with_desc'   => false,
 *      'desc_base'   => 'descripcion-propuesta',
 *   ]
 *
 * @param array $raw
 * @param array $spec
 * @return array
 */
function xabia_interpret_collect_series(array $raw, array $spec): array
{
    $base      = $spec['base']      ?? '';
    $max       = (int)($spec['max'] ?? 1);
    $with_desc = !empty($spec['with_desc']);
    $desc_base = $spec['desc_base'] ?? '';

    $out = [];

    for ($i = 1; $i <= $max; $i++) {
        $idx      = sprintf('%02d', $i);

        // claves posibles para el texto principal
        $keys_main = [
            "{$base}-{$idx}",
            "{$base}_{$idx}",
            "{$base}_0{$i}",
        ];

        $main = xabia_interpret_field($raw, $keys_main);
        if ($main === '' && !$with_desc) {
            continue;
        }

        $desc = '';
        if ($with_desc && $desc_base) {
            $keys_desc = [
                "{$desc_base}-{$idx}",
                "{$desc_base}_{$idx}",
                "{$desc_base}_0{$i}",
            ];
            $desc = xabia_interpret_field($raw, $keys_desc);
        }

        if ($with_desc) {
            // Guardamos como item con "titulo" y "descripcion"
            if ($main === '' && $desc === '') {
                continue;
            }
            $out[] = [
                'titulo'      => $main,
                'descripcion' => $desc,
            ];
        } else {
            if ($main === '') continue;
            $out[] = $main;
        }
    }

    return $out;
}

/* ============================================================
 * 🔵 1) EMPRESA (núcleo PRO)
 * ============================================================ */

/**
 * Interpreta una empresa (entidad Aktiba) añadiendo:
 * - experiencias[]
 * - beneficios[]
 * - propuestas[] (titulo + descripcion)
 * - faqs[] (pregunta + respuesta)
 * - localizacion
 * - categorias_array[]
 *
 * Mantiene:
 * - text: el texto rico del parser (para respuestas).
 * - text_embed: si existiera, no se toca (embeddings).
 *
 * @param array $item entidad parser o fila cruda
 * @return array
 */
function xabia_interpret_empresa(array $item): array
{
    // 1) Garantizar forma de entidad usando el parser oficial
    $ent = xabia_interpret_ensure_entity($item);
    $raw = xabia_interpret_get_raw($ent);

    /* ---------------------------------------
     * Campos básicos
     * --------------------------------------- */
    $id    = $ent['id']        ?? ($ent['slug'] ?? uniqid('empresa_'));
    $slug  = $ent['slug']      ?? $id;
    $name  = $ent['empresa']   ?? ($ent['title'] ?? '');
    $cat   = $ent['categoria'] ?? '';
    $text  = $ent['text']      ?? '';
    $web   = $ent['web']       ?? '';
    $tel   = $ent['telefono']  ?? '';

    // Localización (zona) – soporta nombres con guion / underscore
    $localizacion = xabia_interpret_field($raw, [
        'empresa_localizacion',
        'empresa-localizacion',
        'localizacion',
        'localización',
    ]);

    // Lista de categorías / subcategorías como array (a partir del string del parser o directamente del raw)
    $categorias_array = [];
    if (!empty($cat)) {
        // En parser: "Aire · Vuelo en globo · Otra cosa"
        $parts = array_map('trim', explode('·', $cat));
        $categorias_array = array_values(array_filter($parts));
    } else {
        // Fallback: reconstruir a partir del raw si hiciera falta
        $cats = [];

        $c0 = xabia_interpret_field($raw, ['categoria']);
        if ($c0) $cats[] = $c0;

        for ($i = 1; $i <= 7; $i++) {
            $idx = sprintf('%02d', $i);
            $keys = ["subcategoria-{$idx}", "subcategoria_{$idx}", "subcategoria_0{$i}"];
            $sc = xabia_interpret_field($raw, $keys);
            if ($sc) $cats[] = $sc;
        }

        $categorias_array = array_values(array_unique(array_filter($cats)));
        if (empty($cat) && !empty($categorias_array)) {
            $cat = implode(' · ', $categorias_array);
        }
    }

    /* ---------------------------------------
     * Experiencias / beneficios / propuestas / FAQs
     * --------------------------------------- */

    // EXPERIENCIAS 01–10
    $experiencias = xabia_interpret_collect_series($raw, [
        'base' => 'experiencia',
        'max'  => 10,
    ]);

    // BENEFICIOS 01–04
    $beneficios = xabia_interpret_collect_series($raw, [
        'base' => 'beneficio',
        'max'  => 4,
    ]);

    // PROPUESTAS 01–09 (titulo + descripcion)
    $propuestas = xabia_interpret_collect_series($raw, [
        'base'      => 'propuesta',
        'max'       => 9,
        'with_desc' => true,
        'desc_base' => 'descripcion-propuesta',
    ]);

    // FAQ 01–05 (pregunta + respuesta)
    $faqs = [];
    for ($i = 1; $i <= 5; $i++) {
        $idx = sprintf('%02d', $i);

        $q = xabia_interpret_field($raw, [
            "faq-{$idx}",
            "faq_{$idx}",
            "faq_0{$i}",
        ]);

        $a = xabia_interpret_field($raw, [
            "respuesta-faq-{$idx}",
            "respuesta_faq_{$idx}",
            "respuesta_faq_0{$i}",
        ]);

        if ($q !== '' || $a !== '') {
            $faqs[] = [
                'pregunta'  => $q,
                'respuesta' => $a,
            ];
        }
    }

    /* ---------------------------------------
     * TEXTO semántico auxiliar (no rompe nada)
     * --------------------------------------- */

    // NO machacamos $ent['text'], solo añadimos un texto auxiliar opcional
    $semantic_parts = [];

    if ($name) $semantic_parts[] = "Empresa: {$name}";
    if ($cat)  $semantic_parts[] = "Categorías: {$cat}";
    if ($localizacion) $semantic_parts[] = "Localización: {$localizacion}";

    if (!empty($experiencias)) {
        $semantic_parts[] = "Experiencias: " . implode(' · ', $experiencias);
    }
    if (!empty($beneficios)) {
        $semantic_parts[] = "Beneficios: " . implode(' · ', $beneficios);
    }

    if (!empty($propuestas)) {
        $lines = [];
        foreach ($propuestas as $p) {
            $t = $p['titulo'] ?? '';
            $d = $p['descripcion'] ?? '';
            if ($t && $d) {
                $lines[] = "{$t}: {$d}";
            } elseif ($t) {
                $lines[] = $t;
            } elseif ($d) {
                $lines[] = $d;
            }
        }
        if (!empty($lines)) {
            $semantic_parts[] = "Propuestas: " . implode(' | ', $lines);
        }
    }

    if (!empty($faqs)) {
        $lines = [];
        foreach ($faqs as $f) {
            $q = $f['pregunta']  ?? '';
            $a = $f['respuesta'] ?? '';
            if ($q || $a) {
                $lines[] = "P: {$q} / R: {$a}";
            }
        }
        if (!empty($lines)) {
            $semantic_parts[] = "FAQs: " . implode(' || ', $lines);
        }
    }

    $text_semantic = implode("\n", array_filter($semantic_parts));

    /* ---------------------------------------
     * ENSAMBLADO FINAL
     * --------------------------------------- */

    $out = [
        'type'            => 'empresa',
        'id'              => sanitize_title($id),
        'slug'            => sanitize_title($slug),
        'empresa'         => $name,
        'categoria'       => $cat,
        'categorias_array'=> $categorias_array,
        'telefono'        => $tel,
        'web'             => $web,
        'localizacion'    => $localizacion,

        // Texto principal para respuestas (no lo tocamos)
        'text'            => $text,

        // Texto auxiliar semántico (opcional, para futuras mejoras)
        'text_semantic'   => $text_semantic,

        // Si el parser ya generó text_embed, lo mantenemos
        'text_embed'      => $ent['text_embed'] ?? null,

        // Estructuras enriquecidas
        'experiencias'    => $experiencias,
        'beneficios'      => $beneficios,
        'propuestas'      => $propuestas,
        'faqs'            => $faqs,

        // Siempre conservamos el crudo por si hace falta
        'raw'             => $raw,
    ];

    // Merge suave con la entidad original (por si trae otros campos útiles)
    // Sin machacar nuestros campos clave.
    foreach ($ent as $k => $v) {
        if (!array_key_exists($k, $out)) {
            $out[$k] = $v;
        }
    }

    return $out;
}

/* ============================================================
 * 🔵 2) CATEGORÍA
 * ============================================================ */
function xabia_interpret_categoria(array $raw): array
{
    $id   = $raw['slug'] ?? ($raw['id'] ?? '');
    $name = $raw['nombre'] ?? ($raw['title'] ?? '');

    return [
        'type'   => 'categoria',
        'id'     => sanitize_title($id ?: $name ?: uniqid('cat_')),
        'slug'   => sanitize_title($id ?: $name ?: uniqid('cat_')),
        'nombre' => $name,
        'text'   => $name,
        'raw'    => xabia_interpret_get_raw($raw),
    ];
}

/* ============================================================
 * 🔵 3) EXPERIENCIA
 * ============================================================ */
function xabia_interpret_experiencia(array $raw): array
{
    $id    = $raw['id'] ?? uniqid('xp_');
    $title = $raw['titulo'] ?? ($raw['title'] ?? '');
    $desc  = $raw['descripcion'] ?? ($raw['text'] ?? '');
    $empresa   = $raw['empresa'] ?? '';
    $categoria = $raw['categoria'] ?? '';

    return [
        'type'        => 'experiencia',
        'id'          => sanitize_title($id),
        'titulo'      => $title,
        'descripcion' => $desc,
        'empresa'     => $empresa,
        'categoria'   => $categoria,
        'text'        => trim($title . ' ' . $desc),
        'raw'         => xabia_interpret_get_raw($raw),
    ];
}

/* ============================================================
 * 🔵 4) FAQ
 * ============================================================ */
function xabia_interpret_faq(array $raw): array
{
    $q = $raw['pregunta'] ?? ($raw['question'] ?? '');
    $a = $raw['respuesta'] ?? ($raw['answer'] ?? '');

    $id = $raw['id'] ?? ($q ?: uniqid('faq_'));

    return [
        'type'     => 'faq',
        'id'       => sanitize_title($id),
        'pregunta' => $q,
        'respuesta'=> $a,
        'empresa'  => $raw['empresa'] ?? '',
        'text'     => trim(($q ?: '') . ' ' . ($a ?: '')),
        'raw'      => xabia_interpret_get_raw($raw),
    ];
}

/* ============================================================
 * 🔵 5) Fallback genérico
 * ============================================================ */
function xabia_interpret_generic(array $raw): array
{
    $rk  = xabia_interpret_get_raw($raw);
    $id  = $rk['slug'] ?? ($rk['id'] ?? uniqid('item_'));
    $txt = implode(' ', array_map('strval', $rk));

    return [
        'type' => 'generic',
        'id'   => sanitize_title($id),
        'text' => $txt,
        'raw'  => $rk,
    ];
}