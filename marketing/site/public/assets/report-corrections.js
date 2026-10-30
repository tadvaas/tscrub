/**
 * Correcting an operator's COCID mistake — the dialog used by the record views.
 *
 * Self-contained like manual-assets.js: it builds its own DOM so a defect here
 * cannot take the host page's inline script down with it. A page only adds a
 * script tag and calls into `window.ReportCorrections`.
 *
 * Where it is offered: the footer of a medium's record view (Media) and of the
 * diagnostics and erasure record views (Devices). The list rows keep View only —
 * a correction belongs to the record, not to every line.
 *
 * A mistyped COCID is CORRECTED, never deleted: the payload, the file names, the
 * hashes and the audit trail all stay on the record. See
 * research/cocid-correction/README.md.
 *
 *   POST /api/reports/cocid    {report_id, cocid, reason}   or {from, to, reason}
 *   POST /api/reports/void     {report_id, reason}
 *   POST /api/reports/unvoid   {report_id}
 */
(function () {
  'use strict';

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = String(text);
    return n;
  }

  function apiPost(url, body) {
    return fetch('/api/csrf')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        return fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': (d && d.csrf) || '' },
          body: JSON.stringify(body || {})
        });
      })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) {
          if (!r.ok || j.ok === false) {
            var msg = (j && (j.error || j.message)) || ('Request failed (' + r.status + ')');
            throw new Error(msg);
          }
          return j;
        });
      });
  }

  // ---- modal -----------------------------------------------------------------
  var modal = null;
  var opener = null;

  // Escape must dismiss THIS dialog, not whatever modal it was opened over. The
  // host pages (devices.html) have their own document-level Escape handler that
  // closes the report modal, so ours runs in the CAPTURE phase and stops the
  // event dead: otherwise the report closes underneath and the correction dialog
  // is left floating over the list.
  function onKeyDown(e) {
    if (!modal || (e.key !== 'Escape' && e.key !== 'Esc')) return;
    e.preventDefault();
    e.stopPropagation();
    if (e.stopImmediatePropagation) e.stopImmediatePropagation();
    closeModal();
  }

  function closeModal() {
    if (modal && modal.parentNode) modal.parentNode.removeChild(modal);
    modal = null;
    document.removeEventListener('keydown', onKeyDown, true);
    if (opener && typeof opener.focus === 'function') {
      try { opener.focus(); } catch (e) { /* the opener may be gone */ }
    }
    opener = null;
  }

  function openModal(title, fields, submitLabel, onSubmit) {
    closeModal();
    opener = document.activeElement;
    modal = el('div', 'fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 px-4 py-10');
    var card = el('div', 'w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-5 shadow-xl dark:border-ink-700 dark:bg-ink-900');
    card.appendChild(el('h2', 'text-lg font-bold text-ink-900 dark:text-white', title));
    var inputs = {};
    fields.forEach(function (f) {
      var wrap = el('label', 'mt-4 block');
      wrap.appendChild(el('span', 'mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-300', f.label));
      var input;
      if (f.type === 'textarea') {
        input = el('textarea', 'ts-input w-full');
        input.rows = 3;
      } else {
        input = el('input', 'ts-input w-full');
        input.type = f.type || 'text';
      }
      if (f.maxlength) input.maxLength = f.maxlength;
      if (f.inputmode) input.inputMode = f.inputmode;
      if (f.placeholder) input.placeholder = f.placeholder;
      if (f.value !== undefined) input.value = f.value;
      wrap.appendChild(input);
      if (f.hint) wrap.appendChild(el('span', 'mt-1 block text-xs text-slate-500 dark:text-slate-400', f.hint));
      card.appendChild(wrap);
      inputs[f.name] = input;
    });
    var err = el('p', 'mt-3 hidden text-sm font-semibold text-red-600 dark:text-red-400');
    err.setAttribute('role', 'alert');
    card.appendChild(err);

    var actions = el('div', 'mt-5 flex items-center justify-end gap-2');
    var cancel = el('button', 'ts-btn ts-btn--secondary', 'Cancel');
    cancel.type = 'button';
    cancel.addEventListener('click', closeModal);
    var submit = el('button', 'ts-btn ts-btn--primary', submitLabel);
    submit.type = 'button';
    submit.addEventListener('click', function () {
      err.classList.add('hidden');
      submit.disabled = true;
      var vals = {};
      Object.keys(inputs).forEach(function (k) { vals[k] = String(inputs[k].value || '').trim(); });
      // Wrapped so a synchronous validation throw lands in the catch below rather
      // than escaping the click handler (and leaving the button disabled).
      Promise.resolve().then(function () { return onSubmit(vals); }).then(function () {
        closeModal();
        afterChange();
      }).catch(function (e) {
        err.textContent = e && e.message ? e.message : 'Something went wrong.';
        err.classList.remove('hidden');
      }).then(function () { submit.disabled = false; });
    });
    actions.appendChild(cancel);
    actions.appendChild(submit);
    card.appendChild(actions);
    modal.appendChild(card);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.body.appendChild(modal);
    document.addEventListener('keydown', onKeyDown, true);
    var first = Object.keys(inputs)[0];
    if (first) inputs[first].focus();
  }

  // ---- actions ---------------------------------------------------------------

  function correctJob(prefill) {
    openModal('Correct a job COCID',
      [
        { name: 'from', label: 'Wrong COCID', value: prefill || '', maxlength: 5, inputmode: 'numeric', hint: 'Every report filed under this ID moves to the new one.' },
        { name: 'to', label: 'Correct COCID', maxlength: 5, inputmode: 'numeric', hint: 'Five digits, as typed on the appliance.' },
        { name: 'reason', label: 'Why?', type: 'textarea', maxlength: 255, hint: 'Recorded against every report that moves.' }
      ], 'Correct job', function (v) {
        if (!v.from || !v.to || !v.reason) throw new Error('Fill in both COCIDs and a reason.');
        return apiPost('/api/reports/cocid', { from: v.from, cocid: v.to, reason: v.reason });
      });
  }

  function correctReport(row) {
    openModal('Correct this report’s COCID',
      [
        { name: 'to', label: 'Correct COCID', value: row.cocid, maxlength: 5, inputmode: 'numeric', hint: 'Moves this report only. Use “Correct a job COCID” on the Media page to move the whole job.' },
        { name: 'reason', label: 'Why?', type: 'textarea', maxlength: 255 }
      ], 'Correct report', function (v) {
        if (!v.to || !v.reason) throw new Error('Give the new COCID and a reason.');
        return apiPost('/api/reports/cocid', { report_id: row.id, cocid: v.to, reason: v.reason });
      });
  }

  function removeReport(row) {
    openModal('Remove this report from the lists',
      [
        { name: 'reason', label: 'Why?', type: 'textarea', maxlength: 255, hint: 'The report is hidden, not deleted: it keeps its data, hashes and audit trail, and can be restored. No credit is refunded.' }
      ], 'Remove from lists', function (v) {
        if (!v.reason) throw new Error('Give a reason.');
        return apiPost('/api/reports/void', { report_id: row.id, reason: v.reason });
      });
  }

  function restoreReport(row) {
    apiPost('/api/reports/unvoid', { report_id: row.id }).then(afterChange).catch(function (e) {
      window.alert(e && e.message ? e.message : 'Could not restore the report.');
    });
  }

  // After a correction the host page's own list carries the new COCID (and any
  // open record view names the old job), so hand control back to it. A page with
  // no hook reloads rather than showing stale evidence.
  function afterChange() {
    if (typeof window.onReportCorrected === 'function') {
      try { window.onReportCorrected(); } catch (e) { /* a host hook must not break us */ }
      return;
    }
    window.location.reload();
  }

  window.ReportCorrections = {
    correctReport: correctReport,
    correctJob: correctJob,
    removeReport: removeReport,
    restoreReport: restoreReport
  };
})();
