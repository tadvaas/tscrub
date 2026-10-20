/**
 * ts-dialog – keyboard containment for the dashboard's modal dialogs.
 *
 * Injected inline into <head> by vite.config.js (plugin `tscrub-a11y-shell`), so
 * it is defined before any page script runs, on every page, with no bundler
 * entry and no load-order dependency. Pages call:
 *
 *   window.TSDialog.open(modalEl [, triggerEl])
 *   window.TSDialog.close(modalEl)
 *
 * Why this exists: every dialog in the dashboard already declares
 * role="dialog" aria-modal="true", and that attribute is a promise. Without
 * containment, Tab walks straight out of an open dialog into the page behind
 * it, and closing leaves focus at the top of the document. Both are invisible
 * to a mouse user and obvious to a keyboard user.
 *
 * The dialogs keep their own open/close visuals (classList + body overflow);
 * this only owns focus, the Tab cycle and the return trip.
 */
(function () {
  if (window.TSDialog) { return; }

  var FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])'
  ].join(',');

  // Topmost dialog is the last entry: a dialog can open another (a detail
  // drawer over a list), and only the top one may own the Tab cycle.
  var stack = [];

  function focusables(root) {
    var out = [];
    var nodes = root.querySelectorAll(FOCUSABLE);
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      // Skip anything not rendered (display:none inside the panel) — a hidden
      // control cannot receive focus, so including it would break the cycle.
      if (!el.offsetWidth && !el.offsetHeight && el !== document.activeElement) { continue; }
      if (el.getAttribute('aria-hidden') === 'true') { continue; }
      out.push(el);
    }
    return out;
  }

  function top() {
    return stack.length ? stack[stack.length - 1] : null;
  }

  function open(dialog, trigger) {
    if (!dialog) { return; }
    // A trigger that is no longer in the document (row re-rendered by the 10s
    // poll) cannot take focus back, so fall back to nothing rather than
    // throwing the user to the top of the page.
    var origin = trigger || document.activeElement;
    if (!origin || origin === document.body || origin === document.documentElement) {
      origin = null;
    }
    stack.push({ dialog: dialog, trigger: origin });

    dialog.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    var list = focusables(dialog);
    var target = dialog.querySelector('[data-autofocus]') || list[0] || dialog;
    if (target === dialog && !dialog.hasAttribute('tabindex')) {
      dialog.setAttribute('tabindex', '-1');
    }
    try { target.focus(); } catch (e) { /* focus is best-effort */ }
  }

  function close(dialog) {
    if (!dialog) { return; }
    dialog.classList.add('hidden');

    var entry = null;
    for (var i = stack.length - 1; i >= 0; i--) {
      if (stack[i].dialog === dialog) { entry = stack.splice(i, 1)[0]; break; }
    }
    if (!stack.length) { document.body.style.overflow = ''; }

    var trigger = entry && entry.trigger;
    if (trigger && document.contains(trigger) && typeof trigger.focus === 'function') {
      try { trigger.focus(); } catch (e) { /* ignore */ }
    }
  }

  // One listener for every dialog: cycle Tab / Shift-Tab inside the topmost
  // panel, and pull focus back in if it is somehow outside (clicks, browser
  // chrome returning to the document).
  document.addEventListener('keydown', function (e) {
    var current = top();
    if (!current) { return; }
    var dialog = current.dialog;

    // Escape is left entirely to the page: its own handler closes the dialog,
    // and swallowing the key here (this listener is in the capture phase) would
    // stop that handler ever running.
    if (e.key !== 'Tab') { return; }

    var list = focusables(dialog);
    if (!list.length) { e.preventDefault(); return; }

    var first = list[0];
    var last = list[list.length - 1];
    var active = document.activeElement;

    if (!dialog.contains(active)) {
      e.preventDefault();
      first.focus();
      return;
    }
    if (e.shiftKey && active === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && active === last) {
      e.preventDefault();
      first.focus();
    }
  }, true);

  window.TSDialog = {
    open: open,
    close: close,
    isOpen: function () { return stack.length > 0; }
  };
})();
