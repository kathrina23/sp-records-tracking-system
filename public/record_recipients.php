<?php
require_once __DIR__ . '/../app/auth.php';
require_login();
ensure_plenary_number_schema();

$recordId = (int) ($_GET['record_id'] ?? $_POST['record_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM records WHERE id = ?');
$stmt->execute([$recordId]);
$record = $stmt->fetch();
if (!$record) { http_response_code(404); exit('Record not found.'); }
if (!can_manage_transmittal_recipients($record)) {
    http_response_code(403); exit('You are not allowed to manage recipients for this record.');
}
$rows = [['name' => '', 'position' => '', 'address' => '']];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'delete_recipient') {
        $recipientId = (int) ($_POST['recipient_id'] ?? 0);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT * FROM records WHERE id = ? FOR UPDATE');
            $lock->execute([$recordId]);
            $currentRecord = $lock->fetch();
            if (!$currentRecord || !can_manage_transmittal_recipients($currentRecord)) {
                $pdo->rollBack();
                flash('This record is no longer available for recipient changes.', 'error');
                redirect('/messengerial.php');
            }
            $delete = $pdo->prepare('DELETE FROM record_recipients WHERE id = ? AND record_id = ?');
            $delete->execute([$recipientId, $recordId]);
            $deleted = $delete->rowCount() > 0;
            if ($deleted) {
                audit_log('record_recipient_delete', 'Deleted recipient #' . $recipientId . ' from record ' . $record['control_number'] . '.', 'record', $recordId);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $exception;
        }
        flash($deleted ? 'Recipient deleted successfully.' : 'Recipient not found or already deleted.', $deleted ? 'success' : 'error');
        redirect('/record_recipients.php?record_id=' . $recordId);
    }
    $submitted = $_POST['recipients'] ?? [];
    $rows = [];
    if (!is_array($submitted) || count($submitted) < 1 || count($submitted) > 50) {
        $error = 'Please add between 1 and 50 recipients at a time.';
    } else {
        foreach ($submitted as $row) {
            $clean = [];
            foreach (['name', 'position', 'address'] as $field) {
                $clean[$field] = is_array($row) && is_string($row[$field] ?? null) ? trim($row[$field]) : '';
            }
            $rows[] = $clean;
            if (in_array('', $clean, true)) {
                $error = 'Please complete the name, position, and address for every recipient.';
            } elseif (strlen($clean['name']) > 180 || strlen($clean['position']) > 180 || strlen($clean['address']) > 5000) {
                $error = 'Names and positions must be at most 180 bytes, and addresses at most 5,000 bytes.';
            }
        }
    }
    if ($error === '') {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT * FROM records WHERE id = ? FOR UPDATE');
            $lock->execute([$recordId]);
            $currentRecord = $lock->fetch();
            if (!$currentRecord || !can_manage_transmittal_recipients($currentRecord)) {
                $pdo->rollBack();
                flash('This record is no longer available for recipient changes.', 'error');
                redirect('/messengerial.php');
            }
            $insert = $pdo->prepare('INSERT INTO record_recipients (record_id, name, position, address, created_by) VALUES (?, ?, ?, ?, ?)');
            foreach ($rows as $row) {
                $insert->execute([$recordId, $row['name'], $row['position'], $row['address'], current_user()['id']]);
            }
            audit_log('record_recipient_add', 'Added ' . count($rows) . ' recipients to record ' . $record['control_number'] . '.', 'record', $recordId);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $exception;
        }
        flash(count($rows) . ' recipient(s) saved. You can now print their transmittals.');
        redirect('/record_recipients.php?record_id=' . $recordId);
    }
}
if (!$rows) { $rows = [['name' => '', 'position' => '', 'address' => '']]; }
$recipientsStmt = db()->prepare('SELECT * FROM record_recipients WHERE record_id = ? ORDER BY id ASC');
$recipientsStmt->execute([$recordId]);
$recipients = $recipientsStmt->fetchAll();
require __DIR__ . '/../app/partials/header.php';
?>
<div class="page-head">
    <div><h1>Transmittal Recipients</h1><p class="muted"><?= e($record['control_number']) ?> &middot; <?= e($record['status']) ?></p></div>
    <div class="actions">
        <a class="btn secondary" href="<?= url('/record_view.php?id=') ?><?= $recordId ?>">View Record</a>

    </div>
</div>
<section class="panel"><h2><?= e(display_record_title($record['title'])) ?></h2><p>Add each recipient's name, position, and address. Each recipient receives a separate transmittal and receipt sheet.</p></section>
<?php if ($error !== ''): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="panel" style="margin-top:16px">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="record_id" value="<?= $recordId ?>">
    <div id="recipient-rows">
        <?php foreach ($rows as $index => $row): ?>
        <fieldset class="recipient-entry form-grid">
            <legend>Recipient</legend>
            <label>Name<input name="recipients[<?= $index ?>][name]" maxlength="180" required value="<?= e($row['name']) ?>" placeholder="Include title, if applicable"></label>
            <label>Position<input name="recipients[<?= $index ?>][position]" maxlength="180" required value="<?= e($row['position']) ?>"></label>
            <label class="full">Address<textarea name="recipients[<?= $index ?>][address]" maxlength="5000" rows="3" required><?= e($row['address']) ?></textarea></label>
            <div class="recipient-entry-actions full"><button type="button" class="btn secondary remove-recipient">Remove Recipient</button></div>
        </fieldset>
        <?php endforeach; ?>
    </div>
    <div class="actions recipient-form-actions"><button type="button" class="btn secondary" id="add-recipient">Add Another Recipient</button><button type="submit" class="btn">Save Recipients</button></div>
</form>
<section class="panel" style="margin-top:16px">
    <div class="panel-title-row" style="flex-wrap:wrap;gap:12px"><h2>Saved Recipients (<?= count($recipients) ?>)</h2><?php if ($recipients): ?><a class="btn" target="_blank" rel="noopener" href="<?= url('/transmittal_print.php?record_id=') ?><?= $recordId ?>">Print Transmittal</a><?php endif; ?></div><p class="muted">Print Transmittal includes all saved recipients in one continuous Folio (8.5 &times; 13 inches) document.</p>
    <div class="table-wrap"><table><thead><tr><th>Name</th><th>Position</th><th>Address</th><th>Action</th></tr></thead><tbody>
    <?php foreach ($recipients as $recipient): ?>
        <tr><td><?= e(trim(($recipient['title'] ?? '') . ' ' . $recipient['name'])) ?></td><td><?= e($recipient['position']) ?></td><td><?= nl2br(e($recipient['address'])) ?></td>
        <td>
            <form method="post" class="recipient-delete-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="record_id" value="<?= $recordId ?>">
                <input type="hidden" name="action" value="delete_recipient">
                <input type="hidden" name="recipient_id" value="<?= (int) $recipient['id'] ?>">
                <button type="submit" class="recipient-delete-link" aria-label="Delete recipient <?= e($recipient['name']) ?>">Delete</button>
            </form>
        </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$recipients): ?><tr><td colspan="4">No recipients added yet.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<script>
(() => {
    const rows = document.getElementById('recipient-rows');
    const add = document.getElementById('add-recipient');
    const formActions = add.closest('.recipient-form-actions');
    let nextIndex = <?= count($rows) ?>;
    const update = () => {
        const entries = rows.querySelectorAll('.recipient-entry');
        entries.forEach((entry, index) => {
            entry.querySelector('legend').textContent = 'Recipient ' + (index + 1);
            entry.querySelector('.remove-recipient').disabled = entries.length === 1;
        });
        entries[entries.length - 1].querySelector('.recipient-entry-actions').append(formActions);
        add.disabled = entries.length >= 50;
    };
    add.addEventListener('click', () => {
        if (rows.children.length >= 50) return;
        const entry = rows.firstElementChild.cloneNode(true);
        entry.querySelector('.recipient-form-actions')?.remove();
        entry.querySelectorAll('input, textarea').forEach(field => {
            field.name = field.name.replace(/recipients\[\d+\]/, 'recipients[' + nextIndex + ']');
            field.value = '';
        });
        nextIndex++;
        rows.append(entry);
        update();
        entry.querySelector('input').focus();
    });
    rows.addEventListener('click', event => {
        if (event.target.closest('.remove-recipient') && rows.children.length > 1) {
            event.target.closest('.recipient-entry').remove();
            update();
            add.focus();
        }
    });
    update();
})();
</script>
<?php if ($recipients && can_complete_transmittals($record)): ?>
<section class="panel">
    <h2>Complete Transmittals</h2>
    <form method="post" action="<?= url('/transmittal_complete.php') ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="record_id" value="<?= $recordId ?>">
        <input type="hidden" name="recipient_snapshot" value="<?= e(hash('sha256', implode(',', array_column($recipients, 'id')))) ?>">
        <label class="inline-check"><input type="checkbox" name="all_printed" value="1" required aria-describedby="completion-note"> Check to complete</label>
        <p class="muted" id="completion-note">Check after all transmittals for the <?= count($recipients) ?> saved recipients have been printed. Completing this step forwards the record to Messengerial Services.</p>
        <button class="btn" type="submit">Mark as Complete</button>
    </form>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>