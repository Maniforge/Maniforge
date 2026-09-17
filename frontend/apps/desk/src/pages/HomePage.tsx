import { useEffect } from 'react';
import { hasDeskSession } from '@maniforge/desk-ui';
import { LoginForm } from './LoginForm';

const MANIFEST = `name: item
fields:
  sku: string
  qty: integer`;

const HTTP = `GET    /item
POST   /item
GET    /item/{id}
PATCH  /item/{id}`;

export function HomePage() {
  useEffect(() => {
    if (hasDeskSession()) window.location.href = '/desk/';
  }, []);

  return (
    <div className="split">
      <section className="pane-greet" aria-label="О продукте">
        <h1>Описали сущность — получили API.</h1>
        <p className="lead">Фреймворк на Go. Манифест полей становится REST.</p>
        <div className="forge">
          <pre className="proof">
            <span className="proof-label">Манифест</span>
            <code>{MANIFEST}</code>
          </pre>
          <span className="forge-arrow" aria-hidden="true">
            →
          </span>
          <pre className="proof proof-out">
            <span className="proof-label">HTTP</span>
            <code>{HTTP}</code>
          </pre>
        </div>
        <p className="cta">
          <a className="btn" href="https://github.com/Maniforge/Maniforge">
            Код на GitHub
          </a>
          <a className="btn ghost" href="/about/">
            О проекте
          </a>
        </p>
        <p className="status">Go · PostgreSQL 16 · self-host</p>
      </section>
      <section className="pane-login" id="login" aria-label="Вход">
        <LoginForm titleTag="h2" />
      </section>
    </div>
  );
}
