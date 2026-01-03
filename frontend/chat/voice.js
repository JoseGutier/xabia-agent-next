// Xabia Voice v3.7 — FIX TTS + FIX Chrome "not-allowed" + NO STT AUTO
(function () {
  console.log("[Xabia] voice.js cargado");

  window.Xabia = window.Xabia || {};

  // 🔐 Fuente única de verdad (la gestiona chat.js)
  const USER_INTERACTED = () => window.XABIA_USER_INTERACTED === true;

  /* =====================================================
   * 🧹 Limpieza TTS
   * ===================================================== */
  function cleanText(txt) {
    if (!txt) return "";
    return txt
      .replace(/[\u{1F300}-\u{1FAFF}\u{1F000}-\u{1FFFF}\u{2600}-\u{26FF}]/gu, "")
      .replace(/\*\*/g, "")
      .replace(/[ ]{2,}/g, " ")
      .trim();
  }

  Xabia.Voice = {
    active: true,
    synth: window.speechSynthesis || null,
    rec: null,
    listening: false,

    isSpeaking: false,
    queue: [],
    _lastStart: 0,

    /* =====================================================
     * 🔈 HABLAR (TTS)
     * ===================================================== */
    speak(text) {
      if (!this.synth) return;
      if (!this.active) return;

      // 👇 EVITAR "not-allowed" DE CHROME
      if (!USER_INTERACTED()) {
        console.warn("[Xabia Voice] TTS bloqueado hasta interacción");
        return;
      }

      const cleaned = cleanText(text);
      if (!cleaned) return;

      // Si ya está hablando → cola
      if (this.isSpeaking || this.synth.speaking) {
        this.queue.push(cleaned);
        return;
      }

      const utter = new SpeechSynthesisUtterance(cleaned);

      const langCode = (Xabia.config?.lang || "es").substring(0, 2);
      const langMap = {
        es: "es-ES",
        eu: "eu-ES",
        en: "en-US",
        fr: "fr-FR"
      };
      utter.lang = langMap[langCode] || "es-ES";

      // Selección segura de voz
      const voices = this.synth.getVoices();
      const preferred =
        voices.find(v => v.lang.startsWith(utter.lang) && /Google|Apple|Microsoft/i.test(v.name)) ||
        voices.find(v => v.lang.startsWith(utter.lang));

      if (preferred) utter.voice = preferred;

      try { this.synth.cancel(); } catch (e) {}

      this.isSpeaking = true;

      utter.onend = () => {
        this.isSpeaking = false;
        if (this.queue.length > 0) {
          const next = this.queue.shift();
          this.speak(next);
        }
      };

      utter.onerror = e => {
        console.warn("[Xabia Voice] ❌ TTS error:", e.error);
        this.isSpeaking = false;
        this.queue = [];
      };

      this.synth.speak(utter);
    },

    /* =====================================================
     * 🎙️ ESCUCHA — SOLO MANUAL (NUNCA AUTO)
     * ===================================================== */
listen() {
  const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!Recognition) return Promise.resolve("");

  // 🔇 AQUÍ
  if (this.synth?.speaking) {
    try { this.synth.cancel(); } catch (e) {}
    this.isSpeaking = false;
  }

  const rec = new Recognition();

  // idioma
  const langCode = (Xabia.config?.lang || "es").substring(0, 2);
  const langMap = { es: "es-ES", eu: "eu-ES", en: "en-US", fr: "fr-FR" };
  rec.lang = langMap[langCode] || "es-ES";

  rec.continuous = false;
  rec.interimResults = false;
  rec.maxAlternatives = 1;

  this.listening = true;

  return new Promise(resolve => {
    let resolved = false;

    const cleanup = () => {
      this.listening = false;
      document.body.classList.remove("xabia-listening");
    };

    rec.onstart = () => {
      console.log("[Xabia Voice] 🎙️ escuchando…");
      document.body.classList.add("xabia-listening");
    };

    rec.onresult = e => {
      if (resolved) return;
      resolved = true;

      const txt = (e.results?.[0]?.[0]?.transcript || "").trim();
      console.log("[Xabia Voice] ✅ resultado:", txt);

      cleanup();
      resolve(txt.length > 1 ? txt : "");
    };

    rec.onerror = e => {
  if (resolved) return;
  resolved = true;

  console.warn("[Xabia Voice] ❌ error:", e.error);

  cleanup();

  if (e.error === "no-speech") {
    resolve(""); // silencioso, UX limpia
  } else {
    resolve("");
  }
};

    rec.onend = () => {
      cleanup();
    };

    try {
      setTimeout(() => rec.start(), 400);
    } catch (err) {
      cleanup();
      resolve("");
    }
  });
},
  };
  console.log("[Xabia Voice] 🎧 Sistema de voz listo");

  // Emitir "ready"
  if (!window._xabiaVoiceReady) {
    window._xabiaVoiceReady = true;
    setTimeout(() => {
      document.dispatchEvent(new Event("xabia_voice_ready"));
    }, 80);
  }

})();