(function () {
    var payloadEl = document.getElementById('docMarkdownPayload');
    var contentEl = document.getElementById('docContent');
    var tocEl = document.getElementById('docToc');
    var backBtn = document.getElementById('docBack');
    if (!payloadEl || !contentEl || !tocEl) {
        return;
    }

    var payload;
    try {
        payload = JSON.parse(payloadEl.textContent || '{}');
    } catch (e) {
        contentEl.innerHTML = '<p class="text-danger">Не удалось разобрать документ.</p>';
        return;
    }

    if (typeof marked !== 'undefined') {
        marked.setOptions({
            gfm: true,
            breaks: false,
            headerIds: true,
            mangle: false,
        });
    }

    var html = typeof marked !== 'undefined'
        ? marked.parse(String(payload.markdown || ''))
        : '<pre>' + escapeHtml(String(payload.markdown || '')) + '</pre>';

    contentEl.innerHTML = html;
    wrapTables(contentEl);
    contentEl.removeAttribute('aria-busy');
    buildToc(contentEl, tocEl);
    bindTocScroll(tocEl);

    if (backBtn) {
        backBtn.addEventListener('click', function () {
            if (window.history.length > 1) {
                window.history.back();
                return;
            }
            var ref = document.referrer;
            window.location.href = ref && ref !== window.location.href ? ref : '/';
        });
    }

    function wrapTables(root) {
        root.querySelectorAll('table').forEach(function (table) {
            if (table.parentElement && table.parentElement.classList.contains('table-scroll')) {
                return;
            }
            var wrap = document.createElement('div');
            wrap.className = 'table-scroll';
            table.parentNode.insertBefore(wrap, table);
            wrap.appendChild(table);
        });
    }

    function buildToc(root, nav) {
        var headings = root.querySelectorAll('h2, h3');
        if (!headings.length) {
            nav.innerHTML = '<p class="app-muted small mb-0">Нет разделов</p>';
            return;
        }
        var frag = document.createDocumentFragment();
        headings.forEach(function (heading) {
            if (!heading.id) {
                heading.id = slugify(heading.textContent || '');
            }
            var link = document.createElement('a');
            link.href = '#' + heading.id;
            link.textContent = heading.textContent || '';
            link.className = heading.tagName === 'H3' ? 'toc-h3' : '';
            frag.appendChild(link);
        });
        nav.innerHTML = '';
        nav.appendChild(frag);
    }

    function bindTocScroll(nav) {
        var links = nav.querySelectorAll('a[href^="#"]');
        if (!links.length || !('IntersectionObserver' in window)) {
            return;
        }
        var byId = {};
        links.forEach(function (link) {
            var id = link.getAttribute('href').slice(1);
            byId[id] = link;
        });
        var observer = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) {
                        return;
                    }
                    links.forEach(function (l) {
                        l.classList.remove('is-active');
                    });
                    var active = byId[entry.target.id];
                    if (active) {
                        active.classList.add('is-active');
                    }
                });
            },
            { rootMargin: '-20% 0px -70% 0px', threshold: 0 }
        );
        Object.keys(byId).forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                observer.observe(el);
            }
        });
    }

    function slugify(text) {
        return String(text)
            .trim()
            .toLowerCase()
            .replace(/[^\w\u0400-\u04FF]+/gu, '-')
            .replace(/^-+|-+$/g, '') || 'section';
    }

    function escapeHtml(value) {
        return value
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }
})();
