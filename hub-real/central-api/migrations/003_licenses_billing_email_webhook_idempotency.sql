-- Polar / facturación: localizar licencia por email del comprador y deduplicar webhooks.
-- Ejecutar en la BD del hub tras desplegar el código que usa estas tablas/columnas.

ALTER TABLE xabia_licenses
    ADD COLUMN billing_email VARCHAR(255) NULL DEFAULT NULL COMMENT 'Email Polar u otro PSP (búsqueda al fulfillment)'
        AFTER client_domain,
    ADD KEY idx_billing_email (billing_email(191));

CREATE TABLE IF NOT EXISTS xabia_webhook_deliveries (
    webhook_id VARCHAR(128) NOT NULL,
    provider VARCHAR(32) NOT NULL DEFAULT 'polar',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (webhook_id),
    KEY idx_provider_time (provider, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
