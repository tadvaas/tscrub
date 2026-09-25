// Updates the shared nav bar based on auth state (Sign in ↔ Dashboard + admin links).
;(function () {
  const state = { user: null };

  function setNav() {
    const cta = document.getElementById('nav-cta');
    if (cta) {
      cta.textContent = 'Dashboard';
      cta.href = '/dashboard';
    }
  }

  async function signOut() {
    try {
      const csrfRes = await fetch('/api/csrf');
      const csrfData = await csrfRes.json();
      await fetch('/api/logout', {
        method: 'POST',
        headers: { 'X-CSRF-Token': (csrfData && csrfData.csrf) || '' }
      });
    } catch (e) {
      /* fall through to redirect */
    }
    window.location.href = '/';
  }

  async function load() {
    try {
      const res = await fetch('/api/me');
      const data = await res.json();
      state.user = (data && data.user) || null;
    } catch (e) {
      state.user = null;
    }
    setNav();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', load);
  } else {
    load();
  }
})();
