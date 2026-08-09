# Xabia Agent Core

Xabia Agent Core es el plugin principal de Xabia AI para WordPress. Permite crear asistentes conversacionales conectados a datos reales del sitio, publicar chats mediante shortcode y gestionar consumo de tokens mediante una wallet SaaS.

## Funciones principales

- Creación de múltiples agentes o proyectos.
- Chat frontend adaptable al contenedor.
- Playground de pruebas en administración.
- Conexión con fuentes CSV, SQL, RAG y addons.
- **Catálogo nativo agnóstico (≥ 1.0.118):** listados y pasaporte; SQL remoto (≥ 1.0.164) añade anexo de atributos mapeados al `content_chunk`.
- **Asistente CPT por fuente (≥ 1.0.162)** y **listados breves (≥ 1.0.165)** (anexo fuera del contexto en modo lista).
- Sistema híbrido IA-Lite para respuestas de 0 tokens.
- Historial de conversación en sesión (manifiesto de listado reconstruible desde historial POST).
- Lectura en voz alta mediante navegador.
- Wallet de tokens con consumo de últimos 30 días.
- Endpoint seguro de recarga mediante HMAC.
- Firma HMAC automática contra el proxy central usando la licencia guardada.
- Compatibilidad con addons verticales como Xabia-Avirato.
- Interfaz nativa con avatar flotante o shortcode embebido sin avatar.
- Respuestas políglotas según el idioma del último mensaje del usuario.
- Addons MEC/Woo con conexión SQL remota cuando los datos viven en otro WordPress.

## Instalación

1. En WordPress, entra en **Plugins > Añadir nuevo**.
2. Pulsa **Subir plugin**.
3. Selecciona `xabia-agent-core-<versión>-retail.zip` (o el ZIP comercial que te haya entregado Xabia AI).
4. Instala y activa el plugin.
5. En el menú lateral aparecerá **Xabia Agent**.

## Activación de licencia

1. Entra en **Xabia Agent**.
2. Localiza la sección **Licencia Xabia (Modo SaaS)**.
3. Introduce la clave de licencia facilitada por Xabia AI.
4. Guarda la configuración.
5. Comprueba el saldo en **Xabia Agent > Cartera / Wallet**.

No es necesario editar `wp-config.php` en el WordPress cliente. La licencia guardada se usa automáticamente para validar el dominio, consultar saldo y firmar las peticiones al proxy central.

## Publicación del chat

Usa el shortcode:

```text
[xabia_agent id="default"]
```

Para un agente concreto:

```text
[xabia_agent id="mi-agente"]
```

Si **Mostrar en el sitio sin shortcode** está activado en Apariencia, el Core muestra avatar flotante + panel automáticamente. Si está desactivado, el shortcode muestra solo el chat embebido en la página, con halo visual y overlay de foco.

## Wallet y recargas

La pestaña **Cartera / Wallet** muestra el saldo actual, consumo de los últimos 30 días y botones de recarga. Los botones pueden enlazar a productos Polar.sh enviando los metadatos `license_id` y `client_url`.

| Producto | Precio | Tokens |
|----------|--------|--------|
| Core (primer año) | 199 € | 10.000.000 incluidos |
| Renovación Core | 69 €/año | 10.000.000 incluidos |
| Pack Starter | 29 € | 5.000.000 |
| Pack Business | 79 € | 20.000.000 |
| Pack Enterprise | 249 € | 100.000.000 |

Las llamadas de IA al hub se firman con:

```text
HMAC-SHA256(license_key + source_url + timestamp + body, license_key)
```

El servidor central busca la licencia en `xabia_licenses` y usa esa misma clave para validar la firma.

## Documentación

- Manual de usuario (embebido legacy): `docs/manual-usuario.md` — preferir el modular.
- Manual Core modular (**v1.0.168**): [manual-usuario-xabia-core.md](../../documentation/manual-usuario-xabia-core.md)
- Guía de desarrollo: [DESARROLLO.md](../../documentation/DESARROLLO.md)
- Despliegue producción: [DESPLIEGUE_PRODUCCION_CORE.md](../../documentation/DESPLIEGUE_PRODUCCION_CORE.md)

## Autor

Xabia AI

## Changelog (interno)

### 1.0.168
- Latencia chat: caché respuesta pre-router, caché embeddings consulta, router cache sin `SHOW TABLES`, frontend sin typewriter.
- Vertex local: auxiliares LLM (`classify_route_with_mini`, `maybe_summarize_history`) vía `call_auxiliary_llm`.
- Document-to-RAG: motor agnóstico + adaptadores DB (WP/PDO).

### 1.0.166
- Documentación de producto alineada; anexo/listados agnósticos; sidebar admin sin sticky.

### 1.0.164–1.0.165
- Anexo dinámico de atributos mapeados en pasaporte remoto (`content_chunk`).
- Sin fallback `get_post_meta` en fuentes remotas.
- Modo lista: strip del anexo; utilidad/contacto → modo desarrollo.

### 1.0.162–1.0.163
- Asistente CPT aislado por fuente (SQL remoto / local / multi / addon).
- MEC/Woo: remoto por host SQL; deep schema con metas `_` (Woo) y plazas MEC.

### 1.0.138
- **Arquitectura:** Pipeline conversacional basado en meta-esquemas semánticos; navegación delegada al LLM. Purga de código fantasma de listados procedimentales.
- **Privacidad:** Muro PII Shield en el inyector semántico; ofuscación de PII antes del envío a APIs externas.
- **Admin:** Campo «Descripción semántica de la fuente de datos» (`rules.context_source_description`) en ajustes del agente.

### 1.0.137
- **Fix:** Refactorización del enrutamiento de catálogo negativo mediante abstracción por filtros extensibles y optimización de manifiestos en dispositivos móviles.
