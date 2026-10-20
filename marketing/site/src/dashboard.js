// Dashboard app shell — fills the section tabs and account links that every
// dashboard page renders (see the <header> markup), so the navigation lives in
// one place. Deliberately NOT the marketing nav: an authenticated tool should
// not offer Pricing/Download chrome.
const NAV_LEFT = [
  { href: '/dashboard/devices', label: 'Devices' },
  // 'Media', not 'Drives': the tab is already a superset of drives — its total includes
  // operator-attested media, which can be an iPhone's soldered eMMC or a flash chip, and
  // it counts the same unit the certificate certifies ('14 MEDIA SANITISED IN PLACE').
  // NIST SP 800-88 splits media (the storage) from the device containing it, which is
  // exactly how the attested vocabulary already reads. The URL stays /dashboard/drives.
  { href: '/dashboard/drives', label: 'Media' },
  { href: '/dashboard/certificates', label: 'Certificates' },
  { href: '/dashboard/licences', label: 'Licences' }
];
const NAV_RIGHT = [
  { href: '/dashboard/billing', label: 'Billing' },
  { href: '/dashboard/account', label: 'Account' }
];

;(function () {
  const path = (window.location.pathname || '/').replace(/\/$/, '') || '/';
  // Treat the raw .html entry as the clean URL too, so local preview servers
  // highlight the same tab that production does.
  const isActive = (l) => path === l.href || path === l.href + '.html';

  const tab = (l) => {
    const a = document.createElement('a');
    a.href = l.href;
    a.textContent = l.label;
    // Neutral active state: the green accent is reserved for primary actions,
    // so a selected tab does not compete with "Add device".
    a.className = 'rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors ' +
      (isActive(l)
        ? 'bg-ink-900 text-white shadow-sm dark:bg-white dark:text-ink-900'
        : 'text-slate-600 hover:bg-slate-100 hover:text-ink-900 dark:text-slate-400 dark:hover:bg-ink-800 dark:hover:text-white');
    if (isActive(l)) a.setAttribute('aria-current', 'page');
    return a;
  };

  const desktop = document.getElementById('app-tabs');
  // The admin page is not one of the regular sections, so give it its own tab
  // while it is open. That keeps the app bar from showing four inactive tabs.
  // Accept both the clean URL and the raw .html entry (local preview servers).
  const isAdminPage = path === '/admin' || path === '/admin.html';
  const adminTab = isAdminPage ? [{ href: '/admin', label: 'Admin' }] : [];
  const desktopLinks = NAV_LEFT.concat(adminTab);
  const mobileLinks = desktopLinks.concat(NAV_RIGHT);
  if (desktop) desktopLinks.forEach((l) => desktop.appendChild(tab(l)));

  const mobile = document.getElementById('app-tabs-mobile');
  if (mobile) {
    mobileLinks.forEach((l) => {
      const a = tab(l);
      a.className = 'block rounded-lg px-3 py-2 text-sm font-semibold transition-colors ' +
        (isActive(l)
          ? 'bg-ink-900 text-white dark:bg-white dark:text-ink-900'
          : 'text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-ink-800');
      mobile.appendChild(a);
    });
  }

  const acct = document.getElementById('app-account');
  if (acct) {
    NAV_RIGHT.forEach((l) => {
      const a = tab(l);
      a.className = 'hidden rounded-lg px-2.5 py-1.5 text-sm font-semibold transition-colors sm:block ' +
        (isActive(l)
          ? 'text-ink-900 dark:text-white'
          : 'text-slate-500 hover:bg-slate-100 hover:text-ink-900 dark:text-slate-400 dark:hover:bg-ink-800 dark:hover:text-white');
      acct.appendChild(a);
    });
  }

  const btn = document.getElementById('app-menu-btn');
  if (btn && mobile) {
    btn.addEventListener('click', () => {
      const closed = mobile.classList.toggle('hidden');
      btn.setAttribute('aria-expanded', String(!closed));
    });
  }
})();

// Dark-mode toggle. The `.dark` class is applied before first paint by the
// bootstrap script injected in vite.config.js; this owns the control, the saved
// choice, and live OS changes while no explicit choice has been made.
;(function () {
  const KEY = 'tscrub_theme';
  const root = document.documentElement;
  const media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  const stored = () => { try { return localStorage.getItem(KEY); } catch (e) { return null; } };
  const save = (v) => { try { localStorage.setItem(KEY, v); } catch (e) {} };

  const apply = (dark) => {
    root.classList.toggle('dark', dark);
    root.style.colorScheme = dark ? 'dark' : 'light';
    const toggle = document.getElementById('theme-toggle');
    if (toggle) {
      toggle.title = dark ? 'Switch to light mode' : 'Switch to dark mode';
      toggle.setAttribute('aria-label', toggle.title);
      toggle.setAttribute('aria-pressed', String(dark));
    }
  };

  const slot = document.getElementById('app-account');
  if (slot && !document.getElementById('theme-toggle')) {
    const toggle = document.createElement('button');
    toggle.id = 'theme-toggle';
    toggle.type = 'button';
    toggle.className = 'inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-ink-800';
    // Moon while light (action: go dark), sun while dark (action: go light).
    toggle.innerHTML =
      '<svg class="h-5 w-5 dark:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">' +
        '<path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z"/>' +
      '</svg>' +
      '<svg class="hidden h-5 w-5 dark:block" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">' +
        '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/>' +
      '</svg>';
    toggle.addEventListener('click', () => {
      const next = !root.classList.contains('dark');
      apply(next);
      save(next ? 'dark' : 'light');
    });
    slot.appendChild(toggle);
    apply(root.classList.contains('dark'));
  }

  if (media) {
    const onChange = (e) => { if (!stored()) apply(e.matches); };
    if (media.addEventListener) media.addEventListener('change', onChange);
    else if (media.addListener) media.addListener(onChange);
  }
})();

// Operator identity in the app bar: the organisation name (when in an org) and
// the account email. Rendered instantly from a per-session cache, then refreshed
// from /api/me. Admin-only tools live on the Account page, not in the app bar.
;(function () {
  const detail = document.getElementById('app-user');

  const paint = (orgName, email) => {
    if (!detail) return;
    detail.textContent = '';
    if (!orgName && !email) return;
    const initial = (orgName || email || '?').trim().charAt(0).toUpperCase();
    const badge = document.createElement('span');
    badge.className = 'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-bold text-brand-700 ring-1 ring-inset ring-brand-600/20 dark:bg-brand-900/30 dark:text-brand-400';
    badge.textContent = initial;
    detail.appendChild(badge);
    const text = document.createElement('span');
    text.className = 'min-w-0 truncate';
    if (orgName) {
      const o = document.createElement('span');
      o.className = 'font-semibold text-slate-700 dark:text-slate-300';
      o.textContent = orgName;
      text.appendChild(o);
    }
    if (orgName && email) {
      const sep = document.createElement('span');
      sep.className = 'mx-1.5 text-slate-400 dark:text-slate-500';
      sep.textContent = '·';
      text.appendChild(sep);
    }
    if (email) {
      const e = document.createElement('span');
      e.textContent = email;
      text.appendChild(e);
    }
    detail.appendChild(text);
    detail.title = [orgName, email].filter(Boolean).join(' · ');
  };
  const cache = (orgName, email) => {
    try { sessionStorage.setItem('tscrub_header', JSON.stringify({ orgName, email, ts: Date.now() })); } catch (e) {}
  };

  // Instant first paint from the session cache (tolerates the older {text} shape).
  try {
    const c = JSON.parse(sessionStorage.getItem('tscrub_header') || 'null');
    if (c && Date.now() - (c.ts || 0) < 24 * 3600 * 1000) {
      if (c.orgName || c.email) paint(c.orgName || '', c.email || '');
      else if (c.text) paint(c.text, '');
    }
  } catch (e) {}

  // Admins get an extra card on the Account page. It is hidden in the markup so
  // no non-admin ever sees the link, even before /api/me resolves.
  const revealAdminCard = () => {
    const card = document.getElementById('admin-card');
    if (card) card.classList.remove('hidden');
  };

  // Microsoft probe-tenant expiry banner (admins only). Once the Intune
  // subscription lapses, the MDM worker reports "Offline" — which looks like a
  // network fault. Surface the real deadline while there is still time to buy a
  // paid seat. Server-side check: GET /api/admin/mdm-tenant (Graph, read-only).
  const renderTenantBanner = (t) => {
    if (!t || !t.ok) return;
    const actionable = !!t.expired
      || (!!t.warning && (!!t.expiry_known || (t.status && t.status !== 'Enabled')));
    if (!actionable) return;
    const host = document.getElementById('dashboard-alerts');
    if (!host || document.getElementById('mdm-tenant-banner')) return;

    const expired = !!t.expired;
    const el = document.createElement('div');
    el.id = 'mdm-tenant-banner';
    el.className = 'mt-3 rounded-xl border px-4 py-3 text-sm ' + (expired
      ? 'border-red-300 bg-red-50 text-red-900 dark:border-red-700 dark:bg-red-900/30 dark:text-red-200'
      : 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200');

    const when = t.next_lifecycle ? String(t.next_lifecycle).slice(0, 10) : '';
    const days = Number(t.days_remaining);
    let msg;
    if (expired) {
      msg = 'Microsoft ' + (t.sku || 'Intune') + ' is ' + (t.status || 'expired')
        + ' — MDM checks will fail. Buy Intune Plan 1 before the next check.';
    } else {
      const rel = (!t.expiry_known || !isFinite(days)) ? 'soon'
        : (days <= 0 ? 'today' : days === 1 ? 'tomorrow' : 'in ' + days + ' days');
      msg = 'Microsoft ' + (t.is_trial ? 'Intune trial' : (t.sku || 'Intune subscription'))
        + ' ends ' + rel + (when ? ' (' + when + ')' : '')
        + '. MDM checks stop at expiry — buy Intune Plan 1 before then.';
    }
    el.textContent = msg + ' ';

    const a = document.createElement('a');
    a.href = 'https://admin.microsoft.com/AdminPortal/Home#/subscriptions';
    a.target = '_blank';
    a.rel = 'noopener';
    a.className = 'font-semibold underline underline-offset-2';
    a.textContent = 'Open Microsoft 365 subscriptions';
    el.appendChild(a);

    // Inside the aligned host, not after it: the host carries the page's
    // max-width and horizontal padding, so appending keeps the banner aligned
    // with the cards instead of running full-bleed against the viewport.
    host.appendChild(el);
  };

  const maybeTenantBanner = () => {
    const KEY = 'tscrub_mdm_tenant';
    try {
      const c = JSON.parse(sessionStorage.getItem(KEY) || 'null');
      if (c && Date.now() - (c.ts || 0) < 60 * 60 * 1000) {
        renderTenantBanner(c.data);
        return;
      }
    } catch (e) {}
    fetch('/api/admin/mdm-tenant')
      .then((r) => (r.ok ? r.json() : null))
      .catch(() => null)
      .then((d) => {
        const t = d && d.tenant;
        if (!t) return;
        try { sessionStorage.setItem(KEY, JSON.stringify({ data: t, ts: Date.now() })); } catch (e) {}
        renderTenantBanner(t);
      });
  };

  fetch('/api/me').then((r) => r.json()).catch(() => ({}))
    .then((d) => {
      const u = d && d.user;
      if (!u) return;
      const orgName = d.org && d.org.name ? d.org.name : '';
      paint(orgName, u.email || '');
      cache(orgName, u.email || '');
      if (u.role === 'admin') {
        revealAdminCard();
        maybeTenantBanner();
      }
    });
})();
