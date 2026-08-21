-- Variante compatible MariaDB / MySQL sin ADD COLUMN IF NOT EXISTS (ejecutar una sola vez).

ALTER TABLE xabia_knowledge_vectors
    ADD COLUMN source_record_id VARCHAR(100) NULL DEFAULT NULL
        COMMENT 'ID estable del registro en el sitio WP'
        AFTER project_id;

ALTER TABLE xabia_knowledge_vectors
    ADD COLUMN content_hash CHAR(32) NULL DEFAULT NULL
        COMMENT 'MD5 content_chunk origen'
        AFTER content_chunk;

ALTER TABLE xabia_knowledge_vectors
    ADD KEY idx_kv_license_project_source (license_id, project_id, source_record_id);

ALTER TABLE xabia_wallets
    ADD COLUMN cloud_cron_enabled TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Reloj Maestro Hub → cloud-cron-trigger'
        AFTER last_seen_at;
