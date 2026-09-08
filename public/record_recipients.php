<?php

require_once __DIR__ . '/../app/auth.php';
require_login();
ensure_plenary_number_schema();

$recordId = (int) ($_GET['record_id'] ?? $_POST['record_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM records WHERE id = ?');
$stmt->execute([$recordId]);
$record = $stmt->fetch();
if (!$record) {
    http_response_code(404);
    exit('Record not found.');
}

if (!can_manage_transmittal_recipients($record)) {
    http_response_code(403);
    exit('You are not allowed to manage recipients for this record.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $recipientTitle = trim($_POST['title'] ?? '');
    $recipientName = trim($_POST['name'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');

    if ($recipientTitle === '' || $recipientName === '' || $position === '' || $address === '' || $contactNumber === '') {
        flash('Please complete the title, name, position, address, and contact number.', 'error');
        redirect('/record_recipients.php?record_id=' . $recordId);
    }

    $insert = db()->prepare('INSERT INTO record_recipients (record_id, title, name, position, address, contact_number, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([
        $recordId,
        substr($recipientTitle, 0, 80),
        substr($recipientName, 0, 180),
        substr($position, 0, 180),
        $address,
        substr($contactNumber, 0, 80),
        current_user()['id'] ?? null,
    ]);

    audit_log('record_recipient_add', 'Added recipient ' . $recipientName . ' to record ' . ($record['control_number'] ?? '') . '.', 'record', $recordId);
    flash('Recipient added successfully.');
    redirect('/record_recipients.php?record_id=' . $recordId);
}

$recipientsStmt = db()->prepare("SELECT rr.*, COALESCE(NULLIF(u.nickname, ''), u.name) created_by_name
    FROM record_recipients rr
    LEFT JOIN users u ON u.id = rr.created_by
    WHERE rr.record_id = ?
    ORDER BY rr.created_at DESC, rr.id DESC");
$recipientsStmt->execute([$recordId]);
$recipients = $recipientsStmt->fetchAll();

require __DIR__ . '/../app/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>Add Recipients</h1>
        <p class="muted">Communication Number: <?= e($record['control_number']) ?></p>
    </div>
    <div class="actions">
        <a class="btn secondary" href="<?= url('/records.php?tab=transmittals') ?>">Back to Transmittals Tab</a>
        <a class="btn secondary record-view-action" href="<?= url('/record_view.php?id=') ?><?= (int) $record['id'] ?>">View Record</a>
    </div>
</div>

<section class="panel">
    <h2>Record</h2>
    <div class="detail-list">
        <div><strong>Title</strong><br><?= e(display_record_title($record['title'])) ?></div>
        <div><strong>Client / Origin</strong><br><?= e(($record['origin'] ?? '') !== '' ? $record['origin'] : ($record['client_name'] ?? '')) ?></div>
        <?php if (trim((string) ($record['contact_number'] ?? '')) !== ''): ?>
            <div><strong>Contact Number</strong><br><?= e($record['contact_number']) ?></div>
        <?php endif; ?>
        <div><strong>Status</strong><br><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></div>
    </div>
</section>

<form method="post" class="panel form-grid" style="margin-top:16px;">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
    <label>Title
        <input name="title" required placeholder="Example: Hon., Atty., Mr., Ms.">
    </label>
    <label>Name
        <input name="name" required>
    </label>
    <label>Position
        <input name="position" required>
    </label>
    <label>Contact Number
        <input name="contact_number" required>
    </label>
    <label class="full">Address
        <textarea name="address" rows="3" required></textarea>
    </label>
    <div class="actions full">
        <button class="btn" type="submit">Save Recipient</button>
    </div>
</form>

<section class="panel" style="margin-top:16px;">
    <h2>Recipients</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Name</th>
                    <th>Position</th>
                    <th>Address</th>
                    <th>Contact Number</th>
                    <th>Added By</th>
                    <th>Date Added</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recipients as $recipient): ?>
                <tr>
                    <td><?= e($recipient['title'] ?? '') ?></td>
                    <td><?= e($recipient['name'] ?? '') ?></td>
                    <td><?= e($recipient['position'] ?? '') ?></td>
                    <td><?= nl2br(e($recipient['address'] ?? '')) ?></td>
                    <td><?= e($recipient['contact_number'] ?? '') ?></td>
                    <td><?= e($recipient['created_by_name'] ?? '') ?></td>
                    <td><?= e(display_datetime($recipient['created_at'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recipients): ?><tr><td colspan="7">No recipients added yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
