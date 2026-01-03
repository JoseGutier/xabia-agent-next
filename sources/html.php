<?php

if (!defined('ABSPATH')) exit;

/**
 * Render para tarjetas o estructuras HTML de Xabia en modo FULL
 */

function xabia_render_full_card($e) {

    $empresa = esc_html($e['empresa'] ?? '');
    [$tel, $web] = xabia_extract_contact($e);
    $desc = trim($e['text'] ?? '');

    if ($desc && mb_strlen($desc) > 240) {
        $desc = mb_substr($desc, 0, 240) . '…';
    }

    ob_start(); ?>

    <div class="xabia-card-full">
        <h2 class="empresa-title"><?php echo $empresa; ?></h2>

        <div class="empresa-contact">
            <?php if ($tel): ?><div class="c-item">📞 <?php echo $tel; ?></div><?php endif; ?>
            <?php if ($web): ?><div class="c-item">🌐 <a href="<?php echo $web; ?>" target="_blank"><?php echo $web; ?></a></div><?php endif; ?>
        </div>

        <?php if ($desc): ?>
            <p class="empresa-desc"><?php echo esc_html($desc); ?></p>
        <?php endif; ?>

        <p class="empresa-follow">¿Quieres ver sus actividades o más información?</p>
    </div>

    <?php return ob_get_clean();
}