# Informe técnico: modularidad, mapeador, tokens, i18n y credenciales Vertex

**Contexto:** Xabia AI (RAG en WordPress), objetivo marca blanca, core minimalista, addons desacoplados, eficiencia de tokens y soberanía de datos.

**Fecha:** Abril 2026; actualización mayo 2026 alineada a **Core v1.0.57**: `addons/`, idioma políglota, shortcode focus, MEC remoto y hub.  
**Alcance revisado:** `core/class-xabia-db-bridge.php`, `api/class-xabia-api.php`, `admin/class-xabia-admin.php` (mapeador JS), shortcode `lang`, **`user_lang`** como fallback, rutas Vertex JSON, carga modular en `xabia-intelligence.php`.

**Documentación en este repo:** [README.md](./README.md), [DESARROLLO.md](./DESARROLLO.md) (pipeline API/admin), [MEMORIA_TECNICA.md](../../MEMORIA_TECNICA.md) §5–§7.

---

## 1. Auditoría de modularidad (Bridge + API)

### Resultado

En **`class-xabia-db-bridge.php`** y **`class-xabia-api.php`** **no** aparecen referencias a WooCommerce, MEC ni Amelia (ni slugs de CPT propios de esos plugins). El núcleo de ingesta es genérico: CSV/SQL vía `sql_config`, mapeo `attributes`, y el chat/RAG vía `project_id` y opciones.

El **núcleo** de ingesta y chat sigue **sin** acoplarse a Woo/MEC/Amelia por nombre en `class-xabia-db-bridge.php` / `class-xabia-api.php`. Las integraciones comerciales / verticales están en **`addons/`** (MEC, Amelia, Avirato, **WooCommerce**, **QR/POI** en `addons/xabia-qr/`) y en **`integrations/`** (**Central**, **reservas** unificadas MEC+Amelia) y se enlazan al admin mediante **`register_xabia_addon()`** y filtros (`xabia_register_sql_sources`, `xabia_chat_addon_discovery_blocks`, etc.).

### Acoplamiento residual (no es “plugin name”, pero sí “vertical copy”)

En **`class-xabia-api.php`** el texto fijo del **router** (`expand_user_query_generic`) y sobre todo **`$rag_behavior` dentro de `build_system_prompt()`** incluye ejemplos de dominio (empresas, castillo, hípica/caballo/canoa, “más empresas”). No acoplan a un plugin concreto, pero **sí orientan el producto a un caso de uso concreto** y **consumen tokens en todos los proyectos**, incluso marca blanca genérica.

**Recomendación:** externalizar ese bloque a:

- una plantilla mínima por defecto (neutra), y  
- `apply_filters('xabia_system_rag_behavior', $text, $config, $response_mode)` o un campo opcional en `rules` (p. ej. `rag_behavior_preset`: `default` | `compact` | `custom` + textarea).

Así el core permanece neutro y los partners pueden inyectar copy vertical sin tocar el archivo de la API.

---

## 2. Estado del mapeador y propuesta “visual”

### Estado actual (post-refactor)

- El mapeo sigue persistiendo en **`xabia_projects_config`** con la misma forma: `attributes[*][csv_col|label|visual_role|is_ente|instruction]` y, en multi-fuente, `sources[*][attributes][...]`.
- La UI usa **`<select class="xabia-col-selector">`** alimentado tras **Test SQL / Scan CSV**, más **`xabia_get_meta_fields`** (POST `post_type`) que devuelve **`meta_keys`** (columnas `wp_posts`, meta en BD, campos ACF de grupos ligados al CPT) y un botón para **fusionar** opciones en los selectores existentes.

### Fricción que queda

- El usuario sigue escribiendo **SQL manual** para CPT salvo que use addons o plantillas propias.
- No hay **asistente por pasos** (elegir CPT → elegir campos → generar `SELECT` con alias alineados al mapeo).
- ACF: se listan **nombres de campo**; subcampos repetidor/flexible pueden necesitar **UX** (etiquetas, preview) sin cambiar el contrato de guardado.

### Propuesta técnica (sin romper `xabia_projects_config`)

1. **Mantener el contrato POST actual** (`csv_col` = nombre de columna devuelta por la query o meta key según el origen).
2. **Capa “origen de columnas”** en admin:  
   - Origen A: columnas del último Test SQL (ya existe).  
   - Origen B: `xabia_get_meta_fields` (ya existe).  
   - Origen C (nuevo, opcional): endpoint que devuelva **solo lista de CPT** (`get_post_types`) y, al elegir CPT, proponga un **SQL de plantilla** + columnas `p.post_title`, `p.post_content`, etc., rellenando el textarea y disparando lógica de mapeo.
3. **UI:** tabla o tarjetas por fila de mapeo (columna | etiqueta | rol | ENTE | instrucción) con **arrastrar para ordenar** solo en cliente; el guardado sigue siendo el mismo array ordenado por índice.
4. **Filtro** `xabia_mapping_column_suggestions` (`$columns`, `$project_id`, `$source_type`) para que addons añadan columnas virtuales sin tocar core.

---

## 3. Eficiencia de tokens (`build_system_prompt`)

### Partes costosas hoy

| Bloque | Observación |
|--------|-------------|
| **`$rag_behavior`** | Varias viñetas largas en español; se envía **siempre** al completo. |
| **`$visual_protocols`** | Corto; razonable mantenerlo si el frontend depende de ACTION. |
| **`$time_awareness`** | Una línea; impacto bajo. |
| **`$format_instruction`** | Ya es condicional (`list` vs `development`). |
| **`$persona` / `$qr_rules`** | Ya condicionales a ente / modo estricto. |
| **Router** (`expand_user_query_generic`) | Prompt con ejemplos de ontología; otra llamada LLM cuando no hay vector. |

### Compactación y condicionalidad sugerida

1. **Versión “compacta” de RAG:** sustituir viñetas por 3–5 reglas numeradas (mismo significado, menos caracteres) o mover el detalle conversacional largo a **`rules.instructions`** solo en proyectos que lo necesiten.
2. **Flags en `rules`:** por ejemplo `rag_extended_behavior` (0/1); si 0, solo reglas mínimas (usar contexto, admitir ignorancia, formato lista/desarrollo).
3. **Dominio:** eliminar ejemplos fijos (caballo, castillo) del core; pasarlos a instrucciones del proyecto o a un addon de “plantilla turismo”.
4. **Contexto:** el tope de **20 000 caracteres** ya limita coste; valorar formato de contexto más denso (p. ej. JSON por chunk) en `Xabia_Brain::format_context_from_rows` como mejora aparte.

---

## 4. Multilingüismo (`lang`, `user_lang`, prompt y hub)

### Estado actual (v1.0.57)

- En **`chatbox.php`**, el atributo **`lang`** del shortcode alimenta **`data-lang`** para **reconocimiento de voz / TTS** (p. ej. `es-ES`).
- **`chatbox.js`** envía en el POST de **`xabia_ask_ai`**:
  - **`lang`**: código ISO 639-1 derivado de `data-lang`, usado como UI/voz/fallback.
  - **`user_lang`**: etiqueta tomada de **`document.documentElement.lang`** de la página (p. ej. `es-ES`, `en-GB`); si falta, mismo fallback que `lang`.
- **`build_system_prompt()`** inyecta una regla políglota: responder en el idioma del último mensaje del usuario y traducir internamente el contexto RAG/CSV si está en otro idioma.
- **`handle_chat_request()`** sigue saneando **`user_lang`** para compatibilidad con proxy/hub, pero ese valor ya no debe actuar como candado de idioma.
- El **hub** (`xabia-agent-plugins/central-api`) y las rutas Vertex deben conservar la misma consigna políglota en `systemInstruction`.

**Conclusión:** el **LLM** queda gobernado por (1) el último mensaje del usuario, (2) instrucciones del proyecto, (3) fallback `lang`/`user_lang` cuando el idioma sea ambiguo, y (4) textos fijos del router/RAG que siguen mayormente en español en código — candidatos a externalizar según §1 y §3 de este informe.

**Mejora pendiente (producto):** `load_plugin_textdomain` para cadenas WP del plugin y neutralizar copy fijo del router/RAG sin consumir tokens en verticales que no lo necesiten.

---

## 5. Seguridad de credenciales Google (Vertex) vs memoria §13

### Alineado con buenas prácticas

- Ruta al JSON **fuera del web root** recomendada en documentación; el código **solo lee del sistema de archivos** con `file_exists` / `file_get_contents` en servidor.
- Prioridad **proyecto → opción global** (`resolve_gcloud_json_path`).
- Uso de **Service Account** y librería **Google Auth**; token solo en tránsito hacia Vertex, no expuesto al navegador en flujo normal.

### Puntos de mejora (soberanía / endurecimiento)

1. **`putenv('GOOGLE_APPLICATION_CREDENTIALS=...')`** puede ser **sensible en hosting compartido** (alcance del proceso). Preferible pasar la ruta del JSON **solo** al constructor de `ServiceAccountCredentials` sin fijar variable de entorno global si la librería lo permite en todas las rutas de código.
2. **Mensajes de error** que incluyen la ruta absoluta del JSON **filtran información**; conviene mensajes genéricos en producción y detalle solo en `WP_DEBUG`.
3. **Permisos de archivo:** documentar `0600` y propietario del proceso PHP; no comprobarlo en código, pero sí en guía de despliegue.
4. **OpenAI key** en opción WP: mismo criterio (no registrar en logs, no devolver en REST público).

---

## 6. Resumen ejecutivo

### Fricciones para escalar

- Copy de sistema **largo y verticalizado** en la API (tokens + marca blanca).
- **SQL manual** sigue siendo la puerta de entrada principal para CPT “nativo”.
- Coherencia **`lang`** (shortcode) vs **`user_lang`** (página): deben quedar como fallback/UI para evitar instrucciones contradictorias al modelo.
- Pequeños **riesgos operativos** con `putenv` y filtrado de rutas en errores Vertex.

### Plan de refactor “addons fuera del core”

- **Cumplido en v1.0.0:** Bridge/API sin nombres de Woo/MEC/Amelia; verticales en **`addons/`** y tabla de carga selectiva en `xabia-intelligence.php`.  
- **Siguiente paso:** mover **texto de comportamiento RAG / router** a filtros o a `rules` configurables, con default neutro y compacto.

### Propuesta mapeador visual (siguiente iteración)

- Conservar **shape de `xabia_projects_config`**.  
- Añadir **flujo CPT → meta/ACF → plantilla SQL** y, si aplica, **orden visual** en cliente.  
- Exponer **filtros** para columnas sugeridas por addons.

---

*Documento generado como entregable de análisis; enlazado desde la documentación de producto (`xabia-agent-plugins/documentation/README.md`) y coherente con `MEMORIA_TECNICA.md` §3.6 y §7.2 (abril 2026, producto **v1.0.0**).*
