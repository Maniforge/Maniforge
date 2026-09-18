<?php
declare(strict_types=1);

/** @var string $icon Bootstrap Icons name without bi- prefix */
/** @var string $class Extra CSS classes */
$icon = (string) ($icon ?? '');
$class = (string) ($class ?? 'landing-icon');
if ($icon === '') {
    return;
}
?>
<i class="bi bi-<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
