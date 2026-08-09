#!/usr/bin/env bash
# PDF/HTML modulares: marked + CSS + Puppeteer (Chrome del sistema).
# PDF incluye pie con título, «por Digixop» y paginación (pág. n / total).
#
# Uso: ./scripts/build-modular-manuals-pdf.sh
# Requisitos: Node.js (npx + npm install para puppeteer-core), Chrome o Chromium en /Applications (macOS).

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

DOCS="$ROOT/xabia-agent-plugins/documentation"
if [[ ! -d "$DOCS" ]]; then
  DOCS="$ROOT/docs"
fi
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

if [[ ! -f "$CSS" ]]; then
  echo "ERROR: falta $CSS" >&2
  exit 1
fi

if [[ ! -d "$ROOT/node_modules/puppeteer-core" ]]; then
  echo "Instalando puppeteer-core (primera vez)…" >&2
  (cd "$ROOT" && npm install --no-audit --no-fund)
fi

gen_pdf() {
  local MD="$1"
  local BASE="$2"
  local TITLE="$3"
  local FOOTER_LINE="$4"
  local TMP_BODY="$OUT_DIR/.${BASE}-body.html"
  local HTML_OUT="$OUT_DIR/${BASE}.html"
  local PDF_OUT="$OUT_DIR/${BASE}.pdf"
  npx --yes marked@12.0.0 -o "$TMP_BODY" -i "$MD" --gfm
  {
    echo '<!DOCTYPE html>'
    echo '<html lang="es">'
    echo '<head>'
    echo '<meta charset="utf-8">'
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">'
    echo "<title>${TITLE}</title>"
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
  export CHROME_EXECUTABLE="$CHROME"
  node "$ROOT/scripts/print-manual-pdf.mjs" "$HTML_ABS" "$PDF_OUT" "$FOOTER_LINE"

  echo "HTML: $HTML_OUT"
  echo "PDF: $PDF_OUT ($(du -h "$PDF_OUT" | cut -f1))"
}

gen_pdf "$DOCS/manual-usuario-xabia-core.md" "MANUAL_Xabia_Core" "Manual de usuario — Xabia Agent Core" "Manual de usuario de Xabia Agent Core | por Digixop"
gen_pdf "$DOCS/manual-usuario-xabia-avirato.md" "MANUAL_Xabia_Avirato" "Manual de usuario — Xabia Avirato" "Manual de usuario de Xabia Avirato | por Digixop"
gen_pdf "$DOCS/manual-usuario-xabia-mec.md" "MANUAL_Xabia_MEC" "Manual de usuario — Xabia MEC" "Manual de usuario de Xabia MEC | por Digixop"
gen_pdf "$DOCS/manual-usuario-xabia-woo.md" "MANUAL_Xabia_Woo" "Manual de usuario — Xabia Woo" "Manual de usuario de Xabia Woo | por Digixop"

gen_pdf "$DOCS/manual-usuario-xabia-smart-qr.md" "MANUAL_Xabia_Smart_QR" "Manual de usuario — Smart QR (Xabia Core)" "Manual Smart QR / tótems — Xabia Agent Core | por Digixop"

# Copias junto a los .md modulares (documentation/ en monorepo)
for pair in \
  "MANUAL_Xabia_Core:manual-usuario-xabia-core" \
  "MANUAL_Xabia_Avirato:manual-usuario-xabia-avirato" \
  "MANUAL_Xabia_MEC:manual-usuario-xabia-mec" \
  "MANUAL_Xabia_Woo:manual-usuario-xabia-woo" \
  "MANUAL_Xabia_Smart_QR:manual-usuario-xabia-smart-qr"; do
  base="${pair%%:*}"
  name="${pair##*:}"
  cp -f "$OUT_DIR/${base}.pdf" "$DOCS/${name}.pdf"
  cp -f "$OUT_DIR/${base}.html" "$DOCS/${name}.html"
done
