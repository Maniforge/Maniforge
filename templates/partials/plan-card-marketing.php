<?php
declare(strict_types=1);

/** @var array{name:string,code:string,price:string,summary:string,features:list<string>,icon?:string,badge?:string|null} $plan */
$icon = (string) ($plan['icon'] ?? 'tag');
$badge = $plan['badge'] ?? null;
$highlight = ($plan['code'] ?? '') === 'business';
?>
<article class="app-card app-plan-card landing-plan-card<?= $highlight ? ' landing-plan-card-highlight' : '' ?>">
    <div class="app-plan-card-head">
        <div class="landing-plan-card-title">
            <?php require __DIR__ . '/landing-icon.php'; ?>
            <div>
                <h3 class="app-card-title mb-1"><?= htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                <?php if (is_string($badge) && $badge !== ''): ?>
                    <span class="landing-plan-badge"><?= htmlspecialchars($badge, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </div>
        </div>
        <strong class="app-plan-card-price"><?= htmlspecialchars($plan['price'], ENT_QUOTES, 'UTF-8') ?></strong>
    </div>
    <p class="app-muted app-plan-card-summary"><?= htmlspecialchars($plan['summary'], ENT_QUOTES, 'UTF-8') ?></p>
    <ul class="app-feature-list app-plan-card-features landing-plan-features">
        <?php foreach ($plan['features'] as $feature): ?>
            <li>
                <i class="bi bi-check-lg landing-plan-check" aria-hidden="true"></i>
                <?= htmlspecialchars($feature, ENT_QUOTES, 'UTF-8') ?>
            </li>
        <?php endforeach; ?>
    </ul>
</article>
