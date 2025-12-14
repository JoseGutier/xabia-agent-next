# Xabia Agent — Mapa de Archivos

Este documento describe la función de cada archivo del sistema.
No contiene código, solo responsabilidad.

---

## api/

- **endpoint-query.php**  
  Handler principal del chat.  
  Orquesta:
  - contexto
  - intents
  - planner
  - búsqueda
  - respuestas

- **endpoint-action.php**  
  Endpoint exclusivo para ejecutar acciones (call, web, book…).

---

## core/

- **core.php**  
  Bootstrap del sistema. Carga módulos y prepara el entorno.

- **interpreter.php**  
  Capa semántica.  
  Traduce datos crudos (CSV, DB, scraping) a entidades comprensibles.

- **search-text.php**  
  Buscador textual (fuzzy, tokens, scoring).

- **embeddings.php**  
  Búsqueda semántica (vectorial).  
  Se usa solo cuando aporta valor.

- **llm-intent.php**  
  Interpretación IA:
  - reescritura
  - intención
  - targets
  NO responde, solo sugiere.

- **debug.php**  
  Herramientas de trazabilidad y logs.

---

## sources/

- Cargadores de conocimiento:
  - CSV
  - DB
  - scraping
  Devuelven entidades normalizadas.

---

## frontend/

- **chat.js**  
  UI del chat:
  - render
  - acciones
  - voz
  - tarjetas

---

## context/

- **context.php**  
  Memoria conversacional:
  - last_company
  - last_list
  - last_query
  - last_mode
