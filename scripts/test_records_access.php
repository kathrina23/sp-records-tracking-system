<?php
// Permission regression checks without database or session writes.
declare(strict_types=1);
require_once __DIR__ . '/../app/helpers.php';

$checks = 0;
$roles = ['admin', 'city_secretary', 'receiving_clerk', 'records_officer', 'staff',
    'division_chief', 'secretariat', 'division_staff', 'administrative_support',
    'messengerial_support', 'others', 'server_maintenance_staff', '', 'unknown'];
$types = array_merge(others_user_document_types(), ['Certified Urgent']);
foreach ($roles as $role) {
    $_SESSION['user'] = ['id' => 0, 'role' => $role];
    foreach ($types as $type) {
        foreach (['Received', 'Assigned to the Committee', 'For Plenary Session', 'For Transmittal', 'Forwarded to the Messengerial Services'] as $status) {
            $record = ['id' => 1, 'committee_id' => null, 'document_type' => $type, 'status' => $status];
            $expected = match ($role) {
                'admin', 'city_secretary', 'receiving_clerk', 'records_officer', 'staff' => true,
                'others', 'server_maintenance_staff' => $type !== 'Certified Urgent',
                'division_chief', 'secretariat', 'division_staff' => $type === 'Certified Urgent',
                'administrative_support' => in_array($type, ['Committee Referrals', 'Certified Urgent'], true)
                    && in_array($status, ['For Plenary Session', 'For Transmittal', 'Forwarded to the Messengerial Services'], true),
                // Both queues are available for recipient delivery tracking.
                'messengerial_support' => in_array($status, ['For Transmittal', 'Forwarded to the Messengerial Services'], true),
                default => false,
            };
            if (can_view_record($record) !== $expected || can_view_record_history($record) !== $expected) {
                throw new RuntimeException("Unexpected access: $role / $type / $status");
            }
            if (!$expected && can_view_record_attachments($record)) {
                throw new RuntimeException("Attachment access bypass: $role / $type / $status");
            }
            $checks++;
        }
    }
}
$_SESSION['user'] = ['id' => 0, 'role' => 'messengerial_support'];
foreach ($types as $type) {
    foreach (['Received', 'Approved in the Plenary', 'For Transmittal', 'Forwarded to the Messengerial Services', 'Completed', 'Archived'] as $status) {
        $record = ['id' => 1, 'committee_id' => null, 'document_type' => $type, 'status' => $status];
        $expectedView = in_array($status, ['For Transmittal', 'Forwarded to the Messengerial Services'], true);
        if (can_view_record($record) !== $expectedView || can_view_record_history($record) !== $expectedView) {
            throw new RuntimeException("Messenger workflow access: $type / $status");
        }
        if (!$expectedView && can_view_record_attachments($record)) {
            throw new RuntimeException("Messenger attachment access outside delivery queues: $type / $status");
        }
        if (can_create_records() || can_edit_record($record) || can_update_record_status($record)
            || can_upload_record_attachment($record) || can_complete_transmittals($record) || can_manage_users()) {
            throw new RuntimeException("Messenger gained record management permissions: $type / $status");
        }
        $checks++;
    }
}
echo "PASS: $checks role, document type, and workflow combinations; history and attachment access.\n";
