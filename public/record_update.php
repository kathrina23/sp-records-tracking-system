<?php

require_once __DIR__ . '/../app/auth.php';
require_login();
ensure_plenary_number_schema();

$id = (int) ($_GET['id'] ?? 0);
$isPopup = ($_GET['popup'] ?? '') === '1';
$returnTarget = ($_GET['return'] ?? '') === 'dashboard' ? 'dashboard' : 'record';
$divisionTab = $_GET['division_tab'] ?? '';
$cityTab = $_GET['city_tab'] ?? '';
$closeUrl = $returnTarget === 'dashboard'
    ? '/dashboard.php' . ($cityTab !== '' ? '?city_tab=' . urlencode($cityTab) : ($divisionTab !== '' ? '?division_tab=' . urlencode($divisionTab) : ''))
    : '/record_view.php?id=' . $id;
$stmt = db()->prepare("SELECT r.*, c.name committee_name
    FROM records r
    LEFT JOIN committees c ON c.id = r.committee_id
    WHERE r.id = ?");
$stmt->execute([$id]);
$record = $stmt->fetch();

if (!$record) {
    http_response_code(404);
    exit('Record not found.');
}

if (!can_update_record_status($record)) {
    http_response_code(403);
    if ((current_user()['role'] ?? '') === 'secretariat' && ($record['document_type'] ?? '') === 'Committee Referrals' && !division_chief_first_action_done($record)) {
        exit('The Division Chief must recommend the first action before the Secretariat can update this record.');
    }

    exit('You are not allowed to update this record status.');
}

$statuses = is_administrative_document_type($record['document_type'] ?? '') ? administrative_statuses() : referral_statuses();
if (($record['document_type'] ?? '') === 'Certified Urgent') {
    $statuses = ['For Plenary Session', 'Scheduled for Plenary', 'Disapproved', 'Approved in the Plenary'];
}
if ((current_user()['role'] ?? '') === 'administrative_support') {
    $statuses = post_plenary_statuses();
    if (!record_has_plenary_approval($record)) {
        $statuses = array_values(array_filter($statuses, fn ($status) => $status !== 'For Transmittal'));
    }
}
if ((current_user()['role'] ?? '') === 'secretariat'
    && is_laws_and_rules_secretariat()
    && in_array($record['status'] ?? '', ['For Plenary Session', 'Scheduled for Plenary'], true)) {
    $statuses = ['For Plenary Session', 'Scheduled for Plenary', 'Referred Back to Committee'];
}
if (!can_manage_plenary_scheduling()) {
    $statuses = array_values(array_filter(
        $statuses,
        static fn (string $status): bool => $status !== 'Scheduled for Plenary'
    ));
}
if ($record['document_type'] === 'Committee Referrals' && in_array(current_user()['role'] ?? '', ['city_secretary', 'division_chief', 'secretariat'], true)) {
    $statuses = array_values(array_filter($statuses, fn ($status) => !in_array($status, ['Received', 'Assigned to the Committee'], true)));
}
if (($record['document_type'] ?? '') === 'Committee Referrals'
    && (current_user()['role'] ?? '') === 'division_chief'
    && !division_chief_first_action_done($record)) {
    $statuses = array_values(array_filter($statuses, fn ($status) => $status !== 'Pending to the Committee'));
}
if (($record['document_type'] ?? '') === 'Committee Referrals'
    && !in_array(current_user()['role'] ?? '', ['admin', 'city_secretary'], true)) {
    $statuses = array_values(array_filter($statuses, fn ($status) => $status !== 'Approved in the Plenary'));
}
if ((current_user()['role'] ?? '') === 'admin') {
    $statuses = all_statuses();
}
$committees = db()->query('SELECT id, name FROM committees ORDER BY name')->fetchAll();
$committeeRows = ($record['document_type'] ?? '') === 'Committee Referrals'
    ? record_committee_rows((int) $record['id'], !empty($record['committee_id']) ? (int) $record['committee_id'] : null)
    : [];
$committeeNames = $committeeRows ? implode('; ', array_map(fn ($row) => $row['committee_name'], $committeeRows)) : ($record['committee_name'] ?? 'Unassigned');
$leadCommitteeName = lead_committee_name_from_rows($committeeRows);
$recordAssignments = ($record['document_type'] ?? '') === 'Committee Referrals'
    ? record_assignment_names((int) $record['id'], !empty($record['committee_id']) ? (int) $record['committee_id'] : null)
    : ['secretariats' => []];
$assignedSecretariatNames = implode('; ', $recordAssignments['secretariats'] ?? []);
require __DIR__ . '/../app/partials/header.php';
?>
<?php if ($isPopup): ?><div class="modal-backdrop" role="presentation"><?php endif; ?>
<section class="<?= $isPopup ? 'panel user-edit-modal' : '' ?>" <?= $isPopup ? 'role="dialog" aria-modal="true" aria-labelledby="status_update_title"' : '' ?>>
<div class="page-head">
    <div>
        <h1 id="status_update_title"><?= (($record['document_type'] ?? '') === 'Committee Referrals' && (current_user()['role'] ?? '') === 'division_chief' && !division_chief_first_action_done($record)) ? 'Recommend First Action' : 'Update Status' ?></h1>
        <p class="muted page-record-title"><?= e($record['control_number']) ?> - <?= e(display_record_title(record_title_for_current_user($record))) ?></p>
    </div>
    <?php if ($isPopup): ?>
        <a class="modal-close" href="<?= e($closeUrl) ?>" aria-label="Close update window">X</a>
    <?php else: ?>
        <a class="btn secondary" href="<?= url('/record_view.php?id=') ?><?= (int) $record['id'] ?>">Track</a>
    <?php endif; ?>
</div>

<section class="panel">
    <h2>Record Summary</h2>
    <div class="detail-list">
        <div><strong>Current Status</strong><br><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></div>
        <?php if (($record['document_type'] ?? '') === 'Committee Referrals'): ?>
            <div><strong>Committee</strong><br><?= e($committeeNames) ?></div>
        <?php endif; ?>
        <?php if ($leadCommitteeName !== ''): ?>
            <div><strong>Lead Committee</strong><br><?= e($leadCommitteeName) ?></div>
        <?php endif; ?>
        <?php if (($record['document_type'] ?? '') === 'Committee Referrals'): ?>
            <div class="detail-highlight"><strong>Assigned Secretariat</strong><br><?= e($assignedSecretariatNames !== '' ? $assignedSecretariatNames : 'Unassigned') ?></div>
        <?php endif; ?>
        <div><strong>Type</strong><br><?= e($record['document_type']) ?></div>
        <div><strong>Client/Origin</strong><br><?= e($record['document_type'] === 'Committee Referrals' ? ($record['client_name'] ?? '') : $record['origin']) ?></div>
    </div>
</section>

<section class="panel" style="margin-top:16px;">
    <h2><?= (($record['document_type'] ?? '') === 'Committee Referrals' && (current_user()['role'] ?? '') === 'division_chief' && !division_chief_first_action_done($record)) ? 'Division Chief: First Action' : 'Status Update' ?></h2>
    <form method="post" action="<?= url('/status_update.php') ?>" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
        <input type="hidden" name="return" value="<?= e($returnTarget) ?>">
        <input type="hidden" name="division_tab" value="<?= e($divisionTab) ?>">
        <input type="hidden" name="city_tab" value="<?= e($cityTab) ?>">
        <?php if (in_array($record['document_type'] ?? '', ['Committee Referrals', 'Certified Urgent'], true)
            && in_array(current_user()['role'] ?? '', ['admin', 'city_secretary', 'secretariat'], true)): ?>
            <label class="full">Record Title
                <textarea name="report_title" rows="4" maxlength="1000" required><?= e($record['title']) ?></textarea>
                <span class="muted">Title edits become the current record title. Every update keeps its own title in the tracking history, and the title saved with plenary approval becomes the final title.</span>
            </label>
        <?php endif; ?>
        <label>Status
            <select name="status" id="status_select" required>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= $record['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="status-extra" data-status="For Meeting">Date of Meeting
            <input type="date" name="meeting_date">
        </label>
        <label class="status-extra" data-status="For Inspection">Date of Inspection
            <input type="date" name="inspection_date">
        </label>
        <label class="status-extra" data-status="Recommending Approval">Recommended Committee
            <select name="recommended_committee_id">
                <option value="">Select committee</option>
                <?php foreach ($committees as $committee): ?>
                    <option value="<?= (int) $committee['id'] ?>"><?= e($committee['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="status-extra" data-status="Recommending Approval">Date
            <input type="date" name="recommending_approval_date">
        </label>
        <label class="status-extra" data-status="Deferred">Date Deferred
            <input type="date" name="deferred_date">
        </label>
        <label class="status-extra" data-status="Tabled">Date Tabled
            <input type="date" name="tabled_date">
        </label>
        <label class="status-extra" data-status="Noted">Date Noted
            <input type="date" name="noted_date">
        </label>
        <label class="status-extra" data-status="Referred To">Office / Organization / Individual
            <input name="referred_to" placeholder="Name of office, person, or organization">
        </label>
        <label class="status-extra" data-status="Referred Back to Committee">Committee
            <select name="referred_back_committee_id">
                <option value="">Select committee</option>
                <?php foreach ($committees as $committee): ?>
                    <option value="<?= (int) $committee['id'] ?>"><?= e($committee['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="status-extra" data-status="Endorsement">Date
            <input type="date" name="endorsement_date">
        </label>
        <label class="status-extra" data-status="Scheduled for Plenary">Plenary / Session Date
            <input type="date" name="regular_session_date" value="<?= e($record['plenary_session_date'] ?? '') ?>">
        </label>
        <label class="status-extra" data-status="Disapproved">Date of Session
            <input type="date" name="disapproved_session_date">
        </label>
        <label class="status-extra" data-status="Approved in the Plenary">Type
            <select name="approved_plenary_type">
                <option value="">Select type</option>
                <option value="ordinance">Ordinance</option>
                <option value="resolution">Resolution</option>
            </select>
        </label>
        <label class="status-extra" data-status="Approved in the Plenary">Ordinance / Resolution Number
            <input name="approved_plenary_number" placeholder="Example: 232">
        </label>
        <label class="status-extra" data-status="Approved in the Plenary">Date Approved
            <input type="date" name="approved_plenary_date">
        </label>
        <label class="status-extra" data-status="Others">Details
            <input name="other_status_detail" placeholder="Specify the status detail">
        </label>
        <label class="full">Remarks <span class="muted">(Optional)</span>
            <textarea name="notes" placeholder="Add remarks, action taken, or next step if needed."></textarea>
        </label>
        <div class="actions full">
            <button class="btn" type="submit">Save Status Update</button>
            <a class="btn secondary" href="<?= e($isPopup ? $closeUrl : '/records.php') ?>">Cancel</a>
        </div>
    </form>
</section>
</section>
<?php if ($isPopup): ?></div><?php endif; ?>
<script>
const statusSelect = document.getElementById('status_select');
const statusExtras = document.querySelectorAll('.status-extra');
const syncStatusExtras = () => {
    statusExtras.forEach((field) => {
        const active = field.dataset.status === statusSelect.value;
        field.style.display = active ? 'grid' : 'none';
        field.querySelectorAll('input, select').forEach((input) => {
            input.required = active;
            if (!active) {
                input.value = '';
            }
        });
    });
};
statusSelect.addEventListener('change', syncStatusExtras);
syncStatusExtras();
</script>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
