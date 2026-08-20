# Manual de usuario — Smart QR y tótems (Xabia Agent Core)

> **Versión del producto:** Xabia Agent Core **v1.0.59** (mayo 2026)  
> **Incluido en la licencia Core** — no requiere plugin ni addon de pago aparte.  
> **PDF en línea:** [https://xabia.ai/docs/manual-usuario-xabia-smart-qr.pdf](https://xabia.ai/docs/manual-usuario-xabia-smart-qr.pdf)  
> **HTML en línea:** [https://xabia.ai/docs/manual-usuario-xabia-smart-qr.html](https://xabia.ai/docs/manual-usuario-xabia-smart-qr.html)  
> **Manual Core (contexto general):** [manual-usuario-xabia-core.md](./manual-usuario-xabia-core.md)

---

## Guía rápida (5 minutos)

1. Instale y active **Xabia Agent Core** (`xabia-agent-core`) con licencia válida.
2. Cree un agente, sincronice datos con al menos una columna marcada como **IDENTIDAD (ENTE)** (§4).
3. Publique una **página de aterrizaje** con el shortcode del agente, por ejemplo:  
   `[xabia_agent id="mi-museo"]`
4. En **Editar agente → pestaña Smart QR / Tótems**, seleccione esa página como **Página de aterrizaje** y guarde.
5. En la misma pestaña, pulse **Generar QR** junto al ente que quiera (sala, producto, habitación…).
6. Descargue el PNG, imprímalo o compártalo. Al escanearlo, el visitante llega al chat **anclado a ese ente** (efecto túnel).
7. Para una **tablet en recepción**, use la ruta `/xabia-box/?x_project=ID_AGENTE` o active **Modo tótem** (§8).

**Importante:** si actualiza el Core o usa `/xabia-box/`, visite **Ajustes → Enlaces permanentes → Guardar** una vez para regenerar las reglas de URL.

---

## 1. Qué es Smart QR en Xabia

**Smart QR** es la capa del Core que conecta el mundo físico (carteles, etiquetas, tótems, folletos) con el chat de Xabia. Cuando alguien escanea un código o abre un enlace de túnel:

- El asistente **sabe desde qué punto ha llegado** (sala del museo, habitación del hotel, producto concreto…).
- El **conocimiento RAG** puede filtrarse por **ente** (`ente_id`).
- El **saludo y el tono** se personalizan para ese emplazamiento.
- Las conversaciones pueden etiquetarse en analítica como origen **Smart QR / túnel** (si tiene activada la pestaña de analítica del Core).

Todo esto va **incluido en Xabia Agent Core** desde la v1.0.59. El antiguo plugin separado «Xabia Smart QR» está **obsoleto**; si aún lo tiene instalado, desactívelo y elimínelo.

### 1.1 Casos de uso típicos

| Sector | Ejemplo | Qué consigue |
|--------|---------|--------------|
| **Museo / cultura** | QR junto a cada sala | «Estás en la sala de Goya…» con contexto de esa colección |
| **Hotel / rural** | QR en la habitación o en la recepción | Información del alojamiento, servicios, reservas (con addon Avirato si aplica) |
| **Retail** | QR en estantería o ficha de producto | Chat centrado en ese SKU o categoría |
| **Feria / stand** | QR en el roll-up | Túnel al ente «empresa-stand-12» |
| **Ayuntamiento / oficina** | Tótem en hall | Pantalla `/xabia-box/` con reinicio por inactividad |

### 1.2 Qué **no** es Smart QR

- **No** sustituye la sincronización de datos: debe tener conocimiento indexado (CSV, SQL, CPT…) con entes identificados.
- **No** es un generador de QR genérico para URLs arbitrarias: está pensado para **túneles hacia su agente Xabia** con contexto de ente.
- **No** requiere licencia addon Polar aparte: forma parte del **Core**.

---

## 2. Conceptos clave

### 2.1 Ente (`ente_id`)

Un **ente** es la unidad lógica de segmentación del conocimiento: una sala, un producto, una empresa, una habitación…

- En el **mapeo de columnas** del agente, marque **IDENTIDAD (ENTE)** en el campo que identifica cada fila de forma estable (slug, código, ID interno).
- Tras **Sincronizar datos**, cada fila queda asociada a un `ente_id` (por ejemplo `sala-impresionismo`, `okana`, `producto-442`).
- El valor especial `global` significa «sin ente concreto»; no sirve para generar QR de túnel.

### 2.2 Efecto túnel

Cuando el visitante llega con un **ente** activo (por URL `?ente_id=` o escaneo POI `?xqr=`), el Core inyecta en el prompt del modelo un contexto interno del tipo:

> *El usuario ha llegado por enlace de túnel Smart QR al ente «Sala Impresionismo». Salúdale de forma específica…*

El visitante **no ve** ese texto; solo nota un saludo y respuestas más precisas.

### 2.3 Página de aterrizaje

Es una **página publicada de WordPress** que contiene el shortcode del agente. Los QR generados apuntan a:

```text
https://su-dominio.com/pagina-del-chat/?ente_id=sala-impresionismo
```

Si no configura landing, el sistema puede usar la ruta genérica `/xabia-box/?x_project=ID_AGENTE&ente_id=…`.

### 2.4 Punto de interés (POI) y URLs `?xqr=`

Además del túnel por ente, el Core admite **identificadores de POI** en la URL:

```text
https://su-dominio.com/?xqr=sala-3&x_project=mi-museo
```

(o el alias `?xid=`). Esto activa sesión de escaneo, saludo automático y, si el POI tiene `ente_slug`, ancla el chat a ese ente.

Los datos del POI pueden venir de:

- Registro interno (`xabia_qr_poi_registry`),
- Tabla `xabia_qr_poi`,
- O filas del conocimiento RAG del agente.

### 2.5 Ruta `/xabia-box/`

Experiencia **a pantalla completa** pensada para tótems: sin botón flotante, chat ocupando la pantalla.

```text
https://su-dominio.com/xabia-box/?x_project=mi-museo&ente_id=recepcion
```

---

## 3. Dónde está en el panel de WordPress

| Ubicación | Qué hace |
|-----------|----------|
| **Editar agente → Smart QR / Tótems** | Página de aterrizaje, modo tótem, tabla de entes, botones **Generar QR** |
| **Editar agente → General → preview de conocimiento** | Columna **Smart QR** con acceso rápido al generador por ente |
| **Analítica** (si está activa) | Distinción de tráfico **Web** vs **Smart QR / túnel** |

No aparece en **Xabia Agent → Addons** como producto de pago: es funcionalidad **Core**.

---

## 4. Preparar datos con entes

### 4.1 CSV de ejemplo (museo)

Archivo `salas.csv`:

```csv
codigo_sala;nombre_sala;texto_guia;piso
sala-impresionismo;Impresionismo;Obras de Monet, Renoir y Degas entre 1860 y 1880.;1
sala-barroco;Barroco;Escultura y pintura europea del siglo XVII.;2
```

En el agente:

1. Suba el CSV y **Conectar / Mapear**.
2. Marque `codigo_sala` como **IDENTIDAD (ENTE)**.
3. Etiquete `nombre_sala` como título y `texto_guia` como texto principal.
4. **Sincronizar datos** → **Entrenar** (si usa búsqueda vectorial).

Tras sincronizar, en **Smart QR / Tótems** verá entes `sala-impresionismo` y `sala-barroco`.

### 4.2 SQL / WordPress con ente

Si usa el **Asistente de contenidos WordPress**, marque el campo que identifica cada registro (ID de post, slug, SKU…) como **Ente**. El slug resultante será el `ente_id` en la memoria del agente.

---

## 5. Configurar la pestaña «Smart QR / Tótems»

Abra **Xabia Agent → Editar agente → Smart QR / Tótems**.

### 5.1 Página de aterrizaje

1. Cree una página en WordPress, por ejemplo **«Asistente del museo»**.
2. Inserte el shortcode:

   ```text
   [xabia_agent id="mi-museo"]
   ```

3. **Publique** la página.
4. En el desplegable **Página de aterrizaje**, selecciónela y pulse **Guardar agente**.

### 5.2 Modo tótem (kiosko)

| Ajuste | Efecto |
|--------|--------|
| **Modo tótem** (checkbox) | Tras X minutos sin interacción, la sesión del chat se reinicia al saludo inicial |
| **Minutos de inactividad** | `0` = solo reinicios explícitos del shortcode; `5`–`15` habitual en recepción |

También puede forzar minutos en el shortcode:

```text
[xabia_agent id="mi-museo" totem="10"]
```

El valor del shortcode **prevalece** sobre el defecto del agente.

### 5.3 Tabla de entes y generador

Con el agente guardado y entes en memoria, la pestaña muestra:

| Columna | Descripción |
|---------|-------------|
| **ente_id** | Slug interno |
| **Nombre visible** | Etiqueta amigable |
| **URL de túnel** | Enlace listo para copiar |
| **Smart QR** | Botón **Generar QR** |

Al pulsar **Generar QR** se abre un modal con:

- Vista previa del código
- **Descargar PNG** / **Descargar SVG**
- **Copiar enlace**, **Copiar shortcode**, **Copiar imagen**
- Shortcode sugerido:

  ```text
  [xabia_agent id="mi-museo" ente_id="sala-impresionismo"]
  ```

---

## 6. Tipos de enlace (referencia)

### 6.1 Túnel por ente (recomendado para QR impreso)

```text
https://ejemplo.com/asistente-museo/?ente_id=sala-impresionismo
```

Generado automáticamente por el panel. Es el que codifica el PNG del generador.

### 6.2 Shortcode embebido con ente fijo

Útil en una landing dedicada a una sola sala (sin depender de `?ente_id=` en la URL):

```text
[xabia_agent id="mi-museo" ente_id="sala-impresionismo"]
```

### 6.3 POI físico (`?xqr=`)

```text
https://ejemplo.com/?xqr=placa-entrada&x_project=mi-museo
```

El visitante recibe saludo contextual del POI. Configure POI en registro/tabla o deje que el Core infiera datos del conocimiento RAG.

### 6.4 Tótem pantalla completa

```text
https://ejemplo.com/xabia-box/?x_project=mi-museo
```

Con ente preseleccionado:

```text
https://ejemplo.com/xabia-box/?x_project=mi-museo&ente_id=recepcion
```

En el navegador del tótem, abra esa URL en **modo kiosko** (pantalla completa del SO).

---

## 7. Ejemplos completos por sector

### 7.1 Museo con una landing y QRs por sala

**Objetivo:** Cartel en cada sala con QR distinto.

1. Agente `mi-museo`, CSV de salas (§4.1).
2. Página `/asistente/` con `[xabia_agent id="mi-museo"]`.
3. Landing configurada en Smart QR / Tótems.
4. Generar QR para `sala-impresionismo` → imprimir PNG.
5. El visitante escanea → chat abierto → saludo contextual → respuestas basadas en el texto de esa sala.

**Prueba sin imprimir:** abra la URL de túnel en el móvil o use el Playground con el mismo `ente_id`.

### 7.2 Hotel rural (Astei-style)

**Objetivo:** QR en cada casa con información y enlace a reserva.

1. Sincronice alojamientos con ente = slug de casa (`okana`, `etxola`, …).
2. Landing con el agente principal.
3. QR por ente; el túnel centra el contexto en esa casa.
4. Si tiene **Xabia Avirato**, las preguntas de disponibilidad pueden combinarse con el contexto del ente (consulte manual Avirato).

### 7.3 Tienda con QR en estantería

1. CSV de productos con columna SKU como **ENTE**.
2. QR apunta a `?ente_id=sku-12345`.
3. Instrucciones en **Comportamiento IA**: «Si el visitante llega por QR de producto, prioriza ficha, stock y precio de ese SKU en el contexto».

### 7.4 Recepción municipal (tótem)

1. Agente `atencion-ciudadana`.
2. Active **Modo tótem** con 8 minutos de inactividad.
3. Tablet con `/xabia-box/?x_project=atencion-ciudadana`.
4. Sin QR impreso: experiencia fija en mostrador.

---

## 8. Comportamiento en el chat (visitante)

1. **Apertura:** saludo inicial del agente o saludo POI si vino por `?xqr=`.
2. **Contexto RAG:** el Brain prioriza fragmentos del `ente_id` activo (modo estricto).
3. **Primera persona:** en modo túnel/QR, el modelo suele responder como guía del lugar («En esta sala encontrarás…»).
4. **Idioma:** responde en el idioma del último mensaje del visitante (políglota Core); el QR no fija idioma.
5. **Tótem:** tras inactividad, vuelve al estado inicial (útil en pantallas compartidas).

---

## 9. Analítica y origen del tráfico

Si usa la pestaña **Analítica** del Core, las conversaciones pueden clasificarse:

- **Web** — chat normal en la web.
- **Smart QR / túnel** — llegada con ente/QR activo.

Sirve para medir cuánto uso generan los carteles físicos frente al widget web.

---

## 10. Solución de problemas

| Problema | Qué revisar |
|----------|-------------|
| No aparecen entes en Smart QR / Tótems | ¿Ha **sincronizado**? ¿Hay filas con ente distinto de `global`? |
| «Configura la página de aterrizaje…» al generar QR | Seleccione página publicada con shortcode y **Guarde agente** |
| El QR abre la web pero sin contexto de ente | Compruebe que la URL lleva `?ente_id=` correcto; pruebe el enlace copiado del panel |
| `/xabia-box/` da 404 | **Ajustes → Enlaces permanentes → Guardar**; Core ≥ 1.0.59 |
| Sigue viendo aviso del plugin «Xabia Smart QR» | Desactive y elimine el plugin legacy `xabia-smart-qr` |
| Respuestas genéricas pese al QR | Baje **índice de confianza** en Comportamiento IA; compruebe que el ente tiene texto en RAG |
| Modal QR no carga | Recargue el editor del agente; compruebe que no hay bloqueo de scripts en admin |

---

## 11. Arquitectura modular (referencia técnica ligera)

Smart QR está repartido en módulos **dentro del ZIP del Core**:

| Módulo en Core | Función |
|----------------|---------|
| `core/class-xabia-smart-qr.php` | Túnel en prompt, assets del generador, helpers de URL |
| `addons/xabia-qr/xabia-addon-qr.php` | POI, sesión `?xqr=`, pestaña admin, motor de entes |
| `core/class-xabia-box-route.php` | Rewrite `/xabia-box/` |
| `admin/js/xabia-smart-qr-admin.js` | Modal generador (PNG/SVG, portapapeles) |

No instale addons adicionales para Smart QR. Los addons **Avirato**, **MEC** y **Woo** son independientes y opcionales.

---

## 12. Migración desde el plugin «Xabia Smart QR»

Si en instalaciones antiguas tenía el plugin separado `xabia-smart-qr`:

1. Actualice **Xabia Agent Core** a **v1.0.59** o superior.
2. **Desactive y elimine** `xabia-smart-qr` en **Plugins**.
3. Compruebe **Smart QR / Tótems** en su agente: la funcionalidad ya está ahí.
4. Regeneré permalinks si usa `/xabia-box/`.

No hace falta migrar licencia addon: Smart QR ya no tiene producto Polar propio.

---

<div class="manual-pdf-cierre">

## Xabia AI — La web viva

Sabiduría natural con inteligencia artificial

Xabia AI está especialmente indicada para empresas y asociaciones del sector del turismo, instituciones, empresas y tiendas online que quieran incrementar su conversión.

Xabia AI está desarrollada por Digixop.

**Xabia AI** · Garaizar, 2 · 48004 Bilbao (SPAIN)

[help@xabia.ai](mailto:help@xabia.ai)

</div>
