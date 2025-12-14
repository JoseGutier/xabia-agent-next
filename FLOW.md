# Xabia Agent — Flujo de Decisión

Este documento describe el orden exacto de decisión del sistema
ante cada mensaje del usuario.

---

## 1️⃣ Entrada

Usuario escribe un mensaje en el chat.

Frontend:
- chat.js
- envía texto limpio a `/xabi/v1/query`

---

## 2️⃣ Normalización

endpoint-query.php:

- Limpia texto (acentos, ruido, errores comunes)
- Guarda `last_query`
- No decide nada aún

---

## 3️⃣ Contexto

Se recupera:
- last_company
- last_list
- last_mode

Se resuelven referencias:
- “la segunda”
- “esa empresa”
- ordinales

---

## 4️⃣ Interpretación IA (opcional)

llm-intent.php:

⚠️ Solo se usa si:
- hay ambigüedad real
- o reescritura útil

La IA:
- NO busca
- NO responde
- SOLO propone:
  - intent
  - target
  - rewrite

---

## 5️⃣ Acciones claras (sin IA)

Si el mensaje contiene:
- llamar
- abrir web
- reservar
- precios

➡️ Se ejecuta acción directa
usando el contexto existente.

---

## 6️⃣ Planner

Si el usuario pide:
- plan
- itinerario
- qué hacer

➡️ Se construye respuesta compuesta
(sin listado plano).

---

## 7️⃣ Información de empresa

Si hay empresa activa:
- ficha
- actividades
- imágenes
- contacto

➡️ Se responde sin buscar en todo el catálogo.

---

## 8️⃣ Búsqueda general (último recurso)

Solo si:
- no hay contexto suficiente
- no hay acción clara

Se aplica:
- búsqueda textual
- refuerzo semántico si aporta valor

Devuelve:
- ficha única
- o lista corta y clara

---

## 9️⃣ Fallback

Solo si todo falla:
- pide aclaración
- nunca inventa
