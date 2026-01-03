<?php
return [
    'id'   => 'demo-xabia',
    'name' => 'Proyecto Multi-Fuente',
    'sources' => [
        ['type' => 'csv', 'file' => 'ES-aktiba-agua.csv'],
        ['type' => 'csv', 'file' => 'ES-aktiba-tierra.csv'],
        ['type' => 'wp_db', 'table' => 'posts', 'post_type' => 'product'] // Ejemplo Woo
    ],
];