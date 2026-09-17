<?php
require __DIR__ . '/../app/helpers.php';
$approved = ['status' => 'For Transmittal', 'document_type' => 'Committee Referrals', 'approved_ordinance_number' => '15291-2026'];
foreach (['administrative_support' => true, 'admin' => true, 'receiving_clerk' => false, 'secretariat' => false, 'others' => false] as $role => $expected) {
    $_SESSION['user']['role'] = $role;
    if (can_manage_transmittal_recipients($approved) !== $expected) { throw new RuntimeException('Wrong recipient permission: ' . $role); }
}
$_SESSION['user']['role'] = 'administrative_support';
$approved['status'] = 'Approved in the Plenary';
if (can_manage_transmittal_recipients($approved)) { throw new RuntimeException('Recipients allowed before For Transmittal'); }
echo "PASS: recipient role and status permission checks
";