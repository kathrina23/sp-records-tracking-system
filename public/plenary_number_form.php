<?php

require_once __DIR__ . '/../app/auth.php';
require_login();
ensure_plenary_number_schema();

$id = (int) ($_GET['id'] ?? 0);
$isPopup = ($_GET['popup'] ?? '') === '1';
$returnTarget = ($_GET['return'] ?? '') === 'dashboard' ? 'dashboard' : 'record';
$cityTab = $_GET['city_tab'] ?? '';
$closeUrl = $returnTarget === 'dashboard'
    ? '/dashboard.php?city_tab=' . urlencode($cityTab !== '' ? $cityTab : 'for-plenary')
    : '/record_view.php?id=' . $id;

$stmt = db()->prepare('SELECT * FROM records WHERE id = ?');
$stmt->execute([$id]);
$record = $stmt->fetch();

if (!$record) {
    http_response_code(404);
    exit('Record not found.');
}

$canAssignProposedNumber = can_assign_plenary_numbers($record);

if (!$canAssignProposedNumber) {
    http_response_code(403);
    exit('Only the Laws and Rules Secretariat, City Secretary, or Administrator can assign proposed numbers to For Plenary records.');
}

$selectedProposedType = ($record['proposed_resolution_number'] ?? '') !== '' ? 'resolution' : 'ordinance';
$selectedProposedNumber = $selectedProposedType === 'resolution'
    ? (string) ($record['proposed_resolution_number'] ?? '')
    : (string) ($record['proposed_ordinance_number'] ?? '');
$selectedProposedNumber = preg_replace('/^\d{4}-/', '', $selectedProposedNumber) ?: $selectedProposedNumber;

require __DIR__ . '/../app/partials/header.php';
?>
<?php if ($isPopup): ?><div class="modal-backdrop" role="presentation"><?php endif; ?>
<section class="<?= $isPopup ? 'panel user-edit-modal' : 'panel' ?>" <?= $isPopup ? 'role="dialog" aria-modal="true" aria-labelledby="plenary_number_title"' : '' ?>>
    <div class="page-head">
        <div>
            <h1 id="plenary_number_title">Assign Proposed No.</h1>
            <p class="muted page-record-title"><?= e($record['control_number']) ?> - <?= e(display_record_title(record_title_for_current_user($record))) ?></p>
        </div>
        <?php if ($isPopup): ?>
            <a class="modal-close" href="<?= e(url($closeUrl)) ?>" aria-label="Close proposed number window">X</a>
        <?php endif; ?>
    </div>
    <form method="post" action="<?= url('/record_workflow.php') ?>" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
        <input type="hidden" name="action" value="update_plenary_numbers">
        <input type="hidden" name="return" value="<?= e($returnTarget) ?>">
        <input type="hidden" name="city_tab" value="<?= e($cityTab) ?>">
        <label>Proposed Type
            <select name="proposed_type" required>
                <option value="ordinance" <?= $selectedProposedType === 'ordinance' ? 'selected' : '' ?>>Proposed Ordinance</option>
                <option value="resolution" <?= $selectedProposedType === 'resolution' ? 'selected' : '' ?>>Proposed Resolution</option>
            </select>
        </label>
        <label>Proposed Number
            <input name="proposed_number" value="<?= e($selectedProposedNumber) ?>" placeholder="Example: 001" required>
            <span class="muted">The system will save this as <?= e(date('Y')) ?>-[number].</span>
        </label>
        <label class="full">Remarks
            <textarea name="remarks" placeholder="Add remarks for this update."></textarea>
        </label>
        <div class="actions full">
            <button class="btn" type="submit">Save Proposed No.</button>
            <a class="btn secondary" href="<?= e(url($closeUrl)) ?>">Cancel</a>
        </div>
    </form>
</section>
<?php if ($isPopup): ?></div><?php endif; ?>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
