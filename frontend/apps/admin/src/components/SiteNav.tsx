import type { ReactNode } from 'react';
import { SITE_NAV, type SiteZone } from '@/shared/siteNavConfig';

type Props = {
  active: SiteZone;
  showMarketingLinks?: boolean;
  actions?: ReactNode;
};

export function SiteNav({ active, showMarketingLinks = false, actions }: Props) {
  const { brand, zones, marketingLinks } = SITE_NAV;

  return (
    <header className="mf-site-nav">
      <a className="mf-site-nav-brand" href={brand.href}>
        <span className="mf-site-nav-brand-mark">{brand.mark}</span>
        <span>{brand.name}</span>
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

      {showMarketingLinks ? (
        <nav className="mf-site-nav-links" aria-label="Маркетинг">
          {marketingLinks.map((link) => (
            <a key={link.id} href={link.href}>
              {link.label}
            </a>
          ))}
        </nav>
      ) : null}

      {actions ? <div className="mf-site-nav-actions">{actions}</div> : null}
    </header>
  );
}
