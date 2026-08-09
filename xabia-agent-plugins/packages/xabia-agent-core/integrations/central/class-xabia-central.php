<?php
/**
 * Xabia Central — Nodos, normalización y upsert
 * Unificación: formato canónico por fila; asignación a ente; mínimo envío a IA.
 */

if (!defined('ABSPATH')) exit;

class Xabia_Central {

    /** Placeholder para callback de addon (el sync real va por filtro xabia_addon_sync_result). */
    public static function get_sync_placeholder() {
        return 'SELECT 1 WHERE 0';
    }

    public static function run_sync($project_id) {
        return Xabia_Central_Sync::sync_project($project_id);
    }
}

class Xabia_Central_Nodes {

    /**
     * Nodos de un proyecto (solo activos).
     */
    public static function get_for_project($project_id) {
        global $wpdb;
        $t = Xabia_DB::table('federation_nodes');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, project_id, node_id, name, type, config, last_sync_at, last_error FROM $t WHERE project_id = %s ORDER BY name",
            $project_id
        ), ARRAY_A);
        foreach ($rows as &$r) {
            if (!empty($r['config'])) $r['config'] = json_decode($r['config'], true);
        }
        return $rows ?: [];
    }

    public static function get_by_id($id) {
        global $wpdb;
        $t = Xabia_DB::table('federation_nodes');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", (int) $id), ARRAY_A);
        if ($row && !empty($row['config'])) $row['config'] = json_decode($row['config'], true);
        return $row;
    }

    /**
     * Guardar nodo. config = array (mapeo, endpoint, etc.); api_key solo para push (se hashea).
     */
    public static function save($data) {
        global $wpdb;
        $t = Xabia_DB::table('federation_nodes');
        $project_id = sanitize_key($data['project_id'] ?? '');
        $node_id = sanitize_key($data['node_id'] ?? '');
        $name = sanitize_text_field($data['name'] ?? '');
        $type = in_array($data['type'] ?? '', ['pull', 'push'], true) ? $data['type'] : 'pull';
        $config = isset($data['config']) && is_array($data['config']) ? $data['config'] : [];
        $api_key = $data['api_key'] ?? '';

        $api_key_hash = '';
        if ($api_key !== '') $api_key_hash = hash('sha256', $api_key);

        $config_json = wp_json_encode($config);
        $id = isset($data['id']) ? (int) $data['id'] : 0;

        if ($id > 0) {
            $wpdb->update($t, [
                'project_id' => $project_id,
                'node_id' => $node_id,
                'name' => $name,
                'type' => $type,
                'config' => $config_json,
                'api_key_hash' => $api_key_hash ?: null,
            ], ['id' => $id]);
            return $id;
        }
        $wpdb->insert($t, [
            'project_id' => $project_id,
            'node_id' => $node_id,
            'name' => $name,
            'type' => $type,
            'config' => $config_json,
            'api_key_hash' => $api_key_hash ?: null,
        ]);
        return $wpdb->insert_id;
    }

    public static function delete($id) {
        global $wpdb;
        return $wpdb->delete(Xabia_DB::table('federation_nodes'), ['id' => (int) $id]);
    }

    /**
     * Verificar API key para push (comparación segura con hash).
     */
    public static function verify_api_key($node_id, $project_id, $key) {
        if ($key === '') return false;
        global $wpdb;
        $t = Xabia_DB::table('federation_nodes');
        $hash = hash('sha256', $key);
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t WHERE node_id = %s AND project_id = %s AND api_key_hash = %s",
            $node_id,
            $project_id,
            $hash
        ));
        return (bool) $found;
    }
}

/**
 * Normaliza una fila (desde nodo) al formato canónico y hace upsert.
 * Formato entrante: canonical (ente_id, ente_display, content_chunk, meta_data) o raw (array asociativo + mapping).
 */
class Xabia_Central_Normalize {

    /**
     * Antepone atribución de nodo al texto canónico (RAG / contexto).
     */
    private static function prepend_node_source($content, $node_name) {
        $content = (string) $content;
        $node_name = trim((string) $node_name);
        if ($node_name === '' || trim($content) === '') {
            return trim($content);
        }
        return 'Fuente: ' . $node_name . ' | ' . $content;
    }

    private static function source_record_id_from_row($row) {
        if (!is_array($row)) {
            return '';
        }
        foreach (['source_record_id', 'source_id', 'sourceId', 'ID', 'id', 'Id', 'SKU', 'sku'] as $key) {
            if (!empty($row[$key])) {
                return substr(sanitize_text_field((string) $row[$key]), 0, 100);
            }
        }

        return '';
    }

    /**
     * Convierte fila a canónico. $row = array asociativo; $mapping = array de {source_key, label, is_ente}; si mapping vacío y row tiene ente_id/content_chunk, se usa como canonical.
     *
     * @param string $node_name Nombre legible del nodo federado (atribución en content_chunk).
     */
    public static function row_to_canonical($row, $mapping, $default_ente_id = 'global', $node_name = '') {
        $ente_id = $default_ente_id;
        $ente_display = '';
        $parts = [];
        $meta = [];
        $source_record_id = self::source_record_id_from_row($row);

        if (empty($mapping)) {
            $ente_id = isset($row['ente_id']) && trim((string) $row['ente_id']) !== '' ? sanitize_title($row['ente_id']) : $default_ente_id;
            $ente_display = isset($row['ente_display']) ? trim(strip_tags($row['ente_display'])) : '';
            $content = isset($row['content_chunk']) ? trim(strip_tags($row['content_chunk'])) : '';
            $meta = isset($row['meta_data']) && is_array($row['meta_data']) ? $row['meta_data'] : (is_string($row['meta_data'] ?? '') ? (json_decode($row['meta_data'], true) ?: []) : []);
            if ($source_record_id !== '') {
                $meta['__source_record_id'] = $source_record_id;
            }
            $content = self::prepend_node_source($content, $node_name);
            return [
                'ente_id' => $ente_id ?: $default_ente_id,
                'ente_display' => $ente_display,
                'content_chunk' => $content,
                'meta_data' => $meta,
                'source_record_id' => $source_record_id,
            ];
        }

        foreach ($mapping as $m) {
            $key = $m['source_key'] ?? $m['csv_col'] ?? '';
            if ($key === '' || !isset($row[$key])) continue;
            $val = trim(strip_tags((string) $row[$key]));
            $label = $m['label'] ?? $key;
            if ($m['is_ente'] ?? false) {
                $ente_raw = $val;
                $label_col = isset($m['ente_label_col']) ? trim((string) $m['ente_label_col']) : '';
                if ($label_col !== '' && isset($row[$label_col])) {
                    $from_label = trim(strip_tags((string) $row[$label_col]));
                    $ente_display = $from_label !== '' ? $from_label : $ente_raw;
                } else {
                    $ente_display = $ente_raw;
                }
                $slug_base = $ente_raw !== '' ? $ente_raw : $ente_display;
                $ente_id = sanitize_title($slug_base) ?: (sanitize_title($ente_display) ?: $default_ente_id);
            }
            $parts[] = $label . ': ' . $val;
            $meta[$label] = $val;
        }
        $content = implode(' | ', $parts);
        $content = self::prepend_node_source($content, $node_name);
        if ($ente_display !== '') $meta['__ente_display'] = $ente_display;
        $meta['__ente_id'] = $ente_id;
        if ($source_record_id !== '') {
            $meta['__source_record_id'] = $source_record_id;
        }
        if (trim((string) $node_name) !== '') {
            $meta['__federation_node_name'] = trim((string) $node_name);
        }

        return [
            'ente_id' => $ente_id,
            'ente_display' => $ente_display,
            'content_chunk' => $content,
            'meta_data' => $meta,
            'source_record_id' => $source_record_id,
        ];
    }

    /**
     * Upsert en xabia_knowledge_vectors. Evita re-embedding: vector_data null (entrenar después si se desea).
     * Un registro por (project_id, federation_node_id, ente_id); actualiza si existe.
     *
     * @param string $node_name Nombre del nodo (atribución en content_chunk vía row_to_canonical).
     */
    public static function upsert_batch($project_id, $federation_node_id, $rows, $mapping = [], $node_name = '') {
        global $wpdb;
        $t = Xabia_DB::table('knowledge_vectors');
        $cols = Xabia_DB::knowledge_vectors_column_map();
        $has_fed = isset($cols['federation_node_id']);
        $count = 0;
        foreach ($rows as $row) {
            $canon = self::row_to_canonical($row, $mapping, 'global', $node_name);
            if (trim($canon['content_chunk']) === '') {
                continue;
            }
            $meta_json = wp_json_encode($canon['meta_data'], JSON_UNESCAPED_UNICODE);
            if ($meta_json === false) {
                $meta_json = '{}';
            }
            $ente_id = $canon['ente_id'];
            $source_record_id = isset($canon['source_record_id']) ? trim((string) $canon['source_record_id']) : '';
            if ($source_record_id !== '' && isset($cols['source_record_id'])) {
                if ($has_fed) {
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $t WHERE project_id = %s AND federation_node_id = %s AND source_record_id = %s LIMIT 1",
                        $project_id,
                        $federation_node_id,
                        $source_record_id
                    ));
                } else {
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $t WHERE project_id = %s AND source_record_id = %s LIMIT 1",
                        $project_id,
                        $source_record_id
                    ));
                }
            } elseif ($has_fed) {
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $t WHERE project_id = %s AND federation_node_id = %s AND ente_id = %s LIMIT 1",
                    $project_id,
                    $federation_node_id,
                    $ente_id
                ));
            } else {
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $t WHERE project_id = %s AND ente_id = %s LIMIT 1",
                    $project_id,
                    $ente_id
                ));
            }
            $source_file = 'federation:' . $federation_node_id;
            if ($existing) {
                $upd = ['content_chunk' => $canon['content_chunk']];
                if (isset($cols['meta_json'])) {
                    $upd['meta_json'] = $meta_json;
                } elseif (isset($cols['meta_data'])) {
                    $upd['meta_data'] = $meta_json;
                }
                if (isset($cols['source_file'])) {
                    $upd['source_file'] = $source_file;
                }
                $ok = $wpdb->update($t, $upd, ['id' => $existing]);
            } else {
                $ok = Xabia_DB::insert_knowledge_vector_row(
                    $project_id,
                    $ente_id,
                    $canon['content_chunk'],
                    $canon['meta_data'],
                    [
                        'source_file'        => $source_file,
                        'federation_node_id' => $has_fed ? $federation_node_id : null,
                        'source_record_id'   => $source_record_id,
                    ]
                );
            }
            if ($ok !== false) {
                ++$count;
            }
        }

        return $count;
    }
}
