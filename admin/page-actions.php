<?php
/**
 * Xabia — page-actions.php (v4.2 PRO)
 * Panel para visualizar y probar las acciones registradas.
 */

if (!defined('ABSPATH')) exit;

function xabia_admin_page_actions() {

    // Obtener acciones registradas
    $actions = function_exists('xabia_get_actions')
        ? xabia_get_actions()
        : [];

    ?>
    <div class="wrap xabia-admin-page">
        <h1>⚡ Acciones de Xabia</h1>
        <p class="description">
            Estas son las acciones disponibles en el motor agentic. 
            Cada una puede ser invocada por el intérprete según la intención del usuario.
        </p>

        <hr>

        <?php if (empty($actions)): ?>
            <p><em>No se han encontrado acciones registradas.</em></p>

        <?php else: ?>

            <table class="widefat striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <th>Acción</th>
                        <th>Handler</th>
                        <th>Tipo</th>
                        <th style="width:120px;">Test</th>
                    </tr>
                </thead>
                <tbody>

                <?php foreach ($actions as $id => $a): ?>
                    <tr>
                        <td><strong><?php echo esc_html($id); ?></strong><br>
                            <span style="opacity:.7;"><?php echo esc_html($a['label'] ?? ''); ?></span>
                        </td>

                        <td><code><?php echo esc_html($a['handler']); ?></code></td>

                        <td>
                            <?php
                                $t = $a['type'] ?? 'otros';
                                $color = $t === 'frontend' ? '#0073aa' : '#46b450';
                            ?>
                            <span style="color:<?php echo $color; ?>;">
                                <?php echo ucfirst($t); ?>
                            </span>
                        </td>

                        <!-- Test directo -->
                        <td>
                            <button class="button button-small xabia-test-action"
                                data-action="<?php echo esc_attr($id); ?>">
                                Probar
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>

                </tbody>
            </table>

        <?php endif; ?>

        <hr>
        <p style="opacity:.6;font-size:12px;">Xabia Agent v4 — Gestión de acciones.</p>
    </div>

    <!-- ==================
         MODAL DE TEST
    =================== -->
    <div id="xabia-test-modal" style="
        display:none;position:fixed;top:0;left:0;width:100%;height:100%;
        background:rgba(0,0,0,.35);z-index:99999;align-items:center;justify-content:center;
    ">
        <div style="
            background:#fff;padding:20px;border-radius:8px;max-width:600px;width:90%;
            box-shadow:0 5px 25px rgba(0,0,0,.3);
        ">
            <h2>Resultado de la acción</h2>
            <pre id="xabia-test-output" style="
                background:#f7f7f7;padding:12px;border:1px solid #ddd;
                max-height:350px;overflow:auto;font-size:12px;
            "></pre>

            <button id="xabia-test-close" class="button">Cerrar</button>
        </div>
    </div>

    <?php 
        // Aquí se inyecta el JS limpio
        echo xabia_admin_js_actions();
    ?>
<?php
} // ← FIN DE LA FUNCIÓN PRINCIPAL


/**
 * ============================================================
 * JS para testear acciones — separado para evitar errores
 * ============================================================
 */
function xabia_admin_js_actions() {
    ob_start();
?>
<script>
(function(){

    const modal   = document.getElementById('xabia-test-modal');
    const output  = document.getElementById('xabia-test-output');
    const close   = document.getElementById('xabia-test-close');
    const buttons = document.querySelectorAll('.xabia-test-action');

    close.addEventListener('click', () => modal.style.display = 'none');

    buttons.forEach(btn => {
        btn.addEventListener('click', async () => {

            const action = btn.dataset.action;

            // parámetros de ejemplo para test
    const params = {
    empresa: "Marmitako Sailing",
    test: false
    };

            output.textContent = '⏳ Ejecutando acción...';
            modal.style.display = 'flex';

            try {
                const res = await fetch(
                    '<?php echo esc_url(rest_url("xabi/v1/action")); ?>',
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': '<?php echo wp_create_nonce("wp_rest"); ?>'
                        },
                        body: JSON.stringify({ action, params })
                    }
                );

                const json = await res.json();
                output.textContent = JSON.stringify(json, null, 2);

            } catch (err) {
                output.textContent = "❌ Error: " + err.message;
            }
        });
    });

})();
</script>
<?php
    return ob_get_clean();
}