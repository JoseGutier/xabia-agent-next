# Xabia Agent Next

Plugin de WordPress: asistente conversacional con **RAG**, memoria de sesión, voz y acciones. Incluye **Xabia Central** (federación pull/push), **WooCommerce**, **reservas MEC/Amelia**, importación **SQL/CSV**, multi-fuente y **addons** en la carpeta **`addons/`** (MEC, Amelia, Avirato, WooCommerce, **QR/POI**, …) además de integraciones transversales en **`integrations/`** (Central, reservas).

**Código y manuales de producto** viven en **`xabia-agent-plugins/`** (plugins instalables, documentación, salida de ZIPs).

**Versión actual (Core):** **v1.0.208** — `XABIA_VERSION` / cabecera de [`xabia-intelligence.php`](xabia-agent-plugins/packages/xabia-agent-core/xabia-intelligence.php). Addons de referencia: **MEC 1.0.3**, **Woo 1.0.4**. Incluye activación PRO desde UI LITE retail, checkout Polar con dominio/licencia, corrección del aviso de actualización en WordPress, acciones de chat (IMG/Amelia/MEC remoto), UI stream + Markdown, avatar parlante / launcher y Smart QR / tótems.

## Instalación desde ZIP

1. **Venta / cliente:** `./scripts/build-retail-plugin-zips.sh` → `xabia-agent-plugins/dist/retail/xabia-agent-core-<versión>-retail.zip` (y addon Avirato si aplica).
2. **ZIP mínimo de desarrollo / staging:** `ONLY_SLUG=xabia-agent-core ./scripts/build-plugin-zip.sh` → `xabia-agent-plugins/dist/xabia-agent-core-<versión>.zip` (incluye `api/` del monorepo).
3. En WordPress: **Plugins → Añadir nuevo → Subir plugin** y activar (carpeta del Core: **`xabia-agent-core/`**). Tras activar o actualizar, visite **Ajustes → Enlaces permanentes → Guardar** si usa la ruta `/xabia-box/`.

Pasos de producción detallados: [DESPLIEGUE_PRODUCCION_CORE.md](xabia-agent-plugins/documentation/DESPLIEGUE_PRODUCCION_CORE.md).

Detalle: [MEMORIA_TECNICA.md §16](MEMORIA_TECNICA.md#16-distribución-paquete-zip-e-instalación-en-wordpress). El laboratorio **Playground** usa el mismo motor de chat que el sitio público (`Xabia_API::handle_chat_request`); ver [MEMORIA_TECNICA.md §7.1](MEMORIA_TECNICA.md#71-endpoints-ajax-admin).

## Documentación

### Uso y administración

| Documento | Contenido |
|-----------|-----------|
| [**manual-usuario-xabia-core.md**](xabia-agent-plugins/documentation/manual-usuario-xabia-core.md) | **Manual Core** (modular, con guía rápida de instalación). Índice público: [xabia.ai/docs/](https://xabia.ai/docs/). PDF: [manual-usuario-xabia-core.pdf](https://xabia.ai/docs/manual-usuario-xabia-core.pdf). |
| [**manual-usuario-xabia-smart-qr.md**](xabia-agent-plugins/documentation/manual-usuario-xabia-smart-qr.md) | **Smart QR / tótems** (incluido en Core): túnel por ente, generador QR, `/xabia-box/`, ejemplos museo/hotel/retail. |
| [**manual-usuario-xabia-mec.md**](xabia-agent-plugins/documentation/manual-usuario-xabia-mec.md) | Addon MEC: eventos, reservas, federación y MEC remoto vía SQL/addon. |
| [**manual-usuario-xabia-woo.md**](xabia-agent-plugins/documentation/manual-usuario-xabia-woo.md) | Addon Woo: catálogo, carrito, cupones y tienda remota. |
| [**manual-usuario-xabia-avirato.md**](xabia-agent-plugins/documentation/manual-usuario-xabia-avirato.md) | Addon Avirato: webcode, motor de reservas, disponibilidad y enlaces de reserva. |
| [**manual-usuario-xabia-core.md**](xabia-agent-plugins/documentation/manual-usuario-xabia-core.md) | Manual de usuario canónico (Core). `MANUAL_USUARIO.md` está **deprecado** (redirige aquí). |
| [MEMORIA_TECNICA.md](MEMORIA_TECNICA.md) | Referencia técnica completa: arquitectura, BD, APIs, integraciones, Vertex AI, panel admin, changelog. |

### Desarrollo e ingeniería

| Documento | Contenido |
|-----------|-----------|
| [**DESARROLLO.md**](xabia-agent-plugins/documentation/DESARROLLO.md) | **Guía de desarrollo** a fondo: arranque del plugin, pipeline de chat (nonce, RAG, grounding), ingestión, filtros, frontend, empaquetado. |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Onboarding en el repo: estructura, mapeo, límites del prompt, i18n, ZIP. |
| [Índice documentación producto](xabia-agent-plugins/documentation/README.md) | Planes de estrategia y federación. |
| [Addon Central (federación)](xabia-agent-plugins/packages/xabia-agent-core/integrations/central/README.md) | Xabia Central en WordPress |
| [central-api/README.md](xabia-agent-plugins/central-api/README.md) | Hub PHP en `xabia.ai` (licencias, wallet, proxy OpenAI-compat → Vertex, firma HMAC por licencia; el idioma cliente se usa como respaldo/UI, no como bloqueo de respuesta) |

**Estructura de código (Core):** dentro de **`xabia-agent-plugins/packages/xabia-agent-core/`** — `core/`, `api/`, `addons/xabia-*/`, `integrations/`. Detalle: [MEMORIA_TECNICA.md](MEMORIA_TECNICA.md) §3.6 «Núcleo, integrations y addons».

## Desarrollo

Ver [CONTRIBUTING.md](CONTRIBUTING.md) y [DESARROLLO.md](xabia-agent-plugins/documentation/DESARROLLO.md). Para pruebas de federación (CLI): `test-xabia-federation.php` (raíz del monorepo).

## Licencia / autor

Cabecera del plugin en `xabia-intelligence.php` (Autor: Xabia AI).
