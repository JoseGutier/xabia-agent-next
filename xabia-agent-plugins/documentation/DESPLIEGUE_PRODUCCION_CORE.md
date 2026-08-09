# Despliegue completo en producción — Xabia Agent Core + Hub

Checklist operativo para publicar **Xabia Agent Core v1.0.201+** (chat UI stream + Markdown, avatar parlante / launcher, starter questions, latencia chat, Document-to-RAG, pasaporte remoto, listados, WPML/DTP) y el **Hub central** en `xabia.ai`.

Use este documento como **lista de verificación** antes de dar por cerrado un despliegue.

---

## Resumen: qué despliega en cada sitio

| Componente | Dónde vive | Quién lo toca |
|------------|------------|---------------|
| **Core ZIP** | WordPress del cliente (`/wp-content/plugins/xabia-agent-core/`) | Instalador / admin WP |
| **Addons** | MEC **1.0.3**, Woo **1.0.4** (ZIPs en `dist/`) | Admin WP |
| **Hub PHP** (`central-api/src/`) | Servidor privado `central-api/` (búnker) | DevOps / hosting Xabia |
| **Gateway** (`public_html/api/index.php`) | Solo si cambia el front controller | DevOps |
| **Base de datos Hub** | MySQL del hub (`xabia_licenses`, addons, wallets…) | SQL / phpMyAdmin |
| **Manuales PDF/HTML** | `xabia.ai/docs/` | Tras `./scripts/build-modular-manuals-pdf.sh` |

---

## Fase A — Build del Core (monorepo)

Desde la raíz del repositorio:

```bash
cd /ruta/a/xabia-agent-next
ONLY_SLUG=xabia-agent-core ./scripts/build-plugin-zip.sh
ONLY_SLUG=xabia-mec ./scripts/build-plugin-zip.sh
ONLY_SLUG=xabia-woo ./scripts/build-plugin-zip.sh
```

**Salida:** `xabia-agent-plugins/dist/xabia-agent-core-1.0.201.zip` (versión leída de `xabia-intelligence.php`), más ZIPs MEC/Woo.

**Opcional — retail con vendor:**

```bash
./scripts/build-retail-plugin-zips.sh
# → xabia-agent-plugins/dist/retail/xabia-agent-core-<versión>-retail.zip
```

**Copia rápida en raíz del repo:**

```bash
cp xabia-agent-plugins/dist/xabia-agent-core-*.zip ./xabia-agent-core.zip
```

---

## Fase B — Hub central (DTP + licencias)

### B.1 Archivos PHP a subir al búnker

Ruta en servidor (layout típico): `~/central-api/src/`

| Archivo | Acción |
|---------|--------|
| `Router.php` | Reemplazar |
| `DtpEntitlement.php` | Subir (nuevo) |
| `DtpTranslator.php` | Subir (nuevo) |
| `I18nGreetingHandler.php` | Subir (nuevo) |

Origen en el repo: `xabia-agent-plugins/central-api/src/`.

**No** hace falta tocar `bootstrap.php` ni `public_html/api/index.php` para DTP.

### B.2 Variables de entorno (`.env` del hub)

Mínimo ya existente + opcionales DTP:

```env
XABIA_VERTEX_PROJECT_ID=api-traduccion-aktiba
XABIA_VERTEX_LOCATION=europe-west1
# Opcional solo en entornos de desarrollo:
# XABIA_DTP_ALLOW_ALL=1
# XABIA_DTP_VERTEX_TIER=flash
```

### B.3 DTP y licencias

**DTP va incluido en la licencia Core.** No hace falta activar ningún addon `xabia-dtp` en Polar ni en `xabia_addon_activations` para clientes retail.

El Hub autoriza la traducción automática del saludo a cualquier licencia **activa** que pase la validación firmada (`SignedHubPostAuth`).

> **Nota:** filas históricas con addon `xabia-dtp` en BD son inofensivas; ya no son necesarias.

### B.4 Smoke test del Hub (terminal)

Debe responder JSON (no **404**):

```bash
curl -sS -X POST 'https://xabia.ai/api/xabia/v1/i18n/greeting-translate' \
  -H 'Content-Type: application/json' -d '{}'
# Esperado: 401 (falta licencia), NO 404
```

Con licencia y firma HMAC válidas → `{"ok":true,"dtp":true,"translations":{...}}`.

### B.5 Actualizaciones automáticas WP (opcional)

En `.env` del hub, alinear con la versión publicada:

```env
XABIA_CORE_LATEST_VERSION=1.0.168
XABIA_CORE_UPDATE_PACKAGE=https://xabia.ai/downloads/xabia-agent-core-1.0.201.zip
XABIA_MEC_LATEST_VERSION=1.0.3
XABIA_MEC_UPDATE_PACKAGE=https://xabia.ai/downloads/xabia-mec-1.0.3.zip
XABIA_WOO_LATEST_VERSION=1.0.4
XABIA_WOO_UPDATE_PACKAGE=https://xabia.ai/downloads/xabia-woo-1.0.4.zip
```

Subir el ZIP al path público indicado.

### B.6 Pipeline de conocimiento (sync Hub + vectorización)

Aplicar **antes** de desplegar Core ≥ 1.0.168 en sitios con catálogo vectorial (Woo, MEC, federación).

#### B.6.1 Migraciones MySQL (orden)

1. `migrations/016_hub_knowledge_vectors_incremental.sql` (o `_legacy.sql` en MariaDB antigua).
2. `migrations/017_hub_knowledge_store_pipeline.sql`.

Comprobar índice único:

```sql
SHOW INDEX FROM xabia_knowledge_vectors WHERE Key_name = 'uq_kv_license_project_source';
```

#### B.6.2 Archivos PHP a subir al búnker

| Archivo | Acción |
|---------|--------|
| `src/KnowledgeSyncHandler.php` | Reemplazar |
| `src/KnowledgeVectorsRepository.php` | Reemplazar |
| `src/Workers/VectorizationWorker.php` | Subir (nuevo) |
| `bin/cron-vectorizer.php` | Subir (nuevo) |

Origen: `xabia-agent-plugins/central-api/`.

#### B.6.3 Cron de vectorización (Hub)

```cron
*/5 * * * * /usr/bin/php /home/USER/central-api/bin/cron-vectorizer.php --batch-size=50 >> /home/USER/logs/xabia-vectorizer.log 2>&1
```

Opcional en `.env`: `XABIA_EMBEDDING_MODEL=text-embedding-004`.

#### B.6.4 Core en cada WordPress

Instalar **Core ≥ 1.0.168** (latencia chat, Document-to-RAG, CPT por fuente, pasaporte remoto con anexo, listados breves, sync Hub). Sitios con addon MEC: **MEC ≥ 1.0.3**; Woo: **Woo ≥ 1.0.4**. Tras SQL remoto: **Sincronizar** para regenerar chunks.

> **Catálogo nativo:** listados masivos y contacto/imagen por ente **no requieren** Hub ni re-sync para funcionar. Configure mapeo **ENTE** + roles `tel`/`img`/`logotipo` en el agente. Re-sync + vectorización Hub siguen siendo necesarios para RAG semántico profundo.

Tras desplegar: **Entrenar / Sincronizar** en el agente y comprobar en el panel local que «Registros sincronizados» ≈ «Vectores listos».

Detalle técnico: [central-api/README.md § Conocimiento incremental](../central-api/README.md).

---

## Fase C — WordPress del cliente

### C.1 Instalación

1. **Backup** (archivos + BD).
2. **Plugins → Añadir → Subir plugin** → ZIP Core → **Activar**.
3. **Ajustes → Enlaces permanentes → Guardar** (rewrites `/xabia-box/`).
4. **Xabia Agent → Conexión a la IA:** licencia + **Xabia Cloud** → **Guardar configuración**.
5. **Actualizar saldo** y comprobar tokens.

### C.2 Agente mínimo

1. **Nuevo agente** → nombre, saludo (ES), prompt.
2. Fuente de datos → mapeo → **Sincronizar** → **Entrenar** (si vectorial).
3. Shortcode o **Mostrar en el sitio sin shortcode** (Apariencia).

### C.3 WPML (si aplica)

| Paso | Acción |
|------|--------|
| 1 | WPML + String Translation activos; ≥ 2 idiomas |
| 2 | **Configuración → Traducción de cadenas → idioma original = Español** |
| 3 | Instalar Core **≥ 1.0.72** |
| 4 | **Guardar agente** (dispara traducción automática del saludo si hay ≥ 2 idiomas WPML) |
| 5 | Visitar **una página del front** (sync UI: «Escribe aquí…», botones) |
| 6 | Comprobar String Translation: contexto **Xabia AI** + dominio **xabia-intelligence** |
| 7 | **Vaciar caché** (LiteSpeed, Cloudflare, etc.) |

Manual de usuario detallado: [manual-usuario-xabia-core.md §10.12](./manual-usuario-xabia-core.md).

### C.4 Caché y CDN

Tras cualquier versión nueva:

1. Purgar caché de página y objeto.
2. Probar en **ventana de incógnito**.
3. Assets versionados con `XABIA_VERSION` + `filemtime` en CSS/JS del chat.

---

## Fase D — Verificación en producción

Marque cada ítem:

**Chat básico**

- [ ] Licencia válida y saldo > 0
- [ ] Agente no pausado; chat responde en Playground y en página pública
- [ ] Modo nativo: avatar + panel OK
- [ ] Modo shortcode: solo chat embebido, sin avatar flotante

**Multilingüe (WPML)**

- [ ] `/` → saludo y placeholder en español
- [ ] `/eu/` (o idioma EU) → saludo EU + «Idatzi hemen…»
- [ ] `/en/` → saludo EN + «Write here…»
- [ ] String Translation: traducciones **completas** (no solo «+» azul)

**Hub**

- [ ] `POST …/i18n/greeting-translate` ≠ 404
- [ ] Dominio del sitio autorizado en `xabia_licenses` para la licencia usada
- [ ] Licencia Core **activa** para el dominio del sitio
- [ ] Migraciones **016 + 017** aplicadas; cron `cron-vectorizer.php` activo
- [ ] Tras sync de catálogo: vectores por `project_id` estables (no crecen en bucle)

**Catálogo nativo (Core ≥ 1.0.118)**

- [ ] «Empresas de [actividad]» → respuesta instantánea con viñetas; `rag_debug`: `catálogo nativo WP: sí`
- [ ] «Contacto de la última» tras listado → teléfono/email/web correctos
- [ ] «Y alguna imagen?» → URL de campo con rol **Imagen** en mapeo
- [ ] Mapeo: **ENTE** + roles contacto/imagen/logotipo (sin nombres ACF hardcodeados)

**Conocimiento vectorial (si aplica)**

- [ ] Core **≥ 1.0.168** en el sitio cliente
- [ ] MEC **≥ 1.0.3** / Woo **≥ 1.0.4** si aplica
- [ ] SQL remoto: sync + push Hub tras upgrade 1.0.164+
- [ ] Listado breve sin contacto; seguimiento «teléfono de …» con dato correcto
- [ ] Asistente CPT con SQL remoto no muestra CPT del WP local
- [ ] Sidebar del editor hace scroll normal (sin sticky)
- [ ] Panel Xabia: registros sincronizados ≈ vectores listos
- [ ] `SELECT COUNT(*) FROM xabia_knowledge_vectors WHERE project_id = '…'` coherente con el catálogo publicado

**Smart QR / tótem (si aplica)**

- [ ] `/xabia-box/?x_project=ID` pantalla completa sin avatar
- [ ] QR generado abre chat con `ente_id` correcto

**Consola navegador**

- [ ] Sin 404 en `chatbox.js`, `xabia-interface.js`, estilos del plugin

---

## Fase E — Manuales y docs públicas

Regenerar PDF/HTML:

```bash
./scripts/build-modular-manuals-pdf.sh
```

Subir a `xabia.ai/docs/` según [DOCS_PUBLICACION.md](./DOCS_PUBLICACION.md):

- `manual-usuario-xabia-core.pdf` / `.html` (principal)
- Resto de manuales modulares si hubo cambios

Invalidar CDN en `/docs/*`.

---


## Fase Ebis — Polar (ZIP retail de la tienda)

El pipeline `./xabia-deploy.sh` publica el ZIP en **xabia.ai/downloads/** (actualizaciones Hub / descarga directa). El archivo que se adjunta a los productos en **Polar** es el **retail**:

```bash
./scripts/build-retail-plugin-zips.sh
# → xabia-agent-plugins/dist/retail/xabia-agent-core-1.0.201-retail.zip
```

Suba ese ZIP en el panel de Polar (producto Core / packs) sustituyendo el archivo descargable anterior. No hay CLI Polar en el Release Engine: la subida es manual o vía API de Polar con token de organización.

## Sitio marketing (tema hijo «Prueba tu web»)

Los cambios del sandbox **Prueba tu web**, modal AJAX y email de bienvenida viven en el **tema hijo** `hello-elementor-child` (Local: `home-xabia/`), **no** en el ZIP del Core. Despliéguelos aparte en el WordPress de marketing (`xabia.ai`) si aplica.

## Fase F — Rollback

| Qué falló | Acción |
|-----------|--------|
| Solo Core | Restaurar ZIP anterior desde `dist/`; guardar enlaces permanentes; vaciar caché |
| Hub DTP | Restaurar `Router.php` anterior; quitar handlers DTP; Core sigue con fallback (saludo ES + manual WPML) |
| WPML | No tocar `icl_sitepress_settings` a mano; revertir idioma original de cadenas desde WPML admin |

Las opciones `xabia_*` en `wp_options` se conservan entre versiones del Core salvo breaking change documentado.

---

## Orden recomendado de despliegue (Hub conocimiento + Core 1.0.201)

```
1. Hub: migraciones 016 + 017 en MySQL
2. Hub: subir KnowledgeSyncHandler, KnowledgeVectorsRepository, VectorizationWorker, cron-vectorizer.php
3. Hub: activar cron vectorizer (cada 5 min)
4. Hub: subir handlers DTP si aún no están (Router, DtpEntitlement, …)
5. curl smoke test DTP → 401/403 (no 404)
6. Build ZIP Core 1.0.201 (+ MEC 1.0.3 / Woo 1.0.4) y subir a xabia.ai/downloads/
7. WordPress: actualizar Core (Plugins → Actualizar o ZIP manual)
8. Verificar catálogo nativo en Playground (listado + «contacto de la última»)
9. Entrenar / sincronizar agente si usa RAG vectorial; comprobar contadores locales
10. Actualizar XABIA_CORE_LATEST_VERSION en .env del hub
11. Vaciar caché + probar chat (nativo + RAG; repetir pregunta para validar caché)
```

---

*Core v1.0.201 — agosto 2026 — UI chat stream + Markdown, avatar parlante / [xabia_launcher], starter questions; latencia chat, Document-to-RAG, pasaporte remoto, MEC 1.0.3 / Woo 1.0.4; sync Hub, WPML + DTP.*
