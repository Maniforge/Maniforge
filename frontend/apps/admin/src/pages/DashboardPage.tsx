import { Link } from 'react-router-dom';
import { useAuth } from '@/shared/auth/AuthContext';

type ModuleCard = {
  title: string;
  tag: string;
  description: string;
  href: string;
  external?: boolean;
  icon: string;
};

export function DashboardPage() {
  const { modules } = useAuth();

  const cards: ModuleCard[] = [
    {
      title: 'Manifest',
      tag: 'React',
      description: 'CRUD записей manifest + Realtime live-обновления.',
      href: '/manifest',
      icon: 'bi-braces',
    },
    {
      title: 'Warehouses',
      tag: 'React',
      description: 'Дерево складских узлов, audit log и создание stock.',
      href: '/warehouses',
      icon: 'bi-diagram-3',
    },
    {
      title: 'WMS Scanner',
      tag: 'PWA',
      description: 'Мобильный интерфейс склада — скан-инфо и операции.',
      href: '/scanner',
      external: true,
      icon: 'bi-upc-scan',
    },
  ];

  if (modules?.tenant) {
    cards.push(
      {
        title: 'Профиль',
        tag: 'Account',
        description: 'Email, телефон, смена пароля.',
        href: '/profile',
        external: true,
        icon: 'bi-person',
      },
      {
        title: 'Проекты',
        tag: 'Projects',
        description: 'Project scope и глобальные переменные.',
        href: '/projects',
        external: true,
        icon: 'bi-folder',
      },
      {
        title: 'Tenant admin (PHP)',
        tag: 'Legacy',
        description: 'Пользователи, роли, policies — пока в /admin/tenant.',
        href: '/admin/tenant',
        external: true,
        icon: 'bi-gear',
      },
      {
        title: 'Manifest (PHP)',
        tag: 'Прототип',
        description: 'Старый CRUD /refine-manifest.',
        href: '/refine-manifest',
        external: true,
        icon: 'bi-window',
      },
    );
  }

  if (modules?.platform) {
    cards.push({
      title: 'Platform licensing',
      tag: 'Licensing',
      description: 'Tenants, планы, лицензии.',
      href: '/admin/platform',
      external: true,
      icon: 'bi-credit-card-2',
    });
  }

  return (
    <section>
      <div className="mf-page-head">
        <span className="mf-kicker">
          <i className="bi bi-grid-3x3-gap" aria-hidden="true" />
          Модули
        </span>
        <h1 className="mf-title">Панель управления</h1>
        <p className="mf-lead">React SPA (фаза A). Новые экраны — здесь; legacy — ссылки на PHP.</p>
      </div>
      <div className="mf-grid">
        {cards.map((card) =>
          card.external ? (
            <a key={card.href} className="mf-card mf-module-card" href={card.href}>
              <span>
                <i className={`bi ${card.icon}`} aria-hidden="true" /> {card.tag}
              </span>
              <strong>{card.title}</strong>
              <small>{card.description}</small>
            </a>
          ) : (
            <Link key={card.href} className="mf-card mf-module-card" to={card.href}>
              <span>
                <i className={`bi ${card.icon}`} aria-hidden="true" /> {card.tag}
              </span>
              <strong>{card.title}</strong>
              <small>{card.description}</small>
            </Link>
          ),
        )}
      </div>
    </section>
  );
}
