<?php

require_once __DIR__ . '/../app/auth.php';
require_login();

$role = current_user()['role'] ?? '';
if (!in_array($role, ['admin', 'city_secretary', 'receiving_clerk'], true)) {
    http_response_code(403);
    exit('Only the Admin Receiving Section and authorized management users can print QR reference forms.');
}

$id = max(0, (int) ($_GET['id'] ?? 0));
$stmt = db()->prepare("SELECT r.*, c.name committee_name,
        COALESCE(NULLIF(assigned.division_name, ''), '') assigned_division,
        COALESCE(NULLIF(clerk.nickname, ''), clerk.name) receiving_clerk_name
    FROM records r
    LEFT JOIN committees c ON c.id = r.committee_id
    LEFT JOIN users assigned ON assigned.id = r.assigned_user_id
    LEFT JOIN users clerk ON clerk.id = r.receiving_clerk_id
    WHERE r.id = ?
    LIMIT 1");
$stmt->execute([$id]);
$record = $stmt->fetch();
if (!$record) {
    http_response_code(404);
    exit('Record not found.');
}

$committeeNames = '';
if (($record['document_type'] ?? '') === 'Committee Referrals') {
    $committeeRows = record_committee_rows(
        (int) $record['id'],
        !empty($record['committee_id']) ? (int) $record['committee_id'] : null
    );
    $committeeNames = $committeeRows
        ? implode('; ', array_map(fn ($row) => (string) $row['committee_name'], $committeeRows))
        : (string) ($record['committee_name'] ?? '');
}

$partyName = trim((string) (
    ($record['document_type'] ?? '') === 'Committee Referrals'
        ? ($record['client_name'] ?? '')
        : ($record['origin'] ?? '')
));
if ($partyName === '') {
    $partyName = trim((string) (($record['client_name'] ?? '') ?: ($record['origin'] ?? '')));
}

$publicStatusUrl = public_record_status_url((int) $record['id']);
$isACommunication = str_starts_with(strtoupper((string) $record['control_number']), 'A-');
$receivedTimestamp = strtotime((string) ($record['created_at'] ?? ''));
$receivedTime = $receivedTimestamp !== false ? date('g:i A', $receivedTimestamp) : 'Not specified';
$generatedAt = date('Y-m-d H:i:s');
$citySecretaryName = '';
$hasCitySecretarySignature = false;
if ($isACommunication) {
    $secretaryStmt = db()->query("SELECT name FROM users WHERE role = 'city_secretary' AND is_active = 1 ORDER BY id LIMIT 1");
    $citySecretaryName = trim((string) ($secretaryStmt->fetchColumn() ?: 'City Secretary'));
    $hasCitySecretarySignature = is_file(__DIR__ . '/assets/city-secretary-signature.png');
}

audit_log(
    'communication_qr_print_view',
    'Opened QR reference print form for record ' . $record['control_number'] . '.',
    'record',
    (int) $record['id']
);

$copies = $isACommunication
    ? ['attachment']
    : ['attachment', 'client'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Print QR - <?= e($record['control_number']) ?></title>
    <link rel="stylesheet" href="<?= url('/assets/styles.css') ?>">
</head>
<body class="communication-qr-print-page" data-print-copy="both">
    <div class="qr-print-toolbar actions">
        <button class="btn" type="button" onclick="printQrCopy('<?= $isACommunication ? 'attachment' : 'both' ?>')">Print QR</button>
        <button class="btn danger" type="button" onclick="window.close()">Close</button>
    </div>

    <main class="qr-print-pages">
        <?php foreach ($copies as $copyKey): ?>
            <section class="qr-print-sheet qr-print-sheet-<?= e($copyKey) ?><?= $isACommunication ? ' qr-print-sheet-a' : '' ?>">
                <?php if (!$isACommunication): ?>
                    <div class="qr-print-contact">
                        Visit our website: www.cdeocitycouncil.com &nbsp; Email us: cdeocitycouncil@gmail.com
                    </div>
                <?php endif; ?>
                <div class="qr-print-number-row">
                    <div class="qr-print-number-copy">
                        <span>Communication Number</span>
                        <strong><?= e($record['control_number']) ?></strong>
                    </div>
                    <div class="qr-print-code" aria-label="QR code for <?= e($record['control_number']) ?>">
                        <span class="communication-qr" data-communication-qr data-qr-value="<?= e($publicStatusUrl) ?>"></span>
                        <small>Scan QR</small>
                    </div>
                </div>

                <?php if ($isACommunication): ?>
                    <section class="qr-print-record-details qr-print-a-details">
                        <div>
                            <span>Date and Time Received</span>
                            <strong><?= e(display_datetime($record['created_at'] ?? '')) ?></strong>
                        </div>
                        <div class="qr-print-remarks">
                            <span>Remarks</span>
                            <strong><?= nl2br(e(trim((string) ($record['remarks'] ?? '')) ?: 'No remarks')) ?></strong>
                        </div>
                        <div class="qr-print-secretary">
                            <div class="qr-print-signature">
                                <?php if ($hasCitySecretarySignature): ?>
                                    <img src="<?= url('/assets/city-secretary-signature.png') ?>" alt="">
                                <?php endif; ?>
                                <strong><?= e(strtoupper($citySecretaryName)) ?></strong>
                                <small>City Secretary</small>
                            </div>
                        </div>
                    </section>
                <?php else: ?>
                    <section class="qr-print-record-details qr-print-l-details">
                        <div>
                            <span>Date Received</span>
                            <strong><?= e(display_date($record['received_date'] ?? '')) ?></strong>
                        </div>
                        <div>
                            <span>Time</span>
                            <strong><?= e($receivedTime) ?></strong>
                        </div>
                        <div class="full">
                            <span>Client Name</span>
                            <strong><?= e($partyName !== '' ? $partyName : 'Not specified') ?></strong>
                        </div>
                        <div class="full">
                            <span>Division Assigned</span>
                            <strong><?= e(($record['assigned_division'] ?? '') !== '' ? $record['assigned_division'] : 'Not assigned') ?></strong>
                        </div>
                        <div class="full">
                            <span>Committee</span>
                            <strong><?= e($committeeNames !== '' ? $committeeNames : 'Not assigned') ?></strong>
                        </div>
                    </section>
                    <footer class="qr-print-generated">
                        Generated from the Legislative Records Tracking System &mdash; <?= e(display_datetime($generatedAt)) ?>
                    </footer>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </main>

    <script src="<?= url('/assets/vendor/qrcode-generator.js') ?>"></script>
    <script src="<?= url('/assets/communication-qr.js') ?>"></script>
    <script>
    const printQrCopy = (copy) => {
        document.body.dataset.printCopy = copy;
        requestAnimationFrame(() => window.print());
    };

    window.addEventListener('afterprint', () => {
        document.body.dataset.printCopy = 'both';
    });
    </script>
</body>
</html>
