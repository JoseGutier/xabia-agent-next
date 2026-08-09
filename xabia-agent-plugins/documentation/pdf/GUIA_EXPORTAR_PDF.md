# Exportar manuales a PDF (Markdown PDF + estilo Xabia)

Puede generar PDF con la extensión **Markdown PDF** (`yzhang.markdown-pdf`) usando los estilos y plantillas del repositorio.

## 1. Qué hay en el repo

| Recurso | Uso |
|---------|-----|
| [`xabia-manual-pdf.css`](../xabia-manual-pdf.css) | Tipografías, **acentos azul** (`#1565c0`) + magenta (`#c2185b`), tablas, saltos de página. |
| [`pdf/xabia-logo-mark.svg`](xabia-logo-mark.svg) | Marca simple vectorial para la portada del PDF. |
| [`pdf/cierre-manual-plantilla.md`](cierre-manual-plantilla.md) | Texto de cierre con datos de empresa — **cópielo al final** del manual `.md` antes de exportar (o conviénelo con su legal). |
| [`.vscode/settings.json`](../../.vscode/settings.json) | Configuración recomendada para **pie y cabecera** en cada página del PDF. |

## 2. Configuración Markdown PDF (VS Code / Cursor)

En el menú de comandos: **Markdown PDF: Export (pdf)** sobre el `.md` abierto.

Los ajustes del workspace definen:

- **Cabecera:** barra **azul** + título “Xabia AI”.
- **Pie:** solo texto de marca, dominio público (`xabia.ai`) y **numeración de página**. No se usan las clases por defecto de Chromium que muestran **ruta `file:///…`** ni título largo del documento local.
- **CSS Xabia** sin estilos por defecto del generador (mejor control visual).

**Márgenes** dejados algo amplios para que no solapen cabecera/pie.

Si la extensión no aplica `markdown-pdf.styles` con `${workspaceFolder}`, sustituya por la ruta absoluta a `xabia-agent-plugins/documentation/xabia-manual-pdf.css`.

## 3. Logo en la primera página del contenido

Las plantillas **header/footer** de Puppeteer son HTML fijo y no sustituyen bien un logo complejo sin URL absoluta. Lo más robusto:

1. Al **inicio** del markdown del manual (después del título), añada una línea (ruta relativa desde el `.md` en `xabia-agent-plugins/documentation/`):

```markdown
![Xabia](pdf/xabia-logo-mark.svg)
```

2. Si su manual está en otra carpeta, ajuste la ruta relativa al SVG.

Opcionalmente **sustituya** `pdf/xabia-logo-mark.svg` por su PNG/SVG oficial de marca.

## 4. Pie de página y cierre corporativo

- **Pie repetido en todas las páginas:** lo resuelven `markdown-pdf.headerTemplate` y `markdown-pdf.footerTemplate` en [.vscode/settings.json](../../.vscode/settings.json).
- **Hoja final con datos legales / contacto / disclaimer:** pegue el contenido de [`cierre-manual-plantilla.md`](cierre-manual-plantilla.md) **al final** del manual principal, y edite empresa, CIF, emails, etc.

Para dar estilo al bloque de cierre puede envolverlo en HTML en el `.md`:

```html
<div class="manual-pdf-cierre">

## Información de la empresa
...
</div>
```

(Markdown PDF convierte MD con un motor que suele permitir HTML embebido.)

## 5. Alternativa: scripts por Chrome (sin extensión)

- Manuales **modulares** (recomendado): [`scripts/build-modular-manuals-pdf.sh`](../../scripts/build-modular-manuals-pdf.sh) → PDF/HTML de Core, MEC, Woo, etc.
- El script legacy [`scripts/build-manual-pdf.sh`](../../scripts/build-manual-pdf.sh) apunta a `MANUAL_USUARIO.md`, que está **deprecado** (solo redirección).
- [`scripts/build-modular-manuals-pdf.sh`](../../scripts/build-modular-manuals-pdf.sh) → `build/MANUAL_Xabia_Core.pdf`, `build/MANUAL_Xabia_Avirato.pdf`, `build/MANUAL_Xabia_MEC.pdf`, `build/MANUAL_Xabia_Woo.pdf` y copias en `xabia-agent-plugins/documentation/manual-usuario-xabia-{core,mec,woo}.{html,pdf}`.

Ambos usan Puppeteer con pie de marca (título + «por Digixop» + paginación), sin rutas `file:///…` en el margen.

## 5b. Publicación en producción (xabia.ai/docs)

Los PDF canónicos para clientes se alojan en **https://xabia.ai/docs/** (p. ej. `manual-usuario-xabia-core.pdf`). Tras `./scripts/build-modular-manuals-pdf.sh`, subir los archivos según [DOCS_PUBLICACION.md](../DOCS_PUBLICACION.md).

Si necesita el **mismo pie de marca por página** que Markdown PDF desde script, haría falta Puppeteer con `headerTemplate`/`footerTemplate` (Chrome por CLI no lo expone).

## 6. Comando rápido sugerido

1. Abrir `xabia-agent-plugins/documentation/manual-usuario-xabia-core.md` (o el manual que toque).
2. Añadir logo y bloque de cierre si aún no están.
3. **Command Palette** → `Markdown PDF: Export (pdf)`.
4. Con el `.vscode/settings.json` del workspace, la salida va a **`build/`** junto al proyecto.

### Aviso: aparece `file:///Users/...` en el pie

Eso suele ocurrir si **Imprime** el `.html` desde el navegador con la opción **“Cabeceras y pies de página”** activada (Chrome imprime ahí la URL del archivo). **No** debe salir si exporta con **Markdown PDF** usando nuestras plantillas, ni con **`./scripts/build-modular-manuals-pdf.sh`** (Chrome `--print-to-pdf-no-header`).
