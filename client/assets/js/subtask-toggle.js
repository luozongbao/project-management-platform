/*
 * Client dashboard — sub-tasks toggle controller (issue-010 + issue-027).
 *
 * Loaded as an external file via <script src="assets/js/subtask-toggle.js" defer>
 * from client/dashboard.php. The file is hand-written ES5-compatible JS to
 * match the style of the existing assets/js/script.js (var only, no arrow
 * functions, no block-scoped declarations, no template literals) and to
 * ship as a single static file with no build step.
 *
 * The server-rendered markup (button id="subtasks-toggle", section
 * id="tasks-progress") and the translation strings are passed in via
 * data-* attributes on the button — this keeps the JS file static (no
 * per-page server-rendering of the script body, no <script> blocks in the
 * rendered HTML, no risk of breaking the issue-020 CSP tightening that
 * dropped 'unsafe-inline' from script-src for the public client portal).
 *
 * Contract (matches docs/issues/issue-027.md "Data flow" and
 * docs/issues/issue-010.md):
 *   - <section id="tasks-progress" data-subtasks="hidden|visible">
 *   - <button id="subtasks-toggle" data-state="hidden|visible"
 *              data-share-code="<UPPERCASE_5-5-5>"
 *              data-label-show-all="<translated>"
 *              data-label-show-top="<translated>"
 *              aria-controls="tasks-progress"
 *              aria-pressed="true|false">
 *   - <ul class="task-tree root"> + nested <ul class="task-tree"> sub-trees
 *   - localStorage key: 'client_dash.subtasks_visible.' + <share code>
 *   - localStorage values: "0" = collapsed, "1" = expanded
 *
 * The aria-hidden reflection uses ul.task-tree:not(.root) to mirror the
 * CSS rule — the root <ul.task-tree.root> is intentionally always visible.
 *
 * Behaviour (no flash on first paint): if the stored preference is "1",
 * applyState('visible') runs BEFORE the click handler is attached, so
 * the initial paint already shows the expanded sub-trees.
 */
(function () {
    var section = document.getElementById('tasks-progress');
    var btn = document.getElementById('subtasks-toggle');
    if (!section || !btn) { return; }
    var labelEl = btn.querySelector('.subtasks-toggle-label');
    var iconEl = btn.querySelector('i');
    var shareCode = btn.getAttribute('data-share-code') || '';
    var LABEL_SHOW_ALL = btn.getAttribute('data-label-show-all') || '';
    var LABEL_SHOW_TOP = btn.getAttribute('data-label-show-top') || '';
    var STORAGE_KEY = 'client_dash.subtasks_visible.' + shareCode;

    function readPref() {
        try { return localStorage.getItem(STORAGE_KEY); } catch (e) { return null; }
    }
    function writePref(v) {
        try { localStorage.setItem(STORAGE_KEY, v); } catch (e) { /* no-op */ }
    }
    function applyState(state) {
        section.setAttribute('data-subtasks', state);
        btn.setAttribute('data-state', state);
        var visible = (state === 'visible');
        btn.setAttribute('aria-pressed', visible ? 'true' : 'false');
        if (labelEl) { labelEl.textContent = visible ? LABEL_SHOW_TOP : LABEL_SHOW_ALL; }
        if (iconEl) {
            iconEl.className = visible ? 'fas fa-eye-slash' : 'fas fa-eye';
        }
        // Reflect on nested <ul class="task-tree"> nodes so screen readers
        // can skip the subtree when collapsed. We must scope the selector
        // with :not(.root) to match the CSS rule — the root
        // <ul class="task-tree root"> is intentionally always visible,
        // so flipping its aria-hidden to "true" hides the top-level
        // workstream names from screen readers (issue-010 tester-note-001
        // bug 1).
        var subs = section.querySelectorAll('ul.task-tree:not(.root)');
        for (var i = 0; i < subs.length; i++) {
            subs[i].setAttribute('aria-hidden', visible ? 'false' : 'true');
        }
    }

    // First-paint: honour the stored preference without a flash.
    var stored = readPref();
    if (stored === '1') {
        applyState('visible');
    } else {
        // Default already encoded in the server-rendered markup; just mirror
        // the aria-hidden attributes so screen readers see the same state.
        applyState('hidden');
    }

    btn.addEventListener('click', function () {
        var next = (btn.getAttribute('data-state') === 'visible') ? 'hidden' : 'visible';
        applyState(next);
        writePref(next === 'visible' ? '1' : '0');
    });
})();
