document.addEventListener('DOMContentLoaded', function() {
    console.log("Xabia: Iniciando script...");
    const chatContainer = document.getElementById('xabia-chat-app');
    
    if (!chatContainer) {
        console.error("Xabia: No se encontró el contenedor #xabia-chat-app");
        return;
    }

    const projectId = chatContainer.dataset.project;
    const nonce = chatContainer.dataset.nonce;

    console.log("Xabia: Cargando proyecto:", projectId);

    // FORZAR RENDERIZADO (Esto elimina el mensaje de "Conectando...")
    chatContainer.innerHTML = `
        <div class="xabia-chat-header">Xabia Agent: ${projectId}</div>
        <div class="xabia-chat-messages" id="xabia-msgs">
            <div class="xabia-msg ai">Hola, soy el asistente de ${projectId}. ¿En qué puedo ayudarte?</div>
        </div>
        <form class="xabia-chat-input-area" id="xabia-form">
            <input type="text" id="xabia-input" placeholder="Escribe tu pregunta..." autocomplete="off">
            <button type="submit" id="xabia-send">Enviar</button>
        </form>
    `;

    const form = document.getElementById('xabia-form');
    const input = document.getElementById('xabia-input');
    const msgsBox = document.getElementById('xabia-msgs');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const query = input.value.trim();
        if (!query) return;

        appendMessage('user', query);
        input.value = '';
        const loadingId = appendMessage('ai', 'Pensando...');

        try {
            const response = await fetch('/wp-json/xabia/v1/chat', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce 
                },
                body: JSON.stringify({ prompt: query, project_id: projectId })
            });

            if (!response.ok) throw new Error('Error en API: ' + response.status);

            const data = await response.json();
            updateMessage(loadingId, data.response || 'Sin respuesta.');
        } catch (error) {
            console.error("Xabia Error:", error);
            updateMessage(loadingId, 'Error: No se pudo conectar con el motor.');
        }
    });

    function appendMessage(role, text) {
        const id = 'msg-' + Date.now();
        const div = document.createElement('div');
        div.className = `xabia-msg ${role}`;
        div.id = id;
        div.innerText = text;
        msgsBox.appendChild(div);
        msgsBox.scrollTop = msgsBox.scrollHeight;
        return id;
    }

    function updateMessage(id, text) {
        const el = document.getElementById(id);
        if(el) el.innerText = text;
        msgsBox.scrollTop = msgsBox.scrollHeight;
    }
});