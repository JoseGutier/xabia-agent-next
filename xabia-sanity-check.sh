#!/usr/bin/env bash
# Xabia Release Engine — auditoría pre-vuelo (Fases 1–3).
#
# Uso:
#   ./xabia-sanity-check.sh
#   ./xabia-sanity-check.sh 1.0.60
#   ./xabia-sanity-check.sh --skip-remote 1.0.60
#
# Compatible con Bash 3.2 (macOS).

set -u

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

ENV_LOCAL="$ROOT/.env.local"
ENV_EXAMPLE="$ROOT/.env.local.example"
DOCS_LOCAL="$ROOT/xabia-agent-plugins/documentation"
BUILD_SCRIPT="$ROOT/xabia-build.sh"
DEPLOY_SCRIPT="$ROOT/xabia-deploy.sh"

SKIP_REMOTE=0
TARGET_VERSION="1.0.60"

REQUIRED_ENV_VARS=(
  XABIA_PROD_SERVER
  XABIA_PROD_DOWNLOADS_PATH
  XABIA_PROD_DOCS_PATH
  XABIA_PROD_HUB_PATH
)

MANUAL_BASES=(
  manual-usuario-xabia-core
  manual-usuario-xabia-smart-qr
  manual-usuario-xabia-mec
  manual-usuario-xabia-woo
  manual-usuario-xabia-avirato
)

ERRORS=0
WARNINGS=0

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

SSH_BASE_ARGS=()

usage() {
  cat <<'EOF'
Xabia Sanity Check — auditoría pre-despliegue

Uso:
  ./xabia-sanity-check.sh [versión]
  ./xabia-sanity-check.sh --skip-remote [versión]

Ejemplo:
  ./xabia-sanity-check.sh 1.0.60

Comprueba entorno local (.env.local, scripts, manuales, Git)
y conectividad/rutas remotas en Hostinger vía SSH.
EOF
}

ok() {
  printf "${GREEN}[OK]${NC} %s\n" "$*"
}

err() {
  printf "${RED}[ERROR]${NC} %s\n" "$*"
  ERRORS=$((ERRORS + 1))
}

warn() {
  printf "${YELLOW}[WARN]${NC} %s\n" "$*"
  WARNINGS=$((WARNINGS + 1))
}

section() {
  printf '\n%s%s%s\n' "${BOLD}" "$*" "${NC}"
}

is_executable() {
  [[ -f "$1" && -x "$1" ]]
}

env_var_nonempty() {
  local name="$1"
  local val="${!name:-}"
  [[ -n "$val" ]]
}

looks_like_placeholder() {
  local val="$1"
  case "$val" in
    *123.456.789.012*|*tu@*|*tu/ruta*|*tu_.ssh*|*whsec_tu_*|*example.com*)
      return 0
      ;;
  esac
  return 1
}

load_env_local() {
  if [[ ! -f "$ENV_LOCAL" ]]; then
    return 1
  fi
  set -a
  # shellcheck disable=SC1090
  source "$ENV_LOCAL"
  set +a
  if [[ -n "${XABIA_DOCS_LOCAL:-}" ]]; then
    DOCS_LOCAL="$XABIA_DOCS_LOCAL"
  fi
  return 0
}

ssh_base_args() {
  SSH_BASE_ARGS=(-o BatchMode=yes -o ConnectTimeout=20 -o StrictHostKeyChecking=accept-new)
  if [[ -n "${XABIA_SSH_PORT:-}" ]]; then
    SSH_BASE_ARGS+=(-p "$XABIA_SSH_PORT")
  fi
  if [[ -n "${XABIA_SSH_IDENTITY_FILE:-}" ]]; then
    if [[ -f "${XABIA_SSH_IDENTITY_FILE}" ]]; then
      SSH_BASE_ARGS+=(-i "${XABIA_SSH_IDENTITY_FILE}")
    else
      err "XABIA_SSH_IDENTITY_FILE apunta a un archivo inexistente: ${XABIA_SSH_IDENTITY_FILE}"
    fi
  fi
}

audit_local_env_file() {
  section "1. Local — .env.local"

  if [[ ! -f "$ENV_LOCAL" ]]; then
    err ".env.local no existe (copia desde .env.local.example)"
    if [[ -f "$ENV_EXAMPLE" ]]; then
      warn "Sugerencia: cp .env.local.example .env.local && chmod 600 .env.local"
    fi
    return
  fi
  ok ".env.local presente"

  local mode=""
  if [[ "$(uname -s)" == "Darwin" ]]; then
    mode="$(stat -f '%Lp' "$ENV_LOCAL" 2>/dev/null || echo '')"
  else
    mode="$(stat -c '%a' "$ENV_LOCAL" 2>/dev/null || echo '')"
  fi
  if [[ "$mode" == "600" ]]; then
    ok ".env.local permisos 600"
  else
    err ".env.local permisos ${mode:-desconocidos} (esperado: 600) — ejecuta: chmod 600 .env.local"
  fi

  if ! load_env_local; then
    err "No se pudo cargar .env.local"
    return
  fi
  ok ".env.local cargado en shell"

  local var val
  for var in "${REQUIRED_ENV_VARS[@]}"; do
    if ! env_var_nonempty "$var"; then
      err "Variable obligatoria vacía o ausente: ${var}"
      continue
    fi
    val="${!var}"
    if looks_like_placeholder "$val"; then
      err "Variable ${var} parece un valor de ejemplo: ${val}"
      continue
    fi
    ok "Variable ${var} configurada"
  done

  if [[ -z "${XABIA_CF_ZONE_ID:-}" || -z "${XABIA_CF_TOKEN:-}" ]]; then
    warn "Cloudflare no configurado (XABIA_CF_ZONE_ID / XABIA_CF_TOKEN) — purge CDN se omitirá en deploy"
  else
    ok "Cloudflare configurado (purge CDN habilitado)"
  fi
}

audit_local_scripts() {
  section "2. Local — scripts Release Engine"

  if is_executable "$BUILD_SCRIPT"; then
    ok "xabia-build.sh ejecutable (+x)"
  else
    if [[ -f "$BUILD_SCRIPT" ]]; then
      err "xabia-build.sh existe pero no es ejecutable — chmod +x xabia-build.sh"
    else
      err "Falta xabia-build.sh en la raíz del repo"
    fi
  fi

  if is_executable "$DEPLOY_SCRIPT"; then
    ok "xabia-deploy.sh ejecutable (+x)"
  else
    if [[ -f "$DEPLOY_SCRIPT" ]]; then
      err "xabia-deploy.sh existe pero no es ejecutable — chmod +x xabia-deploy.sh"
    else
      err "Falta xabia-deploy.sh en la raíz del repo"
    fi
  fi

  if [[ -f "$ROOT/scripts/build-modular-manuals-pdf.sh" ]]; then
    ok "scripts/build-modular-manuals-pdf.sh presente"
  else
    warn "No se encontró scripts/build-modular-manuals-pdf.sh"
  fi
}

audit_local_docs() {
  section "3. Local — manuales (documentation/)"

  if [[ ! -d "$DOCS_LOCAL" ]]; then
    err "Carpeta de manuales ausente: ${DOCS_LOCAL}"
    return
  fi
  ok "Carpeta de manuales: ${DOCS_LOCAL}"

  local md_count=0 pdf_count=0 html_count=0 missing_md=0
  local base f

  for base in "${MANUAL_BASES[@]}"; do
    f="${DOCS_LOCAL}/${base}.md"
    if [[ -f "$f" ]]; then
      md_count=$((md_count + 1))
    else
      missing_md=$((missing_md + 1))
      err "Falta Markdown: ${base}.md"
    fi
    if [[ -f "${DOCS_LOCAL}/${base}.pdf" ]]; then
      pdf_count=$((pdf_count + 1))
    fi
    if [[ -f "${DOCS_LOCAL}/${base}.html" ]]; then
      html_count=$((html_count + 1))
    fi
  done

  ok "Manuales Markdown: ${md_count}/${#MANUAL_BASES[@]}"
  if [[ "$pdf_count" -eq ${#MANUAL_BASES[@]} ]]; then
    ok "Manuales PDF: ${pdf_count}/${#MANUAL_BASES[@]}"
  elif [[ "$pdf_count" -gt 0 ]]; then
    warn "Manuales PDF: ${pdf_count}/${#MANUAL_BASES[@]} — ejecuta ./scripts/build-modular-manuals-pdf.sh"
  else
    err "No hay PDFs en documentation/ — ejecuta ./scripts/build-modular-manuals-pdf.sh"
  fi

  if [[ "$html_count" -gt 0 ]]; then
    ok "Manuales HTML: ${html_count}/${#MANUAL_BASES[@]}"
  else
    warn "Sin HTML generado (opcional; el deploy también sube .html si existen)"
  fi
}

audit_local_git() {
  section "4. Local — Git"

  if command -v git >/dev/null 2>&1; then
    ok "Git disponible: $(git --version 2>/dev/null | head -n 1)"
  else
    err "Git no está instalado o no está en PATH"
    return
  fi

  if git rev-parse --git-dir >/dev/null 2>&1; then
    ok "Directorio actual es un repositorio Git"
  else
    err "La raíz del proyecto no es un repositorio Git"
    return
  fi

  local branch=""
  branch="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '')"
  if [[ -n "$branch" ]]; then
    ok "Rama actual: ${branch}"
  fi

  if git remote get-url origin >/dev/null 2>&1; then
    ok "Remote origin configurado"
  else
    warn "No hay remote origin — git push fallará en Fase 3"
  fi
}

audit_remote_ssh() {
  section "5. Remoto — SSH Hostinger"

  if [[ "$SKIP_REMOTE" -eq 1 ]]; then
    warn "Chequeo remoto omitido (--skip-remote)"
    return
  fi

  if [[ ! -f "$ENV_LOCAL" ]]; then
    err "No se puede auditar remoto sin .env.local"
    return
  fi

  load_env_local

  local var
  for var in "${REQUIRED_ENV_VARS[@]}"; do
    if ! env_var_nonempty "$var"; then
      err "SSH omitido: falta ${var}"
      return
    fi
  done

  ssh_base_args

  local ssh_out=""
  if ssh_out="$(ssh "${SSH_BASE_ARGS[@]}" "${XABIA_PROD_SERVER}" "echo XABIA_SSH_OK" 2>&1)"; then
    if [[ "$ssh_out" == *XABIA_SSH_OK* ]]; then
      ok "Conexión SSH exitosa (${XABIA_PROD_SERVER})"
    else
      err "SSH respondió pero sin confirmación esperada"
    fi
  else
    err "Conexión SSH fallida (${XABIA_PROD_SERVER})"
    warn "Detalle: ${ssh_out}"
    return
  fi

  audit_remote_directory "XABIA_PROD_DOWNLOADS_PATH" "${XABIA_PROD_DOWNLOADS_PATH}"
  audit_remote_directory "XABIA_PROD_DOCS_PATH" "${XABIA_PROD_DOCS_PATH}"
  audit_remote_directory "XABIA_PROD_HUB_PATH" "${XABIA_PROD_HUB_PATH}"

  local hub_env="${XABIA_PROD_HUB_PATH%/}/.env"
  local hub_env_out=""
  if hub_env_out="$(ssh "${SSH_BASE_ARGS[@]}" "${XABIA_PROD_SERVER}" "test -f $(printf '%q' "$hub_env") && echo HUB_ENV_OK" 2>&1)"; then
    if [[ "$hub_env_out" == *HUB_ENV_OK* ]]; then
      ok "Hub .env remoto existe (${hub_env})"
    else
      err "Hub .env remoto no encontrado: ${hub_env}"
    fi
  else
    err "No se pudo comprobar Hub .env remoto"
    warn "Detalle: ${hub_env_out}"
  fi
}

audit_remote_directory() {
  local label="$1"
  local path="$2"
  local out=""

  if [[ -z "$path" ]]; then
    err "${label} vacío"
    return
  fi

  if out="$(ssh "${SSH_BASE_ARGS[@]}" "${XABIA_PROD_SERVER}" "test -d $(printf '%q' "$path") && echo DIR_OK" 2>&1)"; then
    if [[ "$out" == *DIR_OK* ]]; then
      ok "Directorio remoto ${label}: ${path}"
    else
      err "Directorio remoto NO existe (${label}): ${path}"
    fi
  else
    err "Error comprobando ${label}: ${path}"
    warn "Detalle: ${out}"
  fi
}

print_summary() {
  section "Resumen"
  printf "Errores: ${ERRORS}  |  Avisos: ${WARNINGS}\n"

  if [[ "$ERRORS" -eq 0 ]]; then
    printf '\n%s%s%s\n\n' "${GREEN}${BOLD}" "ENTORNO VÁLIDO: Listo para lanzar v${TARGET_VERSION}" "${NC}"
    exit 0
  fi

  printf '\n%s%s%s\n\n' "${RED}${BOLD}" "ENTORNO NO VÁLIDO: corrige los [ERROR] antes del deploy" "${NC}"
  exit 1
}

main() {
  local arg
  for arg in "$@"; do
    case "$arg" in
      -h|--help)
        usage
        exit 0
        ;;
      --skip-remote)
        SKIP_REMOTE=1
        ;;
      *)
        if [[ "$arg" =~ ^[0-9]+(\.[0-9]+)*$ ]]; then
          TARGET_VERSION="$arg"
        else
          printf "Argumento desconocido: %s\n" "$arg" >&2
          usage
          exit 1
        fi
        ;;
    esac
  done

  printf '%s\n' "${BOLD}Xabia Sanity Check — Pre-vuelo Release Engine${NC}"
  printf 'Versión objetivo: v%s\n' "$TARGET_VERSION"

  audit_local_env_file
  audit_local_scripts
  audit_local_docs
  audit_local_git
  audit_remote_ssh

  print_summary
}

main "$@"
