<?php
/**
 * Document-to-RAG — motor de ingestión agnóstico (v1.1.0).
 *
 * Escanea un directorio de archivos locales (.md, .json, .txt, .csv),
 * trocea el contenido e inserta filas en knowledge_vectors vía Xabia_Database_Interface.
 *
 * Sin dependencias de WordPress: mkdir(), json_encode(), apply_filters() solo si existe.
 */

class Xabia_Document_Ingest {

    /** @var Xabia_Database_Interface */
    private $db;

    /** @var string */
    private $base_dir;

    /** @var list<string> */
    private static $supported_extensions = ['md', 'markdown', 'json', 'csv', 'txt'];

    /** @var list<string> */
    private static $ignored_files = ['.', '..', '.htaccess', 'index.php', '.gitkeep'];

    public function __construct(Xabia_Database_Interface $db, string $base_dir) {
        $this->db = $db;
        $this->base_dir = rtrim(str_replace('\\', '/', $base_dir), '/') . '/';
    }

    public function get_base_dir(): string {
        return $this->base_dir;
    }

    public function get_project_dir(string $project_id): string {
        $safe_project = preg_replace('/[^a-zA-Z0-9_\-]/', '', $project_id);

        return $this->base_dir . ($safe_project !== '' ? $safe_project : 'default') . '/';
    }

    public function ensure_project_dir_security(string $project_id): string {
        $dir = $this->get_project_dir($project_id);

        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create knowledge directory: ' . $dir);
            }
        }

        $index_file = $dir . 'index.php';
        if (!is_file($index_file)) {
            file_put_contents($index_file, '<?php // Silence is golden.');
        }

        $htaccess_file = $dir . '.htaccess';
        if (!is_file($htaccess_file)) {
            $rules = "Order Deny,Allow\nDeny from all\n";
            if (function_exists('apply_filters')) {
                $rules = (string) apply_filters('xabia_document_ingest_htaccess_rules', $rules, $project_id, $dir);
            }
            file_put_contents($htaccess_file, $rules);
        }

        return $dir;
    }

    /**
     * @return list<array{name: string, extension: string, size: string, status: string}>
     */
    public function scan_project_directory(string $project_id): array {
        $dir = $this->ensure_project_dir_security($project_id);
        if (!is_dir($dir)) {
            return [];
        }

        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return [];
        }

        $payload = [];
        foreach ($entries as $file) {
            if (in_array($file, self::$ignored_files, true)) {
                continue;
            }
            $path = $dir . $file;
            if (!is_file($path)) {
                continue;
            }

            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            $is_supported = in_array($ext, self::$supported_extensions, true);

            $payload[] = [
                'name'      => $file,
                'extension' => $ext,
                'size'      => $this->format_bytes((int) filesize($path)),
                'status'    => $is_supported ? 'compatible' : 'unsupported',
            ];
        }

        return $payload;
    }

    /**
     * @return array{success: bool, chunks: int, error: string}
     */
    public function ingest_project_directory(string $project_id, string $table_prefix = 'wp_'): array {
        $dir = $this->ensure_project_dir_security($project_id);
        $files = $this->scan_project_directory($project_id);

        $vectors_table = $table_prefix . 'xabia_knowledge_vectors';
        $cache_table = $table_prefix . 'xabia_response_cache';

        if (class_exists('Xabia_DB', false)) {
            $vectors_table = Xabia_DB::table('knowledge_vectors');
            $cache_table = Xabia_DB::table('response_cache');
        }

        $this->db->query('START TRANSACTION');

        try {
            $this->db->delete($vectors_table, ['project_id' => $project_id]);
            $this->db->delete($cache_table, ['project_id' => $project_id]);

            $chunks_total = 0;

            foreach ($files as $file_meta) {
                if (($file_meta['status'] ?? '') !== 'compatible') {
                    continue;
                }

                $file_path = $dir . $file_meta['name'];
                $content = @file_get_contents($file_path);
                if (!is_string($content) || trim($content) === '') {
                    continue;
                }

                $chunks = $this->parse_file((string) $file_meta['extension'], $content);

                foreach ($chunks as $chunk) {
                    $title = trim((string) ($chunk['title'] ?? ''));
                    $body = trim((string) ($chunk['body'] ?? ''));
                    if ($title === '' || $body === '') {
                        continue;
                    }

                    $ente_id = $this->slugify($title);
                    $content_chunk = class_exists('Xabia_Rag_Chunk_Enricher', false)
                        ? Xabia_Rag_Chunk_Enricher::enrich_document($title, $body, ['project_id' => (string) $project_id])
                        : ('Tema: ' . $title . "\n\n" . $body);
                    $meta_data = [
                        'titulo'         => $title,
                        'origen_archivo' => (string) $file_meta['name'],
                        '__ente_display' => $title,
                        'source_type'    => 'local_files',
                    ];

                    $db_row = [
                        'project_id'    => $project_id,
                        'ente_id'       => $ente_id,
                        'content_chunk' => $content_chunk,
                        'meta_data'     => json_encode($meta_data, JSON_UNESCAPED_UNICODE),
                        'vector_data'   => null,
                        'source_file'   => (string) $file_meta['name'],
                    ];

                    if (function_exists('apply_filters')) {
                        $filtered = apply_filters('xabia_knowledge_sync_enrich_row', $db_row, $chunk, $project_id);
                        if (is_array($filtered)) {
                            $db_row = $filtered;
                        }
                    }

                    if ($this->db->insert($vectors_table, $db_row)) {
                        $chunks_total++;
                    }
                }
            }

            $this->db->query('COMMIT');

            return [
                'success' => true,
                'chunks'  => $chunks_total,
                'error'   => '',
            ];
        } catch (Throwable $e) {
            $this->db->query('ROLLBACK');

            return [
                'success' => false,
                'chunks'  => 0,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * @return list<array{title: string, body: string}>
     */
    private function parse_file(string $extension, string $content): array {
        switch ($extension) {
            case 'md':
            case 'markdown':
                return $this->parse_markdown($content);
            case 'json':
                return $this->parse_json($content);
            case 'txt':
                return $this->parse_plain_text($content);
            case 'csv':
                return $this->parse_csv($content);
            default:
                return [];
        }
    }

    /**
     * @return list<array{title: string, body: string}>
     */
    private function parse_markdown(string $content): array {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $chunks = [];

        $parts = preg_split('/\n(?=##+\s+)/', $content);
        if (!is_array($parts)) {
            return [];
        }

        foreach ($parts as $index => $section) {
            $section = trim($section);
            if ($section === '') {
                continue;
            }

            if (preg_match('/^##+\s+(.+)\n(.*)$/s', $section, $m)) {
                $title = trim($m[1]);
                $body = trim($m[2]);
            } elseif ($index === 0 && strpos($section, '#') !== 0) {
                $title = 'Introducción';
                $body = $section;
            } else {
                $lines = explode("\n", $section, 2);
                $title = trim(preg_replace('/^#+\s*/', '', $lines[0] ?? ''));
                $body = trim($lines[1] ?? '');
            }

            if ($title === '' || $body === '') {
                continue;
            }

            $chunks[] = ['title' => $title, 'body' => $body];
        }

        return $chunks;
    }

    /**
     * @return list<array{title: string, body: string}>
     */
    private function parse_json(string $content): array {
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        if ($this->is_assoc($data)) {
            $data = [$data];
        }

        $chunks = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = isset($item['title']) ? trim((string) $item['title']) : '';
            $body = isset($item['body']) ? trim((string) $item['body']) : '';
            if ($title === '' && isset($item['titulo'])) {
                $title = trim((string) $item['titulo']);
            }
            if ($body === '' && isset($item['content'])) {
                $body = trim((string) $item['content']);
            }
            if ($title === '' || $body === '') {
                continue;
            }
            $chunks[] = ['title' => $title, 'body' => $body];
        }

        return $chunks;
    }

    /**
     * @return list<array{title: string, body: string}>
     */
    private function parse_plain_text(string $content): array {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $paragraphs = preg_split("/\n\s*\n/", $content);
        if (!is_array($paragraphs)) {
            return [];
        }

        $chunks = [];
        $counter = 1;
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            $chunks[] = [
                'title' => 'Fragmento #' . $counter,
                'body'  => $para,
            ];
            $counter++;
        }

        return $chunks;
    }

    /**
     * CSV con cabecera title,body (o titulo,content).
     *
     * @return list<array{title: string, body: string}>
     */
    private function parse_csv(string $content): array {
        $content = str_replace(["\r\n", "\r"], "\n", trim($content));
        if ($content === '') {
            return [];
        }

        $lines = explode("\n", $content);
        if (count($lines) < 2) {
            return [];
        }

        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $header = str_getcsv(array_shift($lines), $delimiter);
        if (!is_array($header)) {
            return [];
        }

        $header = array_map(static function ($h) {
            return strtolower(trim((string) $h));
        }, $header);

        $title_idx = array_search('title', $header, true);
        if ($title_idx === false) {
            $title_idx = array_search('titulo', $header, true);
        }
        $body_idx = array_search('body', $header, true);
        if ($body_idx === false) {
            $body_idx = array_search('content', $header, true);
        }
        if ($title_idx === false || $body_idx === false) {
            return [];
        }

        $chunks = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = str_getcsv($line, $delimiter);
            if (!is_array($row)) {
                continue;
            }
            $title = trim((string) ($row[$title_idx] ?? ''));
            $body = trim((string) ($row[$body_idx] ?? ''));
            if ($title === '' || $body === '') {
                continue;
            }
            $chunks[] = ['title' => $title, 'body' => $body];
        }

        return $chunks;
    }

    private function slugify(string $text): string {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        if ($text === null) {
            $text = '';
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($converted) && $converted !== '') {
                $text = $converted;
            }
        }
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim((string) $text, '-');
        $text = preg_replace('~-+~', '-', (string) $text);
        $text = strtolower((string) $text);

        return $text !== '' ? $text : 'n-a';
    }

    private function format_bytes(int $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        if ($bytes === 0) {
            return '0 B';
        }
        $pow = (int) floor(log($bytes) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1024 ** $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * @param array<mixed> $array
     */
    private function is_assoc(array $array): bool {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
