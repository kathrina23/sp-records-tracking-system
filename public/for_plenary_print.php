<?php

require_once __DIR__ . '/../app/auth.php';
require_login();
ensure_plenary_number_schema();
require_once __DIR__ . '/../app/plenary_results.php';
try {
    $plenaryDateFilter = plenary_session_filter($_GET);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    exit(e($error->getMessage()));
}

if (!can_manage_plenary_scheduling()) {
    http_response_code(403);
    exit('Only the Laws and Rules Secretariat, City Secretary, or Administrator can print For Plenary results.');
}

$records = for_plenary_results($plenaryDateFilter);

function plenary_record_date(array $record): string
{
    if (($record['status'] ?? '') === 'Scheduled for Plenary'
        && trim((string) ($record['plenary_session_date'] ?? '')) !== '') {
        return display_date($record['plenary_session_date']);
    }

    return '';
}

function plenary_proposed_text(array $record): string
{
    if (trim((string) ($record['proposed_ordinance_number'] ?? '')) !== '') {
        return 'Proposed Ordinance: ' . $record['proposed_ordinance_number'];
    }
    if (trim((string) ($record['proposed_resolution_number'] ?? '')) !== '') {
        return 'Proposed Resolution: ' . $record['proposed_resolution_number'];
    }

    return '';
}

function plenary_print_title(array $record): string
{
    $printTitle = trim((string) ($record['plenary_print_title'] ?? ''));
    return $printTitle !== '' ? $printTitle : (string) ($record['title'] ?? '');
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>For Plenary Result</title>
    <link rel="stylesheet" href="<?= url('/assets/styles.css') ?>">
<style>
.plenary-print-sheet table { width: 100%; table-layout: fixed; border-collapse: collapse; }
.plenary-print-sheet th, .plenary-print-sheet td { border: 1px solid #777; padding: 7px; overflow-wrap: anywhere; vertical-align: top; }
.plenary-print-sheet th:nth-child(2) { width: 28%; }
.plenary-print-sheet thead { display: table-header-group; }
@media print {
    @page { size: A4 landscape; margin: 12mm; }
    .plenary-print-sheet { width: 100%; max-width: none; font-size: 10pt; }
    .plenary-print-sheet .table-wrap { overflow: visible; }
    .plenary-print-sheet tr { break-inside: avoid; }
}
</style>
</head>
<body class="plenary-print-page">
    <main class="plenary-print-sheet">
        <div class="print-actions actions">
            <button class="btn" type="button" onclick="window.print()">Print Result</button>
            <a class="btn secondary back-dashboard-action" href="<?= e(url('/dashboard.php?' . http_build_query([($_GET['return_tab'] ?? '') === 'division_tab' ? 'division_tab' : 'city_tab' => 'for-plenary', 'plenary_date' => $plenaryDateFilter]))) ?>">Back to Dashboard</a>
        </div>
        <header class="plenary-print-header">
            <h1>For Plenary</h1>
            <p>Sangguniang Panlungsod Records Tracking System</p>
<p>Plenary Session Date: <?= $plenaryDateFilter !== '' ? e(display_date($plenaryDateFilter)) : 'All dates' ?> &mdash; <?= count($records) ?> record(s)</p>
        </header>
        <section class="table-wrap">
            <table>
                <thead><tr><th>Communication No.</th><th>Title</th><th>Committee</th><th>Status</th><th>Proposed No.</th><th>Session Date</th><th>Updated</th></tr></thead>
                <tbody>
                <?php foreach ($records as $record): ?>
                    <?php
                        $committeeRows = record_committee_rows((int) $record['id'], !empty($record['committee_id']) ? (int) $record['committee_id'] : null);
                        $committeeNames = $committeeRows
                            ? implode('; ', array_map(fn ($row) => $row['committee_name'], $committeeRows))
                            : (($record['document_type'] ?? '') === 'Certified Urgent' ? 'Certified Urgent' : ($record['committee_name'] ?? 'Unassigned'));
                        $proposedText = plenary_proposed_text($record);
                        $plenaryDate = plenary_record_date($record);
                    ?>
                    <tr>
                        <td><?= e($record['control_number']) ?></td>
                        <td><?= e(display_record_title(plenary_print_title($record))) ?></td>
                        <td><?= e($committeeNames) ?></td>
                        <td><?= e($record['status']) ?></td>
                        <td><?= e($proposedText) ?></td><td><?= e($plenaryDate) ?></td>
<td><?= e(display_datetime($record['updated_at'] ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php if (!$records): ?><tr><td colspan="7">No plenary records match the selected session date.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>
</body>
</html>
