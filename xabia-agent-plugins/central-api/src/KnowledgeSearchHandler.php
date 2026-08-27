<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * Búsqueda vectorial (RAG) sobre xabia_knowledge_vectors del Hub.
 * Sin reglas por dominio ni proyecto: la relevancia viene del texto almacenado (admin/datos) + consulta.
 */
final class KnowledgeSearchHandler
{
    private const CANDIDATE_LIMIT = 500;

    /** Límite superior de fragments devueltos al plugin (alineado con el admin de WordPress). */
    private const MAX_CHUNKS_CAP = 15;

    /** Listados de catálogo: más empresas por consulta (sin favoritismo por top-k). */
    private const MAX_CATALOG_CHUNKS_CAP = 50;

    /** Mínimo de caracteres para tratar una subcadena de la pregunta como «frase literal» frente al chunk. */
    private const MIN_VERBATIM_PHRASE_CHARS = 8;

    /** Relleno léxico (español); solo descarta tokens demasiado genéricos aislados, no vocabulario de negocio. */
    private const LEXICAL_STOPWORDS_ES = [
        'alguna', 'alguno', 'algunos', 'algunas', 'información', 'informacion',
        'datos', 'sitio', 'página', 'pagina', 'momento', 'busco', 'necesito', 'recomienda', 'recomendar',
        'para', 'hacer', 'puedes', 'puede', 'pueden', 'tienes', 'tiene', 'tienen', 'también', 'tambien',
        'algo', 'nada', 'mismo', 'misma', 'mismos', 'mismas', 'otro', 'otra', 'otros', 'otras', 'cada',
        'tan', 'muy', 'vez', 'bien', 'solo', 'sola', 'sólo', 'tras', 'cabe', 'favor', 'donde', 'dónde',
        'cuando', 'cuándo', 'como', 'cómo', 'cual', 'cuál', 'quien', 'quién', 'interesa', 'interesaría',
        'interesaria', 'gustaría', 'gustaria',
    ];

    /**
     * @var list<string>
     */
    private const LEXICAL_NEEDLE_BLOCKLIST = [
        'para', 'por', 'con', 'sin', 'los', 'las', 'del', 'al', 'el', 'la', 'un', 'una', 'uno', 'y', 'o',
        'a', 'en', 'de', 'es', 'son', 'que', 'se', 'le', 'les', 'lo', 'me', 'te', 'ya', 'no', 'si',
        'hacer', 'puede', 'puedes', 'pueden', 'tiene', 'tienen', 'tienes', 'este', 'esta', 'estos', 'estas',
        'ese', 'esa', 'tan', 'muy', 'más', 'mas', 'también', 'tambien', 'solo', 'sola', 'sólo', 'vez', 'cada',
    ];

    public static function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            Json::respond(405, ['error' => ['message' => 'Method Not Allowed', 'type' => 'method']]);

            return;
        }
        $rawBody = (string) file_get_contents('php://input');
        $ctx = SignedHubPostAuth::validate($rawBody);
        if ($ctx === null) {
            return;
        }
        $licenseId = $ctx['billing_license_id'];

        $input = $rawBody === '' ? [] : json_decode($rawBody, true);
        if (!is_array($input)) {
            Json::respond(400, ['error' => ['message' => 'JSON inválido', 'type' => 'invalid_request']]);

            return;
        }

        $projectId = trim((string) ($input['project_id'] ?? ''));
        if ($projectId === '') {
            Json::respond(400, ['error' => ['message' => 'project_id obligatorio', 'type' => 'invalid_request']]);

            return;
        }

        $catalogExhaustive = !empty($input['catalog_exhaustive']);
        $qv = $input['query_embedding'] ?? null;
        $queryVec = [];
        if (is_array($qv) && $qv !== []) {
            foreach ($qv as $v) {
                if (!is_numeric($v)) {
                    Json::respond(400, ['error' => ['message' => 'query_embedding inválido', 'type' => 'invalid_request']]);

                    return;
                }
                $queryVec[] = (float) $v;
            }
        } elseif (!$catalogExhaustive) {
            Json::respond(400, ['error' => ['message' => 'query_embedding obligatorio (array)', 'type' => 'invalid_request']]);

            return;
        }

        // Efecto túnel RAG: solo `ente_id`. Se ignoran `item`, `ente_scope`, `strict_ente` y demás aliases.
        unset($input['item'], $input['ente_scope'], $input['strict_ente']);

        $eid = isset($input['ente_id']) ? trim((string) $input['ente_id']) : '';
        if ($eid !== '' && strlen($eid) > 100) {
            $eid = substr($eid, 0, 100);
        }
        $enteFilter = ($eid !== '' && $eid !== 'global') ? $eid : null;

        $maxChunks = isset($input['max_chunks']) ? (int) $input['max_chunks'] : 4;
        $catalogList = !empty($input['catalog_list']);
        $chunksCap = $catalogList ? self::MAX_CATALOG_CHUNKS_CAP : self::MAX_CHUNKS_CAP;
        if ($catalogList) {
            $maxChunks = max($maxChunks, min(self::MAX_CATALOG_CHUNKS_CAP, 50));
        }
        $maxChunks = max(1, min($chunksCap, $maxChunks));
        $threshold = isset($input['similarity_threshold']) ? (float) $input['similarity_threshold'] : 0.2;
        $threshold = max(0.0, min(1.0, $threshold));
        if ($catalogList) {
            $threshold = min($threshold, 0.06);
        }

        if ($catalogList && $catalogExhaustive) {
            $activityProfile = self::parseActivityProfile($input['activity_profile'] ?? null);
            $matchRegexp = trim((string) ($activityProfile['match_regexp'] ?? ''));
            $catalogDebug = [
                'mode'         => 'exhaustive',
                'source'       => 'none',
                'store_rows'   => 0,
                'vector_rows'  => 0,
                'rows_scanned' => 0,
                'matched'      => 0,
            ];
            if ($matchRegexp !== '') {
                $catalogDebug['match_regexp'] = $matchRegexp;
            }
            if ($activityProfile === []) {
                $catalogDebug['error'] = 'no_activity_profile';
                self::respondScoredChunks([], $maxChunks, $catalogDebug);

                return;
            }
            $fetched = KnowledgeVectorsRepository::fetchEmpresaChunksForCatalogExhaustive(
                $licenseId,
                $projectId,
                $enteFilter,
                self::CANDIDATE_LIMIT,
                $matchRegexp
            );
            $catalogRows = $fetched['rows'];
            $catalogDebug['source'] = (string) ($fetched['source'] ?? 'none');
            $catalogDebug['store_rows'] = (int) ($fetched['store_rows'] ?? 0);
            $catalogDebug['vector_rows'] = (int) ($fetched['vector_rows'] ?? 0);
            $catalogDebug['rows_scanned'] = \count($catalogRows);
            $exhaustive = self::exhaustiveCatalogActivityScan($catalogRows, $activityProfile, $maxChunks);
            $catalogDebug['matched'] = \count($exhaustive);
            self::respondScoredChunks($exhaustive, $maxChunks, $catalogDebug);

            return;
        }

        $rows = KnowledgeVectorsRepository::fetchVectorsForSearch(
            $licenseId,
            $projectId,
            $enteFilter,
            self::CANDIDATE_LIMIT
        );
        if ($rows === []) {
            Json::respond(200, [
                'ok'           => true,
                'chunk_count'  => 0,
                'context'      => '',
                'similarity_avg' => null,
                'chunks'       => [],
            ]);

            return;
        }

        if ($catalogList) {
            $rows = array_values(array_filter($rows, static function (array $r): bool {
                return preg_match('/\bEMPRESA:/iu', (string) ($r['content_chunk'] ?? '')) === 1;
            }));
            if ($rows === []) {
                Json::respond(200, [
                    'ok'             => true,
                    'chunk_count'    => 0,
                    'context'        => '',
                    'similarity_avg' => null,
                    'chunks'         => [],
                ]);

                return;
            }
        }

        if ($queryVec === []) {
            Json::respond(200, [
                'ok'             => true,
                'chunk_count'    => 0,
                'context'        => '',
                'similarity_avg' => null,
                'chunks'         => [],
            ]);

            return;
        }

        $queryText = mb_substr(preg_replace('/\s+/u', ' ', trim(strip_tags((string) ($input['query_text'] ?? '')))), 0, 2000);
        $lexicalQueryText = mb_substr(
            preg_replace('/\s+/u', ' ', trim(strip_tags((string) ($input['lexical_query_text'] ?? '')))),
            0,
            2000
        );
        if ($lexicalQueryText === '') {
            $lexicalQueryText = $queryText;
        }

        $keywordExpansions = self::parseKeywordExpansions($input['keyword_expansions'] ?? null);
        $signalNeedles = $lexicalQueryText !== ''
            ? self::signalNeedlesForQuery($lexicalQueryText, $keywordExpansions)
            : [];
        $priorityVenues = self::parsePriorityVenues($input['priority_venues'] ?? null);

        $vectorScored = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $vec = json_decode((string) ($r['vector_json'] ?? ''), true);
            if (!is_array($vec) || $vec === []) {
                continue;
            }
            $vecF = [];
            foreach ($vec as $x) {
                $vecF[] = is_numeric($x) ? (float) $x : 0.0;
            }
            if (\count($vecF) < 8) {
                continue;
            }
            $sim = self::cosineSimilarity($queryVec, $vecF);
            if ($sim >= $threshold) {
                $chunkText = self::formatChunkForRagContext(
                    (string) ($r['content_chunk'] ?? ''),
                    (string) ($r['meta_json'] ?? '')
                );
                $vectorScored[] = [
                    'content' => $chunkText,
                    'score'   => $sim,
                ];
            }
        }

        if ($signalNeedles !== []) {
            $vectorScored = self::boostHitsMatchingNeedles($vectorScored, $signalNeedles, 0.12);
        }

        $lexPoolLimit = $catalogList ? self::CANDIDATE_LIMIT : self::CANDIDATE_LIMIT;
        $lexScored = [];
        if ($lexicalQueryText !== '') {
            $lexScored = self::lexicalFallbackMatches($rows, $lexicalQueryText, $lexPoolLimit, $keywordExpansions);
        }

        $bigPool = self::CANDIDATE_LIMIT;
        if ($vectorScored === []) {
            $fullRanked = $lexScored;
        } elseif ($lexScored === []) {
            $fullRanked = self::mergeHybridVectorLexical($vectorScored, [], $bigPool);
        } else {
            $fullRanked = self::mergeHybridVectorLexical($vectorScored, $lexScored, $bigPool);
        }

        // Catalog/agenda: boost escenarios principales (metadato o lista del proyecto) antes del Top-K.
        if ($catalogList || $priorityVenues !== []) {
            $fullRanked = self::boostPriorityHits($fullRanked, $priorityVenues, 0.20);
            if ($lexScored !== []) {
                $lexScored = self::boostPriorityHits($lexScored, $priorityVenues, 0.20);
            }
        }

        usort($fullRanked, static fn (array $a, array $b): int => (($b['score'] ?? 0) <=> ($a['score'] ?? 0)));
        if ($catalogList) {
            if ($lexScored !== []) {
                $scored = self::selectCatalogDiverseTopK($lexScored, $rows, $maxChunks);
            } else {
                $scored = self::selectCatalogDiverseTopK($fullRanked, $rows, $maxChunks);
            }
        } elseif ($queryText !== '') {
            $scored = self::ensureLiteralQueryPhraseInTopK($fullRanked, $lexScored, $maxChunks, $queryText, $rows);
        } else {
            $scored = array_slice($fullRanked, 0, $maxChunks);
        }

        $totalQualifying = count($fullRanked);
        $returnedCount = count($scored);

        $context = self::formatContext(array_map(static fn (array $s): string => $s['content'], $scored));
        $avg = $scored === [] ? null : array_sum(array_column($scored, 'score')) / count($scored);

        $payload = [
            'ok'             => true,
            'chunk_count'    => $returnedCount,
            'context'        => $context,
            'similarity_avg' => $avg,
            'chunks'         => $scored,
        ];
        if ($totalQualifying > $returnedCount) {
            $payload['total_found'] = $totalQualifying;
        }

        Json::respond(200, $payload);
    }

    /**
     * Frases/subcadenas literales de la pregunta (minúsculas) para comprobar si están contenidas en un chunk.
     *
     * @return list<string>
     */
    private static function literalQuerySubstrings(string $query): array
    {
        $q = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query)), 'UTF-8');
        if ($q === '') {
            return [];
        }
        $cands = [$q];
        $stripped = preg_replace('/^[\p{P}\p{Z}]+|[\p{P}\p{Z}]+$/u', '', $q);
        if ($stripped !== '' && $stripped !== $q) {
            $cands[] = $stripped;
        }
        $out = [];
        foreach (array_unique($cands) as $s) {
            if (mb_strlen($s, 'UTF-8') >= self::MIN_VERBATIM_PHRASE_CHARS) {
                $out[] = $s;
            }
        }

        $meaningful = self::meaningfulQueryTokens(self::normalizeQueryTermsForLexical($query));
        if ($meaningful !== []) {
            $joined = mb_strtolower(implode(' ', $meaningful), 'UTF-8');
            if (mb_strlen($joined, 'UTF-8') >= self::MIN_VERBATIM_PHRASE_CHARS) {
                $out[] = $joined;
            }
        }

        $uniq = [];
        foreach ($out as $p) {
            $p = trim($p);
            if ($p !== '') {
                $uniq[$p] = true;
            }
        }

        return array_keys($uniq);
    }

    /**
     * @param list<array{content: string, score: float}> $scored
     * @param list<array{content: string, score: float}> $lexScored
     * @param list<array<string, mixed>>                $rows
     *
     * @return list<array{content: string, score: float}>
     */
    private static function ensureLiteralQueryPhraseInTopK(
        array $scored,
        array $lexScored,
        int $maxChunks,
        string $queryText,
        array $rows
    ): array {
        $phrases = self::literalQuerySubstrings($queryText);
        if ($phrases === []) {
            return array_slice($scored, 0, $maxChunks);
        }
        usort($phrases, static fn (string $a, string $b): int => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));
        $maxChunks = max(1, $maxChunks);
        $top = array_slice($scored, 0, $maxChunks);
        foreach ($phrases as $phrase) {
            $pl = mb_strtolower($phrase, 'UTF-8');
            if (mb_strlen($pl, 'UTF-8') < self::MIN_VERBATIM_PHRASE_CHARS) {
                continue;
            }
            foreach ($top as $h) {
                if (!isset($h['content'])) {
                    continue;
                }
                if (self::chunkContainsLiteralPhrase(mb_strtolower((string) $h['content'], 'UTF-8'), $pl)) {
                    return $top;
                }
            }
        }
        $best = null;
        foreach ($phrases as $phrase) {
            $pl = mb_strtolower($phrase, 'UTF-8');
            if (mb_strlen($pl, 'UTF-8') < self::MIN_VERBATIM_PHRASE_CHARS) {
                continue;
            }
            foreach ($lexScored as $h) {
                if (!isset($h['content'])) {
                    continue;
                }
                $c = (string) $h['content'];
                if (!self::chunkContainsLiteralPhrase(mb_strtolower($c, 'UTF-8'), $pl)) {
                    continue;
                }
                $s = (float) ($h['score'] ?? 0);
                if ($best === null || $s > $best['score']) {
                    $best = ['content' => $c, 'score' => $s];
                }
            }
            if ($best !== null) {
                break;
            }
            foreach ($rows as $r) {
                if (!\is_array($r)) {
                    continue;
                }
                $chunkText = self::formatChunkForRagContext(
                    (string) ($r['content_chunk'] ?? ''),
                    (string) ($r['meta_json'] ?? '')
                );
                if ($chunkText === '' || !self::chunkContainsLiteralPhrase(mb_strtolower($chunkText, 'UTF-8'), $pl)) {
                    continue;
                }
                $best = ['content' => $chunkText, 'score' => 0.97];
                break 2;
            }
        }
        if ($best === null) {
            return array_slice($scored, 0, $maxChunks);
        }
        $best['score'] = min(1.0, max((float) $best['score'], 0.97));
        array_unshift($scored, $best);

        $map = [];
        foreach ($scored as $h) {
            if (!isset($h['content'], $h['score'])) {
                continue;
            }
            $k = self::chunkDedupeKey((string) $h['content']);
            if (!isset($map[$k]) || (float) $h['score'] > $map[$k]['score']) {
                $map[$k] = ['content' => (string) $h['content'], 'score' => (float) $h['score']];
            }
        }
        $out = array_values($map);
        usort($out, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($out, 0, $maxChunks);
    }

    private static function chunkContainsLiteralPhrase(string $hayLower, string $phraseLower): bool
    {
        if ($phraseLower === '') {
            return false;
        }

        return mb_strpos($hayLower, $phraseLower) !== false;
    }

    /**
     * @return list<string>
     */
    private static function normalizeQueryTermsForLexical(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $terms = [$query];
        $strippedEnd = preg_replace('/[\p{P}\p{Z}]+$/u', '', $query);
        if ($strippedEnd !== '' && $strippedEnd !== $query) {
            $terms[] = $strippedEnd;
        }
        foreach (preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) as $raw) {
            $t = preg_replace('/^[\p{P}\p{Z}]+|[\p{P}\p{Z}]+$/u', '', $raw);
            if ($t !== '' && mb_strlen($t, 'UTF-8') > 2) {
                $terms[] = $t;
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * @param list<string> $terms
     *
     * @return list<string>
     */
    private static function meaningfulQueryTokens(array $terms): array
    {
        $stop = array_flip(self::LEXICAL_STOPWORDS_ES);
        $out = [];
        foreach ($terms as $t) {
            $tl = mb_strtolower(trim($t), 'UTF-8');
            if ($tl === '' || mb_strlen($tl, 'UTF-8') < 3) {
                continue;
            }
            if (isset($stop[$tl])) {
                continue;
            }
            $out[] = $t;
        }

        return array_values(array_unique($out));
    }

    /**
     * Agujas = tokens significativos (sin tabla de sinónimos por vertical: el contenido viene del admin).
     *
     * @param list<string> $meaningfulTokens
     *
     * @return list<string>
     */
    private static function needlesFromMeaningfulTokens(array $meaningfulTokens): array
    {
        $uniq = [];
        foreach ($meaningfulTokens as $t) {
            $t = trim($t);
            if ($t === '') {
                continue;
            }
            $k = mb_strtolower($t, 'UTF-8');
            $uniq[$k] = $t;
        }

        return array_values($uniq);
    }

    /**
     * @param list<string> $needles
     *
     * @return list<string>
     */
    private static function filterNeedlesForMatching(array $needles): array
    {
        $bl = array_flip(self::LEXICAL_NEEDLE_BLOCKLIST);
        $out = [];
        foreach ($needles as $n) {
            $n = trim($n);
            if ($n === '') {
                continue;
            }
            $k = mb_strtolower($n, 'UTF-8');
            if ($k === '' || mb_strlen($k, 'UTF-8') < 3 || isset($bl[$k])) {
                continue;
            }
            $out[$k] = $n;
        }

        return array_values($out);
    }

    /**
     * @param list<string> $meaningfulTokens
     *
     * @return list<string>
     */
    private static function fallbackLongNeedlesOnly(array $meaningfulTokens): array
    {
        $long = [];
        foreach ($meaningfulTokens as $t) {
            $t = trim($t);
            if ($t !== '' && mb_strlen($t, 'UTF-8') >= 5) {
                $long[] = $t;
            }
        }
        if ($long === []) {
            return [];
        }

        return self::filterNeedlesForMatching(self::needlesFromMeaningfulTokens($long));
    }

    /**
     * @param array<string, string>|null $raw
     *
     * @return array<string, string> needle(lowercase) → pipe-separated alts
     */
    private static function parseKeywordExpansions($raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $pattern) {
            $k = mb_strtolower(trim((string) $key), 'UTF-8');
            $p = trim((string) $pattern);
            if ($k === '' || $p === '' || mb_strlen($k, 'UTF-8') < 2) {
                continue;
            }
            if (preg_match('/[\r\n]/', $p) === 1) {
                continue;
            }
            if (mb_strlen($p, 'UTF-8') > 500) {
                $p = mb_substr($p, 0, 500, 'UTF-8');
            }
            $out[$k] = $p;
        }

        return $out;
    }

    /**
     * Amplía agujas con rules.keyword_expansions del plugin (p. ej. caballo → hípica|equitación).
     *
     * @param list<string>           $needles
     * @param array<string, string>  $expansions
     *
     * @return list<string>
     */
    private static function expandNeedlesWithKeywordMap(array $needles, array $expansions): array
    {
        if ($needles === [] || $expansions === []) {
            return $needles;
        }
        $out = [];
        $seen = [];
        $add = static function (string $t) use (&$out, &$seen): void {
            $t = trim($t);
            if ($t === '' || mb_strlen($t, 'UTF-8') < 3) {
                return;
            }
            $k = mb_strtolower($t, 'UTF-8');
            if (isset($seen[$k])) {
                return;
            }
            $seen[$k] = true;
            $out[] = $t;
        };
        foreach ($needles as $n) {
            $add((string) $n);
            $nl = mb_strtolower(trim((string) $n), 'UTF-8');
            if ($nl === '' || !isset($expansions[$nl])) {
                continue;
            }
            foreach (preg_split('/\|/', $expansions[$nl]) ?: [] as $part) {
                $add((string) $part);
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $keywordExpansions
     *
     * @return list<string>
     */
    private static function signalNeedlesForQuery(string $query, array $keywordExpansions = []): array
    {
        $terms = self::normalizeQueryTermsForLexical($query);
        $meaningful = self::meaningfulQueryTokens($terms);
        if ($meaningful === []) {
            return [];
        }
        $needles = self::filterNeedlesForMatching(self::needlesFromMeaningfulTokens($meaningful));
        if ($needles === []) {
            $needles = self::fallbackLongNeedlesOnly($meaningful);
        }

        return self::expandNeedlesWithKeywordMap($needles, $keywordExpansions);
    }

    /**
     * @param list<array{content: string, score: float}> $hits
     * @param list<string>                               $needles
     *
     * @return list<array{content: string, score: float}>
     */
    private static function boostHitsMatchingNeedles(array $hits, array $needles, float $bonus): array
    {
        if ($needles === [] || $hits === []) {
            return $hits;
        }
        foreach ($hits as $i => $h) {
            if (!isset($h['content'])) {
                continue;
            }
            $hay = mb_strtolower((string) $h['content'], 'UTF-8');
            foreach ($needles as $n) {
                $nl = mb_strtolower(trim($n), 'UTF-8');
                if ($nl === '' || mb_strlen($nl, 'UTF-8') < 3) {
                    continue;
                }
                if (mb_strpos($hay, $nl) !== false || self::tokenSoftMatch($nl, $hay)) {
                    $hits[$i]['score'] = min(1.0, (float) ($h['score'] ?? 0) + $bonus);

                    break;
                }
            }
        }

        return $hits;
    }

    /**
     * @param list<array{content: string, score: float}> $hits
     * @param list<string>                               $priorityVenues
     *
     * @return list<array{content: string, score: float}>
     */
    private static function boostPriorityHits(array $hits, array $priorityVenues, float $bonus): array
    {
        if ($hits === [] || $bonus <= 0) {
            return $hits;
        }
        foreach ($hits as $i => $h) {
            if (!isset($h['content'])) {
                continue;
            }
            if (!self::chunkHasPrioritySignal((string) $h['content'], $priorityVenues)) {
                continue;
            }
            $hits[$i]['score'] = (float) ($h['score'] ?? 0) + $bonus;
        }
        usort($hits, static fn (array $a, array $b): int => (($b['score'] ?? 0) <=> ($a['score'] ?? 0)));

        return array_values($hits);
    }

    /**
     * @param list<string> $priorityVenues
     */
    private static function chunkHasPrioritySignal(string $content, array $priorityVenues): bool
    {
        $content = trim($content);
        if ($content === '') {
            return false;
        }
        if (preg_match('/\[\s*Prioridad\s*:\s*Alta\s*\]/iu', $content)
            || preg_match('/\[\s*Tipo\s*:\s*Escenario\s+Principal\s*\]/iu', $content)
            || preg_match('/\bPrioridad\s*:\s*Alta\b/iu', $content)
            || preg_match('/\b(?:Tipo|Clasificaci[oó]n)\s*:\s*[^\n\]]*Escenario\s+Principal/iu', $content)
            || preg_match('/\bMain\s+Stage\b/iu', $content)
        ) {
            return true;
        }
        foreach ($priorityVenues as $venue) {
            $venue = trim((string) $venue);
            if ($venue === '' || mb_strlen($venue, 'UTF-8') < 4) {
                continue;
            }
            if (preg_match(
                '/\b(?:Lugar|Ubicaci[oó]n|Venue|Location|Site|Escenario)\s*:\s*[^\n\]]*?' . preg_quote($venue, '/') . '/iu',
                $content
            )) {
                return true;
            }
            if (mb_stripos($content, $venue, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $raw
     *
     * @return list<string>
     */
    private static function parsePriorityVenues($raw): array
    {
        if (\is_string($raw)) {
            $raw = preg_split('/[\n,;|]+/u', $raw) ?: [];
        }
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (\is_array($item)) {
                $item = (string) ($item['name'] ?? $item['venue'] ?? $item['label'] ?? '');
            }
            $v = trim(preg_replace('/\s+/u', ' ', (string) $item) ?? '');
            if ($v === '' || mb_strlen($v, 'UTF-8') < 3) {
                continue;
            }
            if (mb_strlen($v, 'UTF-8') > 120) {
                $v = mb_substr($v, 0, 120);
            }
            $key = mb_strtolower($v, 'UTF-8');
            $out[$key] = $v;
            if (\count($out) >= 40) {
                break;
            }
        }

        return array_values($out);
    }

    /**
     * Reciprocal Rank Fusion of vector + lexical ranked lists.
     *
     * @param list<array{content: string, score: float}> $vectorHits
     * @param list<array{content: string, score: float}> $lexHits
     *
     * @return list<array{content: string, score: float}>
     */
    private static function mergeHybridVectorLexical(array $vectorHits, array $lexHits, int $poolLimit): array
    {
        $poolLimit = max(1, $poolLimit);
        $k = 60;
        $scores = [];
        $payloads = [];

        $lists = [$vectorHits, $lexHits];
        foreach ($lists as $list) {
            $rank = 0;
            foreach ($list as $h) {
                if (!isset($h['content'])) {
                    continue;
                }
                $content = (string) $h['content'];
                if ($content === '') {
                    continue;
                }
                $key = self::chunkDedupeKey($content);
                ++$rank;
                $scores[$key] = ($scores[$key] ?? 0.0) + (1.0 / ($k + $rank));
                if (!isset($payloads[$key])) {
                    $payloads[$key] = [
                        'content' => $content,
                        'score'   => isset($h['score']) ? (float) $h['score'] : 0.0,
                    ];
                } else {
                    $payloads[$key]['score'] = max(
                        (float) $payloads[$key]['score'],
                        isset($h['score']) ? (float) $h['score'] : 0.0
                    );
                }
            }
        }

        if ($scores === []) {
            return [];
        }

        arsort($scores, SORT_NUMERIC);
        $out = [];
        foreach ($scores as $key => $rrf) {
            $row = $payloads[$key];
            // Preserve RRF ordering; keep original similarity as secondary signal in score field.
            $row['score'] = max((float) ($row['score'] ?? 0), min(0.999, (float) $rrf));
            $out[] = $row;
            if (\count($out) >= $poolLimit) {
                break;
            }
        }

        return $out;
    }

    private static function chunkDedupeKey(string $content): string
    {
        $t = mb_strtolower(trim($content), 'UTF-8');

        return hash('sha256', mb_substr($t, 0, 600, 'UTF-8'));
    }

    /**
     * Agnostic morphology: shared Unicode prefix between needle and haystack tokens
     * (e.g. urbano ↔ urbanas). No synonym dictionaries.
     */
    private static function tokenSoftMatch(string $needle, string $haystack): bool
    {
        $needle = mb_strtolower(trim($needle), 'UTF-8');
        $haystack = mb_strtolower($haystack, 'UTF-8');
        if ($needle === '' || $haystack === '') {
            return false;
        }
        if (mb_strpos($haystack, $needle) !== false) {
            return true;
        }
        $minToken = 4;
        $needleLen = mb_strlen($needle, 'UTF-8');
        if ($needleLen < $minToken) {
            return false;
        }
        if (!preg_match_all('/\p{L}[\p{L}\p{M}\'-]{2,}/u', $haystack, $matches)) {
            return false;
        }
        foreach ($matches[0] as $token) {
            $token = trim((string) $token, "'-");
            $tokenLen = mb_strlen($token, 'UTF-8');
            if ($tokenLen < $minToken) {
                continue;
            }
            $minLen = min($needleLen, $tokenLen);
            $needPrefix = max(4, (int) floor(0.75 * $minLen));
            $common = 0;
            $limit = min($needleLen, $tokenLen);
            for ($i = 0; $i < $limit; $i++) {
                if (mb_substr($needle, $i, 1, 'UTF-8') !== mb_substr($token, $i, 1, 'UTF-8')) {
                    break;
                }
                ++$common;
            }
            if ($common >= $needPrefix) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{content: string, score: float}> $fullRanked
     * @param list<array<string, mixed>>                $rows
     *
     * @return list<array{content: string, score: float}>
     */
    private static function selectCatalogDiverseTopK(array $fullRanked, array $rows, int $maxChunks): array
    {
        $maxChunks = max(1, $maxChunks);
        if ($fullRanked === []) {
            return [];
        }
        $enteByKey = self::buildContentEnteKeyMap($rows);
        $out = [];
        $seenEntes = [];
        foreach ($fullRanked as $h) {
            if (!isset($h['content'], $h['score'])) {
                continue;
            }
            $key = self::chunkDedupeKey((string) $h['content']);
            $enteId = $enteByKey[$key] ?? $key;
            if ($enteId !== '' && isset($seenEntes[$enteId])) {
                continue;
            }
            if ($enteId !== '') {
                $seenEntes[$enteId] = true;
            }
            $out[] = ['content' => (string) $h['content'], 'score' => (float) $h['score']];
            if (\count($out) >= $maxChunks) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, string>
     */
    private static function buildContentEnteKeyMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $r) {
            if (!\is_array($r)) {
                continue;
            }
            $chunkText = self::formatChunkForRagContext(
                (string) ($r['content_chunk'] ?? ''),
                (string) ($r['meta_json'] ?? '')
            );
            if ($chunkText === '') {
                continue;
            }
            $map[self::chunkDedupeKey($chunkText)] = trim((string) ($r['ente_id'] ?? ''));
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string>      $keywordExpansions
     *
     * @return list<array{content: string, score: float}>
     */
    private static function lexicalFallbackMatches(
        array $rows,
        string $query,
        int $maxChunks,
        array $keywordExpansions = []
    ): array {
        $terms = self::normalizeQueryTermsForLexical($query);
        $meaningful = self::meaningfulQueryTokens($terms);
        $literals = self::literalQuerySubstrings($query);
        if ($meaningful === [] && $literals === []) {
            return [];
        }
        $needles = self::filterNeedlesForMatching(self::needlesFromMeaningfulTokens($meaningful));
        if ($needles === []) {
            $needles = self::fallbackLongNeedlesOnly($meaningful);
        }
        $needles = self::expandNeedlesWithKeywordMap($needles, $keywordExpansions);
        $hits = [];
        foreach ($rows as $r) {
            if (!\is_array($r)) {
                continue;
            }
            $chunkText = self::formatChunkForRagContext(
                (string) ($r['content_chunk'] ?? ''),
                (string) ($r['meta_json'] ?? '')
            );
            if ($chunkText === '') {
                continue;
            }
            $hay = mb_strtolower($chunkText, 'UTF-8');
            $verbatimScore = 0.0;
            foreach ($literals as $lit) {
                $ll = mb_strtolower($lit, 'UTF-8');
                if ($ll !== '' && mb_strpos($hay, $ll) !== false) {
                    $verbatimScore = max($verbatimScore, 0.94 + 0.001 * min(40, mb_strlen($ll, 'UTF-8')));
                }
            }
            $matched = 0;
            $maxLen = 0;
            foreach ($needles as $ndl) {
                $nl = mb_strtolower($ndl, 'UTF-8');
                if ($nl === '') {
                    continue;
                }
                if (mb_strpos($hay, $nl) !== false || self::tokenSoftMatch($nl, $hay)) {
                    ++$matched;
                    $maxLen = max($maxLen, mb_strlen($nl, 'UTF-8'));
                }
            }
            if ($verbatimScore <= 0.0 && $matched < 1) {
                continue;
            }
            $tokenScore = $matched > 0
                ? min(0.96, 0.34 + 0.14 * $matched + 0.004 * $maxLen)
                : 0.0;
            $score = max($verbatimScore, $tokenScore);
            $hits[] = [
                'content' => $chunkText,
                'score'   => min(0.98, $score),
            ];
        }
        usort($hits, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($hits, 0, max(1, $maxChunks));
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n < 1) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; ++$i) {
            $x = $a[$i];
            $y = $b[$i];
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }
        $den = sqrt($na) * sqrt($nb);

        return $den > 0.0 ? $dot / $den : 0.0;
    }

    private static function formatChunkForRagContext(string $contentChunk, string $metaJson): string
    {
        $content = trim($contentChunk);
        $meta = json_decode($metaJson, true);
        if (!is_array($meta)) {
            return $content;
        }
        $img = '';
        foreach (['__image_url', 'empresa_logo', 'logotipo', 'logo', 'imagen', 'image', 'url_imagen', 'imagen_url', 'featured_image', 'foto', 'photo'] as $k) {
            if (!empty($meta[$k]) && is_scalar($meta[$k])) {
                $cand = trim((string) $meta[$k]);
                if ($cand !== '' && preg_match('#^https?://#i', $cand)) {
                    $img = $cand;
                    break;
                }
            }
        }
        if ($img === '') {
            return $content;
        }
        $marker = '[Imagen disponible: ' . $img . ']';
        if ($content !== '' && (stripos($content, $marker) !== false || stripos($content, $img) !== false)) {
            return $content;
        }

        return $content === '' ? $marker : (rtrim($content) . "\n" . $marker);
    }

    /**
     * @param list<string> $chunks
     */
    private static function formatContext(array $chunks): string
    {
        $chunks = array_values(array_filter(array_map('trim', $chunks)));
        if ($chunks === []) {
            return '';
        }

        return implode("\n\n", $chunks);
    }

    /**
     * @param mixed $raw
     *
     * @return array{match_in_header: list<string>, exclude_in_category: list<string>, exclude_ente_slugs: list<string>, match_regexp?: string}
     */
    private static function parseActivityProfile($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $match = array_values(array_filter(array_map('strval', $raw['match_in_header'] ?? [])));
        $regexp = trim((string) ($raw['match_regexp'] ?? ''));
        if ($match === [] && $regexp === '') {
            return [];
        }

        $out = [
            'match_in_header'     => $match,
            'exclude_in_category' => array_values(array_filter(array_map('strval', $raw['exclude_in_category'] ?? []))),
            'exclude_ente_slugs'  => array_values(array_filter(array_map('strval', $raw['exclude_ente_slugs'] ?? []))),
        ];
        if ($regexp !== '') {
            $out['match_regexp'] = $regexp;
        }

        return $out;
    }

    private static function catalogNorm(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        if ($text === '') {
            return '';
        }
        static $accent = [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n',
        ];

        return strtr($text, $accent);
    }

    private static function companyActivityMatchText(string $chunk): string
    {
        $chunk = trim(strip_tags($chunk));
        if ($chunk === '') {
            return '';
        }
        $header = $chunk;
        if (preg_match('/^(.*?)(?:\s*\|\s*(?:EXPERIENCIAS|PROPUESTAS|DESCRIPCI[ÓO]N\s+GENERAL):)/isu', $chunk, $m)) {
            $header = trim((string) $m[1]);
        }
        $parts = [$header];
        if (preg_match('/\bEXPERIENCIAS:\s*(.+?)(?:\s*\|\s*(?:PROPUESTAS|DESCRIPCI[ÓO]N\s+GENERAL):)/isu', $chunk, $em)) {
            $parts[] = trim((string) $em[1]);
        } elseif (preg_match('/\bEXPERIENCIAS:\s*(.+)$/isu', $chunk, $em)) {
            $parts[] = trim((string) $em[1]);
        }

        return self::catalogNorm(trim(implode(' ', array_filter($parts))));
    }

    /**
     * @param array{match_in_header: list<string>, exclude_in_category: list<string>, exclude_ente_slugs: list<string>} $profile
     */
    private static function matchesCatalogActivityProfile(string $chunk, array $profile, string $enteId = ''): bool
    {
        if ($profile === []) {
            return true;
        }
        $enteId = self::catalogNorm($enteId);
        foreach ($profile['exclude_ente_slugs'] as $slug) {
            $sl = self::catalogNorm((string) $slug);
            if ($sl !== '' && $enteId !== '' && mb_strpos($enteId, $sl) !== false) {
                return false;
            }
        }
        $header = $chunk;
        if (preg_match('/^(.*?)(?:\s*\|\s*(?:EXPERIENCIAS|PROPUESTAS|DESCRIPCI[ÓO]N\s+GENERAL):)/isu', $chunk, $hm)) {
            $header = trim((string) $hm[1]);
        }
        $category = '';
        if (preg_match('/\bcategor[ií]a:\s*([^|]+)/iu', $header, $cm)) {
            $category = self::catalogNorm(trim((string) $cm[1]));
        }
        foreach ($profile['exclude_in_category'] as $ex) {
            $exl = self::catalogNorm((string) $ex);
            if ($exl === '' || $category === '') {
                continue;
            }
            if ($category === $exl || mb_strpos($category, $exl) !== false) {
                return false;
            }
        }
        $matchRegexp = trim((string) ($profile['match_regexp'] ?? ''));
        if ($matchRegexp !== '') {
            $blob = self::catalogNorm(strip_tags($chunk));
            if ($blob !== '' && @preg_match('/' . $matchRegexp . '/iu', $blob) === 1) {
                return true;
            }
        }
        $haystack = self::companyActivityMatchText($chunk);
        if ($haystack === '') {
            return false;
        }
        foreach ($profile['match_in_header'] as $needle) {
            $nl = self::catalogNorm((string) $needle);
            if ($nl === '' || mb_strlen($nl, 'UTF-8') < 3) {
                continue;
            }
            if (mb_strpos($haystack, $nl) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function compactCompanyPassportChunk(string $chunk): string
    {
        $chunk = trim(strip_tags($chunk));
        if ($chunk === '' || !preg_match('/\bEMPRESA:\s*([^|]+)/iu', $chunk)) {
            return '';
        }
        $labels = ['EMPRESA', 'CATEGORÍA', 'CATEGORIA', 'SUBCATEGORÍAS', 'SUBCATEGORIAS', 'LOCALIDAD', 'UBICACIÓN', 'UBICACION', 'MUNICIPIO', 'ZONA', 'TERRITORIO'];
        $parts = [];
        foreach ($labels as $label) {
            $re = '/\b' . preg_quote($label, '/') . ':\s*([^|]+)/iu';
            if (preg_match($re, $chunk, $m)) {
                $val = trim((string) $m[1]);
                if ($val !== '') {
                    $parts[$label] = $label . ': ' . $val;
                }
            }
        }
        if ($parts === []) {
            return 'EMPRESA: ' . trim(preg_replace('/\s*\|.*/', '', preg_replace('/^.*?\bEMPRESA:\s*/iu', '', $chunk)));
        }

        return implode(' | ', array_values($parts));
    }

    /**
     * Barrido completo de fichas EMPRESA (sin top-k vectorial).
     *
     * @param list<array<string, mixed>> $rows
     * @param array{match_in_header: list<string>, exclude_in_category: list<string>, exclude_ente_slugs: list<string>} $profile
     *
     * @return list<array{content: string, score: float}>
     */
    private static function exhaustiveCatalogActivityScan(array $rows, array $profile, int $maxChunks): array
    {
        $maxChunks = max(1, min(self::MAX_CATALOG_CHUNKS_CAP, $maxChunks));
        $out = [];
        $seenEntes = [];
        foreach ($rows as $r) {
            if (!\is_array($r)) {
                continue;
            }
            $raw = (string) ($r['content_chunk'] ?? '');
            if ($raw === '' || preg_match('/\bEMPRESA:/iu', $raw) !== 1) {
                continue;
            }
            $enteId = trim((string) ($r['ente_id'] ?? ''));
            if ($enteId !== '' && isset($seenEntes[$enteId])) {
                continue;
            }
            if (!self::matchesCatalogActivityProfile($raw, $profile, $enteId)) {
                continue;
            }
            $compact = self::compactCompanyPassportChunk($raw);
            if ($compact === '') {
                continue;
            }
            if ($enteId !== '') {
                $seenEntes[$enteId] = true;
            }
            $out[] = ['content' => $compact, 'score' => 0.85];
            if (\count($out) >= $maxChunks) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<array{content: string, score: float}> $scored
     * @param array<string, mixed>                       $catalogDebug
     */
    private static function respondScoredChunks(array $scored, int $maxChunks, array $catalogDebug = []): void
    {
        $maxChunks = max(1, $maxChunks);
        $scored = array_slice($scored, 0, $maxChunks);
        $returnedCount = \count($scored);
        $context = self::formatContext(array_map(static fn (array $s): string => $s['content'], $scored));
        $avg = $scored === [] ? null : array_sum(array_column($scored, 'score')) / $returnedCount;
        $payload = [
            'ok'             => true,
            'chunk_count'    => $returnedCount,
            'context'        => $context,
            'similarity_avg' => $avg,
            'chunks'         => $scored,
        ];
        if ($returnedCount > 0) {
            $payload['total_found'] = $returnedCount;
        }
        if ($catalogDebug !== []) {
            $payload['catalog_debug'] = $catalogDebug;
        }
        Json::respond(200, $payload);
    }
}
