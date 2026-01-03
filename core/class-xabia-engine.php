<?php
/**
 * CAPA 1 — MOTOR LÓGICO (ENGINE) v5.3
 * Soporta: Múltiples CSV, WordPress CPT, OpenAI y Fichas Ricas [CARD]
 * Corrección: Gestión de sesiones segura para API REST y resolución de imágenes nativas.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('Xabia_Engine')) {
    class Xabia_Engine {
        private $config;
        private $project_id;

        public function __construct($project_id) {
            $all_configs = get_option('xabia_projects_config', []);
            $this->config = $all_configs[$project_id] ?? null;
            $this->project_id = $project_id;
        }

        public function generate_response($message) {
            if (!$this->config) return "Error: Configuración de proyecto [$this->project_id] no encontrada.";

            // 1. BUSCAR DATOS (CPT o CSV)
            $relevant_info = $this->search_all_sources($message);
            $custom_instructions = $this->config['rules']['instructions'] ?? 'Eres un asistente experto.';

            // 2. CONSTRUIR EL SYSTEM PROMPT
            $system_prompt = "ERES XABIA AGENT NEXT.\n" .
                "CONTEXTO DE IDENTIDAD:\n{$custom_instructions}\n\n" .
                "REGLAS DE FICHA RICA (OBLIGATORIO):\n" .
                "Si encuentras un elemento relevante, genera una respuesta amable y añade la ficha al final exactamente en este formato:\n" .
                "[CARD: Nombre | LogoURL | FotoURL | Tel | Web | FichaURL]\n\n" .
                "REGLA DE IMÁGENES: Usa las URLs proporcionadas. Si no hay, usa N/A. No inventes placeholders.\n" .
                "REGLA DE FORMATO: No uses negritas (**). No inventes datos.\n\n" .
                "DATOS REALES ENCONTRADOS:\n" . ($relevant_info ?: "No hay datos específicos.");

            return $this->call_openai($system_prompt, $message);
        }

        private function search_all_sources($query) {
            $mode = $this->config['type'] ?? 'directory';
            return ($mode === 'cpt') ? $this->search_wordpress_db($query) : $this->search_csv_folder($query);
        }

        private function search_wordpress_db($query) {
            $cpt = $this->config['cpt_source'] ?? 'post';
            $mapping = $this->config['mapping'] ?? [];

            $args = [
                'post_type'      => $cpt,
                's'              => $query,
                'posts_per_page' => 3,
                'post_status'    => 'publish'
            ];

            $search = new WP_Query($args);
            $results = "";

            if ($search->have_posts()) {
                while ($search->have_posts()) {
                    $search->the_post();
                    $id = get_the_ID();
                    // Usamos la biblioteca de medios directamente
                    $img_url = get_the_post_thumbnail_url($id, 'large') ?: 'N/A';
                    
                    $results .= "--- ELEMENTO: " . get_the_title() . " ---\n";
                    $results .= "Descripción: " . wp_trim_words(get_the_content(), 50) . "\n";
                    $results .= "Tel: " . get_post_meta($id, $mapping['tel'] ?? '', true) . "\n";
                    $results .= "Img: " . $img_url . "\n";
                    $results .= "FichaURL: " . get_permalink() . "\n\n";
                }
                wp_reset_postdata();
            }
            return $results;
        }

        private function search_csv_folder($query) {
            $folder = wp_upload_dir()['basedir'] . "/xabia/{$this->project_id}/";
            if (!is_dir($folder)) return "";
            
            $files = glob($folder . "*.csv");
            $output = "";

            foreach ($files as $file) {
                if (($handle = fopen($file, "r")) !== FALSE) {
                    $first_line = fgets($handle);
                    $sep = (strpos($first_line, ';') !== false) ? ';' : ',';
                    rewind($handle);
                    $headers = fgetcsv($handle, 0, $sep);

                    while (($row = fgetcsv($handle, 0, $sep)) !== FALSE) {
                        $row_string = implode(" ", $row);
                        if ($this->helper_search($query, $row_string)) {
                            $output .= "--- REGISTRO ---\n";
                            foreach ($headers as $i => $h) {
                                $val = $row[$i] ?? 'N/A';
                                // Si la columna es de imagen, buscamos la URL real en WP
                                if ($this->is_image_column($h)) {
                                    $val = $this->resolve_media_url($val);
                                }
                                $output .= "{$h}: {$val}\n";
                            }
                            $output .= "\n";
                        }
                    }
                    fclose($handle);
                }
            }
            return $output;
        }

        private function is_image_column($header) {
            $tags = ['img', 'foto', 'imagen', 'logo', 'picture'];
            foreach ($tags as $tag) {
                if (stripos($header, $tag) !== false) return true;
            }
            return false;
        }

        private function resolve_media_url($filename) {
            if (empty($filename) || $filename === 'N/A') return 'N/A';
            if (filter_var($filename, FILTER_VALIDATE_URL)) return $filename;

            global $wpdb;
            $attachment_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
                '%' . $filename . '%'
            ));

            return $attachment_id ? wp_get_attachment_url($attachment_id) : 'N/A';
        }

        private function call_openai($system_prompt, $user_message) {
            $api_key = get_option('xabia_openai_key');
            if (empty($api_key)) return "Error: API Key no configurada.";

            // Gestión de sesión para API REST
            if (!session_id() && !headers_sent()) { session_start(); }
            $history = $_SESSION['xabia_chat_history'] ?? [];
            
            $messages = [["role" => "system", "content" => $system_prompt]];
            foreach (array_slice($history, -6) as $msg) { $messages[] = $msg; }
            $messages[] = ["role" => "user", "content" => $user_message];

            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . trim($api_key),
                    'Content-Type'  => 'application/json',
                ],
                'body' => json_encode([
                    'model'       => 'gpt-4o-mini',
                    'messages'    => $messages,
                    'temperature' => 0.2,
                ]),
                'timeout' => 45
            ]);

            if (is_wp_error($response)) return "Error de conexión con OpenAI.";

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $reply = $body['choices'][0]['message']['content'] ?? "Sin respuesta de IA.";

            $history[] = ["role" => "user", "content" => $user_message];
            $history[] = ["role" => "assistant", "content" => $reply];
            $_SESSION['xabia_chat_history'] = array_slice($history, -10);

            return $reply;
        }

        private function helper_search($query, $text) {
            return (strpos(mb_strtolower($text, 'UTF-8'), mb_strtolower($query, 'UTF-8')) !== false);
        }
    }
}