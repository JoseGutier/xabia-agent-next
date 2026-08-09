# Federación como addon comercial (XABIA CENTRAL)

**Versión:** 1.2  
**Fecha:** Abril 2026 (texto alineado con **Xabia Agent Next v1.0.0**; la carpeta `addons/` no altera el addon Central en `integrations/central/`)  
**Referencia comercial:** [Xabia — Precio](https://xabia.ai/precio/)

**Documentación en este repo:** [README índice](./README.md), implementación [integrations/central/README.md](../packages/xabia-agent-core/integrations/central/README.md), [MEMORIA_TECNICA.md](../../MEMORIA_TECNICA.md) §8.2.

---

## 1. Objetivo

La **federación** (redes de nodos, ingesta unificada desde webs asociadas, coordinador central) debe ser un **addon** de Xabia, no parte del core. Así:

- El **core** (Plugin / Entity / Connect) sigue comercializándose como hoy: licencias por nivel sin federación.
- **XABIA CENTRAL** se comercializa como addon o nivel superior (licencia 1.900€ según [precio](https://xabia.ai/precio/)), ideal para ayuntamientos, corporaciones y **redes federadas**.
- Quien no contrate Central no instala el addon y no tiene lógica ni coste de mantenimiento de federación.

---

## 2. Alineación con la web de precios

| Nivel | Producto | Tecnología | Federación |
|-------|----------|------------|------------|
| **XABIA PLUGIN** | Webs corporativas, Pymes WP | RAG 2 pasos | No |
| **XABIA ENTITY** | Museos, Retail, experiencias físicas | Visión de Túnel (**Smart QR incluido en Core** desde v1.0.59) | No |
| **XABIA CONNECT** | eCommerce, datos vivos | DB Bridge SQL/ERP | No |
| **XABIA CENTRAL** | Ayuntamientos, redes federadas | Federación en WP (**addon**); standalone / widget JS | **Sí (addon; federación hecha en WP)** |

En la práctica del código, **registro de nodos, pull/push e unificación por ente** (ingesta hacia la central) están en el **addon “Xabia Central”** (`integrations/central/`). Un **protocolo genérico de agregados** entre nodos y un **coordinador** de red siguen descritos como evolución en `PLAN_VISION_XABIA_STANDALONE_Y_FEDERACION.md` (Eje C, fases 3–4). La licencia comercial gobierna qué se distribuye y activa en cada instalación.

---

## 3. Federación = addon

### 3.1 Qué implica

- **No** se concentra la lógica de federación en el core: el **core** solo ofrece ganchos (addon como fuente, columna opcional en vectores, filtros de sync).
- El **addon** vive en **`integrations/central/`** y hoy incluye, entre otros:
  - Tablas y setup de nodos (`Xabia_Central_Setup`, etc.); columna **`federation_node_id`** en `xabia_knowledge_vectors` cuando el addon la provisiona.
  - Sección en Admin: **Federación** (menú bajo Xabia Agent) para CRUD de nodos, pull/push y mapeo por nodo.
  - Ingest **push** vía `admin-ajax.php?action=xabia_central_ingest` (cuerpo JSON con `node_id`, `project_id`, `api_key`, `records`).
  - **Pull** desde URL (JSON con `records` o CSV), normalización canónica, prefijo **`Fuente: {nombre del nodo} | `** en el texto indexable, upsert por clave lógica del proyecto; **cron** horario `xabia_central_hourly_sync`.
  - Registro como fuente de proyecto (`addon_slug` **xabia_central**) integrada con el flujo de sincronización del editor de agente (mismo mecanismo que otros addons SQL / filtro `xabia_addon_sync_result`).
- **Roadmap** (no reemplazado por nombres concretos en código todavía): endpoints REST genéricos tipo `POST /federation/ingest` en lugar de `admin-ajax`, `GET /federation/aggregate` entre nodos, coordinador de red. Ver `PLAN_FEDERACION_DATOS_Y_ACTUALIZACIONES.md` y `PLAN_VISION_XABIA_STANDALONE_Y_FEDERACION.md` Eje C fases 3–4.

### 3.2 Qué debe ofrecer el core para que el addon funcione

Para no meter federación en el core pero permitir que el addon se enganche:

| Necesidad del addon | Opción en el core |
|---------------------|-------------------|
| Guardar registros con “origen nodo” | **A)** Añadir columna opcional `federation_node_id` (nullable) a `xabia_knowledge_vectors`. Si es NULL, comportamiento actual. Si el addon está activo, puede rellenarla. **B)** Addon usa su propia tabla y un **hook** que el Brain consulte para incluir esos registros en la búsqueda (más aislado, más complejo). Recomendación: **A** con columna nullable y documentada como “para uso por addon de federación”. |
| Proyecto que usa “fuente = Federación” | Core ya permite `source_type` = `addon` y `addon_slug`. El addon se registra como addon (p. ej. `xabia_central`) y proporciona un **callback de sync** (como MEC/Amelia): cuando el usuario pulsa “Sincronizar”, el core llama al addon; el addon hace pull de nodos, normaliza, y escribe en la tabla de vectores (con `federation_node_id` si existe). |
| Endpoint de ingest (push) | El addon usa **`admin-ajax.php?action=xabia_central_ingest`** (nombre histórico en código); valida API key por nodo, normaliza y escribe en la tabla de conocimiento (con `federation_node_id`). Una futura variante REST puede convivir como capa fina encima del mismo pipeline. |
| Filtro por nodo en búsqueda | Si el Brain recibe un parámetro opcional `federation_node_id` (o scope que lo implique), filtra por esa columna. Ese parámetro puede inyectarlo la API cuando el addon indique “este chat está anclado al nodo X”. Opcional: el addon puede filtrar por nodo en su propia capa si no se quiere tocar el Brain (p. ej. devolviendo solo entes de ese nodo). |

Con esto, el **core** solo necesita:

- (Recomendado) Columna **`federation_node_id`** nullable en `xabia_knowledge_vectors`.
- (Ya existe) Mecanismo de **addon como fuente** (`source_type` = addon, `addon_slug`, callback de sync).
- (Opcional) Parámetro opcional en Brain/API para filtrar por `federation_node_id` cuando el addon lo use.

Tabla de nodos, mapeos, ingest y pull viven **en el addon**. Los diseños de **protocolo de red** y **coordinador** agregado entre muchos nodos siguen en roadmap (no son el mismo código que el pull/push actual).

### 3.3 Licencia / visibilidad

- El addon puede comprobar una opción o constante (ej. `xabia_license_central` o licencia en un servidor de licencias). Si no hay licencia válida, el addon no registra menús ni endpoints de ingest, o muestra mensaje “XABIA CENTRAL requiere licencia”.
- En la web de precios, “XABIA CENTRAL” se describe como incluyendo **Standalone** y **redes federadas**; el addon de federación es la parte “redes federadas”.

---

## 4. Resumen de entregables del addon “Federación” (XABIA CENTRAL)

**Estado frente al código (v1.0.0):** los ítems marcados con **hecho** están cubiertos por `integrations/central/` y la memoria técnica §8.2; el resto es opcional o comercial. La reorganización **MEC/Amelia/Avirato → `addons/`** no cambia la ubicación ni el contrato del addon **Central**.

1. **Addon** en `integrations/central/`:
   - Registro como addon con `addon_slug` **`xabia_central`** — **hecho**.
   - Tablas de nodos y setup — **hecho** (ver clases del addon y `README` del addon).
   - Admin: CRUD nodos, pull/push, mapeo — **hecho**.
   - Sync integrado con “Sincronizar” del proyecto y cron horario — **hecho**.
   - Endpoint de ingest (push): **`xabia_central_ingest`** — **hecho** (ver `integrations/central/README.md` para cuerpo JSON de ejemplo).
   - Documentación de requisitos por nodo — **parcial / vivo** (`PLAN_FEDERACION_DATOS_Y_ACTUALIZACIONES.md`, README Central).
2. **Core (cambios mínimos)**:
   - Columna `federation_node_id` (varchar, nullable) en `xabia_knowledge_vectors` — **hecho** (vía addon al activar/instalar).
   - Filtro por `federation_node_id` en Brain/API — **opcional / según evolución** (el plan lo contemplaba; comprobar `MEMORIA_TECNICA.md` si se documenta comportamiento concreto).
3. **Comercial**: XABIA CENTRAL en [xabia.ai/precio](https://xabia.ai/precio/) como nivel con federación + standalone; el addon es el componente “federación” **en WordPress**; **standalone** sigue en roadmap de producto.

Así la federación queda **empaquetada como addon** y alineada con la comercialización actual.
