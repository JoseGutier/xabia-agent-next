# Publicación de manuales en xabia.ai/docs

Los manuales en **PDF** y **HTML** del producto Xabia se sirven al público desde:

**Índice:** [https://xabia.ai/docs/](https://xabia.ai/docs/) («Manuales de Xabia AI»)  
**Base URL:** [https://xabia.ai/docs/](https://xabia.ai/docs/)

## Portada

| Archivo local | URL pública |
|---------------|-------------|
| `index.html` | https://xabia.ai/docs/ · https://xabia.ai/docs/index.html |
| `assets/manual.css` | https://xabia.ai/docs/assets/manual.css |

Estilo alineado con los **Manuales Aktiba** (Fraunces + Source Sans 3, hero + rejilla de tarjetas, tipografía limpia). Acento de marca Xabia (`#2170b0`).

## Manuales

Tras regenerar con `./scripts/build-modular-manuals-pdf.sh`, subir desde `xabia-agent-plugins/documentation/` al directorio web `docs/` del servidor (el script `./xabia-deploy.sh` ya incluye `index.html` + `assets/manual.css`):

| Archivo local | URL pública |
|---------------|-------------|
| `manual-usuario-xabia-core.pdf` | https://xabia.ai/docs/manual-usuario-xabia-core.pdf |
| `manual-usuario-xabia-core.html` | https://xabia.ai/docs/manual-usuario-xabia-core.html |
| `manual-usuario-xabia-byok-google.html` | https://xabia.ai/docs/manual-usuario-xabia-byok-google.html |
| `manual-usuario-xabia-smart-qr.pdf` | https://xabia.ai/docs/manual-usuario-xabia-smart-qr.pdf |
| `manual-usuario-xabia-smart-qr.html` | https://xabia.ai/docs/manual-usuario-xabia-smart-qr.html |
| `manual-usuario-xabia-mec.pdf` | https://xabia.ai/docs/manual-usuario-xabia-mec.pdf |
| `manual-usuario-xabia-mec.html` | https://xabia.ai/docs/manual-usuario-xabia-mec.html |
| `manual-usuario-xabia-woo.pdf` | https://xabia.ai/docs/manual-usuario-xabia-woo.pdf |
| `manual-usuario-xabia-woo.html` | https://xabia.ai/docs/manual-usuario-xabia-woo.html |
| `manual-usuario-xabia-avirato.pdf` | https://xabia.ai/docs/manual-usuario-xabia-avirato.pdf |
| `manual-usuario-xabia-avirato.html` | https://xabia.ai/docs/manual-usuario-xabia-avirato.html |

El **manual Core** es el principal para clientes del plugin base; **Smart QR** tiene manual propio (incluido en Core); los demás son complementarios por addon de pago.

## Publicación en GitHub

Si el repositorio público se usa como espejo de producto, actualizar también:

1. `README.md` de la raíz, con la versión Core actual y enlace al **índice** `https://xabia.ai/docs/`.
2. `xabia-agent-plugins/documentation/README.md`, índice canónico de documentación.
3. Los Markdown fuente `manual-usuario-xabia-*.md`; no editar a mano los `.html`/`.pdf` generados del cuerpo de cada manual.
4. Mantener `documentation/index.html` y `documentation/assets/manual.css` (portada).
5. Adjuntar o publicar los ZIP de distribución solo desde `xabia-agent-plugins/dist/` o `dist/retail/`, según el canal.

Antes de subir cambios a GitHub, regenerar manuales y comprobar que no se incluyen credenciales ni `.env`.

## Regenerar en el monorepo

```bash
cd /ruta/a/xabia-agent-next
./scripts/build-modular-manuals-pdf.sh
```

**Requisitos:** Node.js (`npm install` en la raíz), Google Chrome o Chromium en macOS (ver script).

**Salidas:**

- `build/MANUAL_Xabia_*.pdf` y `.html` (temporales de build)
- Copias en `xabia-agent-plugins/documentation/manual-usuario-xabia-{core,smart-qr,mec,woo,avirato}.{pdf,html}`
- Portada: `documentation/index.html` + `documentation/assets/manual.css` (no las regenera el script PDF; se editan a mano)

## Enlaces en producto y soporte

- Cabecera del [manual-usuario-xabia-core.md](./manual-usuario-xabia-core.md) enlaza al PDF en línea.
- Preferir enlazar a **https://xabia.ai/docs/** (índice) en webs, emails y panel.
- El ZIP **retail** del Core puede embeber copias en `docs/` del plugin; la versión **canónica** para usuarios finales es la de **xabia.ai/docs/**.

## Caché CDN

Tras subir una versión nueva, invalidar caché de `/docs/`, `/docs/index.html`, `/docs/*.pdf`, `*.html` y `/docs/assets/manual.css` en Cloudflare (el deploy lo hace si hay token).

---

*Core v1.0.283 — agosto 2026*
