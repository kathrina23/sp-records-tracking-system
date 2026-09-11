<?php

require_once __DIR__ . '/../app/auth.php';
require_login();
ensure_plenary_number_schema();

$movementId = (int) ($_GET['movement_id'] ?? $_POST['movement_id'] ?? 0);
$returnTarget = ($_GET['return'] ?? $_POST['return'] ?? '') === 'dashboard' ? 'dashboard' : 'record';
$staffUpdatesPage = max(1, (int) ($_GET['staff_updates_page'] ?? $_POST['staff_updates_page'] ?? 1));
$backUrl = $returnTarget === 'dashboard'
    ? '/dashboard.php?staff_updates_page=' . $staffUpdatesPage
    : '/record_view.php?id=0';

$stmt = db()->prepare("SELECT m.id movement_id, m.record_id movement_record_id, m.from_status, m.to_status, m.from_location, m.to_location,
        m.notes movement_notes, m.report_title, m.report_remarks, m.chief_remarks, m.updated_by movement_updated_by, m.created_at movement_created_at,
        COALESCE(NULLIF(u.nickname, ''), u.name) updated_by_name, u.role updated_by_role,
        r.*, r.id record_id, c.name committee_name
    FROM record_movements m
    INNER JOIN records r ON r.id = m.record_id
    LEFT JOIN committees c ON c.id = r.committee_id
    LEFT JOIN users u ON u.id = m.updated_by
    WHERE m.id = ?");
$stmt->execute([$movementId]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    exit('Update not found.');
}

$record = [
    'id' => (int) $row['record_id'],
    'document_type' => $row['document_type'],
    'committee_id' => $row['committee_id'],
    'control_number' => $row['control_number'],
    'title' => $row['title'],
];
$movement = [
    'id' => (int) $row['movement_id'],
    'movement_id' => (int) $row['movement_id'],
    'updated_by' => $row['movement_updated_by'],
    'updated_by_role' => $row['updated_by_role'],
    'notes' => $row['movement_notes'],
];

if (!can_edit_secretariat_update($record, $movement)) {
    http_response_code(403);
    exit('You are not allowed to edit this update.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $chiefRemarks = trim($_POST['chief_remarks'] ?? '');

    $update = db()->prepare('UPDATE record_movements SET chief_remarks = ?, chief_remarks_updated_at = ? WHERE id = ?');
    $update->execute([
        $chiefRemarks !== '' ? $chiefRemarks : null,
        $chiefRemarks !== '' ? date('Y-m-d H:i:s') : null,
        $movementId,
    ]);

    audit_log('division_chief_update_instruction', 'Added Division Chief remarks for update #' . $movementId . '.', 'record', (int) $row['record_id']);
    flash('Division Chief remarks saved for the Secretariat.');
    redirect($returnTarget === 'dashboard' ? '/dashboard.php?staff_updates_page=' . $staffUpdatesPage : '/record_view.php?id=' . (int) $row['record_id']);
}

$chiefRemarksValue = (string) ($row['chief_remarks'] ?? '');
$backUrl = $returnTarget === 'dashboard'
    ? '/dashboard.php?staff_updates_page=' . $staffUpdatesPage
    : '/record_view.php?id=' . (int) $row['record_id'];
$isDashboardPopup = $returnTarget === 'dashboard';
$updatedByName = (string) ($row['updated_by_name'] ?? 'Secretariat');

require __DIR__ . '/../app/partials/header.php';
?>
<?php if ($isDashboardPopup): ?><div class="modal-backdrop" role="presentation"><?php endif; ?>
<section class="panel <?= $isDashboardPopup ? 'user-edit-modal' : '' ?>" <?= $isDashboardPopup ? 'role="dialog" aria-modal="true" aria-labelledby="remarks_title"' : '' ?>>
<div class="page-head">
    <div>
        <h1 id="remarks_title">Remarks</h1>
        <p class="muted"><?= e($row['control_number']) ?> - <?= e($updatedByName) ?> - <?= e(display_datetime($row['movement_created_at'] ?? '')) ?></p>
    </div>
    <?php if ($isDashboardPopup): ?>
        <a class="modal-close" href="<?= e(url($backUrl)) ?>" aria-label="Close remarks window">X</a>
    <?php else: ?>
        <a class="btn secondary" href="<?= e(url($backUrl)) ?>">Back to Record</a>
    <?php endif; ?>
</div>

    <h2>Instructions for <?= e($updatedByName) ?></h2>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="movement_id" value="<?= (int) $movementId ?>">
        <input type="hidden" name="return" value="<?= e($returnTarget) ?>">
        <input type="hidden" name="staff_updates_page" value="<?= (int) $staffUpdatesPage ?>">
        <div class="full">
            <textarea name="chief_remarks" rows="6" aria-label="Remarks" placeholder="Internal instruction or clarification. This will not appear on the printed referral."><?= e($chiefRemarksValue) ?></textarea>
            <span class="muted">These remarks are internal only and will not be reflected on the Committee Referral printout.</span>
        </div>
        <div class="actions full">
            <button class="btn" type="submit">Save Remarks</button>
            <?php if (!$isDashboardPopup): ?>
            <a class="btn secondary" href="<?= url('/committee_referral_print.php?id=') ?><?= (int) $row['record_id'] ?>&movement_id=<?= (int) $movementId ?>">Print Referral</a>
            <?php endif; ?>
        </div>
    </form>
</section>
<?php if ($isDashboardPopup): ?></div><?php endif; ?>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
