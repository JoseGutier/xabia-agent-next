#!/usr/bin/env bash
# Paquete WordPress.org: Xabia Agent LITE (BYOK, sin ecosistema PRO).
# Uso (desde la raíz del repo): ./scripts/build-xabia-agent-lite-zip.sh
# Salida: xabia-agent-plugins/dist/xabia-agent-lite-<versión>.zip
#
# Nota sed -i: sintaxis GNU (Linux/CI). En macOS BSD usar sed -i '' "s/.../g".

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# GNU sed -i en Linux/CI; en macOS BSD requiere sed -i ''.
sed_inplace() {
  if [[ "$(uname -s)" == "Darwin" ]]; then
    sed -i '' "$@"
  else
    sed -i "$@"
  fi
}

PLUGIN_SLUG="xabia-agent-lite"
SOURCE_DIR="$ROOT/xabia-agent-plugins/packages/xabia-agent-core"
OUT_DIR="$ROOT/xabia-agent-plugins/dist"
LITE_VERSION="1.0.0-lite"
ZIP_PATH="$OUT_DIR/${PLUGIN_SLUG}-${LITE_VERSION}.zip"

if [[ ! -d "$SOURCE_DIR" ]]; then
  echo "ERROR: no existe $SOURCE_DIR" >&2
  exit 1
fi

BUILD_DIR="$(mktemp -d)"
cleanup() { rm -rf "$BUILD_DIR"; }
trap cleanup EXIT

echo "== Paso 1: aislar entorno de build =="
rsync -a \
  --exclude='.DS_Store' \
  --exclude='vendor/' \
  --exclude='node_modules/' \
  --exclude='tests/' \
  --exclude='.git/' \
  "$SOURCE_DIR/" "$BUILD_DIR/$PLUGIN_SLUG/"

echo "== Paso 2: metadatos WordPress.org =="
cp "$SOURCE_DIR/readme.txt" "$BUILD_DIR/$PLUGIN_SLUG/"

MAIN_FILE="$BUILD_DIR/$PLUGIN_SLUG/xabia-intelligence.php"
if [[ ! -f "$MAIN_FILE" ]]; then
  echo "ERROR: falta xabia-intelligence.php en el paquete LITE." >&2
  exit 1
fi

echo "== Paso 3: guillotina de seguridad (purga PRO) =="
TARGET="$BUILD_DIR/$PLUGIN_SLUG"

rm -rf \
  "$TARGET/addons" \
  "$TARGET/integrations/central" \
  "$TARGET/integrations/reservas" \
  "$TARGET/includes/mu-plugins" \
  "$TARGET/tests" \
  "$TARGET/docs" \
  "$TARGET/vendor"

rm -f \
  "$TARGET/admin/class-xabia-admin.php" \
  "$TARGET/admin/css/xabia-admin.css" \
  "$TARGET/admin/js/xabia-smart-qr-admin.js" \
  "$TARGET/core/class-xabia-digixop-client.php" \
  "$TARGET/core/class-xabia-hub-knowledge.php" \
  "$TARGET/core/class-xabia-federation-nexus.php" \
  "$TARGET/core/class-xabia-updater.php" \
  "$TARGET/core/class-xabia-addon-updater.php" \
  "$TARGET/integrations/class-xabia-sql-connector.php" \
  "$TARGET/composer.json" \
  "$TARGET/composer.lock" \
  "$TARGET/phpunit.xml.dist" \
  "$TARGET/README.md"

echo "== Paso 4: parches de cabecera y modo LITE forzado =="
sed_inplace "s/^\([[:space:]]*\* Plugin Name:\).*/\1 Xabia Agent Lite/" "$MAIN_FILE"
sed_inplace "s/^\([[:space:]]*\* Version:\).*/\1 ${LITE_VERSION}/" "$MAIN_FILE"
sed_inplace "s/^\([[:space:]]*\* Description:\).*/\1 Conecta tu web con Google Gemini (BYOK). Sube tu catálogo CSV y responde a tus clientes al instante./" "$MAIN_FILE"

if ! grep -q "XABIA_LITE_BUILD" "$MAIN_FILE"; then
  python3 - "$MAIN_FILE" <<'PY'
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
text = path.read_text(encoding="utf-8")
needle = "if (!defined('ABSPATH')) exit;"
block = (
    "if (!defined('ABSPATH')) exit;\n\n"
    "if (!defined('XABIA_LITE_BUILD')) {\n"
    "    define('XABIA_LITE_BUILD', true);\n"
    "}"
)
if needle not in text:
    raise SystemExit("No se encontró el guard ABSPATH en xabia-intelligence.php")
path.write_text(text.replace(needle, block, 1), encoding="utf-8")
PY
fi

python3 - "$MAIN_FILE" <<'PY'
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
drop_markers = (
    "class-xabia-digixop-client.php",
    "class-xabia-hub-knowledge.php",
    "class-xabia-federation-nexus.php",
    "class-xabia-api.php",
    "class-xabia-wallet-api.php",
    "class-xabia-updater.php",
    "class-xabia-addon-updater.php",
    "admin/class-xabia-admin.php",
    "Xabia_Federation_Nexus::init",
    "Xabia_Updater::init",
    "Xabia_Addon_Updater::init",
    "Xabia_Central_Setup::install",
)
lines = path.read_text(encoding="utf-8").splitlines(keepends=True)
filtered = [line for line in lines if not any(marker in line for marker in drop_markers)]
path.write_text("".join(filtered), encoding="utf-8")
PY

echo "== Paso 5: escaneo de infiltración PRO =="
FORBIDDEN_FILES=(
  "core/class-xabia-digixop-client.php"
  "core/class-xabia-hub-knowledge.php"
  "core/class-xabia-federation-nexus.php"
  "core/class-xabia-updater.php"
  "core/class-xabia-addon-updater.php"
  "admin/class-xabia-admin.php"
  "integrations/central"
  "integrations/reservas"
  "integrations/class-xabia-sql-connector.php"
  "addons"
)

for rel in "${FORBIDDEN_FILES[@]}"; do
  if [[ -e "$TARGET/$rel" ]]; then
    echo "ERROR: artefacto PRO aún presente tras la guillotina: $rel" >&2
    exit 1
  fi
done

echo "== Paso 6: empaquetar ZIP =="
mkdir -p "$OUT_DIR"
rm -f "$ZIP_PATH"
( cd "$BUILD_DIR" && zip -r -q "$ZIP_PATH" "$PLUGIN_SLUG" -x "*.DS_Store" "*/.DS_Store" )

echo "Generado: $ZIP_PATH ($(du -h "$ZIP_PATH" | cut -f1))"
echo "Listo para revisión WordPress.org (readme.txt + código purificado)."
