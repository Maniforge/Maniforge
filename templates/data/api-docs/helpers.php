<?php
declare(strict_types=1);

/**
 * @return list<array{name:string,required?:bool,description:string}>
 */
function api_doc_headers_tenant_login(): array
{
    return [
        ['name' => 'Content-Type', 'required' => true, 'description' => 'application/json'],
        ['name' => 'Accept', 'required' => false, 'description' => 'application/json (рекомендуется).'],
        ['name' => 'X-Tenant-ID', 'required' => true, 'description' => 'Код тенанта — только на login (multi). В single не нужен.'],
        ['name' => 'X-Subtenant-ID', 'required' => true, 'description' => 'Код субтенанта — только на login (multi). Альтернатива: tenant_id/subtenant_id в JSON.'],
    ];
}

/**
 * @return list<array{name:string,required?:bool,description:string}>
 */
function api_doc_headers_bearer_session(bool $csrf = false): array
{
    $rows = [
        ['name' => 'Accept', 'required' => false, 'description' => 'application/json (рекомендуется).'],
        ['name' => 'Authorization', 'required' => true, 'description' => 'Bearer {access_token} — scope tenant/subtenant уже в сессии, X-Tenant-ID не нужен.'],
    ];
    if ($csrf) {
        $rows[] = ['name' => 'X-CSRF-Token', 'required' => true, 'description' => 'csrf_token из ответа login (POST/PATCH/PUT/DELETE).'];
    }

    return $rows;
}

/**
 * @return list<array{name:string,required?:bool,description:string}>
 */
function api_doc_headers_bearer_action(bool $csrf = true): array
{
    $rows = api_doc_headers_bearer_session($csrf);
    $rows[] = [
        'name' => 'X-Action-Token',
        'required' => true,
        'description' => 'Короткоживущий токен из POST /auth/reauth (credentials.action). Нужен для admin-мутаций при require_step_up.',
    ];

    return $rows;
}

function api_doc_headers_platform(): array
{
    return [
        ['name' => 'Authorization', 'required' => true, 'description' => 'Bearer {TENANT_LICENSING_ADMIN_TOKEN}.'],
        ['name' => 'Content-Type', 'required' => true, 'description' => 'application/json — для запросов с телом.'],
        ['name' => 'Accept', 'required' => false, 'description' => 'application/json (рекомендуется).'],
    ];
}

function api_doc_error_responses(): array
{
    return [
        ['code' => 401, 'description' => 'Не авторизован или неверный токен.', 'example' => '{"ok":false,"error":"Не авторизован"}'],
        ['code' => 403, 'description' => 'Недостаточно прав, лицензия, квота или step-up (нужен reauth + X-Action-Token).', 'example' => '{"ok":false,"error":"Требуется step-up: POST /api/v1/auth/reauth, затем X-Action-Token","code":"step_up_required"}'],
        ['code' => 422, 'description' => 'Ошибка валидации входных данных.', 'example' => '{"ok":false,"error":"..."}'],
    ];
}

/**
 * @return list<array{label: string, href: string, tab: string}>
 */
function api_doc_reference_links(): array
{
    return [
        ['label' => 'Ключи и авторизация', 'href' => '#api-credentials-overview', 'tab' => 'api-credentials-docs'],
        ['label' => 'Заготовки заголовков', 'href' => '#api-headers-kit', 'tab' => 'api-headers-docs'],
    ];
}

/**
 * @return array{access: array{title: string, paragraphs: list<string>}, header_profiles: list<array{id: string, label: string, note: string, headers: list<array{name: string, required?: bool, description: string}>}>, errors: list<array{code: int, description: string, example?: string}>}
 */
function api_doc_rbac_common(): array
{
    return [
        'access' => [
            'title' => 'Обзор модуля',
            'paragraphs' => [
                'Публичный RBAC API: вход, сессии, роли, проекты, пользователи внутри tenant/workspace.',
                'Защищённые методы — session access_token после POST /rbac/api/v1/auth/login.',
            ],
            'links' => api_doc_reference_links(),
        ],
        'header_profiles' => [
            [
                'id' => 'none',
                'label' => 'Без авторизации',
                'note' => 'Health и мониторинг.',
                'headers' => [
                    ['name' => 'Accept', 'required' => false, 'description' => 'application/json (рекомендуется).'],
                ],
            ],
            [
                'id' => 'tenant_login',
                'label' => 'Login (выбор tenant)',
                'note' => 'Только POST /api/v1/auth/login — единственный шаг с tenant на границе.',
                'headers' => api_doc_headers_tenant_login(),
            ],
            [
                'id' => 'refresh',
                'label' => 'Refresh (без tenant)',
                'note' => 'POST /api/v1/auth/refresh — scope в refresh_token.',
                'headers' => [
                    ['name' => 'Content-Type', 'required' => true, 'description' => 'application/json'],
                    ['name' => 'Accept', 'required' => false, 'description' => 'application/json'],
                ],
            ],
            [
                'id' => 'bearer',
                'label' => 'Session (чтение)',
                'note' => 'GET и прочие без изменения состояния.',
                'headers' => api_doc_headers_bearer_session(false),
            ],
            [
                'id' => 'bearer_csrf',
                'label' => 'Session + CSRF',
                'note' => 'logout, switch-context, reauth, смена своего пароля.',
                'headers' => api_doc_headers_bearer_session(true),
            ],
            [
                'id' => 'bearer_action_csrf',
                'label' => 'Session + Action + CSRF',
                'note' => 'Admin-мутации при require_step_up: сначала reauth, затем X-Action-Token.',
                'headers' => api_doc_headers_bearer_action(true),
            ],
        ],
        'errors' => api_doc_error_responses(),
    ];
}

/**
 * @return array{access: array{title: string, paragraphs: list<string>}, header_profiles: list<array{id: string, label: string, note: string, headers: list<array{name: string, required?: bool, description: string}>}>, errors: list<array{code: int, description: string, example?: string}>}
 */
function api_doc_platform_common(): array
{
    return [
        'access' => [
            'title' => 'Обзор модуля',
            'paragraphs' => [
                'Приватный API tenant-licensing: тенанты, тарифы, лицензии, делегирование agency.',
                'Доступ только с platform или internal token — не user session.',
            ],
            'links' => api_doc_reference_links(),
        ],
        'header_profiles' => [
            [
                'id' => 'none',
                'label' => 'Без авторизации',
                'note' => 'Health licensing.',
                'headers' => [
                    ['name' => 'Accept', 'required' => false, 'description' => 'application/json (рекомендуется).'],
                ],
            ],
            [
                'id' => 'platform',
                'label' => 'Токен платформы',
                'note' => 'Все /api/v1/* tenant-licensing для операторов.',
                'headers' => api_doc_headers_platform(),
            ],
            [
                'id' => 'internal',
                'label' => 'Внутренний токен',
                'note' => 'internal/v1/* и события в RBAC.',
                'headers' => [
                    ['name' => 'Authorization', 'required' => true, 'description' => 'Bearer {TENANT_LICENSING_INTERNAL_TOKEN} или RBAC_INTERNAL_TOKEN.'],
                    ['name' => 'Content-Type', 'required' => true, 'description' => 'application/json — для запросов с телом.'],
                ],
            ],
        ],
        'errors' => api_doc_error_responses(),
    ];
}

/**
 * Общий блок для модулей с RBAC Bearer (supply chain, manifest, realtime).
 *
 * @param list<string> $paragraphs
 * @param list<array{code: int, description: string, example?: string}> $extraErrors
 *
 * @return array{access: array{title: string, paragraphs: list<string>}, header_profiles: list<array{id: string, label: string, note: string, headers: list<array{name: string, required?: bool, description: string}>}>, errors: list<array{code: int, description: string, example?: string}>}
 */
function api_doc_module_bearer_common(string $accessTitle, array $paragraphs, array $extraErrors = []): array
{
    return [
        'access' => [
            'title' => $accessTitle,
            'paragraphs' => $paragraphs,
            'links' => api_doc_reference_links(),
        ],
        'header_profiles' => [
            [
                'id' => 'none',
                'label' => 'Без авторизации',
                'note' => 'Health и мониторинг.',
                'headers' => [
                    ['name' => 'Accept', 'required' => false, 'description' => 'application/json (рекомендуется).'],
                ],
            ],
            [
                'id' => 'bearer',
                'label' => 'Session Bearer',
                'note' => 'Authorization: Bearer {access_token} после login; tenant/subtenant/project — в сессии.',
                'headers' => api_doc_headers_bearer_session(false),
            ],
        ],
        'errors' => array_merge(api_doc_error_responses(), $extraErrors),
    ];
}

function api_doc_method_class(string $method): string
{
    return match (strtoupper(trim($method))) {
        'POST' => 'app-api-method-post',
        'PATCH' => 'app-api-method-patch',
        'PUT' => 'app-api-method-put',
        'DELETE' => 'app-api-method-delete',
        default => 'app-api-method-get',
    };
}

/**
 * Символ профиля HTTP-заголовков для UI документации (заготовка).
 */
function api_doc_headers_profile_symbol(string $profileId): string
{
    return match ($profileId) {
        'none' => 'MF_HEADER_NIL',
        'tenant_login' => 'MF_HEADER_TENANT_LOGIN',
        'refresh' => 'MF_HEADER_REFRESH',
        'bearer' => 'MF_HEADER_BEARER',
        'bearer_csrf' => 'MF_HEADER_BEARER_CSRF',
        'bearer_action_csrf' => 'MF_HEADER_BEARER_ACTION_CSRF',
        'platform' => 'MF_HEADER_PLATFORM',
        'internal' => 'MF_HEADER_INTERNAL',
        default => 'MF_HEADER_' . strtoupper(trim((string) preg_replace('/[^a-z0-9]+/i', '_', $profileId), '_')),
    };
}

/** @deprecated Используйте app-api-header-symbol — один приглушённый стиль без цветового кодирования. */
function api_doc_headers_profile_chip_class(string $profileId): string
{
    unset($profileId);

    return 'app-api-header-symbol';
}

/**
 * @return array<string, mixed>
 */
function api_doc_headers_catalog(): array
{
    static $catalog = null;
    if (!is_array($catalog)) {
        $catalog = require __DIR__ . '/headers.php';
    }

    return $catalog;
}

/**
 * @return array<string, mixed>|null
 */
function api_doc_headers_profile_def(string $prefix, string $profileId): ?array
{
    foreach (api_doc_headers_catalog()['sections'] as $section) {
        if ((string) ($section['profile_prefix'] ?? '') !== $prefix) {
            continue;
        }
        foreach ($section['profiles'] as $profile) {
            if (!is_array($profile)) {
                continue;
            }
            if ((string) ($profile['id'] ?? '') === $profileId) {
                return $profile;
            }
        }
    }

    return null;
}

function api_doc_headers_profile_copy_for_prefix(string $prefix, string $profileId): string
{
    $profile = api_doc_headers_profile_def($prefix, $profileId);
    if ($profile === null) {
        return '';
    }

    return api_doc_headers_profile_copy_block($profile);
}

function api_doc_headers_copy_value(string $name, string $profileId): string
{
    if ($name === 'Authorization') {
        return match ($profileId) {
            'platform' => 'Bearer {TENANT_LICENSING_ADMIN_TOKEN}',
            'internal' => 'Bearer {TENANT_LICENSING_INTERNAL_TOKEN}',
            default => 'Bearer {access_token}',
        };
    }

    return match ($name) {
        'X-CSRF-Token' => '{csrf_token}',
        'X-Action-Token' => '{action_token}',
        'X-Tenant-ID' => '{tenant_id}',
        'X-Subtenant-ID' => '{subtenant_id}',
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        default => '...',
    };
}

/**
 * JSON-объект заголовков для вставки в fetch/axios/Postman.
 *
 * @param array<string, mixed> $profile
 */
function api_doc_headers_profile_copy_block(array $profile): string
{
    $profileId = (string) ($profile['id'] ?? '');
    $headers = [];
    foreach ($profile['headers'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = (string) ($row['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $headers[$name] = api_doc_headers_copy_value($name, $profileId);
    }

    if ($headers === []) {
        return '';
    }

    $json = json_encode($headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json : '';
}

/**
 * OpenAPI schema property → поле тела запроса для карточки endpoint.
 *
 * @param array<string, mixed> $prop
 *
 * @return array{name: string, type: string, required: bool, description: string}
 */
function api_doc_openapi_schema_field(string $name, array $prop, bool $required): array
{
    $type = (string) ($prop['type'] ?? 'object');
    if ($type === 'integer') {
        $type = 'number';
    }
    $parts = [];
    $desc = trim((string) ($prop['description'] ?? ''));
    if ($desc !== '') {
        $parts[] = $desc;
    }
    if (isset($prop['maxLength'])) {
        $parts[] = 'max_length: ' . (int) $prop['maxLength'];
    }
    if (isset($prop['minimum'])) {
        $parts[] = 'min: ' . $prop['minimum'];
    }
    if (isset($prop['maximum'])) {
        $parts[] = 'max: ' . $prop['maximum'];
    }

    return [
        'name' => $name,
        'type' => $type,
        'required' => $required,
        'description' => implode('; ', $parts),
    ];
}

/**
 * manifest.fields[] → строка таблицы схемы.
 *
 * @param array<string, mixed> $field
 *
 * @return array{name: string, type: string, required: bool, description: string}
 */
function api_doc_manifest_field_to_doc_row(array $field): array
{
    $parts = [];
    if (isset($field['max_length'])) {
        $parts[] = 'max_length: ' . (int) $field['max_length'];
    }
    if (isset($field['min'])) {
        $parts[] = 'min: ' . $field['min'];
    }
    if (isset($field['max'])) {
        $parts[] = 'max: ' . $field['max'];
    }
    if (!empty($field['items']) && is_array($field['items'])) {
        $parts[] = 'items: ' . (string) ($field['items']['type'] ?? 'string');
    }

    return [
        'name' => (string) ($field['name'] ?? ''),
        'type' => (string) ($field['type'] ?? 'string'),
        'required' => !empty($field['required']),
        'description' => implode('; ', $parts),
    ];
}

/**
 * @param list<mixed> $fields
 *
 * @return list<array{name: string, type: string, required: bool, description: string}>
 */
function api_doc_manifest_fields_to_rows(array $fields): array
{
    $rows = [];
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $rows[] = api_doc_manifest_field_to_doc_row($field);
    }

    return $rows;
}

/**
 * @param array<string, mixed> $field
 */
function api_doc_example_value_for_manifest_field(array $field): mixed
{
    $name = (string) ($field['name'] ?? '');
    $type = (string) ($field['type'] ?? 'string');

    return match ($type) {
        'number' => isset($field['min']) ? (float) $field['min'] + 1 : 100,
        'boolean' => false,
        'array' => [],
        'object' => (object) [],
        default => match (true) {
            str_contains($name, 'email') => 'user@example.com',
            str_contains($name, 'phone') => '+79001234567',
            str_contains($name, 'date') => '2026-06-08',
            str_contains($name, 'currency') => 'RUB',
            str_contains($name, 'status') => 'draft',
            str_contains($name, 'number') => 'INV-001',
            str_contains($name, 'amount') => 1500.5,
            str_contains($name, 'name') || str_contains($name, 'title') || str_contains($name, 'company') => 'Пример',
            default => '',
        },
    };
}

/**
 * @param list<mixed> $fields
 */
function api_doc_example_json_from_manifest_fields(array $fields): string
{
    $payload = [];
    foreach ($fields as $field) {
        if (!is_array($field) || ($field['name'] ?? '') === '') {
            continue;
        }
        $payload[(string) $field['name']] = api_doc_example_value_for_manifest_field($field);
    }

    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

/**
 * OpenAPI 3 paths → карточки endpoint (как в api-endpoint-doc.php).
 *
 * @param array<string, mixed> $spec
 *
 * @return list<array<string, mixed>>
 */
function api_doc_manifest_openapi_to_endpoints(string $manifestCode, array $spec, string $pathPrefix = '/manifest-engine', ?array $manifestFields = null): array
{
    $paths = $spec['paths'] ?? [];
    if (!is_array($paths)) {
        return [];
    }

    $info = is_array($spec['info'] ?? null) ? $spec['info'] : [];
    $infoTitle = (string) ($info['title'] ?? $manifestCode);
    $endpoints = [];
    $order = ['get' => 1, 'post' => 2, 'put' => 3, 'patch' => 4, 'delete' => 5];

    foreach ($paths as $path => $methods) {
        if (!is_array($methods)) {
            continue;
        }
        $sorted = $methods;
        uksort($sorted, static function (string $a, string $b) use ($order): int {
            return ($order[strtolower($a)] ?? 99) <=> ($order[strtolower($b)] ?? 99);
        });

        foreach ($sorted as $method => $operation) {
            if (!is_array($operation)) {
                continue;
            }
            $httpMethod = strtoupper((string) $method);
            $fullPath = $pathPrefix . (string) $path;
            $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($manifestCode . '-' . $httpMethod . '-' . $path)) ?? $manifestCode;
            $slug = trim((string) $slug, '-');

            $query = [];
            foreach ($operation['parameters'] ?? [] as $param) {
                if (!is_array($param) || ($param['in'] ?? '') !== 'query') {
                    continue;
                }
                $query[] = [
                    'name' => (string) ($param['name'] ?? ''),
                    'required' => !empty($param['required']),
                    'description' => (string) ($param['description'] ?? ''),
                ];
            }

            $body = null;
            $requestBody = $operation['requestBody'] ?? null;
            if (is_array($requestBody)) {
                $content = $requestBody['content']['application/json'] ?? null;
                if (is_array($content)) {
                    $schema = $content['schema'] ?? [];
                    $fields = [];
                    $requiredNames = $schema['required'] ?? [];
                    if (!is_array($requiredNames)) {
                        $requiredNames = [];
                    }
                    $requiredSet = array_fill_keys($requiredNames, true);
                    foreach ($schema['properties'] ?? [] as $fieldName => $fieldProp) {
                        if (!is_string($fieldName) || !is_array($fieldProp)) {
                            continue;
                        }
                        $fields[] = api_doc_openapi_schema_field($fieldName, $fieldProp, isset($requiredSet[$fieldName]));
                    }
                    $example = '{}';
                    if ($manifestFields !== null && count($fields) > 1) {
                        $example = api_doc_example_json_from_manifest_fields($manifestFields);
                    } elseif ($manifestFields !== null && count($fields) === 1 && ($fields[0]['name'] ?? '') === 'value') {
                        if (preg_match('#/\{id\}/([^/{}]+)$#', (string) $path, $m) === 1) {
                            $fieldName = $m[1];
                            foreach ($manifestFields as $mf) {
                                if (!is_array($mf) || (string) ($mf['name'] ?? '') !== $fieldName) {
                                    continue;
                                }
                                $example = json_encode(
                                    ['value' => api_doc_example_value_for_manifest_field($mf)],
                                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                                ) ?: '{}';
                                break;
                            }
                        }
                    } else {
                        $examplePayload = [];
                        foreach ($fields as $field) {
                            $examplePayload[$field['name']] = match ($field['type']) {
                                'number' => 0,
                                'boolean' => false,
                                'array' => [],
                                default => '',
                            };
                        }
                        $example = json_encode($examplePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
                    }
                    $body = [
                        'content_type' => 'application/json',
                        'fields' => $fields,
                        'example' => $example,
                    ];
                }
            }

            $responses = [];
            foreach ($operation['responses'] ?? [] as $code => $response) {
                if (!is_array($response)) {
                    continue;
                }
                $responses[] = [
                    'code' => is_numeric($code) ? (int) $code : 200,
                    'description' => (string) ($response['description'] ?? 'OK'),
                    'example' => '',
                ];
            }
            if ($responses === []) {
                $responses[] = ['code' => 200, 'description' => 'OK', 'example' => ''];
            }

            $endpoints[] = [
                'id' => 'mf-gen-' . $slug,
                'method' => $httpMethod,
                'path' => $fullPath,
                'title' => (string) ($operation['summary'] ?? $httpMethod . ' ' . $path),
                'summary' => 'Автогенерация из GET /api/v1/manifests/' . $manifestCode . '/openapi — ' . $infoTitle,
                'auth' => 'Bearer session.',
                'headers_profile' => 'bearer',
                'query' => $query,
                'body' => $body,
                'responses' => $responses,
                'generated' => true,
            ];
        }
    }

    return $endpoints;
}

function api_doc_manifest_slug(string $raw, string $fallback = 'general'): string
{
    $slug = preg_replace('/[^a-z0-9-]+/i', '-', strtolower(trim($raw))) ?? $fallback;
    $slug = trim((string) $slug, '-');
    return $slug !== '' ? $slug : $fallback;
}

function api_doc_manifest_type_label(string $typeKey): string
{
    static $ru = [
        'finance' => 'Финансы',
        'crm' => 'CRM',
        'docs' => 'Документы',
        'general' => 'Общее',
        'sales' => 'Продажи',
        'warehouse' => 'Склад',
    ];
    $key = api_doc_manifest_slug($typeKey, '');
    if ($key === '') {
        return 'Общее';
    }
    if (isset($ru[$key])) {
        return $ru[$key];
    }

    return mb_convert_case(str_replace('-', ' ', $key), MB_CASE_TITLE, 'UTF-8');
}

function api_doc_manifest_section_label(string $sectionKey): string
{
    static $ru = [
        'general' => 'Общие',
        'sales' => 'Продажи',
        'customers' => 'Клиенты',
        'finance' => 'Финансы',
        'crm' => 'CRM',
        'operations' => 'Операции',
    ];
    $key = api_doc_manifest_slug($sectionKey, 'general');
    if (isset($ru[$key])) {
        return $ru[$key];
    }

    return mb_convert_case(str_replace('-', ' ', $key), MB_CASE_TITLE, 'UTF-8');
}

/**
 * @return list<array<string, mixed>>
 */
function api_doc_load_generated_manifests(string $generatedDir): array
{
    if (!is_dir($generatedDir)) {
        return [];
    }

    $manifests = [];
    foreach (glob($generatedDir . '/*.openapi.json') ?: [] as $file) {
        $raw = file_get_contents($file);
        if ($raw === false) {
            continue;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            continue;
        }

        $code = (string) ($payload['manifest_code'] ?? basename((string) $file, '.openapi.json'));
        $spec = $payload['openapi'] ?? $payload;
        if (!is_array($spec)) {
            continue;
        }

        $manifestFields = is_array($payload['manifest_fields'] ?? null) ? $payload['manifest_fields'] : [];
        $endpoints = api_doc_manifest_openapi_to_endpoints($code, $spec, '/manifest-engine', $manifestFields);
        if ($endpoints === []) {
            continue;
        }

        $info = is_array($spec['info'] ?? null) ? $spec['info'] : [];
        $manifests[] = [
            'code' => $code,
            'name' => (string) ($payload['manifest_name'] ?? $info['title'] ?? $code),
            'type' => api_doc_manifest_slug((string) ($payload['manifest_type'] ?? ''), ''),
            'section' => api_doc_manifest_slug((string) ($payload['manifest_section'] ?? ''), 'general'),
            'source' => basename((string) $file),
            'fields' => $manifestFields,
            'endpoints' => $endpoints,
        ];
    }

    usort($manifests, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

    return $manifests;
}

function api_doc_personal_nav_purpose(array $manifests): string
{
    if ($manifests === []) {
        return 'Custom manifest, REST по сущностям';
    }

    $parts = [];
    foreach ($manifests as $manifest) {
        $parts[] = (string) $manifest['name'] . ' (' . api_doc_manifest_type_label((string) $manifest['type']) . ')';
    }

    return implode(' · ', $parts);
}

/**
 * Документация одной вкладки-раздела (как Tenant Licensing).
 *
 * @param list<array<string, mixed>> $manifests
 *
 * @return array<string, mixed>
 */
function api_doc_build_personal_module_docs(string $sectionKey, array $manifests, bool $withLive = false): array
{
    $sectionLabel = api_doc_manifest_section_label($sectionKey);
    $groups = [];
    foreach ($manifests as $manifest) {
        $fields = is_array($manifest['fields'] ?? null) ? $manifest['fields'] : [];
        if ($fields !== []) {
            $postEndpointId = '';
            foreach ($manifest['endpoints'] as $ep) {
                if (strtoupper((string) ($ep['method'] ?? '')) === 'POST') {
                    $postEndpointId = (string) ($ep['id'] ?? '');
                    break;
                }
            }
            $groups[] = [
                'id' => 'personal-fields-' . (string) $manifest['code'],
                'title' => 'Схема полей — ' . (string) $manifest['name'],
                'generated' => true,
                'is_fields_panel' => true,
                'manifest_code' => (string) $manifest['code'],
                'manifest_type' => (string) $manifest['type'],
                'manifest_fields' => $fields,
                'field_rows' => api_doc_manifest_fields_to_rows($fields),
                'example_json' => api_doc_example_json_from_manifest_fields($fields),
                'post_endpoint_id' => $postEndpointId,
            ];
        }
        $groups[] = [
            'id' => 'personal-manifest-' . (string) $manifest['code'],
            'title' => (string) $manifest['name'],
            'generated' => true,
            'manifest_code' => (string) $manifest['code'],
            'manifest_type' => (string) $manifest['type'],
            'source' => (string) $manifest['source'],
            'endpoints' => $manifest['endpoints'],
        ];
    }

    if ($withLive) {
        $groups[] = [
            'id' => 'personal-live-openapi',
            'title' => 'Live-загрузка',
            'endpoints' => [],
            'is_live_panel' => true,
        ];
    }

    $typeList = array_unique(array_map(
        static fn (array $m): string => api_doc_manifest_type_label((string) $m['type']),
        $manifests,
    ));

    return [
        'title' => $sectionLabel,
        'description' => 'Раздел «' . $sectionLabel . '»: REST custom manifest, автогенерация из fields[] → OpenAPI → карточки ниже. '
            . 'Типы: ' . implode(', ', $typeList) . '.',
        'openapi' => null,
        'common' => api_doc_module_bearer_common(
            'Обзор',
            [
                'Раздел каталога: ' . $sectionLabel . ' (поле section при создании manifest).',
                'Схема полей — из manifest.fields[]; REST-карточки — из GET /api/v1/manifests/{code}/openapi.',
                'Обновление статики: make manifest-openapi-export-live.',
            ],
        ),
        'groups' => $groups,
        'live_openapi' => [
            'enabled' => $withLive,
            'default_base' => 'http://127.0.0.1:8095',
            'default_code' => $manifests[0]['code'] ?? 'invoice',
        ],
    ];
}

/**
 * Вкладки «Персональные» — по одной на раздел (section), как RBAC / Licensing в «Платформа».
 *
 * @return list<array<string, mixed>>
 */
function api_doc_build_personal_modules(string $generatedDir): array
{
    $manifests = api_doc_load_generated_manifests($generatedDir);
    if ($manifests === []) {
        return [[
            'key' => 'personal-general',
            'inline' => true,
            'section_id' => 'api-personal-general-docs',
            'nav_label' => 'Общие',
            'nav_purpose' => 'Нет экспортированных manifest',
            'badge' => 'module',
            'badge_label' => 'Персональные',
            'common_nav_label' => 'Обзор',
            'show_live_panel' => true,
            'docs' => api_doc_build_personal_module_docs('general', [], true),
            'actions' => [
                ['label' => 'Manifest Engine', 'href' => '/api#api-manifest-docs'],
            ],
        ]];
    }

    $bySection = [];
    foreach ($manifests as $manifest) {
        $bySection[(string) $manifest['section']][] = $manifest;
    }

    uksort($bySection, static function (string $a, string $b): int {
        if ($a === 'general') {
            return -1;
        }
        if ($b === 'general') {
            return 1;
        }

        return strcmp(api_doc_manifest_section_label($a), api_doc_manifest_section_label($b));
    });

    $modules = [];
    $first = true;
    foreach ($bySection as $sectionKey => $items) {
        $slug = api_doc_manifest_slug($sectionKey, 'general');
        $modules[] = [
            'key' => 'personal-' . $slug,
            'inline' => true,
            'section_id' => 'api-personal-' . $slug . '-docs',
            'nav_label' => api_doc_manifest_section_label($slug),
            'nav_purpose' => api_doc_personal_nav_purpose($items),
            'badge' => 'module',
            'badge_label' => 'Персональные',
            'common_nav_label' => 'Обзор',
            'show_live_panel' => $first,
            'docs' => api_doc_build_personal_module_docs($slug, $items, $first),
            'actions' => [
                ['label' => 'Manifest Engine', 'href' => '/api#api-manifest-docs'],
            ],
        ];
        $first = false;
    }

    return $modules;
}

/**
 * @param array<string, mixed> $catalog
 */
function api_doc_merge_personal_modules(array &$catalog, string $generatedDir): void
{
    foreach ($catalog['categories'] as &$category) {
        if ((string) ($category['id'] ?? '') !== 'personal') {
            continue;
        }
        $category['modules'] = api_doc_build_personal_modules($generatedDir);
        break;
    }
    unset($category);
}
