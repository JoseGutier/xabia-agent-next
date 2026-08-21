# Xabia Central Hub (central-api)

Código PHP: `bootstrap.php`, `public/index.php`, `src/`, `composer.json`.

| Ruta | Uso |
|------|-----|
| `schema.sql` | Esquema Hub (`xabia_licenses`, `xabia_wallets`, `xabia_usage_log`, …). |
| `deploy/api/.htaccess` | Copiar junto a `public/` para URLs limpias bajo `/api/`. |
| `migrations/` | SQL operativo (p. ej. `xabia_site_usage_log` vs hub). |
| `wp-mu-plugins/` | MU-plugin WordPress cuando WP y el hub comparten MySQL. |
| `HUB_DATABASE_ISOLATION.md` | Runbook BD compartida. |

## Validación de licencia (rutas)

- **Canónica:** `POST` o `GET` **`/xabia/v1/license/validate`** (cuerpo o query: `license_key`, `domain` o `site_url`).
- **Alias (mismo handler):** `/license/check`, `/license/validate`, `/xabia/v1/license/check`.
- URL pública ejemplo: `https://xabia.ai/api/xabia/v1/license/validate` — el segmento **`/api`** es el montaje del hosting; el contrato interno del `Router` empieza en **`/xabia/v1/...`**.

No existe ruta documentada **`/license/check`** sin el prefijo de montaje: si llamas `https://xabia.ai/api/license/check`, tras el rewrite debe resolverse a path interno `/license/check` (alias soportado).
