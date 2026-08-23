<script src="<?= htmlspecialchars($urlForAssets . 'js/bootstrap.js', ENT_QUOTES, 'UTF-8') ?>" type="text/javascript"></script>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-app-sidebar]').forEach((sidebar) => {
      const sidebarId = sidebar.id;
      const toggles = document.querySelectorAll(`[data-sidebar-toggle="${sidebarId}"]`);
      const closers = sidebar.querySelectorAll('[data-sidebar-close]');
      const backdrop = document.querySelector(`[data-sidebar-backdrop="${sidebarId}"]`);
      const mobileQuery = window.matchMedia('(max-width: 767.98px)');

      const setOpen = (open) => {
        if (mobileQuery.matches) {
          sidebar.classList.remove('is-collapsed');
          sidebar.classList.toggle('is-open', open);
          sidebar.setAttribute('aria-hidden', open ? 'false' : 'true');
          backdrop?.classList.toggle('is-visible', open);
        } else {
          sidebar.classList.remove('is-open');
          sidebar.classList.toggle('is-collapsed', !open);
          sidebar.setAttribute('aria-hidden', 'false');
          backdrop?.classList.remove('is-visible');
        }
        toggles.forEach((button) => button.setAttribute('aria-expanded', String(open)));
      };

      const toggle = () => {
        const isOpen = mobileQuery.matches
          ? sidebar.classList.contains('is-open')
          : !sidebar.classList.contains('is-collapsed');
        setOpen(!isOpen);
      };

      setOpen(!mobileQuery.matches);
      toggles.forEach((button) => button.addEventListener('click', toggle));
      closers.forEach((button) => button.addEventListener('click', () => setOpen(false)));
      backdrop?.addEventListener('click', () => setOpen(false));
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && mobileQuery.matches) setOpen(false);
      });
      mobileQuery.addEventListener('change', () => setOpen(!mobileQuery.matches));
    });
  });
</script>

