# Manual de usuario — Xabia Agent Core

> **Versión del producto:** Xabia Agent Core **v1.0.214** (agosto 2026)  
> **Índice de manuales:** [https://xabia.ai/docs/](https://xabia.ai/docs/)  
> **PDF en línea:** [https://xabia.ai/docs/manual-usuario-xabia-core.pdf](https://xabia.ai/docs/manual-usuario-xabia-core.pdf)  
> **HTML en línea:** [https://xabia.ai/docs/manual-usuario-xabia-core.html](https://xabia.ai/docs/manual-usuario-xabia-core.html)

## Guía rápida de instalación

1. Descargue el ZIP de **Xabia Agent Core** (`xabia-agent-core-1.0.214.zip` o paquete retail equivalente).
2. En WordPress, vaya a **Plugins → Añadir nuevo → Subir plugin**, seleccione el ZIP y pulse **Instalar ahora → Activar**.
3. Abra **Xabia Agent** y configure **Conexión a la IA**: pegue la licencia `XABIA--…`, elija **Xabia Cloud** (recomendado) o **Infraestructura propia**, y guarde.
4. Cree un agente desde **Nuevo agente**, escriba nombre, saludo e instrucciones básicas.
5. Elija una **fuente de información** (CSV, SQL, addon o multi-fuente), pulse **Conectar / Mapear** cuando proceda y guarde el agente.
6. Pulse **Sincronizar datos** y, si usa búsqueda vectorial, **Entrenar IA**.
7. Publique el agente con el shortcode `[xabia_agent id="ID_DEL_AGENTE"]` o active la interfaz nativa desde **Apariencia → Mostrar en el sitio sin shortcode**.
8. Pruebe en **Playground** y en una página real antes de abrirlo al público.

**Actualización desde una versión anterior:** suba el nuevo ZIP desde **Plugins**, sustituya la versión instalada y conserve la configuración existente. Si usa `/xabia-box/`, visite **Ajustes → Enlaces permanentes → Guardar** tras actualizar. Tras instalar **1.0.72+** con WPML, visite una página del front para que se sincronicen las traducciones de la UI del chat; vacíe caché (LiteSpeed, etc.). Con fuente **SQL remota**, tras actualizar a **1.0.164+** vuelva a **Sincronizar** (y enviar al Hub si aplica) para regenerar las fichas con el **anexo** de atributos mapeados.

---

## Guía rápida: qué hacer en cada situación

Use esta tabla como **hoja de ruta**. Cada fila indica la pantalla exacta y el orden de pasos.

| Quiero… | Dónde voy | Qué hago (en orden) |
|---------|-----------|---------------------|
| **Instalar Xabia por primera vez** | Plugins → Subir plugin | ZIP Core → Activar → **Conexión a la IA** (licencia + Cloud) → **Nuevo agente** → fuente de datos → **Sincronizar** → shortcode o modo nativo |
| **Actualizar a una versión nueva** | Plugins → Subir plugin | Subir ZIP (sustituye) → **Enlaces permanentes → Guardar** → vaciar caché del sitio → probar chat en incógnito |
| **Publicar el chat en una página concreta** | Editar agente → General | Copiar shortcode → pegar en la página → **desactivar** «Mostrar en el sitio sin shortcode» si no quiere avatar flotante |
| **Avatar flotante en todo el sitio** | Editar agente → Apariencia | **Activar** «Mostrar en el sitio sin shortcode» → elegir páginas incluidas/excluidas → **Guardar agente** |
| **Ocultar el chat sin borrarlo** | Listado de agentes | **Pausar** (vuelve con **Activar**) |
| **Multilingüe con WPML (ES + EU + EN…)** | Ver §10.12 | Saludo en español → guardar agente → comprobar WPML String Translation → vaciar caché |
| **Saludo distinto por idioma (automático)** | Personalidad + licencia Core activa | Escribir saludo en idioma base → **Guardar agente** (Core llama al Hub si hay WPML; DTP incluido en Core) |
| **Saludo distinto por idioma (manual)** | WPML → String Translation | Contexto **Xabia AI** → cadena `Agent Greeting - su-agente` → traducir a mano |
| **Placeholder «Escribe aquí…» traducido** | Core ≥ 1.0.72 + WPML | Instalar 1.0.72 → visitar el front una vez → comprobar dominio `xabia-intelligence` en String Translation |
| **Conectar CSV o Excel** | General → Archivos CSV | Subir CSV → **Explorar/escanear** → mapear columnas → **Sincronizar** → **Entrenar** |
| **Conectar base de datos remota** | General → SQL remoto | Host, BD, usuario, clave, consulta → **Probar consulta** → mapear → **Sincronizar** |
| **QR por punto de interés** | Smart QR / Tótems | Sincronizar con columna **ENTE** → página de aterrizaje → **Generar QR** → [manual Smart QR](./manual-usuario-xabia-smart-qr.md) |
| **Modo quiosco / tótem** | Smart QR / Tótems | Activar **Modo tótem** + minutos de inactividad, o URL `/xabia-box/?x_project=…` |
| **Recargar tokens** | Cartera / Wallet | Comprobar licencia en Conexión → **Recargar** pack → esperar unos minutos → **Actualizar saldo** |
| **Renovar licencia anual** | Cartera / Wallet | **Renovar año** (misma licencia, no crear otra) |
| **El chat no responde** | Conexión + Cartera | Licencia válida, saldo > 0, agente no **Pausado**, caché vaciada |
| **La IA no «ve» mis datos** | Barra lateral del agente | ¿**Sincronizar** tras cambiar CSV/SQL? ¿**Entrenar** si hay vectorial? ¿Bajar umbral de confianza? |
| **Listado masivo de fichas al instante** | Playground / chat público | Core **≥ 1.0.118**: mapeo con **ENTE** + taxonomía de actividad → preguntas de listado sin depender del Hub (§11) |
| **Contacto o imagen de «la última» del listado** | Mismo chat, turno siguiente | Tras listado: «¿contacto de la última?» / «¿alguna imagen?» — roles **Teléfono**, **Imagen**, **Logotipo** en mapeo (§7.3, §11). Con SQL remoto: **Sincronizar** tras 1.0.164+ (§11.6) |
| **Asistente CPT con SQL remoto** | General → Asistente CPT | Core **≥ 1.0.162**: lista solo tipos de la fuente activa (remoto / local / multi / addon); no mezcla CPTs del WordPress del chat (§7.2) |

---

## 1. Estructura del menú en WordPress

Tras activar **Xabia Agent Core**, en el escritorio aparece el menú **Xabia Agent** con icono de “superhero”.

| Entrada del menú | Qué muestra |
|------------------|-------------|
| **Xabia Agent** | Pantalla principal: listado de agentes, escaparate de addons, y bloque global **Conexión a la IA** (licencia + modo Cloud / infra propia). |
| **Addons** | Tarjetas por addon de pago (Avirato, MEC, Woo, Central…), **clave de licencia** cuando aplica (validación Hub), **tienda Polar** y **portal del cliente**, avisos de renovación si la suscripción está próxima a caducar, y enlaces a **Plugins**. **Smart QR no es un addon:** va incluido en el Core (pestaña **Smart QR / Tótems** al editar un agente). |
| **Cartera / Wallet** | Saldo de tokens, consumo a 30 días, packs de recarga y renovación cuando aplica. |

**Opciones según lo que tenga instalado su sitio**

- Si tiene **Xabia Central** activo, puede ver **Federación (Xabia Central)** como submenú para enlazar varios sitios o nodos cuando use esa función.
- Si tiene el addon **Federación Nexus**, puede aparecer **Federación** o **Federación Nexus** (consulte el manual de ese addon si lo usa).

Para cambiar casi todos los ajustes hace falta estar entrado como **administrador** de WordPress (o con un perfil equivalente que permita gestionar plugins y opciones del sitio).

---

## 2. Pantalla principal: listado de agentes

Cuando abre **Xabia Agent** sin estar editando un agente concreto, verá:

### 2.1 Título y acciones

- **Nuevo agente:** crea una ficha nueva y abre el editor. Al guardar por primera vez, el sistema le asigna un **identificador** estable (derivado del nombre); ese código es el que pegará más adelante en la página web con el **shortcode** (ver más abajo).

### 2.2 Tarjetas de agentes existentes

Cada tarjeta muestra:

- **Nombre** con el que verá el agente en el panel.
- **ID:** un código como `ID: mi-asistente` que debe coincidir con lo que pone en el shortcode: `[xabia_agent id="mi-asistente"]`.
- **Editar:** abre toda la configuración del agente.
- **Borrar:** elimina el agente y **toda la memoria de conocimiento** que hubiera generado para él (lo aprendido al sincronizar y entrenar). **No se puede deshacer** con un solo clic.

**Si borra un agente:** pierde su configuración, los mapeos de datos y la “memoria” asociada. Si subió un archivo CSV para ese agente, el archivo puede seguir en el servidor; si ya no lo necesita, puede borrarlo desde **Medios** o por FTP según su caso.

### 2.3 Bloque «Addons disponibles»

Tarjetas con los **complementos (addons)** que Xabia conoce: nombre, breve descripción y enlace para instalar o gestionar. Activar o desactivar un plugin sigue haciéndose en **Plugins** de WordPress, como con cualquier otro plugin.

**Importante:** un addon puede estar instalado pero inactivo, o activo pero requerir otra configuración (licencia addon, tablas, etc.).

### 2.4 Pausar un agente (listado)

En cada tarjeta del listado hay tres acciones: **Editar**, **Pausar** / **Activar** y **Borrar**.

- **Pausar:** el agente deja de mostrarse en el sitio (no aparece el shortcode ni el disparador flotante). La configuración y la memoria se conservan.
- Si está pausado, la tarjeta muestra la etiqueta **Pausado** y el botón pasa a **Activar**.

### 2.5 Interfaz del chat por agente (pestaña Apariencia)

Desde la **v1.0.28**, la interfaz se configura **por agente** en **Editar agente → Ajustes / Apariencia**, sección **«Interfaz del chat (avatar y panel)»** (después de colores del chat). **No** hace falta Elementor ni bloques HTML en la página.

Desde la **v1.0.57** hay modos claramente separados; desde la **v1.0.192+** existe además el **lanzador incrustable**:

| Modo | Qué se muestra | Cuándo usarlo |
|------|----------------|---------------|
| **Nativo** (**Mostrar en el sitio sin shortcode** activado) | Avatar flotante + panel de chat inyectados automáticamente en la web. | Cuando quiere el botón Xabia global en páginas del sitio. |
| **Shortcode chat** (`[xabia_agent id="…"]`) | Solo el chat embebido donde pegue el shortcode; **no** aparece avatar flotante. | Cuando quiere el panel completo en una página o landing. |
| **Lanzador** (`[xabia_launcher id="…"]` o `[xabia_avatar id="…"]`) | Solo el **botón avatar** incrustado en el contenido; al hacer clic abre el mismo panel que el nativo. | Hero, columnas Elementor, CTAs. Tamaños: `sm` / `md` / `lg` / `xl` o píxeles (`size="320"`). |

Las reglas **Mostrar solo en estas páginas** y **Excluir estas páginas o entradas** pertenecen al modo **nativo**. En modo shortcode/lanzador el chat o botón aparece únicamente donde esté pegado el shortcode.

#### Avatar cinético oficial (v1.0.47)

El disparador por defecto reproduce el muñeco Xabia en **capas SVG inline** (cabeza, cuencas blancas y ojos/satélite), escaladas a una caja de 125×125 px:

| Capa | Qué representa | Color configurable |
|------|----------------|--------------------|
| Cabeza | Círculo base del avatar | `avatar_colors.bg` |
| Cuencas | Dos círculos blancos bajo los ojos | `avatar_colors.shadow` |
| Ojos y satélite | Dos ojos/pupilas y un punto decorativo lateral | `avatar_colors.dots` y variante secundaria |

El avatar **mira al cursor** con efecto de profundidad: la cabeza se mueve muy poco, las cuencas algo más y los ojos/satélite con mayor amplitud (animación GSAP). También puede usar **imagen personalizada** (PNG/SVG desde Medios).

#### Comportamiento en la web (sin Elementor)

| Comportamiento | Descripción |
|----------------|-------------|
| **Disparador flotante** | Fijo abajo a la derecha (o izquierda / márgenes personalizados). |
| **Scroll** | Al **bajar** la página el botón aparece; al **subir** se oculta (como en la versión clásica de Xabia). |
| **Panel de chat** | Flotante derecha/izquierda, **modal centrado con desenfoque**, o pantalla completa. |
| **Telón** | Al abrir el chat, fondo semitransparente con blur en toda la pantalla. |

#### Opciones en el panel de Apariencia

| Ajuste | Qué hace |
|--------|----------|
| **Tipo de disparador** | Avatar cinético (nativo) o imagen personalizada. |
| **Colores del avatar** | Tinte de cabeza, cuencas y ojos del avatar nativo. |
| **Posición del disparador** | Abajo-derecha, abajo-izquierda o márgenes personalizados (px, vh, vw). |
| **Comportamiento del panel** | Flotante derecha, flotante izquierda, modal centrado, pantalla completa. |
| **Avatar parlante** | Activa el **modo inmersivo** (avatar grande / teatro) al abrir el panel. Independiente del mute TTS: silenciar la voz no desactiva el parlante (Core ≥ 1.0.200). |
| **Mostrar en el sitio sin shortcode** | Activa el modo nativo: avatar flotante + panel automático. Si se desmarca, use `[xabia_agent]` o `[xabia_launcher]`. |
| **Mostrar solo en estas páginas** | Limita el modo nativo a páginas concretas. Tiene prioridad sobre las exclusiones generales. |
| **Exclusiones** | Oculta el modo nativo por tipo de contenido, IDs de página, y carrito/checkout WooCommerce. |

Guarde con **Guardar agente**. En modo nativo el plugin inyecta disparador y assets en `wp_footer` automáticamente. En modo shortcode, pegue `[xabia_agent id="su-agente"]` o `[xabia_launcher id="su-agente" size="lg"]` donde corresponda.

#### Aspecto del chat (v1.0.197–1.0.201)

El panel usa un **layout en stream** (sin burbujas de mensaje), esquinas cuadradas y controles (enviar / micrófono / mute) que se revelan al pasar el ratón sobre la zona de escritura. El texto del usuario puede heredar el color de acento del avatar. Las respuestas del bot admiten **Markdown básico** (`**negrita**`, listas con viñetas) desde la **v1.0.201**.

#### Chat embebido con shortcode: halo y foco

El chat generado por shortcode conserva la estética del panel nativo: borde luminoso animado y sombra suave. Al hacer foco en el campo de texto, el plugin puede elevar el chat sobre la página y mostrar un **overlay con blur** que tapa el fondo para mejorar la lectura.

El overlay se cierra haciendo clic fuera del chat, pulsando **Escape** o usando el botón de cierre en móvil. Esta capa es independiente del telón del avatar nativo: el shortcode sigue siendo un chat embebido, no un disparador flotante.

La ruta **`/xabia-box/`** (Smart QR / tótem) muestra el chat a pantalla completa **sin** disparador flotante. Smart QR, generador de códigos y modo tótem están **incluidos en el Core** — consulte el [manual Smart QR](./manual-usuario-xabia-smart-qr.md) para la guía completa con ejemplos.

### 2.6 Documentación en línea (xabia.ai/docs)

Los manuales en PDF y HTML del producto se publican en:

| Recurso | URL |
|---------|-----|
| **Índice — Manuales de Xabia AI** | https://xabia.ai/docs/ |
| Manual Xabia Agent Core | https://xabia.ai/docs/manual-usuario-xabia-core.pdf |
| Manual Smart QR / tótems (Core) | https://xabia.ai/docs/manual-usuario-xabia-smart-qr.pdf |
| Manual Xabia MEC | https://xabia.ai/docs/manual-usuario-xabia-mec.pdf |
| Manual Xabia Woo | https://xabia.ai/docs/manual-usuario-xabia-woo.pdf |
| Manual Xabia Avirato | https://xabia.ai/docs/manual-usuario-xabia-avirato.pdf |

En el repositorio de desarrollo, las fuentes están en `xabia-agent-plugins/documentation/` y se regeneran con `./scripts/build-modular-manuals-pdf.sh`.

---

## 3. Conexión a la IA (tarjeta global)

Aquí decide **cómo** su web se conecta a la inteligencia artificial y al servicio **xabia.ai** cuando use el modo recomendado.

Use el botón **Guardar configuración** de esta misma página (no confundir con **Guardar agente** dentro de un agente).

### 3.1 Modo de conexión

Tiene dos opciones:

| Opción | Nombre en pantalla | Qué implica para usted |
|--------|--------------------|-------------------------|
| **Cloud de Xabia** | **Xabia Cloud (recomendado)** | Su sitio usa una **licencia Xabia** y el saldo de tokens de su cuenta. Las conversaciones pasan por la infraestructura de Xabia; **no** necesita pegar aquí ni en cada agente sus claves de OpenAI o Google: con licencia y saldo suficiente, ya está cubierto. En el agente puede que no vea el selector entre OpenAI y Google; el sistema fija por detrás lo adecuado para Cloud. |
| **Cuenta propia** | **Infraestructura propia** | Las facturas van directamente contra **su** cuenta de OpenAI o Google Cloud (Vertex). Suele aparecer la sección de **Claves API y Google Cloud** para configurar rutas de credenciales. La licencia Xabia puede seguir siendo necesaria para otras funciones según su plan; pero el modelo de gasto aquí es el suyo directamente con proveedores externos. |

**Tras cambiar de modo**, revise cada agente: en Cloud no debe preocuparse por el “motor”; en infraestructura propia debe comprobar en cada uno qué proveedor usa y si las rutas/claves están bien.

### 3.2 Licencia y saldo

#### Bloque informativo (tokens y caducidad)

- **Tokens restantes:** lo que figura después de que el sistema compruebe su licencia. Si lleva tiempo sin sincronizar, puede aparecer una raya hasta que pulse **Actualizar saldo**.
- **Caducidad:** fecha hasta la que su licencia está activa según lo que recibe el sistema.
- **Comprobado:** cuándo se hizo la última verificación contra el servicio central.

El botón **Actualizar saldo** vuelve a preguntar a Xabia y refresca estos datos. Si aún no ha guardado una licencia, le avisará antes.

**Sin licencia válida o sin saldo**, el chat puede no responder o mostrar un mensaje sobre **saldo insuficiente** o licencia según configuración del servicio.

#### Campo «Licencia Xabia de este sitio»

- Es un campo tipo contraseña; puede usar el ícono de ojo para **ver solo lo que está escribiendo ahora**.
- Si **ya tiene** una licencia guardada y deja este campo vacío al guardar, **no borra la antigua**. Para quitar definitivamente la licencia use la casilla **Eliminar licencia guardada** (véase más abajo).
- Solo pegue aquí cuando quiera **cambiar** la licencia por una nueva cadena que le hayan enviado.

**Licencia guardada:** en pantalla solo verá asteriscos y quizá los últimos caracteres (por seguridad).

**Aviso amarillo:** si el sistema detecta que lo guardado parece un **ID de panel** (por ejemplo empieza por `lic_…`) y no la **cadena larga de activación** que suele empezar por `xabia_`, le avisará: el servicio probablemente no la aceptará. Lo habitual es **eliminar** la licencia guardada, guardar, y volver a pegar la clave completa del correo o de su compra.

##### Botón «Ver licencia guardada en WordPress»

Muestra lo que realmente está guardado en su WordPress (sin espacios raros ni cortes). Solo lo deben usar **administradores de confianza**: quien vea la licencia podría usarla en otro sitio si la copiara.

#### Cómo se protege la conexión

Cuando la licencia es correcta, el plugin la usa para **identificar de forma segura** su sitio ante xabia.ai al enviar las peticiones. No hace falta que entienda el detalle técnico: si cambia de licencia, la nueva empieza a aplicarse al guardar. Si por error pega los asteriscos de la pantalla en lugar de la clave real, el sistema intenta **no** sustituir la buena licencia guardada.

#### Casilla «Eliminar licencia guardada de este sitio»

- Al marcarla y pulsar **Guardar configuración**, se borra la licencia de este sitio.
- **Efecto:** el chat dejará de usar el servicio Xabia asociado a esa clave hasta que pegue **otra** licencia válida.
- **No** borra por sí sola sus claves OpenAI o Google de la sección avanzada; solo la licencia Xabia.

### 3.3 Claves API y Google Cloud (cuenta propia)

Solo aparece si eligió **Infraestructura propia**. La sección viene plegada en **desplegable** para no abrumar: ábrala cuando vaya a configurar credenciales.

#### OpenAI — clave (global aquí)

- Es la **clave secreta de su cuenta OpenAI**.
- Sirve cuando el agente está configurado para usar **OpenAI** y cuando no lleva una clave distinta sólo para él.
- Trátela como una contraseña de banca: si alguien la copia, puede generar cargos en su nombre. Cambiela desde el panel OpenAI si cree que ha filtrado.

#### Google Cloud (Vertex AI) — ruta al archivo JSON de la cuenta de servicio

- Debe ser la **ruta completa en el servidor** al archivo JSON que le da Google para la cuenta de servicio con permisos de Vertex/Gemini.
- Si en un agente concreto no rellena nada, se usará esta ruta **global**.

**Errores frecuentes:** ruta mal escrita, el servidor no puede leer el archivo, la cuenta no tiene permisos de Vertex, o el proyecto/región no coinciden con lo que usa en Google.

#### Google Cloud — clave de Maps

- Para mapas en el chat o piezas visuales que integren Google Maps, si su proyecto lo usa.

#### Federación Nexus — «Activar solo modo Puente»

Solo verá esto si tiene instalado el addon **Federación Nexus**.

**Importante:** si está en **Xabia Cloud**, marcar o desmarcar esta casilla en esta pantalla **puede no guardarse** como espera; el valor real sigue el que ya tenía el sitio. Para cambiar el modo Puente con seguridad, use **Infraestructura propia** o la pantalla que indique el manual de Nexus.

**Qué es el modo Puente (en pocas palabras):** su WordPress actúa como **servidor de datos** para otros sitios de la federación. No es el modo normal para un hotel o tienda que solo quiere un chat en la página. Si lo activa por error en un sitio público, **es posible** que el shortcode habitual del chat no se muestre como antes; consulte con quien le haya pedido la federación.

**Consejo:** si no sabe para qué es la federación, **no marque** solo modo Puente.

---

## 4. Editor de un agente: vista general

Al pulsar **Editar** o **Nuevo agente**, el formulario usa pestañas. Las predeterminadas del Core son:

1. **General** — datos básicos, motor de IA solo si usa **infraestructura propia**, fuentes (CSV/SQL…) y cómo etiquetar columnas para el bot.
2. **Smart QR / Tótems** *(Core)* — página de aterrizaje, URLs de túnel, generador de QR por ente, modo kiosko. [Manual detallado Smart QR](./manual-usuario-xabia-smart-qr.md).
3. **Personalidad** — colores, lectura por voz, cuánto contexto usar, límites de respuesta y el “guión” general del asistente.
4. **Registro de conversaciones** — historial reciente guardado si lo tiene habilitado.

Los addons pueden añadir **pestañas extra** dentro del mismo agente (por ejemplo **Avirato**).

Pulse **Guardar agente** al final para aplicar cambios; hasta entonces solo hay borradores en pantalla.

---

## 5. Pestaña «General»

### 5.1 Nombre del agente


- Nombre visible en el panel para usted y su equipo.
- Si crea el agente hoy por primera vez, el **identificador** del shortcode se genera a partir del nombre. Si solo **cambia el nombre** de un agente antiguo, el identificador del shortcode **suele mantenerse** (no cambia la URL pegada antigua).

### 5.2 Shortcode

Tras guardar un agente existente se muestra:

```text
[xabia_agent id="ID_DEL_PROYECTO"]
```

Al pegarlo en una página de WordPress **publica el chat de ese agente** (si el agente no está **Pausado**).

- Si **Mostrar en el sitio sin shortcode** está activado, el shortcode puede convivir con la interfaz nativa, pero el avatar flotante lo controla la configuración de **Apariencia**.
- Si **Mostrar en el sitio sin shortcode** está desactivado, el shortcode muestra **solo el chatbot embebido** en esa página, sin avatar flotante ni reglas de visibilidad nativa.

Algunos complementos admiten opciones extra en el mismo shortcode (idioma, modo tótem, nombre visible del asistente en los mensajes, etc.): revíselas en el manual de cada addon que use. El atributo `lang`, cuando se use, sirve como idioma de interfaz y respaldo; no bloquea el idioma en el que la IA redacta la respuesta (§10.11).

### 5.3 Bloque Xabia Cloud (cuando el UI está en modo Cloud)

Mensaje informativo: el agente usa el servicio de Xabia; la licencia y el saldo los gestiona en la **pantalla principal** del plugin, no aquí dentro del agente.

En esta vista no verá opciones duplicadas de motor o de Google Cloud: el sistema usa lo que ya tiene guardado para ese agente y lo coherente con **Xabia Cloud**.

### 5.4 Motor de inteligencia (solo aparece en infraestructura propia)

**Motor de Inteligencia:**

| Opción | Qué significa en la práctica |
|--------|-------------------------------|
| **OpenAI** | Usa los servicios de OpenAI para el chat y, si corresponde, para generar la “memoria” vectorial del conocimiento |
| **Google Cloud (Vertex)** | Usa Gemini/Vertex según su configuración; necesita la ruta al JSON correcta en servidor |

**Archivo JSON de Google por agente (opcional):** si está vacío, se usa la ruta **global** de ajustes generales.

**Clave OpenAI solo para este agente (opcional):**

- Si rellena y guarda, **este** agente usa esa clave y no la global.
- Si deja vacío al editar y el agente ya tenía clave, **suele conservarse** la anterior.
- Según su modo global y licencia, si no hay clave local donde haga falta, el sistema puede **derivar por el proxy Xabia**; depende del caso.

**Consejo práctico:** si un agente usa su cuenta OpenAI directa y otro no, pueden aparecer cargos separados por proveedor — anote cuál usa cuál si lo administra más de uno.

---

## 6. Fuente de información (de dónde aprende el asistente)

Aquí decide **qué datos** debe “leer” el sistema antes de crear la memoria que usa el chat (preguntas frecuentes, catálogo, contenidos estructurados, etc.). Es aparte del **contexto automático** que algunos addons añaden en cada mensaje (por ejemplo disponibilidad de reservas).

El plugin le recordará en pantalla que **los addons activos pueden aportar información adicional sin pasar solo por aquí**; lo que elija en esta lista es la **base de conocimiento** que usted controla con CSV, SQL o conectores.

### 6.1 Archivos CSV

- Útiles para catálogos en tabla: productos, puntos de interés, listados exportados desde Excel como CSV.
- **Subir CSV** guarda el archivo en el sitio y lo enlaza a este agente.
- **Eliminar CSV** quita el archivo enlazado y la referencia.
- **Explorar / escanear CSV** detecta columnas y prepara el **mapeo** (qué significa cada columna para el bot).
- Por lo general distingue si las columnas van separadas por coma o por punto y coma.

**Recuerde:** hasta que pulse **Sincronizar datos** —y, si usa búsqueda por similitud semántica, **Entrenar**— el asistente puede no “ver” bien esas filas en las respuestas.

### 6.2 Base de datos de WordPress (SQL local)

Una consulta sobre la base de datos del propio WordPress. Use el comodín **`{prefix}`** cuando la plantilla lo indique: el sistema lo sustituye por el prefijo real de sus tablas (suele ser `wp_` u otro que haya configurado su alojamiento).

**Consejo:** las consultas muy amplias pueden ralentizar el sitio o sacar datos de más. Pruebe primero en copia de seguridad/desarrollo o con límites razonables.

### 6.3 Base de datos externa (SQL remota)

Rellene **Host**, **Nombre de la base**, **Usuario**, **Contraseña** y la consulta cuando el catálogo viva fuera de WordPress.

Si la base externa es un WordPress con **Modern Events Calendar**, puede usar **Usar preset MEC remoto**. El Core rellenará la consulta SQL de eventos, aplicará el mapeo recomendado (`Evento`, `Fecha`, `Hora`, `Lugar`, `Link`, `Precio`, `Descripcion`, `Categorias_Tags`, `Imagen_URL`) y guardará `sql_preset = mec_remote`.

Use este preset cuando no vaya a instalar el addon MEC en el sitio del chat. Si sí tiene **Xabia MEC** activo, el flujo recomendado es **Conectores de addons → MEC** con las credenciales SQL remotas (§6.4), igual que Woo.

**Seguridad:** las credenciales quedan guardadas con el agente; lo ideal es que el servidor remoto solo acepte conexiones desde la IP de su web.

### 6.4 Conectores de addons

Solo está disponible con un **addon compatible** instalado y **activo**. Si aparece como no disponible, instale primero ese plugin desde **Plugins**.

Tras seleccionarlo, use **Conectar y Mapear** para revisar las columnas que expone antes de usarlas.

Los addons **MEC** y **Woo** también pueden trabajar contra una base de datos remota desde los campos **Host / DB / usuario / contraseña**. En ese caso el modo sigue siendo **Addon nativo** (porque aporta reglas, mapeo y acciones del dominio), pero la lectura SQL se hace contra otro WordPress. Para **MEC remoto** (Core **≥ 1.0.202** + addon MEC), si el sitio del chat no tiene Modern Events Calendar local, el asistente **nunca** emite botones de reserva local (`[ACTION:BOOK:ID]`): dirige al visitante con **`[ACTION:URL:Link]`** usando la columma **Link** del evento remoto.

### 6.5 Varios orígenes a la vez (“multi‑fuente”)

Puede combinar **hasta dos** fuentes (por ejemplo SQL remoto + CSV).

- Cada parte lleva sus propios datos y **su propio mapeo** de columnas.
- En remotos suele poder ayudar un **prefijo** automático con el mismo criterio que `{prefix}`.

**Consejo:** más fuentes ofrecen flexibilidad, pero también más sitios donde equivocarse; revise cada bloque con tranquilidad.

---

## 7. Herramientas dentro de la pestaña Datos

### 7.1 Probar la consulta SQL (una fila)

Hace una lectura de prueba (**solo la primera fila**) de su consulta para mostrarle qué columnas detecta y ayudarle en el mapeo. Evite usarlo en picos de visitas en sitios en producción sin control: es una herramienta de comprobación.

### 7.2 Asistente de contenidos WordPress (“Consola de curación”)

Un asistente paso a paso que:

1. Lista los **tipos de contenido de la fuente activa** (no los del WordPress del chat si la fuente es otra).
2. Sugiere **qué campos** son interesantes para el bot (título, texto, metadatos, taxonomías…).
3. Puede **transferir** una consulta SQL y un mapeo razonable **al formulario** del agente.

**Segregación por fuente (Core ≥ 1.0.162):**

| Fuente configurada | Qué lista el Asistente CPT |
|--------------------|----------------------------|
| **SQL remoto** | `post_type` publicados en la BD remota (vía SQL Bridge). **Nunca** los CPT locales del sitio del plugin. |
| **SQL local** | Tipos del WordPress actual (`$wpdb`). |
| **Multi-fuente** | Solo la fuente del botón pulsado (índice 1, 2…). |
| **Addon (MEC / Woo…)** | Solo el CPT nativo del addon (`mec-events`, `product`, …). Si el addon usa SQL remoto, valida allí. |

El modal muestra un aviso del origen (p. ej. «Base de Datos Remota»). En deep schema, productos Woo incluyen metas `_price` / `_sku` / stock; MEC incluye plazas calculadas cuando aplica.

**Qué es “Ente”:** marque el campo que identifica de forma única cada elemento (útil en experiencias con QR o cuando el visitante “elige” una ficha concreta).

También tiene un atajo para **añadir metadatos de un tipo de contenido** sin pasar por todo el asistente.

### 7.3 Mapeo de columnas (atributos repetibles)

> 💡 **Piénsalo de forma sencilla:** la IA es increíblemente inteligente, pero no puede adivinar qué número de tu Excel es un teléfono, cuál es un precio o qué celda es un enlace a una imagen. Al asignar el rol **Teléfono** o **Imagen** en el mapeador, le estás dando las llaves de tu negocio: le enseñas a rellenar su agenda de contactos para cuando el cliente le pida el teléfono de una empresa.

Por cada fila elija:

- **Columna o campo** del CSV/SQL.
- **Etiqueta** amigable (“Precio”, “Teléfono de reservas”…).
- **Rol visual** en el chat cuando corresponda mostrar tarjeta enriquecida: título, texto, fecha, **imagen** (fotos/galería), **logotipo** (marca corporativa), teléfono, web, email, mapa, etc.
- **IDENTIDAD (ENTE)** si ese campo distingue registros únicos en su proyecto.

**Roles clave para catálogos de empresas (Core ≥ 1.0.118):**

| Rol visual | Cuándo usarlo |
|------------|---------------|
| **Imagen** | Fotos del establecimiento, galería, escaparate |
| **Logotipo** | Marca corporativa; no mezclar con fotos |
| **Teléfono** | Contacto telefónico |
| **Web** / **Email** | Enlaces y correo |
| **IDENTIDAD (ENTE)** | Campo único por ficha (nombre, código interno, etc.) |

El Core **no** asume nombres fijos de campos de un cliente concreto. Todo se resuelve desde este mapeo.

Algunas columnas típicas de WordPress muestran un **icono de “cerebro”** para destacar que son buenos candidatos como texto principal del conocimiento — no es obligatorio tocarlo si no lo necesita.

---

## 8. Smart QR y modo tótem (incluidos en Core)

Desde la **v1.0.59**, toda la potencia de **Smart QR** (túnel por ente, generador de códigos, `/xabia-box/`, modo kiosko) forma parte de **Xabia Agent Core**. No hace falta instalar ni licenciar un plugin aparte.

### 8.1 Resumen en tres pasos

1. Sincronice conocimiento con al menos una columna marcada como **IDENTIDAD (ENTE)** (§7.3).
2. Publique una página con `[xabia_agent id="…"]` y selecciónela como **Página de aterrizaje** en **Editar agente → Smart QR / Tótems**.
3. Pulse **Generar QR** junto al ente deseado, descargue el PNG e imprímalo.

El escaneo abre el chat con contexto del ente (saludo y RAG acotados). Ejemplo de URL generada:

```text
https://su-dominio.com/asistente/?ente_id=sala-impresionismo
```

### 8.2 Modo tótem (pantallas públicas tipo kiosko)

En **Smart QR / Tótems** puede activar **Modo tótem** y definir **minutos de inactividad**: tras ese tiempo sin usar el chat, la sesión vuelve al saludo inicial (útil en tablet en recepción).

También puede usar la ruta a pantalla completa:

```text
https://su-dominio.com/xabia-box/?x_project=ID_DEL_AGENTE
```

En el shortcode puede forzar minutos con `totem="10"`:

```text
[xabia_agent id="mi-agente" ente_id="recepcion" totem="10"]
```

**Resumen:** Smart QR = carteles y enlaces por ente; modo tótem = quiosco en pantalla fija. No tiene que usar ninguno si solo quiere chat clásico en la web.

### 8.3 Documentación ampliada

Casos museo, hotel, retail, POI `?xqr=`, migración del antiguo plugin `xabia-smart-qr` y solución de problemas: **[manual-usuario-xabia-smart-qr.md](./manual-usuario-xabia-smart-qr.md)** (PDF/HTML en [xabia.ai/docs/manual-usuario-xabia-smart-qr.pdf](https://xabia.ai/docs/manual-usuario-xabia-smart-qr.pdf)).

---

## 9. Barra lateral (solo agente ya guardado, no «new»)

La columna derecha (memoria + Playground) **hace scroll con la página** (Core ≥ 1.0.165): no queda fijada arriba. Así puede revisar el formulario completo sin pelearse con paneles pegados.

### 9.1 Memoria del agente (cifras de la barra lateral)

- **Registros sincronizados:** líneas incorporadas tras la última **Sincronizar datos**.
- **Listos para búsqueda inteligente:** cuántos trozos ya llevan preparada la parte “semántica” si usa esa función.
- **Tokens hoy:** cuántos ha gastado ese agente hoy comparado con su **tope diario** (véase más abajo en Personalidad).

### 9.2 Botón «1. Sincronizar datos»

Vuelve a leer su CSV/SQL/multi‑fuente o conector según lo que configuró en **General**. Hágalo después de cambiar archivos fuente o la consulta.

### 9.3 Botón «2. Entrenar» (cuando aplique vectorización)

Prepara los fragmentos de texto para la **búsqueda por similitud**. Si algo falla (permiso de Google, falta de saldo Cloud, etc.) el sistema mostrará un mensaje claro para corregirlo.

### 9.4 «Borrar memoria vectorial»

Limpia la memoria matemática asociada a este agente (no borra necesariamente el CSV en disco). Úselo cuando quiera empezar de cero después de mover muchos datos.

### 9.5 Zona de pruebas («Playground»)

Un chat de laboratorio igual que usará el visitante pero sin publicar página todavía. Ideal para tunear antes de abrir al público.

---

## 10. Pestaña «Personalidad»

Aquí da “cara y voz” al asistente. Algunos addons pueden añadir más opciones al final de esta pantalla.

### 10.1 Nombre del asistente (en los mensajes)

El nombre corto que verá el visitante junto a cada mensaje del bot (por defecto “Xabia”). **No** confundir con el **avatar cinético** del disparador flotante (§2.5). En el shortcode puede usar `avatar_name="…"` para personalizarlo.

### 10.2 Colores y tipografía

- **Color identidad:** botones y detalles destacados del chat.
- **Color de fondo del chat.**
- **Tamaño del texto** en píxeles (elija lo que se lea bien en móvil y ordenador).

### 10.3 Voz (lector del navegador)

El visitante puede usar **leer en voz alta** si su navegador lo permite.

- **Tipo de voz** (por defecto / más femenina / más masculina): es una sugerencia al sistema; el resultado depende del dispositivo.
- **Velocidad:** de lenta a rápida dentro del rango permitido.
- **Filtros para que suene natural:** puede quitar negritas asteriscos, marcas técnicas tipo `[ACCIÓN:…]` o emojis, y añadir textos concretos que no quiera que se lean (por ejemplo enlaces larguísimos).

En el chat, el botón de altavoz alterna entre voz desactivada y lectura en alto. Cuando un botón de acción está activo o resaltado (voz, micrófono o enviar), el icono cambia a blanco sobre fondo de color para mejorar el contraste.

### 10.4 Índice de confianza del contexto

Valor entre **0 y 1** (en pantalla suele sugerirse algo cercano a **0,20**).

**En lenguaje claro:** cuánto debe “encajar” el material de su web con la pregunta para que el modelo lo use. Si **sube mucho** este valor, el asistente será **más exigente**: puede volverse **cortante o genérico** porque descarta trozos que no le parecen una coincidencia suficiente. Si **baja** el valor, aceptará más contexto relacionado; a veces aparece algo más de ruido si los datos no están bien ordenados.

**Consejo:** ajuste despacio y pruebe en el Playground antes de abrir al público.

### 10.5 Cuánto puede alargarse cada respuesta

Controla el **tamaño máximo** de texto que puede generar cada respuesta (el sistema tiene un rango razonable guardado).

Si **aumenta** este límite: las respuestas pueden ser **más largas y detalladas**, pero también **gastan más tokens** y pueden tardar algo más. Si **reduce** el límite: ahorra y es más directo, pero quizá no desarrolle tanto el tema.

### 10.6 Tope diario de consumo por agente

Número máximo de tokens que este agente puede usar **en un día** (el contador se cierra según el día “técnico” del servicio, habitualmente en horario universal).

Si se supera, el chat puede entrar en **modo mantenimiento** para ese agente aunque la cartera general tenga saldo: es un **fusible** útil frente a picos o abusos accidentales.

### 10.7 Cuántos trozos de conocimiento enviar en cada pregunta (“contexto”)

Número de **fragmentos** de su conocimiento base que se pueden mezclar con la pregunta en cada turno (suele estar en un rango amplio; si lo deja vacío, el sistema usará un valor por defecto).

- **Más trozos:** contextualmente **más rico**, pero **más gasto** y a veces más “disperso” si no acierta el contenido.
- **Menos trozos:** **más económico** y directo, pero el asistente puede **dejar fuera** datos que estaban en otros fragmentos menos priorizados.

### 10.8 Búsqueda inteligente (“vectorial”) y sensibilidad

- **Activar búsqueda vectorial:** conviene solo si ya ha hecho **Entrenar** y ve en la barra lateral que hay memoria lista. Si no hay nada entrenado, el texto de ayuda le avisará.
- **Umbral de similitud (0–1):** qué tan parecido debe ser un trozo a la pregunta para contar. **Valores muy altos** pueden hacer que “no encuentre nada” sobre temas relacionados pero no idénticos.

### 10.9 Saludo inicial

Texto HTML sencillo que ve el visitante al abrir el chat. WordPress aplicará sus reglas de seguridad habituales sobre etiquetas permitidas.

### 10.10 Instrucciones generales (personalidad y reglas)

Aquí escribe **quién es el asistente**, tono (formal, cercano, breve), límites (“no opina de política”, “no invente precios sin consultar datos”, etc.).

**Consejo práctico:** no hace falta listar todos los sinónimos de su sector; con buenos datos y una memoria bien sincronizada las respuestas son más naturales. Use este cuadro para el **comportamiento estable** del negocio.

### 10.11 Idiomas y respuestas políglotas

Xabia responde en el idioma del **último mensaje del usuario**. Si el conocimiento sincronizado está en español pero el visitante pregunta en inglés, francés o euskera, el modelo redacta la respuesta en ese idioma.

El idioma configurado por WordPress o por el atributo `lang` del shortcode afecta sobre todo a la **interfaz fija** (placeholder, botones, voz del navegador). No fuerza a la IA a responder siempre en ese idioma.

Evite en el prompt maestro frases rígidas como «responde exclusivamente en español» salvo que quiera bloquear el comportamiento políglota.

### 10.12 Sitio multilingüe con WPML (saludo + textos del chat)

¿Quieres que tu asistente hable **euskera** o **inglés** de forma automática? Con **WPML** + **String Translation**, **Core ≥ 1.0.72** y licencia **Cloud**, sigue esta ruta rápida de **3 pasos**:

1. Escribe tu **saludo inicial en castellano** dentro de la pestaña **Personalidad** y **guarda el agente**.
2. Xabia detectará tus idiomas activos y se encargará (gracias a tu licencia Cloud) de **traducir el saludo automáticamente** por detrás.
3. Ve a **WPML → String Translation** y comprueba que tus traducciones están listas en el contexto **Xabia AI**. ¡Así de fácil!

Después, vacía la **caché** del sitio (LiteSpeed, Cloudflare…) y prueba en incógnito las URLs `/`, `/eu/`, `/en/` (o las que uses).

#### Un ajuste WPML imprescindible (solo la primera vez)

En **WPML → Configuración**, baja hasta **Traducción de cadenas** → **Cambiar el idioma original de las cadenas** → elige **Español**.

Si no ves ese enlace: **WPML → Traducción de cadenas → Utilidades** y pon **Español** como idioma original de las cadenas del plugin. Así evitas que el saludo aparezca con bandera inglesa aunque el texto esté en castellano.

#### Textos fijos del chat («Escribe aquí…», botones)

Al visitar el sitio una vez tras instalar o actualizar, Xabia suele rellenar también las traducciones de la interfaz del chat (placeholder, Enviar, Hablar…). Si algún texto no cambia de idioma: vacía caché, vuelve a entrar al front y revisa String Translation. Si sigue vacío, escribe a soporte: a veces hace falta forzar una re-sincronización.

#### Si algo no cuadra

| Síntoma | Qué hacer |
|---------|-----------|
| Saludo siempre en español en `/eu/` | Revisa la fila del saludo en **Xabia AI**; vuelve a **guardar el agente** |
| Bandera incorrecta pero texto en castellano | Idioma original de cadenas = **Español** (ajuste de arriba) |
| «Escribe aquí…» no traduce | Core actualizado, caché vacía, visita una página del front |
| Traducción automática del saludo no arranca | Licencia Cloud activa + al menos **2 idiomas** en WPML |

¿Sin WPML o sin licencia Cloud? Puedes traducir el saludo a mano en String Translation; el paso automático simplemente no se ejecuta.

---

## 11. Catálogo, pasaporte remoto y seguimientos por ente (Core ≥ 1.0.118 / 1.0.164+)

Desde la **v1.0.118**, Xabia Agent Core incluye un **motor de catálogo** agnóstico: listados y datos de ficha por empresa, producto o punto de interés **sin** depender de nombres de campos de un cliente concreto. Desde la **v1.0.164**, si tus datos viven en una **base remota**, al sincronizar Xabia guarda junto a cada ficha un **anexo** con los atributos que mapeaste en General (teléfono, email, web, etc.).

### 11.1 Qué resuelve

| Pregunta del visitante | Comportamiento |
|------------------------|----------------|
| Listado por actividad / categoría («¿qué opciones de …?») | Listado breve: nombre + detalle corto. **No** vuelca teléfono/email del anexo. |
| «¿Tienes el contacto / teléfono de …?» | Modo ficha: usa el anexo (remoto) o los datos en vivo del WordPress local. |
| «¿Y alguna imagen?» | Fotos con rol **Imagen**; logotipo solo si pregunta por marca o rol **Logotipo**. |

### 11.2 Qué necesita configurar (una sola vez)

1. **Fuente de datos** coherente con su catálogo (CSV, SQL local/remoto, multi-fuente, addon…).
2. **Mapeo de columnas** con al menos un campo **IDENTIDAD (ENTE)** y roles de contacto/imagen (§7.3).
3. Una **categoría o taxonomía de actividad** reconocible en su proyecto — el Core la deduce del mapeo, sin nombres fijos de un vertical.

**Contenido en el mismo WordPress del chat:** listados y ficha pueden leerse **en vivo** (sin sync obligatorio para ese atajo).

**Datos en SQL remoto / Hub (Core ≥ 1.0.164):** debe **Sincronizar** (y enviar al Hub si usa búsqueda en la nube) para que teléfono, email, etc. viajen con cada ficha. Xabia **no inventa** campos: solo usa lo que usted mapeó en General y tiene valor en la fila.

### 11.3 Flujo conversacional típico

1. Visitante: pregunta de listado (varias entidades).
2. Asistente: viñetas concisas; cierra invitando a elegir o pedir más detalle.
3. Visitante: «teléfono de [nombre]» / «contacto de la primera».
4. Asistente: datos de ficha (anexo o pasaporte).
5. Visitante: «y alguna imagen».
6. Asistente: muestra la imagen solo si el rol **Imagen** está mapeado y tiene valor.

El sistema recuerda el listado a partir del **historial del chat** que se envía en cada mensaje.

### 11.4 Modo lista vs ficha (Core ≥ 1.0.165)

| Modo | Cuándo | Qué ocurre |
|------|--------|------------|
| **Lista** | Varias entidades / pregunta abierta de catálogo | Respuesta breve **sin** volcar teléfono/email del anexo. |
| **Ficha** | Contacto, imagen, «cuéntame más de …», «la primera / la última» | Usa el anexo completo de esa ficha. |

Comportamiento **agnóstico**: solo depende de su mapeo, no de un cliente concreto.

### 11.5 Si algo falla en el Playground

Tras un listado, pruebe en el mismo chat: «¿teléfono de la primera?» o «¿alguna imagen?». Si no responde bien: Core **≥ 1.0.166**, roles **Teléfono** / **Imagen** en el mapeo (§7.3) y, con SQL remoto, **Sincronizar** de nuevo.

Detalle técnico para integradores: [DESARROLLO.md §11–12](./DESARROLLO.md).

### 11.6 Hub vs catálogo local vs SQL remoto

| Objetivo | Acción |
|----------|--------|
| Listados y contacto (contenido **local**) | Core actualizado + mapeo correcto |
| Contacto con **SQL remoto** / Hub | Mapear atributos → **Sincronizar** (≥ 1.0.164) → enviar al Hub si aplica |
| Preguntas abiertas con búsqueda inteligente | **Sincronizar** + **Entrenar** |

Un Hub aún sin memoria entrenada **no bloquea** el listado local; sí limita las respuestas semánticas profundas hasta re-sincronizar.

### 11.7 Novedades 1.0.162 – 1.0.168 (resumen)

| Versión | Cambio |
|---------|--------|
| **1.0.168** | Respuestas más rápidas: caché de preguntas repetidas, menos espera al mostrar el texto en el chat. |
| **1.0.162** | El asistente de tipos de contenido respeta la fuente (no mezcla CPT locales con SQL remoto). |
| **1.0.163** | MEC/Woo: catálogo remoto por host SQL; más campos de precio, SKU y plazas. |
| **1.0.164** | Anexo de atributos mapeados en fichas remotas tras sincronizar. |
| **1.0.165–166** | Listados breves; barra lateral del admin sin sticky; reglas agnósticas. |

---

## 12. Pestaña «Registro de conversaciones»

Aquí aparece, cuando está activado, el **historial reciente**: fecha, mensaje del visitante y respuesta del asistente.

**Privacidad (RGPD):** compruebe con su responsable jurídico si puede guardar conversaciones que incluyan datos personales y si debe informarlo en política de privacidad / cookies como corresponda en su país.

---

## 13. Precios, licencias y packs

> 📢 **Nota sobre tarifas y licencias:** para garantizar que siempre veas las ofertas vigentes, la tabla de precios actualizada, los packs de tokens y las condiciones de renovación se gestionan en tiempo real en nuestra web oficial: **[https://xabia.ai/precio](https://xabia.ai/precio)**.

En la práctica encontrará ahí:

- Licencia **Core** (primer año y renovación), con tokens de cortesía según la oferta vigente.
- **Packs de tokens** (Starter, Business, Enterprise u otras denominaciones publicadas).
- Addons (**Avirato**, **Woo**, **MEC**, etc.) y proyectos de **red federada** a medida.

Las **recargas** se hacen en **Xabia Agent → Cartera / Wallet**. Los addons **añaden funciones**, pero las conversaciones con IA **siguen usando tokens** salvo donde un addon indique lo contrario (respuestas “ligeras” sin modelo).

---

## 14. Cartera / Wallet

Menú: **Xabia Agent → Cartera / Wallet**.

### 13.1 Qué muestra esta pantalla

- **Saldo actual** de tokens asociados a esta instalación cuando el servicio lo confirme.
- Un **identificador de cliente o licencia** que verá también en algunos enlaces de pago. **No** es necesariamente el mismo texto que la cadena secreta **`xabia_…`** que pega en *Conexión a la IA*. Si necesita estar seguro, use **«Ver licencia guardada»** en la pantalla principal.
- **Histograma de consumo** de las últimas semanas para hacerse una idea visual del ritmo de gasto.
- **Renovar año** aparece habitualmente cuando se acerca la fecha de caducidad (ventana habitual de orden de treinta días).

### 13.2 Cómo **recargar tokens**

1. Compruebe que en **Conexión a la IA** tiene la **licencia larga correcta** (la que fue el correo o la clave activable, normalmente formato `xabia_…`).
2. Entre en **Cartera / Wallet** como administrador.
3. Pulse **Recargar** en el pack que se ajuste a su público (consulte importes y tamaños vigentes en [xabia.ai/precio](https://xabia.ai/precio)).
4. Complete el pago en la pasarela que se abre. El sistema ya envía detrás los datos necesarios para **asociar la compra a este sitio**; suele hacer falta esperar **unos minutos** y después pulsar **Actualizar saldo** en la pantalla principal hasta que coincida.

**Comprado desde otro ordenador:** no suele haber problema mientras sea la misma combinación dominio + licencia que espera la tienda para su sitio.

**Multisite o una copia de prueba (staging):** fíjese bien en **desde qué dirección URL** tiene instalado Xabia: la compra puede quedar vinculada a esa máquina concreta.

### 13.3 Renovar solo la licencia

El botón de renovación debe **continuar la misma licencia**, no crear otra nueva. El importe vigente figura siempre en [xabia.ai/precio](https://xabia.ai/precio). Si la fecha sigue vieja después de esperar un rato y de **Actualizar saldo**, abra incidente de soporte con el comprobante de pago.

### 13.4 Aviso de saldo bajo

Si el saldo baja de orden de las **decenas de miles** de tokens, puede ver un mensaje destacado recordándole revisar Wallet antes de que el público encuentre cortes por falta de créditos.

---

## 15. Pantalla Addons

Cuadrícula de **complementos de pago** (Avirato, Central, MEC, Woo… según instalación): estado, enlaces a **Plugins** y, para los que usan suscripción vía Hub, un **formulario de licencia** propio del addon (no sustituye a la licencia **Xabia del sitio** de la pantalla principal). **Smart QR / tótems no aparece aquí:** está integrado en el Core (pestaña del agente).

- **Tienda:** enlace habitual a **[polar.sh/digixop](https://polar.sh/digixop)**.
- **Portal del cliente** (gestionar suscripción): **[polar.sh/digixop/portal](https://polar.sh/digixop/portal)**.
- Si un addon con licencia no valida o la suscripción está a punto de caducar, el aviso aparece **aquí**; en el chat público de addons como Avirato el visitante recibe mensajes **genéricos** de contacto, no errores técnicos de licencia.

El **ZIP** del instalador y el contrato siguen llegando por el canal comercial o el correo habitual de Xabia.

---

## 16. Orden práctico para no perderse

1. Modalidad **Cloud** vs **infra propia**, licencia y saldo (pantalla principal).  
2. **Crear agente** — nombre identificativo y cópielo en el shortcode donde quiera chat.  
3. Elegir **fuente** de datos — mapear con calma; pruebas en staging si existe.  
4. **Sincronizar datos** → mirar estadísticas lateral → si usa búsqueda inteligente, **Entrenar** hasta que los contadores pinten coherentes.  
5. Afinar **Personalidad** → probar Playground → publicar página con shortcode → vigilar gasto en Wallet.

---

## 17. Problemas frecuentes (qué revisar antes de escribir a soporte)

| Situación | Primer chequeo rápido |
|-----------|-----------------------|
| El chat menciona falta licencia o saldo | Cartera más **Actualizar saldo**; compruebe no haber pegado sólo puntos tipo contraseña falsa donde iba una clave real |
| Pagué pero el saldo no sube | Espere algunos minutos; **Actualizar saldo**; confirme que compró desde el sitio esperado mismo dominio |
| Google Cloud dice error al entrenar | Ruta JSON, permisos y región coherentes su panel Google |
| Con Cloud siguen errores extraños entre modelos | A veces toca soporte porque el lado central puede estar corrigiendo un modelo puntual para su zona |
| **Avatar parlante no abre a pantalla completa** | Apariencia → **Avatar parlante** | Core **≥ 1.0.200**: el mute de voz no desactiva el modo inmersivo; vacíe caché CSS/JS |
| **Respuestas sin negrita/listas** | Actualizar Core | Core **≥ 1.0.201** renderiza Markdown básico (`**negrita**`, viñetas) en el chat |
| **Imagen rota o no carga en el chat** | Mapeo + versión Core | Core **≥ 1.0.202**: rutas relativas en `[ACTION:IMG:…]` se resuelven con la base de imágenes del agente; compruebe rol **Imagen** y URL válida |
| **Reserva Amelia no abre el formulario** | Apariencia / Amelia | Core **≥ 1.0.202**: fallback automático (evento JS → clic en widget → URL trigger); configure URL de reserva Amelia si aplica |
| **MEC remoto muestra botón de reserva local** | Addon MEC + Core | Core **≥ 1.0.202**: solo enlace `[ACTION:URL:Link]` en catálogos SQL remotos; reserva local solo con MEC instalado en el mismo WordPress |
| “No encuentra” lo que cargó en CSV | ¿Ejecutó **Sincronizar** tras subir el archivo? ¿Pulsó **Entrenar** si usa búsqueda inteligente? Baje un poco los controles de **confianza del contexto** y de **similitud**: demasiado altos hacen que el asistente “no vea” textos relacionados pero no idénticos |
| Listado de empresas lento o incompleto | Core **≥ 1.0.118**; compruebe mapeo **ENTE** y taxonomía; pruebe listado nativo (§11) antes de depurar Hub |
| «La última» no devuelve contacto | Tras listado, pregunte en el mismo chat; roles **Teléfono**/**Email**/**Web** en mapeo; versión **≥ 1.0.125** |
| Mezcla confusa con **multi‑fuente** | Revise el **segundo bloque** de mapeo con el mismo cuidado que el primero |
| WPML: saludo o placeholder no cambia de idioma | Core **≥ 1.0.72**, caché vacía, String Translation con traducciones completas (§10.12) |
| WPML: cadenas con bandera incorrecta | Idioma original de cadenas = Español; Utilidades → `xabia-intelligence` |

---

## 18. Preguntas frecuentes

### ¿Tengo que sincronizar para que liste empresas al instante?

**No** para listados nativos y seguimientos de contacto/imagen (Core ≥ 1.0.118): el Core consulta WordPress en vivo según su mapeo. **Sí** si quiere RAG vectorial para preguntas abiertas sobre el catálogo.

### ¿Cuántos agentes puedo crear con una licencia?

En el uso típico retail, **uno o varios agentes en el mismo dominio** están incluidos sin coste extra por agente. **Confirme** siempre los límites exactos en su **correo de bienvenida**, en la página de precios oficial o con su comercial si el proyecto es grande.

### ¿Qué es exactamente un “token”?

Es la unidad con la que se mide cuánto trabajo de lenguaje ocupa una conversación (**texto que entra más texto que sale** cuando se usa el modelo). El **entrenamiento** de la memoria inteligente también consume tokens. Cuando la respuesta viene de una **plantilla fija** (sin modelo), muchas veces **no gasta** ninguno.

### ¿Tengo que pegar mi clave de OpenAI si uso Xabia Cloud?

Por lo general **no**. Con **Cloud** y licencia con saldo el tráfico habitual va por Xabia. La clave OpenAI entra en juego sobre todo en **Infraestructura propia**.

### ¿Por qué “no recuerda” mis tablas o PDF si los tengo en el servidor?

Debe **incorporar** ese conocimiento con **Sincronizar** (y **Entrenar** si activó búsqueda por similitudes). Los addons que aportan datos en vivo (reservas, etc.) son un **canal aparte** que no sustituye ese paso si usted quiere que el asistente cite su documentación.

### ¿Puedo usar copia de **pruebas** (`staging`) con la misma licencia que producción?

Depende de **condiciones contractuales**: dos direcciones públicas distintas a veces cuentan como **dos instalaciones**. Pregunte antes de mover tráfico real.

### ¿Si elimino la licencia se borran mis agentes?

**No** desaparecen de la lista ni su configuración borrada automáticamente, pero el **chat dejará de funcionar** bien en Cloud hasta que pegue **otra licencia válida**.

### ¿Hay saldo en la cartera pero el visitante ve “modo mantenimiento”?

Mire en **Personalidad** el **tope diario de tokens por agente**. La barra lateral **Tokens hoy** muestra si ya alcanzó ese techo para el día técnico en curso; al día siguiente ese contador vuelve a cero para el agente, aunque su **cartera global** siga teniendo saldo disponible para otros agentes u otros días.

## Notas de versión (Core)

### Core v1.0.208 (agosto 2026)
- Actualizaciones: corrige el aviso en **Plugins** cuando WordPress aún no había creado el transient `update_plugins`.
- Actualizaciones: revalidación del Hub cada 5 minutos si la caché local coincide con la versión instalada.

### Core v1.0.207 (agosto 2026)
- Actualizaciones automáticas también en modo LITE (sin licencia PRO activa).
- Panel de versión en el admin LITE con «Comprobar ahora».

### Core v1.0.206 (agosto 2026)
- Checkout Polar: enlaces «Contratar suscripción» envían `domain` y `license_key` al Hub.
- Addons: badge «Hub Polar» debajo del título del panel.

### Core v1.0.205 (agosto 2026)
- Retail: si Core arranca sin licencia (UI limitada), permite pegar la licencia Xabia y activar PRO sin WP-CLI.

### Core v1.0.202 (agosto 2026)
- Acciones de chat: imágenes relativas en `[ACTION:IMG:…]`; fallback de reserva Amelia; reglas estrictas MEC remoto (`[ACTION:URL:Link]` únicamente).
- Monorepo alineado con producción: API completa y addons Woo/MEC/Avirato.

### Core v1.0.201 (agosto 2026)
- Chat: Markdown básico en respuestas; UI stream sin burbujas; avatar parlante / launcher; preguntas de arranque; empaquetado completo del ZIP.

### Core v1.0.168–1.0.171
- Latencia chat, Document-to-RAG, pasaporte remoto, listados, WPML/DTP (ver historial técnico en DESARROLLO.md).

<div class="manual-pdf-cierre">

## Xabia AI — La web viva

Sabiduría natural con inteligencia artificial

Xabia AI está especialmente indicada para empresas y asociaciones del sector del turismo, instituciones, empresas y tiendas online que quieran incrementar su conversión.

Xabia AI está desarrollada por Digixop.

**Xabia AI** · Garaizar, 2 · 48004 Bilbao (SPAIN)

[help@xabia.ai](mailto:help@xabia.ai)

</div>
