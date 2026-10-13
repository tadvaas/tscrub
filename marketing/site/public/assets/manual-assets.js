/**
 * Manually recorded (attested) destruction — the Add modal for the Devices and
 * Drives pages.
 *
 * Self-contained on purpose: it builds its own DOM, so a defect here cannot affect
 * the host page's own script (the dashboards' logic is inline, and a syntax or runtime
 * error there would take the whole page down). The pages only add a button and a
 * script tag.
 *
 * Two kinds:
 *   'device' — a destroyed unit (phone, sealed appliance, kit that would not power on)
 *              with one media row per medium it contained.
 *   'drive'  — one standalone destroyed medium, no host recorded.
 *
 * Everything recorded here is an operator ATTESTATION, never instrument-verified, and
 * the server records who attested from the session — never from this form.
 * Plan: research/manual-destruction/README.md
 */
(function () {
  'use strict';

  // Machine values must match the server's vocabulary (manual_destruction.php):
  // md_categories(), md_reasons(), md_methods(). A mismatch is refused by the API
  // with the field named, rather than silently stored.
  var CATEGORIES = [
    ['mobile_phone', 'Mobile phone'], ['tablet', 'Tablet'], ['laptop', 'Laptop'],
    ['desktop', 'Desktop'], ['server', 'Server'], ['sealed_unit', 'Sealed unit'],
    ['embedded', 'Embedded board'], ['other', 'Other']
  ];
  var REASONS = [
    ['would_not_power_on', 'Would not power on'],
    ['would_not_boot', 'Would not boot / no supported interface'],
    ['media_soldered', 'Media soldered / integrated'],
    ['unsupported_media', 'Unsupported media type'],
    ['battery_unsafe', 'Battery unsafe to power'],
    ['physically_damaged', 'Physically damaged'],
    ['beyond_economic_repair', 'Beyond economic repair'],
    ['other', 'Other']
  ];
  var METHODS = [
    ['shredded', 'Shredded'], ['crushed', 'Crushed'], ['degaussed', 'Degaussed'],
    ['incinerated', 'Incinerated'], ['disintegrated', 'Disintegrated'],
    ['dismantled_then_shredded', 'Dismantled, then shredded'], ['other', 'Other']
  ];

  // Module-level: open() sets it, and mediaRow()/save() read it. It must be declared
  // here — the file is in strict mode, so assigning without a declaration throws
  // (the original version kept this as a local inside open()).
  var isDevice = true;
  var root = null;

  function el(tag, attrs, children) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') { n.textContent = attrs[k]; } else { n.setAttribute(k, attrs[k]); }
    });
    (children || []).forEach(function (c) { n.appendChild(c); });
    return n;
  }

  function input(label, name, placeholder) {
    var wrap = el('label', { class: 'block' });
    wrap.appendChild(el('span', { class: 'mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-300', text: label }));
    wrap.appendChild(el('input', { name: name, type: 'text', placeholder: placeholder || '',
      class: 'w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-ink-900 px-3 py-2 text-sm' }));
    return wrap;
  }

  function select(label, name, options, required) {
    var wrap = el('label', { class: 'block' });
    wrap.appendChild(el('span', { class: 'mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-300', text: label }));
    var sel = el('select', { name: name, class: 'w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-ink-900 px-3 py-2 text-sm' });
    sel.appendChild(el('option', { value: '', text: required ? 'Choose…' : '—' }));
    options.forEach(function (o) { sel.appendChild(el('option', { value: o[0], text: o[1] })); });
    wrap.appendChild(sel);
    return wrap;
  }

  function grid(children, cols) {
    return el('div', { class: 'grid grid-cols-1 gap-3 sm:grid-cols-' + (cols || 2) }, children);
  }

  function mediaRow(isDevice) {
    var row = el('div', { class: 'rounded-lg border border-slate-200 dark:border-ink-700 p-3' });
    var head = el('div', { class: 'mb-2 flex items-center justify-between' });
    head.appendChild(el('span', { class: 'text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400', text: 'Medium' }));
    var rm = el('button', { type: 'button', class: 'text-xs font-semibold text-red-600 hover:underline', text: 'Remove' });
    rm.addEventListener('click', function () {
      if (row.parentNode && row.parentNode.children.length > 1) { row.parentNode.removeChild(row); }
    });
    head.appendChild(rm);
    row.appendChild(head);
    row.appendChild(grid([
      input('Type (SSD, HDD, eMMC/UFS (integrated)…)', 'type'),
      input('Size', 'size'),
      input('Make', 'make'),
      input('Model', 'model'),
      input('Serial / IMEI', 'serial'),
      input('Bus', 'bus')
    ], 3));
    if (isDevice) {
      // The device already carries the reason and the destruction method, so asking
      // again per medium showed the same two questions twice. A medium only needs its
      // own when its outcome differed (e.g. one drive pulled and wiped, the rest
      // destroyed), so that pair stays behind a toggle.
      row.appendChild(el('p', { class: 'mt-2 text-xs text-slate-500 dark:text-slate-400',
        text: 'Integrated or soldered storage: use the device\'s own make, model and serial — the medium IS that storage.' }));

      var override = el('div', { class: 'mt-2 hidden' }, [grid([
        select('Reason for this medium', 'reason', REASONS, false),
        select('Destruction method for this medium', 'destroy_method', METHODS, false)
      ], 2)]);
      var toggle = el('button', { type: 'button', class: 'mt-2 text-xs font-semibold text-brand-700 dark:text-brand-400 hover:underline',
        text: 'Different outcome for this medium…' });
      toggle.addEventListener('click', function () {
        override.classList.remove('hidden');
        toggle.classList.add('hidden');
      });
      row.appendChild(toggle);
      row.appendChild(override);
    } else {
      row.appendChild(grid([
        select('Reason', 'reason', REASONS, true),
        select('Destruction method', 'destroy_method', METHODS, true)
      ], 2));
    }
    return row;
  }

  function collect(container) {
    var out = [];
    Array.prototype.forEach.call(container.children, function (row) {
      var o = {};
      Array.prototype.forEach.call(row.querySelectorAll('input,select'), function (f) {
        o[f.name] = f.value.trim();
      });
      // Only send a media row the operator actually filled in.
      if (o.serial || o.model || o.type) { out.push(o); }
    });
    return out;
  }

  function open(kind) {
    isDevice = (kind !== 'drive');
    var modal = document.getElementById('manual-modal');
    var body = document.getElementById('manual-modal-body');

    document.getElementById('manual-modal-title').textContent = isDevice
      ? 'Record a destroyed device' : 'Record a destroyed drive';
    document.getElementById('manual-modal-sub').textContent =
      'Operator attestation — not instrument-verified destruction';
    document.getElementById('manual-modal-save').textContent = isDevice ? 'Record device' : 'Record drive';

    body.innerHTML = '';
    var err = el('p', { id: 'manual-modal-error', class: 'hidden rounded-lg bg-red-50 dark:bg-red-900/30 px-3 py-2 text-xs font-semibold text-red-700 dark:text-red-300' });

    body.appendChild(el('p', { class: 'mb-3 rounded-lg bg-amber-50 dark:bg-amber-900/30 px-3 py-2 text-xs text-amber-800 dark:text-amber-300',
      text: 'This records an operator attestation of physical destruction — it is not instrument-verified, and it will be labelled as attested on the certificate.' }));
    body.appendChild(err);
    body.appendChild(input('Chain of Custody ID (the job this belongs to)', 'cocid', 'COC-…'));

    if (isDevice) {
      body.appendChild(grid([
        select('Category', 'category', CATEGORIES, true),
        input('Make', 'make'),
        input('Model', 'model'),
        input('Serial / IMEI / asset tag', 'serial')
      ], 2));
    }

    var mediaBox = el('div', { id: 'manual-modal-media', class: 'mt-3 space-y-3' });
    mediaBox.appendChild(mediaRow(isDevice));
    body.appendChild(mediaBox);

    var addRow = el('button', { type: 'button', class: 'mt-3 rounded-lg border border-brand-600 dark:border-brand-500 px-3 py-2 text-sm font-semibold text-brand-700 dark:text-brand-400 hover:bg-brand-50',
      text: isDevice ? '+ Add another medium' : '+ Add another drive' });
    addRow.addEventListener('click', function () { mediaBox.appendChild(mediaRow(isDevice)); });
    body.appendChild(addRow);

    if (isDevice) {
      body.appendChild(el('div', { class: 'mt-3 space-y-3 border-t border-slate-200 dark:border-ink-700 pt-3' }, [grid([
        select('Reason', 'reason', REASONS, true),
        select('Destruction method', 'destroy_method', METHODS, true),
        input('Destroyed by (vendor / facility)', 'destroy_vendor'),
        input('Date (YYYY-MM-DD)', 'destroy_date'),
        input('Witness', 'destroy_witness'),
        input('Evidence reference (vendor certificate no.)', 'evidence_ref')
      ], 2)]));
    }

    // The page's own COCID, when one is in view, so the operator does not retype it.
    var pre = document.querySelector('[data-cocid]');
    if (pre) { body.querySelector('input[name=cocid]').value = pre.getAttribute('data-cocid'); }

    // Same open strategy as the Operations and Export modals.
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    var focus = body.querySelector('input[name=cocid]');
    if (focus) { focus.focus(); }
  }

  function close() {
    document.getElementById('manual-modal').classList.add('hidden');
    document.body.style.overflow = '';
  }

  // Bound once: the container markup is static in the page.
  function wire() {
    var modal = document.getElementById('manual-modal');
    if (!modal) { return; }
    document.getElementById('manual-modal-close').addEventListener('click', close);
    document.getElementById('manual-modal-backdrop').addEventListener('click', close);
    document.getElementById('manual-modal-save').addEventListener('click', function () {
      save(isDevice, document.getElementById('manual-modal-body'),
        document.getElementById('manual-modal-error'), this);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.classList.contains('hidden')) { close(); }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wire);
  } else {
    wire();
  }

  function save(isDevice, body, err, button) {
    var cocid = (body.querySelector('input[name=cocid]') || {}).value || '';
    var media = collect(body.querySelector('#manual-modal-media'));
    if (!cocid.trim()) { return show(err, 'A Chain of Custody ID is required.'); }
    if (!media.length) {
      return show(err, isDevice
        ? 'Add at least one medium — a device that held no storage has nothing to record.'
        : 'Fill in the drive you are recording.');
    }

    var payload;
    if (isDevice) {
      var dev = {};
      ['category', 'make', 'model', 'serial', 'reason', 'destroy_method', 'destroy_vendor', 'destroy_date', 'destroy_witness', 'evidence_ref'].forEach(function (k) {
        var f = body.querySelector('[name=' + k + ']');
        if (f) { dev[k] = f.value.trim(); }
      });
      payload = { cocid: cocid.trim(), device: dev, media: media };
    } else {
      payload = { cocid: cocid.trim(), media: media[0] };
    }

    var url = isDevice ? '/api/certificates/devices' : '/api/certificates/media';
    button.disabled = true;
    button.textContent = 'Saving…';

    fetch('/api/csrf').then(function (r) { return r.json(); }).then(function (d) {
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': (d && d.csrf) || '' },
        body: JSON.stringify(payload)
      });
    }).then(function (r) {
      return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; });
    }).then(function (res) {
      if (res.ok && res.body && res.body.ok) {
        close();
        // The job (and the certificate) is rebuilt on the server; a reload is the
        // honest way to show it rather than guessing what the lists now contain.
        window.location.reload();
        return;
      }
      // The server's message is field-shaped and actionable (it names the duplicate
      // identity and tells the operator to assign an asset tag) — show it verbatim.
      show(err, (res.body && (res.body.error || res.body.message)) || 'Could not save the record.');
      button.disabled = false;
      button.textContent = isDevice ? 'Record device' : 'Record drive';
    }).catch(function () {
      show(err, 'Could not reach the server.');
      button.disabled = false;
      button.textContent = isDevice ? 'Record device' : 'Record drive';
    });
  }

  function show(err, message) {
    err.textContent = message;
    err.classList.remove('hidden');
  }

  window.ManualAssets = {
    open: open,
    // The button carries the page's own COCID when one is in view (the drives page
    // uploads per job), so the operator does not retype it.
    openFor: function (kind) {
      var pre = document.querySelector('[data-cocid]');
      open(kind, pre ? pre.getAttribute('data-cocid') : '');
    }
  };
})();
