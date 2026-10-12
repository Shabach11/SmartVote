<script>
(function () {
    const body = document.body;
    const toggle = document.getElementById('app-menu-toggle');
    const backdrop = document.getElementById('app-sidebar-backdrop');

    if (!toggle) {
        return;
    }

    function setOpen(isOpen) {
        body.classList.toggle('app-nav-open', isOpen);
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        localStorage.setItem('smartvote.sidebar.open', isOpen ? '1' : '0');
    }

    setOpen(localStorage.getItem('smartvote.sidebar.open') === '1');

    toggle.addEventListener('click', function () {
        setOpen(!body.classList.contains('app-nav-open'));
    });

    if (backdrop) {
        backdrop.addEventListener('click', function () {
            setOpen(false);
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            setOpen(false);
        }
    });
})();
</script>
</body>
</html>
