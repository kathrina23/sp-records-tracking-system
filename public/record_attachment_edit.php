<?php
require_once __DIR__ . '/../app/auth.php';
require_login();
if (!can_edit_record_attachments()) {
    http_response_code(403);
    exit('Only the City Secretary and Administrator can edit attachment files.');
}
reject_oversized_attachment_request();
ensure_plenary_number_schema();
require_once __DIR__ . '/../app/attachment_edit.php';
$id = (int) ($_GET['id'] ?? 0);
$recordId = (int) ($_GET['record_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM record_attachments WHERE id = ? AND record_id = ?');
$stmt->execute([$id, $recordId]);
$attachment = $stmt->fetch();
if (!$attachment) { http_response_code(404); exit('Attachment not found on this record.'); }
$returnUrl = '/record_attachments_manage.php?record_id=' . $recordId;
$title = record_attachment_display_title($attachment);
$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pdo = db();
    $destination = null;
    try {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) throw new RuntimeException('Enter an attachment title of 1 to 255 characters.');
        $file = validate_attachment_replacement($_FILES['attachment'] ?? null);
        $pdo->beginTransaction();
        $locked = $pdo->prepare('SELECT * FROM record_attachments WHERE id = ? AND record_id = ? FOR UPDATE');
        $locked->execute([$id, $recordId]);
        $current = $locked->fetch();
        if (!$current || !hash_equals(attachment_edit_version($current), (string) ($_POST['version'] ?? ''))) {
            throw new RuntimeException('This attachment was changed or moved. Reload the page before editing it.');
        }
        if ($file) {
            $uploadDir = dirname(__DIR__) . '/storage/attachments';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) throw new RuntimeException('The attachment storage folder is unavailable.');
            $storedName = $recordId . '_' . bin2hex(random_bytes(16)) . '.' . $file['extension'];
            $destination = $uploadDir . '/' . $storedName;
            if (!move_uploaded_file($file['tmp_name'], $destination)) throw new RuntimeException('Unable to save the replacement file.');
            $update = $pdo->prepare('UPDATE record_attachments SET title = ?, original_name = ?, stored_name = ?, mime_type = ?, file_size = ? WHERE id = ? AND record_id = ?');
            $update->execute([$title, $file['name'], $storedName, $file['mime'], $file['size'], $id, $recordId]);
        } else {
            $update = $pdo->prepare('UPDATE record_attachments SET title = ? WHERE id = ? AND record_id = ?');
            $update->execute([$title, $id, $recordId]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($destination && is_file($destination)) unlink($destination);
        error_log('Attachment edit failed: ' . $error->getMessage());
        $errorMessage = $error instanceof RuntimeException && !($error instanceof PDOException)
            ? $error->getMessage() : 'Unable to save the attachment. The previous file has been kept.';
    }
    if ($errorMessage === '') {
        // Keep the previous stored file for recovery; links retain the same attachment ID.
        audit_log('record_attachment_edit', 'Edited attachment #' . $id . ' on record #' . $recordId
            . ($file ? '. Replaced ' . $current['original_name'] . ' with ' . $file['name'] . '. Previous stored file: ' . $current['stored_name'] : '. Updated title.'), 'record', $recordId);
        flash($file ? 'Attachment file and title updated.' : 'Attachment title updated.');
        redirect($returnUrl);
    }
}
require __DIR__ . '/../app/partials/header.php';
?>
<section class="panel">
    <div class="page-head"><h1>Edit Attachment</h1><a class="btn secondary" href="<?= e(url($returnUrl)) ?>">Back to Attachments</a></div>
    <?php if ($errorMessage !== ''): ?><p role="alert"><?= e($errorMessage) ?></p><?php endif; ?>
    <?php if ($attachment['stored_name'] !== ''): ?>
        <p>Current file: <a href="<?= e(url('/record_attachment.php?id=' . $id)) ?>" target="_blank" rel="noopener"><?= e($attachment['original_name']) ?></a></p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="version" value="<?= e(attachment_edit_version($attachment)) ?>">
        <label class="full">Attachment title<input name="title" maxlength="255" value="<?= e($title) ?>" required></label>
        <label class="full">Replace file (optional)<input type="file" name="attachment" accept="application/pdf,image/jpeg,image/png,image/gif,image/webp">
            <span class="muted">Leave blank to keep the current file. PDF, JPG, PNG, GIF, or WEBP; maximum 10 MB.</span>
        </label>
        <div class="actions full"><button class="btn" type="submit">Save Attachment Changes</button><a class="btn secondary" href="<?= e(url($returnUrl)) ?>">Cancel</a></div>
    </form>
</section>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
