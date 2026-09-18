<?php
declare(strict_types=1);

/**
 * Chrome как у KeyStore LocalOne (github.com/Maniforge/keystore → LocalOnePage.jsx).
 * Превью «maniforge.ru/app/maniforge» при MANIFORGE_UI_THEME=brand.
 *
 * @var string $activeNav
 */

$activeNav = $activeNav ?? '';
$origin = 'https://maniforge.ru';

$productLinks = [
    ['id' => 'home', 'href' => '/', 'label' => 'Обзор'],
    ['id' => 'modules', 'href' => '/modules', 'label' => 'Модули'],
    ['id' => 'pricing', 'href' => '/pricing', 'label' => 'Тарифы'],
    ['id' => 'security', 'href' => '/security', 'label' => 'Безопасность'],
    ['id' => 'api', 'href' => '/api', 'label' => 'API'],
    ['id' => 'developers', 'href' => '/developers', 'label' => 'Docs'],
    ['id' => 'get-started', 'href' => '/get-started', 'label' => 'Старт'],
];
?>
<header class="l1-header">
    <a href="<?= htmlspecialchars($origin, ENT_QUOTES, 'UTF-8') ?>/" class="l1-brand" aria-label="Maniforge — главная">
        Mani<span class="l1-accent">forge</span>
    </a>
    <nav class="l1-nav" aria-label="Сайт">
        <a href="<?= htmlspecialchars($origin, ENT_QUOTES, 'UTF-8') ?>/">Главная</a>
        <a href="<?= htmlspecialchars($origin, ENT_QUOTES, 'UTF-8') ?>/projects/">Проекты</a>
        <a href="<?= htmlspecialchars($origin, ENT_QUOTES, 'UTF-8') ?>/news/">Новости</a>
        <a href="/" aria-current="page">Platform</a>
        <a class="l1-nav-cta" href="<?= htmlspecialchars($origin, ENT_QUOTES, 'UTF-8') ?>/contact/">Связаться</a>
    </nav>
</header>
<nav class="l1-subnav" aria-label="Разделы продукта">
    <?php foreach ($productLinks as $plink): ?>
        <?php
        $isCurrent = $plink['id'] === 'home'
            ? ($activeNav === 'home' || $activeNav === '')
            : ($activeNav === $plink['id']);
        ?>
        <a
            href="<?= htmlspecialchars($plink['href'], ENT_QUOTES, 'UTF-8') ?>"
            <?= $isCurrent ? ' aria-current="page"' : '' ?>
        ><?= htmlspecialchars($plink['label'], ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
</nav>
