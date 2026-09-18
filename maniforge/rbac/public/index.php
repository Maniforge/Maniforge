<?php
declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($requestPath === '/assets/css/app.css') {
    $cssPath = dirname(__DIR__, 3) . '/public/assets/css/app.css';
    if (is_file($cssPath)) {
        header('Content-Type: text/css; charset=utf-8');
        readfile($cssPath);
        return;
    }
}

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Maniforge\Rbac\Http\Kernel;

$kernel = new Kernel();
$kernel->handle(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $requestPath,
    $_SERVER,
    file_get_contents('php://input') ?: ''
);
