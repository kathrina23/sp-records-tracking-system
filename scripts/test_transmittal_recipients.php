<?php
require __DIR__ . '/../app/helpers.php';
$approved = ['status' => 'For Transmittal', 'document_type' => 'Committee Referrals', 'approved_ordinance_number' => '15291-2026'];
foreach (['administrative_support' => true, 'admin' => true, 'receiving_clerk' => false, 'secretariat' => false, 'others' => false] as $role => $expected) {
    $_SESSION['user']['role'] = $role;
    if (can_manage_transmittal_recipients($approved) !== $expected) { throw new RuntimeException('Wrong recipient permission: ' . $role); }
}
$_SESSION['user']['role'] = 'administrative_support';
foreach (array_merge(['Approved in the Plenary', 'Archived'], post_plenary_statuses()) as $status) {
    $approved['status'] = $status;
    if (!can_manage_transmittal_recipients($approved)) {
        throw new RuntimeException('Recipients disappeared after status update: ' . $status);
    }
}
$approved['status'] = 'Approved in the Plenary';
if (!can_manage_transmittal_recipients($approved)) { throw new RuntimeException('Approved recipients unavailable'); }
$approved['approved_ordinance_number'] = '';
if (!can_manage_transmittal_recipients($approved)) { throw new RuntimeException('Approved record without number unavailable'); }
foreach (['Received', 'Scheduled for Plenary', 'Forwarded to the Messengerial Services', 'Completed'] as $status) {
    $approved['status'] = $status;
    if (can_manage_transmittal_recipients($approved)) { throw new RuntimeException('Recipients allowed for ' . $status); }
}
echo "PASS: recipient role and status permission checks
";
