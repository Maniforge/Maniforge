<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Controllers;

use App\Maniforge\Rbac\Support\HtmlResponse;

final class PageController
{
    public function home(): void
    {
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $mountedUnderMainPublic = str_starts_with($requestPath, '/rbac');

        if ($mountedUnderMainPublic) {
            header('Location: /', true, 302);
            return;
        }

        $this->render('/maniforge/rbac/public/pages/home.php');
    }

    public function admin(): void
    {
        if (!$this->guardAdminConsole()) {
            return;
        }

        $this->render('/maniforge/rbac/public/admin/index.php');
    }

    public function apiDocs(): void
    {
        header('Location: /api#api-public-docs', true, 302);
    }

    public function openapiYaml(): void
    {
        $root = dirname(__DIR__, 4);
        $path = $root . '/docs/MANIFORGE_RBAC_OPENAPI.yaml';
        if (!is_file($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'OpenAPI file not found';
            return;
        }

        header('Content-Type: application/yaml; charset=utf-8');
        readfile($path);
    }

    private function render(string $relativePath): void
    {
        $root = dirname(__DIR__, 4);
        $path = $root . $relativePath;
        if (!is_file($path)) {
            HtmlResponse::send('<h1>Template not found</h1>', 500);
            return;
        }

        ob_start();
        require $path;
        $html = (string) ob_get_clean();
        HtmlResponse::send($html);
    }

    private function guardAdminConsole(): bool
    {
        $appEnv = strtolower(trim((string) ($_ENV['APP_ENV'] ?? 'production')));
        $appDebug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($appEnv === 'local' && $appDebug) {
            return true;
        }

        $expectedUser = trim((string) ($_ENV['RBAC_ADMIN_CONSOLE_USER'] ?? ''));
        $expectedPassword = (string) ($_ENV['RBAC_ADMIN_CONSOLE_PASSWORD'] ?? '');
        $expectedHash = (string) ($_ENV['RBAC_ADMIN_CONSOLE_PASSWORD_HASH'] ?? '');

        if ($expectedUser === '' || ($expectedPassword === '' && $expectedHash === '')) {
            HtmlResponse::send(
                '<h1>Admin console is locked</h1><p>Configure RBAC_ADMIN_CONSOLE_USER and RBAC_ADMIN_CONSOLE_PASSWORD_HASH before opening this page.</p>',
                503
            );
            return false;
        }

        [$user, $password] = $this->basicAuthCredentials();
        $passwordMatches = $expectedHash !== ''
            ? password_verify($password, $expectedHash)
            : hash_equals($expectedPassword, $password);

        if (!hash_equals($expectedUser, $user) || !$passwordMatches) {
            header('WWW-Authenticate: Basic realm="Maniforge RBAC Admin", charset="UTF-8"');
            HtmlResponse::send('<h1>Unauthorized</h1>', 401);
            return false;
        }

        return true;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function basicAuthCredentials(): array
    {
        $user = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
        $password = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
        if ($user !== '' || $password !== '') {
            return [$user, $password];
        }

        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!str_starts_with($header, 'Basic ')) {
            return ['', ''];
        }

        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return ['', ''];
        }

        [$basicUser, $basicPassword] = explode(':', $decoded, 2);
        return [$basicUser, $basicPassword];
    }
}
