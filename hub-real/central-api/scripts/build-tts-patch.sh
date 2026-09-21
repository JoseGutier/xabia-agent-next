#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"
STAGE="$DIST/tts-patch"
ZIP="$DIST/xabia-hub-tts-patch.zip"

rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE/src"

FILES=(
  src/Router.php
  src/SignedHubPostAuth.php
  src/TtsHandler.php
  src/TtsGoogleCloud.php
  src/TtsHealthHandler.php
)

for f in "${FILES[@]}"; do
  cp "$ROOT/$f" "$STAGE/$f"
done

cp "$ROOT/DEPLOY_TTS.md" "$STAGE/DEPLOY_TTS.md"

mkdir -p "$DIST"
(cd "$STAGE" && zip -rq "$ZIP" .)

echo "Generado: $ZIP"
echo "Sube el contenido de src/ al central-api de producción en xabia.ai"
