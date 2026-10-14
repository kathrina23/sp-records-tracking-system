<?php
declare(strict_types=1);

function tracking_saved_assignment_names(array $movement): string
{
    if (!in_array($movement['to_status'] ?? '', ['Assigned to the Committee', 'Pending to the Committee'], true)) return '';
    $notes = (string) ($movement['notes'] ?? '');
    if (preg_match('/(?:^|\R)(?:Assigned )?Committees?:\s*([^\r\n]+)/i', $notes, $match)) return trim($match[1]);
    if (preg_match('/referral to \d+ committees: (.+?)(?:\. |\.$|$)/i', $notes, $match)) return trim($match[1]);
    if (preg_match('/Reviewed and assigned to (.+?) under Division Chief /', $notes, $match)) return trim($match[1]);
    return '';
}

function tracking_submission_committee_names(array $movement, array $history, string $currentNames): string
{
    $names = tracking_saved_assignment_names($movement);
    if ($names !== '') return $names;
    $previousAssignments = array_filter($history, static function ($entry) use ($movement): bool {
        $entryDate = (string) ($entry['created_at'] ?? '');
        $movementDate = (string) ($movement['created_at'] ?? '');
        return $entryDate < $movementDate
            || ($entryDate === $movementDate && (int) ($entry['id'] ?? 0) < (int) ($movement['id'] ?? 0));
    });
    usort($previousAssignments, static fn ($a, $b) =>
        strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''))
        ?: ((int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0)));
    foreach ($previousAssignments as $entry) {
        $names = tracking_saved_assignment_names($entry);
        if ($names !== '') return $names;
    }
    // Older entries without an assignment snapshot can use the repaired
    // reviewed list. A missing current assignment is not a historical fact.
    $currentNames = trim($currentNames);
    return strcasecmp($currentNames, 'Unassigned') === 0 ? '' : $currentNames;
}
