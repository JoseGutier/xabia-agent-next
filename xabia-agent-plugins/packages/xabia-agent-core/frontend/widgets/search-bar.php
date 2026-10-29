<?php
/**
 * Shortcode [xabia_search_bar]: barra hero (spark, placeholder rotatorio, mic, send, chips).
 * El chat real sigue en chatbox.php; esta barra solo lo abre.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Una frase por línea, mismo tope que las preguntas de arranque.
 *
 * @param mixed $raw
 * @return list<string>
 */
function xabia_search_bar_normalize_lines($raw): array {
    if (class_exists('Xabia_Starter_Questions', false)) {
        return Xabia_Starter_Questions::normalize_manual_list($raw);
    }
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
        $out[] = $line;
        if (count($out) >= 6) {
            break;
        }
    }
    return $out;
}

/**
 * Línea «Etiqueta | consulta». Sin barra, la etiqueta es la consulta.
 *
 * @param list<string> $lines
 * @return list<array{label:string,query:string}>
 */
function xabia_search_bar_parse_chips(array $lines): array {
    $out = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line, 2));
        $label = $parts[0] ?? '';
        $query = $parts[1] ?? '';
        if ($label === '') {
            continue;
        }
        if ($query === '') {
            $query = $label;
        }
        $out[] = [
            'label' => $label,
            'query' => $query,
        ];
    }
    return $out;
}

/**
 * @param array<string, mixed> $rules
 * @return list<string>
 */
function xabia_search_bar_placeholders_from_rules(array $rules): array {
    $raw = $rules['search_bar_placeholders'] ?? [];
    return xabia_search_bar_normalize_lines($raw);
}

/**
 * @param array<string, mixed> $rules
 * @return list<array{label:string,query:string}>
 */
function xabia_search_bar_chips_from_rules(array $rules): array {
    $raw = $rules['search_bar_chips'] ?? [];
    return xabia_search_bar_parse_chips(xabia_search_bar_normalize_lines($raw));
}

/**
 * @param array<string, mixed>|string $atts
 */
function xabia_search_bar_shortcode($atts): string {
    $atts = shortcode_atts(['id' => 'default'], is_array($atts) ? $atts : [], 'xabia_search_bar');
    $project_id = sanitize_key((string) ($atts['id'] ?? 'default'));
    if ($project_id === '') {
        $project_id = 'default';
    }

    if (class_exists('Xabia_Interface', false)) {
        Xabia_Interface::note_search_bar_project($project_id);
    }

    $projects = get_option('xabia_projects_config', []);
    $project = (is_array($projects) && isset($projects[$project_id]) && is_array($projects[$project_id]))
        ? $projects[$project_id]
        : [];
    $rules = (isset($project['rules']) && is_array($project['rules'])) ? $project['rules'] : [];
    $placeholders = xabia_search_bar_placeholders_from_rules($rules);
    $chips = xabia_search_bar_chips_from_rules($rules);
    $placeholders_json = wp_json_encode(array_values($placeholders), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($placeholders_json)) {
        $placeholders_json = '[]';
    }

    $scheme = 'light';
    if (class_exists('Xabia_Interface', false)) {
        $settings = Xabia_Interface::get_project_settings($project_id);
        $scheme = (string) ($settings[Xabia_Interface::OPT_COLOR_SCHEME] ?? 'light');
    }
    $scheme_attrs = class_exists('Xabia_Interface', false)
        ? Xabia_Interface::color_scheme_attributes($scheme)
        : '';

    $chat_file = __DIR__ . '/chatbox.php';
    if (is_readable($chat_file)) {
        require_once $chat_file;
    }
    if (function_exists('xabia_enqueue_chatbox_assets_for_project')) {
        xabia_enqueue_chatbox_assets_for_project($project_id, $project);
    }

    $mic_label = class_exists('Xabia_I18n', false)
        ? Xabia_I18n::t('Toca para hablar o mantén pulsado')
        : 'Toca para hablar o mantén pulsado';
    $send_label = class_exists('Xabia_I18n', false)
        ? Xabia_I18n::t('Enviar')
        : 'Enviar';

    ob_start();
    ?>
    <div class="xabia-search-bar" data-project="<?php echo esc_attr($project_id); ?>" data-placeholders="<?php echo esc_attr($placeholders_json); ?>"<?php echo $scheme_attrs; ?>>
        <form class="xabia-search-bar__box" action="#" method="post">
            <span class="material-symbols-outlined xabia-search-bar__spark" aria-hidden="true">auto_awesome</span>
            <span class="xabia-search-bar__field">
                <input type="text" class="xabia-search-bar__input" autocomplete="off" aria-label="<?php echo esc_attr($send_label); ?>">
                <span class="xabia-search-bar__ghost" aria-hidden="true"></span>
            </span>
            <button type="button" class="xabia-search-bar__mic" title="<?php echo esc_attr($mic_label); ?>" aria-label="<?php echo esc_attr($mic_label); ?>">
                <span class="material-symbols-outlined" aria-hidden="true">mic</span>
            </button>
            <button type="submit" class="xabia-search-bar__send" title="<?php echo esc_attr($send_label); ?>" aria-label="<?php echo esc_attr($send_label); ?>">
                <span class="material-symbols-outlined" aria-hidden="true">send</span>
            </button>
        </form>
        <?php if ($chips !== []) : ?>
            <div class="xabia-search-bar__chips" role="group">
                <?php foreach ($chips as $chip) : ?>
                    <button type="button" class="xabia-search-chip" data-query="<?php echo esc_attr($chip['query']); ?>"><?php echo esc_html($chip['label']); ?></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Si la página solo tiene la barra, el chat se pinta al pie, oculto hasta el overlay.
 */
function xabia_search_bar_render_parked_hosts(): void {
    if (!class_exists('Xabia_Interface', false)) {
        return;
    }
    $ids = Xabia_Interface::search_bar_project_ids();
    if ($ids === []) {
        return;
    }
    $chat_file = __DIR__ . '/chatbox.php';
    if (!function_exists('shortcode_xabia_agent_renderer') && is_readable($chat_file)) {
        require_once $chat_file;
    }
    if (!function_exists('shortcode_xabia_agent_renderer')) {
        return;
    }
    foreach ($ids as $project_id) {
        if (Xabia_Interface::has_rendered_chatbox($project_id)) {
            continue;
        }
        if (Xabia_Interface::is_project_paused($project_id)) {
            continue;
        }
        Xabia_Interface::park_next_chatbox();
        echo shortcode_xabia_agent_renderer(['id' => $project_id]);
    }
}

add_action('wp_footer', 'xabia_search_bar_render_parked_hosts', 24);
