<?php
declare(strict_types=1);

/** @var array<string, mixed> $endpoint */
$responses = $endpoint['responses'] ?? [];
$profile = (string) ($endpoint['headers_profile'] ?? '');
$mergeCommonErrors = $profile !== '' && $profile !== 'none' && !empty($apiCommonErrors);
if ($mergeCommonErrors) {
    $responses = array_merge($responses, $apiCommonErrors);
}

$method = strtoupper((string) ($endpoint['method'] ?? 'GET'));
$methodClass = api_doc_method_class($method);
$path = (string) ($endpoint['path'] ?? '');
?>
<article class="app-api-method" id="<?= htmlspecialchars((string) ($endpoint['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <header class="app-api-method-head">
        <button
            type="button"
            class="app-api-method-copy"
            data-api-copy="<?= htmlspecialchars($path, ENT_QUOTES, 'UTF-8') ?>"
            title="Скопировать путь: <?= htmlspecialchars($path, ENT_QUOTES, 'UTF-8') ?>"
        >
            <span class="app-api-method-badge <?= htmlspecialchars($methodClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($method, ENT_QUOTES, 'UTF-8') ?></span>
            <code class="app-api-method-path"><?= htmlspecialchars($path, ENT_QUOTES, 'UTF-8') ?></code>
            <span class="app-api-copy-label" aria-hidden="true">Копировать</span>
        </button>
    </header>
    <h3 class="app-api-method-title"><?= htmlspecialchars((string) ($endpoint['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
    <p class="app-muted"><?= htmlspecialchars((string) ($endpoint['summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
    <p class="app-api-method-auth"><strong>Доступ при вызове:</strong> <?= htmlspecialchars((string) ($endpoint['auth'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
    <?php if (!empty($endpoint['headers_profile'])): ?>
        <?php
        $headerPrefix = (string) ($endpoint['headers_prefix'] ?? $apiHeadersPrefix ?? 'modules');
        $profileId = (string) $endpoint['headers_profile'];
        $profileSymbol = api_doc_headers_profile_symbol($profileId);
        $profileCopy = api_doc_headers_profile_copy_for_prefix($headerPrefix, $profileId);
        ?>
        <p class="app-api-method-headers">
            <span class="app-muted small">Заготовка:</span>
            <?php if ($profileCopy !== ''): ?>
                <button
                    type="button"
                    class="app-api-header-symbol app-api-header-symbol-copy"
                    data-api-copy="<?= htmlspecialchars($profileCopy, ENT_QUOTES, 'UTF-8') ?>"
                    title="Скопировать состав <?= htmlspecialchars($profileSymbol, ENT_QUOTES, 'UTF-8') ?>"
                >
                    <?= htmlspecialchars($profileSymbol, ENT_QUOTES, 'UTF-8') ?>
                    <span class="app-api-copy-label" aria-hidden="true">Копировать</span>
                </button>
            <?php else: ?>
                <span class="app-api-header-symbol"><?= htmlspecialchars($profileSymbol, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <a href="#api-headers-kit" class="app-api-kit-ref small" data-api-tab-link="api-headers-docs">все заготовки</a>
        </p>
    <?php endif; ?>
    <?php if (!empty($endpoint['headers_extra'])): ?>
        <h4 class="app-api-spec-label">Дополнительные заголовки</h4>
        <div class="app-api-table-wrap">
            <table class="app-api-table app-api-spec-table">
                <thead><tr><th>Заголовок</th><th>Обяз.</th><th>Описание</th></tr></thead>
                <tbody>
                <?php foreach ($endpoint['headers_extra'] as $row): ?>
                    <tr>
                        <td><code><?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?></code></td>
                        <td><?= !empty($row['required']) ? 'да' : 'нет' ?></td>
                        <td><?= htmlspecialchars((string) $row['description'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!empty($endpoint['query'])): ?>
        <h4 class="app-api-spec-label">Параметры URL (query)</h4>
        <div class="app-api-table-wrap">
            <table class="app-api-table app-api-spec-table">
                <thead><tr><th>Параметр</th><th>Обяз.</th><th>Описание</th></tr></thead>
                <tbody>
                <?php foreach ($endpoint['query'] as $row): ?>
                    <tr>
                        <td><code><?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?></code></td>
                        <td><?= !empty($row['required']) ? 'да' : 'нет' ?></td>
                        <td><?= htmlspecialchars((string) $row['description'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!empty($endpoint['body'])): ?>
        <h4 class="app-api-spec-label">Тело запроса (<?= htmlspecialchars((string) ($endpoint['body']['content_type'] ?? 'application/json'), ENT_QUOTES, 'UTF-8') ?>)</h4>
        <?php if (!empty($endpoint['body']['fields'])): ?>
            <div class="app-api-table-wrap">
                <table class="app-api-table app-api-spec-table">
                    <thead><tr><th>Поле</th><th>Тип</th><th>Обяз.</th><th>Описание</th></tr></thead>
                    <tbody>
                    <?php foreach ($endpoint['body']['fields'] as $field): ?>
                        <tr data-api-field-row="<?= htmlspecialchars((string) $field['name'], ENT_QUOTES, 'UTF-8') ?>">
                            <td><code><?= htmlspecialchars((string) $field['name'], ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td><code><?= htmlspecialchars((string) $field['type'], ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td><?= !empty($field['required']) ? 'да' : 'нет' ?></td>
                            <td><?= htmlspecialchars((string) $field['description'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if (!empty($endpoint['body']['example'])): ?>
            <pre class="app-code-block"><?= htmlspecialchars((string) $endpoint['body']['example'], ENT_QUOTES, 'UTF-8') ?></pre>
        <?php endif; ?>
    <?php else: ?>
        <p class="app-muted small">Тело запроса не требуется.</p>
    <?php endif; ?>

    <h4 class="app-api-spec-label">Ответы<?php if ($mergeCommonErrors): ?> <span class="app-muted fw-normal">(+ типовые ошибки в «Общее»)</span><?php endif; ?></h4>
    <div class="app-api-table-wrap">
        <table class="app-api-table app-api-spec-table">
            <thead><tr><th>Код</th><th>Описание</th><th>Пример</th></tr></thead>
            <tbody>
            <?php foreach ($responses as $response): ?>
                <tr>
                    <td><code><?= (int) $response['code'] ?></code></td>
                    <td><?= htmlspecialchars((string) $response['description'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?php if (!empty($response['example'])): ?><code class="app-api-response-example"><?= htmlspecialchars((string) $response['example'], ENT_QUOTES, 'UTF-8') ?></code><?php else: ?>—<?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</article>
