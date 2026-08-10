'use strict';

(function () {
    const form = document.getElementById('mf-live-openapi-form');
    const statusEl = document.getElementById('mf-live-status');
    const container = document.getElementById('mf-live-openapi-endpoints');
    // personal-live-openapi section reuses mf-live-* element ids
    if (!form || !statusEl || !container) {
        return;
    }

    const methodClass = {
        GET: 'app-api-method-get',
        POST: 'app-api-method-post',
        PATCH: 'app-api-method-patch',
        PUT: 'app-api-method-put',
        DELETE: 'app-api-method-delete',
    };

    function esc(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function schemaFields(schema) {
        if (!schema || typeof schema !== 'object') {
            return [];
        }
        const required = new Set(Array.isArray(schema.required) ? schema.required : []);
        const props = schema.properties || {};
        return Object.keys(props).map((name) => {
            const prop = props[name] || {};
            let type = prop.type || 'object';
            if (type === 'integer') {
                type = 'number';
            }
            return {
                name,
                type,
                required: required.has(name),
                description: prop.description || (prop.maxLength ? 'max_length: ' + prop.maxLength : ''),
            };
        });
    }

    function exampleFromFields(fields) {
        const payload = {};
        fields.forEach((field) => {
            if (field.type === 'number') {
                payload[field.name] = 0;
            } else if (field.type === 'boolean') {
                payload[field.name] = false;
            } else if (field.type === 'array') {
                payload[field.name] = [];
            } else {
                payload[field.name] = '';
            }
        });
        return JSON.stringify(payload);
    }

    function endpointsFromOpenAPI(code, spec) {
        const paths = spec.paths || {};
        const infoTitle = (spec.info && spec.info.title) || code;
        const order = { get: 1, post: 2, put: 3, patch: 4, delete: 5 };
        const endpoints = [];

        Object.keys(paths).forEach((path) => {
            const methods = paths[path] || {};
            const sorted = Object.keys(methods).sort((a, b) => (order[a] || 99) - (order[b] || 99));
            sorted.forEach((method) => {
                const op = methods[method] || {};
                const httpMethod = method.toUpperCase();
                const fullPath = '/manifest-engine' + path;
                const query = (op.parameters || [])
                    .filter((p) => p && p.in === 'query')
                    .map((p) => ({
                        name: p.name,
                        required: !!p.required,
                        description: p.description || '',
                    }));

                let body = null;
                const jsonSchema = op.requestBody
                    && op.requestBody.content
                    && op.requestBody.content['application/json']
                    && op.requestBody.content['application/json'].schema;
                if (jsonSchema) {
                    const fields = schemaFields(jsonSchema);
                    body = {
                        fields,
                        example: exampleFromFields(fields),
                    };
                }

                const responses = Object.keys(op.responses || {}).map((codeKey) => ({
                    code: Number.isFinite(Number(codeKey)) ? Number(codeKey) : 200,
                    description: (op.responses[codeKey] && op.responses[codeKey].description) || 'OK',
                }));

                endpoints.push({
                    method: httpMethod,
                    path: fullPath,
                    title: op.summary || httpMethod + ' ' + path,
                    summary: 'Live OpenAPI — ' + infoTitle,
                    query,
                    body,
                    responses: responses.length ? responses : [{ code: 200, description: 'OK' }],
                });
            });
        });

        return endpoints;
    }

    function renderEndpoints(code, endpoints, typeLabel) {
        if (!endpoints.length) {
            container.innerHTML = '<p class="app-muted">OpenAPI не содержит paths.</p>';
            container.hidden = false;
            return;
        }

        const html = endpoints.map((ep) => {
            const cls = methodClass[ep.method] || 'app-api-method-get';
            const queryRows = (ep.query || []).map((row) => (
                '<tr><td><code>' + esc(row.name) + '</code></td><td>'
                + (row.required ? 'да' : 'нет') + '</td><td>' + esc(row.description) + '</td></tr>'
            )).join('');

            const bodyBlock = ep.body
                ? '<h4 class="app-api-spec-label">Тело запроса (application/json)</h4>'
                    + '<div class="app-api-table-wrap"><table class="app-api-table app-api-spec-table">'
                    + '<thead><tr><th>Поле</th><th>Тип</th><th>Обяз.</th><th>Описание</th></tr></thead><tbody>'
                    + ep.body.fields.map((field) => (
                        '<tr><td><code>' + esc(field.name) + '</code></td><td><code>' + esc(field.type)
                        + '</code></td><td>' + (field.required ? 'да' : 'нет') + '</td><td>'
                        + esc(field.description) + '</td></tr>'
                    )).join('')
                    + '</tbody></table></div>'
                    + '<pre class="app-code-block">' + esc(ep.body.example) + '</pre>'
                : '<p class="app-muted small">Тело запроса не требуется.</p>';

            const responseRows = ep.responses.map((row) => (
                '<tr><td><code>' + esc(row.code) + '</code></td><td>' + esc(row.description) + '</td><td>—</td></tr>'
            )).join('');

            return (
                '<article class="app-api-method">'
                + '<header class="app-api-method-head"><button type="button" class="app-api-method-copy" data-api-copy="'
                + esc(ep.path) + '"><span class="app-api-method-badge ' + cls + '">' + esc(ep.method)
                + '</span><code class="app-api-method-path">' + esc(ep.path) + '</code></button></header>'
                + '<h3 class="app-api-method-title">' + esc(ep.title) + '</h3>'
                + '<p class="app-muted">' + esc(ep.summary) + '</p>'
                + '<p class="app-api-method-auth"><strong>Доступ при вызове:</strong> Bearer session.</p>'
                + (queryRows
                    ? '<h4 class="app-api-spec-label">Параметры URL (query)</h4><div class="app-api-table-wrap"><table class="app-api-table app-api-spec-table"><thead><tr><th>Параметр</th><th>Обяз.</th><th>Описание</th></tr></thead><tbody>'
                    + queryRows + '</tbody></table></div>'
                    : '')
                + bodyBlock
                + '<h4 class="app-api-spec-label">Ответы</h4><div class="app-api-table-wrap"><table class="app-api-table app-api-spec-table"><thead><tr><th>Код</th><th>Описание</th><th>Пример</th></tr></thead><tbody>'
                + responseRows + '</tbody></table></div></article>'
            );
        }).join('');

        container.innerHTML = '<h4 class="app-title h5">Live REST — ' + esc(typeLabel || 'Общие')
            + ' / ' + esc(code) + '</h4>' + html;
        container.hidden = false;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const base = document.getElementById('mf-live-base').value.replace(/\/$/, '');
        const code = document.getElementById('mf-live-code').value.trim();
        const token = document.getElementById('mf-live-token').value.trim();

        if (!code) {
            statusEl.textContent = 'Укажите код manifest.';
            return;
        }
        if (!token) {
            statusEl.textContent = 'Нужен Bearer access_token.';
            return;
        }

        statusEl.textContent = 'Загрузка…';
        container.hidden = true;

        try {
            const headers = {
                Accept: 'application/json',
                Authorization: 'Bearer ' + token,
            };
            const manRes = await fetch(base + '/api/v1/manifests/' + encodeURIComponent(code), { headers });
            const manPayload = await manRes.json();
            const manifestType = manPayload && manPayload.manifest && manPayload.manifest.type
                ? manPayload.manifest.type
                : '';
            const typeLabel = manifestType || 'Общие';

            const url = base + '/api/v1/manifests/' + encodeURIComponent(code) + '/openapi';
            const res = await fetch(url, { headers });
            const payload = await res.json();
            if (!res.ok || !payload.ok || !payload.openapi) {
                throw new Error((payload && payload.error) || ('HTTP ' + res.status));
            }
            const endpoints = endpointsFromOpenAPI(code, payload.openapi);
            renderEndpoints(code, endpoints, typeLabel);
            statusEl.textContent = 'Загружено: ' + endpoints.length + ' метод(ов), тип: ' + typeLabel + '.';
            sessionStorage.setItem('mf-live-token', token);
        } catch (err) {
            statusEl.textContent = 'Ошибка: ' + (err && err.message ? err.message : String(err));
        }
    });

    const tokenInput = document.getElementById('mf-live-token');
    const savedToken = sessionStorage.getItem('mf-live-token');
    if (savedToken && tokenInput) {
        tokenInput.value = savedToken;
    } else if (tokenInput) {
        try {
            const sessionToken = localStorage.getItem('maniforge_admin_access_token');
            if (sessionToken) {
                tokenInput.value = sessionToken;
                tokenInput.placeholder = 'из сессии RBAC (maniforge-session)';
            }
        } catch (e) {
            /* ignore */
        }
    }
})();
