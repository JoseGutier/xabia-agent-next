=== Xabia Agent Core ===
Contributors: digixop
Donate link: https://xabia.ai
Tags: chatbot, ai, gemini, virtual assistant, rag, wordpress
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.202
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Agente de IA conversacional para WordPress: chat nativo, RAG, Smart QR, Wallet y addons (Woo, MEC, Avirato).

== Description ==

**Xabia Agent Core** es el plugin premium de Xabia AI para WordPress. Incluye interfaz de chat nativa, avatar cinético, modo parlante, shortcodes, sincronización de conocimiento, búsqueda vectorial, Smart QR / tótems y cartera de tokens vía Xabia Cloud.

Documentación: https://xabia.ai/docs/

== Installation ==

1. Suba el ZIP desde Plugins → Añadir nuevo → Subir plugin.
2. Active **Xabia Agent Core**.
3. Configure **Conexión a la IA** (licencia + Xabia Cloud).
4. Cree un agente, sincronice datos y publique con shortcode o modo nativo.

== Changelog ==

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

