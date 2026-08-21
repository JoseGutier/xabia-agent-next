-- Hub v1.0.61+: UPSERT incremental por source_record_id (sin borrado masivo por project_id).

ALTER TABLE xabia_knowledge_vectors
    ADD COLUMN IF NOT EXISTS source_record_id VARCHAR(100) NULL DEFAULT NULL
        COMMENT 'ID estable del registro en el sitio WP (post ID, SKU key, etc.)'
        AFTER project_id,
    ADD COLUMN IF NOT EXISTS content_hash CHAR(32) NULL DEFAULT NULL
        COMMENT 'MD5 del content_chunk en origen; evita re-escritura si no cambió'
        AFTER content_chunk;

-- MySQL < 8.0.12 no tiene IF NOT EXISTS en ADD COLUMN: ejecutar manualmente si falla.

ALTER TABLE xabia_knowledge_vectors
    ADD KEY idx_kv_license_project_source (license_id, project_id, source_record_id);

-- Opcional: sitios que aceptan disparo de cron remoto desde el Hub
ALTER TABLE xabia_wallets
    ADD COLUMN IF NOT EXISTS cloud_cron_enabled TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1 = el Reloj Maestro del Hub puede llamar a cloud-cron-trigger'
        AFTER last_seen_at;
