-- Quitar columna de mapeo si existe (instalaciones que llegaron a aplicar polar_display_key).
-- Si la columna no existe, este ALTER fallará: ejecute manualmente solo en esas BDs o ignore el error.

ALTER TABLE xabia_licenses DROP COLUMN polar_display_key;
