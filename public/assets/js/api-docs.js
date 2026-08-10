'use strict';

(function () {
    const root = document.getElementById('api-docs-app');
    if (!root) {
        return;
    }

    const tabs = Array.from(root.querySelectorAll('.app-api-tab'));
    const panels = Array.from(root.querySelectorAll('.app-api-tab-panel'));
    const tabsExpandBtn = document.getElementById('api-tabs-expand');
    const tabGroups = Array.from(root.querySelectorAll('.app-api-tabs-group'));
    const pageHero = document.querySelector('.product-hero');
    const COMPACT_ON_PX = 48;
    const COMPACT_OFF_PX = 96;
    const LS_TAB = 'api-docs-tab';
    const LS_EXPANDED = 'api-docs-tabs-expanded';

    function panelBySectionId(sectionId) {
        return panels.find((panel) => panel.dataset.panel === sectionId) || null;
    }

    function activePanel() {
        return root.querySelector('.app-api-tab-panel.is-active');
    }

    function contentScroller(panel) {
        const p = panel || activePanel();
        return p ? p.querySelector('.app-api-content') : null;
    }

    function sidebarScroller(panel) {
        const p = panel || activePanel();
        return p ? p.querySelector('.app-api-sidebar') : null;
    }

    function isCompact() {
        return root.classList.contains('is-compact');
    }

    function navHeight() {
        const nav = document.querySelector('.mf-site-nav');
        return nav ? nav.offsetHeight : 0;
    }

    function categoryIdForSection(sectionId) {
        const tab = tabs.find((item) => item.dataset.panel === sectionId);
        return tab ? (tab.dataset.category || '') : '';
    }

    function syncActiveCategory(sectionId) {
        const categoryId = categoryIdForSection(sectionId);
        tabGroups.forEach((group) => {
            group.classList.toggle('is-current', group.dataset.categoryId === categoryId);
        });
        if (categoryId) {
            root.dataset.currentCategory = categoryId;
        }
    }

    function setTabsExpanded(expanded) {
        root.classList.toggle('is-expanded', expanded);
        if (tabsExpandBtn) {
            tabsExpandBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            tabsExpandBtn.textContent = expanded ? 'Свернуть' : 'Все разделы';
        }
        try {
            localStorage.setItem(LS_EXPANDED, expanded ? '1' : '0');
        } catch (e) {
            /* ignore */
        }
        syncLayoutMetrics();
    }

    function setCompact(compact) {
        const wasCompact = isCompact();
        root.classList.toggle('is-compact', compact);
        document.documentElement.classList.toggle('api-docs-compact', compact);
        document.body.classList.toggle('api-docs-compact', compact);
        if (!compact) {
            setTabsExpanded(false);
            syncLayoutMetrics();
            if (wasCompact && pageHero) {
                const y = window.scrollY + pageHero.getBoundingClientRect().top - navHeight();
                window.scrollTo({ top: Math.max(0, y), behavior: 'instant' });
            }
            return;
        }
        window.scrollTo(0, 0);
        syncLayoutMetrics();
    }

    function syncLayoutMetrics() {
        const navH = navHeight();
        root.style.setProperty('--mf-nav-h', navH + 'px');
        root.style.setProperty('--api-sticky-top', isCompact() ? '0px' : navH + 'px');
    }

    function heroPastThreshold() {
        if (!pageHero) {
            return true;
        }
        if (pageHero.classList.contains('is-collapsed')) {
            return true;
        }
        return pageHero.getBoundingClientRect().bottom <= navHeight() + COMPACT_ON_PX;
    }

    function heroBeforeThreshold() {
        if (!pageHero) {
            return false;
        }
        if (pageHero.classList.contains('is-collapsed')) {
            return false;
        }
        return pageHero.getBoundingClientRect().bottom > navHeight() + COMPACT_OFF_PX;
    }

    function updateCompactFromScroll() {
        if (!pageHero) {
            setCompact(true);
            return;
        }
        if (!isCompact() && heroPastThreshold()) {
            setCompact(true);
            return;
        }
        if (isCompact() && heroBeforeThreshold()) {
            setCompact(false);
        }
    }

    function initCompactMode() {
        updateCompactFromScroll();
        window.addEventListener('scroll', updateCompactFromScroll, { passive: true });
        window.addEventListener('resize', () => {
            syncLayoutMetrics();
            updateCompactFromScroll();
        });
        window.addEventListener('api-docs:layout-ready', () => {
            syncLayoutMetrics();
            updateCompactFromScroll();
        });
        window.addEventListener('api-docs:hero-expand', () => {
            setCompact(false);
        });
        window.addEventListener('api-docs:hero-collapse', () => {
            setCompact(true);
            window.scrollTo(0, 0);
        });
        document.addEventListener('wheel', (event) => {
            if (!isCompact() || event.deltaY >= 0) {
                return;
            }
            const scroller = contentScroller();
            if (scroller && scroller.scrollTop <= 0) {
                setCompact(false);
            }
        }, { passive: true });
        syncLayoutMetrics();
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

    function ensureMobileContentColumn(link) {
        const layout = link ? link.closest('.app-api-layout') : null;
        if (!layout) {
            return;
        }
        const bar = layout.previousElementSibling;
        if (!bar || !bar.classList.contains('app-api-mobile-tabs')) {
            return;
        }
        layout.classList.remove('is-mobile-sidebar');
        layout.classList.add('is-mobile-content');
        bar.querySelectorAll('.app-api-mobile-tab').forEach((tab) => {
            const isContent = tab.getAttribute('data-api-mobile-col') === 'content';
            tab.classList.toggle('is-active', isContent);
            tab.setAttribute('aria-selected', isContent ? 'true' : 'false');
        });
    }

    function navigateToHash(hash, behavior) {
        const sectionId = resolvePanelForHash(hash);
        if (!sectionId) {
            return false;
        }

        const samePanel = activePanel() && activePanel().dataset.panel === sectionId;
        if (!samePanel) {
            activateTab(sectionId, { updateHash: false, resetScroll: true });
        }

        if (hash && hash !== '#') {
            history.replaceState(null, '', hash);
        }

        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => {
                scrollToHashTarget(hash, behavior || 'smooth');
            });
        });
        return true;
    }

    function activateTab(sectionId, options) {
        const opts = options || {};
        const panel = panelBySectionId(sectionId);
        if (!panel) {
            return false;
        }

        tabs.forEach((tab) => {
            const active = tab.dataset.panel === sectionId;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        panels.forEach((item) => {
            const active = item === panel;
            item.classList.toggle('is-active', active);
            if (active) {
                item.removeAttribute('hidden');
            } else {
                item.setAttribute('hidden', '');
            }
        });

        if (opts.updateHash !== false) {
            const nextHash = '#' + sectionId;
            if (window.location.hash !== nextHash) {
                history.replaceState(null, '', nextHash);
            }
        }

        if (opts.resetScroll !== false && isCompact()) {
            const content = contentScroller(panel);
            const sidebar = sidebarScroller(panel);
            if (content) {
                content.scrollTop = 0;
            }
            if (sidebar) {
                sidebar.scrollTop = 0;
            }
        }

        syncActiveCategory(sectionId);
        if (isCompact()) {
            setTabsExpanded(false);
        }

        window.requestAnimationFrame(syncLayoutMetrics);
        try {
            localStorage.setItem(LS_TAB, sectionId);
        } catch (e) {
            /* ignore */
        }
        root.dispatchEvent(new CustomEvent('api-docs:tab', { detail: { sectionId } }));
        return true;
    }

    function scrollToHashTarget(hash, behavior) {
        const id = (hash || '').replace(/^#/, '');
        if (!id) {
            return;
        }
        const target = document.getElementById(id);
        if (!target) {
            return;
        }

        const panel = target.closest('.app-api-tab-panel');
        const scroller = contentScroller(panel);
        if (isCompact() && scrollElementToTarget(scroller, target, behavior)) {
            return;
        }

        target.scrollIntoView({ behavior: behavior || 'smooth', block: 'start' });
    }

    function resolvePanelForHash(hash) {
        const id = (hash || '').replace(/^#/, '');
        if (!id) {
            return null;
        }

        const direct = panelBySectionId(id);
        if (direct) {
            return id;
        }

        if (id === 'api-credentials-overview' || id.startsWith('api-credentials-') || id.startsWith('credentials-')) {
            return 'api-credentials-docs';
        }

        if (id === 'api-headers-kit' || id === 'api-headers-overview' || id.startsWith('api-headers-') || id.startsWith('headers-')) {
            return 'api-headers-docs';
        }

        if (/^api-headers-(rbac|platform|modules)-/.test(id)) {
            return 'api-headers-docs';
        }

        const target = document.getElementById(id);
        if (!target) {
            return null;
        }

        const panel = target.closest('.app-api-tab-panel');
        return panel ? panel.dataset.panel : null;
    }

    function defaultPanelId() {
        const active = tabs.find((tab) => tab.classList.contains('is-active'));
        return active ? active.dataset.panel : (tabs[0] ? tabs[0].dataset.panel : null);
    }

    function applyHash(hash, behavior) {
        if (navigateToHash(hash, behavior)) {
            return;
        }

        const fallback = defaultPanelId();
        if (fallback) {
            activateTab(fallback, { updateHash: false, resetScroll: false });
        }
    }

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const sectionId = tab.dataset.panel;
            if (!sectionId) {
                return;
            }
            activateTab(sectionId);
        });
    });

    root.addEventListener('click', (event) => {
        const link = event.target.closest('[data-api-tab-link], .app-api-nav a[href^="#"], .app-api-module-body a[href^="#"]');
        if (!link || !root.contains(link)) {
            return;
        }

        event.preventDefault();
        ensureMobileContentColumn(link);

        const tabTarget = link.getAttribute('data-api-tab-link');
        const hash = link.getAttribute('href') || '';

        if (tabTarget) {
            const samePanel = activePanel() && activePanel().dataset.panel === tabTarget;
            if (!samePanel) {
                activateTab(tabTarget, { updateHash: false, resetScroll: true });
            }
        }

        if (hash && hash !== '#') {
            navigateToHash(hash, 'smooth');
            return;
        }

        if (tabTarget) {
            activateTab(tabTarget);
        }
    });

    window.addEventListener('hashchange', () => {
        applyHash(window.location.hash, 'smooth');
    });

    let initialHash = window.location.hash;
    if (!initialHash) {
        try {
            const savedTab = localStorage.getItem(LS_TAB);
            if (savedTab && panelBySectionId(savedTab)) {
                initialHash = '#' + savedTab;
            }
        } catch (e) {
            /* ignore */
        }
    }
    applyHash(initialHash || (tabs[0] ? '#' + tabs[0].dataset.panel : ''), 'auto');

    initCompactMode();

    if (tabsExpandBtn) {
        tabsExpandBtn.addEventListener('click', () => {
            if (!isCompact()) {
                return;
            }
            setTabsExpanded(!root.classList.contains('is-expanded'));
        });
        try {
            if (localStorage.getItem(LS_EXPANDED) === '1') {
                root.classList.add('is-expanded');
                tabsExpandBtn.setAttribute('aria-expanded', 'true');
                tabsExpandBtn.textContent = 'Свернуть';
            }
        } catch (e) {
            /* ignore */
        }
    }

    const initialSection = defaultPanelId();
    if (initialSection) {
        syncActiveCategory(initialSection);
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.left = '-9999px';
        document.body.appendChild(area);
        area.select();
        try {
            document.execCommand('copy');
            return Promise.resolve();
        } finally {
            document.body.removeChild(area);
        }
    }

    function showCopied(button) {
        button.classList.add('is-copied');
        const label = button.querySelector('.app-api-copy-label');
        const original = label ? label.textContent : '';
        const originalTitle = button.getAttribute('title') || '';
        if (label) {
            label.textContent = 'Скопировано';
        }
        button.setAttribute('title', 'Скопировано');
        window.setTimeout(() => {
            button.classList.remove('is-copied');
            if (label) {
                label.textContent = original;
            }
            if (originalTitle) {
                button.setAttribute('title', originalTitle);
            } else {
                button.removeAttribute('title');
            }
        }, 1400);
    }

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-api-copy]');
        if (!button) {
            return;
        }
        event.preventDefault();
        const text = button.getAttribute('data-api-copy') || '';
        if (!text) {
            return;
        }
        try {
            await copyText(text);
            showCopied(button);
        } catch (e) {
            /* ignore */
        }
    });

    const scrollTopBtn = document.getElementById('api-scroll-top');
    if (scrollTopBtn) {
        const showAfter = 320;

        function primaryScroller() {
            if (!isCompact()) {
                return null;
            }
            const scroller = contentScroller();
            if (scroller && scroller.scrollHeight > scroller.clientHeight) {
                return scroller;
            }
            return null;
        }

        function scrollTopPosition() {
            const scroller = primaryScroller();
            return scroller ? scroller.scrollTop : window.scrollY;
        }

        function updateScrollTop() {
            const visible = scrollTopPosition() > showAfter;
            scrollTopBtn.hidden = false;
            scrollTopBtn.classList.toggle('is-visible', visible);
        }

        scrollTopBtn.addEventListener('click', () => {
            const scroller = primaryScroller();
            if (scroller) {
                scroller.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        window.addEventListener('scroll', updateScrollTop, { passive: true });
        panels.forEach((panel) => {
            const content = panel.querySelector('.app-api-content');
            if (content) {
                content.addEventListener('scroll', updateScrollTop, { passive: true });
            }
        });
        updateScrollTop();
    }
})();
