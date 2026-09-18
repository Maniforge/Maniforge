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

    <nav id="api-headers-toc" class="app-api-headers-toc" aria-label="Содержание заголовков">
        <?php foreach ($headersDocs['sections'] as $tocSection): ?>
            <a href="#<?= htmlspecialchars((string) $tocSection['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $tocSection['title'], ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
        <a href="#api-headers-overview">Справочник заголовков</a>
        <a href="#api-headers-auth-flow">Поток RBAC</a>
    </nav>

    <section id="api-headers-auth-flow" class="app-api-group" data-api-spy-section>
        <h3 class="app-title h4">Поток авторизации RBAC</h3>
        <ol class="app-api-cred-flow" aria-label="Диаграмма потока авторизации RBAC">
            <li class="app-api-cred-flow-step"><strong>Вход</strong><span class="app-muted">POST /rbac/api/v1/auth/login → access_token, refresh_token, csrf_token.</span></li>
            <li class="app-api-cred-flow-step"><strong>Чтение</strong><span class="app-muted">GET — Authorization: Bearer {access_token}.</span></li>
            <li class="app-api-cred-flow-step"><strong>Изменение</strong><span class="app-muted">POST / PATCH / DELETE — Bearer + X-CSRF-Token.</span></li>
            <li class="app-api-cred-flow-step"><strong>Admin step-up</strong><span class="app-muted">POST /auth/reauth → Bearer + X-Action-Token + CSRF.</span></li>
        </ol>
    </section>

    <section id="api-headers-kit" class="app-api-group" data-api-spy-section>
        <h3 class="app-title h4">Заготовки MF_HEADER_*</h3>
        <p class="app-muted small mb-3">
            Нажмите <code>MF_HEADER_*</code> в карточке метода — скопируется JSON-объект заголовков
            для <code>fetch</code>, Postman и клиентов. Подставьте токены вместо <code>{…}</code>.
        </p>

        <?php $firstKitSection = true; foreach ($headersDocs['sections'] as $section): ?>
            <h4
                class="app-api-kit-group-title<?= $firstKitSection ? '' : ' mt-4' ?>"
                id="<?= htmlspecialchars((string) $section['id'], ENT_QUOTES, 'UTF-8') ?>"
            ><?= htmlspecialchars((string) $section['title'], ENT_QUOTES, 'UTF-8') ?></h4>
            <?php $firstKitSection = false; $openFirst = true; ?>
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
                <details
                    class="app-api-profile-details"
                    id="<?= htmlspecialchars($rowId, ENT_QUOTES, 'UTF-8') ?>"
                    <?= $openFirst ? ' open' : '' ?>
                >
                    <?php $openFirst = false; ?>
                    <summary>
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
                        <strong><?= htmlspecialchars((string) $profile['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <span class="app-muted small"><?= htmlspecialchars((string) $profile['note'], ENT_QUOTES, 'UTF-8') ?></span>
                    </summary>
                    <div class="app-api-kit-headers">
                        <?php foreach ($headerNames as $headerName): ?>
                            <code><?= htmlspecialchars($headerName, ENT_QUOTES, 'UTF-8') ?></code>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
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
