<?php
require_once __DIR__ . '/../app/elibrary.php';
$isDraft = ($_GET['draft'] ?? '') === '1';
if ($isDraft) {
    require_elibrary_staff();
}
$table = $isDraft ? 'legislation_drafts' : 'legislation_publications';
$stmt = db()->prepare("SELECT original_name, stored_name, mime_type FROM $table WHERE id=?");
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$file = $stmt->fetch();
$path = $file ? dirname(__DIR__) . '/storage/elibrary/' . basename($file['stored_name']) : '';
if (!$file || $file['stored_name'] === '' || !is_file($path)) {
    http_response_code(404);
    exit('Signed legislation file not found.');
}
header('Content-Type: ' . $file['mime_type']);
header('Content-Length: ' . filesize($path));
header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($file['original_name']));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: no-store');
readfile($path);
