export function AboutPage() {
  return (
    <main className="main about">
      <p className="kicker">О проекте</p>
      <h1>Maniforge</h1>
      <p className="lead">Описали сущность — получили REST, OpenAPI, доступы и тенанты.</p>
      <section className="card" style={{ marginTop: '1.25rem' }}>
        <h2>Что в ядре</h2>
        <p className="muted">Identity и RBAC, Manifest Engine, Desk, затем Apps. Складские модули подключаются как приложения.</p>
        <p className="cta" style={{ marginTop: '1rem' }}>
          <a className="btn" href="/api/">API</a>
          <a className="btn ghost" href="/desk/login/" style={{ marginLeft: '.5rem' }}>Вход</a>
        </p>
      </section>
    </main>
  );
}
