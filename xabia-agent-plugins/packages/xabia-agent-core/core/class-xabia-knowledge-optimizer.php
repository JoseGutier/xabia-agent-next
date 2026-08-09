<?php
/**
 * Xabia v1.0.61 — optimización de sync: hashing, cooldown transients, SQL incremental.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Knowledge_Optimizer {

    public const COOLDOWN_TRANSIENT_PREFIX = 'xabia_sync_cooldown_';
    public const COOLDOWN_HOOK = 'xabia_sync_cooldown_fire';
    public const COOLDOWN_SECONDS = 300;

    public static function content_hash(string $plain_text): string {
        return md5($plain_text);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $mapping
     */
    public static function source_record_id_from_row(array $row, array $mapping = []): string {
        if (class_exists('Xabia_Knowledge_Ingest', false)) {
            $canonical = Xabia_Knowledge_Ingest::canonical_record_key($row, $mapping);
            if ($canonical !== '') {
                return $canonical;
            }
        }
        foreach (['ID', 'id', 'Id'] as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                return sanitize_text_field((string) $row[$key]);
            }
        }
        foreach ($mapping as $attr) {
            if (empty($attr['is_ente'])) {
                continue;
            }
            $col = (string) ($attr['csv_col'] ?? '');
            if ($col !== '' && isset($row[$col]) && trim((string) $row[$col]) !== '') {
                return sanitize_text_field((string) $row[$col]);
            }
        }
        $first = function_exists('array_key_first') ? array_key_first($row) : null;
        if ($first !== null && isset($row[$first]) && trim((string) $row[$first]) !== '') {
            return substr(sanitize_text_field((string) $row[$first]), 0, 100);
        }

        return '';
    }

    public static function get_last_successful_sync(string $project_id): string {
        if (!class_exists('Xabia_Auto_Sync', false)) {
            return '';
        }
        $state = Xabia_Auto_Sync::get_state();
        $project_id = sanitize_key($project_id);
        $ts = isset($state[$project_id]['last_successful_sync']) ? (string) $state[$project_id]['last_successful_sync'] : '';

        return preg_match('/^\d{4}-\d{2}-\d{2} /', $ts) ? $ts : '';
    }

    public static function mark_last_successful_sync(string $project_id): void {
        if (!class_exists('Xabia_Auto_Sync', false)) {
            return;
        }
        $state = Xabia_Auto_Sync::get_state();
        $project_id = sanitize_key($project_id);
        $prev = isset($state[$project_id]) && is_array($state[$project_id]) ? $state[$project_id] : [];
        $prev['last_successful_sync'] = gmdate('Y-m-d H:i:s');
        $state[$project_id] = $prev;
        update_option(Xabia_Auto_Sync::OPTION_STATE, $state, false);
    }

    /**
     * Agrupa ediciones nativas (Woo/MEC): transient 5 min + un solo disparo al expirar la ventana.
     */
    public static function request_cooldown_sync(string $project_id): void {
        $project_id = sanitize_key($project_id);
        if ($project_id === '') {
            return;
        }
        set_transient(self::COOLDOWN_TRANSIENT_PREFIX . $project_id, 1, self::COOLDOWN_SECONDS);
        $args = [$project_id];
        $fire_at = time() + self::COOLDOWN_SECONDS;
        while ($ts = wp_next_scheduled(self::COOLDOWN_HOOK, $args)) {
            wp_unschedule_event($ts, self::COOLDOWN_HOOK, $args);
        }
        wp_schedule_single_event($fire_at, self::COOLDOWN_HOOK, $args);
    }

    /**
     * @param mixed $project_id
     */
    public static function fire_cooldown_sync($project_id): void {
        $project_id = sanitize_key((string) $project_id);
        if ($project_id === '' || !class_exists('Xabia_Auto_Sync', false)) {
            return;
        }
        delete_transient(self::COOLDOWN_TRANSIENT_PREFIX . $project_id);
        Xabia_Auto_Sync::execute_incremental_sync($project_id, 'cooldown');
    }

    /**
     * Añade filtro temporal a consultas basadas en wp_posts (Woo, SQL local…).
     *
     * MEC no usa este filtro: el calendario cambia por meta (próxima fecha) o publica
     * eventos ya «antiguos» en post_modified; el ahorro de tokens va por content_hash
     * en el upsert (solo insert/update marcan embedding pendiente).
     *
     * @param array<string, mixed> $config
     */
    public static function apply_incremental_sql_filter(string $sql, string $project_id, array $config): string {
        $skip = (class_exists('Xabia_Knowledge_Sync', false) && Xabia_Knowledge_Sync::is_mec_catalog_config($config))
            || (bool) apply_filters('xabia_knowledge_skip_incremental_sql_filter', false, $project_id, $config);
        if ($skip) {
            return $sql;
        }
        $since = self::get_last_successful_sync($project_id);
        if ($since === '') {
            return $sql;
        }
        if (stripos($sql, 'xabia_incremental_applied') !== false) {
            return $sql;
        }
        $since_sql = esc_sql($since);
        $clause = '';
        if (preg_match('/\bFROM\s+[`\']?\w*posts[`\']?\s+p\b/i', $sql)) {
            $clause = " AND p.post_modified_gmt > '{$since_sql}'";
        }
        $sql = self::inject_and_before_order_limit($sql, $clause);
        $sql = (string) apply_filters('xabia_knowledge_incremental_sql', $sql, $project_id, $config, $since);

        return $sql;
    }

    private static function inject_and_before_order_limit(string $sql, string $clause): string {
        if ($clause === '') {
            return $sql;
        }
        if (preg_match('/\b(ORDER\s+BY|LIMIT)\b/i', $sql, $m, PREG_OFFSET_CAPTURE)) {
            $pos = (int) $m[0][1];

            return substr($sql, 0, $pos) . $clause . ' /* xabia_incremental_applied */ ' . substr($sql, $pos);
        }

        return rtrim($sql, "; \t\n\r") . $clause . ' /* xabia_incremental_applied */';
    }

    /**
     * @param array<string, mixed> $meta_array
     * @return array<string, mixed>
     */
    public static function merge_volatile_meta(array $existing_meta, array $meta_array): array {
        $volatile_patterns = apply_filters('xabia_knowledge_volatile_meta_patterns', [
            '/precio/i',
            '/price/i',
            '/stock/i',
            '/plazas/i',
            '/slot/i',
            '/cost/i',
            '/cantidad/i',
            '/qty/i',
            '/descuento/i',
            '/sale/i',
            '/mec_available/i',
        ]);

        foreach ($meta_array as $key => $value) {
            $is_volatile = false;
            foreach ($volatile_patterns as $pattern) {
                if (@preg_match($pattern, (string) $key)) {
                    $is_volatile = true;
                    break;
                }
            }
            if ($is_volatile || !isset($existing_meta[$key])) {
                $existing_meta[$key] = $value;
            }
        }
        foreach (['__ente_display', '__ente_col', '__ente_id', '__source_record_id'] as $internal) {
            if (isset($meta_array[$internal])) {
                $existing_meta[$internal] = $meta_array[$internal];
            }
        }

        return $existing_meta;
    }
}
