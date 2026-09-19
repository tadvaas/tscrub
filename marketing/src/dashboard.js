// Dashboard sub-navigation — rendered into #dashboard-nav on dashboard pages.
;(function () {
  const root = document.getElementById('dashboard-nav');
  if (!root) return;

  const links = [
    { href: '/dashboard', label: 'Overview' },
    { href: '/dashboard/certify', label: 'Certify' },
    { href: '/dashboard/reports', label: 'Reports' },
    { href: '/dashboard/licence', label: 'Licence' },
    { href: '/dashboard/settings', label: 'Account' }
  ];

  const path = (window.location.pathname || '/').replace(/\/$/, '') || '/';
  const wrap = document.createElement('div');
  wrap.className = 'flex flex-wrap gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-sm';

  links.forEach((l) => {
    const a = document.createElement('a');
    a.href = l.href;
    a.textContent = l.label;
    const active = path === l.href;
    a.className = 'rounded-lg px-3 py-2 text-sm font-semibold ' +
      (active ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100');
    wrap.appendChild(a);
  });

  root.appendChild(wrap);
})();
