export function HomePage() {
  return (
    <div className="split">
      <section className="pane-greet">
        <p className="kicker">v0.1.2-box</p>
        <h1>Ядро запущено.</h1>
        <p className="lead">Maniforge отвечает на этом сервере. Справа — вход в Desk.</p>
        <div className="grid">
          <a className="tile" href="/about/">
            <strong>Документация</strong>
            <span>Что уже в ядре</span>
          </a>
          <a className="tile" href="/api/">
            <strong>API</strong>
            <span>Каталог методов</span>
          </a>
          <a className="tile" href="/app/">
            <strong>Admin</strong>
            <span>Консоль тенанта</span>
          </a>
        </div>
      </section>
      <section className="pane-login">
        <div className="card login-card">
          <p className="kicker">Production Box</p>
          <h2>Framework → Desk → Apps</h2>
          <p className="muted">Публично — проект, команда, API и Admin. Desk, Scanner и приложения открываются после входа.</p>
          <p style={{ marginTop: '1rem' }}>
            <a className="btn" href="/desk/login/">Вход</a>
          </p>
        </div>
      </section>
    </div>
  );
}
