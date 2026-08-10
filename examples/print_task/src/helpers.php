<?php

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function view(string $template, array $data = []): void
{
    extract($data, EXTR_SKIP);
    require APP_BASE . '/templates/' . $template . '.php';
}

function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function rs_config_label(int $rsConfig, string $rsName = ''): string
{
    return match ($rsConfig) {
        1 => 'Основной',
        2 => 'Компаньон',
        3 => 'Наволочки',
        4 => 'Компаньон 2',
        default => 'Дизайн №' . $rsConfig . ($rsName !== '' ? ' - ' . $rsName : ''),
    };
}

function url_with_params(array $params): string
{
    $merged = array_merge($_GET, $params);
    $merged = array_filter($merged, static fn($v) => $v !== null && $v !== '');
    return '/' . ($merged ? '?' . http_build_query($merged) : '');
}
