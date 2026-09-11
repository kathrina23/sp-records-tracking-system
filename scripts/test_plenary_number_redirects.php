<?php
// Exercise the handler's return-path construction without a database or HTTP writes.
define('BASE_PATH', $argv[1] ?? '');
require_once __DIR__ . '/../app/helpers.php';
$source = file_get_contents(__DIR__ . '/../public/record_workflow.php');
$start = strpos($source, "    \$returnTarget =", strpos($source, "if (\$action === 'update_plenary_numbers')"));
$end = strpos($source, '    if (!can_assign_plenary_numbers', $start);
if ($start === false || $end === false) {
    throw new RuntimeException('Cannot locate proposed-number return paths');
}
$construction = substr($source, $start, $end - $start);
foreach (['record', 'dashboard'] as $target) {
    foreach (['', 'for-plenary', 'scheduled-for-plenary'] as $tab) {
        $_POST = ['return' => $target, 'city_tab' => $tab];
        $id = 42;
        eval($construction);
        $expected = $target === 'record' ? '/record_view.php?id=42'
            : '/dashboard.php?city_tab=' . urlencode($tab !== '' ? $tab : 'for-plenary');
        if (url($returnUrl) !== BASE_PATH . $expected) {
            throw new RuntimeException('Save redirect has an incorrect deployment path');
        }
        $expectedForm = '/plenary_number_form.php?id=42&popup=1&return=' . $target
            . ($tab !== '' ? '&city_tab=' . urlencode($tab) : '');
        if (url($formUrl) !== BASE_PATH . $expectedForm) {
            throw new RuntimeException('Validation redirect has an incorrect deployment path');
        }
    }
}
echo 'PASS: proposed-number redirects with BASE_PATH=' . BASE_PATH . "\n";