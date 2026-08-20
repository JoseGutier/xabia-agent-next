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

$box_body_class = 'xabia-box-page';
$box_bg_css = 'linear-gradient(165deg, #f3f4f8 0%, #e8eaef 42%, #f6f5f9 100%)';
if (class_exists('Xabia_Interface', false)) {
    $iface = Xabia_Interface::get_project_settings($box_project);
    $pres = (string) ($iface[Xabia_Interface::OPT_PRESENTATION_MODE] ?? 'web_adaptive');
    if (Xabia_Interface::is_transparent_presentation_mode($pres)) {
        $box_body_class .= ' xabia-box-transparent';
        $box_bg_css = 'transparent';
    } elseif (Xabia_Interface::is_kiosk_presentation_mode($pres)) {
        $box_body_class .= ' xabia-box-kiosk';
        $box_bg_css = '#ffffff';
    }
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
            background: <?php echo esc_html($box_bg_css); ?>;
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
            background: inherit;
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
<body <?php body_class($box_body_class); ?>>
<div id="xabia-full-canvas">
    <?php echo do_shortcode($shortcode); ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
