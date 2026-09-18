<?php
declare(strict_types=1);

/** @var list<array<string, mixed>> $modules */

$uiBadge = static function (string $ui): string {
    return match ($ui) {
        'admin' => '✓ Админка',
        'prototype' => '⚡ Прототип',
        default => 'API',
    };
};
?>
<div class="landing-modules-table-wrap">
    <table class="landing-modules-table">
        <thead>
            <tr>
                <th scope="col">Модуль</th>
                <th scope="col">UI</th>
                <th scope="col">Назначение</th>
                <th scope="col"><span class="visually-hidden">API</span></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($modules as $module): ?>
                <?php $apiHref = (string) ($module['api_href'] ?? ''); ?>
                <tr>
                    <td>
                        <span class="landing-modules-table-name">
                            <i class="bi bi-<?= htmlspecialchars((string) ($module['icon'] ?? 'box'), ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                            <?= htmlspecialchars((string) ($module['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td><span class="landing-modules-table-ui"><?= htmlspecialchars($uiBadge((string) ($module['ui'] ?? 'none')), ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?= htmlspecialchars((string) ($module['pitch'] ?? $module['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="landing-modules-table-action">
                        <?php if ($apiHref !== ''): ?>
                            <a class="app-button app-button-secondary landing-table-api-btn" href="<?= htmlspecialchars($apiHref, ENT_QUOTES, 'UTF-8') ?>">API</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
