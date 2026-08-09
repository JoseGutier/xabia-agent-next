#!/usr/bin/env bash
# Paquete de producción: plugin + vendor (Composer), sin xabia-agent-plugins/central-api/ (el Hub va aparte en el servidor).
# Uso: desde la raíz del repo: ./scripts/build-xabia-nexus-zip.sh
# Salida: build/xabia-nexus.zip

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if ! command -v composer >/dev/null 2>&1; then
  echo "ERROR: composer no está en PATH. Instala Composer y ejecuta de nuevo (se requiere vendor/ con Google Auth y JWT)." >&2
  exit 1
fi
if [[ ! -f "$ROOT/composer.json" ]]; then
  echo "ERROR: falta composer.json en la raíz del plugin." >&2
  exit 1
fi
composer install --no-dev --no-interaction --optimize-autoloader --working-dir="$ROOT"
if [[ ! -f "$ROOT/vendor/autoload.php" ]]; then
  echo "ERROR: tras composer install no existe vendor/autoload.php." >&2
  exit 1
fi

OUT_DIR="$ROOT/build"
mkdir -p "$OUT_DIR"
ZIP_PATH="$OUT_DIR/xabia-nexus.zip"
TMP=$(mktemp -d)
cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT

DEST="$TMP/xabia-agent-next"
mkdir -p "$DEST"

rsync -a \
  --exclude='.git' \
  --exclude='build' \
  --exclude='.cursor' \
  --exclude='.DS_Store' \
  --exclude='node_modules' \
  --exclude='central-api' \
  "$ROOT/" "$DEST/"

if [[ ! -f "$DEST/vendor/autoload.php" ]]; then
  echo "ERROR: rsync no copió vendor/autoload.php (¿faltó composer install?)." >&2
  exit 1
fi

rm -f "$ZIP_PATH"
( cd "$TMP" && zip -r -q "$ZIP_PATH" xabia-agent-next )

echo "Generado: $ZIP_PATH ($(du -h "$ZIP_PATH" | cut -f1)) (incluye vendor/ con google/auth y firebase/php-jwt)"
