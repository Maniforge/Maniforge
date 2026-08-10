<?php
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '/' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $target, true, 302);
exit;
