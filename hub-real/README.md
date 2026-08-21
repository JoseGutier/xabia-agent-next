# Hub real (Central API)

Espejo versionado del Hub de producción (`~/central-api` en Hostinger), **sin secretos**.

## Estructura

- `central-api/src/` — Router, DTP, Knowledge*, Workers, Polar, Vertex, etc.
- `central-api/public/` — front controller HTTP
- `central-api/bin/`, `scripts/`, `migrations/`, `wp-mu-plugins/`
- `central-api/env.example` — plantilla de variables (copiar a `.env` en el servidor)

## Secretos (nunca en Git)

- `.env`
- `config/google-key.json`

Usa `env.example` y `config/README.md`.

## Relación con el deploy

`xabia-deploy.sh` sube desde `xabia-agent-plugins/central-api/src/` (espejo de `hub-real/central-api/src/`). Tras cambios en el Hub, sincroniza ambas rutas o despliega desde aquí.

## Smart QR

No es un producto Hub aparte. Smart QR vive **dentro de Xabia Agent Core** (`core/class-xabia-smart-qr.php`, `addons/xabia-qr/`).
