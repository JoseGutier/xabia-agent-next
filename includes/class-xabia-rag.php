<?php
/**
 * Motor RAG (Retrieval-Augmented Generation) de Xabia AI
 * Orquesta el flujo: Carga de datos -> Scoring -> Prompt -> OpenAI.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Xabia_RAG {

    private $scorer;
    private $api_key;

    public function __construct() {
        if ( class_exists( 'Xabia_Scorer' ) ) {
            $this->scorer = new Xabia_Scorer();
        }
        $this->api_key = get_option( 'xabia_openai_key' ); // Coincide con tu register_setting
    }

    /**
     * Punto de entrada principal para el Shortcode o el Chat
     */
    public function get_ai_response( $user_query, $project_id ) {
        
        $projects = get_option('xabia_projects_config', []);
        if ( !isset($projects[$project_id]) ) {
            return "Error: Proyecto no encontrado.";
        }

        $project = $projects[$project_id];
        
        // 1. Cargar todos los datos disponibles para este proyecto (Aire, Tierra, Agua, etc.)
        $data_source = $this->load_project_data( $project_id );
        
        if ( empty( $data_source ) ) {
            return "Actualmente no tengo datos cargados para este agente.";
        }

        // 2. Ejecutar Scoring basado en el Mapping configurado por el admin
        $mapping = $project['mapping'] ?? []; 
        $scored_results = [];

        foreach ( $data_source as $row ) {
            // El Scorer filtra por palabras clave y asigna pesos
            $score = $this->scorer->calculate_score( $row, $user_query, $mapping );
            
            if ( $score > 0 ) {
                $row['xabia_score'] = $score;
                $scored_results[] = $row;
            }
        }

        // 3. Selección de élite: Solo los 3 registros con más puntos
        $top_context = $this->scorer->get_top_results( $scored_results, 3 );

        // 4. Construcción del bloque de contexto
        $context_text = $this->format_context_for_ai( $top_context );

        // 5. Llamada a OpenAI con el System Prompt (instructions) guardado
        $system_prompt = $project['rules']['instructions'] ?? 'Eres un asistente útil.';
        
        return $this->call_openai( $user_query, $context_text, $system_prompt );
    }

    /**
     * Carga todos los archivos CSV dentro de la carpeta del proyecto
     */
    private function load_project_data( $project_id ) {
        $upload_dir = wp_upload_dir();
        $path = $upload_dir['basedir'] . '/xabia/' . $project_id . '/';
        $all_rows = [];

        if ( is_dir($path) ) {
            $files = glob($path . "*.csv");
            foreach ($files as $file) {
                $all_rows = array_merge($all_rows, $this->read_csv($file));
            }
        }
        return $all_rows;
    }

    private function read_csv( $path ) {
        $rows = [];
        if ( ( $handle = fopen( $path, "r" ) ) !== FALSE ) {
            // Detectamos si el separador es coma o punto y coma
            $header = fgetcsv( $handle, 0, ";" ); 
            if (!$header) return [];

            while ( ( $data = fgetcsv( $handle, 0, ";" ) ) !== FALSE ) {
                if (count($header) === count($data)) {
                    $rows[] = array_combine( $header, $data );
                }
            }
            fclose( $handle );
        }
        return $rows;
    }

    private function format_context_for_ai( $data ) {
        if ( empty( $data ) ) return "No se han encontrado coincidencias exactas en la base de datos.";

        $output = "INFORMACIÓN DE BASE DE DATOS:\n";
        foreach ( $data as $item ) {
            $output .= "[ENTIDAD: " . ($item['empresa'] ?? 'Desconocida') . "]\n";
            foreach ( $item as $key => $value ) {
                // No enviamos campos vacíos ni el score interno para ahorrar tokens
                if ( ! empty( $value ) && $key !== 'xabia_score' ) {
                    $output .= "- $key: $value\n";
                }
            }
            $output .= "\n";
        }
        return $output;
    }

    private function call_openai( $query, $context, $system_prompt ) {
        if ( empty( $this->api_key ) ) return "Error: Falta la API Key en la configuración.";

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'model'       => 'gpt-4o', 
                'messages'    => [
                    [ "role" => "system", "content" => $system_prompt ],
                    [ "role" => "user", "content" => "Utiliza estos datos para responder si son relevantes:\n$context\n\nPregunta: $query" ]
                ],
                'temperature' => 0.1, // Casi determinista para evitar alucinaciones
            ]),
            'timeout' => 30,
        ]);

        if ( is_wp_error( $response ) ) return "Error de comunicación con OpenAI.";

        $res_body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $res_body['choices'][0]['message']['content'] ?? 'No tengo una respuesta clara en este momento.';
    }
}