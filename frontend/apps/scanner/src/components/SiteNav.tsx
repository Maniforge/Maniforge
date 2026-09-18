import type { ReactNode } from 'react';
import { SITE_NAV, type SiteZone } from '@/shared/siteNavConfig';

type Props = {
  active: SiteZone;
  actions?: ReactNode;
};

export function SiteNav({ active, actions }: Props) {
  const { brand, zones } = SITE_NAV;

  return (
    <header className="mf-site-nav">
      <a className="mf-site-nav-brand" href={brand.href}>
        Mani<i>forge</i>
      </a>

      <nav className="mf-site-nav-zones" aria-label="Приложения Maniforge">
        {zones.map((zone) => (
          <a
            key={zone.id}
            className={`mf-site-nav-zone${zone.id === active ? ' is-active' : ''}`}
            href={zone.href}
            aria-current={zone.id === active ? 'page' : undefined}
          >
            <i className={`bi bi-${zone.icon}`} aria-hidden="true" />
            {zone.label}
          </a>
        ))}
      </nav>

      {actions ? <div className="mf-site-nav-actions">{actions}</div> : null}
    </header>
  );
}
