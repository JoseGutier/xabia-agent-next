-- =============================================================================
-- astei.com (volcado u610697097_qHaBz): limpiar duplicados wp_xabia_* y alinear Hub
-- =============================================================================
-- Antes: mysqldump completo; ejecutar en la BD real de astei (WordPress + tablas Hub).
--
-- El volcado tenía:
--   - Tablas wp_xabia_* duplicadas frente a xabia_* (el Core usa nombres SIN prefijo wp_)
--   - Vectores RAG: más filas en wp_xabia_knowledge_vectors que en xabia_knowledge_vectors
--   - xabia_usage_log con columnas del plugin (project_id/model_used) a la vez que
--     xabia_site_usage_log (mu-plugin de aislamiento): hay que dejar xabia_usage_log
--     solo para el Hub (license_id / activity_type) como en 001_rename_plugin_usage_log_for_hub.sql
--   - xabia_licenses sin billing_email / expiry_date / updated_at (migraciones Hub)
--   - Sin tablas xabia_addon_activations / xabia_webhook_deliveries en el volcado
--   - license_key = lic_22c44… (no es clave Polar XABIA--…): sustituye por la real en WP
--
-- NO toca tablas wp_* estándar (posts, options, etc.).
-- Conflicto conocido: el plugin usa xabia_logs para chat; SecurityLog del Hub también
-- escribe en xabia_logs (auditoría). Este script NO renombra xabia_logs: evita romper el chat.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

SET @dbname := DATABASE();

-- ---------------------------------------------------------------------------
-- 1) Unificar R + logs + caché: copiar desde wp_* solo si la tabla existe (evita #1146)
-- ---------------------------------------------------------------------------

SET @has_wpkv := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'wp_xabia_knowledge_vectors'
);
SET @sql := IF(@has_wpkv > 0,
    'INSERT INTO `xabia_knowledge_vectors` (`id`, `project_id`, `content_chunk`, `meta_data`, `vector_data`, `source_file`, `ente_id`, `federation_node_id`, `created_at`) SELECT w.`id`, w.`project_id`, w.`content_chunk`, w.`meta_data`, w.`vector_data`, w.`source_file`, w.`ente_id`, w.`federation_node_id`, w.`created_at` FROM `wp_xabia_knowledge_vectors` w LEFT JOIN `xabia_knowledge_vectors` x ON x.`id` = w.`id` WHERE x.`id` IS NULL',
    'SELECT ''omitido: no existe wp_xabia_knowledge_vectors'' AS _merge_knowledge_vectors'
);
PREPARE _m1 FROM @sql;
EXECUTE _m1;
DEALLOCATE PREPARE _m1;

SET @has_wplg := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'wp_xabia_logs'
);
SET @sql := IF(@has_wplg > 0,
    'INSERT INTO `xabia_logs` (`id`, `project_id`, `ente_id`, `user_question`, `ai_response`, `timestamp`) SELECT w.`id`, w.`project_id`, w.`ente_id`, w.`user_question`, w.`ai_response`, w.`timestamp` FROM `wp_xabia_logs` w LEFT JOIN `xabia_logs` x ON x.`id` = w.`id` WHERE x.`id` IS NULL',
    'SELECT ''omitido: no existe wp_xabia_logs'' AS _merge_logs'
);
PREPARE _m2 FROM @sql;
EXECUTE _m2;
DEALLOCATE PREPARE _m2;

SET @has_wprc := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'wp_xabia_response_cache'
);
SET @sql := IF(@has_wprc > 0,
    'INSERT INTO `xabia_response_cache` (`id`, `project_id`, `query_hash`, `response`, `source_type`, `expiry`, `created_at`) SELECT w.`id`, w.`project_id`, w.`query_hash`, w.`response`, w.`source_type`, w.`expiry`, w.`created_at` FROM `wp_xabia_response_cache` w LEFT JOIN `xabia_response_cache` x ON x.`id` = w.`id` WHERE x.`id` IS NULL',
    'SELECT ''omitido: no existe wp_xabia_response_cache'' AS _merge_response_cache'
);
PREPARE _m3 FROM @sql;
EXECUTE _m3;
DEALLOCATE PREPARE _m3;

SET @has_wpus := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'wp_xabia_usage_logs'
);
SET @sql := IF(@has_wpus > 0,
    'INSERT INTO `xabia_site_usage_log` (`project_id`, `model_used`, `tokens_input`, `tokens_output`, `tokens_count`, `estimated_cost`, `sensitive_detected`, `query_fingerprint`, `created_at`) SELECT w.`project_id`, w.`model_used`, w.`tokens_input`, w.`tokens_output`, (w.`tokens_input` + w.`tokens_output`), w.`estimated_cost`, w.`sensitive_detected`, w.`query_fingerprint`, w.`created_at` FROM `wp_xabia_usage_logs` w WHERE NOT EXISTS (SELECT 1 FROM `xabia_site_usage_log` s WHERE s.`query_fingerprint` COLLATE utf8mb4_unicode_ci = w.`query_fingerprint` COLLATE utf8mb4_unicode_ci AND s.`created_at` = w.`created_at`)',
    'SELECT ''omitido: no existe wp_xabia_usage_logs'' AS _merge_site_usage'
);
PREPARE _m4 FROM @sql;
EXECUTE _m4;
DEALLOCATE PREPARE _m4;

-- ---------------------------------------------------------------------------
-- 2) Quitar duplicados wp_xabia_* (datos ya en xabia_*)
-- ---------------------------------------------------------------------------

DROP TABLE IF EXISTS `wp_xabia_knowledge_vectors`;
DROP TABLE IF EXISTS `wp_xabia_logs`;
DROP TABLE IF EXISTS `wp_xabia_response_cache`;
DROP TABLE IF EXISTS `wp_xabia_usage_logs`;
DROP TABLE IF EXISTS `wp_xabia_discovery_blocks`;
DROP TABLE IF EXISTS `wp_xabia_embeddings`;

-- ---------------------------------------------------------------------------
-- 3) Renombrar tablas de addon que solo existían con prefijo wp_
-- ---------------------------------------------------------------------------

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'xabia_federation_nodes') = 0
    AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'wp_xabia_federation_nodes') > 0,
    'RENAME TABLE `wp_xabia_federation_nodes` TO `xabia_federation_nodes`',
    'SELECT ''skip: xabia_federation_nodes ya existe o no hay wp_xabia_federation_nodes'' AS _x'
);
PREPARE _r1 FROM @sql;
EXECUTE _r1;
DEALLOCATE PREPARE _r1;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'xabia_amelia_bookings') = 0
    AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'wp_xabia_amelia_bookings') > 0,
    'RENAME TABLE `wp_xabia_amelia_bookings` TO `xabia_amelia_bookings`',
    'SELECT ''skip: xabia_amelia_bookings'' AS _x'
);
PREPARE _r2 FROM @sql;
EXECUTE _r2;
DEALLOCATE PREPARE _r2;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'xabia_conversions') = 0
    AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'wp_xabia_conversions') > 0,
    'RENAME TABLE `wp_xabia_conversions` TO `xabia_conversions`',
    'SELECT ''skip: xabia_conversions'' AS _x'
);
PREPARE _r3 FROM @sql;
EXECUTE _r3;
DEALLOCATE PREPARE _r3;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'xabia_qr_poi') = 0
    AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'wp_xabia_qr_poi') > 0,
    'RENAME TABLE `wp_xabia_qr_poi` TO `xabia_qr_poi`',
    'SELECT ''skip: xabia_qr_poi'' AS _x'
);
PREPARE _r4 FROM @sql;
EXECUTE _r4;
DEALLOCATE PREPARE _r4;

-- ---------------------------------------------------------------------------
-- 4) xabia_usage_log = Hub (solo si aún tiene esquema del plugin)
-- ---------------------------------------------------------------------------

SET @plugin_usage := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'xabia_usage_log' AND COLUMN_NAME = 'model_used'
);

SET @sql := IF(@plugin_usage > 0,
    'INSERT INTO `xabia_site_usage_log`
        (`project_id`, `model_used`, `tokens_input`, `tokens_output`, `tokens_count`, `estimated_cost`, `sensitive_detected`, `query_fingerprint`, `created_at`)
     SELECT
        u.`project_id`, u.`model_used`, u.`tokens_input`, u.`tokens_output`, u.`tokens_count`, u.`estimated_cost`, u.`sensitive_detected`, u.`query_fingerprint`, u.`created_at`
     FROM `xabia_usage_log` u
     WHERE NOT EXISTS (
         SELECT 1 FROM `xabia_site_usage_log` s
         WHERE s.`query_fingerprint` COLLATE utf8mb4_unicode_ci = u.`query_fingerprint` COLLATE utf8mb4_unicode_ci
           AND s.`created_at` = u.`created_at`
     )',
    'SELECT ''skip merge: xabia_usage_log ya es esquema Hub'' AS _x'
);
PREPARE _mu FROM @sql;
EXECUTE _mu;
DEALLOCATE PREPARE _mu;

SET @sql := IF(@plugin_usage > 0,
    'DROP TABLE IF EXISTS `xabia_usage_log`',
    'SELECT ''skip drop xabia_usage_log'' AS _x'
);
PREPARE _du FROM @sql;
EXECUTE _du;
DEALLOCATE PREPARE _du;

-- Recrear tabla Hub solo si no existe (1ª ejecución o tras DROP)
SET @hub_usage_exists := (
    SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'xabia_usage_log'
);
SET @sql := IF(@hub_usage_exists = 0,
    'CREATE TABLE `xabia_usage_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `license_id` BIGINT UNSIGNED NOT NULL,
    `activity_type` VARCHAR(64) NOT NULL COMMENT ''chat, embedding_train, proxy_openai, usage_report, etc.'',
    `tokens_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `source_url` VARCHAR(512) NULL DEFAULT NULL,
    `project_id` VARCHAR(191) NULL DEFAULT NULL,
    `meta_json` JSON NULL,
    PRIMARY KEY (`id`),
    KEY `idx_license_time` (`license_id`, `timestamp`),
    CONSTRAINT `fk_xabia_usage_license` FOREIGN KEY (`license_id`) REFERENCES `xabia_licenses` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT ''xabia_usage_log Hub ya existe'' AS _x'
);
PREPARE _cu FROM @sql;
EXECUTE _cu;
DEALLOCATE PREPARE _cu;

-- ---------------------------------------------------------------------------
-- 5) Licencias Hub: columnas nuevas (si faltan)
-- ---------------------------------------------------------------------------

SET @c1 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'xabia_licenses' AND COLUMN_NAME = 'billing_email');
SET @sql := IF(@c1 = 0,
    'ALTER TABLE `xabia_licenses` ADD COLUMN `billing_email` VARCHAR(255) NULL DEFAULT NULL AFTER `client_domain`, ADD KEY `idx_billing_email` (`billing_email`(191))',
    'SELECT ''xabia_licenses.billing_email ya existe'' AS _x'
);
PREPARE _a1 FROM @sql;
EXECUTE _a1;
DEALLOCATE PREPARE _a1;

SET @c2 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'xabia_licenses' AND COLUMN_NAME = 'expiry_date');
SET @sql := IF(@c2 = 0,
    'ALTER TABLE `xabia_licenses` ADD COLUMN `expiry_date` DATETIME NULL DEFAULT NULL AFTER `plan_type`',
    'SELECT ''xabia_licenses.expiry_date ya existe'' AS _x'
);
PREPARE _a2 FROM @sql;
EXECUTE _a2;
DEALLOCATE PREPARE _a2;

SET @c3 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'xabia_licenses' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@c3 = 0,
    'ALTER TABLE `xabia_licenses` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`',
    'SELECT ''xabia_licenses.updated_at ya existe'' AS _x'
);
PREPARE _a3 FROM @sql;
EXECUTE _a3;
DEALLOCATE PREPARE _a3;

-- Sustituye por la clave Polar que muestra WordPress (XABIA--…). El volcado traía lic_22c44…
-- UPDATE `xabia_licenses`
-- SET `license_key` = 'XABIA--TU-UUID-AQUI'
-- WHERE `client_domain` COLLATE utf8mb4_unicode_ci = 'astei.com' COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6) Tablas Hub que faltaban en el volcado
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `xabia_addon_activations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `license_key` VARCHAR(255) NOT NULL,
    `addon_slug` VARCHAR(80) NOT NULL,
    `product_id` VARCHAR(191) NOT NULL DEFAULT '',
    `client_url` VARCHAR(255) NOT NULL DEFAULT '',
    `status` ENUM('active', 'inactive', 'expired') NOT NULL DEFAULT 'active',
    `expiry_date` DATE NULL DEFAULT NULL,
    `source` VARCHAR(80) NOT NULL DEFAULT 'polar',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_license_addon` (`license_key`(120), `addon_slug`),
    KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xabia_webhook_deliveries` (
    `webhook_id` VARCHAR(128) NOT NULL,
    `provider` VARCHAR(32) NOT NULL DEFAULT 'polar',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`webhook_id`),
    KEY `idx_provider_time` (`provider`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Avirato: misma lógica que 009 (COLLATE para mezclas utf8mb4_unicode_ci / _520_ci)
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
  AND l.`license_key` COLLATE utf8mb4_unicode_ci LIKE CONCAT('XABIA--', '%') COLLATE utf8mb4_unicode_ci
  AND NOT EXISTS (
      SELECT 1 FROM `xabia_addon_activations` a
      WHERE a.`license_key` COLLATE utf8mb4_unicode_ci = l.`license_key` COLLATE utf8mb4_unicode_ci
        AND a.`addon_slug` COLLATE utf8mb4_unicode_ci = 'xabia-avirato' COLLATE utf8mb4_unicode_ci
  )
LIMIT 1;

-- ---------------------------------------------------------------------------
-- 7) AUTO_INCREMENT sensato en tablas tocadas
-- ---------------------------------------------------------------------------

SET @kvmax := (SELECT IFNULL(MAX(`id`), 0) + 1 FROM `xabia_knowledge_vectors`);
SET @sql := CONCAT('ALTER TABLE `xabia_knowledge_vectors` AUTO_INCREMENT = ', @kvmax);
PREPARE _ai FROM @sql;
EXECUTE _ai;
DEALLOCATE PREPARE _ai;

SET @logmax := (SELECT IFNULL(MAX(`id`), 0) + 1 FROM `xabia_logs`);
SET @sql := CONCAT('ALTER TABLE `xabia_logs` AUTO_INCREMENT = ', @logmax);
PREPARE _ai2 FROM @sql;
EXECUTE _ai2;
DEALLOCATE PREPARE _ai2;

SET @rcmax := (SELECT IFNULL(MAX(`id`), 0) + 1 FROM `xabia_response_cache`);
SET @sql := CONCAT('ALTER TABLE `xabia_response_cache` AUTO_INCREMENT = ', @rcmax);
PREPARE _ai3 FROM @sql;
EXECUTE _ai3;
DEALLOCATE PREPARE _ai3;

SET @sumax := (SELECT IFNULL(MAX(`id`), 0) + 1 FROM `xabia_site_usage_log`);
SET @sql := CONCAT('ALTER TABLE `xabia_site_usage_log` AUTO_INCREMENT = ', @sumax);
PREPARE _ai4 FROM @sql;
EXECUTE _ai4;
DEALLOCATE PREPARE _ai4;

SET FOREIGN_KEY_CHECKS = 1;

-- Post-ejecución:
-- 1) Actualiza xabia_licenses.license_key a la clave Polar real y vuelve a ejecutar el INSERT de addon o sincroniza desde WP.
-- 2) Asegura mu-plugins: includes/mu-plugins/xabia-hub-table-isolation.php activo.
-- 3) Si SecurityLog del Hub debe escribir sin chocar con el chat, valorar tabla dedicada en código (conflicto xabia_logs).
