#!/usr/bin/env bash
# Crea en WordPress la jerarquía Documentación → manuales (sin iframe, sin código en el plugin).
# Uso: ./scripts/wp-create-manual-pages.sh [--dry-run]
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DOCS="${XABIA_DOCS_LOCAL:-$ROOT/xabia-agent-plugins/documentation}"
ENV_LOCAL="$ROOT/.env.local"
DRY_RUN=0
[[ "${1:-}" == "--dry-run" ]] && DRY_RUN=1

if [[ -f "$ENV_LOCAL" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "$ENV_LOCAL"
  set +a
fi

WP_PATH="${XABIA_WP_PATH:-/home/u610697097/domains/xabia.ai/public_html}"
SERVER="${XABIA_PROD_SERVER:?Falta XABIA_PROD_SERVER}"
SITE_URL="${XABIA_PUBLIC_SITE:-https://xabia.ai}"
CSS_URL="${SITE_URL}/wp-content/uploads/xabia-manuales/assets/manual.css"

SSH_OPTS=(-o BatchMode=yes -o ConnectTimeout=25 -o StrictHostKeyChecking=accept-new)
[[ -n "${XABIA_SSH_PORT:-}" ]] && SSH_OPTS+=(-p "$XABIA_SSH_PORT")
[[ -n "${XABIA_SSH_IDENTITY_FILE:-}" ]] && SSH_OPTS+=(-i "$XABIA_SSH_IDENTITY_FILE")

run_wp() {
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "[dry-run] wp $*"
    return 0
  fi
  ssh "${SSH_OPTS[@]}" "$SERVER" "cd $(printf '%q' "$WP_PATH") && wp $*"
}

extract_body() {
  local file="$1"
  python3 - "$file" <<'PY'
import re, sys
path = sys.argv[1]
html = open(path, encoding="utf-8").read()
m = re.search(r"<body[^>]*>(.*)</body>", html, re.I | re.S)
print(m.group(1).strip() if m else html.strip())
PY
}

wrap_for_wp() {
  local body="$1"
  printf '<link rel="stylesheet" href="%s">\n%s' "$CSS_URL" "$body"
}

# slug|title|html_file
PAGES=(
  "xabia-agent-core|Xabia Agent Core|manual-usuario-xabia-core.html"
  "byok-google-gemini|BYOK Google Gemini|manual-usuario-xabia-byok-google.html"
  "smart-qr|Smart QR / Tótems|manual-usuario-xabia-smart-qr.html"
  "mec|Xabia MEC|manual-usuario-xabia-mec.html"
  "woo|Xabia WooCommerce|manual-usuario-xabia-woo.html"
  "avirato|Xabia Avirato|manual-usuario-xabia-avirato.html"
)

# Página padre: índice con enlaces a hijas (reescritura de .html → rutas WP)
INDEX_BODY="$(extract_body "$DOCS/index.html")"
for entry in "${PAGES[@]}"; do
  IFS='|' read -r slug _title html_file <<< "$entry"
  INDEX_BODY="${INDEX_BODY//href=\"${html_file}\"/href=\"${SITE_URL}/documentacion/${slug}/\"}"
  INDEX_BODY="${INDEX_BODY//href=\"${html_file%.html}.pdf\"/href=\"${SITE_URL}/docs/${html_file%.html}.pdf\"}"
done
INDEX_BODY="${INDEX_BODY//href=\".\/\"/href=\"${SITE_URL}/documentacion/\"}"

PARENT_CONTENT="$(wrap_for_wp "$INDEX_BODY")"

ensure_page() {
  local title="$1" slug="$2" parent_id="$3" content="$4"
  local existing_id wp_cmd

  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "[dry-run] ensure_page: $title ($slug) parent=$parent_id" >&2
    echo "0"
    return 0
  fi

  existing_id="$(ssh "${SSH_OPTS[@]}" "$SERVER" "cd $(printf '%q' "$WP_PATH") && wp post list --post_type=page --name=$(printf '%q' "$slug") --post_parent=$(printf '%q' "$parent_id") --field=ID --format=ids" 2>/dev/null || true)"
  if [[ -z "$existing_id" && "$parent_id" == "0" ]]; then
    existing_id="$(ssh "${SSH_OPTS[@]}" "$SERVER" "cd $(printf '%q' "$WP_PATH") && wp post list --post_type=page --name=$(printf '%q' "$slug") --field=ID --format=ids" 2>/dev/null || true)"
  fi

  if [[ -n "$existing_id" ]]; then
    echo "Actualizando página $title ($slug) ID=$existing_id" >&2
    wp_cmd="wp post update $(printf '%q' "$existing_id") --post_title=$(printf '%q' "$title") --post_status=publish --post_parent=$(printf '%q' "$parent_id") --post_content=$(printf '%q' "$content") >/dev/null"
    ssh "${SSH_OPTS[@]}" "$SERVER" "cd $(printf '%q' "$WP_PATH") && $wp_cmd"
    echo "$existing_id"
    return 0
  fi

  echo "Creando página $title ($slug)" >&2
  existing_id="$(ssh "${SSH_OPTS[@]}" "$SERVER" "cd $(printf '%q' "$WP_PATH") && wp post create --post_type=page --post_title=$(printf '%q' "$title") --post_name=$(printf '%q' "$slug") --post_status=publish --post_parent=$(printf '%q' "$parent_id") --post_content=$(printf '%q' "$content") --porcelain")"
  echo "$existing_id"
}

PARENT_ID="$(ensure_page "Documentación" "documentacion" "0" "$PARENT_CONTENT")"
echo "Parent ID: $PARENT_ID"

for entry in "${PAGES[@]}"; do
  IFS='|' read -r slug title html_file <<< "$entry"
  body="$(extract_body "$DOCS/$html_file")"
  # Enlaces internos del manual → rutas WP
  body="${body//href=\".\/\"/href=\"${SITE_URL}/documentacion/\"}"
  body="${body//href=\"index.html\"/href=\"${SITE_URL}/documentacion/\"}"
  for e2 in "${PAGES[@]}"; do
    IFS='|' read -r slug2 _t2 hf2 <<< "$e2"
    body="${body//href=\"${hf2}\"/href=\"${SITE_URL}/documentacion/${slug2}/\"}"
  done
  content="$(wrap_for_wp "$body")"
  child_id="$(ensure_page "$title" "$slug" "$PARENT_ID" "$content")"
  echo "  → $title ($slug) ID=$child_id → ${SITE_URL}/documentacion/${slug}/"
done

echo "Listo: ${SITE_URL}/documentacion/"
