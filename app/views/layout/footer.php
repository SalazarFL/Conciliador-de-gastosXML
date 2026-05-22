        </main>
        <!-- /page-content -->

    </div>
    <!-- /app-main -->

</div>
<!-- /app-layout -->

<!-- Scripts -->
<?php $_ap = rtrim(parse_url(defined('APP_URL') ? APP_URL : '/xmlconcilia/public', PHP_URL_PATH), '/'); ?>
<script src="<?= $_ap ?>/assets/js/app.js"></script>

<script>
// Sidebar toggle (mobile)
(function () {
    var toggle  = document.getElementById('sidebarToggle');
    var sidebar = document.getElementById('sidebar');
    if (!toggle || !sidebar) return;

    // Overlay
    var overlay = document.createElement('div');
    overlay.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:99;';
    document.body.appendChild(overlay);

    function openSidebar() {
        sidebar.classList.add('open');
        overlay.style.display = 'block';
    }

    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.style.display = 'none';
    }

    toggle.addEventListener('click', function () {
        sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
    });

    overlay.addEventListener('click', closeSidebar);
})();
</script>

</body>
</html>
