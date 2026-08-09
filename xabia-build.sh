#!/usr/bin/env bash
# Xabia Release Engine — Fase 1: bump de versión Core + ZIP de distribución.
#
# Uso:
#   ./xabia-build.sh 1.0.60
#   ./xabia-build.sh --help
#
# Artefacto: xabia-agent-plugins/dist/xabia-agent-core-[VERSION].zip

set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

CORE_DIR="$ROOT/xabia-agent-plugins/packages/xabia-agent-core"
CORE_MAIN="$CORE_DIR/xabia-intelligence.php"
CORE_UPDATER="$CORE_DIR/core/class-xabia-updater.php"
HUB_DIR="$ROOT/xabia-agent-plugins/central-api"
HUB_ENV="$HUB_DIR/.env"
HUB_ENV_EXAMPLE="$HUB_DIR/env.example"
API_DIR="$ROOT/xabia-agent-plugins/api"
DIST_DIR="$ROOT/xabia-agent-plugins/dist"
SLUG="xabia-agent-core"
PACKAGE_BASE_URL="${XABIA_PACKAGE_BASE_URL:-https://xabia.ai/downloads}"

RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[0;33m'
BOLD='\033[1m'
NC='\033[0m'

usage() {
  cat <<'EOF'
Xabia Release Engine — Fase 1 (build local Core)

Uso:
  ./xabia-build.sh <versión>

Ejemplo:
  ./xabia-build.sh 1.0.60

Acciones:
  1. Lee la versión actual desde xabia-intelligence.php
  2. Actualiza cabecera WP, Hub (.env / env.example) y referencias al ZIP
  3. Limpia ZIPs antiguos de Core en xabia-agent-plugins/dist/
  4. Genera xabia-agent-plugins/dist/xabia-agent-core-<versión>.zip

Variables opcionales:
  XABIA_PACKAGE_BASE_URL   Base pública del ZIP (default: https://xabia.ai/downloads)
EOF
}

log_ok()   { printf "${GREEN}✓${NC} %s\n" "$*"; }
log_info() { printf "${CYAN}→${NC} %s\n" "$*"; }
log_warn() { printf "${YELLOW}!${NC} %s\n" "$*"; }
log_err()  { printf "${RED}✗${NC} %s\n" "$*" >&2; }

validate_version() {
  local v="$1"
  if [[ ! "$v" =~ ^[0-9]+(\.[0-9]+)*$ ]]; then
    log_err "Versión inválida: «$v». Use formato semver (ej. 1.0.60)."
    exit 1
  fi
}

read_core_version() {
  if [[ ! -f "$CORE_MAIN" ]]; then
    log_err "No se encontró $CORE_MAIN"
    exit 1
  fi
  local v
  v="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$CORE_MAIN" | head -n 1 | tr -d '\r\n' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
  if [[ -z "$v" ]]; then
    log_err "No se pudo leer * Version: de $CORE_MAIN"
    exit 1
  fi
  printf '%s' "$v"
}

bump_wp_plugin_header() {
  local file="$1"
  local new="$2"
  perl -i -pe 'BEGIN {$v=shift} s/^(\s*\*\s*Version:\s*).*/${1}${v}/' "$new" "$file"
}

bump_env_var() {
  local file="$1"
  local key="$2"
  local value="$3"
  [[ -f "$file" ]] || return 0
  if grep -qE "^${key}=" "$file" 2>/dev/null; then
    perl -i -pe 'BEGIN {$k=shift; $v=shift} s/^\Q$k\E=.*/$k=$v/' "$key" "$value" "$file"
  elif grep -qE "^#[[:space:]]*${key}=" "$file" 2>/dev/null; then
    perl -i -pe 'BEGIN {$k=shift; $v=shift} s/^#[[:space:]]*\Q$k\E=.*/$k=$v/' "$key" "$value" "$file"
  else
    printf '\n%s=%s\n' "$key" "$value" >> "$file"
  fi
}

bump_zip_url_in_file() {
  local file="$1"
  local new="$2"
  [[ -f "$file" ]] || return 0
  perl -i -pe '
    BEGIN { $v = shift; $base = shift; }
    s|^(\s*XABIA_CORE_UPDATE_PACKAGE=).*$|${1}${base}/xabia-agent-core-${v}.zip|;
    s|xabia-agent-core-[0-9]+(?:\.[0-9]+)*\.zip|xabia-agent-core-${v}.zip|g;
  ' "$new" "$PACKAGE_BASE_URL" "$file"
}

bump_manual_core_version_line() {
  local new="$1"
  local file="$ROOT/xabia-agent-plugins/documentation/manual-usuario-xabia-core.md"
  [[ -f "$file" ]] || return 0
  perl -i -pe 'BEGIN {$v=shift} s/(Xabia Agent Core \*\*v)[0-9]+(?:\.[0-9]+)*(\*\*)/${1}${v}${2}/' "$new" "$file"
  perl -i -pe 'BEGIN {$v=shift} s/(xabia-agent-core-)[0-9]+(?:\.[0-9]+)*(\.zip)/${1}${v}${2}/g' "$new" "$file"
}

clean_dist_core_zips() {
  mkdir -p "$DIST_DIR"
  local removed=0
  local f
  shopt -s nullglob
  for f in "$DIST_DIR/${SLUG}"-*.zip "$DIST_DIR/${SLUG}.zip"; do
    rm -f "$f"
    removed=$((removed + 1))
  done
  shopt -u nullglob
  log_info "dist/: eliminados $removed ZIP(s) antiguos de Core"
}

build_core_zip() {
  local version="$1"
  local zip_path="$DIST_DIR/${SLUG}-${version}.zip"
  local ztmp
  ztmp="$(mktemp -d)"

  rsync -a \
    --exclude '.git/' \
    --exclude 'node_modules/' \
    --exclude '.DS_Store' \
    --exclude '*.zip' \
    --exclude '.env' \
    --exclude 'vendor/' \
    "$CORE_DIR/" "$ztmp/$SLUG/"

  if [[ -d "$API_DIR" ]]; then
    mkdir -p "$ztmp/$SLUG/api"
    rsync -a \
      --exclude '.git/' \
      --exclude 'node_modules/' \
      --exclude '.DS_Store' \
      --exclude '*.zip' \
      "$API_DIR/" "$ztmp/$SLUG/api/"
  fi

  ( cd "$ztmp" && zip -r -q "$zip_path" "$SLUG" -x "*.DS_Store" "*/.DS_Store" )
  rm -rf "$ztmp"

  mkdir -p "$ROOT/build"
  cp -f "$zip_path" "$ROOT/${SLUG}.zip"
  cp -f "$zip_path" "$ROOT/build/${SLUG}.zip"

  printf '%s' "$zip_path"
}

main() {
  local new_version="${1:-}"
  if [[ -z "$new_version" || "$new_version" == "-h" || "$new_version" == "--help" ]]; then
    usage
    exit 0
  fi

  validate_version "$new_version"
  local old_version
  old_version="$(read_core_version)"

  if [[ "$old_version" == "$new_version" ]]; then
    log_warn "La versión solicitada ($new_version) coincide con la actual; se regenerará el ZIP."
  fi

  printf '\n%s\n' "${BOLD}Xabia Release Engine — Fase 1${NC}"
  printf 'Versión: %s → %s\n\n' "$old_version" "$new_version"

  # --- 1. Bump ---
  log_info "Actualizando cabecera WP en xabia-intelligence.php"
  bump_wp_plugin_header "$CORE_MAIN" "$new_version"
  log_ok "packages/xabia-agent-core/xabia-intelligence.php (* Version + XABIA_VERSION vía get_file_data)"

  if [[ -f "$CORE_UPDATER" ]]; then
    log_ok "core/class-xabia-updater.php (sin versión fija; usa Hub /updates — sin cambio de código)"
  fi

  local hub_files_updated=()
  for env_file in "$HUB_ENV_EXAMPLE" "$HUB_ENV"; do
    if [[ -f "$env_file" ]]; then
      bump_env_var "$env_file" "XABIA_CORE_LATEST_VERSION" "$new_version"
      bump_zip_url_in_file "$env_file" "$new_version"
      if grep -qE '^XABIA_CORE_UPDATE_DATE=|^#[[:space:]]*XABIA_CORE_UPDATE_DATE=' "$env_file" 2>/dev/null; then
        bump_env_var "$env_file" "XABIA_CORE_UPDATE_DATE" "$(date +%Y-%m-%d)"
      fi
      hub_files_updated+=("${env_file#$ROOT/}")
    fi
  done
  if [[ ${#hub_files_updated[@]} -gt 0 ]]; then
    log_ok "Hub: ${hub_files_updated[*]}"
  else
    log_warn "No se encontró env.example ni .env en central-api"
  fi

  bump_manual_core_version_line "$new_version"
  log_ok "documentation/manual-usuario-xabia-core.md (cabecera de versión)"

  # Addons empaquetados por separado: no bump automático salvo referencia explícita al Core ZIP.
  local addon_roots=(
    "$ROOT/xabia-agent-plugins/packages/xabia-woo"
    "$ROOT/xabia-agent-plugins/packages/xabia-mec"
    "$ROOT/xabia-agent-plugins/packages/xabia-avirato"
    "$CORE_DIR/addons"
  )
  local addon_found=0
  for dir in "${addon_roots[@]}"; do
    [[ -d "$dir" ]] || continue
    while IFS= read -r -d '' php; do
      if grep -q 'xabia-agent-core-[0-9]' "$php" 2>/dev/null; then
        bump_zip_url_in_file "$php" "$new_version"
        log_ok "Referencia Core ZIP en ${php#$ROOT/}"
        addon_found=1
      fi
    done < <(find "$dir" -maxdepth 2 -name '*.php' -print0 2>/dev/null)
  done
  if [[ "$addon_found" -eq 0 ]]; then
    log_ok "Addons: sin referencias embebidas a xabia-agent-core-*.zip"
  fi

  # --- 2. Build ---
  log_info "Limpiando dist/ y compilando ZIP…"
  clean_dist_core_zips
  local zip_path
  zip_path="$(build_core_zip "$new_version")"
  local zip_size
  zip_size="$(du -h "$zip_path" | cut -f1)"

  # --- 3. Reporte ---
  printf '\n%s\n' "${BOLD}━━━ Release listo ━━━${NC}"
  log_ok "Versión Core: ${GREEN}${new_version}${NC}"
  log_ok "ZIP: ${zip_path} (${zip_size})"
  log_ok "Copias: ${ROOT}/${SLUG}.zip y build/${SLUG}.zip"
  printf '\n%s\n' "${CYAN}Próximos pasos (manual):${NC}"
  echo "  1. Subir ZIP → xabia.ai/public_html/downloads/xabia-agent-core-${new_version}.zip"
  echo "  2. Desplegar PHP del Hub si cambió + .env con XABIA_CORE_LATEST_VERSION=${new_version}"
  echo "  3. Probar: curl -sS '${PACKAGE_BASE_URL}/xabia-agent-core-${new_version}.zip' -o /dev/null -w '%{http_code}\n'"
  printf '\n'
}

main "$@"
