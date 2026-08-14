<?php
/**
 * Fuente PRO: páginas y entradas publicadas del propio WordPress (+ HTML público opcional).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Xabia_Web_Pages_Source {

    public const SOURCE_TYPE = 'web_pages';

    /**
     * @param mixed $raw
     * @return list<int>
     */
    public static function parse_page_ids($raw): array {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,;]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $item) {
            $id = absint($item);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function default_mapping_fields(): array {
        return [
            [
                'csv_col'     => 'ID',
                'label'       => __('ID de página', 'xabia-intelligence'),
                'visual_role' => 'none',
                'is_ente'     => 1,
                'instruction' => __('Identificador único del contenido web.', 'xabia-intelligence'),
                'import_rag'  => 0,
            ],
            [
                'csv_col'     => 'Titulo',
                'label'       => __('Título', 'xabia-intelligence'),
                'visual_role' => 'title',
                'is_ente'     => 0,
                'instruction' => '',
                'import_rag'  => 1,
            ],
            [
                'csv_col'     => 'Contenido',
                'label'       => __('Contenido', 'xabia-intelligence'),
                'visual_role' => 'info',
                'is_ente'     => 0,
                'instruction' => __('Texto principal de la página.', 'xabia-intelligence'),
                'import_rag'  => 1,
            ],
            [
                'csv_col'     => 'URL',
                'label'       => __('URL', 'xabia-intelligence'),
                'visual_role' => 'web',
                'is_ente'     => 0,
                'instruction' => '',
                'import_rag'  => 1,
            ],
            [
                'csv_col'     => 'Extracto',
                'label'       => __('Extracto', 'xabia-intelligence'),
                'visual_role' => 'info',
                'is_ente'     => 0,
                'instruction' => '',
                'import_rag'  => 1,
            ],
        ];
    }

    /**
     * @param list<int>          $page_ids
     * @param array<string,mixed> $config
     * @return list<array<string,mixed>>
     */
    public static function fetch_rows(array $page_ids, string $project_id, array $config = []): array {
        $page_ids = array_values(array_filter(array_map('absint', $page_ids)));
        if ($page_ids === []) {
            return [];
        }

        $use_public_html = !empty($config['web_pages_use_public_html']);
        $primary_lang = class_exists('Xabia_Knowledge_Ingest', false)
            ? Xabia_Knowledge_Ingest::project_language_code($config)
            : '';

        $rows = [];
        foreach ($page_ids as $requested_id) {
            $post_id = $requested_id;
            if ($primary_lang !== '' && has_filter('wpml_object_id')) {
                $translated = apply_filters('wpml_object_id', $requested_id, get_post_type($requested_id) ?: 'page', true, $primary_lang);
                if (is_numeric($translated) && (int) $translated > 0) {
                    $post_id = (int) $translated;
                }
            }

            $post = get_post($post_id);
            if (!($post instanceof WP_Post) || $post->post_status !== 'publish') {
                continue;
            }
            if (!in_array($post->post_type, ['page', 'post'], true)) {
                continue;
            }

            $title = trim(wp_strip_all_tags(get_the_title($post)));
            $url = get_permalink($post);
            $url = is_string($url) ? $url : '';
            $excerpt = trim(wp_strip_all_tags((string) get_the_excerpt($post)));
            $content = trim(wp_strip_all_tags((string) $post->post_content));
            $content = preg_replace('/\s+/u', ' ', $content) ?? $content;

            if ($use_public_html && $url !== '' && class_exists('Xabia_Web_Scraper', false) && Xabia_Web_Scraper::is_public_http_url($url)) {
                $scraped = Xabia_Web_Scraper::scrape_url($url);
                if (!is_wp_error($scraped)) {
                    $scraped_body = Xabia_Web_Scraper::paragraphs_to_body((array) ($scraped['paragraphs'] ?? []));
                    if ($scraped_body !== '') {
                        $content = $scraped_body;
                    }
                    if ($title === '' && !empty($scraped['title'])) {
                        $title = (string) $scraped['title'];
                    }
                    if ($excerpt === '' && !empty($scraped['description'])) {
                        $excerpt = (string) $scraped['description'];
                    }
                }
            }

            if ($content === '' && $excerpt !== '') {
                $content = $excerpt;
            }
            if ($title === '' && $content === '') {
                continue;
            }

            $lang = '';
            if (class_exists('Xabia_Knowledge_Ingest', false)) {
                $lang = Xabia_Knowledge_Ingest::row_language_code([
                    'language_code' => $primary_lang,
                    'ID'            => $post_id,
                ]);
                if ($lang === '' && $primary_lang !== '') {
                    $lang = $primary_lang;
                }
            }

            $rows[] = [
                'ID'            => (string) $post_id,
                'Titulo'        => $title,
                'Contenido'     => $content,
                'URL'           => $url,
                'Extracto'      => $excerpt,
                'post_name'     => (string) $post->post_name,
                'language_code' => $lang,
            ];
        }

        return $rows;
    }

    /**
     * @param list<int>                   $page_ids
     * @param array<int,array<string,mixed>>|null $mapping
     * @param array<string,mixed>         $opts
     */
    public static function sync(string $project_id, array $page_ids, ?array $mapping = null, array $opts = [], array $config = []): int {
        $project_id = sanitize_key($project_id);
        if ($project_id === '') {
            return 0;
        }

        $projects = get_option('xabia_projects_config', []);
        $project_cfg = isset($projects[$project_id]) && is_array($projects[$project_id]) ? $projects[$project_id] : [];
        if ($config === []) {
            $config = $project_cfg;
        }

        $page_ids = self::parse_page_ids($page_ids);
        if ($page_ids === []) {
            return 0;
        }

        $rows = self::fetch_rows($page_ids, $project_id, $config);
        if ($rows === []) {
            return 0;
        }

        $map = ($mapping !== null && $mapping !== []) ? $mapping : self::default_mapping_fields();

        return (int) Xabia_DB_Bridge::process_prefetched_rows($project_id, $rows, $map);
    }

    /**
     * Páginas complementarias (p. ej. Addon MEC + páginas institucionales).
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $opts
     */
    public static function sync_supplemental(string $project_id, array $config, array $opts = []): int {
        $source_type = sanitize_key((string) ($config['source_type'] ?? ''));
        if ($source_type === self::SOURCE_TYPE || $source_type === 'multi') {
            return 0;
        }
        $ids = self::parse_page_ids($config['web_page_ids'] ?? []);
        if ($ids === []) {
            return 0;
        }

        return self::sync($project_id, $ids, $config['web_pages_attributes'] ?? null, $opts, $config);
    }

    /**
     * @param list<int> $selected_ids
     */
    public static function render_page_picker(string $array_field, string $text_field, array $selected_ids, string $legend, string $description = ''): void {
        $selected_ids = array_map('absint', $selected_ids);
        $posts = get_posts([
            'post_type'              => ['page', 'post'],
            'post_status'            => 'publish',
            'posts_per_page'         => 250,
            'orderby'                => 'title',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'suppress_filters'       => false,
        ]);
        if (!is_array($posts)) {
            $posts = [];
        }
        ?>
        <fieldset class="xabia-web-pages-picker" style="margin:12px 0;padding:12px;border:1px solid #dcdcde;border-radius:6px;">
            <legend style="font-weight:600;padding:0 6px;"><?php echo esc_html($legend); ?></legend>
            <?php if ($description !== '') : ?>
                <p class="description" style="margin:0 0 10px;"><?php echo esc_html($description); ?></p>
            <?php endif; ?>
            <?php if ($posts === []) : ?>
                <p class="description"><?php echo esc_html__('No hay páginas o entradas publicadas.', 'xabia-intelligence'); ?></p>
            <?php else : ?>
                <div style="max-height:220px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;">
                <?php foreach ($posts as $p) :
                    if (!($p instanceof WP_Post)) {
                        continue;
                    }
                    $pid = (int) $p->ID;
                    $type_obj = get_post_type_object($p->post_type);
                    $type_label = $type_obj && isset($type_obj->labels->singular_name)
                        ? (string) $type_obj->labels->singular_name
                        : $p->post_type;
                    $plink = get_permalink($p);
                    ?>
                    <label style="display:flex;align-items:flex-start;gap:8px;margin:0;">
                        <input type="checkbox" name="<?php echo esc_attr($array_field); ?>[]" value="<?php echo esc_attr((string) $pid); ?>" <?php checked(in_array($pid, $selected_ids, true)); ?> style="margin-top:3px;">
                        <span>
                            <?php echo esc_html($p->post_title); ?>
                            <code style="font-size:11px;">ID <?php echo (int) $pid; ?></code>
                            <span style="color:#646970;">(<?php echo esc_html($type_label); ?>)</span>
                            <?php if (is_string($plink) && $plink !== '') : ?>
                                <br><span style="font-size:11px;color:#646970;word-break:break-all;"><?php echo esc_html($plink); ?></span>
                            <?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <p style="margin:10px 0 4px;font-size:12px;color:#646970;"><?php echo esc_html__('IDs adicionales (comas)', 'xabia-intelligence'); ?></p>
            <input type="text" name="<?php echo esc_attr($text_field); ?>" class="widefat" placeholder="123, 456" value="">
        </fieldset>
        <?php
    }
}
