// Dashboard sub-navigation — rendered into #dashboard-nav on dashboard pages.
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

// Subtle user detail inline right of the "Dashboard" heading: organisation
// name when in an org, else the account email. Populated from the API.
;(function () {
  const h1 = Array.from(document.querySelectorAll('h1')).find((el) => el.textContent.trim() === 'Dashboard');
  if (!h1) return;

  h1.classList.add('flex', 'items-baseline', 'justify-between', 'gap-4');
  const detail = document.createElement('span');
  detail.className = 'text-sm font-normal text-slate-500 dark:text-slate-400 truncate';
  h1.appendChild(detail);

  Promise.all([
    fetch('/api/me').then((r) => r.json()).catch(() => ({})),
    fetch('/api/org').then((r) => r.json()).catch(() => ({})),
  ]).then(([me, org]) => {
    if (org && org.org && org.org.name) {
      detail.textContent = org.org.name;
      detail.title = 'Organisation: ' + org.org.name;
    } else if (me && me.user && me.user.email) {
      detail.textContent = me.user.email;
      detail.title = me.user.email;
    }
  });
})();
