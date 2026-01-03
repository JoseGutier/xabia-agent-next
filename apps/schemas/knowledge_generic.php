<?php
/**
 * Knowledge Generic Schema
 * Fuente: WordPress DB (CPT + ACF)
 * Uso: chatboxes contextuales (demo, producto, servicio, etc.)
 */

return [

  // --------------------------------------------------
  // FUENTE
  // --------------------------------------------------
  'source' => 'db',

  // CPT que actúa como base de conocimiento
  'cpt' => 'xabia_knowledge',

  // --------------------------------------------------
  // CONDICIONES BASE
  // --------------------------------------------------
  'where' => [
    'post_status' => 'publish',
    'meta' => [
      'active_in_demo' => true
    ]
  ],

  // --------------------------------------------------
  // MAPEO DE CAMPOS (DB → conocimiento)
  // --------------------------------------------------
  'map' => [

    // Identidad
    'id'    => 'ID',
    'title' => 'post_title',

    // Contenido principal
    'content' => [
      'short' => 'acf.short_answer',
      'long'  => 'acf.long_answer'
    ],

    // UX / Conversación
    'examples' => 'acf.examples[].example_text',
    'actions'  => 'acf.demo_actions',

    // Control narrativo
    'priority' => 'acf.priority',

    // Clasificación semántica
    'type'     => 'tax.xabia_kb_type.slug',
    'audience' => 'tax.xabia_audience.slug',

    // CTA opcional
    'cta' => [
      'label' => 'acf.cta_label',
      'url'   => 'acf.cta_url'
    ],

    // Ayuda semántica futura
    'keywords' => 'acf.keywords'
  ]
];