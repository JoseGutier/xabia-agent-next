# Release notes — Xabia Agent Core v1.0.407

**Rama:** `feature/ux-voice-hero-redesign`  
**Fecha:** 7 nov 2026  
**ZIP:** `xabia-agent-plugins/dist/xabia-agent-core-1.0.407.zip`  
**Descarga pública:** https://xabia.ai/downloads/xabia-agent-core-1.0.407.zip  

---

## Resumen ejecutivo

Release centrada en **catálogo nativo (directorio)** y **higiene RAG**, más una corrección crítica de **UX en el chatbox** que hacía desaparecer viñetas antes de las pastillas v2.

**Principio:** Core agnóstico — sin acoplar Woo/MEC/Avirato en el motor de catálogo; addons siguen en `xabia-agent-plugins/packages/`.

---

## Cambios por versión (397 → 407)

| Versión | Ámbito |
|---------|--------|
| **1.0.397–400** | Catálogo nativo Aktiba: WP_Query agnóstico, dedupe por título, sin WPML en SQL |
| **1.0.401** | Sinónimos admin (`keyword_expansions`), badge intro, viñetas `• **` |
| **1.0.402** | Intro legible en taxonomía, CSS tarjetas |
| **1.0.403** | **SSOT field mapping:** `map_catalog_native_entity_from_post`, `website_url`, sync viñetas/tarjetas |
| **1.0.404** | Viñetas desde `$final_entities`, log `[XABIA_CATALOG_SUCCESS]` |
| **1.0.405** | Fallback nombre unificado, sanitización LLM `[ACTION-CARD:…]`, prompt sin etiquetas ACTION del modelo |
| **1.0.406** | Paridad viñetas/tarjetas en backend, `[Enlace web:]` en contrato RAG, rehidratación URL ficha+web en `encode_rag_entity_card_from_part` |
| **1.0.407** | **Frontend:** `xabiaIsProductCard` solo transaccional (`cart`/precio); fichas `view` no ejecutan `xabiaPeelShopLead` (fix viñeta Cumbre) |

---

## Archivos clave (1.0.407)

- `frontend/widgets/chatbox.js` — heurística producto vs directorio
- `frontend/widgets/chatbox-v1.0.407.js` — asset versionado (CDN Hostinger)
- `core/class-xabia-catalog-list.php` — bullets SSOT
- `api/class-xabia-api.php` — nativo + RAG cards
- `core/class-xabia-knowledge-ingest.php` — mapper nativo (sin cambios de schema en 407)
- `core/class-xabia-brain.php` — `[Enlace web:]` opcional en contrato
- `core/class-xabia-action-card.php` — strip marcadores LLM + enrich `url_ext`

---

## Tests CLI

```bash
cd xabia-agent-plugins/packages/xabia-agent-core
php -l core/class-xabia-catalog-list.php
php tests/test-xabia-catalog-*.php
php tests/test-xabia-action-card.php
node --check frontend/widgets/chatbox.js
```

---

## Despliegue WordPress

1. Backup (archivos + BD).
2. **Plugins → Subir** `xabia-agent-core-1.0.407.zip` → sustituir.
3. **Ajustes → Enlaces permanentes → Guardar**.
4. Vaciar caché (LiteSpeed / CDN / objeto).
5. Probar en **incógnito**:
   - *«empresas para montar a caballo»* → 2 viñetas + 2 pastillas (Hípica).
   - Pastillas directorio: **Ver ficha** + **Sitio web** si el mapeo lo define.

### Sitios actualizados en esta release

| Sitio | Dominio | Notas |
|-------|---------|--------|
| Saludavida | saludavida.com | Woo + catálogo; prioridad chatbox + RAG web externa |
| Astei | astei.com | Directorio / Avirato addon según licencia |
| Aktiba | (cuando se despliegue el mismo ZIP) | Validación catálogo nativo Hípica |

**Versión anterior detectada en saludavida/astei antes del despliegue:** 1.0.391.

---

## Hub / CDN (xabia.ai)

```env
XABIA_CORE_LATEST_VERSION=1.0.407
XABIA_CORE_UPDATE_PACKAGE=https://xabia.ai/downloads/xabia-agent-core-1.0.407.zip
```

Purgar CDN en `/downloads/xabia-agent-core-1.0.407.zip` y endpoint `/updates`.

---

## Git

- Tag sugerido: `v1.0.407`
- Rama de trabajo: `feature/ux-voice-hero-redesign`

---

## Limitaciones conocidas (post-407)

- Consultas con **typos** («hipca») no disparan catálogo nativo → RAG; texto y pastillas pueden desalinearse hasta una oleada de fuzzy match / post-proceso RAG.
- Re-ingesta de conocimiento recomendada si se usa `[Enlace web:]` en chunks ya indexados (nuevos contratos en `format_generic_entity_contract`).
