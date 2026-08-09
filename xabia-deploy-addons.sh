#!/usr/bin/env bash
# Sube ZIPs de addons a xabia.ai/downloads y actualiza variables en el Hub .env
#
# Uso:
#   ./xabia-deploy-addons.sh
#   ./xabia-deploy-addons.sh --dry-run

set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

ENV_LOCAL="$ROOT/.env.local"
DIST_DIR="$ROOT/xabia-agent-plugins/dist"
PACKAGE_BASE_URL="${XABIA_PACKAGE_BASE_URL:-https://xabia.ai/downloads}"

DRY_RUN=0

RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[0;33m'
BOLD='\033[1m'
NC='\033[0m'

log_ok()   { printf "${GREEN}✓${NC} %s\n" "$*"; }
log_info() { printf "${CYAN}→${NC} %s\n" "$*"; }
log_warn() { printf "${YELLOW}!${NC} %s\n" "$*"; }
log_err()  { printf "${RED}✗${NC} %s\n" "$*" >&2; }
log_step() { printf "${BOLD}%s${NC}\n" "$*"; }

usage() {
  cat <<'EOF'
Uso: ./xabia-deploy-addons.sh [--dry-run]

Requisitos: ./xabia-build-addons.sh ejecutado antes; .env.local con credenciales Hostinger.
EOF
}

addon_env_prefix() {
  case "$1" in
    xabia-woo) echo XABIA_WOO ;;
    xabia-mec) echo XABIA_MEC ;;
    xabia-avirato) echo XABIA_AVIRATO ;;
    *) return 1 ;;
  esac
}

load_env_local() {
  [[ -f "$ENV_LOCAL" ]] || { log_err "Falta $ENV_LOCAL"; exit 1; }
  set -a
  # shellcheck disable=SC1090
  source "$ENV_LOCAL"
  set +a
}

normalize_remote_path() {
  local p="$1"
  p="${p%/}"
  printf '%s' "$p"
}

ssh_base_args() {
  SSH_BASE_ARGS=(-p "${XABIA_SSH_PORT:-65002}" -o BatchMode=yes -o ConnectTimeout=20)
}

run_ssh() {
  ssh_base_args
  if [[ "$DRY_RUN" -eq 1 ]]; then
    log_info "[dry-run] ssh ${XABIA_PROD_SERVER} $*"
    return 0
  fi
  ssh "${SSH_BASE_ARGS[@]}" "${XABIA_PROD_SERVER}" "$@"
}

run_scp() {
  if [[ "$DRY_RUN" -eq 1 ]]; then
    log_info "[dry-run] scp → ${XABIA_PROD_SERVER}:$2"
    return 0
  fi
  scp -P "${XABIA_SSH_PORT:-65002}" "$@"
}

read_version_from_zip_name() {
  local zip_name="$1"
  echo "$zip_name" | sed -E 's/^.+-([0-9]+(\.[0-9]+)*)\.zip$/\1/'
}

find_latest_addon_zip() {
  local slug="$1"
  ls -1t "$DIST_DIR/${slug}-"*.zip 2>/dev/null | head -n 1 || true
}

deploy_one_addon() {
  local slug="$1"
  local zip_local version zip_name prefix downloads_path remote_file

  zip_local="$(find_latest_addon_zip "$slug")"
  if [[ -z "$zip_local" || ! -f "$zip_local" ]]; then
    log_warn "Omitido ${slug}: no hay ZIP en dist/ (ejecuta ./xabia-build-addons.sh)"
    return 0
  fi

  zip_name="$(basename "$zip_local")"
  version="$(read_version_from_zip_name "$zip_name")"
  prefix="$(addon_env_prefix "$slug")"
  downloads_path="$(normalize_remote_path "$XABIA_PROD_DOWNLOADS_PATH")"
  remote_file="${downloads_path}/${zip_name}"

  log_info "Subiendo ${slug} v${version} → ${remote_file}"
  if [[ "$DRY_RUN" -eq 0 ]]; then
    run_ssh "mkdir -p $(printf '%q' "$downloads_path")"
  fi
  run_scp "$zip_local" "${XABIA_PROD_SERVER}:${remote_file}"

  if [[ "$DRY_RUN" -eq 1 ]]; then
    log_info "[dry-run] Hub .env ${prefix}_LATEST_VERSION=${version}"
    return 0
  fi

  local hub_path env_file package_url
  hub_path="$(normalize_remote_path "$XABIA_PROD_HUB_PATH")"
  env_file="${hub_path}/.env"
  package_url="${PACKAGE_BASE_URL}/${zip_name}"

  ssh_base_args
  ssh "${SSH_BASE_ARGS[@]}" "${XABIA_PROD_SERVER}" bash -s -- "$env_file" "$prefix" "$version" "$package_url" <<'REMOTE'
set -euo pipefail
ENV_FILE="$1"
PREFIX="$2"
VERSION="$3"
PACKAGE_URL="$4"
if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: no existe $ENV_FILE" >&2
  exit 1
fi
for pair in "LATEST_VERSION=${VERSION}" "UPDATE_PACKAGE=${PACKAGE_URL}"; do
  key="${pair%%=*}"
  val="${pair#*=}"
  var="${PREFIX}_${key}"
  if grep -q "^${var}=" "$ENV_FILE"; then
    sed -i "s|^${var}=.*|${var}=${val}|" "$ENV_FILE"
  else
    echo "${var}=${val}" >> "$ENV_FILE"
  fi
done
grep -E "^${PREFIX}_(LATEST_VERSION|UPDATE_PACKAGE)=" "$ENV_FILE"
REMOTE

  log_ok "${slug} v${version} desplegado"
}

verify_updates_endpoint() {
  local slug="$1"
  local url="https://xabia.ai/api/xabia/v1/updates?plugin=${slug}"
  if [[ "$DRY_RUN" -eq 1 ]]; then
    log_info "[dry-run] curl $url"
    return 0
  fi
  local body code
  body="$(curl -sS "$url" || true)"
  code="$(curl -sS -o /dev/null -w '%{http_code}' "$url" || true)"
  if [[ "$code" == "200" ]] && echo "$body" | grep -q '"version"'; then
    log_ok "/updates?plugin=${slug} → HTTP 200"
  else
    log_warn "/updates?plugin=${slug} → HTTP ${code} (revisa Hub .env y UpdateCatalog.php desplegado)"
  fi
}

main() {
  for arg in "$@"; do
    case "$arg" in
      -h|--help) usage; exit 0 ;;
      --dry-run) DRY_RUN=1 ;;
      *) log_err "Argumento desconocido: $arg"; usage; exit 1 ;;
    esac
  done

  load_env_local
  [[ -n "${XABIA_PROD_SERVER:-}" ]] || { log_err "XABIA_PROD_SERVER vacío"; exit 1; }
  [[ -n "${XABIA_PROD_DOWNLOADS_PATH:-}" ]] || { log_err "XABIA_PROD_DOWNLOADS_PATH vacío"; exit 1; }
  [[ -n "${XABIA_PROD_HUB_PATH:-}" ]] || { log_err "XABIA_PROD_HUB_PATH vacío"; exit 1; }

  printf '\n%s\n' "${BOLD}Xabia Deploy — Addons${NC}"
  [[ "$DRY_RUN" -eq 1 ]] && log_warn "Modo dry-run"
  printf '\n'

  deploy_one_addon xabia-woo
  deploy_one_addon xabia-mec
  deploy_one_addon xabia-avirato

  printf '\n'
  verify_updates_endpoint xabia-woo
  verify_updates_endpoint xabia-mec
  verify_updates_endpoint xabia-avirato

  log_step "[Addons desplegados]"
  printf '\n'
}

main "$@"
