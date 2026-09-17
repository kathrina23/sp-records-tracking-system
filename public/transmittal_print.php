<?php
require_once __DIR__ . '/../app/auth.php';
require_login();
ensure_plenary_number_schema();
$recordId = (int) ($_GET['record_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM records WHERE id = ?');
$stmt->execute([$recordId]);
$record = $stmt->fetch();
if (!$record) { http_response_code(404); exit('Record not found.'); }
if (!can_manage_transmittal_recipients($record)) {
    http_response_code(403); exit('You are not allowed to print transmittals for this record.');
}
$query = 'SELECT * FROM record_recipients WHERE record_id = ?';
$params = [$recordId];
$stmt = db()->prepare($query . ' ORDER BY id ASC');
$stmt->execute($params);
$recipients = $stmt->fetchAll();
if (!$recipients) { http_response_code(404); exit('No recipients found. Add recipients before printing.'); }
$documentParts = [];
if (trim((string) ($record['approved_ordinance_number'] ?? '')) !== '') {
    $documentParts[] = 'Ordinance No. ' . $record['approved_ordinance_number'];
}
if (trim((string) ($record['approved_resolution_number'] ?? '')) !== '') {
    $documentParts[] = 'Resolution No. ' . $record['approved_resolution_number'];
}
$documentLabel = $documentParts ? implode(' / ', $documentParts) : 'Communication No. ' . $record['control_number'];
$approvedDate = trim((string) ($record['plenary_approved_date'] ?? ''));
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Transmittal - <?= e($record['control_number']) ?></title>
<link rel="stylesheet" href="<?= url('/assets/transmittal-print.css?v=') ?><?= (int) filemtime(__DIR__ . '/assets/transmittal-print.css') ?>">
</head><body>
<div class="print-toolbar">
    <a href="<?= url('/record_recipients.php?record_id=') ?><?= $recordId ?>">Back to Recipients</a>
    <label>Signatory name<input id="signatory-name" value="RODERICO V. DUMAU, JR."></label>
    <label>Position<input id="signatory-position" value="Chief Administrative Officer"></label>
    <button type="button" id="print-transmittals">Print Transmittal (<?= count($recipients) ?> recipients)</button>
    <p>All recipients are included in this document, with each transmittal starting on a new page. Confirm the signatory, then print using Folio / custom 8.5 &times; 13 inch paper, 100% scale, with browser headers and footers off. Choose Save as PDF to keep all transmittals in one file.</p>
</div>
<?php foreach ($recipients as $recipient):
    $recipientName = trim(($recipient['title'] ?? '') . ' ' . $recipient['name']);
?>
<article class="transmittal-sheet">
    <section class="transmittal-letter">
        <h1>TRANSMITTAL</h1>
        <p class="center"><?= e(display_date(date('Y-m-d'))) ?></p>
        <p>Respectfully forwarded to <strong><?= e($recipientName) ?>, <?= e($recipient['position']) ?></strong>,
        <?= nl2br(e($recipient['address'])) ?>, the herein copy of <strong><?= e($documentLabel) ?></strong><?php if ($approvedDate !== ''): ?>, passed by the City Council of Cagayan de Oro City on <strong><?= e(display_date($approvedDate)) ?></strong><?php endif; ?>, to wit:</p>
        <div class="document-title"><?= nl2br(e(display_record_title($record['title']))) ?></div>
        <p class="information">For your information.</p>
        <div class="signature"><strong data-signatory-name>RODERICO V. DUMAU, JR.</strong><br><span data-signatory-position>Chief Administrative Officer</span></div>
    </section>
    <section class="acknowledgment">
        <p class="copy-label">Office Copy</p>
        <div class="receipt-box">
            <p class="date-line">Date: ____ / ____ / ______ &nbsp; Time: __________</p>
            <h2>ACKNOWLEDGMENT RECEIPT</h2>
            <p>Office / Organization: <strong><?= e($recipientName) ?></strong><br>
            Position: <strong><?= e($recipient['position']) ?></strong><br>
            Address: <strong><?= nl2br(e($recipient['address'])) ?></strong></p>
            <p>I, ________________________________________, hereby acknowledge that I have received the copy of <strong><?= e($documentLabel) ?></strong> from the Office of the City Council of Cagayan de Oro City.</p>
            <p class="center">Received by:<br><br>________________________________<br>Name &amp; Signature</p>
        </div>
    </section>
    <div class="delivery-slips">
        <?php foreach (["Messenger's Copy", "Recipient's Copy"] as $copy): ?>
        <section class="delivery-slip">
            <p><strong><?= e($copy) ?></strong></p>
            <p>Date: ____ / ____ / ______ &nbsp; Time: __________</p>
            <p>Office / Organization: <strong><?= e($recipientName) ?></strong><br>
            Position: <strong><?= e($recipient['position']) ?></strong><br>
            Address: <strong><?= nl2br(e($recipient['address'])) ?></strong><br>
            Document: <strong><?= e($documentLabel) ?></strong></p>
            <p>Delivered by: __________________________</p>
            <p class="center">Received by:<br><br>____________________________<br>Name &amp; Signature</p>
        </section>
        <?php endforeach; ?>
    </div>
</article>
<?php endforeach; ?>
<script>
for (const key of ['name', 'position']) {
    const input = document.getElementById('signatory-' + key);
    input.addEventListener('input', () => {
        document.querySelectorAll('[data-signatory-' + key + ']').forEach(node => { node.textContent = input.value; });
    });
}
document.getElementById('print-transmittals').addEventListener('click', () => window.print());
</script>
</body></html>