-- Registro de seguridad / auditoría (intentos dominio no autorizado, etc.)

CREATE TABLE IF NOT EXISTS xabia_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    license_key_suffix VARCHAR(48) NULL DEFAULT NULL,
    claimed_domain VARCHAR(255) NULL DEFAULT NULL,
    http_origin_host VARCHAR(255) NULL DEFAULT NULL,
    http_referer_host VARCHAR(255) NULL DEFAULT NULL,
    unauthorized_domain VARCHAR(255) NULL DEFAULT NULL COMMENT 'Host desde el que se intentó usar la licencia',
    registered_domain VARCHAR(512) NULL DEFAULT NULL COMMENT 'Dominio autorizado (resumen)',
    meta_json JSON NULL,
    PRIMARY KEY (id),
    KEY idx_event_time (event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
