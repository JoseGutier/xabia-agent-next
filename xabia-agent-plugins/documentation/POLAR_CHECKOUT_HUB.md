# Polar checkout → Hub Xabia (licencias y addons)

Documento operativo para que **Core, packs de tokens y todos los addons de pago** (MEC, Woo, Avirato) se activen en el Hub de forma fiable tras la compra en Polar.

## Regla única (todos los productos)

El webhook Polar (`PolarWebhookHandler`) usa **la misma resolución de licencia** para Core, tokens y addons. Tras el parche **ago 2026**, el orden es:

1. `license_key` en metadata del checkout (si ya existe en el Hub).
2. Clave Polar del pedido (`XABIA--…` en payload / benefit grant).
3. **`client_url`** del checkout → licencia cuyo `client_domain` coincide.
4. Email **solo si hay una única** licencia para ese email.

Si hay varias licencias con el mismo email y faltan clave Polar + URL del sitio, el webhook **no entrega** (log seguro):

`[xabia-polar] skip: email … has N licenses; require polar license key or client_url in checkout`

## Catálogo Polar → Hub

| Producto Polar | Tipo Hub | `addon_slug` / efecto | UUID producto (`prod_…`) |
|----------------|----------|------------------------|---------------------------|
| Core 199 € | `core` | Extiende caducidad Core 1 año | `80a7bbd7-6d9f-41c4-b6a6-ad9e181cd991` |
| Core 69 €/año | `core` | Extiende caducidad Core 1 año | `4db9de15-39e7-4d5b-814f-be8e334d874e` |
| Pack tokens 29 € | `tokens` | +5 M tokens | `a6e9f15a-f4ac-4bd4-b6f9-c3d9f69ce53e` |
| Pack tokens 79 € | `tokens` | +20 M tokens | `842d765b-a6cd-4be9-8058-d29a23029884` |
| Pack tokens 249 € | `tokens` | +100 M tokens | `040d72f1-e302-4f79-81bd-a97987d74635` |
| **Addon MEC** | `addon` | **`xabia-mec`** | `8078756b-c566-4557-a55d-3712d8e47c44` |
| **Addon WooCommerce** | `addon` | **`xabia-woo`** | `50531883-49dc-486e-ba21-3bdb998d455e` |
| **Addon Avirato** | `addon` | **`xabia-avirato`** | `98a1013f-0439-4428-a1af-0e064d9a352d` |

Mapa en código: `central-api/src/PolarProductMap.php`. Overrides opcionales en `.env`:

- `POLAR_PRODUCT_UUID_MEC`
- `POLAR_PRODUCT_UUID_WOO`
- `POLAR_PRODUCT_UUID_AVIRATO`

### No requieren licencia Polar aparte

| Funcionalidad | Motivo |
|---------------|--------|
| **Smart QR / tótems** | Incluido en Core (`xabia-smart-qr` en el paquete Core). |
| **DTP / traducción WPML** | Incluido en licencia Core (Hub). |
| Amelia, Federation | Aún no mapeados en `PolarProductMap` (catálogo futuro). |

## Custom fields en Polar (obligatorio en addons)

Configurar en **cada checkout de addon** (MEC, Woo, Avirato) y recomendado en Core:

| Campo (slug) | Obligatorio | Ejemplo | Uso |
|--------------|-------------|---------|-----|
| `domain` | **Sí** (addons) | `https://mi-tienda.eus` | **Ya en Polar org Xabia** |
| `license_key` | Opcional | `XABIA--0646A674-…` | Forzar addon a una licencia concreta |

El Hub lee: `metadata`, `custom_data`, `customer_metadata`, `custom_field_data` (y anidados en `customer`, `product`, `checkout`).

Alias URL: `domain`, `client_url`, `site_url`, `site`, `wordpress_url`, `client_domain`.

Alias licencia: `license_key`, `xabia_license_key`, `digixop_license_key`.

### Checklist por producto en Polar

Repetir en **MEC**, **Woo** y **Avirato** (y Core si el checkout lo permite):

1. Producto → Checkout → **Checkout Fields** → activar **`domain`** (Required).
2. Etiqueta visible al cliente: «URL de tu WordPress» (editable en el custom field org).
3. Webhook apuntando a `https://xabia.ai/api/xabia/v1/webhooks/polar`.
4. Eventos: `order.paid`, `subscription.active`, `subscription.created`, `benefit_grant.created`.

## Flujos por tipo de compra

### Core (licencia nueva)

- Polar genera `XABIA--…` → Hub crea fila en `xabia_licenses`.
- Con `client_url` → `client_domain` correcto desde el inicio.
- Sin URL → `pending.unassigned` (se vincula al validar desde WordPress).

### Addon (MEC / Woo / Avirato)

- Misma resolución de licencia que Core.
- Hub escribe/actualiza `xabia_addon_activations` con `addon_slug` correspondiente.
- En WordPress: **Xabia Agent → Addons → Sincronizar licencia** → badge **Hub Polar: activa**.

### Packs de tokens

- Misma resolución de licencia.
- Hub suma tokens a la cartera de la licencia resuelta (`addTokensToWalletForLicenseKey`).

### Mismo email, varios clientes (agencia)

- **Obligatorio** `client_url` en checkout de **cada addon**.
- Alternativa: `license_key` explícita en metadata.

## Comprobación tras compra

```bash
curl -sS -X POST "https://xabia.ai/api/xabia/v1/license/validate" \
  -H "Content-Type: application/json" \
  -H "X-Xabia-License: XABIA--…" \
  -H "X-Xabia-Source: https://tu-sitio.eus" \
  -d '{"license_key":"XABIA--…","domain":"tu-sitio.eus"}'
```

Comprobar `active_addons` incluye el slug esperado:

- `xabia-mec`
- `xabia-woo`
- `xabia-avirato`

## Webhook y logs

- URL: `https://xabia.ai/api/xabia/v1/webhooks/polar`
- Secreto: `POLAR_WEBHOOK_SECRET` en `central-api/.env`
- Logs: `~/.logs/error_log_xabia_ai` (prefijo `[xabia-polar]`)

Mensajes útiles:

| Log | Significado |
|-----|-------------|
| `auto_created_license` | Core/licencia nueva creada |
| `resolved license by domain=` | Addon asignado por `client_url` |
| `fulfilled order.paid … license_key=` | Entrega completada |
| `skip: email … has N licenses` | Falta `client_url` o clave Polar |

## Despliegue Hub

```bash
scp xabia-agent-plugins/central-api/src/LicenseRepository.php \
    u610697097@194.55.132.53:/home/u610697097/central-api/src/

scp xabia-agent-plugins/central-api/src/Handlers/PolarWebhookHandler.php \
    u610697097@194.55.132.53:/home/u610697097/central-api/src/Handlers/

scp xabia-agent-plugins/central-api/src/PolarProductMap.php \
    u610697097@194.55.132.53:/home/u610697097/central-api/src/
```

```bash
php -l /home/u610697097/central-api/src/LicenseRepository.php
php -l /home/u610697097/central-api/src/Handlers/PolarWebhookHandler.php
php -l /home/u610697097/central-api/src/PolarProductMap.php
```

No requiere reinicio de servicios.

## Guía paso a paso — Addon MEC (empezar aquí)

Producto Hub: **`xabia-mec`** · UUID Polar: `8078756b-c566-4557-a55d-3712d8e47c44`  
Checkout actual: [buy.polar.sh/polar_cl_wEzwnqMvZIrPelny1I5HNIsdcVjGs1UO12Roj3zzxIm](https://buy.polar.sh/polar_cl_wEzwnqMvZIrPelny1I5HNIsdcVjGs1UO12Roj3zzxIm)

### Paso 1 — Custom field (ya lo tenéis, compartido con DTP)

En Polar org **Xabia** ya existe el campo **`domain`** (usado también en **Digixop Translator Pro**). **No hace falta otro slug.**

Help text actual (válido para Xabia):

> Cada licencia es válida para un único dominio. Indica aquí el dominio exacto donde instalarás el plugin o el addon. Ejemplo: `tudominio.com` (sin `https://` ni `www.`)

El Hub normaliza cualquier formato (`tudominio.com`, `https://www.tudominio.com`, subdominios) a hostname y compara con la licencia en WordPress.

Opcional: ampliar el help a «…plugin Xabia Core o addon (MEC, Woo, Avirato)».

### Paso 2 — Activarlo en el producto MEC

1. [Polar → Products](https://polar.sh/to/dashboard/products) → abre el producto **MEC** / Modern Events Calendar (UUID `8078756b-…`).
2. Sección **Checkout Page** → **Checkout Fields**.
3. Activa **`domain`** (no hace falta otro slug).
4. Marca **Required** ✓.
5. Guardar producto.

### Paso 3 — Probar el checkout

Abre (o comprueba) el enlace con URL prefilled:

```
https://buy.polar.sh/polar_cl_wEzwnqMvZIrPelny1I5HNIsdcVjGs1UO12Roj3zzxIm?domain=test.ondareabizkaia.eus
```

Debe aparecer el campo **domain** con `test.ondareabizkaia.eus` (sin `https://`).

Desde WordPress (**Xabia Agent → Addons → MEC → Contratar suscripción**), Core **1.0.205+** añade automáticamente `client_url` y `license_key` al enlace Polar del sitio actual.

### Paso 4 — Verificar en el Hub

Tras un pago de prueba:

1. Log servidor: `grep xabia-polar ~/.logs/error_log_xabia_ai | tail -5`  
   Debe verse `resolved license by domain=` o `fulfilled … license_key=XABIA--0646A674-…`
2. API validate: `active_addons` debe incluir **`xabia-mec`**.
3. WP: **Addons → Sincronizar licencia** → **Hub Polar: activa**.

### Paso 5 — Repetir para Woo y Avirato

Misma org field `client_url`; activar como **Required** en cada producto addon.
