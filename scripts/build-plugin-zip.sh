#!/usr/bin/env bash
# Genera ZIPs instalables de plugins modulares.
# Uso: ./scripts/build-plugin-zip.sh
#      ONLY_SLUG=xabia-mec ./scripts/build-plugin-zip.sh
# Salida: xabia-agent-plugins/dist/<plugin-slug>-<version>.zip
# Xabia Woo / MEC: sincroniza el ZIP plano a build/ y raíz; el *-retail.zip con PAQUETE_VENTA va en build-retail-plugin-zips.sh.

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PLUGINS_DIR="$ROOT/xabia-agent-plugins/packages"
if [[ ! -d "$PLUGINS_DIR/xabia-agent-core" ]]; then
  PLUGINS_DIR="$ROOT/plugins"
fi

OUT_DIR="$ROOT/xabia-agent-plugins/dist"
mkdir -p "$OUT_DIR"

sync_addon_zip_delivery() {
  local slug="$1"
  local zip_path="$2"
  local version="$3"
  [[ -f "$zip_path" ]] || return 0
  mkdir -p "$ROOT/build" "$ROOT/xabia-agent-plugins/dist/retail"
  cp -f "$zip_path" "$ROOT/build/${slug}-${version}.zip"
  cp -f "$zip_path" "$ROOT/${slug}.zip"
  cp -f "$zip_path" "$ROOT/build/${slug}.zip"
  if [[ -d "$ROOT/plugins 2" ]]; then
    cp -f "$zip_path" "$ROOT/plugins 2/${slug}-${version}.zip"
    cp -f "$zip_path" "$ROOT/plugins 2/${slug}.zip"
  fi
  echo "Sincronizado ${slug}-${version}.zip → build/, ${slug}.zip (raíz y build/), plugins 2/ (si existe)"
}

build_one() {
  local slug="$1"
  local plugin_dir="$PLUGINS_DIR/$slug"
  local main_file="$plugin_dir/$slug.php"
  local version="1.0.0"
  if [[ ! -d "$plugin_dir" ]]; then
    echo "Saltando $slug (no existe directorio)"
    return
  fi
  if [[ "$slug" == "xabia-agent-core" ]]; then
    main_file="$plugin_dir/xabia-intelligence.php"
  fi
  if [[ -f "$main_file" ]]; then
    version=$(sed -n "s/^[[:space:]]*\\* Version:[[:space:]]*//p" "$main_file" | head -n 1 | tr -d '\r')
    [[ -z "$version" ]] && version="1.0.0"
  fi
  local zip_path="$OUT_DIR/${slug}-${version}.zip"
  rm -f "$zip_path"
  if [[ "$slug" == "xabia-agent-core" && -d "$ROOT/xabia-agent-plugins/api" ]]; then
    local ztmp
    ztmp="$(mktemp -d)"
    rsync -a "$plugin_dir/" "$ztmp/$slug/"
    mkdir -p "$ztmp/$slug/api"
    rsync -a "$ROOT/xabia-agent-plugins/api/" "$ztmp/$slug/api/"
    ( cd "$ztmp" && zip -r -q "$zip_path" "$slug" -x "*.DS_Store" "*/.DS_Store" )
    rm -rf "$ztmp"
  else
    ( cd "$PLUGINS_DIR" && zip -r -q "$zip_path" "$slug" -x "*.DS_Store" "*/.DS_Store" )
  fi
  echo "Generado: $zip_path ($(du -h "$zip_path" | cut -f1))"
  if [[ "$slug" == "xabia-agent-core" ]]; then
    mkdir -p "$ROOT/build"
    cp -f "$zip_path" "$ROOT/xabia-agent-core.zip"
    cp -f "$zip_path" "$ROOT/build/xabia-agent-core.zip"
    echo "Sincronizado xabia-agent-core-${version}.zip → xabia-agent-core.zip (raíz y build/)"
  fi
  if [[ "$slug" == "xabia-woo" || "$slug" == "xabia-mec" ]]; then
    sync_addon_zip_delivery "$slug" "$zip_path" "$version"
  fi
}

if [[ -n "${ONLY_SLUG:-}" ]]; then
  build_one "$ONLY_SLUG"
  exit 0
fi

for slug in xabia-agent-core xabia-woo xabia-mec xabia-avirato xabia-amelia xabia-federation; do
  build_one "$slug"
done
