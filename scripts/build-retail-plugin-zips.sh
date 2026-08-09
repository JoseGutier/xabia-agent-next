#!/usr/bin/env bash
# Paquetes para venta: Xabia Agent Core, Xabia Avirato, Xabia Woo y Xabia MEC.
# - Estructura válida para WordPress (una carpeta raíz por ZIP).
# - Core incluye vendor/, MEMORIA_TÉCNICA, manual de usuario y docs clave del repo.
#
# Uso (desde la raíz del repositorio):
#   chmod +x scripts/build-retail-plugin-zips.sh
#   ./scripts/build-retail-plugin-zips.sh
#
# Salida: xabia-agent-plugins/dist/retail/xabia-agent-core-<versión>-retail.zip
#         xabia-agent-plugins/dist/retail/xabia-avirato-<versión>-retail.zip
#         xabia-agent-plugins/dist/retail/xabia-woo-<versión>-retail.zip
#         xabia-agent-plugins/dist/retail/xabia-mec-<versión>-retail.zip
# Cada *-retail.zip se replica en build/retail/, build/ y plugins 2/ (si existe).
# (sin ZIP combinado: se descargan por separado)

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PLUGINS_DIR="$ROOT/xabia-agent-plugins/packages"
DOC_ROOT="$ROOT/xabia-agent-plugins/documentation"
if [[ ! -d "$PLUGINS_DIR/xabia-agent-core" ]]; then
  PLUGINS_DIR="$ROOT/plugins"
fi
if [[ ! -d "$PLUGINS_DIR/xabia-agent-core" ]]; then
  echo "ERROR: no se encuentra xabia-agent-core en xabia-agent-plugins/packages/ ni en plugins/ (legacy)." >&2
  exit 1
fi
if [[ ! -d "$DOC_ROOT" ]]; then
  DOC_ROOT="$ROOT/docs"
fi

# Ruta absoluta del plugin (WordPress exige un único directorio raíz en el ZIP).
resolve_plugin_root() {
  local dir="$1"
  local expect_base="$2"
  if [[ ! -d "$dir" ]]; then
    echo "ERROR: no existe directorio: $dir" >&2
    exit 1
  fi
  local abs
  abs="$(cd "$dir" && pwd)"
  if [[ "$(basename "$abs")" != "$expect_base" ]]; then
    echo "ERROR: se esperaba carpeta «${expect_base}», obtenido: $abs" >&2
    exit 1
  fi
  echo "$abs"
}

# Comprueba que todas las entradas del ZIP cuelguen de prefix/ (instalable en WordPress).
assert_zip_single_root() {
  local zipf="$1"
  local prefix="$2"
  if [[ ! -f "$zipf" ]]; then
    echo "ERROR: no existe $zipf" >&2
    exit 1
  fi
  if command -v python3 >/dev/null 2>&1; then
    python3 - "$zipf" "$prefix" <<'PY' || exit 1
import sys, zipfile
z = zipfile.ZipFile(sys.argv[1])
root = sys.argv[2].strip().strip("/")
for n in z.namelist():
    n = (n or "").strip()
    if not n:
        continue
    if n.split("/")[0] != root:
        print(f"ERROR: en {sys.argv[1]!r} la entrada {n!r} no cuelga de «{root}/» (comprime solo la carpeta del plugin, sin prefijo «packages/…» en rutas internas).", file=sys.stderr)
        sys.exit(1)
PY
  else
    echo "AVISO: sin python3, omito comprobación de estructura del ZIP." >&2
  fi
}

# Misma ruta de entrega que los ZIPs planos en build-plugin-zip.sh (build/, build/retail/, plugins 2/).
mirror_retail_zip() {
  local zipf="$1"
  [[ -f "$zipf" ]] || return 0
  local bn
  bn="$(basename "$zipf")"
  mkdir -p "$ROOT/build/retail" "$ROOT/build"
  cp -f "$zipf" "$ROOT/build/retail/$bn"
  cp -f "$zipf" "$ROOT/build/$bn"
  if [[ -d "$ROOT/plugins 2" ]]; then
    cp -f "$zipf" "$ROOT/plugins 2/$bn"
  fi
  echo "Mirror retail → build/retail/, build/, plugins 2/: $bn"
}

OUT_DIR="$ROOT/xabia-agent-plugins/dist/retail"
mkdir -p "$OUT_DIR"

TMP=$(mktemp -d)
cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT

get_version_from_plugin_header() {
  local main_file="$1"
  local version="1.0.0"
  if [[ -f "$main_file" ]]; then
    version=$(sed -n "s/^[[:space:]]*\\* Version:[[:space:]]*//p" "$main_file" | head -n 1 | tr -d '\r')
    [[ -z "$version" ]] && version="1.0.0"
  fi
  echo "$version"
}

# --- Xabia Agent Core (incl. vendor para instalación sin Composer en el cliente) ---
CORE_SRC="$(resolve_plugin_root "$PLUGINS_DIR/xabia-agent-core" "xabia-agent-core")"
CORE_MAIN="$CORE_SRC/xabia-intelligence.php"
CORE_VER=$(get_version_from_plugin_header "$CORE_MAIN")
STAGE_PARENT="$TMP/corepack"
STAGE_CORE="$STAGE_PARENT/xabia-agent-core"
rm -rf "$STAGE_PARENT"
mkdir -p "$STAGE_CORE"

rsync -a \
  --exclude='.git' \
  --exclude='.DS_Store' \
  --exclude='*.zip' \
  --exclude='node_modules' \
  "$CORE_SRC/" "$STAGE_CORE/"

mkdir -p "$STAGE_CORE/api"
rsync -a "$ROOT/xabia-agent-plugins/api/" "$STAGE_CORE/api/"

mkdir -p "$STAGE_CORE/docs"
[[ -f "$ROOT/MEMORIA_TECNICA.md" ]] && cp "$ROOT/MEMORIA_TECNICA.md" "$STAGE_CORE/"
[[ -f "$DOC_ROOT/MANUAL_USUARIO.md" ]] && cp "$DOC_ROOT/MANUAL_USUARIO.md" "$STAGE_CORE/docs/MANUAL_USUARIO.md"
[[ -f "$DOC_ROOT/README.md" ]] && cp "$DOC_ROOT/README.md" "$STAGE_CORE/docs/INDICE_DOCS_REPOSITORIO.md"
[[ -f "$DOC_ROOT/DESARROLLO.md" ]] && cp "$DOC_ROOT/DESARROLLO.md" "$STAGE_CORE/docs/DESARROLLO.md"
[[ -f "$ROOT/CONTRIBUTING.md" ]] && cp "$ROOT/CONTRIBUTING.md" "$STAGE_CORE/"

cat > "$STAGE_CORE/PAQUETE_VENTA_LEEME.txt" << 'EOF'
Paquete comercial Xabia Agent Core
=================================
Instalación: WordPress → Plugins → Añadir nuevo → Subir plugin → elegir este ZIP.

Documentación en este paquete:
- MEMORIA_TECNICA.md (arquitectura y referencia profunda)
- docs/MANUAL_USUARIO.md (manual de administrador / usuario)
- docs/DESARROLLO.md (ingeniería)
- docs/INDICE_DOCS_REPOSITORIO.md (índice de la carpeta docs del repositorio)
- CONTRIBUTING.md (colaboración en GitHub)

La carpeta vendor/ se incluye para uso inmediato del proxy Vertex (Google Auth) según tu despliegue.
EOF

ZIP_CORE="$OUT_DIR/xabia-agent-core-${CORE_VER}-retail.zip"
rm -f "$ZIP_CORE"
( cd "$STAGE_PARENT" && zip -r -q "$ZIP_CORE" xabia-agent-core -x "*.DS_Store" "*/.DS_Store" )
assert_zip_single_root "$ZIP_CORE" "xabia-agent-core"
mirror_retail_zip "$ZIP_CORE"
echo "Generado Core:  $ZIP_CORE ($(du -h "$ZIP_CORE" | cut -f1))"

# --- Xabia Avirato ---
AV_SRC="$(resolve_plugin_root "$PLUGINS_DIR/xabia-avirato" "xabia-avirato")"
AV_MAIN="$AV_SRC/xabia-avirato.php"
AV_VER=$(get_version_from_plugin_header "$AV_MAIN")
STAGE_AV_PARENT="$TMP/avpack"
STAGE_AV="$STAGE_AV_PARENT/xabia-avirato"
rm -rf "$STAGE_AV_PARENT"
mkdir -p "$STAGE_AV"

rsync -a \
  --exclude='.git' \
  --exclude='.DS_Store' \
  --exclude='*.zip' \
  "$AV_SRC/" "$STAGE_AV/"

cat > "$STAGE_AV/PAQUETE_VENTA_LEEME.txt" << 'EOF'
Paquete comercial Xabia Avirato (addon)
========================================
Requisito: plugin «Xabia Agent Core» instalado y activo (misma versión recomendada que el contrato comercial).

Instalación: WordPress → Plugins → Añadir nuevo → Subir plugin → este ZIP.

Documentación del addon: README.md y docs/manual-usuario.md dentro de esta carpeta.
Documentación global del producto: incluida en el ZIP retail del Core (MANUAL_USUARIO.md, MEMORIA_TECNICA.md).
EOF

ZIP_AV="$OUT_DIR/xabia-avirato-${AV_VER}-retail.zip"
rm -f "$ZIP_AV"
( cd "$STAGE_AV_PARENT" && zip -r -q "$ZIP_AV" xabia-avirato -x "*.DS_Store" "*/.DS_Store" )
assert_zip_single_root "$ZIP_AV" "xabia-avirato"
mirror_retail_zip "$ZIP_AV"
echo "Generado Avirato: $ZIP_AV ($(du -h "$ZIP_AV" | cut -f1))"

# --- Xabia Woo (addon) ---
WOO_SRC="$(resolve_plugin_root "$PLUGINS_DIR/xabia-woo" "xabia-woo")"
WOO_MAIN="$WOO_SRC/xabia-woo.php"
WOO_VER=$(get_version_from_plugin_header "$WOO_MAIN")
STAGE_WOO_PARENT="$TMP/woopack"
STAGE_WOO="$STAGE_WOO_PARENT/xabia-woo"
rm -rf "$STAGE_WOO_PARENT"
mkdir -p "$STAGE_WOO"

rsync -a \
  --exclude='.git' \
  --exclude='.DS_Store' \
  --exclude='*.zip' \
  "$WOO_SRC/" "$STAGE_WOO/"

cat > "$STAGE_WOO/PAQUETE_VENTA_LEEME.txt" << 'EOF'
Paquete comercial Xabia Woo (addon)
====================================
Requisito: plugin «Xabia Agent Core» instalado y activo (misma versión recomendada que el contrato comercial).
Requisito de negocio: WooCommerce activo en el sitio.

Instalación: WordPress → Plugins → Añadir nuevo → Subir plugin → este ZIP.

Documentación del addon: README.md y docs/manual-usuario.md dentro de esta carpeta (si existen).
Documentación global del producto: incluida en el ZIP retail del Core (MANUAL_USUARIO.md, MEMORIA_TECNICA.md).
EOF

ZIP_WOO="$OUT_DIR/xabia-woo-${WOO_VER}-retail.zip"
rm -f "$ZIP_WOO"
( cd "$STAGE_WOO_PARENT" && zip -r -q "$ZIP_WOO" xabia-woo -x "*.DS_Store" "*/.DS_Store" )
assert_zip_single_root "$ZIP_WOO" "xabia-woo"
mirror_retail_zip "$ZIP_WOO"
echo "Generado Woo:     $ZIP_WOO ($(du -h "$ZIP_WOO" | cut -f1))"

# --- Xabia MEC (addon) ---
MEC_SRC="$(resolve_plugin_root "$PLUGINS_DIR/xabia-mec" "xabia-mec")"
MEC_MAIN="$MEC_SRC/xabia-mec.php"
MEC_VER=$(get_version_from_plugin_header "$MEC_MAIN")
STAGE_MEC_PARENT="$TMP/mecpack"
STAGE_MEC="$STAGE_MEC_PARENT/xabia-mec"
rm -rf "$STAGE_MEC_PARENT"
mkdir -p "$STAGE_MEC"

rsync -a \
  --exclude='.git' \
  --exclude='.DS_Store' \
  --exclude='*.zip' \
  "$MEC_SRC/" "$STAGE_MEC/"

cat > "$STAGE_MEC/PAQUETE_VENTA_LEEME.txt" << 'EOF'
Paquete comercial Xabia MEC (addon)
====================================
Requisito: plugin «Xabia Agent Core» instalado y activo (misma versión recomendada que el contrato comercial).
Requisito de negocio: plugin Modern Events Calendar en el sitio.

Instalación: WordPress → Plugins → Añadir nuevo → Subir plugin → este ZIP.

Documentación del addon: README.md y docs/manual-usuario.md dentro de esta carpeta (si existen).
Documentación global del producto: incluida en el ZIP retail del Core (MANUAL_USUARIO.md, MEMORIA_TECNICA.md).
EOF

ZIP_MEC="$OUT_DIR/xabia-mec-${MEC_VER}-retail.zip"
rm -f "$ZIP_MEC"
( cd "$STAGE_MEC_PARENT" && zip -r -q "$ZIP_MEC" xabia-mec -x "*.DS_Store" "*/.DS_Store" )
assert_zip_single_root "$ZIP_MEC" "xabia-mec"
mirror_retail_zip "$ZIP_MEC"
echo "Generado MEC:     $ZIP_MEC ($(du -h "$ZIP_MEC" | cut -f1))"
echo "Listo. Carpeta: $OUT_DIR"
