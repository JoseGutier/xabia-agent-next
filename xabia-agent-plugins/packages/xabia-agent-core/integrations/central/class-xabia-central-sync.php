<?php
/**
 * Xabia Central — Sync (pull desde nodos)
 * Eficacia: una petición por nodo; batch upsert; sin re-embedding hasta que el usuario entrene.
 */

if (!defined('ABSPATH')) exit;

class Xabia_Central_Sync {

    /**
     * Sincroniza un proyecto: para cada nodo tipo pull, obtiene datos y upsert.
     * Retorna total de registros insertados/actualizados.
     */
    public static function sync_project($project_id) {
        $nodes = Xabia_Central_Nodes::get_for_project($project_id);
        $total = 0;
        global $wpdb;
        $t_nodes = Xabia_DB::table('federation_nodes');

        foreach ($nodes as $node) {
            if (($node['type'] ?? '') !== 'pull') continue;
            $config = $node['config'] ?? [];
            $node_id = $node['node_id'];
            $endpoint = $config['endpoint_url'] ?? '';
            $format = $config['format'] ?? 'json';
            $mapping = $config['mapping'] ?? [];

            $rows = [];
            $error = null;

            if ($endpoint !== '') {
                $rows = self::pull_from_endpoint($endpoint, $format, $config, $error);
            } else {
                $error = 'Falta endpoint_url en el nodo';
            }

            if ($error !== null) {
                $wpdb->update($t_nodes, ['last_error' => substr($error, 0, 255), 'last_sync_at' => current_time('mysql')], ['id' => $node['id']]);
                continue;
            }

            $node_display_name = trim((string) ($node['name'] ?? ''));
            if ($node_display_name === '') {
                $node_display_name = (string) $node_id;
            }
            $count = Xabia_Central_Normalize::upsert_batch($project_id, $node_id, $rows, $mapping, $node_display_name);
            $total += $count;
            $wpdb->update($t_nodes, ['last_error' => null, 'last_sync_at' => current_time('mysql')], ['id' => $node['id']]);
        }

        return $total;
    }

    /**
     * Obtiene datos desde URL: JSON (array de objetos) o CSV.
     */
    private static function pull_from_endpoint($url, $format, $config, &$error) {
        $error = null;
        $headers = ['Accept' => 'application/json'];
        if (!empty($config['auth_header'])) {
            $headers['Authorization'] = $config['auth_header'];
        }
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => $headers,
            'sslverify' => !empty($config['ssl_verify']),
        ]);

        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            return [];
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $error = 'HTTP ' . $code;
            return [];
        }
        $body = wp_remote_retrieve_body($response);
        if ($format === 'csv') {
            return self::parse_csv_string($body);
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $error = 'JSON inválido';
            return [];
        }
        if (isset($data['records'])) return $data['records'];
        if (isset($data['data'])) return $data['data'];
        if (array_is_list($data)) return $data;
        return [$data];
    }

    private static function parse_csv_string($str) {
        $lines = preg_split('/\r\n|\r|\n/', trim($str));
        if (empty($lines)) return [];
        $sep = (substr_count($lines[0], ';') >= substr_count($lines[0], ',')) ? ';' : ',';
        $headers = str_getcsv(array_shift($lines), $sep);
        $headers = array_map('trim', $headers);
        $out = [];
        foreach ($lines as $line) {
            $row = str_getcsv($line, $sep);
            if (count($row) !== count($headers)) $row = array_pad($row, count($headers), '');
            $out[] = array_combine($headers, array_slice($row, 0, count($headers)));
        }
        return $out;
    }
}
