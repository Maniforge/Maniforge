<?php
/** @var string $pageTitle */
/** @var string $contentTemplate */
/** @var array $pageData */
/** @var string $bodyClass */
$pageTitle = $pageTitle ?? app_config('name');
$assetsVer = app_config('assets_version');
$bodyClass = trim('app-shell ' . ($bodyClass ?? ''));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="theme-color" content="#9f3333">
  <title><?= e($pageTitle) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="/assets/css/app.css?v=<?= e($assetsVer) ?>" rel="stylesheet">
</head>
<body class="<?= e($bodyClass) ?>">

<header class="app-header navbar navbar-dark bg-danger shadow-sm sticky-top">
  <div class="container-fluid px-2 px-sm-3">
    <a class="navbar-brand py-2 fs-6" href="/">
      <i class="bi bi-printer"></i>
      <span class="ms-1"><?= e(app_config('name')) ?></span>
    </a>
  </div>
</header>

<main class="app-main flex-grow-1">
  <?php view($contentTemplate, $pageData ?? []); ?>
</main>

<footer class="app-footer border-top bg-white py-2 d-none d-lg-block">
  <div class="container-fluid text-center text-muted small">
    Print Task · <?= APP_STUB ? 'заглушки' : 'БД' ?>
  </div>
</footer>

<div id="order-modal-root"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/app.js?v=<?= e($assetsVer) ?>"></script>
</body>
</html>
