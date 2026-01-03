/**
 * Xabia — accessibility.js (v3.3 FINAL)
 * Sistema de accesibilidad sincronizado con initChat
 */

(function () {
  console.log("[Xabia] accessibility.js cargado");

  window.Xabia = window.Xabia || {};

  Xabia.Accessibility = {
    container: null,
    panel: null,
    storageKey: "xabia_a11y",
    active: false,
    fontScale: 1,
    minScale: 0.9,
    maxScale: 1.4,
    step: 0.1,

    init(selector = "#xabia-chatbox") {
      this.container = document.querySelector(selector);
      this.panel = document.getElementById("xabia-a11y-panel");

      if (!this.container || !this.panel) {
        console.warn("[Xabia A11y] No hay chat o panel");
        return;
      }

      this.loadState();
      this.bindEvents();
      this.applyARIA();
      console.log("[Xabia A11y] Inicializado ✔️");
    },

    bindEvents() {
      const self = this;

      const headerBtn = document.getElementById("xabia-a11y");
      if (headerBtn) headerBtn.addEventListener("click", () => self.openPanel());

      const closeBtn = document.getElementById("xabia-a11y-close");
      if (closeBtn) closeBtn.addEventListener("click", () => self.closePanel());

      this.panel?.addEventListener("click", (e) => {
        if (e.target.closest("#xabia-mic")) return;
        if (e.target === this.panel) self.closePanel();
      });

      document.addEventListener("keydown", (e) => {
        if (window.Xabia?.Voice?.listening) return;
        if (e.key === "Escape") self.closePanel();
      });

      document.getElementById("xabia-a11y-toggle")?.addEventListener("click", () => self.toggleAccessible());
      document.getElementById("xabia-zoom-in")?.addEventListener("click", () => self.zoom("in"));
      document.getElementById("xabia-zoom-out")?.addEventListener("click", () => self.zoom("out"));
      document.getElementById("xabia-reduce-anim")?.addEventListener("click", () => self.reduceMotion());
      document.getElementById("xabia-a11y-reset")?.addEventListener("click", () => self.reset());
    },

    openPanel() {
      this.panel?.classList.add("open");
      this.panel?.setAttribute("aria-hidden", "false");
      this.panel?.focus();
    },

    closePanel() {
      this.panel?.classList.remove("open");
      this.panel?.setAttribute("aria-hidden", "true");
    },

    toggleAccessible() {
      this.active = !this.active;
      this.container.classList.toggle("xabia-accessible", this.active);
      this.saveState();
    },

    zoom(direction) {
      this.fontScale =
        direction === "in"
          ? Math.min(this.maxScale, this.fontScale + this.step)
          : Math.max(this.minScale, this.fontScale - this.step);

      this.applyZoom();
      this.saveState();
    },

    applyZoom() {
      if (this.container) this.container.style.fontSize = this.fontScale + "em";
    },

    reduceMotion() {
      document.documentElement.classList.add("xabia-reduced-motion");
    },

    reset() {
      this.active = false;
      this.fontScale = 1;

      this.container.classList.remove("xabia-accessible");
      this.container.style.fontSize = "";
      document.documentElement.classList.remove("xabia-reduced-motion");

      this.saveState();
    },

    applyARIA() {
      const messages = this.container.querySelector("#xabia-chat-messages");
      if (messages) {
        messages.setAttribute("role", "log");
        messages.setAttribute("aria-live", "polite");
        messages.setAttribute("aria-relevant", "additions");
      }

      const input = this.container.querySelector("#xabia-input");
      if (input) input.setAttribute("aria-label", "Escribe tu mensaje");
    },

    loadState() {
      try {
        const raw = localStorage.getItem(this.storageKey);
        if (!raw) return;

        const data = JSON.parse(raw);

        this.active = !!data.active;
        this.fontScale = data.fontScale || 1;

        if (this.active) this.container.classList.add("xabia-accessible");
        this.applyZoom();
      } catch (e) {
        console.warn("[Xabia A11y] Error leyendo localStorage", e);
      }
    },

    saveState() {
      try {
        localStorage.setItem(
          this.storageKey,
          JSON.stringify({
            active: this.active,
            fontScale: this.fontScale,
          })
        );
      } catch (e) {
        console.warn("[Xabia A11y] Error guardando estado", e);
      }
    },
  };

  /* Arrancar SOLO cuando chat.js ya inicializó */
  document.addEventListener("xabia_chat_ready", () => {
    setTimeout(() => {
      Xabia.Accessibility.init("#xabia-chatbox");
    }, 50);
  });

})();