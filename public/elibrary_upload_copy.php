<?php
require_once __DIR__ . '/../app/elibrary.php';
require_elibrary_staff();
$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM legislation_drafts WHERE id=? AND record_id IS NULL');
$stmt->execute([$id]); $draft = $stmt->fetch();
if (!$draft) { http_response_code(404); exit('Legislation draft not found.'); }
$error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf(); $file = null;
    try {
        if ((int) ($_POST['revision'] ?? 0) !== (int) $draft['revision']) { throw new InvalidArgumentException('This entry changed. Reload before uploading.'); }
        $upload = $_FILES['signed_file'] ?? [];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']) !== 'application/pdf') { throw new InvalidArgumentException('Select a signed PDF copy up to 10 MB.'); }
        $file = legislation_upload($upload);
        db()->beginTransaction();
        $save = db()->prepare('UPDATE legislation_drafts SET original_name=?,stored_name=?,mime_type=?,file_size=?,updated_by=?,revision=revision+1 WHERE id=? AND revision=?');
        $save->execute([$file['original_name'], $file['stored_name'], $file['mime_type'], $file['file_size'], current_user()['id'], $id, $draft['revision']]);
        if ($save->rowCount() !== 1) { throw new InvalidArgumentException('This entry changed. Reload before uploading.'); }
        audit_log('elibrary_historical_copy', 'Uploaded PDF for ' . $draft['kind'] . ' ' . $draft['number'] . '.', 'legislation_draft', $id);
        db()->commit();
        $draft = array_replace($draft, $file); $draft['revision']++;
        $success = 'PDF saved. Review and confirm posting to make this copy public.';
    } catch (Throwable $exception) {
        if (db()->inTransaction()) { db()->rollBack(); }
        if ($file) { unlink(dirname(__DIR__) . '/storage/elibrary/' . $file['stored_name']); }
        $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Unable to save the PDF copy.';
    }
}
require __DIR__ . '/../app/partials/header.php';
?>
<section class="panel"><h1>Upload PDF Copy</h1><h2><?= e(ucfirst($draft['kind']) . ' ' . $draft['number']) ?></h2><p><?= e($draft['title']) ?></p>
<?php if ($error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="flash success" role="status"><?= e($success) ?></div><?php endif; ?>
<?php if ($draft['stored_name']): ?><p>Current copy: <a href="<?= url('/legislation_file.php?draft=1&id=') . $id ?>" target="_blank" rel="noopener"><?= e($draft['original_name']) ?></a></p><?php endif; ?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="revision" value="<?= (int) $draft['revision'] ?>"><label>Signed PDF copy (up to 10 MB)<input type="file" name="signed_file" accept="application/pdf,.pdf" required></label><div class="actions"><button class="btn">Save PDF Copy</button><a href="<?= url('/e-library_old.php?edit=') . $id ?>">Edit Details</a><a href="<?= url('/elibrary_review.php?id=') . $id ?>">Review and Post</a><a href="<?= url('/e-library_old.php') ?>">Back to Legislation Entries</a></div></form></section>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
