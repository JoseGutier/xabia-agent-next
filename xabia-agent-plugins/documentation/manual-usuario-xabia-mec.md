# Manual de usuario — Xabia Modern Events Calendar (addon MEC)

> **Addon:** Xabia MEC **v1.0.3** · **Core requerido:** **≥ 1.0.166** (recomendado)

## Guía rápida de instalación

1. Instale y active **Xabia Agent Core** (`v1.0.166` o superior recomendado).
2. Instale y active el ZIP **Xabia MEC** (`xabia-mec-1.0.3.zip`) desde **Plugins → Añadir nuevo → Subir plugin**.
3. En **Xabia Agent → Addons**, active o sincronice la suscripción **xabia-mec** hasta ver **Hub Polar: activa**.
4. Si los eventos están en el mismo WordPress, active también **Modern Events Calendar** y publique eventos `mec-events`.
5. Si los eventos están en otra web, no hace falta MEC local: rellene las credenciales SQL remotas en el agente y use **Fuente de información → Addon nativo → MEC**.
6. Pulse **Conectar y Mapear**, revise el preset de columnas y guarde el agente.
7. Pulse **Sincronizar datos** y, si usa búsqueda vectorial, **Entrenar IA**.
8. Pruebe preguntas como “¿qué eventos hay este fin de semana?” en **Playground** antes de publicar el shortcode.

**Asistente CPT (≥ Core 1.0.162):** con fuente addon MEC lista solo `mec-events` (valida en SQL remoto si hay host). **Remoto híbrido (≥ 1.0.163):** si configura host SQL, el catálogo se trata como remoto aunque MEC esté instalado localmente (evita mezclar IDs). Deep schema incluye plazas `mec_available_slots` y etiquetas `mec_*`.

---

## Parte 1 — Qué es y para qué sirve

### 1.1 Definición breve

**Xabia MEC** es un **complemento** (plugin independiente) que se instala junto a **Xabia Agent Core**. Conecta su asistente (por ejemplo **Izar** u otro agente configurado) con **Modern Events Calendar (MEC)** para:

- **Sincronizar** el calendario de eventos del sitio hacia la **base de conocimiento** del agente (texto y, si lo configura, vectores para búsqueda semántica).
- Responder con **fechas, lugares, precios, categorías** y textos tomados de los eventos publicados.
- Mostrar **plazas libres** cuando MEC tiene definida capacidad y reservas registradas (campo calculado `mec_available_slots`).
- Ofrecer **botones de reserva** (`[ACTION:BOOK]`) que llevan al visitante a la **página del evento** con ancla de reserva (`#book`) cuando el evento tiene **reserva / entradas** configuradas en MEC.
- Exponer opcionalmente un **feed REST** para **federación** (Xabia Central u otros nodos) con eventos publicados y metadatos alineados al conocimiento.

No sustituye al panel de MEC ni a la configuración legal de precios, cancelaciones ni pasarelas de pago; es una **capa de conversación e información** sobre los datos que ya tiene en WordPress.

### 1.2 Qué problema resuelve

Sin este addon, un asistente solo puede **inventar** fechas o copiar texto estático. Con **Xabia MEC**, las respuestas pueden basarse en **consultas SQL coordinadas** a los posts `mec-events` y metadatos habituales de MEC, y en reglas de contexto que ayudan a la IA a interpretar preguntas como «¿qué hay este fin de semana?» o «¿quedan plazas?».

### 1.3 Identificación del producto (referencia útil)

- **Checkout Polar (suscripción):** [https://buy.polar.sh/polar_cl_wEzwnqMvZIrPelny1I5HNIsdcVjGs1UO12Roj3zzxIm](https://buy.polar.sh/polar_cl_wEzwnqMvZIrPelny1I5HNIsdcVjGs1UO12Roj3zzxIm)  
- **ID de producto Polar (UUID):** `8078756b-c566-4557-a55d-3712d8e47c44` — puede pedirlo a soporte si gestiona facturación o webhook en el Hub.

### 1.4 Requisitos imprescindibles

| Requisito | Qué significa para usted |
|-----------|---------------------------|
| **WordPress** funcionando | El Core y Xabia MEC son plugins de WordPress. |
| **Xabia Agent Core** v **1.0.57 o superior recomendado** instalado y **activo** | Sin el Core, el plugin **Xabia MEC** muestra un aviso y **no carga** la integración. |
| **Plugin Xabia MEC** instalado y **activo** | El conector SQL, el enriquecimiento de filas y el menú **Puente MEC** viven en este plugin (ya **no** van dentro del ZIP del Core). |
| **Modern Events Calendar (Lite o Pro)** activo y con tipo de contenido **mec-events** | Necesario cuando los eventos están en el mismo WordPress. En escenario remoto, MEC puede estar solo en la web origen y Xabia MEC se conecta por SQL desde el sitio del chat. |
| **Suscripción Polar / Hub** para el slug **xabia-mec** | La sincronización del conector «premium» y el uso del motor de reservas en chat quedan validados contra el **Hub** desde **Xabia Agent → Addons**. Sin suscripción activa, puede ver **«Hub Polar: inactiva»** y el botón **«Sincronizar datos»** deshabilitado cuando el agente usa la fuente **Addon MEC**. |
| **Opcional:** add-on de **reservas** del Core (`integrations/reservas`) | Para unificar reglas de intención de reserva y disponibilidad con MEC en el sistema de prompts. |

---

## Parte 2 — Instalación paso a paso

### 2.1 Antes de empezar

1. Tenga los **ZIP**: **Xabia Agent Core** y **Xabia MEC** (y MEC desde el repositorio oficial de Webnus si aún no lo tiene).
2. Confirme que su hosting permite **REST API** (`/wp-json/`) si usará **federación** o integraciones cloud.

### 2.2 Orden recomendado de activación

1. **Xabia Agent Core** → Activar.  
2. **Modern Events Calendar** → Activar.  
3. **Xabia MEC** → Activar **después** del Core.  

Si activa **Xabia MEC** sin el Core, verá un aviso en el escritorio: debe activar primero **Xabia Agent Core**.

### 2.3 Instalar Xabia MEC desde ZIP

1. Entre como **administrador**.  
2. **Plugins → Añadir nuevo → Subir plugin**.  
3. Elija el ZIP de **Xabia MEC**.  
4. **Instalar ahora** → **Activar plugin**.  

### 2.4 Comprobar que WordPress reconoce el plugin

En **Plugins** debe aparecer **Xabia MEC**. En **Xabia Agent → Addons**, la tarjeta **«Xabia — Modern Events Calendar»** debe mostrar el estado **WordPress** (activo / instalado / no instalado) junto al archivo `xabia-mec/xabia-mec.php`.

---

## Parte 3 — Pantalla «Addons»: suscripción Polar y licencia

Abra **Xabia Agent → Addons**. Allí gestiona **todas** las opciones comerciales del addon MEC.

### 3.1 Textos generales de la pantalla

- **Título «Addons»** y subtítulo: explican que las insignias **Hub Polar** son la suscripción válida en el servidor Xabia, **independientes** de si el plugin está activo en WordPress.
- **Botón «Sincronizar licencias con el hub»** (barra superior de la zona Polar): fuerza de nuevo la comprobación del Hub para **todas** las tarjetas de suscripción. Úselo tras pagar en Polar o pegar una clave nueva.
- **«Abrir plugins de WordPress»**: acceso rápido a **Plugins** por si debe activar **Xabia MEC**.

### 3.2 Tarjeta de «Xabia — Modern Events Calendar» — cada elemento

| Elemento | Qué es |
|---------|--------|
| **Insignia «Hub Polar: activa / inactiva»** | Indica si su **licencia** incluye el addon **xabia-mec** según el Hub. No confundir con el estado del plugin WP. |
| **Aviso de renovación** (si aparece) | Si la renovación está cerca, el sistema puede mostrar un **banner** con días restantes; use el enlace a **Polar** para renovar. |
| **Icono y título** | Identificación visual del producto. |
| **Precio / tarifas** | Texto orientativo; las tarifas vigentes están siempre en **[xabia.ai/precio](https://xabia.ai/precio)** y el importe final lo confirma **Polar** en el checkout. |
| **Descripción corta** | Resumen comercial de funciones (eventos, plazas, reserva asistida). |
| **«El plugin está activo… Hub Polar: inactiva»** | Aviso de **consistencia**: el ZIP está activo pero el Hub aún no reconoce la suscripción — revise clave y pulse **Sincronizar licencia** en la tarjeta o el botón global del hub. |
| **«Estado en WordPress:»** | **activo** / **instalado (inactivo)** / **no instalado**, más la ruta `xabia-mec/xabia-mec.php`. |
| **Campo «Clave de licencia»** | Pegue la **misma clave** que muestra Polar / el correo de compra (debe coincidir **carácter a carácter** con la licencia del sitio; suele ser la misma que en **Conexión a la IA**). |
| **Texto de ayuda bajo la clave** | Indica que el Hub activa el add-on sobre la licencia del sitio. |
| **«Suscripción activa con la licencia principal del sitio»** | Si el Hub validó el addon usando la **licencia Core** sin clave separada en el campo (según configuración del proveedor). |
| **Bloque de estado** | Muestra fechas de **alta** y **vencimiento / renovación** si el Hub las devuelve. |
| **Botón «Sincronizar licencia»** (fantasma):** vuelve a consultar el Hub **solo** para este addon. |
| **Botón «Activar» o «Actualizar suscripción»**: guarda el formulario (nonce incluido) y **persiste** la clave en la base de datos del sitio. |
| **«Contratar suscripción»** (si el Hub dice inactivo): abre el **checkout Polar** del producto MEC: [enlace de compra](https://buy.polar.sh/polar_cl_wEzwnqMvZIrPelny1I5HNIsdcVjGs1UO12Roj3zzxIm). |
| **«Gestionar en Polar»** (si ya está activo): abre el **portal de cliente** configurado en el catálogo (gestión de suscripción, facturas, método de pago). |

### 3.3 Después de comprar en Polar

1. Copie la **clave de licencia** que le entrega Polar.  
2. En **Addons**, péguela en la tarjeta MEC y pulse **Activar** / **Actualizar suscripción**.  
3. Pulse **Sincronizar licencia** o el **Sincronizar licencias con el hub** global.  
4. Espere unos minutos si el Hub aplica la activación con retraso y vuelva a cargar la página **Addons**.

---

## Parte 4 — Configuración del agente (fuente «Addon MEC»)

Entre en **Xabia Agent**, cree o **edite** el agente que debe conocer los eventos.

### 4.1 Seleccionar tipo de fuente

En **«Fuente de información»** (`source_type`):

- Elija **«Addons Nativos (Conector externo)»** solo si en su instalación el Core detecta **conectores nativos** activos (incluye **Xabia MEC** cuando la licencia Hub y MEC en WordPress son correctas).  
- Si no ve la opción habilitada, revise que **Xabia MEC** esté **activo** y que **Addons** muestre **Hub Polar: activa** para MEC.

### 4.2 Selector de addon (`addon_slug`)

En la sección que aparece al elegir **Addon**, despliegue la lista y seleccione la entrada cuyo nombre sea la de **MEC — Modern Events Calendar** (slug interno **`mec`**). Es el conector registrado por el plugin Xabia MEC.

### 4.3 Aviso informativo bajo el selector

Cuando **MEC** está elegido, verá el texto con icono:

> **MEC Add-on activo: Los campos de reserva y disponibilidad se sincronizan automáticamente.**

Sirve para recordar que **no** tiene que inventar manualmente columnas de «plazas» si usa el preset estándar.

### 4.4 Botón **«Conectar y Mapear»**

1. Pulse **«Conectar y Mapear»**.  
2. El sistema ejecuta una **consulta de prueba** con el SQL del conector (hasta una fila) para listar **columnas** reales.  
3. Si la licencia Hub **no** está activa para MEC, verá un **error** indicando que hace falta la suscripción; no podrá prefijar el mapeo hasta activarla.  
4. Si todo va bien, recibe columnas y, para MEC, un **preset** de filas de mapeo (`fields`) que puede aplicar a la tabla de atributos.

### 4.5 Campos del preset de mapeo (catálogo oficial)

Cada fila del mapeo tiene: **columna CSV/SQL**, **etiqueta legible**, **rol visual**, **ENTE** (checkbox) e **instrucción**. El orden lógico del conector es:

| Columna SQL | Rol sugerido | ENTÉ | Para qué sirve |
|-------------|--------------|------|----------------|
| **ID** | (ninguno especial) | Sí — identidad del registro | ID del post `mec-events`; imprescindible para **reservas** y deduplicación. |
| **Evento** | Título | No | Nombre del evento en chat y búsquedas. |
| **Fecha** | — | No | Fecha de inicio en formato **Y-m-d** (filtros «fin de semana», «próximos», etc.). |
| **Hora** | — | No | Texto compuesto de hora MEC (hora, minutos, AM/PM si aplica). |
| **Lugar** | — | No | Contenido de `mec_location`. |
| **mec_available_slots** | — | No | **Plazas libres** calculadas (capacidad − reservas confirmadas); puede quedar vacío si no hay tope. |
| **Link** | URL | No | En la sincronización se sustituye por el **permalink** real y el filtro `#book` cuando corresponde. |
| **Precio** | — | No | Meta `mec_cost` o texto por defecto «Consultar». |
| **Descripcion** | — | No | Contenido del post (HTML se indexa según el pipeline del Core). |
| **Categorias_Tags** | — | No | Lista de términos unidos (taxonomías MEC). |
| **Imagen_URL** | Imagen | No | URL de la imagen destacada si existe. |

Puede **añadir** columnas extra con **Asistente CPT** u otras herramientas del Core, pero el **preset** cubre el flujo estándar.

### 4.6 Conexión a base remota (host, SQL)

Xabia MEC sigue el mismo patrón que Xabia Woo: el agente puede usar **Addon nativo → MEC** aunque los eventos estén en otra web.

Use este modo cuando:

- El sitio donde vive el chat tiene **Xabia Agent Core** y **Xabia MEC** activos.
- Los eventos reales están en una base de datos WordPress remota, por ejemplo `https://tu-sitio-con-eventos.com`.
- Quiere conservar las reglas semánticas del addon MEC (fechas, lugares, categorías, disponibilidad cualitativa), pero leer los datos por SQL remoto.

Pasos:

1. En **Fuente de información**, seleccione **Addon nativo (conector automático)**.
2. En el desplegable, elija **Modern Events Calendar (MEC)**.
3. Rellene **Host**, **DB**, **Usuario**, **Contraseña** y, si procede, **Prefijo** (`wp_`, `wpdb_`, etc.).
4. Pulse **Conectar y Mapear**.
5. Guarde el agente, sincronice datos y entrene si usa vectores.

Si el campo **Prefijo** se deja vacío, el Core intenta detectar el prefijo activo de la base remota y usa `wp_` como respaldo.

### 4.6.1 Alternativa: preset MEC remoto desde SQL puro

Si no va a instalar **Xabia MEC** en el sitio del chat, puede usar el Core directamente:

1. Elija **Base de Datos Externa (SQL Remoto)**.
2. Pulse **Usar preset MEC remoto**.
3. Rellene credenciales SQL remotas y pruebe la consulta.
4. Guarde, sincronice y entrene.

Esta alternativa crea un catálogo de eventos vía SQL (`sql_preset = mec_remote`) y mapeo automático, pero no carga todas las capacidades del addon MEC. Para instalaciones comerciales con licencia activa, el flujo recomendado es **Addon nativo → MEC**.

### 4.7 Guardar el agente

Pulse **«Guardar agente»** (o el botón equivalente en su pantalla) para persistir `source_type`, `addon_slug`, mapeo y credenciales SQL.

---

## Parte 5 — Barra lateral del agente: memoria, sincronización y playground

Cuando edita un agente **ya creado** (no en «nuevo» vacío), verá la columna lateral de **memoria**. Cada control hace lo siguiente:

| Control | Función |
|---------|---------|
| **«1. Sincronizar datos»** | Lanza la **ingesta** desde la fuente configurada (en addon MEC, el SQL de eventos + enriquecimiento de plazas y enlace). Si **Hub Polar: inactiva** y la fuente es **addon MEC**, el botón puede estar **deshabilitado** y mostrar aviso **«Requiere Licencia Premium…»**. |
| **«2. Entrenar IA»** | Genera embeddings / entrenamiento local según la configuración del Core (vectores en la tabla de conocimiento). |
| **«Sincronizar Cerebro con Xabia Cloud»** | Envía el conocimiento al Hub cloud si usa **Conexión Segura Xabia** / modo cloud. |
| **«Vista previa del conocimiento (esta base)»** | Muestra recuentos y extractos de filas guardadas para ese `project_id`. |
| **«Borrar memoria vectorial»** | Elimina datos de conocimiento del agente (acción irreversible; pide confirmación). |
| **Playground** | Chat de prueba en el escritorio para validar respuestas antes de publicar el shortcode. |
| **Mensaje de saldo / tokens** | Si tiene **Cartera**, puede ver consumo; véase el [Manual del Core](./manual-usuario-xabia-core.md). |

---

## Parte 6 — Cómo debe estar configurado **Modern Events Calendar** en WordPress

El addon **lee** lo que MEC ya guarda. Para que **reservas** y **plazas** tengan sentido:

1. En **M.E. Calendar → Ajustes → Reservas**, active el **módulo de reservas** si usa la función de booking de MEC (según documentación Webnus / su edición Lite o Pro).  
2. En cada **evento**, configure **entradas / tickets** o límites si quiere que exista un **cupo** calculable.  
3. Publique o programe eventos (`publish` / `future`); el SQL del conector filtra fechas **desde hoy** y eventos **raíz** (`post_parent = 0`) para evitar duplicar ocurrencias hijas en el índice.

Si un evento **no** tiene reserva online, el sistema puede **omitir** el botón `[ACTION:BOOK]` aunque la IA proponga texto informativo.

---

## Parte 7 — Experiencia del visitante en el chat

### 7.1 Preguntas que el contexto MEC ayuda a interpretar

Gracias a las **reglas de búsqueda** inyectadas en el router, el modelo tiende a usar bien:

- **Fechas** y expresiones tipo **«este fin de semana»** (el sistema le sugiere filtrar por la columna **Fecha**).  
- **Plazas** cuando existe **mec_available_slots** numérico.  
- **Lugar** (`Lugar` / `mec_location`).  
- **Categorías y etiquetas** unidas en **Categorias_Tags**.

### 7.2 Botón de reserva

Cuando el proyecto usa el motor de **reservas MEC** y el evento tiene **booking** habilitado, la respuesta puede incluir un **enlace o botón** que abre la página del evento (con ancla de reserva por defecto).

En catálogos **MEC remotos** sin MEC local en el WordPress del chat, Xabia **no** genera botones de reserva local (`[ACTION:BOOK:ID]`) desde Core **≥ 1.0.202**: el asistente emite siempre un enlace al campo **Link** del evento remoto (`[ACTION:URL:Link]`). Si el evento tiene reserva en la web origen, esa ficha remota será la que gestione el proceso.

### 7.3 Privacidad

El visitante solo ve lo que el **plugin de chat** muestre. Los mensajes se procesan según la configuración del Core (proveedor de IA, Hub, etc.). Consulte la política de privacidad de su sitio y el [Manual del Core](./manual-usuario-xabia-core.md).

---

## Parte 8 — Puente de federación MEC (menú «Puente MEC»)

Cuando **Xabia MEC** está activo, en el menú de Xabia aparece **«Puente MEC»** (submenú bajo los ajustes del agente).

### 8.1 Qué muestra la pantalla

| Bloque | Contenido |
|--------|-----------|
| **Descripción superior** | Explica que el feed es para **nodos pull** de Xabia Central, que requiere **suscripción MEC** en el Hub y cabecera **X-Xabia-Key** (salvo administrador con sesión). |
| **Aviso de licencia** | Si el Hub no tiene MEC activo, advierte que el endpoint devolverá **403** a clientes externos. |
| **Aviso de clave de puente** | Si no hay clave en **Federación Nexus**, las peticiones anónimas con cabecera fallarán. |
| **URL del endpoint** | Copiable; suele ser `…/wp-json/xabia/v1/federation-events`. |
| **Instrucción de cabeceras** | **X-Xabia-Key** o **X-Xabia-Fed-Key** con el **secreto del puente** definido en **Xabia Agent → Federación**. |
| **Mapeo sugerido (mapping_hint)** | JSON con claves como `event_title`, `mec_start_date`, `mec_location`, `mec_cost`, `mec_available_slots`, `permalink`, más el **hint** visible: *«MEC Add-on activo: Los campos de reserva y disponibilidad se sincronizan automáticamente.»* |
| **Último acceso** | Registro de IP, user-agent y fecha del **último** GET exitoso al endpoint (trazabilidad ligera). |

### 8.2 Parámetros del GET (API)

- **`per_page`** (1–200, por defecto 100).  
- **`page`** (paginación).  

Solo eventos **`mec-events`** con estado **publicado** o **programados**, **raíz** (`post_parent = 0`), ordenados por `mec_start_date`.

---

## Parte 9 — Recurrencia y una fila por serie

El conector está pensado para indexar **un evento raíz por serie** con la **próxima fecha** en metadatos MEC (no expande docenas de filas por cada repetición si MEC las representa como hijos). Si su caso usa solo eventos hijos, consulte con soporte o un integrador para ajustar SQL vía mantenimiento personalizado.

---

## Parte 10 — Problemas frecuentes y checklist

| Síntoma | Qué revisar |
|---------|-------------|
| **«Hub Polar: inactiva»** y sincronización bloqueada | Clave en **Addons**, pago en [Polar](https://buy.polar.sh/polar_cl_wEzwnqMvZIrPelny1I5HNIsdcVjGs1UO12Roj3zzxIm), botón **Sincronizar licencia**. |
| **Sin eventos en la vista previa** | ¿Hay eventos futuros en MEC? ¿Slug `mec-events` existe? ¿Prefijo SQL correcto en remoto? |
| **Plazas siempre vacías** | ¿Tickets / límites en MEC? Sin capacidad definida el campo puede quedar vacío. |
| **No aparece botón de reserva** | ¿Módulo de reservas MEC activo? ¿Tickets en el evento? El sistema **oculta** el botón si no detecta reserva. |
| **403 en federación** | Licencia MEC, clave de puente en Federación, o sesión de administrador. |
| **Aviso «requiere Core»** al activar MEC | Active **Xabia Agent Core** primero. |

**Checklist rápido:** Core ≥ 1.0.24 → MEC activo → Xabia MEC activo → Hub **activa** → Fuente **addon** + **mec** → **Conectar y Mapear** → **Guardar** → **Sincronizar datos** → **Entrenar** (si aplica) → probar en **Playground**.

---

## Parte 11 — Buenas prácticas

- Mantenga **MEC** actualizado y revise **eventos cancelados** para no ofrecer fechas obsoletas hasta la próxima sincronización.  
- Use **categorías** claras para que el agente filtre bien desde **Categorias_Tags**.  
- Pruebe siempre **reserva real** en una ventana privada tras cambiar plantilla del evento.  
- Documente internamente quién tiene la **clave Polar** y quién puede pulsar **Sincronizar licencia**.

---

## Parte 12 — Soporte

- **Xabia / Digixop** (instalación, licencias Core y addon MEC, Hub): contacto habitual **help@xabia.ai**.  
- **Facturación Polar**: use el **portal de cliente** enlazado desde **Addons** cuando la suscripción esté activa.  
- **Incidencias del calendario MEC** (bugs del plugin Webnus, estilos, shortcodes): **soporte Webnus / MEC**.

Para **licencia principal, tokens y cartera**, abra el **[Manual del Core](./manual-usuario-xabia-core.md)**.

<div class="manual-pdf-cierre">

## Xabia AI — La web viva

Sabiduría natural con inteligencia artificial

Xabia AI está especialmente indicada para empresas y asociaciones del sector del turismo, instituciones, empresas y tiendas online que quieran incrementar su conversión.

Xabia AI está desarrollada por Digixop.

**Xabia AI** · Garaizar, 2 · 48004 Bilbao (SPAIN)

[help@xabia.ai](mailto:help@xabia.ai)

</div>
