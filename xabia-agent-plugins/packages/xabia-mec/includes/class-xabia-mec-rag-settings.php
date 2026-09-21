<?php
/**
 * UI de referencia de campos MEC (pestaña MEC del agente).
 *
 * @package Xabia_MEC
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_init', 'xabia_mec_rag_register_settings');
add_action('admin_init', 'xabia_mec_rag_maybe_save_from_agent_form', 5);

function xabia_mec_rag_register_settings(): void {
    register_setting(
        'xabia_mec_rag_mapping_group',
        XABIA_MEC_RAG_MAPPING_OPTION,
        [
            'type'              => 'array',
            'sanitize_callback' => 'xabia_mec_rag_sanitize_settings_option',
            'default'           => xabia_mec_rag_default_settings(),
        ]
    );
}

/**
 * @param mixed $input
 * @return array{municipality:string,venue:string}
 */
function xabia_mec_rag_sanitize_settings_option($input): array {
    if (!is_array($input)) {
        return xabia_mec_rag_default_settings();
    }
    xabia_mec_rag_save_settings($input);

    return xabia_mec_rag_get_settings();
}

/**
 * Persiste el mapeo RAG cuando el usuario pulsa «Guardar agente» (retrocompatibilidad).
 */
function xabia_mec_rag_maybe_save_from_agent_form(): void {
    if (!isset($_POST['xabia_action']) || (string) wp_unslash($_POST['xabia_action']) !== 'save_project') {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    if (!isset($_POST[XABIA_MEC_RAG_MAPPING_OPTION]) || !is_array($_POST[XABIA_MEC_RAG_MAPPING_OPTION])) {
        return;
    }

    xabia_mec_rag_save_settings(wp_unslash($_POST[XABIA_MEC_RAG_MAPPING_OPTION]));
}

/**
 * Catálogo de campos descubiertos (pestaña MEC). El mapeo activo está en General → Mapeo de atributos.
 *
 * @param string               $edit_id
 * @param array<string, mixed> $agent_data
 */
function xabia_mec_rag_render_settings_panel(string $edit_id = '', array $agent_data = []): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    $synopsis = Xabia_MEC_Admin::get_source_synopsis();
    $tag_method = xabia_mec_rag_get_mec_tag_method();
    $mapped_cols = Xabia_MEC_Admin::mapped_attribute_columns($agent_data);
    $mapped_lookup = array_fill_keys($mapped_cols, true);

    $resolved_muni = Xabia_MEC_Admin::resolve_source(XABIA_MEC_SLOT_MUNICIPALITY);
    $resolved_venue = Xabia_MEC_Admin::resolve_source(XABIA_MEC_SLOT_VENUE);

    $standard_columns = [];
    if (class_exists('Xabia_MEC_Connector', false)) {
        foreach (Xabia_MEC_Connector::default_mapping_fields() as $field) {
            if (!is_array($field) || empty($field['csv_col'])) {
                continue;
            }
            $col = (string) $field['csv_col'];
            $standard_columns[] = [
                'mapping_column' => $col,
                'label'          => (string) ($field['label'] ?? $col),
                'synopsis'       => (string) ($field['instruction'] ?? ''),
                'in_mapping'     => isset($mapped_lookup[$col]),
            ];
        }
    }

    $discovered_total = 0;
    foreach ($synopsis as $items) {
        $discovered_total += is_array($items) ? count($items) : 0;
    }
    ?>
    <div class="xabia-mec-rag-mapping-card xabia-fed-mec-card" style="margin-top:18px;">
        <h2><?php echo esc_html__('Campos MEC descubiertos', 'xabia-intelligence'); ?></h2>
        <p class="description">
            <?php echo esc_html__('El mapeo de columnas para el agente se configura en la pestaña General → «Mapeo de atributos» (o en esta misma pestaña si el panel se ha reubicado). Aquí tienes el catálogo completo detectado en este sitio por si algún campo no aparece abajo.', 'xabia-intelligence'); ?>
        </p>
        <p class="description" style="margin-top:8px;">
            <?php echo esc_html__('Cabeceras semánticas en chunks (automáticas, sin configuración manual):', 'xabia-intelligence'); ?>
            <code>[Ubicación: …]</code>
            <?php echo esc_html(sprintf(__('← %s', 'xabia-intelligence'), Xabia_MEC_Admin::source_label($resolved_muni))); ?>;
            <code>[Recinto: …]</code>
            <?php echo esc_html(sprintf(__('← %s', 'xabia-intelligence'), Xabia_MEC_Admin::source_label($resolved_venue))); ?>.
            <?php echo esc_html__('tag_method MEC:', 'xabia-intelligence'); ?>
            <code><?php echo esc_html($tag_method); ?></code>
        </p>

        <?php if ($standard_columns !== []) : ?>
            <details class="xabia-mec-rag-synopsis" open style="margin:14px 0;border:1px solid #dcdcde;border-radius:8px;padding:10px 14px;background:#fff;">
                <summary style="cursor:pointer;font-weight:600;">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d: number of standard connector columns */
                            __('Columnas del conector MEC (%d)', 'xabia-intelligence'),
                            count($standard_columns)
                        )
                    );
                    ?>
                </summary>
                <p class="description" style="margin:10px 0;">
                    <?php echo esc_html__('Aparecen al pulsar «Conectar y mapear». La columna «En mapeo» indica si ya está en tu mapeo de atributos.', 'xabia-intelligence'); ?>
                </p>
                <table class="widefat striped" style="margin-bottom:4px;">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Columna', 'xabia-intelligence'); ?></th>
                            <th><?php echo esc_html__('Etiqueta', 'xabia-intelligence'); ?></th>
                            <th><?php echo esc_html__('En mapeo', 'xabia-intelligence'); ?></th>
                            <th><?php echo esc_html__('Notas', 'xabia-intelligence'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($standard_columns as $row) : ?>
                            <tr>
                                <td><code><?php echo esc_html((string) $row['mapping_column']); ?></code></td>
                                <td><?php echo esc_html((string) $row['label']); ?></td>
                                <td><?php echo !empty($row['in_mapping']) ? '✓' : '—'; ?></td>
                                <td><?php echo esc_html((string) $row['synopsis']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php endif; ?>

        <details class="xabia-mec-rag-synopsis" <?php echo $discovered_total > 0 ? 'open' : ''; ?> style="margin:14px 0;border:1px solid #dcdcde;border-radius:8px;padding:10px 14px;background:#fff;">
            <summary style="cursor:pointer;font-weight:600;">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %d: number of discovered sources */
                        __('Fuentes adicionales descubiertas (%d)', 'xabia-intelligence'),
                        $discovered_total
                    )
                );
                ?>
            </summary>
            <?php if ($discovered_total === 0) : ?>
                <p class="description" style="margin:10px 0;">
                    <?php echo esc_html__('No se han detectado taxonomías, campos MEC nativos, ACF ni meta extra en este sitio.', 'xabia-intelligence'); ?>
                </p>
            <?php else : ?>
                <p class="description" style="margin:10px 0;">
                    <?php echo esc_html__('Usa la columna «Columna mapeo» para añadir manualmente una fila en el mapeo de atributos si falta.', 'xabia-intelligence'); ?>
                </p>
            <?php endif; ?>
            <?php
            $type_labels = [
                'taxonomy'  => __('Taxonomías', 'xabia-intelligence'),
                'mec_field' => __('Campos nativos MEC', 'xabia-intelligence'),
                'acf'       => __('Campos ACF', 'xabia-intelligence'),
                'meta'      => __('Meta del evento', 'xabia-intelligence'),
            ];
            foreach ($type_labels as $type => $heading) :
                $items = $synopsis[$type] ?? [];
                if ($items === []) {
                    continue;
                }
                ?>
                <h4 style="margin:14px 0 8px;"><?php echo esc_html($heading); ?></h4>
                <table class="widefat striped" style="margin-bottom:12px;">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Clave fuente', 'xabia-intelligence'); ?></th>
                            <th><?php echo esc_html__('Columna mapeo', 'xabia-intelligence'); ?></th>
                            <th><?php echo esc_html__('Etiqueta', 'xabia-intelligence'); ?></th>
                            <th><?php echo esc_html__('En mapeo', 'xabia-intelligence'); ?></th>
                            <th><?php echo esc_html__('Descripción', 'xabia-intelligence'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item) : ?>
                            <?php
                            $map_col = (string) ($item['mapping_column'] ?? '');
                            $in_map = $map_col !== '' && isset($mapped_lookup[$map_col]);
                            ?>
                            <tr>
                                <td><code><?php echo esc_html((string) ($item['source_key'] ?? ((string) ($item['type'] ?? '') . ':' . (string) ($item['key'] ?? '')))); ?></code></td>
                                <td><?php echo $map_col !== '' ? '<code>' . esc_html($map_col) . '</code>' : '—'; ?></td>
                                <td><?php echo esc_html((string) ($item['label'] ?? '')); ?></td>
                                <td><?php echo $in_map ? '✓' : '—'; ?></td>
                                <td><?php echo esc_html((string) ($item['synopsis'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        </details>

        <p class="description" style="margin-top:8px;">
            <?php echo esc_html__('Tras ajustar el mapeo de atributos, guarda el agente y ejecuta «Sincronizar datos».', 'xabia-intelligence'); ?>
        </p>
    </div>
    <?php
}
