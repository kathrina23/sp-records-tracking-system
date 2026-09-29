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
