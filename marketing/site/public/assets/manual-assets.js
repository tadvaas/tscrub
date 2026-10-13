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
      // Kept short on purpose: this text lands in the Drives table's Type column, and
      // "eMMC/UFS (integrated)" does not fit one line there. "integrated" is already
      // said by the reason ("Media soldered / integrated") and by the note below.
      input('Type', 'type', 'SSD, HDD, eMMC/UFS'),
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
      // show() finds the message box itself, so it is not threaded through here.
      save(isDevice, document.getElementById('manual-modal-body'), this);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') { return; }
      var detail = document.getElementById('manual-detail-modal');
      if (detail && !detail.classList.contains('hidden')) { closeDetail(); return; }
      if (!modal.classList.contains('hidden')) { close(); }
    });

    // The read-only detail view is a separate shell, so it needs its own close paths
    // (same strategy as the Add modal: close button, backdrop, Escape).
    var dClose = document.getElementById('manual-detail-close');
    var dBack = document.getElementById('manual-detail-backdrop');
    if (dClose) { dClose.addEventListener('click', closeDetail); }
    if (dBack) { dBack.addEventListener('click', closeDetail); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wire);
  } else {
    wire();
  }

  function save(isDevice, body, button) {
    var cocid = (body.querySelector('input[name=cocid]') || {}).value || '';
    var media = collect(body.querySelector('#manual-modal-media'));
    if (!cocid.trim()) { return show('A Chain of Custody ID is required.'); }
    if (!media.length) {
      return show(isDevice
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

    // Bound the request. Without this a hung POST leaves the button reading
    // "Saving…" for ever, which the operator cannot tell apart from "still working"
    // — and it hides whatever the server said.
    var ctl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var timer = setTimeout(function () { if (ctl) { ctl.abort(); } }, 30000);

    function restore() {
      clearTimeout(timer);
      button.disabled = false;
      button.textContent = isDevice ? 'Record device' : 'Record drive';
    }

    fetch('/api/csrf').then(function (r) { return r.json(); }).then(function (d) {
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': (d && d.csrf) || '' },
        body: JSON.stringify(payload),
        signal: ctl ? ctl.signal : undefined
      });
    }).then(function (r) {
      // Read the body as TEXT first. A PHP fatal returns HTML, and calling .json()
      // on it throws — which the catch below would then report as a connection
      // problem, hiding the real server error behind a network story. Parse
      // defensively so the actual status and body always reach the operator.
      return r.text().then(function (t) {
        var j = null;
        try { j = t ? JSON.parse(t) : null; } catch (e) { j = null; }
        return { ok: r.ok, status: r.status, body: j, raw: t };
      });
    }).then(function (res) {
      if (res.ok && res.body && res.body.ok) {
        clearTimeout(timer);
        close();
        // Land ON the record, rather than reloading to the top of the list. An
        // attested item is never "online", so the default status sort files it on the
        // LAST page of a busy account: the operator saved, saw the list unchanged, and
        // reasonably reported it as a failed save. Opening the search on the serial we
        // just recorded shows it immediately — which is also how they verify a typo.
        var raw = isDevice
          ? (payload.device && payload.device.serial)
          : (payload.media && payload.media.serial);
        var created = String(raw || '').trim();
        var dest = isDevice ? '/dashboard/devices' : '/dashboard/drives';
        window.location.href = dest + (created ? '?q=' + encodeURIComponent(created) : '');
        return;
      }
      // The server's message is field-shaped and actionable (it names the duplicate
      // identity and tells the operator to assign an asset tag) — show it verbatim.
      // If it sent none, at least say which HTTP status failed and quote what came
      // back, so a report of "internal error" identifies the request next time.
      var message = (res.body && (res.body.error || res.body.message)) || '';
      if (!message) {
        var snippet = String(res.raw || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180);
        message = 'Save failed (HTTP ' + res.status + ')' + (snippet ? ': ' + snippet : '.');
      }
      show(message);
      restore();
    }).catch(function (e) {
      var aborted = e && (e.name === 'AbortError' || e.name === 'TimeoutError');
      show(aborted
        ? 'The server did not answer within 30 seconds, so nothing was saved. Check the dashboard is still up, then try again.'
        : 'Could not send the request — check your connection.');
      // restore() must run on EVERY path. Leaving the button disabled is what made a
      // failed save look like a hang.
      restore();
    });
  }

  /**
   * Remove an attested record.
   *
   * This is the ONLY correction available today: a record cannot be edited, so a
   * mistyped serial is removed and re-entered. It is a compliance record, and it is
   * the operator's own evidence, so confirm first — and confirm with specifics rather
   * than "are you sure?", because the button sits on a row they may be skimming.
   */
  function remove(row, btn) {
    if (!row || !btn) { return; }
    // A row with no id cannot be addressed: the request would be "/media/undefined",
    // which cannot succeed and would surface as a confusing server error.
    var id = parseInt(row.attested_id, 10);
    if (!isFinite(id) || id <= 0) {
      window.alert('That record has no id, so it cannot be removed — reload the page and try again.');
      return;
    }
    var kind = (row.attested_kind === 'media') ? 'media' : 'device';
    var what = (kind === 'device')
      ? 'this device and every medium recorded with it'
      : 'this drive';
    var cocid = String(row.cocid || '');

    var question = 'Remove ' + what + ' from this record?\n\n'
      + 'The attested record is deleted. Regenerate the certificate'
      + (cocid ? ' for ' + cocid : '') + ' to issue it without this item.';
    if (!window.confirm(question)) { return; }

    btn.disabled = true;
    btn.textContent = 'Removing…';

    var url = '/api/certificates/' + (kind === 'device' ? 'devices' : 'media')
      + '/' + encodeURIComponent(String(id))
      + '?cocid=' + encodeURIComponent(cocid);

    var ctl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var timer = setTimeout(function () { if (ctl) { ctl.abort(); } }, 30000);

    function restore() {
      clearTimeout(timer);
      btn.disabled = false;
      btn.textContent = 'Remove';
    }

    fetch('/api/csrf').then(function (r) { return r.json(); }).then(function (d) {
      return fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': (d && d.csrf) || '' },
        signal: ctl ? ctl.signal : undefined
      });
    }).then(function (r) {
      // Parse defensively, exactly as save() does: a fatal returns HTML, and calling
      // .json() on it would report the failure as a connection problem.
      return r.text().then(function (t) {
        var j = null;
        try { j = t ? JSON.parse(t) : null; } catch (e) { j = null; }
        return { ok: r.ok, status: r.status, body: j, raw: t };
      });
    }).then(function (res) {
      if (res.ok && res.body && res.body.ok) {
        clearTimeout(timer);
        window.location.reload();
        return;
      }
      var msg = (res.body && (res.body.error || res.body.message)) || '';
      if (!msg) {
        var snippet = String(res.raw || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180);
        msg = 'Could not remove it (HTTP ' + res.status + ')' + (snippet ? ': ' + snippet : '.');
      }
      window.alert(msg);
      restore();
    }).catch(function (e) {
      var aborted = e && (e.name === 'AbortError' || e.name === 'TimeoutError');
      window.alert(aborted
        ? 'The server did not answer within 30 seconds, so nothing was removed.'
        : 'Could not send the request — check your connection.');
      restore();
    });
  }

  // Machine value → human label, from the same vocabulary the form offers. An
  // unknown value falls through as itself rather than becoming blank, so a record
  // written by an older or newer version still displays something truthful.
  function labelOf(list, key) {
    var k = String(key || '');
    for (var i = 0; i < list.length; i++) { if (list[i][0] === k) { return list[i][1]; } }
    return k;
  }

  function val(v) {
    var s = (v === null || v === undefined) ? '' : String(v).trim();
    return s === '' ? '—' : s;
  }

  function dl(pairs) {
    var box = el('dl', { class: 'grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2' });
    pairs.forEach(function (p) {
      var w = el('div', { class: 'min-w-0' });
      w.appendChild(el('dt', { class: 'text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500', text: p[0] }));
      w.appendChild(el('dd', { class: 'break-words text-sm text-ink-900 dark:text-white', text: val(p[1]) }));
      box.appendChild(w);
    });
    return box;
  }

  /**
   * The media recorded with a device.
   *
   * $dev is the parent unit. The last column compares the medium's reason/method with
   * the unit's rather than presenting them as "its own outcome": the API copies the
   * unit's values into any medium that arrived without them, so a stored media row
   * cannot say whether the operator chose the value or it was inherited. Comparing the
   * two is a fact about the values, which is what can honestly be shown.
   */
  function mediaTable(media, dev) {
    if (!media.length) {
      return el('p', { class: 'mt-2 text-sm text-slate-500 dark:text-slate-400', text: 'No media recorded with this device.' });
    }
    var devReason = String((dev && dev.reason) || '');
    var devMethod = String((dev && dev.destroy_method) || '');

    var wrap = el('div', { class: 'mt-2 overflow-x-auto rounded-lg border border-slate-200 dark:border-ink-700' });
    var t = el('table', { class: 'w-full text-left text-sm' });
    var hr = el('tr', { class: 'bg-slate-50 dark:bg-ink-800' });
    ['Type', 'Size', 'Make', 'Model', 'Serial', 'Bus', 'Reason · method'].forEach(function (h) {
      hr.appendChild(el('th', { class: 'whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400', text: h }));
    });
    var thead = el('thead'); thead.appendChild(hr); t.appendChild(thead);

    var tb = el('tbody');
    media.forEach(function (m) {
      var tr = el('tr', { class: 'border-t border-slate-100 dark:border-ink-800' });
      [m.type, m.size, m.make, m.model, m.serial, m.bus].forEach(function (v) {
        tr.appendChild(el('td', { class: 'whitespace-nowrap px-3 py-2 text-slate-700 dark:text-slate-300', text: val(v) }));
      });
      var same = (String(m.reason || '') === devReason) && (String(m.destroy_method || '') === devMethod);
      var outcome = same
        ? 'Same as the device'
        : ([labelOf(REASONS, m.reason), labelOf(METHODS, m.destroy_method)].filter(Boolean).join(' · ') || '—');
      tr.appendChild(el('td', { class: 'px-3 py-2 text-slate-500 dark:text-slate-400', text: outcome }));
      tb.appendChild(tr);
    });
    t.appendChild(tb); wrap.appendChild(t);
    return wrap;
  }

  // Inline heroicons-style SVG (fill=currentColor, 20x20 viewBox) to match the one icon
  // already in the dashboard chrome.
  var ICON_PATHS = {
    details: 'M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z',
    remove: 'M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z'
  };

  /**
   * An icon-only button.
   *
   * Icon-only means the accessible name cannot come from the content, so it is set
   * explicitly as aria-label as well as title — a bare glyph with only a tooltip is
   * unusable with a screen reader and invisible on touch.
   */
  function iconBtn(kind, label, tooltip, cls) {
    var b = el('button', {
      type: 'button',
      class: 'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border ' + cls,
      'aria-label': label
    });
    b.title = tooltip;
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 20 20');
    svg.setAttribute('fill', 'currentColor');
    svg.setAttribute('class', 'h-4 w-4');
    svg.setAttribute('aria-hidden', 'true');
    var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', ICON_PATHS[kind]);
    path.setAttribute('fill-rule', 'evenodd');
    path.setAttribute('clip-rule', 'evenodd');
    svg.appendChild(path);
    b.appendChild(svg);
    return b;
  }

  /**
   * The action cell for an attested row, as icon buttons.
   *
   * Built here rather than in each page because the same two controls appear in the
   * Devices and Drives tables; two copies would drift the moment one was restyled. Icons
   * rather than words also buy back most of a table column, which is why the widths could
   * be rebalanced afterwards.
   */
  function actions(row) {
    var wrap = el('div', { class: 'flex items-center justify-end gap-1.5' });

    var det = iconBtn('details', 'Details',
      'Show everything recorded for this attested item — the evidence that goes on the certificate',
      'border-slate-300 bg-white text-slate-600 hover:bg-slate-100 dark:border-slate-700 dark:bg-ink-900 dark:text-slate-300 dark:hover:bg-ink-800');
    det.addEventListener('click', function () { detail(row); });
    wrap.appendChild(det);

    var rm = iconBtn('remove', 'Remove',
      row.attested_kind === 'media' ? 'Remove this attested drive' : 'Remove this attested device and every medium recorded with it',
      'border-red-300 bg-red-50 text-red-600 hover:bg-red-100 dark:border-red-900 dark:bg-red-900/30 dark:text-red-300 dark:hover:bg-red-900/50');
    rm.addEventListener('click', function () { remove(row, rm); });
    wrap.appendChild(rm);

    return wrap;
  }

  /**
   * Read-only detail view for one attested record.
   *
   * Everything shown was recorded when the item was added and is already printed on
   * the certificate — but until now the dashboard could not show it back, so an
   * operator could not check what they had entered or confirm a witness name. Built
   * from the row the list already holds, so there is nothing extra to fetch and no way
   * for the detail view to disagree with the table.
   */
  function detail(row) {
    var modal = document.getElementById('manual-detail-modal');
    var body = document.getElementById('manual-detail-body');
    if (!modal || !body || !row) { return; }

    var isDevice = (row.attested_kind !== 'media');

    document.getElementById('manual-detail-title').textContent = isDevice
      ? String(row.system || row.sysserial || 'Attested device')
      : (String([row.type, row.make, row.model].filter(Boolean).join(' ')) || String(row.serial || 'Attested drive'));
    document.getElementById('manual-detail-sub').textContent =
      String(row.attested_qualifier || 'operator-attested — not instrument-verified');

    body.innerHTML = '';
    body.appendChild(el('p', { class: 'mb-4 rounded-lg bg-amber-50 dark:bg-amber-900/30 px-3 py-2 text-xs text-amber-800 dark:text-amber-300',
      text: 'Recorded by an operator as physically destroyed. This is an attestation, not an instrument-verified result, and it is what the certificate prints for this item.' }));

    var pairs = [];
    if (isDevice) {
      pairs.push(['Category', labelOf(CATEGORIES, row.category)]);
      pairs.push(['Make', row.make]);
      pairs.push(['Model', row.model]);
      pairs.push(['Serial / IMEI / asset tag', row.sysserial]);
    } else {
      pairs.push(['Type', row.type]);
      pairs.push(['Size', row.size]);
      pairs.push(['Make', row.make]);
      pairs.push(['Model', row.model]);
      pairs.push(['Serial', row.serial]);
      pairs.push(['Bus', row.bus]);
    }
    pairs.push(['Why destroyed, not sanitised', labelOf(REASONS, row.reason)]);
    pairs.push(['Destruction method', labelOf(METHODS, row.destroy_method)]);
    pairs.push(['Destroyed by (vendor / facility)', row.destroy_vendor]);
    pairs.push(['Date destroyed', row.destroy_date]);
    pairs.push(['Witness', row.destroy_witness]);
    pairs.push(['Evidence reference', row.evidence_ref]);
    pairs.push(['Attested by', row.attested_by]);
    if (!isDevice) {
      // A standalone destroyed drive has no host, and the detail view must not imply
      // one — the certificate prints "—" for exactly this reason.
      pairs.push(['Host device', row.system ? row.system : 'No host recorded']);
      if (row.sysserial) { pairs.push(['Host serial', row.sysserial]); }
    }
    pairs.push(['Chain of Custody', row.cocid]);
    if (row.cert_id) { pairs.push(['Certificate', row.cert_id]); }

    body.appendChild(dl(pairs));

    if (isDevice) {
      var media = Array.isArray(row.drives) ? row.drives : [];
      body.appendChild(el('h3', { class: 'mt-6 text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400',
        text: 'MEDIA destroyed with this device (' + media.length + ')' }));
      body.appendChild(mediaTable(media, row));
    }

    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    var closeBtn = document.getElementById('manual-detail-close');
    if (closeBtn) { closeBtn.focus(); }
  }

  function closeDetail() {
    var m = document.getElementById('manual-detail-modal');
    if (m) { m.classList.add('hidden'); }
    // Only release the scroll lock if the Add modal is not also open — they are never
    // both open today, but unlocking here would break page scroll if that changed.
    var add = document.getElementById('manual-modal');
    if (!add || add.classList.contains('hidden')) { document.body.style.overflow = ''; }
  }

  // Takes the message only, and finds the box itself.
  //
  // It used to take (err, message) while its failure paths called it with one
  // argument. Under 'use strict' that made `err` a primitive string, so assigning
  // .textContent to it THREW — every failed save fell through to the catch handler,
  // threw again there, and left the button reading "Saving…" for ever with the real
  // error never shown. Taking one argument makes it impossible to call wrongly.
  function show(message) {
    var box = document.getElementById('manual-modal-error');
    if (!box) { return; }
    box.textContent = message;
    box.classList.remove('hidden');
  }

  window.ManualAssets = {
    open: open,
    remove: remove,
    detail: detail,
    actions: actions,
    // The button carries the page's own COCID when one is in view (the drives page
    // uploads per job), so the operator does not retype it.
    openFor: function (kind) {
      var pre = document.querySelector('[data-cocid]');
      open(kind, pre ? pre.getAttribute('data-cocid') : '');
    }
  };
})();
