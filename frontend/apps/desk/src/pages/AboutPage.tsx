export function AboutPage() {
  return (
    <main className="main about">
      <p className="kicker">Go · PostgreSQL · Apache 2.0 · v0.1.2</p>
      <h1>Описали сущность — получили API.</h1>
      <p className="lead">
        Maniforge — фреймворк на Go. Манифест полей становится REST и OpenAPI, в ядре уже доступы и тенанты. Ставится на
        ваш Ubuntu. Это ранняя версия ядра, не готовая ERP.
      </p>
      <p className="cta">
        <a className="btn" href="https://github.com/Maniforge/Maniforge">
          Код на GitHub
        </a>
        <a className="btn ghost" href="/api/">
          API
        </a>
      </p>
      <p className="facts">
        <span>Manifest → REST</span>
        <span>RBAC · тенанты · realtime</span>
        <span>self-host</span>
      </p>

      <section>
        <p className="kicker">Ориентир</p>
        <h2>Что это</h2>
        <div className="prose">
          <p>
            Это каркас для учёта, склада и внутренних сервисов: сущность описывается, HTTP-слой появляется сам. Не сайт
            «под ключ» и не замена бухгалтерии 1С.
          </p>
          <p>
            Если знаете Frappe или Django — ориентир такой: манифест ≈ DocType или модель. Дальше аналогия кончается:
            рабочего стола и готового ERP в релизе v0.1.2 нет.
          </p>
          <p>
            Сейчас сервер и PostgreSQL ставите и обслуживаете вы. Позже сможем делать это за вас. Заказать такой хостинг
            пока нельзя.
          </p>
        </div>
      </section>

      <section>
        <p className="kicker">v0.1.2-box</p>
        <h2>Что уже можно поставить</h2>
        <p className="lead">Пять сервисов за Caddy, одна PostgreSQL 16. Это и есть публичный комплект.</p>
        <div className="grid">
          <article className="card">
            <span className="tag">RBAC</span>
            <h3>Доступ</h3>
            <p>Сессии, роли, MFA, audit.</p>
          </article>
          <article className="card">
            <span className="tag">Licensing</span>
            <h3>Тенант</h3>
            <p>Организация, план, квоты.</p>
          </article>
          <article className="card">
            <span className="tag">Manifest</span>
            <h3>Сущности</h3>
            <p>Манифест → REST и OpenAPI.</p>
          </article>
          <article className="card">
            <span className="tag">Versioning</span>
            <h3>История</h3>
            <p>След изменений конфигурации.</p>
          </article>
          <article className="card">
            <span className="tag">Realtime</span>
            <h3>События</h3>
            <p>Live-события по сущностям.</p>
          </article>
        </div>
      </section>

      <section>
        <p className="kicker">Дальше</p>
        <h2>Не в этом релизе</h2>
        <div className="prose">
          <p>
            В очереди: рабочий стол из манифестов, склад и WMS как приложения на ядре, установка и сопровождение сервера
            с нашей стороны. Пока этого нельзя заказать как готовый продукт — только ядро и заказная сборка вокруг него.
          </p>
        </div>
      </section>

      <section>
        <p className="kicker">Запуск</p>
        <h2>Как поставить ядро</h2>
        <p className="lead">Ubuntu 22.04 или 24.04 LTS. Минимум 2 vCPU, 4 ГБ RAM, 40 ГБ SSD. Свои секреты, свой домен.</p>
        <pre>
          <code>{`git clone --branch platform-core https://github.com/Maniforge/Maniforge.git
cd Maniforge
cp deploy/.env.platform.server.example deploy/.env.platform
sudo bash deploy/scripts/install-maniforge.sh --domain platform.example.com
bash deploy/scripts/verify-maniforge.sh`}</code>
        </pre>
        <p className="muted">
          Полный runbook:{' '}
          <a href="https://github.com/Maniforge/Maniforge">github.com/Maniforge/Maniforge</a>. Каталог продуктов — на{' '}
          <a href="https://maniforge.ru/">maniforge.ru</a>.
        </p>
      </section>

      <div className="foot">
        <span>Ядро — Apache 2.0. Релиз v0.1.2-box.</span>
        <span>
          <a href="mailto:hello@maniforge.ru">hello@maniforge.ru</a>
        </span>
      </div>
    </main>
  );
}
