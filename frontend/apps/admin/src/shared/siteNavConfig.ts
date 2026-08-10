/** Синхронизировать с public/assets/data/site-nav.json */
export type SiteZone = 'marketing' | 'admin' | 'scanner';

export const SITE_NAV = {
  brand: { name: 'Maniforge', mark: 'M', href: '/' },
  zones: [
    { id: 'marketing' as const, label: 'Сайт', href: '/', icon: 'house' },
    { id: 'admin' as const, label: 'Admin', href: '/app', icon: 'grid' },
    { id: 'scanner' as const, label: 'Scanner', href: '/scanner', icon: 'upc-scan' },
  ],
  marketingLinks: [
    { id: 'capabilities', label: 'Возможности', href: '/#capabilities' },
    { id: 'pricing', label: 'Тарифы', href: '/pricing' },
    { id: 'developers', label: 'Разработчикам', href: '/developers' },
    { id: 'api', label: 'API', href: '/api' },
  ],
} as const;
