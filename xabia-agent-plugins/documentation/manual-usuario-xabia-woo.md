# Manual de usuario — Xabia Woo (addon WooCommerce)

> **Addon:** Xabia Woo **v1.0.4** · **Core requerido:** **≥ 1.0.166** (recomendado)

## Guía rápida de instalación

1. Instale y active **Xabia Agent Core** (`v1.0.166` o superior recomendado).
2. Instale y active **Xabia Woo** (`xabia-woo-1.0.4.zip`) desde **Plugins → Añadir nuevo → Subir plugin**.
3. En **Xabia Agent → Addons**, active o sincronice la suscripción **xabia-woo** hasta ver **Hub Polar: activa**.
4. Si la tienda está en el mismo WordPress, confirme que **WooCommerce** está activo y tiene productos publicados.
5. Si la tienda está en otra web, rellene las credenciales SQL remotas y la **URL pública de la tienda Woo (remoto)** en el agente.
6. En el agente, elija **Fuente de información → Addon nativo → WooCommerce** y pulse **Conectar y Mapear**.
7. Guarde, pulse **Sincronizar datos** y, si usa búsqueda vectorial, **Entrenar IA**.
8. Pruebe en **Playground** consultas de producto, stock, ofertas y botones de carrito antes de publicar.

**Asistente CPT (≥ Core 1.0.162):** con fuente addon Woo lista solo `product`. **Remoto híbrido (≥ 1.0.163):** host SQL o URL de tienda remota → catálogo remoto aunque WC esté instalado. Deep schema / curación CPT muestra metas Woo (`_price`, `_sku`, stock, etc.).

---

## Parte 1 — Qué es y para qué sirve

### 1.1 Definición breve

**Xabia Woo** es un **complemento** (plugin independiente) que se instala junto a **Xabia Agent Core** y **WooCommerce**. Conecta su asistente con el **catálogo de productos** de la tienda para:

- **Sincronizar** productos publicados hacia la **base de conocimiento** del agente (texto y, si lo configura, vectores para búsqueda semántica).
- Responder con **títulos, precios, ofertas, stock, SKU, categorías** y enlaces de ficha tomados de WooCommerce.
- Gestionar **productos variables** (atributos y variaciones con precio/stock en el campo **Resumen** enriquecido).
- Mostrar botones **«Añadir al carrito»** a partir del tag **`[ACTION:CART:ID]`**, donde **ID** es el identificador numérico de la fila del producto (columna **ID** del conector).
- **Sugerir cupones** publicados y vigentes cuando la IA lo considere oportuno (solo códigos reales obtenidos de la tienda; la IA no debe inventarlos).
- Registrar **conversiones** ligadas al chat (cuando el visitante compra tras una recomendación en la misma sesión) en la tabla de conversiones del Core.

No sustituye al **ajuste de impuestos, envíos, pasarelas ni políticas legales** de WooCommerce; es una **capa de conversación y venta asistida** sobre los datos que ya tiene en WordPress.

### 1.2 Qué problema resuelve

Sin este addon, un asistente puede **inventar** precios o productos. Con **Xabia Woo**, las respuestas se apoyan en **consultas SQL coordinadas** a `product` publicados y metadatos estándar (`_price`, `_stock_status`, etc.), en **reglas de contexto** («Oferta especial», uso de **ADDON DISCOVERY**) y en el **carrito AJAX** del tema y WooCommerce.

### 1.3 Identificación del producto (referencia útil)

- **Tienda Polar (suscripción):** [https://polar.sh/xabia](https://polar.sh/xabia)  
- El slug del addon ante el Hub suele ser **`xabia-woo`** (también se reconocen alias como `woo` / `woocommerce` según configuración del proveedor).

### 1.4 Requisitos imprescindibles

| Requisito | Qué significa para usted |
|-----------|---------------------------|
| **WordPress** funcionando | El Core y Xabia Woo son plugins de WordPress. |
| **Xabia Agent Core** instalado y **activo** | Sin el Core, **Xabia Woo** muestra un aviso y **no carga** la integración. |
| **WooCommerce** instalado y **activo** | Necesario cuando la tienda está en el mismo WordPress. En catálogo remoto, WooCommerce puede estar solo en la web origen y Xabia Woo lee por SQL remoto. |
| **Plugin Xabia Woo** instalado y **activo** | El conector SQL, el enriquecimiento de filas variables y el AJAX de carrito viven en `xabia-woo/xabia-woo.php`. |
| **Suscripción Polar / Hub** para el slug **xabia-woo** | La sincronización «premium» y las reglas de catálogo quedan validadas contra el **Hub** desde **Xabia Agent → Addons**. Sin suscripción activa, puede ver **«Hub Polar: inactiva»** y el botón **«Sincronizar datos»** deshabilitado cuando el agente usa la fuente **Addon WooCommerce**. |

---

## Parte 2 — Instalación paso a paso

### 2.1 Antes de empezar

1. Tenga los **ZIP** o paquetes: **Xabia Agent Core**, **WooCommerce** (desde WordPress.org o su distribuidor) y **Xabia Woo**.
2. Confirme que su hosting permite **REST / admin-ajax** para el carrito y la licencia.

### 2.2 Orden recomendado de activación

1. **Xabia Agent Core** → Activar.  
2. **WooCommerce** → Activar y completar el asistente mínimo de tienda si aún no lo hizo.  
3. **Xabia Woo** → Activar **después** del Core.  

Si activa **Xabia Woo** sin el Core, verá un aviso en el escritorio: debe activar primero **Xabia Agent Core**.

### 2.3 Instalar Xabia Woo desde ZIP

1. Entre como **administrador**.  
2. **Plugins → Añadir nuevo → Subir plugin**.  
3. Elija el ZIP de **Xabia Woo**.  
4. **Instalar ahora** → **Activar plugin**.  

### 2.4 Comprobar que WordPress reconoce el plugin

En **Plugins** debe aparecer **Xabia Woo**. En **Xabia Agent → Addons**, la tarjeta **«Xabia — WooCommerce»** debe mostrar el estado **WordPress** (activo / instalado / no instalado) junto al archivo `xabia-woo/xabia-woo.php`.

---

## Parte 3 — Pantalla «Addons»: suscripción Polar y licencia

Abra **Xabia Agent → Addons**. Allí gestiona **todas** las opciones comerciales del addon Woo.

### 3.1 Textos generales de la pantalla

- **Insignia «Hub Polar: activa / inactiva»** indica si la **licencia** incluye el addon **xabia-woo** según el Hub (no confundir con el solo hecho de tener el ZIP activo).
- **Botón «Sincronizar licencias con el hub»** fuerza una nueva comprobación para **todas** las tarjetas.
- **«Contratar suscripción»** (cuando el Hub está inactivo) abre la tienda Polar del producto Woo (no confundir con el checkout del addon MEC).
- Si ve **«Add-on no incluido en el plan»** con licencia Core válida: el hub **no tiene** `xabia-woo` en `addon_activations` para su clave (falta compra del producto Woo en Polar o falta mapear el UUID del producto en el servidor del hub).

### 3.2 Tras comprar o renovar

1. Pegue la **clave de licencia** si su flujo la usa en la tarjeta del addon.  
2. Pulse **Activar** / **Actualizar suscripción** o **Sincronizar licencia**.  
3. Recargue **Addons** y confirme **Hub Polar: activa** para Woo.

---

## Parte 4 — Configuración del agente (fuente «Addon WooCommerce»)

Edite el agente que debe **informar del catálogo** o asistir la venta.

### 4.1 Tipo de fuente

En **«Fuente de información»**:

- Elija **«Addons Nativos (Conector externo)»** cuando el Core detecte **Xabia Woo** y la licencia Hub sea válida.

### 4.2 Selector de addon

En el desplegable de addon, elija **«WooCommerce — catálogo y ventas»** (slug interno **`woo`**).

### 4.3 Botón **«Conectar y Mapear»**

1. Pulse **«Conectar y Mapear»** para una **consulta de prueba** (una fila) y listar columnas.  
2. Si **Hub Polar: inactiva** para Woo, verá error de licencia; corrija **Addons** antes de continuar.  
3. Aplique el **preset** de columnas sugerido para productos (ver tabla siguiente).

### 4.4 Campos típicos del preset de mapeo

| Columna SQL | Rol sugerido | ENTÉ | Notas |
|-------------|--------------|------|--------|
| **ID** | — | Sí (identidad) | ID del post `product`; imprescindible para **`[ACTION:CART:ID]`**. |
| **Titulo** | Título | No | Nombre comercial. |
| **Resumen** | — | No | Extracto; en **variables** se amplía con opciones y stock por variante. |
| **tipo_producto** | — | No | Por ejemplo `simple`, `variable`. |
| **SKU** | — | No | Referencia. |
| **Precio** | — | No | Precio actual (`_price`). |
| **Precio_regular** / **Precio_oferta** | — | No | Para calcular **descuento_porcentaje** y destacar **Oferta especial**. |
| **descuento_porcentaje** | — | No | Calculado en SQL; la IA debe presentarlo como promoción explícita. |
| **Stock_estado** / **Stock_cantidad** | — | No | `instock`, `outofstock`, etc. |
| **Link** | URL | No | En sincronización se sustituye por **permalink** real. |
| **Imagen_URL** | Imagen | No | Se enriquece con tamaño adecuado cuando es posible. |
| **Categorias** | — | No | Categorías y etiquetas de producto. |

### 4.5 Guardar

Pulse **«Guardar agente»** para persistir `source_type`, `addon_slug`, mapeo y credenciales SQL si las usara en un escenario remoto.

### 4.6 Tienda remota sin WooCommerce local

Xabia Woo puede funcionar como **catálogo remoto**: el sitio del chat tiene **Xabia Woo** activo y licencia Hub, pero la tienda real vive en otro WordPress con WooCommerce.

En ese caso:

1. Mantenga **Fuente de información → Addon nativo → WooCommerce**.
2. Rellene las credenciales SQL remotas (**Host**, **DB**, **Usuario**, **Contraseña** y **Prefijo** si no es `wp_`).
3. Rellene **URL pública de la tienda Woo (remoto)**, por ejemplo `https://tu-tienda.com`.
4. Pulse **Conectar y Mapear**, guarde y sincronice.

El chat usará los productos de la base remota. Cuando genere un botón `[ACTION:CART:ID]`, el frontend mostrará un enlace de compra directa hacia la tienda remota (`?add-to-cart=ID`) en lugar de intentar añadir al carrito del WordPress local. Los productos variables normalmente llevarán a la ficha para que el visitante elija variante.

---

## Parte 5 — Memoria, sincronización y playground

| Control | Función |
|---------|---------|
| **«1. Sincronizar datos»** | Ingesta desde el SQL Woo (y enriquecimiento de variables / enlaces). Bloqueado si Hub **inactivo** para este addon. |
| **«2. Entrenar IA»** | Embeddings según configuración del Core. |
| **Playground** | Pruebe preguntas de catálogo, ofertas y «añadir al carrito» antes de publicar el shortcode. |

Tras cambiar precios o stock en masa, **vuelva a sincronizar** (y entrene si usa vectores).

---

## Parte 6 — Buenas prácticas en WooCommerce

- Mantenga **productos publicados** coherentes (precios, impuestos visibles según su plantilla).  
- En **variables**, defina atributos claros para que el **Resumen** enriquecido liste combinaciones útiles.  
- Los **cupones** que la IA puede mencionar son solo los **publicados**, con límite de uso y fecha no caducada; no sustituye reglas de elegibilidad por producto o email en WooCommerce.

---

## Parte 7 — Experiencia del visitante en el chat

- La IA recibe **reglas de ventas** y un resumen de productos recientes cuando el proyecto usa **Woo**.  
- Si recomienda un producto con acción de carrito, el frontend muestra un **botón para añadir al carrito** (requiere WooCommerce activo en la página del chat).  
- **Productos variables:** a veces hace falta elegir variación en la **ficha**; el **Resumen** puede incluir líneas que oriente al visitante.  
- Tras añadir al carrito, el visitante puede completar el pedido en WooCommerce; si la compra sigue a una recomendación en sesión, puede registrarse una conversión interna (véase la siguiente parte).
- Si el catálogo está en una tienda remota, las fichas, carrito y cupones se resuelven en el dominio configurado como **URL pública de la tienda Woo (remoto)**.
- El Core puede traducir automáticamente fichas y contexto de producto cuando el visitante pregunta en otro idioma; el idioma del shortcode queda como respaldo de interfaz, no como bloqueo de respuesta.

---

## Parte 8 — Conversiones y ROI (referencia)

Al activar el addon, Xabia prepara un registro ligero de **conversiones** (agente, pedido, importe, productos y fecha) cuando una compra en WooCommerce sigue a una recomendación reciente del chat. Sirve para **análisis interno** de ROI; no sustituye a los informes nativos de WooCommerce.

---

## Parte 9 — Problemas frecuentes

| Síntoma | Qué revisar |
|---------|-------------|
| **Hub Polar: inactiva** y sync bloqueado | Comprar producto **Woo** en Polar; en el hub configurar el producto Woo. **Addons → Sincronizar licencia**. |
| **«Add-on no incluido en el plan»** (licencia válida) | La clave Core no tiene el addon Woo activado en el hub; revise con soporte o el portal del cliente. |
| **Sin productos en vista previa** | ¿Hay productos publicados? ¿Prefijo SQL correcto si usa base remota? |
| **El botón de carrito no hace nada** | Consola del navegador; ¿WooCommerce activo en la página del chat? |
| **Variable no se añade** | Compruebe si falta elegir atributos; use enlace a ficha o variación según **Resumen**. |

**Checklist rápido:** Core activo → WooCommerce activo → Xabia Woo activo → Hub **activa** → Fuente **addon** + **woo** → **Conectar y Mapear** → **Guardar** → **Sincronizar** → **Entrenar** (si aplica) → **Playground**.

---

## Parte 10 — Soporte

- **Xabia / Digixop** (licencias, Hub, Xabia Woo): **help@xabia.ai**.  
- **WooCommerce** (pagos, envíos, temas, conflictos de terceros): soporte de su **proveedor** o **WooCommerce.com** según el caso.

Para **licencia principal, tokens y cartera**, consulte el **[Manual del Core](./manual-usuario-xabia-core.md)**.

<div class="manual-pdf-cierre">

## Xabia AI — La web viva

Sabiduría natural con inteligencia artificial

Xabia AI está especialmente indicada para empresas y asociaciones del sector del turismo, instituciones, empresas y tiendas online que quieran incrementar su conversión.

Xabia AI está desarrollada por Digixop.

**Xabia AI** · Garaizar, 2 · 48004 Bilbao (SPAIN)

[help@xabia.ai](mailto:help@xabia.ai)

</div>
