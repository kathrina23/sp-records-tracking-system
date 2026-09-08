<?php
$user = current_user();
$currentPage = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
$navCurrent = static fn (array $pages): string => in_array($currentPage, $pages, true) ? ' class="active" aria-current="page"' : '';
$recordsPages = ['records.php', 'record_view.php', 'record_update.php', 'record_attachments_view.php', 'record_attachments_manage.php'];
$recordsActive = in_array($currentPage, $recordsPages, true);
$activeMonitorRole = trim((string) ($_GET['monitor_role'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= url('/assets/styles.css?v=') ?><?= (int) filemtime(__DIR__ . '/../../public/assets/styles.css') ?>">
</head>
<body>
<?php if ($user): ?>
<?php $dashboardActionRequiredCount = dashboard_action_required_count(); ?>
<aside class="sidebar">
    <div class="brand">
        <img class="brand-logo" src="<?= url('/assets/splogo.jpg') ?>" alt="Sangguniang Panlungsod logo">
        <span>SP Records Tracking</span>
    </div>
    <nav>
        <a class="nav-link-with-badge<?= $currentPage === 'dashboard.php' && $activeMonitorRole === '' ? ' active' : '' ?>" href="<?= url('/dashboard.php') ?>"<?= $currentPage === 'dashboard.php' && $activeMonitorRole === '' ? ' aria-current="page"' : '' ?>>
            <span>Dashboard</span>
            <?php if ($dashboardActionRequiredCount > 0): ?>
                <span class="nav-action-badge"><?= (int) $dashboardActionRequiredCount ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= url('/records.php') ?>"<?= $recordsActive ? ' class="active" aria-current="page"' : '' ?>>Records</a>
        <?php if (can_create_records()): ?>
            <a href="<?= url('/record_form.php') ?>"<?= $navCurrent(['record_form.php']) ?>>New Record</a>
        <?php endif; ?>
        <?php if (($user['role'] ?? '') === 'admin'): ?>
            <?php
                $sidebarDashboardRoles = [
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
            ?>
            <div class="nav-section user-level-dashboard-nav">
                <span>User Level Dashboards</span>
                <a href="<?= url('/dashboard.php') ?>"<?= $currentPage === 'dashboard.php' && $activeMonitorRole === '' ? ' class="active" aria-current="page"' : '' ?>>Administrator</a>
                <?php foreach ($sidebarDashboardRoles as $sidebarRole): ?>
                    <a href="<?= url('/dashboard.php?monitor_role=') ?><?= urlencode($sidebarRole) ?>"<?= $currentPage === 'dashboard.php' && $activeMonitorRole === $sidebarRole ? ' class="active" aria-current="page"' : '' ?>><?= e(role_label($sidebarRole)) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (can_access_management_pages() || can_manage_assignments() || can_manage_division_chief_assignments() || can_manage_users()): ?>
            <div class="nav-section">
                <span>Management</span>
                <?php if (can_access_management_pages()): ?>
                    <a href="<?= url('/committees.php') ?>"<?= $navCurrent(['committees.php']) ?>>Committees</a>
                    <a href="<?= url('/terms.php') ?>"<?= $navCurrent(['terms.php']) ?>>Terms</a>
                    <a href="<?= url('/officials.php') ?>"<?= $navCurrent(['officials.php']) ?>>Officials Assignment</a>
                <?php endif; ?>
                <?php if (can_manage_assignments()): ?>
                    <a href="<?= url('/secretariat_assignments.php') ?>"<?= $navCurrent(['secretariat_assignments.php']) ?>>Secretariat Assignments</a>
                <?php endif; ?>
                <?php if (can_manage_division_chief_assignments()): ?>
                    <a href="<?= url('/division_chief_assignments.php') ?>"<?= $navCurrent(['division_chief_assignments.php']) ?>>Committee Assignment</a>
                <?php endif; ?>
                <?php if (can_manage_users()): ?>
                    <a href="<?= url('/users.php') ?>"<?= $navCurrent(['users.php']) ?>>User Creation</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (can_view_audit_logs()): ?>
            <a href="<?= url('/audit_logs.php') ?>"<?= $navCurrent(['audit_logs.php']) ?>>Audit Logs</a>
        <?php endif; ?>
        <?php if (can_backup_system()): ?>
            <a href="<?= url('/backup.php') ?>"<?= $navCurrent(['backup.php']) ?>>Backup</a>
        <?php endif; ?>
        <?php if (can_view_reports()): ?>
            <a href="<?= url('/reports.php') ?>"<?= $navCurrent(['reports.php']) ?>>Reports</a>
        <?php endif; ?>
    </nav>
    <div class="profile">
        <strong><?= e($user['name']) ?></strong>
        <span><?= e(role_label($user['role'])) ?></span>
        <a href="<?= url('/logout.php') ?>">Sign out</a>
    </div>
</aside>
<?php endif; ?>
<main class="<?= $user ? 'main' : 'auth-main' ?>">
<?php if ($flash = flash()): ?>
    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>
