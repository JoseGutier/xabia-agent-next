-- Multi-dominio por clave Polar: una fila por (license_key, client_domain).
-- schema.sql ya define uk_license_key_domain; en prod quedó un UNIQUE residual
-- solo sobre license_key que bloqueaba ensureLicenseDomainRow (INSERT 1062).
-- Idempotente: si el índice no existe, ignorar el error al aplicar a mano.

ALTER TABLE `xabia_licenses` DROP INDEX `license_key`;
