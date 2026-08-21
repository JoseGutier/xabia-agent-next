<?php

declare(strict_types=1);

namespace XabiaCentral;

use Throwable;

/**
 * Sincronización de vectores desde WordPress.
 * v1.0.61+: UPSERT incremental por source_record_id (replace_project solo si el cliente lo pide explícitamente).
 */
final class KnowledgeSyncHandler
{
    private const MAX_ITEMS = 250;

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
        $projectId = substr($projectId, 0, 191);

        if (!empty($input['purge_only'])) {
            try {
                $deleted = KnowledgeVectorsRepository::deleteByLicenseAndProject($licenseId, $projectId);
                Json::respond(200, [
                    'ok'         => true,
                    'deleted'    => $deleted,
                    'project_id' => $projectId,
                    'purged'     => true,
                ]);
            } catch (Throwable $e) {
                Json::respond(500, [
                    'error' => [
                        'message' => $e->getMessage(),
                        'type'    => 'purge_failed',
                    ],
                ]);
            }

            return;
        }

        $items = $input['items'] ?? null;
        if (!is_array($items) || $items === []) {
            Json::respond(400, ['error' => ['message' => 'items[] vacío o inválido', 'type' => 'invalid_request']]);

            return;
        }
        if (count($items) > self::MAX_ITEMS) {
            Json::respond(400, [
                'error' => [
                    'message' => 'Demasiados items en un lote (máx. ' . self::MAX_ITEMS . ')',
                    'type'    => 'invalid_request',
                ],
            ]);

            return;
        }

        // replace_project=true: borrado completo legacy (sync manual total). Por defecto: incremental UPSERT.
        $replaceProject = !empty($input['replace_project']);
        $incremental = array_key_exists('incremental', $input)
            ? !empty($input['incremental'])
            : !$replaceProject;

        $batch = [];
        $skipped = 0;
        foreach ($items as $it) {
            if (!is_array($it)) {
                ++$skipped;
                continue;
            }
            $enteId = KnowledgeSlug::canonical(trim((string) ($it['ente_id'] ?? 'global')));
            $chunk = trim((string) ($it['content_chunk'] ?? ''));
            if ($chunk === '') {
                ++$skipped;
                continue;
            }
            if (strlen($chunk) > 500000) {
                $chunk = substr($chunk, 0, 500000);
            }

            $sourceRecordId = KnowledgeSlug::canonical(trim((string) ($it['source_record_id'] ?? '')));
            if ($sourceRecordId === '' && isset($it['meta_data']) && is_array($it['meta_data'])) {
                $meta = $it['meta_data'];
                if (!empty($meta['__canonical_key'])) {
                    $sourceRecordId = KnowledgeSlug::canonical((string) $meta['__canonical_key']);
                } elseif (!empty($meta['__source_record_id'])) {
                    $sourceRecordId = KnowledgeSlug::canonical((string) $meta['__source_record_id']);
                } elseif (!empty($meta['__ente_id'])) {
                    $sourceRecordId = KnowledgeSlug::canonical((string) $meta['__ente_id']);
                } else {
                    foreach (['Slug_Empresa', 'post_name', 'slug', 'source_record_id'] as $metaKey) {
                        if (!empty($meta[$metaKey])) {
                            $sourceRecordId = KnowledgeSlug::canonical((string) $meta[$metaKey]);
                            if ($sourceRecordId !== '') {
                                break;
                            }
                        }
                    }
                }
            }

            if ($enteId !== '' && $enteId !== 'global') {
                $sourceRecordId = $enteId;
            } elseif ($sourceRecordId !== '' && $sourceRecordId !== 'global' && !ctype_digit($sourceRecordId)) {
                $enteId = $sourceRecordId;
            }

            $contentHash = trim((string) ($it['content_hash'] ?? ''));
            if ($contentHash === '' && $chunk !== '') {
                $contentHash = md5($chunk);
            }
            if (strlen($contentHash) > 32) {
                $contentHash = substr($contentHash, 0, 32);
            }
            if ($sourceRecordId === '') {
                $sourceRecordId = $enteId !== '' && $enteId !== 'global'
                    ? $enteId
                    : 'hash:' . substr(hash('sha256', $chunk), 0, 59);
            }
            if ($enteId === '' || $enteId === 'global') {
                $enteId = $sourceRecordId !== '' ? $sourceRecordId : 'global';
            }
            if (strlen($sourceRecordId) > 100) {
                $sourceRecordId = substr($sourceRecordId, 0, 100);
            }

            $metaJson = null;
            if (isset($it['meta_data'])) {
                $enc = json_encode($it['meta_data'], JSON_UNESCAPED_UNICODE);
                $metaJson = is_string($enc) && $enc !== '' ? $enc : null;
            }

            $vecRaw = $it['vector_data'] ?? null;
            $vecStr = '';
            if (is_string($vecRaw)) {
                $vecStr = $vecRaw;
            } elseif (is_array($vecRaw)) {
                $enc = json_encode(array_values($vecRaw), JSON_UNESCAPED_UNICODE);
                $vecStr = is_string($enc) ? $enc : '';
            }

            $batch[] = [
                'ente_id'          => $enteId !== '' ? $enteId : 'global',
                'source_record_id' => $sourceRecordId,
                'content_chunk'    => $chunk,
                'content_hash'     => $contentHash !== '' ? $contentHash : null,
                'meta_json'        => $metaJson,
                'vector_json'      => $vecStr,
                'embedding_model'  => isset($it['embedding_model']) ? substr(trim((string) $it['embedding_model']), 0, 128) : 'unknown',
                'meta_only'        => !empty($it['meta_only']),
            ];
            unset($it, $meta, $enc, $vecRaw);
        }

        if ($batch === []) {
            Json::respond(400, [
                'error' => [
                    'message' => 'Ningún ítem válido en el lote: content_chunk y source_record_id son obligatorios.',
                    'type'    => 'invalid_knowledge',
                    'skipped' => $skipped,
                ],
            ]);

            return;
        }

        $pdo = Db::pdo();
        try {
            $pdo->beginTransaction();
            $deleted = 0;
            if ($replaceProject && !$incremental) {
                $deleted = KnowledgeVectorsRepository::deleteByLicenseAndProject($licenseId, $projectId);
            }

            $result = KnowledgeVectorsRepository::upsertBatch($pdo, $licenseId, $projectId, $batch);
            $pdo->commit();
            unset($batch);

            Json::respond(200, [
                'ok'           => true,
                'inserted'     => $result['inserted'],
                'updated'      => $result['updated'],
                'upserted'     => $result['upserted'],
                'skipped'      => $skipped + $result['skipped'],
                'pending'      => $result['pending'],
                'synchronized' => $result['synchronized'],
                'deleted'      => $deleted,
                'incremental'  => $incremental,
                'replace_run'  => $replaceProject && !$incremental,
                'project_id'   => $projectId,
                'license_id'   => $licenseId,
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Json::respond(500, [
                'error' => [
                    'message' => Env::str('XABIA_DEBUG') !== '' ? $e->getMessage() : 'Error al guardar vectores',
                    'type'    => 'xabia_hub',
                ],
            ]);
        }
    }
}
