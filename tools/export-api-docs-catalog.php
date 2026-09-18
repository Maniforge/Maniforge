<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/templates/data/api-docs/helpers.php';
$catalog = require $root . '/templates/data/api-docs/catalog.php';
api_doc_merge_personal_modules($catalog, $root . '/templates/data/api-docs/generated');

$docs = [];
foreach ($catalog['categories'] as $category) {
    foreach ($category['modules'] as $module) {
        $key = (string) $module['key'];
        if (!empty($module['inline']) && is_array($module['docs'] ?? null)) {
            $docs[$key] = $module['docs'];
            continue;
        }
        $docs[$key] = require $root . '/templates/data/api-docs/' . $module['file'];
    }
}

$payload = [
    'default_module_key' => $catalog['default_module_key'] ?? 'public',
    'categories' => $catalog['categories'],
    'references' => $catalog['references'] ?? [],
    'docs' => $docs,
];

$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    fwrite(STDERR, "json encode failed\n");
    exit(1);
}

$out = $root . '/deploy/www-desk/assets/api-docs-catalog.json';
file_put_contents($out, $json);
fwrite(STDERR, 'catalog bytes=' . strlen($json) . "\n");
