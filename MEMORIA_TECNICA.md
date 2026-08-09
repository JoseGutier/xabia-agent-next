# MEMORIA TÉCNICA - XABIA AGENT NEXT

**Versión del producto:** **v1.0.0** (lanzamiento comercial inicial)  
**Fecha:** Abril 2026  
**Autor:** Xabia Intelligence Center  
**Última revisión:** Documentación alineada a **v1.0.168** (julio 2026). Histórico: **v1.0.166** (CPT por fuente, anexo remoto); **v1.0.57**; arquitectura modular **`addons/`**, idioma políglota (`lang`/`user_lang` como fallback/UI), shortcode embebido con halo/overlay, MEC remoto por addon/SQL, consola de curación CPT (modal admin, `mapping_hints`, badge «Con datos»), motor `xabia_discover_cpt_fields` y `xabia_get_deep_schema`. Referencias: [§17](#17-documentación-complementaria-manual-y-desarrollo), Playground [§7.1](#71-endpoints-ajax-admin), ZIP [§16](#16-distribución-paquete-zip-e-instalación-en-wordpress), panel [§14](#14-panel-de-administración-desarrollo-y-diseño).

---

## ÍNDICE

1. [Introducción](#1-introducción)
2. [Arquitectura General](#2-arquitectura-general)
3. [Estructura de Archivos](#3-estructura-de-archivos)
4. [Base de Datos](#4-base-de-datos)
5. [Componentes Principales](#5-componentes-principales)
6. [Flujo de Funcionamiento](#6-flujo-de-funcionamiento)
7. [APIs y Endpoints](#7-apis-y-endpoints)
8. [Sistema de Integraciones](#8-sistema-de-integraciones)
9. [Frontend y Widgets](#9-frontend-y-widgets)
10. [Imágenes y uploads estándar](#10-imágenes-y-uploads-estándar)
11. [Configuración y Opciones](#11-configuración-y-opciones)
12. [Changelog / Cambios recientes](#12-changelog--cambios-recientes)
13. [Google Cloud / Vertex AI](#13-google-cloud--vertex-ai)
14. [Panel de administración (desarrollo y diseño)](#14-panel-de-administración-desarrollo-y-diseño)
15. [Documentación para colaboradores (GitHub)](#15-documentación-para-colaboradores-github)
16. [Distribución, paquete ZIP e instalación en WordPress](#16-distribución-paquete-zip-e-instalación-en-wordpress)
17. [Documentación complementaria (manual y desarrollo)](#17-documentación-complementaria-manual-y-desarrollo)

---

## 1. INTRODUCCIÓN

**Xabia Agent Next** es un plugin de WordPress que implementa un sistema RAG (Retrieval-Augmented Generation) para crear asistentes de IA conversacionales con memoria persistente y capacidad de aprendizaje desde múltiples fuentes de datos.

### Características Principales

- **Motor RAG**: Búsqueda semántica en base de conocimiento vectorial
- **Múltiples Fuentes**: CSV, SQL local, SQL remoto, Addons nativos
- **Multi-fuente por proyecto**: Un mismo agente puede combinar varias fuentes (p. ej. CPT/DB + CSV) con **mapeos de atributos distintos por fuente**; todos los datos se escriben en la misma tabla de vectores del proyecto. Ver [§5.4](#54-multi-fuente-por-proyecto).
- **Fuente CSV**: Persistencia del archivo seleccionado; selector (dropdown) de archivos en `uploads/xabia/` además de subida
- **Motores de IA**: OpenAI GPT-4 y Google Cloud Vertex AI (Gemini 2.0)
- **Modo Museo/QR**: Filtrado estricto por ente (`?item=ID` o `ente="ID"` en shortcode), respuesta en primera persona
- **Modo Tótem**: Reinicio automático por inactividad (minutos configurables) para terminales físicos; limpieza de sesión en servidor y UI
- **Sistema de Entes**: Identificación automática (prioridad: campo ENTE → role título → primera columna)
- **Voz y Acciones**: Síntesis de voz y acciones visuales (llamadas, enlaces, imágenes); imágenes resueltas en **wp-content/uploads** (rutas relativas o por nombre, resolución por lote y caché)
- **Memoria de Sesión**: Contexto conversacional persistente; endpoint para limpiar sesión (`xabia_clear_session`)
- **Sistema de Addons**: Arquitectura extensible para integraciones (WooCommerce, MEC, Amelia, QR/POI, Xabia Central, etc.)
- **WooCommerce**: Tag `[ACTION:CART:ID]` en el chat → botón que añade al carrito vía AJAX (`xabia_woo_add_to_cart`); reglas RAG opcionales vía filtro `xabia_system_prompt_rules`
- **Reservas (MEC / Amelia)**: Clase `Xabia_Reservas_Handler`; tag `[ACTION:BOOK:ID]` (expandido en API a variantes MEC/Amelia); disponibilidad comprobable con `check_availability()`; mapeo y buscador de servicios Amelia en admin
- **QR / tótems (Smart QR, Core):** túnel por `ente_id`, URLs `?xqr=` / `?xid=`, generador admin, ruta `/xabia-box/` — módulos `core/class-xabia-smart-qr.php` + `addons/xabia-qr/` (bundled, sin licencia addon); temporizador de inactividad y reset de sesión en el widget
- **Admin**: Pantalla única bajo el menú **Xabia Agent** (`admin.php?page=xabia-settings`): listado de proyectos, formulario de claves globales, editor por pestañas (Datos / Diseño / Historial), sincronización, entrenamiento, playground y borrado de memoria vectorial. Estilos en `admin/css/xabia-admin.css` (tarjetas blancas, acentos verde y magenta); ver [§14](#14-panel-de-administración-desarrollo-y-diseño).

---

## 2. ARQUITECTURA GENERAL

### 2.1 Patrón de Diseño

El plugin sigue una arquitectura modular con separación de responsabilidades:

```
┌─────────────────────────────────────────┐
│         WORDPRESS HOOKS                 │
└─────────────────────────────────────────┘
                    │
        ┌───────────┴───────────┐
        │                       │
┌───────▼────────┐      ┌───────▼────────┐
│   ADMIN UI     │      │   FRONTEND      │
│  (Backend)     │      │  (Shortcode)   │
└───────┬────────┘      └───────┬────────┘
        │                       │
        └───────────┬───────────┘
                    │
        ┌───────────▼───────────┐
        │      API LAYER         │
        │  (AJAX Handlers)       │
        └───────────┬───────────┘
                    │
        ┌───────────▼───────────┐
        │      BRAIN ENGINE      │
        │   (Search/RAG)         │
        └───────────┬───────────┘
                    │
        ┌───────────▼───────────┐
        │    DB BRIDGE           │
        │  (Ingestion Layer)     │
        └───────────┬───────────┘
                    │
        ┌───────────▼───────────┐
        │   DATABASE             │
        │  (Vectors + Logs)      │
        └───────────────────────┘
```

### 2.2 Flujo de Datos

1. **Ingestión**: CSV/SQL (o varias fuentes en modo multi-fuente) → `Xabia_DB_Bridge` → Tabla `xabia_knowledge_vectors`. En multi-fuente, cada fuente usa su propio mapeo de atributos; el resultado se vuelca en la misma tabla por `project_id`.
2. **Búsqueda**: Query usuario → `Xabia_Brain` → Contexto relevante
3. **Generación**: Contexto + Prompt → `Xabia_API` → LLM (OpenAI/Google)
4. **Respuesta**: LLM → Frontend → Usuario

---

## 3. ESTRUCTURA DE ARCHIVOS

### 3.1 Archivo Principal

**`xabia-intelligence.php`**
- Punto de entrada del plugin
- Define constantes globales (`XABIA_PATH`, `XABIA_URL`, **`XABIA_VERSION`** — debe coincidir con la cabecera `Version:` del plugin; versión oficial actual **1.0.0**)
- Inicializa sesiones PHP para memoria conversacional
- Carga todos los módulos core
- Registra hooks de activación y shortcodes

### 3.2 Core (`/core/`)

#### `class-xabia-db.php`
**Responsabilidad**: Gestión de esquema de base de datos

**Métodos principales**:
- `init()`: Inicializa la instalación de tablas
- `install_tables()`: Crea tablas `xabia_knowledge_vectors` y `xabia_logs`

#### `class-xabia-brain.php`
**Responsabilidad**: Motor de búsqueda RAG (Retrieval)

**Métodos principales**:
- `search_knowledge($project_id, $query, $scope, $strict_ente)`: Búsqueda por LIKE sobre `content_chunk`
- `search_knowledge_vector(..., $query_vector = null)`: Búsqueda vectorial (similitud coseno). Si se pasa `$query_vector` (p. ej. desde la API con Vertex/OpenAI según proyecto), lo usa; si no, genera embedding con OpenAI (`get_embedding`). Ver [§13.7](#137-proveedor-único-por-proyecto-openai-vs-vertex).
- `get_embedding($text)`: Genera embedding con OpenAI (usado cuando el proyecto no es Google Cloud)

#### `class-xabia-db-bridge.php`
**Responsabilidad**: Ingestión de datos desde múltiples fuentes (Adapter Pattern: cualquier fuente → array → limpieza → tabla de vectores).

**Métodos principales**:
- `process_csv_knowledge($project_id, $file_path, $attributes_override = null)`: Procesa archivos CSV. Si se pasa `$attributes_override` (array de mapeo), se usa en lugar del mapeo del proyecto; permite multi-fuente con mapeos distintos por fuente.
- `process_sql_knowledge($project_id, $sql_config, $attributes_override = null)`: Procesa consultas SQL (local o remoto, con reemplazo de `{prefix}`). Opcionalmente recibe `$attributes_override` para esa fuente.
- `insert_record($project_id, $row, $mapping, $table_target)`: Inserta registro normalizado (privado). El mapeo determina qué columnas entran en `content_chunk` y `meta_data`, y la detección de ente.

### 3.3 API (`/api/`)

#### `class-xabia-api.php`
**Responsabilidad**: Motor de generación y comunicación con LLMs; limpieza de sesión (Modo Tótem); resolución de imágenes en uploads estándar.

**Métodos principales**:
- `handle_chat_request()`: Handler AJAX principal (chat y **Playground** admin); validación de nonce dual (`xabia_nonce` o `xabia_admin_nonce` + `manage_options`); soporta `item` para modo estricto y persona; calcula `$had_knowledge_rows` antes de sustituir el contexto vacío por `SYSTEM_NOTE` e inyecta en `build_system_prompt()` un bloque **SIN DATOS DE CATÁLOGO** cuando no hubo filas recuperadas, para reducir alucinaciones de listados
- `handle_clear_session()`: Limpia `xabia_chat_history` y `xabia_last_search` para un `project_id` (uso en Modo Tótem)
- `handle_resolve_image()`: Resuelve rutas de imagen (una o varias) a URLs en `wp-content/uploads`; ver [§10 Imágenes y uploads estándar](#10-imágenes-y-uploads-estándar)
- `sanitize_image_path()` (privado): Sanitiza path (sin `..`, sin URL absoluta)
- `resolve_image_paths_batch()` (privado): Resolución por lote; una query para todos los “solo nombre de archivo”
- `expand_user_query_generic(..., $config)`: Router (Intérprete): expande la pregunta a términos de búsqueda; usa **el mismo proveedor que el chat** (OpenAI o Vertex) según `$config['ai_driver']`. Ver [§13.7](#137-proveedor-único-por-proyecto-openai-vs-vertex).
- `build_system_prompt(..., $has_catalog_rows = true)`: Construye el prompt del sistema; inyecta persona (primera persona) y reglas modo QR si hay ente; si `$has_catalog_rows === false`, añade bloque **SIN DATOS DE CATÁLOGO** (prohibición de inventar empresas o usar conocimiento general)
- `get_ente_display_name()`: Obtiene el nombre "humano" del ente desde `meta_data` para saludos y prompt
- `call_openai()`: Llama a la API de OpenAI
- `call_google_vertex(..., $wp_project_id, $enable_vertex_federation_tools)`: Vertex **Gemini 2.5 Flash**. Sin federación o sin nodos: un turno `user` con historial concatenado (incl. Intérprete). Con nodos amigos y federación activa en chat: **`generateContent`** con **`functionDeclarations`** (`ask_federated_node`), bucle **`functionCall`** → **`xabia_federation_ask_node`** / **`Xabia_Federation_Nexus::ask_federated_node`** → **`functionResponse`** (máximo **3** iteraciones). Con **licencia Xabia** y sin JSON local (`should_use_openai_proxy`), el mismo bucle usa **`call_openai_with_federation_tools`** → hub: **`tool_calls`** en formato OpenAI y nuevas peticiones con mensajes **`tool`**. Helpers privados: `vertex_openai_tools_to_declarations`, `vertex_build_gemini_body_from_openai_messages`, `call_google_vertex_federation_tool_loop`, etc.
- `get_query_embedding($text, $config)`: Genera el embedding de la query según `ai_driver`: Vertex (`gemini-embedding-001`) o OpenAI (`text-embedding-3-small`). Usado en búsqueda vectorial y por Admin al entrenar.
- `get_embedding_for_project($text, $project_id)`: Wrapper público para Admin; obtiene el embedding según la config del proyecto (Vertex u OpenAI).
- `get_google_vertex_auth($config)` (privado): Token y proyecto para Vertex; reutilizado por chat y embeddings.
- `get_vertex_embedding($text, $config)` (privado): Llama a Vertex AI `gemini-embedding-001:predict`; devuelve el vector.
- `resolve_action_img_ids_in_response($response)` (privado): Sustituye `[ACTION:IMG:attachment_id]` por URL antes de enviar la respuesta al cliente.
- `resolve_action_book_tags_in_response($response, $project_id)` (privado): Enriquece `[ACTION:BOOK:ID]` según el motor de reservas del proyecto (`engine_for_project`): MEC → permalink en Base64 URL-safe (`[ACTION:BOOK:mec:ID:…]`); Amelia → `[ACTION:BOOK:amelia:ID]`. Ver [§8](#8-sistema-de-integraciones) (Reservas).

### 3.4 Admin (`/admin/`)

| Archivo | Rol |
|---------|-----|
| `class-xabia-admin.php` | Lógica, vistas PHP, JavaScript inline del editor y playground |
| `css/xabia-admin.css` | Sistema visual del panel (encolado solo en la pantalla del plugin) |

#### `class-xabia-admin.php`
**Responsabilidad**: Interfaz de administración y gestión de proyectos

**Pantalla y hook**:
- **Slug de menú / página**: `xabia-settings` → URL `admin.php?page=xabia-settings`
- **Hook de carga de scripts**: `admin_enqueue_scripts`; los assets solo se cargan si `$hook` contiene `xabia-settings` (p. ej. `toplevel_page_xabia-settings`)

**Métodos principales**:
- `init()`: Registra hooks y acciones AJAX (incl. `xabia_list_csv_files`, `xabia_clear_memory`)
- `register_menu()`: Menú "Xabia Agent" (`add_menu_page`)
- `load_assets()`: Encola `dashicons`, `wp-color-picker` y **`xabia-admin`** (`XABIA_URL . 'admin/css/xabia-admin.css'`, versión `XABIA_VERSION`). La hoja de estilos del plugin declara dependencia de `wp-color-picker` para que las reglas de botones y layout queden **después** de los estilos del picker y no queden pisadas.
- `render_view()`: Renderiza la interfaz (listado o edición según `$_GET['edit']`); en edición: pestañas Datos, Diseño, Historial; formulario con `enctype="multipart/form-data"` para CSV; bloque lateral (memoria + playground) si el proyecto ya existe (`edit !== 'new'`).
- `controller_handle_post()`: Guarda proyecto; subida CSV a `uploads/xabia/`; guarda `csv_filename`, `totem` (modo_totem, tiempo_inactividad_defecto)
- `controller_handle_list_actions()`: Borrado de proyecto
- `handle_sync_content_ajax()`: Sincroniza contenido. Si `source_type` = `multi` y existe `sources`, borra vectores del proyecto y procesa cada fuente con su mapeo (`process_csv_knowledge` / `process_sql_knowledge` con `$attributes_override`). Si no, flujo clásico CSV/SQL/addon; usa `csv_filename` si está definido.
- `handle_train_ai_ajax()`: Entrena embeddings; usa **OpenAI** o **Vertex** según `ai_driver` del proyecto (mismo proveedor que el chat). Ver [§13.7](#137-proveedor-único-por-proyecto-openai-vs-vertex).
- `handle_clear_memory_ajax()`: Borra registros en `xabia_knowledge_vectors` para el proyecto (solo admin, con nonce)
- `ajax_scan_csv_fields()`: Escanea columnas de un CSV (soporta `csv_file` por nombre)
- `ajax_list_csv_files()`: Lista archivos `.csv` en `uploads/xabia/` para el selector
- `ajax_test_sql_connection()`, `ajax_test_addon_columns()`: Pruebas SQL y addon (columnas / mapeo). El **Playground** del laboratorio usa la misma acción AJAX **`xabia_ask_ai`** que el frontend; el handler está en **`Xabia_API::handle_chat_request()`** (ver [§7.1](#71-endpoints-ajax-admin)), no en un stub del admin.
- `ajax_get_meta_fields()`, `ajax_get_wp_schema()`: Esquema de CPT / tipos públicos y meta keys para herramientas de mapeo y Asistente CPT. Aplica filtros **`xabia_wp_schema_post_types`** (tipos extra, p. ej. virtual Amelia) y **`xabia_wp_schema_for_post_type`** (meta keys sin `post_type` real en WP). Ver [§8](#8-sistema-de-integraciones).
- **`admin_json_success(array $data)`** (privado): **estándar** para todas las respuestas **correctas** de los handlers AJAX de administración. Fusiona en `$data` la clave **`nonce`** con un valor nuevo (`wp_create_nonce('xabia_admin_nonce')`) y llama a `wp_send_json_success($data)`. Así el cliente puede rotar el token en cada respuesta y mantener coherencia con `check_ajax_referer('xabia_admin_nonce', 'nonce')` en peticiones sucesivas. Los handlers que devolvían antes un array “plano” (p. ej. solo la lista de columnas) pasan a envolver el payload en claves con nombre (`fields`, `files`, `columns`); ver [§7.1](#71-endpoints-ajax-admin).

**Guía visual y convenciones de marcado**: ver [§14 Panel de administración](#14-panel-de-administración-desarrollo-y-diseño).

#### `css/xabia-admin.css`
**Responsabilidad**: Apariencia del panel principal del plugin (inspiración: producto tipo Google Site Kit, compatible con clases nativas de WordPress).

**Ámbito**: Todas las reglas están pensadas para aplicarse bajo **`.xabia-wrapper.xabia-admin-app`** para no alterar el resto del escritorio de WordPress.

### 3.5 Frontend (`/frontend/widgets/`)

#### `chatbox.php`
**Responsabilidad**: Widget de chat para frontend; prioridad de anclaje; Modo Tótem (timer, aviso, reset)

**Función principal**:
- `shortcode_xabia_agent_renderer($atts)`: Renderiza el shortcode `[xabia_agent]`; acepta `id`, `lang`, `scope`, `ente`, `totem`

**Lógica PHP**:
- Prioridad de scope: URL `?item=` > shortcode `ente=` > URL `x_scope`/`ente` > shortcode `scope=`
- Saludo personalizado en modo estricto (nombre del ente desde BD o shortcode)
- Cálculo de `totem_minutes`: shortcode `totem="N"` tiene prioridad; si no, valor del proyecto (Diseño)

**Data attributes al contenedor**:
- `data-scope`, `data-strict-mode`, `data-ente-raw`, `data-totem-minutes`, `data-images-base` (URL base de uploads WP para imágenes)

**JavaScript** (`chatbox.js`):
- Timer de inactividad (Modo Tótem): se reinicia en envío, focus/input, click en contenedor; aviso 10 s antes; al expirar: llamada `xabia_clear_session`, limpieza de sessionStorage/localStorage, restauración del HTML del saludo inicial (`data-totem-reset` + JSON de saludo/avatar)
- Envío de `item` en POST cuando `strictMode` para modo QR/Museo; envío de **`lang`** (ISO 639-1) alineado con `data-lang` del shortcode (STT/TTS y coherencia con el backend)
- Envío de **`user_lang`**: valor de **`document.documentElement.lang`** de la página (p. ej. `es-ES`, `en`); si está vacío, fallback al `data-lang` del widget. El servidor y el hub de licencias lo usan para instruir al modelo a responder en ese idioma (véase **§7.2**)
- **`renderActions`**: `[ACTION:CALL]`, `[ACTION:URL]`, `[ACTION:IMG]`, `[ACTION:MAP]`, `[ACTION:CART:ID]` (WooCommerce), **`[ACTION:BOOK:…]`** (MEC: enlace con `data-type="mec"`; Amelia: botón + eventos `xabia-amelia-booking-open` y URL opcional con `{service}` vía `xabia_amelia_booking_trigger_url`)
- **WooCommerce**: clic en `.xabia-btn-cart` → POST `xabia_woo_add_to_cart` (nonce `xabia_woo_cart`)
- **Imágenes**: `buildImageTag(path)` unifica `[ACTION:IMG:...]`, `[IMAGE:...]` y Markdown `![...](url)`; rutas relativas usan `data-images-base` (wp-content/uploads); solo nombre de archivo → `data-resolve` y resolución por lote con caché (`resolveImagesIn`, endpoint `xabia_resolve_image`)

**Localización de scripts** (`wp_localize_script` sobre `xabia-chatbox`):
- **`xabiaWooCart`** (si WooCommerce activo): `ajaxUrl`, `nonce`, `action`, `addedMsg`
- **`xabiaReservas`** (si existe `Xabia_Reservas_Handler`): `engine` (`mec` \| `amelia` \| vacío según proyecto/addon), `homeUrl`, `ameliaTriggerUrl` (filtro `xabia_amelia_booking_trigger_url`)

### 3.6 Núcleo, `integrations/` y `addons/` (carga modular)

El arranque en **`xabia-intelligence.php`** separa el **núcleo** (RAG, CSV/SQL, CPT, API, admin) de las **extensiones de producto** en la carpeta **`addons/`**.

**Orden de carga relevante**

1. Constantes, sesión, **`core/`** (DB, Brain, DB Bridge, descubrimiento CPT, cliente SaaS/licencia).
2. **`api/class-xabia-api.php`** → `Xabia_API::init()`.
3. Definición de **`register_xabia_addon()`**, global **`$xabia_available_addons`**.
4. **`integrations/*.php`** — incluye el conector SQL genérico **`integrations/class-xabia-sql-connector.php`** (infraestructura, no es un vertical de producto).
5. Solo estas subcarpetas bajo **`integrations/`**: **`central/`**, **`reservas/`** (ingesta Xabia Central y handler unificado MEC+Amelia). **MEC** como vertical de producto vive en **`addons/xabia-mec/`** (no bajo una ruta **`integrations/mec/`**). **WooCommerce**, **QR/POI** y el **addon de federación Nexus** están en **`addons/`** (`addons/xabia-woo/`, `addons/xabia-qr/`, `addons/xabia-federation/`). Las rutas antiguas **`integrations/qrs/`** u **`integrations/mec/`** no forman parte del árbol actual; el QR está en **`addons/xabia-qr/`**.
6. **`addons/*/*.php`** — todos los archivos PHP de primer nivel dentro de cada `addons/xabia-<nombre>/` (MEC, Amelia, Avirato, WooCommerce, QR, etc.).
7. **`do_action('xabia_register_addons')`** para addons que prefieren registro por hook.
8. Admin (`is_admin`) y shortcode según el flujo habitual del plugin.

**Qué queda en el “core” conceptual:** ingestión **CSV** y **SQL** (local/remoto), mapeo, tabla de vectores, Brain, chat, licencia Xabia/SaaS, panel, Asistente CPT (`core/class-xabia-cpt-schema-discovery.php`), clase **`Xabia_Federation_Nexus`** en `core/class-xabia-federation-nexus.php`, hooks globales. **Qué va en `addons/`:** verticales y partners (MEC, Amelia, Avirato, WooCommerce, QR/POI, federación Nexus, …) que se registran con **`register_xabia_addon()`** y/o filtros.

| Ruta | Rol |
|------|-----|
| `integrations/class-xabia-sql-connector.php` | Conector SQL externo reutilizable (consultas, prefijos). |
| `addons/xabia-woo/xabia-addon-woo.php` | **WooCommerce**: SQL sync productos, **`xabia_woo_discover_products()`**, filtro **`xabia_chat_addon_discovery_blocks`**, `xabia_mapping_column_suggestions`, personalidad de ventas en **`xabia_system_prompt_rules`** (`rag_behavior`), `[ACTION:CART:ID]`, AJAX carrito, ROI (`xabia_conversions`). |
| `integrations/reservas/xabia-addon-reservas.php` | **`Xabia_Reservas_Handler`**: detección MEC/Amelia, `engine_for_project`, disponibilidad, mapeo/esquema, AJAX `xabia_reservas_amelia_services`, reglas para `[ACTION:BOOK:ID]`. |
| `addons/xabia-mec/xabia-addon-mec.php` | **MEC**: SQL de eventos, filtro `xabia_router_search_logic`, meta MEC en esquema profundo. |
| `addons/xabia-mec/xabia-federation-bridge-mec.php` | REST **`/wp-json/xabia/v1/federation-events`** para que una central haga pull de eventos MEC publicados. |
| `addons/xabia-amelia/xabia-addon-amelia.php` | **Amelia**: `get_sync_sql` sobre `{prefix}amelia_services`, tabla ROI, clase **`Xabia_Amelia_Connector`** con **`discover_services()`**, **`discover_providers()`** (tablas `amelia_services` / `amelia_users`), **`get_discovery_summary_text()`**; filtro **`xabia_chat_addon_discovery_blocks`** inyecta el resumen al contexto del chat si el motor del proyecto es Amelia. |
| `addons/xabia-avirato/xabia-addon-avirato.php` | **Avirato**: fuente SQL / herramientas de agente según implementación del archivo. |
| `addons/xabia-qr/xabia-addon-qr.php` | **QR** (slug **`qr`**): POI (`xabia_qr_get_poi_data`), URLs `?xqr=` / `?xid=`, `?item=` / `?x_scope=`, bloque **CONTEXTO FÍSICO** en `xabia_chat_addon_discovery_blocks`, clase **`Xabia_QR_Engine`**. |
| `addons/xabia-federation/xabia-addon-federation.php` | **Federación Nexus** (red de nodos amigos, REST `/federate`, herramienta **`ask_federated_node`** en chat OpenAI y en Vertex local vía `functionDeclarations`). La lógica compartida vive en **`core/class-xabia-federation-nexus.php`**. |
| `integrations/central/` | **Xabia Central**: nodos, pull/push, normalización, cron, ingest AJAX. |
| `xabia-agent-plugins/central-api/` (repo, no es parte del ZIP del plugin WP) | **Hub**: licencias, proxy OpenAI-compat → Vertex; **`VertexForwarder`** acepta **`tools`**, mapea **`functionDeclarations`** (JSON Schema normalizado para Gemini) y devuelve **`functionCall`** como **`tool_calls`** OpenAI (`finish_reason` **`tool_calls`**); **`ProxyHandler`** inyecta identidad de nodo maestro federado en el **`system`** cuando el cuerpo incluye la herramienta **`ask_federated_node`**; campo opcional **`user_lang`** (véase **[README del hub](xabia-agent-plugins/central-api/README.md)**). |
| `test-xabia-federation.php` (raíz del plugin) | Script CLI de prueba PUSH/PULL; ver `integrations/central/README.md`. |

**Filtro `xabia_chat_addon_discovery_blocks`:** recibe `(array $blocks, string $project_id, array $config)`; cada addon puede devolver cadenas que se concatenan al **contexto RAG** antes del tope de caracteres (bloque `### ADDON DISCOVERY ###` en `handle_chat_request`). Amelia lo usa para listar servicios y especialistas visibles en BD.

---

## 4. BASE DE DATOS

### 4.1 Tabla: `{prefix}_xabia_knowledge_vectors`

Almacena el conocimiento vectorial procesado (una fila por “ente”/registro sincronizado).

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | bigint(20) | ID autoincremental (PK) |
| `project_id` | varchar(50) | ID del proyecto/agente |
| `content_chunk` | text | **Texto indexable**: concatenación de "label: valor" (o "label (instrucción): valor") de cada columna mapeada, unidos por " \| ". Es lo que el Brain busca con LIKE. Ej.: `empresa: Foo \| categoria: Tierra \| subcategoria_01: Hípica \| experiencia_01: Rutas a caballo \| ...` |
| `meta_data` | longtext | JSON con metadatos por campo (clave = label saneado, valor = texto del campo) más `__ente_display`, `__ente_col`, `__ente_id` si hay ente. Usado por el frontend/acciones, no por la búsqueda. |
| `vector_data` | longtext | Embeddings vectoriales (opcional; para búsqueda semántica futura). |
| `source_file` | varchar(255) | Origen del dato (opcional). |
| `ente_id` | varchar(100) | ID del ente (valor del campo marcado como ENTE en el mapeo; `global` si no hay). |
| `federation_node_id` | varchar(80) | (Opcional) Identificador del **nodo** Xabia Central asociado a la fila; `NULL` en ingestas no federadas. |
| `created_at` | datetime | Timestamp de inserción. |

**Importante**: La búsqueda RAG se hace solo sobre `content_chunk`. Cualquier dato que deba “encontrarse” por preguntas del usuario (p. ej. actividades, categorías) debe estar en columnas mapeadas en el admin para que entren en ese texto. Lo específico de un proyecto (sinónimos de búsqueda, reglas extra) debe configurarse en el admin (reglas/instrucciones del proyecto), no en código del plugin.

### 4.2 Tabla: `{prefix}_xabia_logs`

Registra conversaciones (solo auditoría).

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | bigint(20) | PK |
| `project_id` | varchar(50) | ID del proyecto |
| `ente_id` | varchar(100) | Ente de la conversación |
| `user_question` | text | Pregunta del usuario |
| `ai_response` | text | Respuesta de la IA |
| `timestamp` | datetime | Momento del mensaje |

### 4.3 Opciones y configuración por proyecto

**`xabia_projects_config`** (opción de WordPress, array asociativo `[ project_id => config ]`). Cada proyecto guarda exactamente lo que el administrador define en la pantalla de edición del agente; no hay datos “por defecto” de un proyecto concreto en el código.

Estructura típica de `$config = xabia_projects_config[$id]`:

| Clave | Origen en admin | Descripción |
|-------|------------------|-------------|
| `name` | Nombre del agente | Etiqueta del proyecto |
| `source_type` | Fuente de información | `csv`, `sql`, `addon`, `multi` |
| `sources` | Solo si `source_type` = `multi` | Array de fuentes; cada una: `type` (`csv`\|`sql`), `attributes` (mapeo), y `csv_filename` o `sql_config` (`host`, `user`, `name`, `pass`, `query`, `prefix` opcional). Ver [§5.4](#54-multi-fuente-por-proyecto). |
| `ai_driver` | Motor de IA | `openai`, `google_cloud` |
| `gcloud_json_path` | Ruta JSON (si Google) | Ruta absoluta al JSON de Service Account |
| `addon_slug` | Addon (si source = addon) | Slug del addon |
| `csv_filename` | CSV seleccionado | Nombre del archivo en uploads/xabia |
| `attributes` | Mapeo (Datos) | Array de `{ csv_col, label, visual_role, is_ente, instruction }` por columna |
| `sql_config` | SQL remoto | `host`, `user`, `name`, `pass`, `query` |
| `rules` | Reglas / Instrucciones | `instructions`, `min_score`, `greeting`, `context_chunk_limit` (opcional, 1–200), `use_vector_search` (0|1), `similarity_threshold` (0–1, umbral similitud para búsqueda vectorial) |
| `design` | Pestaña Diseño | `primary_color`, `bg_color`, `font_size`, `avatar_name`, `tts_voice`, `tts_rate`, `tts_clean_bold`, `tts_clean_italic`, `tts_clean_actions`, `tts_clean_patterns` (array de strings, una línea por patrón a eliminar en TTS) |
| `totem` | Modo tótem | `enabled`, `tiempo_inactividad_defecto` |

Cualquier comportamiento específico de un proyecto (p. ej. expansión de sinónimos para búsqueda, reglas de actividades) debe introducirse por el administrador vía **Instrucciones** (`rules.instructions`) o, si se añade en el futuro, mediante un campo específico en el admin guardado en `rules` u otra clave de esta config.

La sesión PHP mantiene por proyecto:
- `$_SESSION['xabia_chat_history'][$project_id]`: historial de mensajes para el LLM
- `$_SESSION['xabia_last_search'][$project_id]`: última búsqueda; ambos se limpian con `xabia_clear_session`

---

## 5. COMPONENTES PRINCIPALES

### 5.1 Sistema de Entes

**Detección automática** (en `Xabia_DB_Bridge::insert_record`):
1. Campo marcado como **ENTE** en el mapeo del admin
2. Campo con role **"título"** (case-insensitive)
3. Primera columna del CSV/row

Se persiste en `meta_data`: `__ente_display`, `__ente_col`, `__ente_id` para personalización de saludos y prompt.

### 5.2 Modo Estricto (QR/Museo)

**Activación**:
- Parámetro URL `?item=ENTE_ID`
- Atributo `ente="ENTE_ID"` en shortcode

**Comportamiento**: Solo se usa el contexto de ese ente; el prompt instruye a la IA a responder en primera persona como ese objeto/obra; si preguntan por otra entidad, indicar que no tiene acceso en ese modo.

### 5.3 Modo Tótem (terminales físicos)

**Objetivo**: Reiniciar la conversación tras inactividad para proteger la privacidad del usuario anterior.

**Activación**:
- Shortcode: `totem="N"` (N = minutos de inactividad; `0` = desactivado)
- Admin (Diseño): checkbox "Activar modo tótem" + "Tiempo de inactividad por defecto (minutos)". Si el shortcode no lleva `totem`, se usa este valor.

**Flujo**:
1. Temporizador se reinicia en cada interacción (enviar mensaje, focus/input, click en el chat).
2. 10 s antes del límite: mensaje "La sesión se cerrará pronto por inactividad." (`.xabia-totem-warning`).
3. Al llegar al límite: llamada AJAX `xabia_clear_session` (limpia sesión en servidor), limpieza de sessionStorage/localStorage para la clave del proyecto, reemplazo del historial por el saludo inicial, reinicio del temporizador.

**Seguridad**: No se borra la base de conocimiento (vectores); solo el historial de chat de esa sesión.

### 5.4 Multi-fuente por proyecto

**Objetivo**: Que un mismo agente use varias fuentes de datos (p. ej. un CPT en la DB y un CSV) con **campos y mapeos distintos** por fuente; todo se integra en la misma base de conocimiento del proyecto.

**Configuración** (Admin → Datos → Fuente de información = «Multi-fuente (DB + CSV, etc.)»):

- Se muestran **dos bloques** (Fuente 1 y Fuente 2). En cada uno:
  - **Tipo**: SQL (CPT/DB) o CSV.
  - **Si SQL**: Host, DB, usuario, contraseña, prefijo opcional, consulta (con `{prefix}` si aplica). Botón «Test SQL y mapear» rellena el mapeo de columnas de esa fuente.
  - **Si CSV**: Selector de archivo en `uploads/xabia/` y botón «Scan CSV y mapear» para generar el mapeo.
  - **Mapeo de atributos** propio (columnas → etiquetas, rol visual, ENTE, instrucciones).

**Dónde se guarda**:

- **Mapeos**: En la misma opción `xabia_projects_config`, en `$config['sources']`: array de entradas `{ type, attributes, csv_filename | sql_config }`. No hay tabla aparte para mapeos.
- **Datos ingestados**: En la **misma tabla** `xabia_knowledge_vectors`, con el mismo `project_id`. No se guarda en la tabla qué fuente originó cada fila; cada fila es un `content_chunk` + `meta_data` ya normalizados con el mapeo de su fuente.

**Sincronización** (`xabia_sync_content` con `source_type` = `multi`):

1. Se borran todos los registros del proyecto en `xabia_knowledge_vectors`.
2. Para cada elemento de `sources`: si `type` = `csv`, se llama a `Xabia_DB_Bridge::process_csv_knowledge($project_id, $file_path, $source['attributes'])`; si `type` = `sql`, a `process_sql_knowledge($project_id, $sql_config, $source['attributes'])`.
3. Se suman los contadores y se devuelve el total.

El chat y el RAG no cambian: el Brain consulta la misma tabla por `project_id` y obtiene el contexto unificado (DB + CSV).

---

## 6. FLUJO DE FUNCIONAMIENTO

### 6.1 Configuración Inicial
1. Instalación → Creación de tablas
2. Configuración de proyecto
3. Selección de fuente (CSV, SQL, addon o **multi-fuente**)
4. Mapeo de atributos (uno si fuente única; **uno por fuente** si multi-fuente)
5. Sincronización (en multi-fuente se procesan todas las fuentes con su mapeo)
6. Entrenamiento (opcional)

### 6.2 Flujo de Conversación

0. **Caché de respuesta (v1.0.168, antes del router):** Si la petición no es admin ni modo RAG dev, `Xabia_Router::find_cached_response_for_query()` busca en `xabia_response_cache` por hash de KNOWLEDGE/GENERAL. Hit → JSON inmediato sin LLM ni embedding.
1. **Usuario** hace una pregunta (ej. "montar a caballo").
2. **Clasificador de ruta (opcional):** Heurística en `Xabia_Router::classify()`; si el filtro `xabia_router_classify_route` invoca `classify_route_with_mini()`, usa **`call_auxiliary_llm()`** (Vertex `gemini-2.5-flash` local o `gpt-4o-mini` OpenAI/proxy). Ver [§13.7](#137-proveedor-único-por-proyecto-openai-vs-vertex).
3. **IA (Intérprete/router léxico)** (solo si búsqueda vectorial está desactivada): `expand_user_query_generic()` traduce a términos de búsqueda con el mismo proveedor que el chat.
4. **Término de búsqueda y recuperación (Brain)**:
   - **Si búsqueda vectorial está activa**: no se llama al Intérprete léxico; se usa el mensaje del usuario directamente. La API obtiene el **embedding de la query** vía `get_query_embedding()` (con **caché** `Xabia_Embedding_Cache` desde v1.0.168) y lo pasa al Brain. Similitud coseno con `vector_data` (hasta 200 candidatos), umbral y top_k. Si vector devuelve 0 resultados, fallback LIKE.
   - **Si no**: Intérprete + LIKE sobre `content_chunk`.
5. **Doble salto (lista vs desarrollo)**: Según chunks recuperados, prompt lista o desarrollo.
6. **IA (respuesta)**: `call_openai` (GPT-4o) o `call_google_vertex` (Gemini 2.5 Flash). Historial largo: `maybe_summarize_history()` vía `call_auxiliary_llm`.
7. **Frontend:** `chatbox.js` muestra la respuesta completa al recibir el JSON (sin typewriter desde v1.0.168).

En resumen: la IA interpreta la pregunta; Xabia recupera por vector (con umbral y top_k) o por LIKE; según cuántos resultados, el prompt pide lista o desarrollo; la IA explica al usuario.

Si **no** se recupera ningún fragmento útil de la base de conocimiento, el contexto se sustituye por un `SYSTEM_NOTE` y se marca **`had_knowledge_rows = false`**; el system prompt incluye entonces un bloque **SIN DATOS DE CATÁLOGO** que prohíbe inventar nombres de empresas o recurrir a conocimiento general (reduce alucinaciones en listados).

### 6.3 Sinónimos y variabilidad (enfoque en tres pilares)

Para que expresiones distintas del usuario (“montar a caballo”, “hípica”, “Jaco”) encuentren la misma oferta, el diseño se apoya en:

1. **Anclaje vectorial (embeddings)**  
   Con **búsqueda vectorial activa** (regla `use_vector_search` en el proyecto y vectores entrenados), el Brain convierte la query en embedding, calcula similitud coseno con cada `vector_data`, filtra por **umbral de similitud** (`similarity_threshold`, 0–1; recomendado 0,2–0,3) y devuelve hasta **top_k** chunks (`context_chunk_limit`). Así no se buscan “letras” sino conceptos (ej. “subir en globo” ≈ “vuelo en globo”). Si el umbral es demasiado alto se descartan resultados válidos; si `top_k` es muy bajo la IA ve pocas opciones. Sin vectores o con búsqueda vectorial desactivada, se usa LIKE sobre `content_chunk`.

2. **Columna de etiquetas (taxonomía en los datos)**  
   Es vital que la columna donde están las categorías/actividades (o una columna “etiquetas”) **incluya varios términos por ente** en la misma fila (ej. “Hípica, Caballos, Montar a caballo, Excursiones ecuestres”). Esa columna debe estar **mapeada** en el admin (INFO o como cualquier rol) para que entre en `content_chunk`. Así, cualquier sinónimo que el Intérprete o el usuario use en la búsqueda puede coincidir con ese texto.

3. **Instrucción de sistema (semántica en el prompt)**  
   En **Prompt Maestro (Instrucciones)** del proyecto, el administrador puede definir que la IA entienda equivalencias de concepto, por ejemplo: “Actúa como experto en [dominio]. Entiende que ‘hípica’, ‘equitación’ y ‘paseo a caballo’ se refieren a lo mismo; que ‘volar’, ‘viajar en globo’ y ‘subir en globo’ también. Si el usuario usa lenguaje informal, tradúcelo internamente a las categorías disponibles en los datos.” Así la IA que responde interpreta bien el contexto recuperado.

**Checklist:**  
- **Mapeo**: La columna de categorías/etiquetas debe estar mapeada para que entre en `content_chunk`.  
- **Top_K** (`context_chunk_limit`): Para respuestas tipo lista conviene 5–10; para desarrollo 1–2; por defecto 30.  
- **Umbral de similitud** (`similarity_threshold`): Solo aplica si búsqueda vectorial está activa. 0,2–0,3 suele ir bien; muy estricto descarta resultados válidos.  
- **Búsqueda vectorial**: Activar en Reglas y haber ejecutado «Entrenar» para que exista `vector_data`. Los vectores deben generarse con **el mismo proveedor** (OpenAI o Vertex) que use el proyecto en chat; ver [§13.7](#137-proveedor-único-por-proyecto-openai-vs-vertex).  
- **No matar la semántica**: El Intérprete expande a ontología; la recuperación es por similitud (vector) o por LIKE sobre ese término.

---

## 7. APIs Y ENDPOINTS

### 7.1 Endpoints AJAX (Admin)

**Nonce en petición**: salvo nota explícita, todas las acciones comprueban `check_ajax_referer('xabia_admin_nonce', 'nonce')` y esperan el campo POST **`nonce`**.

**Playground (`xabia_ask_ai`) — mismo handler que el chat público**  
El laboratorio en la edición del agente llama a **`admin-ajax.php`** con `action=xabia_ask_ai` y **`nonce`** = `xabia_admin_nonce` (`xabiaCurrentNonce`). El handler **`Xabia_API::handle_chat_request()`** acepta, si hay `nonce` en POST: **`xabia_nonce`** (visitante) o **`xabia_admin_nonce`** junto con **`manage_options`** (admin). Así el Playground usa el **mismo pipeline** RAG + LLM que el frontend (no hay respuesta mock en `Xabia_Admin`).  
Si el nonce admin caduca en **otras** acciones exclusivas del panel, esos handlers siguen usando `check_ajax_referer(..., false)` + `wp_send_json_error` + nonce nuevo (evita **HTTP 403** sin JSON). El JS **`xabiaAdminPost`** mantiene la cadena de nonces en respuestas que incluyen `data.nonce` vía **`admin_json_success()`**.

**Estándar de respuesta JSON en éxito (`success: true`)**  
Los handlers que usan el helper **`admin_json_success()`** devuelven siempre un objeto **`data`** asociativo que incluye, además del payload de negocio, la clave **`nonce`** (string) con un token nuevo para la siguiente petición. El JavaScript del panel mantiene **`xabiaCurrentNonce`** y lo sustituye por `data.nonce` tras cada respuesta parseada (ver [§14.7](#147-asistente-cpt-y-nonce-en-cadena-ajax)).

**Cuerpos de respuesta relevantes** (siempre dentro de `data`, junto con `nonce` cuando aplica):

| Acción | Claves principales en `data` | Notas |
|--------|------------------------------|--------|
| `xabia_list_csv_files` | `files`: array de `{ name, path }` | Antes el éxito podía devolver solo el array de archivos; ahora va bajo `files`. |
| `xabia_get_fields` | `fields`: array de filas de mapeo `{ csv_col, label, visual_role, is_ente, instruction }` (misma forma que usa el renderizado del mapeador) | Equivalente al escaneo de cabeceras CSV. |
| `xabia_test_addon` | `columns`: array de strings (nombres de columna detectados en la consulta del addon) | Sustituye la respuesta “plana” solo-array. |
| `xabia_test_sql` | `message`, `columns`, `fields` | Sin cambio semántico; se añade `nonce` vía `admin_json_success`. |
| `xabia_get_wp_schema` | Sin `post_type`: `post_types` (públicos + filtro `xabia_wp_schema_post_types`), `meta_keys` (vacío), `post_type` (null). Con `post_type`: resolución vía filtro virtual `xabia_wp_schema_for_post_type` o postmeta | Incluye `nonce` en éxito. |
| `xabia_reservas_amelia_services` | `services`: `[{ id, name }, …]` | Solo admin; Amelia activo; nonce admin. |
| `xabia_sync_content`, `xabia_train_ai`, `xabia_clear_memory` | `message` y contadores / `pending` según caso | Incluyen `nonce`. |
| `xabia_ask_ai` (playground admin) | `response` (texto del LLM, mismo formato que frontend) | Misma acción que el chat público; `nonce` en POST debe ser `xabia_admin_nonce` válido + usuario administrador. |

**Errores (`success: false`)**: el mensaje suele ir en `data.message`. Algunos handlers añaden también `data.nonce` para permitir al cliente recuperarse tras un fallo previsible (p. ej. permiso denegado o tipo de contenido inválido en `xabia_get_wp_schema`).

**Listado de acciones** (resumen):
- `xabia_get_fields`: Escanea columnas CSV → `data.fields`
- `xabia_sync_content`: Sincroniza contenido desde CSV/SQL/addon/multi
- `xabia_train_ai`: Genera embeddings
- `xabia_clear_memory`: Borra todos los registros de `xabia_knowledge_vectors` del proyecto
- `xabia_list_csv_files`: Lista archivos CSV en `uploads/xabia/` → `data.files`
- `xabia_get_meta_fields`, `xabia_get_wp_schema`: Meta / esquema para CPT y Asistente CPT
- `xabia_reservas_amelia_services`: Listado/búsqueda de servicios Amelia (admin, mapeo / buscador en modal CPT)
- `xabia_test_sql`, `xabia_test_addon`: Pruebas SQL y addon (admin). **`xabia_ask_ai`**: chat (público o admin); ver tabla anterior y [§17](#17-documentación-complementaria-manual-y-desarrollo).

### 7.2 Endpoints Frontend (públicos / nopriv)

- **`xabia_ask_ai`**: Chat. POST: `project_id`, `message`, `x_scope`, opcionalmente `item` (modo estricto), **`lang`** (UI, voz y fallback), **`user_lang`** (etiqueta BCP-47 de la página como fallback/compatibilidad), opcionalmente **`history`** (JSON de últimos mensajes). Desde v1.0.57, `build_system_prompt()` gobierna la salida con una regla políglota: responder en el idioma del último mensaje del usuario y traducir internamente el contexto RAG/CSV si viene en otro idioma. La petición al hub se firma con HMAC usando la licencia guardada como secreto dinámico. Si hay nodos federados, Vertex usa herramientas nativas y **`Xabia_Federation_Nexus::federation_vertex_consciousness_block()`** refuerza el uso de **`ask_federated_node`**.
- **`xabia_clear_session`**: Limpia la sesión de chat del cliente para un `project_id` (Modo Tótem). POST: `project_id`. No requiere nonce.
- **`xabia_resolve_image`**: Resuelve rutas de imagen a URLs en `wp-content/uploads`. POST: `path` (uno) o `paths[]` (varios). Respuesta: `{ success: true, data: { urls: { "path": "url", ... } } }`. Límite 100 paths por petición. No requiere nonce. Ver [§10 Imágenes y uploads estándar](#10-imágenes-y-uploads-estándar).
- **`xabia_woo_add_to_cart`**: WooCommerce. POST: `nonce` (`xabia_woo_cart`), `product_id`, `xabia_project_id`. También `nopriv`.
- **`xabia_central_ingest`**: Push federación. POST body JSON: `node_id`, `project_id`, `api_key`, `records`. Ver [§8.2](#82-federación-addon-comercial--xabia-central) e `integrations/central/README.md`.

---

## 8. SISTEMA DE INTEGRACIONES

### 8.1 Addons y registro
- **`register_xabia_addon($slug, $args)`** (`xabia-intelligence.php`): registra nombre, `callback` (SQL sync o placeholder), etc., y enlaza con `xabia_register_sql_sources`.
- **Hook `xabia_register_addons`**: Woo, QR, Amelia (en `addons/`), Central, etc. registran aquí si no llaman a `register_xabia_addon` en la carga del archivo.
- **Sync addon**: `handle_sync_content_ajax` aplica **`xabia_addon_sync_result`** antes de ejecutar SQL del callback (p. ej. Central devuelve `['count' => n]` tras `Xabia_Central_Sync::sync_project`).

### 8.1.1 WooCommerce (`addons/xabia-woo/`)
- Addon slug **`woo`**: SQL por defecto de productos publicados; sugerencias de mapeo `_price`, `_stock_status`, `_sku`, `_thumbnail_id`.
- **Discovery:** **`xabia_woo_discover_products()`** lee `posts` (`product`) y `postmeta` (`_price`, `_stock_status`, `_thumbnail_id`), resuelve URL de imagen y permalink; el filtro **`xabia_chat_addon_discovery_blocks`** inyecta el bloque en el chat cuando `source_type` = `addon` y `addon_slug` = `woo`.
- **Reglas RAG** (`xabia_system_prompt_rules`, contexto `rag_behavior`): personalidad comercial, formato nombre/precio/stock/enlace, cierre con `[ACTION:CART:ID]`; alineación con **user_lang** del Core (traducción natural de fichas vía instrucciones al LLM / hub).
- **Frontend**: `[ACTION:CART:ID]` → botón; AJAX `xabia_woo_add_to_cart`; sesión `xabia_last_recommendation` para ROI en `woocommerce_thankyou`.
- **Estilos**: variable `--xabia-magenta` y `.xabia-btn-cart` en el widget.

### 8.1.2 Reservas — MEC y Amelia (`integrations/reservas/xabia-addon-reservas.php`)
- **`Xabia_Reservas_Handler`**: `is_mec()` / `is_amelia()` (`class_exists` MEC / Amelia), `detect_engine()`, **`engine_for_project($project_id)`** (respeta `addon_slug` y fuentes `multi`), **`check_availability($engine, $entity_id, $args)`** (MEC: fecha `mec_start_date`; Amelia: servicio visible en tabla; extensible con **`xabia_reservas_check_availability`**).
- **Mapeo**: filtro **`xabia_mapping_column_suggestions`**: `mec-events` → `mec_start_date`, `mec_cost`, `mec_location`; tipo virtual **`xabia_amelia_services`** → columnas tabla Amelia; filtros **`xabia_wp_schema_post_types`** y **`xabia_wp_schema_for_post_type`**.
- **Admin**: en el modal Asistente CPT, tipo «Amelia — servicios (tabla)» genera SQL sobre `{prefix}amelia_services`; bloque **buscador** de servicios (AJAX `xabia_reservas_amelia_services`) y slug sugerido `amelia-svc-{id}` para `?item=`.
- **Chat / API**: reglas `xabia_system_prompt_rules` para `[ACTION:BOOK:ID]`; **`resolve_action_book_tags_in_response`** expande el tag; **`xabiaReservas`** en el widget (`engine`, `homeUrl`, `ameliaTriggerUrl` vía **`xabia_amelia_booking_trigger_url`**).
- **MEC** (`addons/xabia-mec/`): SQL eventos y puente REST de federación; **Amelia** (`addons/xabia-amelia/`): **`Xabia_Amelia_Connector::get_sync_sql`**, descubrimiento de catálogo y bloque opcional en contexto vía **`xabia_chat_addon_discovery_blocks`**.

### 8.1.3 Smart QR — POI, túnel y tótems (Core, bundled)

**Incluido en la licencia Core** (desde v1.0.59; el plugin retail `xabia-smart-qr` está obsoleto).

| Módulo | Rol |
|--------|-----|
| **`core/class-xabia-smart-qr.php`** | Preambulo túnel (`xabia_chat_tunnel_system_preamble`), URLs de aterrizaje, assets del generador QR en admin |
| **`addons/xabia-qr/xabia-addon-qr.php`** | Slug interno **`qr`**: POI (`xabia_qr_get_poi_data`), pestaña **Smart QR / Tótems**, `Xabia_QR_Engine` |
| **`core/class-xabia-box-route.php`** | Ruta pública **`/xabia-box/`** |

Funcionalidad: mapeo POI (`xabia_qr_poi_registry`, tabla `xabia_qr_poi`, fallback RAG); interceptor **`?xqr=`** / **`?xid=`** (+ **`?x_project=`**); túnel **`?ente_id=`**; saludo automático en widget; segmentación por ente; filtros **`xabia_chat_addon_discovery_blocks`** y **`xabia_system_prompt_rules`**; modo tótem (inactividad / `xabia_clear_session`).

Manual de usuario: [manual-usuario-xabia-smart-qr.md](xabia-agent-plugins/documentation/manual-usuario-xabia-smart-qr.md).

### 8.2 Federación (addon comercial — XABIA CENTRAL)
La **federación** (redes de nodos, ingesta unificada desde webs asociadas con datos en distintos formatos, pull/push, asignación por ente) se implementa como **addon** independiente del core, comercializado en el nivel **XABIA CENTRAL** ([xabia.ai/precio](https://xabia.ai/precio/)). Planificación: `xabia-agent-plugins/documentation/FEDERACION_COMO_ADDON_Y_COMERCIAL.md`, `xabia-agent-plugins/documentation/PLAN_FEDERACION_DATOS_Y_ACTUALIZACIONES.md`.

**Implementación técnica** (`integrations/central/` — ver también `README.md` del addon):

| Componente | Descripción |
|------------|-------------|
| **`Xabia_Central_Normalize::row_to_canonical`** | Recibe **`$node_name`**; antepone al `content_chunk` **`Fuente: {Nombre del nodo} | `** (si hay contenido). `meta_data` puede incluir `__federation_node_name`. |
| **`upsert_batch`** | Pasa el nombre del nodo a la normalización (sync pull e ingest push). |
| **Cron `xabia_central_hourly_sync`** | Registrado en activación del plugin principal + `xabia_install_addon_tables` + `init` (si hay proyecto Central y el cron no existía). Ejecuta **`Xabia_Central_Sync::sync_project($id)`** para cada proyecto con `addon_slug = xabia_central`. |
| **Admin Federación** | Botón «Sincronizar ahora (pull)» usa **`xabia_sync_content`** (mismo flujo que el panel del agente → filtro `xabia_addon_sync_result`). |
| **Pruebas** | Script raíz **`test-xabia-federation.php`**: PUSH paralelo (3 nodos), PULL simulado, comprobación de prefijo `Fuente:` y duplicados (solo CLI o `XABIA_ALLOW_FEDERATION_TEST`). |

---

## 9. FRONTEND Y WIDGETS

### 9.1 Shortcode Principal

**`[xabia_agent id="proyecto" lang="es" scope="global" ente="ente_id" totem="5" avatar_name=""]`**

| Atributo | Descripción |
|----------|-------------|
| `id` | ID del proyecto (obligatorio) |
| `lang` | Idioma (STT/TTS y `data-lang`); si se omite, fallback desde `get_locale()` |
| `scope` | Scope inicial (por defecto `global`) |
| `ente` | Identificador del ente; activa modo estricto y saludo en primera persona |
| `totem` | Minutos de inactividad para reiniciar sesión; `0` = desactivado. Si no se indica, se usa el valor del proyecto en Diseño |
| `avatar_name` | Nombre mostrado del asistente en burbujas (opcional) |

**Ejemplo tótem en centro comercial**:  
`[xabia_agent id="demo-aktiba" ente="nombre-del-ente" totem="5"]` — proyecto, túnel por ente y reinicio a los 5 minutos sin interacción.

**Características**:
- Síntesis de voz y reconocimiento de voz
- Acciones visuales (llamada, URL, imagen, mapa, **carrito WooCommerce**, **reserva MEC/Amelia**)
- Memoria de sesión (historial en servidor; envío de últimos mensajes en el POST del chat)
- Modo Tótem: temporizador, aviso previo, limpieza de sesión y restauración del saludo inicial

---

## 10. IMÁGENES Y UPLOADS ESTÁNDAR

Las imágenes mostradas en las respuestas del chat **no dependen de una carpeta propia del plugin**. Se resuelven siempre respecto a la **raíz estándar de WordPress**: **`/wp-content/uploads/`** (o la URL base devuelta por `wp_upload_dir()['baseurl']`). Así se compatibiliza el guardado normal de medios y CPTs con la capacidad de Xabia de encontrar las imágenes.

### 10.1 Convención de rutas

- **URL absoluta** (`https://...`): se usa tal cual.
- **Ruta relativa con subcarpetas** (ej. `2025/02/archivo.png`): se antepone la base de uploads → `{baseurl}/2025/02/archivo.png`. Ideal para CSV o contenido que almacene la ruta relativa a uploads (p. ej. la que guarda WordPress al subir).
- **Solo nombre de archivo** (ej. `archivo.png`): el frontend marca la imagen con `data-resolve="archivo.png"` y, tras insertar el mensaje, llama al endpoint **`xabia_resolve_image`** en **lote** (una petición por mensaje con todos los paths únicos). El servidor busca en la biblioteca de medios (`_wp_attached_file`) y devuelve la URL real (p. ej. con año/mes). Si no hay coincidencia, se devuelve `baseurl + nombre` como fallback.

**Recomendación**: En CSV o contenido RAG, usar **rutas relativas a la raíz de uploads** (ej. `2025/02/taupada_01.png`) cuando se conozcan; así no hace falta resolución por nombre y todo es más eficiente.

### 10.2 API de resolución (`xabia_resolve_image`)

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `path` | string | Una sola ruta (alternativa a `paths[]`) |
| `paths[]` | array | Varias rutas en una sola petición (recomendado para el frontend) |

**Respuesta** (éxito):  
`{ "success": true, "data": { "urls": { "path1": "https://...", "path2": "https://..." } } }`

- **Seguridad**: Paths se sanitizan (sin `..`, sin protocolo `http(s)://`).
- **Límite**: Máximo 100 paths por petición.
- **Backend**: Rutas con `/` se resuelven a `baseurl + path`. Solo nombres de archivo se resuelven con una única query a `postmeta` + `posts` (attachments) por lote; se prioriza el attachment más reciente por nombre.

### 10.3 Frontend (chatbox)

- **Base**: `data-images-base` en el contenedor = URL base de uploads (sin `/xabia/`).
- **Builder único**: `buildImageTag(path)` genera el HTML para `[ACTION:IMG:path]`, `[IMAGE:path]` y Markdown `![alt](path)`.
- **Resolución por lote**: Tras añadir un mensaje del bot, `resolveImagesIn(container, endpoint)` recoge todos los `img[data-resolve]`, agrupa por path, aplica **caché en memoria** (path → url) para los ya resueltos y envía **una sola petición** con los paths no cacheados. Con la respuesta actualiza la caché y asigna `src` a cada imagen.

Con esto se consigue: archivos en carpetas normales de WP, CSV con rutas relativas opcionales, y resolución por nombre cuando solo se dispone del nombre (p. ej. desde medios/CPTs), sin obligar a cambiar el flujo de creación de posts.

---

## 11. CONFIGURACIÓN Y OPCIONES

### 11.1 Opciones de WordPress
- `xabia_openai_key`: API Key OpenAI (chat y embeddings cuando el proyecto usa OpenAI).
- `xabia_gcloud_json_path`: Ruta absoluta al JSON de la cuenta de servicio de Google Cloud (Vertex AI / Gemini). Se usa por defecto para todos los proyectos con motor «Google Cloud»; cada proyecto puede sobreescribirla con su propio `gcloud_json_path` en la config.
- `xabia_google_key`: API Key Google Cloud Maps (mapas en frontend, si aplica).
- `xabia_projects_config`: Configuración de proyectos; estructura completa por proyecto en [§4.3](#43-opciones-y-configuración-por-proyecto). Todo lo específico de un proyecto (reglas, sinónimos de búsqueda, etc.) debe configurarse aquí desde el admin, no en código del plugin.

### 11.2 Admin – Pestaña Diseño (por proyecto)
- Colores del widget (identidad y fondo del chat), tamaño de fuente, nombre del avatar/asistente
- **TTS (voz)**: preferencia de voz, velocidad, opciones de limpieza de markdown/acciones antes de leer en alto
- Saludo inicial, prompt maestro, **índice de confianza** (`min_score`), límite de chunks RAG, búsqueda vectorial y umbral de similitud
- **Modo Tótem**: checkbox "Activar modo tótem" y número "Tiempo de inactividad por defecto (minutos)". Valor por defecto 0 (desactivado). Si el shortcode usa `totem="N"`, prevalece N.

### 11.5 Admin – Pantallas y rutas (referencia rápida)

| Contexto | URL / parámetros | Contenido |
|----------|-------------------|-----------|
| Listado de agentes | `admin.php?page=xabia-settings` | Cabecera, botón "Nuevo agente", rejilla de tarjetas por proyecto, formulario de claves globales (OpenAI, ruta JSON Vertex, Maps) |
| Nuevo agente | `admin.php?page=xabia-settings&edit=new` | Mismo layout de edición sin bloque lateral de memoria/playground hasta que exista un `project_id` real (tras el primer guardado se redirige a `edit=<id>`) |
| Editar agente | `admin.php?page=xabia-settings&edit=<project_id>` | Formulario principal con pestañas + sidebar: estadísticas de vectores, sincronizar, entrenar, borrar memoria, playground |

**Borrado de proyecto**: `admin.php?page=xabia-settings&xabia_action=delete&project_id=<id>` (con confirmación en el cliente).

**Nota**: Otras pantallas del ecosistema (p. ej. **Xabia Central** en `integrations/central/class-xabia-central-admin.php`) pueden tener su propia maquetación; el sistema de diseño descrito en [§14](#14-panel-de-administración-desarrollo-y-diseño) aplica de forma explícita a la pantalla `xabia-settings`.

### 11.3 Fuente CSV
- Archivos en **`/uploads/xabia/`** (rutas relativas al `basedir` de WordPress)
- El proyecto guarda el nombre del archivo seleccionado (`csv_filename`); al recargar se muestra en el selector y se usa en sincronización

### 11.4 Fuente multi-fuente
- **Selector**: Fuente de información = «Multi-fuente (DB + CSV, etc.)».
- **UI**: Dos bloques (Fuente 1 y Fuente 2); en cada uno: tipo (SQL/CSV), configuración (consulta SQL o archivo CSV) y mapeo de atributos independiente. «Test SQL y mapear» y «Scan CSV y mapear» rellenan el mapeo de esa fuente.
- **Guardado**: Al guardar proyecto con `source_type` = `multi`, se persiste el array `sources` en `xabia_projects_config`; cada fuente tiene `type`, `attributes` y `csv_filename` o `sql_config` (incl. `prefix` opcional).
- **Sync**: Mismo endpoint `xabia_sync_content`; el backend detecta `source_type` = `multi` y ejecuta el flujo multi-fuente (borrado + iteración sobre `sources`).

---

## 12. CHANGELOG / Cambios recientes

- **v1.0.168 (julio 2026):** Optimización latencia chat — caché respuesta pre-router (`find_cached_response_for_query`), caché embeddings consulta (`Xabia_Embedding_Cache`, TTL 30 días), router cache sin `SHOW TABLES`, auxiliares LLM alineados a Vertex (`call_auxiliary_llm`), frontend sin typewriter. Motor **Document-to-RAG** agnóstico (`class-xabia-document-ingest.php`, adaptadores WP/PDO). Ver [DESARROLLO.md §4.12 y §5.1](xabia-agent-plugins/documentation/DESARROLLO.md).
- **v1.0.166 (julio 2026):** Docs de producto alineadas; anexo/listados **agnósticos**; sidebar admin `position: static` (sin sticky). Manuales Core/MEC/Woo + DESARROLLO/DESPLIEGUE.
- **v1.0.164–1.0.165:** Anexo dinámico de atributos mapeados en `content_chunk` remoto (`append_mapped_attributes_annex`); sin `get_post_meta` en remoto; modo lista strip del bloque `---`; utilidad/contacto → desarrollo.
- **v1.0.162–1.0.163:** Asistente CPT aislado por fuente (`discover_cpt_assistant_types`); MEC/Woo remoto por host SQL; deep schema Woo `_metas` y MEC `mec_available_slots`.
- **v1.0.138:** Pipeline semántico / meta-esquemas; PII Shield.

- **v1.0.0** - Versión comercial inicial. Arquitectura modular de addons, integración multilingüe nativa y Hub centralizado con Gemini Flash. *Incluye:* carga selectiva `integrations/` (**`central/`**, **`reservas/`**) + **`addons/*/*.php`** (MEC, Amelia, Avirato, **WooCommerce** en `addons/xabia-woo/` con **`xabia_woo_discover_products()`**, **QR/POI** en `addons/xabia-qr/` con slug **`qr`**, **federación Nexus** en `addons/xabia-federation/` + `core/class-xabia-federation-nexus.php`, y el filtro **`xabia_chat_addon_discovery_blocks`**); Amelia con descubrimiento `{prefix}amelia_services` / `amelia_users`; **`user_lang`** desde el DOM en `chatbox.js`, reenvío por proxy Xabia e inyección en **`systemInstruction`** vía **`central-api`** (`ProxyHandler`, `VertexForwarder`) y consigna equivalente en Vertex local; **`VertexForwarder`** y el plugin mapean **`tools`** OpenAI ↔ **`functionDeclarations`** en Gemini para **`ask_federated_node`**.
- **Vertex + federación (plugin):** con `ai_driver === 'google_cloud'` y nodos amigos, el chat usa **`generateContent`** multimodelo, **`federation_vertex_consciousness_block()`** y ejecución de **`xabia_federation_ask_node`** hasta respuesta final en texto (máx. **3** rondas de herramienta). Vía **Xabia hub**, **`call_openai_with_federation_tools`** mantiene el mismo límite y reenvía **`tool`** al **`VertexForwarder`**.
- **Hub (`central-api`):** normalización de JSON Schema en declaraciones Gemini; **`ProxyHandler`**: consigna de nodo maestro en peticiones con **`ask_federated_node`**.
- **Consola de curación CPT (admin):** Modal «Consola de curación de contenidos» (`#xabia-field-selector`): cuatro bloques (Identidad / Detalles / Clasificación / Estado), radio **Ente** por fila, badge verde **Con datos** si `in_recent_sample` en meta MEC, selección por defecto según **`mapping_hints`** en la respuesta de **`xabia_get_deep_schema`** (filtro `xabia_deep_schema_mapping_hints`). Cierre del modal con confirmación para aplicar SQL y mapeo. PHP: `core/class-xabia-cpt-schema-discovery.php` (`xabia_discover_cpt_fields`, `xabia_default_deep_mapping_hints`). Estilos: `admin/css/xabia-admin.css` (`.xabia-modal-panel--curator`, `.xabia-curate-row`, …).
- **Documentación (julio 2026):** [manual-usuario-xabia-core.md](xabia-agent-plugins/documentation/manual-usuario-xabia-core.md) (manual de usuario canónico; `MANUAL_USUARIO.md` deprecado), [DESARROLLO.md](xabia-agent-plugins/documentation/DESARROLLO.md) (desarrollo a fondo, §12 filtros/HMAC), [README.md (índice doc.)](xabia-agent-plugins/documentation/README.md). [§17](#17-documentación-complementaria-manual-y-desarrollo).
- **Playground (laboratorio en admin):** `xabia_ask_ai` la gestiona solo **`Xabia_API::handle_chat_request()`** (mismo flujo que el chat público). `nonce` del admin puede ser **`xabia_admin_nonce`** (válido con `manage_options`) o **`xabia_nonce`**. Eliminado el handler stub en `Xabia_Admin` que devolvía un texto fijo. Ver [§7.1](#71-endpoints-ajax-admin) y [DESARROLLO.md §4](xabia-agent-plugins/documentation/DESARROLLO.md#4-pipeline-de-chat).
- **RAG sin filas de catálogo:** Si la recuperación no devuelve chunks útiles, se marca `$had_knowledge_rows = false` y `build_system_prompt()` añade instrucciones explícitas para **no** inventar empresas ni usar conocimiento general (mitiga alucinaciones cuando el contexto es solo `SYSTEM_NOTE`).
- **Playground admin (nonce / 403) en otros AJAX:** Los handlers que solo aceptan `xabia_admin_nonce` siguen usando `check_ajax_referer(..., false)` y responden con JSON de error + nonce renovado en lugar de **403** sin cuerpo. `xabiaAdminPost` y `xabiaSyncNonce(r)` en el cliente. Ver [§7.1](#71-endpoints-ajax-admin).
- **Distribución y paquete ZIP:** **`scripts/build-retail-plugin-zips.sh`** → `xabia-agent-plugins/dist/retail/xabia-agent-core-<versión>-retail.zip` y `xabia-avirato-<versión>-retail.zip`; **`scripts/build-plugin-zip.sh`** → ZIPs mínimos en `xabia-agent-plugins/dist/`; [§16](#16-distribución-paquete-zip-e-instalación-en-wordpress); código en **`xabia-agent-plugins/packages/`**; cabecera del plugin y `XABIA_VERSION` alineados; `.gitignore` incluye `build/` y `xabia-agent-plugins/dist/`.
- **Xabia Central (federación)**: Atribución por nodo en **`content_chunk`**: prefijo `Fuente: {nombre del nodo} | ` en `Xabia_Central_Normalize::row_to_canonical` / `upsert_batch` (pull y push). Cron horario **`xabia_central_hourly_sync`** sincroniza todos los proyectos con addon `xabia_central`. Admin Federación: texto de ayuda y feedback AJAX mejorado. Script **`test-xabia-federation.php`** (raíz del plugin) para pruebas de PUSH/PULL y verificación en BD. Ver [§8.2](#82-federación-addon-comercial--xabia-central) y `integrations/central/README.md`.
- **Licencias SaaS (abril 2026):** el Hub permite la misma `license_key` en más de un dominio mediante unicidad compuesta **`(license_key, client_domain)`**; los handlers (`proxy`, `license/validate`, `usage`) resuelven la licencia por **clave + dominio**. En el plugin, `X-Xabia-Source` usa `get_site_url()` limpio (sin barra final), la URL base por defecto apunta a `https://xabia.ai/api/xabia/v1/` (gateway en `public_html/api/` cuando `central-api` está fuera del docroot) y el panel admin muestra la licencia Xabia enmascarada con persistencia de guardado. El proxy valida `X-Xabia-Signature` usando la propia `license_key` como secreto HMAC dinámico.
- **Avirato por cliente (abril 2026):** el hub no define `AVIRATO_ESTABLISHMENT_ID`; el addon cliente envía `avirato.establishment_id` y `avirato.room_filter` dentro del payload del proxy. Si falta el ID y la consulta es de disponibilidad, el hub devuelve `missing_avirato_establishment_id`.
- **Router Hub / BasePath (abril 2026):** `xabia-agent-plugins/central-api/src/Router.php` normaliza automáticamente prefijos (`/api`, `/api/index.php`, `/api-xabia-saas`, `/api-xabia-saas/public`, `/api-xabia-saas/public/index.php`) para evitar `Not Found` en despliegues con subcarpetas y front-controller.
- **Gateway público + búnker (mayo 2026):** `public_html/api/index.php` carga `central-api/bootstrap.php` fuera del webroot (resolución por `dirname(__DIR__, 4)`, `XABIA_HUB_BOOTSTRAP` o fallbacks). Polar: `PolarWebhookHandler` prioriza metadata **`product_type`** (`initial`, `renewal`, `topup`) alineada con DTP; sin meta, **`PolarProductMap`** por UUID. Checklist: `xabia-agent-plugins/central-api/UNIFICATION_QA_CHECKLIST.md`.
- **Empaquetado producción:** script `scripts/build-xabia-nexus-zip.sh` fuerza `composer install --no-dev`, exige `vendor/autoload.php`, excluye `xabia-agent-plugins/central-api/` del paquete final y genera `build/xabia-nexus.zip` con `vendor/`.
- **Reservas (MEC / Amelia)**: Nueva integración **`integrations/reservas/xabia-addon-reservas.php`** con **`Xabia_Reservas_Handler`** (detección por `class_exists`, `engine_for_project`, `check_availability`, filtros de esquema y mapeo). Chat: **`[ACTION:BOOK:ID]`**; API expande a formas MEC/Amelia; WooCommerce-style botones/enlaces en `chatbox.js` + `xabiaReservas`. Amelia: SQL sync en conector, buscador de servicios en Asistente CPT, tipo virtual **`xabia_amelia_services`**. MEC: sugerencias `mec_start_date`, `mec_cost`, `mec_location`.
- **Admin — esquema WP**: `ajax_get_wp_schema` soporta tipos virtuales vía **`xabia_wp_schema_post_types`** y **`xabia_wp_schema_for_post_type`** (p. ej. Amelia sin CPT público).
- **WooCommerce**: Addon con `[ACTION:CART:ID]`, AJAX add-to-cart, reglas RAG, sugerencias de mapeo para `product`; estilos de botón en el widget.
- **QR / tótem / chat**: Addon **`qr`** (`addons/xabia-qr/`, `?item=` / `?xqr=` / `?xid=`); temporizador de tótem, `data-totem-reset`, `xabia_clear_session`; historial reciente en POST del chat; `lang` en shortcode y petición.
- **API**: `resolve_action_book_tags_in_response`; resolución de imágenes por ID; reglas de idioma en respuestas según configuración del proyecto.
- **Mapeador visual (admin)**: Columnas / meta keys como `<select class="xabia-col-selector">` enlazadas a `attributes[i][csv_col]` (fuente única) o `sources[idx][attributes][i][csv_col]` (multi-fuente), con icono de “campo prioritario” para columnas típicas de posts; herramienta “Campos de un CPT (meta + ACF)” que inyecta opciones en los selectores. Ver [§14](#14-panel-de-administración-desarrollo-y-diseño).
- **Asistente CPT**: En fuente SQL (simple y multi-fuente), botón que abre un modal: carga tipos públicos con `xabia_get_wp_schema` (sin `post_type`), luego meta keys con `post_type` elegido; generación automática de un `SELECT` sobre `{prefix}posts` y subconsultas a `{prefix}postmeta` para metas, lista para revisión con «Test SQL». Estilos del modal en `admin/css/xabia-admin.css` (`body.wp-admin .xabia-modal-*`).
- **Seguridad AJAX robusta (admin)**: Helper PHP `admin_json_success()`; respuestas de éxito con **`nonce` dinámico** en `data`; cliente con `xabiaCurrentNonce`, `xabiaSyncNonce(r)` y `xabiaAdminPost()` para encadenar peticiones sin caducar el referer. Cuerpos normalizados: `xabia_list_csv_files` → `{ files }`, `xabia_get_fields` → `{ fields }`, `xabia_test_addon` → `{ columns }`. Playground: `xabia_ask_ai` validado en **`Xabia_API`** (nonce admin o público). Detalle en [§7.1](#71-endpoints-ajax-admin) y [§14.7](#147-asistente-cpt-y-nonce-en-cadena-ajax).
- **Documentación colaboradores**: `CONTRIBUTING.md` en la raíz (estructura, admin/mapeo, prompt LLM, i18n); §15 en esta memoria con enlace.
- **Rediseño del panel de administración (Xabia Agent)**: Nueva hoja `admin/css/xabia-admin.css` (tarjetas blancas, tipografía clara, pestañas tipo producto, verde para primarios y éxito, magenta para detalles). Encolado con dependencia de `wp-color-picker`. Marcado semántico: rejilla de agentes, formulario de claves en tarjeta, layout edición en dos columnas, pestañas como `<button>`, feedback AJAX con clases `xabia-sync-feedback--*`. Documentación en [§14](#14-panel-de-administración-desarrollo-y-diseño).
- **Multi-fuente por proyecto**: Un mismo agente puede usar varias fuentes (p. ej. SQL/CPT + CSV) con mapeos de atributos distintos por fuente. Nueva opción «Multi-fuente (DB + CSV, etc.)» en Fuente de información; array `sources` en config (`type`, `attributes`, `csv_filename` o `sql_config`). Bridge: `process_csv_knowledge` y `process_sql_knowledge` aceptan tercer parámetro opcional `$attributes_override`. Sincronización: borrado de vectores del proyecto y procesado de cada fuente con su mapeo; todos los datos en la misma tabla `xabia_knowledge_vectors`. Ver §5.4 y §11.4.
- **Shortcode dinámico**: Atributos `ente` y `totem`; prioridad de anclaje (URL `item` > shortcode `ente` > URL scope > shortcode scope).
- **Persistencia CSV**: Campo `csv_filename` en proyecto; selector (dropdown) de archivos existentes en `uploads/xabia/`; subida y guardado al guardar proyecto.
- **Botón Borrar Memoria**: Handler JS que llama a `xabia_clear_memory` con nonce y recarga tras confirmación.
- **Detección de Ente**: Prioridad ENTE → role "título" → primera columna; metadatos `__ente_display` para saludos y persona.
- **Modo QR/Museo**: Parámetro `item`; filtro estricto por `ente_id`; prompt en primera persona; `get_ente_display_name()`.
- **Modo Tótem**: Atributo `totem` en shortcode; configuración en Admin (Diseño); temporizador de inactividad; aviso 10 s antes; `xabia_clear_session`; restauración del saludo inicial en UI; estilos `.xabia-totem-warning`.
- **Imágenes en uploads estándar**: Base de imágenes = raíz de `wp-content/uploads` (sin carpeta `xabia`). Endpoint `xabia_resolve_image` (lote, hasta 100 paths); rutas relativas o solo nombre de archivo; resolución por biblioteca de medios en una query; frontend con caché y una petición por mensaje; `buildImageTag()` unificado para `[ACTION:IMG]`, `[IMAGE]` y Markdown.
- **Proveedor único por proyecto (OpenAI vs Vertex)**: Router (Intérprete), embedding de la query y entrenamiento usan el mismo proveedor que el chat según `ai_driver`. Vertex: `gemini-embedding-001` para embeddings; misma auth que Gemini 2.0 Flash. Brain acepta `query_vector` opcional en `search_knowledge_vector`. Si se cambia de proveedor, borrar memoria y volver a entrenar. Ver §13.7.

---

## 13. GOOGLE CLOUD / VERTEX AI

Xabia puede usar **Google Cloud Vertex AI** (modelo **Gemini 2.0 Flash**) como motor de IA en lugar de OpenAI. La configuración es **por proyecto** y requiere un archivo JSON de **Service Account**.

### 13.1 Dónde se configura en Xabia

- **Admin** → Editar agente → Pestaña **Datos** → bloque "Motor de Inteligencia (Driver)".
- Seleccionar **Google Cloud (Vertex AI)**.
- Campo **"Ruta absoluta JSON Google Cloud (Service Account)"**: ruta en el servidor al archivo `.json` de la cuenta de servicio (ej. `/home/usuario/auth/gcloud-key.json`).
- El valor se guarda en la configuración del proyecto: `xabia_projects_config[$id]['gcloud_json_path']` (no dentro de `design`).

**Código**:
- `admin/class-xabia-admin.php`: campo `gcloud_json_path` en el formulario del proyecto; se persiste en la raíz de la entrada del proyecto dentro de `xabia_projects_config[$id]['gcloud_json_path']` (no dentro de `design`).
- `api/class-xabia-api.php`: `call_google_vertex()` lee `$config['gcloud_json_path']`, exige que el archivo exista y que el JSON tenga `project_id`. Con nodos Nexus y chat en Vertex, usa **`generateContent`** multimodelo con **`tools`** / **`functionResponse`** (no solo el prompt monolítico).

### 13.2 Contenido del JSON

El archivo es la **clave de cuenta de servicio** de Google Cloud (descargada desde la consola). Debe contener al menos:

- **`project_id`**: ID del proyecto de GCP (ej. `mi-proyecto-123`).
- Campos típicos de una Service Account key: `type`, `client_email`, `private_key_id`, `private_key`, `client_id`, etc.

Ejemplo mínimo (estructura; no uses este contenido real):

```json
{
  "type": "service_account",
  "project_id": "TU_PROYECTO_ID",
  "private_key_id": "...",
  "private_key": "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n",
  "client_email": "nombre@TU_PROYECTO_ID.iam.gserviceaccount.com",
  "client_id": "...",
  "auth_uri": "https://accounts.google.com/o/oauth2/auth",
  "token_uri": "https://oauth2.googleapis.com/token",
  "auth_provider_x509_cert_url": "https://www.googleapis.com/oauth2/v1/certs",
  "client_x509_cert_url": "..."
}
```

### 13.3 Cómo volver a configurarlo (cuenta nueva, ej. jose.g@digixop.com)

Si cambias de cuenta (p. ej. antes tenías el JSON en `/home/u610697097/xabia-ai-engine/auth/gcloud-key.json` y ahora usas **jose.g@digixop.com**), el proyecto anterior no aparecerá en la nueva cuenta. Hay que crear un **nuevo proyecto** (o usar uno existente) en la cuenta actual:

1. **Entrar en Google Cloud Console** con **jose.g@digixop.com**: https://console.cloud.google.com/
2. **Crear un proyecto** (o elegir uno existente): nombre ej. "Xabia Vertex" → anotar el **ID del proyecto**.
3. **Activar la API de Vertex AI**:
   - En la consola: "APIs y servicios" → "Biblioteca" → buscar **"Vertex AI API"** → Activar (o "Generative Language API" si usas otro flujo; para el código actual basta Vertex AI).
4. **Crear una cuenta de servicio**:
   - "IAM y administración" → "Cuentas de servicio" → "Crear cuenta de servicio".
   - Nombre ej. `xabia-vertex`.
   - Rol: **"Vertex AI User"** (o "Vertex AI Administrator" si lo prefieres).
   - Crear y abrir la cuenta → pestaña "Claves" → "Añadir clave" → "Crear clave nueva" → **JSON** → descargar.
5. **Subir el JSON al servidor** en una ruta que PHP pueda leer y que **no** sea accesible por URL (ej. fuera del `document root`):
   - Ejemplo: `/home/u610697097/private/auth/gcloud-key.json` (o la ruta que use tu hosting para jose.g@digixop.com).
6. **En Xabia**: Editar el agente → Motor "Google Cloud (Vertex AI)" → Ruta absoluta: la ruta del JSON (ej. `/home/u610697097/private/auth/gcloud-key.json`) → Guardar.

### 13.4 Dependencias PHP (Google Auth)

El código usa la clase `Google\Auth\Credentials\ServiceAccountCredentials` y hace `require_once` de un `vendor/autoload.php`. Busca en este orden:

1. `dirname(dirname($json_path)) . '/vendor/autoload.php'` (dos niveles por encima del archivo JSON).
2. Si no existe: `plugin_dir_path(del plugin) . 'vendor/autoload.php'`.

Si no tienes Composer en el plugin, instala las dependencias de Google en la carpeta que prefieras (por ejemplo donde esté el JSON) y coloca el JSON ahí, o instala en el plugin:

```bash
cd /ruta/al/plugin/xabia-agent-core
composer require google/auth
```

Así existirá `vendor/autoload.php` y el plugin lo usará como fallback.

### 13.5 Detalles técnicos del código

- **Región**: `us-central1` (hardcodeada en `call_google_vertex`).
- **Modelo**: `gemini-2.0-flash-001` (Vertex AI).
- **URL**: `https://{location}-aiplatform.googleapis.com/v1/projects/{project_id}/locations/{location}/publishers/google/models/gemini-2.0-flash-001:generateContent`
- **Autenticación**: OAuth2 con la cuenta de servicio; se usa el token de acceso en el header `Authorization: Bearer ...`.

### 13.6 Referencia de la ruta antigua

- Ruta antigua del JSON (cuenta anterior): `/home/u610697097/xabia-ai-engine/auth/gcloud-key.json`
- Ese archivo y proyecto pertenecen a la cuenta anterior. Con **jose.g@digixop.com** hay que usar un **nuevo proyecto** y un **nuevo JSON** descargado desde esa cuenta, y poner en Xabia la **nueva ruta absoluta** del JSON.

### 13.7 Proveedor único por proyecto (OpenAI vs Vertex)

Cada proyecto tiene un **único motor de IA** definido en `ai_driver` (`openai` o `google_cloud`). Ese valor determina **todas** las llamadas relacionadas con ese proyecto: chat, router (Intérprete) y embeddings. Así se evitan mezclas de modelos y se mantiene coherencia de coste, latencia y privacidad.

| Componente | `ai_driver` = OpenAI | `ai_driver` = Google Cloud |
|------------|----------------------|----------------------------|
| **Chat (respuesta)** | `call_openai()` — GPT-4o | `call_google_vertex()` — Gemini 2.5 Flash (con nodos Nexus: bucle **`functionCall`** / **`ask_federated_node`**) |
| **Router mini + resumen historial** | `call_auxiliary_llm()` → `gpt-4o-mini` | `call_auxiliary_llm()` → Vertex `gemini-2.5-flash` (solo `own_infra` + JSON) |
| **Router (Intérprete léxico)** | `call_openai()` — `gpt-4o-mini`, ~200 tokens | `call_google_vertex()` — mismo auth y región |
| **Embedding de la query** (búsqueda vectorial) | `Xabia_Brain::get_embedding()` — `text-embedding-3-small` (+ caché `Xabia_Embedding_Cache`) | Vertex AI `gemini-embedding-001:predict` (+ caché) |
| **Entrenar (Admin)** | OpenAI Embeddings API por cada chunk | Vertex `gemini-embedding-001:predict` vía `Xabia_Api::get_embedding_for_project()` |

**Flujo en código**:

1. **API – Chat**  
   Carga `$config` del proyecto. Si `use_vector_search`: no llama al router; genera término = mensaje del usuario. Para recuperación vectorial: `$query_vector = Xabia_API::get_query_embedding($search_term, $config)` (Vertex u OpenAI según `ai_driver`) y lo pasa a `Xabia_Brain::search_knowledge_vector(..., $query_vector)`.

2. **API – Router**  
   `expand_user_query_generic(..., $config)`: si `ai_driver === 'google_cloud'` llama a `call_google_vertex()` con el prompt del Intérprete; si no, a `call_openai()`.

3. **Brain**  
   `search_knowledge_vector(..., $query_vector = null)`: si se recibe `$query_vector`, lo usa para la similitud; si es `null`, llama a `get_embedding($query)` (OpenAI) por compatibilidad.

4. **Admin – Entrenar**  
   `handle_train_ai_ajax()` carga la config del proyecto. Si `ai_driver === 'google_cloud'`, requiere la clase API y usa `Xabia_API::get_embedding_for_project($chunk, $project_id)` para cada fila sin vector y guarda en `vector_data`. Si no, usa la llamada directa a la API de OpenAI como antes.

**Importante**:

- Los vectores almacenados en `vector_data` deben haberse generado con **el mismo proveedor** que usará el proyecto en tiempo de ejecución. Si se cambia `ai_driver` de un proyecto (p. ej. de OpenAI a Google Cloud), hay que **Borrar memoria** y **volver a ejecutar «Entrenar»** para regenerar todos los embeddings con el modelo correcto; de lo contrario la similitud entre query (nuevo modelo) y vectores (modelo antiguo) no es comparable.
- Vertex usa la misma autenticación (JSON de Service Account y `vendor`) para chat y para embeddings; el helper `get_google_vertex_auth($config)` centraliza el token para **`get_vertex_embedding`** y para el **chat Vertex con bucle de herramientas**; la ruta legado de chat sin herramientas sigue obteniendo el token en el mismo `try` que prepara el prompt monolítico.
- Modelo de embedding en Vertex: **`gemini-embedding-001`**, endpoint `:predict`, cuerpo `instances: [{ content: "texto" }]`, respuesta `predictions[0].embeddings.values`.

---

## 14. PANEL DE ADMINISTRACIÓN (DESARROLLO Y DISEÑO)

Esta sección sirve para **incorporar a un desarrollador**: dónde está el código de la UI de administración principal, cómo está organizado el HTML/CSS, cómo se encolan los assets y cómo extender la interfaz sin romper el escritorio de WordPress.

### 14.1 Objetivos del diseño

- **Legibilidad y densidad controlada**: tarjetas con fondo blanco, padding generoso, bordes finos y sombras muy suaves (referencia de producto: *Google Site Kit*).
- **Compatibilidad con WordPress**: se siguen usando clases del core donde aplica (`wrap`, `widefat`, `button`, `button-primary`, `postbox`, `description`, `wp-color-picker` en campos de color).
- **Aislamiento visual**: las reglas CSS del plugin están **acotadas** a `.xabia-wrapper.xabia-admin-app` para no modificar menús, listas u otras pantallas de `wp-admin`.
- **Semántica de color**:
  - **Verde** (`#1e8e3e` y derivados): acciones primarias dentro del área del plugin, estados de éxito, enlaces de acción en el chat del playground, realimentación positiva de sincronización/entrenamiento.
  - **Magenta** (`#c2185b` y derivados): acentos de “detalle” (pestaña activa, IDs de proyecto, nombres de columna en el mapeo, `code`, bloque del motor IA, mensajes de usuario en el laboratorio).

### 14.2 Archivos implicados

| Ruta | Contenido |
|------|-----------|
| `admin/class-xabia-admin.php` | Clase `Xabia_Admin`: menú, controladores POST/GET, todos los handlers AJAX de administración, método `render_view()` (markup + `<script>` inline con jQuery) |
| `admin/css/xabia-admin.css` | Variables CSS, componentes (tarjetas, pestañas, tablas de log, chat playground, formularios) |
| `xabia-intelligence.php` | Define `XABIA_URL` y `XABIA_VERSION`, usados al encolar `xabia-admin.css` |

No hay bundler ni SASS: el CSS es un archivo estático versionado con `XABIA_VERSION` en la query de caché del navegador.

### 14.3 Encolado de estilos (`load_assets`)

1. Comprobar que el hook de pantalla corresponde a la página del plugin (`strpos($hook, 'xabia-settings') !== false`).
2. Encolar en orden: `dashicons`, `wp-color-picker` (estilo), luego **`xabia-admin`** con dependencia `['wp-color-picker']`.
3. Encolar script `wp-color-picker` para los inputs con clase `xabia-color-field` (inicialización en el JS inline: `$('.xabia-color-field').wpColorPicker()`).

**Motivo del orden**: los estilos del color picker de WordPress cargan antes que `xabia-admin.css`, de modo que las sobrescrituras de botones y layout del plugin tienen prioridad en la cascada.

### 14.4 Estructura del DOM (vistas)

**Raíz común**

```html
<div class="wrap xabia-wrapper xabia-admin-app">
```

- WordPress añade `.wrap`; el plugin añade `.xabia-wrapper.xabia-admin-app` para el scope CSS.

**Vista listado** (`edit` ausente en la query)

1. **Cabecera**: `.xabia-card.xabia-admin-header` — título (`Xabia Agent` en listado o nombre del agente en edición), subtítulo distinto según contexto, y enlace «Volver al listado» **solo** en vista de edición.
2. **Barra de acción**: `.xabia-toolbar` — enlace/botón "Nuevo agente" (`button button-primary`).
3. **Rejilla de proyectos**: `.xabia-agent-grid` con una `.xabia-card.xabia-agent-tile` por proyecto (`xabia-agent-tile__name`, `xabia-agent-tile__id`, acciones Editar / Borrar).
4. **Claves globales**: `.xabia-card.xabia-keys-form` — título, descripción, `<form>` con grupos `.xabia-field-group` y pie `.xabia-form-actions`.

**Vista edición** (`edit=new` o `edit=<id>`)

1. Misma cabecera; el título usa el nombre del agente (o "Nuevo agente" si aún no hay datos).
2. **Layout de dos columnas**: `.xabia-edit-layout`
   - **Columna principal**: `.xabia-main-card.postbox` > `.xabia-card-inner`
     - **Pestañas**: contenedor `.xabia-tab-nav` con elementos `<button type="button" class="xabia-tab-btn" data-tab="tab-...">` (accesibles frente a los antiguos `div`).
     - **Paneles**: `.xabia-tab-content` con `id="tab-data"`, `tab-design`, `tab-history`; la clase `active` alterna visibilidad (controlado por jQuery).
     - **Pie del formulario**: `.xabia-card-footer` — submit "Guardar agente".
   - **Columna lateral**: `<aside class="xabia-sidebar">` (solo si el proyecto no es `new`). Desde **v1.0.165** CSS `position: static` (scroll de página; ya no sticky).
     - `.xabia-status-box`: contadores, botones sincronizar / entrenar, `#sync-feedback`, botón borrar memoria.
     - `.xabia-playground-card`: cabecera, `#p-chat-canvas`, fila `.xabia-playground-input-row`.

**Bloques reutilizables dentro de Datos**

- Motor IA: `.xabia-vertex-box`
- Conexión SQL remota (cuando aplica): `#section-sql-remote-fields.xabia-panel-muted` (visibilidad con `display` inline según tipo de fuente)
- Fuentes multi: `.xabia-multi-source-box` por cada fuente
- Filas de mapeo: `.row-repeater-box`, `.row-col-select-wrap`, `<select class="xabia-col-selector">` (nombres de formulario `attributes[…][csv_col]` o `sources[…][attributes][…][csv_col]`), `.row-inputs`, `.check-ente-wrapper`
- SQL: `textarea.sql-box` (tema oscuro monoespaciado)

### 14.5 Variables y tokens CSS (`xabia-admin.css`)

Definidas en `.xabia-wrapper.xabia-admin-app` (heredan descendientes):

| Variable | Uso |
|----------|-----|
| `--xabia-page-bg`, `--xabia-card-bg` | Fondo de página vs tarjeta |
| `--xabia-border`, `--xabia-border-subtle` | Bordes de tarjetas y separadores |
| `--xabia-text`, `--xabia-text-secondary` | Texto principal y ayuda |
| `--xabia-green`, `--xabia-green-hover`, `--xabia-green-soft`, `--xabia-green-border` | Primarios y estados de éxito |
| `--xabia-magenta`, `--xabia-magenta-hover`, `--xabia-magenta-soft` | Acentos y detalles |
| `--xabia-radius`, `--xabia-radius-sm` | Radios de borde |
| `--xabia-shadow`, `--xabia-shadow-hover` | Elevación de tarjetas |

Para un nuevo componente, preferir reutilizar estas variables antes de introducir colores sueltos.

### 14.6 JavaScript inline (admin)

Todo el JS específico de esta pantalla vive al final de `render_view()` dentro de un `jQuery(document).ready(...)`. Responsabilidades principales:

| Área | Comportamiento |
|------|----------------|
| **Pestañas** | Click en `.xabia-tab-btn`: quita `active` de todos los botones y `.xabia-tab-content`, añade `active` al botón pulsado y al `#` + `data-tab`. |
| **Tipo de fuente** | `#xabia-source-select`: muestra/oculta secciones `#section-csv`, `#section-sql`, `#section-addon`, `#section-multi`, `#section-sql-remote-fields`, y el mapeo único vs multi. |
| **CSV** | `loadCsvFiles` / `loadMultiCsvOptions` → `xabia_list_csv_files` vía `xabiaAdminPost`; la respuesta usa `data.files` (compatibilidad con array plano antiguo en el helper de lista). Feedback en `#csv-feedback` y `.multi-csv-feedback`. |
| **Motor** | `#ai_driver_select` muestra/oculta `#gcloud_json_wrapper`. |
| **Tests SQL / addon / scan** | `xabiaAdminPost` hacia `xabia_test_sql`, `xabia_test_addon`, `xabia_get_fields`; repintan atributos (`renderMapping`, `renderMultiMapping`). `xabia_get_fields` entrega `data.fields`. |
| **Memoria** | `xabia_sync_content`, `xabia_train_ai`, `xabia_clear_memory` mediante `xabiaAdminPost`. |
| **Feedback** | Función `xabiaSetSyncFeedback(msg, kind)` con `kind` en `success` \| `error` \| `pending`; alterna clases en `#sync-feedback`: `xabia-sync-feedback--success`, `--error`, `--pending`. |
| **Playground** | Saludo inicial con `parseChatVisualTags`; envío `xabia_ask_ai` con **`nonce: xabiaCurrentNonce`** (vía `xabiaAdminPost`); callback con **`xabiaSyncNonce(r)`**; Enter en `#p-input` evita submit del formulario (no recargar la página). |
| **Color picker** | `$('.xabia-color-field').wpColorPicker()`. |
| **Contraseña SQL** | Toggle tipo texto/password en `#sql_pass`. |

### 14.7 Asistente CPT y nonce en cadena (AJAX)

**Asistente CPT**  
En la pestaña **Datos**, si la fuente es SQL (una sola o dentro de **multi-fuente**), junto a los botones de prueba de consulta hay **Asistente CPT**. Abre un modal (`#xabia-cpt-assistant-modal`, backdrop `#xabia-cpt-assistant-backdrop`) que:

1. Llama a **`xabia_get_wp_schema`** sin `post_type` y rellena un desplegable con los **tipos de contenido públicos** (`post_types[].name` / etiqueta).
2. Al elegir un tipo, vuelve a llamar al mismo endpoint con **`post_type`** y obtiene **`meta_keys`** (claves distintas en `postmeta` para ese tipo; incluye datos que ya estén guardados en BD, p. ej. ACF).
3. Un **`<select multiple>`** combina columnas habituales de la tabla `posts` (optgroup) con las meta keys (otro optgroup). Al cambiar tipo o selección, el JS genera un **`SELECT`** con placeholder **`{prefix}`** (columnas core como `p.\`campo\``, metas como subconsultas escalar a `{prefix}postmeta`) y lo escribe en `#sql_query` o en el `textarea` de la fuente multi correspondiente. El administrador puede editar la consulta y usar **Test SQL** como siempre.
4. **Amelia (servicios en tabla)**: Si el addon Amelia está activo, el desplegable puede incluir el tipo virtual **`xabia_amelia_services`**. Al seleccionarlo, el SQL generado apunta a **`{prefix}amelia_services`** (columna canónica **`Ente`** = `name`). Aparece el bloque **buscador de servicios** (lista filtrable, clic copia slug `amelia-svc-{id}` para `?item=` / modo estricto); datos vía AJAX **`xabia_reservas_amelia_services`**.

**Nonce en cadena**  
Al cargar la vista de edición, el script define **`xabiaCurrentNonce`** con el token inicial (`wp_create_nonce` / `wp_json_encode` en PHP). **`xabiaAdminPost(payload, callback)`** asigna siempre `payload.nonce = xabiaCurrentNonce` antes del `$.post`. En el callback, **`xabiaSyncNonce(r)`** lee `r.data.nonce` (si es un string no vacío) y actualiza **`xabiaCurrentNonce`**. Así, tras cada respuesta exitosa (y en varios errores que devuelven `nonce` en `data`), la siguiente petición admin envía un token válido y alineado con la comprobación del servidor, reduciendo fallos por nonce caducado en secuencias largas (varios scans, sync, train encadenados, pasos del asistente, etc.).

**Backend**: las respuestas de éxito consolidadas pasan por **`admin_json_success()`** ([§3.4](#34-admin-admin)); véase la tabla de formas de `data` en [§7.1](#71-endpoints-ajax-admin).

### 14.8 Cómo extender la UI sin romper el admin

1. **Nuevos bloques**: envolver en `.xabia-card` o subclases coherentes; mantener el contenedor padre `.xabia-wrapper.xabia-admin-app`.
2. **Nuevos estilos**: añadir reglas en `admin/css/xabia-admin.css` **prefijadas** con `.xabia-wrapper.xabia-admin-app` (o hijos directos ya bajo ese árbol).
3. **Nuevos endpoints AJAX**: registrar en `init()` con `add_action('wp_ajax_...')`, comprobar capacidad `manage_options` o la política que se acuerde, y **siempre** `check_ajax_referer('xabia_admin_nonce', 'nonce')` como en los handlers existentes. En **éxito**, usar **`admin_json_success()`** (u otro patrón que añada `data.nonce`) para no romper el cliente que encadena peticiones; ver [§7.1](#71-endpoints-ajax-admin) y [§14.7](#147-asistente-cpt-y-nonce-en-cadena-ajax).
4. **No** depender de estilos globales de `wp-admin` para “arreglar” el layout del plugin: si hace falta un override, hacerlo scoped.
5. **i18n**: el código de la vista usa funciones `esc_html__`, `esc_attr__`, etc., con text domain `xabia-intelligence` (el plugin puede añadir en el futuro `load_plugin_textdomain` si se distribuyen traducciones).

### 14.9 Checklist para revisión de PR (admin)

- [ ] ¿Los nuevos estilos están bajo `.xabia-admin-app`?
- [ ] ¿Se ha encolado algún asset solo en `xabia-settings`?
- [ ] ¿Los formularios sensibles (claves) usan `autocomplete="off"` donde corresponde?
- [ ] ¿Las redirecciones POST usan `admin_url()` / `esc_url()`?
- [ ] ¿Los AJAX de administración usan `check_ajax_referer('xabia_admin_nonce', 'nonce')` y, en éxito, **`admin_json_success()`** (o equivalente que añada `data.nonce`) para no romper la cadena de nonces en el cliente?
- [ ] ¿El JS usa `xabiaAdminPost` (o replicar `nonce` + `xabiaSyncNonce`) y contempla `r.success` y las claves nuevas de payload (`files`, `fields`, `columns`) donde aplique?

---

## 15. DOCUMENTACIÓN PARA COLABORADORES (GITHUB)

Para **ponerse al día con el repo** sin depender de conversaciones sueltas (estructura de carpetas, guardado del admin y shape de `xabia_projects_config`, plantilla y límites del prompt al LLM, estado real del i18n), existe una guía mantenida en la raíz del proyecto:

**[CONTRIBUTING.md](./CONTRIBUTING.md)** (raíz del repositorio)

Incluye: árbol de directorios y puntos de extensión de addons, tabla resumen del POST `save_project`, resumen del pipeline `handle_chat_request` / `build_system_prompt` / `format_context_from_rows`, y la situación de `.po` vs `load_plugin_textdomain` y el atributo `lang` del shortcode.

La **memoria técnica** (este documento) sigue siendo la referencia profunda de arquitectura, flujos RAG, Vertex y base de datos; CONTRIBUTING es el **onboarding rápido** para desarrolladores que trabajan en GitHub.

Para **usuarios finales y administradores de WordPress** (instalación, mapeo, shortcode, modos QR/tótem): **[manual-usuario-xabia-core.md](./xabia-agent-plugins/documentation/manual-usuario-xabia-core.md)**. Para un **recorrido de ingeniería** centrado en código (boot, pipeline de chat, filtros, HMAC): **[DESARROLLO.md](./xabia-agent-plugins/documentation/DESARROLLO.md)**. Índice de documentación de producto: **[documentation/README.md](./xabia-agent-plugins/documentation/README.md)**.

---

## 16. DISTRIBUCIÓN, PAQUETE ZIP E INSTALACIÓN EN WORDPRESS

### 16.1 Versión publicada

La **versión del producto** visible en WordPress (listado de plugins) y en recursos encolados con `XABIA_VERSION` está definida en la cabecera del archivo principal y en la constante:

- **`xabia-intelligence.php`**: comentario `Version:` y `define('XABIA_VERSION', '…')` deben mantenerse **alineados** (actualmente **1.0.0**).
- La **descripción** del plugin en la misma cabecera resume capacidades clave (RAG, Xabia Central, WooCommerce, reservas MEC/Amelia, etc.) para el listado de plugins.

### 16.2 Generar los ZIP de instalación

**Venta / documentación embebida (recomendado para entregar al cliente):** desde la raíz del repositorio:

```bash
chmod +x scripts/build-retail-plugin-zips.sh
./scripts/build-retail-plugin-zips.sh
```

Genera, en **`xabia-agent-plugins/dist/retail/`**:

- `xabia-agent-core-<versión>-retail.zip` — carpeta raíz **`xabia-agent-core/`**, incluye **`vendor/`** si existe en el árbol de origen, más **`MEMORIA_TECNICA.md`**, **`CONTRIBUTING.md`**, y **`docs/`** (manuales modulares, stub `MANUAL_USUARIO.md` deprecado, índice y **`DESARROLLO.md`** copiados desde `xabia-agent-plugins/documentation/`).
- `xabia-avirato-<versión>-retail.zip` — carpeta raíz **`xabia-avirato/`** (addon; requiere Core activo).

Los plugins se toman de **`xabia-agent-plugins/packages/`**; si ese árbol no existe, los scripts pueden usar **`plugins/`** como respaldo heredado. Requisitos: **`rsync`**, **`zip`**, Bash.

**ZIP rápido (solo carpeta del plugin, sin copiar memoria/manual desde la raíz):**

```bash
chmod +x scripts/build-plugin-zip.sh
./scripts/build-plugin-zip.sh
```

Escribe en **`xabia-agent-plugins/dist/`** archivos del estilo `xabia-agent-core-<versión>.zip` para cada slug configurado en el script.

### 16.3 Instalación en WordPress

1. **Subir plugin**: `Plugins` → `Añadir nuevo` → `Subir plugin` → elegir el ZIP → `Instalar ahora` → `Activar`.
2. **O manualmente**: descomprimir el ZIP en `wp-content/plugins/` (debe quedar `wp-content/plugins/xabia-agent-core/xabia-intelligence.php`).
3. Tras activar, las tablas y hooks de activación se ejecutan según `xabia-intelligence.php` (incl. `do_action('xabia_install_addon_tables')` para addons).

### 16.4 Dependencias PHP opcionales

Si el proyecto usa **Google Cloud** (Vertex), hace falta **`vendor/autoload.php`**. El **ZIP retail** del Core incluye `vendor/` cuando ya está presente en `xabia-agent-plugins/packages/xabia-agent-core/`; si instalas un ZIP mínimo sin `vendor/`, ejecuta **`composer install`** en la carpeta del plugin en el servidor o sigue [§13.4](#134-dependencias-php-google-auth).

### 16.5 Documentación incluida en el paquete

El **ZIP retail** del Core incorpora **`MEMORIA_TECNICA.md`**, **`CONTRIBUTING.md`**, documentación copiada desde **`xabia-agent-plugins/documentation/`** (manual de usuario, índice, desarrollo), el **`README.md`** propio del Core, **`integrations/central/README.md`** en el árbol del código, y el código (**incluida la carpeta `addons/`** con MEC, Amelia, QR, federación, etc.). El **ZIP retail** de Avirato incluye su **`README.md`** y **`docs/manual-usuario.md`** (carpeta interna del addon); la visión global del producto va en el paquete del Core. El **hub** en **`xabia-agent-plugins/central-api/`** no se incluye en estos ZIP salvo que se empaquete aparte.

---

## 17. DOCUMENTACIÓN COMPLEMENTARIA (MANUAL Y DESARROLLO)

Además de esta memoria y de [CONTRIBUTING.md](CONTRIBUTING.md), el repositorio incluye:

| Archivo | Contenido |
|---------|-----------|
| [manual-usuario-xabia-core.md](xabia-agent-plugins/documentation/manual-usuario-xabia-core.md) | Manual de usuario canónico (no-code): instalación, mapeo, sync, Smart QR, WPML, Wallet. `MANUAL_USUARIO.md` está deprecado. |
| [DESARROLLO.md](xabia-agent-plugins/documentation/DESARROLLO.md) | Guía de desarrollo: arranque, pipeline de chat, ingestión, filtros (§6 + **§12** HMAC/MEC/Woo), frontend, admin, empaquetado. |
| [README (índice doc.)](xabia-agent-plugins/documentation/README.md) | Índice de `xabia-agent-plugins/documentation/` y enlaces a planes de estrategia y federación; tabla resumen Core / `integrations/` / `addons/` / hub. |
| [central-api/README.md](xabia-agent-plugins/central-api/README.md) | Hub Xabia: proxy Vertex, licencias, wallet, firma HMAC por licencia, campo **`user_lang`** en el JSON del chat. |

**Público objetivo:** el manual está orientado a **administradores** y editores; la guía de desarrollo, a **ingeniería** que mantenga o extienda el plugin.

---

**Versión del documento**: 1.0.0 (alineada al producto **v1.0.0**)  
**Última actualización**: Abril 2026 — Lanzamiento comercial **v1.0.0**; documentación manual **v1.1.0** (federación + Vertex tools); §3.6 Core + `integrations/` + `addons/` (QR **`addons/xabia-qr/`**, MEC **`addons/xabia-mec/`**, federación **`addons/xabia-federation/`**; sin `integrations/qrs/` ni `integrations/mec/`); §7.2 `user_lang` y hub Vertex con **`tools`** + **`ProxyHandler`** (identidad federada); Amelia descubrimiento; §17 y `xabia-agent-plugins/central-api/README.md`.
