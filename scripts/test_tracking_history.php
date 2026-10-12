<?php
declare(strict_types=1);
require __DIR__ . '/../app/tracking_history.php';
function history_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$assigned = ['id' => 1, 'created_at' => '2026-09-01 09:00:00', 'to_status' => 'Pending to the Committee', 'notes' => 'Committee: Health; Finance'];
$printed = ['id' => 2, 'created_at' => '2026-09-01 10:00:00', 'to_status' => 'Pending to the Committee', 'notes' => 'Committee Referral printed and forwarded to the assigned committee.'];
$later = ['id' => 3, 'created_at' => '2026-09-02 09:00:00', 'to_status' => 'Pending to the Committee', 'notes' => 'Committee: Laws and Rules'];
$history = [$later, $printed, $assigned];
history_check(tracking_submission_committee_names($printed, $history, 'Unassigned') === 'Health; Finance', 'Earlier assignment not recovered for printed entry');
history_check(tracking_submission_committee_names($assigned, $history, 'Laws and Rules') === 'Health; Finance', 'Current assignment rewrote original submission');
history_check(tracking_submission_committee_names($later, $history, 'Health') === 'Laws and Rules', 'Later actual assignment ignored');
history_check(tracking_submission_committee_names($printed, [$printed], 'Unassigned') === '', 'Missing current committee labeled historical submission Unassigned');
history_check(tracking_submission_committee_names($printed, [$printed], 'Health') === 'Health', 'Repaired original assignment fallback missing');
history_check(tracking_saved_assignment_names(['to_status' => 'Recommending Approval', 'notes' => 'Recommended Committee: Laws and Rules - 2026-09-07']) === '', 'Recommendation treated as original assignment');
history_check(tracking_saved_assignment_names(['to_status' => 'Pending to the Committee', 'notes' => 'Joint referral to 2 committees: Health, Laws and Rules. Reviewed and assigned to Health under Division Chief Example.']) === 'Health, Laws and Rules', 'Genuine joint assignment not retained');
echo "PASS: Historical assignment snapshots, earlier assignment recovery, genuine joint referrals and missing-assignment display.\n";
