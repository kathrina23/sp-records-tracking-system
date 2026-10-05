<?php
require_once __DIR__ . '/../app/auth.php';
require_login();
if (!can_city_secretary_action()) {
    http_response_code(403);
    exit('Only the City Secretary and Administrator can edit merged records.');
}
require_once __DIR__ . '/../app/record_merges.php';
ensure_plenary_number_schema();
ensure_record_merge_schema();
$recordId = (int) ($_GET['record_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM records WHERE id = ?');
$stmt->execute([$recordId]);
$record = $stmt->fetch();
if (!$record) { http_response_code(404); exit('Record not found.'); }
$merges = active_record_merges($recordId);
$legacy = has_legacy_record_merge($record);
if (!$merges && !$legacy) { http_response_code(404); exit('This record has no active merges.'); }
$returnUrl = '/record_unmerge.php?record_id=' . $recordId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pdo = db();
    $lock = '';
    try {
        $action = (string) ($_POST['action'] ?? '');
        if (!in_array($action, ['restore', 'separate', 'titles'], true)) throw new RuntimeException('Invalid action.');
        $type = $record['document_type'];
        if ($action === 'restore') {
            $selected = array_values(array_filter($merges, fn ($merge) => (int) $merge['id'] === (int) ($_POST['merge_id'] ?? 0)));
            if (!$selected) throw new RuntimeException('This merge is no longer available.');
            $snapshot = json_decode($selected[0]['source_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            $type = $snapshot['record']['document_type'];
        }
        if ($action !== 'titles') $lock = acquire_control_number_lock($type);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM records WHERE id = ? FOR UPDATE');
        $stmt->execute([$recordId]);
        $record = $stmt->fetch();
        if (!$record) throw new RuntimeException('The record has changed. Reload and try again.');
        $newId = null;
        if ($action === 'titles') {
            $titles = $_POST['titles'] ?? [];
            if (!is_array($titles) || !$titles) throw new RuntimeException('No attachment titles were supplied.');
            $allowed = merged_attachment_ids($record);
            $update = $pdo->prepare('UPDATE record_attachments SET title = ? WHERE id = ? AND record_id = ?');
            $belongs = $pdo->prepare('SELECT id FROM record_attachments WHERE id = ? AND record_id = ? FOR UPDATE');
            foreach ($titles as $id => $value) {
                if (!in_array((int) $id, $allowed, true) || !is_string($value)) throw new RuntimeException('An attachment does not belong to this merge.');
                $value = trim($value);
                if (mb_strlen($value) > 255) throw new RuntimeException('Attachment titles must be 255 characters or shorter.');
                $belongs->execute([(int) $id, $recordId]);
                if (!$belongs->fetchColumn()) throw new RuntimeException('An attachment has moved. Reload and try again.');
                $update->execute([$value !== '' ? $value : null, (int) $id, $recordId]);
            }
            audit_log('merged_attachment_titles', 'Edited attachment titles on merged record ' . $record['control_number'] . '.', 'record', $recordId);
        } else {
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 1000) throw new RuntimeException('Enter a record title of 1 to 1,000 characters.');
            if ($action === 'restore') {
                $newId = restore_record_merge((int) $_POST['merge_id'], $recordId, $title);
            } else {
                $ids = $_POST['attachment_ids'] ?? [];
                if (!is_array($ids)) throw new RuntimeException('Select attachments to separate.');
                $newId = separate_merged_attachments($record, $ids, $title);
            }
            audit_log('record_unmerge', 'Separated record ' . $newId . ' from ' . $record['control_number'] . '.', 'record', $recordId);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Record separation failed: ' . $error->getMessage());
        flash($error instanceof RuntimeException && !($error instanceof PDOException) ? $error->getMessage() : 'Unable to save the changes. No records were separated.', 'error');
        $failed = true;
    } finally {
        if ($lock !== '') release_control_number_lock($lock);
    }
    if (!empty($failed)) redirect($returnUrl);
    flash($newId ? 'Record marked as new. Review and save its details below.' : 'Merged attachment titles updated.');
    redirect($newId ? '/record_form.php?id=' . $newId : $returnUrl);
}

$allowed = merged_attachment_ids($record);
$stmt = db()->prepare('SELECT * FROM record_attachments WHERE record_id = ? ORDER BY created_at, id');
$stmt->execute([$recordId]);
$attachments = array_values(array_filter($stmt->fetchAll(), fn ($row) => in_array((int) $row['id'], $allowed, true)));
require __DIR__ . '/../app/partials/header.php';
?>
<section class="panel">
    <div class="page-head"><h1>Edit / Unmerge Records</h1><a class="btn secondary" href="<?= e(url('/record_view.php?id=' . $recordId)) ?>">Back to Record</a></div>
    <p><strong><?= e($record['control_number']) ?></strong> — <?= e($record['title']) ?></p>
    <p>Restore a merged record as a separate record, then edit its details. Its original number is reused when available; otherwise a new number is assigned. Existing workflow status and history are preserved.</p>
    <?php foreach ($merges as $merge): $snapshot = json_decode($merge['source_snapshot'], true, 512, JSON_THROW_ON_ERROR); ?>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="restore">
            <input type="hidden" name="merge_id" value="<?= (int) $merge['id'] ?>">
            <label class="full">Merged record: <?= e($snapshot['record']['control_number']) ?>
                <input name="title" value="<?= e($snapshot['record']['title']) ?>" maxlength="1000" required>
            </label>
            <div class="actions full"><button class="btn" type="submit">Unmerge and Mark as New Record</button></div>
        </form>
    <?php endforeach; ?>
    <?php if ($legacy): ?>
        <p class="muted">Older merges did not save the original record or identify which files came from it. Select the files that belong together below to create a separate record, then review its details. Original record details cannot be restored automatically.</p>
    <?php endif; ?>
</section>
<?php if ($attachments): ?>
<section class="panel" style="margin-top:16px">
    <h2>Edit Merged Attachment Titles</h2>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="titles">
        <?php foreach ($attachments as $attachment): ?>
            <label class="full"><?= e($attachment['original_name']) ?>
                <input name="titles[<?= (int) $attachment['id'] ?>]" value="<?= e(record_attachment_display_title($attachment)) ?>" maxlength="255">
            </label>
        <?php endforeach; ?>
        <div class="actions full"><button class="btn" type="submit">Save Attachment Titles</button></div>
    </form>
</section>
<section class="panel" style="margin-top:16px">
    <h2>Unmerge Selected Attachments</h2>
    <p>Select files to move into a new record. The new record starts at its initial workflow status; the current record keeps its history.</p>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="separate">
        <label class="full">New record title<input name="title" maxlength="1000" required></label>
        <?php foreach ($attachments as $attachment): ?>
            <label class="choice-line full"><input type="checkbox" name="attachment_ids[]" value="<?= (int) $attachment['id'] ?>"><span><?= e(record_attachment_display_title($attachment)) ?></span></label>
        <?php endforeach; ?>
        <div class="actions full"><button class="btn" type="submit">Move Selected Attachments to New Record</button></div>
    </form>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
