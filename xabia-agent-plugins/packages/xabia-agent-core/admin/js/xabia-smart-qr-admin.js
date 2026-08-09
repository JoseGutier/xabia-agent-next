(function ($) {
    'use strict';

    function cfg() {
        return window.xabiaSmartQr || null;
    }

    function buildEnteUrl(enteId) {
        var c = cfg();
        var raw = c && c.landingUrl ? String(c.landingUrl).trim() : '';
        if (!raw) {
            return '';
        }
        try {
            var u = new URL(raw, window.location.origin);
            u.searchParams.set('ente_id', String(enteId));
            return u.toString();
        } catch (e) {
            var sep = raw.indexOf('?') === -1 ? '?' : '&';
            return raw + sep + 'ente_id=' + encodeURIComponent(String(enteId));
        }
    }

    function buildModalDom() {
        if ($('#xabia-smart-qr-modal').length) {
            return;
        }
        var i18n = (cfg() && cfg().i18n) ? cfg().i18n : {};
        $('body').append(
            '<div id="xabia-smart-qr-modal" class="xabia-smart-qr-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="xabia-smart-qr-modal-title">' +
            '<div class="xabia-smart-qr-modal__backdrop"></div>' +
            '<div class="xabia-smart-qr-modal__panel">' +
            '<button type="button" class="xabia-smart-qr-modal__close dashicons dashicons-no-alt" aria-label="' + (i18n.close || 'Cerrar') + '"></button>' +
            '<h2 id="xabia-smart-qr-modal-title" class="xabia-smart-qr-modal__title"></h2>' +
            '<p class="xabia-smart-qr-modal__subtitle description"></p>' +
            '<div class="xabia-smart-qr-modal__qr-wrap"><div class="xabia-smart-qr-modal__qr"></div></div>' +
            '<p class="xabia-smart-qr-modal__url-row"><strong class="xabia-sqrl"></strong><br><code class="xabia-sqru"></code></p>' +
            '<p><label><strong class="xabia-sqrs"></strong><br><input type="text" class="large-text xabia-sqsci" readonly /></label></p>' +
            '<div class="xabia-smart-qr-modal__actions">' +
            '<button type="button" class="button button-primary xabia-sqd-png"></button>' +
            '<button type="button" class="button xabia-sqd-svg"></button>' +
            '<button type="button" class="button xabia-sq-copy-link"></button>' +
            '<button type="button" class="button xabia-sq-copy-sc"></button>' +
            '<button type="button" class="button xabia-sq-copy-img"></button>' +
            '<button type="button" class="button xabia-sqc"></button>' +
            '</div></div></div>'
        );
    }

    function copyText(text) {
        if (!text) {
            return Promise.reject();
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var $t = $('<textarea>').css({ position: 'fixed', left: '-9999px' }).val(text).appendTo('body');
            $t[0].select();
            try {
                document.execCommand('copy') ? resolve() : reject();
            } catch (err) {
                reject(err);
            }
            $t.remove();
        });
    }

    function downloadDataUrl(dataUrl, filename) {
        var a = document.createElement('a');
        a.href = dataUrl;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    function downloadSvg(svg, filename) {
        var blob = new Blob([svg], { type: 'image/svg+xml;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function slugFile(name) {
        return String(name || 'ente').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'ente';
    }

    function renderQr($modal, url) {
        var i18n = (cfg() && cfg().i18n) ? cfg().i18n : {};
        var $qr = $modal.find('.xabia-smart-qr-modal__qr').empty().text(i18n.loading || '…');
        $modal.removeData('xabiaQrPng').removeData('xabiaQrSvg');

        if (typeof window.QRCode === 'undefined') {
            $qr.text(i18n.libMissing || 'Error');
            return;
        }

        var opts = { width: 280, margin: 2, errorCorrectionLevel: 'M' };

        window.QRCode.toDataURL(url, opts, function (err, png) {
            if (!err && png) {
                $qr.empty().append($('<img>', { alt: 'QR', width: 280, height: 280, id: 'xabia-smart-qr-img', src: png }));
                $modal.data('xabiaQrPng', png);
            } else {
                $qr.text(i18n.genError || 'Error');
            }
        });

        if (typeof window.QRCode.toString === 'function') {
            window.QRCode.toString(url, { type: 'svg', errorCorrectionLevel: 'M', margin: 2 }, function (err, svg) {
                if (!err && svg) {
                    $modal.data('xabiaQrSvg', svg);
                }
            });
        }
    }

    function openModal(enteId, enteName) {
        var c = cfg();
        if (!c) {
            alert('Smart QR: configuración no cargada.');
            return;
        }
        if (typeof window.QRCode === 'undefined') {
            alert(c.i18n.libMissing || 'Generador QR no disponible.');
            return;
        }
        var url = buildEnteUrl(enteId);
        if (!url) {
            alert((c.i18n && c.i18n.selectLanding) || 'Configura la página de aterrizaje en Smart QR / Tótems.');
            return;
        }
        buildModalDom();
        var $m = $('#xabia-smart-qr-modal');
        var sc = '[xabia_agent id="' + String(c.projectId).replace(/"/g, '') + '" ente_id="' + String(enteId).replace(/"/g, '') + '"]';
        $m.find('.xabia-smart-qr-modal__title').text((c.i18n.modalTitle || 'Smart QR') + ' — ' + enteName);
        $m.find('.xabia-smart-qr-modal__subtitle').text(url);
        $m.find('.xabia-sqrl').text(c.i18n.target || 'URL');
        $m.find('.xabia-sqru').text(url);
        $m.find('.xabia-sqrs').text(c.i18n.shortcode || 'Shortcode');
        $m.find('.xabia-sqsci').val(sc);
        $m.find('.xabia-sqd-png').text(c.i18n.downloadPng || 'PNG');
        $m.find('.xabia-sqd-svg').text(c.i18n.downloadSvg || 'SVG');
        $m.find('.xabia-sq-copy-link').text(c.i18n.copyLink || 'Copiar enlace');
        $m.find('.xabia-sq-copy-sc').text(c.i18n.copyShortcode || 'Copiar shortcode');
        $m.find('.xabia-sq-copy-img').text(c.i18n.copyImage || 'Copiar imagen');
        $m.find('.xabia-sqc').text(c.i18n.close || 'Cerrar');
        $m.data('xabiaFileSlug', slugFile(enteName));
        $m.show();
        renderQr($m, url);
    }

    function closeModal() {
        $('#xabia-smart-qr-modal').hide();
    }

    function notifyCopied() {
        var msg = (cfg() && cfg().i18n.copied) || 'Copiado';
        if (window.wp && wp.a11y && wp.a11y.speak) {
            wp.a11y.speak(msg);
        }
    }

    function notifyCopyFail() {
        alert((cfg() && cfg().i18n.copyFail) || 'No se pudo copiar');
    }

    $(document).on('click', '.xabia-smart-qr-open', function (e) {
        e.preventDefault();
        openModal($(this).data('ente-id'), $(this).data('ente-name') || $(this).data('ente-id'));
    });

    $(document).on('click', '.xabia-smart-qr-modal__backdrop, .xabia-sqc, .xabia-smart-qr-modal__close', function (e) {
        e.preventDefault();
        closeModal();
    });

    $(document).on('click', '.xabia-sqd-png', function (e) {
        e.preventDefault();
        var $m = $('#xabia-smart-qr-modal');
        var png = $m.data('xabiaQrPng');
        if (png) {
            downloadDataUrl(png, 'xabia-qr-' + ($m.data('xabiaFileSlug') || 'ente') + '.png');
        }
    });

    $(document).on('click', '.xabia-sqd-svg', function (e) {
        e.preventDefault();
        var $m = $('#xabia-smart-qr-modal');
        var svg = $m.data('xabiaQrSvg');
        if (svg) {
            downloadSvg(svg, 'xabia-qr-' + ($m.data('xabiaFileSlug') || 'ente') + '.svg');
        } else {
            alert((cfg() && cfg().i18n.genError) || 'Error');
        }
    });

    $(document).on('click', '.xabia-sq-copy-link', function (e) {
        e.preventDefault();
        copyText($('#xabia-smart-qr-modal .xabia-sqru').text()).then(notifyCopied).catch(notifyCopyFail);
    });

    $(document).on('click', '.xabia-sq-copy-sc', function (e) {
        e.preventDefault();
        copyText($('#xabia-smart-qr-modal .xabia-sqsci').val()).then(notifyCopied).catch(notifyCopyFail);
    });

    $(document).on('click', '.xabia-sq-copy-img', function (e) {
        e.preventDefault();
        var png = $('#xabia-smart-qr-modal').data('xabiaQrPng');
        if (!png || !navigator.clipboard || !window.fetch) {
            notifyCopyFail();
            return;
        }
        fetch(png)
            .then(function (r) { return r.blob(); })
            .then(function (blob) {
                return navigator.clipboard.write([new window.ClipboardItem({ 'image/png': blob })]);
            })
            .then(notifyCopied)
            .catch(notifyCopyFail);
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $('#xabia-smart-qr-modal').is(':visible')) {
            closeModal();
        }
    });
})(jQuery);
