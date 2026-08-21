# Guía de desarrollo — Xabia Agent Next

**Versión de la guía:** 1.0.208 (alineada con Xabia Agent Core **v1.0.208** — actualizaciones WP, activación PRO retail, Polar checkout; UI chat stream + Markdown, avatar parlante / launcher; latencia, embeddings, Document-to-RAG)

**Manual de usuario canónico:** [manual-usuario-xabia-core.md](./manual-usuario-xabia-core.md)  
**Despliegue:** [DESPLIEGUE_PRODUCCION_CORE.md](./DESPLIEGUE_PRODUCCION_CORE.md)  
**Memoria técnica:** [MEMORIA_TECNICA.md](../../MEMORIA_TECNICA.md)  
**Audiencia:** desarrolladores que mantienen el plugin, añaden integraciones o depuran RAG/LLM.

Esta guía **profundiza en el diseño del software** y complementa [MEMORIA_TECNICA.md](../../MEMORIA_TECNICA.md) (referencia exhaustiva) y [CONTRIBUTING.md](../../CONTRIBUTING.md) (onboarding rápido). El **manual de usuario canónico** es [manual-usuario-xabia-core.md](./manual-usuario-xabia-core.md) (add-ons: MEC, Woo, Avirato, Smart QR en esta misma carpeta).

---

## 1. Filosofía del proyecto

- **Un solo núcleo de chat:** Toda conversación pasa por `Xabia_API::handle_chat_request()` en `api/class-xabia-api.php`, tanto para visitantes (`wp_ajax` + `wp_ajax_nopriv`) como para el **Playground** del admin (misma acción `xabia_ask_ai`, validación de seguridad distinta según origen).
- **Ingestión desacoplada:** CSV/SQL/addon/multi-fuente convergen en `Xabia_DB_Bridge` → tabla `xabia_knowledge_vectors` (texto + JSON meta + vectores opcionales).
- **RAG en dos capas:** Búsqueda por **similitud vectorial** (si está activada y hay `vector_data`) con **fallback LIKE** sobre `content_chunk`; si no hay vector, solo LIKE + router semántico opcional.
- **RAG híbrido RRF (Core + Hub):** fusión Reciprocal Rank Fusion de listas vectorial + léxica; matching léxico por **prefijo Unicode compartido** (morfología agnóstica, sin diccionarios); **query rewrite** LLM opcional (`rules.rag_query_rewrite`, default on); prefijos estructurales en ingestión (`rules.rag_chunk_enrichment`). Flags: `rag_hybrid_rrf`, `rag_chunk_enrichment`, `rag_query_rewrite` (`off` restaura comportamiento previo). Tras desplegar Hub/Core: **re-sincronizar y re-vectorizar** agentes cloud (p. ej. demo Aktiba) para que los chunks enriquecidos lleguen al índice Hub.
- **Espacio vectorial unificado (Cloud/Vertex):** embeddings **`text-embedding-004`** en Core (proxy/Hub/Vertex local) y Hub. OpenAI BYOK directo sigue en `text-embedding-3-small` (espacio distinto). Chat Cloud/UI: **`gemini-2.5-flash`** (nombres OpenAI solo en BYOK).
- **Catálogo nativo antes que RAG (Core ≥ 1.0.118):** Preguntas de **listado** y **utilidad por ente** (contacto, teléfono, imagen) pueden resolverse en **PHP + WP nativo** (`Xabia_Catalog_List`, pasaporte por `post_id`) **sin** embedding, Hub ni LLM cuando el atajo aplica **y** la fuente es local. El diseño es **100 % agnóstico**: CPT, taxonomías y meta keys salen del **mapeo `attributes`** del agente, no de nombres fijos de un cliente.
- **Fuente remota (Core ≥ 1.0.164):** si hay SQL host / config remota, **no** se usa `get_post_meta` local para contacto. El sync construye el `content_chunk` del pasaporte + **anexo** (`append_mapped_attributes_annex`) con atributos mapeados que tengan valor.
- **Extensión por hooks y addons:** `register_xabia_addon`, filtros `xabia_system_prompt_rules`, `xabia_register_sql_sources`, etc.

---

## 2. Arranque del plugin (boot)

Orden relevante en `xabia-intelligence.php`:

1. Constantes `XABIA_PATH`, `XABIA_URL`, `XABIA_VERSION`.
2. `init` prioritario: `session_start` si hace falta (memoria de chat).
3. `require` de `class-xabia-db.php`, `class-xabia-brain.php`, `class-xabia-db-bridge.php`, `class-xabia-cpt-schema-discovery.php`, `class-xabia-digixop-client.php`.
4. `require` de `api/class-xabia-api.php` → al final del archivo se llama **`Xabia_API::init()`**, que registra los handlers AJAX del chat, limpieza de sesión y resolución de imágenes.
5. Definición de **`register_xabia_addon()`** y la global **`$xabia_available_addons`**.
6. **`integrations/*.php`** (p. ej. `class-xabia-sql-connector.php` en la raíz de esa carpeta).
7. **`integrations/{central,reservas}/*.php`** — solo esas subcarpetas; ya **no** se hace un `glob` de todas las subcarpetas de `integrations/`. **WooCommerce** se carga desde **`addons/xabia-woo/`**; **QR/POI** desde **`addons/xabia-qr/`**.
8. **`addons/*/*.php`** — todos los PHP de primer nivel en cada `addons/xabia-<nombre>/` (MEC, Amelia, Avirato, WooCommerce, QR, …).
9. **`do_action('xabia_register_addons')`**.
10. Si `is_admin()`, carga `admin/class-xabia-admin.php` y `Xabia_Admin::init()` (UI, AJAX de administración; **no** duplica el handler de `xabia_ask_ai` del chat: el chat es solo API).

El shortcode `[xabia_agent]` registra en el mismo archivo principal y renderiza `frontend/widgets/chatbox.php`.

**Convención para nuevos verticales:** crear `addons/xabia-<slug>/xabia-addon-<slug>.php` (y archivos auxiliares en la misma carpeta), registrar con `register_xabia_addon()` y, si aportan contexto al chat sin ser RAG puro, evaluar el filtro **`xabia_chat_addon_discovery_blocks`** (ver implementación en `addons/xabia-amelia/xabia-addon-amelia.php`).

---

## 3. Modelo de datos esencial

### 3.1 Tabla `wp_xabia_knowledge_vectors` (prefijo configurable)

| Campo | Uso en runtime |
|-------|----------------|
| `project_id` | Aislamiento por agente. |
| `ente_id` | Slug del ente; `global` si no hay campo ENTE. Filtra en modo QR/scope. |
| `content_chunk` | Texto plano concatenado desde el mapeo (`label: valor`); **es lo que busca** LIKE y lo que se trocea para embeddings. |
| `meta_data` | JSON: copia por campo + `__ente_display`, etc.; no se usa en LIKE. |
| `vector_data` | JSON array de floats; null hasta «Entrenar». |

### 3.2 Opción `xabia_projects_config`

Array asociativo `project_id => config`. No hay esquema rígido en BD: nuevas claves pueden añadirse desde el admin si el formulario las guarda. Estructura documentada en MEMORIA §4.3.

---

## 4. Pipeline de chat

Todo ocurre en `Xabia_API::handle_chat_request()`.

### 4.1 Seguridad (nonce)

- Si **no** se envía `nonce` en POST (chat público actual), no se exige verificación de nonce (comportamiento histórico).
- Si se envía `nonce`:
  - **`xabia_nonce`:** válido para frontend.
  - **`xabia_admin_nonce`:** válido solo si `current_user_can('manage_options')` (Playground y peticiones admin que reutilicen el mismo endpoint).

Así el laboratorio del admin puede usar `xabiaAdminPost` con `xabiaCurrentNonce` sin chocar con la comprobación pensada solo para el nonce público.

### 4.2 Sesión e historial

- `$_SESSION['xabia_chat_history'][$project_id]`: hasta 6 mensajes user/assistant para el LLM.
- Si la sesión está vacía, el frontend puede enviar `history` en JSON en el POST.
- `$_SESSION['xabia_last_search'][$project_id]`: último término de búsqueda para el router.

### 4.3 Término de búsqueda

- Si `rules.use_vector_search` está activo: **no** se llama al router; `search_term` = mensaje del usuario (tras saneado).
- Si no: `expand_user_query_generic()` invoca al **Intérprete** (mismo proveedor que el chat: OpenAI o Vertex) para producir palabras clave orientadas al contenido indexado.

### 4.4 Recuperación (`Xabia_Brain`)

- **Vector:** `get_query_embedding($text, $config)` usa OpenAI `text-embedding-3-small` o Vertex `gemini-embedding-001` según `ai_driver`. Se calcula similitud coseno con cada fila candidata (tope de candidatos en constante de clase), se filtra por `similarity_threshold` y se limita por `context_chunk_limit`.
- **Fallback:** si el contexto vectorial es vacío o demasiado corto, búsqueda **LIKE** sobre `content_chunk`.
- Se registra **`$had_knowledge_rows`:** verdadero si hubo al menos un fragmento útil antes de sustituir por el mensaje sistema de «sin resultados».

### 4.5 Contexto vacío

Si tras recuperar no hay texto suficiente, se reemplaza el contexto por una línea tipo:

`SYSTEM_NOTE: La búsqueda de '…' no arrojó resultados directos en la base de datos local.`

Eso **no** son datos de catálogo; el system prompt añade un bloque **SIN DATOS DE CATÁLOGO** cuando `$had_knowledge_rows` es falso, prohibiendo inventar empresas o usar conocimiento general. Así se reduce la alucinación en listados.

### 4.6 Modo lista vs desarrollo

- Si búsqueda vectorial activa y `chunk_count` entre 1 y 2 → modo **desarrollo** (respuesta más narrativa), salvo lógica adicional de foco de entidad.
- Pedidos de utilidad (contacto, teléfono, imagen, …) con entidad focal → **desarrollo** (conserva anexo).
- En caso contrario → modo **lista** (comparativa breve).

**Core ≥ 1.0.165 (agnóstico):** en modo **lista**, `strip_mapped_attributes_annex_from_context()` elimina del contexto el bloque estructural tras `\n---` (anexo de atributos mapeados) antes del LLM. Las reglas de prompt hablan de «anexo / detalle», no de verticales ni CTAs de un cliente. El compact de listados de catálogo (`compact_company_passport_chunk` / Hub) también corta en `\n---`.

### 4.7 System prompt

`build_system_prompt()` compone: instrucciones del proyecto, regla políglota de idioma, fecha, protocolos de acciones (`[ACTION:URL:…]`, etc.), persona/modo QR, formato lista o desarrollo, reglas RAG (preset `rules.rag_behavior_preset` + filtro `xabia_system_prompt_rules` + `xabia_system_rag_behavior`), bloque opcional **sin catálogo**, y el contexto entre delimitadores.

### 4.7.1 Contexto enriquecido por addons (`xabia_chat_addon_discovery_blocks`)

Tras construir el contexto RAG y **antes** del tope de 20 000 caracteres, `handle_chat_request()` aplica:

`apply_filters('xabia_chat_addon_discovery_blocks', [], $project_id, $config)`

Cada callback puede devolver strings que se concatenan bajo el encabezado `### ADDON DISCOVERY ###`. **Amelia** usa esto para inyectar un resumen de servicios y proveedores leídos de `{prefix}amelia_services` y `{prefix}amelia_users` cuando `Xabia_Reservas_Handler::engine_for_project()` devuelve `amelia`. El addon **QR** (`addons/xabia-qr/xabia-addon-qr.php`, slug **`qr`**) añade el bloque **`### CONTEXTO FÍSICO ACTUAL ###`** cuando hay escaneo activo por sesión (`?xqr=` / `?xid=`).

### 4.7.2 Idioma políglota (`lang` como fallback + proxy Xabia / Vertex)

- El frontend (`chatbox.js`) envía **`lang`** / **`user_lang`** para UI, voz y fallback (`document.documentElement.lang`, `data-lang` del shortcode o configuración del sitio). En servidor, **`Xabia_I18n_Bridge::resolve_user_lang()`** unifica la resolución (WPML → Polylang → TranslatePress → locale WP).
- La API sanea la cadena y la conserva como respaldo; ya no debe generar una regla rígida de “responder exclusivamente” en ese idioma.
- `build_system_prompt()` inyecta la regla políglota: responder en el idioma del último mensaje del usuario, traduciendo internamente el contexto RAG/CSV si está en otro idioma.
- Si el proyecto usa el **proxy Xabia** (`Xabia_Digixop_Client::should_use_openai_proxy`), el idioma fallback puede seguir viajando en **`user_lang`** dentro del JSON para trazabilidad y compatibilidad.
- El **hub** (`xabia-agent-plugins/central-api/src/ProxyHandler.php`) lee el cuerpo JSON; si existe **`user_lang`**, la normaliza y deja el valor listo para el forwarder.
- **`VertexForwarder::openAiMessagesToGeminiGenerateContent`** y la ruta local **`call_google_vertex`** deben usar la misma consigna políglota, evitando bloquear el modelo al idioma de la página.

Así el idioma de la **página HTML** queda como respaldo operativo, mientras que el idioma real de salida lo gobierna el último mensaje del interlocutor.

### 4.8 Llamada al LLM

- `ai_driver === 'google_cloud'` → `call_google_vertex()` (Gemini 2.5 Flash, región configurada).
- Si no → `call_openai()` (GPT-4o para chat), con **`user_lang`** en el payload si aplica proxy.

### 4.9 Post-procesado

- Sustitución de `[ACTION:IMG:ID]` por URL de adjunto.
- Resolución de `[ACTION:BOOK:ID]` según motor MEC/Amelia vía `Xabia_Reservas_Handler::engine_for_project`.

### 4.10 Persistencia

- Actualización de sesión con user + assistant.
- Inserción en `xabia_logs` si la tabla existe.
- `$_SESSION['xabia_last_entity']` y `xabia_last_search`: entidad y término RAG del turno (sanitizados; no se persisten frases ordinales sin resolver).
- `$_SESSION['xabia_catalog_manifest']`: manifiesto del último listado nativo (respaldo; ver §12.3).

### 4.11 Atajo de listado nativo (sin LLM)

Antes del bloque RAG/Hub, `maybe_send_native_catalog_list_response()` puede **terminar la petición** con `finish_reason: native_catalog`:

1. Perfil de actividad detectado (`resolve_catalog_activity_profile` + filtros `xabia_rag_catalog_activity_profiles`).
2. Intención de **varias empresas** (`query_expects_multiple_catalog_companies`, `query_implies_catalog_listing`, descubrimiento de actividad).
3. **No** es petición de utilidad por ente (contacto, imagen, teléfono…) ni profundidad sobre una sola ficha.

Consulta `wp_posts` + taxonomía resueltas desde el mapeo (`Xabia_Knowledge_Ingest::resolve_catalog_post_type`, `resolve_catalog_activity_taxonomy`). Respuesta: viñetas deterministas en PHP. Debug RAG: `catálogo nativo WP: sí`, `Hub chunks: 0/0`.

### 4.12 Caché y rendimiento del chat (v1.0.168)

Objetivo: reducir latencia percibida y llamadas redundantes a APIs externas **sin cambiar** la lógica RAG, los hooks ni el formato de respuesta.

#### 4.12.1 Caché de respuesta (`xabia_response_cache`)

Clase: **`Xabia_Router`** (`core/class-xabia-router.php`).

| Momento | Método | Efecto |
|---------|--------|--------|
| **Antes** del router / mini-LLM | `find_cached_response_for_query()` | Busca hit con hash de `ROUTE_KNOWLEDGE` y `ROUTE_GENERAL` en una sola consulta SQL |
| Tras clasificar ruta | `get_cached_response()` → `get_cached_response_by_hashes()` | Lookup por hash exacto (ruta conocida) |
| Tras generar respuesta | `put_cached_response()` | TTL 1 día (KNOWLEDGE/GENERAL); 15 min (ACTION) |

- Hash: `query_hash(project_id, query, route, lang)` + filtro **`xabia_response_cache_version`** (invalida al subir Core).
- Se omite en admin (`xabia_admin_nonce`), modo RAG dev y rutas `ROUTE_ACTION` dinámicas.
- **No** se ejecuta `SHOW TABLES` en lectura/escritura: se asume tabla creada en activación (`Xabia_DB::create_tables` / `dbDelta`).

Trazas: `xabia_trace('[XABIA_CORE] response_cache early hit (pre-router)', …)` en hit temprano.

#### 4.12.2 Caché de embeddings de consulta

Clase: **`Xabia_Embedding_Cache`** (`core/class-xabia-embedding-cache.php`).

- Clave transient: `xabia_emb_` + `md5($model_name . '_' . trim(mb_strtolower($text)))`.
- El **nombre del modelo** siempre forma parte del hash (p. ej. `text-embedding-3-small` vs `gemini-embedding-001`).
- Capas: array estático por petición + `set_transient` (TTL por defecto **30 días**, filtro **`xabia_query_embedding_cache_ttl`**).
- Integrado en **`Xabia_API::get_query_embedding()`** y **`Xabia_Brain::get_embedding()`** (miss → HTTP → set).

#### 4.12.3 LLM auxiliares alineados al proveedor

Helper privado **`Xabia_API::call_auxiliary_llm()`**:

| Función | Uso |
|---------|-----|
| `classify_route_with_mini()` | Filtro `xabia_router_classify_route` — etiqueta ROUTE_* |
| `maybe_summarize_history()` | Historial > 10 turnos → resumen de 2 líneas |

Si `ai_driver === 'google_cloud'` y **`Xabia_Digixop_Client::should_use_local_vertex($config)`** (modo `own_infra` + JSON): **`call_google_vertex()`** con **`gemini-2.5-flash`**. En caso contrario (OpenAI, proxy Xabia Cloud): **`gpt-4o-mini`** vía `call_openai()`.

El **chat principal** sigue usando `gpt-4o` (OpenAI/proxy) o Vertex Flash según `ai_driver`; los auxiliares ya no saltan a OpenAI cuando el proyecto es Vertex local.

#### 4.12.4 Frontend

`frontend/widgets/chatbox.js` — **`appendBotMessage()`** pinta la respuesta completa al instante (`renderBotHtml`); se eliminó el typewriter post-respuesta. Los **typing dots** durante la espera AJAX se mantienen.

---

## 5. Ingestión (sync)

`Xabia_Admin::handle_sync_content_ajax()` (y variantes según `source_type`):

- **multi:** borra vectores del `project_id`, itera `sources[]` con `process_csv_knowledge` / `process_sql_knowledge` y mapeo por fuente.
- **sql:** `process_sql_knowledge` con resolución de `{prefix}` (manual, remoto → detección de prefijo en admin, o local `wpdb->prefix`).
- **csv:** lectura CSV con separador y cabeceras; `insert_record` por fila.

`insert_record()` construye `content_chunk` desde `prepare_node_data()`: pares `label (+ instrucción): valor` unidos; detecta ente por ENTE / rol título / primera columna.

**Entrenar:** recorre filas sin `vector_data`, genera embedding con el proveedor del proyecto, guarda JSON en `vector_data`.

### 5.1 Ingestión documental agnóstica (Document-to-RAG)

Motor framework-agnostic para ficheros locales (MD, JSON, CSV, TXT) sin acoplar a WordPress:

| Archivo | Rol |
|---------|-----|
| `core/interface-xabia-database.php` | Contrato `delete` / `insert` / `query` |
| `core/class-xabia-wp-db-adapter.php` | Adaptador `$wpdb` |
| `core/class-xabia-pdo-db-adapter.php` | Adaptador PDO (Standalone/Lite) |
| `core/class-xabia-document-ingest.php` | Scan, parse, ingest a `knowledge_vectors` |
| `core/class-xabia-document-ingest-bridge.php` | Factory WP (`uploads/xabia-knowledge/`) |

- Ingest borra vectores y **`xabia_response_cache`** del `project_id` antes de reinsertar.
- Hook opcional: **`xabia_knowledge_sync_enrich_row`** (solo si `function_exists('apply_filters')`).
- Los chunks insertados llevan `vector_data = null` hasta **Entrenar** (`Xabia_Knowledge_Train`).
- **No** está en el hot path del chat; afecta latencia solo indirectamente (repoblación de caché tras ingest).

---

## 6. Filtros y extensiones (selección)

| Filtro | Uso |
|--------|-----|
| `xabia_system_prompt_rules` | Ajustar texto del intérprete (`interpreter`), comportamiento RAG (`rag_behavior`), etc. |
| `xabia_system_rag_behavior` | Última capa sobre el bloque RAG antes de inyectarlo al prompt (`$text`, `$project_id`, `$config`, `$response_mode`). |
| `xabia_i18n_current_language` | Filtrar el código ISO corto devuelto por `Xabia_I18n_Bridge::get_current_language()`. |
| `xabia_i18n_translate_agent_greeting` | Traducir saludo del agente cuando no hay WPML/Polylang (fallback). |
| `xabia_rag_behavior_presets` | Sustituir o ampliar los presets base `neutral` y `compact`. |
| `xabia_register_sql_sources` | Registrar fuentes tipo addon para el desplegable SQL. |
| `xabia_addon_sync_result` | Sincronización custom (p. ej. Central) devolviendo `['count' => n]`. |
| `xabia_knowledge_sync_enrich_row` | Enriquecer una fila antes de insertar en conocimiento. |
| `xabia_wp_schema_post_types` / `xabia_wp_schema_for_post_type` | Tipos y meta virtuales (Amelia, etc.). |
| `xabia_chat_addon_discovery_blocks` | Permite a addons añadir texto al contexto del chat (p. ej. catálogo Amelia). |
| `xabia_catalog_post_type` | CPT del catálogo si no se infiere del mapeo. |
| `xabia_catalog_entity_meta_keys` | Meta keys de título/ubicación/slug del ente. |
| `xabia_catalog_contact_meta_keys` | Meta keys de contacto resueltas. |
| `xabia_catalog_image_meta_keys` | Meta keys de imagen (foto). |
| `xabia_catalog_logotipo_meta_keys` | Meta keys de logotipo. |
| `xabia_context_source_schemas` | Meta-esquemas auto-descriptivos por fuente (`[FUENTE]`, `[DESCRIPCIÓN]`, `[DATOS]`). Bypass dev: clave `xabia_dev_lock` en un esquema para no sobrescribir la descripción del admin. |
| `xabia_semantic_context_data_blocks` | Bloques de contexto adicionales serializados por conectores (Woo, MEC, Avirato…). |
| `xabia_context_privacy_sanitize` | Sanitización final del contexto antes de envío al LLM (PII Shield). |
| `xabia_context_privacy_commerce_line_patterns` | Patrones de líneas privadas de comercio/reservas a eliminar del contexto. |
| `xabia_context_privacy_is_catalog_block` | Marca bloques de contexto como catálogo público (preserva contacto comercial). |
| `xabia_entity_name_reject_patterns` | Patrones para rechazar candidatos a nombre de ente en extracción del historial. |
| `xabia_rag_catalog_activity_profiles` | Perfiles de actividad para filtrado RAG (opcional; extensible por vertical). |
| `xabia_response_cache_version` | Versión lógica incluida en el hash de caché de respuestas (invalidar al cambiar comportamiento). |
| `xabia_query_embedding_cache_ttl` | TTL en segundos de transients de embedding de consulta (default 30 días). |
| `xabia_knowledge_sync_enrich_row` | Enriquecer fila al ingestar documentos locales. |
| `xabia_document_ingest_base_dir` | Ruta base del directorio de conocimiento (Standalone). |

---

## 7. Frontend (chatbox + interfaz nativa)

### 7.1 Chatbox (shortcode)

- **PHP:** `frontend/widgets/chatbox.php` — `shortcode_xabia_agent_renderer` calcula scope, modo estricto, saludo, datos del proyecto, atributos `data-*`.
- **JS:** `frontend/widgets/chatbox.js` — `$.post(admin-ajax.php, { action: 'xabia_ask_ai', … })` sin nonce obligatorio; envía **`lang`**, **`user_lang`**, `history` opcional, `item` en modo estricto. Desde **v1.0.168** la respuesta del bot se muestra al completo en cuanto llega el JSON (sin animación typewriter). Desde **v1.0.201**, `renderBotHtml()` convierte Markdown básico (`**…**`, viñetas) a HTML seguro antes de insertarlo.
- **Acciones:** Regex en cliente para CALL, URL, IMG, MAP, CART, BOOK según integraciones.
- **Starter questions:** `core/class-xabia-starter-questions.php` + `data-starter-questions` en el chatbox.
- **Shortcode focus:** los chatboxes embebidos no nativos pueden activar `xabia-chatbox-shortcode-focus` al enfocar el input, crear `#xabia-shortcode-focus-overlay`, cerrar con clic fuera, Escape o botón móvil, y mostrar halo luminoso desde `frontend/widgets/styles.css`.
- **UI stream (v1.0.197+):** `styles.css` — sin burbujas, esquinas cuadradas, input/controles con hover reveal.

### 7.2 Interfaz nativa (`Xabia_Interface`, v1.0.57)

- **Clase:** `frontend/class-xabia-interface.php`; bootstrap en `xabia-intelligence.php` (`plugins_loaded`, solo front).
- **Persistencia:** `xabia_projects_config[project_id]['interface']` + `paused` (1 = oculto en front). Migración desde opciones globales legacy en `get_project_settings()`.
- **Admin:** pestaña Apariencia → `Xabia_Interface::render_admin_fields()`; listado → acción `toggle_pause` con nonce. Los bloques de trigger/avatar/páginas solo se muestran cuando `xabia_autoload_without_shortcode` está activo.
- **Front:** `wp_footer` renderiza disparador por `data-project` solo en modo nativo. El shortcode por sí mismo no registra el proyecto para avatar flotante.
- **Visibilidad nativa:** `should_render_for_project($id)` — “mostrar solo en estas páginas”, exclusiones por post type / ID, Woo cart/checkout; filtros `xabia_interface_force_hide`, `xabia_interface_should_render`.
- **Assets SVG (v1.0.29):** `frontend/assets/svg/back-circle-blue.svg`, `dos-circulos-WH.svg`, `xabia-dots.svg` — render con `<img>` (ojos multicolor, sin máscaras CSS).
- **JS:** `frontend/assets/js/xabia-interface.js` — GSAP (mirada, parpadeo, sombra); scroll hide/show; `IntersectionObserver` para anclaje al footer; `body.xabia-open` + overlay blur; `shouldUseImmersive()` respeta `speaking_avatar` aunque `data-voice=0` (mute ≠ salir del parlante, ≥ 1.0.200).
- **Lanzador:** shortcodes `[xabia_launcher]` / `[xabia_avatar]` (`render_trigger_markup` inline); tamaños `sm|md|lg|xl` o px.
- **CSS:** `frontend/assets/css/xabia-interface.css` — wrapper `.xabia-sticky-footer-box`, panel layouts, telón, overrides `xabia-immersive-mode`.

### 7.3 Addons remotos por SQL

- **Woo:** `source_type = addon`, `addon_slug = woo`. Remoto si hay `sql_config.host` o URL de tienda remota **o** no existe WooCommerce local (`xabia_woo_is_remote_catalog`, ≥ 1.0.163). Deep schema incluye metas `_price`, `_sku`, etc. La URL `rules.woo_remote_shop_url` permite `?add-to-cart=ID`.
- **MEC:** mismo patrón con `xabia_mec_is_remote_catalog` (host SQL / URL remota prioritarios). Deep schema remoto incluye `mec_available_slots` y etiquetas `mec_*`.
- **SQL puro:** el Core mantiene `sql_preset = mec_remote` como alternativa cuando no se instala el addon MEC en el sitio del chat.

### 7.4 Asistente CPT — aislamiento de fuente (Core ≥ 1.0.162)

`ajax_get_wp_schema` → `scope_config_for_source_discovery` → `discover_cpt_assistant_types()`:

| `source_type` | Comportamiento |
|---------------|----------------|
| `sql` | `DISTINCT post_type` remoto; **sin** fallback a CPT locales |
| `local_sql` | `$wpdb->posts` local |
| `multi` | Resuelve por `source_index` |
| `addon` | Solo CPT nativos del addon (+ validación remota opcional) |

UI: `#xabia-cpt-assistant-source-hint` con `ui_hint`.

---

## 8. Admin (UI)

- Vista única `xabia-settings`: listado + edición con pestañas; assets solo si el hook de pantalla contiene `xabia-settings`.
- Estilos acotados a `.xabia-wrapper.xabia-admin-app`.
- AJAX admin: `check_ajax_referer('xabia_admin_nonce', 'nonce')` y **`admin_json_success()`** que añade `nonce` nuevo en respuestas exitosas para cadena en el cliente (`xabiaSyncNonce`).
- **Sidebar del editor (≥ 1.0.165):** `.xabia-edit-layout .xabia-sidebar` usa `position: static` (scroll de página normal; ya no sticky).
---

## 9. Empaquetado y dependencias

- **`scripts/build-retail-plugin-zips.sh`** genera `xabia-agent-plugins/dist/retail/xabia-agent-core-<versión>-retail.zip` (incluye `vendor/` si existe en `xabia-agent-plugins/packages/xabia-agent-core/`, más memoria y manuales copiados) y `xabia-agent-plugins/dist/retail/xabia-avirato-<versión>-retail.zip`.
- **`scripts/build-plugin-zip.sh`** genera ZIPs mínimos en **`xabia-agent-plugins/dist/`** desde **`xabia-agent-plugins/packages/`** (sin copiar documentación extra desde la raíz del repo).
- En destino, si el ZIP no trae `vendor/` y se usa Vertex, puede ser necesario `composer require google/auth` en la carpeta del plugin o junto al JSON (ver MEMORIA §13.4).

---

## 10. Pruebas y depuración

- **Playground:** Verifica RAG + LLM con el mismo código que producción.
- **Logs:** Tabla `xabia_logs` para preguntas/respuestas recientes.
- **Federación:** Script CLI `test-xabia-federation.php` (condiciones de seguridad en el propio script).
- **Catálogo nativo:** En Playground, pregunte «empresas de [actividad]» y compruebe `rag_debug`: `catálogo nativo WP: sí`, `finish_reason: native_catalog`. Seguimiento «contacto de la última» → `manifest_count` > 0, `search_term` con nombre de ente.

---

## 11. Catálogo nativo agnóstico y pasaporte de ente (Core ≥ 1.0.118)

Módulo principal: **`core/class-xabia-catalog-list.php`** + resolvers en **`core/class-xabia-knowledge-ingest.php`** + orquestación en **`api/class-xabia-api.php`**.

### 11.1 Principios de diseño

| Principio | Implementación |
|-----------|----------------|
| **Agnóstico de vertical** | Ningún CPT ni meta key fijo en código de producto. Todo sale de `xabia_projects_config[project_id].attributes` (o `sources[].attributes` en multi-fuente). |
| **Velocidad** | Listados masivos: una query WP + taxonomía; **0 tokens**, **0 Hub**, **0 LLM**. |
| **Separación listado / profundidad** | Listado = muchas filas ligeras. Seguimiento = **pasaporte nativo** por `post_id` (contacto, imágenes, taxonomía, texto RAG local opcional). |
| **Sesión + historial POST** | El chatbox envía `history` en cada AJAX. Si `$_SESSION` no persiste entre peticiones, el **manifiesto se reconstruye** parseando la última respuesta con viñetas (`parse_catalog_manifest_lines_from_history`). |
| **Extensible** | Filtros `xabia_catalog_post_type`, `xabia_catalog_entity_meta_keys`, `xabia_catalog_contact_meta_keys`, `xabia_catalog_image_meta_keys`, `xabia_catalog_logotipo_meta_keys`, `xabia_catalog_*_visual_roles`. |

### 11.2 Resolución agnóstica desde el mapeo (`Xabia_Knowledge_Ingest`)

Métodos públicos usados por listados y pasaporte:

| Método | Rol / criterio |
|--------|----------------|
| `resolve_catalog_post_type($config)` | CPT del ente: `is_ente`, addon, SQL del proyecto, filtro. |
| `resolve_catalog_activity_taxonomy($config)` | Taxonomía de actividad/categoría (p. ej. perfil náutico → término padre + hijos). |
| `resolve_catalog_entity_meta_keys($config)` | Título, slug, ubicación: `is_ente`, `visual_role` `title`/`map`, etiquetas genéricas. |
| `resolve_catalog_contact_meta_keys($config)` | Roles `tel`, `web`, `email`, `contact` + etiquetas (teléfono, email, web…). |
| `resolve_catalog_image_meta_keys($config)` | Roles `img`, `image`, `photo`, `thumbnail` — **fotos/galería**, no logotipo. |
| `resolve_catalog_logotipo_meta_keys($config)` | Rol **`logotipo`** — marca corporativa separada de fotos. |

**Prohibido en producto:** fallbacks hardcodeados del tipo `empresa_tel`, `empresa_img_01`. Cada sitio debe marcar roles en el admin.

### 11.3 Manifiesto de listado y referencias ordinales

Tras un listado nativo, cada línea del manifiesto interno tiene forma `**Nombre** (localidad)`.

**Resolución de entidad en seguimientos** (`Xabia_API`):

- Nombre explícito en el mensaje (`resolve_named_entity_from_user_message`).
- Coincidencia parcial contra manifiesto (`resolve_entity_from_catalog_manifest`).
- **Ordinales:** «la última», «la primera», «la 3ª», typos (`úlrima` → `última`) → `resolve_entity_ordinal_from_manifest`.
- Historial POST (`resolve_entity_from_conversation_history`).
- Utilidad sin nombre («el teléfono», «y alguna imagen») → `last_entity` o turno anterior del historial.

Funciones clave: `catalog_manifest_lines`, `ensure_catalog_manifest_from_history`, `sanitize_persisted_entity_reference`.

### 11.4 Pasaporte nativo (`fetch_entity_passport_context`)

Cuando hay entidad focal y **no** es listado masivo **y** la fuente **no** es remota:

1. Resuelve `post_id` por título/slug/manifiesto.
2. Lee metas en vivo con `get_post_meta` (roles del mapeo: tel, email, img, logotipo…).
3. Construye bloque de ficha e inyecta líneas `imagen:` / `logotipo:` para `[ACTION:IMG:…]`.

Si `Xabia_Knowledge_Sync::is_remote_config($config)` o hay `sql_config.host` → **return ''** (Core ≥ 1.0.164). El contacto remoto vive en el `content_chunk` enriquecido en sync.

### 11.4.1 Anexo dinámico en sync remoto (`append_mapped_attributes_annex`)

`build_passport_chunk($row, $mapping, ['project_id' => …])`:

1. Cabecera estructural del pasaporte (`$used_cols` = columnas ya consumidas).
2. Si la config es remota → `\n---\n` + título filtrable + líneas `- {label}: {valor}` por atributos mapeados con valor (excluye roles imagen, IDs, columnas usadas).
3. **Sin hardcode** de claves de cliente. Filtros: `xabia_knowledge_passport_annex_heading`, `xabia_knowledge_passport_annex_lines`, `xabia_knowledge_passport_annex_exclude_column`.

Tras cambiar mapeo o actualizar a 1.0.164+: **Sincronizar** + push Hub.

### 11.5 Cuándo interviene RAG/Hub vs nativo

| Escenario | Camino |
|-----------|--------|
| Listado (modo lista) | Catálogo compacto / contexto **sin** anexo `---` |
| Contacto / ficha (modo desarrollo) | Chunk completo con anexo (remoto) o pasaporte nativo (local) |
| Pregunta abierta semántica | RAG vectorial/LIKE (+ anexo si el chunk lo trae) |
| Hub incompleto vs WP local | Listado nativo local **no depende** del Hub |

### 11.6 Depuración (Playground)

El payload `rag_debug` incluye, entre otros:

- `native_catalog`, `native_catalog_matches` (cuando aplique)
- `search_term`, `context_empresa_count`, `manifest_count`
- `finish_reason: native_catalog` en listados atajados
- En remoto: verificar en Hub que `content_chunk` contiene `\n---` tras sync

## Notas operativas recientes (julio 2026)

- Rebuild Core: `ONLY_SLUG=xabia-agent-core ./scripts/build-plugin-zip.sh` → `dist/xabia-agent-core-1.0.166.zip`
- Addons: `ONLY_SLUG=xabia-mec` / `xabia-woo` → MEC **1.0.3**, Woo **1.0.4**
- Manuales: `./scripts/build-modular-manuals-pdf.sh` → subir a `xabia.ai/docs/`
- Hub: desplegar `central-api` / `hub-real` con compact de anexos (`---` en listados) alineado al Core

(Se conservan notas Hub multi-dominio / licencia / Avirato de abril 2026 en historial git si se necesitan.)

---

## 12. Filtros, Hooks y Ajustes Avanzados para Desarrolladores

> Contenido **retirado de los manuales de usuario** (Core / MEC / Woo) para mantener guías 100 % no-code. Complementa la selección de filtros de [§6](#6-filtros-y-extensiones-selección) y el esquema de [§3.1](#31-tabla-wp_xabia_knowledge_vectors-prefijo-configurable).

### 12.1 Tabla de conocimiento (`wp_xabia_knowledge_vectors`)

La ingestión CSV/SQL/addon/multi-fuente converge en `Xabia_DB_Bridge` → tabla `{prefix}xabia_knowledge_vectors` (texto + JSON meta + vectores opcionales). Columnas y semántica: **§3.1** de esta guía. Los manuales de usuario **no** deben documentar el esquema interno.

### 12.2 Licencias y firma HMAC-SHA256 (Hub)

Las llamadas del plugin al proxy central se firman con la licencia guardada. El cliente solo pega la licencia en el dashboard; **no** necesita editar `wp-config.php`: esa clave actúa como secreto HMAC dinámico.

**Firma de proxy / chat hacia el Hub:**

```text
HMAC-SHA256(license_key + source_url + timestamp + body, license_key)
```

El hub busca primero la licencia en `xabia_licenses` (clave + dominio) y valida la firma con esa misma licencia.

**Firma en recargas de tokens** (evita que un tercero se añada saldo):

```php
$signature = hash_hmac('sha256', $license_key . $token_amount . $timestamp, $license_key);
```

Buenas prácticas: proteger la licencia, HTTPS, no exponer logs públicos, permisos de administrador acotados.

### 12.3 Addon MEC — filtros

| Filtro / acción | Efecto |
|-----------------|--------|
| `xabia_mec_require_hub_subscription` | Por defecto exige **Hub** para funciones premium; devolver `false` desactiva el requisito (solo entornos de prueba). |
| `xabia_mec_event_reservation_url` | Cambia la URL del botón de reserva (p. ej. otra ancla que no sea `#book`). |
| `xabia_mec_is_booking_enabled` | Fuerza manualmente si un evento «tiene reserva» para mostrar `ACTION:BOOK`. |
| `xabia_addons_registry` | Permite sustituir URLs o metadatos del catálogo Polar. |

### 12.4 Addon Woo — filtros

| Filtro | Efecto |
|--------|--------|
| `xabia_woo_require_hub_subscription` | Por defecto exige Hub; devolver `false` omite el gate (solo pruebas). |

### 12.5 Catálogo / pasaporte remoto (señales de depuración)

Tras un listado en Playground (modo desarrollador), `rag_debug` puede incluir `native_catalog`, `native_catalog_matches`, `search_term`, `manifest_count`, `finish_reason: native_catalog`. En remoto, verificar en Hub que `content_chunk` contiene el delimitador `\n---` tras sync (anexo de atributos mapeados). Ver también **§11** de esta guía.

---

## 13. Referencias cruzadas

| Tema | Documento |
|------|-----------|
| Catálogo / pasaporte / listados (usuario) | [manual-usuario-xabia-core.md §11](./manual-usuario-xabia-core.md) |
| Despliegue Core 1.0.168 | [DESPLIEGUE_PRODUCCION_CORE.md](./DESPLIEGUE_PRODUCCION_CORE.md) |
| Endpoints AJAX completos | [MEMORIA §7](../../MEMORIA_TECNICA.md#7-apis-y-endpoints) |
| Vertex y cuentas de servicio | [MEMORIA §13](../../MEMORIA_TECNICA.md#13-google-cloud--vertex-ai) |
| Panel admin (DOM, CSS) | [MEMORIA §14](../../MEMORIA_TECNICA.md#14-panel-de-administración-desarrollo-y-diseño) |
| Planes federación / producto | [Índice documentación](./README.md) |

---

## Notas de versión recientes

### Core v1.0.201
- **Markdown en chat:** `renderBotHtml` en `chatbox.js`.
- **UI:** stream sin burbujas; launcher / avatar parlante; starter questions en el ZIP.
- **Empaquetado:** incluye `class-xabia-starter-questions.php` y `avatar-svg.php`.

### Core v1.0.200
- Immersive parlante desacoplado del mute TTS (`shouldUseImmersive`).

### Core v1.0.197–1.0.199
- Chrome del chat; fix ZIP incompleto (1.0.199).

### Core v1.0.168
- **Latencia chat:** caché de respuesta pre-router; caché de embeddings; frontend sin typewriter.
- **Vertex:** auxiliares alineados con el chat; Document-to-RAG agnóstico.

### Core v1.0.166
- Documentación alineada; anexo/listados agnósticos; sidebar admin sin sticky.

### Core v1.0.164–1.0.165
- Anexo de atributos mapeados; list mode strip.

### Core v1.0.162–1.0.163
- Asistente CPT por fuente; MEC/Woo remoto + deep schema.

*Última actualización: agosto 2026 — Core **v1.0.208**; manuales en https://xabia.ai/docs/.*