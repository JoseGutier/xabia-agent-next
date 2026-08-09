#!/usr/bin/env bash
# Xabia Release Engine — Fase 2+3: despliegue producción, docs, CDN y Git.
#
# Uso:
#   ./xabia-deploy.sh 1.0.60
#   ./xabia-deploy.sh --dry-run 1.0.60
#   ./xabia-deploy.sh --no-git 1.0.60
#
# Requisitos: credenciales en .env.local (ver .env.local.example)

set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

ENV_LOCAL="$ROOT/.env.local"
DIST_DIR="$ROOT/xabia-agent-plugins/dist"
DOCS_LOCAL="${XABIA_DOCS_LOCAL:-$ROOT/xabia-agent-plugins/documentation}"
SLUG="xabia-agent-core"
PACKAGE_BASE_URL="${XABIA_PACKAGE_BASE_URL:-https://xabia.ai/downloads}"
PUBLIC_SITE="${XABIA_PUBLIC_SITE:-https://xabia.ai}"
DOCS_PUBLIC_BASE="${XABIA_DOCS_PUBLIC_BASE:-${PUBLIC_SITE}/docs}"
UPDATES_URL="${XABIA_UPDATES_URL:-${PUBLIC_SITE}/api/xabia/v1/updates}"
GIT_BRANCH="${XABIA_GIT_BRANCH:-main}"

DRY_RUN=0
NO_GIT=0
DEPLOY_ZIP_OK=0

# Manuales del ecosistema (md + pdf + html si existen localmente)
MANUAL_BASES=(
  manual-usuario-xabia-core
  manual-usuario-xabia-smart-qr
  manual-usuario-xabia-mec
  manual-usuario-xabia-woo
  manual-usuario-xabia-avirato
)

RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[0;33m'
BOLD='\033[1m'
NC='\033[0m'

usage() {
  cat <<'EOF'
Xabia Release Engine — Fase 2+3 (despliegue producción + docs + Git)

Uso:
  ./xabia-deploy.sh [--dry-run] [--no-git] <versión>

Ejemplo:
  ./xabia-deploy.sh 1.0.60
  ./xabia-deploy.sh --dry-run --no-git 1.0.60

Flujo:
  1. SCP del ZIP → XABIA_PROD_DOWNLOADS_PATH
  2. SCP Hub PHP (Router + DTP) → XABIA_PROD_HUB_PATH/src/
  3. SSH: actualiza XABIA_CORE_* en .env del Hub
  4. SCP manuales (md/pdf/html) → XABIA_PROD_DOCS_PATH
  4. Cloudflare: purge de /updates, ZIP y documentación
  5. Verificación HTTP del ZIP y /updates
  6. Git commit + tag + push (salvo --no-git)

Configuración:
  Copia .env.local.example → .env.local y rellena credenciales (no commitear).

Variables obligatorias:
  XABIA_PROD_SERVER
  XABIA_PROD_DOWNLOADS_PATH
  XABIA_PROD_HUB_PATH
  XABIA_PROD_DOCS_PATH

Variables opcionales:
  XABIA_DOCS_LOCAL          (default: xabia-agent-plugins/documentation)
  XABIA_CF_ZONE_ID / XABIA_CF_TOKEN
  XABIA_GIT_BRANCH          (default: main)
  XABIA_SSH_IDENTITY_FILE
  XABIA_SSH_PORT            (opcional; ej. 65002 en Hostinger si no usas ~/.ssh/config)
EOF
}

log_ok()   { printf "${GREEN}✓${NC} %s\n" "$*"; }
log_info() { printf "${CYAN}→${NC} %s\n" "$*"; }
log_warn() { printf "${YELLOW}!${NC} %s\n" "$*"; }
log_err()  { printf "${RED}✗${NC} %s\n" "$*" >&2; }
log_step() { printf "${BOLD}%s${NC}\n" "$*"; }

validate_version() {
  local v="$1"
  if [[ ! "$v" =~ ^[0-9]+(\.[0-9]+)*$ ]]; then
    log_err "Versión inválida: «$v». Use formato semver (ej. 1.0.60)."
    exit 1
  fi
}

load_env_local() {
  if [[ -f "$ENV_LOCAL" ]]; then
    set -a
    # shellcheck disable=SC1090
    source "$ENV_LOCAL"
    set +a
    DOCS_LOCAL="${XABIA_DOCS_LOCAL:-$DOCS_LOCAL}"
    GIT_BRANCH="${XABIA_GIT_BRANCH:-$GIT_BRANCH}"
    log_ok "Credenciales cargadas desde .env.local"
  else
    log_warn "No existe .env.local — solo se usarán variables ya exportadas en el shell"
  fi
}

require_var() {
  local name="$1"
  if [[ -z "${!name:-}" ]]; then
    log_err "Falta variable obligatoria: $name (defínela en .env.local)"
    exit 1
  fi
}

SSH_BASE_ARGS=()

ssh_base_args() {
  SSH_BASE_ARGS=(-o BatchMode=yes -o ConnectTimeout=20 -o StrictHostKeyChecking=accept-new)
  if [[ -n "${XABIA_SSH_PORT:-}" ]]; then
    SSH_BASE_ARGS+=(-p "$XABIA_SSH_PORT")
  fi
  if [[ -n "${XABIA_SSH_IDENTITY_FILE:-}" ]]; then
    SSH_BASE_ARGS+=(-i "$XABIA_SSH_IDENTITY_FILE")
  fi
}

run_ssh() {
  ssh_base_args
  if [[ "$DRY_RUN" -eq 1 ]]; then
    log_info "[dry-run] ssh ${SSH_BASE_ARGS[*]} ${XABIA_PROD_SERVER} $*"
    return 0
  fi
  ssh "${SSH_BASE_ARGS[@]}" "${XABIA_PROD_SERVER}" "$@"
}

run_scp() {
  ssh_base_args
  if [[ "$DRY_RUN" -eq 1 ]]; then
    log_info "[dry-run] scp ${SSH_BASE_ARGS[*]} $* → ${XABIA_PROD_SERVER}"
    return 0
  fi
  scp "${SSH_BASE_ARGS[@]}" "$@"
}

normalize_remote_path() {
  local p="$1"
  p="${p%/}"
  printf '%s' "$p"
}

collect_local_manual_files() {
  local -a files=()
  local base ext f
  for base in "${MANUAL_BASES[@]}"; do
    for ext in md pdf html; do
      f="${DOCS_LOCAL}/${base}.${ext}"
      if [[ -f "$f" ]]; then
        files+=("$f")
      fi
    done
  done
  if [[ -f "${DOCS_LOCAL}/index.html" ]]; then
    files+=("${DOCS_LOCAL}/index.html")
  fi
  if [[ ${#files[@]} -eq 0 ]]; then
    return 1
  fi
  printf '%s\n' "${files[@]}"
}

docs_public_urls() {
  local base ext
  for base in "${MANUAL_BASES[@]}"; do
    for ext in md pdf html; do
      if [[ -f "${DOCS_LOCAL}/${base}.${ext}" ]]; then
        printf '%s/%s.%s\n' "${DOCS_PUBLIC_BASE}" "$base" "$ext"
      fi
    done
  done
  if [[ -f "${DOCS_LOCAL}/index.html" ]]; then
    printf '%s/\n' "${DOCS_PUBLIC_BASE}"
    printf '%s/index.html\n' "${DOCS_PUBLIC_BASE}"
  fi
  if [[ -f "${DOCS_LOCAL}/assets/manual.css" ]]; then
    printf '%s/assets/manual.css\n' "${DOCS_PUBLIC_BASE}"
  fi
}

deploy_zip() {
  local version="$1"
  local zip_local="$DIST_DIR/${SLUG}-${version}.zip"
  local zip_name="${SLUG}-${version}.zip"
  local downloads_path
  downloads_path="$(normalize_remote_path "$XABIA_PROD_DOWNLOADS_PATH")"
  local remote_file="${downloads_path}/${zip_name}"

  if [[ ! -f "$zip_local" ]]; then
    log_err "No existe el artefacto local: $zip_local"
    log_err "Ejecuta antes: ./xabia-build.sh ${version}"
    exit 1
  fi

  log_info "Subiendo $(du -h "$zip_local" | cut -f1) → ${XABIA_PROD_SERVER}:${remote_file}"
  if [[ "$DRY_RUN" -eq 0 ]]; then
    run_ssh "mkdir -p $(printf '%q' "$downloads_path")"
  fi
  run_scp "$zip_local" "${XABIA_PROD_SERVER}:${remote_file}"

  if [[ "$DRY_RUN" -eq 0 ]]; then
    run_ssh "test -f $(printf '%q' "$remote_file")"
  fi

  log_step "[ZIP Subido] ${remote_file}"
}

update_remote_hub_env() {
  local version="$1"
  local hub_path
  hub_path="$(normalize_remote_path "$XABIA_PROD_HUB_PATH")"
  local env_file="${hub_path}/.env"
  local package_url="${PACKAGE_BASE_URL}/${SLUG}-${version}.zip"

  log_info "Actualizando Hub .env en ${env_file}"

  if [[ "$DRY_RUN" -eq 1 ]]; then
    log_info "[dry-run] XABIA_CORE_LATEST_VERSION=${version}"
    log_info "[dry-run] XABIA_CORE_UPDATE_PACKAGE=${package_url}"
    log_step "[.env Actualizado] (simulado)"
    return 0
  fi

  ssh_base_args
  ssh "${SSH_BASE_ARGS[@]}" "${XABIA_PROD_SERVER}" bash -s -- "$env_file" "$version" "$package_url" <<'REMOTE'
set -euo pipefail
ENV_FILE="$1"
VERSION="$2"
PACKAGE_URL="$3"
if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: no existe $ENV_FILE" >&2
  exit 1
fi
# sed (sin perl): compatible con hosting compartido Hostinger
if grep -q '^XABIA_CORE_LATEST_VERSION=' "$ENV_FILE"; then
  sed -i "s|^XABIA_CORE_LATEST_VERSION=.*|XABIA_CORE_LATEST_VERSION=${VERSION}|" "$ENV_FILE"
else
  echo "XABIA_CORE_LATEST_VERSION=${VERSION}" >> "$ENV_FILE"
fi
if grep -q '^XABIA_CORE_UPDATE_PACKAGE=' "$ENV_FILE"; then
  sed -i "s|^XABIA_CORE_UPDATE_PACKAGE=.*|XABIA_CORE_UPDATE_PACKAGE=${PACKAGE_URL}|" "$ENV_FILE"
else
  echo "XABIA_CORE_UPDATE_PACKAGE=${PACKAGE_URL}" >> "$ENV_FILE"
fi
grep -E '^XABIA_CORE_LATEST_VERSION=|^XABIA_CORE_UPDATE_PACKAGE=' "$ENV_FILE"
REMOTE

  log_step "[.env Actualizado] ${env_file}"
}

deploy_hub_php() {
  local hub_path
  hub_path="$(normalize_remote_path "$XABIA_PROD_HUB_PATH")"
  local src_local="$ROOT/xabia-agent-plugins/central-api/src"
  local remote_src="${hub_path}/src"

  if [[ ! -d "$src_local" ]]; then
    log_err "No existe $src_local"
    exit 1
  fi

  # Puente DTP + Router + pipeline conocimiento Hub.
  local -a hub_files=(
    Router.php
    DtpEntitlement.php
    DtpTranslator.php
    I18nGreetingHandler.php
    KnowledgeSyncHandler.php
    KnowledgeSearchHandler.php
    KnowledgeVectorsRepository.php
    KnowledgeSlug.php
  )
  local -a hub_worker_files=(
    Workers/VectorizationWorker.php
  )

  log_info "Subiendo Hub PHP (${#hub_files[@]} archivos) → ${XABIA_PROD_SERVER}:${remote_src}/"

  local f missing=0
  for f in "${hub_files[@]}"; do
    if [[ ! -f "${src_local}/${f}" ]]; then
      log_err "Falta ${src_local}/${f}"
      missing=1
    fi
  done
  if [[ "$missing" -eq 1 ]]; then
    exit 1
  fi

  if [[ "$DRY_RUN" -eq 0 ]]; then
    run_ssh "mkdir -p $(printf '%q' "$remote_src") $(printf '%q' "${remote_src}/Workers")"
    local abs_files=()
    for f in "${hub_files[@]}"; do
      abs_files+=("${src_local}/${f}")
    done
    run_scp "${abs_files[@]}" "${XABIA_PROD_SERVER}:${remote_src}/"
    for f in "${hub_worker_files[@]}"; do
      if [[ -f "${src_local}/${f}" ]]; then
        run_scp "${src_local}/${f}" "${XABIA_PROD_SERVER}:${remote_src}/${f}"
      fi
    done
  else
    local f
    for f in "${hub_files[@]}"; do
      log_info "[dry-run]   ${f}"
    done
  fi

  log_step "[Hub PHP desplegado] ${remote_src}/ (${#hub_files[@]} archivos)"
}

deploy_docs() {
  local docs_path
  docs_path="$(normalize_remote_path "$XABIA_PROD_DOCS_PATH")"
  local -a files=()

  if [[ ! -d "$DOCS_LOCAL" ]]; then
    log_err "No existe carpeta local de documentación: $DOCS_LOCAL"
    exit 1
  fi

  while IFS= read -r f; do
    files+=("$f")
  done < <(collect_local_manual_files || true)

  if [[ ${#files[@]} -eq 0 ]]; then
    log_warn "No hay manuales en $DOCS_LOCAL — ejecuta ./scripts/build-modular-manuals-pdf.sh"
    log_step "[Manuales Subidos] omitido (sin archivos locales)"
    return 0
  fi

  log_info "Subiendo ${#files[@]} archivo(s) de docs → ${XABIA_PROD_SERVER}:${docs_path}/"

  if [[ "$DRY_RUN" -eq 0 ]]; then
    run_ssh "mkdir -p $(printf '%q' "$docs_path") $(printf '%q' "${docs_path}/assets")"
    run_scp "${files[@]}" "${XABIA_PROD_SERVER}:${docs_path}/"
    if [[ -f "${DOCS_LOCAL}/assets/manual.css" ]]; then
      run_scp "${DOCS_LOCAL}/assets/manual.css" "${XABIA_PROD_SERVER}:${docs_path}/assets/manual.css"
    fi
  else
    local f
    for f in "${files[@]}"; do
      log_info "[dry-run]   $(basename "$f")"
    done
    if [[ -f "${DOCS_LOCAL}/assets/manual.css" ]]; then
      log_info "[dry-run]   assets/manual.css"
    fi
  fi

  log_step "[Manuales Subidos] ${docs_path}/ (${#files[@]} archivos + assets)"
}

purge_cloudflare_cache() {
  local version="$1"
  local zip_url="${PACKAGE_BASE_URL}/${SLUG}-${version}.zip"
  local -a purge_urls=("$UPDATES_URL" "$zip_url")

  while IFS= read -r url; do
    [[ -n "$url" ]] && purge_urls+=("$url")
  done < <(docs_public_urls)

  if [[ -z "${XABIA_CF_ZONE_ID:-}" || -z "${XABIA_CF_TOKEN:-}" ]]; then
    log_warn "Cloudflare no configurado (XABIA_CF_ZONE_ID / XABIA_CF_TOKEN) — omitiendo purge"
    log_step "[CDN Invalidada] omitido"
    log_step "[Caché Docs Purgada] omitido"
    return 0
  fi

  log_info "Cloudflare purge selectivo (${#purge_urls[@]} URLs, zone ${XABIA_CF_ZONE_ID})"

  local payload
  payload="$(python3 -c 'import json,sys; print(json.dumps({"files": sys.argv[1:]}))' "${purge_urls[@]}")"

  if [[ "$DRY_RUN" -eq 1 ]]; then
    local url
    for url in "${purge_urls[@]}"; do
      log_info "[dry-run] purge $url"
    done
    log_step "[CDN Invalidada] (simulado)"
    log_step "[Caché Docs Purgada] (simulado)"
    return 0
  fi

  local response http_code body
  response="$(curl -sS -w $'\n%{http_code}' -X POST \
    "https://api.cloudflare.com/client/v4/zones/${XABIA_CF_ZONE_ID}/purge_cache" \
    -H "Authorization: Bearer ${XABIA_CF_TOKEN}" \
    -H "Content-Type: application/json" \
    --data "$payload")"

  http_code="${response##*$'\n'}"
  body="${response%$'\n'*}"

  if [[ "$http_code" != "200" ]]; then
    log_err "Cloudflare API HTTP ${http_code}"
    echo "$body" >&2
    exit 1
  fi

  if ! echo "$body" | grep -q '"success"[[:space:]]*:[[:space:]]*true'; then
    log_err "Cloudflare purge falló"
    echo "$body" >&2
    exit 1
  fi

  log_step "[CDN Invalidada] /updates + ZIP v${version}"
  log_step "[Caché Docs Purgada] ${DOCS_PUBLIC_BASE}/*"
}

verify_deployment() {
  local version="$1"
  local zip_url="${PACKAGE_BASE_URL}/${SLUG}-${version}.zip"

  DEPLOY_ZIP_OK=0

  if [[ "$DRY_RUN" -eq 1 ]]; then
    DEPLOY_ZIP_OK=1
    return 0
  fi

  log_info "Verificando endpoints públicos…"
  local zip_code updates_body core_pdf_code
  zip_code="$(curl -sS -o /dev/null -w '%{http_code}' "$zip_url" || true)"
  updates_body="$(curl -sS "${UPDATES_URL}?plugin=xabia-agent-core&installed=${version}" || true)"
  core_pdf_code="$(curl -sS -o /dev/null -w '%{http_code}' "${DOCS_PUBLIC_BASE}/manual-usuario-xabia-core.pdf" || true)"

  if [[ "$zip_code" == "200" ]]; then
    log_ok "ZIP público HTTP 200"
    DEPLOY_ZIP_OK=1
  else
    log_warn "ZIP público HTTP ${zip_code} (revisa CDN o ruta downloads/)"
  fi

  if echo "$updates_body" | grep -q "\"version\"[[:space:]]*:[[:space:]]*\"${version}\""; then
    log_ok "/updates devuelve versión ${version}"
  else
    log_warn "/updates no confirma versión ${version} (¿PHP opcache o .env sin recargar?)"
    echo "$updates_body" | head -c 400 >&2 || true
    echo >&2
  fi

  if [[ "$core_pdf_code" == "200" ]]; then
    log_ok "Manual Core PDF HTTP 200"
  elif [[ -f "${DOCS_LOCAL}/manual-usuario-xabia-core.pdf" ]]; then
    log_warn "Manual Core PDF HTTP ${core_pdf_code} (puede ser caché CDN tardía)"
  fi
}


deploy_polar_retail() {
  local version="$1"
  local zip_path="${DIST_DIR}/retail/${SLUG}-${version}-retail.zip"

  if [[ -z "${POLAR_ACCESS_TOKEN:-}" || -z "${POLAR_CORE_BENEFIT_ID:-}" ]]; then
    log_warn "Polar omitido: falta POLAR_ACCESS_TOKEN o POLAR_CORE_BENEFIT_ID en .env.local"
    log_step "[Polar Retail] omitido (sin credenciales)"
    return 0
  fi

  if [[ ! -f "$zip_path" ]]; then
    log_warn "Polar omitido: no existe $zip_path — ejecuta ./scripts/build-retail-plugin-zips.sh"
    log_step "[Polar Retail] omitido (sin ZIP retail)"
    return 0
  fi

  if [[ "$DRY_RUN" -eq 1 ]]; then
    log_info "[dry-run] python3 scripts/polar-upload-retail.py ${version}"
    log_step "[Polar Retail] (simulado)"
    return 0
  fi

  log_info "Subiendo retail ZIP a Polar (benefit ${POLAR_CORE_BENEFIT_ID})…"
  if python3 "$ROOT/scripts/polar-upload-retail.py" "$version"; then
    log_step "[Polar Retail] ZIP adjunto al beneficio Downloadables"
  else
    log_err "Fallo subida Polar — el deploy Hostinger/docs ya se aplicó"
    exit 1
  fi
}

git_release() {
  local version="$1"
  local tag="v${version}"
  local msg="Release v${version} - Core, Docs & Hub Sync"

  if [[ "$NO_GIT" -eq 1 ]]; then
    log_step "[Git Commit & Push Completado] omitido (--no-git)"
    return 0
  fi

  if [[ "$DEPLOY_ZIP_OK" -ne 1 ]]; then
    log_warn "Git omitido: el ZIP no respondió HTTP 200 en verificación pública"
    log_step "[Git Commit & Push Completado] omitido (deploy no verificado)"
    return 0
  fi

  if [[ "$DRY_RUN" -eq 1 ]]; then
    log_info "[dry-run] git add ."
    log_info "[dry-run] git commit -m \"${msg}\""
    log_info "[dry-run] git tag -a ${tag} -m \"Release v${version}\""
    log_info "[dry-run] git push origin ${GIT_BRANCH} --tags"
    log_step "[Git Commit & Push Completado] (simulado)"
    return 0
  fi

  if ! git rev-parse --git-dir >/dev/null 2>&1; then
    log_warn "No es un repositorio Git — omitiendo commit/tag/push"
    log_step "[Git Commit & Push Completado] omitido"
    return 0
  fi

  log_info "Git: commit, tag ${tag} y push a origin/${GIT_BRANCH}…"

  git add .

  if git diff --cached --quiet; then
    log_warn "No hay cambios staged — commit omitido"
  else
    git commit -m "$msg"
    log_ok "Commit creado"
  fi

  if git rev-parse "$tag" >/dev/null 2>&1; then
    log_warn "El tag ${tag} ya existe — no se recrea"
  else
    git tag -a "$tag" -m "Release v${version}"
    log_ok "Tag ${tag} creado"
  fi

  git push origin "$GIT_BRANCH" --tags
  log_step "[Git Commit & Push Completado] origin/${GIT_BRANCH} + ${tag}"
}

main() {
  local version=""
  for arg in "$@"; do
    case "$arg" in
      -h|--help)
        usage
        exit 0
        ;;
      --dry-run)
        DRY_RUN=1
        ;;
      --no-git)
        NO_GIT=1
        ;;
      *)
        if [[ -z "$version" ]]; then
          version="$arg"
        else
          log_err "Argumento desconocido: $arg"
          usage
          exit 1
        fi
        ;;
    esac
  done

  if [[ -z "$version" ]]; then
    usage
    exit 1
  fi

  validate_version "$version"
  load_env_local
  require_var XABIA_PROD_SERVER
  require_var XABIA_PROD_DOWNLOADS_PATH
  require_var XABIA_PROD_HUB_PATH
  require_var XABIA_PROD_DOCS_PATH

  printf '\n%s\n' "${BOLD}Xabia Release Engine — Fase 2+3${NC}"
  printf 'Versión: %s\n' "$version"
  printf 'Docs local: %s\n' "$DOCS_LOCAL"
  [[ "$DRY_RUN" -eq 1 ]] && log_warn "Modo dry-run (no se aplican cambios reales)"
  [[ "$NO_GIT" -eq 1 ]] && log_warn "Git desactivado (--no-git)"
  printf '\n'

  deploy_zip "$version"
  deploy_hub_php
  update_remote_hub_env "$version"
  deploy_docs
  purge_cloudflare_cache "$version"
  verify_deployment "$version"
  deploy_polar_retail "$version"
  git_release "$version"

  printf '\n%s\n' "${BOLD}━━━ Release Engine completado ━━━${NC}"
  log_ok "[ZIP Subido] ➔ [Hub PHP] ➔ [.env Actualizado] ➔ [Manuales Subidos] ➔ [Caché Docs Purgada] ➔ [Git Commit & Push Completado]"
  printf '\n'
}

main "$@"
