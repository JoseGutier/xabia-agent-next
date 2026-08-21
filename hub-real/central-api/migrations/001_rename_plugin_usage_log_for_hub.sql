-- =============================================================================
-- Xabia: separar xabia_usage_log (Hub SaaS) de xabia_site_usage_log (plugin WP)
-- =============================================================================
-- Contexto: WordPress y central-api comparten MySQL (p. ej. xabia.ai). El plugin
-- usaba xabia_usage_log con columnas project_id/model_used/…; el hub exige el
-- esquema de schema.sql (license_id, activity_type, tokens_count, …).
--
-- ORDEN RECOMENDADO (no perder datos del sitio):
--   1) Ejecutar este script (o las sentencias aplicables) en la base compartida.
--   2) Copiar wp-mu-plugins/xabia-isolate-site-usage-log.php a
--      wp-content/mu-plugins/ en el WordPress de xabia.ai y recargar admin
--      una vez para que dbDelta alinee la tabla del sitio si hace falta.
--
-- Si xabia_site_usage_log YA existe, omite el RENAME y revisa a mano.
-- Si xabia_usage_log no existe, omite el RENAME y solo crea la tabla del hub.
--
-- Verificación previa (plugin): la tabla debe tener columna model_used.
--   SHOW COLUMNS FROM xabia_usage_log LIKE 'model_used';
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Mover la tabla del plugin fuera del nombre reservado al hub.
RENAME TABLE `xabia_usage_log` TO `xabia_site_usage_log`;

-- Tabla de uso/cobro del Hub (fuente de verdad para reportes /v1/usage y log tras proxy).
CREATE TABLE `xabia_usage_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `license_id` BIGINT UNSIGNED NOT NULL,
  `activity_type` VARCHAR(64) NOT NULL COMMENT 'chat, embedding_train, proxy_openai, usage_report, etc.',
  `tokens_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `source_url` VARCHAR(512) NULL DEFAULT NULL COMMENT 'X-Xabia-Source o site_url del reporte',
  `project_id` VARCHAR(191) NULL DEFAULT NULL,
  `meta_json` JSON NULL,
  PRIMARY KEY (`id`),
  KEY `idx_license_time` (`license_id`, `timestamp`),
  CONSTRAINT `fk_xabia_usage_license` FOREIGN KEY (`license_id`) REFERENCES `xabia_licenses` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
