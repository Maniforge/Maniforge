<?php
declare(strict_types=1);

/** @var string $pageTitle */
/** @var string $docHeroTitle */
/** @var string $docRawUrl */
/** @var string $docSource */
/** @var string $activeNav */

$activeNav = $activeNav ?? '';
$extraStylesheets = ['/assets/css/doc-prose.css'];
$extraScripts = [
    'https://cdn.jsdelivr.net/npm/marked@15.0.7/marked.min.js',
    '/assets/js/docs-markdown.js',
];

require __DIR__ . '/layout/header.php';
?>
<section class="app-page-head app-shell">
    <span class="app-kicker">Документация</span>
    <h1 class="app-title h2"><?= htmlspecialchars($docHeroTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></h1>
    <div class="app-actions mt-3">
        <button type="button" class="app-button app-button-secondary" id="docBack">Назад</button>
        <a class="app-button app-button-secondary" href="<?= htmlspecialchars($docRawUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">Markdown</a>
        <a class="app-button app-button-secondary" href="/api">API</a>
    </div>
</section>

<div class="app-doc-layout app-main-wide">
    <aside class="app-doc-sidebar app-panel" aria-label="Содержание">
        <span class="app-kicker">Содержание</span>
        <nav class="app-doc-toc" id="docToc">
            <p class="app-muted small mb-0">Загрузка…</p>
        </nav>
    </aside>
    <article class="app-panel app-doc-prose" id="docContent" aria-busy="true">
        <p class="app-muted">Загрузка документа…</p>
    </article>
</div>

<script type="application/json" id="docMarkdownPayload"><?= json_encode(
    ['markdown' => $docSource],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>
<?php require __DIR__ . '/layout/footer.php';
