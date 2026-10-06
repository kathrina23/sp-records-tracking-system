<?php
// Preserve old bookmarks and submissions after the route rename.
require_once __DIR__ . '/../app/config.php';
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ' . rtrim(BASE_PATH, '/') . '/councilors.php' . ($query !== '' ? '?' . $query : ''), true, 307);
exit;
