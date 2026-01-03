<?php
/**
 * Xabia — floating-button.php
 * Botón flotante que abre/cierra el chat embebido ([xabia_chatbox]).
 * Compatible con Xabia v2.0 y sistema modular JS (loader.js, ui.js, etc.).
 */

if (!defined('ABSPATH')) exit;

/**
 * Render del botón flotante.
 */
function xabia_render_floating_button($atts = []) {
    $atts = shortcode_atts([
        'label' => '¿Hablamos?',
        'position' => 'bottom-right', // bottom-left | bottom-right
    ], $atts, 'xabia_floating_button');

    ob_start(); ?>
    <div id="xabia-floating-button"
         class="xabia-floating-button <?php echo esc_attr($atts['position']); ?>"
         data-target="#xabia-chatbox">
        <span class="xabia-floating-icon">💬</span>
        <span class="xabia-floating-label"><?php echo esc_html($atts['label']); ?></span>
    </div>

    <style>
        /* 🔘 Estilo base del botón flotante */
        .xabia-floating-button {
            position: fixed;
            bottom: 20px;
            right: 25px;
            display: flex;
            align-items: center;
            gap: 8px;
            background: #006cff;
            color: #fff;
            border-radius: 40px;
            padding: 10px 16px;
            font-size: 15px;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
            cursor: pointer;
            z-index: 9999;
            transition: all 0.3s ease;
        }

        .xabia-floating-button.bottom-left { left: 25px; right: auto; }
        .xabia-floating-button:hover { background: #0054cc; transform: scale(1.05); }

        .xabia-floating-icon {
            font-size: 20px;
            line-height: 1;
        }

        /* 💬 Chat visible */
        .xabia-chat-container {
            display: none;
            position: fixed;
            bottom: 80px;
            right: 25px;
            width: 350px;
            max-height: 80vh;
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
            box-shadow: 0 6px 18px rgba(0,0,0,0.3);
            flex-direction: column;
            z-index: 9998;
        }
        .xabia-chat-container.visible { display: flex !important; animation: fadeInUp 0.3s ease; }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const button = document.getElementById('xabia-floating-button');
        const chatbox = document.querySelector(button?.dataset.target || '#xabia-chatbox');

        if (!button || !chatbox) return;

        button.addEventListener('click', () => {
            chatbox.classList.toggle('visible');
            if (chatbox.classList.contains('visible')) {
                button.classList.add('active');
                if (window.Xabia && typeof Xabia.initChat === 'function') {
                    Xabia.initChat('#xabia-chatbox');
                }
            } else {
                button.classList.remove('active');
            }
        });

        // Cierra el chat al hacer clic fuera
        document.addEventListener('click', (e) => {
            if (!chatbox.contains(e.target) && !button.contains(e.target)) {
                chatbox.classList.remove('visible');
                button.classList.remove('active');
            }
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Registrar shortcode
 */
add_shortcode('xabia_floating_button', 'xabia_render_floating_button');