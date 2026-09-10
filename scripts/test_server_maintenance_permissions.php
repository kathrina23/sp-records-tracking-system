<?php
// Permission regression checks; no database connection or production writes.
require_once __DIR__ . '/../app/helpers.php';
function expect_permission(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$_SESSION['user'] = ['id' => 123, 'role' => 'server_maintenance_staff'];
expect_permission(role_label('server_maintenance_staff') === 'Server Maintenance Staff', 'Role label');
expect_permission(can_backup_system() && can_view_audit_logs(), 'Backup and logs must be available');
foreach (['can_create_records', 'can_delete_records', 'can_manage_users', 'can_manage_assignments', 'can_access_management_pages', 'can_manage_division_chief_assignments', 'can_manage_plenary_scheduling', 'can_view_reports', 'can_city_secretary_action'] as $permission) {
    expect_permission(!$permission(), $permission . ' must be denied');
}
$types = array_merge(others_user_document_types(), ['Certified Urgent', 'Unknown']);
$statuses = array_unique(array_merge(referral_statuses(), post_plenary_statuses(), administrative_statuses()));
foreach ($types as $type) {
    foreach ($statuses as $status) {
        $record = ['id' => 1, 'document_type' => $type, 'status' => $status, 'committee_id' => 1];
        expect_permission(can_view_record($record) === in_array($type, others_user_document_types(), true), 'Others record visibility: ' . $type);
        expect_permission(can_view_record_attachments($record) === can_view_record($record), 'Attachment visibility follows record scope');
        expect_permission(!can_edit_record($record), 'Editing must be denied');
        expect_permission(!can_update_record_status($record), 'Updates must be denied');
        expect_permission(!can_upload_record_attachment($record), 'Uploads must be denied');
        expect_permission(!can_manage_plenary_record_attachments($record), 'Attachment management must be denied');
        expect_permission(!can_manage_transmittal_recipients($record), 'Recipient management must be denied');
        expect_permission(!can_edit_secretariat_update($record, []), 'Update editing must be denied');
        expect_permission(!can_assign_plenary_numbers($record), 'Plenary numbering must be denied');
    }
}
foreach (['dashboard.php', 'records.php', 'record_view.php', 'personal_note_record_search.php', 'audit_logs.php', 'log_history.php', 'backup.php'] as $page) {
    expect_permission(maintenance_request_is_allowed('GET', '/server/public/' . $page), 'Viewing ' . $page);
}
foreach (glob(__DIR__ . '/../public/*.php') as $page) {
    expect_permission(maintenance_request_is_allowed('POST', $page) === (basename($page) === 'backup.php'), 'Only backup POST is allowed: ' . basename($page));
}
foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
    expect_permission(!maintenance_request_is_allowed($method, '/server/public/backup.php'), 'Unsupported write method');
}
$_SESSION['user']['role'] = 'others';
expect_permission(!can_backup_system() && !can_view_audit_logs(), 'Others must not gain maintenance access');
$_SESSION['user']['role'] = 'admin';
expect_permission(can_backup_system() && can_view_audit_logs() && can_manage_users() && can_create_records(), 'Administrator permissions retained');
echo "PASS: maintenance role scope, all record states, mutation denial, backup-only POST, and existing-role permissions.\n";
