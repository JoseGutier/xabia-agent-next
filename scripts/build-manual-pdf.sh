#!/usr/bin/env bash
# PDF maquetado estilo Xabia (azul + magenta) del manual de usuario (marked + Chrome headless).
#
# Uso: ./scripts/build-manual-pdf.sh
# Requisitos: Node.js (npx), Google Chrome en /Applications.
# Salida: build/MANUAL_USUARIO_Xabia.pdf + build/MANUAL_USUARIO_Xabia.html (referencia)

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

DOCS="$ROOT/xabia-agent-plugins/documentation"
if [[ ! -d "$DOCS" ]]; then
  DOCS="$ROOT/docs"
fi
MD="$DOCS/MANUAL_USUARIO.md"
CSS="$DOCS/xabia-manual-pdf.css"
OUT_DIR="$ROOT/build"
mkdir -p "$OUT_DIR"

CHROME=""
for c in \
  "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
  "/Applications/Chromium.app/Contents/MacOS/Chromium"; do
  if [[ -x "$c" ]]; then
    CHROME="$c"
    break
  fi
done
if [[ -z "$CHROME" ]]; then
  echo "ERROR: no se encontró Google Chrome ni Chromium en /Applications." >&2
  exit 1
fi

if [[ ! -f "$MD" || ! -f "$CSS" ]]; then
  echo "ERROR: faltan $MD o $CSS" >&2
  exit 1
fi

TMP_BODY="$OUT_DIR/.manual-body-tmp.html"
HTML_OUT="$OUT_DIR/MANUAL_USUARIO_Xabia.html"
PDF_OUT="$OUT_DIR/MANUAL_USUARIO_Xabia.pdf"

npx --yes marked@12.0.0 -o "$TMP_BODY" -i "$MD" --gfm

{
  echo '<!DOCTYPE html>'
  echo '<html lang="es">'
  echo '<head>'
  echo '<meta charset="utf-8">'
  echo '<meta name="viewport" content="width=device-width, initial-scale=1">'
  echo '<title>Manual de usuario — Xabia Agent</title>'
  echo '<style>'
  cat "$CSS"
  echo '</style>'
  echo '</head>'
  echo '<body>'
  cat "$TMP_BODY"
  echo '</body>'
  echo '</html>'
} > "$HTML_OUT"

rm -f "$TMP_BODY"

HTML_ABS="$(cd "$(dirname "$HTML_OUT")" && pwd)/$(basename "$HTML_OUT")"
FILE_URL="file://${HTML_ABS}"

# Impresión: tiempo virtual alto para documentos largos (muchas tablas/bloques).
"$CHROME" --headless=new \
  --disable-gpu \
  --no-first-run \
  --no-pdf-header-footer \
  --virtual-time-budget=120000 \
  --print-to-pdf-no-header \
  --print-to-pdf="$PDF_OUT" \
  "$FILE_URL"

if [[ ! -f "$PDF_OUT" || ! -s "$PDF_OUT" ]]; then
  echo "ERROR: Chrome no generó el PDF. Revisa $HTML_OUT en el navegador (Imprimir → PDF)." >&2
  exit 1
fi

echo "HTML: $HTML_OUT"
echo "PDF:  $PDF_OUT ($(du -h "$PDF_OUT" | cut -f1))"
