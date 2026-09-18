<?php
declare(strict_types=1);

$branding = $branding ?? require dirname(__DIR__) . '/data/branding.php';
$pageTitle = $pageTitle ?? ('API — ' . (string) ($branding['app_name'] ?? 'Maniforge'));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars((string) $pageTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></title>
  <link rel="stylesheet" href="/assets/desk.css?v=20260917k">
  <link rel="stylesheet" href="/assets/css/api-docs.css?v=20260917b">
  <script>
  (function () {
    try {
      if (localStorage.getItem('maniforge_access_token') || localStorage.getItem('maniforge_admin_access_token')) {
        document.documentElement.classList.add('maniforge-has-session');
      }
      var heroPref = localStorage.getItem('api-docs-hero-collapsed');
      var visited = localStorage.getItem('api-docs-visited');
      if (heroPref === '1' || (heroPref !== '0' && visited === '1')) {
        document.documentElement.classList.add('api-docs-compact');
      }
    } catch (e) {}
  })();
  </script>
</head>
<body class="app-site api-docs-desk">
<script>
(function () {
  try {
    if (document.documentElement.classList.contains('api-docs-compact')) {
      document.body.classList.add('api-docs-compact');
    }
  } catch (e) {}
})();
</script>
<header class="top">
  <a class="brand" href="/" aria-label="Maniforge">Mani<i>forge</i></a>
  <nav class="nav nav-guest">
    <a href="/about/">О проекте</a>
    <a href="/about-us/">О нас</a>
    <a href="/app/">Admin</a>
    <a href="/api/" aria-current="page">API</a>
    <a class="nav-cta" href="/desk/login/">Вход</a>
  </nav>
  <nav class="nav nav-auth" hidden>
    <a href="/desk/">Desk</a>
    <a href="/desk/users/">Пользователи</a>
    <a href="/apps/">Apps</a>
    <a href="/app/">Admin</a>
    <a href="/scanner/">Scanner</a>
    <a href="/api/" aria-current="page">API</a>
    <button type="button" data-logout>Выйти</button>
  </nav>
</header>
<main class="app-main app-main-wide">
