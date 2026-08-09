<?php
/**
 * Xabia Central — Ingest por push (nodos envían POST)
 * Privacidad: solo acepta con API key válida; datos solo a la central, no se reenvían.
 */

if (!defined('ABSPATH')) exit;

class Xabia_Central_Ingest {

    /**
     * Endpoint: POST con body JSON { node_id, project_id, api_key, records: [...] }.
     * Respuesta: { success, count, message }.
     */
    public static function handle_push() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_send_json_error(['message' => 'Método no permitido'], 405);
            return;
        }
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            wp_send_json_error(['message' => 'Body JSON inválido']);
            return;
        }
        $node_id = sanitize_key($input['node_id'] ?? '');
        $project_id = sanitize_key($input['project_id'] ?? '');
        $api_key = $input['api_key'] ?? (isset($_REQUEST['api_key']) ? $_REQUEST['api_key'] : '');
        $records = $input['records'] ?? [];

        if ($node_id === '' || $project_id === '') {
            wp_send_json_error(['message' => 'Faltan node_id o project_id']);
            return;
        }
        if (!Xabia_Central_Nodes::verify_api_key($node_id, $project_id, $api_key)) {
            wp_send_json_error(['message' => 'API key inválida o nodo no encontrado']);
            return;
        }
        if (!is_array($records)) {
            wp_send_json_error(['message' => 'records debe ser un array']);
            return;
        }

        $node = self::get_node_by_ids($project_id, $node_id);
        $mapping = ($node['config'] ?? [])['mapping'] ?? [];
        $node_display_name = trim((string) ($node['name'] ?? ''));
        if ($node_display_name === '') {
            $node_display_name = $node_id;
        }
        $count = Xabia_Central_Normalize::upsert_batch($project_id, $node_id, $records, $mapping, $node_display_name);

        wp_send_json_success(['message' => 'Ingestados: ' . $count, 'count' => $count]);
    }

    private static function get_node_by_ids($project_id, $node_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, name, config FROM ' . Xabia_DB::table('federation_nodes') . ' WHERE project_id = %s AND node_id = %s',
            $project_id,
            $node_id
        ), ARRAY_A);
        if ($row && !empty($row['config'])) $row['config'] = json_decode($row['config'], true);
        return $row ?: [];
    }
}
