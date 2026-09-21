=== Xabia Agent Core ===
Contributors: digixop
Donate link: https://xabia.ai
Tags: chatbot, ai, gemini, virtual assistant, rag, wordpress
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.318
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Agente de IA conversacional para WordPress: chat nativo, RAG, Smart QR, Wallet y addons (Woo, MEC, Avirato).

== Description ==

**Xabia Agent Core** es el plugin premium de Xabia AI para WordPress. Incluye interfaz de chat nativa, avatar cinético, modo parlante, shortcodes, sincronización de conocimiento, búsqueda vectorial, Smart QR / tótems y cartera de tokens vía Xabia Cloud.

Documentación: https://xabia.ai/documentacion/

== Installation ==

1. Suba el ZIP desde Plugins → Añadir nuevo → Subir plugin.
2. Active **Xabia Agent Core**.
3. Configure **Conexión a la IA** (licencia + Xabia Cloud).
4. Cree un agente, sincronice datos y publique con shortcode o modo nativo.

== Changelog ==

= 1.0.318 =
* Chat: fichas de producto/ente ([ACTION:CARD]) con layout responsive (CTA a ancho completo); imágenes de respuesta más compactas; texto del chat sin desbordar el panel.
* Woo: al ofrecer productos se inyectan fichas con compra directa ([ACTION:CART] → card); catálogo nativo agnóstico; reparación de URLs truncadas en RAG.

= 1.0.317 =
* Chat: render de [ACTION:CARD] fuera del flujo de listas markdown; Woo convierte CART inline en ficha (WC/contexto/título).

= 1.0.316 =
* Core: schema agnóstico [ACTION:CARD] (foto, título, meta, precio, CTA); Woo emite cards al recomendar productos.

= 1.0.315 =
* Hook xabia_chat_response_postprocess para addons (p. ej. Woo añade acciones de carrito).

= 1.0.314 =
* RAG: preservar/reparar URLs largas en chunks y en [ACTION:URL:].

= 1.0.313 =
* Listado nativo de catálogo agnóstico (sin hardcodes de vertical).

= 1.0.299 =
* TTS: localización EU en el navegador (fallback cuando Google devuelve 503); OpenAI TTS de respaldo; pausas en saltos de línea (respiración); evita voz española en páginas EU.

= 1.0.298 =
* TTS euskera: spellout nativo EU (ICU no soporta eu_ES); sustitución de numerales castellanos; horas en formato 19:30ean; voz Google eu-ES-Wavenet; sin fallback OpenAI en EU.

= 1.0.297 =
* Imágenes en chat: render de [Imagen disponible: URL] y URLs de imagen como img (no enlace); lightbox al clic.
* i18n chatbox: matriz EU/EN para Polylang; textos de espera, placeholder y micrófono traducidos vía wp_localize_script.
* TTS: preprocesado Intl (fechas, horas, cifras) en servidor según idioma activo antes de sintetizar.

= 1.0.296 =
* TTS multilingüe: directiva universal de formato hablado (fechas, horas, cifras) en el system prompt; clase Xabia_Voice con mapeo dinámico de locales (Polylang/WPML); data-lang en BCP-47 y voces Google por idioma.

= 1.0.290 =
* RAG léxico: unaccent (bebé→bebe), stopwords de verbos de agenda (actúa, canta…) y variantes LIKE con/sin tilde; mismo criterio en Hub.

= 1.0.289 =
* Chat UI: ocultar el avatar inmersivo por defecto (CSS crítico inline) para evitar FOUC en Elementor y al cargar la página.

= 1.0.288 =
* Chat UI: indicador «pensando» (puntitos + mensaje) en línea, en el hueco del input.

= 1.0.287 =
* RAG catálogo: Top-K híbrido vector 70%/léxico 30% (ya no solo FULLTEXT); expansión semántica universal; diversidad máx. 2 chunks/ente.

= 1.0.286 =
* RAG agenda: boost agnóstico de escenarios principales (`rules.priority_venues` + tags `[Prioridad: Alta]` / `[Tipo: Escenario Principal]`) en ranking híbrido/Hub; digest temporal prioriza sedes principales.

= 1.0.285 =
* RAG temporal: reloj de servidor, TEMPORAL_CATALOG, rescate léxico por etiquetas de día y listados compactos.

= 1.0.283 =
* Admin Playground: alt de `[ACTION:IMG:…]` pasa a «Imagen de la entidad» (agnóstico; ya no «Imagen del evento»).

= 1.0.282 =
* RAG: el Hub aplica `keyword_expansions` en la búsqueda léxica; el trim prioriza también esos sinónimos (p. ej. caballo → hípica).

= 1.0.281 =
* RAG: densifica y trocea fichas Hub antes del trim; evita substr UTF-8 inválido que vaciaba el contexto (p. ej. «caballo» → «no hay resultados»).

= 1.0.280 =
* RAG: enrutador semántico híbrido (regex fast-path + micro-LLM CATALOG|GENERAL) para intención de catálogo sin más diccionarios de dominio.

= 1.0.279 =
* RAG: «¿hacéis…?», «¿tenéis…?», «hay opciones…», «buscando…» y «me recomiendas…» disparan intención de catálogo (top-K 20).

= 1.0.278 =
* RAG léxico FULLTEXT: tokeniza frases compuestas (p. ej. «excursiones a caballo») sin pegar palabras en un solo término obligatorio.

= 1.0.277 =
* Sync: corrige fatal al sincronizar páginas web complementarias (row_language_code era private).
* Admin AJAX sync: captura Throwable para devolver JSON de error en lugar de HTTP 500 opaco.

= 1.0.276 =
* Delta Sync: content_hash pasa a SHA-256 (varchar 64) con compatibilidad MD5 legado, sin re-embeber texto idéntico.
* RAG léxico: índice FULLTEXT en content_chunk (MATCH … AGAINST BOOLEAN MODE) y fallback LIKE si el motor no soporta FULLTEXT.

= 1.0.275 =
* RAG: detector de intención de catálogo más amplio (sin vocabularios de dominio) y top-K 10–15 antes del recorte elástico.
* Ingesta: las taxonomías del chunk se indexan como KEYWORDS (nombre, slug y forma sin acentos), sin diccionarios de sinónimos.

= 1.0.211 =
* MEC: enlaces usan permalink nativo (WPML /eu/…) cuando el evento existe en el mismo WordPress.
* Admin MEC: URL base + slug explican prefijo de idioma (p. ej. base /eu + slug ekintzak).

= 1.0.210 =
* Admin MEC remoto: placeholders y textos de ayuda genéricos (sin referencias a sitios concretos).

= 1.0.209 =
* MEC remoto / RAG: con búsqueda vectorial desactivada y Xabia Cloud activo, el chat vuelve a consultar el catálogo local sincronizado.

= 1.0.208 =
* Actualizaciones: corrige que WordPress no mostraba el aviso si aún no existía el transient nativo update_plugins.
* Actualizaciones: revalidación del Hub cada 5 min cuando la versión cacheada coincide con la instalada.

= 1.0.207 =
* Actualizaciones: el comprobador funciona también en modo LITE (sin licencia PRO activa).
* Actualizaciones: fallback de slug del catálogo Hub si la carpeta del plugin difiere.

= 1.0.206 =
* Checkout Polar: enlaces «Contratar suscripción» envían domain y license_key al Hub.
* Addons: badge «Hub Polar» debajo del título (sin solapamiento en la cabecera).

= 1.0.205 =
* Retail: si Core arranca sin licencia (UI limitada), permite pegar la licencia Xabia y activar PRO sin WP-CLI.

= 1.0.202 =
* Acciones: rutas relativas en [ACTION:IMG:…] resueltas con data-images-base del chatbox.
* Amelia: fallback de reserva (evento JS → clic DOM → URL trigger → scroll) y enlace directo si hay URL configurada.
* Addon MEC: catálogo remoto usa solo [ACTION:URL:Link]; prohibido [ACTION:BOOK:ID] en nodos SQL remotos.
* Monorepo: API completa y paquetes Woo/MEC/Avirato sincronizados con producción.

= 1.0.201 =
* Chat: renderizado Markdown básico (**negrita**, listas) en mensajes del bot.
* Interfaz: rediseño stream (sin burbujas), esquinas cuadradas, controles con hover.
* Avatar parlante: modo inmersivo independiente del mute TTS.
* Shortcodes [xabia_launcher] / [xabia_avatar] con tamaños sm/md/lg/xl o píxeles.
* Preguntas de arranque (starter questions) restauradas en el chatbox.
* Corrección de empaquetado: incluye class-xabia-starter-questions.php y avatar-svg.php.

= 1.0.200 =
* Avatar parlante: immersive si speaking_avatar está activo (mute solo afecta TTS).

= 1.0.199 =
* Sync completo del paquete Core (starter-questions + api) tras ZIP roto en 1.0.198.

= 1.0.197–1.0.198 =
* Chrome del chat: layout stream, iconos, input semi-oculto al hover.

