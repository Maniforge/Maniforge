<?php
declare(strict_types=1);

/** @var array<string, mixed> $group */
$postEndpointId = (string) ($group['post_endpoint_id'] ?? '');
$manifestCode = (string) ($group['manifest_code'] ?? '');
?>
<section id="<?= htmlspecialchars((string) $group['id'], ENT_QUOTES, 'UTF-8') ?>" class="app-api-group app-api-group-generated" data-api-spy-section>
    <h3 class="app-title h4">
        <?= htmlspecialchars((string) $group['title'], ENT_QUOTES, 'UTF-8') ?>
        <span class="app-api-badge app-api-badge-module ms-2">fields[]</span>
    </h3>
    <p class="app-muted small">
        <code><?= htmlspecialchars($manifestCode, ENT_QUOTES, 'UTF-8') ?></code>
        <?php if (!empty($group['manifest_type'])): ?>
            · тип <?= htmlspecialchars(api_doc_manifest_type_label((string) $group['manifest_type']), ENT_QUOTES, 'UTF-8') ?>
        <?php endif; ?>
        — автогенерация из <code>manifest.fields[]</code> (источник схемы для OpenAPI и REST).
        <?php if ($postEndpointId !== ''): ?>
            <a href="#<?= htmlspecialchars($postEndpointId, ENT_QUOTES, 'UTF-8') ?>" class="app-api-field-rest-link" data-api-field-rest>→ REST POST</a>
        <?php endif; ?>
    </p>

    <?php if (!empty($group['field_rows'])): ?>
        <div class="table-responsive mb-3">
            <table class="table table-sm app-api-table app-api-manifest-fields-table">
                <thead>
                    <tr>
                        <th>Поле</th>
                        <th>Тип</th>
                        <th>Обязательное</th>
                        <th>Ограничения</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($group['field_rows'] as $field): ?>
                        <?php $fieldName = (string) $field['name']; ?>
                        <tr data-mf-field="<?= htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') ?>">
                            <td>
                                <?php if ($postEndpointId !== ''): ?>
                                    <a
                                        href="#<?= htmlspecialchars($postEndpointId, ENT_QUOTES, 'UTF-8') ?>"
                                        class="app-api-field-link"
                                        data-api-field-link="<?= htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') ?>"
                                    ><code><?= htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') ?></code></a>
                                <?php else: ?>
                                    <code><?= htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') ?></code>
                                <?php endif; ?>
                            </td>
                            <td><code><?= htmlspecialchars((string) $field['type'], ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td><?= !empty($field['required']) ? 'да' : 'нет' ?></td>
                            <td><?= htmlspecialchars((string) $field['description'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!empty($group['example_json'])): ?>
        <p class="app-muted small mb-1">Пример записи (POST / PATCH body):</p>
        <pre class="app-code-block"><code><?= htmlspecialchars((string) $group['example_json'], ENT_QUOTES, 'UTF-8') ?></code></pre>
    <?php endif; ?>
</section>
