<?php
/**
 * FRONTEND - Chatbox Widget Dinámico v8.8
 * Soporta Personalización Visual, Voz y Formateo Xabia.
 */

if (!defined('ABSPATH')) exit;

add_action('init', function() {
    add_shortcode('xabia_agent', function($atts) {
        $atts = shortcode_atts(['project' => 'default'], $atts);
        $project_id = sanitize_text_field($atts['project']);

        $config = get_option('xabia_projects_config', []);
        $project_data = $config[$project_id] ?? null;

        if (!$project_data && !is_admin()) return "";

        $style = $project_data['style'] ?? [
            'font_size' => '16px',
            'accent_color' => '#8b004f',
            'container_w' => '100%'
        ];

        $greeting = $project_data['rules']['greeting'] ?? '¡Hola! Bienvenido al ecosistema <b>Xabia</b>.';

        wp_enqueue_style('xabia-google-icons', 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200');
        
        // El CSS principal se carga desde el plugin
        wp_enqueue_style('xabia-frontend-css', XABIA_URL . 'frontend/widgets/styles.css', [], XABIA_VERSION);

        ob_start(); ?>
        
        <style>
            :root {
                --xabia-accent: <?php echo esc_attr($style['accent_color']); ?>;
                --xabia-font-size: <?php echo esc_attr($style['font_size']); ?>;
                --xabia-max-width: <?php echo esc_attr($style['container_w']); ?>;
            }
            #xabia-chatbox {
                max-width: var(--xabia-max-width) !important;
                font-size: var(--xabia-font-size) !important;
                margin: 20px auto;
            }
            #xabia-send-btn, .xabia-icon-btn.listening { color: var(--xabia-accent) !important; }
            .xabia-input-area:focus-within { border-color: var(--xabia-accent) !important; }
        </style>

        <div id="xabia-chatbox" class="xabia-chat-container">
            <div id="xabia-chat-messages" class="xabia-chat-history">
                <div class="xabia-msg bot"><?php echo wp_kses_post($greeting); ?></div>
            </div>

            <div id="xabia-typing" class="xabia-thinking" style="display:none;">
                <span></span><span></span><span></span>
            </div>

            <div class="xabia-input-area">
                <div class="xabia-mic-wrapper">
                    <span id="xabia-voice-btn" class="material-symbols-outlined xabia-icon-btn">mic</span>
                </div>
                
                <input type="text" id="xabia-user-input" placeholder="Pregunta a Xabia..." autocomplete="off">
                
                <div class="xabia-input-right-actions">
                    <span id="xabia-mute-btn" class="material-symbols-outlined xabia-icon-btn">volume_up</span>
                    <span id="xabia-send-btn" class="material-symbols-outlined xabia-icon-btn">send</span>
                </div>
            </div>
        </div>

        <script>
        (function() {
            const sendBtn = document.getElementById('xabia-send-btn');
            const voiceBtn = document.getElementById('xabia-voice-btn');
            const muteBtn = document.getElementById('xabia-mute-btn');
            const input = document.getElementById('xabia-user-input');
            const container = document.getElementById('xabia-chat-messages');
            const typing = document.getElementById('xabia-typing');
            
            let isMuted = false;

            // Formateo de respuesta: Adiós asteriscos, hola negritas y botones
            function formatResponse(text) {
                let formatted = text.replace(/\*\*(.*?)\*\*/g, '<b>$1</b>');
                formatted = formatted.replace(/\*(.*?)\*/g, '<b>$1</b>');
                
                const actionRegex = /\[ACTION:(.*?):(.*?)\]/g;
                return formatted.replace(actionRegex, (match, type, value) => {
                    let icon = type === 'CALL' ? 'call' : (type === 'EMAIL' ? 'mail' : 'language');
                    let label = type === 'CALL' ? 'Llamar' : (type === 'EMAIL' ? 'Email' : 'Ver Web');
                    let href = type === 'CALL' ? `tel:${value}` : (type === 'EMAIL' ? `mailto:${value}` : value);
                    return `<a href="${href}" target="_blank" class="xabia-action-btn"><span class="material-symbols-outlined">${icon}</span> ${label}</a>`;
                });
            }

            // --- Control de Mute ---
            muteBtn.addEventListener('click', () => {
                isMuted = !isMuted;
                muteBtn.innerText = isMuted ? 'volume_off' : 'volume_up';
                if(isMuted) window.speechSynthesis.cancel();
            });

            // --- Lógica de Voz ---
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (SpeechRecognition) {
                const recognition = new SpeechRecognition();
                recognition.lang = 'es-ES';
                
                voiceBtn.addEventListener('click', () => {
                    try { recognition.start(); voiceBtn.classList.add('listening'); } 
                    catch(e) { recognition.stop(); }
                });

                recognition.onresult = (e) => {
                    input.value = e.results[0][0].transcript;
                    sendMessage();
                };
                recognition.onend = () => voiceBtn.classList.remove('listening');
            }

            function sendMessage() {
                const msg = input.value.trim();
                if(!msg) return;
                
                input.value = '';
                container.innerHTML += `<div class="xabia-msg user">${msg}</div>`;
                typing.style.display = 'flex';
                container.scrollTop = container.scrollHeight;

                fetch('<?php echo esc_url(rest_url("xabia/v1/chat")); ?>', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json', 
                        'X-WP-Nonce': '<?php echo wp_create_nonce("wp_rest"); ?>' 
                    },
                    body: JSON.stringify({ prompt: msg, project_id: '<?php echo esc_js($project_id); ?>' })
                })
                .then(r => r.json())
                .then(data => {
                    typing.style.display = 'none';
                    if (data.response) {
                        const cleanText = data.response;
                        container.innerHTML += `<div class="xabia-msg bot">${formatResponse(cleanText)}</div>`;
                        container.scrollTop = container.scrollHeight;
                        
                        if (!isMuted) {
                            const utterance = new SpeechSynthesisUtterance(cleanText.replace(/\[.*?\]/g, '').replace(/\*/g, ''));
                            utterance.lang = 'es-ES';
                            window.speechSynthesis.speak(utterance);
                        }
                    }
                });
            }

            sendBtn.addEventListener('click', sendMessage);
            input.addEventListener('keypress', (e) => { if(e.key === 'Enter') sendMessage(); });
        })();
        </script>
        <?php
        return ob_get_clean();
    });
});