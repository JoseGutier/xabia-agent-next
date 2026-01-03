<?php
/**
 * CAPA 4 — CONTEXTO (LOADER) UNIVERSAL
 * Soporta archivos locales por carpeta de proyecto y conexión remota.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('Xabia_Loader')) {

    class Xabia_Loader {
        private $project_id;

        public function __construct($project_id) {
            $this->project_id = sanitize_text_field($project_id);
        }

        /**
         * NUEVO: Obtiene la ruta física real de este proyecto específico
         */
        public function get_project_path() {
            $upload_dir = wp_upload_dir();
            // Ruta: .../wp-content/uploads/xabia/{project_id}/
            return $upload_dir['basedir'] . '/xabia/' . $this->project_id . '/';
        }

        public function load_context() {
            $all_config = get_option('xabia_projects_config', []);

            if (!isset($all_config[$this->project_id])) {
                return new WP_Error('project_not_found', 'El proyecto [' . $this->project_id . '] no existe.');
            }

            $project_data = $all_config[$this->project_id];

            return [
                'identity' => [
                    'id'      => $this->project_id,
                    'name'    => $project_data['name'] ?? 'Nuevo Proyecto',
                    'remote_url' => $project_data['remote_url'] ?? null,
                    'sources' => $this->parse_sources($project_data)
                ],
                'rules' => [
                    'instructions' => $project_data['rules']['instructions'] ?? 'Eres un asistente experto.',
                    'greeting'     => $project_data['rules']['greeting'] ?? '¡Hola! ¿En qué puedo ayudarte?',
                    'card_mapping' => [
                        'title' => $project_data['mapping']['title'] ?? 0,
                        'logo'  => $project_data['mapping']['logo'] ?? 'logo_de_la_empresa',
                        'photo' => $project_data['mapping']['photo'] ?? 'imagen_principal',
                        'tel'   => $project_data['mapping']['tel'] ?? 'telefono_de_la_empresa',
                        'web'   => $project_data['mapping']['web'] ?? 'sitio_web',
                        'ficha' => $project_data['mapping']['ficha'] ?? null
                    ]
                ]
            ];
        }

        /**
         * CORREGIDO: Ahora busca el archivo en la carpeta del proyecto
         */
        public function get_csv_headers($filename) {
            $path = $this->get_project_path() . trim($filename);
            
            if (!file_exists($path)) {
                error_log("[Xabia Loader] ⚠ Archivo no encontrado: " . $path);
                return [];
            }

            if (($handle = fopen($path, "r")) !== FALSE) {
                // Detectar si el separador es coma o punto y coma
                $headers = fgetcsv($handle, 1000, ";");
                fclose($handle);
                return $headers;
            }
            return [];
        }

        private function parse_sources($data) {
            $formatted = [];
            $raw_sources = $data['sources'] ?? [];

            // 1. Si es un Proyecto Remoto (Aktiba)
            if (!empty($data['remote_url'])) {
                $formatted[] = [
                    'type' => 'remote_api',
                    'url'  => esc_url($data['remote_url']),
                    'endpoint' => 'empresa' 
                ];
            }

            // 2. Procesar CSVs locales de la carpeta del proyecto
            if (!empty($raw_sources['csv'])) {
                $files = is_array($raw_sources['csv']) ? $raw_sources['csv'] : explode(',', $raw_sources['csv']);
                foreach ($files as $file) {
                    $clean_file = trim($file);
                    if (empty($clean_file)) continue;

                    $formatted[] = [
                        'type' => 'csv',
                        'file' => $clean_file,
                        'headers' => $this->get_csv_headers($clean_file)
                    ];
                }
            }

            return $formatted;
        }
    }
}