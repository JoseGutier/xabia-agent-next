#!/usr/bin/env bash
# Xabia Release Engine — build ZIPs de addons + sync Hub env.example
#
# Uso:
#   ./xabia-build-addons.sh              # todos los addons (versión desde cabecera WP)
#   ./xabia-build-addons.sh woo 1.0.3    # bump + build solo Woo
#   ./xabia-build-addons.sh --help
#
# Salida: xabia-agent-plugins/dist/xabia-{woo,mec,avirato}-<versión>.zip

set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

PACKAGES="$ROOT/xabia-agent-plugins/packages"
DIST_DIR="$ROOT/xabia-agent-plugins/dist"
HUB_ENV_EXAMPLE="$ROOT/xabia-agent-plugins/central-api/env.example"
PACKAGE_BASE_URL="${XABIA_PACKAGE_BASE_URL:-https://xabia.ai/downloads}"

RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[0;33m'
BOLD='\033[1m'
NC='\033[0m'

usage() {
  cat <<'EOF'
Xabia Release Engine — build addons

Uso:
  ./xabia-build-addons.sh
  ./xabia-build-addons.sh woo 1.0.3

Genera ZIPs en xabia-agent-plugins/dist/ y actualiza central-api/env.example.
EOF
}

log_ok()   { printf "${GREEN}✓${NC} %s\n" "$*"; }
log_info() { printf "${CYAN}→${NC} %s\n" "$*"; }
log_err()  { printf "${RED}✗${NC} %s\n" "$*" >&2; }

validate_version() {
  [[ "$1" =~ ^[0-9]+(\.[0-9]+)*$ ]] || { log_err "Versión inválida: $1"; exit 1; }
}

addon_slug() {
  case "$1" in
    woo) echo xabia-woo ;;
    mec) echo xabia-mec ;;
    avirato) echo xabia-avirato ;;
    *) return 1 ;;
  esac
}

addon_main_file() {
  case "$1" in
    woo) echo xabia-woo.php ;;
    mec) echo xabia-mec.php ;;
    avirato) echo xabia-avirato.php ;;
    *) return 1 ;;
  esac
}

addon_env_prefix() {
  case "$1" in
    woo) echo XABIA_WOO ;;
    mec) echo XABIA_MEC ;;
    avirato) echo XABIA_AVIRATO ;;
    *) return 1 ;;
  esac
}

read_plugin_version() {
  local main_file="$1"
  sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$main_file" | head -n 1 | tr -d '\r\n' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'
}

bump_wp_version() {
  local file="$1" new="$2"
  perl -i -pe 'BEGIN {$v=shift} s/^(\s*\*\s*Version:\s*).*/${1}${v}/' "$new" "$file"
}

bump_env_var() {
  local file="$1" key="$2" value="$3"
  [[ -f "$file" ]] || return 0
  if grep -qE "^${key}=" "$file" 2>/dev/null; then
    perl -i -pe 'BEGIN {$k=shift; $v=shift} s/^\Q$k\E=.*/$k=$v/' "$key" "$value" "$file"
  else
    printf '\n%s=%s\n' "$key" "$value" >> "$file"
  fi
}

build_addon_zip() {
  local key="$1" version="$2"
  local slug main ztmp zip_path
  slug="$(addon_slug "$key")"
  ztmp="$(mktemp -d)"
  zip_path="$DIST_DIR/${slug}-${version}.zip"

  rsync -a \
    --exclude '.git/' \
    --exclude 'node_modules/' \
    --exclude '.DS_Store' \
    --exclude '*.zip' \
    "$PACKAGES/$slug/" "$ztmp/$slug/"

  ( cd "$ztmp" && zip -r -q "$zip_path" "$slug" -x "*.DS_Store" "*/.DS_Store" )
  rm -rf "$ztmp"

  mkdir -p "$ROOT/build"
  cp -f "$zip_path" "$ROOT/build/${slug}-${version}.zip"

  printf '%s' "$zip_path"
}

sync_hub_env() {
  local key="$1" version="$2" slug prefix
  slug="$(addon_slug "$key")"
  prefix="$(addon_env_prefix "$key")"
  bump_env_var "$HUB_ENV_EXAMPLE" "${prefix}_LATEST_VERSION" "$version"
  bump_env_var "$HUB_ENV_EXAMPLE" "${prefix}_UPDATE_PACKAGE" "${PACKAGE_BASE_URL}/${slug}-${version}.zip"
}

build_one() {
  local key="$1" bump_version="${2:-}" slug main_file version zip_path
  slug="$(addon_slug "$key")" || { log_err "Addon desconocido: $key"; exit 1; }
  main_file="$PACKAGES/$slug/$(addon_main_file "$key")"

  if [[ ! -f "$main_file" ]]; then
    log_err "No existe $main_file"
    exit 1
  fi

  if [[ -n "$bump_version" ]]; then
    validate_version "$bump_version"
    bump_wp_version "$main_file" "$bump_version"
    log_ok "${slug}: cabecera → ${bump_version}"
  fi

  version="$(read_plugin_version "$main_file")"
  [[ -n "$version" ]] || { log_err "Sin versión en $main_file"; exit 1; }

  mkdir -p "$DIST_DIR"
  zip_path="$(build_addon_zip "$key" "$version")"
  sync_hub_env "$key" "$version"
  log_ok "${slug}-${version}.zip ($(du -h "$zip_path" | cut -f1))"
}

main() {
  local target="" bump=""

  while [[ $# -gt 0 ]]; do
    case "$1" in
      -h|--help) usage; exit 0 ;;
      woo|mec|avirato)
        target="$1"
        shift
        if [[ $# -gt 0 && "$1" != --* ]]; then
          bump="$1"
          shift
        fi
        ;;
      *)
        log_err "Argumento desconocido: $1"
        usage
        exit 1
        ;;
    esac
  done

  printf '\n%s\n' "${BOLD}Xabia Release Engine — Addons${NC}\n"

  if [[ -n "$target" ]]; then
    build_one "$target" "$bump"
  else
    build_one woo ""
    build_one mec ""
    build_one avirato ""
  fi

  log_ok "Hub env.example sincronizado"
  printf '\n%s\n' "${CYAN}Próximo paso:${NC} ./xabia-deploy-addons.sh"
  printf '\n'
}

main "$@"
