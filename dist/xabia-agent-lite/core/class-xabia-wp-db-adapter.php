<?php
/**
 * Adaptador WordPress → Xabia_Database_Interface (inyecta $wpdb).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_WP_DB_Adapter implements Xabia_Database_Interface {

    /** @var wpdb */
    private $wpdb;

    public function __construct($wpdb = null) {
        if ($wpdb === null) {
            global $wpdb;
        }
        if (!($wpdb instanceof wpdb)) {
            throw new InvalidArgumentException('Xabia_WP_DB_Adapter requires a wpdb instance.');
        }
        $this->wpdb = $wpdb;
    }

    /**
     * @param array<string, scalar|null> $where
     */
    public function delete(string $table, array $where) {
        $table = $this->resolve_table_name($table);
        if ($table === '' || $where === []) {
            return false;
        }

        $formats = $this->where_formats($where);

        return $this->wpdb->delete($table, $where, $formats);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): bool {
        $table = $this->resolve_table_name($table);
        if ($table === '' || $data === []) {
            return false;
        }

        if ($this->is_knowledge_vectors_table($table) && class_exists('Xabia_DB', false)) {
            $meta = [];
            if (isset($data['meta_data']) && is_string($data['meta_data'])) {
                $decoded = json_decode($data['meta_data'], true);
                $meta = is_array($decoded) ? $decoded : [];
            } elseif (isset($data['meta_json']) && is_string($data['meta_json'])) {
                $decoded = json_decode($data['meta_json'], true);
                $meta = is_array($decoded) ? $decoded : [];
            }

            $extras = [];
            if (!empty($meta['origen_archivo'])) {
                $extras['source_file'] = (string) $meta['origen_archivo'];
            }

            return Xabia_DB::insert_knowledge_vector_row(
                (string) ($data['project_id'] ?? ''),
                (string) ($data['ente_id'] ?? 'global'),
                (string) ($data['content_chunk'] ?? ''),
                $meta,
                $extras
            );
        }

        $formats = $this->insert_formats($data);

        return $this->wpdb->insert($table, $data, $formats) !== false;
    }

    public function query(string $sql) {
        return $this->wpdb->query($sql);
    }

    /**
     * wpdb espera nombre sin prefijo duplicado; acepta nombre con o sin prefijo WP.
     */
    private function resolve_table_name(string $table): string {
        $table = trim($table);
        if ($table === '') {
            return '';
        }

        $prefix = (string) $this->wpdb->prefix;
        if ($prefix !== '' && strpos($table, $prefix) === 0) {
            return substr($table, strlen($prefix));
        }

        if (class_exists('Xabia_DB', false)) {
            $logical = $this->logical_table_key($table);
            if ($logical !== null) {
                return Xabia_DB::table($logical);
            }
        }

        return $table;
    }

    private function logical_table_key(string $table): ?string {
        $suffixes = [
            'xabia_knowledge_vectors'      => 'knowledge_vectors',
            'xabia_site_knowledge_vectors' => 'knowledge_vectors',
            'xabia_response_cache'         => 'response_cache',
        ];
        foreach ($suffixes as $suffix => $key) {
            if ($table === $suffix || (strlen($suffix) <= strlen($table) && substr($table, -strlen($suffix)) === $suffix)) {
                return $key;
            }
        }

        return null;
    }

    private function is_knowledge_vectors_table(string $table): bool {
        return strpos($table, 'knowledge_vectors') !== false;
    }

    /**
     * @param array<string, scalar|null> $where
     * @return list<string>
     */
    private function where_formats(array $where): array {
        $formats = [];
        foreach ($where as $value) {
            $formats[] = is_int($value) ? '%d' : '%s';
        }

        return $formats;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function insert_formats(array $data): array {
        $formats = [];
        foreach ($data as $value) {
            if ($value === null) {
                $formats[] = '%s';
            } elseif (is_int($value)) {
                $formats[] = '%d';
            } elseif (is_float($value)) {
                $formats[] = '%f';
            } else {
                $formats[] = '%s';
            }
        }

        return $formats;
    }
}
