'use strict';

(function () {
    const root = document.getElementById('api-docs-app');
    if (!root) {
        return;
    }

    const LS_HERO = 'api-docs-hero-collapsed';
    const LS_VISITED = 'api-docs-visited';
    const hero = document.getElementById('api-page-hero');
    const heroBtn = document.getElementById('api-hero-collapse');
    const breadcrumbs = document.getElementById('api-breadcrumbs');
    const searchRoot = document.getElementById('api-docs-search');
    const searchInput = document.getElementById('api-docs-search-input');
    const searchResults = document.getElementById('api-docs-search-results');
    const searchTrigger = document.getElementById('api-search-trigger');

    function isCompact() {
        return root.classList.contains('is-compact');
    }

    function activePanel() {
        return root.querySelector('.app-api-tab-panel.is-active');
    }

    function contentScroller(panel) {
        const p = panel || activePanel();
        return p ? p.querySelector('.app-api-content') : null;
    }

    function scrollElementToTarget(scroller, target, behavior) {
        if (!scroller || !target || !scroller.contains(target)) {
            return false;
        }
        const y = target.getBoundingClientRect().top
            - scroller.getBoundingClientRect().top
            + scroller.scrollTop
            - 12;
        scroller.scrollTo({ top: Math.max(0, y), behavior: behavior || 'smooth' });
        return true;
    }

    /* Hero collapse */
    function setHeroCollapsed(collapsed) {
        if (!hero) {
            return;
        }
        hero.classList.toggle('is-collapsed', collapsed);
        if (heroBtn) {
            heroBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            heroBtn.textContent = collapsed ? 'Развернуть' : 'Свернуть';
        }
        try {
            localStorage.setItem(LS_HERO, collapsed ? '1' : '0');
        } catch (e) {
            /* ignore */
        }
        if (collapsed) {
            window.dispatchEvent(new CustomEvent('api-docs:hero-collapse'));
        } else {
            window.dispatchEvent(new CustomEvent('api-docs:hero-expand'));
        }
        window.dispatchEvent(new Event('resize'));
    }

    if (heroBtn) {
        heroBtn.addEventListener('click', () => {
            setHeroCollapsed(!hero.classList.contains('is-collapsed'));
        });
    }

    function applyHeroPreference() {
        try {
            const explicit = localStorage.getItem(LS_HERO);
            if (explicit === '1') {
                setHeroCollapsed(true);
                return;
            }
            if (explicit === '0') {
                setHeroCollapsed(false);
                return;
            }
            if (localStorage.getItem(LS_VISITED) === '1') {
                setHeroCollapsed(true);
            }
        } catch (e) {
            /* ignore */
        }
    }

    applyHeroPreference();
    window.dispatchEvent(new CustomEvent('api-docs:layout-ready'));

    window.addEventListener('beforeunload', () => {
        try {
            localStorage.setItem(LS_VISITED, '1');
        } catch (e) {
            /* ignore */
        }
    });

    /* Breadcrumbs (compact) */
    function tabLabelForSection(sectionId) {
        const tab = root.querySelector('.app-api-tab[data-panel="' + sectionId + '"]');
        return tab ? tab.textContent.trim() : sectionId;
    }

    function updateBreadcrumbs(anchorId) {
        if (!breadcrumbs) {
            return;
        }
        const panel = activePanel();
        if (!panel || !isCompact()) {
            breadcrumbs.hidden = true;
            breadcrumbs.innerHTML = '';
            return;
        }

        const sectionId = panel.dataset.panel || '';
        const crumbs = [
            { label: tabLabelForSection(sectionId), href: '#' + sectionId },
        ];

        if (anchorId && anchorId !== sectionId) {
            const target = document.getElementById(anchorId);
            let label = anchorId;
            if (target) {
                const h = target.querySelector('h3, h4, summary, .app-api-method-title');
                if (h) {
                    label = h.textContent.trim();
                }
            }
            crumbs.push({ label: label, href: '#' + anchorId });
        }

        breadcrumbs.innerHTML = crumbs.map((crumb, i) => {
            const sep = i > 0 ? '<span class="app-api-breadcrumbs-sep" aria-hidden="true">/</span>' : '';
            return sep + '<a href="' + crumb.href + '">' + escapeHtml(crumb.label) + '</a>';
        }).join('');
        breadcrumbs.hidden = false;
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    root.addEventListener('api-docs:tab', () => {
        updateBreadcrumbs('');
    });

    breadcrumbs?.addEventListener('click', (event) => {
        const link = event.target.closest('a[href^="#"]');
        if (!link) {
            return;
        }
        event.preventDefault();
        const hash = link.getAttribute('href');
        if (hash) {
            history.replaceState(null, '', hash);
            window.dispatchEvent(new HashChangeEvent('hashchange'));
        }
    });

    /* Scroll spy */
    let spyObserver = null;
    let currentSpyId = '';

    function setNavActive(anchorId) {
        const panel = activePanel();
        if (!panel) {
            return;
        }
        panel.querySelectorAll('[data-api-nav-anchor]').forEach((link) => {
            const id = link.getAttribute('data-api-nav-anchor') || '';
            link.classList.toggle('is-spy-active', id === anchorId);
        });
        if (anchorId && anchorId !== currentSpyId) {
            currentSpyId = anchorId;
            updateBreadcrumbs(anchorId);
        }
    }

    function initScrollSpy() {
        if (spyObserver) {
            spyObserver.disconnect();
            spyObserver = null;
        }

        const panel = activePanel();
        const scroller = contentScroller(panel);
        if (!panel || !scroller) {
            return;
        }

        const targets = panel.querySelectorAll('[data-api-spy-section], .app-api-method, .app-api-profile-details');
        if (!targets.length) {
            return;
        }

        spyObserver = new IntersectionObserver((entries) => {
            const visible = entries
                .filter((e) => e.isIntersecting)
                .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
            if (visible.length) {
                setNavActive(visible[0].target.id);
            }
        }, {
            root: scroller,
            rootMargin: '-20% 0px -60% 0px',
            threshold: 0,
        });

        targets.forEach((el) => {
            if (el.id) {
                spyObserver.observe(el);
            }
        });
    }

    root.addEventListener('api-docs:tab', () => {
        window.requestAnimationFrame(initScrollSpy);
    });
    window.addEventListener('resize', () => {
        window.requestAnimationFrame(initScrollSpy);
    });
    window.requestAnimationFrame(initScrollSpy);

    /* Profile accordion: one open per section */
    root.addEventListener('toggle', (event) => {
        const details = event.target;
        if (!details.matches('.app-api-profile-details') || !details.open) {
            return;
        }
        const section = details.closest('.app-api-group, .app-api-headers-main');
        if (!section) {
            return;
        }
        section.querySelectorAll('.app-api-profile-details[open]').forEach((other) => {
            if (other !== details) {
                other.open = false;
            }
        });
    }, true);

    /* Manifest field → POST highlight */
    root.addEventListener('click', (event) => {
        const link = event.target.closest('[data-api-field-link]');
        if (!link) {
            return;
        }
        const fieldName = link.getAttribute('data-api-field-link') || '';
        if (!fieldName) {
            return;
        }
        window.setTimeout(() => highlightField(fieldName), 400);
    });

    function highlightField(fieldName) {
        root.querySelectorAll('.is-field-highlight').forEach((el) => {
            el.classList.remove('is-field-highlight');
        });
        const row = root.querySelector('[data-api-field-row="' + fieldName + '"]');
        if (row) {
            row.classList.add('is-field-highlight');
            row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            window.setTimeout(() => row.classList.remove('is-field-highlight'), 2400);
        }
    }

    /* Mobile column tabs */
    root.querySelectorAll('.app-api-mobile-tabs').forEach((bar) => {
        const layout = bar.nextElementSibling;
        if (!layout || !layout.classList.contains('app-api-layout')) {
            return;
        }
        bar.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-api-mobile-col]');
            if (!btn) {
                return;
            }
            const col = btn.getAttribute('data-api-mobile-col');
            layout.classList.toggle('is-mobile-sidebar', col === 'sidebar');
            layout.classList.toggle('is-mobile-content', col === 'content');
            bar.querySelectorAll('.app-api-mobile-tab').forEach((tab) => {
                const active = tab === btn;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });
        });
    });

    /* Search */
    let searchIndex = [];
    let searchActiveIndex = -1;

    function buildSearchIndex() {
        searchIndex = [];
        root.querySelectorAll('.app-api-tab-panel').forEach((panel) => {
            const sectionId = panel.dataset.panel || '';
            const moduleLabel = tabLabelForSection(sectionId);
            panel.querySelectorAll('.app-api-method').forEach((article) => {
                const method = article.querySelector('.app-api-method-badge');
                const path = article.querySelector('.app-api-method-path');
                const title = article.querySelector('.app-api-method-title');
                searchIndex.push({
                    sectionId,
                    moduleLabel,
                    anchor: article.id,
                    method: method ? method.textContent.trim() : '',
                    path: path ? path.textContent.trim() : '',
                    title: title ? title.textContent.trim() : '',
                });
            });
            panel.querySelectorAll('.app-api-nav a[data-api-nav-anchor]').forEach((link) => {
                searchIndex.push({
                    sectionId,
                    moduleLabel,
                    anchor: link.getAttribute('data-api-nav-anchor') || '',
                    method: '',
                    path: '',
                    title: link.textContent.trim(),
                });
            });
        });
    }

    function searchItemButtons() {
        return searchResults
            ? Array.from(searchResults.querySelectorAll('.app-api-search-item'))
            : [];
    }

    function setSearchActiveIndex(index) {
        const buttons = searchItemButtons();
        if (!buttons.length) {
            searchActiveIndex = -1;
            if (searchInput) {
                searchInput.removeAttribute('aria-activedescendant');
            }
            return;
        }
        if (index < 0) {
            searchActiveIndex = -1;
            buttons.forEach((btn) => {
                btn.classList.remove('is-active');
                btn.setAttribute('aria-selected', 'false');
            });
            if (searchInput) {
                searchInput.removeAttribute('aria-activedescendant');
            }
            return;
        }
        const next = Math.min(index, buttons.length - 1);
        searchActiveIndex = next;
        buttons.forEach((btn, i) => {
            const active = i === next;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
            if (active) {
                btn.scrollIntoView({ block: 'nearest' });
            }
        });
        if (searchInput) {
            searchInput.setAttribute('aria-activedescendant', buttons[next].id || '');
        }
    }

    function openSearch() {
        if (!searchRoot || !searchInput) {
            return;
        }
        buildSearchIndex();
        searchRoot.hidden = false;
        searchInput.value = '';
        searchActiveIndex = -1;
        renderSearchResults('');
        searchInput.focus();
        document.body.classList.add('api-docs-search-open');
        if (searchTrigger) {
            searchTrigger.setAttribute('aria-expanded', 'true');
        }
    }

    function closeSearch() {
        if (!searchRoot) {
            return;
        }
        searchRoot.hidden = true;
        searchActiveIndex = -1;
        document.body.classList.remove('api-docs-search-open');
        if (searchTrigger) {
            searchTrigger.setAttribute('aria-expanded', 'false');
            searchTrigger.focus();
        }
    }

    function renderSearchResults(query) {
        if (!searchResults) {
            return;
        }
        const q = query.trim().toLowerCase();
        const items = q === ''
            ? searchIndex.slice(0, 12)
            : searchIndex.filter((item) => {
                const hay = (item.method + ' ' + item.path + ' ' + item.title + ' ' + item.moduleLabel).toLowerCase();
                return hay.includes(q);
            }).slice(0, 20);

        if (!items.length) {
            searchResults.innerHTML = '<li class="app-api-search-empty">Ничего не найдено</li>';
            return;
        }

        searchResults.innerHTML = items.map((item, i) => {
            const sub = item.path
                ? '<span class="app-api-search-item-path"><code>' + escapeHtml(item.path) + '</code></span>'
                : '';
            const method = item.method
                ? '<span class="app-api-method-badge app-api-method-' + item.method.toLowerCase() + '">' + escapeHtml(item.method) + '</span> '
                : '';
            const optionId = 'api-search-option-' + i;
            return '<li role="presentation"><button type="button" class="app-api-search-item" id="' + optionId + '" data-search-index="' + i + '" role="option" aria-selected="false">'
                + method + '<span class="app-api-search-item-title">' + escapeHtml(item.title || item.path) + '</span>'
                + sub
                + '<span class="app-api-search-item-module">' + escapeHtml(item.moduleLabel) + '</span>'
                + '</button></li>';
        }).join('');

        searchResults._items = items;
        setSearchActiveIndex(items.length ? 0 : -1);
    }

    function navigateSearchItem(item) {
        if (!item) {
            return;
        }
        closeSearch();
        const hash = item.anchor ? '#' + item.anchor : '#' + item.sectionId;
        history.replaceState(null, '', hash);
        window.dispatchEvent(new HashChangeEvent('hashchange'));
    }

    searchTrigger?.addEventListener('click', openSearch);
    searchRoot?.querySelector('[data-api-search-close]')?.addEventListener('click', closeSearch);

    searchInput?.addEventListener('input', () => {
        renderSearchResults(searchInput.value);
    });

    searchInput?.addEventListener('keydown', (event) => {
        if (!searchRoot || searchRoot.hidden) {
            return;
        }
        const buttons = searchItemButtons();
        if (!buttons.length) {
            return;
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setSearchActiveIndex(searchActiveIndex + 1);
            return;
        }
        if (event.key === 'ArrowUp') {
            event.preventDefault();
            setSearchActiveIndex(searchActiveIndex <= 0 ? buttons.length - 1 : searchActiveIndex - 1);
            return;
        }
        if (event.key === 'Enter' && searchActiveIndex >= 0 && searchResults._items) {
            event.preventDefault();
            navigateSearchItem(searchResults._items[searchActiveIndex]);
        }
    });

    searchResults?.addEventListener('click', (event) => {
        const btn = event.target.closest('[data-search-index]');
        if (!btn || !searchResults._items) {
            return;
        }
        const idx = Number(btn.getAttribute('data-search-index'));
        navigateSearchItem(searchResults._items[idx]);
    });

    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            openSearch();
            return;
        }
        if (event.key === 'Escape' && searchRoot && !searchRoot.hidden) {
            closeSearch();
        }
    });

    buildSearchIndex();
})();
