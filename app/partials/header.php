<?php
$user = current_user();
$currentPage = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
$navCurrent = static fn (array $pages): string => in_array($currentPage, $pages, true) ? ' class="active" aria-current="page"' : '';
$recordsPages = ['records.php', 'record_view.php', 'record_update.php', 'record_attachments_view.php', 'record_attachments_manage.php'];
$recordsActive = in_array($currentPage, $recordsPages, true);
$activeMonitorRole = trim((string) ($_GET['monitor_role'] ?? ''));
$dashboardActive = in_array($currentPage, ['dashboard.php', 'messengerial.php'], true) && $activeMonitorRole === '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="<?= url('/assets/scroll-position.js?v=') ?><?= (int) filemtime(__DIR__ . '/../../public/assets/scroll-position.js') ?>"></script>
    <script src="<?= url('/assets/scroll-position.js?v=') ?><?= (int) filemtime(__DIR__ . '/../../public/assets/scroll-position.js') ?>"></script>
    <title><?= e(APP_NAME) ?></title>
    <script>
    // Apply before painting, including after form submissions inside the window.
    if (window.frameElement && window.frameElement.classList.contains('system-window-frame')) {
        document.documentElement.classList.add('system-window-content');
    }
    </script>
    <link rel="stylesheet" href="<?= url('/assets/styles.css?v=') ?><?= (int) filemtime(__DIR__ . '/../../public/assets/styles.css') ?>">
</head>
<body<?= !empty($publicLanding) ? ' class="public-landing-page"' : (!empty($loginBackground) ? ' class="login-background-page"' : (!empty($legislationBackground) ? ' class="legislation-background-page"' : '')) ?>>
<?php if ($user): ?>
<?php $dashboardActionRequiredCount = dashboard_action_required_count(); ?>
<aside class="sidebar">
    <div class="brand">
        <img class="brand-logo" src="<?= url('/assets/splogo.jpg') ?>" alt="Sangguniang Panlungsod logo">
        <span>SP Records Tracking</span>
    </div>
    <nav>
        <a class="nav-link-with-badge<?= $dashboardActive ? ' active' : '' ?>" href="<?= url('/dashboard.php') ?>"<?= $dashboardActive ? ' aria-current="page"' : '' ?>>
            <span>Dashboard</span>
            <?php if ($dashboardActionRequiredCount > 0): ?>
                <span class="nav-action-badge"><?= (int) $dashboardActionRequiredCount ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= url('/records.php') ?>"<?= $recordsActive ? ' class="active" aria-current="page"' : '' ?>>Records</a>
        <?php if (($user['role'] ?? '') === 'lmis_data_entry'): ?>
            <a href="<?= url('/elibrary.php') ?>"<?= $navCurrent(array_merge(['elibrary.php', 'elibrary_form.php'], empty($historical) ? ['elibrary_review.php'] : [])) ?>>E-Library Posting</a>
            <a href="<?= url('/e-library_old.php') ?>"<?= $navCurrent(array_merge(['e-library_old.php'], !empty($historical) ? ['elibrary_review.php'] : [])) ?>>Ordinances &amp; Resolutions</a>
        <?php endif; ?>
        <a href="<?= url('/legislation.php') ?>"<?= $navCurrent(['legislation.php']) ?>>Search Legislation</a>
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
                    'lmis_data_entry',
                    'messengerial_support',
                    'others',
                    'server_maintenance_staff',
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
        <?php if (can_view_terms() || can_access_management_pages() || can_manage_elibrary_data()): ?>
            <div class="nav-section">
                <span>Legislative Setup</span>
                <?php if (can_view_terms()): ?>
                    <a href="<?= url('/terms.php') ?>"<?= $navCurrent(['terms.php']) ?>>Terms</a>
                <?php endif; ?>
                <?php if (can_access_management_pages()): ?>
                    <a href="<?= url('/councilors.php') ?>"<?= $navCurrent(['councilors.php']) ?>>City Councilors</a>
                    <a href="<?= url('/committees.php') ?>"<?= $navCurrent(['committees.php']) ?>>Standing Committees</a>
                <?php endif; ?>
                <?php if (can_manage_elibrary_data()): ?>
                    <a href="<?= url('/elibrary_categories.php') ?>"<?= $navCurrent(['elibrary_categories.php']) ?>>E-Library Data Entry</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (can_manage_assignments() || can_manage_division_chief_assignments() || can_manage_users()): ?>
            <div class="nav-section">
                <span>Management</span>
                <?php if (can_manage_assignments()): ?>
                    <a href="<?= url('/secretariat_assignments.php') ?>"<?= $navCurrent(['secretariat_assignments.php']) ?>>Secretariat Assignments</a>
                <?php endif; ?>
                <?php if (can_manage_division_chief_assignments()): ?>
                    <a href="<?= url('/division_chief_assignments.php') ?>"<?= $navCurrent(['division_chief_assignments.php']) ?>>Staff Committee Assignment</a>
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
