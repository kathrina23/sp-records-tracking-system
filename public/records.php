<?php

require_once __DIR__ . '/../app/auth.php';
require_login();
ensure_plenary_number_schema();

$userRole = current_user()['role'] ?? '';
$isOthersUser = $userRole === 'others';
$canUseAllRecordsTab = in_array($userRole, ['admin', 'city_secretary'], true);
$canUseAdministrativeDocumentsTab = in_array($userRole, ['admin', 'city_secretary', 'receiving_clerk', 'others'], true);
$canUseCertifiedUrgentTab = !$isOthersUser;
$activeTab = $_GET['tab'] ?? 'committee';
if (
    !in_array($activeTab, ['all', 'committee', 'certified', 'documents', 'transmittals', 'memoranda'], true)
    || (!$canUseAllRecordsTab && $activeTab === 'all')
    || (!$canUseAdministrativeDocumentsTab && $activeTab === 'documents')
    || (!$canUseCertifiedUrgentTab && $activeTab === 'certified')
    || ($isOthersUser && $activeTab === 'documents')
    || (!$isOthersUser && in_array($activeTab, ['transmittals', 'memoranda'], true))
) {
    $activeTab = 'committee';
}
$activeDocumentType = match ($activeTab) {
    'committee' => 'Committee Referrals',
    'certified' => 'Certified Urgent',
    'transmittals' => 'Transmittals, Letters and Endorsements',
    'memoranda' => 'Memorandum, Executive Order, Directive Order and Etc.',
    default => '',
};

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$committeeId = trim($_GET['committee_id'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$sort = trim($_GET['sort'] ?? '');
$staffUserId = (int) ($_GET['staff_user_id'] ?? 0);
$returnTab = trim($_GET['return_tab'] ?? '');
$pendingOnly = ($_GET['pending'] ?? '') === '1';

if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$where = [];
$params = [];
if ($activeTab === 'documents') {
    $administrativeTypes = administrative_document_types();
    $where[] = 'r.document_type IN (' . implode(',', array_fill(0, count($administrativeTypes), '?')) . ')';
    $params = array_merge($params, $administrativeTypes);
} elseif ($activeDocumentType !== '') {
    $where[] = 'r.document_type = ?';
    $params[] = $activeDocumentType;
}
if ($search !== '') {
    $where[] = '(r.control_number LIKE ? OR r.title LIKE ? OR r.origin LIKE ? OR r.client_name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($status === '__blank__') {
    $where[] = "(r.status IS NULL OR r.status = '')";
} elseif ($status !== '') {
    $where[] = 'r.status = ?';
    $params[] = $status;
}
if ($activeTab === 'committee' && $pendingOnly) {
    $where[] = "r.status IN ('Assigned to the Committee', 'Pending to the Committee')";
}
if ($userRole === 'city_secretary' && $activeTab === 'all') {
    $where[] = "r.document_type NOT IN ('Transmittals, Letters and Endorsements', 'Memorandum, Executive Order, Directive Order and Etc.')";
}
if ($activeTab === 'committee' && $committeeId !== '') {
    $where[] = '(r.committee_id = ? OR EXISTS (SELECT 1 FROM record_committees rc_scope WHERE rc_scope.record_id = r.id AND rc_scope.committee_id = ?))';
    array_push($params, $committeeId, $committeeId);
}
if ($activeTab === 'committee' && $staffUserId > 0 && $userRole === 'division_chief') {
    $staffStmt = db()->prepare("SELECT id, role, division_name FROM users WHERE id = ? AND role IN ('secretariat', 'division_staff') LIMIT 1");
    $staffStmt->execute([$staffUserId]);
    $staffUser = $staffStmt->fetch();
    $chiefDivisionStmt = db()->prepare('SELECT division_name FROM users WHERE id = ? LIMIT 1');
    $chiefDivisionStmt->execute([(int) (current_user()['id'] ?? 0)]);
    $chiefDivision = (string) ($chiefDivisionStmt->fetchColumn() ?: '');
    if ($staffUser && $chiefDivision !== '' && (string) ($staffUser['division_name'] ?? '') === $chiefDivision) {
        $staffCommitteeIds = committee_ids_for_staff_user((int) $staffUser['id'], (string) $staffUser['role']);
        if ($staffCommitteeIds) {
            $placeholders = implode(',', array_fill(0, count($staffCommitteeIds), '?'));
            $where[] = "(r.committee_id IN ($placeholders) OR EXISTS (SELECT 1 FROM record_committees rc_staff WHERE rc_staff.record_id = r.id AND rc_staff.committee_id IN ($placeholders)))";
            $params = array_merge($params, $staffCommitteeIds, $staffCommitteeIds);
        } else {
            $where[] = '1 = 0';
        }
    } else {
        $where[] = '1 = 0';
    }
}
if ($dateFrom !== '') {
    $where[] = 'r.received_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'r.received_date <= ?';
    $params[] = $dateTo;
}
if ($userRole === 'division_chief' && $activeTab !== 'certified') {
    $where[] = "NOT (r.document_type = 'Committee Referrals' AND r.status = 'Received')";
    $chiefCommitteeIds = division_chief_committee_ids();
    if ($chiefCommitteeIds) {
        $placeholders = implode(',', array_fill(0, count($chiefCommitteeIds), '?'));
        $where[] = "(r.committee_id IN ($placeholders) OR EXISTS (SELECT 1 FROM record_committees rc_scope WHERE rc_scope.record_id = r.id AND rc_scope.committee_id IN ($placeholders)))";
        $params = array_merge($params, $chiefCommitteeIds, $chiefCommitteeIds);
    } else {
        $where[] = '1 = 0';
    }
}
if ($userRole === 'division_staff' && $activeTab !== 'certified') {
    $where[] = "NOT (r.document_type = 'Committee Referrals' AND r.status = 'Received')";
    $staffCommitteeIds = division_staff_committee_ids();
    if ($staffCommitteeIds) {
        $placeholders = implode(',', array_fill(0, count($staffCommitteeIds), '?'));
        $where[] = "(r.committee_id IN ($placeholders) OR EXISTS (SELECT 1 FROM record_committees rc_scope WHERE rc_scope.record_id = r.id AND rc_scope.committee_id IN ($placeholders)))";
        $params = array_merge($params, $staffCommitteeIds, $staffCommitteeIds);
    } else {
        $where[] = '1 = 0';
    }
}
if ($userRole === 'secretariat' && $activeTab !== 'certified') {
    $where[] = "NOT (r.document_type = 'Committee Referrals' AND r.status = 'Received')";
    $secretariatCommitteeIds = secretariat_committee_ids();
    if ($secretariatCommitteeIds) {
        $placeholders = implode(',', array_fill(0, count($secretariatCommitteeIds), '?'));
        $where[] = "(r.committee_id IN ($placeholders) OR EXISTS (SELECT 1 FROM record_committees rc_scope WHERE rc_scope.record_id = r.id AND rc_scope.committee_id IN ($placeholders)))";
        $params = array_merge($params, $secretariatCommitteeIds, $secretariatCommitteeIds);
    } else {
        $where[] = '1 = 0';
    }
}
if ($userRole === 'administrative_support') {
    $where[] = "r.document_type IN ('Committee Referrals', 'Certified Urgent')";
    $where[] = "r.status IN ('For Plenary Session', 'Approved in the Plenary', 'For Vice Mayor''s Signature', 'Returned from The Vice Mayor', 'Forwarded for Admin/Mayor Signature', 'Returned from Admin/Mayor', 'Veto', 'Lapse into Ordinance', 'Forwarded to the Messengerial Services', 'Completed')";
}

$secretariatActionSelect = $userRole === 'secretariat'
    ? ', CASE WHEN (' . record_needs_secretariat_action_sql('r') . ') THEN 1 ELSE 0 END AS needs_secretariat_action'
    : '';
$receivingCommentSelect = $userRole === 'receiving_clerk'
    ? ', CASE WHEN (' . pending_receiving_staff_comment_sql('r') . ') THEN 1 ELSE 0 END AS has_receiving_comment'
    : '';
$sql = "SELECT r.*, c.name committee_name, COALESCE(NULLIF(u.division_name, ''), u.name) assigned_division" . $secretariatActionSelect . $receivingCommentSelect . "
        FROM records r
        LEFT JOIN committees c ON c.id = r.committee_id
        LEFT JOIN users u ON u.id = r.assigned_user_id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY ' . record_action_priority_sql('r') . ', r.updated_at DESC, r.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

$divisionReceiptsByRecord = [];
$recordsReturnPath = '/records.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
if ($userRole === 'division_staff' && $records) {
    $divisionReceiptsByRecord = division_receipts_for_records(array_column($records, 'id'));
}

$attachmentsByRecord = [];
if ($records) {
    $recordIds = array_map(fn ($recordItem) => (int) $recordItem['id'], $records);
    $attachmentPlaceholders = implode(',', array_fill(0, count($recordIds), '?'));
    try {
        $attachmentStmt = db()->prepare("SELECT id, record_id, original_name
            FROM record_attachments
            WHERE record_id IN ($attachmentPlaceholders)
            ORDER BY created_at DESC, id DESC");
        $attachmentStmt->execute($recordIds);
        foreach ($attachmentStmt->fetchAll() as $attachment) {
            $attachmentsByRecord[(int) $attachment['record_id']][] = $attachment;
        }
    } catch (Throwable $error) {
        $attachmentsByRecord = [];
    }
}

foreach ($records as &$recordItem) {
    if (($recordItem['document_type'] ?? '') === 'Committee Referrals') {
        $committeeRows = record_committee_rows((int) $recordItem['id'], !empty($recordItem['committee_id']) ? (int) $recordItem['committee_id'] : null);
        if ($committeeRows) {
            $recordItem['committee_names'] = implode('; ', array_map(fn ($row) => $row['committee_name'], $committeeRows));
            $recordItem['lead_committee_name'] = lead_committee_name_from_rows($committeeRows);
        } else {
            $recordItem['committee_names'] = $recordItem['committee_name'] ?? '';
            $recordItem['lead_committee_name'] = '';
        }
        $recordAssignments = record_assignment_names(
            (int) $recordItem['id'],
            !empty($recordItem['committee_id']) ? (int) $recordItem['committee_id'] : null
        );
        $recordItem['division_names'] = implode('; ', $recordAssignments['divisions']);
        $recordItem['secretariat_names'] = implode('; ', $recordAssignments['secretariats']);
    }
}
unset($recordItem);
if (in_array($userRole, ['division_chief', 'secretariat', 'division_staff'], true)) {
    $assignedCommitteeIds = scoped_committee_ids_for_current_user();
    if ($assignedCommitteeIds) {
        $placeholders = implode(',', array_fill(0, count($assignedCommitteeIds), '?'));
        $committeeStmt = db()->prepare("SELECT id, name FROM committees WHERE id IN ($placeholders) ORDER BY name");
        $committeeStmt->execute($assignedCommitteeIds);
        $committees = $committeeStmt->fetchAll();
    } else {
        $committees = [];
    }
} else {
    $committees = db()->query('SELECT id, name FROM committees ORDER BY name')->fetchAll();
}
$statuses = match ($activeTab) {
    'committee' => referral_statuses(),
    'documents', 'transmittals', 'memoranda' => administrative_statuses(),
    default => array_values(array_unique(array_merge(referral_statuses(), administrative_statuses()))),
};
$showAssignmentDetails = in_array($userRole, ['admin', 'city_secretary', 'division_chief', 'secretariat', 'division_staff'], true);
$committeeActionCount = action_required_count_for_type('Committee Referrals');
$administrativeDocumentsActionCount = $canUseAdministrativeDocumentsTab
    ? action_required_count_for_type('Transmittals, Letters and Endorsements')
    : 0;

require __DIR__ . '/../app/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>Records</h1>
        <p class="muted">Search, filter, and open tracked records.</p>
    </div>
    <div class="actions">
        <?php if ($activeTab === 'committee' && $committeeId !== '' && $sort === 'updated'): ?>
            <a class="btn secondary" href="<?= url('/dashboard.php?division_tab=committees') ?>">Back to Committee Tab</a>
        <?php endif; ?>
        <?php if ($activeTab === 'committee' && $staffUserId > 0 && $returnTab === 'staff'): ?>
            <a class="btn secondary" href="<?= url('/dashboard.php?division_tab=staff') ?>">Back to Staff Tab</a>
        <?php endif; ?>
        <a class="btn secondary back-dashboard-action" href="<?= url('/dashboard.php') ?>">Back to Dashboard</a>
    </div>
</div>

<nav class="dashboard-tabs" aria-label="Records type tabs">
    <?php if ($canUseAllRecordsTab): ?>
        <a class="<?= $activeTab === 'all' ? 'active' : '' ?>" href="<?= url('/records.php?tab=all') ?>">
            <span>All Records</span>
        </a>
    <?php endif; ?>
    <a class="<?= $activeTab === 'committee' ? 'active' : '' ?>" href="<?= url('/records.php?tab=committee') ?>">
        <span>Committee Referrals</span>
        <?php if ($committeeActionCount > 0): ?>
            <span class="tab-action-badge"><?= (int) $committeeActionCount ?></span>
        <?php endif; ?>
    </a>
    <?php if ($canUseCertifiedUrgentTab): ?>
        <a class="<?= $activeTab === 'certified' ? 'active' : '' ?>" href="<?= url('/records.php?tab=certified') ?>">
            <span>Certified Urgent</span>
        </a>
    <?php endif; ?>
    <?php if ($canUseAdministrativeDocumentsTab && !$isOthersUser): ?>
        <a class="<?= $activeTab === 'documents' ? 'active' : '' ?>" href="<?= url('/records.php?tab=documents') ?>">
            <span>Transmittals, Letters and Endorsements</span>
            <?php if ($administrativeDocumentsActionCount > 0): ?>
                <span class="tab-action-badge"><?= (int) $administrativeDocumentsActionCount ?></span>
            <?php endif; ?>
        </a>
    <?php endif; ?>
    <?php if ($isOthersUser): ?>
        <a class="<?= $activeTab === 'transmittals' ? 'active' : '' ?>" href="<?= url('/records.php?tab=transmittals') ?>">
            <span>Transmittals, Letters and Endorsements</span>
        </a>
        <a class="<?= $activeTab === 'memoranda' ? 'active' : '' ?>" href="<?= url('/records.php?tab=memoranda') ?>">
            <span>Memo and EO's</span>
        </a>
    <?php endif; ?>
</nav>

<form method="get" class="filters">
    <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
    <input name="search" placeholder="Search communication no., title, origin, client" value="<?= e($search) ?>">
    <select name="status">
        <option value="">All statuses</option>
        <?php if ($activeTab === 'all'): ?>
            <option value="__blank__" <?= $status === '__blank__' ? 'selected' : '' ?>>No Status</option>
        <?php endif; ?>
        <?php foreach ($statuses as $item): ?>
            <option value="<?= e($item) ?>" <?= $status === $item ? 'selected' : '' ?>><?= e($item) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($activeTab === 'committee'): ?>
        <select name="committee_id">
            <option value="">All committees</option>
            <?php foreach ($committees as $committee): ?>
                <option value="<?= (int) $committee['id'] ?>" <?= $committeeId === (string) $committee['id'] ? 'selected' : '' ?>><?= e($committee['name']) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
    <input type="date" name="date_from" value="<?= e($dateFrom) ?>" aria-label="Date received from">
    <input type="date" name="date_to" value="<?= e($dateTo) ?>" aria-label="Date received to">
    <button class="btn secondary records-filter-action" type="submit">Filter</button>
    <a class="btn secondary records-filter-action" href="<?= url('/records.php?') ?><?= e(http_build_query(['tab' => $activeTab])) ?>">Clear</a>
</form>

<section class="record-list">
    <?php foreach ($records as $record): ?>
        <?php
            $needsAction = record_needs_user_action($record);
            $needsDivisionChiefAction = (current_user()['role'] ?? '') === 'division_chief'
                && record_needs_division_chief_action($record)
                && can_access_record_committees($record);
            $greenActionRoles = ['receiving_clerk', 'secretariat'];
            $actionClass = $needsAction && in_array(current_user()['role'] ?? '', $greenActionRoles, true) ? 'action-required' : ($needsAction ? 'needs-action' : '');
            $approvedPlenaryClass = ($record['status'] ?? '') === 'Approved in the Plenary'
                && (trim((string) ($record['approved_ordinance_number'] ?? '')) !== '' || trim((string) ($record['approved_resolution_number'] ?? '')) !== '')
                ? 'approved-plenary-card'
                : '';
            $recordAnchor = 'record-' . (int) $record['id'];
            $recordPopupUrl = url('/record_view.php?' . http_build_query([
                'id' => (int) $record['id'],
                'popup' => 1,
                'return' => 'records',
                'return_url' => $recordsReturnPath . '#' . $recordAnchor,
            ]));
            $recordEditPopupUrl = url('/record_form.php?' . http_build_query([
                'id' => (int) $record['id'],
                'popup' => 1,
                'return' => 'records',
                'return_url' => $recordsReturnPath . '#' . $recordAnchor,
            ]));
            $recordAttachmentPopupUrl = url('/record_attachments_view.php?' . http_build_query([
                'record_id' => (int) $record['id'],
                'popup' => 1,
                'return' => 'records',
                'return_url' => $recordsReturnPath . '#' . $recordAnchor,
            ]));
        ?>
        <article id="<?= e($recordAnchor) ?>" class="record-card <?= e(trim($actionClass . ' ' . $approvedPlenaryClass)) ?>">
            <?php if ($needsAction || $needsDivisionChiefAction || current_secretariat_is_lead_committee($record)): ?>
                <span class="record-card-label-stack">
                    <?php if ($needsDivisionChiefAction): ?>
                        <span class="needs-action-label">Needs Division Chief Action</span>
                    <?php elseif ($needsAction): ?>
                        <span class="<?= $actionClass === 'action-required' ? 'action-required-label' : 'needs-action-label' ?>"><?= (current_user()['role'] ?? '') === 'secretariat' ? 'Needs Action' : ($actionClass === 'action-required' ? 'Action Required' : 'Needs Action') ?></span>
                    <?php endif; ?>
                    <?php if (current_secretariat_is_lead_committee($record)): ?><span class="lead-secretariat-label">Lead<br>Committee Secretariat</span><?php endif; ?>
                </span>
            <?php endif; ?>
            <div class="record-line record-topline">
                <div>
                    <strong>Communication Number:</strong> <?= control_number_link($record, $recordPopupUrl) ?>
                    <?php if ($userRole === 'receiving_clerk' && record_has_pending_receiving_staff_comment($record)): ?>
                        <span class="receiving-comment-tag">For Correction</span>
                    <?php endif; ?>
                </div>
                <div><strong>Type:</strong> <?= ($record['status'] ?? '') === 'Received' ? '<span class="badge for-review">Proposed: ' . e($record['document_type']) . '</span>' : e($record['document_type']) ?></div>
                <div><strong>Date Received:</strong> <?= e(display_date($record['received_date'] ?? '')) ?></div>
            </div>
            <?php if (($record['document_type'] ?? '') === 'Committee Referrals'): ?>
                <div class="record-line"><strong>Client / Origin:</strong> <?= e($record['client_name'] ?? '') ?></div>
                <div class="record-line"><strong>Committee:</strong>
                    <?php if (empty($record['committee_id'])): ?>
                        <span class="badge for-review">Committee Assignment</span>
                    <?php elseif (($record['status'] ?? '') === 'Received'): ?>
                        <span class="badge for-review">Proposed: <?= e(($record['committee_names'] ?? '') !== '' ? $record['committee_names'] : ($record['committee_name'] ?? 'Unassigned')) ?></span>
                    <?php else: ?>
                        <?= e(($record['committee_names'] ?? '') !== '' ? $record['committee_names'] : ($record['committee_name'] ?? 'Unassigned')) ?>
                    <?php endif; ?>
                </div>
                <?php if (($record['lead_committee_name'] ?? '') !== ''): ?>
                    <div class="record-line"><strong>Lead Committee:</strong> <?= e($record['lead_committee_name']) ?></div>
                <?php endif; ?>
            <?php else: ?>
                <div class="record-line"><strong>Client / Origin:</strong> <?= e($record['origin'] ?? '') ?></div>
                <?php if (can_view_transmittal_contact_details($record) && trim((string) ($record['contact_number'] ?? '')) !== ''): ?>
                    <div class="record-line"><strong>Contact Number:</strong> <?= e($record['contact_number']) ?></div>
                <?php endif; ?>
            <?php endif; ?>
            <div class="record-line record-title-line"><span class="record-title-text"><strong>Title:</strong> <?= nl2br(e(display_record_title(record_title_for_current_user($record)))) ?></span></div>
            <?php if ($showAssignmentDetails && ($record['document_type'] ?? '') === 'Committee Referrals'): ?>
                <div class="record-line record-highlight-line"><strong>Division:</strong> <?= e($record['division_names'] ?: ($record['assigned_division'] ?? 'Unassigned')) ?></div>
                <div class="record-line record-highlight-line"><strong>Assigned Secretariat:</strong> <?= e($record['secretariat_names'] ?: 'Unassigned') ?></div>
            <?php endif; ?>
            <?php $recordAttachments = can_view_record_attachments($record) ? ($attachmentsByRecord[(int) $record['id']] ?? []) : []; ?>
            <?php if ($recordAttachments): ?>
                <div class="record-line record-attachments-line">
                    <strong>Attachments:</strong>
                    <span class="record-attachment-links">
                        <a class="record-attachment-link view-all-attachments" href="<?= e($recordAttachmentPopupUrl) ?>">
                            <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
                                <path d="M4 4h12v16H4z"></path>
                                <path d="M8 8h4M8 12h4M8 16h4"></path>
                                <path d="M16 7h4v13H8"></path>
                            </svg>
                            <span>View All Documents</span>
                        </a>
                    </span>
                </div>
            <?php endif; ?>
            <div class="record-line"><strong>Status:</strong> <span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></div>
            <?php if ($userRole === 'division_staff' && can_attest_division_receipt($record)): ?>
                <?php $divisionReceipt = $divisionReceiptsByRecord[(int) $record['id']] ?? null; ?>
                <div class="record-line division-receipt-row">
                    <strong>Physical Copy:</strong>
                    <?php if ($divisionReceipt): ?>
                        <span class="division-receipt-control is-received">
                            <input type="checkbox" checked disabled aria-label="Physical copy received">
                            <span>Received</span>
                        </span>
                        <span class="receipt-meta">
                            <?= e(display_datetime($divisionReceipt['received_at'] ?? '')) ?>
                            <?php if (!empty($divisionReceipt['received_by_name'])): ?>
                                by <?= e($divisionReceipt['received_by_name']) ?>
                            <?php endif; ?>
                        </span>
                    <?php else: ?>
                        <form method="post" action="<?= url('/record_division_receipt.php') ?>" class="division-receipt-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                            <input type="hidden" name="return_path" value="<?= e($recordsReturnPath) ?>">
                            <label class="division-receipt-control">
                                <input type="checkbox" name="received" value="1" onchange="if (this.checked) this.form.submit();">
                                <span>Received</span>
                            </label>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ((current_user()['role'] ?? '') === 'receiving_clerk'): ?>
                <div class="record-line record-actions"><strong>Action:</strong>
                    <span class="actions">
                        <?php if (can_edit_record($record)): ?>
                            <a class="record-edit-action" href="<?= e($recordEditPopupUrl) ?>">Edit</a>
                        <?php endif; ?>
                        <?php if (($record['document_type'] ?? '') === 'Committee Referrals' && !empty($record['committee_id']) && ($record['status'] ?? '') !== 'Received'): ?>
                            <a href="<?= url('/committee_referral_print.php?id=') ?><?= (int) $record['id'] ?>" target="_blank" rel="noopener">Print</a>
                        <?php endif; ?>
                        <?php if (can_manage_transmittal_recipients($record)): ?>
                            <a href="<?= url('/record_recipients.php?record_id=') ?><?= (int) $record['id'] ?>">Add Recipients</a>
                        <?php endif; ?>
                        <span class="record-view-qr-actions">
                            <a class="record-view-action" href="<?= e($recordPopupUrl) ?>">View Record</a>
                            <a class="record-qr-print-action" href="<?= url('/communication_qr_print.php?id=') ?><?= (int) $record['id'] ?>" target="communication_qr_print" rel="noopener" onclick="window.open(this.href, 'communication_qr_print', 'width=1020,height=820,scrollbars=yes,resizable=yes'); return false;">Print QR</a>
                        </span>
                        <?php if (can_delete_record($record)): ?>
                            <form method="post" action="<?= url('/record_delete.php') ?>" class="inline-form" onsubmit="return confirm('Delete this record and its tracking history?');">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                <button class="link-danger record-delete-action" type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    </span>
                </div>
            <?php elseif ((current_user()['role'] ?? '') !== 'receiving_clerk'): ?>
                <div class="record-line record-actions"><strong>Action:</strong>
                    <span class="actions">
                        <?php if (can_edit_record($record)): ?>
                            <a class="<?= (current_user()['role'] ?? '') === 'city_secretary' && ($record['status'] ?? '') === 'Received' ? 'record-review-action' : 'record-edit-action' ?>" href="<?= e($recordEditPopupUrl) ?>"><?= (current_user()['role'] ?? '') === 'city_secretary' && ($record['status'] ?? '') === 'Received' ? 'Review' : 'Edit' ?></a>
                        <?php endif; ?>
                        <?php if (can_update_record_status($record)): ?>
                            <a class="record-update-action" href="<?= url('/record_update.php?id=') ?><?= (int) $record['id'] ?>">Update</a>
                        <?php endif; ?>
                        <?php if ((current_user()['role'] ?? '') === 'admin' && ($record['document_type'] ?? '') === 'Committee Referrals' && !empty($record['committee_id']) && ($record['status'] ?? '') !== 'Received'): ?>
                            <a href="<?= url('/committee_referral_print.php?id=') ?><?= (int) $record['id'] ?>">Print</a>
                        <?php endif; ?>
                        <?php if (can_manage_transmittal_recipients($record)): ?>
                            <a href="<?= url('/record_recipients.php?record_id=') ?><?= (int) $record['id'] ?>">Add Recipients</a>
                        <?php endif; ?>
                        <a class="record-view-action" href="<?= e($recordPopupUrl) ?>">View Record</a>
                        <?php if (can_delete_record($record)): ?>
                            <form method="post" action="<?= url('/record_delete.php') ?>" class="inline-form" onsubmit="return confirm('Delete this record and its tracking history?');">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                <button class="link-danger record-delete-action" type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    <?php if (!$records): ?>
        <section class="panel"><p class="muted">No matching records found.</p></section>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
