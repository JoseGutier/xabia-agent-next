#!/usr/bin/env bash
# Empaqueta Xabia Agent LITE para instalación local / WordPress.org.
#
# Uso (desde la raíz del repo):
#   ./scripts/build-lite-plugin.sh
#
# Salida:
#   dist/xabia-agent-lite/          (directorio desplegable)
#   dist/xabia-agent-lite.zip       (ZIP instalable)

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PLUGIN_SLUG="xabia-agent-lite"
SOURCE_DIR="$ROOT/xabia-agent-plugins/packages/xabia-agent-core"
STAGE_DIR="$ROOT/dist/$PLUGIN_SLUG"
ZIP_PATH="$ROOT/dist/${PLUGIN_SLUG}.zip"
LITE_VERSION="${XABIA_LITE_VERSION:-1.0.0-lite}"

sed_inplace() {
  if [[ "$(uname -s)" == "Darwin" ]]; then
    sed -i '' "$@"
  else
    sed -i "$@"
  fi
}

if [[ ! -d "$SOURCE_DIR" ]]; then
  echo "ERROR: no existe $SOURCE_DIR" >&2
  exit 1
fi

echo "== Xabia Agent LITE — build =="
echo "Fuente:  $SOURCE_DIR"
echo "Staging: $STAGE_DIR"
echo "Versión: $LITE_VERSION"

rm -rf "$STAGE_DIR"
mkdir -p "$ROOT/dist"

rsync -a \
  --exclude='.DS_Store' \
  --exclude='vendor/' \
  --exclude='node_modules/' \
  --exclude='tests/' \
  --exclude='.git/' \
  "$SOURCE_DIR/" "$STAGE_DIR/"

MAIN_FILE="$STAGE_DIR/xabia-intelligence.php"
if [[ ! -f "$MAIN_FILE" ]]; then
  echo "ERROR: falta xabia-intelligence.php" >&2
  exit 1
fi

echo "== Purga PRO =="
rm -rf \
  "$STAGE_DIR/addons" \
  "$STAGE_DIR/integrations/central" \
  "$STAGE_DIR/integrations/reservas" \
  "$STAGE_DIR/includes/mu-plugins" \
  "$STAGE_DIR/tests" \
  "$STAGE_DIR/docs" \
  "$STAGE_DIR/vendor" \
  "$STAGE_DIR/api"

rm -f \
  "$STAGE_DIR/admin/class-xabia-admin.php" \
  "$STAGE_DIR/admin/css/xabia-admin.css" \
  "$STAGE_DIR/admin/js/xabia-smart-qr-admin.js" \
  "$STAGE_DIR/readme-lite.txt" \
  "$STAGE_DIR/core/class-xabia-digixop-client.php" \
  "$STAGE_DIR/core/class-xabia-hub-knowledge.php" \
  "$STAGE_DIR/core/class-xabia-federation-nexus.php" \
  "$STAGE_DIR/core/class-xabia-updater.php" \
  "$STAGE_DIR/core/class-xabia-addon-updater.php" \
  "$STAGE_DIR/integrations/class-xabia-sql-connector.php" \
  "$STAGE_DIR/composer.json" \
  "$STAGE_DIR/composer.lock" \
  "$STAGE_DIR/phpunit.xml.dist" \
  "$STAGE_DIR/README.md"

LITE_README="$SOURCE_DIR/readme-lite.txt"
if [[ -f "$LITE_README" ]]; then
  cp -f "$LITE_README" "$STAGE_DIR/readme.txt"
  echo "== readme.txt WordPress.org (desde readme-lite.txt) =="
else
  echo "WARN: no existe readme-lite.txt — se omite readme.txt LITE" >&2
fi

echo "== Cabecera WordPress.org + constantes LITE =="
sed_inplace "s/^\([[:space:]]*\* Plugin Name:\).*/\1 Xabia Agent LITE - AI Chatbot \& Local Assistant/" "$MAIN_FILE"
sed_inplace "s/^\([[:space:]]*\* Version:\).*/\1 ${LITE_VERSION}/" "$MAIN_FILE"
sed_inplace "s/^\([[:space:]]*\* Description:\).*/\1 Agente de IA para WordPress con ingesta de CSV y scraper local. BYOK con Google Gemini./" "$MAIN_FILE"

python3 - "$MAIN_FILE" <<'PY'
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
text = path.read_text(encoding="utf-8")
needle = "if (!defined('ABSPATH')) exit;"
lite_defs = (
    "if (!defined('ABSPATH')) exit;\n\n"
    "if (!defined('XABIA_AGENT_LITE')) {\n"
    "    define('XABIA_AGENT_LITE', true);\n"
    "}\n"
    "if (!defined('XABIA_LITE_BUILD')) {\n"
    "    define('XABIA_LITE_BUILD', true);\n"
    "}"
)
if needle not in text:
    raise SystemExit("No se encontró guard ABSPATH")
if "XABIA_AGENT_LITE" not in text:
    text = text.replace(needle, lite_defs, 1)
path.write_text(text, encoding="utf-8")
PY

echo "== Verificación anti-PRO =="
FORBIDDEN=(
  "api"
  "addons"
  "integrations/central"
  "integrations/reservas"
  "integrations/class-xabia-sql-connector.php"
  "admin/class-xabia-admin.php"
  "core/class-xabia-digixop-client.php"
)
for rel in "${FORBIDDEN[@]}"; do
  if [[ -e "$STAGE_DIR/$rel" ]]; then
    echo "ERROR: artefacto PRO presente: $rel" >&2
    exit 1
  fi
done

echo "== ZIP =="
rm -f "$ZIP_PATH"
( cd "$ROOT/dist" && zip -r -q "$ZIP_PATH" "$PLUGIN_SLUG" -x "*.DS_Store" "*/.DS_Store" )

echo "✓ Directorio: $STAGE_DIR"
echo "✓ ZIP:        $ZIP_PATH ($(du -h "$ZIP_PATH" | cut -f1))"
echo "Listo para instalar en WordPress (Plugins → Subir plugin)."
