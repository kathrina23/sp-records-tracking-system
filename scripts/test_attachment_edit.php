<?php
require __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/attachment_edit.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $call): void {
    try { $call(); } catch (RuntimeException $error) { return; }
    throw new RuntimeException('Invalid replacement was accepted.');
}
foreach (['admin', 'city_secretary', 'receiving_clerk', 'secretariat', 'division_chief', 'staff', 'server_maintenance_staff', ''] as $role) {
    $_SESSION['user'] = ['role' => $role];
    check(can_edit_record_attachments() === in_array($role, ['admin', 'city_secretary'], true), 'Incorrect edit permission: ' . $role);
    if (can_edit_record_attachments()) {
        check(can_manage_plenary_record_attachments(['document_type' => 'Committee Referrals', 'status' => 'Received']), 'Attachment manager unavailable');
    }
}
check(validate_attachment_replacement(null) === null, 'Title-only editing requires an upload');
check(validate_attachment_replacement(['error' => UPLOAD_ERR_NO_FILE]) === null, 'Empty replacement rejected');
rejects(fn () => validate_attachment_replacement(['error' => UPLOAD_ERR_INI_SIZE]));
rejects(fn () => validate_attachment_replacement(['error' => UPLOAD_ERR_PARTIAL]));
$path = tempnam(sys_get_temp_dir(), 'attachment-edit-');
try {
    $file = ['tmp_name' => $path, 'name' => '../replacement.pdf', 'error' => UPLOAD_ERR_OK];
    file_put_contents($path, "%PDF-1.4\n" . str_repeat(' ', 3 * 1024 * 1024) . "\n%%EOF");
    $result = validate_attachment_replacement($file);
    check($result['mime'] === 'application/pdf' && $result['name'] === 'replacement.pdf', '3 MB PDF rejected or filename not normalized');
    file_put_contents($path, 'This is not a PDF.');
    clearstatcache(true, $path);
    rejects(fn () => validate_attachment_replacement($file));
    file_put_contents($path, '');
    clearstatcache(true, $path);
    rejects(fn () => validate_attachment_replacement($file));
    $stream = fopen($path, 'wb');
    fwrite($stream, "%PDF-1.4\n");
    ftruncate($stream, ATTACHMENT_MAX_BYTES + 1);
    fclose($stream);
    clearstatcache(true, $path);
    rejects(fn () => validate_attachment_replacement($file));
} finally { unlink($path); }
$row = ['record_id' => 1, 'stored_name' => 'old.pdf', 'title' => 'Title', 'original_name' => 'file.pdf', 'file_size' => 100];
$version = attachment_edit_version($row);
foreach (['record_id' => 2, 'stored_name' => 'new.pdf', 'title' => 'Changed'] as $key => $value) {
    $changed = $row;
    $changed[$key] = $value;
    check(!hash_equals($version, attachment_edit_version($changed)), 'Concurrent change not detected: ' . $key);
}
echo "PASS: edit permissions, title-only edits, 3 MB PDF, invalid/empty/oversized files, and stale versions.\n";
