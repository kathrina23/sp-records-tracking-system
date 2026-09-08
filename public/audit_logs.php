<?php

require_once __DIR__ . '/../app/auth.php';
require_login();

if (!can_view_audit_logs()) {
    http_response_code(403);
    exit('Only the Administrator can view audit logs.');
}

try {
    db()->query('SELECT 1 FROM audit_logs LIMIT 1');
} catch (Throwable $error) {
    require __DIR__ . '/../app/partials/header.php';
    ?>
    <section class="panel">
        <h1>Audit Logs</h1>
        <p class="muted">Import <strong>database/migration_audit_backup.sql</strong> in phpMyAdmin to enable audit logs.</p>
    </section>
    <?php
    require __DIR__ . '/../app/partials/footer.php';
    exit;
}

$userId = trim($_GET['user_id'] ?? '');
$action = trim($_GET['action'] ?? '');
$date = trim($_GET['date'] ?? '');

$where = [];
$params = [];

if ($userId !== '') {
    $where[] = 'a.user_id = ?';
    $params[] = (int) $userId;
}
if ($action !== '') {
    $where[] = 'a.action = ?';
    $params[] = $action;
}
if ($date !== '') {
    $where[] = 'DATE(a.created_at) = ?';
    $params[] = $date;
}

$sql = 'SELECT a.*, u.name user_name, u.email user_email, r.id record_id, r.control_number
        FROM audit_logs a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN records r ON a.entity_type = \'record\' AND r.id = a.entity_id';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY a.created_at DESC LIMIT 300';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();
$auditLogsReturnUrl = url('/audit_logs.php') . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');

$users = db()->query('SELECT id, name, email FROM users ORDER BY name')->fetchAll();
$actions = db()->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll();

require __DIR__ . '/../app/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>Audit Logs</h1>
        <p class="muted">Administrator view of user movements and system actions.</p>
    </div>
</div>

<form method="get" class="filters" style="grid-template-columns: 1fr 180px 180px auto;">
    <select name="user_id">
        <option value="">All users</option>
        <?php foreach ($users as $user): ?>
            <option value="<?= (int) $user['id'] ?>" <?= $userId === (string) $user['id'] ? 'selected' : '' ?>><?= e($user['name']) ?> - <?= e($user['email']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="action">
        <option value="">All actions</option>
        <?php foreach ($actions as $item): ?>
            <option value="<?= e($item['action']) ?>" <?= $action === $item['action'] ? 'selected' : '' ?>><?= e($item['action']) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="date" name="date" value="<?= e($date) ?>">
    <button class="btn secondary" type="submit">Filter</button>
</form>

<section class="panel table-wrap">
    <table>
        <thead><tr><th>Date/Time</th><th>User</th><th>Action</th><th>Item</th><th>Description</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <?php
                $userLogUrl = !empty($log['user_id'])
                    ? '/log_history.php?' . http_build_query([
                        'view' => 'user',
                        'id' => (int) $log['user_id'],
                        'popup' => 1,
                        'return_url' => $auditLogsReturnUrl,
                    ])
                    : '';
                $recordLogUrl = !empty($log['record_id'])
                    ? '/log_history.php?' . http_build_query([
                        'view' => 'record',
                        'id' => (int) $log['record_id'],
                        'popup' => 1,
                        'return_url' => $auditLogsReturnUrl,
                    ])
                    : '';
            ?>
            <tr>
                <td><?= e(display_datetime($log['created_at'] ?? '')) ?></td>
                <td>
                    <?php if ($userLogUrl !== ''): ?>
                        <a class="log-history-link" href="<?= e($userLogUrl) ?>"><?= e($log['user_name'] ?? 'System') ?></a>
                    <?php else: ?>
                        <?= e($log['user_name'] ?? 'System') ?>
                    <?php endif; ?>
                    <br><span class="muted"><?= e($log['user_email'] ?? '') ?></span>
                </td>
                <td><?= e($log['action']) ?></td>
                <td>
                    <?php if ($recordLogUrl !== '' && !empty($log['control_number'])): ?>
                        <a class="log-history-link" href="<?= e($recordLogUrl) ?>"><?= e($log['control_number']) ?></a>
                    <?php else: ?>
                        <?= e($log['entity_type'] ?? '') ?> <?= e($log['entity_id'] ? '#' . $log['entity_id'] : '') ?>
                    <?php endif; ?>
                </td>
                <td><?= e($log['description']) ?></td>
                <td><?= e($log['ip_address'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?><tr><td colspan="6">No audit log entries found.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
