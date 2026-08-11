=== Xabia Agent LITE - AI Chatbot & Local Assistant ===
Contributors: digixop
Donate link: https://xabia.ai
Tags: chatbot, ai, gemini, assistant, csv, wordpress
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0-lite
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Agente de IA conversacional para WordPress: chat flotante, CSV, scraper web local y BYOK con Google Gemini (o recargas Xabia Cloud).

== Description ==

**Xabia Agent LITE** añade un asistente de chat inteligente a tu sitio WordPress. La versión gratuita (LITE) está pensada para WordPress.org:

* Widget de chatbox flotante nativo.
* Ingesta de catálogo mediante **archivo CSV**.
* **Scraper superficial** del contenido público de tu propia web (páginas y entradas).
* Conexión dual: **BYOK Google Gemini** (gratis, sin cuenta Xabia) o **recargas Xabia Cloud**.

Para sincronización automática con WooCommerce, Modern Events Calendar, Amelia, Avirato, SQL remoto, RAG vectorial, voz TTS y más, consulta **Xabia Agent PRO** en [https://xabia.ai](https://xabia.ai).

= Third-party Service Disclosure =

This plugin connects to an external AI service to generate chat responses.

**Service Name:** Google Gemini API (default provider configured in the plugin settings)

**Domain / URL:** [https://ai.google.dev](https://ai.google.dev) · API endpoint: [https://generativelanguage.googleapis.com](https://generativelanguage.googleapis.com)

**Description:** Este plugin envía las consultas del usuario y el contexto local de la web a la API de Google Gemini para generar respuestas de chat inteligente.

**Link to Terms of Service:** [https://policies.google.com/terms](https://policies.google.com/terms)

**Link to Privacy Policy:** [https://policies.google.com/privacy](https://policies.google.com/privacy)

No data is sent to Xabia servers in LITE mode unless you optionally visit xabia.ai links. The site administrator must provide and manage their own Google API credentials.

== Installation ==

1. Upload the plugin ZIP via **Plugins → Add New → Upload Plugin**, or install from the WordPress.org repository when published.
2. Activate **Xabia Agent LITE**.
3. Open **Xabia LITE** in the admin menu.
4. Elige **Opción A (BYOK Gemini)** o **Opción B (recargas Xabia Cloud)**.
5. (Opcional) Sube un **CSV** y/o pulsa **Indexar contenido de esta web**.
6. Añade el chat con el widget flotante incluido.

== Frequently Asked Questions ==

= Do I need a Xabia license for LITE? =

No. LITE uses your own Gemini API Key (BYOK). No Xabia Cloud subscription is required.

= What data is sent to Google? =

The user's chat message, recent conversation history (session), your optional CSV catalog excerpt, and basic published page context from your WordPress site may be included in the prompt sent to Gemini.

= Can I use WooCommerce or remote SQL in LITE? =

No. Those features require **Xabia Agent PRO**. The LITE admin shows preview fields marked PRO for upgrade discovery.

= Is this the same plugin as Xabia Agent PRO? =

Same codebase architecture, different build. PRO adds Hub licensing, vector RAG, addons, and advanced admin. Upgrade at [https://xabia.ai](https://xabia.ai).

= How do I show the chat on my site? =

Use the floating chat widget included with the plugin or embed the shortcode provided in the Xabia documentation once configured.

== Changelog ==

= 1.0.0-lite =
* Initial WordPress.org LITE build: CSV ingest, local site scraper, Gemini BYOK / Xabia Cloud, floating chatbox.
* Branding oficial Xabia (logo e icono X + puntos) en panel y menú admin.
* PRO upsell UI for appearance, PDF/DOCX ingest, and addon integrations.
* Third-party service disclosure for Google Gemini API.
