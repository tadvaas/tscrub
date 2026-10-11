// Dashboard sub-navigation — rendered into #dashboard-nav on dashboard pages.
// The right-hand group is exposed so the Admin link (added once /api/me confirms
// the admin flag) can be appended without a second full render.
const NAV_RIGHT = { el: null };

;(function () {
  const root = document.getElementById('dashboard-nav');
  if (!root) return;

  const links = [
    { href: '/dashboard/devices', label: 'Devices', side: 'left' },
    { href: '/dashboard/drives', label: 'Drives', side: 'left' },
    { href: '/dashboard/certificates', label: 'Certificates', side: 'left' },
    { href: '/dashboard/licences', label: 'Licences', side: 'left' },
    { href: '/dashboard/billing', label: 'Billing', side: 'right' },
    { href: '/dashboard/account', label: 'Account', side: 'right' }
  ];

  const path = (window.location.pathname || '/').replace(/\/$/, '') || '/';
  const wrap = document.createElement('div');
  wrap.className = 'flex flex-wrap items-center justify-between gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-sm dark:border-ink-700 dark:bg-ink-900';

  const groups = { left: document.createElement('div'), right: document.createElement('div') };
  groups.left.className = 'flex flex-wrap items-center gap-1';
  groups.right.className = 'flex flex-wrap items-center gap-1';
  NAV_RIGHT.el = groups.right;

  links.forEach((l) => {
    const a = document.createElement('a');
    a.href = l.href;
    a.textContent = l.label;
    const active = path === l.href;
    a.className = 'rounded-lg px-3 py-2 text-sm font-semibold ' +
      (active ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-ink-800');
    groups[l.side].appendChild(a);
  });

  wrap.appendChild(groups.left);
  wrap.appendChild(groups.right);
  root.appendChild(wrap);
})();

// Operator identity inline right of the "Dashboard" heading: the organisation
// name (when in an org) AND the account email, plus a distinct Admin link when
// the account carries the admin flag. Rendered instantly from a per-session
// cache, then refreshed from /api/me.
;(function () {
  const h1 = Array.from(document.querySelectorAll('h1')).find((el) => el.textContent.trim() === 'Dashboard');
  let detail = null;
  if (h1) {
    h1.classList.add('flex', 'items-baseline', 'justify-between', 'gap-4');
    detail = document.createElement('span');
    detail.className = 'text-sm font-normal text-slate-500 dark:text-slate-400 truncate';
    h1.appendChild(detail);
  }

  const paint = (orgName, email) => {
    if (!detail) return;
    detail.textContent = '';
    if (orgName) {
      const o = document.createElement('span');
      o.className = 'font-semibold text-slate-700 dark:text-slate-300';
      o.textContent = orgName;
      detail.appendChild(o);
    }
    if (orgName && email) {
      const sep = document.createElement('span');
      sep.className = 'mx-1.5 text-slate-300 dark:text-slate-600';
      sep.textContent = '·';
      detail.appendChild(sep);
    }
    if (email) {
      const e = document.createElement('span');
      e.textContent = email;
      detail.appendChild(e);
    }
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

  const showAdmin = () => {
    const slot = NAV_RIGHT.el;
    if (!slot || slot.querySelector('[data-admin-link]')) return;
    const a = document.createElement('a');
    a.href = '/admin';
    a.setAttribute('data-admin-link', '1');
    a.textContent = 'Admin';
    a.title = 'Administrator panel';
    a.className = 'rounded-lg bg-amber-100 px-3 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-200 dark:bg-amber-900/40 dark:text-amber-300 dark:hover:bg-amber-900/60';
    slot.appendChild(a);
  };

  fetch('/api/me').then((r) => r.json()).catch(() => ({}))
    .then((d) => {
      const u = d && d.user;
      if (!u) return;
      const orgName = d.org && d.org.name ? d.org.name : '';
      paint(orgName, u.email || '');
      cache(orgName, u.email || '');
      if (u.role === 'admin') showAdmin();
    });
})();
