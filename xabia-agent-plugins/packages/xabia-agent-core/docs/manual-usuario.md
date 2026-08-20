# Manual de usuario — Xabia Agent

> **Nota (agosto 2026):** este archivo es el **manual legacy voluminoso**. La guía de producto actualizada es el **manual modular Core v1.0.242**: [manual-usuario-xabia-core.md](./manual-usuario-xabia-core.md) (PDF/HTML en [xabia.ai/docs/](https://xabia.ai/docs/manual-usuario-xabia-core.pdf)). Use este documento solo como referencia histórica o material de redacción.

**Versión del manual:** 2.0 (legacy)  
**Producto actual:** Xabia Agent Core **v1.0.242**

---

## Guía rápida de instalación

1. Instala el ZIP de **Xabia Agent Core** desde **Plugins → Añadir nuevo → Subir plugin**.
2. Activa el plugin y abre **Xabia Agent**.
3. Configura licencia y modo de IA en **Conexión a la IA**.
4. Crea un agente, define saludo, instrucciones y fuente de datos.
5. Pulsa **Conectar / Mapear** si usas SQL, CSV o addon; después **Sincronizar datos** y **Entrenar IA** si usas vectores.
6. Publica con `[xabia_agent id="ID_DEL_AGENTE"]` o activa la interfaz nativa desde **Apariencia → Mostrar en el sitio sin shortcode**.
7. Prueba en **Playground** y en una página real.

Desde Core v1.0.57, el modo nativo muestra **avatar + panel** automáticamente; el modo shortcode muestra **solo el chat embebido**, con halo luminoso y overlay de foco. La IA responde de forma políglota en el idioma del último mensaje del usuario.

---

## 1. Introducción

### 1.1 Qué es Xabia Agent

Xabia Agent es un sistema de asistentes conversacionales para WordPress. Permite crear uno o varios agentes de IA dentro de un mismo sitio, conectarlos a datos reales del proyecto y publicarlos mediante shortcode en cualquier página.

El objetivo no es solo "poner un chatbot". Xabia Agent está diseñado para convertir conocimiento, productos, reservas, alojamientos, eventos o servicios en una conversación útil, controlada y medible.

El sistema combina tres capas:

- **Core:** el plugin principal. Gestiona agentes, diseño del chat, fuentes de datos, RAG, consumo, wallet, logs, licencias y frontend.
- **Addons:** módulos especializados para verticales concretos, como Avirato, WooCommerce, QR, MEC, Amelia o conectores futuros.
- **Central SaaS:** capa opcional en `xabia.ai` para licencias, wallet, proxy de IA, recargas y operación comercial.

### 1.2 Qué problemas resuelve

Xabia Agent resuelve problemas frecuentes en webs con información cambiante:

- Clientes que preguntan por productos, disponibilidad, precios o servicios.
- Sitios donde la información está repartida entre PDFs, CSVs, bases de datos y plugins.
- Negocios que necesitan responder rápido sin depender de formularios lentos.
- Proyectos que quieren IA, pero con control de coste y privacidad.
- Equipos que no quieren que el asistente invente datos.
- Alojamientos que necesitan consultar disponibilidad real antes de sugerir una reserva.

### 1.3 Ventajas principales

Las ventajas del sistema son:

- **Integración directa en WordPress.** Se instala como plugin y se publica con shortcode.
- **Varios agentes por web.** Cada proyecto puede tener datos, tono, idioma y configuración propia.
- **RAG configurable.** El bot puede responder usando documentos, CSV, SQL o datos mapeados.
- **Sistema híbrido IA-Lite.** Muchas respuestas se resuelven en PHP antes de llamar a la IA.
- **Menor consumo de tokens.** Las respuestas de plantilla, disponibilidad estructurada o acciones directas pueden costar 0 tokens.
- **Wallet SaaS.** El saldo de tokens se controla desde una cartera y se puede recargar.
- **Addons verticales.** Avirato, WooCommerce, eventos, QR y otros módulos se pueden añadir sin inflar el Core.
- **Diseño adaptable.** El chat puede ocupar el 100% del contenedor, tiene input expandible, efecto de escritura y marca "Powered by Xabia AI".
- **Voz del navegador.** Lectura en alto sin consumo adicional de IA.

---

## 2. Conceptos Básicos

### 2.1 Agente o proyecto

Un **agente** es una configuración independiente dentro de Xabia Agent. También se le llama **proyecto**.

Cada agente puede tener:

- Un ID interno.
- Un nombre visible.
- Un nombre de asistente.
- Un saludo.
- Un idioma.
- Un tono.
- Fuentes de datos propias.
- Reglas de respuesta.
- Diseño propio.
- Historial y consumo asociado.

Ejemplos:

- `demo-rural`: asistente de alojamientos rurales.
- `catalogo`: asistente comercial para productos.
- `museo`: asistente cultural para una exposición.
- `soporte`: asistente para preguntas frecuentes.

### 2.2 Core

El **Core** es el plugin principal. Sin el Core no hay sistema.

El Core gestiona:

- Panel de administración.
- Creación de agentes.
- Chat frontend.
- Playground de pruebas.
- AJAX de conversación.
- RAG.
- Historial de conversación.
- Consumo de tokens.
- Wallet.
- Licencia.
- Endpoints de recarga.
- Shortcodes.
- Carga de addons.

### 2.3 Addon

Un **addon** es un plugin o módulo especializado que amplía el Core.

Ejemplos:

- **Xabia-Avirato:** disponibilidad y reservas en Avirato.
- **Xabia-Woo:** catálogo y acciones WooCommerce.
- **Smart QR / tótems:** incluido en el **Core** (pestaña del agente, generador QR, `/xabia-box/`).
- **Xabia-MEC:** eventos.
- **Xabia-Amelia:** reservas de servicios.

La idea es mantener el Core limpio y añadir funciones según el tipo de cliente.

### 2.4 Fuente de conocimiento

Una fuente de conocimiento es cualquier origen de datos que el agente puede usar para responder.

Puede ser:

- CSV.
- PDF.
- Texto estructurado.
- Base de datos SQL.
- WooCommerce.
- Avirato.
- Plugins de eventos.
- Entidades mapeadas.
- Datos preparados por un addon.

### 2.5 RAG

RAG significa **Retrieval-Augmented Generation**. Es decir: antes de llamar a la IA, el sistema busca fragmentos relevantes en la base de conocimiento y los añade al contexto.

Sirve para que la IA responda usando información del negocio, no solo conocimiento general.

Ejemplo:

Usuario:

```text
¿Qué productos tenéis para regalar a una persona que le gusta el vino?
```

El sistema puede buscar en el catálogo productos con categoría vino, regalo, experiencia o pack, y pasar solo esos datos a la IA.

### 2.6 Acción determinística

Una acción determinística es una respuesta que el sistema puede resolver sin llamar al modelo de IA.

Ejemplos:

- "Reservar" cuando ya existe una URL de reserva en sesión.
- "Sigue" cuando la última respuesta fue truncada.
- Mostrar disponibilidad de Avirato con plantilla PHP.
- Devolver un enlace ya calculado.
- Listar casas libres obtenidas del motor de reservas.

Estas respuestas son más rápidas, más fiables y pueden consumir 0 tokens.

### 2.7 Tokens

Los tokens son unidades de texto que usan los modelos de IA para procesar entrada y salida.

En general:

- Más texto enviado a la IA = más tokens de entrada.
- Respuestas más largas = más tokens de salida.
- Más tokens = más coste.

Xabia Agent registra el consumo en `xabia_usage_log`.

### 2.8 Wallet

La **wallet** es la cartera de tokens del cliente.

Permite:

- Ver saldo actual.
- Ver consumo de los últimos 30 días.
- Recargar packs.
- Mostrar alertas cuando el saldo baja.
- Descontar tokens automáticamente cuando se usa IA.

Las respuestas de 0 tokens no descuentan saldo.

### 2.9 Licencia

La licencia identifica al cliente o instalación. Se usa para:

- Validar acceso al sistema SaaS.
- Asociar saldo de wallet.
- Recibir recargas desde `xabia.ai`.
- Pasar el ID de licencia al checkout.
- Firmar automáticamente las peticiones al proxy central.

El cliente solo debe pegar su licencia en el dashboard de WordPress. No necesita editar `wp-config.php`: la licencia guardada se usa como secreto HMAC dinámico para las llamadas al hub.

---

## 3. Instalación del Core

### 3.1 Requisitos previos

Antes de instalar, conviene tener:

- WordPress operativo.
- Permisos de administrador.
- PHP compatible con sesiones.
- Acceso a instalar plugins ZIP.
- Una clave de IA o licencia/proxy configurado.
- Datos preparados si se va a entrenar conocimiento.

### 3.2 Instalación desde ZIP

1. En WordPress, entra en **Plugins > Añadir nuevo**.
2. Pulsa **Subir plugin**.
3. Selecciona el paquete del Core (por ejemplo `xabia-agent-core-1.0.57-retail.zip` generado con `scripts/build-retail-plugin-zips.sh`, o el ZIP comercial que te haya entregado Xabia AI).
4. Pulsa **Instalar ahora**.
5. Activa el plugin.
6. Comprueba que aparece el menú **Xabia Agent**.

### 3.3 Activación de tablas

Al activarse, el Core crea o actualiza tablas como:

- `xabia_knowledge_vectors`
- `xabia_logs`
- `xabia_usage_log`
- `xabia_wallets`
- `xabia_recharge_history`
- `xabia_response_cache`
- `xabia_embeddings`
- `xabia_discovery_blocks`

El sistema actual usa nombres sin prefijo `wp_` por defecto.

### 3.4 Configuración inicial

Después de activar:

1. Entra en **Xabia Agent**.
2. Configura la licencia o claves globales.
3. Crea un primer agente.
4. Define saludo, diseño y fuente de datos.
5. Prueba en el Playground.
6. Inserta el shortcode en una página.

---

## 4. Panel de Administración

### 4.1 Vista general

El panel de Xabia Agent agrupa:

- Listado de agentes.
- Edición de cada agente.
- Playground.
- Addons.
- Cartera / Wallet.
- Configuración de licencias y claves.

### 4.2 Listado de agentes

El listado permite:

- Crear un agente nuevo.
- Editar agentes existentes.
- Ver configuración básica.
- Acceder al Playground.

Recomendación: usa IDs cortos y claros.

Ejemplos:

```text
demo-rural
catalogo
soporte
museo
restaurante
```

### 4.3 Pantalla de edición

En la edición de un agente se configuran:

- Datos.
- Diseño.
- Reglas.
- Fuentes.
- Voz.
- Límites.
- Comportamiento.

### 4.4 Playground

El Playground permite probar el agente desde el admin sin publicarlo en una página.

Sirve para:

- Revisar el saludo.
- Probar prompts.
- Comprobar memoria.
- Validar respuestas.
- Detectar truncamientos.
- Ver si el agente usa bien el nombre configurado.

### 4.5 Addons

La sección Addons muestra módulos instalados o disponibles.

Un addon puede añadir:

- Fuentes de datos.
- Rutas de acción.
- Respuestas determinísticas.
- Integraciones con plugins.
- Campos de configuración.

### 4.6 Cartera / Wallet

La pestaña **Cartera / Wallet** muestra:

- Saldo actual.
- Consumo de los últimos 30 días.
- Barra de progreso.
- Packs de recarga.
- Enlaces al checkout externo.

---

## 5. Creación de un Agente

### 5.1 Paso 1: crear agente

1. Entra en **Xabia Agent**.
2. Pulsa **Nuevo agente**.
3. Asigna un nombre.
4. Define un ID.
5. Guarda.

Ejemplo:

```text
Nombre: Laura
ID: demo-rural
```

### 5.2 Paso 2: definir identidad

Configura:

- Nombre visible del bot.
- Saludo.
- Tono.
- Idioma.

Ejemplo:

```text
Nombre del asistente: Laura
Saludo: Kaixo, soy Laura, tu asistente de Casa Ejemplo. ¿En qué puedo ayudarte?
Tono: cercano, claro, natural, orientado a reservas.
```

### 5.3 Paso 3: configurar reglas

Las reglas ayudan a controlar la respuesta.

Recomendaciones:

- Evitar respuestas demasiado largas.
- No inventar datos.
- Pedir fechas si son necesarias.
- Usar enlaces de acción cuando existan.
- Responder en el idioma del usuario.
- Mantener tono coherente con la marca.

### 5.4 Paso 4: configurar fuente de datos

Según el caso:

- CSV para catálogos.
- SQL para bases de datos.
- Addon para integraciones.
- RAG para documentos.
- Respuesta determinística para disponibilidad o acciones.

### 5.5 Paso 5: probar

Prueba preguntas reales:

```text
¿Tenéis disponibilidad para el puente de mayo?
```

```text
¿Qué producto me recomiendas para regalar?
```

```text
¿Cuál es la diferencia entre el pack Starter y Business?
```

### 5.6 Paso 6: publicar

Inserta el shortcode en una página:

```text
[xabia_agent id="demo-rural"]
```

---

## 6. Shortcodes y Uso en WordPress

### 6.1 Shortcode básico

```text
[xabia_agent id="default"]
```

### 6.2 Shortcode por proyecto

```text
[xabia_agent id="catalogo"]
```

### 6.3 Shortcode con idioma

```text
[xabia_agent id="demo-rural" lang="es"]
```

```text
[xabia_agent id="demo-rural" lang="eu"]
```

El atributo `lang` define textos de interfaz, voz del navegador y fallback. No obliga a la IA a responder siempre en ese idioma: desde v1.0.57 Xabia responde en el idioma del último mensaje del usuario y traduce internamente el contexto RAG si hace falta.

### 6.4 Shortcode con scope

El scope limita el contexto de la conversación.

```text
[xabia_agent id="museo" scope="sala-1"]
```

### 6.5 Shortcode con ente

El ente permite anclar el chat a una entidad concreta.

```text
[xabia_agent id="catalogo" ente="producto-123"]
```

Ejemplo de uso:

- Página de un producto.
- Ficha de alojamiento.
- Punto de interés.
- Sala de museo.

### 6.6 Shortcode modo tótem

```text
[xabia_agent id="museo" totem="5"]
```

Esto puede reiniciar la conversación tras 5 minutos de inactividad.

---

## 7. Diseño del Chatbot

### 7.1 Interfaz nativa y shortcode — Core v1.0.57

En **editar agente → pestaña Apariencia**, sección **Interfaz del chat (avatar y panel)**. En el **listado**, botón **Pausar** / **Activar**.

Hay dos modos:

- **Nativo:** con **Mostrar en el sitio sin shortcode** activado, se inyecta avatar flotante + panel en el sitio.
- **Shortcode:** con esa opción desactivada, `[xabia_agent id="…"]` pinta solo el chat embebido, sin avatar flotante. Las reglas de páginas incluidas/excluidas aplican al modo nativo.

**Persistencia:** `xabia_projects_config[project_id]['interface']` y `paused`. Campos del formulario (nombres POST): `xabia_trigger_type`, colores avatar, posición, layout, exclusiones — guardados vía `Xabia_Interface::build_config_from_post()`.

| Clave en `interface` | Descripción |
|--------------------|-------------|
| `trigger_type` | `native_avatar` (avatar SVG inline oficial 125×125) o `custom_image`. |
| `avatar_colors` | `bg`, `shadow`, `dots` (cabeza, cuencas y ojos del avatar nativo). |
| `trigger_position` | `bottom_right`, `bottom_left`, `custom` (+ márgenes). |
| `panel_layout` | `right_float`, `left_float`, `centered_modal`, `full_screen`. |
| `autoload_without_shortcode` | `1` = modo nativo automático; `0` = solo shortcode. |
| `include_ids` / `exclude_*` | Páginas incluidas/excluidas, post types, Woo cart/checkout para modo nativo. |
| `paused` | `1` = agente oculto (shortcode vacío, sin disparador). |

**Frontend:** `wp_footer` + `xabia-interface.js` renderizan el disparador solo en modo nativo; el shortcode no registra por sí mismo el avatar. `chatbox.js` gestiona el foco del chat embebido con `#xabia-shortcode-focus-overlay`, cierre por clic fuera/Escape/botón móvil y halo en `styles.css`. Manual PDF: https://xabia.ai/docs/manual-usuario-xabia-core.pdf

**Filtros:** `xabia_interface_force_hide`, `xabia_interface_should_render`.

### 7.2 Personalización visual (pestaña Personalidad del agente)

El chat soporta:

- Color principal.
- Color de fondo.
- Tamaño de fuente.
- Nombre del bot (texto en mensajes).
- Saludo inicial.
- Voz.

### 7.3 Input expandible

El campo de entrada crece cuando el usuario escribe varias líneas.

Ventaja:

- El usuario puede revisar lo que escribe.
- Mejora en consultas largas.
- Evita errores en formularios estrechos.

### 7.4 Efecto de escritura

El bot puede mostrar la respuesta progresivamente, de forma similar a una conversación moderna.

Ventajas:

- Sensación más natural.
- Menor brusquedad visual.
- Mejor percepción de respuesta.

### 7.5 Altura y anchura 100%

El chat puede ocupar el 100% del contenedor donde se inserta.

Esto permite:

- Chats embebidos en columnas.
- Landings con asistente a pantalla completa.
- Modo tótem.
- Interfaces de soporte.

### 7.6 Powered by Xabia AI

El pie del chat incluye:

```text
Powered by Xabia AI
```

con enlace a:

```text
https://xabia.ai
```

### 7.7 Voz

La voz usa la API de síntesis del navegador. No consume tokens.

Se puede seleccionar:

- Por defecto.
- Femenina.
- Masculina.

En castellano, el sistema intenta priorizar voces `es-ES` y evitar voces latinoamericanas si hay una voz española disponible.

Limitación: la calidad depende del navegador y del sistema operativo.

Los botones de voz, micrófono y envío usan iconos SVG embebidos. Cuando un botón queda activo o resaltado con fondo azul/rojo, el icono pasa a blanco para mantener contraste.

---

## 8. Fuentes de Conocimiento

### 8.1 CSV

El CSV es ideal para:

- Catálogos.
- Tarifas.
- Directorios.
- FAQs.
- Fichas de producto.
- Listados de servicios.

Ejemplo de CSV para catálogo:

```csv
id,nombre,categoria,descripcion,precio,url,imagen,stock
101,Pack Enoturismo,Experiencias,Visita a bodega con cata,49,https://ejemplo.com/pack-enoturismo,2026/imagenes/pack.jpg,disponible
102,Cesta Gourmet,Regalos,Cesta con productos locales,79,https://ejemplo.com/cesta,2026/imagenes/cesta.jpg,disponible
```

### 8.2 PDFs

Los PDFs sirven para:

- Manuales.
- Dossiers.
- Reglamentos.
- Guías turísticas.
- Documentación técnica.

Recomendación: usar PDFs limpios, no escaneados como imagen.

### 8.3 SQL

La conexión SQL permite consultar datos en tablas.

Sirve para:

- Catálogos vivos.
- Directorios.
- Registros propios.
- Datos de plugins.

Para un WordPress remoto con **Modern Events Calendar**, el Core incluye el botón **Usar preset MEC remoto** dentro de **Base de Datos Externa (SQL Remoto)**. Ese preset rellena consulta y mapeo estándar de eventos. Si tiene instalado **Xabia MEC**, es preferible usar **Fuente → Addon nativo → MEC** con credenciales SQL remotas, porque conserva las reglas del addon.

### 8.4 Addons

Los addons pueden obtener datos desde sistemas externos y generar respuestas más fiables que un RAG genérico.

Ejemplo:

- Avirato no debe depender de un PDF para disponibilidad.
- Debe consultar el motor de reservas y responder con datos reales.

Woo y MEC también pueden leer desde una base remota manteniendo `source_type = addon`: el addon aporta reglas y acciones, y las credenciales SQL deciden dónde se leen los datos.

### 8.5 Entes

Los entes son elementos identificables:

- Producto.
- Casa.
- Alojamiento.
- Evento.
- Punto de interés.
- Servicio.

Permiten que el bot entienda cuando el usuario pregunta por algo concreto.

---

## 9. Sistema Híbrido IA-Lite

### 9.1 Qué es IA-Lite

IA-Lite es la capa que procesa ciertos mensajes en PHP antes de llamar a la IA.

Su objetivo:

- Ahorrar tokens.
- Reducir latencia.
- Evitar errores de interpretación.
- Mejorar privacidad.
- Responder con datos estructurados cuando ya los tenemos.

### 9.2 Ejemplos de respuestas 0 tokens

No consumen IA:

- Plantillas de disponibilidad de Avirato.
- "Reservar" cuando hay URL en sesión.
- "Sigue" si se detecta truncamiento.
- Respuestas con enlaces ya calculados.
- Mensajes de casa ocupada + alternativas cuando se obtienen del scraper.

### 9.3 Cuándo sí se usa IA

Se usa IA cuando:

- La pregunta es abierta.
- Hay que redactar una explicación.
- Hay que combinar varias fuentes.
- El usuario pide recomendación.
- No hay una respuesta estructurada fiable.

### 9.4 Ventaja comercial

La IA-Lite permite vender un sistema más eficiente:

- Menor coste por conversación.
- Mejor control.
- Más velocidad.
- Menos riesgo de alucinación.

---

## 10. Wallet, Tokens y Consumo

### 10.1 Dónde se ve el saldo

En WordPress:

```text
Xabia Agent > Cartera / Wallet
```

La pantalla muestra:

- Saldo actual.
- Consumo últimos 30 días.
- Packs de recarga.
- ID de licencia.

### 10.2 Cómo se descuenta

Cuando se registra una fila en `xabia_usage_log`, el Core descuenta:

```text
tokens_input + tokens_output
```

Cuando la petición pasa por el proxy central de `xabia.ai`, el descuento se realiza en la base de datos central (`xabia_wallets`) usando el conteo real devuelto por Google Vertex. La respuesta devuelve el nuevo `tokens_remaining` y el plugin sincroniza su saldo local.

También guarda:

```text
tokens_count
```

### 10.3 Respuestas de 0 tokens

Si la respuesta la genera PHP con plantilla y no se llama al modelo, no se genera consumo de IA y no se descuenta saldo.

### 10.4 Precios oficiales Xabia AI

La licencia Xabia Agent Core tiene un coste de 199€ el primer año, que incluye el software completo y un saldo inicial de 10.000.000 tokens. La licencia permite crear agentes ilimitados dentro de un único dominio autorizado. A partir del segundo año, la suscripción se renueva por 69€/año, incluyendo una recarga anual de otros 10.000.000 tokens. Los Add-ons especializados tienen un coste de 49€/año (Woo: 69€/año).

| Producto | Precio | Incluye |
| --- | ---: | --- |
| Licencia Core | 199€ el primer año | Software completo y 10.000.000 tokens iniciales. |
| Renovación Anual | 69€/año | Mantenimiento, actualizaciones y 10.000.000 tokens de cortesía cada año. |
| Add-on Avirato | 49€/año | Activa la consulta de disponibilidad Avirato. No añade tokens. |
| Pack Starter | 29€ | Recarga de 5.000.000 tokens. |
| Pack Business | 79€ | Recarga de 20.000.000 tokens. |
| Pack Enterprise | 249€ | Recarga de 100.000.000 tokens. |

Los tokens no caducan nunca, aunque venza la licencia. Si la licencia no está activa, no se podrán realizar nuevas recargas hasta renovarla.

Polar.sh debe enviar `metadata.product_type` con uno de estos valores: `core_initial`, `core_renewal`, `addon_avirato`, `pack_s`, `pack_m` o `pack_l`. La renovación debe incluir `metadata[license_key]` para extender la licencia existente, y las recargas deben incluir la licencia y `metadata[client_url]`.

### 10.5 Recharge Bridge

El Core expone:

```text
POST /wp-json/xabia/v1/wallet/recharge
```

Parámetros:

```json
{
  "license_key": "xabia-demo-xxxx",
  "token_amount": 20000000,
  "timestamp": 1770000000,
  "signature": "..."
}
```

Firma:

```php
hash_hmac('sha256', $license_key . $token_amount . $timestamp, $license_key)
```

Si la firma es válida:

- Suma tokens a `xabia_wallets`.
- Registra en `xabia_recharge_history`.
- Devuelve el nuevo saldo.

### 10.6 Aviso de saldo bajo

Si el saldo baja de 50.000 tokens, aparece un aviso:

```text
¡Atención! Tu saldo de tokens está bajo. Recarga para no perder reservas.
```

---

## 11. Ejemplos de Agentes / Proyectos

### 11.1 Agente para catálogo de productos

Objetivo: ayudar a encontrar productos, comparar opciones y llevar al usuario a comprar.

Configuración sugerida:

```text
ID: catalogo
Nombre del bot: Xabi
Saludo: Hola, soy Xabi. Puedo ayudarte a encontrar el producto que necesitas.
Fuente: CSV o WooCommerce
Tono: comercial, claro, no invasivo
```

Prompt recomendado:

```text
Eres un asistente comercial. Ayudas al usuario a encontrar productos del catálogo.
Responde solo con productos presentes en los datos disponibles.
Si no hay stock o no hay información suficiente, dilo claramente.
Cuando recomiendes un producto, explica brevemente por qué encaja.
Si existe URL de compra, ofrece el enlace.
No inventes precios, descuentos ni disponibilidad.
```

Preguntas de prueba:

```text
Busco un regalo de menos de 80 euros.
```

```text
¿Qué productos tenéis para una persona que le gusta la gastronomía local?
```

```text
Compárame la cesta gourmet y el pack enoturismo.
```

### 11.2 Agente para hotel o alojamiento

Objetivo: resolver dudas y convertir a reserva.

Configuración sugerida:

```text
ID: reservas
Nombre del bot: Laura
Fuente: Addon Avirato + información general del alojamiento
Tono: cercano, útil, orientado a reserva
```

Prompt recomendado:

```text
Eres el asistente del alojamiento. Ayudas a encontrar disponibilidad, resolver dudas y facilitar la reserva.
Si el sistema proporciona disponibilidad estructurada, úsala como fuente de verdad.
No inventes disponibilidad ni precios.
Si faltan fechas, pídelas.
Si una casa está ocupada, ofrece alternativas disponibles.
```

### 11.3 Agente para museo o tótem cultural

Objetivo: explicar contenidos por sala, obra o punto de interés.

Configuración:

```text
ID: museo
Nombre del bot: Gida
Shortcode: [xabia_agent id="museo" totem="5"]
```

Prompt:

```text
Eres un guía cultural. Explicas de forma clara y accesible.
Adapta la respuesta a familias, visitantes generales y público no experto.
No inventes datos históricos.
Si el usuario pregunta por una obra concreta, usa solo la información asociada a ese ente.
```

### 11.4 Agente para restaurante

Objetivo: ayudar con carta, alérgenos, reservas y recomendaciones.

Prompt:

```text
Eres el asistente del restaurante.
Ayudas a elegir platos según gustos, alergias y presupuesto.
No inventes ingredientes ni alérgenos.
Si el usuario menciona alergia, responde con prudencia y recomienda confirmar con el equipo.
```

### 11.5 Agente para soporte técnico

Objetivo: reducir tickets repetitivos.

Prompt:

```text
Eres un asistente de soporte.
Responde paso a paso.
Si la consulta requiere acceso privado o datos personales, indica que debe contactar con soporte.
No solicites contraseñas.
```

---

## 12. Ejemplo Completo: Catálogo de Productos

### 12.1 Objetivo

Crear un agente que ayude al usuario a explorar un catálogo y llegar a un producto concreto.

### 12.2 CSV recomendado

```csv
id,nombre,categoria,descripcion,precio,stock,url,imagen,tags
1,Cesta Gourmet,Regalos,Cesta con productos locales,79,disponible,https://tienda.com/cesta,2026/cesta.jpg,"regalo,gourmet,local"
2,Pack Enoturismo,Experiencias,Visita a bodega con cata,49,disponible,https://tienda.com/enoturismo,2026/vino.jpg,"vino,experiencia,pareja"
3,Curso de Cocina,Experiencias,Taller práctico de cocina vasca,120,limitado,https://tienda.com/curso,2026/curso.jpg,"cocina,taller,experiencia"
```

### 12.3 Parámetros de agente

```text
ID: catalogo
Nombre: Asistente Catálogo
Avatar name: Xabi
Idioma: es
Fuente: CSV
Campo principal: nombre
Campos de contexto: descripcion, categoria, precio, stock, tags
Campos de acción: url, imagen
```

### 12.4 Prompt del sistema

```text
Eres Xabi, asistente comercial del catálogo.
Tu objetivo es ayudar al usuario a encontrar el producto adecuado.
Usa únicamente productos presentes en el catálogo.
Si recomiendas un producto, menciona nombre, precio si está disponible y motivo.
Si el usuario indica presupuesto, respétalo.
Si no hay producto adecuado, dilo y sugiere una alternativa cercana.
No inventes stock, precios ni enlaces.
```

### 12.5 Conversaciones de ejemplo

Usuario:

```text
Quiero un regalo para una pareja por menos de 80 euros.
```

Respuesta esperada:

```text
Te recomendaría el Pack Enoturismo. Cuesta 49€ y encaja bien como experiencia para pareja.
También podría encajar la Cesta Gourmet si prefieres un regalo físico.
```

Usuario:

```text
¿Cuál tiene mejor relación calidad precio?
```

Respuesta esperada:

```text
Si buscas experiencia, el Pack Enoturismo es la opción más ajustada.
Si quieres un regalo tangible, la Cesta Gourmet tiene más presencia como detalle.
```

### 12.6 Buenas prácticas

- Mantener filas limpias.
- No mezclar muchos productos en una sola celda.
- Usar categorías consistentes.
- Añadir URLs.
- Añadir imágenes relativas a uploads cuando sea posible.
- Entrenar después de cambios importantes.

---

## 13. Ejemplos de Prompts y Configuración

### 13.1 Prompt comercial cercano

```text
Eres un asistente comercial cercano y claro.
Ayudas al usuario a elegir entre productos reales del catálogo.
No presiones. Recomienda solo si hay encaje.
Si faltan datos, pregunta una cosa cada vez.
```

### 13.2 Prompt de reservas

```text
Eres un asistente de reservas.
Tu prioridad es ayudar al usuario a encontrar disponibilidad real.
No inventes fechas ni disponibilidad.
Si el sistema proporciona una URL de reserva, úsala.
Si una opción está ocupada, ofrece alternativas disponibles.
```

### 13.3 Prompt turístico

```text
Eres un guía turístico local.
Responde con tono amable, práctico y contextual.
Recomienda planes según clima, duración, movilidad e intereses.
No inventes horarios ni precios.
```

### 13.4 Prompt técnico

```text
Eres un asistente técnico.
Responde con pasos numerados.
Si hay riesgo de pérdida de datos, advierte antes.
No pidas contraseñas ni datos sensibles.
```

### 13.5 Prompt multilingüe

```text
Responde en el mismo idioma en que escribe el usuario.
Si mezcla idiomas, prioriza el idioma principal de la pregunta.
Mantén nombres propios sin traducir.
```

---

## 14. Seguridad y Privacidad

### 14.1 Datos sensibles

No se deben subir:

- Contraseñas.
- Claves API.
- Documentos privados.
- Datos bancarios.
- Datos personales no necesarios.

### 14.2 Detección de información sensible

El Core puede marcar consultas sensibles en los logs.

Sirve para:

- Auditoría.
- Mejora de prompts.
- Prevención de usos incorrectos.

### 14.3 Licencia y proxy

El sistema puede usar una central para:

- Validar licencia.
- Gestionar wallet.
- Canalizar llamadas a IA.
- Controlar planes.

Las llamadas del plugin al proxy central se firman con la licencia guardada:

```text
HMAC-SHA256(license_key + source_url + timestamp + body, license_key)
```

El hub busca primero la licencia en `xabia_licenses` usando la clave y el dominio, y valida la firma con esa misma licencia. Así cada cliente tiene su propio secreto sin configurar constantes adicionales en su WordPress.

### 14.4 Firma HMAC en recargas

El endpoint de recarga exige firma HMAC. Esto evita que un usuario externo se añada tokens manualmente.

La central debe calcular:

```php
$signature = hash_hmac('sha256', $license_key . $token_amount . $timestamp, $license_key);
```

### 14.5 Buenas prácticas

- Proteger la licencia.
- No exponer logs públicamente.
- Usar HTTPS.
- Revisar permisos de administrador.
- Mantener plugins actualizados.

---

## 15. Addons

### 15.1 Qué es un addon

Un addon amplía el Core para un caso concreto.

Puede:

- Consultar APIs externas.
- Leer tablas de otro plugin.
- Crear respuestas de plantilla.
- Añadir botones.
- Registrar acciones.

### 15.2 Cómo se instala

1. Subir ZIP del addon.
2. Activar en WordPress.
3. Ir a Xabia Agent.
4. Configurar el addon.
5. Probar en Playground.

### 15.3 Addons actuales

Según instalación, pueden existir:

- Xabia-Avirato.
- Xabia-Woo.
- Xabia-MEC.
- Xabia-Amelia.
- Smart QR / tótems (Core integrado).
- Xabia-Federation.

### 15.4 Diferencia entre addon y RAG

RAG busca texto.

Un addon puede ejecutar lógica real.

Ejemplo:

- RAG puede explicar qué es La Terraza.
- Xabia-Avirato puede comprobar si La Terraza está disponible.

---

## 16. Tutorial Específico: Xabia-Avirato

### 16.1 Qué hace Xabia-Avirato

Xabia-Avirato conecta Xabia Agent con el motor de reservas de Avirato.

Permite:

- Consultar disponibilidad real.
- Detectar casas libres.
- Detectar casas ocupadas.
- Generar enlaces de reserva.
- Responder sin gastar tokens en consultas estructuradas.
- Proponer alternativas.
- Buscar próxima disponibilidad de una casa concreta.

### 16.2 Requisitos

Necesitas:

- Core instalado.
- Addon Xabia-Avirato instalado.
- ID de establecimiento Avirato.
- Motor de reservas accesible.
- Configuración de alojamientos/casas.
- Si se usa Avirato Calendar, tabla de establecimientos disponible.

### 16.3 Instalación

1. En WordPress, ve a **Plugins > Añadir nuevo**.
2. Sube el addon (por ejemplo `xabia-avirato-1.0.0-retail.zip`).
3. Activa el addon.
4. Entra en Xabia Agent.
5. Configura el agente que usará Avirato.

### 16.4 Configuración básica

Campos habituales:

```text
ID de establecimiento: código web de Avirato
Engine URL: https://booking.avirato.com/
Nombre público: nombre comercial del alojamiento
Filtro de inclusión: palabra que deben contener las casas válidas
Lista de exclusión: palabras o alojamientos que no deben aparecer
ID habitación: opcional si se quiere limitar manualmente
Código promocional: opcional
```

El **ID de establecimiento** pertenece a cada cliente y se guarda en el WordPress cliente. No se configura como variable global del hub central. Cuando el chat usa el proxy de `xabia.ai`, el addon Avirato adjunta automáticamente:

```json
{
  "avirato": {
    "establishment_id": "codigo_web_del_cliente",
    "room_filter": "filtro opcional"
  }
}
```

Si el usuario pregunta por disponibilidad y ese ID no llega al proxy, el sistema devuelve un error indicando que falta la configuración de Avirato.

### 16.5 Nombre público

El nombre público se usa para respuestas genéricas.

Ejemplo:

```text
Casa Ejemplo
```

Respuesta:

```text
Para esas fechas no me aparece disponibilidad en Casa Ejemplo.
```

### 16.6 Filtros

Los filtros evitan mostrar alojamientos ajenos al proyecto.

Ejemplo:

```text
Filtro inclusión: demo-rural
Exclusión: kanala, prueba, demo
```

Así el bot no debería listar casas de otros establecimientos si aparecen en datos mezclados.

### 16.7 Detección de disponibilidad real

El addon no se limita a comprobar que aparezca el nombre de una casa.

Comprueba señales como:

- `originalFreeRooms`
- `numRooms`
- `freeRooms`
- `availableRooms`
- flags positivos de disponibilidad

Si una casa aparece en el JSON pero tiene:

```text
numRooms = 0
originalFreeRooms = 0
```

se considera ocupada.

### 16.8 URLs con IDs filtrados

El enlace de reserva final solo incluye IDs de casas confirmadas como libres.

Esto evita enviar al usuario a una pantalla donde aparecen opciones ocupadas o no solicitadas.

### 16.9 Casa ocupada con alternativas

Si el usuario pregunta por una casa concreta y está ocupada:

```text
¿La Terraza está libre del 15 al 18 de mayo?
```

Respuesta esperada:

```text
La Terraza está ocupado para esas fechas.

En esas fechas tienes estas opciones disponibles:

- Casa Ejemplo — Suite del Roble

La Terraza podrías reservarlo a partir del 20-05-2026 hasta el 23-05-2026.
```

### 16.10 Próxima fecha disponible

Cuando una casa concreta está ocupada, el addon puede buscar la siguiente disponibilidad manteniendo la duración de la estancia.

Para ahorrar recursos:

- La búsqueda es acotada.
- Se cachea brevemente.
- Se puede forzar actualización.

### 16.11 Caché

La disponibilidad se cachea de forma breve:

```text
2 minutos
```

Esto evita repetir consultas si el usuario sigue preguntando en la misma conversación.

### 16.12 Bypass de caché

El usuario puede forzar consulta limpia con frases como:

```text
actualiza
mira otra vez
vuelve a mirar
refresca
comprueba otra vez
```

### 16.13 Respuestas 0 tokens

Las respuestas de disponibilidad de Avirato se generan con plantilla PHP.

Ventajas:

- No consumen tokens.
- No inventan disponibilidad.
- Son más rápidas.
- Son más auditables.

### 16.14 Logs de depuración

El addon registra por cada casa:

```text
Casa: [Nombre] | ID: [ID] | Estado: [Libre/Ocupado]
```

Ejemplo:

```text
Casa: Casa Ejemplo — Suite del Roble | ID: 57 | Estado: Libre
Casa: Casa Ejemplo — La Terraza | ID: 34 | Estado: Ocupado
```

### 16.15 Ejemplos de conversación

Usuario:

```text
Una casa para dos personas a partir del 15 de mayo, para 3 días.
```

Respuesta:

```text
He encontrado estas opciones disponibles para a partir del 15 de mayo:

- Casa Ejemplo — Suite del Roble

¿Te cuento más sobre alguna?

Puedes reservar aquí: Abrir enlace
```

Usuario:

```text
Quiero La Terraza para esas fechas.
```

Respuesta:

```text
La Terraza está ocupado para esas fechas.

En esas fechas tienes estas opciones disponibles:

- Casa Ejemplo — Suite del Roble
```

Usuario:

```text
Reservar
```

Respuesta:

```text
Perfecto. Puedes hacer la reserva aquí:

Abrir enlace
```

### 16.16 Problemas comunes

Problema:

```text
El bot dice que no hay disponibilidad, pero Avirato sí muestra casas.
```

Revisar:

- ID de establecimiento.
- Filtro de inclusión.
- Exclusiones.
- Fechas interpretadas.
- Logs de casas libres/ocupadas.

Problema:

```text
Muestra una casa que está ocupada.
```

Revisar:

- `numRooms`
- `originalFreeRooms`
- IDs en URL final
- Logs por casa

Problema:

```text
El enlace abre demasiadas casas.
```

Revisar:

- Que la URL final solo incluya IDs libres.
- Que no se use un `id_habitacion` manual demasiado amplio.

---

## 17. Flujos Prácticos con Xabia-Avirato

### 17.1 Disponibilidad general

Usuario:

```text
¿Tenéis alguna casa libre la segunda quincena de junio?
```

Flujo:

1. El Core detecta intención de disponibilidad.
2. Avirato calcula fechas.
3. El scraper consulta el motor.
4. Filtra solo casas libres.
5. Responde con plantilla.
6. No llama a la IA.

### 17.2 Casa concreta

Usuario:

```text
¿La villa «Casa del Puerto» está libre del 15 al 21 de junio?
```

Flujo:

1. El addon reconoce el nombre del alojamiento (p. ej. «Casa del Puerto»).
2. Resuelve su ID.
3. Consulta disponibilidad.
4. Si está libre, responde con enlace específico.
5. Si está ocupada, propone alternativas.

### 17.3 Fechas ambiguas

Usuario:

```text
Un fin de semana en mayo.
```

El sistema puede pedir concreción si no puede interpretar fechas suficientes.

### 17.4 Actualización

Usuario:

```text
Mira otra vez, por favor.
```

El addon ignora caché y consulta de nuevo Avirato.

### 17.5 Continuación

Usuario:

```text
sigue
```

Si la respuesta anterior fue truncada, el Core activa continuación sin tratarlo como una pregunta nueva.

---

## 18. Mantenimiento y Actualizaciones

### 18.1 Actualizar Core

1. Descargar ZIP nuevo.
2. Subir plugin.
3. Sustituir versión anterior.
4. Revisar que los agentes siguen configurados.
5. Probar Playground.

### 18.2 Actualizar addons

1. Subir ZIP del addon.
2. Sustituir versión anterior.
3. Revisar configuración.
4. Probar flujo real.

### 18.3 Revisar consumo

Entra en:

```text
Xabia Agent > Cartera / Wallet
```

Comprueba:

- Saldo.
- Consumo de 30 días.
- Necesidad de recarga.

### 18.4 Revisar logs

Los logs ayudan a detectar:

- Preguntas frecuentes.
- Fallos de datos.
- Disponibilidad mal interpretada.
- Consumo alto.
- Consultas sensibles.

### 18.5 Limpiar o regenerar datos

Cuando cambies datos:

1. Sincroniza fuente.
2. Entrena si aplica.
3. Limpia caché si es necesario.
4. Prueba en Playground.

---

## 19. Solución de Problemas

### 19.1 El bot no responde

Revisar:

- Plugin activo.
- Shortcode correcto.
- Proyecto existente.
- AJAX funcionando.
- Claves/licencia.
- Errores PHP.

### 19.2 Respuestas cortadas

Revisar:

- `max_output_tokens`.
- Botón Continuar.
- Finish reason.
- Configuración del modelo.

### 19.3 No recuerda contexto

Revisar:

- Sesiones PHP.
- Historial de últimos mensajes.
- Navegador bloqueando cookies.

### 19.4 No aparece disponibilidad

Revisar:

- Avirato activo.
- ID de establecimiento.
- Fechas.
- Filtros.
- Logs.

### 19.5 Muestra casas incorrectas

Revisar:

- Filtro de inclusión.
- Lista de exclusión.
- Mapeo de IDs.
- Datos de `avc_establishment`.

### 19.6 Voz incorrecta

Revisar:

- Preferencia femenina/masculina.
- Voces instaladas en el navegador.
- Idioma del chat.
- Sistema operativo.

### 19.7 No se descuenta saldo

Puede ser correcto si:

- La respuesta fue de plantilla PHP.
- No se llamó a IA.
- Fue una acción 0 tokens.

Si sí hubo IA, revisar:

- `xabia_usage_log`.
- `tokens_count`.
- `xabia_wallets`.

### 19.8 No se recarga saldo

Revisar:

- Endpoint REST accesible.
- Firma HMAC.
- Timestamp.
- Licencia configurada.
- Tabla `xabia_recharge_history`.

---

## 20. Anexos

### 20.1 Checklist de puesta en producción

- Core instalado.
- Licencia configurada.
- Wallet visible.
- Agente creado.
- Saludo revisado.
- Fuente configurada.
- Playground probado.
- Shortcode publicado.
- Voz probada.
- Logs revisados.
- Si usa Avirato, disponibilidad validada con casos reales.
- Recarga probada desde central o entorno de pruebas.

### 20.2 Ejemplo de payload de recarga

```json
{
  "license_key": "xabia-demo-xxxxxxxx",
  "token_amount": 20000000,
  "timestamp": 1770000000,
  "signature": "9b1b..."
}
```

### 20.3 Ejemplo de cálculo de firma

```php
<?php
$license_key = 'xabia-demo-xxxxxxxx';
$token_amount = 20000000;
$timestamp = time();
$signature = hash_hmac('sha256', $license_key . $token_amount . $timestamp, $license_key);
```

### 20.4 Shortcodes frecuentes

```text
[xabia_agent id="default"]
[xabia_agent id="demo-rural" lang="es"]
[xabia_agent id="catalogo" ente="producto-123"]
[xabia_agent id="museo" totem="5"]
```

### 20.5 Glosario

**Agente:** configuración individual del asistente.  
**Addon:** módulo especializado.  
**Core:** plugin principal.  
**Ente:** elemento identificable dentro del conocimiento.  
**IA-Lite:** capa determinística previa a la IA.  
**RAG:** búsqueda de contexto antes de responder.  
**Token:** unidad de consumo del modelo.  
**Wallet:** saldo de tokens.  
**Recharge Bridge:** endpoint seguro para recargas desde `xabia.ai`.  
**Avirato:** motor de reservas integrado mediante addon.

---

## 21. Recomendaciones Finales

Para obtener buenos resultados:

- Empieza con un agente sencillo.
- Prueba preguntas reales.
- Ajusta el prompt con ejemplos concretos.
- Usa addons cuando haya datos estructurados.
- Reserva la IA para lo que necesita redacción o razonamiento.
- Usa plantillas PHP para acciones repetibles.
- Revisa consumo y saldo.
- Mantén los datos limpios.
- No dejes que el bot invente disponibilidad, precios o stock.

La filosofía de Xabia Agent es clara: **usar IA donde aporta valor y resolver en servidor todo lo que pueda resolverse con datos estructurados, privacidad y eficiencia**.
