# Documentación del repositorio (`xabia-agent-plugins/documentation/`)

Índice de los documentos en esta carpeta y su propósito.

| Archivo | Contenido |
|---------|-----------|
| [pdf/GUIA_EXPORTAR_PDF.md](./pdf/GUIA_EXPORTAR_PDF.md) | **Exportar manuales a PDF** con la extensión Markdown PDF, cabecera/pie Xabia, logo SVG y cierre corporativo. |
| [MANUAL_USUARIO.md](./MANUAL_USUARIO.md) | **Deprecado** — solo redirige al manual modular. Use [manual-usuario-xabia-core.md](./manual-usuario-xabia-core.md). |
| [manual-usuario-xabia-avirato.md](./manual-usuario-xabia-avirato.md) | **Manual de usuario** del addon **Xabia Avirato**: guía rápida, instalación, pestaña Avirato del agente, campos (webcode, motor, filtros), experiencia en el chat, idioma políglota del Core, tokens y solución de problemas. |
| [index.html](./index.html) | **Portada «Manuales de Xabia AI»** (estilo limpio Aktiba). En vivo: [xabia.ai/docs/](https://xabia.ai/docs/). |
| [manual-usuario-xabia-core.md](./manual-usuario-xabia-core.md) | **Manual Core** (**v1.0.201**): UI chat stream + Markdown, avatar parlante / launcher, CPT por fuente (§7.2), pasaporte remoto (§11), WPML, Smart QR, Wallet. PDF/HTML en [xabia.ai/docs/](https://xabia.ai/docs/manual-usuario-xabia-core.pdf). |
| [manual-usuario-xabia-mec.md](./manual-usuario-xabia-mec.md) | **Manual MEC** (addon **1.0.3**, Core ≥ 1.0.168): eventos, reservas, SQL remoto híbrido, deep schema. |
| [manual-usuario-xabia-woo.md](./manual-usuario-xabia-woo.md) | **Manual Woo** (addon **1.0.4**, Core ≥ 1.0.168): catálogo, carrito, remoto híbrido, metas `_price`/`_sku`. |
| [manual-usuario-xabia-smart-qr.md](./manual-usuario-xabia-smart-qr.md) | **Manual Smart QR / tótems** (Core): guía extendida con ejemplos museo, hotel, retail, `/xabia-box/`, generador QR, POI `?xqr=`, migración del plugin legacy. PDF/HTML vía script de build. |
| [DOCS_PUBLICACION.md](./DOCS_PUBLICACION.md) | **Publicar manuales** en el servidor (`xabia.ai/docs/`), regenerar PDF y CDN. |
| [DESPLIEGUE_PRODUCCION_CORE.md](./DESPLIEGUE_PRODUCCION_CORE.md) | **Despliegue completo** Core + Hub DTP + WPML + manuales (checklist fases A–F). |
| [DESARROLLO.md](./DESARROLLO.md) | **Guía de desarrollo** a fondo: arranque del plugin, pipeline de chat, ingestión, **§12 filtros/hooks/HMAC**, frontend, admin, empaquetado. |
| [INFORME_ESTRATEGIA_CORE_MAPEADOR_TOKENS.md](./INFORME_ESTRATEGIA_CORE_MAPEADOR_TOKENS.md) | Estrategia de mapeo y tokens (notas de producto/diseño). |
| [PLAN_VISION_XABIA_STANDALONE_Y_FEDERACION.md](./PLAN_VISION_XABIA_STANDALONE_Y_FEDERACION.md) | Visión standalone y federación; **v1.2** alineada con producto **v1.0.0**, addon Central, carpeta **`addons/`**, REST MEC en `addons/xabia-mec/`, y lo pendiente (Tools, standalone, fases red). |
| [PLAN_FEDERACION_DATOS_Y_ACTUALIZACIONES.md](./PLAN_FEDERACION_DATOS_Y_ACTUALIZACIONES.md) | Plan de datos y actualizaciones en federación. |
| [FEDERACION_COMO_ADDON_Y_COMERCIAL.md](./FEDERACION_COMO_ADDON_Y_COMERCIAL.md) | Federación como addon y aspecto comercial. |

Documentación en la **raíz del repo**:

- [README.md](../../README.md) — Instalación ZIP e índice general.
- [MEMORIA_TECNICA.md](../../MEMORIA_TECNICA.md) — Referencia técnica completa (arquitectura, BD, APIs, Vertex, changelog).
- [CONTRIBUTING.md](../../CONTRIBUTING.md) — Guía rápida para colaboradores (estructura, prompt, i18n).

**Código y carpetas clave (producto v1.0.0):**

| Carpeta / componente | Rol |
|----------------------|-----|
| `core/`, `admin/`, `frontend/` | Núcleo del plugin WordPress en `packages/xabia-agent-core/` (RAG, CSV/SQL, CPT, chat, panel). |
| `integrations/*.php` | Conector SQL genérico en la raíz de `integrations/`. |
| `integrations/central/`, `reservas/` | Integraciones transversales (federación, reservas MEC+Amelia). |
| `addons/xabia-mec/`, `addons/xabia-amelia/`, `addons/xabia-avirato/`, `addons/xabia-woo/`, `addons/xabia-qr/` | Addons verticales de producto (`register_xabia_addon`; Woo, MEC, Avirato…) y módulo **Smart QR/POI bundled** en `addons/xabia-qr/` (slug **`qr`**, incluido en Core sin licencia aparte). Orquestación en `core/class-xabia-smart-qr.php`. |
| `../api/` | Código fuente **AJAX/REST del Core** (`Xabia_API`); carpeta hermana de `packages/` dentro de `xabia-agent-plugins/`. |
| `../central-api/` | Hub Xabia (`xabia.ai`): licencias, wallet, proxy → Vertex, firma HMAC por licencia; ver [README del hub](../central-api/README.md). El idioma enviado por el cliente queda como respaldo/UI, no como bloqueo de respuesta. |

Addon Central (federación en WordPress): [README del addon Central](../packages/xabia-agent-core/integrations/central/README.md).

---

*Última actualización del índice: agosto 2026 — Core **v1.0.201** (chat UI + Markdown, launcher, parlante); manuales en **https://xabia.ai/docs/** (ver [DOCS_PUBLICACION.md](./DOCS_PUBLICACION.md)).*

Actualización operativa (abril 2026):
- Hub SaaS en `https://xabia.ai/api/xabia/v1/` (gateway público) con normalización automática de base path en router; despliegue legacy `api-xabia-saas` aún soportado.
- Licencias con unicidad compuesta `license_key + client_domain`.
- Detalle de firma HMAC y filtros de addons: [DESARROLLO.md §12](./DESARROLLO.md#12-filtros-hooks-y-ajustes-avanzados-para-desarrolladores).
- Avirato envía `establishment_id` por cliente en el payload del proxy; no existe `AVIRATO_ESTABLISHMENT_ID` global en el hub.
- Paquete comercial Core + Avirato (manuales embebidos en el Core): `xabia-agent-plugins/dist/retail/xabia-agent-core-<versión>-retail.zip` y `xabia-agent-plugins/dist/retail/xabia-avirato-<versión>-retail.zip` vía **`scripts/build-retail-plugin-zips.sh`**.
- **PDF de manuales modulares** (maquetación estilo Xabia): **`scripts/build-modular-manuals-pdf.sh`**. El script legacy `build-manual-pdf.sh` ya no es la fuente canónica (`MANUAL_USUARIO.md` deprecado).
- Paquete recomendado de producción: `build/xabia-nexus.zip` generado por `scripts/build-xabia-nexus-zip.sh` incluyendo `vendor/` y excluyendo el árbol del hub (`xabia-agent-plugins/central-api/`).
