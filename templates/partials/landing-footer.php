<?php
declare(strict_types=1);

/** @var array<string, mixed> $branding */
$uiTheme = strtolower(trim((string) (getenv('MANIFORGE_UI_THEME') ?: ($_ENV['MANIFORGE_UI_THEME'] ?? ''))));
$brandThemeEnabled = $uiTheme === 'brand';
?>
<?php if ($brandThemeEnabled): ?>
<footer class="l1-footer">
    <div class="l1-footer-inner">
        <div>
            <a href="https://maniforge.ru/">maniforge.ru</a>
            · <a href="/">Maniforge Platform</a>
        </div>
        <div>
            Platform by Maniforge
            · <a href="mailto:hello@maniforge.ru">hello@maniforge.ru</a>
            · <a href="https://maniforge.ru/app/keystore/">KeyStore</a>
        </div>
    </div>
</footer>
<?php else: ?>
<footer class="landing-footer">
    <div class="landing-footer-grid">
        <div>
            <strong class="landing-footer-brand">
                <i class="bi bi-hexagon-fill landing-footer-logo" aria-hidden="true"></i>
                <?= htmlspecialchars((string) $branding['company_name'], ENT_QUOTES, 'UTF-8') ?>
            </strong>
            <p class="app-muted small mb-0">Модульный B2B‑конструктор. API‑first. Для тех, кто не пишет инфраструктуру с нуля.</p>
        </div>
        <nav class="landing-footer-nav" aria-label="Навигация в подвале">
            <a href="/why">Почему Maniforge</a>
            <a href="/modules">Модули</a>
            <a href="/get-started">Как начать</a>
            <a href="/security">Безопасность</a>
            <a href="/pricing">Тарифы</a>
            <a href="/api">API</a>
            <a href="/developers">Разработчикам</a>
            <a href="/stack">Стек</a>
        </nav>
    </div>
    <p class="landing-footer-copy app-muted small mb-0">
        © <?= htmlspecialchars((string) $branding['company_year'], ENT_QUOTES, 'UTF-8') ?>
        <?= htmlspecialchars((string) $branding['company_name'], ENT_QUOTES, 'UTF-8') ?>
    </p>
</footer>
<?php endif; ?>
