<?php
declare(strict_types=1);
$pageTitle = '404';
require __DIR__ . '/layout/header.php';
?>
<section class="app-page-head app-shell text-center">
    <span class="app-kicker">404</span>
    <h1 class="app-title display-4">Страница не найдена</h1>
    <p class="app-lead mx-auto">Проверьте адрес или вернитесь на предыдущую страницу.</p>
    <div class="app-actions justify-content-center mt-4">
        <button type="button" class="btn btn-primary" id="back404">Назад</button>
    </div>
</section>
<script>
(function () {
    var btn = document.getElementById('back404');
    if (!btn) return;
    function goBack() {
        if (window.history.length > 1) {
            window.history.back();
            return;
        }
        window.location.href = document.referrer && document.referrer !== window.location.href
            ? document.referrer
            : '/';
    }
    btn.addEventListener('click', goBack);
})();
</script>
<?php require __DIR__ . '/layout/footer.php';
