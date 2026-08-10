> **Versión canónica online:** [https://xabia.ai/docs/manual-usuario-xabia-avirato.pdf](https://xabia.ai/docs/manual-usuario-xabia-avirato.pdf) — este archivo se incluye en el ZIP del addon.

# Manual de usuario — Xabia Avirato (addon para Avirato)

## Guía rápida de instalación

1. Instale y active **Xabia Agent Core**.
2. Instale y active el ZIP **Xabia Avirato** desde **Plugins → Añadir nuevo → Subir plugin**.
3. En **Xabia Agent → Addons**, active o sincronice la licencia del addon **Avirato** hasta que el Hub la reconozca.
4. Cree o edite el agente que usará reservas y abra la pestaña **Avirato**.
5. Introduzca el **ID de establecimiento (webcode)**, la **URL del motor** y, si hace falta, filtros de inclusión/exclusión.
6. Guarde el agente y pruebe disponibilidad con fechas reales en **Playground**.
7. Publique el chat con shortcode o con la interfaz nativa del Core.
8. Verifique que los enlaces de reserva abren el motor Avirato con fechas e IDs correctos.

---

## Parte 1 — Qué es y para qué sirve

### 1.1 Definición breve

**Xabia Avirato** es un **complemento** (plugin extra) que se instala sobre **Xabia Agent Core**. Conecta su chat Xabia con el **motor de reservas online de Avirato** que sus huéspedes ya conocen (la dirección habitual es la de booking de Avirato) para poder:

- Consultar si hay habitaciones o casas **libres u ocupadas** en fechas concretas.
- Mostrar **precios** cuando el motor los devuelve.
- Generar **enlaces de reserva** con los identificadores correctos.
- Reconocer cuando el visitante pide una **casa o habitación concreta** por nombre.
- Proponer **alternativas** si la casa pedida está llena y, cuando es posible, indicar una **próxima fecha** con disponibilidad.

No sustituye al panel de Avirato ni a la configuración legal de precios y condiciones en su web; es una **capa de consulta automatizada** para el chat.

### 1.2 Qué problema resuelve

Sin este addon, un asistente con IA puede **inventar** disponibilidad o remitir genéricamente “a la web”. Con Xabia Avirato, las respuestas sobre disponibilidad se apoyan en lo que devuelve el **motor real** de reservas, de forma ordenada y con enlaces útiles.

### 1.3 Requisitos imprescindibles

| Requisito | Qué significa para usted |
|-----------|---------------------------|
| **WordPress** funcionando | El addon es un plugin de WordPress. |
| **Xabia Agent Core** instalado y **activo** | Sin el Core el addon no carga correctamente y puede mostrar un aviso de error en el escritorio. |
| **Licencia con add-on Avirato** | La disponibilidad en tiempo real se valida en el **Hub** desde **Xabia Agent → Addons**, con la clave del addon **Avirato**. Si no hay suscripción activa, los **avisos técnicos** aparecen solo en el escritorio (Addons / registro); en el **chat público** el visitante verá un mensaje **genérico** para contactar con el establecimiento (villas + calendarios exactos), sin mensajes de «módulo no activo». |
| **Establecimiento en Avirato** | Debe tener un establecimiento dado de alta en Avirato con motor de reservas accesible. |
| **ID de establecimiento (webcode)** | Es el código numérico que usa Avirato en la URL del motor; sin él no hay consultas válidas (salvo configuración especial con otro plugin; ver más abajo). |

---

## Parte 2 — Instalación paso a paso

### 2.1 Antes de empezar

1. Tenga a mano el **archivo ZIP** del complemento que le haya facilitado Xabia o su distribuidor (el nombre puede variar en cada paquete).
2. Confirme que **Xabia Agent Core** ya aparece instalado en **Plugins** y está **activo**.

### 2.2 Instalar el ZIP en WordPress

1. Entre en el escritorio de WordPress como administrador.
2. Vaya a **Plugins > Añadir nuevo**.
3. Pulse **Subir plugin**.
4. Elija el archivo ZIP de **Xabia Avirato**.
5. Pulse **Instalar ahora** y, a continuación, **Activar plugin**.

### 2.3 Orden correcto de activación

- Lo habitual es: **primero Core**, **después** Xabia Avirato.
- Si activa Xabia Avirato sin tener el Core activo, WordPress mostrará un aviso informando que hace falta el plugin principal de Xabia antes.

### 2.4 Comprobar que el addon está reconocido

En **Xabia Agent → Addons** puede ver **Xabia Avirato** en las tarjetas, **introducir o revisar la clave de licencia del addon** (validación Hub) y acceder a la **tienda Polar** ([polar.sh/xabia](https://polar.sh/xabia)) y al **portal del cliente** ([polar.sh/xabia/portal](https://polar.sh/xabia/portal)) para suscripciones. Normalmente el addon **no tiene un menú propio aparte**: el **ID de establecimiento**, URL del motor y filtros se configuran **dentro de cada agente**, en la pestaña **Avirato** (véase más abajo).

> 📢 **Nota sobre tarifas:** el precio de la suscripción Avirato (y del resto de addons) se actualiza en tiempo real en **[https://xabia.ai/precio](https://xabia.ai/precio)**. En el panel de Addons verá un texto orientativo, no una tarifa fija impresa.

---

## Parte 3 — Dónde se configura (navegación en el escritorio)

### 3.1 Ruta general

1. **Xabia Agent** (o el nombre del menú que use su instalación para el Core).
2. Edite el **agente / proyecto** que debe usar Avirato (cada sitio puede tener varios agentes).
3. Busque la pestaña **«Avirato»** en la ficha del agente.

### 3.2 Cómo se guardan los datos

Los campos se guardan al pulsar **Guardar agente** en esa misma pantalla — no tiene que buscar ningún archivo suelto: WordPress custodia esa información en **su instalación**.

---

## Parte 4 — Campos de configuración (explicación detallada)

### 4.1 ID de establecimiento

- **Qué es:** el **código web** (webcode) del establecimiento en Avirato, el mismo que usa el motor en la URL de reservas.
- **Formato:** normalmente un número (ejemplo ilustrativo: `12345678`).
- **Importancia:** es el campo **más crítico**. Si está vacío o mal copiado, no habrá lectura correcta del motor.
- **Configuración automática (opcional):** si ya usa el plugin **Avirato Calendar** en la misma web y ese plugin tiene cargado correctamente el **webcode**, el campo puede completarse solo. Sin ese calendario, copie siempre el número a mano desde su panel Avirato.

### 4.2 Nombre público del alojamiento

- **Qué es:** el nombre con el que el asistente hablará de “su” oferta cuando no haya otro contexto (por ejemplo “Casa rural El Roble”).
- **Si lo deja vacío:** se usará el **filtro de inclusión** como etiqueta legible, o un texto genérico del tipo “el alojamiento configurado”.

### 4.3 Filtro de inclusión

- **Qué es:** un **fragmento de texto** que deben contener los nombres de tipos de espacio (subtipos) que **sí** quiere ofrecer.
- **Ejemplo:** si todas sus casas llevan en el catálogo “demo-rural”, puede poner `demo-rural` para que solo esas entren en las respuestas.
- **Efecto técnico:** limita qué líneas del catálogo se consideran “válidas”; si es demasiado estricto, puede parecer que “no hay disponibilidad” aunque en Avirato haya otras habitaciones.

### 4.4 IDs de habitación / casa (opcional)

- **Qué es:** uno o varios números internos de tipo de espacio (**habitaciones o casas** en el sistema Avirato), separados por guiones cuando son varios.
- **Automático:** si rellenó el **filtro de inclusión** y el ID de establecimiento es correcto, el complemento puede **rellenar esos números** trayéndolos del **Avirato Calendar** que tenga en WordPress.
- **Manual:** puede fijarlos usted si conoce los IDs exactos.

### 4.5 Lista de exclusión

- **Qué es:** palabras separadas por **coma** (sin complicar el formato).
- **Uso:** excluir alojamientos cuyo nombre contenga ese fragmento (por ejemplo pruebas, casas cerradas temporada, etc.).
- **Comparación:** no distingue mayúsculas; se usa el nombre normalizado en minúsculas.

### 4.6 Código promocional (opcional)

- Si su campaña usa un código en el motor, puede indicarlo aquí para que las URLs generadas incluyan el parámetro de promoción cuando Avirato lo soporte en la misma forma que el addon construye la petición.

### 4.7 URL del motor

- **Valor típico:** la dirección web del motor de reservas que le da Avirato (**booking** habitual, suele recomendarse dejar también la **/** final como indique su equipo). **Cámbiela solo** si su proveedor le ha dicho usar **otra dirección**. Si la escribe mal, el chat no podrá consultar bien la disponibilidad.

---

## Parte 5 — Gestión día a día

### 5.1 Verificar licencia y addon

- **Licencia Xabia del sitio y saldo de tokens** se gestionan en la pantalla principal del Core: **[Manual del Core — Conexión a la IA y Cartera](./manual-usuario-xabia-core.md)**.
- **Licencia del addon Avirato** (suscripción validada en el Hub) se introduce en **Xabia Agent → Addons**, en la tarjeta de Avirato. Allí puede comprar o renovar en **[Polar — xabia](https://polar.sh/xabia)** (tarifas en [xabia.ai/precio](https://xabia.ai/precio)) y gestionar la suscripción en el **[portal Polar](https://polar.sh/xabia/portal)**. Si la fecha de renovación está a **30 días o menos**, el escritorio puede mostrar un **aviso** en esa misma pantalla.
- Si tras contratar o pegar la clave el Hub aún no reconoce el addon, espere unos minutos, vuelva a cargar **Addons** y, si hace falta, contacte con **soporte** con el comprobante. **No** espere que el visitante vea errores técnicos de licencia en el chat: solo mensajes orientativos de contacto.

### 5.2 Cambios de temporada o catálogo

- Si añade o renombra casas en Avirato, revise el **filtro de inclusión** y la **lista de exclusión** para que sigan teniendo sentido.
- Si usa el calendario Avirato en WordPress, sincronice o actualice datos según el procedimiento de ese plugin para que los **subtipos** y webcodes estén al día.

### 5.3 Probar antes de publicitar

1. Abra el **playground** o el chat en una página de prueba.
2. Pruebe frases con **fechas claras** (véase Parte 6).
3. Pruebe el nombre de **una casa concreta** que exista en el catálogo.
4. Pulse el **enlace de reserva** y confirme que abre el motor con fechas e IDs coherentes.

### 5.4 Privacidad y datos

- El addon consulta el **motor público** de reservas (petición HTTP al mismo tipo de URL que vería un usuario en el navegador). No sustituye a políticas de privacidad ni a los textos legales de su web; el responsable del sitio debe seguir cumpliendo RGPD y condiciones de Avirato.

---

## Parte 6 — Cómo lo usa el visitante (experiencia en el chat)

### 6.1 Qué frases “activan” la disponibilidad

El sistema busca **intención de disponibilidad** o **fechas** en el mensaje. Entre otras, reacciona a términos como: disponibilidad, reserva, alojamiento, noches, estancia, nombres de meses, “esta semana”, “fin de semana”, “semana santa”, rangos de fechas en texto o formato `DD/MM/AAAA` o `AAAA-MM-DD`, etc.

### 6.2 Fechas en lenguaje natural

El addon interpreta muchas expresiones en español, por ejemplo:

- Semanas del mes (“primera semana de julio”), quincenas, mediados de mes, principios/finales de mes.
- “Una semana” o duración en **noches** o **días** (ajusta la salida según lo que diga el usuario).
- Algunas expresiones fijas de calendario (p. ej. ciertos puentes o festividades según la versión del plugin).

Si el usuario es vago con las fechas, el sistema **deduce** un rango razonable; cuanto más concreto sea el visitante, más fiable es el resultado.

### 6.3 Idioma del motor

- El idioma con el que se consulta la página de disponibilidad **sigue al idioma elegido por el visitante en el chat** (español, inglés, etc.). Si no llega ese dato desde el frontal, habitualmente habrá español como respaldo.
- Desde el Core v1.0.57, la respuesta de la IA es **políglota**: Xabia intenta responder en el idioma del último mensaje del usuario aunque la configuración del sitio o del shortcode esté en otro idioma. El parámetro de idioma sigue siendo útil para interfaz, voz y consulta del motor, pero no bloquea la redacción final del asistente.

### 6.4 Pedir una casa o habitación por nombre

- Si el nombre coincide con un alojamiento de su lista en **Avirato Calendar**, o cuando en el proyecto del Core marca una **IDENTIDAD (ENTE)** coherente, el sistema puede **centrarse** primero en esa unidad ante la misma fecha.
- Si esa unidad está **ocupada**, el mensaje lo indicará, mostrará **otras opciones** libres en las mismas fechas si las hay, y puede sugerir la **próxima** ventana disponible buscando fechas cercanas.

### 6.5 Forzar una consulta “fresca” (sin depender de caché reciente)

Si el visitante escribe cosas como **actualiza**, **refresca**, **mira otra vez**, **vuelve a comprobar**, etc., el sistema puede **saltar** la caché reciente y volver a consultar el motor.

### 6.6 Enlaces de reserva

Cuando haya plaza, la respuesta incluirá normalmente **un botón de reserva o un enlace** listos para clicar según cómo monte visualmente la plantilla de Xabia.

---

## Parte 7 — Consumo de tokens e IA

- Las respuestas **directas sobre disponibilidad** con la plantilla de este complemento pueden resolverse **sin llamada al modelo de lenguaje** en ese momento, de modo que **muchas consultas prácticas de fechas pueden no consumir tokens**.

---

## Parte 8 — Mensajes habituales y qué significan

| Situación | Qué suele significar |
|-----------|---------------------|
| En el **chat público**, texto genérico del tipo «contacte…», «villas», «calendarios exactos» (sin detalle técnico) | El addon puede estar desactivado, la **clave en Addons** no valida aún, o la suscripción no está activa. **Revise Xabia Agent → Addons** y el registro (`debug.log` si lo tiene); el visitante no debe ver textos tipo «módulo no activo». |
| “No he podido leer correctamente el motor de reservas…” | Fallo técnico al obtener o parsear la página del motor (red, cambio HTML, URL incorrecta). |
| Siempre “sin disponibilidad” | Revise ID de establecimiento, URL del motor y filtros de inclusión/exclusión; pruebe fechas donde sepa que hay plazas. |
| El enlace abre demasiadas habitaciones | Revise si el **ID de habitación** manual es demasiado amplio o si los filtros no acotan bien. |

---

## Parte 9 — Solución de problemas (checklist)

1. **Core activo** y **Xabia Avirato activo**.
2. **ID de establecimiento** correcto (copiado del entorno Avirato / web del motor).
3. **URL del motor** correcta (`https://booking.avirato.com/` salvo indicación contraria).
4. **Filtro de inclusión** no demasiado restrictivo para pruebas iniciales (puede dejarse vacío al principio para validar lectura y luego afinar).
5. **Exclusiones:** confirme que no está excluyendo por error todo el catálogo.
6. **Xabia Agent → Addons:** clave de **Avirato** validada en el Hub (y suscripción activa en Polar si aplica).
7. **Prueba en incógnito** tras limpiar caché del sitio si usa plugins de caché agresivos en el frontal.
8. Si usa **Avirato Calendar**, confirme que ha refrescado allí establecimientos y tipos antes de esperar numeración automática de habitaciones aquí dentro.

---

## Parte 10 — Buenas prácticas

- Validar el **webcode** con una reserva manual en el motor antes de confiar en el chat.
- Ajustar **nombre público** y **filtros** para que el tono del bot coincida con su marca.
- No usar PDFs ni documentos estáticos como sustituto de **disponibilidad en vivo**; el valor del addon es precisamente la consulta al motor.
- Revisar periódicamente los **enlaces** tras actualizaciones grandes de Avirato o del tema del sitio.

---

## Parte 11 — Soporte

**Xabia** (instalación, licencia Core, complementos): mismo correo de ayuda habitual de Xabia.

**Temas sólo del contrato con Avirato** (no del plugin Xabia): su contacto comercial Avirato.

Para **licencia**, **Cartera** y ajustes globales abra el **[Manual del Core](./manual-usuario-xabia-core.md)**.

<div class="manual-pdf-cierre">

## Xabia AI — La web viva

Sabiduría natural con inteligencia artificial

Xabia AI está especialmente indicada para empresas y asociaciones del sector del turismo, instituciones, empresas y tiendas online que quieran incrementar su conversión.

Xabia AI está desarrollada por Digixop.

**Xabia AI** · Garaizar, 2 · 48004 Bilbao (SPAIN)

[help@xabia.ai](mailto:help@xabia.ai)

</div>
