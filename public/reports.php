<?php

require_once __DIR__ . '/../app/auth.php';
require_login();

if (!can_view_reports()) {
    http_response_code(403);
    exit('Your account has read-only access to the permitted record tabs only.');
}

$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$scopeWhere = [];
$scopeParams = [];
if (in_array(current_user()['role'] ?? '', ['division_chief', 'division_staff'], true)) {
    $scopedCommitteeIds = scoped_committee_ids_for_current_user();
    if ($scopedCommitteeIds) {
        $placeholders = implode(',', array_fill(0, count($scopedCommitteeIds), '?'));
        $scopeWhere[] = "(r.committee_id IN ($placeholders) OR EXISTS (SELECT 1 FROM record_committees rc_scope WHERE rc_scope.record_id = r.id AND rc_scope.committee_id IN ($placeholders)))";
        $scopeParams = array_merge($scopedCommitteeIds, $scopedCommitteeIds);
    } else {
        $scopeWhere[] = '1 = 0';
    }
} elseif ((current_user()['role'] ?? '') === 'secretariat') {
    $secretariatCommitteeIds = secretariat_committee_ids();
    if ($secretariatCommitteeIds) {
        $placeholders = implode(',', array_fill(0, count($secretariatCommitteeIds), '?'));
        $scopeWhere[] = "(r.committee_id IN ($placeholders) OR EXISTS (SELECT 1 FROM record_committees rc_scope WHERE rc_scope.record_id = r.id AND rc_scope.committee_id IN ($placeholders)))";
        $scopeParams = array_merge($secretariatCommitteeIds, $secretariatCommitteeIds);
    } else {
        $scopeWhere[] = '1 = 0';
    }
}
if ($dateFrom !== '') {
    $scopeWhere[] = 'r.received_date >= ?';
    $scopeParams[] = $dateFrom;
}
if ($dateTo !== '') {
    $scopeWhere[] = 'r.received_date <= ?';
    $scopeParams[] = $dateTo;
}

$whereSql = $scopeWhere ? ' WHERE ' . implode(' AND ', $scopeWhere) : '';

$byCommitteeStmt = db()->prepare("SELECT COALESCE(c.name, 'Unassigned') label, COUNT(r.id) total FROM records r LEFT JOIN committees c ON c.id = r.committee_id $whereSql GROUP BY label ORDER BY total DESC");
$byCommitteeStmt->execute($scopeParams);
$byCommittee = $byCommitteeStmt->fetchAll();

$byStatusStmt = db()->prepare("SELECT status label, COUNT(*) total FROM records r $whereSql GROUP BY status ORDER BY total DESC");
$byStatusStmt->execute($scopeParams);
$byStatus = $byStatusStmt->fetchAll();

$recentStmt = db()->prepare("SELECT r.*, c.name committee_name FROM records r LEFT JOIN committees c ON c.id = r.committee_id $whereSql ORDER BY r.updated_at DESC LIMIT 20");
$recentStmt->execute($scopeParams);
$recent = $recentStmt->fetchAll();

require __DIR__ . '/../app/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>Reports</h1>
        <p class="muted">Summary counts and recent records.</p>
    </div>
    <button class="btn secondary" onclick="window.print()">Print</button>
</div>

<form method="get" class="filters">
    <input type="date" name="date_from" value="<?= e($dateFrom) ?>" aria-label="Date received from">
    <input type="date" name="date_to" value="<?= e($dateTo) ?>" aria-label="Date received to">
    <button class="btn secondary" type="submit">Generate Report</button>
    <?php if ($dateFrom !== '' || $dateTo !== ''): ?>
        <a class="btn secondary" href="<?= url('/reports.php') ?>">Clear</a>
    <?php endif; ?>
</form>

<?php if ($dateFrom !== '' || $dateTo !== ''): ?>
    <p class="muted">Report period: <?= e($dateFrom !== '' ? $dateFrom : 'Beginning') ?> to <?= e($dateTo !== '' ? $dateTo : 'Present') ?></p>
<?php endif; ?>

<section class="grid" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
    <div class="panel">
        <h2>By Status</h2>
        <table>
            <thead><tr><th>Status</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($byStatus as $row): ?><tr><td><?= e($row['label']) ?></td><td><?= (int) $row['total'] ?></td></tr><?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="panel">
        <h2>By Committee</h2>
        <table>
            <thead><tr><th>Committee</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($byCommittee as $row): ?><tr><td><?= e($row['label']) ?></td><td><?= (int) $row['total'] ?></td></tr><?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel table-wrap" style="margin-top:16px;">
    <h2>Recent Records</h2>
    <table>
        <thead><tr><th>Communication No.</th><th>Title</th><th>Date Received</th><th>Committee</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $record): ?>
            <tr>
                <td><?= control_number_link($record) ?></td>
                <td><?= e(display_record_title(record_title_for_current_user($record))) ?></td>
                <td><?= e(display_date($record['received_date'] ?? '')) ?></td>
                <td><?= e($record['committee_name'] ?? 'Unassigned') ?></td>
                <td><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$recent): ?><tr><td colspan="5">No records found.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
