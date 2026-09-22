<?php
require_once __DIR__ . '/../app/auth.php';
require_login();
if (!can_view_messengerial()) {
    http_response_code(403);
    exit('You are not authorized to view Messengerial records.');
}
$search = trim((string) ($_GET['search'] ?? ''));
$params = ['Forwarded to the Messengerial Services'];
$sql = 'SELECT r.*, u.name forwarded_by FROM records r LEFT JOIN users u ON u.id = r.updated_by WHERE r.status = ?';
if ($search !== '') {
    $sql .= ' AND (r.control_number LIKE ? OR r.title LIKE ? OR r.origin LIKE ?)';
    array_push($params, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%');
}
$sql .= ' ORDER BY r.updated_at DESC, r.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();
$recipientStmt = db()->prepare("SELECT rr.* FROM record_recipients rr JOIN records r ON r.id = rr.record_id WHERE r.status = ? ORDER BY rr.id");
$recipientStmt->execute(['Forwarded to the Messengerial Services']);
$recipientsByRecord = [];
foreach ($recipientStmt->fetchAll() as $recipient) {
    $recipientsByRecord[(int) $recipient['record_id']][] = $recipient;
}
$isMessengerialMonitor = !empty($isDashboardMonitor)
    && ($authenticatedDashboardUser['role'] ?? '') === 'admin'
    && ($requestedMonitorRole ?? '') === 'messengerial_support';
$messengerialQueueUrl = $isMessengerialMonitor
    ? url('/dashboard.php?' . http_build_query([
        'monitor_role' => $requestedMonitorRole,
        'monitor_user_id' => $dashboardMonitorUserId,
    ]))
    : url('/messengerial.php');
if ($isMessengerialMonitor) {
    $_SESSION['user'] = $authenticatedDashboardUser;
}
require __DIR__ . '/../app/partials/header.php';
if ($isMessengerialMonitor) {
    $_SESSION['user'] = $dashboardMonitorUser;
    require __DIR__ . '/../app/partials/dashboard_monitor_banner.php';
}
?>
<div class="page-head"><div><h1>Messengerial</h1><p class="muted">Records forwarded to Messengerial Services after transmittal preparation.</p></div></div>
<section class="panel">
    <form method="get" class="filters">
        <?php if ($isMessengerialMonitor): ?>
            <input type="hidden" name="monitor_role" value="<?= e($requestedMonitorRole) ?>">
            <input type="hidden" name="monitor_user_id" value="<?= (int) $dashboardMonitorUserId ?>">
        <?php endif; ?>
        <label>Search records<input type="search" name="search" value="<?= e($search) ?>" placeholder="Control number, title, or origin"></label>
        <button class="btn" type="submit">Search</button>
        <?php if ($search !== ''): ?><a class="btn secondary" href="<?= e($messengerialQueueUrl) ?>">Clear</a><?php endif; ?>
    </form>
</section>
<section class="panel">
    <h2>Forwarded Records (<?= count($records) ?>)</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Control Number</th><th>Title</th><th>Status / Location</th><th>Recipients</th><th>Last Updated</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($records as $record): ?>
            <tr>
                <td><?= e($record['control_number']) ?></td>
                <td><?= e(display_record_title($record['title'])) ?></td>
                <td><?= e($record['status']) ?><br><span class="muted"><?= e($record['current_location']) ?></span></td>
                <td>
                    <?php $recipients = $recipientsByRecord[(int) $record['id']] ?? []; ?>
                    <?php if ($recipients): ?>
                        <details><summary><?= count($recipients) ?> recipient(s)</summary>
                        <?php foreach ($recipients as $recipient): ?>
                            <p><strong><?= e($recipient['name']) ?></strong><br><?= e($recipient['position']) ?><br><?= nl2br(e($recipient['address'])) ?></p>
                        <?php endforeach; ?>
                        </details>
                    <?php else: ?>No saved recipients<?php endif; ?>
                </td>
                <td><?= e(display_datetime($record['updated_at'])) ?><br><?= e($record['forwarded_by'] ?? '') ?></td>
                <td><a class="btn secondary" href="<?= url('/record_view.php?id=') ?><?= (int) $record['id'] ?>">View Record</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$records): ?><tr><td colspan="6">No records forwarded to Messengerial Services<?= $search !== '' ? ' match your search' : '' ?>.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</section>
<?php
if ($isMessengerialMonitor) {
    $_SESSION['user'] = $authenticatedDashboardUser;
}
require __DIR__ . '/../app/partials/footer.php';
?>
