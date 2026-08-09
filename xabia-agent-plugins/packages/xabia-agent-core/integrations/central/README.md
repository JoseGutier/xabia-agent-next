# Xabia Central — Addon de federación

**Producto:** [XABIA CENTRAL](https://xabia.ai/precio/) — Redes federadas, ayuntamientos, corporaciones.

**Documentación general del plugin:** [README.md](../../README.md), [MEMORIA_TECNICA.md](../../../../../MEMORIA_TECNICA.md) §3.6 (Core / `integrations/` / `addons/`) y §8.2 (este addon), [FEDERACION_COMO_ADDON_Y_COMERCIAL.md](../../../../documentation/FEDERACION_COMO_ADDON_Y_COMERCIAL.md), [PLAN_FEDERACION_DATOS_Y_ACTUALIZACIONES.md](../../../../documentation/PLAN_FEDERACION_DATOS_Y_ACTUALIZACIONES.md). Pull opcional de eventos MEC desde un nodo: REST en el plugin **`xabia-mec`** (`includes/xabia-federation-bridge-mec.php`; ver también `documentation/PLAN_FEDERACION_DATOS_Y_ACTUALIZACIONES.md` en el repo).

## Principios

- **Ahorro:** Un sync por nodo (pull); batch upsert; sin re-embedding hasta que el usuario pulse «Entrenar».
- **Privacidad:** Datos solo en esta instalación; API key hasheada; nodos envían solo lo acordado.
- **Eficacia:** Formato canónico unificado; asignación por ente; pull (GET) o push (POST).
- **Trazabilidad en RAG:** Cada fila ingestada incluye en **`content_chunk`** el prefijo **`Fuente: {Nombre del nodo} | `** (nombre configurado en el nodo; si falta, se usa el `node_id`). Así el contexto del asistente identifica el origen del dato.

## Requisitos

- Xabia Agent Next activo.
- Columna `federation_node_id` en `wp_xabia_knowledge_vectors` (el addon la crea si no existe vía `Xabia_Central_Setup`).

## Uso

1. Crea un proyecto en Xabia y elige **Fuente: Addons Nativos** → **Xabia Central (Federación)**.
2. En **Xabia Agent → Federación** selecciona el proyecto y añade nodos (pull o push). Asigna un **Nombre** legible a cada nodo (aparecerá en `Fuente: …`).
3. **Pull:** URL endpoint que devuelva JSON `{ "records": [...] }` o CSV. Cada fila = un ente (mapeo opcional).
4. **Push:** El nodo envía POST a `admin-ajax.php?action=xabia_central_ingest` con `node_id`, `project_id`, `api_key`, `records`.
5. **Sincronizar ahora (pull)** en la pantalla Federación llama a `xabia_sync_content` (mismo flujo que el botón Sincronizar del proyecto en el editor del agente): filtro `xabia_addon_sync_result` → `Xabia_Central_Sync::sync_project`.
6. **Auto-sync:** Evento WordPress **`xabia_auto_sync_tick`** (cada 5 min). Por agente puedes elegir intervalo en la barra lateral (inmediato en local, cron ajustable en remoto/federación). Sustituye al antiguo cron horario exclusivo de Central.
7. Opcional: **Entrenar** en el proyecto para generar vectores y usar búsqueda semántica.

## Formato canónico (push o respuesta pull)

Cada fila puede ser:

- **Canónico:** `ente_id`, `ente_display`, `content_chunk`, `meta_data` (objeto). La central normaliza y **antepone** `Fuente: {Nombre del nodo} | ` al texto indexable (si el contenido base no está vacío). En `meta_data` puede guardarse `__federation_node_name`.
- **Raw:** Objeto con columnas arbitrarias; en el nodo configuras **Mapeo** (array de `source_key`, `label`, `is_ente`) para convertir a canónico; la atribución `Fuente:` se aplica igualmente al `content_chunk` resultante.

## Endpoint de ingest (push)

```
POST /wp-admin/admin-ajax.php?action=xabia_central_ingest
Content-Type: application/json

{
  "node_id": "web-ayto-1",
  "project_id": "mi-federacion",
  "api_key": "clave-entregada-al-nodo",
  "records": [
    { "ente_id": "empresa-1", "ente_display": "Empresa Uno", "content_chunk": "Nombre: Empresa Uno | Teléfono: 123", "meta_data": {} }
  ]
}
```

Respuesta: `{ "success": true, "data": { "count": 1, "message": "Ingestados: 1" } }`.

## Script de prueba (raíz del plugin)

**`test-xabia-federation.php`** (junto a `xabia-intelligence.php`):

- Simula **3 PUSH** en paralelo (Farmacia 1, Museo B, Bus Local; 10 registros y API key propia cada uno).
- Configura **2 nodos PULL** con JSON estático interceptado por `pre_http_request` y ejecuta `Xabia_Central_Sync::sync_project`.
- Verifica prefijo **`Fuente:`** y ausencia de duplicados `(project_id, federation_node_id, ente_id)`.

**Ejecución:** desde la raíz de WordPress, por CLI:

`php wp-content/plugins/<carpeta-plugin>/test-xabia-federation.php`

O definir `XABIA_ALLOW_FEDERATION_TEST` en `wp-config.php` para permitir ejecución vía web (no recomendado en producción).

## Archivos

- `xabia-addon-central.php` — Bootstrap, registro addon, filtro sync, cron, endpoint ingest, menú, `register_activation_hook` para programar el cron.
- `class-xabia-central-setup.php` — Tabla `xabia_federation_nodes` y columna `federation_node_id`.
- `class-xabia-central.php` — Nodos (CRUD), **`Xabia_Central_Normalize`** (atribución `Fuente:`), upsert batch.
- `class-xabia-central-sync.php` — Pull desde URL (JSON/CSV); sync por proyecto; pasa nombre del nodo al normalizador.
- `class-xabia-central-ingest.php` — Handler push (validación API key, upsert con nombre de nodo).
- `class-xabia-central-admin.php` — UI: listado nodos, formulario, sincronizar ahora (pull).

Documentación ampliada: **[MEMORIA_TECNICA.md](../../MEMORIA_TECNICA.md)** §8.2.
