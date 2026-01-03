<?php
/**
 * Xabia Admin — page-embeddings.php (v4.2 PRO)
 * Panel completo: regeneración, progreso, logs y estado.
 */

if (!defined('ABSPATH')) exit;

/* ============================================================
 * PÁGINA ADMIN — EMBEDDINGS
 * ============================================================ */
function xabia_admin_page_embeddings() {

    $emb_file  = xabia_path('embeddings.json');
    $meta_file = xabia_path('embeddings.meta.json');

    $emb  = file_exists($emb_file)  ? json_decode(file_get_contents($emb_file),  true) : null;
    $meta = file_exists($meta_file) ? json_decode(file_get_contents($meta_file), true) : null;

    $count = $emb['count'] ?? 0;
    $date  = isset($emb['created']) ? date('Y-m-d H:i:s', $emb['created']) : '—';
?>
    <div class="wrap xabia-admin-page">
        <h1>🧠 Embeddings — Modelo semántico</h1>
        <p class="description">Regenera el vectorizado de todas las empresas y entidades.</p>

        <hr>

        <h2>📊 Estado actual</h2>

        <table class="widefat striped" style="max-width:680px;">
            <tbody>
                <tr><td><strong>Modelo</strong></td><td>text-embedding-3-large</td></tr>
                <tr><td><strong>Vectores almacenados</strong></td><td><?php echo intval($count); ?></td></tr>
                <tr><td><strong>Última actualización</strong></td><td><?php echo esc_html($date); ?></td></tr>
                <tr><td><strong>Archivo</strong></td><td><code><?php echo basename($emb_file); ?></code></td></tr>
            </tbody>
        </table>

        <hr>

        <h2>⚙️ Reentrenar embeddings</h2>

        <button id="xabia-emb-btn" class="button button-primary button-hero">
            🧠 Regenerar embeddings
        </button>

        <button id="xabia-emb-log-toggle" class="button" style="margin-left:10px;">
            📜 Ver historial
        </button>

        <!-- Progreso -->
        <div id="xabia-emb-progress" style="display:none;margin-top:12px;width:100%;max-width:380px;background:#eee;border-radius:5px;">
            <div id="xabia-emb-bar" style="height:10px;width:0;background:#0073aa;transition:width .3s;"></div>
        </div>

        <div id="xabia-emb-status" style="margin-top:12px;font-family:monospace;"></div>

        <?php echo xabia_admin_js_embeddings(); ?>

        <hr>
        <p style="opacity:.6;font-size:12px;">Xabia Agent v4 — Sistema de embeddings.</p>
    </div>
<?php
}

/* ============================================================
 * JS COMPLETO — ESTA ES LA FUNCIÓN QUE FALTABA
 * ============================================================ */
function xabia_admin_js_embeddings() {
    ob_start();
?>
<script>
(function(){

    const btn       = document.getElementById('xabia-emb-btn');
    const barWrap   = document.getElementById('xabia-emb-progress');
    const bar       = document.getElementById('xabia-emb-bar');
    const statusBox = document.getElementById('xabia-emb-status');

    const toast      = document.getElementById('xabia-emb-toast');
    const toastText  = document.getElementById('xabia-emb-toast-text');
    const toastIcon  = document.getElementById('xabia-emb-toast-icon');
    const toastClose = document.getElementById('xabia-emb-toast-close');

    const logPanel  = document.getElementById('xabia-emb-log-panel');
    const logList   = document.getElementById('xabia-emb-log-list');
    const logToggle = document.getElementById('xabia-emb-log-toggle');

    /* ====== LOG LOCAL ====== */
    let history = JSON.parse(localStorage.getItem('xabia_emb_log') || '[]');
    renderLog();

    logToggle.addEventListener('click', () => {
        logPanel.style.right = (logPanel.style.right === '0px') ? '-380px' : '0px';
    });

    function addToHistory(entry){
        entry.time = new Date().toLocaleString();
        history.unshift(entry);
        history = history.slice(0, 20);
        localStorage.setItem('xabia_emb_log', JSON.stringify(history));
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
                <span style="color:${e.success ? 'green' : 'red'};">
                    ${e.success ? '✅' : '❌'} ${e.message}
                </span>
            </div>
        `).join('');
    }

    function showToast(msg, ok=true){
        const toast = document.getElementById('xabia-emb-toast');
        const toastText = document.getElementById('xabia-emb-toast-text');
        const toastIcon = document.getElementById('xabia-emb-toast-icon');

        toast.style.background = ok ? '#46b450' : '#dc3232';
        toastText.textContent  = msg;
        toastIcon.textContent  = ok ? '✔️' : '❌';

        toast.style.display = 'block';
        setTimeout(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
        }, 20);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(20px)';
            setTimeout(() => toast.style.display = 'none', 300);
        }, 3000);
    }

    /* ====== ACCIÓN PRINCIPAL ====== */
    btn.addEventListener('click', async () => {

        btn.disabled = true;
        btn.textContent = '⏳ Procesando…';

        bar.style.width = '0';
        barWrap.style.display = 'block';
        statusBox.innerHTML = '<em>Generando embeddings…</em>';

        let anim = setInterval(() => {
            let w = parseFloat(bar.style.width) || 0;
            w = Math.min(w + Math.random() * 10, 95);
            bar.style.width = w + '%';
        }, 300);

        try {
            const response = await fetch(
                '<?php echo esc_url(rest_url("xabi/v1/train-embeddings")); ?>',
                {
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': '<?php echo wp_create_nonce("wp_rest"); ?>'
                    }
                }
            );

            clearInterval(anim);
            bar.style.width = "100%";

            const json = await response.json();

            if (json.status === 'ok') {
                const msg = `Embeddings regenerados (${json.count} vectores)`;
                statusBox.innerHTML = `<span style="color:green;">${msg}</span>`;
                showToast(msg, true);
                addToHistory({success:true, message:msg});
            } else {
                const msg = json.message || 'Error desconocido';
                statusBox.innerHTML = `<span style="color:red;">❌ ${msg}</span>`;
                showToast(msg, false);
                addToHistory({success:false, message:msg});
            }

        } catch (e) {
            const msg = 'Error de conexión: ' + e.message;
            statusBox.innerHTML = `<span style="color:red;">❌ ${msg}</span>`;
            showToast(msg, false);
            addToHistory({success:false, message:msg});
            clearInterval(anim);
        }

        btn.disabled = false;
        btn.textContent = '🧠 Regenerar embeddings';

        setTimeout(() => {
            barWrap.style.display = 'none';
        }, 1200);
    });

})();
</script>

<!-- Toast -->
<div id="xabia-emb-toast" style="
    display:none;opacity:0;position:fixed;bottom:20px;right:20px;
    padding:12px 18px;border-radius:6px;color:#fff;font-size:14px;
    background:#000;box-shadow:0 4px 12px rgba(0,0,0,.25);z-index:9999;
    transition:opacity .4s ease, transform .4s ease;
    transform:translateY(20px);
">
    <span id="xabia-emb-toast-icon"></span>
    <span id="xabia-emb-toast-text"></span>
</div>

<!-- Panel Historial -->
<div id="xabia-emb-log-panel" style="
    position:fixed;top:0;right:-380px;width:360px;height:100%;z-index:9998;
    background:#f6f7f7;border-left:1px solid #ddd;overflow-y:auto;
    transition:right .4s ease;padding:20px;
">
    <h2>📜 Historial</h2>
    <div id="xabia-emb-log-list"></div>
</div>

<?php
    return ob_get_clean();
}