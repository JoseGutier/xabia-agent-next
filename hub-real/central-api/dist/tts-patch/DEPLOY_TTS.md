# Despliegue TTS Hub — central-api

Parche para activar `POST /xabia/v1/tts/synthesize` en **xabia.ai**.

## Archivos del parche

Subir estos archivos al servidor del Hub (misma raíz que el `central-api` en producción):

| Archivo | Acción |
|---------|--------|
| `src/Router.php` | Actualizar |
| `src/SignedHubPostAuth.php` | Actualizar (logs auth) |
| `src/TtsHandler.php` | **Nuevo** |
| `src/TtsGoogleCloud.php` | **Nuevo** |
| `src/TtsHealthHandler.php` | **Nuevo** |

## Requisitos en el Hub (.env)

```env
GOOGLE_APPLICATION_CREDENTIALS=/ruta/absoluta/google-key.json
# o GOOGLE_APPLICATION_CREDENTIALS_JSON={"type":"service_account",...}

OPENAI_API_KEY=sk-...   # fallback opcional
XABIA_DB_DSN=...
```

En Google Cloud Console, activar **Cloud Text-to-Speech API** en el mismo proyecto del JSON.

## Verificación tras desplegar

### 1. Health (sin auth)

```bash
curl -sS 'https://xabia.ai/api/xabia/v1/tts/health' | jq .
```

Respuesta esperada:

```json
{
  "ok": true,
  "service": "xabia-hub-tts",
  "route_synthesize": "/xabia/v1/tts/synthesize",
  "google_credentials": {
    "ready": true,
    "source": "GOOGLE_APPLICATION_CREDENTIALS",
    "project_id": "tu-proyecto-gcp",
    "path_readable": true
  }
}
```

Si `google_credentials.ready` es `false`, el TTS fallará hasta corregir credenciales.

### 2. Synthesize (con firma de licencia)

El cliente WordPress firma automáticamente. Para probar manualmente, usar la misma licencia y algoritmo HMAC que el plugin (`X-Xabia-License`, `X-Xabia-Source`, `X-Xabia-Timestamp`, `X-Xabia-Signature`).

### 3. Logs del Hub

En `error_log` del servidor PHP:

- `[xabia-tts]` — flujo TTS
- `[xabia-hub-auth]` — rechazos HMAC/licencia/dominio
- `[xabia-tts-google]` — errores Google Cloud TTS

Errores JSON al cliente incluyen `error.code` descriptivo (`invalid_proxy_signature`, `google_http_403`, etc.) y en 503 un bloque `upstream` + `diagnostics`.

## Empaquetado local

Desde la raíz del repo:

```bash
./hub-real/central-api/scripts/build-tts-patch.sh
```

Genera `hub-real/central-api/dist/xabia-hub-tts-patch.zip` listo para subir.

## Orden de despliegue

1. **Hub** — subir parche central-api y verificar `/tts/health`
2. **Cliente** — subir Core **1.0.303** (`xabia-agent-core-1.0.303.zip`)
3. Probar voz en test.ondareabizkaia.eus → Network → `xabia_tts` debe ser **200**
4. Si falla, revisar `wp-content/debug.log` en Hostinger (`Xabia Hub TTS Error [...]`)
