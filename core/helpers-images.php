<?php
/**
 * Xabia — helpers-images.php (v5 FILE SCANNER PRO — ESTABLE)
 *
 * - Resolución real de imágenes en /uploads/YYYY/MM/
 * - Auto-detección de columnas de imágenes (CSV universal)
 * - Soporte de URLs absolutas y rutas parciales
 * - File finder optimizado y 100% estable
 * - Incluye tu card_html EXACTA (sin tocar nada)
 */

if (!defined('ABSPATH')) exit;


/* =====================================================
 * 1) Buscar una imagen REAL dentro de /uploads
 * ===================================================== */
function xabia_find_real_image($file)
{
    if (!$file || !is_string($file)) return '';

    $file = trim($file);

    /* A) Si ya es URL absoluta */
    if (preg_match('#^https?://#i', $file)) {
        return esc_url($file);
    }

    $uploads = wp_upload_dir();
    $basedir = trailingslashit($uploads['basedir']);
    $baseurl = trailingslashit($uploads['baseurl']);

    /* B) Ruta tipo 2024/11/foto.jpg */
    if (preg_match('#^\d{4}/\d{2}/#', $file)) {
        $local = $basedir . $file;
        if (file_exists($local)) {
            return esc_url($baseurl . $file);
        }
    }

    /* C) Ruta completa /wp-content/uploads/... */
    if (strpos($file, '/wp-content/uploads/') !== false) {
        $local = ABSPATH . ltrim($file, '/');
        if (file_exists($local)) {
            return esc_url(home_url($file));
        }
    }

    /* D) Nombre suelto → búsqueda PRO */
    $filename = basename($file);


/* PRIORIDAD: carpeta xabia/imagenes */
$prio = glob($basedir . 'xabia/imagenes/' . $filename, GLOB_NOSORT);
if (!empty($prio)) {
    $real_local = $prio[0];
    $rel = str_replace($basedir, '', $real_local);
    return esc_url($baseurl . $rel);
}

    // Buscar en uploads/*/
    $paths = glob($basedir . '*/' . $filename, GLOB_NOSORT);

    // Buscar también en uploads/*/*/
    if (empty($paths)) {
        $paths = glob($basedir . '*/*/' . $filename, GLOB_NOSORT);
    }

    if (!empty($paths)) {

        // Elegir archivo más reciente
        usort($paths, function($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        $real_local = $paths[0];
        $rel        = str_replace($basedir, '', $real_local);

        return esc_url($baseurl . $rel);
    }

    /* E) Fallback: construir URL básica */
    return esc_url($baseurl . $filename);
}


/* =====================================================
 * 2) Obtener TODAS las imágenes reales de una empresa
 * ===================================================== */
function xabia_company_images(array $company): array
{
    $raw = $company['raw'] ?? [];
    $out = [];

    // Campos más habituales
    $keys = [
        'empresa_logo',
        'empresa_img_01', 'empresa_img_02', 'empresa_img_03',
        'empresa_img_04', 'empresa_img_05', 'empresa_img_06',
        'empresa_img_07', 'empresa_img_08', 'empresa_img_09', 'empresa_img_10',
        '@empresa_logo', '@empresa_img_01', '@empresa_img_02',
        '@empresa_img_03', '@empresa_img_04',
    ];

    foreach ($keys as $k) {
        if (!empty($raw[$k])) {
            $u = xabia_find_real_image($raw[$k]);
            if ($u) $out[] = $u;
        }
    }

    // Auto-detección de cualquier columna con extensión de imagen
    foreach ($raw as $v) {
        if (!is_string($v)) continue;
        $v = trim($v);
        if ($v === '') continue;

        if (preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $v)) {
            $u = xabia_find_real_image($v);
            if ($u) $out[] = $u;
        }
    }

    return array_values(array_unique($out));
}


/* =====================================================
 * 3) Tarjeta HTML con galería (TU BLOQUE EXACTO)
 * ===================================================== */
function xabia_company_card_html(array $company): string
{
    $name  = trim((string)($company['empresa'] ?? 'Empresa'));
    $raw   = $company['raw'] ?? [];

    /* Imágenes reales */
    $images = xabia_company_images($company);
    $main   = $images[0] ?? '';

    /* Descripción */
    $desc = trim((string)($company['text'] ?? ''));
    if (!$desc) {
        for ($i=1; $i<=7; $i++) {
            $tmp = trim($raw[sprintf('descripcion_propuesta_%02d', $i)] ?? '');
            if ($tmp) { $desc = $tmp; break; }
        }
    }
    if ($desc) {
        $desc = wp_strip_all_tags($desc);
        if (strlen($desc) > 240) $desc = substr($desc, 0, 240).'…';
    }

    /* Categorías */
    $cats_html = '';
    if (!empty($company['categoria'])) {
        $cats = array_map('trim', explode('·', $company['categoria']));
        foreach ($cats as $c) {
            if ($c !== '') {
                $cats_html .= '<span class="xabia-cat-pill">'.esc_html($c).'</span>';
            }
        }
    }

    /* Contacto */
    $tel = $raw['empresa_tel']
        ?? $raw['telefono']
        ?? $raw['telefono1']
        ?? '';

    $web = $raw['empresa_web']
        ?? $raw['url_empresa']
        ?? '';

    ob_start(); ?>

    <div class="xabia-card-wrapper">

        <?php if ($main): ?>
        <div class="xabia-card-img">
            <img src="<?php echo esc_url($main); ?>"
                 alt="<?php echo esc_attr($name); ?>" loading="lazy">
        </div>
        <?php endif; ?>

        <div class="xabia-card-body">

            <h4 class="xabia-card-title"><?php echo esc_html($name); ?></h4>

            <?php if ($cats_html): ?>
                <div class="xabia-card-meta"><?php echo $cats_html; ?></div>
            <?php endif; ?>

            <?php if ($desc): ?>
                <p class="xabia-card-desc"><?php echo esc_html($desc); ?></p>
            <?php endif; ?>
<?php if (!empty($images)): ?>
                <div class="xabia-gallery">
                    <div class="xabia-gallery-track">
                        <?php foreach ($images as $img): ?>
                            <button type="button"
                                    class="xabia-gallery-item"
                                    data-img="<?php echo esc_url($img); ?>">
                                <img src="<?php echo esc_url($img); ?>" alt="" loading="lazy">
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <?php
    return ob_get_clean();
}