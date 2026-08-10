<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Support;

final class HtmlResponse
{
    public static function send(string $html, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }
}
