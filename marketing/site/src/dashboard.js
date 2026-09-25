// Dashboard sub-navigation — rendered into #dashboard-nav on dashboard pages.
;(function () {
  const root = document.getElementById('dashboard-nav');
  if (!root) return;

  const links = [
    { href: '/dashboard/certificates', label: 'Certificates', side: 'left' },
    { href: '/dashboard/reports', label: 'Reports', side: 'left' },
    { href: '/dashboard/licences', label: 'Licences', side: 'left' },
    { href: '/dashboard/billing', label: 'Billing', side: 'right' },
    { href: '/dashboard/account', label: 'Account', side: 'right' }
  ];

  const path = (window.location.pathname || '/').replace(/\/$/, '') || '/';
  const wrap = document.createElement('div');
  wrap.className = 'flex flex-wrap items-center justify-between gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-sm';

  const groups = { left: document.createElement('div'), right: document.createElement('div') };
  groups.left.className = 'flex flex-wrap items-center gap-1';
  groups.right.className = 'flex flex-wrap items-center gap-1';

  links.forEach((l) => {
    const a = document.createElement('a');
    a.href = l.href;
    a.textContent = l.label;
    const active = path === l.href;
    a.className = 'rounded-lg px-3 py-2 text-sm font-semibold ' +
      (active ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100');
    groups[l.side].appendChild(a);
  });

  wrap.appendChild(groups.left);
  wrap.appendChild(groups.right);
  root.appendChild(wrap);
})();
