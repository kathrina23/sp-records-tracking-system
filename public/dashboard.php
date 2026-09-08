<?php

require_once __DIR__ . '/../app/auth.php';
require_login();
ensure_plenary_number_schema();

$authenticatedDashboardUser = current_user() ?? [];
$monitorableDashboardRoles = [
    'city_secretary',
    'receiving_clerk',
    'division_chief',
    'secretariat',
    'division_staff',
    'administrative_support',
    'others',
    'records_officer',
    'staff',
];
$requestedMonitorRole = trim((string) ($_GET['monitor_role'] ?? ''));
$isDashboardMonitor = ($authenticatedDashboardUser['role'] ?? '') === 'admin'
    && in_array($requestedMonitorRole, $monitorableDashboardRoles, true);
$dashboardMonitorUsers = [];
$dashboardMonitorUser = null;
$dashboardMonitorUserId = 0;
if ($isDashboardMonitor) {
    $monitorUsersStmt = db()->prepare("SELECT id, name, nickname, email, role, division_name, is_active
        FROM users
        WHERE role = ? AND is_active = 1
        ORDER BY name, id");
    $monitorUsersStmt->execute([$requestedMonitorRole]);
    $dashboardMonitorUsers = $monitorUsersStmt->fetchAll();
    $requestedMonitorUserId = (int) ($_GET['monitor_user_id'] ?? 0);
    foreach ($dashboardMonitorUsers as $monitorUser) {
        if ((int) $monitorUser['id'] === $requestedMonitorUserId) {
            $dashboardMonitorUser = $monitorUser;
            break;
        }
    }
    if (!$dashboardMonitorUser && $dashboardMonitorUsers) {
        $dashboardMonitorUser = $dashboardMonitorUsers[0];
    }
    if (!$dashboardMonitorUser) {
        $dashboardMonitorUser = [
            'id' => 0,
            'name' => 'No active account',
            'nickname' => '',
            'email' => '',
            'role' => $requestedMonitorRole,
            'division_name' => '',
            'is_active' => 0,
        ];
    }
    $dashboardMonitorUserId = (int) ($dashboardMonitorUser['id'] ?? 0);
    register_shutdown_function(static function () use ($authenticatedDashboardUser): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['user'] = $authenticatedDashboardUser;
        }
    });
    $_SESSION['user'] = $dashboardMonitorUser;
}

// Dashboard content below must depend only on the selected user's identity and
// permissions. Monitoring may add admin context and block writes, but it must
// not change visible role-specific panels or controls.
$dashboardUser = current_user() ?? [];
if (!array_key_exists('nickname', $dashboardUser) && (int) ($dashboardUser['id'] ?? 0) > 0) {
    try {
        $dashboardNicknameStmt = db()->prepare('SELECT nickname FROM users WHERE id = ? LIMIT 1');
        $dashboardNicknameStmt->execute([(int) $dashboardUser['id']]);
        $dashboardUser['nickname'] = (string) ($dashboardNicknameStmt->fetchColumn() ?: '');
    } catch (Throwable $error) {
        $dashboardUser['nickname'] = '';
    }
}
$dashboardGreetingName = trim((string) ($dashboardUser['nickname'] ?? ''));
if ($dashboardGreetingName === '') {
    $dashboardGreetingName = trim((string) ($dashboardUser['name'] ?? ''));
}
if ($dashboardGreetingName === '') {
    $dashboardGreetingName = 'User';
}
$dashboardCurrentDate = date('F j, Y');

$userRole = current_user()['role'] ?? '';
$isLawsAndRulesSecretariat = $userRole === 'secretariat' && is_laws_and_rules_secretariat();
$isFullDashboard = in_array($userRole, ['admin', 'city_secretary'], true);
$isAdministrator = $userRole === 'admin';
$isReceivingClerk = $userRole === 'receiving_clerk';
$isOthersUser = $userRole === 'others';
$isAssignedDashboard = in_array($userRole, ['division_chief', 'secretariat', 'division_staff'], true);
$usesAdministrativeSupportDashboard = $userRole === 'administrative_support';
$showStats = $isFullDashboard;
$showCitySecretaryAssignment = $isFullDashboard || $isReceivingClerk;
$showPrinting = $isFullDashboard || $isReceivingClerk;
$showAssignedReferrals = $isFullDashboard || $isAssignedDashboard;
$showTransmittals = $usesAdministrativeSupportDashboard;
$showAdministrativeDocuments = $isAdministrator || $isReceivingClerk || $isOthersUser;
$showCertifiedUrgent = $isAdministrator || $isReceivingClerk;
$showMemorandumDocuments = $isAdministrator || $isReceivingClerk || $isOthersUser;
$administrativeSupportCityTab = in_array($_GET['city_tab'] ?? '', ['transmittals', 'approved-plenary', 'notes'], true)
    ? $_GET['city_tab']
    : 'approved-plenary';
$activeTab = $_GET['tab'] ?? 'committee';
if (
    !in_array($activeTab, ['committee', 'printing', 'documents', 'certified-urgent', 'memoranda', 'transmittals', 'notes'], true)
    || (!$isReceivingClerk && $activeTab === 'printing')
    || (!$showAdministrativeDocuments && $activeTab === 'documents')
    || (!$showCertifiedUrgent && $activeTab === 'certified-urgent')
    || (!$showMemorandumDocuments && $activeTab === 'memoranda')
    || (!$showTransmittals && $activeTab === 'transmittals')
) {
    $activeTab = 'committee';
}
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$committeeId = trim($_GET['committee_id'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$approvedDate = trim($_GET['approved_date'] ?? '');
$approvedType = trim($_GET['approved_type'] ?? '');
$staffUpdateSearch = trim($_GET['staff_update_search'] ?? '');
$staffUpdateDateFrom = trim($_GET['staff_update_date_from'] ?? '');
$staffUpdateDateTo = trim($_GET['staff_update_date_to'] ?? '');
$staffUpdatesPage = max(1, (int) ($_GET['staff_updates_page'] ?? 1));
$staffUpdatesPerPage = 10;
$staffUpdatesOffset = ($staffUpdatesPage - 1) * $staffUpdatesPerPage;
$staffLogMinimumDate = '2026-08-10';
$staffLogDateFrom = trim($_GET['staff_log_date_from'] ?? $staffLogMinimumDate);
$staffLogDateTo = trim($_GET['staff_log_date_to'] ?? '');
$staffLogsPage = max(1, (int) ($_GET['staff_logs_page'] ?? 1));
$staffLogsPerPage = 10;
$recentReferralsPage = max(1, (int) ($_GET['recent_referrals_page'] ?? 1));
$recentReferralsPerPage = 10;

if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}
if ($approvedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $approvedDate)) {
    $approvedDate = '';
}
if (!in_array($approvedType, ['', 'ordinance', 'resolution'], true)) {
    $approvedType = '';
}
if ($staffUpdateDateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $staffUpdateDateFrom)) {
    $staffUpdateDateFrom = '';
}
if ($staffUpdateDateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $staffUpdateDateTo)) {
    $staffUpdateDateTo = '';
}
if ($staffLogDateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $staffLogDateFrom)) {
    $staffLogDateFrom = $staffLogMinimumDate;
}
if ($staffLogDateFrom === '' || $staffLogDateFrom < $staffLogMinimumDate) {
    $staffLogDateFrom = $staffLogMinimumDate;
}
if ($staffLogDateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $staffLogDateTo)) {
    $staffLogDateTo = '';
}
$staffUpdateFilterSql = '';
$staffUpdateFilterParams = [];
if ($userRole === 'division_chief') {
    if ($staffUpdateSearch !== '') {
        $staffUpdateFilterSql .= " AND (
            r.control_number LIKE ?
            OR r.title LIKE ?
            OR COALESCE(r.client_name, '') LIKE ?
            OR COALESCE(r.origin, '') LIKE ?
            OR COALESCE(m.to_status, '') LIKE ?
            OR COALESCE(m.notes, '') LIKE ?
            OR COALESCE(u.name, '') LIKE ?
            OR COALESCE(u.nickname, '') LIKE ?
        )";
        $staffUpdateLike = '%' . $staffUpdateSearch . '%';
        array_push(
            $staffUpdateFilterParams,
            $staffUpdateLike,
            $staffUpdateLike,
            $staffUpdateLike,
            $staffUpdateLike,
            $staffUpdateLike,
            $staffUpdateLike,
            $staffUpdateLike,
            $staffUpdateLike
        );
    }
    if ($staffUpdateDateFrom !== '') {
        $staffUpdateFilterSql .= ' AND DATE(m.created_at) >= ?';
        $staffUpdateFilterParams[] = $staffUpdateDateFrom;
    }
    if ($staffUpdateDateTo !== '') {
        $staffUpdateFilterSql .= ' AND DATE(m.created_at) <= ?';
        $staffUpdateFilterParams[] = $staffUpdateDateTo;
    }
}

function append_dashboard_filters(array &$where, array &$params, string $alias = 'r', bool $includeCommittee = true): void
{
    global $search, $status, $committeeId, $dateFrom, $dateTo;

    if ($search !== '') {
        $where[] = "($alias.control_number LIKE ? OR $alias.title LIKE ? OR $alias.origin LIKE ? OR $alias.client_name LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($status !== '') {
        $where[] = "$alias.status = ?";
        $params[] = $status;
    }
    if ($includeCommittee && $committeeId !== '') {
        $where[] = "($alias.committee_id = ? OR EXISTS (SELECT 1 FROM record_committees rc_scope WHERE rc_scope.record_id = $alias.id AND rc_scope.committee_id = ?))";
        array_push($params, $committeeId, $committeeId);
    }
    if ($dateFrom !== '') {
        $where[] = "$alias.received_date >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = "$alias.received_date <= ?";
        $params[] = $dateTo;
    }
}

function render_dashboard_record_filters(
    array $hiddenFields,
    string $clearUrl,
    array $statusOptions,
    array $committeeOptions,
    bool $includeCommittee = true
): void {
    global $search, $status, $committeeId, $dateFrom, $dateTo;
    ?>
    <form method="get" class="filters dashboard-filters dashboard-global-filters" aria-label="Dashboard record filters">
        <?php foreach ($hiddenFields as $name => $value): ?>
            <input type="hidden" name="<?= e((string) $name) ?>" value="<?= e((string) $value) ?>">
        <?php endforeach; ?>
        <input name="search" placeholder="Search communication no., title, origin, client" value="<?= e($search) ?>">
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach ($statusOptions as $item): ?>
                <option value="<?= e($item) ?>" <?= $status === $item ? 'selected' : '' ?>><?= e($item) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($includeCommittee): ?>
            <select name="committee_id">
                <option value="">All committees</option>
                <?php foreach ($committeeOptions as $committee): ?>
                    <option value="<?= (int) $committee['id'] ?>" <?= $committeeId === (string) $committee['id'] ? 'selected' : '' ?>><?= e($committee['name']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <input type="date" name="date_from" value="<?= e($dateFrom) ?>" aria-label="Date received from">
        <input type="date" name="date_to" value="<?= e($dateTo) ?>" aria-label="Date received to">
        <button class="btn secondary records-filter-action" type="submit">Filter</button>
        <a class="btn secondary records-filter-action" href="<?= e($clearUrl) ?>">Clear</a>
    </form>
    <?php
}

function dashboard_action_url(string $url): string
{
    global $isDashboardMonitor;

    return $isDashboardMonitor ? '#dashboard-monitor-read-only' : $url;
}

$dashboardWhere = '';
$dashboardParams = [];
if ($userRole === 'secretariat') {
    $secretariatCommitteeIds = secretariat_committee_ids();
    if ($secretariatCommitteeIds) {
        $placeholders = implode(',', array_fill(0, count($secretariatCommitteeIds), '?'));
        $dashboardWhere = " WHERE NOT (document_type = 'Committee Referrals' AND status = 'Received') AND (committee_id IN ($placeholders) OR EXISTS (SELECT 1 FROM record_committees rc_scope WHERE rc_scope.record_id = records.id AND rc_scope.committee_id IN ($placeholders)))";
        $dashboardParams = array_merge($secretariatCommitteeIds, $secretariatCommitteeIds);
    } else {
        $dashboardWhere = ' WHERE 1 = 0';
    }
} elseif (in_array($userRole, ['division_chief', 'division_staff'], true)) {
    $scopedCommitteeIds = scoped_committee_ids_for_current_user();
    if ($scopedCommitteeIds) {
        $placeholders = implode(',', array_fill(0, count($scopedCommitteeIds), '?'));
        $dashboardWhere = " WHERE NOT (document_type = 'Committee Referrals' AND status = 'Received') AND (committee_id IN ($placeholders) OR EXISTS (SELECT 1 FROM record_committees rc_scope WHERE rc_scope.record_id = records.id AND rc_scope.committee_id IN ($placeholders)))";
        $dashboardParams = array_merge($scopedCommitteeIds, $scopedCommitteeIds);
    } else {
        $dashboardWhere = ' WHERE 1 = 0';
    }
}

$totalsStmt = db()->prepare("SELECT status, COUNT(*) total FROM records $dashboardWhere GROUP BY status");
$totalsStmt->execute($dashboardParams);
$totals = $totalsStmt->fetchAll();
$totalStmt = db()->prepare("SELECT COUNT(*) FROM records $dashboardWhere");
$totalStmt->execute($dashboardParams);
$totalRecords = (int) $totalStmt->fetchColumn();

$unassignedReferrals = [];
if ($showCitySecretaryAssignment) {
    $unassignedWhere = ["r.document_type = 'Committee Referrals'", "r.status = 'Received'"];
    $unassignedParams = [];
    append_dashboard_filters($unassignedWhere, $unassignedParams);
    $unassignedStmt = db()->prepare("SELECT r.*, c.name committee_name,
            CASE WHEN (" . pending_receiving_staff_comment_sql('r') . ") THEN 1 ELSE 0 END AS has_receiving_comment
        FROM records r
        LEFT JOIN committees c ON c.id = r.committee_id
        WHERE " . implode(' AND ', $unassignedWhere) . "
        ORDER BY has_receiving_comment ASC, r.updated_at DESC, r.created_at DESC
        LIMIT 8");
    $unassignedStmt->execute($unassignedParams);
    $unassignedReferrals = $unassignedStmt->fetchAll();
}

$printingReferrals = [];
$printingWhere = ["r.document_type = 'Committee Referrals'", "r.status IN ('Assigned to the Committee', 'Pending to the Committee')"];
$printingParams = [];
$receivingCommentSelect = $isReceivingClerk
    ? ', CASE WHEN (' . pending_receiving_staff_comment_sql('r') . ') THEN 1 ELSE 0 END AS has_receiving_comment'
    : '';
append_dashboard_filters($printingWhere, $printingParams);
if ($showPrinting) {
    $printingStmt = db()->prepare("SELECT r.*, c.name committee_name" . $receivingCommentSelect . " FROM records r LEFT JOIN committees c ON c.id = r.committee_id WHERE " . implode(' AND ', $printingWhere) . ' ORDER BY r.updated_at DESC LIMIT 8');
    $printingStmt->execute($printingParams);
    $printingReferrals = $printingStmt->fetchAll();
}

$assignedReferrals = [];
if ($showAssignedReferrals) {
    $assignedWhere = ["r.document_type = 'Committee Referrals'", 'r.committee_id IS NOT NULL', "r.status <> 'Received'"];
    $assignedParams = [];
    if ($isFullDashboard) {
        append_dashboard_filters($assignedWhere, $assignedParams);
        $assignedStmt = db()->prepare("SELECT r.*, c.name committee_name FROM records r LEFT JOIN committees c ON c.id = r.committee_id WHERE " . implode(' AND ', $assignedWhere) . " ORDER BY r.updated_at DESC LIMIT 8");
        $assignedStmt->execute($assignedParams);
        $assignedReferrals = $assignedStmt->fetchAll();
    } else {
        $assignedCommitteeIds = scoped_committee_ids_for_current_user();
        if ($assignedCommitteeIds) {
            $placeholders = implode(',', array_fill(0, count($assignedCommitteeIds), '?'));
            $assignedWhere[] = "(r.committee_id IN ($placeholders) OR EXISTS (SELECT 1 FROM record_committees rc_scope WHERE rc_scope.record_id = r.id AND rc_scope.committee_id IN ($placeholders)))";
            $assignedParams = array_merge($assignedParams, $assignedCommitteeIds, $assignedCommitteeIds);
            append_dashboard_filters($assignedWhere, $assignedParams);
            $assignedStmt = db()->prepare("SELECT r.*, c.name committee_name FROM records r LEFT JOIN committees c ON c.id = r.committee_id WHERE " . implode(' AND ', $assignedWhere) . " ORDER BY r.updated_at DESC LIMIT 8");
            $assignedStmt->execute($assignedParams);
            $assignedReferrals = $assignedStmt->fetchAll();
        }
    }
}
$divisionReceiptsByRecord = [];
$dashboardReturnPath = '/dashboard.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
if ($userRole === 'division_staff' && $assignedReferrals) {
    $divisionReceiptsByRecord = division_receipts_for_records(array_column($assignedReferrals, 'id'));
}
$recentCommitteeWhere = ["r.document_type = 'Committee Referrals'"];
$recentCommitteeParams = [];
if ($isAssignedDashboard) {
    $recentCommitteeIds = scoped_committee_ids_for_current_user();
    if ($recentCommitteeIds) {
        $placeholders = implode(',', array_fill(0, count($recentCommitteeIds), '?'));
        $recentCommitteeWhere[] = "(r.committee_id IN ($placeholders) OR EXISTS (SELECT 1 FROM record_committees rc_scope WHERE rc_scope.record_id = r.id AND rc_scope.committee_id IN ($placeholders)))";
        $recentCommitteeWhere[] = "r.status <> 'Received'";
        $recentCommitteeParams = array_merge($recentCommitteeIds, $recentCommitteeIds);
    } else {
        $recentCommitteeWhere[] = '1 = 0';
    }
}
append_dashboard_filters($recentCommitteeWhere, $recentCommitteeParams);
$recentCommitteeCountStmt = db()->prepare("SELECT COUNT(*) FROM records r WHERE " . implode(' AND ', $recentCommitteeWhere));
$recentCommitteeCountStmt->execute($recentCommitteeParams);
$recentCommitteeTotal = (int) $recentCommitteeCountStmt->fetchColumn();
$recentCommitteeTotalPages = max(1, (int) ceil($recentCommitteeTotal / $recentReferralsPerPage));
$recentReferralsPage = min($recentReferralsPage, $recentCommitteeTotalPages);
$recentReferralsOffset = ($recentReferralsPage - 1) * $recentReferralsPerPage;

$recentCommitteeStmt = db()->prepare("SELECT r.*, c.name committee_name" . $receivingCommentSelect . " FROM records r LEFT JOIN committees c ON c.id = r.committee_id WHERE " . implode(' AND ', $recentCommitteeWhere) . " ORDER BY r.updated_at DESC, r.id DESC LIMIT $recentReferralsPerPage OFFSET $recentReferralsOffset");
$recentCommitteeStmt->execute($recentCommitteeParams);
$recentCommitteeReferrals = $recentCommitteeStmt->fetchAll();

function render_recent_referrals_pagination(int $currentPage, int $totalPages, bool $useCityTab = false): void
{
    if ($totalPages <= 1) {
        return;
    }

    $pageUrl = static function (int $page) use ($useCityTab): string {
        $params = $_GET;
        $params['recent_referrals_page'] = $page;
        if ($useCityTab) {
            $params['city_tab'] = 'recent';
        } else {
            $params['tab'] = 'committee';
        }

        return '/dashboard.php?' . http_build_query($params);
    };

    $visiblePages = [1, $totalPages];
    for ($page = max(1, $currentPage - 2); $page <= min($totalPages, $currentPage + 2); $page++) {
        $visiblePages[] = $page;
    }
    $visiblePages = array_values(array_unique($visiblePages));
    sort($visiblePages);
    ?>
    <nav class="table-pagination" aria-label="Recently updated Committee Referrals pages">
        <?php if ($currentPage > 1): ?>
            <a class="pagination-link pagination-direction" href="<?= e($pageUrl($currentPage - 1)) ?>" rel="prev">Previous</a>
        <?php endif; ?>
        <?php $lastRenderedPage = 0; ?>
        <?php foreach ($visiblePages as $page): ?>
            <?php if ($lastRenderedPage > 0 && $page > $lastRenderedPage + 1): ?>
                <span class="pagination-ellipsis" aria-hidden="true">&hellip;</span>
            <?php endif; ?>
            <?php if ($page === $currentPage): ?>
                <span class="pagination-link active" aria-current="page"><?= (int) $page ?></span>
            <?php else: ?>
                <a class="pagination-link" href="<?= e($pageUrl($page)) ?>" aria-label="Go to page <?= (int) $page ?>"><?= (int) $page ?></a>
            <?php endif; ?>
            <?php $lastRenderedPage = $page; ?>
        <?php endforeach; ?>
        <?php if ($currentPage < $totalPages): ?>
            <a class="pagination-link pagination-direction" href="<?= e($pageUrl($currentPage + 1)) ?>" rel="next">Next</a>
        <?php endif; ?>
    </nav>
    <?php
}

function render_staff_updates_pagination(int $currentPage, int $totalPages): void
{
    if ($totalPages <= 1) {
        return;
    }

    $pageUrl = static function (int $page): string {
        $params = $_GET;
        $params['division_tab'] = 'staff-updates';
        $params['staff_updates_page'] = $page;
        return '/dashboard.php?' . http_build_query($params);
    };

    $visiblePages = [1, $totalPages];
    for ($page = max(1, $currentPage - 2); $page <= min($totalPages, $currentPage + 2); $page++) {
        $visiblePages[] = $page;
    }
    $visiblePages = array_values(array_unique($visiblePages));
    sort($visiblePages);
    ?>
    <nav class="table-pagination" aria-label="Staff Updates pages">
        <?php if ($currentPage > 1): ?>
            <a class="pagination-link pagination-direction" href="<?= e($pageUrl($currentPage - 1)) ?>" rel="prev">Previous</a>
        <?php endif; ?>
        <?php $lastRenderedPage = 0; ?>
        <?php foreach ($visiblePages as $page): ?>
            <?php if ($lastRenderedPage > 0 && $page > $lastRenderedPage + 1): ?>
                <span class="pagination-ellipsis" aria-hidden="true">&hellip;</span>
            <?php endif; ?>
            <?php if ($page === $currentPage): ?>
                <span class="pagination-link active" aria-current="page"><?= (int) $page ?></span>
            <?php else: ?>
                <a class="pagination-link" href="<?= e($pageUrl($page)) ?>" aria-label="Go to page <?= (int) $page ?>"><?= (int) $page ?></a>
            <?php endif; ?>
            <?php $lastRenderedPage = $page; ?>
        <?php endforeach; ?>
        <?php if ($currentPage < $totalPages): ?>
            <a class="pagination-link pagination-direction" href="<?= e($pageUrl($currentPage + 1)) ?>" rel="next">Next</a>
        <?php endif; ?>
    </nav>
    <?php
}

function render_staff_logs_pagination(int $currentPage, int $totalPages): void
{
    if ($totalPages <= 1) {
        return;
    }

    $pageUrl = static function (int $page): string {
        $params = $_GET;
        $params['city_tab'] = 'logs';
        $params['staff_logs_page'] = $page;
        return '/dashboard.php?' . http_build_query($params);
    };

    $visiblePages = [1, $totalPages];
    for ($page = max(1, $currentPage - 2); $page <= min($totalPages, $currentPage + 2); $page++) {
        $visiblePages[] = $page;
    }
    $visiblePages = array_values(array_unique($visiblePages));
    sort($visiblePages);
    ?>
    <nav class="table-pagination" aria-label="Staff and Division Chief Logs pages">
        <?php if ($currentPage > 1): ?>
            <a class="pagination-link pagination-direction" href="<?= e($pageUrl($currentPage - 1)) ?>" rel="prev">Previous</a>
        <?php endif; ?>
        <?php $lastRenderedPage = 0; ?>
        <?php foreach ($visiblePages as $page): ?>
            <?php if ($lastRenderedPage > 0 && $page > $lastRenderedPage + 1): ?>
                <span class="pagination-ellipsis" aria-hidden="true">&hellip;</span>
            <?php endif; ?>
            <?php if ($page === $currentPage): ?>
                <span class="pagination-link active" aria-current="page"><?= (int) $page ?></span>
            <?php else: ?>
                <a class="pagination-link" href="<?= e($pageUrl($page)) ?>" aria-label="Go to page <?= (int) $page ?>"><?= (int) $page ?></a>
            <?php endif; ?>
            <?php $lastRenderedPage = $page; ?>
        <?php endforeach; ?>
        <?php if ($currentPage < $totalPages): ?>
            <a class="pagination-link pagination-direction" href="<?= e($pageUrl($currentPage + 1)) ?>" rel="next">Next</a>
        <?php endif; ?>
    </nav>
    <?php
}

$dashboardDocumentTabDefinitions = [
    'documents' => [
        'document_type' => 'Transmittals, Letters and Endorsements',
        'title' => 'Transmittals, Letters and Endorsements',
        'empty_message' => 'No Transmittals, Letters and Endorsements records found.',
    ],
    'certified-urgent' => [
        'document_type' => 'Certified Urgent',
        'title' => 'Certified Urgent',
        'empty_message' => 'No Certified Urgent records found.',
    ],
    'memoranda' => [
        'document_type' => 'Memorandum, Executive Order, Directive Order and Etc.',
        'title' => 'Memorandum, Executive Order, Directive Order and Etc.',
        'empty_message' => 'No Memorandum, Executive Order, Directive Order and Etc. records found.',
    ],
];
$activeDashboardDocumentTab = $dashboardDocumentTabDefinitions[$activeTab] ?? null;
$dashboardDocumentRecords = [];
if ($activeDashboardDocumentTab) {
    $dashboardDocumentWhere = ['r.document_type = ?'];
    $dashboardDocumentParams = [$activeDashboardDocumentTab['document_type']];
    append_dashboard_filters($dashboardDocumentWhere, $dashboardDocumentParams, 'r', false);
    $dashboardDocumentStmt = db()->prepare("SELECT r.*,
            COALESCE(
                NULLIF(receiving_staff.nickname, ''),
                NULLIF(receiving_staff.name, ''),
                NULLIF(creator.nickname, ''),
                NULLIF(creator.name, ''),
                'Not recorded'
            ) receiving_staff_name
        FROM records r
        LEFT JOIN users receiving_staff ON receiving_staff.id = r.receiving_clerk_id
        LEFT JOIN users creator ON creator.id = r.created_by
        WHERE " . implode(' AND ', $dashboardDocumentWhere) . "
        ORDER BY r.updated_at DESC, r.id DESC
        LIMIT 50");
    $dashboardDocumentStmt->execute($dashboardDocumentParams);
    $dashboardDocumentRecords = $dashboardDocumentStmt->fetchAll();
}

$secretariatNewlyUpdatedRecords = [];
if ($userRole === 'secretariat') {
    $secretariatUpdateCommitteeIds = secretariat_committee_ids();
    if ($secretariatUpdateCommitteeIds) {
        $placeholders = implode(',', array_fill(0, count($secretariatUpdateCommitteeIds), '?'));
        $secretariatUpdatedStmt = db()->prepare("SELECT r.*, c.name committee_name, m.to_status movement_status,
                m.notes movement_notes, m.created_at movement_created_at, COALESCE(NULLIF(u.nickname, ''), u.name) updated_by_name
            FROM record_movements m
            INNER JOIN records r ON r.id = m.record_id
            LEFT JOIN committees c ON c.id = r.committee_id
            INNER JOIN users u ON u.id = m.updated_by
            WHERE r.document_type = 'Committee Referrals'
            AND r.status <> 'Received'
            AND (r.committee_id IN ($placeholders)
                OR EXISTS (
                    SELECT 1 FROM record_committees rc_scope
                    WHERE rc_scope.record_id = r.id
                    AND rc_scope.committee_id IN ($placeholders)
                ))
            ORDER BY m.created_at DESC, m.id DESC
            LIMIT 8");
        $secretariatUpdatedStmt->execute(array_merge($secretariatUpdateCommitteeIds, $secretariatUpdateCommitteeIds));
        $secretariatNewlyUpdatedRecords = $secretariatUpdatedStmt->fetchAll();
    }
}

$transmittals = [];
$recentTransmittals = [];
if ($showTransmittals) {
    $transmittalsWhere = [
        "document_type = 'Committee Referrals'",
        "status = 'For Transmittal'",
        "(plenary_approved_date IS NOT NULL OR COALESCE(NULLIF(approved_ordinance_number, ''), NULLIF(approved_resolution_number, '')) IS NOT NULL)",
    ];
    $transmittalsParams = [];
    if ($isReceivingClerk) {
        $transmittalsWhere[] = 'created_by = ?';
        $transmittalsParams[] = current_user()['id'];
    }
    append_dashboard_filters($transmittalsWhere, $transmittalsParams, 'records', false);
    $transmittalsSql = 'SELECT * FROM records WHERE ' . implode(' AND ', $transmittalsWhere) . ' ORDER BY updated_at DESC LIMIT 8';
    $transmittalsStmt = db()->prepare($transmittalsSql);
    $transmittalsStmt->execute($transmittalsParams);
    $transmittals = $transmittalsStmt->fetchAll();
    $recentTransmittals = $transmittals;
}

if (in_array($userRole, ['division_chief', 'secretariat', 'division_staff'], true)) {
    $filterCommitteeIds = scoped_committee_ids_for_current_user();
    if ($filterCommitteeIds) {
        $filterPlaceholders = implode(',', array_fill(0, count($filterCommitteeIds), '?'));
        $filterCommitteeStmt = db()->prepare("SELECT id, name FROM committees WHERE id IN ($filterPlaceholders) ORDER BY name");
        $filterCommitteeStmt->execute($filterCommitteeIds);
        $committees = $filterCommitteeStmt->fetchAll();
    } else {
        $committees = [];
    }
} else {
    $committees = db()->query('SELECT id, name FROM committees ORDER BY name')->fetchAll();
}
$statuses = match ($activeTab) {
    'documents', 'memoranda' => administrative_statuses(),
    'certified-urgent' => array_values(array_unique(array_merge(
        ['For Plenary Session', 'Scheduled for Plenary', 'Disapproved', 'Approved in the Plenary'],
        post_plenary_statuses()
    ))),
    'transmittals' => ['For Transmittal'],
    default => referral_statuses(),
};

$divisionChiefDashboard = [
    'division_name' => '',
    'committees' => [],
    'staff' => [],
    'notes' => [],
    'notes_available' => false,
    'due_reminder_count' => 0,
    'new_referrals' => [],
    'new_referrals_total' => 0,
    'newly_updated_records' => [],
    'staff_updates' => [],
    'staff_updates_total' => 0,
    'updates_today_count' => 0,
    'review_needed_count' => 0,
];

$personalNotesUserId = (int) (current_user()['id'] ?? 0);
$divisionChiefDashboard['notes_available'] = ensure_division_chief_notes_schema();
if ($personalNotesUserId > 0 && $divisionChiefDashboard['notes_available']) {
    try {
        $notesStmt = db()->prepare("SELECT n.*, r.control_number, r.title record_title,
                r.status record_status, c.name committee_name
            FROM division_chief_notes n
            INNER JOIN records r ON r.id = n.record_id
            LEFT JOIN committees c ON c.id = r.committee_id
            WHERE n.user_id = ?
            ORDER BY n.updated_at DESC, n.id DESC");
        $notesStmt->execute([$personalNotesUserId]);
        $divisionChiefDashboard['notes'] = $notesStmt->fetchAll();
        foreach ($divisionChiefDashboard['notes'] as $note) {
            $reminderTimestamp = strtotime((string) ($note['reminder_at'] ?? ''));
            if ($reminderTimestamp !== false && $reminderTimestamp <= time()) {
                $divisionChiefDashboard['due_reminder_count']++;
            }
        }
    } catch (Throwable $error) {
        error_log('Unable to load personal dashboard notes: ' . $error->getMessage());
        $divisionChiefDashboard['notes_available'] = false;
    }
}

$usesTabbedAssignedDashboard = in_array($userRole, ['division_chief', 'secretariat'], true);
$usesCitySecretaryTabbedDashboard = $isFullDashboard || $usesAdministrativeSupportDashboard;
$citySecretaryDashboard = [
    'committees' => [],
    'committee_referrals' => [],
    'certified_urgent' => [],
    'administrative_documents' => [],
    'memoranda' => [],
    'for_plenary' => [],
    'approved_plenary' => [],
    'staff_logs' => [],
    'staff_logs_total' => 0,
    'staff_logs_total_pages' => 1,
    'staff_logs_available' => true,
];
$cityReviewActionCount = 0;
$cityAdministrativeDocumentsActionCount = 0;
$cityMemorandaActionCount = 0;
$cityForPlenaryActionCount = 0;
$cityApprovedPlenaryActionCount = 0;
if ($usesCitySecretaryTabbedDashboard) {
    $cityCommitteeStmt = db()->query("SELECT c.id, c.name,
            COUNT(DISTINCT r.id) record_count
        FROM committees c
        LEFT JOIN records r ON r.document_type = 'Committee Referrals'
            AND r.status <> 'Received'
            AND (
                r.committee_id = c.id
                OR EXISTS (
                    SELECT 1 FROM record_committees rc_count
                    WHERE rc_count.record_id = r.id
                    AND rc_count.committee_id = c.id
                )
            )
        GROUP BY c.id, c.name
        ORDER BY c.name");
    $citySecretaryDashboard['committees'] = $cityCommitteeStmt->fetchAll();

    if (!$usesAdministrativeSupportDashboard) {
        $cityReviewActionCount = action_required_count_for_type('Committee Referrals');

        $committeeReferralsStmt = db()->query("SELECT r.*, c.name committee_name,
                COALESCE(
                    (
                        SELECT GROUP_CONCAT(c_list.name ORDER BY rc_list.sequence_no SEPARATOR '; ')
                        FROM record_committees rc_list
                        INNER JOIN committees c_list ON c_list.id = rc_list.committee_id
                        WHERE rc_list.record_id = r.id
                    ),
                    c.name
                ) committee_names
            FROM records r
            LEFT JOIN committees c ON c.id = r.committee_id
            WHERE r.document_type = 'Committee Referrals'
            ORDER BY CASE WHEN r.status = 'Received' THEN 0 ELSE 1 END,
                r.updated_at DESC, r.id DESC
            LIMIT 50");
        $citySecretaryDashboard['committee_referrals'] = $committeeReferralsStmt->fetchAll();

        $certifiedUrgentStmt = db()->query("SELECT r.*
            FROM records r
            WHERE r.document_type = 'Certified Urgent'
            ORDER BY r.updated_at DESC, r.id DESC
            LIMIT 50");
        $citySecretaryDashboard['certified_urgent'] = $certifiedUrgentStmt->fetchAll();

        $administrativeDocumentsStmt = db()->query("SELECT r.*
            FROM records r
            WHERE r.document_type = 'Transmittals, Letters and Endorsements'
            ORDER BY CASE WHEN r.status NOT IN ('Completed', 'Archived') THEN 0 ELSE 1 END,
                r.updated_at DESC, r.id DESC
            LIMIT 50");
        $citySecretaryDashboard['administrative_documents'] = $administrativeDocumentsStmt->fetchAll();
        $cityAdministrativeDocumentsActionCount = action_required_count_for_type('Transmittals, Letters and Endorsements');

        $memorandaStmt = db()->query("SELECT r.*
            FROM records r
            WHERE r.document_type = 'Memorandum, Executive Order, Directive Order and Etc.'
            ORDER BY CASE WHEN r.status NOT IN ('Completed', 'Archived') THEN 0 ELSE 1 END,
                r.updated_at DESC, r.id DESC
            LIMIT 50");
        $citySecretaryDashboard['memoranda'] = $memorandaStmt->fetchAll();
        $cityMemorandaActionCount = action_required_count_for_type('Memorandum, Executive Order, Directive Order and Etc.');
        $cityForPlenaryActionCount = (int) db()->query("SELECT COUNT(*)
            FROM records
            WHERE document_type IN ('Committee Referrals', 'Certified Urgent')
            AND status IN ('For Plenary Session', 'Scheduled for Plenary')
            AND (status = 'For Plenary Session'
                OR COALESCE(
                    NULLIF(TRIM(proposed_ordinance_number), ''),
                    NULLIF(TRIM(proposed_resolution_number), '')
                ) IS NULL)")->fetchColumn();
        $cityApprovedPlenaryActionCount = (int) db()->query("SELECT COUNT(*)
            FROM records
            WHERE document_type IN ('Committee Referrals', 'Certified Urgent')
            AND status = 'Approved in the Plenary'
            AND COALESCE(
                NULLIF(TRIM(approved_ordinance_number), ''),
                NULLIF(TRIM(approved_resolution_number), '')
            ) IS NULL")->fetchColumn();

        $forPlenaryStmt = db()->query("SELECT r.*, c.name committee_name
            FROM records r
            LEFT JOIN committees c ON c.id = r.committee_id
            WHERE r.document_type IN ('Committee Referrals', 'Certified Urgent')
            AND r.status IN ('For Plenary Session', 'Scheduled for Plenary')
            ORDER BY CASE WHEN r.status = 'For Plenary Session' THEN 0 ELSE 1 END,
                r.plenary_session_date ASC, r.updated_at DESC, r.id DESC
            LIMIT 50");
        $citySecretaryDashboard['for_plenary'] = $forPlenaryStmt->fetchAll();
    }

    $approvedPlenaryWhere = [
        "r.document_type IN ('Committee Referrals', 'Certified Urgent')",
        "(r.plenary_approved_date IS NOT NULL OR COALESCE(NULLIF(r.approved_ordinance_number, ''), NULLIF(r.approved_resolution_number, '')) IS NOT NULL)",
    ];
    $approvedPlenaryParams = [];
    if ($approvedDate !== '') {
        $approvedPlenaryWhere[] = 'r.plenary_approved_date = ?';
        $approvedPlenaryParams[] = $approvedDate;
    }
    if ($approvedType === 'ordinance') {
        $approvedPlenaryWhere[] = "COALESCE(NULLIF(r.approved_ordinance_number, ''), '') <> ''";
    } elseif ($approvedType === 'resolution') {
        $approvedPlenaryWhere[] = "COALESCE(NULLIF(r.approved_resolution_number, ''), '') <> ''";
    }

    $approvedPlenaryStmt = db()->prepare("SELECT r.*, c.name committee_name
        FROM records r
        LEFT JOIN committees c ON c.id = r.committee_id
        WHERE " . implode(' AND ', $approvedPlenaryWhere) . "
        ORDER BY COALESCE(r.plenary_approved_date, DATE(r.updated_at)) DESC, r.updated_at DESC, r.id DESC
        LIMIT 50");
    $approvedPlenaryStmt->execute($approvedPlenaryParams);
    $citySecretaryDashboard['approved_plenary'] = $approvedPlenaryStmt->fetchAll();

    try {
        $staffLogWhere = ["u.role IN ('division_chief', 'secretariat', 'receiving_clerk', 'division_staff')"];
        $staffLogParams = [];
        if ($staffLogDateFrom !== '') {
            $staffLogWhere[] = 'DATE(a.created_at) >= ?';
            $staffLogParams[] = $staffLogDateFrom;
        }
        if ($staffLogDateTo !== '') {
            $staffLogWhere[] = 'DATE(a.created_at) <= ?';
            $staffLogParams[] = $staffLogDateTo;
        }

        $staffLogCountStmt = db()->prepare("SELECT COUNT(*)
            FROM audit_logs a
            INNER JOIN users u ON u.id = a.user_id
            WHERE " . implode(' AND ', $staffLogWhere));
        $staffLogCountStmt->execute($staffLogParams);
        $citySecretaryDashboard['staff_logs_total'] = (int) $staffLogCountStmt->fetchColumn();
        $citySecretaryDashboard['staff_logs_total_pages'] = max(
            1,
            (int) ceil($citySecretaryDashboard['staff_logs_total'] / $staffLogsPerPage)
        );
        $staffLogsPage = min($staffLogsPage, $citySecretaryDashboard['staff_logs_total_pages']);
        $staffLogsOffset = ($staffLogsPage - 1) * $staffLogsPerPage;

        $staffLogStmt = db()->prepare("SELECT a.*, u.name user_name, u.email user_email, u.role user_role, u.division_name,
                r.id record_id, r.control_number
            FROM audit_logs a
            INNER JOIN users u ON u.id = a.user_id
            LEFT JOIN records r ON a.entity_type = 'record' AND r.id = a.entity_id
            WHERE " . implode(' AND ', $staffLogWhere) . "
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT $staffLogsPerPage OFFSET $staffLogsOffset");
        $staffLogStmt->execute($staffLogParams);
        $citySecretaryDashboard['staff_logs'] = $staffLogStmt->fetchAll();
    } catch (Throwable $error) {
        $citySecretaryDashboard['staff_logs_available'] = false;
    }
}
if ($isLawsAndRulesSecretariat) {
    $cityForPlenaryActionCount = (int) db()->query("SELECT COUNT(*)
        FROM records
        WHERE document_type IN ('Committee Referrals', 'Certified Urgent')
        AND status IN ('For Plenary Session', 'Scheduled for Plenary')
        AND (status = 'For Plenary Session'
            OR COALESCE(
                NULLIF(TRIM(proposed_ordinance_number), ''),
                NULLIF(TRIM(proposed_resolution_number), '')
            ) IS NULL)")->fetchColumn();
    $forPlenaryStmt = db()->query("SELECT r.*, c.name committee_name
        FROM records r
        LEFT JOIN committees c ON c.id = r.committee_id
        WHERE r.document_type IN ('Committee Referrals', 'Certified Urgent')
        AND r.status IN ('For Plenary Session', 'Scheduled for Plenary')
        ORDER BY CASE WHEN r.status = 'For Plenary Session' THEN 0 ELSE 1 END,
            r.plenary_session_date ASC, r.updated_at DESC, r.id DESC
        LIMIT 50");
    $citySecretaryDashboard['for_plenary'] = $forPlenaryStmt->fetchAll();
}
if ($userRole === 'division_chief') {
    $chiefId = (int) (current_user()['id'] ?? 0);
    $chiefStmt = db()->prepare('SELECT division_name FROM users WHERE id = ? LIMIT 1');
    $chiefStmt->execute([$chiefId]);
    $divisionChiefDashboard['division_name'] = (string) ($chiefStmt->fetchColumn() ?: '');

    $chiefCommitteeIds = division_chief_committee_ids($chiefId);
    if ($chiefCommitteeIds) {
        $placeholders = implode(',', array_fill(0, count($chiefCommitteeIds), '?'));
        $committeeStmt = db()->prepare("SELECT c.id, c.name,
                COUNT(DISTINCT r.id) record_count
            FROM committees c
            LEFT JOIN records r ON r.document_type = 'Committee Referrals'
                AND r.status <> 'Received'
                AND (
                    r.committee_id = c.id
                    OR EXISTS (
                        SELECT 1 FROM record_committees rc_count
                        WHERE rc_count.record_id = r.id
                        AND rc_count.committee_id = c.id
                    )
                )
            WHERE c.id IN ($placeholders)
            GROUP BY c.id, c.name
            ORDER BY c.name");
        $committeeStmt->execute($chiefCommitteeIds);
        $divisionChiefDashboard['committees'] = $committeeStmt->fetchAll();

        $newReferralStmt = db()->prepare("SELECT r.*, c.name committee_name
            FROM records r
            LEFT JOIN committees c ON c.id = r.committee_id
            WHERE r.document_type = 'Committee Referrals'
            AND r.status IN ('Assigned to the Committee', 'Pending to the Committee')
            AND NOT EXISTS (
                SELECT 1 FROM record_movements m_first
                INNER JOIN users u_first ON u_first.id = m_first.updated_by
                WHERE m_first.record_id = r.id
                AND u_first.role = 'division_chief'
            )
            AND (r.committee_id IN ($placeholders)
                OR EXISTS (
                    SELECT 1 FROM record_committees rc_scope
                    WHERE rc_scope.record_id = r.id
                    AND rc_scope.committee_id IN ($placeholders)
                ))
            ORDER BY r.updated_at DESC, r.id DESC
            LIMIT 8");
        $newReferralStmt->execute(array_merge($chiefCommitteeIds, $chiefCommitteeIds));
        $divisionChiefDashboard['new_referrals'] = $newReferralStmt->fetchAll();

        $newReferralCountStmt = db()->prepare("SELECT COUNT(DISTINCT r.id)
            FROM records r
            WHERE r.document_type = 'Committee Referrals'
            AND r.status IN ('Assigned to the Committee', 'Pending to the Committee')
            AND NOT EXISTS (
                SELECT 1 FROM record_movements m_first
                INNER JOIN users u_first ON u_first.id = m_first.updated_by
                WHERE m_first.record_id = r.id
                AND u_first.role = 'division_chief'
            )
            AND (r.committee_id IN ($placeholders)
                OR EXISTS (
                    SELECT 1 FROM record_committees rc_scope
                    WHERE rc_scope.record_id = r.id
                    AND rc_scope.committee_id IN ($placeholders)
                ))");
        $newReferralCountStmt->execute(array_merge($chiefCommitteeIds, $chiefCommitteeIds));
        $divisionChiefDashboard['new_referrals_total'] = (int) $newReferralCountStmt->fetchColumn();

        if ($divisionChiefDashboard['division_name'] !== '') {
            $newlyUpdatedStmt = db()->prepare("SELECT r.*, c.name committee_name, m.to_status movement_status,
                    m.notes movement_notes, m.created_at movement_created_at, COALESCE(NULLIF(u.nickname, ''), u.name) updated_by_name
                FROM record_movements m
                INNER JOIN records r ON r.id = m.record_id
                LEFT JOIN committees c ON c.id = r.committee_id
                INNER JOIN users u ON u.id = m.updated_by
                WHERE u.division_name = ?
                AND u.id <> ?
                AND u.role IN ('receiving_clerk', 'secretariat', 'division_staff')
                AND r.document_type = 'Committee Referrals'
                AND (r.committee_id IN ($placeholders)
                    OR EXISTS (
                        SELECT 1 FROM record_committees rc_scope
                        WHERE rc_scope.record_id = r.id
                        AND rc_scope.committee_id IN ($placeholders)
                    ))
                ORDER BY m.created_at DESC, m.id DESC
                LIMIT 8");
            $newlyUpdatedStmt->execute(array_merge(
                [$divisionChiefDashboard['division_name'], $chiefId],
                $chiefCommitteeIds,
                $chiefCommitteeIds
            ));
            $divisionChiefDashboard['newly_updated_records'] = $newlyUpdatedStmt->fetchAll();

            $staffUpdateCountStmt = db()->prepare("SELECT COUNT(*)
                FROM record_movements m
                INNER JOIN records r ON r.id = m.record_id
                INNER JOIN users u ON u.id = m.updated_by
                WHERE u.division_name = ?
                AND u.id <> ?
                AND u.role IN ('receiving_clerk', 'secretariat', 'division_staff')
                AND r.document_type = 'Committee Referrals'
                AND m.id = (
                    SELECT m_latest.id
                    FROM record_movements m_latest
                    INNER JOIN users u_latest ON u_latest.id = m_latest.updated_by
                    WHERE m_latest.record_id = m.record_id
                    AND u_latest.role IN ('receiving_clerk', 'secretariat', 'division_staff')
                    AND COALESCE(u_latest.division_name, '') = COALESCE(u.division_name, '')
                    ORDER BY m_latest.created_at DESC, m_latest.id DESC
                    LIMIT 1
                )
                $staffUpdateFilterSql
                AND (r.committee_id IN ($placeholders)
                    OR EXISTS (
                        SELECT 1 FROM record_committees rc_scope
                        WHERE rc_scope.record_id = r.id
                        AND rc_scope.committee_id IN ($placeholders)
                    ))");
            $staffUpdateCountStmt->execute(array_merge(
                [$divisionChiefDashboard['division_name'], $chiefId],
                $staffUpdateFilterParams,
                $chiefCommitteeIds,
                $chiefCommitteeIds
            ));
            $divisionChiefDashboard['staff_updates_total'] = (int) $staffUpdateCountStmt->fetchColumn();

            $todayUpdateCountStmt = db()->prepare("SELECT COUNT(DISTINCT m.record_id)
                FROM record_movements m
                INNER JOIN records r ON r.id = m.record_id
                INNER JOIN users u ON u.id = m.updated_by
                WHERE u.division_name = ?
                AND u.id <> ?
                AND u.role IN ('receiving_clerk', 'secretariat', 'division_staff')
                AND r.document_type = 'Committee Referrals'
                AND DATE(m.created_at) = CURDATE()
                $staffUpdateFilterSql
                AND (r.committee_id IN ($placeholders)
                    OR EXISTS (
                        SELECT 1 FROM record_committees rc_scope
                        WHERE rc_scope.record_id = r.id
                        AND rc_scope.committee_id IN ($placeholders)
                    ))");
            $todayUpdateCountStmt->execute(array_merge(
                [$divisionChiefDashboard['division_name'], $chiefId],
                $staffUpdateFilterParams,
                $chiefCommitteeIds,
                $chiefCommitteeIds
            ));
            $divisionChiefDashboard['updates_today_count'] = (int) $todayUpdateCountStmt->fetchColumn();

            $reviewNeededStmt = db()->prepare("SELECT COUNT(*)
                FROM record_movements m
                INNER JOIN records r ON r.id = m.record_id
                INNER JOIN users u ON u.id = m.updated_by
                WHERE u.division_name = ?
                AND u.id <> ?
                AND u.role = 'secretariat'
                AND COALESCE(NULLIF(m.chief_remarks, ''), '') = ''
                AND m.id = (
                    SELECT MAX(m_latest.id)
                    FROM record_movements m_latest
                    INNER JOIN users u_latest ON u_latest.id = m_latest.updated_by
                    WHERE m_latest.record_id = m.record_id
                    AND u_latest.role = 'secretariat'
                    AND COALESCE(u_latest.division_name, '') = COALESCE(u.division_name, '')
                )
                AND (r.committee_id IN ($placeholders)
                    OR EXISTS (
                        SELECT 1 FROM record_committees rc_scope
                        WHERE rc_scope.record_id = r.id
                        AND rc_scope.committee_id IN ($placeholders)
                    ))");
            $reviewNeededStmt->execute(array_merge(
                [$divisionChiefDashboard['division_name'], $chiefId],
                $chiefCommitteeIds,
                $chiefCommitteeIds
            ));
            $divisionChiefDashboard['review_needed_count'] = (int) $reviewNeededStmt->fetchColumn();

            $staffUpdateStmt = db()->prepare("SELECT r.*, c.name committee_name, m.to_status movement_status,
                    m.id movement_id, m.notes movement_notes, m.chief_remarks, m.created_at movement_created_at,
                    COALESCE(NULLIF(u.nickname, ''), u.name) staff_name, u.role staff_role,
                    CASE WHEN u.role = 'secretariat' AND m.id = (
                        SELECT MAX(m_latest.id)
                        FROM record_movements m_latest
                        INNER JOIN users u_latest ON u_latest.id = m_latest.updated_by
                        WHERE m_latest.record_id = m.record_id
                        AND u_latest.role = 'secretariat'
                        AND COALESCE(u_latest.division_name, '') = COALESCE(u.division_name, '')
                    ) THEN 1 ELSE 0 END is_latest_secretariat_update
                FROM record_movements m
                INNER JOIN records r ON r.id = m.record_id
                LEFT JOIN committees c ON c.id = r.committee_id
                INNER JOIN users u ON u.id = m.updated_by
                WHERE u.division_name = ?
                AND u.id <> ?
                AND u.role IN ('receiving_clerk', 'secretariat', 'division_staff')
                AND r.document_type = 'Committee Referrals'
                AND m.id = (
                    SELECT m_latest.id
                    FROM record_movements m_latest
                    INNER JOIN users u_latest ON u_latest.id = m_latest.updated_by
                    WHERE m_latest.record_id = m.record_id
                    AND u_latest.role IN ('receiving_clerk', 'secretariat', 'division_staff')
                    AND COALESCE(u_latest.division_name, '') = COALESCE(u.division_name, '')
                    ORDER BY m_latest.created_at DESC, m_latest.id DESC
                    LIMIT 1
                )
                $staffUpdateFilterSql
                AND (r.committee_id IN ($placeholders)
                    OR EXISTS (
                        SELECT 1 FROM record_committees rc_scope
                        WHERE rc_scope.record_id = r.id
                        AND rc_scope.committee_id IN ($placeholders)
                    ))
                ORDER BY CASE WHEN (
                        u.role = 'secretariat'
                        AND COALESCE(NULLIF(m.chief_remarks, ''), '') = ''
                        AND m.id = (
                            SELECT MAX(m_priority.id)
                            FROM record_movements m_priority
                            INNER JOIN users u_priority ON u_priority.id = m_priority.updated_by
                            WHERE m_priority.record_id = m.record_id
                            AND u_priority.role = 'secretariat'
                            AND COALESCE(u_priority.division_name, '') = COALESCE(u.division_name, '')
                        )
                    ) THEN 0 ELSE 1 END,
                    m.created_at DESC, m.id DESC
                LIMIT $staffUpdatesPerPage OFFSET $staffUpdatesOffset");
            $staffUpdateStmt->execute(array_merge(
                [$divisionChiefDashboard['division_name'], $chiefId],
                $staffUpdateFilterParams,
                $chiefCommitteeIds,
                $chiefCommitteeIds
            ));
            $divisionChiefDashboard['staff_updates'] = $staffUpdateStmt->fetchAll();
        }
    }

    if ($divisionChiefDashboard['division_name'] !== '') {
        $staffStmt = db()->prepare("SELECT u.id, u.name, u.nickname, u.role, u.email, u.is_active,
                (SELECT MAX(a.created_at) FROM audit_logs a WHERE a.user_id = u.id AND a.action = 'login') last_login_at,
                (SELECT a.action FROM audit_logs a WHERE a.user_id = u.id AND a.action IN ('login', 'logout') ORDER BY a.created_at DESC, a.id DESC LIMIT 1) latest_login_action
            FROM users
            u
            WHERE u.division_name = ?
            AND u.id <> ?
            AND u.role IN ('receiving_clerk', 'secretariat', 'division_staff')
            ORDER BY u.role, u.name");
        $staffStmt->execute([$divisionChiefDashboard['division_name'], $chiefId]);
        $divisionChiefDashboard['staff'] = $staffStmt->fetchAll();
        foreach ($divisionChiefDashboard['staff'] as &$staffRow) {
            $staffRow['committee_count'] = count(committee_ids_for_staff_user((int) $staffRow['id'], (string) $staffRow['role']));
        }
        unset($staffRow);
    }
} elseif ($userRole === 'secretariat') {
    $secretariatId = (int) (current_user()['id'] ?? 0);
    $secretariatStmt = db()->prepare('SELECT division_name FROM users WHERE id = ? LIMIT 1');
    $secretariatStmt->execute([$secretariatId]);
    $divisionChiefDashboard['division_name'] = (string) ($secretariatStmt->fetchColumn() ?: '');

    $secretariatCommitteeIds = secretariat_committee_ids($secretariatId);
    if ($secretariatCommitteeIds) {
        $placeholders = implode(',', array_fill(0, count($secretariatCommitteeIds), '?'));
        $committeeStmt = db()->prepare("SELECT c.id, c.name,
                COUNT(DISTINCT r.id) record_count
            FROM committee_secretariats cs
            INNER JOIN committees c ON c.id = cs.committee_id
            LEFT JOIN records r ON r.document_type = 'Committee Referrals'
                AND r.status <> 'Received'
                AND (
                    r.committee_id = c.id
                    OR EXISTS (
                        SELECT 1 FROM record_committees rc_count
                        WHERE rc_count.record_id = r.id
                        AND rc_count.committee_id = c.id
                    )
                )
            WHERE cs.user_id = ?
            GROUP BY c.id, c.name
            ORDER BY c.name");
        $committeeStmt->execute([$secretariatId]);
        $divisionChiefDashboard['committees'] = $committeeStmt->fetchAll();

        $newReferralStmt = db()->prepare("SELECT r.*, c.name committee_name
            FROM records r
            LEFT JOIN committees c ON c.id = r.committee_id
            WHERE (" . record_needs_secretariat_action_sql('r') . ")
            AND (r.committee_id IN ($placeholders)
                OR EXISTS (
                    SELECT 1 FROM record_committees rc_scope
                    WHERE rc_scope.record_id = r.id
                    AND rc_scope.committee_id IN ($placeholders)
                ))
            ORDER BY r.updated_at DESC, r.id DESC
            LIMIT 8");
        $newReferralStmt->execute(array_merge($secretariatCommitteeIds, $secretariatCommitteeIds));
        $divisionChiefDashboard['new_referrals'] = $newReferralStmt->fetchAll();

        $newReferralCountStmt = db()->prepare("SELECT COUNT(DISTINCT r.id)
            FROM records r
            WHERE (" . record_needs_secretariat_action_sql('r') . ")
            AND (r.committee_id IN ($placeholders)
                OR EXISTS (
                    SELECT 1 FROM record_committees rc_scope
                    WHERE rc_scope.record_id = r.id
                    AND rc_scope.committee_id IN ($placeholders)
                ))");
        $newReferralCountStmt->execute(array_merge($secretariatCommitteeIds, $secretariatCommitteeIds));
        $divisionChiefDashboard['new_referrals_total'] = (int) $newReferralCountStmt->fetchColumn();

        $divisionChiefDashboard['newly_updated_records'] = $secretariatNewlyUpdatedRecords;

        $staffUpdateCountStmt = db()->prepare("SELECT COUNT(*)
            FROM record_movements m
            INNER JOIN records r ON r.id = m.record_id
            WHERE r.document_type = 'Committee Referrals'
            AND m.id = (
                SELECT m_latest.id
                FROM record_movements m_latest
                WHERE m_latest.record_id = m.record_id
                ORDER BY m_latest.created_at DESC, m_latest.id DESC
                LIMIT 1
            )
            AND (r.committee_id IN ($placeholders)
                OR EXISTS (
                    SELECT 1 FROM record_committees rc_scope
                    WHERE rc_scope.record_id = r.id
                    AND rc_scope.committee_id IN ($placeholders)
                ))");
        $staffUpdateCountStmt->execute(array_merge($secretariatCommitteeIds, $secretariatCommitteeIds));
        $divisionChiefDashboard['staff_updates_total'] = (int) $staffUpdateCountStmt->fetchColumn();

        $todayUpdateCountStmt = db()->prepare("SELECT COUNT(DISTINCT m.record_id)
            FROM record_movements m
            INNER JOIN records r ON r.id = m.record_id
            WHERE r.document_type = 'Committee Referrals'
            AND DATE(m.created_at) = CURDATE()
            AND (r.committee_id IN ($placeholders)
                OR EXISTS (
                    SELECT 1 FROM record_committees rc_scope
                    WHERE rc_scope.record_id = r.id
                    AND rc_scope.committee_id IN ($placeholders)
                ))");
        $todayUpdateCountStmt->execute(array_merge($secretariatCommitteeIds, $secretariatCommitteeIds));
        $divisionChiefDashboard['updates_today_count'] = (int) $todayUpdateCountStmt->fetchColumn();

        $staffUpdateStmt = db()->prepare("SELECT r.*, c.name committee_name, m.to_status movement_status,
                m.id movement_id, m.notes movement_notes, m.chief_remarks, m.created_at movement_created_at,
                COALESCE(NULLIF(u.nickname, ''), u.name) staff_name, u.role staff_role
            FROM record_movements m
            INNER JOIN records r ON r.id = m.record_id
            LEFT JOIN committees c ON c.id = r.committee_id
            LEFT JOIN users u ON u.id = m.updated_by
            WHERE r.document_type = 'Committee Referrals'
            AND m.id = (
                SELECT m_latest.id
                FROM record_movements m_latest
                WHERE m_latest.record_id = m.record_id
                ORDER BY m_latest.created_at DESC, m_latest.id DESC
                LIMIT 1
            )
            AND (r.committee_id IN ($placeholders)
                OR EXISTS (
                    SELECT 1 FROM record_committees rc_scope
                    WHERE rc_scope.record_id = r.id
                    AND rc_scope.committee_id IN ($placeholders)
                ))
            ORDER BY m.created_at DESC, m.id DESC
            LIMIT $staffUpdatesPerPage OFFSET $staffUpdatesOffset");
        $staffUpdateStmt->execute(array_merge($secretariatCommitteeIds, $secretariatCommitteeIds));
        $divisionChiefDashboard['staff_updates'] = $staffUpdateStmt->fetchAll();
    }

    $selfStmt = db()->prepare("SELECT u.id, u.name, u.nickname, u.role, u.email, u.is_active,
            (SELECT MAX(a.created_at) FROM audit_logs a WHERE a.user_id = u.id AND a.action = 'login') last_login_at,
            (SELECT a.action FROM audit_logs a WHERE a.user_id = u.id AND a.action IN ('login', 'logout') ORDER BY a.created_at DESC, a.id DESC LIMIT 1) latest_login_action
        FROM users u
        WHERE u.id = ?
        LIMIT 1");
    $selfStmt->execute([$secretariatId]);
    $selfRow = $selfStmt->fetch();
    if ($selfRow) {
        $pendingReferralCount = 0;
        if ($secretariatCommitteeIds) {
            $pendingPlaceholders = implode(',', array_fill(0, count($secretariatCommitteeIds), '?'));
            $pendingStmt = db()->prepare("SELECT COUNT(DISTINCT r.id)
                FROM records r
                WHERE r.document_type = 'Committee Referrals'
                AND r.status IN ('Assigned to the Committee', 'Pending to the Committee')
                AND (r.committee_id IN ($pendingPlaceholders)
                    OR EXISTS (
                        SELECT 1 FROM record_committees rc_scope
                        WHERE rc_scope.record_id = r.id
                        AND rc_scope.committee_id IN ($pendingPlaceholders)
                    ))");
            $pendingStmt->execute(array_merge($secretariatCommitteeIds, $secretariatCommitteeIds));
            $pendingReferralCount = (int) $pendingStmt->fetchColumn();
        }
        $selfRow['committee_count'] = $pendingReferralCount;
        $divisionChiefDashboard['staff'] = [$selfRow];
    }
}

if ($isDashboardMonitor) {
    $_SESSION['user'] = $authenticatedDashboardUser;
}
require __DIR__ . '/../app/partials/header.php';
if ($isDashboardMonitor) {
    $_SESSION['user'] = $dashboardMonitorUser;
}
?>
<script>document.body.classList.add('dashboard-page'<?= $usesCitySecretaryTabbedDashboard ? ", 'city-secretary-dashboard'" : '' ?><?= $isDashboardMonitor ? ", 'dashboard-monitoring'" : '' ?>);</script>
<div class="page-head">
    <div>
        <h1>Dashboard</h1>
        <p class="muted">Hello, <?= e($dashboardGreetingName) ?>. This is your personal dashboard. The date today is <?= e($dashboardCurrentDate) ?>.</p>
    </div>
    <div class="actions">
        <?php if (can_manage_users() && !$usesCitySecretaryTabbedDashboard): ?>
            <a class="btn secondary" href="<?= url('/users.php') ?>">User Creation</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($isDashboardMonitor): ?>
    <section class="dashboard-monitor-banner" aria-label="Administrator dashboard monitoring">
        <div>
            <span class="dashboard-monitor-badge">Read-only monitoring</span>
            <strong><?= e(role_label($requestedMonitorRole)) ?> Dashboard</strong>
            <p>Showing the dashboard and assignments for <?= e($dashboardMonitorUser['name'] ?? 'No active account') ?>.</p>
        </div>
        <form method="get" class="dashboard-monitor-account-form">
            <input type="hidden" name="monitor_role" value="<?= e($requestedMonitorRole) ?>">
            <?php if ($dashboardMonitorUsers): ?>
                <label>Account
                    <select name="monitor_user_id" onchange="this.form.submit()">
                        <?php foreach ($dashboardMonitorUsers as $monitorUser): ?>
                            <option value="<?= (int) $monitorUser['id'] ?>" <?= (int) $monitorUser['id'] === $dashboardMonitorUserId ? 'selected' : '' ?>>
                                <?= e($monitorUser['name']) ?><?= trim((string) ($monitorUser['division_name'] ?? '')) !== '' ? ' — ' . e($monitorUser['division_name']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php else: ?>
                <span class="muted">No active account exists for this user level.</span>
            <?php endif; ?>
            <a class="btn secondary" data-monitor-reset href="<?= url('/dashboard.php') ?>">Back to Administrator</a>
        </form>
    </section>
<?php endif; ?>
<?php if ($usesTabbedAssignedDashboard): ?>
    <section class="panel division-chief-tabs" style="margin-bottom:18px;">
        <nav class="dashboard-tabs division-chief-tab-nav" aria-label="Division Chief dashboard tabs">
            <button class="active" type="button" data-division-tab="new-referrals">
                Newly Assigned Referrals
                <?php if (in_array($userRole, ['division_chief', 'secretariat'], true) && (int) $divisionChiefDashboard['new_referrals_total'] > 0): ?>
                    <span class="tab-action-badge"><?= (int) $divisionChiefDashboard['new_referrals_total'] ?></span>
                <?php endif; ?>
            </button>
            <button type="button" data-division-tab="staff-updates">
                <?= $userRole === 'secretariat' ? 'Latest Updated Records' : 'Latest Staff Updates' ?>
                <span class="tab-today-badge" title="Records updated today">New <?= (int) $divisionChiefDashboard['updates_today_count'] ?></span>
                <?php if ($userRole === 'division_chief' && (int) $divisionChiefDashboard['review_needed_count'] > 0): ?>
                    <span class="tab-action-badge" title="Updates needing review"><?= (int) $divisionChiefDashboard['review_needed_count'] ?></span>
                <?php endif; ?>
            </button>
            <button type="button" data-division-tab="committees">Committee</button>
            <button type="button" data-division-tab="staff"><?= $userRole === 'secretariat' ? 'Account' : 'Staff' ?></button>
            <button type="button" data-division-tab="notes">
                Notes
                <span class="tab-action-badge" data-note-reminder-count title="Due reminders" <?= (int) $divisionChiefDashboard['due_reminder_count'] === 0 ? 'hidden' : '' ?>><?= (int) $divisionChiefDashboard['due_reminder_count'] ?></span>
            </button>
            <?php if ($isLawsAndRulesSecretariat): ?>
                <button type="button" data-division-tab="for-plenary">
                    For Plenary
                    <?php if ($cityForPlenaryActionCount > 0): ?><span class="tab-action-badge"><?= (int) $cityForPlenaryActionCount ?></span><?php endif; ?>
                </button>
            <?php endif; ?>
            <button type="button" data-division-tab="search">Search Record</button>
        </nav>

        <?php render_dashboard_record_filters(
            ['division_tab' => 'search', 'tab' => 'committee'],
            '/dashboard.php?division_tab=search',
            referral_statuses(),
            $committees
        ); ?>

        <div class="division-tab-panel active" data-division-panel="new-referrals">
            <h2>Newly Assigned Referrals</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Communication No.</th><th class="title-column">Title</th><th>Committee</th><th>Date</th><th class="status-column">Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($divisionChiefDashboard['new_referrals'] as $record): ?>
                        <tr>
                            <td>
                                <?= control_number_link($record) ?>
                                <?php if (current_secretariat_is_lead_committee($record)): ?><span class="lead-secretariat-label under-control">Lead<br>Committee Secretariat</span><?php endif; ?>
                            </td>
                            <td><?= e(display_record_title(record_title_for_current_user($record))) ?></td>
                            <td><?= e($record['committee_name'] ?? '') ?></td>
                            <td>
                                <?= e(display_datetime($record['updated_at'] ?? '')) ?>
                                <?php $recordAgeMarker = update_age_marker($record['updated_at'] ?? ''); ?>
                                <?php if ($recordAgeMarker): ?><span class="update-age-marker <?= e($recordAgeMarker['class']) ?>"><?= e($recordAgeMarker['label']) ?></span><?php endif; ?>
                            </td>
                            <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                            <td class="center-cell">
                                <?php if (can_update_record_status($record)): ?>
                                    <div class="review-action-stack">
                                        <?php if ($userRole === 'division_chief'): ?>
                                            <span class="review-needed-box">Needs Division Chief Action</span>
                                        <?php elseif ($userRole === 'secretariat'): ?>
                                            <span class="review-needed-box">Needs Action</span>
                                        <?php endif; ?>
                                        <a class="btn secondary small-btn<?= $userRole === 'secretariat' ? ' record-update-action' : '' ?>" href="<?= e(dashboard_action_url('/record_update.php?' . http_build_query([
                                            'id' => (int) $record['id'],
                                            'popup' => 1,
                                            'return' => 'dashboard',
                                            'division_tab' => 'new-referrals',
                                        ]))) ?>"><?= $userRole === 'secretariat' ? 'Update' : 'Action' ?></a>
                                    </div>
                                <?php else: ?>
                                    <a class="btn secondary small-btn record-view-action" href="<?= url('/record_view.php?id=') ?><?= (int) $record['id'] ?>">View Record</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$divisionChiefDashboard['new_referrals']): ?><tr><td colspan="6">No Committee Referrals assigned to your committees yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <h2 style="margin-top:18px;">Newly Updated Records</h2>
            <div class="record-list">
                <?php foreach ($divisionChiefDashboard['newly_updated_records'] as $record): ?>
                    <?php
                        $committeeRows = record_committee_rows((int) $record['id'], !empty($record['committee_id']) ? (int) $record['committee_id'] : null);
                        $committeeNames = $committeeRows
                            ? implode('; ', array_map(fn ($row) => $row['committee_name'], $committeeRows))
                            : ($record['committee_name'] ?? 'Unassigned');
                        $leadCommitteeName = lead_committee_name_from_rows($committeeRows);
                        $assignmentNames = record_assignment_names((int) $record['id'], !empty($record['committee_id']) ? (int) $record['committee_id'] : null);
                    ?>
                    <article class="record-card">
                        <div class="record-line record-topline">
                            <div>
                                <strong>Communication Number:</strong> <?= control_number_link($record) ?>
                                <?php if (current_secretariat_is_lead_committee($record)): ?><span class="lead-secretariat-label under-control">Lead<br>Committee Secretariat</span><?php endif; ?>
                            </div>
                            <div><strong>Type:</strong> <?= e($record['document_type'] ?? '') ?></div>
                            <div><strong>Date Received:</strong> <?= e(display_date($record['received_date'] ?? '')) ?></div>
                        </div>
                        <div class="record-line"><strong>Client / Origin:</strong> <?= e($record['client_name'] ?? '') ?></div>
                        <div class="record-line"><strong>Committee:</strong> <?= e($committeeNames) ?></div>
                        <?php if ($leadCommitteeName !== ''): ?>
                            <div class="record-line"><strong>Lead Committee:</strong> <?= e($leadCommitteeName) ?></div>
                        <?php endif; ?>
                        <div class="record-line record-title-line"><span class="record-title-text"><strong>Title:</strong> <?= nl2br(e(display_record_title(record_title_for_current_user($record)))) ?></span></div>
                        <div class="record-line record-highlight-line"><strong>Division:</strong> <?= e($assignmentNames['divisions'] ? implode('; ', $assignmentNames['divisions']) : 'Unassigned') ?></div>
                        <div class="record-line record-highlight-line"><strong>Assigned Secretariat:</strong> <?= e($assignmentNames['secretariats'] ? implode('; ', $assignmentNames['secretariats']) : 'Unassigned') ?></div>
                        <div class="record-line"><strong>Status:</strong> <span class="badge <?= e(status_class($record['status'] ?? '')) ?>"><?= e($record['status'] ?? '') ?></span></div>
                        <div class="record-line latest-update-line">
                            <strong>Latest Update:</strong>
                            <?= e($record['updated_by_name'] ?? '') ?> -
                            <?= e(display_datetime($record['movement_created_at'] ?? '')) ?> -
                            <span class="badge <?= e(status_class($record['movement_status'] ?? '')) ?>"><?= e($record['movement_status'] ?? '') ?></span>
                        </div>
                        <?php if (trim((string) ($record['movement_notes'] ?? '')) !== ''): ?>
                            <div class="record-line"><strong>Remarks:</strong> <?= nl2br(e($record['movement_notes'])) ?></div>
                        <?php endif; ?>
                        <div class="record-line record-actions"><strong>Action:</strong> <span class="actions"><a class="record-view-action" href="<?= url('/record_view.php?id=') ?><?= (int) $record['id'] ?>">View Record</a></span></div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$divisionChiefDashboard['newly_updated_records']): ?>
                    <article class="record-card">No newly updated records from your division yet.</article>
                <?php endif; ?>
            </div>
        </div>

        <div class="division-tab-panel" data-division-panel="staff-updates">
            <h2><?= $userRole === 'secretariat' ? 'Latest Updated Records' : 'Latest Staff Updates' ?></h2>
            <?php if ($userRole === 'division_chief'): ?>
                <form method="get" class="filters dashboard-filters staff-update-filters">
                    <input type="hidden" name="division_tab" value="staff-updates">
                    <label>Search
                        <input type="search" name="staff_update_search" placeholder="Any word or number" value="<?= e($staffUpdateSearch) ?>">
                    </label>
                    <label>From Date
                        <input type="date" name="staff_update_date_from" value="<?= e($staffUpdateDateFrom) ?>">
                    </label>
                    <label>To Date
                        <input type="date" name="staff_update_date_to" value="<?= e($staffUpdateDateTo) ?>">
                    </label>
                    <button class="btn compact-filter-btn records-filter-action" type="submit">Search</button>
                    <a class="btn secondary compact-filter-btn records-filter-action" href="<?= url('/dashboard.php?division_tab=staff-updates') ?>">Clear</a>
                </form>
            <?php endif; ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Communication No.</th><th class="title-column">Title</th><th>Staff</th><th class="update-column">Update</th><th>Remarks</th><th>Date</th><th><?= $userRole === 'division_chief' ? 'Comment' : 'Action' ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($divisionChiefDashboard['staff_updates'] as $update): ?>
                        <?php
                            $isLatestSecretariatUpdate = (int) ($update['is_latest_secretariat_update'] ?? 0) === 1;
                            $reviewNeeded = $userRole === 'division_chief'
                                && $isLatestSecretariatUpdate
                                && trim((string) ($update['chief_remarks'] ?? '')) === '';
                        ?>
                        <tr>
                            <?php
                                $staffUpdateRecordParams = [
                                    'id' => (int) $update['id'],
                                    'popup' => 1,
                                    'return' => 'dashboard',
                                    'division_tab' => 'staff-updates',
                                    'staff_updates_page' => $staffUpdatesPage,
                                ];
                                if ($staffUpdateDateFrom !== '') {
                                    $staffUpdateRecordParams['staff_update_date_from'] = $staffUpdateDateFrom;
                                }
                                if ($staffUpdateDateTo !== '') {
                                    $staffUpdateRecordParams['staff_update_date_to'] = $staffUpdateDateTo;
                                }
                                if ($staffUpdateSearch !== '') {
                                    $staffUpdateRecordParams['staff_update_search'] = $staffUpdateSearch;
                                }
                            ?>
                            <td><?= control_number_link($update, url('/record_view.php?' . http_build_query($staffUpdateRecordParams))) ?></td>
                            <td><?= e(display_record_title($update['title'] ?? '')) ?></td>
                            <td><?= e($update['staff_name'] ?? 'Staff') ?></td>
                            <td><?= e($update['movement_status'] ?? '') ?></td>
                            <td><?= nl2br(e($update['movement_notes'] ?? '')) ?></td>
                            <td>
                                <?= e(display_datetime($update['movement_created_at'] ?? '')) ?>
                                <?php $updateAgeMarker = update_age_marker($update['movement_created_at'] ?? ''); ?>
                                <?php if ($updateAgeMarker): ?><span class="update-age-marker <?= e($updateAgeMarker['class']) ?>"><?= e($updateAgeMarker['label']) ?></span><?php endif; ?>
                            </td>
                            <td class="center-cell">
                                <?php if ($userRole === 'division_chief'): ?>
                                    <?php if ($isLatestSecretariatUpdate): ?>
                                        <div class="review-action-stack">
                                            <?php if ($reviewNeeded): ?>
                                                <span class="review-needed-box">Review Needed</span>
                                            <?php endif; ?>
                                            <a class="print-link small-action-link" href="<?= url('/record_update_edit.php?movement_id=') ?><?= (int) $update['movement_id'] ?>&amp;return=dashboard&amp;staff_updates_page=<?= (int) $staffUpdatesPage ?>">
                                                <?= trim((string) ($update['chief_remarks'] ?? '')) !== '' ? 'Edit Comment' : 'Add Comment' ?>
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <span class="muted">Closed</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <a class="print-link small-action-link record-view-action" href="<?= url('/record_view.php?id=') ?><?= (int) $update['id'] ?>&amp;popup=1&amp;return=dashboard&amp;division_tab=staff-updates">View Record</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$divisionChiefDashboard['staff_updates']): ?><tr><td colspan="7">No staff updates yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
                $staffUpdatesTotalPages = max(1, (int) ceil($divisionChiefDashboard['staff_updates_total'] / $staffUpdatesPerPage));
            ?>
            <?php render_staff_updates_pagination($staffUpdatesPage, $staffUpdatesTotalPages); ?>
        </div>

        <div class="division-tab-panel" data-division-panel="committees">
            <h2>Committees</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Committee</th><th>Records</th><th>Members</th></tr></thead>
                    <tbody>
                    <?php foreach ($divisionChiefDashboard['committees'] as $committee): ?>
                        <tr>
                            <td><?= e($committee['name']) ?></td>
                            <td class="center-cell">
                                <a class="print-link" href="<?= url('/records.php?tab=committee&amp;committee_id=') ?><?= (int) $committee['id'] ?>&amp;sort=updated">
                                    <?= (int) ($committee['record_count'] ?? 0) ?>
                                </a>
                            </td>
                            <td class="center-cell"><a class="btn secondary small-btn" href="<?= url('/committee_roster.php?committee_id=') ?><?= (int) $committee['id'] ?>&amp;popup=1&amp;return=dashboard&amp;division_tab=committees">See Members</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$divisionChiefDashboard['committees']): ?><tr><td colspan="3">No committees assigned yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="division-tab-panel" data-division-panel="staff">
            <h2><?= $userRole === 'secretariat' ? 'Account' : 'Staff' ?></h2>
            <p class="muted"><?= e($divisionChiefDashboard['division_name'] !== '' ? $divisionChiefDashboard['division_name'] : 'No division assigned') ?></p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Full Name</th><th>Nickname</th><th>Role</th><th>Pending Referrals</th><th>Account</th><th>Login</th></tr></thead>
                    <tbody>
                    <?php foreach ($divisionChiefDashboard['staff'] as $staff): ?>
                        <?php
                            $loginStatus = 'No login yet';
                            if (($staff['latest_login_action'] ?? '') === 'login') {
                                $loginStatus = 'Logged in';
                            } elseif (!empty($staff['last_login_at'])) {
                                $loginStatus = 'Last logged in: ' . display_datetime($staff['last_login_at']);
                            }
                        ?>
                        <tr>
                            <td><?= e($staff['name']) ?></td>
                            <td><?= e($staff['nickname'] ?? '') ?></td>
                            <td><?= e(role_label($staff['role'])) ?></td>
                            <td class="center-cell">
                                <?php if ((int) ($staff['committee_count'] ?? 0) > 0): ?>
                                    <a class="print-link" href="<?= url('/records.php?tab=committee&amp;staff_user_id=') ?><?= (int) $staff['id'] ?>&amp;sort=updated&amp;return_tab=staff&amp;pending=1">
                                        <?= (int) $staff['committee_count'] ?>
                                    </a>
                                <?php else: ?>
                                    0
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $staff['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                            <td><?= e($loginStatus) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$divisionChiefDashboard['staff']): ?><tr><td colspan="6"><?= $userRole === 'secretariat' ? 'No account details found.' : 'No staff assigned to this division yet.' ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php require __DIR__ . '/../app/partials/personal_notes_dashboard_panel.php'; ?>

        <div class="division-tab-panel" data-division-panel="search">
            <h2>Search Record</h2>

            <div class="table-wrap" style="margin-top:14px;">
                <table>
                    <thead><tr><th>Communication No.</th><th class="title-column">Title</th><th>Committee</th><th class="status-column">Status</th><th class="updated-column">Updated</th></tr></thead>
                    <tbody>
                    <?php foreach ($assignedReferrals as $record): ?>
                        <tr>
                            <td><?= control_number_link($record) ?></td>
                            <td><?= e(display_record_title(record_title_for_current_user($record))) ?></td>
                            <td><?= e($record['committee_name'] ?? '') ?></td>
                            <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                            <td><?= e(display_datetime($record['updated_at'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$assignedReferrals): ?><tr><td colspan="5">No matching records found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($isLawsAndRulesSecretariat): ?>
            <?php require __DIR__ . '/../app/partials/for_plenary_dashboard_panel.php'; ?>
        <?php endif; ?>

    </section>
<?php endif; ?>

<?php if ($showStats): ?>
    <section class="grid stats">
        <a class="stat stat-link" href="<?= url('/records.php?tab=all') ?>" aria-label="View all <?= $totalRecords ?> records">
            <span>Total Records</span><strong><?= $totalRecords ?></strong>
        </a>
        <?php foreach ($totals as $row): ?>
            <?php
                if ($isFullDashboard && ($row['status'] ?? '') === 'Completed') {
                    continue;
                }
                $statusLabel = trim((string) ($row['status'] ?? '')) !== '' ? $row['status'] : 'No Status';
                $statusFilter = trim((string) ($row['status'] ?? '')) !== '' ? $row['status'] : '__blank__';
            ?>
            <a class="stat stat-link" href="<?= url('/records.php?tab=all&amp;status=') ?><?= urlencode($statusFilter) ?>" aria-label="View <?= (int) $row['total'] ?> records with status <?= e($statusLabel) ?>">
                <span><?= e($statusLabel) ?></span><strong><?= (int) $row['total'] ?></strong>
            </a>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($usesCitySecretaryTabbedDashboard): ?>
    <section class="panel division-chief-tabs" style="margin-bottom:18px;">
        <nav class="dashboard-tabs division-chief-tab-nav" aria-label="Legislative dashboard tabs">
            <?php if (!$usesAdministrativeSupportDashboard): ?>
                <button class="active" type="button" data-division-tab="review">
                    Review
                    <?php if ($cityReviewActionCount > 0): ?>
                        <span class="tab-action-badge"><?= (int) $cityReviewActionCount ?></span>
                    <?php endif; ?>
                </button>
                <button type="button" data-division-tab="committee-referrals">Committee Referrals</button>
                <button type="button" data-division-tab="certified-urgent">Certified Urgent</button>
                <button type="button" data-division-tab="administrative-documents">
                    Transmittals, Letters and Endorsements
                    <?php if ($cityAdministrativeDocumentsActionCount > 0): ?>
                        <span class="tab-action-badge"><?= (int) $cityAdministrativeDocumentsActionCount ?></span>
                    <?php endif; ?>
                </button>
                <button type="button" data-division-tab="memoranda">
                    Memo and EO's
                    <?php if ($cityMemorandaActionCount > 0): ?>
                        <span class="tab-action-badge"><?= (int) $cityMemorandaActionCount ?></span>
                    <?php endif; ?>
                </button>
                <?php if ($showTransmittals): ?>
                    <button type="button" data-division-tab="transmittals">Transmittals</button>
                <?php endif; ?>
                <button type="button" data-division-tab="for-plenary">
                    For Plenary
                    <?php if ($cityForPlenaryActionCount > 0): ?>
                        <span class="tab-action-badge"><?= (int) $cityForPlenaryActionCount ?></span>
                    <?php endif; ?>
                </button>
            <?php else: ?>
                <a class="<?= $administrativeSupportCityTab === 'transmittals' ? 'active' : '' ?>" href="<?= url('/dashboard.php?city_tab=transmittals') ?>">Transmittals</a>
            <?php endif; ?>
            <?php if ($usesAdministrativeSupportDashboard): ?>
                <a class="<?= $administrativeSupportCityTab === 'approved-plenary' ? 'active' : '' ?>" href="<?= url('/dashboard.php?city_tab=approved-plenary') ?>">Approved in the Plenary</a>
            <?php else: ?>
                <button type="button" data-division-tab="approved-plenary">
                    Approved in the Plenary
                    <?php if ($cityApprovedPlenaryActionCount > 0): ?>
                        <span class="tab-action-badge"><?= (int) $cityApprovedPlenaryActionCount ?></span>
                    <?php endif; ?>
                </button>
            <?php endif; ?>
            <?php if (!$usesAdministrativeSupportDashboard): ?>
                <button type="button" data-division-tab="recent">Recently Updated</button>
                <button type="button" data-division-tab="logs">Logs</button>
            <?php endif; ?>
            <?php if ($usesAdministrativeSupportDashboard): ?>
                <a class="<?= $administrativeSupportCityTab === 'notes' ? 'active' : '' ?>" href="<?= url('/dashboard.php?city_tab=notes') ?>">
                    Notes
                    <span class="tab-action-badge" data-note-reminder-count title="Due reminders" <?= (int) $divisionChiefDashboard['due_reminder_count'] === 0 ? 'hidden' : '' ?>><?= (int) $divisionChiefDashboard['due_reminder_count'] ?></span>
                </a>
            <?php else: ?>
                <button type="button" data-division-tab="notes">
                    Notes
                    <span class="tab-action-badge" data-note-reminder-count title="Due reminders" <?= (int) $divisionChiefDashboard['due_reminder_count'] === 0 ? 'hidden' : '' ?>><?= (int) $divisionChiefDashboard['due_reminder_count'] ?></span>
                </button>
            <?php endif; ?>
        </nav>

        <?php render_dashboard_record_filters(
            ['city_tab' => 'search', 'tab' => $activeTab],
            '/dashboard.php?city_tab=search',
            $statuses,
            $committees,
            $activeTab === 'committee'
        ); ?>

        <div class="division-tab-panel <?= !$usesAdministrativeSupportDashboard ? 'active' : '' ?>" data-division-panel="review">
            <h2>Committee Assignment</h2>
            <p class="muted">Committee Referrals waiting for committee assignment.</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Communication No.</th><th class="title-column">Title</th><th>Client / Origin</th><th>Date Received</th><th class="status-column">Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($unassignedReferrals as $record): ?>
                        <tr>
                            <td>
                                <?= control_number_link($record) ?>
                                <?php if (record_has_pending_receiving_staff_comment($record)): ?>
                                    <span class="receiving-comment-tag">Waiting for Correction</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e(display_record_title(record_title_for_current_user($record))) ?></td>
                            <td><?= e($record['client_name'] ?? '') ?></td>
                            <td><?= e(display_date($record['received_date'] ?? '')) ?></td>
                            <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                            <td class="center-cell">
                                <?php if (record_has_pending_receiving_staff_comment($record)): ?>
                                    <a class="print-link small-action-link record-view-action" href="<?= url('/record_view.php?id=') ?><?= (int) $record['id'] ?>">View Record</a>
                                <?php else: ?>
                                    <a class="print-link small-action-link record-review-action" href="<?= e(dashboard_action_url('/record_form.php?' . http_build_query([
                                        'id' => (int) $record['id'],
                                        'popup' => 1,
                                        'return' => 'dashboard',
                                        'city_tab' => 'review',
                                    ]))) ?>">Review</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$unassignedReferrals): ?><tr><td colspan="6">No Committee Referrals waiting for review.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!$usesAdministrativeSupportDashboard): ?>
        <div class="division-tab-panel" data-division-panel="committee-referrals">
            <div class="panel-title-row">
                <div>
                    <h2>Committee Referrals</h2>
                    <p class="muted">All Committee Referral records, ordered by the latest activity.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Communication No.</th><th class="title-column">Title</th><th>Client / Origin</th><th>Committee(s)</th><th class="status-column">Status</th><th class="updated-column">Updated</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($citySecretaryDashboard['committee_referrals'] as $record): ?>
                        <tr>
                            <td><?= control_number_link($record) ?></td>
                            <td><strong><?= e(display_record_title(record_title_for_current_user($record))) ?></strong></td>
                            <td><?= e(($record['client_name'] ?? '') !== '' ? $record['client_name'] : ($record['origin'] ?? '')) ?></td>
                            <td><?= e(($record['committee_names'] ?? '') !== '' ? $record['committee_names'] : 'For Committee Assignment') ?></td>
                            <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                            <td><?= e(display_datetime($record['updated_at'] ?? '')) ?></td>
                            <td class="center-cell"><a class="small-action-link record-view-action" href="<?= url('/record_view.php?id=') ?><?= (int) $record['id'] ?>">View Record</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$citySecretaryDashboard['committee_referrals']): ?><tr><td colspan="7">No Committee Referral records found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="division-tab-panel" data-division-panel="certified-urgent">
            <div class="panel-title-row">
                <div>
                    <h2>Certified Urgent</h2>
                    <p class="muted">All Certified Urgent records, ordered by the latest activity.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Communication No.</th><th class="title-column">Title</th><th>Client / Origin</th><th>Date Received</th><th class="status-column">Status</th><th class="updated-column">Updated</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($citySecretaryDashboard['certified_urgent'] as $record): ?>
                        <tr>
                            <td><?= control_number_link($record) ?></td>
                            <td><strong><?= e(display_record_title(record_title_for_current_user($record))) ?></strong></td>
                            <td><?= e(($record['origin'] ?? '') !== '' ? $record['origin'] : ($record['client_name'] ?? '')) ?></td>
                            <td><?= e(display_date($record['received_date'] ?? '')) ?></td>
                            <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                            <td><?= e(display_datetime($record['updated_at'] ?? '')) ?></td>
                            <td class="center-cell"><a class="small-action-link record-view-action" href="<?= url('/record_view.php?id=') ?><?= (int) $record['id'] ?>">View Record</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$citySecretaryDashboard['certified_urgent']): ?><tr><td colspan="7">No Certified Urgent records found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$usesAdministrativeSupportDashboard): ?>
        <div class="division-tab-panel" data-division-panel="administrative-documents">
            <div class="panel-title-row">
                <div>
                    <h2>Transmittals, Letters and Endorsements</h2>
                    <p class="muted">Documents for City Secretary review and action.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table class="administrative-documents-table">
                    <thead>
                        <tr>
                            <th>Communication No.</th>
                            <th>Type</th>
                            <th class="title-column">Title</th>
                            <th>Client / Origin</th>
                            <th>Date Received</th>
                            <th class="status-column">Status</th>
                            <th class="updated-column">Updated</th>
                            <th class="administrative-action-column">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($citySecretaryDashboard['administrative_documents'] as $record): ?>
                        <tr>
                            <td><?= control_number_link($record) ?></td>
                            <td><?= e($record['document_type'] ?? '') ?></td>
                            <td><?= e(display_record_title(record_title_for_current_user($record))) ?></td>
                            <td><?= e(($record['origin'] ?? '') !== '' ? $record['origin'] : ($record['client_name'] ?? '')) ?></td>
                            <td><?= e(display_date($record['received_date'] ?? '')) ?></td>
                            <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                            <td><?= e(display_datetime($record['updated_at'] ?? '')) ?></td>
                            <td class="center-cell administrative-action-cell">
                                <div class="administrative-document-actions">
                                    <?php if (can_edit_record($record)): ?>
                                        <a class="print-link small-action-link record-review-action" href="<?= e(dashboard_action_url('/record_form.php?' . http_build_query([
                                            'id' => (int) $record['id'],
                                            'popup' => 1,
                                            'return' => 'dashboard',
                                            'return_url' => '/dashboard.php?city_tab=administrative-documents',
                                        ]))) ?>">Review</a>
                                    <?php endif; ?>
                                    <a class="print-link small-action-link record-view-action" href="<?= url('/record_view.php?id=') ?><?= (int) $record['id'] ?>">View Record</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$citySecretaryDashboard['administrative_documents']): ?>
                        <tr><td colspan="8">No administrative records found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="division-tab-panel" data-division-panel="memoranda">
            <div class="panel-title-row">
                <div>
                    <h2>Memorandum, Executive Orders and Directives</h2>
                    <p class="muted">Memorandum and executive-order records for City Secretary review and action.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table class="administrative-documents-table">
                    <thead>
                        <tr>
                            <th>Communication No.</th>
                            <th>Type</th>
                            <th class="title-column">Title</th>
                            <th>Client / Origin</th>
                            <th>Date Received</th>
                            <th class="status-column">Status</th>
                            <th class="updated-column">Updated</th>
                            <th class="administrative-action-column">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($citySecretaryDashboard['memoranda'] as $record): ?>
                        <tr>
                            <td><?= control_number_link($record) ?></td>
                            <td><?= e($record['document_type'] ?? '') ?></td>
                            <td><?= e(display_record_title(record_title_for_current_user($record))) ?></td>
                            <td><?= e(($record['origin'] ?? '') !== '' ? $record['origin'] : ($record['client_name'] ?? '')) ?></td>
                            <td><?= e(display_date($record['received_date'] ?? '')) ?></td>
                            <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                            <td><?= e(display_datetime($record['updated_at'] ?? '')) ?></td>
                            <td class="center-cell administrative-action-cell">
                                <div class="administrative-document-actions">
                                    <?php if (can_edit_record($record)): ?>
                                        <a class="print-link small-action-link record-review-action" href="<?= e(dashboard_action_url('/record_form.php?' . http_build_query([
                                            'id' => (int) $record['id'],
                                            'popup' => 1,
                                            'return' => 'dashboard',
                                            'return_url' => '/dashboard.php?city_tab=memoranda',
                                        ]))) ?>">Review</a>
                                    <?php endif; ?>
                                    <a class="print-link small-action-link record-view-action" href="<?= url('/record_view.php?') ?><?= e(http_build_query([
                                        'id' => (int) $record['id'],
                                        'popup' => 1,
                                        'return' => 'dashboard',
                                        'return_url' => '/dashboard.php?city_tab=memoranda',
                                    ])) ?>">View Record</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$citySecretaryDashboard['memoranda']): ?>
                        <tr><td colspan="8">No memorandum or executive-order records found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($showTransmittals): ?>
        <div class="division-tab-panel <?= $usesAdministrativeSupportDashboard && $administrativeSupportCityTab === 'transmittals' ? 'active' : '' ?>" data-division-panel="transmittals">
            <h2>Transmittals</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Communication No.</th><th class="title-column">Title</th><th>Client / Origin</th><th>Contact Number</th><th class="status-column">Status</th><th class="updated-column">Updated</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($transmittals as $record): ?>
                        <tr>
                            <td><?= control_number_link($record) ?></td>
                            <td><?= e(display_record_title(record_title_for_current_user($record))) ?></td>
                            <td><?= e(($record['origin'] ?? '') !== '' ? $record['origin'] : ($record['client_name'] ?? '')) ?></td>
                            <td><?= can_view_transmittal_contact_details($record) ? e($record['contact_number'] ?? '') : '' ?></td>
                            <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                            <td><?= e(display_datetime($record['updated_at'] ?? '')) ?></td>
                            <td class="center-cell">
                                <?php if (can_manage_transmittal_recipients($record)): ?>
                                    <a class="print-link small-action-link" href="<?= e(dashboard_action_url('/record_recipients.php?record_id=' . (int) $record['id'])) ?>">Add Recipients</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$transmittals): ?><tr><td colspan="7">No records for transmittal found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="division-tab-panel" data-division-panel="for-plenary">
            <h2>For Plenary</h2>
            <p class="muted">Committee Referrals and Certified Urgent records ready for or scheduled for plenary.</p>
            <div class="actions plenary-print-controls">
                <a class="btn" href="<?= e(dashboard_action_url('/for_plenary_print.php')) ?>" target="_blank">Print Result</a>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Communication No.</th><th class="title-column">Title</th><th>Committee</th><th class="status-column">Status</th><th class="updated-column">Updated</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($citySecretaryDashboard['for_plenary'] as $record): ?>
                        <?php
                            $committeeRowsForRecord = record_committee_rows((int) $record['id'], !empty($record['committee_id']) ? (int) $record['committee_id'] : null);
                            $committeeNamesForRecord = $committeeRowsForRecord
                                ? implode('; ', array_map(fn ($row) => $row['committee_name'], $committeeRowsForRecord))
                                : (($record['document_type'] ?? '') === 'Certified Urgent' ? 'Certified Urgent' : ($record['committee_name'] ?? 'Unassigned'));
                            $proposedLabel = '';
                            $proposedNumber = '';
                            if (trim((string) ($record['proposed_ordinance_number'] ?? '')) !== '') {
                                $proposedLabel = 'Proposed Ordinance';
                                $proposedNumber = (string) $record['proposed_ordinance_number'];
                            } elseif (trim((string) ($record['proposed_resolution_number'] ?? '')) !== '') {
                                $proposedLabel = 'Proposed Resolution';
                                $proposedNumber = (string) $record['proposed_resolution_number'];
                            }
                            $plenaryDate = ($record['status'] ?? '') === 'Scheduled for Plenary'
                                && trim((string) ($record['plenary_session_date'] ?? '')) !== ''
                                    ? display_date($record['plenary_session_date'])
                                    : '';
                        ?>
                        <tr>
                            <td><?= control_number_link($record) ?></td>
                            <td><?= e(display_record_title(record_title_for_current_user($record))) ?></td>
                            <td><?= e($committeeNamesForRecord) ?></td>
                            <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                            <td><?= e(display_datetime($record['updated_at'] ?? '')) ?></td>
                            <td class="center-cell for-plenary-action-cell">
                                <div class="for-plenary-actions">
                                    <?php if ($isFullDashboard && can_edit_record($record)): ?>
                                        <a class="print-link small-action-link record-edit-action" href="<?= e(dashboard_action_url('/record_form.php?' . http_build_query([
                                            'id' => (int) $record['id'],
                                            'popup' => 1,
                                            'return' => 'dashboard',
                                            'return_url' => '/dashboard.php?city_tab=for-plenary',
                                        ]))) ?>">Edit</a>
                                    <?php endif; ?>
                                    <?php if (can_update_record_status($record)): ?>
                                        <a class="print-link small-action-link record-update-action" href="<?= e(dashboard_action_url('/record_update.php?' . http_build_query([
                                            'id' => (int) $record['id'],
                                            'popup' => 1,
                                            'return' => 'dashboard',
                                            'city_tab' => 'for-plenary',
                                        ]))) ?>"><?= ($record['status'] ?? '') === 'Scheduled for Plenary' ? 'Reschedule' : 'Schedule Plenary' ?></a>
                                    <?php endif; ?>
                                    <?php if (can_assign_plenary_numbers($record)): ?><a class="print-link small-action-link" href="<?= e(dashboard_action_url('/plenary_number_form.php?' . http_build_query([
                                        'id' => (int) $record['id'],
                                        'popup' => 1,
                                        'return' => 'dashboard',
                                        'city_tab' => 'for-plenary',
                                    ]))) ?>">Assign Proposed No.</a><?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php if ($proposedNumber !== '' || $plenaryDate !== ''): ?>
                            <tr class="plenary-detail-row">
                                <td colspan="6">
                                    <span>
                                        <?php if ($proposedNumber !== ''): ?>
                                            <strong><?= e($proposedLabel) ?>:</strong> <?= e($proposedNumber) ?>
                                        <?php endif; ?>
                                        <?php if ($proposedNumber !== '' && $plenaryDate !== ''): ?>
                                            <span class="detail-separator">|</span>
                                        <?php endif; ?>
                                        <?php if ($plenaryDate !== ''): ?>
                                            <strong>Scheduled Plenary Date:</strong> <?= e($plenaryDate) ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (!$citySecretaryDashboard['for_plenary']): ?><tr><td colspan="6">No records are currently for plenary.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="division-tab-panel <?= $usesAdministrativeSupportDashboard && $administrativeSupportCityTab === 'approved-plenary' ? 'active' : '' ?>" data-division-panel="approved-plenary">
            <h2>Approved in the Plenary</h2>
            <p class="muted">Ordinances and resolutions approved during plenary session.</p>
            <form method="get" class="filters dashboard-filters">
                <input type="hidden" name="city_tab" value="approved-plenary">
                <label>Date Approved
                    <input type="date" name="approved_date" value="<?= e($approvedDate) ?>">
                </label>
                <label>Type
                    <select name="approved_type">
                        <option value="">All Types</option>
                        <option value="ordinance" <?= $approvedType === 'ordinance' ? 'selected' : '' ?>>Ordinance</option>
                        <option value="resolution" <?= $approvedType === 'resolution' ? 'selected' : '' ?>>Resolution</option>
                    </select>
                </label>
                <button class="btn secondary records-filter-action" type="submit">Filter</button>
                <a class="btn secondary records-filter-action" href="<?= url('/dashboard.php?city_tab=approved-plenary') ?>">Clear</a>
            </form>
            <?php if ($approvedDate !== '' || $approvedType !== ''): ?>
                <p class="muted plenary-filter-summary">
                    Showing approved records
                    <?php if ($approvedDate !== ''): ?> for Date Approved: <strong><?= e(display_date($approvedDate)) ?></strong><?php endif; ?>
                    <?php if ($approvedType !== ''): ?> <?= $approvedDate !== '' ? 'and' : 'for' ?> Type: <strong><?= e(ucfirst($approvedType)) ?></strong><?php endif; ?>
                </p>
            <?php endif; ?>
            <?php
                $approvedPlenaryReturnParams = ['city_tab' => 'approved-plenary'];
                if ($approvedDate !== '') {
                    $approvedPlenaryReturnParams['approved_date'] = $approvedDate;
                }
                if ($approvedType !== '') {
                    $approvedPlenaryReturnParams['approved_type'] = $approvedType;
                }
                $approvedPlenaryReturnUrl = '/dashboard.php?' . http_build_query($approvedPlenaryReturnParams);
            ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Communication No.</th>
                            <th>Type</th>
                            <th class="title-column">Title</th>
                            <th>Committee</th>
                            <th>Ordinance / Resolution No.</th>
                            <th>Date Approved</th>
                            <th class="updated-column">Updated</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($citySecretaryDashboard['approved_plenary'] as $record): ?>
                        <?php
                            $committeeRowsForRecord = record_committee_rows((int) $record['id'], !empty($record['committee_id']) ? (int) $record['committee_id'] : null);
                            $committeeNamesForRecord = $committeeRowsForRecord
                                ? implode('; ', array_map(fn ($row) => $row['committee_name'], $committeeRowsForRecord))
                                : ($record['committee_name'] ?? 'Unassigned');
                            $approvedNumber = trim((string) ($record['approved_ordinance_number'] ?? ''));
                            $approvedNumberLabel = $approvedNumber !== '' ? 'Ordinance' : '';
                            if ($approvedNumber === '') {
                                $approvedNumber = trim((string) ($record['approved_resolution_number'] ?? ''));
                                $approvedNumberLabel = $approvedNumber !== '' ? 'Resolution' : '';
                            }
                            $approvedEditUrl = dashboard_action_url('/record_form.php?' . http_build_query(['id' => (int) $record['id'], 'popup' => 1, 'return' => 'dashboard', 'return_url' => $approvedPlenaryReturnUrl]));
                        ?>
                        <tr>
                            <td><?= control_number_link($record) ?></td>
                            <td><?= e($approvedNumberLabel !== '' ? $approvedNumberLabel : 'Not set') ?></td>
                            <td><?= e(display_record_title(record_title_for_current_user($record))) ?></td>
                            <td><?= e($committeeNamesForRecord) ?></td>
                            <td>
                                <?php if ($approvedNumber !== ''): ?>
                                    <strong><?= e($approvedNumberLabel) ?>:</strong> <?= e($approvedNumber) ?>
                                <?php else: ?>
                                    <span class="muted">Not set</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e(display_date($record['plenary_approved_date'] ?? '')) ?></td>
                            <td><?= e(display_datetime($record['updated_at'] ?? '')) ?></td>
                            <td class="center-cell">
                                <a class="print-link small-action-link record-view-action" href="<?= e('/record_view.php?' . http_build_query([
                                    'id' => (int) $record['id'],
                                    'popup' => 1,
                                    'return' => 'dashboard',
                                    'return_url' => $approvedPlenaryReturnUrl,
                                ])) ?>">View Record</a>
                                <?php if (in_array($userRole, ['admin', 'city_secretary'], true) && can_edit_record($record)): ?>
                                    <span class="muted"> | </span>
                                    <a class="print-link small-action-link record-edit-action" href="<?= e($approvedEditUrl) ?>">Edit</a>
                                <?php endif; ?>
                                <?php if (can_update_record_status($record)): ?>
                                    <span class="muted"> | </span>
                                    <a class="print-link small-action-link record-update-action" href="<?= e(dashboard_action_url('/record_update.php?' . http_build_query([
                                        'id' => (int) $record['id'],
                                        'return' => 'dashboard',
                                        'city_tab' => 'approved-plenary',
                                    ]))) ?>"><?= $usesAdministrativeSupportDashboard ? 'New Update Status' : 'Update Status' ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$citySecretaryDashboard['approved_plenary']): ?><tr><td colspan="8">No records are approved in the plenary.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="division-tab-panel" data-division-panel="recent">
            <h2>Recently Updated Committee Referrals</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Communication No.</th><th class="title-column">Title</th><th>Committee</th><th class="status-column">Status</th><th class="updated-column">Updated</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentCommitteeReferrals as $record): ?>
                        <tr>
                            <td><?= control_number_link($record) ?></td>
                            <td><?= e(display_record_title(record_title_for_current_user($record))) ?></td>
                            <td><?= e($record['committee_name'] ?? 'Unassigned') ?></td>
                            <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                            <td><?= e(display_datetime($record['updated_at'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recentCommitteeReferrals): ?><tr><td colspan="5">No recently updated Committee Referrals found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php render_recent_referrals_pagination($recentReferralsPage, $recentCommitteeTotalPages, true); ?>

            <?php if ($showTransmittals): ?>
                <h2 style="margin-top:18px;">Recently Updated Transmittals</h2>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Communication No.</th><th class="title-column">Title</th><th>Client / Origin</th><th class="status-column">Status</th><th class="updated-column">Updated</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentTransmittals as $record): ?>
                            <tr>
                                <td><?= control_number_link($record) ?></td>
                                <td><?= e(display_record_title(record_title_for_current_user($record))) ?></td>
                                <td><?= e(($record['origin'] ?? '') !== '' ? $record['origin'] : ($record['client_name'] ?? '')) ?></td>
                                <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                                <td><?= e(display_datetime($record['updated_at'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$recentTransmittals): ?><tr><td colspan="5">No recently updated transmittal records found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="division-tab-panel" data-division-panel="logs">
            <h2>Staff and Division Chief Logs</h2>
            <p class="muted">System activity from August 10, 2026 onward for the Admin Receiving Section, secretariat, division staff, and division chiefs.</p>
            <?php if (!$citySecretaryDashboard['staff_logs_available']): ?>
                <p class="muted">Audit logs are not available yet. Import the audit log migration to enable this tab.</p>
            <?php else: ?>
                <form method="get" class="filters dashboard-filters" style="grid-template-columns: 180px 180px auto auto;">
                    <input type="hidden" name="city_tab" value="logs">
                    <label>From Date
                        <input type="date" name="staff_log_date_from" value="<?= e($staffLogDateFrom) ?>" min="<?= e($staffLogMinimumDate) ?>">
                    </label>
                    <label>To Date
                        <input type="date" name="staff_log_date_to" value="<?= e($staffLogDateTo) ?>" min="<?= e($staffLogMinimumDate) ?>">
                    </label>
                    <button class="btn secondary records-filter-action" type="submit">Filter</button>
                    <a class="btn secondary records-filter-action" href="<?= url('/dashboard.php?city_tab=logs') ?>">Clear</a>
                </form>
                <p class="muted"><?= (int) $citySecretaryDashboard['staff_logs_total'] ?> matching log entr<?= (int) $citySecretaryDashboard['staff_logs_total'] === 1 ? 'y' : 'ies' ?>.</p>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Date/Time</th><th>User</th><th>Role</th><th>Division</th><th>IP Address</th><th>Action</th><th>Record</th><th>Description</th></tr></thead>
                        <tbody>
                        <?php foreach ($citySecretaryDashboard['staff_logs'] as $log): ?>
                            <?php
                                $userLogUrl = '/log_history.php?' . http_build_query([
                                    'view' => 'user',
                                    'id' => (int) $log['user_id'],
                                    'popup' => 1,
                                    'return_url' => '/dashboard.php?' . http_build_query(array_filter([
                                        'city_tab' => 'logs',
                                        'staff_logs_page' => $staffLogsPage,
                                        'staff_log_date_from' => $staffLogDateFrom,
                                        'staff_log_date_to' => $staffLogDateTo,
                                    ], fn ($value) => $value !== '')),
                                ]);
                                $recordLogUrl = !empty($log['record_id'])
                                    ? '/log_history.php?' . http_build_query([
                                        'view' => 'record',
                                        'id' => (int) $log['record_id'],
                                        'popup' => 1,
                                        'return_url' => '/dashboard.php?' . http_build_query(array_filter([
                                            'city_tab' => 'logs',
                                            'staff_logs_page' => $staffLogsPage,
                                            'staff_log_date_from' => $staffLogDateFrom,
                                            'staff_log_date_to' => $staffLogDateTo,
                                        ], fn ($value) => $value !== '')),
                                    ])
                                    : '';
                            ?>
                            <tr>
                                <td><?= e(display_datetime($log['created_at'] ?? '')) ?></td>
                                <td>
                                    <a class="log-history-link" href="<?= e($userLogUrl) ?>"><?= e($log['user_name'] ?? 'System') ?></a><br>
                                    <span class="muted"><?= e($log['user_email'] ?? '') ?></span>
                                </td>
                                <td><?= e(role_label($log['user_role'] ?? '')) ?></td>
                                <td><?= e($log['division_name'] ?? '') ?></td>
                                <td><code class="log-ip-address"><?= e(trim((string) ($log['ip_address'] ?? '')) !== '' ? $log['ip_address'] : 'Not recorded') ?></code></td>
                                <td><?= e($log['action'] ?? '') ?></td>
                                <td>
                                    <?php if ($recordLogUrl !== '' && !empty($log['control_number'])): ?>
                                        <a class="log-history-link" href="<?= e($recordLogUrl) ?>"><?= e($log['control_number']) ?></a>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($log['description'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$citySecretaryDashboard['staff_logs']): ?><tr><td colspan="8">No staff or division chief logs found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php render_staff_logs_pagination(
                    $staffLogsPage,
                    (int) $citySecretaryDashboard['staff_logs_total_pages']
                ); ?>
            <?php endif; ?>
        </div>

        <div class="division-tab-panel" data-division-panel="committees">
            <h2>Committees</h2>
            <p class="muted">All committees and their current pending referral counts.</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Committee</th><th>Records</th><th>Members</th></tr></thead>
                    <tbody>
                    <?php foreach ($citySecretaryDashboard['committees'] as $committee): ?>
                        <tr>
                            <td><?= e($committee['name']) ?></td>
                            <td class="center-cell">
                                <a class="print-link" href="<?= url('/records.php?tab=committee&amp;committee_id=') ?><?= (int) $committee['id'] ?>&amp;sort=updated">
                                    <?= (int) ($committee['record_count'] ?? 0) ?>
                                </a>
                            </td>
                            <td class="center-cell"><a class="btn secondary small-btn" href="<?= url('/committee_roster.php?committee_id=') ?><?= (int) $committee['id'] ?>&amp;popup=1&amp;return=dashboard&amp;city_tab=committees">See Members</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$citySecretaryDashboard['committees']): ?><tr><td colspan="3">No committees found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="division-tab-panel" data-division-panel="search">
            <h2>Search Record</h2>

            <div class="table-wrap" style="margin-top:14px;">
                <table>
                    <thead><tr><th>Communication No.</th><th class="title-column">Title</th><th>Committee</th><th class="status-column">Status</th><th class="updated-column">Updated</th></tr></thead>
                    <tbody>
                    <?php foreach (($activeTab === 'transmittals' ? $transmittals : $assignedReferrals) as $record): ?>
                        <tr>
                            <td><?= control_number_link($record) ?></td>
                            <td><?= e(display_record_title(record_title_for_current_user($record))) ?></td>
                            <td><?= e($record['committee_name'] ?? ($record['origin'] ?? '')) ?></td>
                            <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                            <td><?= e(display_datetime($record['updated_at'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!($activeTab === 'transmittals' ? $transmittals : $assignedReferrals)): ?><tr><td colspan="5">No matching records found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php require __DIR__ . '/../app/partials/personal_notes_dashboard_panel.php'; ?>
    </section>
<?php endif; ?>

<?php if (!$usesTabbedAssignedDashboard && !$usesCitySecretaryTabbedDashboard): ?>
<nav class="dashboard-tabs" aria-label="Dashboard record type tabs">
    <a class="<?= $activeTab === 'committee' ? 'active' : '' ?>" href="<?= url('/dashboard.php?tab=committee') ?>">Committee Referrals</a>
    <?php if ($isReceivingClerk): ?>
        <a class="<?= $activeTab === 'printing' ? 'active' : '' ?>" href="<?= url('/dashboard.php?tab=printing') ?>">For Printing</a>
    <?php endif; ?>
    <?php if ($showAdministrativeDocuments): ?>
        <a class="<?= $activeTab === 'documents' ? 'active' : '' ?>" href="<?= url('/dashboard.php?tab=documents') ?>">Transmittals, Letters and Endorsements</a>
    <?php endif; ?>
    <?php if ($showCertifiedUrgent): ?>
        <a class="<?= $activeTab === 'certified-urgent' ? 'active' : '' ?>" href="<?= url('/dashboard.php?tab=certified-urgent') ?>">Certified Urgent</a>
    <?php endif; ?>
    <?php if ($showMemorandumDocuments): ?>
        <a class="<?= $activeTab === 'memoranda' ? 'active' : '' ?>" href="<?= url('/dashboard.php?tab=memoranda') ?>">Memo and EO's</a>
    <?php endif; ?>
    <?php if ($showTransmittals): ?>
        <a class="<?= $activeTab === 'transmittals' ? 'active' : '' ?>" href="<?= url('/dashboard.php?tab=transmittals') ?>">Transmittals</a>
    <?php endif; ?>
    <a class="<?= $activeTab === 'notes' ? 'active' : '' ?>" href="<?= url('/dashboard.php?tab=notes') ?>">
        Notes
        <span class="tab-action-badge" data-note-reminder-count title="Due reminders" <?= (int) $divisionChiefDashboard['due_reminder_count'] === 0 ? 'hidden' : '' ?>><?= (int) $divisionChiefDashboard['due_reminder_count'] ?></span>
    </a>
</nav>
<?php endif; ?>

<?php if (!$usesTabbedAssignedDashboard && !$usesCitySecretaryTabbedDashboard && $activeTab === 'notes'): ?>
    <section class="panel division-chief-tabs" style="margin-top:18px;">
        <?php $personalNotesPanelActive = true; ?>
        <?php require __DIR__ . '/../app/partials/personal_notes_dashboard_panel.php'; ?>
    </section>
<?php endif; ?>

<?php if (!$usesTabbedAssignedDashboard && !$usesCitySecretaryTabbedDashboard && $activeTab !== 'notes'): ?>
<form method="get" class="filters dashboard-filters">
    <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
    <input name="search" placeholder="Search communication no., title, origin, client" value="<?= e($search) ?>">
    <select name="status">
        <option value="">All statuses</option>
        <?php foreach ($statuses as $item): ?>
            <option value="<?= e($item) ?>" <?= $status === $item ? 'selected' : '' ?>><?= e($item) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if (in_array($activeTab, ['committee', 'printing'], true)): ?>
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
    <a class="btn secondary records-filter-action" href="<?= url('/dashboard.php?tab=') ?><?= e($activeTab) ?>">Clear</a>
</form>
<?php endif; ?>

<?php if (!$usesTabbedAssignedDashboard && !$usesCitySecretaryTabbedDashboard && $activeTab !== 'notes'): ?>
<section class="grid" style="margin-top:18px;">
    <?php if ($activeTab === 'committee'): ?>
    <?php if ($showCitySecretaryAssignment): ?>
        <div class="panel panel-green">
            <h2>Committee Assignment</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Communication No.</th><th class="title-column">Title</th><th>Client / Origin</th><th>Date Received</th><th class="status-column">Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($unassignedReferrals as $record): ?>
                        <tr>
                            <td>
                                <?= control_number_link($record) ?>
                                <?php if ($isReceivingClerk && record_has_pending_receiving_staff_comment($record)): ?>
                                    <span class="receiving-comment-tag">For Correction</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e(display_record_title(record_title_for_current_user($record))) ?></td>
                            <td><?= e($record['client_name'] ?? '') ?></td>
                            <td><?= e(display_date($record['received_date'] ?? '')) ?></td>
                            <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                            <td class="center-cell">
                                <?php if (can_edit_record($record)): ?>
                                    <a class="btn secondary small-btn record-edit-action" href="<?= e(dashboard_action_url('/record_form.php?id=' . (int) $record['id'])) ?>">Edit</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$unassignedReferrals): ?><tr><td colspan="6">No Committee Referrals waiting for assignment.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($showAssignedReferrals): ?>
        <div class="panel">
            <h2><?= $isFullDashboard ? 'Assigned Committee Referrals' : 'Committee Referrals' ?></h2>
            <p class="muted"><?= $isFullDashboard ? 'Referrals assigned to committees.' : 'Committee referrals assigned to your committees.' ?></p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Communication No.</th><th class="title-column">Title</th><th>Committee</th><th class="status-column">Status</th><?php if ($userRole === 'division_staff'): ?><th>Physical Copy</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($assignedReferrals as $record): ?>
                        <tr>
                            <td><?= control_number_link($record) ?></td>
                            <td><?= e(display_record_title(record_title_for_current_user($record))) ?></td>
                            <td><?= e($record['committee_name'] ?? '') ?></td>
                            <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                            <?php if ($userRole === 'division_staff'): ?>
                                <td>
                                    <?php $divisionReceipt = $divisionReceiptsByRecord[(int) $record['id']] ?? null; ?>
                                    <?php if ($divisionReceipt): ?>
                                        <span class="division-receipt-control is-received">
                                            <input type="checkbox" checked disabled aria-label="Physical copy received">
                                            <span>Received</span>
                                        </span>
                                        <span class="receipt-meta receipt-meta-block"><?= e(display_datetime($divisionReceipt['received_at'] ?? '')) ?></span>
                                    <?php elseif (can_attest_division_receipt($record)): ?>
                                        <?php if ($isDashboardMonitor): ?>
                                            <label class="division-receipt-control">
                                                <input type="checkbox" disabled aria-label="Mark physical copy as received">
                                                <span>Received</span>
                                            </label>
                                        <?php else: ?>
                                            <form method="post" action="<?= url('/record_division_receipt.php') ?>" class="division-receipt-form">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                                                <input type="hidden" name="return_path" value="<?= e($dashboardReturnPath) ?>">
                                                <label class="division-receipt-control">
                                                    <input type="checkbox" name="received" value="1" onchange="if (this.checked) this.form.submit();">
                                                    <span>Received</span>
                                                </label>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$assignedReferrals): ?><tr><td colspan="<?= $userRole === 'division_staff' ? 5 : 4 ?>">No referrals assigned to your committees yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($userRole === 'secretariat'): ?>
        <div class="panel">
            <h2>Newly Updated Records</h2>
            <p class="muted">Latest updates for Committee Referrals assigned to your committees.</p>
            <div class="record-list">
                <?php foreach ($secretariatNewlyUpdatedRecords as $record): ?>
                    <?php
                        $committeeRows = record_committee_rows((int) $record['id'], !empty($record['committee_id']) ? (int) $record['committee_id'] : null);
                        $committeeNames = $committeeRows
                            ? implode('; ', array_map(fn ($row) => $row['committee_name'], $committeeRows))
                            : ($record['committee_name'] ?? 'Unassigned');
                        $leadCommitteeName = lead_committee_name_from_rows($committeeRows);
                        $assignmentNames = record_assignment_names((int) $record['id'], !empty($record['committee_id']) ? (int) $record['committee_id'] : null);
                    ?>
                    <article class="record-card">
                        <div class="record-line record-topline">
                            <div>
                                <strong>Communication Number:</strong> <?= control_number_link($record) ?>
                                <?php if (current_secretariat_is_lead_committee($record)): ?><span class="lead-secretariat-label under-control">Lead<br>Committee Secretariat</span><?php endif; ?>
                            </div>
                            <div><strong>Type:</strong> <?= e($record['document_type'] ?? '') ?></div>
                            <div><strong>Date Received:</strong> <?= e(display_date($record['received_date'] ?? '')) ?></div>
                        </div>
                        <div class="record-line"><strong>Client / Origin:</strong> <?= e($record['client_name'] ?? '') ?></div>
                        <div class="record-line"><strong>Committee:</strong> <?= e($committeeNames) ?></div>
                        <?php if ($leadCommitteeName !== ''): ?>
                            <div class="record-line"><strong>Lead Committee:</strong> <?= e($leadCommitteeName) ?></div>
                        <?php endif; ?>
                        <div class="record-line record-title-line"><span class="record-title-text"><strong>Title:</strong> <?= nl2br(e(display_record_title(record_title_for_current_user($record)))) ?></span></div>
                        <div class="record-line record-highlight-line"><strong>Division:</strong> <?= e($assignmentNames['divisions'] ? implode('; ', $assignmentNames['divisions']) : 'Unassigned') ?></div>
                        <div class="record-line record-highlight-line"><strong>Assigned Secretariat:</strong> <?= e($assignmentNames['secretariats'] ? implode('; ', $assignmentNames['secretariats']) : 'Unassigned') ?></div>
                        <div class="record-line"><strong>Status:</strong> <span class="badge <?= e(status_class($record['status'] ?? '')) ?>"><?= e($record['status'] ?? '') ?></span></div>
                        <div class="record-line latest-update-line">
                            <strong>Latest Update:</strong>
                            <?= e($record['updated_by_name'] ?? '') ?> -
                            <?= e(display_datetime($record['movement_created_at'] ?? '')) ?> -
                            <span class="badge <?= e(status_class($record['movement_status'] ?? '')) ?>"><?= e($record['movement_status'] ?? '') ?></span>
                        </div>
                        <?php if (trim((string) ($record['movement_notes'] ?? '')) !== ''): ?>
                            <div class="record-line"><strong>Remarks:</strong> <?= nl2br(e($record['movement_notes'])) ?></div>
                        <?php endif; ?>
                        <div class="record-line record-actions"><strong>Action:</strong> <span class="actions"><a class="record-view-action" href="<?= url('/record_view.php?id=') ?><?= (int) $record['id'] ?>">View Record</a></span></div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$secretariatNewlyUpdatedRecords): ?>
                    <article class="record-card">No newly updated records under your committees yet.</article>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="panel panel-yellow">
        <h2>Recently Updated Committee Referrals</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Communication No.</th><th class="title-column">Title</th><th>Committee</th><th class="status-column">Status</th><th class="updated-column">Updated</th><?php if ($isReceivingClerk): ?><th>Action</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($recentCommitteeReferrals as $record): ?>
                    <tr>
                        <td>
                            <?= control_number_link($record) ?>
                            <?php if ($isReceivingClerk && record_has_pending_receiving_staff_comment($record)): ?>
                                <span class="receiving-comment-tag">For Correction</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(display_record_title(record_title_for_current_user($record))) ?></td>
                        <td><?= e($record['committee_name'] ?? 'Unassigned') ?></td>
                        <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                        <td><?= e(display_datetime($record['updated_at'] ?? '')) ?></td>
                        <?php if ($isReceivingClerk): ?>
                            <td class="center-cell">
                                <?php if (record_has_pending_receiving_staff_comment($record)): ?>
                                    <a class="small-action-link record-edit-action" href="<?= e(dashboard_action_url('/record_form.php?id=' . (int) $record['id'])) ?>">Edit</a>
                                <?php else: ?>
                                    <span class="record-view-qr-actions">
                                        <a class="small-action-link record-view-action" href="<?= url('/record_view.php?id=') ?><?= (int) $record['id'] ?>">View Record</a>
                                        <?php if ($isReceivingClerk): ?>
                                            <a class="small-action-link record-qr-print-action" href="<?= url('/communication_qr_print.php?id=') ?><?= (int) $record['id'] ?>" target="communication_qr_print" rel="noopener" onclick="window.open(this.href, 'communication_qr_print', 'width=1020,height=820,scrollbars=yes,resizable=yes'); return false;">Print QR</a>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentCommitteeReferrals): ?><tr><td colspan="<?= $isReceivingClerk ? 6 : 5 ?>">No recently updated Committee Referrals found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php render_recent_referrals_pagination($recentReferralsPage, $recentCommitteeTotalPages); ?>
    </div>
    <?php endif; ?>

    <?php if ($activeTab === 'printing' && $isReceivingClerk): ?>
        <div class="panel printing-alert-panel">
            <h2>For Printing</h2>
            <p class="muted">Committee Referrals reviewed by the City Secretary and ready for printing.</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Communication No.</th><th class="title-column">Title</th><th>Committee</th><th class="status-column">Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($printingReferrals as $record): ?>
                        <tr>
                            <td>
                                <?= control_number_link($record) ?>
                                <?php if (record_has_pending_receiving_staff_comment($record)): ?>
                                    <span class="receiving-comment-tag">For Correction</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e(display_record_title(record_title_for_current_user($record))) ?></td>
                            <td><?= e($record['committee_name'] ?? '') ?></td>
                            <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$printingReferrals): ?><tr><td colspan="4">No Committee Referrals waiting for printing.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($activeDashboardDocumentTab): ?>
        <div class="panel panel-blue">
            <div class="panel-title-row">
                <div>
                    <h2><?= e($activeDashboardDocumentTab['title']) ?></h2>
                    <p class="muted">Records received by all Admin Receiving Section personnel.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Communication No.</th>
                            <th>Type</th>
                            <th class="title-column">Title</th>
                            <th>Client / Origin</th>
                            <th>Date Received</th>
                            <th>Admin Receiving Section</th>
                            <th class="status-column">Status</th>
                            <th class="updated-column">Updated</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($dashboardDocumentRecords as $record): ?>
                        <tr>
                            <td><?= control_number_link($record) ?></td>
                            <td><?= e($record['document_type'] ?? '') ?></td>
                            <td><strong><?= e(display_record_title(record_title_for_current_user($record))) ?></strong></td>
                            <td><?= e(($record['origin'] ?? '') !== '' ? $record['origin'] : ($record['client_name'] ?? '')) ?></td>
                            <td><?= e(display_date($record['received_date'] ?? '')) ?></td>
                            <td><?= e($record['receiving_staff_name'] ?? 'Not recorded') ?></td>
                            <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                            <td><?= e(display_datetime($record['updated_at'] ?? '')) ?></td>
                            <td class="center-cell">
                                <?php if (can_edit_record($record)): ?>
                                    <a class="small-action-link record-edit-action" href="<?= e(dashboard_action_url('/record_form.php?id=' . (int) $record['id'])) ?>">Edit</a>
                                <?php else: ?>
                                    <span class="record-view-qr-actions">
                                        <a class="small-action-link record-view-action" href="<?= url('/record_view.php?id=') ?><?= (int) $record['id'] ?>">View Record</a>
                                        <?php if ($isReceivingClerk): ?>
                                            <a class="small-action-link record-qr-print-action" href="<?= url('/communication_qr_print.php?id=') ?><?= (int) $record['id'] ?>" target="communication_qr_print" rel="noopener" onclick="window.open(this.href, 'communication_qr_print', 'width=1020,height=820,scrollbars=yes,resizable=yes'); return false;">Print QR</a>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$dashboardDocumentRecords): ?>
                        <tr><td colspan="9"><?= e($activeDashboardDocumentTab['empty_message']) ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($activeTab === 'transmittals' && $showTransmittals): ?>
        <div class="panel panel-blue">
            <h2>Transmittals</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Communication No.</th><th class="title-column">Title</th><th>Client / Origin</th><th>Contact Number</th><th class="status-column">Status</th><th class="updated-column">Updated</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($transmittals as $record): ?>
                        <tr>
                            <td><?= control_number_link($record) ?></td>
                            <td><?= e(display_record_title(record_title_for_current_user($record))) ?></td>
                            <td><?= e(($record['origin'] ?? '') !== '' ? $record['origin'] : ($record['client_name'] ?? '')) ?></td>
                            <td><?= can_view_transmittal_contact_details($record) ? e($record['contact_number'] ?? '') : '' ?></td>
                            <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                            <td><?= e(display_datetime($record['updated_at'] ?? '')) ?></td>
                            <td class="center-cell">
                                <?php if (can_manage_transmittal_recipients($record)): ?>
                                    <a class="print-link small-action-link" href="<?= e(dashboard_action_url('/record_recipients.php?record_id=' . (int) $record['id'])) ?>">Add Recipients</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$transmittals): ?><tr><td colspan="7">No records for transmittal found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel panel-violet">
            <h2>Recently Updated Transmittals</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Communication No.</th><th class="title-column">Title</th><th>Client / Origin</th><th class="status-column">Status</th><th class="updated-column">Updated</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentTransmittals as $record): ?>
                        <tr>
                            <td><?= control_number_link($record) ?></td>
                            <td><?= e(display_record_title(record_title_for_current_user($record))) ?></td>
                            <td><?= e(($record['origin'] ?? '') !== '' ? $record['origin'] : ($record['client_name'] ?? '')) ?></td>
                            <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                            <td><?= e(display_datetime($record['updated_at'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recentTransmittals): ?><tr><td colspan="5">No recently updated transmittal records found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php if ($usesTabbedAssignedDashboard || $usesCitySecretaryTabbedDashboard || $activeTab === 'notes'): ?>
<script>
const divisionTabButtons = document.querySelectorAll('[data-division-tab]');
const divisionTabPanels = document.querySelectorAll('[data-division-panel]');
const dashboardGlobalFilters = document.querySelectorAll('.dashboard-global-filters');
const tabsWithoutGlobalFilters = new Set(['notes', 'for-plenary', 'approved-plenary']);
const updateDashboardFilterVisibility = (tabName) => {
    dashboardGlobalFilters.forEach((filter) => {
        filter.hidden = tabsWithoutGlobalFilters.has(tabName);
    });
};
const showDivisionTab = (tabName) => {
    divisionTabButtons.forEach((button) => {
        button.classList.toggle('active', button.dataset.divisionTab === tabName);
    });
    divisionTabPanels.forEach((panel) => {
        panel.classList.toggle('active', panel.dataset.divisionPanel === tabName);
    });
    updateDashboardFilterVisibility(tabName);
};
divisionTabButtons.forEach((button) => {
    button.addEventListener('click', () => showDivisionTab(button.dataset.divisionTab));
});
const initialDivisionTabPanel = document.querySelector('[data-division-panel].active');
if (initialDivisionTabPanel) {
    updateDashboardFilterVisibility(initialDivisionTabPanel.dataset.divisionPanel);
}

const personalNoteReminderCards = document.querySelectorAll('.division-sticky-note[data-reminder-at]');
const personalNoteReminderBadge = document.querySelector('[data-note-reminder-count]');
const personalNoteReminderAlert = document.querySelector('[data-note-reminder-alert]');
const personalNoteReminderMessage = document.querySelector('[data-note-reminder-message]');
const refreshPersonalNoteReminders = () => {
    let dueReminderCount = 0;
    const now = Date.now();

    personalNoteReminderCards.forEach((card) => {
        const reminderTime = Date.parse(card.dataset.reminderAt || '');
        const reminderIsDue = Number.isFinite(reminderTime) && reminderTime <= now;
        card.classList.toggle('reminder-due', reminderIsDue);
        const reminderBox = card.querySelector('.division-note-reminder');
        const reminderState = card.querySelector('[data-note-reminder-state]');
        if (reminderBox) {
            reminderBox.classList.toggle('reminder-due', reminderIsDue);
        }
        if (reminderState) {
            reminderState.textContent = reminderIsDue ? 'Reminder due' : 'Reminder';
        }
        if (reminderIsDue) {
            dueReminderCount++;
        }
    });

    if (personalNoteReminderBadge) {
        personalNoteReminderBadge.textContent = String(dueReminderCount);
        personalNoteReminderBadge.hidden = dueReminderCount === 0;
    }
    if (personalNoteReminderAlert) {
        personalNoteReminderAlert.hidden = dueReminderCount === 0;
    }
    if (personalNoteReminderMessage) {
        personalNoteReminderMessage.textContent = `You have ${dueReminderCount} due ${dueReminderCount === 1 ? 'reminder' : 'reminders'}.`;
    }
};
refreshPersonalNoteReminders();
if (personalNoteReminderCards.length > 0) {
    window.setInterval(refreshPersonalNoteReminders, 30000);
}

const personalNoteReminderPicker = document.querySelector('[data-personal-note-reminder-picker]');
if (personalNoteReminderPicker) {
    const reminderInput = personalNoteReminderPicker.querySelector('[data-personal-note-reminder-input]');
    const reminderDateInput = personalNoteReminderPicker.querySelector('[data-personal-note-reminder-date]');
    const reminderTimeSelect = personalNoteReminderPicker.querySelector('[data-personal-note-reminder-time]');
    const reminderOpenButton = personalNoteReminderPicker.querySelector('[data-personal-note-reminder-open]');
    const reminderSummary = personalNoteReminderPicker.querySelector('[data-personal-note-reminder-summary]');
    const reminderPresetButtons = Array.from(personalNoteReminderPicker.querySelectorAll('[data-reminder-preset]'));
    const reminderClearButton = personalNoteReminderPicker.querySelector('[data-reminder-preset="clear"]');
    const reminderForm = personalNoteReminderPicker.closest('form');
    const twoDigits = (value) => String(value).padStart(2, '0');
    const dateToReminderDateValue = (date) => `${date.getFullYear()}-${twoDigits(date.getMonth() + 1)}-${twoDigits(date.getDate())}`;
    const dateToReminderTimeValue = (date) => `${twoDigits(date.getHours())}:${twoDigits(date.getMinutes())}`;
    const roundReminderTime = (date) => {
        const rounded = new Date(date);
        rounded.setSeconds(0, 0);
        const remainder = rounded.getMinutes() % 15;
        if (remainder !== 0) {
            rounded.setMinutes(rounded.getMinutes() + (15 - remainder));
        }
        return rounded;
    };
    const updateReminderSummary = (selectedPreset = '') => {
        reminderPresetButtons.forEach((button) => {
            button.classList.toggle('is-selected', button.dataset.reminderPreset === selectedPreset);
        });
        reminderDateInput.setCustomValidity('');
        reminderTimeSelect.setCustomValidity('');
        reminderClearButton.hidden = reminderDateInput.value === '' && reminderTimeSelect.value === '';
        if (reminderDateInput.value === '' || reminderTimeSelect.value === '') {
            reminderInput.value = '';
            if (reminderDateInput.value !== '') {
                reminderSummary.textContent = 'Select a reminder time';
            } else if (reminderTimeSelect.value !== '') {
                reminderSummary.textContent = 'Select a reminder date';
            } else {
                reminderSummary.textContent = 'No reminder set';
            }
            return;
        }

        reminderInput.value = `${reminderDateInput.value}T${reminderTimeSelect.value}`;
        const reminderDate = new Date(reminderInput.value);
        reminderSummary.textContent = Number.isNaN(reminderDate.getTime())
            ? 'Choose a valid reminder time'
            : `Reminder set for ${new Intl.DateTimeFormat(undefined, {
                weekday: 'short',
                month: 'short',
                day: 'numeric',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
            }).format(reminderDate)}`;
    };
    const setReminderTime = (date, preset) => {
        const roundedDate = roundReminderTime(date);
        reminderDateInput.value = dateToReminderDateValue(roundedDate);
        reminderTimeSelect.value = dateToReminderTimeValue(roundedDate);
        updateReminderSummary(preset);
    };

    reminderPresetButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const preset = button.dataset.reminderPreset;
            const now = new Date();
            if (preset === 'clear') {
                reminderDateInput.value = '';
                reminderTimeSelect.value = '';
                reminderInput.value = '';
                updateReminderSummary();
                reminderDateInput.focus();
                return;
            }
            if (preset === 'hour') {
                now.setHours(now.getHours() + 1);
                setReminderTime(now, preset);
                return;
            }
            if (preset === 'tomorrow') {
                now.setDate(now.getDate() + 1);
                now.setHours(9, 0, 0, 0);
                setReminderTime(now, preset);
                return;
            }
            if (preset === 'monday') {
                const daysUntilMonday = (8 - now.getDay()) % 7 || 7;
                now.setDate(now.getDate() + daysUntilMonday);
                now.setHours(9, 0, 0, 0);
                setReminderTime(now, preset);
            }
        });
    });
    reminderDateInput.addEventListener('input', () => updateReminderSummary());
    reminderDateInput.addEventListener('change', () => updateReminderSummary());
    reminderTimeSelect.addEventListener('change', () => updateReminderSummary());
    reminderOpenButton.addEventListener('click', () => {
        reminderDateInput.focus();
        if (typeof reminderDateInput.showPicker === 'function') {
            reminderDateInput.showPicker();
        }
    });
    reminderForm.addEventListener('submit', (event) => {
        updateReminderSummary();
        if (reminderDateInput.value === '' && reminderTimeSelect.value === '') {
            return;
        }
        if (reminderDateInput.value === '') {
            event.preventDefault();
            reminderDateInput.setCustomValidity('Select a reminder date.');
            reminderDateInput.reportValidity();
            return;
        }
        if (reminderTimeSelect.value === '') {
            event.preventDefault();
            reminderTimeSelect.setCustomValidity('Select a reminder time.');
            reminderTimeSelect.reportValidity();
            return;
        }

        const selectedReminder = new Date(reminderInput.value);
        if (Number.isNaN(selectedReminder.getTime()) || selectedReminder.getTime() < Date.now()) {
            event.preventDefault();
            reminderTimeSelect.setCustomValidity('Choose a reminder time in the future.');
            reminderTimeSelect.reportValidity();
        }
    });
    updateReminderSummary();
}

const personalNoteRecordPicker = document.querySelector('[data-personal-note-record-picker]');
if (personalNoteRecordPicker) {
    const recordSearchInput = personalNoteRecordPicker.querySelector('[data-personal-note-record-search]');
    const recordIdInput = personalNoteRecordPicker.querySelector('[data-personal-note-record-id]');
    const recordSuggestions = personalNoteRecordPicker.querySelector('[data-personal-note-record-suggestions]');
    const noteForm = personalNoteRecordPicker.closest('form');
    let searchTimer = 0;
    let searchRequest = null;
    let searchSequence = 0;
    let activeSuggestionIndex = -1;

    const suggestionButtons = () => Array.from(recordSuggestions.querySelectorAll('.personal-note-record-option'));
    const closeRecordSuggestions = () => {
        recordSuggestions.hidden = true;
        recordSearchInput.setAttribute('aria-expanded', 'false');
        recordSearchInput.removeAttribute('aria-activedescendant');
        activeSuggestionIndex = -1;
    };
    const showRecordSearchStatus = (message) => {
        recordSuggestions.replaceChildren();
        const status = document.createElement('div');
        status.className = 'personal-note-record-search-status';
        status.textContent = message;
        recordSuggestions.appendChild(status);
        recordSuggestions.hidden = false;
        recordSearchInput.setAttribute('aria-expanded', 'true');
        recordSearchInput.removeAttribute('aria-activedescendant');
        activeSuggestionIndex = -1;
    };
    const chooseRecordSuggestion = (button) => {
        recordIdInput.value = button.dataset.recordId || '';
        recordSearchInput.value = button.dataset.recordLabel || '';
        recordSearchInput.setCustomValidity('');
        closeRecordSuggestions();
    };
    const activateRecordSuggestion = (nextIndex) => {
        const buttons = suggestionButtons();
        if (buttons.length === 0) {
            return;
        }
        activeSuggestionIndex = (nextIndex + buttons.length) % buttons.length;
        buttons.forEach((button, index) => {
            const isActive = index === activeSuggestionIndex;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        const activeButton = buttons[activeSuggestionIndex];
        recordSearchInput.setAttribute('aria-activedescendant', activeButton.id);
        activeButton.scrollIntoView({ block: 'nearest' });
    };
    const renderRecordSuggestions = (records) => {
        recordSuggestions.replaceChildren();
        if (records.length === 0) {
            showRecordSearchStatus('No matching records found. Try another keyword.');
            return;
        }

        records.forEach((record, index) => {
            const title = String(record.title || 'Untitled record');
            const controlNumber = String(record.control_number || `Record #${record.id}`);
            const button = document.createElement('button');
            button.type = 'button';
            button.id = `personal-note-record-option-${record.id}-${index}`;
            button.className = 'personal-note-record-option';
            button.dataset.recordId = String(record.id || '');
            button.dataset.recordLabel = `${controlNumber} — ${title}`;
            button.setAttribute('role', 'option');
            button.setAttribute('aria-selected', 'false');

            const number = document.createElement('strong');
            number.textContent = controlNumber;
            const titleLine = document.createElement('span');
            titleLine.className = 'personal-note-record-option-title';
            titleLine.textContent = title;
            const meta = document.createElement('span');
            meta.className = 'personal-note-record-option-meta';
            meta.textContent = [record.party_name, record.committee_name, record.document_type, record.status]
                .filter((value) => String(value || '').trim() !== '')
                .join(' · ');

            button.append(number, titleLine, meta);
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => chooseRecordSuggestion(button));
            recordSuggestions.appendChild(button);
        });
        recordSuggestions.hidden = false;
        recordSearchInput.setAttribute('aria-expanded', 'true');
        activeSuggestionIndex = -1;
    };
    const searchPersonalNoteRecords = async () => {
        const query = recordSearchInput.value.trim();
        if (query.length < 2 || recordIdInput.value !== '') {
            closeRecordSuggestions();
            return;
        }

        if (searchRequest) {
            searchRequest.abort();
        }
        searchRequest = new AbortController();
        const currentSequence = ++searchSequence;
        showRecordSearchStatus('Searching records…');
        try {
            const response = await fetch(`/personal_note_record_search.php?q=${encodeURIComponent(query)}`, {
                headers: { Accept: 'application/json' },
                signal: searchRequest.signal,
            });
            if (!response.ok) {
                throw new Error(`Record search failed with status ${response.status}`);
            }
            const payload = await response.json();
            if (currentSequence !== searchSequence || recordSearchInput.value.trim() !== query) {
                return;
            }
            renderRecordSuggestions(Array.isArray(payload.records) ? payload.records : []);
        } catch (error) {
            if (error.name !== 'AbortError' && currentSequence === searchSequence) {
                showRecordSearchStatus('Unable to search records right now. Please try again.');
            }
        }
    };

    recordSearchInput.addEventListener('input', () => {
        recordIdInput.value = '';
        recordSearchInput.setCustomValidity('');
        window.clearTimeout(searchTimer);
        if (recordSearchInput.value.trim().length < 2) {
            if (searchRequest) {
                searchRequest.abort();
            }
            closeRecordSuggestions();
            return;
        }
        searchTimer = window.setTimeout(searchPersonalNoteRecords, 250);
    });
    recordSearchInput.addEventListener('keydown', (event) => {
        const buttons = suggestionButtons();
        if (event.key === 'ArrowDown' && buttons.length > 0) {
            event.preventDefault();
            activateRecordSuggestion(activeSuggestionIndex + 1);
        } else if (event.key === 'ArrowUp' && buttons.length > 0) {
            event.preventDefault();
            activateRecordSuggestion(activeSuggestionIndex - 1);
        } else if (event.key === 'Enter' && activeSuggestionIndex >= 0 && buttons[activeSuggestionIndex]) {
            event.preventDefault();
            chooseRecordSuggestion(buttons[activeSuggestionIndex]);
        } else if (event.key === 'Escape') {
            closeRecordSuggestions();
        }
    });
    noteForm.addEventListener('submit', (event) => {
        if (recordIdInput.value === '') {
            event.preventDefault();
            recordSearchInput.setCustomValidity('Select a record from the suggestions.');
            recordSearchInput.reportValidity();
        }
    });
    document.addEventListener('mousedown', (event) => {
        if (!personalNoteRecordPicker.contains(event.target)) {
            closeRecordSuggestions();
        }
    });
}
<?php if (isset($_GET['recent_referrals_page']) && $usesCitySecretaryTabbedDashboard): ?>
showDivisionTab('recent');
<?php elseif ($approvedDate !== '' || $approvedType !== ''): ?>
showDivisionTab('approved-plenary');
<?php elseif ($staffUpdateSearch !== '' || $staffUpdateDateFrom !== '' || $staffUpdateDateTo !== ''): ?>
showDivisionTab('staff-updates');
<?php elseif ($search !== '' || $status !== '' || $committeeId !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
showDivisionTab('search');
<?php elseif (isset($_GET['staff_updates_page'])): ?>
showDivisionTab('staff-updates');
<?php elseif (in_array($_GET['division_tab'] ?? '', ['new-referrals', 'staff-updates', 'committees', 'staff', 'notes', 'for-plenary', 'search'], true)): ?>
showDivisionTab('<?= e($_GET['division_tab']) ?>');
<?php elseif (in_array($_GET['city_tab'] ?? '', ['review', 'committee-referrals', 'certified-urgent', 'administrative-documents', 'memoranda', 'transmittals', 'for-plenary', 'approved-plenary', 'recent', 'logs', 'committees', 'search', 'notes'], true)): ?>
showDivisionTab('<?= e($_GET['city_tab']) ?>');
<?php endif; ?>
</script>
<?php endif; ?>
<?php if ($isDashboardMonitor): ?>
<script>
const dashboardMonitorRole = <?= json_encode($requestedMonitorRole, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const dashboardMonitorUserId = <?= (int) $dashboardMonitorUserId ?>;
document.querySelectorAll('main a[href="#dashboard-monitor-read-only"]').forEach((link) => {
    link.addEventListener('click', (event) => {
        event.preventDefault();
        window.alert('This is a read-only dashboard preview. Sign in to the user account to perform this action.');
    });
});
document.querySelectorAll('main form[method="get"]:not(.dashboard-monitor-account-form)').forEach((form) => {
    if (!form.querySelector('input[name="monitor_role"]')) {
        const roleInput = document.createElement('input');
        roleInput.type = 'hidden';
        roleInput.name = 'monitor_role';
        roleInput.value = dashboardMonitorRole;
        form.appendChild(roleInput);
    }
    if (dashboardMonitorUserId > 0 && !form.querySelector('input[name="monitor_user_id"]')) {
        const userInput = document.createElement('input');
        userInput.type = 'hidden';
        userInput.name = 'monitor_user_id';
        userInput.value = String(dashboardMonitorUserId);
        form.appendChild(userInput);
    }
});
document.querySelectorAll('main a[href^="/dashboard.php"]:not([data-monitor-reset])').forEach((link) => {
    const url = new URL(link.href, window.location.origin);
    url.searchParams.set('monitor_role', dashboardMonitorRole);
    if (dashboardMonitorUserId > 0) {
        url.searchParams.set('monitor_user_id', String(dashboardMonitorUserId));
    }
    link.href = url.pathname + url.search + url.hash;
});
</script>
<?php endif; ?>
<?php
if ($isDashboardMonitor) {
    $_SESSION['user'] = $authenticatedDashboardUser;
}
require __DIR__ . '/../app/partials/footer.php';
?>
