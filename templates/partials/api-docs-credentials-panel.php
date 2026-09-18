<?php
declare(strict_types=1);

/** @var array<string, mixed> $module */
/** @var array<string, mixed> $credentialsDocs */

$overview = $credentialsDocs['overview'] ?? [];
?>
<section id="<?= htmlspecialchars((string) $module['section_id'], ENT_QUOTES, 'UTF-8') ?>" class="app-panel app-api-module-body app-api-credentials-page">
    <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start">
        <div>
            <span class="app-api-badge app-api-badge-reference">Справочник</span>
            <h2 class="app-title h3 mt-2"><?= htmlspecialchars((string) $credentialsDocs['title'], ENT_QUOTES, 'UTF-8') ?></h2>
            <?php if ((string) ($credentialsDocs['description'] ?? '') !== ''): ?>
                <p class="app-lead mb-0"><?= htmlspecialchars((string) $credentialsDocs['description'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </div>
        <?php if (!empty($credentialsDocs['actions'])): ?>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($credentialsDocs['actions'] as $action): ?>
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

    <section id="api-credentials-overview" class="app-api-group" data-api-spy-section>
        <h3 class="app-title h4">С чего начать</h3>

        <?php if (!empty($overview['levels'])): ?>
            <div class="app-api-cred-levels">
                <?php foreach ($overview['levels'] as $level): ?>
                    <a href="#<?= htmlspecialchars((string) $level['anchor'], ENT_QUOTES, 'UTF-8') ?>" class="app-api-cred-level-card">
                        <span class="app-api-cred-level-badge"><?= htmlspecialchars((string) $level['badge'], ENT_QUOTES, 'UTF-8') ?></span>
                        <strong class="app-api-cred-level-title"><?= htmlspecialchars((string) $level['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <span class="app-muted small"><?= htmlspecialchars((string) $level['summary'], ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($overview['flow'])): ?>
            <h4 class="app-api-spec-label mt-4">Типовый сценарий</h4>
            <ol class="app-api-cred-flow">
                <?php foreach ($overview['flow'] as $step): ?>
                    <li class="app-api-cred-flow-step">
                        <strong><?= htmlspecialchars((string) $step['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <span class="app-muted"><?= htmlspecialchars((string) $step['text'], ENT_QUOTES, 'UTF-8') ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>

    <?php foreach ($credentialsDocs['sections'] as $section): ?>
        <section id="<?= htmlspecialchars((string) $section['id'], ENT_QUOTES, 'UTF-8') ?>" class="app-api-group" data-api-spy-section>
            <h3 class="app-title h4"><?= htmlspecialchars((string) $section['title'], ENT_QUOTES, 'UTF-8') ?></h3>

            <?php if (!empty($section['tokens'])): ?>
                <div class="app-api-cred-tokens">
                    <?php foreach ($section['tokens'] as $token): ?>
                        <?php
                        $headerPrefix = (string) ($token['header_prefix'] ?? '');
                        $headerProfile = (string) ($token['header_profile'] ?? '');
                        $profileSymbol = $headerProfile !== '' ? api_doc_headers_profile_symbol($headerProfile) : '';
                        $profileCopy = $headerPrefix !== '' && $headerProfile !== ''
                            ? api_doc_headers_profile_copy_for_prefix($headerPrefix, $headerProfile)
                            : '';
                        ?>
                        <article class="app-api-cred-token-card">
                            <header class="app-api-cred-token-head">
                                <div>
                                    <h4 class="app-api-cred-token-label"><?= htmlspecialchars((string) ($token['label'] ?? $token['name']), ENT_QUOTES, 'UTF-8') ?></h4>
                                    <code class="app-api-cred-token-name"><?= htmlspecialchars((string) $token['name'], ENT_QUOTES, 'UTF-8') ?></code>
                                </div>
                                <?php if ($profileSymbol !== '' && $profileCopy !== ''): ?>
                                    <button
                                        type="button"
                                        class="app-api-header-symbol app-api-header-symbol-copy"
                                        data-api-copy="<?= htmlspecialchars($profileCopy, ENT_QUOTES, 'UTF-8') ?>"
                                        title="Скопировать JSON <?= htmlspecialchars($profileSymbol, ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                        <?= htmlspecialchars($profileSymbol, ENT_QUOTES, 'UTF-8') ?>
                                        <span class="app-api-copy-label" aria-hidden="true">Копировать</span>
                                    </button>
                                <?php endif; ?>
                            </header>
                            <dl class="app-api-cred-token-dl">
                                <div>
                                    <dt>Когда нужен</dt>
                                    <dd><?= htmlspecialchars((string) ($token['when'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                                </div>
                                <div>
                                    <dt>Как получить</dt>
                                    <dd><?= htmlspecialchars((string) ($token['how_get'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                                </div>
                                <div>
                                    <dt>Как передать</dt>
                                    <dd><?= htmlspecialchars((string) ($token['how_send'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                                </div>
                                <div>
                                    <dt>Срок жизни</dt>
                                    <dd><?= htmlspecialchars((string) ($token['lifetime'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                                </div>
                            </dl>
                            <?php if ((string) ($token['client_warning'] ?? '') !== ''): ?>
                                <p class="app-api-cred-warning small mb-0"><?= htmlspecialchars((string) $token['client_warning'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($section['scenarios'])): ?>
                <div class="app-api-cred-scenarios">
                    <?php foreach ($section['scenarios'] as $scenario): ?>
                        <div class="app-api-cred-scenario">
                            <strong><?= htmlspecialchars((string) $scenario['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <p class="app-muted small mb-0"><?= htmlspecialchars((string) $scenario['text'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php foreach ($section['paragraphs'] ?? [] as $paragraph): ?>
                <p class="app-muted small"><?= htmlspecialchars((string) $paragraph, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
</section>
