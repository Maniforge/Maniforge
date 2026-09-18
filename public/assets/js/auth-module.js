'use strict';

(function (global) {
    const DRAFT_KEY = 'maniforge_auth_form_draft';
    let privacyNoticeCache = null;
    const STORAGE = {
        access: 'maniforge_admin_access_token',
        refresh: 'maniforge_admin_refresh_token',
        csrf: 'maniforge_admin_csrf_token',
    };

    function readDraft() {
        try {
            return JSON.parse(sessionStorage.getItem(DRAFT_KEY) || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    function writeDraft(data) {
        sessionStorage.setItem(DRAFT_KEY, JSON.stringify(data));
    }

    function saveDraftFromForm() {
        const phoneField = global.ManiforgePhoneField && global.ManiforgePhoneField.bind('authPhonePrefix', 'authPhoneNumber');
        writeDraft({
            phone: phoneField ? phoneField.getFullPhone() : '',
            password: document.getElementById('authPassword')?.value || '',
        });
    }

    function restoreDraftToForm() {
        const draft = readDraft();
        const phoneField = global.ManiforgePhoneField && global.ManiforgePhoneField.bind('authPhonePrefix', 'authPhoneNumber');
        if (phoneField && draft.phone) {
            phoneField.setFromStored(draft.phone);
        }
        const passwordEl = document.getElementById('authPassword');
        if (passwordEl && draft.password) {
            passwordEl.value = draft.password;
        }
    }

    function setNavMode(mode) {
        document.querySelectorAll('.maniforge-nav-auth-tab').forEach((tab) => {
            const isLogin = tab.getAttribute('href') === '/login';
            const isRegister = tab.getAttribute('href') === '/register';
            const active = (mode === 'login' && isLogin) || (mode === 'register' && isRegister);
            tab.classList.toggle('is-active', active);
            if (active) {
                tab.setAttribute('aria-current', 'page');
            } else {
                tab.removeAttribute('aria-current');
            }
        });
    }

    async function loadPrivacyNotice(module) {
        const block = document.getElementById('authPrivacyBlock');
        const purposesEl = document.getElementById('authPrivacyPurposes');
        const operatorEl = document.getElementById('authPrivacyOperator');
        const policyLink = document.getElementById('authPrivacyPolicyLink');
        if (!block || !purposesEl) {
            return;
        }

        block.hidden = true;
        purposesEl.innerHTML = '';

        try {
            const noticeHeaders = { Accept: 'application/json' };
            const defaultTenant = module.dataset.defaultTenant || 'default';
            const defaultSubtenant = module.dataset.defaultSubtenant || 'main';
            noticeHeaders['X-Tenant-ID'] = defaultTenant;
            noticeHeaders['X-Subtenant-ID'] = defaultSubtenant;
            const response = await fetch('/rbac/api/v1/privacy/notice', {
                headers: noticeHeaders,
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !payload.ok || !payload.notice) {
                showPrivacyFallback(block, policyLink, operatorEl);
                return;
            }
            privacyNoticeCache = payload.notice;
            const notice = payload.notice;
            const operator = notice.operator || {};
            const processor = notice.processor || {};
            if (operatorEl) {
                let line = 'Оператор: ' + (operator.name || '—')
                    + (notice.data_storage_region ? ' · хранение: ' + notice.data_storage_region : '');
                if (processor.name) {
                    line += ' · обработчик: ' + processor.name;
                }
                operatorEl.textContent = line;
            }
            const processorHint = document.getElementById('authProcessorHint');
            const dpaLabel = document.getElementById('authPlatformDpaLabel');
            if (processor.name && processorHint) {
                const dpaUrl = processor.dpa_url ? ' <a href="' + processor.dpa_url + '" target="_blank" rel="noopener">DPA</a>' : '';
                processorHint.innerHTML = ' · обработчик: ' + processor.name + dpaUrl;
            } else if (processorHint) {
                processorHint.textContent = '';
            }
            if (processor.name && dpaLabel) {
                dpaLabel.textContent = 'Принимаю поручение обработки ПДн с «' + processor.name + '» (обработчик) для работы сервиса.';
            }
            if (policyLink) {
                if (notice.privacy_policy_url) {
                    policyLink.href = notice.privacy_policy_url;
                }
                policyLink.hidden = false;
            }

            const purposes = notice.processing_purposes || [];
            purposes.forEach((purpose) => {
                const code = purpose.code || '';
                const mandatory = !!purpose.mandatory_for_registration;
                const version = purpose.policy_version || '1.0';
                const id = 'authConsent_' + code;
                const wrap = document.createElement('div');
                wrap.className = 'form-check mb-1';
                wrap.innerHTML =
                    '<input class="form-check-input auth-consent-checkbox" type="checkbox" id="' + id + '"'
                    + ' data-purpose-code="' + code + '" data-policy-version="' + version + '"'
                    + (mandatory ? ' data-mandatory="1" required' : '') + '>'
                    + '<label class="form-check-label small" for="' + id + '">'
                    + (purpose.title || code)
                    + (mandatory ? ' <span class="text-danger">*</span>' : '')
                    + '</label>';
                purposesEl.appendChild(wrap);
            });

            block.hidden = false;
        } catch (e) {
            showPrivacyFallback(block, policyLink, operatorEl);
        }
    }

    function showPrivacyFallback(block, policyLink, operatorEl) {
        if (!block) {
            return;
        }
        if (operatorEl) {
            operatorEl.textContent = 'После регистрации оператором выступает ваша организация; платформа Maniforge — обработчик по DPA.';
        }
        if (policyLink) {
            policyLink.href = '/docs/152FZ_COMPLIANCE.md';
            policyLink.hidden = false;
        }
        block.hidden = false;
    }

    function collectConsents() {
        const items = [];
        document.querySelectorAll('.auth-consent-checkbox:checked').forEach((el) => {
            items.push({
                purpose_code: el.getAttribute('data-purpose-code') || '',
                policy_version: el.getAttribute('data-policy-version') || '1.0',
            });
        });
        return items;
    }

    function validateMandatoryConsents(messageEl) {
        const missing = [];
        document.querySelectorAll('.auth-consent-checkbox[data-mandatory="1"]').forEach((el) => {
            if (!el.checked) {
                missing.push(el.getAttribute('data-purpose-code') || 'purpose');
            }
        });
        if (missing.length === 0) {
            return true;
        }
        messageEl.textContent = 'Отметьте обязательные согласия: ' + missing.join(', ');
        messageEl.className = 'auth-module-message text-danger';
        return false;
    }

    function setMode(module, mode, options) {
        const opts = options || {};
        const isRegister = mode === 'register';
        module.dataset.mode = mode;
        module.classList.toggle('is-register', isRegister);
        module.classList.toggle('is-login', !isRegister);
        const extra = module.querySelector('.auth-module-extra');
        if (extra) {
            extra.setAttribute('aria-hidden', isRegister ? 'false' : 'true');
        }
        const btnLogin = document.getElementById('authBtnLogin');
        const btnRegister = document.getElementById('authBtnRegister');
        if (btnLogin) {
            btnLogin.classList.toggle('is-active', !isRegister);
            btnLogin.classList.toggle('is-grow', !isRegister);
            btnLogin.type = isRegister ? 'button' : 'submit';
        }
        if (btnRegister) {
            btnRegister.classList.toggle('is-active', isRegister);
            btnRegister.classList.toggle('is-grow', isRegister);
            btnRegister.type = isRegister ? 'submit' : 'button';
        }
        const passwordEl = document.getElementById('authPassword');
        if (passwordEl) {
            passwordEl.autocomplete = isRegister ? 'new-password' : 'current-password';
        }
        setNavMode(mode);
        if (isRegister) {
            loadPrivacyNotice(module);
        } else {
            const block = document.getElementById('authPrivacyBlock');
            if (block) {
                block.hidden = true;
            }
        }
        if (!opts.skipHistory && window.history && window.history.replaceState) {
            const invite = module.dataset.invite || '';
            const path = isRegister ? '/register' : '/login';
            const url = invite && isRegister ? path + '?invite=' + encodeURIComponent(invite) : path;
            window.history.replaceState({ authMode: mode }, '', url);
        }
    }

    async function submitLogin(module, messageEl) {
        const phoneField = global.ManiforgePhoneField.bind('authPhonePrefix', 'authPhoneNumber');
        const phone = phoneField.getFullPhone();
        const password = document.getElementById('authPassword')?.value || '';
        if (!phone) {
            messageEl.textContent = 'Укажите телефон';
            messageEl.className = 'auth-module-message text-danger';
            return;
        }
        messageEl.textContent = 'Вход…';
        messageEl.className = 'auth-module-message text-muted';
        const response = await fetch('/rbac/api/v1/auth/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ phone, password }),
        });
        const payload = await response.json().catch(() => ({ ok: false }));
        if (!response.ok || !payload.ok) {
            messageEl.textContent = payload.error || ('HTTP ' + response.status);
            messageEl.className = 'auth-module-message text-danger';
            return;
        }
        const session = payload.session || payload.credentials?.session || {};
        localStorage.setItem(STORAGE.access, session.access_token || '');
        localStorage.setItem(STORAGE.refresh, session.refresh_token || '');
        localStorage.setItem(STORAGE.csrf, payload.csrf_token || '');
        sessionStorage.removeItem(DRAFT_KEY);
        messageEl.textContent = 'Вход выполнен. Перенаправление…';
        messageEl.className = 'auth-module-message text-success';
        window.location.href = '/admin';
    }

    async function submitRegister(module, messageEl) {
        const minLen = Number(module.dataset.minPassword || 12);
        const phoneField = global.ManiforgePhoneField.bind('authPhonePrefix', 'authPhoneNumber');
        const phone = phoneField.getFullPhone();
        const password = document.getElementById('authPassword')?.value || '';
        const inviteToken = module.dataset.invite || '';

        if (!phone || !password) {
            messageEl.textContent = 'Телефон и пароль обязательны';
            messageEl.className = 'auth-module-message text-danger';
            return;
        }
        if (password.length < minLen) {
            messageEl.textContent = 'Пароль должен быть не короче ' + minLen + ' символов';
            messageEl.className = 'auth-module-message text-danger';
            return;
        }
        if (!validateMandatoryConsents(messageEl)) {
            return;
        }
        const platformDpaEl = document.getElementById('authPlatformDpa');
        if (platformDpaEl && !inviteToken && !platformDpaEl.checked) {
            messageEl.textContent = 'Подтвердите поручение обработки ПДн с платформой (DPA)';
            messageEl.className = 'auth-module-message text-danger';
            return;
        }

        const body = { phone, password };
        const consents = collectConsents();
        if (consents.length > 0) {
            body.consents = consents;
        }
        if (inviteToken) {
            body.invite_token = inviteToken;
        } else {
            body.platform_dpa_accepted = platformDpaEl ? platformDpaEl.checked : false;
        }

        messageEl.textContent = 'Регистрация…';
        messageEl.className = 'auth-module-message text-muted';
        const response = await fetch('/rbac/api/v1/auth/register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        const payload = await response.json().catch(() => ({ ok: false }));
        if (!response.ok || !payload.ok) {
            messageEl.textContent = payload.error || ('HTTP ' + response.status);
            messageEl.className = 'auth-module-message text-danger';
            return;
        }
        sessionStorage.removeItem(DRAFT_KEY);
        messageEl.textContent = 'Аккаунт создан. Переключение на вход…';
        messageEl.className = 'auth-module-message text-success';
        setTimeout(() => {
            setMode(module, 'login');
            messageEl.textContent = 'Регистрация успешна. Нажмите «Войти».';
            messageEl.className = 'auth-module-message text-success';
        }, 800);
    }

    function bindNavTabs(module) {
        document.querySelectorAll('.maniforge-nav-auth-tab').forEach((tab) => {
            tab.addEventListener('click', (event) => {
                if (!module) {
                    return;
                }
                const href = tab.getAttribute('href') || '';
                if (href === '/login' || href === '/register') {
                    event.preventDefault();
                    saveDraftFromForm();
                    setMode(module, href === '/register' ? 'register' : 'login');
                }
            });
        });
    }

    function init() {
        const module = document.getElementById('authModule');
        if (!module) {
            return;
        }

        restoreDraftToForm();
        const params = new URLSearchParams(window.location.search);
        if (params.get('registered')) {
            const messageEl = document.getElementById('authMessage');
            if (messageEl) {
                messageEl.textContent = 'Регистрация успешна. Войдите с новым паролем.';
                messageEl.className = 'auth-module-message text-success';
            }
        }

        setMode(module, module.dataset.mode === 'register' ? 'register' : 'login', { skipHistory: true });

        const form = document.getElementById('authForm');
        const messageEl = document.getElementById('authMessage');
        const btnLogin = document.getElementById('authBtnLogin');
        const btnRegister = document.getElementById('authBtnRegister');

        form?.addEventListener('submit', async (event) => {
            event.preventDefault();
            saveDraftFromForm();
            if (module.dataset.mode === 'register') {
                await submitRegister(module, messageEl);
            } else {
                await submitLogin(module, messageEl);
            }
        });

        btnLogin?.addEventListener('click', () => {
            if (module.dataset.mode === 'register') {
                saveDraftFromForm();
                setMode(module, 'login');
                if (messageEl) {
                    messageEl.textContent = '';
                }
            }
        });

        btnRegister?.addEventListener('click', () => {
            if (module.dataset.mode === 'login') {
                saveDraftFromForm();
                setMode(module, 'register');
                if (messageEl) {
                    messageEl.textContent = '';
                }
            }
        });

        ['authPassword', 'authEmail', 'authOrganizationName'].forEach((id) => {
            document.getElementById(id)?.addEventListener('input', saveDraftFromForm);
        });
        document.getElementById('authPhoneNumber')?.addEventListener('input', saveDraftFromForm);
        document.getElementById('authPhonePrefix')?.addEventListener('change', saveDraftFromForm);

        bindNavTabs(module);
    }

    document.addEventListener('DOMContentLoaded', init);
})(window);
