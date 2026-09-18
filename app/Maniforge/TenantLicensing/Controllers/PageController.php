<?php
declare(strict_types=1);

namespace App\Maniforge\TenantLicensing\Controllers;

final class PageController
{
    public function admin(): void
    {
        $expectedToken = trim((string) ($_ENV['TENANT_LICENSING_ADMIN_TOKEN'] ?? ''));
        if ($expectedToken !== '') {
            $provided = trim((string) ($_GET['token'] ?? ''));
            if ($provided === '' || !hash_equals($expectedToken, $provided)) {
                $this->renderAdminGate();
                return;
            }
        }

        $this->render(dirname(__DIR__, 4) . '/maniforge/tenant-licensing/public/admin/index.php');
    }

    public function apiDocs(): void
    {
        header('Location: /api#api-private-docs', true, 302);
    }

    private function render(string $path): void
    {
        if (!is_file($path)) {
            http_response_code(404);
            echo 'Page not found';
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        require $path;
    }

    private function renderAdminGate(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(401);
        ?>
        <!doctype html>
        <html lang="ru">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Platform admin access</title>
            <link href="/assets/css/app.css" rel="stylesheet">
        </head>
        <body>
        <main class="app-main">
            <section class="app-panel app-shell-lg mt-4">
                <span class="app-kicker">Tenant Licensing</span>
                <h1 class="app-title h3">Platform admin token required</h1>
                <p class="app-lead">
                    Задайте <code>TENANT_LICENSING_ADMIN_TOKEN</code> в окружении и откройте
                    <code>/tenant-licensing/admin?token=&lt;token&gt;</code> или введите token в UI после входа.
                </p>
                <form class="app-actions mt-3" method="get" action="/tenant-licensing/admin">
                    <input class="app-input" type="password" name="token" placeholder="Admin token" autocomplete="off" required>
                    <button class="app-button" type="submit">Open console</button>
                </form>
            </section>
        </main>
        </body>
        </html>
        <?php
    }
}
