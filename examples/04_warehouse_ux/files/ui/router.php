<?php
declare(strict_types=1);
/** Warehouse UX Suite. php -S 127.0.0.1:8765 router.php */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$rbac = getenv('RBAC_URL') ?: 'http://127.0.0.1:8093/rbac';
$manifest = getenv('MANIFEST_URL') ?: 'http://127.0.0.1:8095';

if (str_starts_with($uri, '/proxy/rbac')) {
    proxy($rbac, substr($uri, strlen('/proxy/rbac')) ?: '/', $method);
    return true;
}
if (str_starts_with($uri, '/proxy/manifest')) {
    proxy($manifest, substr($uri, strlen('/proxy/manifest')) ?: '/', $method);
    return true;
}

if (str_starts_with($uri, '/manifests/')) {
    $name = basename($uri);
    $path = dirname(__DIR__) . '/manifests/' . $name;
    if (is_file($path)) {
        header('Content-Type: application/json; charset=utf-8');
        readfile($path);
        return true;
    }
}

$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    return false;
}
if ($uri === '/' || $uri === '') {
    readfile(__DIR__ . '/index.html');
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not found: {$uri}\n";
return true;

function proxy(string $base, string $path, string $method): void
{
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $url = rtrim($base, '/') . $path . ($qs !== '' ? ('?' . $qs) : '');
    $headers = [];
    foreach (getallheaders() ?: [] as $name => $value) {
        $lname = strtolower((string) $name);
        if (in_array($lname, ['host', 'content-length', 'connection'], true)) {
            continue;
        }
        $headers[] = $name . ': ' . $value;
    }
    $body = file_get_contents('php://input');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => ($method === 'GET' || $method === 'HEAD') ? null : $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        http_response_code(502);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => curl_error($ch)], JSON_UNESCAPED_UNICODE);
        return;
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    http_response_code($status);
    foreach (explode("\r\n", substr($raw, 0, $headerSize)) as $line) {
        if ($line === '' || str_starts_with(strtolower($line), 'http/')) {
            continue;
        }
        $l = strtolower($line);
        if (str_starts_with($l, 'transfer-encoding:') || str_starts_with($l, 'content-length:')) {
            continue;
        }
        header($line, false);
    }
    echo substr($raw, $headerSize);
}
