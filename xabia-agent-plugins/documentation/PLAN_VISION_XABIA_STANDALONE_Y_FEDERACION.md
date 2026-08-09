# Plan: Visión Xabia — Standalone, Fuentes Universales, Addons y Módulo de Federación

**Versión:** 1.2  
**Fecha:** Abril 2026 (revisión alineada con **Xabia Agent Next v1.0.0** — Core + `addons/`, `user_lang`, hub)  
**Base:** Whitepaper v35, Documento de bienvenida, Dossier técnico comercial (260122, 260127, Documento sin título).  
**Comercial:** [Xabia — Precio](https://xabia.ai/precio/) (Plugin, Entity, Connect, **Central** = redes federadas + Standalone).

**Nota de sincronización doc ↔ código:** la federación **tipo Xabia Central** (nodos pull/push, ingesta, cron) **está implementada** en `integrations/central/`. Este plan conserva la visión de producto; las tablas de estado y el Eje C distinguen lo **hecho** de lo **pendiente o evolutivo**.

**Documentación en este repo:** [README.md](./README.md) (índice), [MEMORIA_TECNICA.md](../../MEMORIA_TECNICA.md) (referencia técnica), [manual-usuario-xabia-core.md](./manual-usuario-xabia-core.md) (manual de usuario), [DESARROLLO.md](./DESARROLLO.md) (ingeniería).

---

## 1. Resumen de la visión (según adjuntos)

- **Xabia no es solo un plugin WP**: es un “cerebro” desacoplado que debe poder instalarse en cualquier servidor y trabajar con **las fuentes del cliente** (DB propia, Excel, APIs, etc.).
- **Universal y generalizable**: mapeo semántico, fuentes agnósticas (CSV, SQL remoto, addons), múltiples verticales (turismo, retail, museos, farmacia, RRHH) sin cambiar código base.
- **Privacidad y soberanía**: datos en casa del cliente; solo se envían a la IA fragmentos necesarios para la inferencia; coste marginal cero frente a “peaje por resolución”.
- **Tres líneas de trabajo** (standalone y Tools siguen pendientes a nivel “kernel”; federación base **ya existe** como addon):
  1. **Standalone**: módulo que corre fuera de WordPress, API REST, widget JS inyectable para cualquier web (Shopify, Wix, HTML). **Sigue sin existir** como producto desacoplado del core WP.
  2. **Addons “transaccionales” / Tools**: hoy hay sync RAG, reglas de prompt, tags de acción (**`[ACTION:CART:ID]`**, **`[ACTION:BOOK:ID]`**), disponibilidad básica (`Xabia_Reservas_Handler`) y AJAX asociados; **falta** un contrato formal de **Tools** (registro, schema, invocación desde la API cuando la IA elija herramienta).
  3. **Modelo federado**: la **ingesta federada pull/push** con nodos, normalización canónica, prefijo `Fuente:` en `content_chunk`, cron y pantalla **Federación** está en el **addon Xabia Central** (`integrations/central/`). **Queda** evolucionar protocolos genéricos entre nodos no WordPress, coordinador de agregados, licenciamiento duro en código, etc. Comercialmente: `xabia-agent-plugins/documentation/FEDERACION_COMO_ADDON_Y_COMERCIAL.md`.

---

## 2. Estado actual (breve)

| Área | Estado |
|------|--------|
| **Core RAG** | Brain (vector + LIKE), DB Bridge (CSV, SQL, addons como fuente SQL), API (chat, sesión, router, Vertex/OpenAI). |
| **Fuentes** | CSV, SQL remoto, Addons (MEC, Amelia en `addons/`, Woo, **QR** en `addons/xabia-qr/`) como **origen de datos** (callback SQL / sync). **Multi-fuente por proyecto** implementado: un mismo agente puede combinar varias fuentes (p. ej. CPT + CSV) con mapeos distintos; ver `MEMORIA_TECNICA.md` §5.4 y §11.4. |
| **Addons** | Registro como “SQL source”; verticales **MEC**, **Amelia**, **Avirato**, **WooCommerce**, **QR** en **`addons/xabia-*/`**; reservas unificadas MEC/Amelia en `integrations/reservas/`. **Parcial**: acciones vía tags y handlers; **falta** capa **Tools** genérica invocable por la API con contrato estable (ver Eje B). |
| **Standalone** | No existe: todo depende de WordPress (options, wpdb, admin-ajax). |
| **Federación (Xabia Central)** | **Implementado** como addon en `integrations/central/`: nodos pull/push, formato canónico o raw+mapeo, upsert por `(project_id, federation_node_id, ente_id)`, ingest AJAX `xabia_central_ingest`, cron `xabia_central_hourly_sync`, columna `federation_node_id` en vectores, menú **Federación** en admin. Detalle: `MEMORIA_TECNICA.md` §8.2, `integrations/central/README.md`. **Opcional en nodo WordPress+MEC:** REST `xabia/v1/federation-events` (`addons/xabia-mec/xabia-federation-bridge-mec.php`) para exponer eventos publicados a una central que haga pull. **No implementado** aquí: coordinador multi-nodo genérico, protocolo `/federation/aggregate` estándar, standalone. |

---

## 3. Plan en tres ejes

### Eje A — Standalone (módulo instalable en cualquier servidor)

**Objetivo:** Núcleo ejecutable fuera de WP: API REST, config por proyecto, mismas capacidades de Brain + ingesta.

**Ideas clave (de los adjuntos):**

- “El núcleo PHP podrá alojarse en un servidor independiente, exponiendo una API REST.”
- “Widget JS inyectable (snippet 2 líneas)” para que cualquier web consuma Xabia sin WP.
- “Conectividad DB universal”: DB Bridge capaz de mapear esquemas ajenos (MySQL, PostgreSQL, incluso Excel vía FTP).

**Plan de trabajo (propuesta):**

1. **Extraer “Core agnóstico”**
   - Identificar todo lo que depende de WP: `get_option`, `$wpdb`, `admin_url`, nonces, etc.
   - Introducir una capa **Adapter** (interfaz): `ConfigProvider`, `DatabaseAdapter`, `AuthAdapter`. En WP, el adapter usa `get_option`/`$wpdb`; en standalone, usa archivo/ENV + PDO o MySQLi.
   - Mover Brain, DB Bridge y lógica de API (flujo chat, router, embeddings) a un “kernel” que solo use esos adapters.

2. **API REST unificada (standalone)**
   - Endpoints mínimos: `POST /chat`, `GET/POST /sync` (ingesta), `GET /projects`, etc.
   - Autenticación: API key por proyecto o por cliente, configurable.
   - Respuestas JSON; mismo contrato que el frontend espera hoy (para reutilizar el mismo widget).

3. **Widget JS “inyectable”**
   - Un script que cargue el chat (ej. `https://tu-xabia.ejemplo.com/widget.js`).
   - Parámetros: `apiOrigin`, `projectId`, `lang`, `scope`, `ente`, etc.
   - Que funcione tanto contra `admin-ajax.php` (WP) como contra la API REST del standalone.

4. **Fuentes en standalone**
   - DB Bridge ya soporta SQL remoto y CSV; en standalone la “config” del proyecto vendría de fichero o de una tabla “config” en la misma DB.
   - Addons que dependan de WP (Woo, MEC, Amelia) en standalone no estarían disponibles salvo que se expongan vía API externa y un “connector HTTP” en el Bridge.

**Entregables:** (1) Repo o carpeta `xabia-standalone` con kernel + adapters + API REST; (2) Widget JS parametrizado; (3) Documentación de despliegue (PHP + extensión PDO/MySQLi, variables de entorno).

---

### Eje B — Addons nativos (MEC, Amelia, WooCommerce) como Tools

**Objetivo:** Que la IA no solo busque en conocimiento estático, sino que **consulte y ejecute** en sistemas externos (stock, disponibilidad, reservas, eventos).

**Ideas clave (de los adjuntos):**

- “Endpoints de Consulta (GET) y de Ejecución (POST)”.
- “Cuando la IA detecta una intención de reserva, el motor debe disparar el endpoint del conector.”
- Niveles: 1) RAG; 2) Consulta datos dinámicos; 3) Ejecuta acciones.

**Plan de trabajo (propuesta):**

1. **Definir contrato “Tool” en la API**
   - Un **Tool** = nombre, descripción (para el LLM), parámetros (schema), y un callback PHP o URL.
   - La API mantiene un registro de Tools por proyecto (o global): `xabia_tools` o en `rules['tools']`.
   - En el system prompt se inyecta: “Tienes estas herramientas: [lista]. Si el usuario pide X, usa la herramienta Y con parámetros Z.”

2. **Flujo de decisión IA → Tool**
   - Opción A: La IA devuelve en la respuesta un bloque estructurado tipo `[TOOL:nombre:params]` y el backend lo interpreta y llama al conector.
   - Opción B: Dos fases: (1) IA responde “necesito disponibilidad de Amelia”; (2) Backend llama al addon, inyecta resultado en contexto y (3) segunda llamada a la IA para la respuesta final.
   - Recomendación: empezar por Opción A (una sola vuelta) con un formato acordado en el prompt.

3. **Implementar Tools por addon**
   - **WooCommerce**: `get_product_data(product_id)`, `search_products(query, filters)`, `add_to_cart(product_id, qty)` (si se desea). Lectura de catálogo ya vía sync; aquí añadir “consulta en tiempo real” y acción.
   - **Amelia**: `get_services()`, `get_availability(service_id, date)`, `create_booking(service_id, datetime, customer_info)`. Tabla de atribución ya existe; falta exponer como Tool.
   - **MEC**: `get_events(filters)`, `get_event(event_id)`. Ya hay callback SQL; añadir Tool de “consulta dinámica” para el chat.

4. **Admin**
   - En el proyecto: activar/desactivar Tools por addon; opcionalmente listar parámetros (ej. qué servicios de Amelia están disponibles para reserva).

**Entregables:** (1) Contrato Tool en API (registro, invocación); (2) Implementación de al menos un Tool por addon (Woo, Amelia, MEC); (3) Documentación para que terceros registren Tools.

---

### Eje C — Módulo de Federación

**Objetivo:** Que Xabia pueda operar en modo “federado”: múltiples instancias, múltiples fuentes de verdad, y (opcional) colaboración entre nodos o agregación controlada, con **privacidad por diseño**.

**Estado respecto al código (abril 2026):** el **bloque principal de ingesta federada en la instalación central** (addon **Xabia Central**) coincide con las fases 1–2 del plan original en lo esencial: modelo canónico en tabla de vectores, nodos, pull/push, normalización y atribución `Fuente:`. Las **fases 3–4** (protocolo genérico de agregados entre nodos, coordinador opcional) siguen siendo **diseño / roadmap**, no un único endpoint estándar expuesto en todos los nodos.

**Ideas clave (de los adjuntos):**

- “Federación de Conocimiento”: instancias independientes (carrito, soporte, RRHH), fuentes de verdad híbridas, identidad vocal/tonal por instancia.
- “Expandir el conocimiento de Xabia hacia las **federaciones de empresas**” (ej. Aktiba = muchas empresas).
- “SaaS federado y privado”: un despliegue puede servir a varios clientes/webs; cada uno con sus datos.

**Interpretación del “modelo federado” (para el plan):**

1. **Federación interna (multi-instancia / multi-fuente)**  
   Varios `project_id` y, por proyecto, **multi-fuente** (varias fuentes con mapeos distintos hacia la misma tabla de vectores). El “módulo federación” del plan original puede seguir formalizando:
   - **Grupos o “Federaciones”**: un grupo = conjunto de proyectos que comparten algo (marca, cliente, territorio). No implica compartir datos crudos; sirve para UI, facturación o políticas.
   - **Fuente de verdad híbrida por proyecto**: un mismo proyecto con **varias fuentes** (`source_type` = `multi`) ya está soportado en el Bridge; opcional futuro: prioridad explícita entre fuentes o deduplicación avanzada por `ente_id` entre nodos federados.

2. **Federación entre nodos (multi-servidor)**  
   **Parcialmente cubierto** por Xabia Central: la matriz hace pull a URLs o recibe push; los datos acotados que cada nodo expone o envía se unifican en central. Queda como evolución: varios despliegues que colaboran **solo con metadatos agregados** sin compartir filas completas:
   - **Solo metadatos agregados**: cada nodo expone, por ejemplo, conteos o “índice público” (nombres de categorías, sin datos personales). Un nodo “coordinador” o el widget puede preguntar “¿cuántas empresas hay en total?” y sumar respuestas sin que ningún nodo comparta su base completa.
   - **Privacidad**: ningún nodo envía a otro filas completas de conocimiento; solo lo acordado (conteos, listas de IDs públicos, etc.).

3. **Federación “federación de empresas” (caso Aktiba)**  
   Una sola instancia (o una federación interna) donde el conocimiento proviene de muchas empresas (130 empresas). Cada empresa puede ser un “ente”; el Smart QR / scope ya permite “hablar con una empresa”. La federación añade:
   - Posibilidad de que “la federación” tenga un proyecto que agregue solo datos públicos o resúmenes de todas las empresas.
   - O que cada empresa tenga su “mini Xabia” (su ente) y un nivel superior que solo consulte índices (conteos, categorías) para respuestas tipo “¿cuántas empresas de agua hay?”.

**Plan de trabajo del Módulo de Federación (propuesta):**

#### Fase 1 — Modelo de datos y concepto

- Definir entidad **“Federación”** (o “Grupo”): id, nombre, tipo (`internal` | `network`), configuración (JSON).
- Asociar proyectos a una federación (opcional). Si no hay federación, el proyecto se comporta como hoy.
- Documentar: “Federación interna” = agrupación + posible multi-fuente por proyecto. “Federación de red” = varios nodos que hablan por API con un protocolo ligero.
- **Datos heterogéneos y unificación por ente:** Las webs federadas pueden tener la info en distintos formatos (DB, CSV, APIs). Plan detallado en **`xabia-agent-plugins/documentation/PLAN_FEDERACION_DATOS_Y_ACTUALIZACIONES.md`**: esquema canónico en central, requisitos por nodo (obligatorios/opcionales), requisitos de la web central, cómo se disparan las actualizaciones (pull/push/webhook) y pipeline de unificación con asignación al ente correspondiente.

#### Fase 2 — Federación interna

- En Admin: CRUD de “Federaciones” (nombre, tipo). Asignar proyectos a una federación.
- **Multi-fuente por proyecto** ya implementado en core: un agente puede tener varias fuentes (SQL + CSV, etc.) con mapeos distintos; el Bridge escribe todo en la misma tabla de vectores. Opcional futuro: prioridad entre fuentes o deduplicación por ente_id (addon/federación).
- Sin cambios en el protocolo de chat; solo organización y datos.

#### Fase 3 — Protocolo de federación de red (nodo a nodo)

- Definir un **protocolo mínimo** entre nodos (REST o similar):
  - `GET /federation/identity` → nombre del nodo, versión, qué “agregados” ofrece (ej. conteos por categoría).
  - `GET /federation/aggregate?query=count_by_category` → respuesta acotada (números, listas de etiquetas, sin PII).
- Cada nodo decide qué exponer (whitelist de consultas agregadas). Nada de enviar `content_chunk` o vectores a otros por defecto.
- Autenticación: API key entre nodos o mTLS, según entorno.

#### Fase 4 — Coordinador (opcional)

- Un servicio “coordinador” (puede ser un Xabia especial) que:
  - Conoce la lista de nodos de la federación.
  - Recibe preguntas tipo “totalidad” (¿cuántas X hay en toda la red?) y las descompone en peticiones a cada nodo (`/federation/aggregate`), luego agrega resultados.
  - El usuario final podría hablar con el coordinador (widget apuntando al coordinador), que internamente consulta nodos y responde sin que el usuario vea nodos individuales.

**Entregables del Módulo de Federación (como addon “Xabia Central”):**

1. Documento de diseño — **parcialmente cubierto** por `PLAN_FEDERACION_DATOS_Y_ACTUALIZACIONES.md`, `FEDERACION_COMO_ADDON_Y_COMERCIAL.md` y `MEMORIA_TECNICA.md` §8.2.
2. **Addon de federación** (no en core) — **implementado** en `integrations/central/`: nodos, UI Federación, sync vía filtro `xabia_addon_sync_result`, push `xabia_central_ingest`, cron horario, columna `federation_node_id`. Multi-fuente por proyecto: en el **core**, no solo en el addon.
3. Fase 3 — **pendiente**: protocolo genérico tipo `GET /federation/aggregate` entre nodos arbitrarios (más allá del contrato pull JSON/CSV + push actual).
4. Fase 4 (opcional) — **pendiente**: coordinador mínimo y ejemplo de uso a escala red.

---

## 4. Priorización sugerida

| Prioridad | Eje | Motivo |
|-----------|-----|--------|
| Alta | B — Addons como Tools | Valor inmediato (reservas, stock, eventos); formaliza lo que hoy se cubre en parte con tags y AJAX; alinea con “Xabia transaccional”. |
| Media | C — Consolidar Central + nodos | Base pull/push **ya existe**; priorizar pruebas end-to-end, seguridad de API keys, documentación de nodos (incl. MEC REST si aplica) y mejoras UX. |
| Media | C — Fase 3 (protocolo de red / agregados) | Para “varios servidores colaborando” solo con agregados acordados, más allá del flujo actual de ingesta de filas. |
| Media | A — Standalone | Gran impacto estratégico pero esfuerzo alto; sigue pendiente el núcleo fuera de WP. |
| Según demanda | C — Fase 4 (coordinador) | Útil cuando la red pida “una sola voz” sobre muchos nodos. |

---

## 5. Principios (universal, generalizable, privacidad)

- **Universal**: fuentes mediante adapters (DB, CSV, HTTP); no asumir WP en el kernel; widget que hable con WP o con API REST.
- **Generalizable**: configuración por proyecto (mapeo, reglas, Tools); sin hardcodear verticales; addons como contratos (source, tools).
- **Privacidad**: datos en casa del cliente; federación de red solo con agregados acordados; ningún nodo recibe datos crudos de otro por defecto; documentar qué se envía a la IA (contexto RAG) y qué no.

---

## 6. Siguientes pasos

1. **Mantener la doc al día** con el código (este documento v1.2, `MEMORIA_TECNICA.md` §3.6 para Core/`addons/`, `integrations/central/README.md` para federación, `xabia-agent-plugins/central-api/README.md` para el hub y `user_lang`).
2. **Eje B**: Diseñar el contrato Tool (nombre, schema, registro) y elegir opción A o B para “IA → Tool”; mapear qué reemplaza o complementa los tags actuales.
3. **Eje C**: Priorizar fase 3/4 solo si hay caso de uso; mientras tanto, endurecer gobernanza (keys, logs, límites de payload) y escenarios reales pull/push.
4. **Eje A**: Cuando se decida standalone, listar dependencias WP en core/api/admin y definir la interfaz de adapters.

Para bajar a tareas concretas (issues, ramas, PRs), el foco natural **hoy** es **Tools (Eje B)** y **consolidación de Central (Eje C)**; standalone (Eje A) sigue siendo hito aparte.
