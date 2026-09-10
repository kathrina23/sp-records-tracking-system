<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_login();

$role = current_user()['role'] ?? '';
if (!in_array($role, ['admin', 'city_secretary', 'server_maintenance_staff'], true)) {
    http_response_code(403);
    exit('Your account is not allowed to view detailed logs.');
}

$view = in_array($_GET['view'] ?? '', ['user', 'record'], true) ? (string) $_GET['view'] : '';
$entityId = (int) ($_GET['id'] ?? 0);
if ($view === '' || $entityId <= 0) {
    http_response_code(400);
    exit('Invalid log history request.');
}

$defaultCloseUrl = $role === 'city_secretary' ? url('/dashboard.php?city_tab=logs') : url('/audit_logs.php');
$closeUrl = (string) ($_GET['return_url'] ?? $defaultCloseUrl);
$closeParts = parse_url($closeUrl);
$allowedClosePath = $role === 'city_secretary' ? url('/dashboard.php') : url('/audit_logs.php');
if (
    $closeUrl === ''
    || $closeParts === false
    || isset($closeParts['scheme'])
    || isset($closeParts['host'])
    || ($closeParts['path'] ?? '') !== $allowedClosePath
) {
    $closeUrl = $defaultCloseUrl;
}

$user = null;
$record = null;
$auditLogs = [];
$movementLogs = [];

if ($view === 'user') {
    $userStmt = db()->prepare('SELECT id, name, email, role, division_name FROM users WHERE id = ?');
    $userStmt->execute([$entityId]);
    $user = $userStmt->fetch();
    if (!$user) {
        http_response_code(404);
        exit('User not found.');
    }

    $auditStmt = db()->prepare("SELECT a.*, r.id record_id, r.control_number
        FROM audit_logs a
        LEFT JOIN records r ON a.entity_type = 'record' AND r.id = a.entity_id
        WHERE a.user_id = ?
        ORDER BY a.created_at DESC, a.id DESC");
    $auditStmt->execute([$entityId]);
    $auditLogs = $auditStmt->fetchAll();
} else {
    $recordStmt = db()->prepare('SELECT id, control_number, title, document_type, status FROM records WHERE id = ?');
    $recordStmt->execute([$entityId]);
    $record = $recordStmt->fetch();
    if (!$record) {
        http_response_code(404);
        exit('Record not found.');
    }

    $auditStmt = db()->prepare("SELECT a.*, u.name user_name, u.email user_email
        FROM audit_logs a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE a.entity_type = 'record' AND a.entity_id = ?
        ORDER BY a.created_at DESC, a.id DESC");
    $auditStmt->execute([$entityId]);
    $auditLogs = $auditStmt->fetchAll();

    $movementStmt = db()->prepare("SELECT m.*, u.name user_name, u.email user_email, u.role user_role
        FROM record_movements m
        LEFT JOIN users u ON u.id = m.updated_by
        WHERE m.record_id = ?
        ORDER BY m.created_at DESC, m.id DESC");
    $movementStmt->execute([$entityId]);
    $movementLogs = $movementStmt->fetchAll();
}

$returnQuery = ['view' => 'record', 'popup' => 1, 'return_url' => $closeUrl];

require __DIR__ . '/../app/partials/header.php';
?>
<div class="modal-backdrop" role="presentation">
    <section class="panel user-edit-modal log-history-modal" role="dialog" aria-modal="true" aria-labelledby="log_history_title">
        <div class="modal-title-row">
            <div>
                <?php if ($view === 'user'): ?>
                    <h2 id="log_history_title">User Log History</h2>
                    <p class="muted"><?= e($user['name']) ?> · <?= e($user['email']) ?> · <?= e(role_label($user['role'])) ?></p>
                <?php else: ?>
                    <h2 id="log_history_title">Record Log History</h2>
                    <p class="muted"><?= e($record['control_number']) ?> · <?= e(display_record_title(record_title_for_current_user($record))) ?></p>
                <?php endif; ?>
            </div>
            <a class="modal-close" href="<?= e($closeUrl) ?>" aria-label="Close log history">X</a>
        </div>

        <?php if ($view === 'record'): ?>
            <div class="record-line record-topline log-history-summary">
                <div><strong>Communication Number:</strong> <?= e($record['control_number']) ?></div>
                <div><strong>Type:</strong> <?= e($record['document_type']) ?></div>
                <div><strong>Status:</strong> <span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></div>
            </div>

            <h3>Record Movement Logs</h3>
            <div class="table-wrap log-history-table-wrap">
                <table>
                    <thead><tr><th>Date/Time</th><th>User</th><th>From</th><th>To</th><th>Notes</th></tr></thead>
                    <tbody>
                    <?php foreach ($movementLogs as $log): ?>
                        <tr>
                            <td><?= e(display_datetime($log['created_at'] ?? '')) ?></td>
                            <td><?= e($log['user_name'] ?? 'System') ?><br><span class="muted"><?= e(role_label($log['user_role'] ?? '')) ?></span></td>
                            <td><?= e($log['from_status'] ?? '') ?><br><span class="muted"><?= e($log['from_location'] ?? '') ?></span></td>
                            <td><?= e($log['to_status'] ?? '') ?><br><span class="muted"><?= e($log['to_location'] ?? '') ?></span></td>
                            <td><?= nl2br(e($log['notes'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$movementLogs): ?><tr><td colspan="5">No movement logs found for this record.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <h3>System Audit Logs</h3>
        <?php endif; ?>

        <div class="table-wrap log-history-table-wrap">
            <table>
                <thead>
                    <?php if ($view === 'user'): ?>
                        <tr><th>Date/Time</th><th>Action</th><th>Record</th><th>Description</th><th>IP Address</th></tr>
                    <?php else: ?>
                        <tr><th>Date/Time</th><th>User</th><th>Action</th><th>Description</th><th>IP Address</th></tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                <?php foreach ($auditLogs as $log): ?>
                    <tr>
                        <td><?= e(display_datetime($log['created_at'] ?? '')) ?></td>
                        <?php if ($view === 'user'): ?>
                            <td><?= e($log['action'] ?? '') ?></td>
                            <td>
                                <?php if (!empty($log['record_id']) && !empty($log['control_number'])): ?>
                                    <?php $recordUrl = '/log_history.php?' . http_build_query(array_merge($returnQuery, ['id' => (int) $log['record_id']])); ?>
                                    <a class="log-history-link" href="<?= e($recordUrl) ?>"><?= e($log['control_number']) ?></a>
                                <?php else: ?>
                                    <?= e(($log['entity_type'] ?? '') . (!empty($log['entity_id']) ? ' #' . $log['entity_id'] : '')) ?>
                                <?php endif; ?>
                            </td>
                        <?php else: ?>
                            <td><?= e($log['user_name'] ?? 'System') ?><br><span class="muted"><?= e($log['user_email'] ?? '') ?></span></td>
                            <td><?= e($log['action'] ?? '') ?></td>
                        <?php endif; ?>
                        <td><?= e($log['description'] ?? '') ?></td>
                        <td><code class="log-ip-address"><?= e(trim((string) ($log['ip_address'] ?? '')) !== '' ? $log['ip_address'] : 'Not recorded') ?></code></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$auditLogs): ?><tr><td colspan="5">No audit logs found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="actions log-history-actions">
            <a class="btn secondary" href="<?= e($closeUrl) ?>">Close</a>
        </div>
    </section>
</div>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
