<?php
// Database-free regression tests for root and subfolder deployments.
define('BASE_PATH', $argv[1] ?? '');
require_once __DIR__ . '/../app/helpers.php';
$base = rtrim(BASE_PATH, '/');
$checks = 0;
function check_url($actual, $expected): void {
    global $checks;
    if ($actual !== $expected) {
        throw new RuntimeException(var_export($actual, true) . ' != ' . var_export($expected, true));
    }
    $checks++;
}
function source_section(string $file, string $start, string $end): string {
    $source = file_get_contents(__DIR__ . '/../' . $file);
    $from = strpos($source, $start);
    $to = strpos($source, $end, $from);
    if ($from === false || $to === false) {
        throw new RuntimeException('Missing source section: ' . $file);
    }
    return substr($source, $from, $to - $from);
}
foreach (glob(__DIR__ . '/../public/*.php') as $file) {
    $path = '/' . basename($file) . '?id=42&return_url=%2Fdashboard.php%3Ftab%3Dall#details';
    check_url(url($path), $base . $path);
    check_url(url(url($path)), $base . $path);
}
if ($base !== '') {
    check_url(url($base . '-archive/records.php'), $base . $base . '-archive/records.php');
    check_url(url($base . '?tab=all'), $base . '?tab=all');
    check_url(url($base . '#details'), $base . '#details');
}
$dashboardFunction = source_section('public/dashboard.php', 'function dashboard_action_url(', '$dashboardWhere =');
eval($dashboardFunction);
$isDashboardMonitor = false;
check_url(dashboard_action_url('/record_update.php?id=42'), $base . '/record_update.php?id=42');
check_url(dashboard_action_url(url('/record_update.php?id=42')), $base . '/record_update.php?id=42');
$isDashboardMonitor = true;
check_url(dashboard_action_url('/record_update.php?id=42'), '#dashboard-monitor-read-only');

$editor = source_section('public/record_form.php', '$id = isset(', 'if (!$id && !can_create_records())');
foreach (['/records.php?tab=all&search=test', $base . '/records.php?tab=all&search=test'] as $return) {
    $_GET = ['id' => 42, 'popup' => '1', 'return' => 'records', 'return_url' => $return];
    eval($editor);
    check_url(url($popupCloseUrl), $base . '/records.php?tab=all&search=test');
}
foreach (['https://example.com/records.php', '//example.com/records.php', '/users.php'] as $return) {
    $_GET['return_url'] = $return;
    eval($editor);
    check_url(url($popupCloseUrl), $base . '/records.php');
}
$viewer = source_section('public/record_view.php', '$id = (int)', '$attachmentCloseParams =');
foreach (['records' => '/records.php', 'dashboard' => '/dashboard.php', 'recipients' => '/record_recipients.php'] as $target => $path) {
    foreach ([$path, $base . $path] as $return) {
        $_GET = ['id' => 42, 'return' => $target, 'return_url' => $return . '?page=3'];
        eval($viewer);
        check_url($closeUrl, $base . $path . '?page=3');
    }
}
foreach (['public/record_attachments_view.php', 'public/record_attachments_manage.php'] as $file) {
    $section = source_section($file, '$recordId =', '$recordStmt =');
    foreach (['/dashboard.php?city_tab=for-plenary', $base . '/dashboard.php?city_tab=for-plenary'] as $return) {
        $_GET = ['record_id' => 42, 'return' => 'dashboard', 'return_url' => $return];
        eval($section);
        check_url($closeUrl, $base . '/dashboard.php?city_tab=for-plenary');
    }
    $_GET['return_url'] = 'https://example.com/dashboard.php';
    eval($section);
    check_url($closeUrl, $base . '/record_view.php?id=42');
}
$statusPaths = source_section('public/status_update.php', '$id = (int)', '$statuses = all_statuses();');
$_POST = ['record_id' => 42, 'return' => 'dashboard', 'city_tab' => 'for-plenary'];
eval($statusPaths);
check_url(url($dashboardReturnUrl), $base . '/dashboard.php?city_tab=for-plenary');
check_url(url($updateFormUrl), $base . '/record_update.php?id=42&popup=1&return=dashboard&city_tab=for-plenary');
echo "PASS: $checks deployment URL and return navigation checks for BASE_PATH=" . BASE_PATH . "\n";