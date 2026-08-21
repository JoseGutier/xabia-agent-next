# Config local del Hub

Coloca aquí `google-key.json` (cuenta de servicio Vertex) **solo en local/producción**.

Nunca lo subas a Git. En `.env` apunta:

```
GOOGLE_APPLICATION_CREDENTIALS=/ruta/absoluta/a/config/google-key.json
```

Alternativa: `GOOGLE_APPLICATION_CREDENTIALS_JSON` con el JSON inline (también solo en `.env`).
