<?php

declare(strict_types=1);

namespace XabiaCentral\Workers;

use PDO;
use Throwable;
use XabiaCentral\Env;
use XabiaCentral\OpenAiForwarder;
use XabiaCentral\VertexForwarder;

final class VectorizationWorker
{
    /**
     * @var callable(string): array<int, float>|null
     */
    private $embeddingService;

    /**
     * @param callable(string): array<int, float>|null $embeddingService
     */
    public function __construct(
        private PDO $pdo,
        ?callable $embeddingService = null
    ) {
        $this->embeddingService = $embeddingService;
    }

    /**
     * @return array{processed:int,synchronized:int,errors:int,skipped:int,error_details:list<array{id:int,message:string}>}
     */
    public function process_pending_batch(int $batch_size = 50): array
    {
        $batch_size = max(1, min(250, $batch_size));
        $records = $this->fetchPending($batch_size);

        $processed = 0;
        $synchronized = 0;
        $errors = 0;
        $skipped = 0;
        $errorDetails = [];

        foreach ($records as $record) {
            $id = (int) ($record['id'] ?? 0);
            if ($id < 1) {
                ++$skipped;
                continue;
            }
            ++$processed;

            try {
                $embedding = $this->embeddingForRecord($record);
                if ($embedding === []) {
                    throw new \RuntimeException('Embedding vacío.');
                }

                $this->pdo->beginTransaction();
                $this->upsertVector($record, $embedding);
                $this->markSynchronized($id);
                $this->pdo->commit();
                ++$synchronized;
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $message = mb_substr($e->getMessage(), 0, 1000);
                $this->markError($id, $message);
                ++$errors;
                $errorDetails[] = [
                    'id'      => $id,
                    'message' => $message,
                ];
            }
        }

        return [
            'processed'     => $processed,
            'synchronized'  => $synchronized,
            'errors'        => $errors,
            'skipped'       => $skipped,
            'error_details' => $errorDetails,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPending(int $limit): array
    {
        $sql = 'SELECT id, license_id, project_id, source_record_id, ente_id, content_chunk, content_hash, meta_json
                FROM xabia_knowledge_store
                WHERE vectorization_status = \'PENDING\'
                ORDER BY id ASC
                LIMIT ' . $limit;
        $stmt = $this->pdo->query($sql);
        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<int, float>
     */
    private function embeddingForRecord(array $record): array
    {
        $content = $this->embeddingText($record);
        if ($this->embeddingService !== null) {
            $embedding = ($this->embeddingService)($content);

            return $this->normalizeEmbedding($embedding);
        }

        return $this->defaultEmbeddingService($content);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function embeddingText(array $record): string
    {
        $meta = [];
        if (!empty($record['meta_json']) && is_string($record['meta_json'])) {
            $decoded = json_decode($record['meta_json'], true);
            $meta = is_array($decoded) ? $decoded : [];
        }

        $parts = [
            'Proyecto: ' . (string) ($record['project_id'] ?? ''),
            'Ente: ' . (string) ($record['ente_id'] ?? 'global'),
            'Contenido: ' . (string) ($record['content_chunk'] ?? ''),
        ];
        foreach (['titulo', 'title', 'nombre', 'empresa', 'localidad', 'categorias', 'categories'] as $key) {
            if (!empty($meta[$key]) && is_scalar($meta[$key])) {
                $parts[] = $key . ': ' . (string) $meta[$key];
            }
        }

        return $this->limitText(implode("\n\n", array_filter($parts)), 12000);
    }

    /**
     * @return array<int, float>
     */
    private function defaultEmbeddingService(string $text): array
    {
        $payload = [
            'model' => Env::str('XABIA_EMBEDDING_MODEL', 'text-embedding-004'),
            'input' => $text,
        ];

        $googlePath = Env::str('GOOGLE_APPLICATION_CREDENTIALS');
        $googleJson = Env::str('GOOGLE_APPLICATION_CREDENTIALS_JSON');
        if ($googlePath !== '' || $googleJson !== '') {
            $response = VertexForwarder::forwardOpenAiCompatible($payload, 'flash');
        } else {
            $response = OpenAiForwarder::forward('/v1/embeddings', $payload);
        }

        $code = (int) ($response['http_code'] ?? 0);
        $decoded = $response['decoded'] ?? null;
        if ($code < 200 || $code >= 300 || !is_array($decoded)) {
            $message = 'Embedding upstream error HTTP ' . (string) $code;
            if (is_array($decoded) && isset($decoded['error']['message'])) {
                $message .= ': ' . (string) $decoded['error']['message'];
            }
            throw new \RuntimeException($message);
        }

        $embedding = $decoded['data'][0]['embedding'] ?? null;

        return $this->normalizeEmbedding($embedding);
    }

    /**
     * @param mixed $embedding
     * @return array<int, float>
     */
    private function normalizeEmbedding($embedding): array
    {
        if (!is_array($embedding)) {
            return [];
        }

        $out = [];
        foreach ($embedding as $value) {
            if (!is_numeric($value)) {
                throw new \RuntimeException('Embedding contiene valores no numéricos.');
            }
            $out[] = (float) $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<int, float> $embedding
     */
    private function upsertVector(array $record, array $embedding): void
    {
        $knowledgeStoreId = (int) $record['id'];
        $licenseId = (int) $record['license_id'];
        $projectId = substr((string) $record['project_id'], 0, 191);
        $identity = KnowledgeSlug::resolveIdentity([
            'ente_id'          => (string) ($record['ente_id'] ?? 'global'),
            'source_record_id' => (string) ($record['source_record_id'] ?? ''),
            'meta_json'        => $record['meta_json'] ?? null,
        ]);
        $enteId = $identity['ente_id'];
        $sourceId = $identity['source_record_id'];
        if ($enteId === '' || $enteId === 'global') {
            throw new \RuntimeException('Registro sin slug de empresa canónico.');
        }
        $sourceId = $enteId;
        $chunk = (string) $record['content_chunk'];
        $hash = $this->normalizeContentHash((string) $record['content_hash']);
        $metaJson = $record['meta_json'] ?? null;
        $model = Env::str('XABIA_EMBEDDING_MODEL', 'text-embedding-004');
        $vectorJson = json_encode(array_values($embedding), JSON_UNESCAPED_UNICODE);
        if (!is_string($vectorJson) || $vectorJson === '') {
            throw new \RuntimeException('No se pudo serializar el embedding.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO xabia_knowledge_vectors
                (knowledge_store_id, license_id, project_id, source_record_id, ente_id, content_chunk, content_hash, embedding_model, meta_json, vector_json, vectorization_status, vectorization_error, vectorized_at)
             VALUES
                (:ksid, :lid, :pid, :sid, :eid, :chunk, :hash, :model, :meta, :vec, \'SYNCHRONIZED\', NULL, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                knowledge_store_id = VALUES(knowledge_store_id),
                ente_id = VALUES(ente_id),
                content_chunk = VALUES(content_chunk),
                content_hash = VALUES(content_hash),
                embedding_model = VALUES(embedding_model),
                meta_json = VALUES(meta_json),
                vector_json = VALUES(vector_json),
                vectorization_status = \'SYNCHRONIZED\',
                vectorization_error = NULL,
                vectorized_at = UTC_TIMESTAMP(),
                updated_at = UTC_TIMESTAMP()'
        );
        $stmt->execute([
            ':ksid'  => $knowledgeStoreId,
            ':lid'   => $licenseId,
            ':pid'   => $projectId,
            ':sid'   => $sourceId,
            ':eid'   => $enteId !== '' ? $enteId : 'global',
            ':chunk' => $chunk,
            ':hash'  => $hash,
            ':model' => $model,
            ':meta'  => $metaJson,
            ':vec'   => $vectorJson,
        ]);
    }

    private function markSynchronized(int $knowledgeStoreId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE xabia_knowledge_store
             SET vectorization_status = \'SYNCHRONIZED\',
                 vectorization_error = NULL,
                 vectorized_at = UTC_TIMESTAMP(),
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $stmt->execute([':id' => $knowledgeStoreId]);
    }

    private function markError(int $knowledgeStoreId, string $message): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE xabia_knowledge_store
             SET vectorization_status = \'ERROR\',
                 vectorization_error = :message,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $knowledgeStoreId,
            ':message' => $message,
        ]);
    }

    private function limitText(string $text, int $limit): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') : $text;
        }

        return strlen($text) > $limit ? substr($text, 0, $limit) : $text;
    }

    /**
     * SHA-256 (64 hex) o MD5 legado (32).
     */
    private function normalizeContentHash(string $hash): ?string
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
