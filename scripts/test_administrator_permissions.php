<?php
// No database or production writes.
require_once __DIR__ . '/../app/helpers.php';

$_SESSION['user'] = ['id' => 123, 'role' => 'admin'];
$checks = 0;
foreach (array_merge(others_user_document_types(), ['Certified Urgent']) as $type) {
    foreach (all_statuses() as $status) {
        foreach ([null, 1, 999] as $committeeId) {
            $record = ['id' => 1, 'document_type' => $type, 'status' => $status,
                'committee_id' => $committeeId, 'created_by' => 456];
            foreach (['can_view_record', 'can_edit_record', 'can_delete_record',
                'can_update_record_status', 'can_view_record_history', 'can_view_record_attachments',
                'can_upload_record_attachment', 'can_manage_plenary_record_attachments',
                'can_manage_transmittal_recipients', 'can_access_record_committees'] as $permission) {
                if (!$permission($record)) {
                    throw new RuntimeException("$permission denied for $type / $status / $committeeId");
                }
                $checks++;
            }
        }
    }
}
foreach (['city_secretary', 'division_chief', 'receiving_clerk', 'secretariat', 'others', 'server_maintenance_staff'] as $role) {
    $_SESSION['user']['role'] = $role;
    $record = ['id' => 1, 'document_type' => 'Certified Urgent',
        'status' => 'Approved in the Plenary', 'committee_id' => 1];
    if (can_update_record_status($record) || can_manage_plenary_record_attachments($record)
        || can_manage_transmittal_recipients($record)) {
        throw new RuntimeException("Administrator bypass leaked to $role");
    }
}
$_SESSION['user']['role'] = 'administrative_support';
if (!can_update_record_status($record)) {
    throw new RuntimeException('Existing post-plenary staff permission lost');
}
echo "PASS: $checks administrator record checks and existing role restrictions.\n";