<?php
require __DIR__ . '/../app/helpers.php';
$source = file_get_contents(__DIR__ . '/../public/record_form.php');
$start = strpos($source, '    if ($isRecordCorrection) {' . "\n" . '        // Corrections preserve');
$end = strpos($source, '    $pendingAttachments = [];', $start);
$correction = substr($source, $start, $end - $start);
if (!$start || !$end) { throw new RuntimeException('Correction block missing'); }
$count = 0;
foreach (['admin', 'city_secretary'] as $role) {
    $_SESSION['user'] = ['id' => 123, 'role' => $role];
    foreach (array_merge(administrative_document_types(), ['Committee Referrals', 'Certified Urgent']) as $documentType) {
        foreach (all_statuses() as $status) {
            $record = ['id' => 1, 'document_type' => $documentType, 'status' => $status,
                'committee_id' => 1, 'assigned_user_id' => 99, 'due_date' => '2026-10-01',
                'current_location' => 'Existing location'];
            if (!can_edit_record($record)) { throw new RuntimeException("Edit denied: $role / $documentType / $status"); }
            $data = ['status' => 'Received', 'due_date' => null, 'current_location' => 'Automatic routing', 'assigned_user_id' => null];
            $primaryCommitteeId = 1;
            $selectedForwardOptions = [];
            $forwardedTo = '';
            $isRecordCorrection = true;
            eval($correction);
            foreach (['status', 'due_date', 'current_location', 'assigned_user_id'] as $field) {
                if ($data[$field] !== $record[$field]) { throw new RuntimeException("Correction changed $field"); }
            }
            $count++;
        }
    }
}
echo "PASS: $count correction permission and workflow preservation cases.\n";