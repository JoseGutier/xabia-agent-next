# Xabia Agent — Arquitectura

## Objetivo
Convertir una web WordPress en una web conversacional,
capaz de entender preguntas humanas y responder usando
su propio conocimiento estructurado.

## Capas del sistema
1. UI (chat.js)
2. API REST (endpoint-query.php)
3. Núcleo de decisión (intents, planner, rewriter)
4. Interpretación semántica (interpreter.php)
5. Conocimiento (CSV, DB, scraping)
6. Contexto de conversación (XabiaContext)

## Principios
- Privacy-first (datos locales)
- No depender SIEMPRE de IA
- IA solo cuando aporta valor
- Sistema explicable y depurable
