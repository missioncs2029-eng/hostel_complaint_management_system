(() => {
  const toggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  let backdrop = null;
  const closeMenu = () => { if (sidebar) sidebar.classList.remove('sidebar-open'); if (backdrop) backdrop.classList.remove('show'); };
  if (toggle && sidebar) {
    backdrop = document.createElement('div');
    backdrop.className = 'sidebar-backdrop';
    document.body.appendChild(backdrop);
    backdrop.addEventListener('click', closeMenu);
    toggle.addEventListener('click', () => {
      const open = sidebar.classList.toggle('sidebar-open');
      backdrop.classList.toggle('show', open);
    });
    sidebar.querySelectorAll('a.nav-link').forEach(a => a.addEventListener('click', closeMenu));
  }
  document.querySelectorAll('[data-dismiss] .alert-close').forEach(btn => btn.addEventListener('click', () => btn.closest('[data-dismiss]').remove()));
  document.querySelectorAll('.stat-card,.feature-card,.room-card,.notice-card').forEach((el, i) => { el.style.setProperty('--delay', `${Math.min(i * 55, 330)}ms`); });
  document.querySelectorAll('form').forEach(form => form.addEventListener('submit', () => {
    const btn = form.querySelector('button[type="submit"],button:not([type])');
    if (btn && !btn.disabled) {
      btn.classList.add('is-submitting');
      setTimeout(() => btn.classList.remove('is-submitting'), 5000);
    }
  }));
  // Restore buttons when the page is shown again from the back/forward cache.
  window.addEventListener('pageshow', () => document.querySelectorAll('.is-submitting').forEach(b => b.classList.remove('is-submitting')));
})();
