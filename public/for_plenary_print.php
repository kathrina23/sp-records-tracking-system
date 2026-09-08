<?php

require_once __DIR__ . '/../app/auth.php';
require_login();
ensure_plenary_number_schema();

if (!can_manage_plenary_scheduling()) {
    http_response_code(403);
    exit('Only the Laws and Rules Secretariat, City Secretary, or Administrator can print For Plenary results.');
}

$stmt = db()->query("SELECT r.*, c.name committee_name
    FROM records r
    LEFT JOIN committees c ON c.id = r.committee_id
    WHERE r.document_type IN ('Committee Referrals', 'Certified Urgent')
    AND r.status IN ('For Plenary Session', 'Scheduled for Plenary')
    ORDER BY CASE WHEN r.status = 'For Plenary Session' THEN 0 ELSE 1 END,
        r.plenary_session_date ASC, r.updated_at DESC, r.id DESC");
$records = $stmt->fetchAll();

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
</head>
<body class="plenary-print-page">
    <main class="plenary-print-sheet">
        <div class="print-actions actions">
            <button class="btn" type="button" onclick="window.print()">Print Result</button>
            <a class="btn secondary back-dashboard-action" href="<?= url('/dashboard.php?city_tab=for-plenary') ?>">Back to Dashboard</a>
        </div>
        <header class="plenary-print-header">
            <h1>For Plenary</h1>
            <p>Sangguniang Panlungsod Records Tracking System</p>
        </header>
        <section class="table-wrap">
            <table>
                <thead><tr><th>Communication No.</th><th>Title</th><th>Committee</th><th>Status</th><th>Updated</th></tr></thead>
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
                        <td><?= e(display_datetime($record['updated_at'] ?? '')) ?></td>
                    </tr>
                    <?php if ($proposedText !== '' || $plenaryDate !== ''): ?>
                        <tr class="plenary-detail-row">
                            <td colspan="5">
                                <?php if ($proposedText !== ''): ?><strong><?= e($proposedText) ?></strong><?php endif; ?>
                                <?php if ($proposedText !== '' && $plenaryDate !== ''): ?><span class="detail-separator">|</span><?php endif; ?>
                                <?php if ($plenaryDate !== ''): ?><strong>Scheduled Plenary Date:</strong> <?= e($plenaryDate) ?><?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if (!$records): ?><tr><td colspan="5">No records are currently for plenary.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>
</body>
</html>
