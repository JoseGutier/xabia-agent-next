-- =============================================================================
-- Limpieza / alineación BD compartida (Hub + sitio) — desbloqueo Avirato Astei
-- =============================================================================
-- Antes: mysqldump completo de la BD y prueba en staging.
--
-- Contexto (volcado u610697097_hD89x):
--   - xabia_licenses tiene license_key = XABIA--A113ACDA-544E-4419-B900-18183CD1349D, astei.com
--   - xabia_addon_activations tenía license_key erróneo xabia_astei_official_2024
--   - xabia_addon_activations sin columna expiry_date (falla o ignora según versión MySQL)
--   - xabia_logs con esquema antiguo (chat), no el de auditoría del Hub (006)
--   - Tablas xabia_discovery_blocks, xabia_embeddings, xabia_federation_nodes (sin wp_)
--     no las usa central-api/src; suelen ser restos / duplicado del esquema “sitio”.
--
-- NO ejecutes los DROP de wp_*: son tablas del plugin en WordPress si compartes BD.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- 1) OBLIGATORIO: xabia_addon_activations alineada con license_validate
-- ---------------------------------------------------------------------------

-- Columna que usa LicenseRepository::activeAddonActivationsWithExpiryForLicense
SET @dbname = DATABASE();
SET @col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'xabia_addon_activations' AND COLUMN_NAME = 'expiry_date'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE `xabia_addon_activations` ADD COLUMN `expiry_date` DATE NULL DEFAULT NULL AFTER `status`',
    'SELECT ''xabia_addon_activations.expiry_date ya existe'' AS _skip'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- status como ENUM del Hub (opcional pero recomendado)
ALTER TABLE `xabia_addon_activations`
    MODIFY COLUMN `status` ENUM('active','inactive','expired') NOT NULL DEFAULT 'active';

-- Quitar la fila de prueba que no coincide con Polar (volcado antiguo)
DELETE FROM `xabia_addon_activations`
WHERE `license_key` COLLATE utf8mb4_unicode_ci = 'xabia_astei_official_2024' COLLATE utf8mb4_unicode_ci;

-- Activación Avirato para la licencia real de astei.com (solo si aún no existe)
INSERT INTO `xabia_addon_activations`
    (`license_key`, `addon_slug`, `product_id`, `client_url`, `status`, `expiry_date`, `source`)
SELECT
    l.`license_key`,
    'xabia-avirato',
    '',
    CONCAT('https://', l.`client_domain`),
    'active',
    DATE(COALESCE(l.`expiry_date`, '2027-05-01')),
    'manual_repair'
FROM `xabia_licenses` l
WHERE l.`client_domain` COLLATE utf8mb4_unicode_ci = 'astei.com' COLLATE utf8mb4_unicode_ci
  AND l.`license_key` LIKE 'XABIA--%' COLLATE utf8mb4_unicode_ci
  AND NOT EXISTS (
      SELECT 1 FROM `xabia_addon_activations` a
      WHERE a.`license_key` COLLATE utf8mb4_unicode_ci = l.`license_key` COLLATE utf8mb4_unicode_ci
        AND a.`addon_slug` COLLATE utf8mb4_unicode_ci = 'xabia-avirato' COLLATE utf8mb4_unicode_ci
  )
LIMIT 1;

-- Por si quedaran duplicados (mismo par license_key + addon_slug)
DELETE t1 FROM `xabia_addon_activations` t1
INNER JOIN `xabia_addon_activations` t2
  ON t1.`license_key` COLLATE utf8mb4_unicode_ci = t2.`license_key` COLLATE utf8mb4_unicode_ci
 AND t1.`addon_slug` COLLATE utf8mb4_unicode_ci = t2.`addon_slug` COLLATE utf8mb4_unicode_ci
 AND t1.`id` > t2.`id`;

-- ---------------------------------------------------------------------------
-- 2) Auditoría Hub: xabia_logs (schema 006) si aún tienes el esquema “chat”
-- ---------------------------------------------------------------------------

SET @logs_chat := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'xabia_logs' AND COLUMN_NAME = 'user_question'
);

SET @sql2 := IF(@logs_chat > 0,
    'RENAME TABLE `xabia_logs` TO `xabia_logs_legacy_chat`',
    'SELECT ''xabia_logs ya es esquema nuevo; no renombrar'' AS _skip'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

CREATE TABLE IF NOT EXISTS `xabia_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_type` VARCHAR(64) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `license_key_suffix` VARCHAR(48) NULL DEFAULT NULL,
    `claimed_domain` VARCHAR(255) NULL DEFAULT NULL,
    `http_origin_host` VARCHAR(255) NULL DEFAULT NULL,
    `http_referer_host` VARCHAR(255) NULL DEFAULT NULL,
    `unauthorized_domain` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Host desde el que se intentó usar la licencia',
    `registered_domain` VARCHAR(512) NULL DEFAULT NULL COMMENT 'Host autorizado (resumen)',
    `meta_json` JSON NULL,
    PRIMARY KEY (`id`),
    KEY `idx_event_time` (`event_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3) Webhook Polar (idempotencia)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `xabia_webhook_deliveries` (
    `webhook_id` VARCHAR(128) NOT NULL,
    `provider` VARCHAR(32) NOT NULL DEFAULT 'polar',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`webhook_id`),
    KEY `idx_provider_time` (`provider`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- 4) OPCIONAL: tablas “hub” que no usa central-api (revisa COUNT antes)
-- ---------------------------------------------------------------------------
-- Ejecuta en manual:
--   SELECT COUNT(*) FROM xabia_discovery_blocks;
--   SELECT COUNT(*) FROM xabia_embeddings;
--   SELECT COUNT(*) FROM xabia_federation_nodes;
-- Si todas son 0 o datos de prueba:
/*
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `xabia_discovery_blocks`;
DROP TABLE IF EXISTS `xabia_embeddings`;
DROP TABLE IF EXISTS `xabia_federation_nodes`;
SET FOREIGN_KEY_CHECKS = 1;
*/

-- Catálogo legacy (sustituido por xabia_addon_activations + Polar); ok eliminar si no tienes scripts propios
/*
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `xabia_license_addons`;
DROP TABLE IF EXISTS `xabia_addons`;
SET FOREIGN_KEY_CHECKS = 1;
*/

-- NO eliminar: xabia_knowledge_vectors (RAG central), xabia_usage_log, xabia_wallets, xabia_licenses,
-- xabia_recharge_history / xabia_response_cache si el Core WP las usa vía prefijo en otro entorno.
-- NO eliminar: ninguna tabla wp_* de este dump.
