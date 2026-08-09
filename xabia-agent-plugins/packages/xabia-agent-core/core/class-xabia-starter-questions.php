<?php
/**
 * Preguntas sugeridas (chips) para el chat — generación dinámica desde knowledge_vectors.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Starter_Questions {

    public const MAX_SUGGESTIONS = 3;
    private const CACHE_TTL      = HOUR_IN_SECONDS;
    private const SAMPLE_LIMIT   = 36;

    /**
     * Preguntas manuales por defecto para agentes conocidos (semilla comercial / demo).
     *
     * @var array<string, list<string>>
     */
    private const PROJECT_MANUAL_DEFAULTS = [
        'conoce-xabia' => [
            '¿En qué te diferencias de un chatbot tradicional?',
            '¿Qué son los Smart QR y cómo funcionan?',
            '¿Cómo puedo integrarte en mi web o proyecto?',
        ],
    ];

    /**
     * @param array<string, mixed> $rules
     * @return list<string>
     */
    public static function for_project(string $project_id, string $ente_scope = '', array $rules = []): array {
        $project_id = sanitize_key($project_id);
        if ($project_id === '') {
            return [];
        }

        if (!self::is_enabled($rules)) {
            return [];
        }

        $ente_scope = trim(sanitize_title($ente_scope));
        if ($ente_scope === 'global') {
            $ente_scope = '';
        }

        $manual = self::normalize_manual_list($rules['starter_questions'] ?? []);
        if ($manual !== []) {
            $out = array_slice($manual, 0, self::MAX_SUGGESTIONS);

            return (array) apply_filters('xabia_starter_questions', $out, $project_id, $ente_scope, $rules);
        }

        $dynamic = self::load_dynamic($project_id, $ente_scope);
        $out = array_slice($dynamic, 0, self::MAX_SUGGESTIONS);

        return (array) apply_filters('xabia_starter_questions', $out, $project_id, $ente_scope, $rules);
    }

    /**
     * @param array<string, mixed> $rules
     */
    public static function is_enabled(array $rules): bool {
        if (!array_key_exists('starter_questions_enabled', $rules)) {
            return true;
        }

        return !empty($rules['starter_questions_enabled']);
    }

    /**
     * Escribe en xabia_projects_config las preguntas semilla si el agente existe y aún no tiene ninguna.
     */
    public static function maybe_seed_project_defaults(): void {
        $projects = get_option('xabia_projects_config', []);
        if (!is_array($projects) || $projects === []) {
            return;
        }

        $changed = false;
        foreach (self::PROJECT_MANUAL_DEFAULTS as $project_id => $questions) {
            $project_id = sanitize_key((string) $project_id);
            if ($project_id === '' || !isset($projects[$project_id]) || !is_array($projects[$project_id])) {
                continue;
            }
            if (!isset($projects[$project_id]['rules']) || !is_array($projects[$project_id]['rules'])) {
                $projects[$project_id]['rules'] = [];
            }
            if (!array_key_exists('starter_questions_enabled', $projects[$project_id]['rules'])) {
                $projects[$project_id]['rules']['starter_questions_enabled'] = 1;
                $changed = true;
            }
            $existing = self::normalize_manual_list($projects[$project_id]['rules']['starter_questions'] ?? []);
            if ($existing !== []) {
                continue;
            }
            $projects[$project_id]['rules']['starter_questions'] = self::normalize_manual_list($questions);
            $changed = true;
        }

        if ($changed) {
            update_option('xabia_projects_config', $projects, false);
        }
    }

    /**
     * @return list<string>
     */
    public static function project_default_manual(string $project_id): array {
        $project_id = sanitize_key($project_id);
        if ($project_id === '' || !isset(self::PROJECT_MANUAL_DEFAULTS[$project_id])) {
            return [];
        }

        return self::normalize_manual_list(self::PROJECT_MANUAL_DEFAULTS[$project_id]);
    }

    public static function bust_project_cache(string $project_id): void {
        $project_id = sanitize_key($project_id);
        if ($project_id === '') {
            return;
        }
        delete_transient(self::cache_key($project_id, ''));
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return;
        }
        $like = '_transient_' . self::cache_prefix($project_id) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    public static function normalize_manual_list($raw): array {
        if (is_string($raw)) {
            $raw = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $line) {
            $line = trim(wp_strip_all_tags((string) $line));
            if ($line === '') {
                continue;
            }
            if (function_exists('mb_strlen') && mb_strlen($line) > 140) {
                $line = mb_substr($line, 0, 137) . '…';
            } elseif (strlen($line) > 140) {
                $line = substr($line, 0, 137) . '…';
            }
            $out[] = $line;
            if (count($out) >= 6) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $rules
     * @return list<string>
     */
    private static function default_questions(array $rules): array {
        $desc = trim((string) ($rules['context_source_description'] ?? ''));
        $defaults = [
            __('¿Qué servicios o productos ofrecéis?', 'xabia-intelligence'),
            __('¿Cómo puedo contactaros o contratar?', 'xabia-intelligence'),
            __('¿En qué me podéis ayudar?', 'xabia-intelligence'),
        ];
        if ($desc !== '') {
            array_unshift(
                $defaults,
                sprintf(
                    /* translators: %s: short description of the knowledge source */
                    __('¿Qué puedo saber sobre %s?', 'xabia-intelligence'),
                    self::short_label($desc, 48)
                )
            );
        }

        return $defaults;
    }

    /**
     * @return list<string>
     */
    private static function load_dynamic(string $project_id, string $ente_scope): array {
        $cache_key = self::cache_key($project_id, $ente_scope);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $topics = self::collect_topics_from_db($project_id, $ente_scope);
        $questions = self::topics_to_questions($topics);
        set_transient($cache_key, $questions, self::CACHE_TTL);

        return $questions;
    }

    /**
     * @return list<string>
     */
    private static function collect_topics_from_db(string $project_id, string $ente_scope): array {
        if (!class_exists('Xabia_DB', false)) {
            return [];
        }
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return [];
        }

        $table = Xabia_DB::table('knowledge_vectors');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return [];
        }

        $cols = Xabia_DB::knowledge_vectors_column_map();
        $meta_col = isset($cols['meta_data']) ? 'meta_data' : (isset($cols['meta_json']) ? 'meta_json' : '');
        // Solo filas con embedding listo: los chips no deben adelantar al cerebro (Hub/RAG).
        $ready = Xabia_DB::knowledge_vectors_sql_has_embedding();

        if ($ente_scope !== '') {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT content_chunk, ente_id" . ($meta_col !== '' ? ", {$meta_col} AS meta_raw" : '') . "
                     FROM {$table}
                     WHERE project_id = %s AND ente_id = %s AND content_chunk <> '' AND {$ready}
                     ORDER BY id DESC
                     LIMIT %d",
                    $project_id,
                    $ente_scope,
                    self::SAMPLE_LIMIT
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT content_chunk, ente_id" . ($meta_col !== '' ? ", {$meta_col} AS meta_raw" : '') . "
                     FROM {$table}
                     WHERE project_id = %s AND content_chunk <> '' AND {$ready}
                     ORDER BY id DESC
                     LIMIT %d",
                    $project_id,
                    self::SAMPLE_LIMIT
                ),
                ARRAY_A
            );
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $topics = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (self::extract_topics_from_row($row) as $topic) {
                $topics[] = $topic;
            }
        }

        return self::dedupe_topics($topics);
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function extract_topics_from_row(array $row): array {
        $topics = [];
        $meta_raw = (string) ($row['meta_raw'] ?? '');
        if ($meta_raw !== '') {
            $meta = json_decode($meta_raw, true);
            if (is_array($meta)) {
                if (!empty($meta['__ente_display']) && is_string($meta['__ente_display'])) {
                    $topics[] = trim($meta['__ente_display']);
                }
                foreach (['title', 'post_title', 'name', 'nombre'] as $mk) {
                    if (!empty($meta[$mk]) && is_string($meta[$mk])) {
                        $topics[] = trim($meta[$mk]);
                    }
                }
            }
        }

        $chunk = trim(wp_strip_all_tags((string) ($row['content_chunk'] ?? '')));
        if ($chunk === '') {
            return array_values(array_filter($topics));
        }

        $lines = preg_split('/\r\n|\r|\n/', $chunk) ?: [];
        foreach ($lines as $i => $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if ($i === 0 && preg_match('/^([A-ZÁÉÍÓÚÄËÏÖÜÑ][A-ZÁÉÍÓÚÄËÏÖÜÑ\s\-]{0,24}):\s*(.+)$/u', $line, $m)) {
                $val = trim($m[2]);
                if (self::is_meaningful_topic($val)) {
                    $topics[] = self::short_label($val, 72);
                }
                continue;
            }

            if (preg_match('/^-\s*(.+?):\s*(.+)$/u', $line, $m)) {
                $label = trim($m[1]);
                $val = trim($m[2]);
                if (!self::is_meaningful_topic($val)) {
                    continue;
                }
                if (preg_match('/(nombre|título|title|servicio|producto|empresa|actividad|evento|marca|hotel|restaurante|monumento|obra)/ui', $label)) {
                    $topics[] = self::short_label($val, 72);
                } elseif (preg_match('/(descripción|resumen|about|qué es)/ui', $label)) {
                    $topics[] = self::short_label($val, 56);
                }
            }
        }

        return array_values(array_filter($topics));
    }

    private static function is_meaningful_topic(string $text): bool {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) < 3) {
            return false;
        }
        if (preg_match('/^https?:\/\//i', $text)) {
            return false;
        }
        if (preg_match('/^\d+([.,]\d+)?$/', $text)) {
            return false;
        }

        return true;
    }

    private static function short_label(string $text, int $max): string {
        $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($text)));
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strlen') && mb_strlen($text) > $max) {
            return rtrim(mb_substr($text, 0, max(1, $max - 1))) . '…';
        }
        if (strlen($text) > $max) {
            return rtrim(substr($text, 0, max(1, $max - 1))) . '…';
        }

        return $text;
    }

    /**
     * @param list<string> $topics
     * @return list<string>
     */
    private static function topics_to_questions(array $topics): array {
        $out = [];
        foreach ($topics as $topic) {
            $topic = self::short_label($topic, 72);
            if ($topic === '') {
                continue;
            }
            $out[] = sprintf(
                /* translators: %s: topic label from indexed knowledge */
                __('¿Qué me puedes contar sobre «%s»?', 'xabia-intelligence'),
                $topic
            );
            if (count($out) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $topics
     * @return list<string>
     */
    private static function dedupe_topics(array $topics): array {
        $seen = [];
        $out = [];
        foreach ($topics as $topic) {
            $topic = self::short_label($topic, 72);
            if ($topic === '') {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($topic, 'UTF-8') : strtolower($topic);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $topic;
        }

        return $out;
    }

    /**
     * @param list<string> ...$lists
     * @return list<string>
     */
    private static function merge_unique(array ...$lists): array {
        $seen = [];
        $out = [];
        foreach ($lists as $list) {
            foreach ($list as $q) {
                $q = trim((string) $q);
                if ($q === '') {
                    continue;
                }
                $key = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = $q;
            }
        }

        return $out;
    }

    private static function cache_prefix(string $project_id): string {
        return 'xabia_sq_' . $project_id . '_';
    }

    private static function cache_key(string $project_id, string $ente_scope): string {
        $ente = $ente_scope !== '' ? sanitize_key($ente_scope) : 'global';

        return self::cache_prefix($project_id) . $ente;
    }
}
