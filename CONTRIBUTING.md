# Guía para colaboradores (GitHub)

Este documento resume el **estado actual del repositorio** para alinear trabajo en paralelo: estructura, dónde tocar el admin/mapeo, qué se envía al LLM y cómo está el i18n. La referencia arquitectónica detallada sigue siendo **[MEMORIA_TECNICA.md](./MEMORIA_TECNICA.md)**. Para el **manual de usuario** (administradores) ver **[xabia-agent-plugins/documentation/manual-usuario-xabia-core.md](./xabia-agent-plugins/documentation/manual-usuario-xabia-core.md)**; para **desarrollo a fondo** (pipeline, extensiones, HMAC/filtros §12) **[xabia-agent-plugins/documentation/DESARROLLO.md](./xabia-agent-plugins/documentation/DESARROLLO.md)**.

---

## 1. Estructura de carpetas

```
xabia-agent-next/
├── xabia-agent-plugins/           # Producto WordPress, hub, API Core, manuales, dist/
│   ├── packages/                  # xabia-agent-core/, xabia-mec/, xabia-avirato/, …
│   ├── api/                       # Clases REST/AJAX del Core (Xabia_API); en ZIP van dentro del Core
│   ├── central-api/               # Hub PHP Xabia (licencias, proxy Vertex, …)
│   ├── documentation/             # MANUAL_USUARIO, DESARROLLO, manuales modulares, PDF
│   └── dist/                      # ZIP generados (gitignore)
├── scripts/
│   ├── build-retail-plugin-zips.sh → xabia-agent-plugins/dist/retail/
│   └── build-plugin-zip.sh        → xabia-agent-plugins/dist/
├── test-xabia-federation.php
├── README.md
└── MEMORIA_TECNICA.md
```

**Core (dentro de `xabia-agent-plugins/packages/xabia-agent-core/`):**

```
├── xabia-intelligence.php
├── core/
├── admin/
├── frontend/
│   ├── widgets/          # chatbox (shortcode)
│   ├── class-xabia-interface.php  # avatar/panel nativo (wp_footer)
│   └── assets/css|js|svg/
├── integrations/
│   ├── class-xabia-sql-connector.php
│   ├── central/
│   └── reservas/
└── addons/
```

**Addons:** registro con `register_xabia_addon($slug, $args)` y/o filtro `xabia_register_sql_sources`; hook `xabia_register_addons` y `xabia_install_addon_tables` en activación. Los verticales nuevos deben vivir preferentemente en **`addons/xabia-<slug>/`** con un `xabia-addon-<slug>.php` principal.

---

## 2. Admin y guardado de fuentes (`class-xabia-admin.php`)

- **Acción POST:** `xabia_action=save_project` → `controller_handle_post()`.
- **Persistencia:** opción de WordPress `xabia_projects_config` (array `[ project_id => config ]`).

**Campos relevantes para un mapeador “user-friendly”:**

| Concepto | Dónde se guarda |
|----------|------------------|
| Tipo de fuente | `source_type`: `csv`, `sql`, `addon`, `multi` |
| Mapeo fuente única | `attributes[]`: `csv_col`, `label`, `instruction`, `is_ente`, `visual_role` |
| CSV | `csv_filename` + archivos en `wp-content/uploads/xabia/` |
| SQL | `sql_config`: `host`, `user`, `name`, `pass`, `query` |
| Addon | `addon_slug` |
| Multi-fuente | `sources[]`: por entrada `type`, `attributes`, y `csv_filename` **o** `sql_config` (+ `prefix` opcional) |

Cualquier nueva UI de mapeo debe generar el **mismo shape** que espera el POST actual (o una capa que lo traduzca antes de guardar). Detalle en MEMORIA_TECNICA §4.3, §11.4 y §14.

---

## 3. Qué se envía al LLM (tokens / plantilla)

No hay tokenizador propio: es **texto** con límites fijos.

**Archivo:** `api/class-xabia-api.php` — `handle_chat_request()` + `build_system_prompt()`.

1. **System (un solo string):** instrucciones del proyecto (`rules.instructions`) + bloques fijos (fecha, protocolos ACTION, persona/QR, formato lista vs desarrollo, reglas RAG en español) + sección `CONTEXTO DISPONIBLE:` entre `###` y el texto `$context`.
2. **Historial:** hasta **6** mensajes `user`/`assistant` (sesión PHP o fallback JSON `history` en POST).
3. **Usuario:** último mensaje del visitante.
4. **max_tokens** de respuesta del chat: **1000** (OpenAI `gpt-4o` / Vertex según `ai_driver`).

**Contexto `$context`:**

- Proviene de `content_chunk` de la BD, formateado en `Xabia_Brain::format_context_from_rows()` como **bloques separados por línea en blanco** (chunks únicos, sin viñetas obligatorias).
- Tope: **20 000 caracteres**; si se supera, se trunca y se añade `[CORTADO POR LÍMITE]`.
- Número de chunks/filas: `rules.context_chunk_limit` (1–200) cuando está definido.

**Llamada extra (sin vector):** `expand_user_query_generic()` — intérprete con **~200** tokens máx.; mismo proveedor que el proyecto (OpenAI mini o Vertex).

**Optimización futura:** acortar texto fijo del system, densificar `content_chunk` en ingestión, bajar `context_chunk_limit` o el techo de 20 000, o esquema de contexto más compacto (p. ej. JSON mínimo).

---

## 4. Traducción e idioma del agente

| Aspecto | Estado |
|---------|--------|
| Archivos `.po` | `languages/xabia-*.po` (es, eu, en) |
| `load_plugin_textdomain` | **No** está registrado en `xabia-intelligence.php`; las traducciones WP no se aplican solas hasta que se añada |
| Text domain en código | Muchas cadenas usan `'xabia-intelligence'` |
| Atributo `lang` del shortcode | STT/TTS, placeholders y fallback de interfaz vía `data-lang`; no debe bloquear el idioma del texto generado |
| **`user_lang` en el POST** | `chatbox.js` puede enviar la etiqueta de **`document.documentElement.lang`** (p. ej. `es-ES`) para compatibilidad, trazabilidad y fallback |
| Idioma de respuestas | `build_system_prompt()` inyecta la regla políglota: responder en el idioma del último mensaje del usuario y traducir internamente el contexto RAG/CSV si está en otro idioma |

---

## 5. Referencias rápidas

| Tema | Ubicación |
|------|-----------|
| Arquitectura, BD, RAG, Vertex | [MEMORIA_TECNICA.md](./MEMORIA_TECNICA.md) |
| UI admin (CSS, DOM, AJAX) | MEMORIA_TECNICA §14 |
| Endpoints AJAX | MEMORIA_TECNICA §7 |
| Federación Central, cron, script de prueba | MEMORIA_TECNICA §8.2, `integrations/central/README.md` |
| Reservas MEC/Amelia, WooCommerce cart | MEMORIA_TECNICA §8.1; addons en `addons/` (§3.6) |

**Pull requests:** cambios acotados, sin refactors masivos no solicitados; si tocáis estilos del admin, mantener el ámbito bajo `.xabia-wrapper.xabia-admin-app` (ver §14 de la memoria).

---

## 6. Empaquetado ZIP para WordPress

**Paquete comercial (Core + Avirato, documentación embebida en el Core):**

```bash
chmod +x scripts/build-retail-plugin-zips.sh
./scripts/build-retail-plugin-zips.sh
```

Salida: **`xabia-agent-plugins/dist/retail/xabia-agent-core-<versión>-retail.zip`** y **`xabia-agent-plugins/dist/retail/xabia-avirato-<versión>-retail.zip`**. Origen: **`xabia-agent-plugins/packages/`** (el script usa `rsync` + `zip`; el Core incluye `vendor/` si existe en origen).

**ZIP mínimo por slug:**

```bash
chmod +x scripts/build-plugin-zip.sh
./scripts/build-plugin-zip.sh
```

Salida: **`xabia-agent-plugins/dist/<slug>-<versión>.zip`**, etc., según la lista de slugs del script.

Antes de un release, alinear **`Version:`** y **`define('XABIA_VERSION', …)`** en `xabia-agent-plugins/packages/xabia-agent-core/xabia-intelligence.php` y, si aplica, actualizar **MEMORIA_TECNICA.md** / **README.md**. Ver memoria **[§16](MEMORIA_TECNICA.md#16-distribución-paquete-zip-e-instalación-en-wordpress)**.

**Playground (laboratorio en edición de agente):** las peticiones `xabia_ask_ai` envían `nonce` = `xabia_admin_nonce` vía `xabiaAdminPost` / `xabiaCurrentNonce`. El chat lo procesa **`Xabia_API::handle_chat_request()`** (mismo pipeline que el frontend), aceptando `xabia_admin_nonce` con `manage_options` o `xabia_nonce`. Para **otros** AJAX exclusivos del admin, si el nonce falla se usa `check_ajax_referer(..., false)` + `wp_send_json_error` con nonce nuevo (evitar 403 sin JSON). Detalle: MEMORIA **[§7.1](MEMORIA_TECNICA.md#71-endpoints-ajax-admin)**, guía **[DESARROLLO.md §4](xabia-agent-plugins/documentation/DESARROLLO.md#4-pipeline-de-chat)**.
