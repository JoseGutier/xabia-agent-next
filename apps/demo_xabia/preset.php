<?php
/**
 * Xabia App — DEMO XABIA
 * Preset de contexto para explicar Xabia a clientes potenciales
 */

if (!defined('ABSPATH')) exit;

/**
 * Aplica el preset DEMO XABIA
 */
function xabia_app_demo_xabia_apply_context_preset(array $params = []) {

    /* =====================================================
     * 1) CONTEXTO BASE
     * ===================================================== */
    XabiaContext::set('app', 'demo_xabia');
    XabiaContext::set('mode', 'demo');

    /* =====================================================
     * 2) FUENTES — SOLO CONOCIMIENTO INTERNO
     * ===================================================== */
    XabiaContext::set('sources', [
        'knowledge_generic',
    ]);

    XabiaContext::set('search_priority', [
        'knowledge',
    ]);

    /* =====================================================
     * 3) ROL DEL ASISTENTE
     * ===================================================== */
    XabiaContext::set('assistant_role', 'product_explainer');

    XabiaContext::set('system_prompt', implode("\n", [
        "Eres Xabia, un asistente inteligente integrado en WordPress.",
        "Tu objetivo es explicar claramente qué haces, para quién eres útil y por qué aportas valor.",
        "Hablas de forma profesional, cercana y convincente.",
        "Respondes objeciones habituales de clientes con argumentos claros.",
        "No prometes funcionalidades que no estén en la base de conocimiento.",
        "Cuando tiene sentido, propones el siguiente paso de forma natural.",
    ]));

    /* =====================================================
     * 4) MENSAJE INICIAL
     * ===================================================== */
    XabiaContext::set('welcome_message',
        "Hola 👋 Soy **Xabia**.\n\n"
      . "Soy un asistente inteligente integrado en sitios WordPress.\n\n"
      . "Ayudo a que una web:\n"
      . "• Atienda a sus visitantes 24/7\n"
      . "• Explique mejor sus servicios o productos\n"
      . "• Responda preguntas reales de usuarios\n"
      . "• Guíe a cada persona hacia la acción correcta\n\n"
      . "Si quieres, pregúntame:\n"
      . "• *¿Qué puede hacer Xabia?*\n"
      . "• *¿Para qué tipo de webs es útil?*\n"
      . "• *¿En qué se diferencia de un chatbot normal?*"
    );

    /* =====================================================
     * 5) OBJECIONES COMERCIALES (FOLLOW-UP GUIADO)
     * ===================================================== */
    XabiaContext::set('suggested_questions', [
        '¿Qué puede hacer Xabia?',
        '¿Para qué tipo de webs es útil?',
        '¿En qué se diferencia de un chatbot normal?',
        '¿Qué ventajas tiene frente a un formulario?',
        '¿Cómo se integra en una web WordPress?',
    ]);

    /* =====================================================
     * 6) CONTROL DE CTA (NO BLOQUEO)
     * ===================================================== */
    XabiaContext::set('cta_mode', 'soft');

    XabiaContext::set('cta_rules', [
        'allow_after_explanation' => true,
        'allow_after_example'     => true,
        'max_cta_per_session'     => 1,
    ]);

    /* =====================================================
     * 7) SEGURIDAD DE CONTEXTO
     * ===================================================== */
    // Evita que entre en fichas de empresas, actividades, CSV…
    XabiaContext::set('disable_entities', [
        'empresa',
        'actividad',
    ]);

    // El planner no aporta valor comercial aquí
    XabiaContext::set('disable_planner', true);
    
    /* =====================================================
     * 8) CTA ESTRUCTURADO (SALIDA, NO LÓGICA)
     * ===================================================== */
    XabiaContext::set('cta_payload', [
        'type' => 'actions',
        'items' => [
            [
                'label' => '📩 Solicitar demo',
                'command' => 'open_url',
                'value' => 'https://xabia.ai/contacto'
            ],
            [
                'label' => '📞 Hablar con un asesor',
                'command' => 'open_url',
                'value' => 'https://xabia.ai/contacto#llamada'
            ]
        ]
    ]);

    /* =====================================================
     * DEBUG
     * ===================================================== */
    error_log('[Xabia DEMO] ✔ Preset demo_xabia aplicado');
}