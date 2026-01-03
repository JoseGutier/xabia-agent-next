<?php
/**
 * Xabia — actions/backend.php (v2.0 PRO)
 * Backend agentic AVANZADO — búsqueda semántica + textual + acciones por empresa.
 */

if (!defined('ABSPATH')) exit;

/* ============================================================
 * DEPENDENCIAS
 * ============================================================ */
require_once XABIA_CORE_DIR . 'core.php';
require_once XABIA_CORE_DIR . 'embeddings.php';

/* ============================================================
 * 0) ÍNDICE GLOBAL DE CONOCIMIENTO
 * ============================================================ */
function xabia_backend_get_knowledge_index(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $entities = xabia_load_knowledge();
    if (!is_array($entities)) $entities = [];

    $by_slug = [];
    $by_name = [];

    foreach ($entities as $e) {
        $slug = $e['slug'] ?? ($e['id'] ?? null);
        $name = $e['empresa'] ?? '';

        if ($slug) $by_slug[$slug] = $e;
        if ($name) $by_name[mb_strtolower(trim($name))] = $e;
    }

    return $cache = [
        'list'    => $entities,
        'by_slug' => $by_slug,
        'by_name' => $by_name,
    ];
}

/* ============================================================
 * HELPERS
 * ============================================================ */
function xabia_backend_norm(string $t): string {
    $t = mb_strtolower(trim($t));
    return strtr($t, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n']);
}

function xabia_backend_get_categories_from_entity(array $e): array {
    $cat = $e['categoria'] ?? '';
    if (!$cat) return [];
    $parts = array_map('trim', explode('·', $cat));
    return array_values(array_filter($parts));
}

function xabia_backend_text_score(array $e, string $query): float {
    if (!$query) return 0.0;

    $q = xabia_backend_norm($query);

    $empresa = xabia_backend_norm($e['empresa'] ?? '');
    $cat     = xabia_backend_norm($e['categoria'] ?? '');
    $text    = xabia_backend_norm($e['text'] ?? '');

    $score = 0.0;
    if ($empresa && str_contains($empresa, $q)) $score += 5.0;
    if ($cat && str_contains($cat, $q))         $score += 3.0;
    if ($text && str_contains($text, $q))       $score += 1.0;

    return $score;
}

/* ============================================================
 * 1) FORMATEO UNIVERSAL PARA RESPUESTAS
 * ============================================================ */
function xabia_backend_format_company(array $e, float $score = 0.0, string $reason = ''): array {

    // Extraer web / teléfono de raw si no existen arriba
    $raw = $e['raw'] ?? [];
    $web = $e['web'] ?? ($raw['empresa_web'] ?? '');
    $tel = $e['telefono'] ?? ($raw['empresa_tel'] ?? '');

    return [
        'id'        => $e['id']        ?? ($e['slug'] ?? null),
        'slug'      => $e['slug']      ?? null,
        'empresa'   => $e['empresa']   ?? '',
        'categoria' => $e['categoria'] ?? '',
        'web'       => $web,
        'telefono'  => $tel,
        'score'     => $score,
        'reason'    => $reason,
    ];
}

/* ============================================================
 * 2) FIND COMPANY (con fallback inteligente)
 * ============================================================ */
function xabia_backend_find_company(?string $slug = null, ?string $empresa = null): ?array {

    $idx = xabia_backend_get_knowledge_index();

    // Búsqueda por slug
    if ($slug) {
        $s = sanitize_title($slug);
        if (isset($idx['by_slug'][$s])) return $idx['by_slug'][$s];
    }

    // Búsqueda por nombre normalizado
    if ($empresa) {
        $n = mb_strtolower(trim($empresa));
        if (isset($idx['by_name'][$n])) return $idx['by_name'][$n];
    }

    return null;
}

/* ============================================================
 * 3) Acción — search_company
 * ============================================================ */
function xabia_action_search_company(array $payload) {

    $query     = sanitize_text_field($payload['query'] ?? '');
    $category  = sanitize_text_field($payload['category'] ?? '');
    $limit     = max(1, (int)($payload['limit'] ?? 10));
    $min_score = (float)($payload['min_score'] ?? 0.0);

    $idx   = xabia_backend_get_knowledge_index();
    $items = $idx['list'];

    if (!$items) {
        return ['ok'=>false,'message'=>'No hay entidades cargadas.','results'=>[]];
    }

    // FILTRO POR CATEGORÍA
    if ($category) {
        $needle = xabia_backend_norm($category);
        $items = array_filter($items, function ($e) use ($needle) {
            foreach (xabia_backend_get_categories_from_entity($e) as $c) {
                if (str_contains(xabia_backend_norm($c), $needle)) return true;
            }
            return false;
        });
    }

    if (!$query && !$category) {
        return ['ok'=>false,'message'=>'Se requiere query o categoría.','results'=>[]];
    }

    // SEMÁNTICA
    $semantic_map = [];
    if ($query && function_exists('xabia_vector_search')) {
        $sem = xabia_vector_search($query, $limit * 3, 0.30);
        foreach ($sem as $e) {
            $slug = $e['slug'] ?? ($e['id'] ?? null);
            if (!$slug) continue;
            $semantic_map[$slug] = [
                'entity'=> $e,
                'score' => ($e['_score'] ?? 0) * 10.0
            ];
        }
    }

    // COMBINAR SCORES
    $results = [];
    foreach ($items as $e) {
        $slug = $e['slug'] ?? null;
        if (!$slug) continue;

        $s_text = $query ? xabia_backend_text_score($e, $query) : 0.0;
        $s_sem  = $semantic_map[$slug]['score'] ?? 0.0;

        $score = ($s_sem * 1.2) + $s_text;

        if ($score < $min_score && $query) continue;

        $reason = [];
        if ($s_sem > 0)  $reason[] = 'semántico';
        if ($s_text > 0) $reason[] = 'textual';
        if ($category)   $reason[] = 'categoría';

        $results[] = xabia_backend_format_company($e, $score, implode(' + ', $reason));
    }

    usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
    return [
        'ok'=>true,
        'query'=>$query,
        'category'=>$category,
        'total'=>count($results),
        'results'=>array_slice($results, 0, $limit)
    ];
}

/* ============================================================
 * 4) recommend_company
 * ============================================================ */
function xabia_action_recommend_company(array $payload) {
    $intent = sanitize_text_field($payload['intent'] ?? '');
    if (!$intent)
        return ['ok'=>false,'message'=>'Falta "intent".','results'=>[]];

    $res = xabia_action_search_company([
        'query'    => $intent,
        'category' => $payload['category'] ?? '',
        'limit'    => $payload['limit'] ?? 5
    ]);

    return [
        'ok'=> $res['ok'],
        'intent'=> $intent,
        'total'=> $res['total'] ?? 0,
        'results'=> $res['results'] ?? []
    ];
}

/* ============================================================
 * 5) get_company (con raw completo)
 * ============================================================ */
function xabia_action_get_company(array $payload) {

    $slug    = $payload['slug'] ?? ($payload['id'] ?? null);
    $empresa = $payload['empresa'] ?? null;

    if (!$slug && !$empresa)
        return ['ok'=>false,'message'=>'Falta slug/id/empresa.'];

    $company = xabia_backend_find_company($slug, $empresa);
    if (!$company)
        return ['ok'=>false,'message'=>'Empresa no encontrada.'];

    $raw = $company['raw'] ?? $company;

    return [
        'ok'=>true,
        'company'=>[
            'id'        => $company['id']        ?? ($company['slug'] ?? null),
            'slug'      => $company['slug']      ?? null,
            'empresa'   => $company['empresa']   ?? '',
            'categoria' => $company['categoria'] ?? '',
            'web'       => $company['web']       ?? ($raw['empresa_web'] ?? ''),
            'telefono'  => $company['telefono']  ?? ($raw['empresa_tel'] ?? ''),
            'text'      => $company['text']      ?? '',
            'raw'       => $raw,
        ]
    ];
}

/* ============================================================
 * 6) get_faqs
 * ============================================================ */
function xabia_action_get_faqs(array $payload) {

    $res = xabia_action_get_company($payload);
    if (empty($res['ok'])) return ['ok'=>false,'message'=>$res['message'],'faqs'=>[]];

    $raw = $res['company']['raw'];
    $faqs = [];

    for ($i=1;$i<=8;$i++) {
        $q = trim($raw[sprintf('faq_%02d', $i)] ?? '');
        $a = trim($raw[sprintf('respuesta_faq_%02d', $i)] ?? '');
        if ($q || $a) $faqs[] = ['question'=>$q,'answer'=>$a];
    }

    return [
        'ok'=>true,
        'company'=>$res['company'],
        'total'=>count($faqs),
        'faqs'=>$faqs
    ];
}

/* ============================================================
 * 7) get_experiences
 * ============================================================ */
function xabia_action_get_experiences(array $payload) {

    $res = xabia_action_get_company($payload);
    if (empty($res['ok'])) return ['ok'=>false,'message'=>$res['message'],'experiences'=>[]];

    $raw = $res['company']['raw'];
    $out = [];

    for ($i=1;$i<=12;$i++) {
        $val = trim($raw[sprintf('experiencia_%02d', $i)] ?? '');
        if ($val) $out[] = $val;
    }

    return [
        'ok'=>true,
        'company'=>$res['company'],
        'total'=>count($out),
        'experiences'=>$out
    ];
}

/* ============================================================
 * 8) get_benefits
 * ============================================================ */
function xabia_action_get_benefits(array $payload) {

    $res = xabia_action_get_company($payload);
    if (empty($res['ok'])) return ['ok'=>false,'message'=>$res['message'],'benefits'=>[]];

    $raw = $res['company']['raw'];
    $out = [];

    for ($i=1;$i<=6;$i++) {
        $val = trim($raw[sprintf('beneficio_%02d', $i)] ?? '');
        if ($val) $out[] = $val;
    }

    return [
        'ok'=>true,
        'company'=>$res['company'],
        'total'=>count($out),
        'benefits'=>$out
    ];
}

/* ============================================================
 * 9) Acción AGENTIC — book_experience
 * ============================================================ */
function xabia_action_book_experience(array $payload) {

    $query = $payload['experience'] ?? ($payload['query'] ?? '');
    if (!$query)
        return ['ok'=>false,'error'=>'Falta el nombre de la experiencia.'];

    $results = xabia_vector_search($query, 5);

    if (!$results)
        return ['ok'=>false,'error'=>"No he encontrado ninguna experiencia para «{$query}»."];

    $match = $results[0];

    $title = $match['empresa'] ?? '';
    $url   = $match['booking'] ?? ($match['url'] ?? '');
    $phone = $match['telefono'] ?? '';

    if (!$url) {
        return [
            'ok'=>true,
            'fallback'=>true,
            'message'=>"He encontrado «{$title}», pero no tengo enlace de reserva.",
            'actions'=>[
                ['type'=>'call_phone','payload'=>['phone'=>$phone]]
            ]
        ];
    }

    return [
        'ok'=>true,
        'title'=>$title,
        'booking_url'=>$url,
        'actions'=>[
            ['type'=>'open_url','payload'=>['url'=>$url]]
        ]
    ];
}