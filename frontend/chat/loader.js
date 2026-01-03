// =====================================================
// loader.js — Xabia Loader SAFE v43
// =====================================================
(function () {
  console.log("[Xabia] loader.js (v43 SAFE) cargado");

  window.Xabia = window.Xabia || {};
  Xabia.config = Xabia.config || {};

  function detectLanguage() {
    if (typeof ICL_LANGUAGE_CODE !== "undefined") return ICL_LANGUAGE_CODE;

    const box = document.querySelector("#xabia-chatbox");
    if (box && box.dataset && box.dataset.lang) return box.dataset.lang;

    return (document.documentElement && document.documentElement.lang) || "es";
  }

  function resolveBase() {
    let base = null;

    if (typeof XABIA_DATA !== "undefined" && XABIA_DATA.assets) {
      base = String(XABIA_DATA.assets).replace(/\/+$/, "");
    }

    if (!base) {
      const src = (document.currentScript && document.currentScript.src) || "";
      if (src.includes("frontend/chat/")) {
        base = src.split("frontend/chat/")[0] + "frontend/chat";
      }
    }

    if (!base) {
      base = "/wp-content/plugins/xabia-agent-next/frontend/chat";
      console.warn("[Xabia] ⚠️ loader.js usando ruta fallback");
    }

    return String(base).replace(/\/+$/, "");
  }

  // 1) Config
  Xabia.config.lang = detectLanguage();
  Xabia.config.assets = resolveBase();
  Xabia.config.restUrl =
    (typeof XABIA_DATA !== "undefined" && XABIA_DATA.restUrl)
      ? XABIA_DATA.restUrl
      : "/wp-json/xabi/v1/query";

  // 2) app / sources
  if (typeof XABIA_DATA !== "undefined") {
    if (XABIA_DATA.app) Xabia.config.app = XABIA_DATA.app;
    if (Array.isArray(XABIA_DATA.sources)) Xabia.config.sources = XABIA_DATA.sources;
  }

  const box = document.querySelector("#xabia-chatbox");
  if (box && box.dataset) {
    if (box.dataset.app && !Xabia.config.app) Xabia.config.app = box.dataset.app;

    if (box.dataset.sources && !Xabia.config.sources) {
      Xabia.config.sources = box.dataset.sources
        .split(",")
        .map(s => s.trim())
        .filter(Boolean);
    }
  }

  Xabia.config.app = Xabia.config.app || "default";
  Xabia.config.sources = Xabia.config.sources || [];

  console.log("[Xabia] Config final:", Xabia.config);

// 3) Init: SOLO cuando chat.js esté listo
function initIfPossible() {
  const el = document.querySelector("#xabia-chatbox");
  if (!el) return;

  if (typeof Xabia.initChat !== "function") {
    setTimeout(initIfPossible, 50);
    return;
  }

  if (Xabia._initialized) return;
  Xabia._initialized = true;

  console.log("[Xabia] 🚀 initChat ejecutado");
  Xabia.initChat("#xabia-chatbox");
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initIfPossible, { once: true });
} else {
  initIfPossible();
}
})();