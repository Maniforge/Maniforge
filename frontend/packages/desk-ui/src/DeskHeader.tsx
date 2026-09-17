import { useEffect, useState, type MouseEvent, type ReactNode } from 'react';
import { deskLogout, hasDeskSession, isLoginPath } from './session';

// Authed chrome reads maniforge_access_token (and admin twin) via hasDeskSession.

type Props = {
  current?: string;
  actions?: ReactNode;
  onLogout?: () => void;
};

const GUEST = [
  { href: '/about/', label: 'О проекте' },
  { href: '/about-us/', label: 'О нас' },
  { href: '/api/', label: 'API' },
];

const AUTH = [
  { href: '/desk/', label: 'Desk' },
  { href: '/desk/users/', label: 'Пользователи' },
  { href: '/apps/', label: 'Apps' },
  { href: '/app/', label: 'Admin' },
  { href: '/scanner/', label: 'Scanner' },
  { href: '/api/', label: 'API' },
];

function isCurrent(href: string, path: string): boolean {
  const here = path.endsWith('/') || path === '/' ? path : path + '/';
  const target = href === '/' ? '/' : href.endsWith('/') ? href : href + '/';
  if (target === '/') return here === '/';
  if (target === '/desk/' && here.startsWith('/desk/login')) return false;
  return here === target || here.startsWith(target);
}

function pathFromCurrent(current?: string): string {
  if (current === 'admin') return '/app/';
  if (current === 'scanner') return '/scanner/';
  if (typeof window === 'undefined') return '/';
  return window.location.pathname;
}

export function DeskHeader({ current, actions, onLogout }: Props) {
  const [authed, setAuthed] = useState(false);
  const [path, setPath] = useState(pathFromCurrent(current));

  useEffect(() => {
    const next = pathFromCurrent(current);
    setPath(next);
    setAuthed(hasDeskSession() && !isLoginPath(next));
  }, [current]);

  const links = authed ? AUTH : GUEST;

  function handleLogout(event: MouseEvent<HTMLButtonElement>) {
    event.preventDefault();
    if (onLogout) {
      onLogout();
      return;
    }
    deskLogout();
  }

  return (
    <header className="top">
      <a className="brand" href="/" aria-label="Maniforge">
        Mani<i>forge</i>
      </a>
      <nav className="nav">
        {links.map((link) => (
          <a
            key={link.href + link.label}
            href={link.href}
            aria-current={isCurrent(link.href, path) ? 'page' : undefined}
          >
            {link.label}
          </a>
        ))}
        {actions}
        {authed ? (
          <button type="button" data-logout onClick={handleLogout}>
            Выйти
          </button>
        ) : (
          <a className="nav-cta" href={path === '/' ? '#login' : '/desk/login/'}>
            Вход
          </a>
        )}
      </nav>
    </header>
  );
}
