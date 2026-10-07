<?php
// Preserve bookmarks and in-progress submissions after the page rename.
require_once __DIR__ . '/../app/config.php';
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ' . rtrim(BASE_PATH, '/') . '/e-library_old.php' . ($query !== '' ? '?' . $query : ''), true, 307);
exit;
