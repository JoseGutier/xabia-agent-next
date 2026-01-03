<?php
/**
 * Xabia Agent — CORE v8.8 (Intelligent Context Scoring)
 * Filtra y prioriza la información antes de enviarla a la IA.
 */

if (!defined('ABSPATH')) exit;

/**
 * Busca en los CSV y devuelve solo lo más relevante basado en la consulta.
 */
function get_xabia_context($project_id, $user_query = '') {
    $upload_dir = wp_upload_dir()['basedir'] . '/xabia/' . $project_id . '/';
    if (!file_exists($upload_dir)) return "";

    $projects_config = get_option('xabia_projects_config', []);
    $mapping = $projects_config[$project_id]['mapping'] ?? [];
    
    $scored_rows = [];
    $files = glob($upload_dir . "*.csv");

    if ($files) {
        foreach ($files as $file) {
            if (($handle = fopen($file, "r")) !== FALSE) {
                // Detectamos cabeceras para el mapeo
                $headers = fgetcsv($handle, 0, ";"); 
                
                while (($data = fgetcsv($handle, 0, ";")) !== FALSE) {
                    $row_text = "";
                    $current_score = 0;
                    
                    foreach ($data as $index => $value) {
                        $value = trim($value);
                        if (empty($value)) continue;

                        $header_name = $headers[$index] ?? '';
                        $row_text .= "$header_name: $value | ";

                        // --- SISTEMA DE SCORING ---
                        // Si la palabra del usuario está en una columna de prioridad ALTA
                        if (!empty($user_query) && mb_stripos($value, $user_query) !== false) {
                            $priority = $mapping[$header_name] ?? 'baja';
                            if ($priority === 'alta') $current_score += 10;
                            elseif ($priority === 'media') $current_score += 5;
                            else $current_score += 1;
                        }
                    }

                    if ($current_score > 0) {
                        $scored_rows[] = ['score' => $current_score, 'content' => $row_text];
                    }
                }
                fclose($handle);
            }
        }
    }

    // Ordenar por puntuación (lo más importante primero)
    usort($scored_rows, function($a, $b) { return $b['score'] <=> $a['score']; });

    // Extraer solo las mejores filas (limitamos para no saturar)
    $final_context = "";
    $top_rows = array_slice($scored_rows, 0, 15);
    foreach ($top_rows as $row) {
        $final_context .= $row['content'] . "\n";
    }

    return !empty($final_context) ? $final_context : "No hay datos específicos, responde con conocimiento general pero mencionando que no encontraste registros exactos.";
}

/**
 * SEO IA: llms.txt (Sincronizado con el ecosistema)
 */
add_action('init', function() {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (trim($path, '/') === 'llms.txt') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "# LLMS.txt for Xabia AI\n\n";
        echo "Full-context: " . home_url('/xabia-knowledge.txt') . "\n";
        echo "Site-Name: " . get_bloginfo('name') . "\n\n";
        echo "## Agentes Activos\n";
        
        $projects = get_option('xabia_projects_config', []);
        foreach ((array)$projects as $id => $p) {
            echo "- " . wp_strip_all_tags($p['name']) . ": " . home_url("/?agent=$id") . "\n";
        }
        exit;
    }
});