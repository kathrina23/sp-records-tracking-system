<?php

require_once __DIR__ . '/../app/auth.php';
require_login();
ensure_plenary_number_schema();

$id = (int) ($_GET['id'] ?? 0);
$isPopup = ($_GET['popup'] ?? '') === '1';
$returnTarget = in_array($_GET['return'] ?? '', ['dashboard', 'records', 'recipients'], true)
    ? (string) $_GET['return']
    : 'record';
$recordsReturnUrl = (string) ($_GET['return_url'] ?? url('/records.php'));
$recordsReturnParts = parse_url($recordsReturnUrl);
if (
    $recordsReturnUrl === ''
    || $recordsReturnParts === false
    || isset($recordsReturnParts['scheme'])
    || isset($recordsReturnParts['host'])
    || ($recordsReturnParts['path'] ?? '') !== url('/records.php')
) {
    $recordsReturnUrl = url('/records.php');
}
$divisionTab = $_GET['division_tab'] ?? '';
$staffUpdatesPage = max(1, (int) ($_GET['staff_updates_page'] ?? 1));
$staffUpdateSearch = trim($_GET['staff_update_search'] ?? '');
$staffUpdateDateFrom = trim($_GET['staff_update_date_from'] ?? '');
$staffUpdateDateTo = trim($_GET['staff_update_date_to'] ?? '');
$dashboardReturnParams = [];
if ($divisionTab !== '') {
    $dashboardReturnParams['division_tab'] = $divisionTab;
}
if ($divisionTab === 'staff-updates') {
    $dashboardReturnParams['staff_updates_page'] = $staffUpdatesPage;
    if ($staffUpdateSearch !== '') {
        $dashboardReturnParams['staff_update_search'] = $staffUpdateSearch;
    }
    if ($staffUpdateDateFrom !== '') {
        $dashboardReturnParams['staff_update_date_from'] = $staffUpdateDateFrom;
    }
    if ($staffUpdateDateTo !== '') {
        $dashboardReturnParams['staff_update_date_to'] = $staffUpdateDateTo;
    }
}
$dashboardReturnUrl = url('/dashboard.php') . ($dashboardReturnParams ? '?' . http_build_query($dashboardReturnParams) : '');
if ($returnTarget === 'dashboard') {
    $requestedDashboardReturnUrl = (string) ($_GET['return_url'] ?? $dashboardReturnUrl);
    $requestedDashboardReturnParts = parse_url($requestedDashboardReturnUrl);
    if (
        $requestedDashboardReturnUrl !== ''
        && $requestedDashboardReturnParts !== false
        && !isset($requestedDashboardReturnParts['scheme'])
        && !isset($requestedDashboardReturnParts['host'])
        && ($requestedDashboardReturnParts['path'] ?? '') === url('/dashboard.php')
    ) {
        $dashboardReturnUrl = $requestedDashboardReturnUrl;
    }
}
$recipientsReturnUrl = url('/record_recipients.php?record_id=' . $id);
if ($returnTarget === 'recipients') {
    $requestedRecipientsReturnUrl = (string) ($_GET['return_url'] ?? $recipientsReturnUrl);
    $requestedRecipientsReturnParts = parse_url($requestedRecipientsReturnUrl);
    if (
        $requestedRecipientsReturnUrl !== ''
        && $requestedRecipientsReturnParts !== false
        && !isset($requestedRecipientsReturnParts['scheme'])
        && !isset($requestedRecipientsReturnParts['host'])
        && ($requestedRecipientsReturnParts['path'] ?? '') === url('/record_recipients.php')
    ) {
        $recipientsReturnUrl = $requestedRecipientsReturnUrl;
    }
}
$closeUrl = match ($returnTarget) {
    'dashboard' => $dashboardReturnUrl,
    'records' => $recordsReturnUrl,
    'recipients' => $recipientsReturnUrl,
    default => url('/records.php'),
};
$attachmentCloseParams = ['id' => $id];
if ($isPopup) {
    $attachmentCloseParams['popup'] = 1;
    $attachmentCloseParams['return'] = $returnTarget;
    if (in_array($returnTarget, ['records', 'dashboard', 'recipients'], true)) {
        $attachmentCloseParams['return_url'] = $closeUrl;
    }
}
$attachmentCloseUrl = url('/record_view.php?' . http_build_query($attachmentCloseParams));
$recordEditUrl = url('/record_form.php?id=' . $id);
if ($isPopup) {
    $recordEditUrl = url('/record_form.php?' . http_build_query([
        'id' => $id,
        'popup' => 1,
        'return' => 'record',
        'return_url' => $attachmentCloseUrl,
    ]));
}
$attachmentViewUrl = url('/record_attachments_view.php?' . http_build_query([
    'record_id' => $id,
    'popup' => 1,
    'return' => 'record',
    'return_url' => $attachmentCloseUrl,
]));
$attachmentManageParams = [
    'record_id' => $id,
    'return_url' => $attachmentCloseUrl,
];
if ($isPopup) {
    $attachmentManageParams['popup'] = 1;
}
$attachmentManageUrl = url('/record_attachments_manage.php?' . http_build_query($attachmentManageParams));
$stmt = db()->prepare("SELECT r.*, c.name committee_name, COALESCE(NULLIF(u.division_name, ''), u.name) assigned_division, creator.name created_by_name, clerk.name receiving_clerk_name
    FROM records r
    LEFT JOIN committees c ON c.id = r.committee_id
    LEFT JOIN users u ON u.id = r.assigned_user_id
    LEFT JOIN users creator ON creator.id = r.created_by
    LEFT JOIN users clerk ON clerk.id = r.receiving_clerk_id
    WHERE r.id = ?");
$stmt->execute([$id]);
$record = $stmt->fetch();
if (!$record) {
    http_response_code(404);
    exit('Record not found.');
}

$role = current_user()['role'] ?? '';
if (!can_view_record($record)) {
    http_response_code(403);
    exit('You are not allowed to view this record.');
}

$history = db()->prepare("SELECT m.*, COALESCE(NULLIF(u.nickname, ''), u.name) updated_by_name, u.role updated_by_role FROM record_movements m LEFT JOIN users u ON u.id = m.updated_by WHERE m.record_id = ? ORDER BY m.created_at DESC");
$history->execute([$id]);
$movements = $history->fetchAll();
$movements = array_values(array_filter($movements, function ($movement) {
    $notes = (string) ($movement['notes'] ?? '');
    if (strpos($notes, 'Committee Referral print') === 0 && ($movement['updated_by_role'] ?? '') !== 'receiving_clerk') {
        return false;
    }

    return true;
}));
$receiptHistoryStmt = db()->prepare("SELECT rr.id receipt_id, rr.division_name, rr.received_at,
        COALESCE(NULLIF(u.nickname, ''), u.name) received_by_name, u.role received_by_role
    FROM record_division_receipts rr
    LEFT JOIN users u ON u.id = rr.received_by
    WHERE rr.record_id = ?
    ORDER BY rr.received_at DESC, rr.id DESC");
$receiptHistoryStmt->execute([$id]);
foreach ($receiptHistoryStmt->fetchAll() as $receiptHistory) {
    $movements[] = [
        'id' => 0,
        'from_status' => $record['status'],
        'to_status' => 'Physical Copy Received',
        'from_location' => $record['current_location'],
        'to_location' => $record['current_location'],
        'notes' => 'Physical copy of the record attachments received by Division Staff for ' . $receiptHistory['division_name'] . '.',
        'report_title' => null,
        'record_title' => null,
        'previous_title' => null,
        'report_remarks' => null,
        'chief_remarks' => null,
        'chief_remarks_updated_at' => null,
        'updated_by_name' => $receiptHistory['received_by_name'] ?: 'Division Staff',
        'updated_by_role' => $receiptHistory['received_by_role'] ?: 'division_staff',
        'created_at' => $receiptHistory['received_at'],
        'is_division_receipt' => 1,
        'receipt_id' => (int) $receiptHistory['receipt_id'],
    ];
}
usort($movements, function (array $left, array $right): int {
    $dateComparison = strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
    if ($dateComparison !== 0) {
        return $dateComparison;
    }

    $leftOrder = !empty($left['is_division_receipt']) ? (int) ($left['receipt_id'] ?? 0) : (int) ($left['id'] ?? 0);
    $rightOrder = !empty($right['is_division_receipt']) ? (int) ($right['receipt_id'] ?? 0) : (int) ($right['id'] ?? 0);
    return $rightOrder <=> $leftOrder;
});
$originalRecordTitle = '';
foreach (array_reverse($movements) as $movementCandidate) {
    if ((int) ($movementCandidate['id'] ?? 0) <= 0) {
        continue;
    }
    $candidateTitle = trim((string) ($movementCandidate['record_title'] ?? ''));
    if ($candidateTitle === '') {
        $candidateTitle = trim((string) ($movementCandidate['report_title'] ?? ''));
    }
    if ($candidateTitle !== '') {
        $originalRecordTitle = $candidateTitle;
        break;
    }
}
$printedStmt = db()->prepare("SELECT COUNT(*) FROM record_movements WHERE record_id = ? AND notes LIKE 'Committee Referral printed%'");
$printedStmt->execute([$id]);
$committeeReferralPrinted = (int) $printedStmt->fetchColumn() > 0;
$statuses = is_administrative_document_type($record['document_type'] ?? '') ? administrative_statuses() : referral_statuses();
$committees = db()->query('SELECT id, name FROM committees ORDER BY name')->fetchAll();
$committeeRows = $record['document_type'] === 'Committee Referrals'
    ? record_committee_rows((int) $record['id'], !empty($record['committee_id']) ? (int) $record['committee_id'] : null)
    : [];
$committeeNames = $committeeRows ? implode('; ', array_map(fn ($row) => $row['committee_name'], $committeeRows)) : ($record['committee_name'] ?? 'Unassigned');
$leadCommitteeName = lead_committee_name_from_rows($committeeRows);
$recordAssignments = record_assignment_names((int) $record['id'], !empty($record['committee_id']) ? (int) $record['committee_id'] : null);
$assignedDivisionNames = implode('; ', $recordAssignments['divisions']);
$assignedSecretariatNames = implode('; ', $recordAssignments['secretariats']);
$canViewAssignmentDetails = in_array(current_user()['role'] ?? '', ['admin', 'city_secretary', 'division_chief', 'secretariat', 'division_staff'], true);
$canUpdatePlenaryNumbers = can_assign_plenary_numbers($record);
$divisionReceipt = null;
$recordViewReturnPath = '/record_view.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '?id=' . $id);
if ($role === 'division_staff' && can_attest_division_receipt($record)) {
    $divisionReceipt = division_receipts_for_records([$id])[$id] ?? null;
}

function render_tracking_detail(string $detail): string
{
    $escaped = e($detail);
    $escaped = preg_replace_callback(
        '/Date Approved:\s*(\d{4}-\d{2}-\d{2}|\d{2}\/\d{2}\/\d{4})/',
        fn ($matches) => '<span class="approved-date-highlight">Date Approved: ' . e(display_date($matches[1])) . '</span>',
        $escaped
    );

    return nl2br($escaped);
}

$attachments = [];
if (can_view_record_attachments($record)) {
    try {
        $attachmentStmt = db()->prepare("SELECT a.*, COALESCE(NULLIF(u.nickname, ''), u.name) uploaded_by_name
            FROM record_attachments a
            LEFT JOIN users u ON u.id = a.uploaded_by
            WHERE a.record_id = ?
            ORDER BY a.created_at DESC, a.id DESC");
        $attachmentStmt->execute([$id]);
        $attachments = $attachmentStmt->fetchAll();
    } catch (Throwable $error) {
        $attachments = [];
    }
}

$publicStatusUrl = public_record_status_url((int) $record['id']);
require __DIR__ . '/../app/partials/header.php';
?>
<?php if ($isPopup): ?><div class="modal-backdrop" role="presentation"><?php endif; ?>
<div class="<?= $isPopup ? 'panel user-edit-modal record-view-modal' : 'record-view-page' ?>" <?= $isPopup ? 'role="dialog" aria-modal="true" aria-labelledby="record_view_title"' : '' ?>>
<?php if ($isPopup): ?>
    <a class="modal-close record-view-close" href="<?= e($closeUrl) ?>" aria-label="Close record window">X</a>
<?php endif; ?>
<div class="page-head">
    <div class="record-heading-with-qr">
        <div class="record-heading-copy">
            <h1 id="record_view_title">Communication Number: <?= e($record['control_number']) ?></h1>
            <?php if ((current_user()['role'] ?? '') === 'receiving_clerk' && record_has_pending_receiving_staff_comment($record)): ?>
                <span class="receiving-comment-tag page-head-comment-tag">For Correction</span>
            <?php endif; ?>
            <p class="muted page-record-title"><?= e(display_record_title(record_title_for_current_user($record))) ?></p>
        </div>
        <div class="communication-qr-link" aria-label="QR code for <?= e($record['control_number']) ?>">
            <span class="communication-qr" data-communication-qr data-qr-value="<?= e($publicStatusUrl) ?>"></span>
            <span class="communication-qr-label">Scan status</span>
        </div>
    </div>
    <div class="actions record-view-actions">
        <?php if ($canUpdatePlenaryNumbers): ?>
            <a class="btn secondary" href="<?= url('/plenary_number_form.php?id=') ?><?= (int) $record['id'] ?>&amp;popup=1<?= $isPopup ? '&amp;return=dashboard&amp;division_tab=' . e($divisionTab !== '' ? $divisionTab : 'staff-updates') : '' ?>">Assign Proposed No.</a>
        <?php elseif (can_edit_record($record)): ?>
            <a class="btn secondary<?= (current_user()['role'] ?? '') === 'city_secretary' && ($record['status'] ?? '') === 'Received' ? ' record-review-action' : ' record-edit-action' ?>" href="<?= e($recordEditUrl) ?>"><?= (current_user()['role'] ?? '') === 'city_secretary' && ($record['status'] ?? '') === 'Received' ? 'Review' : 'Edit' ?></a>
        <?php endif; ?>
        <?php if (can_update_record_status($record)): ?>
            <a class="btn secondary record-update-action" href="<?= url('/record_update.php?id=') ?><?= (int) $record['id'] ?><?= $isPopup ? '&amp;popup=1&amp;return=dashboard&amp;division_tab=' . e($divisionTab !== '' ? $divisionTab : 'staff-updates') : '' ?>">Update Status</a>
        <?php endif; ?>
        <?php if (can_manage_transmittal_recipients($record)): ?>
            <a class="btn secondary" href="<?= url('/record_recipients.php?record_id=') ?><?= (int) $record['id'] ?>">Add Recipients</a>
        <?php endif; ?>
        <?php if ($isPopup && !in_array(current_user()['role'] ?? '', ['administrative_support', 'others', 'server_maintenance_staff'], true) && $record['document_type'] === 'Committee Referrals' && !empty($record['committee_id']) && ($record['status'] ?? '') !== 'Received'): ?>
            <a class="btn apple-green<?= (current_user()['role'] ?? '') === 'receiving_clerk' ? '' : ' record-document-action' ?>" href="<?= url('/committee_referral_print.php?id=') ?><?= (int) $record['id'] ?>"<?= (current_user()['role'] ?? '') === 'receiving_clerk' ? ' target="_blank" rel="noopener"' : '' ?>><?= (current_user()['role'] ?? '') === 'receiving_clerk' ? 'View Referral' : 'Committee Referral' ?></a>
        <?php endif; ?>
        <?php if (can_delete_record($record)): ?>
            <form method="post" action="<?= url('/record_delete.php') ?>" onsubmit="return confirm('Delete this record and its tracking history?');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                <button class="btn danger record-delete-action" type="submit">Delete</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<section class="panel">
    <div class="panel-title-row">
        <h2>Record Details</h2>
        <?php if (!$isPopup && !in_array(current_user()['role'] ?? '', ['administrative_support', 'others', 'server_maintenance_staff'], true) && $record['document_type'] === 'Committee Referrals' && !empty($record['committee_id']) && ($record['status'] ?? '') !== 'Received'): ?>
            <a class="btn apple-green<?= (current_user()['role'] ?? '') === 'receiving_clerk' ? '' : ' record-document-action' ?>" href="<?= url('/committee_referral_print.php?id=') ?><?= (int) $record['id'] ?>"<?= (current_user()['role'] ?? '') === 'receiving_clerk' ? ' target="_blank" rel="noopener"' : '' ?>><?= (current_user()['role'] ?? '') === 'receiving_clerk' ? 'View Referral' : 'Committee Referral' ?></a>
        <?php endif; ?>
    </div>
    <div class="detail-list">
        <div><strong>Status</strong><br><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></div>
        <div><strong>Record Type</strong><br><?= ($record['status'] ?? '') === 'Received' ? '<span class="badge for-review">Proposed: ' . e($record['document_type']) . '</span>' : e($record['document_type']) ?></div>
        <?php if ($record['document_type'] === 'Committee Referrals'): ?>
            <div><strong>Client / Origin</strong><br><?= e($record['client_name'] ?? '') ?></div>
            <?php if (trim((string) ($record['contact_number'] ?? '')) !== ''): ?>
                <div><strong>Client Contact Number</strong><br><?= e($record['contact_number']) ?></div>
            <?php endif; ?>
            <?php if (trim((string) ($record['client_email'] ?? '')) !== ''): ?>
                <div><strong>Client Email</strong><br><?= e($record['client_email']) ?></div>
            <?php endif; ?>
            <div><strong>Committee</strong><br><?= ($record['status'] ?? '') === 'Received' && !empty($record['committee_id']) ? '<span class="badge for-review">Proposed: ' . e($committeeNames) . '</span>' : e($committeeNames) ?></div>
            <?php if ($leadCommitteeName !== ''): ?>
                <div><strong>Lead Committee</strong><br><?= e($leadCommitteeName) ?></div>
            <?php endif; ?>
            <div><strong>Date Received</strong><br><?= e(display_date($record['received_date'] ?? '')) ?></div>
            <div><strong>Admin Receiving Section</strong><br><?= e($record['receiving_clerk_name'] ?? 'Not set') ?></div>
            <?php if ($role === 'division_staff' && can_attest_division_receipt($record)): ?>
                <div>
                    <strong>Physical Copy</strong><br>
                    <?php if ($divisionReceipt): ?>
                        <span class="division-receipt-control is-received">
                            <input type="checkbox" checked disabled aria-label="Physical copy received">
                            <span>Received</span>
                        </span>
                        <span class="receipt-meta receipt-meta-block">
                            <?= e(display_datetime($divisionReceipt['received_at'] ?? '')) ?>
                            <?php if (!empty($divisionReceipt['received_by_name'])): ?>by <?= e($divisionReceipt['received_by_name']) ?><?php endif; ?>
                        </span>
                    <?php else: ?>
                        <form method="post" action="<?= url('/record_division_receipt.php') ?>" class="division-receipt-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                            <input type="hidden" name="return_path" value="<?= e($recordViewReturnPath) ?>">
                            <label class="division-receipt-control">
                                <input type="checkbox" name="received" value="1" onchange="if (this.checked) this.form.submit();">
                                <span>Received</span>
                            </label>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div><strong>Client / Origin</strong><br><?= e($record['origin']) ?></div>
            <?php if ($record['document_type'] === 'Certified Urgent'): ?>
                <div><strong>Date Received</strong><br><?= e(display_date($record['received_date'] ?? '')) ?></div>
                <div><strong>Admin Receiving Section</strong><br><?= e($record['receiving_clerk_name'] ?? 'Not set') ?></div>
            <?php endif; ?>
            <?php if (can_view_transmittal_contact_details($record) && trim((string) ($record['contact_number'] ?? '')) !== ''): ?>
                <div><strong>Contact Number</strong><br><?= e($record['contact_number']) ?></div>
            <?php endif; ?>
            <?php if (can_view_transmittal_contact_details($record) && trim((string) ($record['client_email'] ?? '')) !== ''): ?>
                <div><strong>Email</strong><br><?= e($record['client_email']) ?></div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($canViewAssignmentDetails && $record['document_type'] === 'Committee Referrals'): ?>
            <div class="detail-highlight"><strong>Division</strong><br><?= e($assignedDivisionNames !== '' ? $assignedDivisionNames : ($record['assigned_division'] ?? 'Unassigned')) ?></div>
        <?php endif; ?>
        <?php if ($canViewAssignmentDetails && $record['document_type'] === 'Committee Referrals'): ?>
            <div class="detail-highlight"><strong>Assigned Secretariat</strong><br><?= e($assignedSecretariatNames !== '' ? $assignedSecretariatNames : 'Unassigned') ?></div>
        <?php endif; ?>
        <?php if (in_array($record['document_type'], ['Committee Referrals', 'Certified Urgent'], true) && (($record['proposed_ordinance_number'] ?? '') !== '' || ($record['proposed_resolution_number'] ?? '') !== '')): ?>
            <?php if (($record['proposed_ordinance_number'] ?? '') !== ''): ?>
                <div><strong>Proposed Ordinance Number</strong><br><?= e($record['proposed_ordinance_number']) ?></div>
            <?php endif; ?>
            <?php if (($record['proposed_resolution_number'] ?? '') !== ''): ?>
                <div><strong>Proposed Resolution Number</strong><br><?= e($record['proposed_resolution_number']) ?></div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (in_array($record['document_type'], ['Committee Referrals', 'Certified Urgent'], true)
            && ($record['plenary_session_date'] ?? '') !== ''
            && (in_array($record['status'] ?? '', array_merge(['Scheduled for Plenary', 'Approved in the Plenary'], post_plenary_statuses()), true)
                || record_has_plenary_approval($record))): ?>
            <div><strong>Scheduled Plenary Date</strong><br><?= e(display_date($record['plenary_session_date'])) ?></div>
        <?php endif; ?>
        <?php if (in_array($record['document_type'], ['Committee Referrals', 'Certified Urgent'], true) && (($record['approved_ordinance_number'] ?? '') !== '' || ($record['approved_resolution_number'] ?? '') !== '')): ?>
            <?php if (($record['approved_ordinance_number'] ?? '') !== ''): ?>
                <div><strong>Ordinance Number</strong><br><?= e($record['approved_ordinance_number']) ?></div>
            <?php endif; ?>
            <?php if (($record['approved_resolution_number'] ?? '') !== ''): ?>
                <div><strong>Resolution Number</strong><br><?= e($record['approved_resolution_number']) ?></div>
            <?php endif; ?>
            <?php if (($record['plenary_approved_date'] ?? '') !== ''): ?>
                <div><strong>Date Approved</strong><br><span class="approved-date-highlight"><?= e(display_date($record['plenary_approved_date'])) ?></span></div>
            <?php endif; ?>
        <?php endif; ?>
        <div><strong>Created By</strong><br><?= e($record['created_by_name'] ?? '') ?></div>
        <?php if (is_administrative_document_type($record['document_type'] ?? '') || $record['remarks']): ?>
            <div class="full"><strong>Remarks</strong><br><?= nl2br(e($record['remarks'])) ?></div>
        <?php endif; ?>
    </div>
</section>

<?php if (can_view_record_attachments($record)): ?>
    <section class="panel" style="margin-top:16px;">
        <div class="panel-title-row">
            <h2>Attachments</h2>
            <?php if (can_manage_plenary_record_attachments($record)): ?>
                <a class="btn secondary" href="<?= e($attachmentManageUrl) ?>">Attachments on File</a>
            <?php endif; ?>
        </div>
        <?php if (can_upload_record_attachment($record) && !can_manage_plenary_record_attachments($record)): ?>
            <form method="post" action="<?= url('/record_attachment.php') ?>" enctype="multipart/form-data" class="attachment-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                <label>Attach PDF / Images
                    <input type="file" name="attachments[]" accept="application/pdf,image/*" multiple required>
                </label>
                <button class="btn secondary" type="submit">Upload Attachments</button>
            </form>
            <p class="muted">Select up to 10 files. Allowed: PDF, JPG, PNG, GIF, and WEBP. Maximum 10 MB per file and 35 MB combined.</p>
        <?php endif; ?>
        <?php if ($attachments): ?>
            <div class="actions attachment-view-all-action">
                <a class="btn secondary record-document-action" href="<?= e($attachmentViewUrl) ?>">View All Documents</a>
            </div>
        <?php else: ?>
            <p class="muted">No attachments uploaded yet.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if (can_assign_referral_committee($record) && ($record['document_type'] ?? '') === 'Committee Referrals' && ($record['status'] ?? '') === 'Received'): ?>
    <section class="panel" style="margin-top:16px;">
        <h2>City Secretary: Review Record</h2>
        <form method="post" action="<?= url('/record_workflow.php') ?>" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
            <input type="hidden" name="action" value="assign_committee">
            <label>Committee / Committees
                <div class="committee-checkbox-menu" id="committee_ids" role="group" aria-label="Committee selection">
                    <?php foreach ($committees as $committee): ?>
                        <label class="committee-checkbox-option">
                            <input type="checkbox" name="committee_ids[]" value="<?= (int) $committee['id'] ?>" <?= in_array((int) $committee['id'], array_map(fn ($row) => (int) $row['committee_id'], $committeeRows), true) ? 'checked' : '' ?>>
                            <span><?= e($committee['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <span class="muted">Check one or more committees.</span>
            </label>
            <label>Lead Committee
                <select name="lead_committee_id" id="lead_committee_id">
                    <option value="">Use first selected committee</option>
                    <?php foreach ($committees as $committee): ?>
                        <option value="<?= (int) $committee['id'] ?>" <?= (int) $committee['id'] === (int) ($record['committee_id'] ?? 0) ? 'selected' : '' ?>><?= e($committee['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="muted">Only choose this when more than one committee is selected. If only one committee is selected, it is automatically the lead committee.</span>
            </label>
            <label class="full">Remarks / Instruction
                <textarea name="remarks" required placeholder="Add routing remarks or instruction for the Division Chief and assigned Secretariat."></textarea>
            </label>
            <div class="actions full">
                <button class="btn" type="submit">Mark Reviewed and Ready for Printing</button>
            </div>
        </form>
        <script>
        const committeeIdsSelect = document.getElementById('committee_ids');
        const committeeIdInputs = Array.from(document.querySelectorAll('input[name="committee_ids[]"]'));
        const leadCommitteeSelect = document.getElementById('lead_committee_id');
        const leadCommitteeWrap = leadCommitteeSelect ? leadCommitteeSelect.closest('label') : null;
        const syncLeadCommitteeChoices = () => {
            if (!committeeIdsSelect || !leadCommitteeSelect) {
                return;
            }
            const selectedValues = committeeIdInputs.filter((input) => input.checked).map((input) => input.value);
            const selectedCommittees = committeeIdInputs
                .filter((input) => input.checked)
                .map((input) => ({
                    value: input.value,
                    label: (input.closest('.committee-checkbox-option')?.innerText || input.value).trim(),
                }));
            const previousLead = leadCommitteeSelect.value;
            leadCommitteeSelect.innerHTML = '';
            if (selectedCommittees.length === 0) {
                leadCommitteeSelect.add(new Option('Select committee first', ''));
            } else if (selectedCommittees.length > 1) {
                leadCommitteeSelect.add(new Option('Select lead committee', ''));
            }
            selectedCommittees.forEach((committee) => {
                leadCommitteeSelect.add(new Option(committee.label, committee.value));
            });
            if (selectedCommittees.length === 1) {
                leadCommitteeSelect.value = selectedCommittees[0].value;
            } else if (selectedValues.includes(previousLead)) {
                leadCommitteeSelect.value = previousLead;
            } else {
                leadCommitteeSelect.value = '';
            }
            leadCommitteeSelect.required = selectedValues.length > 1;
            if (leadCommitteeWrap) {
                leadCommitteeWrap.style.display = selectedValues.length > 0 ? 'grid' : 'none';
            }
        };
        if (committeeIdInputs.length) {
            committeeIdInputs.forEach((input) => input.addEventListener('change', syncLeadCommitteeChoices));
            syncLeadCommitteeChoices();
        }
        </script>
    </section>
<?php endif; ?>

<?php if (in_array(current_user()['role'] ?? '', ['admin', 'receiving_clerk'], true) && $record['document_type'] === 'Committee Referrals' && !empty($record['committee_id']) && ($record['status'] ?? '') !== 'Received'): ?>
    <section class="panel" style="margin-top:16px;">
        <h2><?= $committeeReferralPrinted ? 'Committee Referral Printed' : 'Committee Referrals for Printing' ?></h2>
        <p><strong>Current Status:</strong> <span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></p>
        <?php if ($committeeReferralPrinted): ?>
            <p class="muted">This Committee Referral was already marked printed and forwarded. You may print another copy if needed.</p>
        <?php endif; ?>
        <div class="actions">
            <a class="btn" href="<?= url('/committee_referral_print.php?id=') ?><?= (int) $record['id'] ?>"<?= (current_user()['role'] ?? '') === 'receiving_clerk' ? ' target="_blank" rel="noopener"' : '' ?>><?= $committeeReferralPrinted ? 'Print Again' : 'Open Committee Referral' ?></a>
            <?php if (!$committeeReferralPrinted): ?>
                <form method="post" action="<?= url('/record_workflow.php') ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                    <input type="hidden" name="action" value="mark_referral_printed">
                    <button class="btn secondary" type="submit">Mark Printed and Forwarded</button>
                </form>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php if (can_act_on_administrative_document($record) && $record['status'] !== 'Completed'): ?>
    <section class="panel" style="margin-top:16px;">
        <h2>City Secretary: Act on Document</h2>
        <form method="post" action="<?= url('/record_workflow.php') ?>" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
            <input type="hidden" name="action" value="act_administrative_document">
            <label class="full">Remarks / Action Taken
                <textarea name="remarks" required placeholder="Add instruction, action taken, or disposition for the Admin Receiving Section."><?= e($record['remarks']) ?></textarea>
            </label>
            <div class="actions full">
                <button class="btn" type="submit">Mark Acted Upon</button>
            </div>
        </form>
    </section>
<?php endif; ?>

<?php if (can_view_record_history($record)): ?>
    <section class="panel" style="margin-top:16px;">
        <h2>Status Tracking History</h2>
        <div class="timeline<?= count($movements) >= 5 ? ' timeline-scroll' : '' ?>">
            <?php foreach ($movements as $movement): ?>
                <div class="timeline-item">
                    <strong><?= e($movement['updated_by_name'] ?? 'System') ?></strong>
                    <?php
                    $movementDate = display_datetime($movement['created_at'] ?? '');
                    $isDivisionReceipt = str_starts_with(
                        (string) ($movement['notes'] ?? ''),
                        'Physical copy of the record attachments received by Division Staff'
                    );
                    $isPendingCommitteeUpdate = (string) ($movement['to_status'] ?? '') === 'Pending to the Committee';
                    $statusAction = $isDivisionReceipt
                        ? 'Physical Copy Received'
                        : ($movement['from_status']
                        ? (string) $movement['to_status']
                        : 'Created record with status ' . $movement['to_status']);
                    if (
                        in_array(
                            (string) ($movement['to_status'] ?? ''),
                            ['Assigned to the Committee', 'Pending to the Committee'],
                            true
                        )
                        && $committeeNames !== ''
                    ) {
                        $statusAction .= ': ' . $committeeNames;
                    }
                    $inputDetails = [];
                    $movementTitle = trim((string) ($movement['record_title'] ?? ''));
                    if ($movementTitle === '') {
                        $movementTitle = trim((string) ($movement['report_title'] ?? ''));
                    }
                    $previousMovementTitle = trim((string) ($movement['previous_title'] ?? ''));
                    $isPlenaryApproval = (string) ($movement['to_status'] ?? '') === 'Approved in the Plenary';
                    if ($previousMovementTitle !== '' && $movementTitle !== '' && $previousMovementTitle !== $movementTitle) {
                        $previousTitleLabel = $originalRecordTitle !== '' && $previousMovementTitle === $originalRecordTitle
                            ? 'Original Title'
                            : 'Previous Title';
                        $updatedTitleLabel = $isPlenaryApproval
                            ? 'Final Approved Title'
                            : 'Edited Title';
                        $inputDetails[] = $previousTitleLabel . ': ' . $previousMovementTitle;
                        $inputDetails[] = $updatedTitleLabel . ': ' . $movementTitle;
                    }
                    if (!empty($movement['notes'])) {
                        $inputDetails[] = trim((string) $movement['notes']);
                    }
                    if (!empty($movement['report_remarks']) && strpos((string) $movement['notes'], (string) $movement['report_remarks']) === false) {
                        $inputDetails[] = 'Remarks: ' . $movement['report_remarks'];
                    }
                    ?>
                    <p class="muted"><?= e($movementDate) ?></p>
                    <p class="timeline-status-update<?= $isPendingCommitteeUpdate ? ' pending-committee-update' : '' ?>"><strong><?= $isDivisionReceipt ? 'Confirmation:' : 'Update:' ?></strong> <?= e($statusAction) ?></p>
                    <?php if ($inputDetails): ?>
                        <div class="timeline-details">
                            <?php foreach ($inputDetails as $detail): ?>
                                <?php $isPrintedForwardedDetail = trim((string) $detail) === 'Committee Referral printed and forwarded to the assigned committee.'; ?>
                                <p<?= $isPrintedForwardedDetail ? ' class="printed-forwarded-detail"' : '' ?>><?= render_tracking_detail($detail) ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (can_print_record_update($record, $movement)): ?>
                        <p>
                            <a class="print-link" href="<?= url('/committee_referral_print.php?id=') ?><?= (int) $record['id'] ?>&movement_id=<?= (int) $movement['id'] ?>">Print Referral</a>
                            <?php if (can_edit_secretariat_update($record, $movement)): ?>
                                <span class="muted"> | </span>
                                <a class="print-link" href="<?= url('/record_update_edit.php?movement_id=') ?><?= (int) $movement['id'] ?>">Add Chief Remarks</a>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($movement['chief_remarks'])): ?>
                        <p>
                            <strong>Division Chief Remarks:</strong>
                            <?php if (!empty($movement['chief_remarks_updated_at'])): ?>
                                <span class="muted"><?= e(display_datetime($movement['chief_remarks_updated_at'])) ?></span>
                            <?php endif; ?>
                            <br><?= nl2br(e($movement['chief_remarks'])) ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php else: ?>
    <section class="panel" style="margin-top:16px;">
        <h2>Current Status</h2>
        <p><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></p>
        <p class="muted">Full tracking history is visible to the City Secretary, assigned Division Chief, and assigned Secretariat.</p>
    </section>
<?php endif; ?>
</div>
<?php if ($isPopup): ?></div><?php endif; ?>
<script src="<?= url('/assets/vendor/qrcode-generator.js') ?>"></script>
<script src="<?= url('/assets/communication-qr.js') ?>"></script>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
