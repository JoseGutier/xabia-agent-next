<?php
/**
 * Empresas/registros en memoria que ya no existen en la fuente (WP, SQL, etc.).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Knowledge_Orphans {

    /** @var array{migrated:int,deleted:int,fixed:int,purged_ghosts:int} */
    private static $last_reconcile_stats = [
        'migrated'      => 0,
        'deleted'       => 0,
        'fixed'         => 0,
        'purged_ghosts' => 0,
    ];

    /**
     * Normaliza slugs del catálogo igual que en ingesta (guiones medios, sin _).
     */
    private static function catalog_slug_norm(string $value): string {
        if (class_exists('Xabia_Knowledge_Ingest', false)) {
            return Xabia_Knowledge_Ingest::canonical_slug($value);
        }

        return sanitize_title($value);
    }

    /**
     * @param array<string, string> $id_slug_pairs
     * @param list<string> $catalog_ids
     * @return array<string, true>
     */
    private static function build_catalog_slug_set(array $id_slug_pairs, array $catalog_ids): array {
        $slugs = [];
        foreach (array_values($id_slug_pairs) as $slug) {
            $norm = self::catalog_slug_norm((string) $slug);
            if ($norm !== '') {
                $slugs[] = $norm;
            }
        }
        foreach (array_keys($id_slug_pairs) as $post_id) {
            $id = trim((string) $post_id);
            if ($id !== '') {
                $slugs[] = $id;
            }
        }
        foreach ($catalog_ids as $catalog_id) {
            $norm = self::catalog_slug_norm((string) $catalog_id);
            if ($norm !== '') {
                $slugs[] = $norm;
            }
            $raw = trim((string) $catalog_id);
            if ($raw !== '' && ctype_digit($raw)) {
                $slugs[] = $raw;
            }
        }
        $slugs = array_values(array_unique(array_filter($slugs)));

        return $slugs === [] ? [] : array_fill_keys($slugs, true);
    }

    /**
     * @return array{migrated:int,deleted:int,fixed:int,purged_ghosts:int}
     */
    public static function get_last_reconcile_stats(): array {
        return self::$last_reconcile_stats;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array{id:int,source_record_id:string,label:string}>
     */
    public static function find_after_sync(string $project_id, array $config): array {
        self::$last_reconcile_stats = self::reconcile_project_memory($project_id, $config);

        $catalog_ids = self::fetch_catalog_source_ids($project_id, $config);
        if ($catalog_ids === null || $catalog_ids === []) {
            return [];
        }

        $id_slug_pairs = self::fetch_catalog_id_slug_pairs($project_id, $config);
        $orphans = self::find_orphan_rows($project_id, $catalog_ids, $id_slug_pairs);
        if ($orphans === []) {
            return [];
        }

        return self::filter_suspicious_orphans($project_id, $orphans, $catalog_ids);
    }

    /**
     * Limpieza post-sync: migra IDs → slug, alinea ente_id y elimina filas duplicadas.
     *
     * @param array<string, mixed> $config
     * @return array{migrated:int,deleted:int,fixed:int,purged_ghosts:int}
     */
    public static function reconcile_project_memory(string $project_id, array $config): array {
        $stats = ['migrated' => 0, 'deleted' => 0, 'fixed' => 0, 'purged_ghosts' => 0];
        if (!class_exists('Xabia_DB', false)) {
            return $stats;
        }

        $project_id = sanitize_key($project_id);
        if ($project_id === '') {
            return $stats;
        }

        $pairs = self::fetch_catalog_id_slug_pairs($project_id, $config);
        $catalog_ids = self::fetch_catalog_source_ids($project_id, $config);
        if (!is_array($catalog_ids)) {
            $catalog_ids = [];
        }

        $catalog_slug_set = self::build_catalog_slug_set($pairs, $catalog_ids);
        if ($catalog_slug_set === []) {
            return $stats;
        }
        $stats['fixed'] += self::normalize_project_slug_fields($project_id);
        $stats['deleted'] += self::dedupe_rows_by_slug_alias($project_id);
        $stats['deleted'] += self::dedupe_rows_by_ente_id($project_id);
        $stats['deleted'] += self::dedupe_rows_by_canonical_key($project_id);

        global $wpdb;
        $t = Xabia_DB::table('knowledge_vectors');
        $meta_col = Xabia_DB::knowledge_vectors_meta_column();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, source_record_id, ente_id, `{$meta_col}` AS meta_blob FROM {$t} WHERE project_id = %s",
            $project_id
        ));
        if (!is_array($rows)) {
            return $stats;
        }

        foreach ($rows as $row) {
            if (!is_object($row) || !isset($row->id)) {
                continue;
            }
            $row_id = (int) $row->id;
            $sid = trim((string) ($row->source_record_id ?? ''));
            $ente = self::catalog_slug_norm(trim((string) ($row->ente_id ?? '')));
            $sid_slug = ($sid !== '' && !ctype_digit($sid)) ? self::catalog_slug_norm($sid) : '';

            if ($sid !== '' && ctype_digit($sid) && isset($pairs[$sid])) {
                $target = self::catalog_slug_norm((string) $pairs[$sid]);
                if ($target === '') {
                    continue;
                }
                $slug_row = Xabia_DB::find_knowledge_row_by_ente($project_id, $target);
                if ($slug_row !== null && isset($slug_row->id) && (int) $slug_row->id !== $row_id) {
                    if ($wpdb->delete($t, ['id' => $row_id, 'project_id' => $project_id], ['%d', '%s'])) {
                        $stats['deleted']++;
                    }
                    continue;
                }
                $meta = self::decode_meta_blob($row->meta_blob ?? '');
                $meta['__source_record_id'] = $target;
                $meta['__canonical_key'] = $target;
                $meta['__ente_id'] = $target;
                if (Xabia_DB::update_knowledge_identity($row_id, [
                    'ente_id'          => $target,
                    'source_record_id' => $target,
                ], $meta)) {
                    $stats['migrated']++;
                }
                continue;
            }

            if ($ente !== '' && $ente !== 'global' && isset($catalog_slug_set[$ente]) && $sid !== $ente) {
                $slug_row = Xabia_DB::find_knowledge_row_by_ente($project_id, $ente);
                if ($slug_row !== null && isset($slug_row->id) && (int) $slug_row->id !== $row_id) {
                    if ($wpdb->delete($t, ['id' => $row_id, 'project_id' => $project_id], ['%d', '%s'])) {
                        $stats['deleted']++;
                    }
                    continue;
                }
                $meta = self::decode_meta_blob($row->meta_blob ?? '');
                $meta['__source_record_id'] = $ente;
                $meta['__canonical_key'] = $ente;
                if (Xabia_DB::update_knowledge_identity($row_id, [
                    'ente_id'          => $ente,
                    'source_record_id' => $ente,
                ], $meta)) {
                    $stats['fixed']++;
                }
            }
        }

        $stats['fixed'] += self::normalize_project_slug_fields($project_id);
        $stats['deleted'] += self::dedupe_rows_by_slug_alias($project_id);
        $stats['deleted'] += self::dedupe_rows_by_ente_id($project_id);
        $stats['deleted'] += self::dedupe_rows_by_canonical_key($project_id);
        $stats['purged_ghosts'] += self::purge_ghost_rows_not_in_catalog($project_id, $catalog_slug_set, $pairs);

        return $stats;
    }

    /**
     * Elimina filas que no existen en el catálogo WP actual (traducciones, IDs viejos, duplicados).
     *
     * @param array<string, true> $catalog_slug_set
     * @param array<string, string> $id_slug_pairs
     */
    private static function purge_ghost_rows_not_in_catalog(string $project_id, array $catalog_slug_set, array $id_slug_pairs): int {
        if ($catalog_slug_set === [] || count($catalog_slug_set) < 10) {
            return 0;
        }

        global $wpdb;
        $t = Xabia_DB::table('knowledge_vectors');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, source_record_id, ente_id FROM {$t} WHERE project_id = %s",
            $project_id
        ));
        if (!is_array($rows) || $rows === []) {
            return 0;
        }

        $catalog_has_text_slug = false;
        foreach (array_keys($catalog_slug_set) as $catalog_key) {
            if ($catalog_key !== '' && !ctype_digit((string) $catalog_key)) {
                $catalog_has_text_slug = true;
                break;
            }
        }
        $memory_has_text_slug = false;
        foreach ($rows as $row) {
            if (!is_object($row)) {
                continue;
            }
            $ente = self::catalog_slug_norm(trim((string) ($row->ente_id ?? '')));
            if ($ente !== '' && $ente !== 'global') {
                $memory_has_text_slug = true;
                break;
            }
            $sid = trim((string) ($row->source_record_id ?? ''));
            if ($sid !== '' && !ctype_digit($sid)) {
                $memory_has_text_slug = true;
                break;
            }
        }
        if ($memory_has_text_slug && !$catalog_has_text_slug && $id_slug_pairs === []) {
            return 0;
        }

        $to_purge = [];
        foreach ($rows as $row) {
            if (!is_object($row) || !isset($row->id)) {
                continue;
            }
            if (self::row_belongs_to_catalog($row, $catalog_slug_set, $id_slug_pairs)) {
                continue;
            }
            $to_purge[] = $row;
        }
        if ($to_purge === []) {
            return 0;
        }
        $total = count($rows);
        if (($total > 0) && (count($to_purge) / $total) > 0.5) {
            return 0;
        }

        $purged = 0;
        foreach ($to_purge as $row) {
            $n = $wpdb->delete($t, ['id' => (int) $row->id, 'project_id' => $project_id], ['%d', '%s']);
            if ($n !== false && $n > 0) {
                $purged++;
            }
        }

        return $purged;
    }

    /**
     * @param object $row
     * @param array<string, true> $catalog_slug_set
     * @param array<string, string> $id_slug_pairs
     */
    private static function row_belongs_to_catalog($row, array $catalog_slug_set, array $id_slug_pairs): bool {
        $sid = trim((string) ($row->source_record_id ?? ''));
        $ente = self::catalog_slug_norm(trim((string) ($row->ente_id ?? '')));

        if ($sid !== '' && !ctype_digit($sid)) {
            $sid_slug = self::catalog_slug_norm($sid);
            if ($sid_slug !== '' && isset($catalog_slug_set[$sid_slug])) {
                return true;
            }
        }
        if ($ente !== '' && $ente !== 'global' && isset($catalog_slug_set[$ente])) {
            return true;
        }
        if ($sid !== '' && ctype_digit($sid) && isset($id_slug_pairs[$sid])) {
            return true;
        }
        if ($ente !== '' && $ente !== 'global') {
            foreach ($id_slug_pairs as $post_id => $slug) {
                if (self::catalog_slug_norm((string) $slug) === $ente) {
                    return true;
                }
                if (trim((string) $post_id) === $sid && $sid !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Alinea ente_id y source_record_id al slug canónico (guiones medios).
     */
    private static function normalize_project_slug_fields(string $project_id): int {
        global $wpdb;
        $project_id = sanitize_key($project_id);
        if ($project_id === '' || !class_exists('Xabia_DB', false) || !class_exists('Xabia_Knowledge_Ingest', false)) {
            return 0;
        }

        $t = Xabia_DB::table('knowledge_vectors');
        $meta_col = Xabia_DB::knowledge_vectors_meta_column();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, source_record_id, ente_id, `{$meta_col}` AS meta_blob FROM {$t} WHERE project_id = %s",
            $project_id
        ));
        if (!is_array($rows)) {
            return 0;
        }

        $fixed = 0;
        foreach ($rows as $row) {
            if (!is_object($row) || !isset($row->id)) {
                continue;
            }
            $ente = Xabia_Knowledge_Ingest::canonical_slug((string) ($row->ente_id ?? ''));
            if ($ente === '' || $ente === 'global') {
                $ente = Xabia_Knowledge_Ingest::canonical_slug((string) ($row->source_record_id ?? ''));
            }
            if ($ente === '' || $ente === 'global') {
                continue;
            }
            $sid = trim((string) ($row->source_record_id ?? ''));
            $needs = ((string) ($row->ente_id ?? '')) !== $ente
                || $sid !== $ente
                || (ctype_digit($sid) && $sid !== '');
            if (!$needs) {
                continue;
            }
            $meta = self::decode_meta_blob($row->meta_blob ?? '');
            $meta['__ente_id'] = $ente;
            $meta['__source_record_id'] = $ente;
            $meta['__canonical_key'] = $ente;
            if (Xabia_DB::update_knowledge_identity((int) $row->id, [
                'ente_id'          => $ente,
                'source_record_id' => $ente,
            ], $meta)) {
                $fixed++;
            }
        }

        return $fixed;
    }

    /**
     * Colapsa alias de slug (guión bajo vs guión medio) en una sola fila por empresa.
     */
    private static function dedupe_rows_by_slug_alias(string $project_id): int {
        global $wpdb;
        $project_id = sanitize_key($project_id);
        if ($project_id === '' || !class_exists('Xabia_DB', false)) {
            return 0;
        }

        $t = Xabia_DB::table('knowledge_vectors');
        $vec_col = Xabia_DB::knowledge_vectors_vector_column();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, ente_id, source_record_id, {$vec_col} AS vector_col FROM {$t} WHERE project_id = %s",
            $project_id
        ));
        if (!is_array($rows) || $rows === []) {
            return 0;
        }

        $groups = [];
        foreach ($rows as $row) {
            if (!is_object($row)) {
                continue;
            }
            $key = '';
            if (class_exists('Xabia_Knowledge_Ingest', false)) {
                $key = Xabia_Knowledge_Ingest::canonical_slug((string) ($row->ente_id ?? ''));
                if ($key === '' || $key === 'global') {
                    $key = Xabia_Knowledge_Ingest::canonical_slug((string) ($row->source_record_id ?? ''));
                }
            }
            if ($key === '' || $key === 'global') {
                continue;
            }
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $row;
        }

        $deleted = 0;
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            $keep_id = 0;
            foreach ($group as $item) {
                $sid = trim((string) ($item->source_record_id ?? ''));
                $vec = trim((string) ($item->vector_col ?? ''));
                if ($sid !== '' && !ctype_digit($sid) && $vec !== '' && $vec !== '[]' && $vec !== 'null') {
                    $keep_id = (int) $item->id;
                    break;
                }
            }
            if ($keep_id < 1) {
                foreach ($group as $item) {
                    $sid = trim((string) ($item->source_record_id ?? ''));
                    if ($sid !== '' && !ctype_digit($sid)) {
                        $keep_id = (int) $item->id;
                        break;
                    }
                }
            }
            if ($keep_id < 1) {
                $keep_id = (int) ($group[0]->id ?? 0);
            }
            foreach ($group as $item) {
                $id = (int) ($item->id ?? 0);
                if ($id < 1 || $id === $keep_id) {
                    continue;
                }
                if ($wpdb->delete($t, ['id' => $id, 'project_id' => $project_id], ['%d', '%s'])) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Una fila por slug/source_record_id: conserva embedding o el id más alto.
     */
    private static function dedupe_rows_by_canonical_key(string $project_id): int {
        global $wpdb;
        $project_id = sanitize_key($project_id);
        if ($project_id === '' || !class_exists('Xabia_DB', false)) {
            return 0;
        }

        $t = Xabia_DB::table('knowledge_vectors');
        $vec_col = Xabia_DB::knowledge_vectors_vector_column();
        $dupes = $wpdb->get_results($wpdb->prepare(
            "SELECT source_record_id, COUNT(*) AS c FROM {$t}
             WHERE project_id = %s AND source_record_id IS NOT NULL AND source_record_id != ''
             GROUP BY source_record_id HAVING c > 1",
            $project_id
        ));
        if (!is_array($dupes) || $dupes === []) {
            return 0;
        }

        $deleted = 0;
        foreach ($dupes as $dupe) {
            $key = trim((string) ($dupe->source_record_id ?? ''));
            if ($key === '') {
                continue;
            }
            $group = $wpdb->get_results($wpdb->prepare(
                "SELECT id, {$vec_col} AS vector_col FROM {$t} WHERE project_id = %s AND source_record_id = %s ORDER BY id DESC",
                $project_id,
                $key
            ));
            if (!is_array($group) || count($group) < 2) {
                continue;
            }
            $keep_id = (int) ($group[0]->id ?? 0);
            foreach ($group as $item) {
                if (!is_object($item)) {
                    continue;
                }
                $vec = trim((string) ($item->vector_col ?? ''));
                if ($vec !== '' && $vec !== '[]' && $vec !== 'null') {
                    $keep_id = (int) $item->id;
                    break;
                }
            }
            foreach ($group as $item) {
                if (!is_object($item)) {
                    continue;
                }
                $id = (int) ($item->id ?? 0);
                if ($id < 1 || $id === $keep_id) {
                    continue;
                }
                if ($wpdb->delete($t, ['id' => $id, 'project_id' => $project_id], ['%d', '%s'])) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Una fila por ente_id (slug empresa): elimina copias con distinto source_record_id numérico.
     */
    private static function dedupe_rows_by_ente_id(string $project_id): int {
        global $wpdb;
        $project_id = sanitize_key($project_id);
        if ($project_id === '' || !class_exists('Xabia_DB', false)) {
            return 0;
        }

        $t = Xabia_DB::table('knowledge_vectors');
        $vec_col = Xabia_DB::knowledge_vectors_vector_column();
        $dupes = $wpdb->get_results($wpdb->prepare(
            "SELECT ente_id, COUNT(*) AS c FROM {$t}
             WHERE project_id = %s AND ente_id IS NOT NULL AND ente_id != '' AND ente_id != 'global'
             GROUP BY ente_id HAVING c > 1",
            $project_id
        ));
        if (!is_array($dupes) || $dupes === []) {
            return 0;
        }

        $deleted = 0;
        foreach ($dupes as $dupe) {
            $ente = sanitize_title(trim((string) ($dupe->ente_id ?? '')));
            if ($ente === '') {
                continue;
            }
            $group = $wpdb->get_results($wpdb->prepare(
                "SELECT id, source_record_id, {$vec_col} AS vector_col FROM {$t}
                 WHERE project_id = %s AND ente_id = %s ORDER BY id DESC",
                $project_id,
                $ente
            ));
            if (!is_array($group) || count($group) < 2) {
                continue;
            }

            $keep_id = 0;
            foreach ($group as $item) {
                if (!is_object($item)) {
                    continue;
                }
                $sid = trim((string) ($item->source_record_id ?? ''));
                $vec = trim((string) ($item->vector_col ?? ''));
                $has_vec = $vec !== '' && $vec !== '[]' && $vec !== 'null';
                if ($sid !== '' && !ctype_digit($sid) && $has_vec) {
                    $keep_id = (int) $item->id;
                    break;
                }
            }
            if ($keep_id < 1) {
                foreach ($group as $item) {
                    if (!is_object($item)) {
                        continue;
                    }
                    $sid = trim((string) ($item->source_record_id ?? ''));
                    if ($sid !== '' && !ctype_digit($sid)) {
                        $keep_id = (int) $item->id;
                        break;
                    }
                }
            }
            if ($keep_id < 1) {
                foreach ($group as $item) {
                    if (!is_object($item)) {
                        continue;
                    }
                    $vec = trim((string) ($item->vector_col ?? ''));
                    if ($vec !== '' && $vec !== '[]' && $vec !== 'null') {
                        $keep_id = (int) $item->id;
                        break;
                    }
                }
            }
            if ($keep_id < 1) {
                $keep_id = (int) ($group[0]->id ?? 0);
            }

            foreach ($group as $item) {
                if (!is_object($item)) {
                    continue;
                }
                $id = (int) ($item->id ?? 0);
                if ($id < 1 || $id === $keep_id) {
                    continue;
                }
                if ($wpdb->delete($t, ['id' => $id, 'project_id' => $project_id], ['%d', '%s'])) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode_meta_blob($blob): array {
        if (empty($blob)) {
            return [];
        }
        $decoded = json_decode((string) $blob, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>|null null si no se puede obtener el catálogo de IDs
     */
    public static function fetch_catalog_source_ids(string $project_id, array $config): ?array {
        $catalog_sql = self::resolve_catalog_sql($config);
        if ($catalog_sql === '') {
            return null;
        }

        $sql_config = self::build_sql_config($project_id, $config, $catalog_sql);
        if ($sql_config === null) {
            return null;
        }

        if (class_exists('Xabia_Knowledge_Ingest', false)) {
            $prefix = self::resolve_sql_prefix($sql_config, $config);
            $remote_db = self::maybe_open_remote_db($sql_config, $config);
            $sql_config['query'] = Xabia_Knowledge_Ingest::apply_primary_language_sql_filter(
                (string) ($sql_config['query'] ?? ''),
                $project_id,
                $config,
                $prefix,
                $remote_db
            );
        }

        if (!class_exists('Xabia_SQL_Connector', false)) {
            $path = XABIA_PATH . 'integrations/class-xabia-sql-connector.php';
            if (is_readable($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Xabia_SQL_Connector', false)) {
            return null;
        }

        $raw = Xabia_SQL_Connector::fetch_data($sql_config);
        if (is_wp_error($raw) || !is_array($raw)) {
            return null;
        }

        $mapping = isset($config['attributes']) && is_array($config['attributes']) ? $config['attributes'] : [];
        $ids = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = '';
            if (class_exists('Xabia_Knowledge_Ingest', false)) {
                $id = Xabia_Knowledge_Ingest::canonical_record_key($row, $mapping);
            }
            if ($id === '' && class_exists('Xabia_Knowledge_Optimizer', false)) {
                $id = Xabia_Knowledge_Optimizer::source_record_id_from_row($row, $mapping);
            }
            if ($id === '' && isset($row['ID'])) {
                $id = sanitize_text_field((string) $row['ID']);
            }
            if ($id === '' && isset($row['post_name'])) {
                $id = sanitize_title((string) $row['post_name']);
            }
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === [] && class_exists('Xabia_DB', false)) {
            global $wpdb;
            $t = Xabia_DB::table('knowledge_vectors');
            $stored = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$t} WHERE project_id = %s",
                sanitize_key($project_id)
            ));
            if ($stored > 0) {
                return null;
            }
        }

        return $ids;
    }

    /**
     * Evita falsos positivos masivos (p. ej. post_type mal detectado en SQL con subconsultas).
     *
     * @param list<string> $catalog_ids
     * @param list<array{id:int,source_record_id:string,label:string}> $orphans
     * @return list<array{id:int,source_record_id:string,label:string}>
     */
    private static function filter_suspicious_orphans(string $project_id, array $orphans, array $catalog_ids): array {
        global $wpdb;
        $project_id = sanitize_key($project_id);
        if ($project_id === '' || !class_exists('Xabia_DB', false)) {
            return [];
        }

        $t = Xabia_DB::table('knowledge_vectors');
        $stored_with_source = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE project_id = %s AND source_record_id IS NOT NULL AND source_record_id != ''",
            $project_id
        ));

        if (
            $stored_with_source > 0
            && count($orphans) >= (int) ceil($stored_with_source * 0.85)
            && count($catalog_ids) < (int) ceil($stored_with_source * 0.25)
        ) {
            return [];
        }

        if (self::looks_like_slug_catalog_id_mismatch($orphans, $catalog_ids, $stored_with_source)) {
            return [];
        }

        if (
            count($catalog_ids) >= 20
            && count($orphans) > count($catalog_ids)
            && count($orphans) >= (int) ceil($stored_with_source * 0.4)
        ) {
            return [];
        }

        return $orphans;
    }

    /**
     * Tras migrar a slug (1.0.90+), filas con source_record_id numérico no coinciden con el catálogo por post_name.
     *
     * @param list<array{id:int,source_record_id:string,label:string}> $orphans
     * @param list<string> $catalog_ids
     */
    private static function looks_like_slug_catalog_id_mismatch(array $orphans, array $catalog_ids, int $stored_with_source): bool {
        if ($orphans === [] || $catalog_ids === [] || $stored_with_source < 1) {
            return false;
        }

        $numeric_orphans = 0;
        foreach ($orphans as $orphan) {
            $sid = trim((string) ($orphan['source_record_id'] ?? ''));
            if ($sid !== '' && ctype_digit($sid)) {
                $numeric_orphans++;
            }
        }

        $slug_catalog = 0;
        foreach ($catalog_ids as $id) {
            $id = trim((string) $id);
            if ($id !== '' && !ctype_digit($id)) {
                $slug_catalog++;
            }
        }

        if ($numeric_orphans < (int) ceil(count($orphans) * 0.85)) {
            return false;
        }
        if ($slug_catalog < (int) ceil(count($catalog_ids) * 0.85)) {
            return false;
        }
        if (count($orphans) < (int) ceil($stored_with_source * 0.85)) {
            return false;
        }

        return true;
    }

    /**
     * @deprecated Usar reconcile_project_memory()
     * @param array<string, mixed> $config
     */
    public static function migrate_legacy_source_keys(string $project_id, array $config): int {
        $stats = self::reconcile_project_memory($project_id, $config);

        return (int) ($stats['migrated'] + $stats['deleted'] + $stats['fixed']);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string> post ID → slug
     */
    private static function fetch_catalog_id_slug_pairs(string $project_id, array $config): array {
        $catalog_sql = self::resolve_catalog_sql($config);
        if ($catalog_sql === '') {
            return [];
        }

        if (!preg_match('/\bFROM\s+[`\']?[\w]*posts[`\']?\s+(?:AS\s+)?p\b/i', $catalog_sql)) {
            return [];
        }

        $catalog_sql = preg_replace(
            '/SELECT\s+p\.post_name\b/i',
            'SELECT p.ID, p.post_name',
            $catalog_sql,
            1
        );
        if (!preg_match('/\bSELECT\s+p\.ID\b/i', $catalog_sql)) {
            $catalog_sql = preg_replace('/\bSELECT\b/i', 'SELECT p.ID,', $catalog_sql, 1);
        }

        $sql_config = self::build_sql_config($project_id, $config, $catalog_sql);
        if ($sql_config === null) {
            return [];
        }

        if (class_exists('Xabia_Knowledge_Ingest', false)) {
            $prefix = self::resolve_sql_prefix($sql_config, $config);
            $remote_db = self::maybe_open_remote_db($sql_config, $config);
            $sql_config['query'] = Xabia_Knowledge_Ingest::apply_primary_language_sql_filter(
                (string) ($sql_config['query'] ?? ''),
                $project_id,
                $config,
                $prefix,
                $remote_db
            );
        }

        if (!class_exists('Xabia_SQL_Connector', false)) {
            $path = XABIA_PATH . 'integrations/class-xabia-sql-connector.php';
            if (is_readable($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Xabia_SQL_Connector', false)) {
            return [];
        }

        $raw = Xabia_SQL_Connector::fetch_data($sql_config);
        if (is_wp_error($raw) || !is_array($raw)) {
            return [];
        }

        $pairs = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = '';
            foreach (['ID', 'id'] as $key) {
                if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                    $id = trim((string) $row[$key]);
                    break;
                }
            }
            $slug = '';
            foreach (['post_name', 'Slug_Empresa', 'slug'] as $key) {
                if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                    $slug = sanitize_title((string) $row[$key]);
                    break;
                }
            }
            if ($id !== '' && $slug !== '') {
                $pairs[$id] = $slug;
            }
        }

        return $pairs;
    }

    /**
     * @param list<string> $catalog_ids
     * @param array<string, string> $id_slug_pairs post ID → slug
     * @return list<array{id:int,source_record_id:string,label:string}>
     */
    public static function find_orphan_rows(string $project_id, array $catalog_ids, array $id_slug_pairs = []): array {
        global $wpdb;
        $project_id = sanitize_key($project_id);
        if ($project_id === '' || !class_exists('Xabia_DB', false)) {
            return [];
        }

        $cols = Xabia_DB::knowledge_vectors_column_map();
        if (!isset($cols['source_record_id'])) {
            return [];
        }

        $catalog_set = array_fill_keys(array_map('strval', $catalog_ids), true);
        $t = Xabia_DB::table('knowledge_vectors');
        $meta_col = Xabia_DB::knowledge_vectors_meta_column();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, source_record_id, ente_id, content_chunk, {$meta_col} AS meta_blob
                 FROM {$t}
                 WHERE project_id = %s AND source_record_id IS NOT NULL AND source_record_id != ''",
                $project_id
            )
        );

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $orphans = [];
        foreach ($rows as $row) {
            if (!is_object($row)) {
                continue;
            }
            $sid = trim((string) ($row->source_record_id ?? ''));
            $ente = isset($row->ente_id) ? sanitize_title(trim((string) $row->ente_id)) : '';
            if ($sid === '') {
                continue;
            }
            if (isset($catalog_set[$sid]) || ($ente !== '' && $ente !== 'global' && isset($catalog_set[$ente]))) {
                continue;
            }
            if ($sid !== '' && ctype_digit($sid) && isset($id_slug_pairs[$sid])) {
                continue;
            }
            $label = self::row_label($row);
            if ($label !== '' && isset($catalog_set[sanitize_title($label)])) {
                continue;
            }
            $orphans[] = [
                'id'               => (int) $row->id,
                'source_record_id' => $sid,
                'label'            => self::row_label($row),
            ];
        }

        usort($orphans, static function (array $a, array $b): int {
            return strcasecmp((string) $a['label'], (string) $b['label']);
        });

        return self::dedupe_orphans_by_label(self::dedupe_orphans_by_source($orphans));
    }

    /**
     * @param list<array{id:int,source_record_id:string,label:string,vector_ids?:list<int>}> $orphans
     * @return list<array{id:int,source_record_id:string,label:string,vector_ids:list<int>}>
     */
    private static function dedupe_orphans_by_label(array $orphans): array {
        $by_label = [];
        foreach ($orphans as $orphan) {
            $label = sanitize_title(trim((string) ($orphan['label'] ?? '')));
            if ($label === '') {
                $label = trim((string) ($orphan['source_record_id'] ?? ''));
            }
            if ($label === '') {
                continue;
            }
            if (!isset($by_label[$label])) {
                $by_label[$label] = [
                    'id'               => (int) ($orphan['id'] ?? 0),
                    'source_record_id' => (string) ($orphan['source_record_id'] ?? ''),
                    'label'            => (string) ($orphan['label'] ?? $label),
                    'vector_ids'       => [],
                ];
            }
            $vids = isset($orphan['vector_ids']) && is_array($orphan['vector_ids']) ? $orphan['vector_ids'] : [];
            if ($vids === [] && !empty($orphan['id'])) {
                $vids = [(int) $orphan['id']];
            }
            foreach ($vids as $vid) {
                $vid = (int) $vid;
                if ($vid > 0) {
                    $by_label[$label]['vector_ids'][] = $vid;
                }
            }
        }

        $out = [];
        foreach ($by_label as $row) {
            $row['vector_ids'] = array_values(array_unique(array_filter($row['vector_ids'])));
            if ($row['vector_ids'] === [] && $row['id'] > 0) {
                $row['vector_ids'] = [$row['id']];
            }
            $row['id'] = (int) ($row['vector_ids'][0] ?? $row['id']);
            $out[] = $row;
        }

        usort($out, static function (array $a, array $b): int {
            return strcasecmp((string) $a['label'], (string) $b['label']);
        });

        return $out;
    }

    /**
     * Una empresa puede tener varios chunks; el aviso muestra cada ente una sola vez.
     *
     * @param list<array{id:int,source_record_id:string,label:string}> $orphans
     * @return list<array{id:int,source_record_id:string,label:string,vector_ids:list<int>}>
     */
    private static function dedupe_orphans_by_source(array $orphans): array {
        $by_source = [];
        foreach ($orphans as $orphan) {
            $sid = (string) ($orphan['source_record_id'] ?? '');
            if ($sid === '') {
                continue;
            }
            if (!isset($by_source[$sid])) {
                $by_source[$sid] = [
                    'id'               => (int) ($orphan['id'] ?? 0),
                    'source_record_id' => $sid,
                    'label'            => (string) ($orphan['label'] ?? $sid),
                    'vector_ids'       => [],
                ];
            }
            $vid = (int) ($orphan['id'] ?? 0);
            if ($vid > 0) {
                $by_source[$sid]['vector_ids'][] = $vid;
            }
        }

        $out = [];
        foreach ($by_source as $row) {
            $row['vector_ids'] = array_values(array_unique(array_filter($row['vector_ids'])));
            if ($row['vector_ids'] === [] && $row['id'] > 0) {
                $row['vector_ids'] = [$row['id']];
            }
            $row['id'] = (int) ($row['vector_ids'][0] ?? $row['id']);
            $out[] = $row;
        }

        usort($out, static function (array $a, array $b): int {
            return strcasecmp((string) $a['label'], (string) $b['label']);
        });

        return $out;
    }

    /**
     * @param list<int> $vector_ids
     */
    public static function purge(string $project_id, array $vector_ids): int {
        global $wpdb;
        $project_id = sanitize_key($project_id);
        if ($project_id === '' || !class_exists('Xabia_DB', false)) {
            return 0;
        }

        $ids = array_values(array_unique(array_filter(array_map('absint', $vector_ids))));
        if ($ids === []) {
            return 0;
        }

        $t = Xabia_DB::table('knowledge_vectors');
        $deleted = 0;
        foreach ($ids as $id) {
            if ($id < 1) {
                continue;
            }
            $n = $wpdb->delete($t, ['id' => $id, 'project_id' => $project_id], ['%d', '%s']);
            if ($n !== false && $n > 0) {
                $deleted += (int) $n;
            }
        }

        return $deleted;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function resolve_catalog_sql(array $config): string {
        $custom = trim((string) ($config['catalog_id_sql'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        $main_sql = self::resolve_main_query($config);
        if ($main_sql === '') {
            return '';
        }

        $derived = class_exists('Xabia_Knowledge_Ingest', false)
            ? Xabia_Knowledge_Ingest::derive_catalog_sql($main_sql)
            : self::derive_catalog_sql($main_sql);
        if ($derived === '') {
            $derived = self::derive_catalog_sql($main_sql);
        }

        return (string) apply_filters('xabia_knowledge_catalog_id_sql', $derived, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function resolve_main_query(array $config): string {
        $source_type = (string) ($config['source_type'] ?? '');

        if ($source_type === 'sql' || $source_type === 'local_sql') {
            return trim((string) (($config['sql_config']['query'] ?? '')));
        }

        if ($source_type === 'addon') {
            global $xabia_available_addons;
            $slug = (string) ($config['addon_slug'] ?? '');
            $addons = array_merge((array) $xabia_available_addons, (array) apply_filters('xabia_register_sql_sources', []));
            if ($slug !== '' && isset($addons[$slug]['callback']) && is_callable($addons[$slug]['callback'])) {
                $sql = call_user_func($addons[$slug]['callback']);

                return is_string($sql) ? trim($sql) : '';
            }
        }

        return '';
    }

    private static function derive_catalog_sql(string $sql): string {
        $posts_table = '';
        if (preg_match('/\bFROM\s+([`\'"]?(?:\{prefix\}|[\w]+)posts[`\'"]?)\s+(?:AS\s+)?p\b/i', $sql, $from_match)) {
            $posts_table = trim($from_match[1], "`'\" ");
        } else {
            return '';
        }

        $post_type = '';
        if (preg_match('/\bWHERE\b\s+[^;]*?p\.post_type\s*=\s*\'([^\']+)\'/is', $sql, $where_match)) {
            $post_type = $where_match[1];
        } elseif (preg_match('/\bp\.post_type\s*=\s*\'([^\']+)\'/i', $sql, $alias_match)) {
            $post_type = $alias_match[1];
        }

        if ($post_type === '') {
            return '';
        }

        $post_type = preg_replace('/[^a-z0-9_-]/i', '', $post_type);
        if ($post_type === '') {
            return '';
        }

        return "SELECT p.ID FROM {$posts_table} p WHERE p.post_type = '{$post_type}' AND p.post_status = 'publish'";
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    private static function build_sql_config(string $project_id, array $config, string $catalog_sql): ?array {
        unset($project_id);
        $source_type = (string) ($config['source_type'] ?? '');

        if ($source_type === 'local_sql') {
            $sql_config = $config['sql_config'] ?? [];
            $sql_config['host'] = '';
            $sql_config['query'] = self::apply_prefix_to_sql($catalog_sql, $sql_config, true);

            return $sql_config;
        }

        if ($source_type === 'sql') {
            $sql_config = $config['sql_config'] ?? [];
            $sql_config['query'] = self::apply_prefix_to_sql($catalog_sql, $sql_config, false);

            return $sql_config;
        }

        if ($source_type === 'addon') {
            $host = trim((string) (($config['sql_config']['host'] ?? '')));
            $sql_config = $host !== '' ? ($config['sql_config'] ?? []) : [
                'host' => DB_HOST,
                'user' => DB_USER,
                'pass' => DB_PASSWORD,
                'name' => DB_NAME,
            ];
            $sql_config['query'] = self::apply_prefix_to_sql($catalog_sql, $sql_config, $host === '');

            return $sql_config;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $sql_config
     */
    private static function apply_prefix_to_sql(string $sql, array $sql_config, bool $local): string {
        if (!class_exists('Xabia_SQL_Connector', false)) {
            $path = defined('XABIA_PATH')
                ? XABIA_PATH . 'integrations/class-xabia-sql-connector.php'
                : plugin_dir_path(dirname(__FILE__)) . '../integrations/class-xabia-sql-connector.php';
            if (is_readable($path)) {
                require_once $path;
            }
        }
        if (class_exists('Xabia_SQL_Connector', false)) {
            $cfg = $sql_config;
            if ($local) {
                $cfg['host'] = '';
            }

            return Xabia_SQL_Connector::apply_prefix_to_sql($sql, $cfg);
        }

        global $wpdb;
        $manual_prefix = trim((string) ($sql_config['prefix'] ?? ''));
        if ($manual_prefix !== '') {
            $real_prefix = $manual_prefix;
        } elseif (!$local && !empty($sql_config['host'])) {
            $real_prefix = 'wp_';
        } else {
            $real_prefix = $wpdb->prefix;
        }

        if (stripos($sql, '{prefix}') !== false) {
            $sql = str_replace('{prefix}', $real_prefix, $sql);
        }

        return $sql;
    }

    /**
     * @param array<string, mixed> $sql_config
     * @param array<string, mixed> $config
     * @return object|null wpdb remoto si hay host; null = usar global.
     */
    private static function maybe_open_remote_db(array $sql_config, array $config) {
        if (class_exists('Xabia_Knowledge_Sync', false) && !Xabia_Knowledge_Sync::is_remote_config($config)) {
            return null;
        }
        $host = trim((string) ($sql_config['host'] ?? ''));
        if ($host === '') {
            return null;
        }
        $user = (string) ($sql_config['user'] ?? '');
        $pass = (string) ($sql_config['pass'] ?? '');
        $name = (string) ($sql_config['name'] ?? '');
        if ($user === '' || $name === '') {
            return null;
        }
        $rdb = new wpdb($user, $pass, $name, $host);
        if (!empty($rdb->error)) {
            return null;
        }

        return $rdb;
    }

    /**
     * @param array<string, mixed> $sql_config
     * @param array<string, mixed> $config
     */
    private static function resolve_sql_prefix(array $sql_config, array $config): string {
        if (!class_exists('Xabia_SQL_Connector', false)) {
            $path = defined('XABIA_PATH')
                ? XABIA_PATH . 'integrations/class-xabia-sql-connector.php'
                : plugin_dir_path(dirname(__FILE__)) . '../integrations/class-xabia-sql-connector.php';
            if (is_readable($path)) {
                require_once $path;
            }
        }
        $source_type = (string) ($config['source_type'] ?? '');
        $cfg = $sql_config;
        if ($source_type === 'local_sql') {
            $cfg['host'] = '';
        }
        if (class_exists('Xabia_SQL_Connector', false)) {
            return Xabia_SQL_Connector::resolve_table_prefix($cfg);
        }
        global $wpdb;
        $manual_prefix = trim((string) ($sql_config['prefix'] ?? ''));
        if ($manual_prefix !== '') {
            return $manual_prefix;
        }
        if ($source_type === 'local_sql' || empty($sql_config['host'])) {
            return $wpdb->prefix;
        }

        return 'wp_';
    }

    /**
     * @param object $row
     */
    private static function row_label($row): string {
        if (!empty($row->meta_blob)) {
            $meta = json_decode((string) $row->meta_blob, true);
            if (is_array($meta)) {
                foreach (['__ente_display', 'empresa', 'title', 'nombre', 'name'] as $key) {
                    if (!empty($meta[$key]) && is_scalar($meta[$key])) {
                        return trim((string) $meta[$key]);
                    }
                }
            }
        }

        $chunk = trim(strip_tags((string) ($row->content_chunk ?? '')));
        if ($chunk !== '' && preg_match('/\bEMPRESA:\s*([^|]+)/iu', $chunk, $match_passport)) {
            return trim($match_passport[1]);
        }
        if ($chunk !== '' && preg_match('/\bempresa:\s*([^|]+)/iu', $chunk, $match)) {
            return trim($match[1]);
        }
        if ($chunk !== '') {
            if (function_exists('mb_strlen') && mb_strlen($chunk) > 60) {
                return mb_substr($chunk, 0, 57) . '…';
            }
            if (strlen($chunk) > 60) {
                return substr($chunk, 0, 57) . '…';
            }

            return $chunk;
        }

        return __('(sin nombre)', 'xabia-intelligence');
    }
}
