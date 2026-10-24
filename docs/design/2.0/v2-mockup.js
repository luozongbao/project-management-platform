/* ==========================================================================
   Project Management Platform — v2.0 Mockup interactivity
   Pure vanilla JS, no dependencies. Demonstrates the interaction patterns
   the production implementation must reproduce (dev ports these to
   assets/js/script.js / assets/js/subtask-manager.js).
   ========================================================================== */
(function () {
    'use strict';

    /* ---------------------------------------------------------------- */
    /* Sidebar collapse                                                  */
    /* ---------------------------------------------------------------- */
    function initSidebar() {
        var toggle = document.querySelector('[data-sidebar-toggle]');
        var shell = document.querySelector('.app-shell');
        if (!toggle || !shell) return;

        var KEY = 'pmp.v2.sidebar.collapsed';
        var stored = null;
        try { stored = localStorage.getItem(KEY); } catch (e) { /* private mode */ }

        if (stored === '1') shell.classList.add('is-collapsed');

        toggle.addEventListener('click', function () {
            shell.classList.toggle('is-collapsed');
            try {
                localStorage.setItem(KEY, shell.classList.contains('is-collapsed') ? '1' : '0');
            } catch (e) { /* ignore */ }
        });
    }

    /* ---------------------------------------------------------------- */
    /* Dropdowns (user menu, About panel)                                */
    /* ---------------------------------------------------------------- */
    function initDropdowns() {
        document.addEventListener('click', function (ev) {
            var triggers = document.querySelectorAll('[data-dropdown]');
            for (var i = 0; i < triggers.length; i++) {
                var trigger = triggers[i];
                var menu = document.getElementById(trigger.getAttribute('data-dropdown'));
                if (!menu) continue;
                var isOpen = menu.classList.contains('is-open');
                // Close all
                document.querySelectorAll('.dropdown__menu.is-open').forEach(function (m) {
                    m.classList.remove('is-open');
                    m.hidden = true;
                });
                if (!isOpen && menu.contains(ev.target) === false && ev.target !== trigger) {
                    menu.classList.add('is-open');
                    menu.hidden = false;
                    trigger.setAttribute('aria-expanded', 'true');
                }
            }
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') {
                document.querySelectorAll('.dropdown__menu.is-open').forEach(function (m) {
                    m.classList.remove('is-open');
                    m.hidden = true;
                });
            }
        });
    }

    /* ---------------------------------------------------------------- */
    /* Tabs                                                               */
    /* ---------------------------------------------------------------- */
    function initTabs() {
        document.querySelectorAll('[data-tabs]').forEach(function (group) {
            var tabs = group.querySelectorAll('.tab');
            tabs.forEach(function (tab) {
                tab.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    var target = tab.getAttribute('data-tab');
                    tabs.forEach(function (t) {
                        var on = t === tab;
                        t.classList.toggle('is-active', on);
                        t.setAttribute('aria-selected', on ? 'true' : 'false');
                    });
                    group.parentElement.querySelectorAll('[data-tab-panel]').forEach(function (p) {
                        p.hidden = p.getAttribute('data-tab-panel') !== target;
                    });
                });
            });
        });
    }

    /* ---------------------------------------------------------------- */
    /* Range slider <-> number input (task completion %)                 */
    /* ---------------------------------------------------------------- */
    function initRangeSync() {
        document.querySelectorAll('[data-range-sync]').forEach(function (range) {
            var target = document.querySelector(range.getAttribute('data-range-sync'));
            var out = document.querySelector(range.getAttribute('data-range-out') || '#none');
            function push(fromRange) {
                var v = parseInt(range.value, 10);
                if (!isNaN(v)) {
                    if (fromRange && target) target.value = v;
                    if (out) out.textContent = v + '%';
                }
            }
            range.addEventListener('input', function () { push(true); });
            if (target) {
                target.addEventListener('input', function () {
                    var v = parseInt(target.value, 10);
                    if (!isNaN(v)) {
                        range.value = v;
                        if (out) out.textContent = v + '%';
                    }
                });
            }
            push(false);
        });
    }

    /* ---------------------------------------------------------------- */
    /* Conditional fields: task status -> reason textarea (v1.8.0)      */
    /* ---------------------------------------------------------------- */
    function initStatusReason() {
        var statusSel = document.querySelector('[data-status-select]');
        if (!statusSel) return;
        var panels = {
            on_hold: document.querySelector('[data-reason-panel="on_hold"]'),
            delayed: document.querySelector('[data-reason-panel="delayed"]')
        };
        var delayHint = document.querySelector('[data-delay-hint]');

        function sync() {
            var v = statusSel.value;
            Object.keys(panels).forEach(function (k) {
                if (!panels[k]) return;
                var show = (k === 'on_hold' && v === 'on_hold') ||
                           (k === 'delayed' && v !== 'completed' && v !== 'on_hold');
                panels[k].hidden = !show;
                var ta = panels[k].querySelector('textarea');
                var lab = panels[k].querySelector('.req');
                if (ta) {
                    ta.required = show;
                    if (!show) ta.setAttribute('data-demo-disabled', '1');
                    else ta.removeAttribute('data-demo-disabled');
                }
                if (lab) lab.hidden = !show;
            });
            if (delayHint) delayHint.hidden = statusSel.value === 'completed';
        }
        statusSel.addEventListener('change', sync);
        sync();
    }

    /* ---------------------------------------------------------------- */
    /* Copy to clipboard (share code, share URL)                         */
    /* ---------------------------------------------------------------- */
    function initCopy() {
        document.querySelectorAll('[data-copy]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var text = btn.getAttribute('data-copy');
                var label = btn.innerHTML;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text);
                }
                btn.innerHTML = '<i class="fas fa-check"></i> Copied';
                setTimeout(function () { btn.innerHTML = label; }, 1600);
            });
        });
    }

    /* ---------------------------------------------------------------- */
    /* Toast demo                                                        */
    /* ---------------------------------------------------------------- */
    var toastStack = null;
    function toast(msg, kind) {
        if (!toastStack) {
            toastStack = document.createElement('div');
            toastStack.className = 'toast-stack';
            document.body.appendChild(toastStack);
        }
        var el = document.createElement('div');
        el.className = 'toast' + (kind ? ' toast--' + kind : '');
        el.innerHTML = '<i class="fas fa-circle-check"></i> ' + msg;
        toastStack.appendChild(el);
        setTimeout(function () { el.remove(); }, 2600);
    }
    window.pmpToast = toast;

    function initToastTriggers() {
        document.querySelectorAll('[data-toast]').forEach(function (el) {
            el.addEventListener('click', function () {
                toast(el.getAttribute('data-toast'), el.getAttribute('data-toast-kind'));
            });
        });
    }

    /* ---------------------------------------------------------------- */
    /* Modal open/close                                                  */
    /* ---------------------------------------------------------------- */
    function initModals() {
        document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var m = document.getElementById(btn.getAttribute('data-modal-open'));
                if (m) { m.hidden = false; document.body.style.overflow = 'hidden'; }
            });
        });
        document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var m = btn.closest('.modal-backdrop');
                if (m) { m.hidden = true; document.body.style.overflow = ''; }
            });
        });
        document.querySelectorAll('.modal-backdrop').forEach(function (bd) {
            bd.addEventListener('click', function (ev) {
                if (ev.target === bd) { bd.hidden = true; document.body.style.overflow = ''; }
            });
        });
    }

    /* ---------------------------------------------------------------- */
    /* Language toggle (ENG / 中文 demo)                                 */
    /* ---------------------------------------------------------------- */
    function initLang() {
        var KEY = 'pmp.v2.lang';
        function apply(lang) {
            document.documentElement.setAttribute('data-lang', lang);
            document.querySelectorAll('[data-lang-label]').forEach(function (el) {
                el.textContent = el.getAttribute('data-lang-label') === 'zh' ? '中文' : 'ENG';
            });
            document.querySelectorAll('[data-lang-switch]').forEach(function (sw) {
                sw.querySelectorAll('button').forEach(function (b) {
                    b.classList.toggle('is-active', b.getAttribute('data-lang-set') === lang);
                });
            });
            document.querySelectorAll('[data-i18n]').forEach(function (el) {
                var pair = el.getAttribute('data-i18n').split('|');
                el.textContent = (lang === 'zh' ? pair[1] : pair[0]);
            });
            try { localStorage.setItem(KEY, lang); } catch (e) { /* ignore */ }
        }
        document.querySelectorAll('[data-lang-set]').forEach(function (b) {
            b.addEventListener('click', function () {
                apply(b.getAttribute('data-lang-set'));
            });
        });
        var saved = null;
        try { saved = localStorage.getItem(KEY); } catch (e) { /* ignore */ }
        apply(saved || 'en');
    }

    /* ---------------------------------------------------------------- */
    /* Filter form demo (prevent default submit, show a toast)          */
    /* ---------------------------------------------------------------- */
    function initFilters() {
        document.querySelectorAll('[data-filter-form]').forEach(function (form) {
            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                toast('Filters applied (demo)', 'success');
            });
        });
        document.querySelectorAll('[data-filter-reset]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var form = btn.closest('form');
                if (form) form.reset();
                toast('Filters cleared (demo)', 'warning');
            });
        });
    }

    /* ---------------------------------------------------------------- */
    /* Subtask tree expand/collapse                                      */
    /* ---------------------------------------------------------------- */
    function initTree() {
        document.querySelectorAll('[data-tree-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = document.querySelector(btn.getAttribute('data-tree-toggle'));
                if (!target) return;
                var hidden = target.hasAttribute('hidden');
                if (hidden) { target.removeAttribute('hidden'); }
                else { target.setAttribute('hidden', ''); }
                btn.setAttribute('aria-expanded', hidden ? 'true' : 'false');
                var icon = btn.querySelector('i');
                if (icon) icon.className = hidden ? 'fas fa-chevron-down' : 'fas fa-chevron-right';
            });
        });
    }

    /* ---------------------------------------------------------------- */
    /* Boot                                                               */
    /* ---------------------------------------------------------------- */
    document.addEventListener('DOMContentLoaded', function () {
        initSidebar();
        initDropdowns();
        initTabs();
        initRangeSync();
        initStatusReason();
        initCopy();
        initToastTriggers();
        initModals();
        initLang();
        initFilters();
        initTree();
    });
})();
