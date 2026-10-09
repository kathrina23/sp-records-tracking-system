<?php
// Isolated fixtures: no production database or session writes.
declare(strict_types=1);
require_once __DIR__ . '/../app/helpers.php';

function current_user(): array { return $_SESSION['user']; }
function db(): object {
    return new class {
        public function prepare(string $sql): object {
            return new class($sql) {
                public function __construct(private string $sql) {}
                public function execute(array $params): void {}
                public function fetchAll(?int $mode = null): array {
                    return str_contains($this->sql, 'committee_secretariats')
                        ? $GLOBALS['assignedCommittees'] : $GLOBALS['referralCommittees'];
                }
                public function fetchColumn(): int { return 0; }
            };
        }
    };
}

$source = file_get_contents(__DIR__ . '/../public/committee_referral_print.php');
$start = strpos($source, '$canViewSharedReferrals =');
$end = strpos($source, '$movementUpdaterId =', $start);
$selectionCode = substr($source, $start, $end - $start);
$start = strpos($source, 'if ($limitPrintToCurrentCommittee &&');
$end = strpos($source, 'if ($committeeRows) {', $start);
$filterCode = substr($source, $start, $end - $start);
$checks = 0;
foreach ([1, 2, 3, 4, 11] as $count) {
    $referralCommittees = [];
    for ($i = 1; $i <= $count; $i++) {
        $referralCommittees[] = ['committee_id' => $i, 'sequence_no' => $i, 'committee_name' => "Committee $i"];
    }
    $record = ['id' => 1, 'committee_id' => 1, 'document_type' => 'Committee Referrals', 'status' => 'Assigned to the Committee'];
    foreach (range(1, $count + 1) as $assignedId) {
        $_SESSION['user'] = ['id' => 123, 'role' => 'secretariat'];
        $assignedCommittees = [$assignedId];
        $involved = $assignedId <= $count;
        foreach (['can_view_record', 'can_view_record_materials', 'can_view_record_history'] as $permission) {
            if ($permission($record) !== $involved) {
                throw new RuntimeException("Incorrect $permission for committee $assignedId of $count");
            }
            $checks++;
        }
        $movement = ['updated_by_role' => 'secretariat', 'updated_by' => $count, 'notes' => 'Committee update'];
        if (can_print_record_update($record, $movement) !== $involved) {
            throw new RuntimeException('Incorrect shared update print permission');
        }
        foreach ([0, 99] as $movementId) {
            $committeeRows = $referralCommittees;
            $recordCommitteeIds = array_column($committeeRows, 'committee_id');
            $totalReferrals = $count;
            $currentPrintCommitteeId = $movementId > 0 ? $count : 1;
            eval($selectionCode);
            if ($canViewSharedReferrals !== ($involved && $count > 1)) {
                throw new RuntimeException('Shared view exception leaked outside involved secretariats');
            }
            eval($filterCode);
            $expectedCount = $involved && $count > 1 && $movementId === 0 ? $count : 1;
            if (count($committeeRows) !== $expectedCount) {
                throw new RuntimeException('Incorrect number of printable referral committees');
            }
            if ($movementId > 0 && (int) $committeeRows[0]['committee_id'] !== $count) {
                throw new RuntimeException('Specific update lost its committee selection');
            }
            $checks++;
        }
        $unreviewed = array_replace($record, ['status' => 'Received']);
        if (can_view_record_materials($unreviewed)) {
            throw new RuntimeException('Unreviewed referral became visible');
        }
    }
    foreach (['receiving_clerk', 'division_chief', 'admin'] as $role) {
        $_SESSION['user'] = ['id' => 123, 'role' => $role];
        $movementId = 0;
        $committeeRows = $referralCommittees;
        $recordCommitteeIds = array_column($committeeRows, 'committee_id');
        $totalReferrals = $count;
        $currentPrintCommitteeId = 1;
        eval($selectionCode);
        eval($filterCode);
        if ($canViewSharedReferrals || count($committeeRows) !== ($role === 'receiving_clerk' ? $count : 1)) {
            throw new RuntimeException("Existing $role selection changed");
        }
        $checks++;
    }
}
echo "PASS: $checks shared referral, history, update print, and role selection checks.\n";
