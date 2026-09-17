import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  type ApiCatalog,
  type ApiDoc,
  type ApiEndpoint,
  type ApiModule,
  deskAccessToken,
  findProfile,
  headersPrefixForModule,
  profileCopyBlock,
  profileSymbol,
} from './apiCatalog';

const HERO_KEY = 'api-docs-hero-collapsed';

function copyText(value: string) {
  if (!value) return;
  void navigator.clipboard?.writeText(value);
}

function moduleList(catalog: ApiCatalog): { category: ApiCatalog['categories'][number]; module: ApiModule }[] {
  const items: { category: ApiCatalog['categories'][number]; module: ApiModule }[] = [];
  for (const category of catalog.categories) {
    for (const module of category.modules) {
      items.push({ category, module });
    }
  }
  return items;
}

export function ApiDocsPage() {
  const [catalog, setCatalog] = useState<ApiCatalog | null>(null);
  const [error, setError] = useState('');
  const [activeKey, setActiveKey] = useState('public');
  const [heroCollapsed, setHeroCollapsed] = useState(() => {
    try {
      return localStorage.getItem(HERO_KEY) === '1';
    } catch {
      return true;
    }
  });
  const [tabsExpanded, setTabsExpanded] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [spyId, setSpyId] = useState('');

  useEffect(() => {
    fetch('/assets/api-docs-catalog.json', { headers: { Accept: 'application/json' } })
      .then((res) => {
        if (!res.ok) throw new Error('Каталог API недоступен');
        return res.json();
      })
      .then((data: ApiCatalog) => {
        setCatalog(data);
        const hash = window.location.hash.replace('#', '');
        const fromHash = data.categories
          .flatMap((c) => c.modules)
          .find((m) => m.section_id === hash || m.key === hash);
        let stored = '';
        try {
          stored = localStorage.getItem('api-docs-tab') || '';
          setTabsExpanded(localStorage.getItem('api-docs-tabs-expanded') === '1');
        } catch {
          /* ignore */
        }
        setActiveKey(fromHash?.key || stored || data.default_module_key || 'public');
      })
      .catch((err: Error) => setError(err.message));
  }, []);

  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        setSearchOpen(true);
      }
      if (event.key === 'Escape') setSearchOpen(false);
    }
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  useEffect(() => {
    const nodes = document.querySelectorAll<HTMLElement>('[data-api-spy-section]');
    if (!nodes.length) return;
    const observer = new IntersectionObserver(
      (entries) => {
        const visible = entries.filter((e) => e.isIntersecting).sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
        if (visible?.target.id) setSpyId(visible.target.id);
      },
      { rootMargin: '-20% 0px -60% 0px', threshold: [0.1, 0.25] },
    );
    nodes.forEach((n) => observer.observe(n));
    return () => observer.disconnect();
  }, [catalog, activeKey]);

  const items = useMemo(() => (catalog ? moduleList(catalog) : []), [catalog]);
  const active = items.find((item) => item.module.key === activeKey) || items[0];
  const docs = catalog && active ? catalog.docs[active.module.key] : undefined;
  const token = deskAccessToken();

  const toggleHero = useCallback(() => {
    setHeroCollapsed((prev) => {
      const next = !prev;
      try {
        localStorage.setItem(HERO_KEY, next ? '1' : '0');
      } catch {
        /* ignore */
      }
      return next;
    });
  }, []);

  function openModule(key: string, hash?: string) {
    setActiveKey(key);
    try {
      localStorage.setItem('api-docs-tab', key);
    } catch {
      /* ignore */
    }
    setSearchOpen(false);
    if (hash) {
      window.location.hash = hash;
      requestAnimationFrame(() => document.getElementById(hash)?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    }
  }

  function toggleSections() {
    setTabsExpanded((prev) => {
      const next = !prev;
      try {
        localStorage.setItem('api-docs-tabs-expanded', next ? '1' : '0');
      } catch {
        /* ignore */
      }
      return next;
    });
  }

  const hits = useMemo(() => {
    if (!catalog || query.trim().length < 2) return [];
    const q = query.toLowerCase();
    const found: { key: string; label: string; hash: string }[] = [];
    for (const { module } of items) {
      if (module.nav_label.toLowerCase().includes(q) || module.nav_purpose.toLowerCase().includes(q)) {
        found.push({ key: module.key, label: module.nav_label, hash: module.section_id });
      }
      const doc = catalog.docs[module.key];
      for (const group of doc?.groups || []) {
        if (group.title.toLowerCase().includes(q)) {
          found.push({ key: module.key, label: `${module.nav_label} · ${group.title}`, hash: group.id });
        }
        for (const ep of group.endpoints || []) {
          const blob = `${ep.method || ''} ${ep.path || ''} ${ep.title || ''}`.toLowerCase();
          if (blob.includes(q)) {
            found.push({
              key: module.key,
              label: `${ep.method || ''} ${ep.path || ep.title || ''}`.trim(),
              hash: ep.id || group.id,
            });
          }
        }
      }
    }
    return found.slice(0, 24);
  }, [catalog, items, query]);

  if (error) {
    return (
      <main className="main">
        <p className="msg">{error}</p>
      </main>
    );
  }
  if (!catalog || !active) {
    return (
      <main className="main">
        <p className="muted">Загрузка каталога API…</p>
      </main>
    );
  }

  return (
    <div className={`api-page${tabsExpanded ? ' api-docs-tabs-expanded' : ''}`}>
      <nav className="api-breadcrumbs" aria-label="Навигация">
        <a href="/">Maniforge</a>
        <span>/</span>
        <a href="/about/">О проекте</a>
        <span>/</span>
        <span>API</span>
      </nav>

      <section
        className={`api-page-hero${heroCollapsed ? ' is-collapsed api-docs-hero-collapsed' : ''}`}
        id="api-page-hero"
      >
        <div>
          <p className="kicker">Документация API</p>
          <h1>Каталог API по модулям</h1>
          <p className="lead">
            Сначала раздел, затем модуль. Токены — в «Ключи», JSON-заготовки <code>MF_HEADER_*</code> — в «Заголовки».
          </p>
          <p className="api-hero-links">
            <a href="/api/rbac/">/api/rbac/</a>
            <a href="/api/tenant-licensing/">/api/tenant-licensing/</a>
            <a href="/api/manifest/">/api/manifest/</a>
            <a href="/warehouses">/warehouses</a>
            <a href="/products">/products</a>
            <a href="/inventory">/inventory</a>
            <a href="/wms">/wms</a>
          </p>
        </div>
        <button type="button" className="btn ghost" onClick={toggleHero} aria-expanded={!heroCollapsed} aria-controls="api-page-hero">
          {heroCollapsed ? 'Развернуть' : 'Свернуть'}
        </button>
      </section>

      <div className={`api-dock${tabsExpanded ? ' is-expanded' : ''}`}>
        <div className="api-dock-tabs api-mobile-tabs" role="navigation" aria-label="Модули API">
          {catalog.categories.map((category) => (
            <div key={category.id} className={`api-tabs-group${category.id === active.category.id ? ' is-current' : ''}`}>
              <span className="api-tabs-group-label">{category.title}</span>
              <div className="api-tabs-list" role="tablist" aria-label={category.title}>
                {category.modules.map((module) => (
                  <button
                    key={module.key}
                    type="button"
                    role="tab"
                    className={`api-docs-tab${module.key === active.module.key ? ' is-active' : ''}`}
                    aria-selected={module.key === active.module.key}
                    onClick={() => openModule(module.key, module.section_id)}
                  >
                    {module.nav_label}
                  </button>
                ))}
              </div>
            </div>
          ))}
          <div className="api-tabs-toolbar">
            <button
              type="button"
              className="api-docs-search-trigger"
              title="Поиск (Ctrl+K)"
              aria-expanded={searchOpen}
              aria-controls="api-docs-search"
              onClick={() => setSearchOpen(true)}
            >
              Поиск <kbd>Ctrl+K</kbd>
            </button>
            <button type="button" aria-expanded={tabsExpanded} onClick={toggleSections}>
              Все разделы
            </button>
          </div>
        </div>
        {catalog.references?.length ? (
          <div className="api-dock-footer">
            {catalog.references.map((ref) => (
              <a key={ref.href} href={ref.href}>
                {ref.label}
              </a>
            ))}
          </div>
        ) : null}
      </div>

      {searchOpen ? (
        <div className="api-docs-search" id="api-docs-search" role="dialog" aria-label="Поиск по API">
          <input
            autoFocus
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Метод, путь или модуль"
            aria-label="Поиск по документации API"
          />
          <ul>
            {hits.map((hit) => (
              <li key={hit.key + hit.hash}>
                <button type="button" onClick={() => openModule(hit.key, hit.hash)}>
                  {hit.label}
                </button>
              </li>
            ))}
          </ul>
          <button type="button" className="btn ghost" onClick={() => setSearchOpen(false)}>
            Закрыть
          </button>
        </div>
      ) : null}

      <div className="api-layout">
        <aside className="api-nav">
          <p className="muted">{active.module.nav_purpose}</p>
          <Nav
            module={active.module}
            docs={docs}
            spyId={spyId}
            onJump={(hash) => {
              if (hash === 'api-credentials-overview' || hash.startsWith('api-credentials-')) {
                openModule('credentials', hash);
                return;
              }
              if (hash === 'api-headers-kit' || hash.startsWith('api-headers-')) {
                openModule('headers', hash);
                return;
              }
              openModule(active.module.key, hash);
            }}
          />
        </aside>
        <div className="api-content">
          {active.module.reference_panel === 'credentials' ? (
            <CredentialsPanel module={active.module} docs={docs} catalog={catalog} />
          ) : null}
          {active.module.reference_panel === 'headers' ? (
            <HeadersPanel module={active.module} docs={docs} />
          ) : null}
          {!active.module.is_reference ? (
            <ModulePanel module={active.module} docs={docs} catalog={catalog} token={token} />
          ) : null}
        </div>
      </div>
    </div>
  );
}

function Nav({
  module,
  docs,
  spyId,
  onJump,
}: {
  module: ApiModule;
  docs?: ApiDoc;
  spyId: string;
  onJump: (hash: string) => void;
}) {
  const links: { href: string; label: string }[] = [];
  if (module.key === 'credentials') {
    links.push({ href: 'api-credentials-overview', label: 'С чего начать' });
    for (const section of docs?.sections || []) links.push({ href: section.id, label: section.title });
  } else if (module.key === 'headers') {
    links.push({ href: 'api-headers-kit', label: 'Заготовки' });
    links.push({ href: 'api-headers-overview', label: 'Справочник заголовков' });
  } else {
    links.push({ href: module.section_id.replace('-docs', '') + '-common', label: module.common_nav_label });
    links.push({ href: 'api-credentials-overview', label: 'Ключи' });
    links.push({ href: 'api-headers-kit', label: 'Заготовки' });
    for (const group of docs?.groups || []) links.push({ href: group.id, label: group.title });
  }
  return (
    <nav className="api-side-nav" aria-label={`Разделы ${module.nav_label}`}>
      {links.map((link, index) => (
        <a
          key={link.href}
          href={'#' + link.href}
          className={`${spyId === link.href ? 'is-active' : ''} ${index === 0 ? 'is-main' : ''} ${link.label === 'Ключи' || link.label === 'Заготовки' ? 'is-ref' : ''}`.trim()}
          onClick={(event) => {
            event.preventDefault();
            onJump(link.href);
          }}
        >
          {link.label}
        </a>
      ))}
    </nav>
  );
}

function CredentialsPanel({ module, docs, catalog }: { module: ApiModule; docs?: ApiDoc; catalog: ApiCatalog }) {
  return (
    <div id={module.section_id}>
      <header className="api-module-head">
        <div>
          <p className="kicker">{module.badge_label}</p>
          <h2>{docs?.title}</h2>
          <p className="lead">{docs?.description}</p>
        </div>
      </header>
      <section id="api-credentials-overview" className="api-group" data-api-spy-section>
        <h3>С чего начать</h3>
        <div className="api-cred-levels">
          {(docs?.overview?.levels || []).map((level) => (
            <a key={level.anchor} href={'#' + level.anchor}>
              <span className="badge">{level.badge}</span>
              <strong>{level.title}</strong>
              <span className="muted">{level.summary}</span>
            </a>
          ))}
        </div>
        <p className="api-spec-label">Типовый сценарий</p>
        <ol className="api-cred-flow">
          {(docs?.overview?.flow || []).map((step) => (
            <li key={step.title}>
              <strong>{step.title}</strong>
              <span className="muted">{step.text}</span>
            </li>
          ))}
        </ol>
      </section>
      {(docs?.sections || []).map((section) => (
        <section key={section.id} id={section.id} className="api-group" data-api-spy-section>
          <h3>{section.title}</h3>
          {(section.tokens || []).map((token) => {
            const profile = token.header_prefix && token.header_profile
              ? findProfile(catalog, token.header_prefix, token.header_profile)
              : undefined;
            const copy = profile ? profileCopyBlock(profile) : '';
            return (
              <article key={token.name} className="api-token-card">
                <header className="api-token-head">
                  <div>
                    <h4>{token.label || token.name}</h4>
                    <code>{token.name}</code>
                  </div>
                  {profile ? (
                    <button
                      type="button"
                      className="api-header-symbol"
                      data-api-copy={copy}
                      onClick={() => copyText(copy)}
                    >
                      {profileSymbol(token.header_profile || '')}
                    </button>
                  ) : null}
                </header>
                <dl className="api-token-dl">
                  <div>
                    <dt>Когда нужен</dt>
                    <dd>{token.when}</dd>
                  </div>
                  <div>
                    <dt>Как получить</dt>
                    <dd>{token.how_get}</dd>
                  </div>
                  <div>
                    <dt>Как передать</dt>
                    <dd>{token.how_send}</dd>
                  </div>
                  <div>
                    <dt>Срок жизни</dt>
                    <dd>{token.lifetime}</dd>
                  </div>
                </dl>
              </article>
            );
          })}
        </section>
      ))}
    </div>
  );
}

function HeadersPanel({ module, docs }: { module: ApiModule; docs?: ApiDoc }) {
  return (
    <div id={module.section_id}>
      <header className="api-module-head">
        <div>
          <p className="kicker">{module.badge_label}</p>
          <h2>{docs?.title}</h2>
          <p className="lead">{docs?.description}</p>
        </div>
      </header>
      <nav id="api-headers-toc" className="api-headers-toc" aria-label="Содержание заголовков">
        {(docs?.sections || []).map((section) => (
          <a key={section.id} href={'#' + section.id}>
            {section.title}
          </a>
        ))}
        <a href="#api-headers-overview">Справочник заголовков</a>
        <a href="#api-headers-auth-flow">Поток RBAC</a>
      </nav>
      <section id="api-headers-auth-flow" className="api-group" data-api-spy-section>
        <h3>Поток авторизации RBAC</h3>
        <ol className="api-cred-flow">
          <li>
            <strong>Вход</strong>
            <span className="muted">POST /rbac/api/v1/auth/login → access_token, refresh_token, csrf_token.</span>
          </li>
          <li>
            <strong>Чтение</strong>
            <span className="muted">GET — Authorization: Bearer {'{access_token}'}.</span>
          </li>
          <li>
            <strong>Изменение</strong>
            <span className="muted">POST / PATCH / DELETE — Bearer + X-CSRF-Token.</span>
          </li>
        </ol>
      </section>
      <section id="api-headers-kit" className="api-group" data-api-spy-section>
        <h3>Заготовки MF_HEADER_*</h3>
        {(docs?.sections || []).map((section, index) => (
          <div key={section.id}>
            <h4 id={section.id}>{section.title}</h4>
            {(section.profiles || []).map((profile, pIndex) => {
              const copy = profileCopyBlock(profile);
              return (
                <details key={profile.id} className="api-profile-details" open={index === 0 && pIndex === 0}>
                  <summary>
                    <button
                      type="button"
                      className="api-header-symbol"
                      data-api-copy={copy}
                      onClick={(event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        copyText(copy);
                      }}
                    >
                      {profileSymbol(profile.id)}
                    </button>
                    {profile.label}
                  </summary>
                  <p className="muted">{profile.note}</p>
                  <pre className="api-code-block">{copy}</pre>
                </details>
              );
            })}
          </div>
        ))}
      </section>
      <section id="api-headers-overview" className="api-group" data-api-spy-section>
        <h3>Справочник заголовков</h3>
        <div className="api-table-wrap">
          <table className="api-spec-table">
            <thead>
              <tr>
                <th>Имя</th>
                <th>Где</th>
                <th>Описание</th>
              </tr>
            </thead>
            <tbody>
              {(docs?.overview?.headers || []).map((row) => (
                <tr key={row.name}>
                  <td>
                    <code>{row.name}</code>
                  </td>
                  <td>{row.scope}</td>
                  <td>{row.description}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}

function ModulePanel({
  module,
  docs,
  catalog,
  token,
}: {
  module: ApiModule;
  docs?: ApiDoc;
  catalog: ApiCatalog;
  token: string;
}) {
  const commonId = module.section_id.replace('-docs', '') + '-common';
  const prefix = headersPrefixForModule(module.key);
  const commonErrors = docs?.common?.errors || [];
  return (
    <div id={module.section_id}>
      <header className="api-module-head">
        <div>
          <p className="kicker">{module.badge_label}</p>
          <h2>{docs?.title}</h2>
          <p className="lead">{docs?.description}</p>
        </div>
        {module.actions?.length ? (
          <p className="api-module-actions">
            {module.actions.map((action) => (
              <a key={action.href} href={action.href}>
                {action.label}
              </a>
            ))}
          </p>
        ) : null}
      </header>
      <section id={commonId} className="api-group" data-api-spy-section>
        <h3>{docs?.common?.access?.title || 'Обзор'}</h3>
        {(docs?.common?.access?.paragraphs || []).map((p) => (
          <p key={p} className="muted">
            {p}
          </p>
        ))}
        {commonErrors.length ? (
          <>
            <p className="api-spec-label">Типовые ошибки</p>
            <SpecTable
              headers={['Код', 'Описание', 'Пример']}
              rows={commonErrors.map((row) => [String(row.code ?? ''), row.description || '', row.example || '—'])}
            />
          </>
        ) : null}
      </section>
      {(docs?.groups || []).map((group) => (
        <section key={group.id} id={group.id} className="api-group" data-api-spy-section>
          <h3>{group.title}</h3>
          {group.is_live_panel ? <p className="muted">Живая спека подставляется из сессии Desk (maniforge_access_token).</p> : null}
          {group.is_fields_panel ? <p className="muted">Поля custom manifest связаны с REST POST через data-api-field-link.</p> : null}
          {(group.endpoints || []).map((ep) => (
            <EndpointCard
              key={ep.id || ep.path}
              endpoint={ep}
              prefix={ep.headers_prefix || prefix}
              catalog={catalog}
              token={token}
              commonErrors={commonErrors}
            />
          ))}
        </section>
      ))}
    </div>
  );
}

function SpecTable({ headers, rows }: { headers: string[]; rows: string[][] }) {
  return (
    <div className="api-table-wrap">
      <table className="api-spec-table">
        <thead>
          <tr>
            {headers.map((h) => (
              <th key={h}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, i) => (
            <tr key={i}>
              {row.map((cell, j) => (
                <td key={j}>
                  {j === 0 || headers[j] === 'Тип' || headers[j] === 'Пример' ? <code>{cell}</code> : cell}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function EndpointCard({
  endpoint,
  prefix,
  catalog,
  token,
  commonErrors,
}: {
  endpoint: ApiEndpoint;
  prefix: string;
  catalog: ApiCatalog;
  token: string;
  commonErrors: { code?: number; description?: string; example?: string }[];
}) {
  const method = (endpoint.method || 'GET').toUpperCase();
  const profileId = endpoint.headers_profile || '';
  const profile = profileId ? findProfile(catalog, prefix, profileId) : undefined;
  const copy = profile ? profileCopyBlock(profile) : '';
  const responses = [...(endpoint.responses || [])];
  if (profileId && profileId !== 'none' && commonErrors.length) {
    for (const err of commonErrors) {
      if (!responses.some((r) => r.code === err.code)) responses.push(err);
    }
  }
  const isLogin = endpoint.method === 'POST' && Boolean(endpoint.path?.includes('/auth/login'));
  return (
    <article className="api-method-card" id={endpoint.id}>
      <header className="api-ep-head">
        <code className={`api-method api-method-${method.toLowerCase()}`}>{method}</code>
        <button type="button" data-api-copy={endpoint.path || ''} onClick={() => copyText(endpoint.path || '')}>
          <code className="api-method-path">{endpoint.path}</code>
        </button>
      </header>
      <h3 className="api-method-title">{endpoint.title}</h3>
      <p className="muted">{endpoint.summary}</p>
      {endpoint.auth ? (
        <p className="api-method-auth">
          <strong>Доступ при вызове:</strong> {endpoint.auth}
        </p>
      ) : null}
      {profile ? (
        <p className="api-method-headers">
          <span className="muted">Заготовка:</span>
          <button type="button" className="api-header-symbol" data-api-copy={copy} onClick={() => copyText(copy)}>
            {profileSymbol(profile.id)}
          </button>
          <a href="#api-headers-kit" className="muted">
            все заготовки
          </a>
        </p>
      ) : null}
      {endpoint.headers_extra?.length ? (
        <>
          <p className="api-spec-label">Дополнительные заголовки</p>
          <SpecTable
            headers={['Заголовок', 'Обяз.', 'Описание']}
            rows={endpoint.headers_extra.map((row) => [row.name, row.required ? 'да' : 'нет', row.description || ''])}
          />
        </>
      ) : null}
      {endpoint.query?.length ? (
        <>
          <p className="api-spec-label">Параметры URL (query)</p>
          <SpecTable
            headers={['Параметр', 'Обяз.', 'Описание']}
            rows={endpoint.query.map((row) => [row.name, row.required ? 'да' : 'нет', row.description || ''])}
          />
        </>
      ) : null}
      {endpoint.body ? (
        <>
          <p className="api-spec-label">Тело запроса ({endpoint.body.content_type || 'application/json'})</p>
          {endpoint.body.fields?.length ? (
            <div className="api-table-wrap">
              <table className="api-spec-table">
                <thead>
                  <tr>
                    <th>Поле</th>
                    <th>Тип</th>
                    <th>Обяз.</th>
                    <th>Описание</th>
                  </tr>
                </thead>
                <tbody>
                  {endpoint.body.fields.map((field) => (
                    <tr key={field.name}>
                      <td>
                        <a href="#api-try" data-api-field-link={field.name}>
                          <code>{field.name}</code>
                        </a>
                      </td>
                      <td>
                        <code>{field.type}</code>
                      </td>
                      <td>{field.required ? 'да' : 'нет'}</td>
                      <td>{field.description}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : null}
          {endpoint.body.example ? <pre className="api-code-block">{endpoint.body.example}</pre> : null}
        </>
      ) : (
        <p className="muted" style={{ fontSize: '.88rem' }}>
          Тело запроса не требуется.
        </p>
      )}
      <p className="api-spec-label">Ответы</p>
      {responses.length ? (
        <SpecTable
          headers={['Код', 'Описание', 'Пример']}
          rows={responses.map((row) => [String(row.code ?? ''), row.description || '', row.example || '—'])}
        />
      ) : null}
      {isLogin ? (
        <p className="muted" id="api-try">
          Сессия Desk: {token ? 'maniforge_access_token задан' : 'maniforge_access_token пуст — войдите в Desk'}
        </p>
      ) : null}
    </article>
  );
}
