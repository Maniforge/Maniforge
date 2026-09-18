<?php
declare(strict_types=1);
$_SERVER['REQUEST_URI'] = '/api';
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
require __DIR__ . '/api-docs-preview-router.php';
$html = ob_get_clean();
echo 'LEN=' . strlen($html) . PHP_EOL;
echo 'HAS_RBAC=' . (str_contains($html, 'RBAC') ? 'yes' : 'no') . PHP_EOL;
echo 'HAS_HEADERS=' . (str_contains($html, 'Заголовки') ? 'yes' : 'no') . PHP_EOL;
echo substr($html, 0, 500) . PHP_EOL;
