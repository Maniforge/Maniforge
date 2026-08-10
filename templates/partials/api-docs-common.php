<?php
declare(strict_types=1);

/** @var array<string, mixed> $common */
/** @var string $sectionId */
?>
<section id="<?= htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8') ?>-common" class="app-api-common app-panel-soft" data-api-spy-section>
    <h3 class="app-title h4"><?= htmlspecialchars((string) ($common['access']['title'] ?? 'Общее'), ENT_QUOTES, 'UTF-8') ?></h3>
    <?php foreach ($common['access']['paragraphs'] as $paragraph): ?>
        <p class="app-muted"><?= htmlspecialchars((string) $paragraph, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endforeach; ?>

    <?php if (!empty($common['access']['links'])): ?>
        <p class="app-muted small">
            <?php foreach ($common['access']['links'] as $i => $refLink): ?>
                <?php if ($i > 0): ?> · <?php endif; ?>
                <a
                    href="<?= htmlspecialchars((string) $refLink['href'], ENT_QUOTES, 'UTF-8') ?>"
                    data-api-tab-link="<?= htmlspecialchars((string) $refLink['tab'], ENT_QUOTES, 'UTF-8') ?>"
                ><?= htmlspecialchars((string) $refLink['label'], ENT_QUOTES, 'UTF-8') ?></a>
            <?php endforeach; ?>
            . У каждого метода ниже — код заготовки <code>MF_HEADER_*</code>.
        </p>
    <?php endif; ?>

    <h4 class="app-api-spec-label">Типовые ошибки</h4>
    <p class="app-muted small">Для защищённых методов, если не указано иное в карточке.</p>
    <div class="app-api-table-wrap">
        <table class="app-api-table app-api-spec-table">
            <thead><tr><th>Код</th><th>Описание</th><th>Пример</th></tr></thead>
            <tbody>
            <?php foreach ($common['errors'] as $response): ?>
                <tr>
                    <td><code><?= (int) $response['code'] ?></code></td>
                    <td><?= htmlspecialchars((string) $response['description'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?php if (!empty($response['example'])): ?><code class="app-api-response-example"><?= htmlspecialchars((string) $response['example'], ENT_QUOTES, 'UTF-8') ?></code><?php else: ?>—<?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
