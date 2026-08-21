-- Caducidad independiente por addon (suscripción) frente a la licencia Core.
-- Ejecutar en la misma BD que usa el hub (XABIA_DB_DSN).

ALTER TABLE xabia_addon_activations
    ADD COLUMN expiry_date DATE NULL DEFAULT NULL
        COMMENT 'NULL = sin caducidad; si está en el pasado, el hub no incluye el addon en active_addons'
        AFTER status;
