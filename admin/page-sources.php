<?php
/**
 * Xabia Admin — page-sources.php (v4.2)
 * Panel avanzado: estado, fuentes, embeddings y reentrenamiento PRO.
 */

if (!defined('ABSPATH')) exit;

function xabia_admin_page_sources() {

    /* ============================================
     * 1) Localizar rutas oficiales v4
     * ============================================ */
    if (function_exists('xabia_path')) {
        $base_file = xabia_path('base.json');
        $emb_file  = xabia_path('embeddings.json'); // v7 usa este nombre SIEMPRE
    } else {
        $uploads   = wp_upload_dir();
        $dir       = trailingslashit($uploads['basedir']) . 'xabia/';
        $base_file = $dir . 'base.json';
        $emb_file  = $dir . 'embeddings.json';
    }

    /* ============================================
     * 2) Cargar JSON si existen
     * ============================================ */
    $base_info = file_exists($base_file)
        ? json_decode(file_get_contents($base_file), true)
        : null;

    $emb_info = file_exists($emb_file)
        ? json_decode(file_get_contents($emb_file), true)
        : null;

    /* Para compatibilidad: base.json puede usar "count" o "total" */
    $count_base = $base_info['count']
        ?? $base_info['total']
        ?? count($base_info['registros'] ?? []);

    /* embeddings v7.2 usa: {count, created} */
    $count_emb = $emb_info['count'] ?? 0;
    $date_emb  = isset($emb_info['created'])
        ? date('Y-m-d H:i:s', $emb_info['created'])
        : '—';
?>
    <div class="wrap xabia-admin-page">
        <h1>🧠 Xabia — Fuentes y Conocimiento</h1>
        <p class="description">Gestión avanzada de CSV, conocimiento unificado y embeddings PRO.</p>

        <hr>

        <!-- =========================
             ESTADO DE ARCHIVOS
        ========================== -->
        <h2>📊 Estado actual</h2>
        <table class="widefat striped" style="max-width:780px;">
            <thead>
                <tr>
                    <th>Archivo</th>
                    <th>Última actualización</th>
                    <th>Registros</th>
                    <th>Ruta</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>base.json</strong></td>
                    <td><?php echo esc_html($base_info['fecha'] ?? '—'); ?></td>
                    <td><?php echo esc_html($count_base); ?></td>
                    <td><code><?php echo esc_html(basename($base_file)); ?></code></td>
                </tr>
                <tr>
                    <td><strong>embeddings.json</strong></td>
                    <td><?php echo esc_html($date_emb); ?></td>
                    <td><?php echo esc_html($count_emb); ?></td>
                    <td><code><?php echo esc_html(basename($emb_file)); ?></code></td>
                </tr>
            </tbody>
        </table>

        <hr>

        <!-- =========================
             ACCIONES
        ========================== -->

        <h2>⚙️ Acciones</h2>
        <?php if (current_user_can('manage_options')): ?>
            <p>Pulsa para regenerar los embeddings con el motor PRO.</p>

            <button id="xabia-train-btn" class="button button-primary button-hero">
                🧠 Actualizar conocimiento
            </button>

            <button id="xabia-log-toggle" class="button" style="margin-left:10px;">
                📜 Ver historial
            </button>

            <!-- Barra -->
            <div id="xabia-train-progress" style="display:none;margin-top:10px;width:100%;max-width:400px;background:#eee;border-radius:5px;overflow:hidden;">
                <div id="xabia-train-bar" style="height:10px;width:0;background:#0073aa;transition:width 0.3s;"></div>
            </div>

            <div id="xabia-train-status" style="margin-top:12px;font-family:monospace;"></div>

            <!-- Toast -->
            <div id="xabia-toast" style="
                display:none;opacity:0;position:fixed;bottom:20px;right:20px;
                padding:12px 18px;border-radius:6px;color:#fff;font-size:14px;
                box-shadow:0 4px 12px rgba(0,0,0,.25);z-index:9999;
                transition:opacity .4s ease, transform .4s ease;
                transform:translateY(20px);
            ">
                <span id="xabia-toast-icon"></span>
                &nbsp;<span id="xabia-toast-text"></span>
                <button id="xabia-toast-close" style="
                    background:none;border:none;color:#fff;font-size:18px;float:right;margin-left:10px;
                ">×</button>
            </div>

            <!-- Log lateral -->
            <div id="xabia-log-panel" style="
                position:fixed;top:0;right:-380px;width:360px;height:100%;z-index:9998;
                background:#f6f7f7;border-left:1px solid #ddd;overflow-y:auto;
                transition:right .4s ease;padding:20px;
            ">
                <h2>📜 Historial</h2>
                <div id="xabia-log-list"></div>
            </div>

            <?php echo xabia_admin_js_train(); ?>

        <?php else: ?>
            <p><em>No tienes permisos para reentrenar embeddings.</em></p>
        <?php endif; ?>

        <hr>

        <!-- =========================
             FUENTES ACTIVAS
        ========================== -->
        <h2>📚 Fuentes detectadas</h2>

        <?php
        $sources = function_exists('xabia_get_registered_sources')
            ? xabia_get_registered_sources()
            : [];

        if (empty($sources)) {
            echo "<p><em>No se han encontrado fuentes. Sube CSV a /uploads/xabia/.</em></p>";
        } else {
            echo "<ul style='columns:2;list-style:square;margin-left:20px;'>";

            foreach ($sources as $src) {

                $label  = esc_html($src['label'] ?? $src['id']);
                $type   = esc_html($src['type'] ?? 'csv');

                // Determinar path correcto (manager v3.6)
                $path = $src['path']
                    ?? ($src['options']['path'] ?? '-');

                $file = esc_html($path);

                $entity = esc_html($src['entity']
                    ?? ($src['options']['entity'] ?? 'empresa'));

                $state = !empty($src['active']) ? '✅ Activa' : '⚠️ Inactiva';

                echo "<li><strong>{$label}</strong><br>
                        <code>{$file}</code> &nbsp;
                        <em>({$type} → {$entity})</em><br>{$state}
                      </li>";
            }

            echo "</ul>";
        }
        ?>

        <hr>

        <p style="opacity:.6;font-size:12px;">Xabia Agent v4 — Sistema de embeddings y conocimiento local.</p>
    </div>

<?php
}

/* ============================================================
 * JS dinámico separado (más limpio)
 * ============================================================ */
function xabia_admin_js_train() {
    ob_start();
?>
<script>
(function(){

    const btn        = document.getElementById('xabia-train-btn');
    const statusBox  = document.getElementById('xabia-train-status');
    const barWrap    = document.getElementById('xabia-train-progress');
    const bar        = document.getElementById('xabia-train-bar');

    const toast      = document.getElementById('xabia-toast');
    const toastText  = document.getElementById('xabia-toast-text');
    const toastIcon  = document.getElementById('xabia-toast-icon');
    const toastClose = document.getElementById('xabia-toast-close');

    const logPanel   = document.getElementById('xabia-log-panel');
    const logList    = document.getElementById('xabia-log-list');
    const logToggle  = document.getElementById('xabia-log-toggle');

    let history = JSON.parse(localStorage.getItem('xabia_train_log') || '[]');
    renderLog();

    logToggle.addEventListener('click', () => {
        logPanel.style.right = logPanel.style.right === '0px' ? '-380px' : '0px';
    });

    toastClose.addEventListener('click', hideToast);

    function addToHistory(entry){
        entry.time = new Date().toLocaleString();
        history.unshift(entry);
        history = history.slice(0, 12);
        localStorage.setItem('xabia_train_log', JSON.stringify(history));
        renderLog();
    }

    function renderLog(){
        if (!history.length){
            logList.innerHTML = '<em>Aún no hay operaciones.</em>';
            return;
        }
        logList.innerHTML = history.map(e => `
            <div style="margin-bottom:10px;border-bottom:1px solid #ddd;padding-bottom:8px;">
                <strong>${e.time}</strong><br>
                <span style="color:${e.success?'green':'red'};">
                    ${e.success?'✅':'❌'} ${e.message}
                </span>
            </div>
        `).join('');
    }

    function showToast(msg, ok=true){
        toast.style.background = ok ? '#46b450' : '#dc3232';
        toastText.textContent  = msg;
        toastIcon.innerHTML    = ok ? '✔️' : '❌';
        toast.style.display    = 'block';
        setTimeout(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
        }, 20);
        setTimeout(hideToast, 4000);
    }

    function hideToast(){
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(20px)';
        setTimeout(() => toast.style.display = 'none', 400);
    }

    btn.addEventListener('click', async() => {

        btn.disabled = true;
        btn.textContent = '⏳ Procesando...';
        bar.style.width = '0';
        barWrap.style.display = 'block';
        statusBox.innerHTML = '<em>Procesando embeddings...</em>';

        let anim = setInterval(() => {
            let w = parseFloat(bar.style.width) || 0;
            w = Math.min(w + Math.random()*15, 95);
            bar.style.width = w + '%';
        }, 400);

        try {
            const res = await fetch('<?php echo esc_url(rest_url('xabi/v1/train')); ?>', {
                method: 'POST',
                headers: { 'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>' }
            });

            clearInterval(anim);
            bar.style.width = '100%';

            const json = await res.json();

            if (json.ok) {
                const msg = `Embeddings actualizados (${json.embedded ?? json.count ?? 0} vectores)`;
                statusBox.innerHTML = '<span style="color:green;">'+msg+'</span>';
                showToast(msg, true);
                addToHistory({success:true, message:msg});
            } else {
                const err = json.error || 'Error desconocido';
                statusBox.innerHTML = '<span style="color:red;">❌ '+err+'</span>';
                showToast(err, false);
                addToHistory({success:false, message:err});
            }

        } catch (e) {
            const msg = 'Error en la conexión: ' + e.message;
            statusBox.innerHTML = '<span style="color:red;">❌ '+msg+'</span>';
            showToast(msg, false);
            addToHistory({success:false, message:msg});
            clearInterval(anim);

        } finally {
            btn.disabled = false;
            btn.textContent = '🧠 Actualizar conocimiento';
            setTimeout(()=>{ barWrap.style.display='none'; }, 2000);
        }
    });

})();
</script>
<?php
    return ob_get_clean();
}