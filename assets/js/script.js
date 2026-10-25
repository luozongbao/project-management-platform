// Project Management System JavaScript

document.addEventListener('DOMContentLoaded', function() {
    // Initialize all interactive components
    initDropdowns();
    initFormValidation();
    initProgressBars();
    initDateInputs();
    initConfirmDialogs();
    initTooltips();
    // v2.0 components (ported from docs/design/2.0/v2-mockup.js)
    initModals();
    initRangeSync();
    initStatusReason();
    initCopy();
    initToastTriggers();
});

// Dropdown functionality
function initDropdowns() {
    const dropdowns = document.querySelectorAll('.nav-dropdown');
    
    dropdowns.forEach(dropdown => {
        const toggle = dropdown.querySelector('.dropdown-toggle');
        const menu = dropdown.querySelector('.dropdown-menu');
        
        if (toggle && menu) {
            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!dropdown.contains(e.target)) {
                    menu.style.opacity = '0';
                    menu.style.visibility = 'hidden';
                    menu.style.transform = 'translateY(-10px)';
                }
            });
        }
    });
}

// Form validation
function initFormValidation() {
    const forms = document.querySelectorAll('form[data-validate]');
    
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!validateForm(form)) {
                e.preventDefault();
                return false;
            }
        });
        
        // Real-time validation
        const inputs = form.querySelectorAll('input, textarea, select');
        inputs.forEach(input => {
            input.addEventListener('blur', function() {
                validateField(input);
            });
            
            input.addEventListener('input', function() {
                clearFieldError(input);
            });
        });
    });
}

// Validate entire form
function validateForm(form) {
    let isValid = true;
    const inputs = form.querySelectorAll('input[required], textarea[required], select[required]');
    
    inputs.forEach(input => {
        if (!validateField(input)) {
            isValid = false;
        }
    });
    
    return isValid;
}

// Validate individual field
function validateField(input) {
    const value = input.value.trim();
    let isValid = true;
    let errorMessage = '';
    
    // Required validation
    if (input.hasAttribute('required') && !value) {
        errorMessage = 'This field is required';
        isValid = false;
    }
    
    // Email validation
    if (input.type === 'email' && value && !isValidEmail(value)) {
        errorMessage = 'Please enter a valid email address';
        isValid = false;
    }
    
    // Password confirmation
    if (input.name === 'confirm_password') {
        const password = document.querySelector('input[name="password"]');
        if (password && value !== password.value) {
            errorMessage = 'Passwords do not match';
            isValid = false;
        }
    }
    
    // Date validation
    if (input.type === 'date' && value) {
        const selectedDate = new Date(value);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        if (input.hasAttribute('data-min-today') && selectedDate < today) {
            errorMessage = 'Date cannot be in the past';
            isValid = false;
        }
    }
    
    // Display error
    if (isValid) {
        clearFieldError(input);
    } else {
        showFieldError(input, errorMessage);
    }
    
    return isValid;
}

// Show field error
function showFieldError(input, message) {
    clearFieldError(input);
    
    input.classList.add('error');
    
    const errorElement = document.createElement('div');
    errorElement.className = 'field-error';
    errorElement.textContent = message;
    
    input.parentNode.appendChild(errorElement);
}

// Clear field error
function clearFieldError(input) {
    input.classList.remove('error');
    
    const existingError = input.parentNode.querySelector('.field-error');
    if (existingError) {
        existingError.remove();
    }
}

// Email validation helper
function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

// Animate progress bars
function initProgressBars() {
    const progressBars = document.querySelectorAll('.progress-fill');
    
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const progressBar = entry.target;
                const width = progressBar.style.width;
                
                // Reset and animate
                progressBar.style.width = '0%';
                setTimeout(() => {
                    progressBar.style.width = width;
                }, 100);
                
                observer.unobserve(progressBar);
            }
        });
    }, observerOptions);
    
    progressBars.forEach(bar => {
        observer.observe(bar);
    });
}

// Enhanced date inputs
function initDateInputs() {
    const dateInputs = document.querySelectorAll('input[type="date"]');
    
    dateInputs.forEach(input => {
        // Set min date to today for future dates
        if (input.hasAttribute('data-min-today')) {
            const today = new Date().toISOString().split('T')[0];
            input.setAttribute('min', today);
        }
        
        // Format date display
        input.addEventListener('change', function() {
            if (input.value) {
                const date = new Date(input.value);
                const formattedDate = date.toLocaleDateString();
                input.title = `Selected: ${formattedDate}`;
            }
        });
    });
}

// Confirmation dialogs
function initConfirmDialogs() {
    const confirmButtons = document.querySelectorAll('[data-confirm]');
    
    confirmButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            const message = button.getAttribute('data-confirm');
            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });
    });
}

// Tooltips
function initTooltips() {
    const tooltipElements = document.querySelectorAll('[title]');
    
    tooltipElements.forEach(element => {
        element.addEventListener('mouseenter', function() {
            showTooltip(element);
        });
        
        element.addEventListener('mouseleave', function() {
            hideTooltip(element);
        });
    });
}

// Show tooltip
function showTooltip(element) {
    const title = element.getAttribute('title');
    if (!title) return;
    
    // Store original title and remove it to prevent default tooltip
    element.setAttribute('data-original-title', title);
    element.removeAttribute('title');
    
    const tooltip = document.createElement('div');
    tooltip.className = 'custom-tooltip';
    tooltip.textContent = title;
    
    document.body.appendChild(tooltip);
    
    // Position tooltip
    const rect = element.getBoundingClientRect();
    tooltip.style.position = 'absolute';
    tooltip.style.top = (rect.top - tooltip.offsetHeight - 8) + 'px';
    tooltip.style.left = (rect.left + rect.width / 2 - tooltip.offsetWidth / 2) + 'px';
    tooltip.style.zIndex = '9999';
    
    // Show tooltip
    setTimeout(() => {
        tooltip.classList.add('show');
    }, 10);
}

// Hide tooltip
function hideTooltip(element) {
    const tooltip = document.querySelector('.custom-tooltip');
    if (tooltip) {
        tooltip.remove();
    }
    
    // Restore original title
    const originalTitle = element.getAttribute('data-original-title');
    if (originalTitle) {
        element.setAttribute('title', originalTitle);
        element.removeAttribute('data-original-title');
    }
}

// Utility functions
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

function throttle(func, limit) {
    let inThrottle;
    return function() {
        const args = arguments;
        const context = this;
        if (!inThrottle) {
            func.apply(context, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
}

// AJAX helper
function ajax(url, options = {}) {
    const defaults = {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    };
    
    const config = { ...defaults, ...options };
    
    return fetch(url, config)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .catch(error => {
            console.error('AJAX request failed:', error);
            throw error;
        });
}

// Show loading state
function showLoading(element, text = 'Loading...') {
    const loader = document.createElement('div');
    loader.className = 'loader';
    loader.innerHTML = `
        <div class="loader-spinner"></div>
        <span>${text}</span>
    `;
    
    element.appendChild(loader);
    element.classList.add('loading');
}

// Hide loading state
function hideLoading(element) {
    const loader = element.querySelector('.loader');
    if (loader) {
        loader.remove();
    }
    element.classList.remove('loading');
}

// Notification system
function showNotification(message, type = 'info', duration = 5000) {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <span class="notification-message">${message}</span>
            <button class="notification-close">&times;</button>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    // Show notification
    setTimeout(() => {
        notification.classList.add('show');
    }, 10);
    
    // Auto hide
    setTimeout(() => {
        hideNotification(notification);
    }, duration);
    
    // Manual close
    const closeBtn = notification.querySelector('.notification-close');
    closeBtn.addEventListener('click', () => {
        hideNotification(notification);
    });
}

function hideNotification(notification) {
    notification.classList.remove('show');
    setTimeout(() => {
        if (notification.parentNode) {
            notification.parentNode.removeChild(notification);
        }
    }, 300);
}

// Add CSS for custom elements
const style = document.createElement('style');
style.textContent = `
    .field-error {
        color: #dc3545;
        font-size: 12px;
        margin-top: 5px;
        display: block;
    }
    
    input.error,
    textarea.error,
    select.error {
        border-color: #dc3545 !important;
        box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1) !important;
    }
    
    .custom-tooltip {
        background: rgba(0, 0, 0, 0.8);
        color: white;
        padding: 8px 12px;
        border-radius: 4px;
        font-size: 12px;
        opacity: 0;
        transition: opacity 0.3s;
        pointer-events: none;
    }
    
    .custom-tooltip.show {
        opacity: 1;
    }
    
    .loader {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 20px;
        color: #666;
    }
    
    .loader-spinner {
        width: 20px;
        height: 20px;
        border: 2px solid #f3f3f3;
        border-top: 2px solid #007bff;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    .loading {
        position: relative;
        opacity: 0.7;
        pointer-events: none;
    }
    
    .notification {
        position: fixed;
        top: 20px;
        right: 20px;
        min-width: 300px;
        background: white;
        border-radius: 6px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        opacity: 0;
        transform: translateX(100%);
        transition: all 0.3s ease;
        z-index: 10000;
        border-left: 4px solid;
    }
    
    .notification.show {
        opacity: 1;
        transform: translateX(0);
    }
    
    .notification-info { border-left-color: #17a2b8; }
    .notification-success { border-left-color: #28a745; }
    .notification-warning { border-left-color: #ffc107; }
    .notification-danger { border-left-color: #dc3545; }
    
    .notification-content {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 15px 20px;
    }
    
    .notification-message {
        flex: 1;
        margin-right: 10px;
    }
    
    .notification-close {
        background: none;
        border: none;
        font-size: 18px;
        cursor: pointer;
        color: #999;
        padding: 0;
        width: 20px;
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .notification-close:hover {
        color: #666;
    }
`;
document.head.appendChild(style);


/* ================================================================== */
/* v2.0 components                                                     */
/*                                                                     */
/* Ported from docs/design/2.0/v2-mockup.js so the New/Edit pages     */
/* (task_edit.php, project_edit.php, contact_edit.php) can use the     */
/* v2 markup vocabulary: .modal-backdrop, .range-row, .share-box,    */
/* data-reason-panel and so on.                                        */
/* ================================================================== */

/* ------------------------------------------------------------------ */
/* Modal open/close                                                    */
/* ------------------------------------------------------------------ */
function initModals() {
    document.querySelectorAll('[data-modal-open]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var modal = document.getElementById(btn.getAttribute('data-modal-open'));
            if (modal) {
                modal.hidden = false;
                document.body.style.overflow = 'hidden';
                // Move focus into the dialog for keyboard users.
                var focusable = modal.querySelector('input, textarea, select, button');
                if (focusable) { focusable.focus(); }
            }
        });
    });

    document.querySelectorAll('[data-modal-close]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var backdrop = btn.closest('.modal-backdrop');
            if (backdrop) {
                backdrop.hidden = true;
                document.body.style.overflow = '';
            }
        });
    });

    document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
        backdrop.addEventListener('click', function(ev) {
            // Only close when the backdrop itself was clicked, not a child.
            if (ev.target === backdrop) {
                backdrop.hidden = true;
                document.body.style.overflow = '';
            }
        });
    });

    // Escape closes the top-most open modal.
    document.addEventListener('keydown', function(ev) {
        if (ev.key !== 'Escape') return;
        var open = document.querySelectorAll('.modal-backdrop:not([hidden])');
        if (!open.length) return;
        open[open.length - 1].hidden = true;
        document.body.style.overflow = '';
    });
}

/* ------------------------------------------------------------------ */
/* Range slider <-> number input (task completion %)                   */
/* ------------------------------------------------------------------ */
function initRangeSync() {
    document.querySelectorAll('[data-range-sync]').forEach(function(range) {
        var target = document.querySelector(range.getAttribute('data-range-sync'));
        var out = document.querySelector(range.getAttribute('data-range-out') || '#none');

        function push(fromRange) {
            var v = parseInt(range.value, 10);
            if (!isNaN(v)) {
                if (fromRange && target) { target.value = v; }
                if (out) { out.textContent = v + '%'; }
            }
        }

        range.addEventListener('input', function() { push(true); });

        if (target) {
            target.addEventListener('input', function() {
                var v = parseInt(target.value, 10);
                if (!isNaN(v)) {
                    range.value = v;
                    if (out) { out.textContent = v + '%'; }
                }
            });
        }

        push(false);
    });
}

/* ------------------------------------------------------------------ */
/* Conditional fields: task status -> reason textarea                  */
/* ------------------------------------------------------------------ */
/**
 * Show / hide the Status Reason panels on the Task -> Edit form.
 *
 * There are TWO independent reasons and they are NOT mutually exclusive:
 *
 *   - "on_hold"  -- driven by the status alone.
 *   - "delayed"  -- driven by the DUE DATE (a task is delayed when its
 *                   expected completion date is in the past and it is not
 *                   completed).
 *
 * A task can be on hold AND delayed at the same time: it is blocked on an
 * external dependency and it also blew its deadline. The database models
 * these as two separate nullable columns (tasks.on_hold_reason and
 * tasks.delay_reason), task_detail.php renders both editors side by side,
 * and includes/functions.php isTaskDelayed() explicitly does NOT exclude
 * on_hold from the delayed set.
 *
 * issue-056: the delay panel's visibility used to be derived from the
 * STATUS alone ("hide it when the status is on_hold"). That coupling made
 * the form a dead end for on-hold + delayed tasks -- the delay textarea was
 * hidden AND disabled, a disabled control is not submitted, so the server
 * saw an empty delay_reason and rejected the save with "A delay reason is
 * required...". The user could never satisfy the rule.
 *
 * The fix keeps PHP as the single source of truth for the delayed
 * predicate: the server computes it and renders it into the panel's
 * data-reason-show attribute. The JS reads that attribute instead of
 * re-deriving the date rule on the initial render.
 *
 * Once the user edits EITHER input (the status or the due date) the
 * predicate has to be recomputed live, because both feed it:
 *   - due date in the past   => delayed
 *   - status != 'completed'  => still delayed
 * A completed task is never delayed, so switching the status to
 * 'completed' must close the delay panel even though the due date is
 * still in the past.
 */
function initStatusReason() {
    var statusSel = document.querySelector('[data-status-select]');
    if (!statusSel) return;

    var panels = {
        on_hold: document.querySelector('[data-reason-panel="on_hold"]'),
        delayed: document.querySelector('[data-reason-panel="delayed"]')
    };
    var delayHint = document.querySelector('[data-delay-hint]');
    var dateInput = document.querySelector('#expected_completion_date');

    // The server owns the "is this task delayed?" rule (it knows the due
    // date and the status). Read its answer from the data attribute.
    function isDelayed() {
        if (!panels.delayed) return false;
        return panels.delayed.getAttribute('data-reason-show') === '1';
    }

    // Recompute the delayed predicate the same way PHP does:
    //   expected_completion_date < today AND status != 'completed'
    // Note the status clause: 'completed' is the ONLY status that is
    // never delayed. 'on_hold' DOES still count as delayed -- that is the
    // whole point of issue-056.
    function computeDelayed() {
        if (!dateInput || !dateInput.value) return false;
        var ts = Date.parse(dateInput.value + 'T00:00:00');
        if (isNaN(ts)) return false;
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        return ts < today.getTime() && statusSel.value !== 'completed';
    }

    // Push a freshly computed predicate back into the flag the server set.
    function refreshDelayedFlag() {
        if (!panels.delayed) return;
        panels.delayed.setAttribute(
            'data-reason-show', computeDelayed() ? '1' : '0'
        );
    }

    function sync() {
        var v = statusSel.value;

        // On-hold panel: shown exactly when the status is on_hold.
        if (panels.on_hold) {
            panels.on_hold.hidden = (v !== 'on_hold');
        }

        // Delay panel: shown exactly when the task is delayed. A task that
        // is on_hold AND past its due date shows BOTH panels.
        if (panels.delayed) {
            panels.delayed.hidden = !isDelayed();
        }

        // Apply required / disabled consistently to whichever panels are
        // open. A disabled control is NOT submitted, so this is what turns
        // a hidden panel into a failed save -- it must follow the visible
        // state exactly.
        Object.keys(panels).forEach(function(key) {
            var p = panels[key];
            if (!p) return;
            var show = !p.hidden;
            var ta = p.querySelector('textarea');
            var lab = p.querySelector('.req');
            if (ta) {
                ta.required = show;
                ta.disabled = !show;
            }
            if (lab) { lab.hidden = !show; }
        });

        if (delayHint) { delayHint.hidden = !isDelayed(); }
    }

    statusSel.addEventListener('change', function() {
        // The status feeds the delayed predicate too: a completed task is
        // never delayed.
        refreshDelayedFlag();
        sync();
    });

    // Keep the delay panel in step with the due date: the user can move the
    // expected completion date, which changes whether the task is delayed.
    // Without this, an on-hold task with a future due date would keep the
    // delay panel hidden after the user moves the date into the past -- and
    // the next save would fail with the same dead-end error.
    if (dateInput && panels.delayed) {
        dateInput.addEventListener('change', function() {
            refreshDelayedFlag();
            sync();
        });
    }

    sync();
}

/* ------------------------------------------------------------------ */
/* Copy to clipboard (share code, share URL)                           */
/* ------------------------------------------------------------------ */
/**
 * Copy text to the clipboard with a real fallback chain.
 *
 * The Async Clipboard API (navigator.clipboard) is only exposed in a secure
 * context (HTTPS or localhost). On a plain-HTTP origin the property is
 * undefined, so a naive `navigator.clipboard.writeText()` throws and the
 * button silently does nothing. document.execCommand('copy') is deprecated
 * but remains the only option on plain HTTP and inside some WebViews.
 *
 * @param {string} text
 * @returns {Promise<void>} Resolves when the clipboard was written, rejects
 *                           when every mechanism failed.
 */
function pmpCopyText(text) {
    // Preferred: Clipboard API (HTTPS / localhost only).
    if (navigator.clipboard && navigator.clipboard.writeText) {
        return navigator.clipboard.writeText(text);
    }

    // Fallback: execCommand. Deprecated but still the only option on
    // plain-HTTP origins and inside older WebViews.
    return new Promise(function (resolve, reject) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '-1000px';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        ta.setSelectionRange(0, ta.value.length);
        var ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }
        document.body.removeChild(ta);
        ok ? resolve() : reject(new Error('Copy command was rejected.'));
    });
}
window.pmpCopyText = pmpCopyText;

function initCopy() {
    document.querySelectorAll('[data-copy]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var text = btn.getAttribute('data-copy');
            var label = btn.innerHTML;

            pmpCopyText(text).then(function () {
                btn.innerHTML = '<i class="fas fa-check"></i> Copied';
                btn.classList.add('is-copied');
            }).catch(function () {
                // Do not claim success when the clipboard was not written.
                btn.innerHTML = '<i class="fas fa-circle-exclamation"></i> Copy failed';
                btn.classList.add('is-copy-failed');
            }).then(function () {
                setTimeout(function () {
                    btn.innerHTML = label;
                    btn.classList.remove('is-copied', 'is-copy-failed');
                }, 1600);
            });
        });
    });
}

/* ------------------------------------------------------------------ */
/* Toast notifications                                                 */
/* ------------------------------------------------------------------ */
var toastStack = null;

function pmpToast(msg, kind) {
    if (!toastStack) {
        toastStack = document.createElement('div');
        toastStack.className = 'toast-stack';
        document.body.appendChild(toastStack);
    }
    var el = document.createElement('div');
    el.className = 'toast' + (kind ? ' toast--' + kind : '');
    el.innerHTML = '<i class="fas fa-circle-check"></i> ' + msg;
    toastStack.appendChild(el);
    setTimeout(function() { el.remove(); }, 2600);
}
window.pmpToast = pmpToast;

function initToastTriggers() {
    document.querySelectorAll('[data-toast]').forEach(function(el) {
        el.addEventListener('click', function() {
            pmpToast(el.getAttribute('data-toast'), el.getAttribute('data-toast-kind'));
        });
    });
}
