<?php
require_once __DIR__ . '/../app/elibrary.php';
require_elibrary_staff();
$date = trim((string) ($_GET['approved_date'] ?? ''));
$type = (string) ($_GET['approved_type'] ?? '');
$search = substr(trim((string) ($_GET['search'] ?? '')), 0, 1000);
$where = ["r.document_type IN ('Committee Referrals', 'Certified Urgent')",
    "(r.status='Approved in the Plenary' OR r.plenary_approved_date IS NOT NULL OR NULLIF(TRIM(r.approved_ordinance_number), '') IS NOT NULL OR NULLIF(TRIM(r.approved_resolution_number), '') IS NOT NULL)"];
$params = [];
if ($date !== '') {
    $where[] = 'r.plenary_approved_date=?';
    $params[] = $date;
}
if (in_array($type, ['ordinance', 'resolution'], true)) {
    $where[] = "NULLIF(TRIM(r.approved_{$type}_number), '') IS NOT NULL";
}
if ($search !== '') {
    $where[] = '(r.title LIKE ? OR r.control_number LIKE ? OR r.approved_ordinance_number LIKE ? OR r.approved_resolution_number LIKE ?)';
    array_push($params, ...array_fill(0, 4, '%' . $search . '%'));
}
$stmt = db()->prepare("SELECT r.*, c.name committee_name FROM records r LEFT JOIN committees c ON c.id=r.committee_id WHERE " . implode(' AND ', $where) . ' ORDER BY r.plenary_approved_date DESC, r.id DESC');
$stmt->execute($params);
$records = $stmt->fetchAll();
$publications = db()->query('SELECT record_id, kind, revision FROM legislation_publications')->fetchAll();
$posted = [];
foreach ($publications as $publication) {
    $posted[$publication['record_id']][$publication['kind']] = true;
}
require __DIR__ . '/../app/partials/header.php';
?>
<section class="panel elibrary-posting">
    <header class="elibrary-posting-header"><div><p class="legislation-editor-eyebrow">E-Library / Publishing</p><h1><?= !empty($isDashboardMonitor) ? 'LMIS Data Entry Staff Dashboard — Read Only' : 'E-Library Posting' ?></h1><p class="muted">Prepare approved ordinances and resolutions for public search.</p></div>
    <?php if (empty($isDashboardMonitor)): ?><a class="legislation-entry-action" href="<?= url('/e-library_old.php#legislation-entries') ?>">Edit Entries / Upload Copies</a><?php endif; ?></header>
    <form method="get" class="elibrary-posting-filters">
        <?php if (!empty($isDashboardMonitor)): ?><input type="hidden" name="monitor_role" value="lmis_data_entry"><input type="hidden" name="monitor_user_id" value="<?= (int) $dashboardMonitorUserId ?>"><?php endif; ?>
        <label>Search<input name="search" value="<?= e($search) ?>" placeholder="Title or legislation number"></label>
        <label>Date Approved<input type="date" name="approved_date" value="<?= e($date) ?>"></label>
        <label>Type<select name="approved_type"><option value="">All Types</option><option value="ordinance" <?= $type === 'ordinance' ? 'selected' : '' ?>>Ordinance</option><option value="resolution" <?= $type === 'resolution' ? 'selected' : '' ?>>Resolution</option></select></label>
        <div class="elibrary-filter-actions"><button class="legislation-entry-action primary" type="submit">Apply Filters</button><?php if ($search !== '' || $date !== '' || $type !== ''): ?><a class="legislation-entry-action" href="<?= !empty($isDashboardMonitor) ? url('/dashboard.php?') . e(http_build_query(['monitor_role' => 'lmis_data_entry', 'monitor_user_id' => $dashboardMonitorUserId])) : url('/elibrary.php') ?>">Clear</a><?php endif; ?></div>
    </form>
    <div class="elibrary-posting-list-head"><h2>Approved in the Plenary <span class="legislation-entry-count"><?= count($records) ?></span></h2><p>Review the details and signed copy before posting.</p></div>
    <div class="table-wrap"><table class="elibrary-posting-table"><colgroup><col class="posting-number-col"><col><col class="posting-committee-col"><col class="posting-communication-col"><col class="posting-date-col"><col class="posting-action-col"></colgroup>
        <thead><tr><th scope="col">Legislation</th><th scope="col">Title / Subject</th><th scope="col">Committee</th><th scope="col">Communication</th><th scope="col">Approval / Update</th><th scope="col">Posting</th></tr></thead>
        <tbody>
        <?php foreach ($records as $record): ?>
            <?php $committeeRows = record_committee_rows((int) $record['id'], !empty($record['committee_id']) ? (int) $record['committee_id'] : null); ?>
            <tr>
                <td><?php foreach (['ordinance', 'resolution'] as $kind): ?><?php if (legislation_number($record, $kind) !== ''): ?><div class="elibrary-posting-number"><span><?= e(ucfirst($kind)) ?></span><strong><?= e(legislation_number($record, $kind)) ?></strong></div><?php endif; ?><?php endforeach; ?></td>
                <td><?php $displayTitle = display_record_title($record['title']); ?><?php if (mb_strlen($displayTitle) > 180): ?><details class="elibrary-posting-title"><summary><span class="posting-title-text"><?= e($displayTitle) ?></span><span class="posting-title-toggle"><span class="expand-label">Read full title</span><span class="collapse-label">Collapse title</span></span></summary></details><?php else: ?><span class="posting-title-text"><?= e($displayTitle) ?></span><?php endif; ?></td>
                <td class="elibrary-posting-committees"><?php foreach ($committeeRows ? array_column($committeeRows, 'committee_name') : [($record['committee_name'] ?? 'Unassigned')] as $committeeName): ?><span><?= e($committeeName) ?></span><?php endforeach; ?></td>
                <td class="elibrary-posting-communication"><?= e($record['control_number']) ?></td>
                <td class="elibrary-posting-dates"><span><?= e(display_date($record['plenary_approved_date'] ?? '')) ?></span><small>Updated<br><?= e(display_datetime($record['updated_at'] ?? '')) ?></small></td>
                <td><div class="elibrary-posting-actions">
                    <?php if (legislation_number($record, 'ordinance') === '' && legislation_number($record, 'resolution') === ''): ?><span class="muted">Awaiting approved legislation number</span><?php endif; ?>
                    <?php foreach (['ordinance', 'resolution'] as $kind): ?><?php if (legislation_number($record, $kind) !== ''): ?>
                        <?php if (!empty($posted[$record['id']][$kind])): ?><span class="legislation-entry-status posted">Posted <?= e(ucfirst($kind)) ?></span><?php endif; ?>
                        <?php if (empty($isDashboardMonitor)): ?><a class="legislation-entry-action primary" href="<?= url('/elibrary_form.php?') . e(http_build_query(['record_id' => $record['id'], 'kind' => $kind])) ?>"><?= !empty($posted[$record['id']][$kind]) ? 'Edit Posting' : 'Prepare Posting' ?><?= legislation_number($record, 'ordinance') !== '' && legislation_number($record, 'resolution') !== '' ? ' (' . e(ucfirst($kind)) . ')' : '' ?></a><?php else: ?><span class="muted">Read only</span><?php endif; ?>
                    <?php endif; ?><?php endforeach; ?>
                </div></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$records): ?><tr><td colspan="6" class="legislation-entries-empty">No approved ordinances or resolutions match these filters.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</section>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
