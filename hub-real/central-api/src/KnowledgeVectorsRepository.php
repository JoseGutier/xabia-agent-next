<?php

declare(strict_types=1);

namespace XabiaCentral;

use PDO;
use Throwable;

final class KnowledgeVectorsRepository
{
    /**
     * Restringe a registros de ente concreto (slug en ente_id), no a prefijos en content_chunk.
     *
     * @param array<string, mixed> $params
     */
    private static function appendEntityRecordFilter(string &$sql, string $enteColumn): void
    {
        $sql .= ' AND ' . $enteColumn . ' IS NOT NULL AND ' . $enteColumn . " != '' AND " . $enteColumn . " != 'global'";
    }

    public static function deleteByLicenseAndProject(int $licenseId, string $projectId): int
    {
        $projectId = substr(trim($projectId), 0, 191);
        if ($licenseId < 1 || $projectId === '') {
            return 0;
        }
        try {
            $st = Db::pdo()->prepare(
                'DELETE FROM xabia_knowledge_store WHERE license_id = :lid AND project_id = :pid'
            );
            $st->execute([':lid' => $licenseId, ':pid' => $projectId]);
            $deleted = $st->rowCount();

            $legacy = Db::pdo()->prepare(
                'DELETE FROM xabia_knowledge_vectors WHERE license_id = :lid AND project_id = :pid'
            );
            $legacy->execute([':lid' => $licenseId, ':pid' => $projectId]);

            return $deleted + $legacy->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * @return list<array{id: int, ente_id: string, content_chunk: string, vector_json: string, meta_json: ?string}>
     */
    public static function fetchVectorsForSearch(int $licenseId, string $projectId, ?string $enteScope, int $limit): array
    {
        $projectId = substr(trim($projectId), 0, 191);
        if ($licenseId < 1 || $projectId === '') {
            return [];
        }
        $limit = max(1, min(800, $limit));
        try {
            $sql = 'SELECT
                        kv.id,
                        COALESCE(ks.ente_id, kv.ente_id, \'global\') AS ente_id,
                        COALESCE(ks.content_chunk, kv.content_chunk) AS content_chunk,
                        kv.vector_json,
                        COALESCE(ks.meta_json, kv.meta_json) AS meta_json
                    FROM xabia_knowledge_vectors kv
                    LEFT JOIN xabia_knowledge_store ks ON ks.id = kv.knowledge_store_id
                    WHERE kv.license_id = :lid
                      AND kv.project_id = :pid
                      AND kv.vector_json IS NOT NULL
                      AND TRIM(kv.vector_json) != \'\'
                      AND kv.vector_json != \'[]\'
                      AND kv.vectorization_status = \'SYNCHRONIZED\'';
            $params = [':lid' => $licenseId, ':pid' => $projectId];
            if ($enteScope !== null && $enteScope !== '' && $enteScope !== 'global') {
                $sql .= ' AND COALESCE(ks.ente_id, kv.ente_id) = :eid';
                $params[':eid'] = substr($enteScope, 0, 100);
            }
            $sql .= ' ORDER BY kv.id DESC LIMIT ' . $limit;
            $st = Db::pdo()->prepare($sql);
            $st->execute($params);
            $out = $st->fetchAll(PDO::FETCH_ASSOC);

            return is_array($out) ? $out : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Fichas de catálogo en store (sin exigir vector): listados exhaustivos por actividad.
     *
     * @return list<array{id: int, ente_id: string, content_chunk: string, meta_json: ?string}>
     */
    public static function fetchStoreChunksForCatalog(int $licenseId, string $projectId, ?string $enteScope, int $limit, string $matchRegexp = '', bool $entityRecordsOnly = true): array
    {
        $projectId = substr(trim($projectId), 0, 191);
        if ($licenseId < 1 || $projectId === '') {
            return [];
        }
        $limit = max(1, min(800, $limit));
        $matchRegexp = self::sanitizeCatalogMatchRegexp($matchRegexp);
        try {
            $sql = 'SELECT id, ente_id, content_chunk, meta_json
                    FROM xabia_knowledge_store
                    WHERE license_id = :lid
                      AND project_id = :pid';
            $params = [':lid' => $licenseId, ':pid' => $projectId];
            if ($entityRecordsOnly) {
                self::appendEntityRecordFilter($sql, 'ente_id');
            }
            if ($enteScope !== null && $enteScope !== '' && $enteScope !== 'global') {
                $sql .= ' AND ente_id = :eid';
                $params[':eid'] = substr($enteScope, 0, 100);
            }
            if ($matchRegexp !== '') {
                $sql .= ' AND LOWER(content_chunk) REGEXP :re';
                $params[':re'] = $matchRegexp;
            }
            $sql .= ' ORDER BY id ASC LIMIT ' . $limit;
            $st = Db::pdo()->prepare($sql);
            $st->execute($params);
            $out = $st->fetchAll(PDO::FETCH_ASSOC);

            return is_array($out) ? $out : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Listado exhaustivo: store canónico; si vacío, fichas EMPRESA en vectors+store sin exigir embedding.
     *
     * @return array{rows: list<array{id: int, ente_id: string, content_chunk: string, meta_json: ?string}>, source: string, store_rows: int, vector_rows: int}
     */
    public static function fetchEmpresaChunksForCatalogExhaustive(
        int $licenseId,
        string $projectId,
        ?string $enteScope,
        int $limit,
        string $matchRegexp = ''
    ): array {
        $empty = ['rows' => [], 'source' => 'none', 'store_rows' => 0, 'vector_rows' => 0];
        $projectId = substr(trim($projectId), 0, 191);
        if ($licenseId < 1 || $projectId === '') {
            return $empty;
        }
        $limit = max(1, min(800, $limit));
        $matchRegexp = self::sanitizeCatalogMatchRegexp($matchRegexp);

        // Listado exhaustivo: project_id es la clave del corpus (p. ej. 68 empresas Aktiba).
        // Filtrar primero por license_id dejaba 2 filas cuando el store tiene 68 por proyecto.
        $storeRows = self::fetchStoreChunksForCatalogByProject($projectId, $enteScope, $limit, $matchRegexp);
        $source = 'store_project';
        if ($storeRows === []) {
            $storeRows = self::fetchStoreChunksForCatalog($licenseId, $projectId, $enteScope, $limit, $matchRegexp);
            $source = 'store_license';
        }
        if ($storeRows !== []) {
            return [
                'rows'        => $storeRows,
                'source'      => $source,
                'store_rows'  => \count($storeRows),
                'vector_rows' => 0,
            ];
        }

        $vectorRows = self::fetchEmpresaChunksFromVectorsJoin($licenseId, $projectId, $enteScope, $limit, $matchRegexp);

        return [
            'rows'        => $vectorRows,
            'source'      => $vectorRows !== [] ? 'vectors_join' : 'none',
            'store_rows'  => 0,
            'vector_rows' => \count($vectorRows),
        ];
    }

    /**
     * @return list<array{id: int, ente_id: string, content_chunk: string, meta_json: ?string}>
     */
    /**
     * Rescate léxico en store canónico (p. ej. aguja «velero» ausente en vectores del proyecto demo).
     *
     * @return list<array{id: int, ente_id: string, content_chunk: string, meta_json: ?string}>
     */
    public static function fetchStoreChunksMatchingNeedle(
        int $licenseId,
        ?string $projectId,
        string $needle,
        int $limit,
        ?string $enteScope = null
    ): array {
        $needle = trim(mb_strtolower($needle, 'UTF-8'));
        if ($licenseId < 1 || $needle === '' || mb_strlen($needle, 'UTF-8') < 4) {
            return [];
        }
        $limit = max(1, min(20, $limit));
        try {
            $projectId = $projectId !== null ? substr(trim($projectId), 0, 191) : '';
            $sql = 'SELECT
                        ks.id,
                        ks.ente_id,
                        ks.content_chunk,
                        ks.meta_json,
                        kv.vector_json
                    FROM xabia_knowledge_store ks
                    LEFT JOIN xabia_knowledge_vectors kv
                        ON kv.knowledge_store_id = ks.id
                       AND kv.vectorization_status = \'SYNCHRONIZED\'
                    WHERE ks.license_id = :lid
                      AND (
                            LOWER(ks.content_chunk) LIKE :needle
                         OR LOWER(COALESCE(ks.ente_id, \'\')) LIKE :needle
                      )';
            $params = [
                ':lid'    => $licenseId,
                ':needle' => '%' . $needle . '%',
            ];
            if ($projectId !== '') {
                $sql .= ' AND ks.project_id = :pid';
                $params[':pid'] = $projectId;
            } else {
                // Aislamiento: sin project_id no se busca a nivel de licencia.
                return [];
            }
            if ($enteScope !== null && $enteScope !== '' && $enteScope !== 'global') {
                $sql .= ' AND ks.ente_id = :eid';
                $params[':eid'] = substr($enteScope, 0, 100);
            }
            $sql .= ' ORDER BY (ks.ente_id IS NOT NULL AND ks.ente_id != \'\' AND ks.ente_id != \'global\') DESC, ks.id DESC LIMIT ' . $limit;
            $st = Db::pdo()->prepare($sql);
            $st->execute($params);
            $out = $st->fetchAll(PDO::FETCH_ASSOC);

            return is_array($out) ? $out : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fetchStoreChunksMatchingRegexp(
        int $licenseId,
        ?string $projectId,
        string $regexp,
        int $limit,
        ?string $enteScope = null
    ): array {
        $regexp = trim($regexp);
        if ($licenseId < 1 || $regexp === '') {
            return [];
        }
        $limit = max(1, min(20, $limit));
        try {
            $projectId = $projectId !== null ? substr(trim($projectId), 0, 191) : '';
            if ($projectId === '') {
                return [];
            }
            $sql = 'SELECT
                        ks.id,
                        ks.ente_id,
                        ks.content_chunk,
                        ks.meta_json,
                        kv.vector_json
                    FROM xabia_knowledge_store ks
                    LEFT JOIN xabia_knowledge_vectors kv
                        ON kv.knowledge_store_id = ks.id
                       AND kv.vectorization_status = \'SYNCHRONIZED\'
                    WHERE ks.license_id = :lid
                      AND (
                            LOWER(ks.content_chunk) REGEXP :re
                         OR LOWER(COALESCE(ks.ente_id, \'\')) REGEXP :re
                      )';
            $params = [
                ':lid' => $licenseId,
                ':re'  => $regexp,
            ];
            if ($projectId !== '') {
                $sql .= ' AND ks.project_id = :pid';
                $params[':pid'] = $projectId;
            }
            if ($enteScope !== null && $enteScope !== '' && $enteScope !== 'global') {
                $sql .= ' AND ks.ente_id = :eid';
                $params[':eid'] = substr($enteScope, 0, 100);
            }
            $sql .= ' ORDER BY (ks.ente_id IS NOT NULL AND ks.ente_id != \'\' AND ks.ente_id != \'global\') DESC, ks.id DESC LIMIT ' . $limit;
            $st = Db::pdo()->prepare($sql);
            $st->execute($params);
            $out = $st->fetchAll(PDO::FETCH_ASSOC);

            return is_array($out) ? $out : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function fetchStoreChunksForCatalogByProject(string $projectId, ?string $enteScope, int $limit, string $matchRegexp = '', bool $entityRecordsOnly = true): array
    {
        $limit = max(1, min(800, $limit));
        try {
            $sql = 'SELECT id, ente_id, content_chunk, meta_json
                    FROM xabia_knowledge_store
                    WHERE project_id = :pid';
            $params = [':pid' => $projectId];
            if ($entityRecordsOnly) {
                self::appendEntityRecordFilter($sql, 'ente_id');
            }
            if ($enteScope !== null && $enteScope !== '' && $enteScope !== 'global') {
                $sql .= ' AND ente_id = :eid';
                $params[':eid'] = substr($enteScope, 0, 100);
            }
            if ($matchRegexp !== '') {
                $sql .= ' AND LOWER(content_chunk) REGEXP :re';
                $params[':re'] = $matchRegexp;
            }
            $sql .= ' ORDER BY id ASC LIMIT ' . $limit;
            $st = Db::pdo()->prepare($sql);
            $st->execute($params);
            $out = $st->fetchAll(PDO::FETCH_ASSOC);

            return is_array($out) ? $out : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @return list<array{id: int, ente_id: string, content_chunk: string, meta_json: ?string}>
     */
    private static function fetchEmpresaChunksFromVectorsJoin(
        int $licenseId,
        string $projectId,
        ?string $enteScope,
        int $limit,
        string $matchRegexp = ''
    ): array {
        $limit = max(1, min(800, $limit));
        try {
            $sql = 'SELECT
                        COALESCE(ks.id, kv.id) AS id,
                        COALESCE(ks.ente_id, kv.ente_id, \'global\') AS ente_id,
                        COALESCE(ks.content_chunk, kv.content_chunk) AS content_chunk,
                        COALESCE(ks.meta_json, kv.meta_json) AS meta_json
                    FROM xabia_knowledge_vectors kv
                    LEFT JOIN xabia_knowledge_store ks ON ks.id = kv.knowledge_store_id
                    WHERE kv.license_id = :lid
                      AND kv.project_id = :pid';
            $params = [':lid' => $licenseId, ':pid' => $projectId];
            self::appendEntityRecordFilter($sql, 'COALESCE(ks.ente_id, kv.ente_id)');
            if ($enteScope !== null && $enteScope !== '' && $enteScope !== 'global') {
                $sql .= ' AND COALESCE(ks.ente_id, kv.ente_id) = :eid';
                $params[':eid'] = substr($enteScope, 0, 100);
            }
            if ($matchRegexp !== '') {
                $sql .= ' AND LOWER(COALESCE(ks.content_chunk, kv.content_chunk)) REGEXP :re';
                $params[':re'] = $matchRegexp;
            }
            $sql .= ' ORDER BY COALESCE(ks.id, kv.id) ASC LIMIT ' . ($limit * 3);
            $st = Db::pdo()->prepare($sql);
            $st->execute($params);
            $raw = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($raw)) {
                return [];
            }
            $out = [];
            $seen = [];
            foreach ($raw as $r) {
                if (!\is_array($r)) {
                    continue;
                }
                $ente = trim((string) ($r['ente_id'] ?? ''));
                $dedupe = $ente !== '' && $ente !== 'global' ? $ente : (string) ($r['id'] ?? '');
                if ($dedupe !== '' && isset($seen[$dedupe])) {
                    continue;
                }
                if ($dedupe !== '') {
                    $seen[$dedupe] = true;
                }
                $out[] = [
                    'id'            => (int) ($r['id'] ?? 0),
                    'ente_id'       => $ente,
                    'content_chunk' => (string) ($r['content_chunk'] ?? ''),
                    'meta_json'     => isset($r['meta_json']) ? (string) $r['meta_json'] : null,
                ];
                if (\count($out) >= $limit) {
                    break;
                }
            }

            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function sanitizeCatalogMatchRegexp(string $regexp): string
    {
        $regexp = trim($regexp);
        if ($regexp === '') {
            return '';
        }
        if (!preg_match('/^[a-z0-9_|]+$/i', $regexp)) {
            return '';
        }

        return $regexp;
    }

    public static function isValidVectorJson(string $json): bool
    {
        $a = json_decode($json, true);
        if (!is_array($a) || $a === []) {
            return false;
        }
        $n = 0;
        foreach ($a as $v) {
            if (!is_numeric($v)) {
                return false;
            }
            ++$n;
            if ($n > 16384) {
                return false;
            }
        }

        return $n >= 64;
    }

    /**
     * UPSERT por (license_id, project_id, source_record_id).
     *
     * @param list<array{
     *   ente_id: string,
     *   source_record_id: string,
     *   content_chunk: string,
     *   content_hash: ?string,
     *   meta_json: ?string,
     *   vector_json: string,
     *   meta_only: bool
     * }> $batch
     * @return array{upserted: int, inserted: int, updated: int, skipped: int, pending: int, synchronized: int}
     */
    public static function upsertBatch(PDO $pdo, int $licenseId, string $projectId, array $batch): array
    {
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $pending = 0;
        $synchronized = 0;
        $projectId = substr(trim($projectId), 0, 191);

        foreach ($batch as $row) {
            $identity = KnowledgeSlug::resolveIdentity([
                'ente_id'          => (string) ($row['ente_id'] ?? 'global'),
                'source_record_id' => (string) ($row['source_record_id'] ?? ''),
                'meta_json'        => $row['meta_json'] ?? null,
            ]);
            $enteId = $identity['ente_id'];
            $sourceId = $identity['source_record_id'];
            $chunk = (string) ($row['content_chunk'] ?? '');
            $hash = isset($row['content_hash']) && $row['content_hash'] !== ''
                ? self::normalizeContentHash((string) $row['content_hash'])
                : null;
            $metaJson = $row['meta_json'] ?? null;
            $vecJson = (string) ($row['vector_json'] ?? '');
            $metaOnly = !empty($row['meta_only']);

            if ($chunk === '' || $enteId === '' || $enteId === 'global') {
                ++$skipped;
                continue;
            }
            $sourceId = $enteId;
            $hasVector = self::isValidVectorJson($vecJson);

            $store = $pdo->prepare(
                'INSERT INTO xabia_knowledge_store
                 (license_id, project_id, source_record_id, ente_id, content_chunk, content_hash, meta_json, vectorization_status, vectorized_at)
                 VALUES (:lid, :pid, :sid, :eid, :chunk, :hash, :meta, :status, :vectorized_at)
                 ON DUPLICATE KEY UPDATE
                    source_record_id = VALUES(ente_id),
                    ente_id = VALUES(ente_id),
                    content_chunk = IF(VALUES(content_hash) IS NOT NULL AND content_hash <=> VALUES(content_hash), content_chunk, VALUES(content_chunk)),
                    meta_json = VALUES(meta_json),
                    vectorization_status = IF(:has_vector_status = 1, \'SYNCHRONIZED\', IF(VALUES(content_hash) IS NOT NULL AND content_hash <=> VALUES(content_hash), vectorization_status, \'PENDING\')),
                    vectorization_error = IF(:has_vector_error = 1 OR NOT (VALUES(content_hash) IS NOT NULL AND content_hash <=> VALUES(content_hash)), NULL, vectorization_error),
                    vectorized_at = IF(:has_vector_at = 1, UTC_TIMESTAMP(), IF(VALUES(content_hash) IS NOT NULL AND content_hash <=> VALUES(content_hash), vectorized_at, NULL)),
                    content_hash = VALUES(content_hash),
                    updated_at = UTC_TIMESTAMP(),
                    id = LAST_INSERT_ID(id)'
            );
            $store->execute([
                ':lid'           => $licenseId,
                ':pid'           => $projectId,
                ':sid'           => $sourceId,
                ':eid'           => $enteId !== '' ? $enteId : 'global',
                ':chunk'         => $chunk,
                ':hash'          => $hash,
                ':meta'          => $metaJson,
                ':status'        => $hasVector ? 'SYNCHRONIZED' : 'PENDING',
                ':vectorized_at' => $hasVector ? gmdate('Y-m-d H:i:s') : null,
                ':has_vector_status' => $hasVector ? 1 : 0,
                ':has_vector_error'  => $hasVector ? 1 : 0,
                ':has_vector_at'     => $hasVector ? 1 : 0,
            ]);
            if ($store->rowCount() === 1) {
                ++$inserted;
            } else {
                ++$updated;
            }
            $knowledgeStoreId = (int) $pdo->lastInsertId();

            if (!$hasVector) {
                $pendingVector = $pdo->prepare(
                    'UPDATE xabia_knowledge_vectors
                     SET source_record_id = :sid,
                         ente_id = :eid,
                         content_chunk = :chunk,
                         content_hash = :hash,
                         meta_json = :meta,
                         vectorization_status = \'PENDING\',
                         vectorization_error = NULL,
                         vectorized_at = NULL,
                         updated_at = UTC_TIMESTAMP()
                     WHERE license_id = :lid
                       AND project_id = :pid
                       AND ente_id = :eid_match
                       AND NOT (content_hash <=> :hash_match)'
                );
                $pendingVector->execute([
                    ':eid'        => $enteId,
                    ':sid'        => $sourceId,
                    ':chunk'      => $chunk,
                    ':hash'       => $hash,
                    ':meta'       => $metaJson,
                    ':lid'        => $licenseId,
                    ':pid'        => $projectId,
                    ':eid_match'  => $enteId,
                    ':hash_match' => $hash,
                ]);
                ++$pending;
                unset($store, $pendingVector, $row);
                continue;
            }

            $st = $pdo->prepare(
                'INSERT INTO xabia_knowledge_vectors
                 (knowledge_store_id, license_id, project_id, source_record_id, ente_id, content_chunk, content_hash, embedding_model, meta_json, vector_json, vectorization_status, vectorized_at)
                 VALUES (:ksid, :lid, :pid, :sid, :eid, :chunk, :hash, :model, :meta, :vec, \'SYNCHRONIZED\', UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                    knowledge_store_id = VALUES(knowledge_store_id),
                    source_record_id = VALUES(ente_id),
                    ente_id = VALUES(ente_id),
                    content_chunk = IF(VALUES(content_hash) IS NOT NULL AND content_hash <=> VALUES(content_hash), content_chunk, VALUES(content_chunk)),
                    embedding_model = VALUES(embedding_model),
                    meta_json = VALUES(meta_json),
                    vector_json = IF(:meta_only = 1 OR (VALUES(content_hash) IS NOT NULL AND content_hash <=> VALUES(content_hash)), vector_json, VALUES(vector_json)),
                    vectorization_status = \'SYNCHRONIZED\',
                    vectorization_error = NULL,
                    vectorized_at = UTC_TIMESTAMP(),
                    content_hash = VALUES(content_hash),
                    updated_at = UTC_TIMESTAMP()'
            );
            $st->execute([
                ':ksid'      => $knowledgeStoreId > 0 ? $knowledgeStoreId : null,
                ':lid'       => $licenseId,
                ':pid'       => $projectId,
                ':sid'       => $sourceId,
                ':eid'       => $enteId !== '' ? $enteId : 'global',
                ':chunk'     => $chunk,
                ':hash'      => $hash,
                ':model'     => (string) ($row['embedding_model'] ?? 'unknown'),
                ':meta'      => $metaJson,
                ':vec'       => $vecJson,
                ':meta_only' => $metaOnly ? 1 : 0,
            ]);
            ++$synchronized;
            unset($store, $st, $row);
        }

        return [
            'upserted' => $inserted + $updated,
            'inserted' => $inserted,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'pending'  => $pending,
            'synchronized' => $synchronized,
        ];
    }

    /**
     * @return array{id: int, content_hash: ?string}|null
     */
    private static function findBySourceInTransaction(PDO $pdo, int $licenseId, string $projectId, string $sourceId): ?array
    {
        try {
            $st = $pdo->prepare(
                'SELECT id, content_hash FROM xabia_knowledge_vectors
                 WHERE license_id = :lid AND project_id = :pid AND source_record_id = :sid LIMIT 1'
            );
            $st->execute([
                ':lid' => $licenseId,
                ':pid' => $projectId,
                ':sid' => $sourceId,
            ]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * SHA-256 (64 hex) o MD5 legado (32). No truncar a 32: invalidaría el delta y re-embebería todo.
     */
    private static function normalizeContentHash(string $hash): ?string
    {
        $hash = strtolower(trim($hash));
        if ($hash === '') {
            return null;
        }
        if (preg_match('/^[0-9a-f]{32}([0-9a-f]{32})?$/', $hash) !== 1) {
            return substr($hash, 0, 64);
        }

        return $hash;
    }
}
