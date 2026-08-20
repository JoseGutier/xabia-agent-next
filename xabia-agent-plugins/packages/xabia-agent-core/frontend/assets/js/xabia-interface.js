/**
 * Xabia Agent — avatar oficial, panel, scroll y anclaje al footer.
 */
(function () {
    'use strict';

    const rootCfg = typeof window.XabiaInterface === 'object' && window.XabiaInterface !== null
        ? window.XabiaInterface
        : {};

    const PANEL_LAYOUT_CLASS = {
        right_float: 'layout-right-float',
        left_float: 'layout-left-float',
        centered_modal: 'layout-centered-modal',
        full_screen: 'layout-full-screen',
    };

    let lastScrollY = window.scrollY;

    function layoutClass(layout) {
        return PANEL_LAYOUT_CLASS[layout] || PANEL_LAYOUT_CLASS.right_float;
    }

    function projectCfg(el) {
        const pid = el.getAttribute('data-project') || '';
        return (rootCfg.projects && rootCfg.projects[pid]) ? rootCfg.projects[pid] : {};
    }

    function loadScript(url, cb) {
        if (!url) {
            cb();
            return;
        }
        if (document.querySelector('script[data-xabia-gsap="1"]') && window.gsap) {
            cb();
            return;
        }
        const s = document.createElement('script');
        s.src = url;
        s.async = true;
        s.dataset.xabiaGsap = '1';
        s.onload = function () { cb(); };
        s.onerror = function () { cb(); };
        document.head.appendChild(s);
    }

    function findSiteFooter() {
        return document.querySelector('#site-footer, footer.site-footer, footer#colophon, footer[role="contentinfo"], .site-footer, #footer');
    }

    function initKineticAvatar(root) {
        if (!root || root.getAttribute('data-xabia-kinetic-bound') === '1') {
            return;
        }
        const projectHost = root.closest('[data-project]') || root;
        const cfg = projectCfg(projectHost);
        if (cfg.triggerType === 'custom_image' && root.classList.contains('xabia-interface-trigger')) {
            return;
        }
        const head = root.querySelector('.x-avatar-head-layer');
        const sockets = root.querySelector('.x-avatar-sockets-layer');
        const eyes = root.querySelector('.x-avatar-eyes-layer');
        const mouth = root.querySelector('.x-avatar-mouth-layer');
        const container = root.querySelector('.x-avatar-container');
        const blinkEyes = root.querySelectorAll('.x-avatar-fill-dot, .x-avatar-fill-secondary');
        if (!head || !sockets || !eyes || !container || !window.gsap) {
            return;
        }

        root.setAttribute('data-xabia-kinetic-bound', '1');

        const lookProfile = resolveLookProfile(root);
        const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const isTouchLike = window.matchMedia && window.matchMedia('(hover: none), (pointer: coarse)').matches;
        const idleBias = resolveIdleBias(root, isTouchLike);
        const isLauncher = root.classList.contains('xabia-interface-trigger');

        function axisOffset(layerKey, normalized) {
            const layer = lookProfile.layers[layerKey];
            if (normalized >= 0) {
                return normalized * layer.xRight;
            }
            return normalized * layer.xLeft;
        }

        window.gsap.set(container, {
            transformPerspective: 1000,
            transformOrigin: 'center center',
        });
        window.gsap.set([head, sockets, eyes, mouth], {
            transformOrigin: 'center center',
            force3D: true,
        });
        if (blinkEyes.length) {
            window.gsap.set(blinkEyes, {
                transformBox: 'fill-box',
                transformOrigin: 'center center',
                force3D: true,
            });
        }

        function applyGazePose(nx, ny, duration) {
            const mouthLayer = lookProfile.layers.mouth || { xRight: 10, xLeft: 8, y: 4 };
            const pose = {
                head: {
                    x: axisOffset('head', nx) * 0.3,
                    y: ny * lookProfile.layers.head.y * 0.3,
                },
                sockets: {
                    x: axisOffset('sockets', nx),
                    y: ny * lookProfile.layers.sockets.y,
                },
                eyes: {
                    x: axisOffset('eyes', nx),
                    y: ny * lookProfile.layers.eyes.y,
                    rotateY: nx * lookProfile.rotateY,
                    rotateX: ny * lookProfile.rotateX,
                },
                mouth: {
                    x: nx >= 0 ? nx * mouthLayer.xRight : nx * mouthLayer.xLeft,
                    y: ny * mouthLayer.y,
                },
            };

            if (!duration) {
                window.gsap.set(head, pose.head);
                window.gsap.set(sockets, pose.sockets);
                window.gsap.set(eyes, pose.eyes);
                if (mouth) {
                    window.gsap.set(mouth, pose.mouth);
                }
                return;
            }

            const tweenOpts = { duration: duration, ease: 'power2.out', overwrite: 'auto' };
            window.gsap.to(head, Object.assign({}, tweenOpts, pose.head));
            window.gsap.to(sockets, Object.assign({}, tweenOpts, pose.sockets));
            window.gsap.to(eyes, Object.assign({}, tweenOpts, pose.eyes));
            if (mouth) {
                window.gsap.to(mouth, Object.assign({}, tweenOpts, pose.mouth));
            }
        }

        function applyIdleBias() {
            applyGazePose(idleBias.x, idleBias.y, 0.38);
        }

        /* Sin seguimiento del ratón en launcher ni en ningún avatar. */
        if (!reduceMotion) {
            if (blinkEyes.length) {
                scheduleBlink();
            }
            if (isLauncher || isTouchLike) {
                applyIdleBias();
            } else {
                scheduleIdleGaze();
            }
        } else {
            applyIdleBias();
        }

        function scheduleBlink() {
            window.gsap.delayedCall(window.gsap.utils.random(1, 10), function () {
                window.gsap.timeline({
                    onComplete: scheduleBlink,
                })
                    .to(blinkEyes, {
                        scaleY: 0.1,
                        duration: 0.075,
                        ease: 'power2.inOut',
                        overwrite: 'auto',
                    })
                    .to(blinkEyes, {
                        scaleY: 1,
                        duration: 0.095,
                        ease: 'power2.inOut',
                        overwrite: 'auto',
                    });
            });
        }

        function scheduleIdleGaze() {
            const hostChat = root.closest('.xabia-chatbox');
            const idleGaze = { x: idleBias.x, y: idleBias.y };

            function avatarBusy() {
                return document.body.classList.contains('xabia-avatar-speaking')
                    || document.body.classList.contains('xabia-avatar-thinking')
                    || document.body.classList.contains('xabia-mic-listening')
                    || (hostChat && hostChat.classList.contains('xabia-mic-is-listening'));
            }

            function randomGazeX() {
                if (Math.random() < 0.72) {
                    return window.gsap.utils.random(0.28, 1);
                }
                return window.gsap.utils.random(-0.25, 0.35);
            }

            function wanderGaze() {
                if (!root.isConnected) {
                    return;
                }
                if (avatarBusy()) {
                    window.gsap.to(idleGaze, {
                        x: idleBias.x,
                        y: idleBias.y,
                        duration: 0.28,
                        ease: 'power2.out',
                        onUpdate: function () {
                            applyGazePose(idleGaze.x, idleGaze.y, 0);
                        },
                        onComplete: function () {
                            window.gsap.delayedCall(0.3, wanderGaze);
                        },
                    });
                    return;
                }

                const targetX = randomGazeX();
                const targetY = window.gsap.utils.random(-0.22, 0.28);
                window.gsap.to(idleGaze, {
                    x: targetX,
                    y: targetY,
                    duration: window.gsap.utils.random(0.38, 0.68),
                    ease: 'power2.inOut',
                    onUpdate: function () {
                        applyGazePose(idleGaze.x, idleGaze.y, 0);
                    },
                    onComplete: function () {
                        window.gsap.to(idleGaze, {
                            x: idleBias.x + (targetX - idleBias.x) * 0.18,
                            y: idleBias.y + (targetY - idleBias.y) * 0.18,
                            duration: window.gsap.utils.random(2, 3.4),
                            delay: window.gsap.utils.random(0.5, 1.2),
                            ease: 'sine.inOut',
                            onUpdate: function () {
                                applyGazePose(idleGaze.x, idleGaze.y, 0);
                            },
                            onComplete: wanderGaze,
                        });
                    },
                });
            }

            applyGazePose(idleBias.x, idleBias.y, 0.38);
            wanderGaze();
        }
    }

    function initKineticIn(scope) {
        const root = scope || document;
        root.querySelectorAll('.xabia-kinetic-wrapper').forEach(function (wrap) {
            initKineticAvatar(wrap);
        });
        root.querySelectorAll('.xabia-interface-trigger').forEach(function (trigger) {
            initKineticAvatar(trigger);
        });
    }

    window.xabiaInitKineticAvatar = initKineticAvatar;
    window.xabiaInitKineticIn = initKineticIn;

    function defaultLookProfile() {
        return {
            vectorBiasX: 0,
            vectorScaleX: 1,
            clampMinX: -1,
            clampMaxX: 1,
            rotateY: 12,
            rotateX: -9,
            layers: {
                head: { xRight: 2, xLeft: 2, y: 2 },
                sockets: { xRight: 7, xLeft: 7, y: 6 },
                /* Ojos más allá del socket blanco (desborde a la derecha como en diseño) */
                eyes: { xRight: 48, xLeft: 32, y: 18 },
                mouth: { xRight: 11, xLeft: 8, y: 4 },
            },
        };
    }

    function resolveLookProfile(root) {
        const footerBox = root.closest
            ? root.closest('.xabia-sticky-footer-box')
            : null;
        const profile = defaultLookProfile();
        const isImmersive = !!(root.classList && (
            root.classList.contains('xabia-kinetic-wrapper--immersive')
            || root.closest('.xabia-immersive-avatar-stage')
        ));

        if (isImmersive) {
            profile.vectorBiasX = 0.16;
            profile.layers.head = { xRight: 4, xLeft: 4, y: 3 };
            profile.layers.sockets = { xRight: 14, xLeft: 8, y: 8 };
            profile.layers.eyes = { xRight: 78, xLeft: 26, y: 26 };
            profile.layers.mouth = { xRight: 16, xLeft: 10, y: 5 };
            profile.rotateY = 14;
            profile.rotateX = -11;
            return profile;
        }

        if (footerBox && footerBox.classList.contains('xabia-trigger--bottom-left')) {
            profile.vectorBiasX = 0.14;
            profile.vectorScaleX = 1.42;
            profile.clampMinX = -0.5;
            profile.clampMaxX = 1;
            profile.layers.sockets = { xRight: 11, xLeft: 5, y: 6 };
            profile.layers.eyes = { xRight: 58, xLeft: 14, y: 18 };
            profile.layers.head = { xRight: 3, xLeft: 1.5, y: 2 };
            return profile;
        }

        if (footerBox && footerBox.classList.contains('xabia-trigger--bottom-right')) {
            profile.vectorBiasX = -0.14;
            profile.vectorScaleX = 1.42;
            profile.clampMinX = -1;
            profile.clampMaxX = 0.5;
            profile.layers.sockets = { xRight: 5, xLeft: 11, y: 6 };
            profile.layers.eyes = { xRight: 18, xLeft: 52, y: 18 };
            profile.layers.head = { xRight: 1.5, xLeft: 3, y: 2 };
            return profile;
        }

        return profile;
    }

    function resolveIdleBias(root, isTouchLike) {
        if (!isTouchLike) {
            return { x: 0, y: 0 };
        }
        const isImmersive = !!(root.classList && (
            root.classList.contains('xabia-kinetic-wrapper--immersive')
            || (root.closest && root.closest('.xabia-immersive-avatar-stage'))
        ));
        if (isImmersive) {
            return { x: 0.24, y: 0.02 };
        }
        const footerBox = root.closest ? root.closest('.xabia-sticky-footer-box') : null;
        const inward = footerBox && footerBox.classList.contains('xabia-mobile-preset-ultra-compact') ? 0.5 : 0.42;
        if (footerBox && footerBox.classList.contains('xabia-trigger--bottom-left')) {
            return { x: inward, y: -0.02 };
        }
        return { x: -inward, y: -0.02 };
    }

    function mobilePresetClass(trigger) {
        const preset = (trigger.getAttribute('data-mobile-preset') || 'compact').replace(/_/g, '-');
        return preset === 'ultra-compact' || preset === 'compact'
            ? 'xabia-mobile-preset-' + preset
            : 'xabia-mobile-preset-compact';
    }

    function bindMobilePanelBehavior(trigger, panel) {
        const presetClass = mobilePresetClass(trigger);
        panel.classList.add(presetClass);

        const input = panel.querySelector('.xabia-input-field');
        if (!input || !window.visualViewport) {
            return;
        }

        function syncKeyboardState() {
            const keyboardLikelyOpen = window.visualViewport.height < window.innerHeight * 0.78;
            document.body.classList.toggle('xabia-keyboard-open', keyboardLikelyOpen);
        }

        input.addEventListener('focus', function () {
            window.setTimeout(syncKeyboardState, 120);
        });
        input.addEventListener('blur', function () {
            window.setTimeout(function () {
                document.body.classList.remove('xabia-keyboard-open');
            }, 180);
        });
        window.visualViewport.addEventListener('resize', syncKeyboardState);
        window.visualViewport.addEventListener('scroll', syncKeyboardState);
    }

    function portalPanel(panel) {
        if (!panel || panel.dataset.xabiaPortaled === '1') {
            return;
        }
        document.body.appendChild(panel);
        panel.dataset.xabiaPortaled = '1';
    }

    function ensurePanelCloseButton(panel, onClose) {
        if (!panel) {
            return;
        }
        const header = panel.querySelector('.xabia-chat-header');
        let btn = panel.querySelector('.xabia-panel-close');
        if (!btn) {
            btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'xabia-panel-close';
            btn.setAttribute('aria-label', (typeof window.xabiaI18n === 'object' && window.xabiaI18n && window.xabiaI18n.closeChat)
                ? window.xabiaI18n.closeChat
                : ((rootCfg.i18n && rootCfg.i18n.closeChat) ? rootCfg.i18n.closeChat : 'Cerrar chat'));
            btn.innerHTML = '<svg class="xabia-lucide" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>';
            if (header) {
                header.insertBefore(btn, header.firstChild);
            } else {
                panel.insertBefore(btn, panel.firstChild);
            }
        } else if (header && btn.parentElement !== header) {
            header.insertBefore(btn, header.firstChild);
        } else if (!header && btn.parentElement !== panel) {
            panel.insertBefore(btn, panel.firstChild);
        }
        if (!btn.dataset.xabiaCloseBound) {
            btn.dataset.xabiaCloseBound = '1';
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                onClose();
            });
        }
    }

    function isMobileViewport() {
        return window.matchMedia && window.matchMedia('(max-width: 768px)').matches;
    }

    function syncBodyMobilePreset(trigger, open) {
        document.body.classList.remove('xabia-mobile-preset-compact', 'xabia-mobile-preset-ultra-compact');
        if (!open || !isMobileViewport()) {
            return;
        }
        const preset = (trigger.getAttribute('data-mobile-preset') || 'compact').replace(/_/g, '-');
        if (preset === 'ultra-compact' || preset === 'compact') {
            document.body.classList.add('xabia-mobile-preset-' + preset);
        } else {
            document.body.classList.add('xabia-mobile-preset-compact');
        }
    }

    function resolveSpeakingAvatar(trigger, panel) {
        const cfg = projectCfg(trigger);
        const triggerFlag = trigger.getAttribute('data-speaking-avatar');
        const panelFlag = panel.getAttribute('data-speaking-avatar');
        let speakingAvatar = true;
        if (triggerFlag !== null && triggerFlag !== '') {
            speakingAvatar = triggerFlag !== '0';
        } else if (cfg && Object.prototype.hasOwnProperty.call(cfg, 'speakingAvatar')) {
            speakingAvatar = !!Number(cfg.speakingAvatar);
        } else if (panelFlag !== null && panelFlag !== '') {
            speakingAvatar = panelFlag !== '0';
        }
        return speakingAvatar;
    }

    /**
     * Immersive (avatar parlante) si el proyecto lo tiene activo y el markup del stage existe.
     * El mute/altavoz controla solo el TTS; no cambia el layout immersive.
     */
    function shouldUseImmersive(open, trigger, panel) {
        if (!open || !panel) {
            return false;
        }
        if (!resolveSpeakingAvatar(trigger, panel)) {
            return false;
        }
        return !!panel.querySelector('.xabia-immersive-avatar-stage');
    }

    function applyImmersiveState(open, immersive, trigger, panel, overlay, layout, footerBox) {
        document.body.classList.toggle('xabia-immersive-open', open && immersive);
        panel.classList.toggle('xabia-immersive-mode', open && immersive);
        const stage = panel.querySelector('.xabia-immersive-avatar-stage');
        if (stage) {
            stage.setAttribute('aria-hidden', (open && immersive) ? 'false' : 'true');
        }
        const voiceHero = panel.querySelector('.xabia-voice-hero');
        if (voiceHero) {
            if (open && immersive) {
                voiceHero.setAttribute('hidden', 'hidden');
                voiceHero.setAttribute('aria-hidden', 'true');
                voiceHero.style.display = 'none';
            } else if (open) {
                voiceHero.removeAttribute('hidden');
                voiceHero.style.removeProperty('display');
            }
        }

        const isOverlayLayout = layout === 'centered_modal' || layout === 'full_screen';
        /* Flotante = no bloqueante; overlay solo en immersive o layouts modal */
        if (overlay) {
            const showOverlay = open && !isMobileViewport() && (immersive || isOverlayLayout);
            overlay.hidden = !showOverlay;
            overlay.classList.toggle('is-active', showOverlay);
            overlay.setAttribute('aria-hidden', showOverlay ? 'false' : 'true');
        }

        if (open && immersive) {
            const immersiveWrap = panel.querySelector('.xabia-immersive-avatar-stage .xabia-kinetic-wrapper');
            if (immersiveWrap) {
                loadScript(rootCfg.gsapUrl || '', function () {
                    initKineticAvatar(immersiveWrap);
                });
            }
        }
    }

    function syncImmersiveForPanel(panel) {
        if (!panel || !panel.classList.contains('is-active')) {
            return;
        }
        const pid = panel.getAttribute('data-project') || '';
        const trigger = document.querySelector(
            '.xabia-interface-trigger[data-project="' + pid + '"].is-active'
        ) || document.querySelector(
            '.xabia-interface-trigger[data-project="' + pid + '"]'
        );
        if (!trigger) {
            return;
        }
        const footerBox = trigger.closest('.xabia-sticky-footer-box');
        const overlayId = trigger.getAttribute('data-overlay-id');
        const overlay = overlayId ? document.getElementById(overlayId) : null;
        const layout = (projectCfg(trigger).panelLayout)
            || trigger.getAttribute('data-panel-layout')
            || 'right_float';
        const immersive = shouldUseImmersive(true, trigger, panel);
        applyImmersiveState(true, immersive, trigger, panel, overlay, layout, footerBox);
    }

    function setPanelOpen(open, trigger, panel, overlay, layout, footerBox) {
        const immersive = shouldUseImmersive(open, trigger, panel);
        document.body.classList.toggle('xabia-open', open);
        document.body.classList.toggle('xabia-mobile-chat-stack', open && isMobileViewport());
        if (!open) {
            document.body.classList.remove(
                'xabia-keyboard-open',
                'xabia-mobile-preset-compact',
                'xabia-mobile-preset-ultra-compact',
                'xabia-immersive-open'
            );
        }
        trigger.classList.toggle('is-active', open);
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        panel.classList.toggle('is-active', open);
        panel.setAttribute('aria-hidden', open ? 'false' : 'true');
        applyImmersiveState(open, immersive, trigger, panel, overlay, layout, footerBox);
        syncBodyMobilePreset(trigger, open);

        if (footerBox) {
            footerBox.classList.toggle('xabia-trigger-over-blur', open && immersive);
            footerBox.classList.toggle('xabia-avatar-below-chat', open && isMobileViewport() && immersive);
            if (!open) {
                footerBox.classList.remove('footer-hidden');
            }
            const hint = footerBox.querySelector('.xabia-avatar-hint-bubble');
            if (hint) {
                hint.classList.remove('is-visible');
            }
        }

        if (open && !isMobileViewport()) {
            const input = panel.querySelector('.xabia-input-field');
            if (input) {
                window.setTimeout(function () { input.focus(); }, 280);
            }
        }
    }

    function bindScrollBehavior(footerBox) {
        footerBox.classList.remove('footer-hidden');
        window.addEventListener('scroll', function () {
            const currentScrollY = window.scrollY;

            if (!document.body.classList.contains('xabia-open') && footerBox) {
                if (!footerBox.classList.contains('is-anchored')) {
                    if (currentScrollY > lastScrollY + 2) {
                        footerBox.classList.remove('footer-hidden');
                    } else if (currentScrollY < lastScrollY - 2 && currentScrollY > 100) {
                        footerBox.classList.add('footer-hidden');
                    }
                }
            }

            lastScrollY = currentScrollY;
        }, { passive: true });
    }

    function bindAvatarHint(footerBox) {
        if (!footerBox) {
            return;
        }
        let bubble = footerBox.querySelector('.xabia-avatar-hint-bubble');
        if (!bubble) {
            bubble = document.createElement('span');
            bubble.className = 'xabia-avatar-hint-bubble';
            bubble.setAttribute('aria-hidden', 'true');
            footerBox.appendChild(bubble);
        }
        const hints = ['?', (rootCfg.i18n && rootCfg.i18n.avatarHint) ? rootCfg.i18n.avatarHint : '¿Te puedo ayudar?'];
        let showTimer = null;
        let hideTimer = null;

        function clearTimers() {
            if (showTimer) {
                window.clearTimeout(showTimer);
                showTimer = null;
            }
            if (hideTimer) {
                window.clearTimeout(hideTimer);
                hideTimer = null;
            }
        }

        function canShow() {
            return !document.body.classList.contains('xabia-open')
                && !footerBox.classList.contains('footer-hidden');
        }

        function scheduleNext() {
            clearTimers();
            const wait = 12000 + Math.floor(Math.random() * 18000);
            showTimer = window.setTimeout(function () {
                if (!canShow()) {
                    scheduleNext();
                    return;
                }
                bubble.textContent = Math.random() < 0.38 ? '?' : hints[1];
                bubble.classList.add('is-visible');
                hideTimer = window.setTimeout(function () {
                    bubble.classList.remove('is-visible');
                    scheduleNext();
                }, 2600);
            }, wait);
        }

        scheduleNext();
    }

    function bindFooterAnchor(footerBox) {
        const siteFooter = findSiteFooter();
        if (!siteFooter || !footerBox) {
            return;
        }

        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    footerBox.classList.add('is-anchored');
                    footerBox.classList.remove('footer-hidden');
                } else {
                    footerBox.classList.remove('is-anchored');
                }
            });
        }, { threshold: 0.1 });

        observer.observe(siteFooter);
    }

    function bindTrigger(trigger) {
        if (!trigger || trigger.dataset.xabiaBound === '1') {
            return;
        }
        trigger.dataset.xabiaBound = '1';

        const footerBox = trigger.closest('.xabia-sticky-footer-box');
        const selector = trigger.getAttribute('data-panel-selector') || '.xabia-chatbox';
        const overlayId = trigger.getAttribute('data-overlay-id');

        function resolvePanel() {
            return document.querySelector(selector);
        }

        function resolveOverlay() {
            return overlayId ? document.getElementById(overlayId) : null;
        }

        let panel = resolvePanel();
        let overlay = resolveOverlay();

        if (!panel) {
            let attempts = 0;
            const retry = window.setInterval(function () {
                attempts += 1;
                panel = resolvePanel();
                if (panel || attempts >= 40) {
                    window.clearInterval(retry);
                    if (!panel) {
                        trigger.dataset.xabiaBound = '0';
                        return;
                    }
                    overlay = resolveOverlay();
                    finishBind(trigger, footerBox, panel, overlay);
                }
            }, 250);
            return;
        }

        finishBind(trigger, footerBox, panel, overlay);
    }

    function bindAllTriggers(root) {
        const scope = root && root.querySelectorAll ? root : document;
        const triggers = scope.querySelectorAll
            ? scope.querySelectorAll('.xabia-interface-trigger[data-project]')
            : [];
        if (!triggers.length && scope !== document) {
            return;
        }
        const list = triggers.length
            ? triggers
            : document.querySelectorAll('.xabia-interface-trigger[data-project]');
        if (!list.length) {
            return;
        }
        document.body.classList.add('xabia-native-interface');
        list.forEach(bindTrigger);
    }

    function finishBind(trigger, footerBox, panel, overlay) {

        const cfg = projectCfg(trigger);
        const layout = cfg.panelLayout || trigger.getAttribute('data-panel-layout') || 'right_float';
        const layoutCls = layoutClass(layout);
        const isOverlayLayout = layout === 'centered_modal' || layout === 'full_screen';

        portalPanel(panel);
        panel.classList.add('xabia-panel-shell', layoutCls);
        bindMobilePanelBehavior(trigger, panel);
        try {
            document.dispatchEvent(new CustomEvent('xabia:chatbox:mounted'));
        } catch (eMount) {}
        if (typeof window.xabiaChatboxInitAll === 'function') {
            window.xabiaChatboxInitAll();
        }
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', isOverlayLayout ? 'true' : 'false');
        panel.setAttribute('aria-hidden', 'true');

        if (overlay) {
            overlay.classList.add(layoutCls);
        }

        function closePanel() {
            try {
                if (window.jQuery && typeof window.jQuery.fn !== 'undefined') {
                    var $box = window.jQuery(panel);
                    if ($box.length && typeof window.xabiaStopAvatarLipSync === 'function') {
                        window.xabiaStopAvatarLipSync($box);
                    } else if ($box.length) {
                        $box.removeClass('xabia-is-speaking xabia-is-thinking');
                        try { document.body.classList.remove('xabia-avatar-speaking', 'xabia-avatar-thinking'); } catch (eBody) {}
                    }
                }
            } catch (eClose) {}
            setPanelOpen(false, trigger, panel, overlay, layout, footerBox);
        }

        function togglePanel() {
            const open = !panel.classList.contains('is-active');
            setPanelOpen(open, trigger, panel, overlay, layout, footerBox);
        }

        ensurePanelCloseButton(panel, closePanel);

        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            togglePanel();
        });

        if (overlay) {
            overlay.addEventListener('click', closePanel);
        }

        /* Clic en la zona blur / avatar parlante → cerrar (móvil y escritorio) */
        panel.addEventListener('click', function (e) {
            if (!panel.classList.contains('is-active') || !panel.classList.contains('xabia-immersive-mode')) {
                return;
            }
            const stage = e.target.closest('.xabia-immersive-avatar-stage');
            if (stage && panel.contains(stage)) {
                e.preventDefault();
                closePanel();
            }
        });

        var panelClickProxy = false;
        if (panel.dataset.xabiaClickProxy !== '1') {
            panel.dataset.xabiaClickProxy = '1';
            panel.addEventListener('click', function (e) {
                if (panelClickProxy) {
                    return;
                }
                var jq = window.jQuery;
                if (!jq) {
                    return;
                }
                var actionable = e.target.closest(
                    '.xabia-continue, .xabia-send, .xabia-mic, .xabia-mute, .xabia-panel-close, .xabia-starter-chip'
                );
                if (!actionable || !panel.contains(actionable)) {
                    /* No cortar el bubble: los handlers delegados de chatbox.js viven en document */
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                if (actionable.disabled) {
                    return;
                }
                panelClickProxy = true;
                try {
                    jq(actionable).trigger('click');
                } finally {
                    panelClickProxy = false;
                }
            });
        }

        document.addEventListener('click', function (e) {
            if (!panel.classList.contains('is-active')) {
                return;
            }
            if (panel.contains(e.target) || trigger.contains(e.target)) {
                return;
            }
            if (overlay && overlay.contains(e.target)) {
                return;
            }
            closePanel();
        });

        document.addEventListener('keydown', function (e) {
            if ((e.key === 'Escape' || e.keyCode === 27) && panel.classList.contains('is-active')) {
                e.preventDefault();
                closePanel();
            }
        });

        if (footerBox) {
            const isInline = footerBox.classList.contains('xabia-trigger--inline')
                || trigger.getAttribute('data-placement') === 'inline';
            if (!isInline) {
                bindScrollBehavior(footerBox);
                bindAvatarHint(footerBox);
            }
        }

        loadScript(rootCfg.gsapUrl || '', function () {
            const triggerWrap = trigger.querySelector('.xabia-kinetic-wrapper');
            initKineticAvatar(triggerWrap || trigger);
            const immersiveWrap = panel.querySelector('.xabia-immersive-avatar-stage .xabia-kinetic-wrapper');
            if (immersiveWrap) {
                initKineticAvatar(immersiveWrap);
            }
        });
    }

    function init() {
        bindAllTriggers(document);
        if (typeof MutationObserver === 'function') {
            const mo = new MutationObserver(function (mutations) {
                for (let i = 0; i < mutations.length; i += 1) {
                    const nodes = mutations[i].addedNodes;
                    for (let j = 0; j < nodes.length; j += 1) {
                        const node = nodes[j];
                        if (!node || node.nodeType !== 1) {
                            continue;
                        }
                        if (node.matches && node.matches('.xabia-interface-trigger[data-project]')) {
                            bindTrigger(node);
                        } else if (node.querySelectorAll) {
                            bindAllTriggers(node);
                        }
                    }
                }
            });
            mo.observe(document.documentElement, { childList: true, subtree: true });
        }
        document.addEventListener('xabia:chatbox:mounted', function () {
            bindAllTriggers(document);
        });
    }

    function openProject(projectId) {
        if (!projectId) {
            return false;
        }
        bindAllTriggers(document);
        const inline = document.querySelector(
            '.xabia-interface-trigger[data-project="' + projectId + '"][data-placement="inline"]'
        );
        const trigger = inline || document.querySelector(
            '.xabia-interface-trigger[data-project="' + projectId + '"]'
        );
        if (!trigger) {
            return false;
        }
        if (!trigger.classList.contains('is-active')) {
            trigger.click();
        }
        return true;
    }

    function closeProject(projectId) {
        if (!projectId) {
            return false;
        }
        const trigger = document.querySelector('.xabia-interface-trigger[data-project="' + projectId + '"].is-active');
        if (!trigger) {
            return false;
        }
        trigger.click();
        return true;
    }

    window.XabiaInterfaceApi = {
        open: openProject,
        close: closeProject,
        syncImmersive: syncImmersiveForPanel,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
