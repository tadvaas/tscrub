/**
 * Correcting an operator's COCID mistake — the "Recent uploads" card on the
 * Media page.
 *
 * Self-contained like manual-assets.js: it builds its own DOM so a defect here
 * cannot take the host page's inline script down with it. The page only supplies
 * a mount point and a script tag.
 *
 * A mistyped COCID is CORRECTED or HIDDEN, never deleted: the evidence, its
 * hashes and the audit trail stay on the record. See
 * research/cocid-correction/README.md.
 *
 *   POST /api/reports/cocid    {report_id, cocid, reason}   or {from, to, reason}
 *   POST /api/reports/void     {report_id, reason}
 *   POST /api/reports/unvoid   {report_id}
 */
(function () {
  'use strict';

  var mount = null;
  var state = { showRemoved: false, rows: [], busy: false };

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

  function closeModal() {
    if (modal && modal.parentNode) modal.parentNode.removeChild(modal);
    modal = null;
  }

  function openModal(title, fields, submitLabel, onSubmit) {
    closeModal();
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

  function removeUpload(row) {
    openModal('Remove this report from the lists',
      [
        { name: 'reason', label: 'Why?', type: 'textarea', maxlength: 255, hint: 'The report is hidden, not deleted: it keeps its data, hashes and audit trail, and can be restored. No credit is refunded.' }
      ], 'Remove from lists', function (v) {
        if (!v.reason) throw new Error('Give a reason.');
        return apiPost('/api/reports/void', { report_id: row.id, reason: v.reason });
      });
  }

  function restoreUpload(row) {
    apiPost('/api/reports/unvoid', { report_id: row.id }).then(afterChange).catch(function (e) {
      window.alert(e && e.message ? e.message : 'Could not restore the report.');
    });
  }

  // ---- list -------------------------------------------------------------------

  function fmtRow(row) {
    var tr = el('tr', row.voided_at ? 'opacity-60' : '');
    var when = el('td', 'px-3 py-2 text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap');
    when.textContent = row.uploaded_at || '';
    tr.appendChild(when);

    var coc = el('td', 'px-3 py-2 text-xs font-mono');
    coc.textContent = row.cocid || '';
    if (row.voided_at) {
      var b = el('span', 'ml-2 rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-600 dark:bg-ink-700 dark:text-slate-300', 'removed');
      if (row.void_reason) b.title = row.void_reason;
      coc.appendChild(b);
    }
    tr.appendChild(coc);

    var files = el('td', 'px-3 py-2 text-xs text-slate-600 dark:text-slate-400 max-w-[18rem] truncate');
    files.textContent = row.filename || '—';
    files.title = row.filename || '';
    tr.appendChild(files);

    tr.appendChild(el('td', 'px-3 py-2 text-xs text-right tabular-nums', row.devices));
    tr.appendChild(el('td', 'px-3 py-2 text-xs text-right tabular-nums', row.runs));

    var type = el('td', 'px-3 py-2 text-xs');
    type.textContent = row.report_type === 'diagnostics' ? 'Diagnostics' : 'Erasure';
    tr.appendChild(type);

    var note = el('td', 'px-3 py-2 text-xs text-slate-500 dark:text-slate-400');
    if (row.correction) {
      var c = row.correction;
      note.textContent = c.action === 'reassign'
        ? 'COCID corrected ' + (c.from_cocid || '?') + ' → ' + (c.to_cocid || '?') + ' · ' + c.at
        : (c.action === 'void' ? 'Removed · ' + c.at : 'Restored · ' + c.at);
      if (c.reason) note.title = c.reason;
    }
    tr.appendChild(note);

    var act = el('td', 'px-3 py-2 text-right whitespace-nowrap');
    if (row.voided_at) {
      var restore = el('button', 'ts-btn ts-btn--secondary ts-btn--sm', 'Restore');
      restore.type = 'button';
      restore.addEventListener('click', function () { restoreUpload(row); });
      act.appendChild(restore);
    } else {
      var fix = el('button', 'ts-btn ts-btn--secondary ts-btn--sm mr-1', 'Correct…');
      fix.type = 'button';
      fix.title = 'Change this upload’s Chain of Custody ID';
      fix.addEventListener('click', function () { correctReport(row); });
      act.appendChild(fix);
      var rm = el('button', 'ts-btn ts-btn--secondary ts-btn--sm', 'Remove');
      rm.type = 'button';
      rm.title = 'Hide this upload from every list, picker and certificate';
      rm.addEventListener('click', function () { removeUpload(row); });
      act.appendChild(rm);
    }
    tr.appendChild(act);
    return tr;
  }

  function render() {
    if (!mount) return;
    mount.innerHTML = '';
    var card = el('details', 'mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm ring-1 ring-slate-900/5 dark:border-ink-700 dark:bg-ink-900');
    var head = el('summary', 'cursor-pointer select-none px-4 py-3 text-sm font-semibold text-slate-700 dark:text-slate-300');
    head.textContent = 'Recent uploads and COCID corrections';
    card.appendChild(head);

    var bar = el('div', 'flex flex-wrap items-center gap-2 border-t border-slate-100 px-4 py-3 dark:border-ink-800');
    var job = el('button', 'ts-btn ts-btn--secondary ts-btn--sm', 'Correct a job COCID…');
    job.type = 'button';
    job.title = 'One typo at boot misfiles the whole session: move every report under an ID at once';
    job.addEventListener('click', function () { correctJob(''); });
    bar.appendChild(job);

    var label = el('label', 'ml-auto inline-flex items-center gap-2 text-xs text-slate-600 dark:text-slate-400');
    var cb = el('input');
    cb.type = 'checkbox';
    cb.checked = state.showRemoved;
    cb.addEventListener('change', function () { state.showRemoved = cb.checked; load(); });
    label.appendChild(cb);
    label.appendChild(document.createTextNode('Show removed'));
    bar.appendChild(label);
    card.appendChild(bar);

    var wrap = el('div', 'overflow-x-auto border-t border-slate-100 dark:border-ink-800');
    if (state.rows.length === 0) {
      wrap.appendChild(el('p', 'px-4 py-4 text-sm text-slate-500 dark:text-slate-400',
        state.showRemoved ? 'No uploads on this account.' : 'No uploads yet — upload a report above.'));
    } else {
      var table = el('table', 'w-full min-w-[46rem] border-collapse');
      var thead = el('thead');
      var htr = el('tr', 'bg-slate-50 dark:bg-ink-800/80');
      ['Uploaded', 'COCID', 'Files', 'Drives', 'Runs', 'Type', 'Last correction', ''].forEach(function (h, i) {
        htr.appendChild(el('th', 'px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400' + (i > 2 && i < 6 ? ' text-right' : ''), h));
      });
      thead.appendChild(htr);
      table.appendChild(thead);
      var tbody = el('tbody', 'divide-y divide-slate-100 dark:divide-ink-800');
      state.rows.forEach(function (r) { tbody.appendChild(fmtRow(r)); });
      table.appendChild(tbody);
      wrap.appendChild(table);
    }
    card.appendChild(wrap);
    mount.appendChild(card);
  }

  function load() {
    if (!mount) return;
    fetch('/api/reports?page=1&voided=' + (state.showRemoved ? '1' : '0'), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) { state.rows = (d && d.reports) || []; render(); })
      .catch(function () { /* the card simply stays empty; the page above still works */ });
  }

  // After any correction: refresh the panel if this page has one, and tell the
  // host page to refetch its own list (the COCID is printed on its rows). A page
  // with neither reloads, so the operator never sees stale evidence.
  function afterChange() {
    if (mount) load();
    if (typeof window.onReportCorrected === 'function') {
      try { window.onReportCorrected(); } catch (e) { /* host hook must not break us */ }
      return;
    }
    if (!mount) window.location.reload();
  }

  function start() {
    // The API is available on ANY dashboard page that loads this script — the
    // callers are the per-report rows in the Media drive view and the Devices
    // log, which need the dialog without the panel (research/cocid-correction).
    window.ReportCorrections = {
      reload: load,
      correctJob: correctJob,
      correctReport: correctReport,
      removeReport: removeUpload,
      restoreReport: restoreUpload,
      open: function () {
        var d = mount ? mount.querySelector('details') : null;
        if (d) d.open = true;
      }
    };

    mount = document.getElementById('recent-uploads');
    if (!mount) return;   // dialog-only page: nothing more to do
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
