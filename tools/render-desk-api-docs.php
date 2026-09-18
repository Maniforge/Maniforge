<?php
declare(strict_types=1);

putenv('MANIFORGE_DESK_API=1');
$_ENV['MANIFORGE_DESK_API'] = '1';
$_SERVER['REQUEST_URI'] = '/api';
$_SERVER['REQUEST_METHOD'] = 'GET';

$root = dirname(__DIR__);
$outPath = $root . '/deploy/www-desk/api/index.html';
ob_start();
require $root . '/templates/api.php';
$html = ob_get_clean();
if ($html === '' || !str_contains($html, 'app-api-dock')) {
    fwrite(STDERR, "render failed\n");
    exit(1);
}
if (!is_dir(dirname($outPath))) {
    mkdir(dirname($outPath), 0777, true);
}
file_put_contents($outPath, $html);
fwrite(STDERR, 'wrote ' . strlen($html) . " bytes\n");
