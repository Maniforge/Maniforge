<?php
declare(strict_types=1);

/** @var array<string, mixed> $branding */
?>
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
