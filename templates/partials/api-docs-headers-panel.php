<?php
declare(strict_types=1);

/** @var array<string, mixed> $module */
/** @var array<string, mixed> $headersDocs */
?>
<section id="<?= htmlspecialchars((string) $module['section_id'], ENT_QUOTES, 'UTF-8') ?>" class="app-panel app-api-module-body">
    <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start">
        <div>
            <span class="app-api-badge app-api-badge-reference">Справочник</span>
            <h2 class="app-title h3 mt-2"><?= htmlspecialchars((string) $headersDocs['title'], ENT_QUOTES, 'UTF-8') ?></h2>
            <?php if ((string) ($headersDocs['description'] ?? '') !== ''): ?>
                <p class="app-lead mb-0"><?= htmlspecialchars((string) $headersDocs['description'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </div>
        <?php if (!empty($headersDocs['actions'])): ?>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($headersDocs['actions'] as $action): ?>
                    <?php $actionTab = (string) ($action['tab'] ?? ''); ?>
                    <a
                        class="app-button app-button-secondary"
                        href="<?= htmlspecialchars((string) $action['href'], ENT_QUOTES, 'UTF-8') ?>"
                        <?= $actionTab !== '' ? ' data-api-tab-link="' . htmlspecialchars($actionTab, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
                    >
                        <?= htmlspecialchars((string) $action['label'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <section id="api-headers-kit" class="app-api-group" data-api-spy-section>
        <h3 class="app-title h4">Заготовки MF_HEADER_*</h3>
        <p class="app-muted small mb-3">
            Нажмите <code>MF_HEADER_*</code> в таблице или в карточке метода — скопируется JSON-объект заголовков
            для <code>fetch</code>, Postman и клиентов. Подставьте токены вместо <code>{…}</code>.
        </p>

        <?php $firstKitSection = true; foreach ($headersDocs['sections'] as $section): ?>
            <h4 class="app-api-kit-group-title<?= $firstKitSection ? '' : ' mt-4' ?>"><?= htmlspecialchars((string) $section['title'], ENT_QUOTES, 'UTF-8') ?></h4>
            <?php $firstKitSection = false; ?>
            <div class="app-api-table-wrap mb-2">
                <table class="app-api-table app-api-spec-table app-api-kit-table">
                    <thead>
                        <tr>
                            <th>Заготовка</th>
                            <th>Название</th>
                            <th>Когда</th>
                            <th>Состав</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($section['profiles'] as $profile): ?>
                        <?php
                        $profileId = (string) $profile['id'];
                        $rowId = 'api-headers-' . (string) $section['profile_prefix'] . '-' . $profileId;
                        $profileSymbol = api_doc_headers_profile_symbol($profileId);
                        $copyBlock = api_doc_headers_profile_copy_block($profile);
                        $headerNames = array_map(
                            static fn (array $row): string => (string) $row['name'],
                            array_filter($profile['headers'] ?? [], 'is_array'),
                        );
                        ?>
                        <tr id="<?= htmlspecialchars($rowId, ENT_QUOTES, 'UTF-8') ?>">
                            <td>
                                <?php if ($copyBlock !== ''): ?>
                                    <button
                                        type="button"
                                        class="app-api-header-symbol app-api-header-symbol-copy"
                                        data-api-copy="<?= htmlspecialchars($copyBlock, ENT_QUOTES, 'UTF-8') ?>"
                                        title="Скопировать состав <?= htmlspecialchars($profileSymbol, ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                        <?= htmlspecialchars($profileSymbol, ENT_QUOTES, 'UTF-8') ?>
                                        <span class="app-api-copy-label" aria-hidden="true">Копировать</span>
                                    </button>
                                <?php else: ?>
                                    <span class="app-api-header-symbol"><?= htmlspecialchars($profileSymbol, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string) $profile['label'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="app-muted small"><?= htmlspecialchars((string) $profile['note'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="app-api-kit-headers">
                                <?php foreach ($headerNames as $headerName): ?>
                                    <code><?= htmlspecialchars($headerName, ENT_QUOTES, 'UTF-8') ?></code>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </section>

    <section id="api-headers-overview" class="app-api-group" data-api-spy-section>
        <?php $overview = $headersDocs['overview']; ?>
        <h3 class="app-title h4">Справочник отдельных заголовков</h3>
        <?php foreach ($overview['paragraphs'] ?? [] as $paragraph): ?>
            <p class="app-muted small"><?= htmlspecialchars((string) $paragraph, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endforeach; ?>
        <div class="app-api-table-wrap">
            <table class="app-api-table app-api-spec-table">
                <thead>
                    <tr><th>Заголовок</th><th>Когда</th><th>Обяз.</th><th>Описание</th></tr>
                </thead>
                <tbody>
                <?php foreach ($overview['headers'] as $row): ?>
                    <tr>
                        <td><code><?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?></code></td>
                        <td><?= htmlspecialchars((string) $row['scope'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $row['required'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $row['description'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
