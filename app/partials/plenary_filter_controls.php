<?php
$plenaryTabKey = $userRole === 'secretariat' ? 'division_tab' : 'city_tab';
$plenaryFilterParams = [$plenaryTabKey => 'for-plenary'];
if ($isDashboardMonitor) {
    $plenaryFilterParams['monitor_role'] = $requestedMonitorRole;
    $plenaryFilterParams['monitor_user_id'] = $dashboardMonitorUserId;
}
?>
<form method="get" action="<?= e(url('/dashboard.php')) ?>" class="filters plenary-print-controls">
    <?php foreach ($plenaryFilterParams as $key => $value): ?>
        <input type="hidden" name="<?= e($key) ?>" value="<?= e((string) $value) ?>">
    <?php endforeach; ?>
    <label>Plenary Session Date
        <input type="date" name="plenary_date" value="<?= e($plenaryDateFilter) ?>">
    </label>
    <button class="btn" type="submit">Search</button>
    <a class="btn secondary" href="<?= e(url('/dashboard.php?' . http_build_query($plenaryFilterParams))) ?>">Clear</a>
    <a class="btn" href="<?= e(dashboard_action_url('/for_plenary_print.php?' . http_build_query([
        'plenary_date' => $plenaryDateFilter,
        'return_tab' => $plenaryTabKey,
    ]))) ?>" target="_blank" rel="noopener">Print Result</a>
</form>
<p class="muted"><?= count($citySecretaryDashboard['for_plenary']) ?> record(s)<?= $plenaryDateFilter !== '' ? ' for the plenary session on ' . e(display_date($plenaryDateFilter)) : '' ?>.</p>