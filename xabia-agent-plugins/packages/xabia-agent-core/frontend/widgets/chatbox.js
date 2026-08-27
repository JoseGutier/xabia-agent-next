/**
 * Xabia chatbox: enviar, micrófono, voz (TTS). Lee config de data-* del .xabia-chatbox.
 */
(function() {
    'use strict';

    function xabiaI18n(key, fallback) {
        var bag = (typeof window.xabiaI18n === 'object' && window.xabiaI18n !== null) ? window.xabiaI18n : {};
        if (Object.prototype.hasOwnProperty.call(bag, key) && bag[key] !== null && String(bag[key]) !== '') {
            return String(bag[key]);
        }
        return fallback !== undefined && fallback !== null ? String(fallback) : '';
    }

    function xabiaChatSettings() {
        return (typeof window.XabiaSettings === 'object' && window.XabiaSettings !== null) ? window.XabiaSettings : {};
    }

    function xabiaIsLiteMode() {
        return !!xabiaChatSettings().liteMode;
    }

    function xabiaChatAjaxAction() {
        var settings = xabiaChatSettings();
        if (settings.liteMode) {
            return settings.ajaxAction || 'xabia_lite_ask_ai';
        }
        return 'xabia_ask_ai';
    }

    /**
     * Identificador anónimo estable por proyecto (localStorage). El Core lo usa
     * para el límite de tokens por sesión / anti-bot.
     */
    function xabiaVisitorKey(projectId) {
        var pid = String(projectId || 'default').replace(/[^a-zA-Z0-9_-]/g, '') || 'default';
        var storageKey = 'xabia_visitor_key_' + pid;
        try {
            var existing = window.localStorage ? localStorage.getItem(storageKey) : '';
            if (existing && /^[a-zA-Z0-9_-]{8,64}$/.test(existing)) {
                return existing;
            }
            var next = '';
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                next = String(window.crypto.randomUUID()).replace(/-/g, '');
            } else {
                var i;
                for (i = 0; i < 32; i++) {
                    next += Math.floor(Math.random() * 16).toString(16);
                }
            }
            next = String(next).replace(/[^a-zA-Z0-9]/g, '').substring(0, 64);
            if (next.length < 8) {
                next = (next + 'xabiafall') .substring(0, 16);
            }
            if (window.localStorage) {
                localStorage.setItem(storageKey, next);
            }
            return next;
        } catch (eVk) {
            return '';
        }
    }

    function xabiaChatboxMain($) {

    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    /**
     * Seguimiento de intención de compra (clics en añadir al carrito / pack) por proyecto.
     * window.xabiaWooCartIntent.getItems(projectId), .recordSingle(projectId, id), .recordPack(...), .getSummaryText(projectId), .clear(projectId)
     */
    window.xabiaWooCartIntent = (function() {
        function storageKey(projectId) {
            return 'xabia_woo_cart_intent_' + String(projectId || 'default');
        }
        function load(projectId) {
            try {
                var raw = sessionStorage.getItem(storageKey(projectId));
                var arr = raw ? JSON.parse(raw) : [];
                return Array.isArray(arr) ? arr : [];
            } catch (e) {
                return [];
            }
        }
        function save(projectId, items) {
            try {
                sessionStorage.setItem(storageKey(projectId), JSON.stringify(items.slice(-40)));
            } catch (e2) {}
        }
        function record(projectId, entry) {
            var items = load(projectId);
            items.push(entry);
            save(projectId, items);
        }
        return {
            recordSingle: function(projectId, productId, label) {
                var id = parseInt(productId, 10);
                if (!id) return;
                record(String(projectId || 'default'), { id: id, t: Date.now(), label: label || '', kind: 'single' });
            },
            recordPack: function(projectId, ids, quantities, label) {
                var pids = (ids || []).map(function(x) { return parseInt(x, 10); }).filter(function(n) { return n > 0; });
                if (!pids.length) return;
                record(String(projectId || 'default'), {
                    ids: pids,
                    qty: quantities || [],
                    t: Date.now(),
                    label: label || '',
                    kind: 'pack'
                });
            },
            getItems: function(projectId) {
                return load(projectId);
            },
            clear: function(projectId) {
                try {
                    sessionStorage.removeItem(storageKey(projectId));
                } catch (e3) {}
            },
            getSummaryText: function(projectId) {
                var items = load(projectId);
                if (!items.length) return '';
                var lines = [];
                var byPack = {};
                var byId = {};
                items.forEach(function(it) {
                    if (it && Array.isArray(it.ids) && it.ids.length) {
                        var k = it.ids.join('-');
                        byPack[k] = (byPack[k] || 0) + 1;
                    } else if (it && it.id) {
                        var id = it.id;
                        byId[id] = (byId[id] || 0) + 1;
                    }
                });
                Object.keys(byId).forEach(function(id) {
                    lines.push('ID ' + id + ' ×' + byId[id]);
                });
                Object.keys(byPack).forEach(function(k) {
                    lines.push('Pack [' + k.replace(/-/g, ', ') + '] ×' + byPack[k]);
                });
                return lines.length ? (xabiaI18n('sessionCartClicks', 'Clics de compra en esta sesión:') + ' ' + lines.join('; ')) : '';
            }
        };
    })();

    /** Código ISO 639-1 (data-lang) → BCP47 para Speech API / utterance (alineado con chatbox.php). */
    var XABIA_LANG_BCP47 = { es: 'es-ES', eu: 'eu-ES', en: 'en-US', fr: 'fr-FR', ca: 'ca-ES', gl: 'gl-ES', pt: 'pt-PT', de: 'de-DE', it: 'it-IT' };
    function xabiaBcp47FromLang(code) {
        var c = String(code || 'es').toLowerCase().replace(/[^a-z]/g, '').substring(0, 2) || 'es';
        return XABIA_LANG_BCP47[c] || (c + '-' + c.toUpperCase());
    }

    function parseTtsConfig($box) {
        var raw = $box.data('tts');
        if (!raw) return { voice: 'default', rate: 1, clean: { bold: false, italic: false, actions: false, emojis: false, patterns: [] } };
        try { return typeof raw === 'string' ? JSON.parse(raw) : raw; } catch (e) { return { voice: 'default', rate: 1, clean: {} }; }
    }

    /** jQuery .data('voice') y el attr HTML pueden desincronizarse; unificar lectura. */
    function isVoiceOn($box) {
        if (!$box || !$box.length) return false;
        var v = $box.data('voice');
        if (v === true || v === 1 || v === '1') return true;
        var attr = $box.attr('data-voice');
        return attr === '1' || attr === 'true';
    }

    function setVoiceOn($box, on) {
        var val = on ? 1 : 0;
        $box.data('voice', val);
        $box.attr('data-voice', String(val));
        var $btn = $box.find('.xabia-mute');
        if (on) {
            $btn.addClass('is-voice-on')
                .attr('title', xabiaI18n('voiceDisable', 'Desactivar voz'))
                .attr('aria-label', xabiaI18n('voiceDisable', 'Desactivar voz'));
        } else {
            $btn.removeClass('is-voice-on')
                .attr('title', xabiaI18n('voiceEnable', 'Activar voz (lectura en alto)'))
                .attr('aria-label', xabiaI18n('voiceEnableShort', 'Activar voz'));
        }
        /* Layout immersive no depende del mute; solo sincroniza por si el panel estaba cerrado */
        try {
            if (window.XabiaInterfaceApi && typeof window.XabiaInterfaceApi.syncImmersive === 'function') {
                window.XabiaInterfaceApi.syncImmersive($box[0]);
            }
        } catch (eSync) {}
    }

    /**
     * Desbloquear salida de audio (Safari/Chrome) + pitido breve de confirmación.
     * Si el usuario no oye el pitido, el problema es del sistema/navegador, no del TTS.
     */
    var xabiaAudioCtx = null;
    function unlockSpeechSynthesis(opts) {
        opts = opts || {};
        if (!window.speechSynthesis) return;
        try { window.speechSynthesis.resume(); } catch (eUnlock) {}
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            if (!xabiaAudioCtx) xabiaAudioCtx = new Ctx();
            if (xabiaAudioCtx.state === 'suspended') {
                xabiaAudioCtx.resume();
            }
            if (opts.beep) {
                var o = xabiaAudioCtx.createOscillator();
                var g = xabiaAudioCtx.createGain();
                o.type = 'sine';
                o.frequency.value = 880;
                g.gain.value = 0.0001;
                g.gain.exponentialRampToValueAtTime(0.12, xabiaAudioCtx.currentTime + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, xabiaAudioCtx.currentTime + 0.18);
                o.connect(g);
                g.connect(xabiaAudioCtx.destination);
                o.start();
                o.stop(xabiaAudioCtx.currentTime + 0.2);
            }
        } catch (eAc) {}
    }

    function attachMsgSpeakButton($box, $botDiv, raw) {
        if (!$botDiv || !$botDiv.length) return;
        $botDiv.find('.xabia-msg-speak').remove();
        var $btn = $('<button type="button" class="xabia-msg-speak"></button>')
            .attr('title', xabiaI18n('voiceListen', 'Escuchar'))
            .attr('aria-label', xabiaI18n('voiceListen', 'Escuchar'))
            .html('<span class="xabia-msg-speak__icon" aria-hidden="true"></span>');
        $btn.on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            unlockSpeechSynthesis({ beep: false });
            setVoiceOn($box, true);
            speakText($box, raw || lastBotRawText($box), null, { immediate: true, force: true });
        });
        $botDiv.append($btn);
    }

    function lastBotRawText($box) {
        var $last = $box.find('.xabia-msg.bot').last();
        if (!$last.length) return '';
        var raw = $last.attr('data-raw');
        if (raw) return String(raw);
        var $c = $last.find('.xabia-msg-content');
        return String(($c.length ? $c.text() : $last.text()) || '').trim();
    }

    function clearSpeechResumeWatch() {
        if (xabiaLipSync.resumeWatch) {
            clearInterval(xabiaLipSync.resumeWatch);
            xabiaLipSync.resumeWatch = 0;
        }
    }

    function startSpeechResumeWatch() {
        clearSpeechResumeWatch();
        /* Chrome: speechSynthesis se pausa solo tras ~15s sin resume() */
        xabiaLipSync.resumeWatch = setInterval(function() {
            try {
                if (window.speechSynthesis && window.speechSynthesis.speaking && window.speechSynthesis.paused) {
                    window.speechSynthesis.resume();
                }
            } catch (eR) {}
        }, 4000);
    }

    function cleanTextForTts(text, clean) {
        if (!text) return '';
        text = String(text).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
        /* Siempre quitar etiquetas de acción: no deben oírse */
        text = text.replace(/\[(?:ACTION|IMAGE|CART|BOOK)[^\]]*\]/gi, ' ');
        if (!clean || typeof clean !== 'object') {
            return text.replace(/\s+/g, ' ').trim();
        }
        if (clean.bold) text = text.replace(/\*\*/g, '');
        if (clean.italic) text = text.replace(/\*/g, '');
        if (clean.emojis) {
            text = text.replace(/[\u{1F000}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE0F}\u{200D}]/gu, ' ');
        }
        if (Array.isArray(clean.patterns)) {
            clean.patterns.forEach(function(p) { if (p) text = text.split(p).join(' '); });
        }
        return text.replace(/\s+/g, ' ').trim();
    }

    function scoreVoice(voice, langCode, preference) {
        var wanted = String(langCode || 'es-ES').toLowerCase();
        var base = wanted.split('-')[0];
        var voiceLang = String(voice.lang || '').toLowerCase();
        var name = String(voice.name || '');
        var normalizedName = name.normalize ? name.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase() : name.toLowerCase();
        var score = 0;

        if (voiceLang === wanted) score += 120;
        else if (voiceLang.indexOf(base + '-') === 0) score += 35;
        else if (voiceLang.indexOf(base) === 0) score += 15;

        if (wanted === 'es-es') {
            if (/spanish \(spain\)|espanol \(espana\)|castellano|google espanol/i.test(normalizedName)) score += 35;
            if (/paulina|penelope|miguel|sabina|dalia|latino|latin|mexico|mexican|estados unidos|united states|us spanish|es-419/i.test(normalizedName) || /es-(mx|us|419|ar|cl|co|pe|ve|uy|bo|ec|gt|hn|ni|pa|pr|py|sv)/.test(voiceLang)) {
                score -= 100;
            }
        }

        if (preference === 'female') {
            if (/female|woman|mujer|femenin|monica|helena|lucia|lucía|marta|marisol|elvira|laura|sara|carmen|isabel/i.test(normalizedName)) score += 130;
            if (/male|man|hombre|masculin|jorge|alvaro|alvaro|enrique|diego|pablo|carlos|juan|miguel/i.test(normalizedName)) score -= 180;
        } else if (preference === 'male') {
            if (/male|man|hombre|masculin|jorge|alvaro|alvaro|enrique|diego|pablo|carlos|juan|miguel/i.test(normalizedName)) score += 130;
            if (/female|woman|mujer|femenin|monica|helena|lucia|lucía|marta|marisol|elvira|laura|sara|carmen|isabel/i.test(normalizedName)) score -= 180;
        } else {
            if (/google|natural|premium|neural|enhanced|samantha|paulina|monica|helena|lucia|jorge|marta/i.test(normalizedName)) score += 45;
            if (/espeak|compact|android|samsung|microsoft.*desktop|fred|junior|bad/i.test(normalizedName)) score -= 120;
        }

        if (voice.localService) {
            score += 80;
        } else {
            /* Voces remotas de Chrome a veces quedan mudas en macOS */
            score -= 20;
        }

        return score;
    }

    function pickVoice(langCode, preference) {
        var list = window.speechSynthesis.getVoices();
        if (!list || !list.length) return null;
        var ranked = list.slice().sort(function(a, b) {
            return scoreVoice(b, langCode, preference) - scoreVoice(a, langCode, preference);
        });
        return ranked[0] || list[0];
    }

    /** Fuerza la carga de voces del navegador (Chrome/Safari las cargan async). */
    function preloadSpeechVoices() {
        try {
            if (!window.speechSynthesis) return;
            window.speechSynthesis.getVoices();
        } catch (eVoices) {}
    }

    function joinImagesBase(base, path) {
        base = String(base || '').trim().replace(/\/+$/, '');
        path = String(path || '').trim().replace(/^\/+/, '');
        if (!path) return '';
        if (!base) return path;
        return base + '/' + path;
    }

    function resolveActionImgSrc(raw, imagesBase) {
        var url = String(raw || '').trim();
        if (!url || url.indexOf(' ') !== -1) return '';
        if (/^https?:\/\//i.test(url)) return url;
        if (url.indexOf('//') === 0) return 'https:' + url;
        if (url.charAt(0) === '/') return url;
        return joinImagesBase(imagesBase, url);
    }

    function xabiaAmeliaBookingUrl(serviceId) {
        var sid = String(serviceId || '').replace(/\D/g, '');
        if (!sid) return '';
        var tpl = window.xabiaReservas && window.xabiaReservas.ameliaTriggerUrl ? String(window.xabiaReservas.ameliaTriggerUrl) : '';
        if (tpl.indexOf('{service}') !== -1) {
            return tpl.split('{service}').join(sid);
        }
        return '';
    }

    function xabiaAmeliaTryNativeOpen(serviceId) {
        var sid = String(serviceId || '').replace(/\D/g, '');
        if (!sid) return false;
        var selectors = [
            '[data-amelia-service-id="' + sid + '"]',
            '[data-service-id="' + sid + '"]',
            '.amelia-v2-booking[data-service="' + sid + '"]',
            '#amelia-app-booking',
            '.amelia-app-booking',
            '[class*="amelia"][class*="booking"]'
        ];
        for (var i = 0; i < selectors.length; i++) {
            var el = document.querySelector(selectors[i]);
            if (el) {
                try {
                    el.click();
                    return true;
                } catch (eClick) {}
            }
        }
        return false;
    }

    function xabiaAmeliaDispatchBookingOpen(serviceId) {
        var sid = parseInt(serviceId, 10);
        if (!sid) return;
        var detail = { serviceId: sid, service_id: sid };
        try {
            window.dispatchEvent(new CustomEvent('xabia-amelia-booking-open', { detail: detail, bubbles: true }));
        } catch (eWin) {}
        try {
            document.dispatchEvent(new CustomEvent('xabia-amelia-booking-open', { detail: detail, bubbles: true }));
        } catch (eDoc) {}
        if (typeof jQuery !== 'undefined') {
            jQuery(document.body).trigger('xabia-amelia-booking-open', [sid]);
        }
    }

    function xabiaAmeliaHandleBookingOpen(serviceId, opts) {
        opts = opts || {};
        var sid = parseInt(serviceId, 10);
        if (!sid) return false;
        if (!opts.skipEvent) {
            xabiaAmeliaDispatchBookingOpen(sid);
        }
        if (xabiaAmeliaTryNativeOpen(sid)) return true;
        var url = xabiaAmeliaBookingUrl(sid);
        if (url) {
            window.location.href = url;
            return true;
        }
        var anchor = document.querySelector('#amelia-app-booking, .amelia-v2-booking, [id*="amelia"]');
        if (anchor && typeof anchor.scrollIntoView === 'function') {
            anchor.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return true;
        }
        return false;
    }

    window.addEventListener('xabia-amelia-booking-open', function(ev) {
        if (!ev || ev.defaultPrevented) return;
        var sid = ev.detail ? (ev.detail.serviceId || ev.detail.service_id) : null;
        if (!sid) return;
        xabiaAmeliaHandleBookingOpen(sid, { skipEvent: true });
    });

    /**
     * Convierte la respuesta del bot en HTML: enlaces, teléfono, imágenes, mapa.
     * [ACTION:CALL:num] -> enlace tel:; [ACTION:URL:url] -> enlace; [ACTION:IMG:url] -> img; [ACTION:MAP:...] -> enlace a mapa.
     */
    function renderActions(text, imagesBase) {
        if (!text) return '';
        text = String(text);
        imagesBase = String(imagesBase || '').trim();
        function escAttr(s) {
            return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }
        function decodeHtmlEntities(s) {
            if (!s) return '';
            var t = document.createElement('textarea');
            t.innerHTML = String(s);
            return t.value;
        }
        function renderAmeliaBookControl(serviceId) {
            var sid = String(serviceId || '').replace(/\D/g, '');
            if (!sid) return '';
            var label = escAttr(xabiaI18n('actionBook', 'Reservar'));
            var href = xabiaAmeliaBookingUrl(sid);
            if (href) {
                return '<a href="' + escAttr(href) + '" class="xabia-btn-book xabia-book-amelia xabia-book-amelia--link" data-engine="amelia" data-service-id="' + escAttr(sid) + '" target="_blank" rel="noopener">📅 ' + label + '</a>';
            }
            return '<button type="button" class="xabia-btn-book xabia-book-amelia" data-engine="amelia" data-service-id="' + escAttr(sid) + '">📅 ' + label + '</button>';
        }
        text = text.replace(/\[ACTION:CALL:([^\]]+)\]/g, function(_, num) {
            var tel = (num || '').replace(/\s+/g, '');
            return '<a href="tel:' + escAttr(tel) + '" class="xabia-action xabia-action-call">📞 ' + escAttr(xabiaI18n('actionCall', 'Llamar')) + '</a>';
        });
        text = text.replace(/\[ACTION:URL:([^\]]+)\]/g, function(_, url) {
            var href = decodeHtmlEntities((url || '').trim());
            return '<a href="' + escAttr(href) + '" target="_blank" rel="noopener" class="xabia-action xabia-action-url">🌐 ' + escAttr(xabiaI18n('actionOpenLink', 'Abrir enlace')) + '</a>';
        });
        text = text.replace(/\[ACTION:IMG:([^\]]+)\]/g, function(_, src) {
            var url = resolveActionImgSrc(src, imagesBase);
            if (!url) return '';
            return '<img src="' + escAttr(url) + '" alt="" class="xabia-action-img" loading="lazy" decoding="async">';
        });
        text = text.replace(/\[ACTION:MAP:([^\]]+)\]/g, function(_, q) {
            var query = encodeURIComponent((q || '').trim());
            return '<a href="https://www.google.com/maps/search/?api=1&query=' + query + '" target="_blank" rel="noopener" class="xabia-action xabia-action-map">📍 ' + escAttr(xabiaI18n('actionViewMap', 'Ver en mapa')) + '</a>';
        });
        
        text = text.replace(/\[ACTION:CART:(\d+)\]/g, function(_, id) {
            var pid = String(id || '').replace(/\D/g, '');
            if (!pid) return '';
            var wc = window.xabiaWooCart || {};
            if (wc.mode === 'redirect' && wc.urlTemplate && String(wc.urlTemplate).indexOf('{id}') !== -1) {
                var href = String(wc.urlTemplate).split('{id}').join(pid);
                var lbl = wc.checkoutLabel || xabiaI18n('actionBuyNow', 'Comprar ahora');
                return '<a href="' + escAttr(href) + '" class="xabia-action xabia-action-url xabia-btn-cart-remote" target="_blank" rel="noopener">🛒 ' + escAttr(lbl) + '</a>';
            }
            return '<button type="button" class="xabia-btn-cart" data-id="' + escAttr(pid) + '">🛒 ' + escAttr(xabiaI18n('actionAddToCart', 'Añadir al carrito')) + '</button>';
        });
        text = text.replace(/\[ACTION:CART_PACK:([^\]]+)\]/g, function(_, spec) {
            var parts = String(spec || '').trim().split('|');
            var idsPart = (parts[0] || '').trim();
            var qtyPart = (parts[1] || '').trim();
            var ids = idsPart.split(',').map(function(s) { return String(s).replace(/\D/g, ''); }).filter(Boolean);
            if (!ids.length) return '';
            var quantities = [];
            if (qtyPart) {
                qtyPart.split(',').forEach(function(s) {
                    var n = parseInt(String(s).trim(), 10);
                    quantities.push(isNaN(n) || n < 1 ? 1 : n);
                });
            }
            while (quantities.length < ids.length) {
                quantities.push(1);
            }
            quantities = quantities.slice(0, ids.length);
            var wc = window.xabiaWooCart || {};
            var tpl = wc.packUrlTemplate;
            if (!tpl || String(tpl).indexOf('{ids}') === -1) {
                return '';
            }
            var href = String(tpl).replace('{ids}', ids.join(',')).replace('{quantities}', quantities.join(','));
            var lbl = wc.packCheckoutLabel || xabiaI18n('actionBuyPack', 'Comprar pack');
            return '<a href="' + escAttr(href) + '" class="xabia-action xabia-action-url xabia-btn-cart-pack-remote" target="_blank" rel="noopener" data-pack-ids="' + escAttr(ids.join(',')) + '">🛒 ' + escAttr(lbl) + '</a>';
        });
        function xabiaDecodeUrlB64(b64) {
            try {
                var raw = String(b64 || '');
                var pad = raw.length % 4 ? 4 - (raw.length % 4) : 0;
                var s = raw.replace(/-/g, '+').replace(/_/g, '/') + (pad ? '==='.slice(0, pad) : '');
                return atob(s);
            } catch (e) { return ''; }
        }
        
        text = text.replace(/\[ACTION:BOOK:[a-z0-9_-]+:(\d+):([^\]]+)\]/gi, function(_, id, b64) {
            var href = xabiaDecodeUrlB64(b64);
            if (!href) return '';
            return '<a href="' + escAttr(href) + '" class="xabia-btn-book" data-id="' + escAttr(String(id)) + '" target="_blank" rel="noopener">📅 ' + escAttr(xabiaI18n('actionBook', 'Reservar')) + '</a>';
        });
        
        text = text.replace(/\[ACTION:BOOK:amelia:(\d+)\]/g, function(_, id) {
            return renderAmeliaBookControl(id);
        });
        
        text = text.replace(/\[ACTION:BOOK:(\d+)\]/g, function(_, id) {
            var pid = String(id || '').replace(/\D/g, '');
            if (!pid) return '';
            var eng = (window.xabiaReservas && window.xabiaReservas.engine) ? String(window.xabiaReservas.engine) : '';
            if (eng === 'amelia') {
                return renderAmeliaBookControl(pid);
            }
            if (eng !== 'amelia') {
                var remote = (window.xabiaReservas && window.xabiaReservas.remoteSiteUrl) ? String(window.xabiaReservas.remoteSiteUrl).replace(/\/$/, '') : '';
                var home = (window.xabiaReservas && window.xabiaReservas.homeUrl) ? String(window.xabiaReservas.homeUrl) : '';
                var href = '';
                if (remote) {
                    // Remoto: el servidor debe enriquecer BOOK con URL completa; no inventar /?p=ID en el agente.
                    href = remote + '/';
                } else if (home) {
                    var base = home.replace(/\/$/, '');
                    var sep = base.indexOf('?') >= 0 ? '&' : '?';
                    href = base + sep + 'p=' + pid;
                }
                if (!href) return '';
                return '<a href="' + escAttr(href) + '" class="xabia-btn-book" data-id="' + escAttr(pid) + '" target="_blank" rel="noopener">📅 ' + escAttr(xabiaI18n('actionBook', 'Reservar')) + '</a>';
            }
            return '';
        });
        return text;
    }

    function makeContinueButton() {
        return $('<button type="button" class="xabia-continue button button-small"></button>').text(xabiaI18n('continue', 'Continuar'));
    }

    function userInputMaxLines() {
        var n = parseInt(xabiaChatSettings().inputMaxLines, 10);
        return (n > 0 && n <= 20) ? n : 8;
    }

    function userInputMaxChars() {
        var n = parseInt(xabiaChatSettings().inputMaxChars, 10);
        return (n >= 80 && n <= 4000) ? n : 800;
    }

    function countInputLines(text) {
        var val = String(text || '');
        if (val === '') {
            return 1;
        }
        return val.split(/\r\n|\r|\n/).length;
    }

    function clampUserInputText(text) {
        text = String(text || '');
        var maxChars = userInputMaxChars();
        var maxLines = userInputMaxLines();
        if (text.length > maxChars) {
            text = text.substring(0, maxChars);
        }
        var lines = text.split(/\r\n|\r|\n/);
        if (lines.length > maxLines) {
            text = lines.slice(0, maxLines).join('\n');
        }
        return text;
    }

    function getInputLineMetrics(el) {
        var cs = window.getComputedStyle(el);
        var lineHeight = parseFloat(cs.lineHeight);
        if (!lineHeight || isNaN(lineHeight)) {
            lineHeight = (parseFloat(cs.fontSize) || 16) * 1.45;
        }
        var padTop = parseFloat(cs.paddingTop) || 0;
        var padBottom = parseFloat(cs.paddingBottom) || 0;
        var borderTop = parseFloat(cs.borderTopWidth) || 0;
        var borderBottom = parseFloat(cs.borderBottomWidth) || 0;
        return {
            lineHeight: lineHeight,
            verticalPad: padTop + padBottom + borderTop + borderBottom
        };
    }

    function getInputMaxHeightPx($input) {
        var el = $input[0];
        var metrics = getInputLineMetrics(el);
        return Math.ceil(metrics.lineHeight * userInputMaxLines() + metrics.verticalPad);
    }

    function syncInputLimitState($input) {
        var $box = $input.closest('.xabia-chatbox');
        var val = String($input.val() || '');
        var atLimit = countInputLines(val) >= userInputMaxLines() || val.length >= userInputMaxChars();
        $input.toggleClass('xabia-input-at-limit', atLimit);
        if ($box.length) {
            $box.toggleClass('xabia-input-at-limit', atLimit);
        }
        if (atLimit) {
            $input.attr('title', xabiaI18n('inputTooLong', 'Máximo 8 líneas por mensaje.'));
        } else {
            $input.removeAttr('title');
        }
    }

    function enforceInputLimits($input) {
        if (!$input || !$input.length) {
            return '';
        }
        var raw = String($input.val() || '');
        var clamped = clampUserInputText(raw);
        if (clamped !== raw) {
            $input.val(clamped);
        }
        syncInputLimitState($input);
        return clamped;
    }

    function autoSizeInput($input) {
        if (!$input || !$input.length) return;
        var el = $input[0];
        var $box = $input.closest('.xabia-chatbox');
        var metrics = getInputLineMetrics(el);
        var minPx = Math.ceil(metrics.lineHeight + metrics.verticalPad);
        var lineCap = getInputMaxHeightPx($input);

        el.style.maxHeight = lineCap + 'px';
        el.style.height = '0px';
        el.style.overflowY = 'hidden';
        var scrollHeight = el.scrollHeight;
        el.style.height = Math.max(minPx, Math.min(scrollHeight, lineCap)) + 'px';
        el.style.overflowY = 'hidden';

        if ($box.length) {
            if (document.activeElement === el || $box.find('.xabia-mic-listening').length) {
                scrollMessages($box);
            } else {
                scrollMessages($box, { onlyIfNearBottom: true });
            }
        }
    }

    function messagesStream($box) {
        var $stream = $box.find('.xabia-messages-stream');
        return $stream.length ? $stream : $box.find('.xabia-chat-history');
    }

    function textScrollPane($box) {
        var $pane = $box.find('.xabia-text-scroll').first();
        if ($pane.length) {
            return $pane;
        }
        return $box.find('.xabia-chat-messages, .xabia-chat-history').first();
    }

    function isTextScrollNearBottom($pane, threshold) {
        threshold = typeof threshold === 'number' ? threshold : 56;
        var el = $pane && $pane[0];
        if (!el) {
            return true;
        }
        return (el.scrollHeight - el.scrollTop - el.clientHeight) <= threshold;
    }

    function scrollMessages($box, opts) {
        opts = opts || {};
        var $pane = textScrollPane($box);
        if (!$pane.length) {
            return;
        }
        if (opts.onlyIfNearBottom && !isTextScrollNearBottom($pane)) {
            return;
        }
        var el = $pane[0];
        el.scrollTop = el.scrollHeight;
    }

    function chatIsOpenForGreeting($box) {
        return $box.hasClass('is-active')
            || $box.hasClass('xabia-chatbox--fullscreen')
            || $box.hasClass('xabia-chatbox-shortcode-focus');
    }

    function maybeSpeakGreetingOnOpen($box) {
        return;
    }

    function appendPendingVoiceMessage($box, transcript) {
        var text = $.trim(String(transcript || ''));
        if (!text) return;
        var $history = messagesStream($box);
        var $msg = $('<div class="xabia-msg user xabia-msg-pending-voice" data-xabia-pending="1"></div>');
        $msg.append($('<span class="xabia-msg-content"></span>').text(text));
        $history.append($msg);
        scrollMessages($box);
        syncChatUiState($box);
    }

    function countUserMessages($box) {
        return messagesStream($box).find('.xabia-msg.user').length;
    }

    function parseStarterQuestions($box) {
        var raw = $box.attr('data-starter-questions');
        if (!raw) {
            return [];
        }
        try {
            var arr = JSON.parse(raw);
            if (!Array.isArray(arr)) {
                return [];
            }
            return arr.map(function(q) { return String(q || '').trim(); }).filter(Boolean);
        } catch (e0) {
            return [];
        }
    }

    function starterSuggestionsEl($box) {
        return $box.find('.xabia-starter-suggestions');
    }

    function hideStarterSuggestions($box) {
        starterSuggestionsEl($box).attr('hidden', 'hidden').empty();
    }

    function renderStarterSuggestions($box, list) {
        var $el = starterSuggestionsEl($box);
        if (!$el.length) {
            return;
        }
        $el.empty();
        if (!list || !list.length || countUserMessages($box) > 0) {
            $el.attr('hidden', 'hidden');
            return;
        }
        list.forEach(function(q) {
            var text = String(q || '').trim();
            if (!text) {
                return;
            }
            var $btn = $('<button type="button" class="xabia-starter-chip"></button>')
                .attr('data-question', text)
                .text(text);
            $el.append($btn);
        });
        if ($el.children().length) {
            $el.removeAttr('hidden');
        } else {
            $el.attr('hidden', 'hidden');
        }
    }

    function syncStarterSuggestions($box) {
        if (!$box || !$box.length) {
            return;
        }
        if (countUserMessages($box) > 0) {
            hideStarterSuggestions($box);
            return;
        }
        renderStarterSuggestions($box, parseStarterQuestions($box));
    }

    function syncChatUiState($box) {
        if (!$box || !$box.length || !$box.hasClass('xabia-ui-modern')) {
            return;
        }
        var $input = $box.find('.xabia-input-field');
        var textLen = $.trim($input.val()).length;
        var hasConversation = countUserMessages($box) > 0;
        var isEmpty = textLen === 0 && !hasConversation;
        $box.toggleClass('xabia-state-empty', isEmpty);
        $box.toggleClass('xabia-state-typing', !isEmpty);
        var $hero = $box.find('.xabia-voice-hero');
        if ($hero.length) {
            $hero.attr('aria-hidden', isEmpty ? 'false' : 'true');
        }
        syncStarterSuggestions($box);
    }

    function renderBotHtml(raw, imagesBase) {
        if (!raw) return '';
        var text = String(raw);
        /* Desescapar strong ya enviada por el servidor. */
        text = text.replace(/&lt;strong&gt;/gi, '<strong>').replace(/&lt;\/strong&gt;/gi, '</strong>');
        text = text.replace(/&lt;em&gt;/gi, '<em>').replace(/&lt;\/em&gt;/gi, '</em>');
        /* Viñetas markdown en línea ("…: * **Título** … * **Otro**") → una por línea. */
        text = text.replace(/([^\n])\s+\*\s+(?=\*\*)/g, '$1\n* ');
        text = text.replace(/([^\n])\s+[-•]\s+(?=\*\*)/g, '$1\n- ');
        /* Negrita / cursiva markdown. */
        text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/(^|[^\*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>');
        /* Markdown [label](https://…) → ACTION:URL para el botón 🌐. */
        text = text.replace(/\[([^\]]*)\]\((https?:\/\/[^\s\)]+)\)/g, function(_, _label, url) {
            var href = String(url || '').replace(/[.,;)]+$/, '');
            return href ? ('[ACTION:URL:' + href + ']') : _;
        });
        /* Listas "- …" / "* …" en líneas propias → <ul>. */
        text = text.replace(/(?:^|\n)((?:\s*[-*•]\s+.+(?:\n|$))+)/g, function(_, block) {
            var items = String(block).trim().split(/\n/).map(function(line) {
                return line.replace(/^\s*[-*•]\s+/, '').trim();
            }).filter(Boolean);
            if (!items.length) return block;
            return '\n<ul class="xabia-msg-list">' + items.map(function(it) {
                return '<li>' + it + '</li>';
            }).join('') + '</ul>\n';
        });
        /* Saltos de línea restantes (sin romper listas). */
        text = text.replace(/\n/g, '<br>');
        text = text.replace(/(?:<br>\s*){3,}/g, '<br><br>');
        text = text.replace(/<br>\s*(<ul\b)/gi, '$1').replace(/(<\/ul>)\s*<br>/gi, '$1');
        return renderActions(text, imagesBase);
    }

    function chatboxImagesBase($box) {
        if (!$box || !$box.length) return '';
        var base = $box.data('imagesBase');
        if (base === undefined || base === null || String(base) === '') {
            base = $box.attr('data-images-base');
        }
        return base ? String(base).trim() : '';
    }

    function appendBotMessage($box, $history, raw, opts) {
        opts = opts || {};
        var text = String(raw || '');
        var $botDiv = $('<div class="xabia-msg bot"></div>').attr('data-raw', raw);
        var $content = $('<span class="xabia-msg-content"></span>').html(renderBotHtml(text, chatboxImagesBase($box)));
        $botDiv.append($content);
        $history.append($botDiv);
        finishBotMessage($box, $history, $botDiv, raw, opts);
    }

    function finishBotMessage($box, $history, $botDiv, raw, opts) {
        $botDiv.removeClass('xabia-msg-typing');
        if (opts.truncated) {
            $botDiv.append(' ').append(makeContinueButton());
        }
        attachMsgSpeakButton($box, $botDiv, raw);
        speakText($box, raw);
        scrollMessages($box);
        syncChatUiState($box);
    }

    /**
     * Lip-sync: scrub del texto por tiempo (reloj) + recalibración onboundary.
     * Evita quedarse cerrada tras la 1.ª palabra / 1.er speak.
     */
    /* Aperturas más contenidas: menos contraste = menos “pulsos” visuales. */
    var XABIA_VISEMES = {
        a: { y: 0.72, x: 1.1, o: 1 },
        á: { y: 0.72, x: 1.1, o: 1 },
        e: { y: 0.5, x: 1.04, o: 1 },
        é: { y: 0.5, x: 1.04, o: 1 },
        i: { y: 0.34, x: 1.1, o: 1 },
        í: { y: 0.34, x: 1.1, o: 1 },
        y: { y: 0.34, x: 1.08, o: 1 },
        o: { y: 0.58, x: 0.78, o: 1 },
        ó: { y: 0.58, x: 0.78, o: 1 },
        u: { y: 0.36, x: 0.76, o: 1 },
        ú: { y: 0.36, x: 0.76, o: 1 },
        ü: { y: 0.36, x: 0.76, o: 1 },
        w: { y: 0.36, x: 0.76, o: 1 },
        m: { y: 0.18, x: 0.95, o: 0.9 },
        b: { y: 0.18, x: 0.95, o: 0.9 },
        p: { y: 0.18, x: 0.95, o: 0.9 },
        f: { y: 0.28, x: 1.04, o: 1 },
        v: { y: 0.28, x: 1.04, o: 1 },
        _: { y: 0.32, x: 0.98, o: 1 },
        rest: { y: 0.3, x: 0.96, o: 1 },
        silent: { y: 0, x: 0.9, o: 0 }
    };

    var xabiaLipSync = {
        mouths: [],
        $box: null,
        speakText: '',
        rate: 0.92,
        active: false,
        t0: 0,
        keepAlive: 0,
        resumeWatch: 0,
        speakToken: 0,
        htmlAudio: null,
        burstTimers: [],
        utterance: null,
        audioCtx: null,
        analyser: null,
        source: null,
        data: null,
        audioEl: null,
        onAudioPause: null,
        onAudioEnded: null,
        audioRaf: 0,
        /* 0: no adelantar (adelantar hacía que la boca acabara antes que la voz) */
        leadMs: 0,
        endWatch: 0,
        /* Mínimo entre cambios de forma (menos pulsos, misma sincronía gruesa) */
        visemeHoldMs: 85,
        lastVisemeAt: 0,
        lastVisemeKey: ''
    };

    function collectAvatarMouths($box) {
        var list = [];
        var seen = typeof WeakSet === 'function' ? new WeakSet() : null;
        function add(node) {
            if (!node || (seen && seen.has(node))) return;
            if (seen) seen.add(node);
            list.push(node);
        }
        if ($box && $box.length) {
            $box.find('.x-avatar-mouth').each(function() { add(this); });
        }
        try {
            document.querySelectorAll(
                '.xabia-immersive-avatar-stage .x-avatar-mouth, .xabia-interface-trigger .x-avatar-mouth'
            ).forEach(add);
        } catch (eQ) {}
        return list;
    }

    function refreshMouthTargets($box) {
        if ($box && $box.length) {
            xabiaLipSync.$box = $box;
        }
        xabiaLipSync.mouths = collectAvatarMouths(xabiaLipSync.$box);
        return xabiaLipSync.mouths.length > 0;
    }

    function setMouthVisual(mouths, opacity, scaleY, scaleX) {
        var i;
        var sx = (typeof scaleX === 'number') ? scaleX : 1;
        var sy = (typeof scaleY === 'number') ? scaleY : 0;
        for (i = 0; i < mouths.length; i++) {
            mouths[i].style.transition = opacity > 0.05 ? 'none' : 'opacity 0.14s ease';
            mouths[i].style.opacity = String(opacity);
            mouths[i].style.transform = 'scaleX(' + sx + ') scaleY(' + sy + ')';
            if (opacity > 0.05) mouths[i].classList.add('is-speaking');
            else mouths[i].classList.remove('is-speaking');
        }
    }

    function visemeKey(vis) {
        if (!vis) return '';
        return String(vis.y) + ':' + String(vis.x) + ':' + String(vis.o);
    }

    function applyViseme(vis, force) {
        if (!vis) return;
        if (!xabiaLipSync.mouths || !xabiaLipSync.mouths.length) {
            refreshMouthTargets(xabiaLipSync.$box);
        }
        if (!xabiaLipSync.mouths || !xabiaLipSync.mouths.length) return;
        var key = visemeKey(vis);
        var now = performance.now();
        if (!force
            && key === xabiaLipSync.lastVisemeKey
        ) {
            return;
        }
        if (!force
            && xabiaLipSync.lastVisemeKey
            && (now - xabiaLipSync.lastVisemeAt) < (xabiaLipSync.visemeHoldMs || 85)
        ) {
            return;
        }
        xabiaLipSync.lastVisemeKey = key;
        xabiaLipSync.lastVisemeAt = now;
        setMouthVisual(xabiaLipSync.mouths, vis.o, vis.y, vis.x);
    }

    function visemeForChar(ch) {
        if (!ch) return XABIA_VISEMES.rest;
        ch = String(ch).toLowerCase();
        if (/[\s]/.test(ch)) return XABIA_VISEMES.rest;
        if (/[,.;:!?…—–\-¿¡"'«»()\[\]{}]/.test(ch)) return XABIA_VISEMES.rest;
        if (XABIA_VISEMES[ch]) return XABIA_VISEMES[ch];
        if (/[aeiouáéíóúü]/.test(ch)) return XABIA_VISEMES.a;
        return XABIA_VISEMES._;
    }

    function updateMouthShape(ch) {
        applyViseme(visemeForChar(ch));
    }

    function clearBurstTimers() {
        var i;
        for (i = 0; i < xabiaLipSync.burstTimers.length; i++) {
            clearTimeout(xabiaLipSync.burstTimers[i]);
        }
        xabiaLipSync.burstTimers = [];
    }

    function clearKeepAlive() {
        if (xabiaLipSync.keepAlive) {
            clearTimeout(xabiaLipSync.keepAlive);
            xabiaLipSync.keepAlive = 0;
        }
        if (xabiaLipSync.endWatch) {
            clearTimeout(xabiaLipSync.endWatch);
            xabiaLipSync.endWatch = 0;
        }
    }

    /**
     * Ritmo lento a propósito: speechSynthesis en ES ~11–13 chars/s a rate≈1.
     * Valores bajos hacían que el scrub terminara antes y dejara la última postura.
     */
    function msPerChar(rate) {
        var r = rate && rate > 0 ? rate : 1;
        return 98 / r;
    }

    /** Caracter útil cerca del índice estimado (salta espacios hacia vocal). */
    function pickVisemeCharNear(text, idx) {
        if (!text) return 'e';
        var n = text.length;
        var i = Math.max(0, Math.min(n - 1, idx | 0));
        var k;
        for (k = 0; k < 12; k++) {
            if (i + k < n && /[aeiouáéíóúüAEIOUÁÉÍÓÚÜ]/.test(text.charAt(i + k))) {
                return text.charAt(i + k).toLowerCase();
            }
            if (i - k >= 0 && /[aeiouáéíóúüAEIOUÁÉÍÓÚÜ]/.test(text.charAt(i - k))) {
                return text.charAt(i - k).toLowerCase();
            }
        }
        for (k = 0; k < 8; k++) {
            if (i + k < n && /[a-zA-ZáéíóúüñÁÉÍÓÚÜÑ]/.test(text.charAt(i + k))) {
                return text.charAt(i + k).toLowerCase();
            }
        }
        return 'e';
    }

    function isSpeechEngineBusy() {
        try {
            if (!window.speechSynthesis) return false;
            return !!(window.speechSynthesis.speaking || window.speechSynthesis.pending);
        } catch (eBusy) {
            return false;
        }
    }

    function closeMouthNow() {
        refreshMouthTargets(xabiaLipSync.$box);
        xabiaLipSync.lastVisemeKey = '';
        xabiaLipSync.lastVisemeAt = 0;
        setMouthVisual(xabiaLipSync.mouths || [], 0, 0, 0.9);
    }

    function scrubMouthByClock() {
        if (!xabiaLipSync.active) return;
        refreshMouthTargets(xabiaLipSync.$box);

        var engineBusy = isSpeechEngineBusy();
        var elapsed = performance.now() - xabiaLipSync.t0;
        /* Si el motor ya calló y llevamos un rato, cerrar (onend a veces no llega). */
        if (!engineBusy && elapsed > 500) {
            stopAvatarLipSync(xabiaLipSync.$box);
            return;
        }

        var text = xabiaLipSync.speakText || '';
        if (!text) {
            applyViseme(XABIA_VISEMES.rest);
        } else {
            var idx = Math.floor((elapsed + (xabiaLipSync.leadMs || 0)) / msPerChar(xabiaLipSync.rate));
            if (idx < 0) {
                applyViseme(XABIA_VISEMES.rest);
            } else if (idx >= text.length) {
                /* Texto scrub agotado pero la voz sigue: pose neutra, no congelar última vocal */
                applyViseme(XABIA_VISEMES.rest);
            } else {
                updateMouthShape(pickVisemeCharNear(text, idx));
            }
        }
        xabiaLipSync.keepAlive = setTimeout(scrubMouthByClock, 80);
    }

    function startMouthKeepAlive() {
        clearKeepAlive();
        xabiaLipSync.active = true;
        scrubMouthByClock();
    }

    function wordKeyChars(word) {
        var chars = [];
        var i;
        for (i = 0; i < word.length; i++) {
            var c = word.charAt(i).toLowerCase();
            if (/[aeiouáéíóúü]/.test(c)) chars.push(c);
            else if (/[mbpfv]/.test(c)) chars.push(c);
        }
        if (!chars.length && word) chars.push(word.charAt(0).toLowerCase());
        return chars;
    }

    function dominantVisemeChar(chars) {
        var order = ['a', 'á', 'o', 'ó', 'e', 'é', 'u', 'ú', 'ü', 'i', 'í', 'y'];
        var i, j;
        for (i = 0; i < order.length; i++) {
            for (j = 0; j < chars.length; j++) {
                if (chars[j] === order[i]) return chars[j];
            }
        }
        return chars[0] || 'e';
    }

    /** Un solo acento por palabra (sin ráfaga de visemas secundarios). */
    function pulseWordVisemes(word) {
        clearBurstTimers();
        if (!word) return;
        var chars = wordKeyChars(word);
        if (!chars.length) return;
        applyViseme(visemeForChar(dominantVisemeChar(chars)), true);
    }

    function extractWordAt(text, charIndex, charLength) {
        if (!text) return '';
        var idx = Math.max(0, Math.min(text.length - 1, charIndex | 0));
        if (typeof charLength === 'number' && charLength > 0) {
            return text.substr(idx, charLength);
        }
        var end = idx;
        while (end < text.length && !/[\s,.;:!?…]/.test(text.charAt(end))) end++;
        var start = idx;
        while (start > 0 && !/[\s,.;:!?…]/.test(text.charAt(start - 1))) start--;
        return text.substring(start, end);
    }

    function disconnectLipSyncAudio() {
        if (xabiaLipSync.audioRaf) {
            cancelAnimationFrame(xabiaLipSync.audioRaf);
            xabiaLipSync.audioRaf = 0;
        }
        if (xabiaLipSync.audioEl) {
            if (xabiaLipSync.onAudioPause) xabiaLipSync.audioEl.removeEventListener('pause', xabiaLipSync.onAudioPause);
            if (xabiaLipSync.onAudioEnded) xabiaLipSync.audioEl.removeEventListener('ended', xabiaLipSync.onAudioEnded);
        }
        xabiaLipSync.audioEl = null;
        xabiaLipSync.onAudioPause = null;
        xabiaLipSync.onAudioEnded = null;
        try { if (xabiaLipSync.source) xabiaLipSync.source.disconnect(); } catch (eDisc) {}
        xabiaLipSync.source = null;
        xabiaLipSync.analyser = null;
        xabiaLipSync.data = null;
    }

    function syncMuteSpeakingUi($box, on) {
        if (!$box || !$box.length) {
            return;
        }
        $box.find('.xabia-mute.is-voice-on').toggleClass('xabia-mute-speaking', !!on);
    }

    function stopAvatarLipSync($box) {
        xabiaLipSync.active = false;
        clearKeepAlive();
        clearBurstTimers();
        clearSpeechResumeWatch();
        disconnectLipSyncAudio();
        try {
            if (xabiaLipSync.htmlAudio) {
                xabiaLipSync.htmlAudio.pause();
                xabiaLipSync.htmlAudio.removeAttribute('src');
                try { xabiaLipSync.htmlAudio.load(); } catch (eLoad) {}
                xabiaLipSync.htmlAudio = null;
            }
        } catch (eHa) {}
        if ($box && $box.length) xabiaLipSync.$box = $box;
        closeMouthNow();
        xabiaLipSync.mouths = [];
        xabiaLipSync.speakText = '';
        xabiaLipSync.utterance = null;
        if ($box && $box.length) $box.removeClass('xabia-is-speaking');
        syncMuteSpeakingUi($box, false);
        try { if ($box && $box.length) $box.trigger('xabia:bot-speaking-stop'); } catch (eSpeakStop) {}
        try { document.body.classList.remove('xabia-avatar-speaking'); } catch (eBody) {}
    }

    window.xabiaStopAvatarLipSync = stopAvatarLipSync;

    function tickAudioVisemes() {
        if (!xabiaLipSync.analyser || !xabiaLipSync.data) {
            xabiaLipSync.audioRaf = 0;
            return;
        }
        refreshMouthTargets(xabiaLipSync.$box);
        xabiaLipSync.analyser.getByteTimeDomainData(xabiaLipSync.data);
        var sum = 0, i;
        for (i = 0; i < xabiaLipSync.data.length; i++) {
            var v = (xabiaLipSync.data[i] - 128) / 128;
            sum += v * v;
        }
        /* Ganancia más baja + menos umbrales = menos aperturas bruscas */
        var amp = Math.min(1, Math.sqrt(sum / xabiaLipSync.data.length) * 3.1);
        if (amp < 0.08) applyViseme(XABIA_VISEMES.rest);
        else if (amp < 0.28) applyViseme(XABIA_VISEMES.e);
        else applyViseme(XABIA_VISEMES.a);
        xabiaLipSync.audioRaf = requestAnimationFrame(tickAudioVisemes);
    }

    function startAvatarLipSync($box, audioEl, opts) {
        opts = opts || {};
        /* No llamar stop completo: evita carrera al re-speak; limpia timers y reabre */
        xabiaLipSync.active = false;
        clearKeepAlive();
        clearBurstTimers();
        disconnectLipSyncAudio();

        xabiaLipSync.$box = $box;
        if (!refreshMouthTargets($box)) return;

        xabiaLipSync.speakText = opts.text || '';
        xabiaLipSync.rate = opts.rate || 0.92;
        xabiaLipSync.t0 = performance.now();
        xabiaLipSync.lastVisemeAt = 0;
        xabiaLipSync.lastVisemeKey = '';
        if ($box && $box.length) $box.addClass('xabia-is-speaking');
        syncMuteSpeakingUi($box, true);
        try { if ($box && $box.length) $box.trigger('xabia:bot-speaking-start'); } catch (eSpeakStart) {}
        try { document.body.classList.add('xabia-avatar-speaking'); } catch (eBody2) {}

        if (audioEl && typeof window.AudioContext !== 'undefined') {
            try {
                if (!xabiaLipSync.audioCtx) {
                    xabiaLipSync.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                }
                if (xabiaLipSync.audioCtx.state === 'suspended') xabiaLipSync.audioCtx.resume();
                xabiaLipSync.analyser = xabiaLipSync.audioCtx.createAnalyser();
                xabiaLipSync.analyser.fftSize = 256;
                xabiaLipSync.data = new Uint8Array(xabiaLipSync.analyser.frequencyBinCount);
                xabiaLipSync.source = xabiaLipSync.audioCtx.createMediaElementSource(audioEl);
                xabiaLipSync.source.connect(xabiaLipSync.analyser);
                xabiaLipSync.analyser.connect(xabiaLipSync.audioCtx.destination);
                xabiaLipSync.audioEl = audioEl;
                xabiaLipSync.onAudioPause = function() { stopAvatarLipSync($box); };
                xabiaLipSync.onAudioEnded = function() { stopAvatarLipSync($box); };
                audioEl.addEventListener('pause', xabiaLipSync.onAudioPause);
                audioEl.addEventListener('ended', xabiaLipSync.onAudioEnded);
                xabiaLipSync.active = true;
                xabiaLipSync.audioRaf = requestAnimationFrame(tickAudioVisemes);
            } catch (eAudio) {
                disconnectLipSyncAudio();
            }
            return;
        }

        applyViseme(XABIA_VISEMES.rest);
        startMouthKeepAlive();
        if (opts.firstWord) {
            pulseWordVisemes(opts.firstWord);
        }
    }

    function onSpeechBoundary($box, ev) {
        if (ev && ev.name && ev.name !== 'word' && ev.name !== 'sentence') return;
        refreshMouthTargets($box || xabiaLipSync.$box);
        if (!xabiaLipSync.active) {
            xabiaLipSync.active = true;
            if (!xabiaLipSync.keepAlive) startMouthKeepAlive();
        }
        var text = xabiaLipSync.speakText;
        var idx = (ev && typeof ev.charIndex === 'number') ? ev.charIndex : 0;
        var len = (ev && typeof ev.charLength === 'number') ? ev.charLength : 0;
        /* Recalibrar reloj al índice real de la voz */
        xabiaLipSync.t0 = performance.now() - (idx * msPerChar(xabiaLipSync.rate));
        var word = extractWordAt(text, idx, len);
        if (word) pulseWordVisemes(word);
        else updateMouthShape(pickVisemeCharNear(text, idx));
    }

    function stopAllSpeech($box) {
        xabiaLipSync.speakToken += 1;
        clearSpeechResumeWatch();
        try {
            if (xabiaLipSync.htmlAudio) {
                xabiaLipSync.htmlAudio.pause();
                xabiaLipSync.htmlAudio.removeAttribute('src');
                try { xabiaLipSync.htmlAudio.load(); } catch (eLoad) {}
                xabiaLipSync.htmlAudio = null;
            }
        } catch (eHa) {}
        try { if (window.speechSynthesis) window.speechSynthesis.cancel(); } catch (eC) {}
        stopAvatarLipSync($box);
    }

    function speakText($box, rawText, audioEl, opts) {
        opts = opts || {};
        if (audioEl && audioEl.tagName === 'AUDIO') {
            if (!(opts && opts.force) && !isVoiceOn($box)) return;
            startAvatarLipSync($box, audioEl, { text: cleanTextForTts(rawText || '', parseTtsConfig($box).clean) });
            try { if (audioEl.paused) audioEl.play(); } catch (ePlay) {}
            return;
        }
        if (!rawText) return;
        if (!(opts && opts.force) && !isVoiceOn($box)) return;
        var tts = parseTtsConfig($box);
        var text = cleanTextForTts(rawText, tts.clean);
        if (!text) return;

        var token = ++xabiaLipSync.speakToken;
        clearSpeechResumeWatch();
        stopAvatarLipSync($box);
        try {
            if (xabiaLipSync.htmlAudio) {
                xabiaLipSync.htmlAudio.pause();
                xabiaLipSync.htmlAudio.removeAttribute('src');
                xabiaLipSync.htmlAudio.load();
                xabiaLipSync.htmlAudio = null;
            }
        } catch (eHa) {}
        try { if (window.speechSynthesis) window.speechSynthesis.cancel(); } catch (eC) {}

        /* Audio servidor (HTML5): speechSynthesis en Chrome/macOS a menudo queda mudo. */
        fetchServerTtsAndPlay($box, text, tts, token, opts);
    }

    function fetchServerTtsAndPlay($box, text, tts, token, opts) {
        var endpoint = $box.data('endpoint') || (typeof window.ajaxurl === 'string' ? window.ajaxurl : '/wp-admin/admin-ajax.php');
        var rate = parseFloat(tts.rate);
        if (!rate || rate === 1) rate = 0.92;
        rate = Math.max(0.5, Math.min(2, rate));
        var payload = {
            action: 'xabia_tts',
            project_id: $box.data('project') || '',
            text: text,
            lang: String($box.data('lang') || 'es'),
            voice: tts.voice || 'default',
            rate: rate
        };
        $.post(endpoint, payload, null, 'json').done(function(r) {
            if (token !== xabiaLipSync.speakToken) return;
            if (!(opts && opts.force) && !isVoiceOn($box)) return;
            if (r && r.success && r.data && r.data.base64 && r.data.mime) {
                playServerTtsAudio($box, text, rate, String(r.data.mime), String(r.data.base64), token);
                return;
            }
            speakTextBrowserFallback($box, text, tts, token, opts);
        }).fail(function() {
            if (token !== xabiaLipSync.speakToken) return;
            speakTextBrowserFallback($box, text, tts, token, opts);
        });
    }

    function playServerTtsAudio($box, text, rate, mime, base64, token) {
        if (token !== xabiaLipSync.speakToken) return;
        var audio = new Audio();
        audio.preload = 'auto';
        audio.src = 'data:' + mime + ';base64,' + base64;
        audio.volume = 1;
        xabiaLipSync.htmlAudio = audio;
        var firstWord = (text.match(/[a-zA-ZáéíóúüñÁÉÍÓÚÜÑ]+/) || [''])[0];

        audio.onplay = function() {
            if (token !== xabiaLipSync.speakToken) return;
            startAvatarLipSync($box, audio, {
                text: text,
                rate: rate,
                firstWord: firstWord
            });
        };
        audio.onended = function() {
            if (token !== xabiaLipSync.speakToken) return;
            stopAvatarLipSync($box);
            closeMouthNow();
        };
        audio.onerror = function() {
            if (token !== xabiaLipSync.speakToken) return;
            if (!isVoiceOn($box)) return;
            speakTextBrowserFallback($box, text, parseTtsConfig($box), token, opts || {});
        };
        var p = audio.play();
        if (p && typeof p.catch === 'function') {
            p.catch(function() {
                if (token !== xabiaLipSync.speakToken) return;
                if (!isVoiceOn($box)) return;
                speakTextBrowserFallback($box, text, parseTtsConfig($box), token, opts || {});
            });
        }
    }

    function speakTextBrowserFallback($box, text, tts, token, opts) {
        opts = opts || {};
        if (!window.speechSynthesis) return;
        if (token !== xabiaLipSync.speakToken) return;
        if (!(opts && opts.force) && !isVoiceOn($box)) return;

        var firstWord = (text.match(/[a-zA-ZáéíóúüñÁÉÍÓÚÜÑ]+/) || [''])[0];
        var rate = parseFloat(tts.rate);
        if (!rate || rate === 1) rate = 0.92;
        rate = Math.max(0.5, Math.min(2, rate));
        var attempt = 0;

        function doSpeak() {
            if (token !== xabiaLipSync.speakToken) return;
            if (!(opts && opts.force) && !isVoiceOn($box)) return;
            attempt += 1;
            try { window.speechSynthesis.resume(); } catch (eRes) {}
            var u = new window.SpeechSynthesisUtterance(text);
            xabiaLipSync.utterance = u;
            u.lang = xabiaBcp47FromLang($box.data('lang'));
            u.rate = rate;
            u.pitch = 1;
            u.volume = 1;
            if (attempt === 1) {
                var chosen = pickVoice(u.lang, tts.voice);
                if (chosen) {
                    u.voice = chosen;
                    if (chosen.lang) u.lang = chosen.lang;
                }
            }
            var started = false;
            function beginMouth() {
                if (started) return;
                started = true;
                startAvatarLipSync($box, null, { text: text, rate: u.rate, firstWord: firstWord });
                startSpeechResumeWatch();
            }
            u.onstart = beginMouth;
            u.onboundary = function(ev) { onSpeechBoundary($box, ev); };
            u.onend = function() {
                if (token !== xabiaLipSync.speakToken) return;
                clearSpeechResumeWatch();
                stopAvatarLipSync($box);
                closeMouthNow();
            };
            u.onerror = function(ev) {
                if (token !== xabiaLipSync.speakToken) return;
                var err = (ev && ev.error) ? String(ev.error) : '';
                if (err === 'interrupted' || err === 'canceled' || err === 'cancelled') return;
                clearSpeechResumeWatch();
                stopAvatarLipSync($box);
                closeMouthNow();
                if (attempt < 2) window.setTimeout(doSpeak, 180);
            };
            try { window.speechSynthesis.speak(u); } catch (eSpeak) {
                if (attempt < 2) window.setTimeout(doSpeak, 180);
            }
        }
        try { window.speechSynthesis.cancel(); } catch (eCancel) {}
        window.setTimeout(doSpeak, (opts && opts.immediate) ? 0 : 80);
    }

    function setAvatarThinking($box, thinking) {
        var on = !!thinking;
        if ($box && $box.length) {
            $box.toggleClass('xabia-is-thinking', on);
            $box.find('.xabia-immersive-avatar-stage').toggleClass('xabia-avatar-thinking', on);
        }
        try {
            document.body.classList.toggle('xabia-avatar-thinking', on);
            document.querySelectorAll('.xabia-sticky-footer-box, .xabia-interface-trigger').forEach(function(el) {
                el.classList.toggle('xabia-avatar-thinking', on);
            });
        } catch (eThink) {}
        if (on) {
            /* No mezclar lip-sync con pensando */
            xabiaLipSync.speakToken += 1;
            clearSpeechResumeWatch();
            try { if (window.speechSynthesis) window.speechSynthesis.cancel(); } catch (eC) {}
            stopAvatarLipSync($box);
        }
    }

    function setMicListeningUi($box, listening) {
        var on = !!listening;
        var labelOn = xabiaI18n('micListening', 'Escuchando… Habla ahora');
        var labelOff = xabiaI18n('Toca para hablar o mantén pulsado', 'Toca para hablar o mantén pulsado');
        if ($box && $box.length) {
            $box.toggleClass('xabia-mic-is-listening', on);
            $box.find('.xabia-mic').toggleClass('xabia-mic-listening', on)
                .attr('aria-pressed', on ? 'true' : 'false')
                .attr('aria-label', on ? labelOn : labelOff)
                .attr('title', on ? labelOn : labelOff);
        }
        try {
            document.body.classList.toggle('xabia-mic-listening', on);
        } catch (eMicBody) {}
    }

    function normalizePromptForMatch(text) {
        var s = String(text || '').toLowerCase();
        try {
            if (typeof s.normalize === 'function') {
                s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            }
        } catch (eNorm) {}
        return s.replace(/\s+/g, ' ').trim();
    }

    function getWaitingMessage(userPrompt) {
        var text = normalizePromptForMatch(userPrompt);
        if (!text) {
            return xabiaI18n('waitingGeneric', 'Entendido, buscando la mejor solución...');
        }
        if (/\b(reserva|cita|hotel|disponibilidad|habitacion|habitaciones|booking|appointment|alojamiento)\b/.test(text)) {
            return xabiaI18n('waitingBooking', 'Un momento... buscando la mejor opción de reserva');
        }
        if (/\b(comprar|precio|producto|tienda|carrito|catalogo|shop|buy|cart|pedido)\b/.test(text)) {
            return xabiaI18n('waitingShop', 'Buscando el producto más adecuado...');
        }
        if (/\b(donde|llegar|mapa|ubicacion|direccion|localizacion|how to get|location|address|directions)\b/.test(text)) {
            return xabiaI18n('waitingLocation', 'Localizando la información de ubicación...');
        }
        return xabiaI18n('waitingGeneric', 'Entendido, buscando la mejor solución...');
    }

    function clearWaitingTimers($box) {
        if (!$box || !$box.length) {
            return;
        }
        var state = $box.data('xabiaWaitingState');
        if (state && state.timers && state.timers.length) {
            state.timers.forEach(function(tid) {
                clearTimeout(tid);
            });
        }
        $box.removeData('xabiaWaitingState');
    }

    function setWaitingMessageText($msgEl, text) {
        if (!$msgEl || !$msgEl.length) {
            return;
        }
        $msgEl.removeClass('xabia-waiting-message--swap');
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(function() {
                $msgEl.text(text).addClass('xabia-waiting-message--swap');
            });
        } else {
            $msgEl.text(text).addClass('xabia-waiting-message--swap');
        }
    }

    function showTyping($box, userPrompt) {
        if (!$box || !$box.length) {
            return;
        }
        clearWaitingTimers($box);

        var $dots = $box.find('.xabia-compose-area .xabia-typing-dots');
        if (!$dots.length) {
            $dots = $box.find('.xabia-typing-dots');
        }
        var $msgEl = $dots.find('.xabia-waiting-message');
        if (!$msgEl.length) {
            $msgEl = $('<span class="xabia-waiting-message" aria-live="polite"></span>');
            $dots.append($msgEl);
        }

        setWaitingMessageText($msgEl, getWaitingMessage(userPrompt));
        $box.addClass('xabia-is-waiting');
        $dots.attr('aria-hidden', 'false').css('display', '');
        setAvatarThinking($box, true);

        var timers = [];
        timers.push(setTimeout(function() {
            setWaitingMessageText($msgEl, xabiaI18n('waitingAnalyzing', 'Sigo analizando la información...'));
        }, 3500));
        timers.push(setTimeout(function() {
            setWaitingMessageText($msgEl, xabiaI18n('waitingAlmost', 'Un segundo más, preparando la respuesta...'));
        }, 7000));
        $box.data('xabiaWaitingState', { timers: timers, $message: $msgEl });
    }

    function hideTyping($box) {
        if (!$box || !$box.length) {
            return;
        }
        clearWaitingTimers($box);
        var $dots = $box.find('.xabia-compose-area .xabia-typing-dots');
        if (!$dots.length) {
            $dots = $box.find('.xabia-typing-dots');
        }
        $box.removeClass('xabia-is-waiting');
        $dots.attr('aria-hidden', 'true').css('display', 'none');
        $dots.find('.xabia-waiting-message').text('').removeClass('xabia-waiting-message--swap');
        setAvatarThinking($box, false);
    }

    function isShortcodeFocusBox($box) {
        return $box
            && $box.length
            && $box.hasClass('xabia-chatbot')
            && !$box.hasClass('xabia-panel-shell')
            && !$box.hasClass('xabia-chatbox--fullscreen');
    }

    function ensureShortcodeFocusOverlay($) {
        var $overlay = $('#xabia-shortcode-focus-overlay');
        if ($overlay.length) {
            return $overlay;
        }
        $overlay = $('<button type="button" id="xabia-shortcode-focus-overlay" class="xabia-shortcode-focus-overlay"></button>').attr('aria-label', xabiaI18n('closeChat', 'Cerrar chat'));
        $('body').append($overlay);
        return $overlay;
    }

    function storeShortcodeFocusAnchor($box) {
        if ($box.data('xabiaShortcodeFocusAnchor')) {
            return;
        }
        var el = $box[0];
        if (!el || !el.parentNode) {
            return;
        }
        $box.data('xabiaShortcodeFocusAnchor', {
            parent: el.parentNode,
            next: el.nextSibling
        });
    }

    function portalShortcodeFocusBox($box) {
        storeShortcodeFocusAnchor($box);
        var el = $box[0];
        if (el && el.parentNode !== document.body) {
            document.body.appendChild(el);
        }
    }

    function restoreShortcodeFocusBox($box) {
        if (!$box || !$box.length) {
            return;
        }
        var anchor = $box.data('xabiaShortcodeFocusAnchor');
        if (!anchor || !anchor.parent) {
            return;
        }
        var el = $box[0];
        if (anchor.next && anchor.next.parentNode === anchor.parent) {
            anchor.parent.insertBefore(el, anchor.next);
        } else {
            anchor.parent.appendChild(el);
        }
        $box.removeData('xabiaShortcodeFocusAnchor');
    }

    function boxHasSpeakingAvatar($box) {
        if (!$box || !$box.length) return false;
        return String($box.attr('data-speaking-avatar') || '1') !== '0';
    }

    function ensureImmersiveKinetic($box) {
        if (!boxHasSpeakingAvatar($box)) return;
        if (!$box || !$box.length) return;
        var wrap = $box.find('.xabia-immersive-avatar-stage .xabia-kinetic-wrapper').get(0);
        if (!wrap) return;
        function run() {
            if (typeof window.xabiaInitKineticAvatar === 'function') {
                window.xabiaInitKineticAvatar(wrap);
            }
        }
        if (window.gsap) {
            run();
            return;
        }
        var url = (window.XabiaInterface && window.XabiaInterface.gsapUrl)
            ? window.XabiaInterface.gsapUrl
            : 'https://cdn.jsdelivr.net/npm/gsap@3/dist/gsap.min.js';
        if (document.querySelector('script[data-xabia-gsap="1"]')) {
            var tries = 0;
            var wait = setInterval(function() {
                tries += 1;
                if (window.gsap || tries > 40) {
                    clearInterval(wait);
                    run();
                }
            }, 50);
            return;
        }
        var s = document.createElement('script');
        s.src = url;
        s.async = true;
        s.dataset.xabiaGsap = '1';
        s.onload = run;
        s.onerror = run;
        document.head.appendChild(s);
    }

    function openShortcodeFocus($, $box) {
        if (!isShortcodeFocusBox($box)) {
            return;
        }
        var speaking = boxHasSpeakingAvatar($box);
        $('.xabia-chatbox-shortcode-focus').not($box).each(function() {
            $(this).removeClass('xabia-chatbox-shortcode-focus xabia-immersive-mode');
        });
        var isMobile = window.matchMedia && window.matchMedia('(max-width: 768px)').matches;
        portalShortcodeFocusBox($box);
        if (!isMobile) {
            ensureShortcodeFocusOverlay($).attr('data-project', String($box.data('project') || '')).addClass('is-active');
        }
        $('body').addClass('xabia-shortcode-focus-open');
        if (speaking) {
            $('body').addClass('xabia-immersive-open');
        }
        $box.addClass('xabia-chatbox-shortcode-focus');
        if (speaking) {
            $box.addClass('xabia-immersive-mode');
            $box.find('.xabia-immersive-avatar-stage').attr('aria-hidden', 'false');
            ensureImmersiveKinetic($box);
        }
        if (!isMobile) {
            try {
                $box[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            } catch (eScroll) {}
        }
    }

    function closeShortcodeFocus($) {
        var $box = $('.xabia-chatbox-shortcode-focus').first();
        if ($box.length) {
            stopAvatarLipSync($box);
        }
        $('#xabia-shortcode-focus-overlay').removeClass('is-active');
        $('body').removeClass('xabia-shortcode-focus-open xabia-immersive-open');
        $('.xabia-chatbox-shortcode-focus').removeClass('xabia-chatbox-shortcode-focus xabia-immersive-mode');
        if ($box.length) {
            $box.find('.xabia-immersive-avatar-stage').attr('aria-hidden', 'true');
        }
        restoreShortcodeFocusBox($box);
        if ($box.length) {
            try {
                $box.find('.xabia-input-field').trigger('blur');
            } catch (eBlur) {}
        }
    }

        var xabiaHandlersBound = false;

        function bindXabiaChatboxHandlers() {
            if (xabiaHandlersBound) {
                return;
            }
            xabiaHandlersBound = true;

            var urlParams = null;
            try {
                urlParams = new URLSearchParams(window.location.search);
            } catch (eP) {
                urlParams = null;
            }

            function xabiaApplyQueryTunnelToBox($b) {
                if (!urlParams || !$b || !$b.length) return;
                var xp = urlParams.get('x_project');
                var eid = urlParams.get('ente_id');
                if (xp) {
                    $b.attr('data-project', xp);
                }
                if (eid) {
                    $b.attr('data-ente-id', eid);
                    $b.attr('data-strict-mode', '1');
                    $b.attr('data-scope', eid);
                }
                if (window.XabiaSettings && typeof window.XabiaSettings === 'object') {
                    if (xp) {
                        window.XabiaSettings.projectId = xp;
                        window.XabiaSettings.xProject = xp;
                    }
                    if (eid) {
                        window.XabiaSettings.enteId = eid;
                        window.XabiaSettings.strictMode = true;
                        window.XabiaSettings.scope = eid;
                    }
                }
            }

            var lang = xabiaBcp47FromLang($('.xabia-chatbox').first().data('lang'));
        var secure = window.isSecureContext;

        function micInsecureHelpMessage() {
            var host = '';
            try {
                host = String(window.location.hostname || '');
            } catch (eH) {}
            var isLocalHost = host === 'localhost' || host === '127.0.0.1' || /\.local$/i.test(host);
            if (isLocalHost && host) {
                return xabiaI18n(
                    'micHttpsLocal',
                    'El micrófono necesita HTTPS. En Local: activa SSL (botón Trust) y abre https://' + host + '/'
                );
            }
            return xabiaI18n('micHttps', 'El micrófono requiere HTTPS.');
        }

        /* [xabia_agent] embebido: no expandir a inmersivo al enfocar el textarea (evita launcher a pantalla completa al escribir). */

        $(document).on('click', '#xabia-shortcode-focus-overlay', function(e) {
            e.preventDefault();
            closeShortcodeFocus($);
        });

        $(document).on('click', '.xabia-chatbox.xabia-immersive-mode .xabia-immersive-avatar-stage', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var $box = $(this).closest('.xabia-chatbox');
            if ($box.hasClass('xabia-chatbox-shortcode-focus')) {
                closeShortcodeFocus($);
                return;
            }
            /* Panel nativo: dispara el cierre del botón minimizar */
            $box.find('.xabia-panel-close').first().trigger('click');
        });

        $(document).on('click', '.xabia-chatbox-shortcode-focus .xabia-panel-close', function(e) {
            e.preventDefault();
            e.stopPropagation();
            closeShortcodeFocus($);
        });

        $(document).on('keydown', function(e) {
            if (e.key !== 'Escape' && e.keyCode !== 27) {
                return;
            }
            if ($('.xabia-chatbox-shortcode-focus').length) {
                e.preventDefault();
                closeShortcodeFocus($);
                return;
            }
            var $open = $('.xabia-chatbox.xabia-panel-shell.is-active, .xabia-chatbox.xabia-immersive-mode.is-active').first();
            if ($open.length) {
                e.preventDefault();
                stopAvatarLipSync($open);
                $open.find('.xabia-panel-close').first().trigger('click');
            }
        });

        $(document).on('click', '.xabia-mute', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var $box = $(this).closest('.xabia-chatbox');
            if (!$box.length) return;
            /* Evita doble toggle (proxy del panel + varios triggers) en el mismo gesto */
            var now = Date.now();
            var lastToggle = parseInt($box.data('xabiaMuteToggleAt') || '0', 10) || 0;
            if (now - lastToggle < 350) {
                return;
            }
            $box.data('xabiaMuteToggleAt', now);

            var turningOff = isVoiceOn($box);
            if (turningOff) {
                stopAllSpeech($box);
                setVoiceOn($box, false);
            } else {
                setVoiceOn($box, true);
                preloadSpeechVoices();
                /* Pitido: confirma que el navegador tiene salida de audio */
                unlockSpeechSynthesis({ beep: true });
                var last = lastBotRawText($box);
                if (last) {
                    var $lastBot = $box.find('.xabia-msg.bot').last();
                    attachMsgSpeakButton($box, $lastBot, last);
                    window.setTimeout(function() {
                        if (!isVoiceOn($box)) return;
                        speakText($box, last, null, { immediate: true, force: true });
                    }, 220);
                }
            }
        });

        var micSessions = new WeakMap();
        var MIC_SILENCE_MS = 2600;
        var MIC_START_GRACE_MS = 4800;
        var MIC_MAX_RESTARTS = 8;

        function clearMicTimers(session) {
            if (!session) return;
            if (session.holdTimer) {
                window.clearTimeout(session.holdTimer);
                session.holdTimer = 0;
            }
            if (session.silenceTimer) {
                window.clearTimeout(session.silenceTimer);
                session.silenceTimer = 0;
            }
        }

        function mergeSpeechFinals(finals) {
            if (!finals || !finals.length) {
                return '';
            }
            var merged = $.trim(String(finals[0] || ''));
            for (var i = 1; i < finals.length; i++) {
                var cur = $.trim(String(finals[i] || ''));
                if (!cur) {
                    continue;
                }
                if (!merged) {
                    merged = cur;
                    continue;
                }
                var mergedLow = merged.toLowerCase();
                var curLow = cur.toLowerCase();
                if (curLow.indexOf(mergedLow) === 0) {
                    merged = cur;
                    continue;
                }
                if (mergedLow.indexOf(curLow) === 0) {
                    continue;
                }
                merged = $.trim((merged + ' ' + cur).replace(/\s+/g, ' '));
            }
            return merged;
        }

        function syncMicResults(session, results) {
            var finals = [];
            var interim = '';
            if (!results || !results.length) {
                session.transcript = $.trim(String(session.committedTranscript || ''));
                return '';
            }
            for (var i = 0; i < results.length; i++) {
                var piece = results[i][0] ? String(results[i][0].transcript || '') : '';
                piece = $.trim(piece);
                if (!piece) {
                    continue;
                }
                if (results[i].isFinal) {
                    finals.push(piece);
                } else {
                    interim = piece;
                }
            }
            var runText = mergeSpeechFinals(finals);
            var committed = $.trim(String(session.committedTranscript || ''));
            session.transcript = $.trim((committed + (committed && runText ? ' ' : '') + runText).replace(/\s+/g, ' '));
            if (interim) {
                var previewLow = interim.toLowerCase();
                var transcriptLow = session.transcript.toLowerCase();
                if (transcriptLow && previewLow.indexOf(transcriptLow) === 0) {
                    return interim;
                }
            }
            return interim;
        }

        function micDisplayText(session, interim) {
            var text = $.trim(String(session.transcript || ''));
            interim = $.trim(String(interim || ''));
            if (interim) {
                var textLow = text.toLowerCase();
                var interimLow = interim.toLowerCase();
                if (!text || interimLow.indexOf(textLow) === 0) {
                    text = interim;
                } else if (textLow.indexOf(interimLow) !== 0) {
                    text = $.trim((text + ' ' + interim).replace(/\s+/g, ' '));
                }
            }
            // Solo la locución actual: no concatenar borrador previo del textarea.
            return text;
        }

        function micInputPreview($box, session, interim) {
            // No volcar el dictado al textarea mientras escucha: en móvil hincha el composer
            // y deja texto residual que se acumula en la siguiente locución.
            session.previewText = micDisplayText(session, interim);
        }

        function scheduleMicSilenceStop($box, session, opts) {
            opts = opts || {};
            if (!session || session.released) {
                return;
            }
            if (session.silenceTimer) {
                window.clearTimeout(session.silenceTimer);
            }
            var delay = session.hasHeardSpeech ? MIC_SILENCE_MS : MIC_START_GRACE_MS;
            if (opts.forceGrace) {
                delay = MIC_START_GRACE_MS;
            }
            session.silenceTimer = window.setTimeout(function() {
                if (micSessions.get($box[0]) !== session || session.released) {
                    return;
                }
                stopMicSession($box);
            }, delay);
        }

        function finalizeMicSession($box, session) {
            clearMicTimers(session);
            setMicListeningUi($box, false);
            micSessions.delete($box[0]);
            if (!session.released) {
                return;
            }
            var $input = $box.find('.xabia-input-field');
            var newPart = $.trim(String(session.transcript || session.previewText || ''));
            var draft = $.trim(String(session.baseInput || ''));
            if (!newPart) {
                $input.val(draft);
                autoSizeInput($input);
                syncInputLimitState($input);
                syncChatUiState($box);
                return;
            }
            // Enviar solo la locución (sin acumular borrador tipado) y limpiar el campo.
            $input.val(clampUserInputText(newPart));
            submitChatMessage($box);
            if (draft) {
                $input.val(draft);
                autoSizeInput($input);
                syncInputLimitState($input);
                syncChatUiState($box);
            }
        }

        function stopMicSession($box) {
            var session = micSessions.get($box[0]);
            if (!session) {
                return;
            }
            session.holding = false;
            session.released = true;
            clearMicTimers(session);
            try {
                session.rec.stop();
            } catch (eStop) {
                finalizeMicSession($box, session);
            }
        }

        function startMicSession($mic) {
            var $box = $mic.closest('.xabia-chatbox');
            var $input = $box.find('.xabia-input-field');
            if (!$box.length) {
                return;
            }
            if ($box.hasClass('xabia-is-speaking') || document.body.classList.contains('xabia-avatar-speaking')) {
                messagesStream($box).append(
                    $('<div class="xabia-msg bot" style="color:#b91c1c"></div>').text(
                        xabiaI18n('micBlockedWhileBotSpeaks', 'Espera a que termine de hablar y vuelve a pulsar el micrófono.')
                    )
                );
                scrollMessages($box);
                return;
            }
            if (!secure) {
                messagesStream($box).append($('<div class="xabia-msg bot" style="color:orange"></div>').text(micInsecureHelpMessage()));
                scrollMessages($box);
                return;
            }
            if (!SpeechRecognition) {
                messagesStream($box).append($('<div class="xabia-msg bot" style="color:orange"></div>').text(xabiaI18n('micUnsupported', 'Tu navegador no soporta voz. Prueba Chrome o Edge.')));
                scrollMessages($box);
                return;
            }
            if (micSessions.get($box[0])) {
                stopMicSession($box);
                return;
            }

            var rec = new SpeechRecognition();
            var typedDraft = $.trim(String($input.val() || ''));
            var session = {
                rec: rec,
                transcript: '',
                committedTranscript: '',
                previewText: '',
                // Conservar borrador tipado aparte; la locución no se acumula sobre él.
                baseInput: typedDraft,
                holding: true,
                released: false,
                restarting: false,
                restarts: 0,
                silenceTimer: 0,
                hasHeardSpeech: false,
                startedAt: Date.now(),
            };
            micSessions.set($box[0], session);
            // Durante la escucha el textarea no muestra el dictado (evita ocupación vertical).
            $input.val('');
            autoSizeInput($input);
            syncChatUiState($box);

            rec.continuous = true;
            rec.interimResults = true;
            rec.lang = xabiaBcp47FromLang($box.data('lang')) || lang;
            stopAllSpeech($box);

            rec.onstart = function() {
                setMicListeningUi($box, true);
            };
            rec.onresult = function(ev) {
                var interim = syncMicResults(session, ev.results);
                if (interim || $.trim(String(session.transcript || ''))) {
                    session.hasHeardSpeech = true;
                }
                micInputPreview($box, session, interim);
                scheduleMicSilenceStop($box, session);
            };
            rec.onerror = function() {
                if (!session.released) {
                    finalizeMicSession($box, session);
                }
            };
            rec.onend = function() {
                session.restarting = false;
                if (!session.released && session.holding) {
                    var elapsed = Date.now() - (session.startedAt || Date.now());
                    var inGrace = !session.hasHeardSpeech && elapsed < MIC_START_GRACE_MS;
                    var canRestart = session.restarts < MIC_MAX_RESTARTS
                        && (session.hasHeardSpeech || inGrace);
                    if (canRestart) {
                        session.committedTranscript = $.trim(String(session.transcript || session.committedTranscript || ''));
                        session.restarts += 1;
                        session.restarting = true;
                        window.setTimeout(function() {
                            if (session.released || !session.holding || micSessions.get($box[0]) !== session) {
                                return;
                            }
                            try {
                                rec.start();
                            } catch (eRestart) {
                                session.restarting = false;
                                finalizeMicSession($box, session);
                            }
                        }, 120);
                        return;
                    }
                }
                finalizeMicSession($box, session);
            };

            setMicListeningUi($box, true);
            try {
                rec.start();
                scheduleMicSilenceStop($box, session, { forceGrace: true });
            } catch (eStart) {
                finalizeMicSession($box, session);
            }
        }

        $(document).on('click', '.xabia-mic', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var $mic = $(this);
            var $box = $mic.closest('.xabia-chatbox');
            if (!$box.length) {
                return;
            }
            var now = Date.now();
            var lastToggle = parseInt($box.data('xabiaMicToggleAt') || '0', 10) || 0;
            if (now - lastToggle < 250) {
                return;
            }
            $box.data('xabiaMicToggleAt', now);
            if (micSessions.get($box[0])) {
                stopMicSession($box);
                return;
            }
            startMicSession($mic);
        });

        $(document).on('xabia:bot-speaking-start', '.xabia-chatbox', function() {
            stopMicSession($(this));
        });

        function submitChatMessage($box, options) {
            options = options || {};
            var $input = $box.find('.xabia-input-field');
            var $history = messagesStream($box);
            var continuePrompt = options.forceContinue ? xabiaI18n('continuePrompt', 'Continúa exactamente desde donde lo dejaste, sin repetir lo anterior.') : ($box.data('continuePrompt') || '');
            var isContinue = !!continuePrompt;
            var val = $.trim(isContinue ? continuePrompt : enforceInputLimits($input));
            if (!val) return;
            if (!isContinue && (countInputLines(val) > userInputMaxLines() || val.length > userInputMaxChars())) {
                syncInputLimitState($input);
                return;
            }
            /* Gesto de usuario: desbloquear TTS para cuando llegue la respuesta AJAX (Safari). */
            if (isVoiceOn($box)) {
                unlockSpeechSynthesis();
            }
            $box.removeData('continuePrompt');
            var htmlLang = (document.documentElement && document.documentElement.lang)
                ? String(document.documentElement.lang).trim()
                : '';
            var payload = {
                action: xabiaChatAjaxAction(),
                project_id: $box.data('project'),
                message: val,
                x_scope: $box.data('scope') || 'global',
                lang: String($box.data('lang') || 'es').toLowerCase().replace(/[^a-z]/g, '').substring(0, 2) || 'es',
                user_lang: htmlLang || String($box.data('lang') || 'es').trim(),
                visitor_key: xabiaVisitorKey($box.data('project'))
            };
            if (xabiaIsLiteMode()) {
                var liteCfg = xabiaChatSettings();
                if (liteCfg.nonce) {
                    payload.nonce = liteCfg.nonce;
                }
            }
            if (isContinue) payload.x_continue = '1';
            var enteIdAttr = $box.attr('data-ente-id');
            var enteFromSettings = (window.XabiaSettings && window.XabiaSettings.enteId) ? String(window.XabiaSettings.enteId) : '';
            var entePayload = (enteIdAttr && String(enteIdAttr).trim() !== '') ? String(enteIdAttr).trim() : String(enteFromSettings || '').trim();
            if (!xabiaIsLiteMode() && entePayload !== '') {
                payload.ente_id = entePayload;
            }
            if (!xabiaIsLiteMode() && urlParams && urlParams.get('x_project')) {
                payload.x_project = String(urlParams.get('x_project'));
            }
            if (!xabiaIsLiteMode() && window.xabiaWooCartIntent) {
                var intentProj = String($box.data('project') || (window.XabiaSettings && window.XabiaSettings.projectId) || 'default');
                var intentItems = window.xabiaWooCartIntent.getItems(intentProj);
                if (intentItems.length) {
                    payload.woo_cart_intent = JSON.stringify(intentItems);
                }
            }

            var avatarName = $box.data('avatarName') || 'Xabia';
            function escapeRe(s) { return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
            var userYou = xabiaI18n('userYou', 'Tú');
            var prefixRe = new RegExp('^(' + escapeRe(userYou) + '|' + escapeRe(avatarName) + '):\\s*', 'i');
            var historyArr = [];
            $history.find('.xabia-msg').each(function() {
                var $msg = $(this);
                var role = $msg.hasClass('user') ? 'user' : 'assistant';
                var content;
                if (role === 'assistant' && $msg.attr('data-raw')) {
                    content = $msg.attr('data-raw');
                } else {
                    var $contentEl = $msg.find('.xabia-msg-content');
                    content = ($contentEl.length ? $contentEl.text() : $msg.text()).replace(prefixRe, '').trim();
                }
                if (content) historyArr.push({ role: role, content: content });
            });
            if (historyArr.length) payload.history = JSON.stringify(historyArr.slice(-6));
            if (!isContinue) {
                hideStarterSuggestions($box);
                var usedPending = false;
                var $pending = $history.find('.xabia-msg.user[data-xabia-pending="1"]').last();
                if ($pending.length) {
                    var pendingText = $.trim($pending.find('.xabia-msg-content').text());
                    if (pendingText === val) {
                        $pending.removeAttr('data-xabia-pending').removeClass('xabia-msg-pending-voice');
                        usedPending = true;
                    }
                }
                if (!usedPending) {
                    var $userMsg = $('<div class="xabia-msg user"></div>');
                    $userMsg.append($('<span class="xabia-msg-content"></span>').text(val));
                    $history.append($userMsg);
                }
                $input.val('');
                autoSizeInput($input);
                syncChatUiState($box);
            }
            $box.find('.xabia-continue').prop('disabled', true);
            showTyping($box, val);
            $.post($box.data('endpoint'), payload, null, 'json').done(function(r) {
                hideTyping($box);
                if (r.success && r.data && r.data.response) {
                    var raw = r.data.response;
                    if (isContinue) {
                        var $lastBot = $history.find('.xabia-msg.bot').last();
                        if ($lastBot.length) {
                            var prev = String($lastBot.attr('data-raw') || '');
                            var merged = prev ? (prev + '\n\n' + raw) : raw;
                            $lastBot.attr('data-raw', merged);
                            $lastBot.removeClass('xabia-msg-typing');
                            $lastBot.find('.xabia-continue').remove();
                            var $content = $lastBot.find('.xabia-msg-content');
                            if ($content.length) {
                                $content.html(renderBotHtml(merged, chatboxImagesBase($box)));
                            } else {
                                $lastBot.append($('<span class="xabia-msg-content"></span>').html(renderBotHtml(merged, chatboxImagesBase($box))));
                            }
                            if (r.data.truncated) {
                                $lastBot.append(' ').append(makeContinueButton());
                            }
                            attachMsgSpeakButton($box, $lastBot, merged);
                            speakText($box, merged);
                        } else {
                            appendBotMessage($box, $history, raw, { truncated: !!r.data.truncated });
                        }
                    } else {
                        appendBotMessage($box, $history, raw, { truncated: !!r.data.truncated });
                    }
                } else {
                    var errText = (r.data && r.data.message) ? String(r.data.message) : xabiaI18n('errorGeneric', 'Error');
                    var $err = $('<div class="xabia-msg bot xabia-msg--error"></div>');
                    $err.append($('<span class="xabia-msg-content"></span>').text(errText));
                    $history.append($err);
                    $box.find('.xabia-continue').prop('disabled', false);
                }
                scrollMessages($box);
                syncChatUiState($box);
            }).fail(function(xhr, status) {
                hideTyping($box);
                $box.find('.xabia-continue').prop('disabled', false);
                var errText = xabiaI18n('errorServer', 'Error servidor.');
                if (status === 'parsererror') {
                    errText = xabiaI18n('errorInvalidResponse', 'Respuesta inválida del servidor. Actualiza Xabia Core o revisa el log PHP del hosting.');
                    if (xhr && xhr.responseText && window.console && console.warn) {
                        console.warn('[Xabia] chat parsererror:', String(xhr.responseText).substring(0, 800));
                    }
                } else if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errText = String(xhr.responseJSON.data.message);
                } else if (xhr && xhr.status === 504) {
                    errText = xabiaI18n('errorTimeout', 'El servidor tardó demasiado. Inténtalo de nuevo en unos segundos.');
                }
                messagesStream($box).append('<div class="xabia-msg bot xabia-msg--error"><span class="xabia-msg-content">' + errText + '</span></div>');
                scrollMessages($box);
                syncChatUiState($box);
            });
        }

        $(document).on('click', '.xabia-send', function(e) {
            e.preventDefault();
            e.stopPropagation();
            submitChatMessage($(this).closest('.xabia-chatbox'));
        });

        $(document).on('click', '.xabia-starter-chip', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var $chip = $(this);
            var $box = $chip.closest('.xabia-chatbox');
            if (!$box.length) {
                return;
            }
            var text = String($chip.attr('data-question') || $chip.text() || '').trim();
            if (!text) {
                return;
            }
            var $input = $box.find('.xabia-input-field');
            $input.val(text);
            autoSizeInput($input);
            syncChatUiState($box);
            submitChatMessage($box);
        });

        $(document).on('click', '.xabia-continue', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var $box = $(this).closest('.xabia-chatbox');
            var $btn = $(this);
            if ($btn.prop('disabled') || $box.hasClass('xabia-is-waiting')) {
                return;
            }
            $btn.prop('disabled', true);
            submitChatMessage($box, { forceContinue: true });
        });

        $(document).on('keydown keypress', '.xabia-input-field', function(e) {
            var isEnter = e.key === 'Enter' || e.which === 13 || e.keyCode === 13;
            if (isEnter && !e.shiftKey && !e.isComposing) {
                e.preventDefault();
                $(this).closest('.xabia-chatbox').find('.xabia-send').trigger('click');
            }
        });

        $(document).on('focus input keyup paste', '.xabia-ui-modern .xabia-input-field', function() {
            var $input = $(this);
            enforceInputLimits($input);
            autoSizeInput($input);
            syncChatUiState($input.closest('.xabia-chatbox'));
        });

        $(document).on('input paste', '.xabia-input-field', function() {
            var $input = $(this);
            enforceInputLimits($input);
            autoSizeInput($input);
        });

        function refreshOpenInputHeights() {
            $('.xabia-chatbox .xabia-input-field').each(function() {
                var $input = $(this);
                enforceInputLimits($input);
                autoSizeInput($input);
            });
        }

        $(window).on('resize orientationchange', function() {
            window.setTimeout(refreshOpenInputHeights, 80);
        });
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', function() {
                window.setTimeout(refreshOpenInputHeights, 80);
            });
        }

        $(document).on('click', 'a.xabia-btn-cart-remote', function() {
            var href = String($(this).attr('href') || '');
            var m = href.match(/(?:add-to-cart=)(\d+)/i);
            var id = m ? m[1] : '';
            var $box = $(this).closest('.xabia-chatbox');
            var proj = $box.length ? String($box.data('project') || 'default') : ((window.XabiaSettings && window.XabiaSettings.projectId) ? String(window.XabiaSettings.projectId) : 'default');
            if (id && window.xabiaWooCartIntent) {
                window.xabiaWooCartIntent.recordSingle(proj, id, '');
            }
        });
        $(document).on('click', 'a.xabia-btn-cart-pack-remote', function() {
            var ids = String($(this).attr('data-pack-ids') || '').split(',').map(function(s) { return parseInt(s, 10); }).filter(function(n) { return n > 0; });
            var $box = $(this).closest('.xabia-chatbox');
            var proj = $box.length ? String($box.data('project') || 'default') : ((window.XabiaSettings && window.XabiaSettings.projectId) ? String(window.XabiaSettings.projectId) : 'default');
            if (ids.length && window.xabiaWooCartIntent) {
                window.xabiaWooCartIntent.recordPack(proj, ids, [], '');
            }
        });

        $(document).on('click', '.xabia-book-amelia', function(e) {
            if ($(this).hasClass('xabia-book-amelia--link')) {
                return;
            }
            e.preventDefault();
            var sid = parseInt($(this).attr('data-service-id'), 10);
            if (!sid) return;
            xabiaAmeliaHandleBookingOpen(sid);
        });

        $(document).on('click', '.xabia-btn-cart', function(e) {
            e.preventDefault();
            var $btn = $(this);
            if ($btn.is('a')) {
                return;
            }
            if ($btn.prop('disabled')) return;
            var wc = window.xabiaWooCart;
            if (!wc || !wc.ajaxUrl || !wc.nonce || !wc.action) {
                alert(xabiaI18n('cartUnavailable', 'Carrito no disponible.'));
                return;
            }
            var productId = parseInt($btn.attr('data-id'), 10);
            if (!productId) return;
            var $box = $btn.closest('.xabia-chatbox');
            var $history = messagesStream($box);
            var avatarName = $box.data('avatarName') || 'Xabia';
            $btn.prop('disabled', true);
            $.post(wc.ajaxUrl, {
                action: wc.action,
                nonce: wc.nonce,
                product_id: productId,
                xabia_project_id: $box.data('project') || 'default'
            }).done(function(r) {
                if (r.success && r.data) {
                    var msg = (r.data.message || wc.addedMsg || xabiaI18n('productAdded', '¡Producto añadido!'));
                    var proj = String($box.data('project') || (window.XabiaSettings && window.XabiaSettings.projectId) || 'default');
                    if (window.xabiaWooCartIntent) {
                        window.xabiaWooCartIntent.recordSingle(proj, productId, '');
                    }
                    var $row = $('<div class="xabia-msg bot xabia-msg-system"></div>');
                    $row.append($('<span class="xabia-msg-content"></span>').text(msg));
                    $history.append($row);
                    scrollMessages($box);
                } else {
                    var err = (r.data && r.data.message) ? r.data.message : xabiaI18n('errorGeneric', 'Error');
                    $history.append('<div class="xabia-msg bot" style="color:#b32d2e">' + $('<div/>').text(err).html() + '</div>');
                    scrollMessages($box);
                }
            }).fail(function() {
                $history.append($('<div class="xabia-msg bot" style="color:#b32d2e"></div>').text(xabiaI18n('networkError', 'Error de red.')));
                scrollMessages($box);
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
        }

        /**
         * Modo tótem: inactividad → xabia_clear_session (servidor) y UI al saludo inicial.
         */
        function initXabiaTotem($box) {
            if (xabiaIsLiteMode()) {
                return;
            }
            var min = parseInt($box.attr('data-totem-minutes'), 10);
            if (!min || min <= 0) {
                return;
            }

            var ms = min * 60 * 1000;
            var warnAt = Math.max(0, ms - 10000);
            var endpoint = $box.data('endpoint');
            var projectId = $box.data('project');
            var storageKey = 'xabia_totem_' + projectId;

            var timerId = null;
            var warnId = null;

            function parseReset() {
                var raw = $box.attr('data-totem-reset');
                if (!raw) {
                    return null;
                }
                try {
                    return JSON.parse(raw);
                } catch (e2) {
                    return null;
                }
            }

            function resetTimers() {
                if (timerId) {
                    clearTimeout(timerId);
                    timerId = null;
                }
                if (warnId) {
                    clearTimeout(warnId);
                    warnId = null;
                }
                $box.find('.xabia-totem-warning').hide().empty();
            }

            function applyResetHtml() {
                var o = parseReset();
                var $stream = messagesStream($box);
                if (o && o.greeting_html !== undefined && o.greeting_html !== null) {
                    $stream.html('<div class="xabia-msg bot xabia-msg-greeting"><span class="xabia-msg-content">' + String(o.greeting_html) + '</span></div>');
                } else {
                    $stream.find('.xabia-msg').not('.xabia-msg-greeting').remove();
                }
                if (o && Array.isArray(o.starter_questions) && o.starter_questions.length) {
                    try {
                        $box.attr('data-starter-questions', JSON.stringify(o.starter_questions));
                    } catch (eReset) {}
                }
                syncChatUiState($box);
            }

            function onTimeout() {
                resetTimers();
                $.post(endpoint, { action: 'xabia_clear_session', project_id: projectId }).always(function() {
                    try {
                        sessionStorage.removeItem(storageKey);
                        localStorage.removeItem(storageKey);
                        if (window.xabiaWooCartIntent) {
                            window.xabiaWooCartIntent.clear(projectId);
                        }
                    } catch (err) {}
                    applyResetHtml();
                });
            }

            function arm() {
                resetTimers();
                if (warnAt < ms) {
                    warnId = setTimeout(function() {
                        $box.find('.xabia-totem-warning').text(xabiaI18n('totemWarning', 'La sesión se cerrará pronto por inactividad.')).show();
                    }, warnAt);
                }
                timerId = setTimeout(onTimeout, ms);
            }

            $box.on('click.xabiaTotem mousedown.xabiaTotem touchstart.xabiaTotem', function() {
                arm();
            });
            $box.find('.xabia-input-field').on('focus.xabiaTotem input.xabiaTotem keydown.xabiaTotem', function() {
                arm();
            });

            arm();
        }

        function tryAddonAutoMessage($box) {
            var raw = $box.attr('data-qr-auto');
            if (!raw) {
                return;
            }
            var o;
            try {
                o = JSON.parse(raw);
            } catch (e0) {
                return;
            }
            if (!o || !o.greeting) {
                return;
            }
            var qrId = String(o.qr_id || '');
            var key = 'xabia_addon_auto_' + encodeURIComponent(qrId || '1');
            try {
                if (sessionStorage.getItem(key)) {
                    return;
                }
            } catch (e1) {}
            if ($box[0] && typeof $box[0].scrollIntoView === 'function') {
                $box[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            $box.find('.xabia-input-field').val(String(o.greeting));
            try {
                sessionStorage.setItem(key, '1');
            } catch (e2) {}
            $box.find('.xabia-send').trigger('click');
        }

        function xabiaApplyQueryTunnelToBoxOuter($b) {
            var urlParams = null;
            try {
                urlParams = new URLSearchParams(window.location.search);
            } catch (eP) {
                urlParams = null;
            }
            if (!urlParams || !$b || !$b.length) {
                return;
            }
            var xp = urlParams.get('x_project');
            var eid = urlParams.get('ente_id');
            if (xp) {
                $b.attr('data-project', xp);
            }
            if (eid) {
                $b.attr('data-ente-id', eid);
                $b.attr('data-strict-mode', '1');
                $b.attr('data-scope', eid);
            }
            if (window.XabiaSettings && typeof window.XabiaSettings === 'object') {
                if (xp) {
                    window.XabiaSettings.projectId = xp;
                    window.XabiaSettings.xProject = xp;
                }
                if (eid) {
                    window.XabiaSettings.enteId = eid;
                    window.XabiaSettings.strictMode = true;
                    window.XabiaSettings.scope = eid;
                }
            }
        }

        function initKioskPresentation($box) {
            var mode = String($box.attr('data-presentation-mode') || 'web_adaptive');
            if (!mode || mode === 'web_adaptive') {
                return;
            }
            $('html').addClass('xabia-kiosk-page');
            $('body').addClass('xabia-kiosk-open xabia-immersive-open');
            $box.addClass('xabia-kiosk-embed xabia-immersive-mode xabia-chatbox--fullscreen xabia-panel-shell is-active');
            $box.find('.xabia-immersive-avatar-stage').attr('aria-hidden', 'false');
            ensureImmersiveKinetic($box);
        }

        function initXabiaChatboxes() {
            var isBoxPage = document.body && document.body.classList.contains('xabia-box-page');
            $('.xabia-chatbox').each(function() {
                var $b = $(this);
                if ($b.data('xabiaInited') === 1) {
                    return;
                }
                $b.data('xabiaInited', 1);
                xabiaApplyQueryTunnelToBoxOuter($b);
                initKioskPresentation($b);
                if (isBoxPage) {
                    $('html').addClass('xabia-box-page');
                    $b.addClass('xabia-chatbox--fullscreen');
                    if (boxHasSpeakingAvatar($b)) {
                        $b.addClass('xabia-immersive-mode');
                        $('body').addClass('xabia-immersive-open');
                        $b.find('.xabia-immersive-avatar-stage').attr('aria-hidden', 'false');
                        ensureImmersiveKinetic($b);
                    }
                }
                setVoiceOn($b, isVoiceOn($b));
                autoSizeInput($b.find('.xabia-input-field'));
                syncChatUiState($b);
                initXabiaTotem($b);
                tryAddonAutoMessage($b);
                $b.find('.xabia-msg.bot').each(function() {
                    var $m = $(this);
                    var raw = $m.attr('data-raw') || $m.find('.xabia-msg-content').text() || '';
                    attachMsgSpeakButton($b, $m, raw);
                });
                if (isBoxPage) {
                    window.setTimeout(function() {
                        var $inp = $b.find('.xabia-input-field');
                        if ($inp.length) {
                            try {
                                $inp.trigger('focus');
                            } catch (eF) {}
                        }
                    }, 120);
                }
                if (chatIsOpenForGreeting($b)) {
                    window.setTimeout(function() { maybeSpeakGreetingOnOpen($b); }, 180);
                }
            });
        }

        bindXabiaChatboxHandlers();
        preloadSpeechVoices();
        if (window.speechSynthesis) {
            window.speechSynthesis.onvoiceschanged = preloadSpeechVoices;
        }
        window.xabiaChatboxInitAll = initXabiaChatboxes;
        $(function() {
            initXabiaChatboxes();
        });
        document.addEventListener('xabia:chatbox:mounted', function() {
            initXabiaChatboxes();
        });
        $(document).on('click', '.xabia-interface-trigger, .xabia-chatbox .xabia-input-field, .xabia-chatbox .xabia-send, .xabia-chatbox .xabia-mute', function() {
            unlockSpeechSynthesis({ beep: false });
            window.setTimeout(function() {
                $('.xabia-chatbox').each(function() {
                    maybeSpeakGreetingOnOpen($(this));
                });
            }, 180);
        });
    }

    function xabiaChatboxBoot() {
        if (typeof window.jQuery === 'undefined') {
            window.setTimeout(xabiaChatboxBoot, 40);
            return;
        }
        xabiaChatboxMain(window.jQuery);
    }

    xabiaChatboxBoot();
})();
