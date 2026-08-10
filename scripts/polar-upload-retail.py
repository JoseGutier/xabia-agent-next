#!/usr/bin/env python3
"""Upload Xabia Core retail ZIP to a Polar downloadables benefit.

Requires env (from .env.local):
  POLAR_ACCESS_TOKEN
  POLAR_ORGANIZATION_ID   (optional if token is org-scoped)
  POLAR_CORE_BENEFIT_ID   (downloadables benefit UUID)

Usage:
  ./scripts/polar-upload-retail.py 1.0.201
  ./scripts/polar-upload-retail.py --list
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path

API = "https://api.polar.sh/v1"
# Cloudflare on api.polar.sh bans the default Python-urllib User-Agent (error 1010).
USER_AGENT = "xabia-release-engine/1.0 (+https://xabia.ai; compatible; curl/8.0)"


def die(msg: str, code: int = 1) -> None:
    print(f"✗ {msg}", file=sys.stderr)
    raise SystemExit(code)


def load_dotenv_local(root: Path) -> None:
    env_path = root / ".env.local"
    if not env_path.is_file():
        return
    for line in env_path.read_text(encoding="utf-8").splitlines():
        s = line.strip()
        if not s or s.startswith("#") or "=" not in s:
            continue
        k, v = s.split("=", 1)
        k = k.strip()
        v = v.strip().strip("'").strip('"')
        if k and k not in os.environ:
            os.environ[k] = v


def api_request(method: str, path: str, token: str, body: dict | None = None) -> dict:
    data = None
    headers = {
        "Authorization": f"Bearer {token}",
        "Accept": "application/json",
        "User-Agent": USER_AGENT,
    }
    if body is not None:
        data = json.dumps(body).encode("utf-8")
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(f"{API}{path}", data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=120) as resp:
            raw = resp.read().decode("utf-8")
            return json.loads(raw) if raw else {}
    except urllib.error.HTTPError as e:
        err = e.read().decode("utf-8", errors="replace")
        die(f"Polar API {method} {path} → HTTP {e.code}: {err[:800]}")


def sha256_b64(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return base64.b64encode(h.digest()).decode("ascii")


def list_resources(token: str) -> None:
    orgs = api_request("GET", "/organizations/?limit=100", token)
    print("Organizations:")
    for o in orgs.get("items") or []:
        print(f"  {o.get('id')}  slug={o.get('slug')}  name={o.get('name')}")
    benefits = api_request("GET", "/benefits/?limit=100&type=downloadables", token)
    print("\nDownloadables benefits:")
    for b in benefits.get("items") or []:
        props = b.get("properties") or {}
        files = props.get("files") or []
        print(
            f"  {b.get('id')}  org={b.get('organization_id')}  "
            f"desc={b.get('description')!r}  files={len(files)}"
        )


def upload_file(token: str, org_id: str | None, zip_path: Path, version: str) -> str:
    size = zip_path.stat().st_size
    checksum = sha256_b64(zip_path)
    name = zip_path.name
    body: dict = {
        "name": name,
        "mime_type": "application/zip",
        "size": size,
        "checksum_sha256_base64": checksum,
        "service": "downloadable",
        "version": version,
        "upload": {
            "parts": [
                {
                    "number": 1,
                    "chunk_start": 0,
                    "chunk_end": size - 1,
                    "checksum_sha256_base64": checksum,
                }
            ]
        },
    }
    # Organization access tokens reject organization_id on create (422).
    # Only send it for user PATs that can act across orgs.
    if org_id and os.environ.get("POLAR_SEND_ORGANIZATION_ID", "").strip() in ("1", "true", "yes"):
        body["organization_id"] = org_id

    created = api_request("POST", "/files/", token, body)
    file_id = created.get("id")
    upload = created.get("upload") or {}
    parts = upload.get("parts") or []
    if not file_id or not parts:
        die(f"Respuesta create file inesperada: {json.dumps(created)[:500]}")

    part = parts[0]
    put_url = part["url"]
    put_headers = dict(part.get("headers") or {})
    put_headers.setdefault("Content-Type", "application/zip")

    with zip_path.open("rb") as f:
        data = f.read()
    put_req = urllib.request.Request(put_url, data=data, headers=put_headers, method="PUT")
    try:
        with urllib.request.urlopen(put_req, timeout=300) as resp:
            etag = resp.headers.get("ETag") or resp.headers.get("etag") or ""
            etag = etag.strip('"')
    except urllib.error.HTTPError as e:
        err = e.read().decode("utf-8", errors="replace")
        die(f"S3 PUT falló HTTP {e.code}: {err[:500]}")

    if not etag:
        die("S3 PUT OK pero sin ETag en respuesta")

    up = created.get("upload") or {}
    completed = api_request(
        "POST",
        f"/files/{file_id}/uploaded",
        token,
        {
            "id": up.get("id"),
            "path": up.get("path"),
            "parts": [
                {
                    "number": 1,
                    "checksum_etag": etag,
                    "checksum_sha256_base64": checksum,
                }
            ],
        },
    )
    _ = completed
    print(f"✓ Archivo Polar subido: {file_id} ({name}, {size} bytes)")
    return str(file_id)


def attach_to_benefit(token: str, benefit_id: str, file_id: str, archive_old: bool) -> None:
    benefit = api_request("GET", f"/benefits/{benefit_id}", token)
    if benefit.get("type") != "downloadables":
        die(f"Benefit {benefit_id} no es downloadables (type={benefit.get('type')})")
    props = benefit.get("properties") or {}
    old_files = list(props.get("files") or [])
    archived = dict(props.get("archived") or {})
    if archive_old:
        for fid in old_files:
            if fid != file_id:
                archived[fid] = True
    body = {
        "type": "downloadables",
        "properties": {
            "files": [file_id],
            "archived": archived,
        },
    }
    updated = api_request("PATCH", f"/benefits/{benefit_id}", token, body)
    print(f"✓ Benefit actualizado: {benefit_id} → files={updated.get('properties', {}).get('files')}")


def main() -> None:
    root = Path(__file__).resolve().parents[1]
    load_dotenv_local(root)

    parser = argparse.ArgumentParser(description="Upload Core retail ZIP to Polar")
    parser.add_argument("version", nargs="?", help="Semver, ej. 1.0.201")
    parser.add_argument("--list", action="store_true", help="Listar orgs y benefits downloadables")
    parser.add_argument("--zip", dest="zip_path", help="Ruta ZIP (override)")
    parser.add_argument("--keep-old", action="store_true", help="No archivar ZIPs previos del benefit")
    args = parser.parse_args()

    token = (os.environ.get("POLAR_ACCESS_TOKEN") or "").strip()
    if not token:
        die("Falta POLAR_ACCESS_TOKEN en .env.local")

    if args.list:
        list_resources(token)
        return

    if not args.version:
        die("Indica versión: ./scripts/polar-upload-retail.py 1.0.201  (o --list)")

    version = args.version
    org_id = (os.environ.get("POLAR_ORGANIZATION_ID") or "").strip() or None
    benefit_id = (os.environ.get("POLAR_CORE_BENEFIT_ID") or "").strip()
    if not benefit_id:
        die("Falta POLAR_CORE_BENEFIT_ID en .env.local (usa --list para descubrirlo)")

    if args.zip_path:
        zip_path = Path(args.zip_path)
    else:
        zip_path = (
            root
            / "xabia-agent-plugins"
            / "dist"
            / "retail"
            / f"xabia-agent-core-{version}-retail.zip"
        )
    if not zip_path.is_file():
        die(f"No existe ZIP: {zip_path}")

    print(f"→ Subiendo {zip_path.name} a Polar…")
    file_id = upload_file(token, org_id, zip_path, version)
    attach_to_benefit(token, benefit_id, file_id, archive_old=not args.keep_old)
    print("✓ Polar retail listo")


if __name__ == "__main__":
    main()
