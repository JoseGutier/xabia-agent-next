<?php
/**
 * Xabia Admin — page-status.php (v2.0)
 * Estado completo del sistema, archivos, caché y consistencia.
 */

if (!defined('ABSPATH')) exit;

function xabia_admin_page_status() {

    // Directorio oficial de trabajo
    $uploads = wp_upload_dir();
    $dir     = trailingslashit($uploads['basedir']) . 'xabia/';

    // Archivos principales v4/v7
    $base_file  = $dir . 'base.json';
    $emb_file   = $dir . 'embeddings.json';
    $cache_file = $dir . 'cache.json';

    // Carga segura
    $base  = file_exists($base_file)  ? json_decode(file_get_contents($base_file),  true) : null;
    $emb   = file_exists($emb_file)   ? json_decode(file_get_contents($emb_file),   true) : null;
    $cache = file_exists($cache_file) ? json_decode(file_get_contents($cache_file), true) : null;

    // Conteos automáticos
    $base_count = $base['count']
        ?? (isset($base['registros']) ? count($base['registros']) : 0);

    $emb_count = $emb['count'] ?? 0;

    $cache_entities = $cache['entities'] ?? '—';
    $cache_updated  = isset($cache['updated'])
        ? date('Y-m-d H:i:s', $cache['updated'])
        : '—';

    $cache_hash_base       = $cache['hash_base']       ?? '—';
    $cache_hash_embeddings = $cache['hash_embeddings'] ?? '—';
?>
    <div class="wrap xabia-admin-page">
        <h1>📊 Estado del sistema — Xabia Agent</h1>
        <p class="description">
            Diagnóstico general del conocimiento, embeddings, caché y consistencia interna.
        </p>

        <hr>

        <!-- ===========================
             ENTORNO
        ============================ -->
        <h2>🖥 Entorno del sistema</h2>

        <table class="widefat striped" style="max-width:600px;">
            <tbody>
                <tr><td><strong>Versión del plugin</strong></td><td><?php echo esc_html(XABIA_PLUGIN_VERSION ?? '—'); ?></td></tr>
                <tr><td><strong>WordPress</strong></td><td><?php echo esc_html(get_bloginfo('version')); ?></td></tr>
                <tr><td><strong>PHP</strong></td><td><?php echo esc_html(PHP_VERSION); ?></td></tr>
                <tr><td><strong>Ruta de trabajo</strong></td><td><code><?php echo esc_html($dir); ?></code></td></tr>
            </tbody>
        </table>

        <hr>

        <!-- ===========================
             ARCHIVOS PRINCIPALES
        ============================ -->
        <h2>📁 Archivos principales</h2>

        <table class="widefat striped" style="max-width:800px;">
            <thead>
                <tr>
                    <th>Archivo</th>
                    <th>Tamaño</th>
                    <th>Última modificación</th>
                    <th>Registros</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>

                <!-- base.json -->
                <tr>
                    <td><strong>base.json</strong></td>
                    <td><?php echo file_exists($base_file) ? size_format(filesize($base_file)) : '—'; ?></td>
                    <td><?php echo file_exists($base_file) ? date('Y-m-d H:i:s', filemtime($base_file)) : '—'; ?></td>
                    <td><?php echo intval($base_count); ?></td>
                    <td>
                        <?php
                        if (!$base) echo '⚠️ No válido';
                        else if ($base_count === 0) echo '⚠️ Vacío';
                        else echo '✅ OK';
                        ?>
                    </td>
                </tr>

                <!-- embeddings.json -->
                <tr>
                    <td><strong>embeddings.json</strong></td>
                    <td><?php echo file_exists($emb_file) ? size_format(filesize($emb_file)) : '—'; ?></td>
                    <td><?php echo file_exists($emb_file) ? date('Y-m-d H:i:s', filemtime($emb_file)) : '—'; ?></td>
                    <td><?php echo intval($emb_count); ?></td>
                    <td>
                        <?php
                        if (!$emb) echo '⚠️ No válido';
                        else if ($emb_count === 0) echo '⚠️ Vacío';
                        else echo '✅ OK';
                        ?>
                    </td>
                </tr>

                <!-- cache.json -->
                <tr>
                    <td><strong>cache.json</strong></td>
                    <td><?php echo file_exists($cache_file) ? size_format(filesize($cache_file)) : '—'; ?></td>
                    <td><?php echo file_exists($cache_file) ? date('Y-m-d H:i:s', filemtime($cache_file)) : '—'; ?></td>
                    <td><?php echo esc_html($cache_entities); ?></td>
                    <td>
                        <?php echo $cache ? '🟦 Activo' : '—'; ?>
                    </td>
                </tr>

            </tbody>
        </table>

        <hr>

        <!-- ===========================
             DETALLES DE CACHÉ
        ============================ -->
        <h2>🧩 Caché del sistema</h2>

        <table class="widefat striped" style="max-width:600px;">
            <tbody>
                <tr><td>Última actualización</td><td><?php echo esc_html($cache_updated); ?></td></tr>
                <tr><td>hash_base</td><td><code><?php echo esc_html($cache_hash_base); ?></code></td></tr>
                <tr><td>hash_embeddings</td><td><code><?php echo esc_html($cache_hash_embeddings); ?></code></td></tr>
                <tr><td>Entidades registradas</td><td><?php echo esc_html($cache_entities); ?></td></tr>
            </tbody>
        </table>

        <hr>

        <p style="opacity:0.6;font-size:12px;">
            Xabia Agent — Monitor del sistema v2.0
        </p>
    </div>
<?php
}