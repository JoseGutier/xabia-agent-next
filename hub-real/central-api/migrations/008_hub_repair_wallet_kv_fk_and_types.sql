-- Reparación Hub: conflicto FK en xabia_wallets + alineación license_id con xabia_licenses.id
-- Ejecutar en la misma base que usa el Hub (XABIA_DB_DSN). Hacer backup antes.
-- Requiere tablas existentes; si xabia_knowledge_vectors no existe aún, aplicar antes 007_xabia_knowledge_vectors.sql
-- o comentar el bloque 5. Si el nombre del FK difiere: SHOW CREATE TABLE xabia_wallets;
--
-- IMPORTANTE: xabia_wallets.license_id debe ser el MISMO tipo que xabia_licenses.id (FK).
-- En schema.sql canónico ambos son BIGINT UNSIGNED. No conviertas license_id a VARCHAR(191):
-- varchar(191) aplica típicamente a project_id / claves de texto, no al id numérico de licencia.
-- Si tu error venía de una herramienta que intentaba VARCHAR en esta columna, corrige la herramienta
-- y deja license_id como entero sin signo alineado con licenses.id.

SET NAMES utf8mb4;

-- 1) Comprobar tipo de id en licencias (ejecuta manualmente si dudas):
-- SELECT COLUMN_TYPE FROM information_schema.COLUMNS
--   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xabia_licenses' AND COLUMN_NAME = 'id';

SET FOREIGN_KEY_CHECKS = 0;

-- 2) xabia_wallets: quitar FK que bloquea ALTER TABLE
ALTER TABLE xabia_wallets
    DROP FOREIGN KEY fk_xabia_wallet_license;

-- 3) Alinear columna con xabia_licenses.id (ajusta a INT UNSIGNED solo si licenses.id es INT y no BIGINT)
ALTER TABLE xabia_wallets
    MODIFY COLUMN license_id BIGINT UNSIGNED NOT NULL;

-- 4) Recrear FK
ALTER TABLE xabia_wallets
    ADD CONSTRAINT fk_xabia_wallet_license
        FOREIGN KEY (license_id) REFERENCES xabia_licenses (id)
        ON DELETE CASCADE ON UPDATE CASCADE;

-- 5) xabia_knowledge_vectors: mismo tipo que licenses.id (evita 500 en sync por mismatch FK)
ALTER TABLE xabia_knowledge_vectors
    DROP FOREIGN KEY fk_xabia_kv_license;

ALTER TABLE xabia_knowledge_vectors
    MODIFY COLUMN license_id BIGINT UNSIGNED NOT NULL COMMENT 'ID facturación en xabia_licenses (MIN por clave)';

ALTER TABLE xabia_knowledge_vectors
    ADD CONSTRAINT fk_xabia_kv_license
        FOREIGN KEY (license_id) REFERENCES xabia_licenses (id)
        ON DELETE CASCADE ON UPDATE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;
