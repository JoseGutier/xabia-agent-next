-- Hub: Delta Sync SHA-256 (Core ≥ 1.0.276). Amplía MD5 CHAR(32) a VARCHAR(64).
-- MariaDB / MySQL 5.7+: MODIFY COLUMN (sin IF EXISTS).

ALTER TABLE xabia_knowledge_vectors
    MODIFY COLUMN content_hash VARCHAR(64) NULL DEFAULT NULL
    COMMENT 'SHA-256 (64 hex) o MD5 legado (32) del content_chunk';

ALTER TABLE xabia_knowledge_store
    MODIFY COLUMN content_hash VARCHAR(64) NULL DEFAULT NULL
    COMMENT 'SHA-256 (64 hex) o MD5 legado (32) del content_chunk';
