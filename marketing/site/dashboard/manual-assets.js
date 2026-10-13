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
      row.appendChild(el('p', { class: 'mt-2 text-xs text-slate-500 dark:text-slate-400',
        text: 'Media inherit the device\'s reason and destruction method unless you set them here.' }));
      row.appendChild(grid([
        select('Reason', 'reason', REASONS, false),
        select('Destruction method', 'destroy_method', METHODS, false)
      ], 2));
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

  function open(kind, cocid) {
    close();
    var isDevice = kind === 'device';

    var body = el('div', { class: 'max-h-[70vh] space-y-4 overflow-y-auto px-5 py-4' });

    var notice = el('p', { class: 'rounded-lg bg-amber-50 dark:bg-amber-900/30 px-3 py-2 text-xs text-amber-800 dark:text-amber-300',
      text: 'This records an operator attestation of physical destruction — it is not instrument-verified, and it will be labelled as attested on the certificate.' });
    body.appendChild(notice);

    var err = el('p', { class: 'hidden rounded-lg bg-red-50 dark:bg-red-900/30 px-3 py-2 text-xs font-semibold text-red-700 dark:text-red-300' });
    body.appendChild(err);

    body.appendChild(input('Chain of Custody ID (the job this belongs to)', 'cocid', 'COC-…'));
    var cocidField = body.querySelector('input[name=cocid]');
    if (cocid) { cocidField.value = cocid; }

    if (isDevice) {
      body.appendChild(grid([
        select('Category', 'category', CATEGORIES, true),
        input('Make', 'make'),
        input('Model', 'model'),
        input('Serial / IMEI / asset tag', 'serial')
      ], 2));
    }

    var mediaBox = el('div', { id: 'manual-assets-media', class: 'space-y-3' });
    mediaBox.appendChild(mediaRow(isDevice));
    body.appendChild(mediaBox);

    var addRow = el('button', { type: 'button', class: 'rounded-lg border border-brand-600 dark:border-brand-500 px-3 py-2 text-sm font-semibold text-brand-700 dark:text-brand-400 hover:bg-brand-50',
      text: isDevice ? '+ Add another medium' : '+ Add another drive' });
    addRow.addEventListener('click', function () { mediaBox.appendChild(mediaRow(isDevice)); });
    body.appendChild(addRow);

    if (isDevice) {
      var ev = el('div', {}, [grid([
        select('Reason', 'reason', REASONS, true),
        select('Destruction method', 'destroy_method', METHODS, true),
        input('Destroyed by (vendor / facility)', 'destroy_vendor'),
        input('Date (YYYY-MM-DD)', 'destroy_date'),
        input('Witness', 'destroy_witness'),
        input('Evidence reference (vendor certificate no.)', 'evidence_ref')
      ], 2)]);
      ev.className = 'space-y-3 border-t border-slate-200 dark:border-ink-700 pt-3';
      body.appendChild(ev);
    }

    var modal = el('div', { id: 'manual-assets-modal', class: 'fixed inset-0 z-50', role: 'dialog', 'aria-modal': 'true' }, [
      el('div', { class: 'absolute inset-0 bg-slate-900/60 backdrop-blur-sm' }),
      el('div', { class: 'absolute left-1/2 top-1/2 w-[min(760px,94vw)] -translate-x-1/2 -translate-y-1/2 overflow-hidden rounded-2xl bg-white shadow-xl dark:bg-ink-900' }, [
        el('div', { class: 'flex items-center justify-between border-b border-slate-200 dark:border-ink-700 px-5 py-3' }, [
          el('h2', { class: 'text-lg font-bold text-ink-900 dark:text-white',
            text: isDevice ? 'Record a destroyed device' : 'Record a destroyed drive' }),
          el('button', { type: 'button', id: 'manual-assets-cancel', class: 'rounded-lg border border-slate-300 dark:border-slate-700 px-3 py-2 text-sm font-semibold text-slate-700 dark:text-slate-300', text: 'Cancel' })
        ]),
        body,
        el('div', { class: 'flex items-center justify-end gap-2 border-t border-slate-200 dark:border-ink-700 px-5 py-3' }, [
          el('button', { type: 'button', id: 'manual-assets-save',
            class: 'rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700',
            text: isDevice ? 'Record device' : 'Record drive' })
        ])
      ])
    ]);

    modal.querySelector('#manual-assets-cancel').addEventListener('click', close);
    modal.querySelector('.absolute.inset-0').addEventListener('click', close);
    modal.querySelector('#manual-assets-save').addEventListener('click', function () {
      save(isDevice, body, err, this);
    });

    document.body.appendChild(modal);
    root = modal;
    cocidField.focus();
  }

  function close() {
    if (root && root.parentNode) { root.parentNode.removeChild(root); }
    root = null;
  }

  function save(isDevice, body, err, button) {
    var cocid = (body.querySelector('input[name=cocid]') || {}).value || '';
    var media = collect(body.querySelector('#manual-assets-media'));
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
