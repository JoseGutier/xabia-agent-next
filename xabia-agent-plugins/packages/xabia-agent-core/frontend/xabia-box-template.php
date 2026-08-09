<?php
/**
 * Plantilla Xabia Box: sin cabecera ni pie del tema; solo lienzo + agente.
 *
 * Query recomendadas: ?x_project=slug_agente&ente_id=slug_ente
 *
 * @noinspection HtmlRequiredTitleElement
 */

if (!defined('ABSPATH')) {
    exit;
}

$box_project = isset($_GET['x_project']) ? sanitize_key(wp_unslash($_GET['x_project'])) : '';
if ($box_project === '') {
    $box_project = 'default';
}

$shortcode = sprintf('[xabia_agent id="%s"]', $box_project);
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo esc_html(wp_get_document_title()); ?></title>
    <style id="xabia-box-base">
        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
            overflow: hidden;
            -webkit-text-size-adjust: 100%;
        }
        body.xabia-box-page {
            background: linear-gradient(165deg, #f3f4f8 0%, #e8eaef 42%, #f6f5f9 100%);
            color: #1d1d1f;
        }
        #xabia-full-canvas {
            box-sizing: border-box;
            width: 100%;
            min-height: 100vh;
            min-height: 100dvh;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
        }
        #xabia-full-canvas > .xabia-chatbox {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
    </style>
    <?php wp_head(); ?>
</head>
<body <?php body_class('xabia-box-page'); ?>>
<div id="xabia-full-canvas">
    <?php echo do_shortcode($shortcode); ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
