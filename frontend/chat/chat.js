// =====================================================
// Xabia — chat.js v5.3 (DEMO-AWARE, SAFE)
// =====================================================
(function () {
  console.log("[Xabia] chat.js v5.3 cargado");

  window.Xabia = window.Xabia || {};
  Xabia.state = Xabia.state || {};

  /* =====================================================
   * 1) CONTEXTO
   * ===================================================== */
  const isDemo = Xabia.config?.app === "demo_xabia";

  /* =====================================================
   * 2) HELPERS UI
   * ===================================================== */
  function addMessage(role, html) {
    const box = document.getElementById("xabia-chat-messages");
    if (!box) return;

    const el = document.createElement("div");
    el.className = "xabia-msg xabia-" + role;
    el.innerHTML = html;
    box.appendChild(el);
    box.scrollTop = box.scrollHeight;
  }

  function escapeHTML(str) {
    return str.replace(/[&<>"']/g, m => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;"
    }[m]));
  }

  /* =====================================================
   * 3) ACCIONES — FILTRO DEMO
   * ===================================================== */
  function filterActions(actions = []) {
    if (!isDemo) return actions;

    // En demo SOLO permitimos acciones informativas
    return actions.filter(a => {
      if (!a || !a.command) return false;

      return [
        "explain",
        "example",
        "compare",
        "cta"
      ].includes(a.command);
    });
  }

    /* =====================================================
     * CTA — RENDER ESTRUCTURADO
     * ===================================================== */
    function renderCTA(cta) {
          if (!cta || !Array.isArray(cta.items)) return;
        
          const wrap = document.createElement("div");
          wrap.className = "xabia-actions xabia-cta";
        
          cta.items.forEach(btnData => {
            const btn = document.createElement("button");
            btn.className = "xabia-action-btn xabia-cta-btn";
            btn.textContent = btnData.label || "Acción";
        
            btn.onclick = () => {
              if (btnData.command === "open_url" && btnData.value) {
                window.open(btnData.value, "_blank");
              } else if (btnData.value) {
                sendMessage(btnData.value);
              }
            };
        
            wrap.appendChild(btn);
          });
        
          document.getElementById("xabia-chat-messages").appendChild(wrap);
        }
        
        /* =====================================================
         * RENDER DE LISTA DE RESULTADOS
         * ===================================================== */
        function renderRecords(records = []) {
          if (!Array.isArray(records) || !records.length) return;
        
          const box = document.getElementById("xabia-chat-messages");
        
          records.forEach(r => {
            const name = r.empresa || r.title || "Empresa";
            const cat  = r.categoria || "";
            const desc =
              r.descripcion_empresa ||
              r.text ||
              "";
        
            const el = document.createElement("div");
            el.className = "xabia-msg xabia-bot xabia-record";
            el.innerHTML = `
              <strong>${name}</strong><br>
              ${cat ? `<small>${cat}</small><br>` : ""}
              ${desc ? `<div class="xabia-record-text">${desc.slice(0, 160)}…</div>` : ""}
            `;
        
            box.appendChild(el);
          });
        
          box.scrollTop = box.scrollHeight;
        }

  /* =====================================================
   * 4) RENDER DE RESPUESTA
   * ===================================================== */
     function renderResponse(data) {
      if (!data) {
        addMessage("bot", "No he podido generar una respuesta.");
        return;
      }
    
      if (data.answer) {
        addMessage("bot", data.answer);
      }
    
      // 👇 ESTA LÍNEA ES LA CLAVE
      if (Array.isArray(data.records) && data.records.length) {
        renderRecords(data.records);
      }
    
      // Acciones
      if (Array.isArray(data.actions)) {
        const actions = filterActions(data.actions);
        if (actions.length) {
          const wrap = document.createElement("div");
          wrap.className = "xabia-actions";
    
          actions.forEach(a => {
            const btn = document.createElement("button");
            btn.className = "xabia-action-btn";
            btn.textContent = a.label || "Acción";
            btn.onclick = () => a.value && sendMessage(a.value);
            wrap.appendChild(btn);
          });
    
          document.getElementById("xabia-chat-messages").appendChild(wrap);
        }
      }
    
      if (data.cta) {
        renderCTA(data.cta);
      }
    }

  /* =====================================================
   * 5) ENVÍO DE MENSAJES
   * ===================================================== */
  async function sendMessage(text) {
    if (!text) return;

    addMessage("user", escapeHTML(text));

    const payload = {
      q: text,
      app: Xabia.config.app || null,
      sources: Xabia.config.sources || [],
    };

    try {
      const res = await fetch(Xabia.config.restUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });

      const data = await res.json();
      renderResponse(data);

    } catch (e) {
      console.error("[Xabia] Error:", e);
      addMessage("bot", "Ha ocurrido un error. Inténtalo de nuevo.");
    }
  }
  
   /* =====================================================
 * SALUDO INSTITUCIONAL (BACKEND)
 * ===================================================== */
if (!Xabia.state._greeted) {
  Xabia.state._greeted = true;

  fetch(Xabia.config.restUrl, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      q: "",
      app: Xabia.config.app,
      sources: Xabia.config.sources || []
    })
  })
  .then(r => r.json())
  .then(data => {
    if (data && data.answer) {
      addMessage("bot", data.answer);
      if (data.cta) renderCTA(data.cta);
    }
  })
  .catch(err => {
    console.error("[Xabia] Error saludo inicial:", err);
  });
}

/* =====================================================
 * 6) INIT
 * ===================================================== */
Xabia.initChat = function (selector = "#xabia-chatbox") {

  const root = document.querySelector(selector);
  if (!root) {
    console.warn("[Xabia] Chat root no encontrado:", selector);
    return;
  }

  const input = root.querySelector("#xabia-input");
  const send  = root.querySelector("#xabia-send");

  if (!input || !send) {
    console.warn("[Xabia] Input o botón no encontrados");
    return;
  }

  send.onclick = () => {
    const val = input.value.trim();
    if (!val) return;
    input.value = "";
    sendMessage(val);
  };

  input.addEventListener("keydown", e => {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      send.click();
    }
  });

  /* =====================================================
   * SALUDO INSTITUCIONAL (BACKEND)
   * ===================================================== */
  if (!Xabia.state._greeted) {
    Xabia.state._greeted = true;

    fetch(Xabia.config.restUrl, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        q: "",
        app: Xabia.config.app,
        sources: Xabia.config.sources || []
      })
    })
    .then(r => r.json())
    .then(data => {
      if (data && data.answer) {
        addMessage("bot", data.answer);
        if (data.cta) renderCTA(data.cta);
      }
    })
    .catch(err => {
      console.error("[Xabia] Error saludo inicial:", err);
    });
  }

  console.log("[Xabia] Chat inicializado OK", {
    app: Xabia.config.app,
    demo: isDemo,
  });
};
})();