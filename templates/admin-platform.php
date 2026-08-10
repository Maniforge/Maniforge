<?php
declare(strict_types=1);

$branding = require __DIR__ . '/data/branding.php';
$pageTitle = 'Platform admin — ' . $branding['app_name'];
$activeNav = 'admin';

require __DIR__ . '/layout/header.php';
?>
<section class="app-page-head app-main-wide">
    <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center">
        <div>
            <span class="app-kicker">Platform scope</span>
            <h1 class="app-title h4 mb-0">Tenant licensing</h1>
        </div>
        <a class="app-button app-button-secondary" href="/admin">← К модулям админки</a>
    </div>
</section>
<iframe
    id="platformLicensingFrame"
    class="app-admin-frame app-main-wide"
    title="Platform licensing admin"
    src="/tenant-licensing/admin#access"
></iframe>
<script>
(function () {
    const token = localStorage.getItem('maniforge_platform_admin_token')
        || localStorage.getItem('maniforge_tl_admin_token')
        || '';
    const frame = document.getElementById('platformLicensingFrame');
    if (token !== '' && frame) {
        const url = new URL('/tenant-licensing/admin', window.location.origin);
        url.searchParams.set('token', token);
        url.hash = 'access';
        frame.src = url.pathname + url.search + url.hash;
        localStorage.setItem('maniforge_tl_admin_token', token);
    }
})();
</script>
<?php require __DIR__ . '/layout/footer.php';
