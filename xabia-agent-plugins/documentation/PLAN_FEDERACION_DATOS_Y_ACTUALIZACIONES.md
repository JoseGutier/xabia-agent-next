# Plan: Modelo federado — Datos heterogéneos, unificación por ente y actualizaciones

**Versión:** 1.2  
**Fecha:** Abril 2026 (producto **Xabia Agent Next v1.0.0**; rutas MEC federación en `addons/xabia-mec/`)  
**Relación:** Complementa `PLAN_VISION_XABIA_STANDALONE_Y_FEDERACION.md` (Eje C). La **federación se implementa como addon** comercial (XABIA CENTRAL); ver `FEDERACION_COMO_ADDON_Y_COMERCIAL.md` y [xabia.ai/precio](https://xabia.ai/precio/).

**Implementación:** la ingesta con esquema canónico (`ente_id`, `ente_display`, `content_chunk`, `meta_data`, `federation_node_id`, upsert por proyecto+nodo+ente) está cubierta por **`integrations/central/`** (normalización, pull JSON/CSV, push). Un **nodo WordPress con MEC** puede exponer eventos para pull mediante REST **`xabia/v1/federation-events`** implementado en **`addons/xabia-mec/xabia-federation-bridge-mec.php`** (no forma parte del addon Central). Este documento sigue siendo la referencia de **requisitos por nodo** y de **políticas de actualización**; si algún detalle difiere del código, prima `MEMORIA_TECNICA.md` §8.2 y `integrations/central/README.md`.

**Documentación en este repo:** [README.md](./README.md) (índice), [MEMORIA_TECNICA.md](../../MEMORIA_TECNICA.md), [manual-usuario-xabia-core.md](./manual-usuario-xabia-core.md), [DESARROLLO.md](./DESARROLLO.md).

---

## 1. Objetivo y premisa

En el modelo federado, **cada web asociada puede tener la información en formatos distintos** (MySQL, PostgreSQL, CSV, WordPress/ACF, APIs propias, etc.). El sistema debe:

1. **Unificar la entrada**: un esquema canónico común para todo lo que llega de cualquier nodo.
2. **Asignar cada registro al ente correspondiente**: cada fila se asocia a una entidad (empresa, producto, obra, etc.) con un `ente_id` estable, para búsqueda, filtro por scope y modo QR.
3. **Saber el origen**: qué nodo federado envió cada dato (para filtros, privacidad y actualizaciones incrementales).

Este documento planifica: **qué exige cada web asociada**, **qué exige la web central/matriz**, **cómo se disparan las actualizaciones** y **cómo se unifica la entrada y se asigna al ente**.

---

## 2. Esquema canónico (unificado) en central

Todo lo que la central almacena para RAG debe seguir un **modelo único**, independiente del formato de origen.

### 2.1 Registro canónico (por “fila” de conocimiento)

| Campo | Tipo | Obligatorio | Descripción |
|-------|------|-------------|-------------|
| `project_id` | string | Sí | Proyecto de la federación (en central) al que pertenece este conocimiento. |
| `federation_node_id` | string | Sí | Identificador único del nodo federado que envió el dato (ej. `nodo_aktiba_empresa_1`). |
| `ente_id` | string | Sí | Identificador estable de la entidad (empresa, producto, etc.). Usado para scope, QR y filtros. Debe ser único **dentro del nodo**; en central será `{federation_node_id}:{ente_id}` o se almacena tal cual y se filtra por nodo. |
| `ente_display` | string | Recomendado | Nombre legible del ente (para saludos, UI, respuestas). |
| `content_chunk` | text | Sí | Texto indexable: concatenación "etiqueta: valor" de los campos mapeados. Es lo que el Brain busca (LIKE/vector). |
| `meta_data` | JSON | Opcional | Metadatos por campo (teléfono, web, categoría, etc.) para acciones y visualización. |
| `vector_data` | blob/JSON | Opcional | Embedding del `content_chunk`; se genera en central tras ingesta o lo envía el nodo (según política). |
| `source_updated_at` | datetime | Opcional | Última actualización en el sistema de origen (para sincronización incremental). |
| `source_id` | string | Opcional | ID en el sistema de origen (para deduplicar y actualizar por ID). |

**Clave única recomendada (central):** `(project_id, federation_node_id, ente_id)` o `(project_id, federation_node_id, source_id)` si existe `source_id`, para poder hacer **upsert** cuando un nodo reenvía datos (actualización).

### 2.2 Asignación al ente

- **En el nodo federado**: cada fila debe poder asociarse a un **ente** (una empresa, un producto, una obra). El nodo puede enviar:
  - Un campo que la central interpreta como “identificador de ente” (ej. `ente_id` o `id_empresa`).
  - Un campo “nombre para mostrar” (ej. `ente_display` o `nombre`).
- **En la central**: si el nodo no envía `ente_id`, la central puede generarlo a partir de un campo obligatorio (ej. nombre o ID de origen), por ejemplo `sanitize_title(nombre)` o `source_id`, y almacenar `federation_node_id` para no colisionar entre nodos (p. ej. `ente_id` = `nodo1:empresa-alfa` o mantener `ente_id` = `empresa-alfa` y filtrar por `federation_node_id` en las consultas).

Recomendación: **exigir al nodo** que envíe al menos un identificador estable por fila (ID o nombre único por ente) y, opcionalmente, nombre para mostrar. La central normaliza a `ente_id` + `ente_display` y asigna `federation_node_id`.

---

## 3. Qué se necesita en cada web asociada (nodo federado)

Cada web federada puede tener su propia base de datos, stack (WP, Shopify, custom) y formato (tablas SQL, CSV, JSON). Lo importante es **qué debe poder exponer** y **qué debe cumplir** para participar en la federación.

### 3.1 Requisitos mínimos (obligatorios)

| Requisito | Descripción |
|-----------|-------------|
| **Identificador de nodo** | Un `federation_node_id` único acordado con la central (ej. slug del sitio, código cliente). Lo usa la central para asociar cada registro a su origen. |
| **Exportación de “filas”** | Cada fila = una entidad (ente) con al menos: **un identificador estable** (ID o nombre único) y **texto indexable** (o columnas que la central pueda convertir en `content_chunk`). |
| **Punto de acceso** | Algún mecanismo para que la central (o el nodo) envíe o exponga datos: **push** (el nodo envía a la central) o **pull** (la central llama a un endpoint del nodo o descarga un archivo). Ver §5. |

No se exige que la web asociada tenga Xabia instalado; puede ser solo una API REST, un CSV en una URL, o una base de datos accesible (con credenciales restringidas).

### 3.2 Formatos de salida admitidos (uno por nodo)

La central debe aceptar al menos estos formatos y **normalizarlos al esquema canónico** mediante adapters:

| Formato | Descripción | Requisitos en el nodo |
|---------|-------------|------------------------|
| **JSON (recomendado)** | El nodo expone un endpoint que devuelve un array de objetos (o la central recibe un POST con ese array). Cada objeto tiene campos que la central mapea a canónico. | Estructura mínima: lista de objetos con al menos un campo identificador de ente y campos para construir texto (o `content_chunk` ya generado). |
| **CSV** | URL de descarga o archivo subido. Primera fila = cabeceras. | Cabeceras con al menos una columna que identifique el ente (ID o nombre) y columnas para el contenido. |
| **SQL remoto** | La central se conecta a la DB del nodo (MySQL/PostgreSQL) con una query configurada. | Credenciales de solo lectura; query que devuelva filas con columnas mapeables a canónico. |

Para **JSON** y **CSV**, la central necesita un **mapeo por nodo** (no solo por proyecto): “columna o clave X → ente_id”, “Y → ente_display”, “estas claves → content_chunk o meta_data”. Ese mapeo puede estar en la central (config del nodo) o el nodo puede enviar ya un formato “casi canónico” (menos trabajo en central).

### 3.3 Campos recomendados por fila (desde el nodo)

Para que la central pueda unificar y asignar al ente sin ambigüedades:

| Campo (en origen) | Uso en central | Obligatorio |
|-------------------|----------------|-------------|
| Identificador de ente | `ente_id` (o se deriva de aquí) | Sí (o nombre único) |
| Nombre para mostrar | `ente_display` | Recomendado |
| Texto para búsqueda | Se concatena en `content_chunk` o se envía ya concatenado | Sí (o columnas que se concatenen) |
| Metadatos (teléfono, web, etc.) | `meta_data` | Opcional |
| ID en sistema origen | `source_id` (para upsert/actualizaciones) | Recomendado si hay actualizaciones |
| Fecha de última modificación | `source_updated_at` | Recomendado para sync incremental |

**Organización en el nodo:** no se impone una estructura de BD concreta. Solo que, en el momento de exportar (o en la query/API), exista una noción de “una fila = un ente” y que se puedan identificar los campos anteriores (por nombre de columna o por clave JSON).

### 3.4 Opciones de implementación en el nodo

- **Opción A — Nodo con Xabia (WP o standalone):** El nodo ya tiene proyecto Xabia y su propia tabla de vectores. Puede exponer un endpoint “exportar en formato canónico” (o “exportar para federación”) que la central consume. Ventaja: reutiliza mapeo y lógica de ente del nodo.
- **Opción B — Nodo sin Xabia:** El nodo expone CSV, JSON o DB de solo lectura. La central hace **todo el mapeo** (configuración por nodo en la matriz: qué columna es ente, qué columnas son contenido, etc.). Requisito: que los datos tengan estructura mínima (identificador + contenido).
- **Opción C — Híbrido:** El nodo envía un JSON “casi canónico” (mismos nombres de campo que la central) y la central solo añade `federation_node_id` y hace upsert. Menor carga en la central; el nodo debe conocer el contrato.

---

## 4. Qué se necesita en la web central / matriz

La central es el agregador: recibe o obtiene datos de varios nodos, los normaliza, asigna ente y nodo, y los guarda en una sola base de conocimiento (o en una vista lógica por proyecto/federación).

### 4.1 Registro de nodos federados

- **Tabla o config de nodos:** Por cada web asociada:
  - `federation_node_id` (único).
  - Nombre/descripción.
  - Tipo de integración: `push` (nodo envía a central) o `pull` (central descarga/consulta).
  - Endpoint o origen: URL de API, URL de CSV, o credenciales DB (host, user, query, etc.).
  - **Mapeo de entrada**: para ese nodo, cómo traducir columnas/claves a canónico (qué campo → ente_id, ente_display, content_chunk, meta_data). Puede ser un JSON de mapeo (igual que el “attributes” actual por proyecto) o un “schema_id” que apunte a un esquema predefinido.
  - Autenticación: API key, token, o credenciales DB (almacenadas de forma segura).
  - Estado: activo/pausado; última sincronización; último error (opcional).

### 4.2 Proyecto(s) de la federación

- Uno o varios `project_id` en la central que corresponden a la “federación” (ej. un proyecto “Aktiba” que agrega todas las empresas de los nodos).
- Cada registro guardado tiene `project_id` + `federation_node_id` + `ente_id` (y opcionalmente `source_id`), de modo que se pueda filtrar por “solo nodo X” o “todos los nodos”.

### 4.3 Almacenamiento unificado

- **Tabla de conocimiento** (como la actual `xabia_knowledge_vectors`) con columnas adicionales:
  - `federation_node_id` (qué web envió el dato).
  - Opcional: `source_id`, `source_updated_at` para actualizaciones incrementales y deduplicación.
- Clave única para **upsert**: `(project_id, federation_node_id, ente_id)` o `(project_id, federation_node_id, source_id)`.
- El Brain y la API deben poder filtrar por `federation_node_id` cuando se quiera scope “solo este nodo” o “solo esta web”.

### 4.4 Pipeline de unificación (por nodo)

Para cada nodo registrado, la central debe tener un **adapter** que:

1. **Obtenga los datos** (pull desde endpoint/CSV/DB o reciba push).
2. **Aplique el mapeo** de ese nodo: columnas/claves → ente_id, ente_display, content_chunk, meta_data.
3. **Asigne** `federation_node_id` y `project_id`.
4. **Normalice** ente_id (sanitize, unicidad dentro del nodo).
5. **Inserte o actualice** en la tabla canónica (upsert por clave única).
6. **(Opcional)** Genere embeddings (`vector_data`) en central para el texto unificado, o marque para entrenamiento posterior.

Si el nodo envía ya formato “casi canónico”, el adapter solo rellena `federation_node_id` y hace el upsert.

### 4.5 Requisitos técnicos de la central

- Base de datos con la tabla extendida (incl. `federation_node_id`, opcionalmente `source_id`, `source_updated_at`).
- Configuración por nodo (almacenada en BD o en fichero) y por proyecto federado.
- Cola o cron para ejecutar sincronizaciones (pull) o endpoint seguro para recibir push.
- Autenticación de nodos (API key, IP allowlist, o mTLS) para no aceptar datos de fuentes no registradas.

---

## 5. Cómo se disparan las actualizaciones

Hay tres modelos posibles; la central puede soportar uno o varios según el tipo de nodo.

### 5.1 Pull (central va a buscar)

- **Quién dispara:** La central (cron o cola).
- **Frecuencia:** Periódica (cada X minutos/horas) o bajo demanda (botón “Sincronizar nodo X” en admin).
- **Flujo:**
  1. La central tiene registrado el nodo con URL/credenciales.
  2. Según el tipo: llama al endpoint del nodo (GET/POST), descarga CSV desde URL, o ejecuta query contra la DB del nodo (solo lectura).
  3. Recibe filas en el formato acordado (JSON, CSV o filas SQL).
  4. Aplica el adapter de ese nodo (mapeo → canónico), asigna `federation_node_id` y hace upsert en la tabla unificada.
- **Requisitos en el nodo:** Para pull por API: endpoint accesible desde la central (y opcionalmente autenticado con API key que la central envía). Para pull por CSV: URL pública o con auth. Para pull por SQL: que la central pueda conectarse a la DB del nodo (red/firewall y usuario de solo lectura).

### 5.2 Push (nodo envía a la central)

- **Quién dispara:** El nodo federado cuando sus datos cambian (o en cron propio).
- **Flujo:**
  1. El nodo genera un payload en formato acordado (JSON “casi canónico” o con mapeo conocido por la central).
  2. El nodo llama a un endpoint de la central, ej. `POST /federation/ingest` con body = `{ "federation_node_id": "...", "records": [ ... ] }`.
  3. La central valida el `federation_node_id` y la API key (o token). Aplica el adapter/mapeo si hace falta y hace upsert.
- **Requisitos en el nodo:** Conocer la URL de la central y tener una API key de ingesta (entregada por la central al dar de alta el nodo). Poder ejecutar código o cron que construya el payload y haga el POST (p. ej. tras guardar un post en WP, o cada hora).
- **Requisitos en la central:** Endpoint `POST /federation/ingest` (o similar), rate limiting y autenticación por nodo.

### 5.3 Híbrido (webhook + pull de respaldo)

- El nodo puede avisar a la central “hay cambios” (webhook: `POST /federation/notify` con `federation_node_id`). La central pone el nodo en cola para un pull inmediato (o en los próximos segundos).
- Como respaldo, la central sigue haciendo pull periódico por si el webhook falla o el nodo no lo implementa.

### 5.4 Resumen de disparos

| Modo | Quién dispara | Requisito nodo | Requisito central |
|------|----------------|----------------|-------------------|
| **Pull (cron)** | Central | Exponer endpoint o CSV o DB lectura | Cron/cola + adapters por nodo |
| **Pull (manual)** | Usuario en central | Igual | Botón “Sincronizar” + mismo pipeline |
| **Push** | Nodo | Llamar a central con payload + API key | Endpoint `POST /federation/ingest` + auth |
| **Webhook** | Nodo | Llamar “notify” al cambiar datos | Cola para pull inmediato |

---

## 6. Flujo completo (resumen)

1. **Alta de nodo en central:** Se registra `federation_node_id`, tipo (push/pull), origen (URL/DB) y **mapeo** (cómo pasar de formato del nodo a canónico y asignación a ente).
2. **Obtención de datos:** Por pull (central consulta/descarga) o por push (nodo envía a central).
3. **Unificación:** Adapter por nodo aplica mapeo → se genera para cada fila: `project_id`, `federation_node_id`, `ente_id`, `ente_display`, `content_chunk`, `meta_data`, y opcionalmente `source_id`, `source_updated_at`.
4. **Persistencia:** Upsert en tabla canónica (clave `project_id` + `federation_node_id` + `ente_id` o `source_id`).
5. **Embeddings (opcional):** Central genera `vector_data` para los registros nuevos o modificados, o los marca para un job de entrenamiento.
6. **Uso en chat:** El Brain consulta la misma tabla; puede filtrar por `project_id` y, si aplica, por `federation_node_id` o por `ente_id` (scope/QR). Cada registro ya está asignado al ente correspondiente y al nodo de origen.

---

## 7. Próximos pasos recomendados

1. **Fijar contrato canónico** en código: extensión de la tabla (añadir `federation_node_id`, `source_id`, `source_updated_at`) y documento de “formato de ingest” (JSON mínimo para push).
2. **Diseñar tabla o config de nodos** en central (id, nombre, tipo, endpoint/credenciales, mapeo, estado).
3. **Implementar un adapter “JSON canónico”** en central: recibe array de objetos con campos estándar y hace upsert; luego añadir adapter CSV y SQL remoto reutilizando lógica actual del DB Bridge.
4. **Implementar disparo pull:** cron que, por cada nodo de tipo pull, llame al adapter y actualice la tabla.
5. **Implementar endpoint push** `POST /federation/ingest` con auth por nodo y mismo pipeline de unificación.
6. **Admin en central:** CRUD de nodos federados, mapeo por nodo (similar al mapeo de atributos por proyecto), y botón “Sincronizar ahora” por nodo.

Cuando quieras, se puede bajar a diseño de tablas SQL concretas y a la firma del endpoint de ingest para implementación.
