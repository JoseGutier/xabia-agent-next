-- Vectores de conocimiento por licencia + proyecto (RAG centralizado en el Hub)

CREATE TABLE IF NOT EXISTS xabia_knowledge_vectors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    license_id BIGINT UNSIGNED NOT NULL COMMENT 'ID facturación en xabia_licenses (MIN por clave)',
    project_id VARCHAR(191) NOT NULL,
    ente_id VARCHAR(100) NOT NULL DEFAULT 'global',
    content_chunk MEDIUMTEXT NOT NULL,
    meta_json JSON NULL,
    vector_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_kv_license_project (license_id, project_id),
    KEY idx_kv_project_ente (project_id, ente_id),
    CONSTRAINT fk_xabia_kv_license
        FOREIGN KEY (license_id) REFERENCES xabia_licenses (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
