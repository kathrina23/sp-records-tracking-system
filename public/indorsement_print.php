<?php
require_once __DIR__ . '/../app/auth.php';
require_login();
$stmt = db()->prepare('SELECT * FROM records WHERE id = ?');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$record = $stmt->fetch();
if (!$record) {
    http_response_code(404);
    exit('Record not found.');
}
if (!can_update_record_status($record)) {
    http_response_code(403);
    exit('You are not allowed to print an indorsement for this record.');
}
if (($record['status'] ?? '') !== 'Forwarded for Admin/Mayor Signature') {
    http_response_code(409);
    exit('Save the status as Forwarded for Admin/Mayor Signature before printing an indorsement.');
}
$numbers = [];
foreach (['approved_ordinance_number' => 'Ordinance No. ', 'approved_resolution_number' => 'Resolution No. '] as $field => $label) {
    if (trim((string) ($record[$field] ?? '')) !== '') {
        $numbers[] = $label . $record[$field];
    }
}
$documentLabel = $numbers ? implode(' / ', $numbers) : 'Communication No. ' . $record['control_number'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Indorsement - <?= e($record['control_number']) ?></title>
    <link rel="stylesheet" href="<?= url('/assets/indorsement-print.css?v=') ?><?= (int) filemtime(__DIR__ . '/assets/indorsement-print.css') ?>">
</head>
<body>
<div class="print-toolbar">
    <h1>Print Indorsement</h1>
    <p>Review the letters below. Enter names and the signatory before printing, or leave the spaces blank to complete by hand.</p>
    <div class="print-controls">
        <label>Print for<select id="print-for"><option value="both">City Administrator and City Mayor</option><option value="administrator">City Administrator</option><option value="mayor">City Mayor</option></select></label>
        <label>City Administrator name<input data-edit="administrator" maxlength="180"></label>
        <label>City Mayor name<input data-edit="mayor" maxlength="180"></label>
        <label>Signatory name<input data-edit="signatory" maxlength="180"></label>
        <label>Signatory position<input data-edit="position" maxlength="180"></label>
    </div>
    <button type="button" id="print-letter">Print Indorsement</button>
</div>
<?php foreach (['administrator' => 'City Administrator', 'mayor' => 'City Mayor'] as $key => $office): ?>
<article class="indorsement-sheet" data-office="<?= e($key) ?>">
    <header>Republic of the Philippines<br><strong>CITY OF CAGAYAN DE ORO</strong><br>OFFICE OF THE SANGGUNIANG PANLUNGSOD</header>
    <h2>INDORSEMENT</h2>
    <p class="letter-date"><?= e(date('F j, Y')) ?></p>
    <p><strong data-display="<?= e($key) ?>">____________________________</strong><br><?= e($office) ?><br>City Government of Cagayan de Oro<br>Cagayan de Oro City</p>
    <p><strong>Subject: <?= e($documentLabel) ?></strong><br>Reference: <?= e($record['control_number']) ?></p>
    <p>Sir/Madam:</p>
    <p>Respectfully forwarded to your office, for <?= $key === 'administrator' ? 'review and endorsement to the City Mayor for signature' : 'review and signature' ?>, is the attached copy of <strong><?= e($documentLabel) ?></strong><?php if (!empty($record['plenary_approved_date'])): ?>, approved by the Sangguniang Panlungsod on <?= e(display_date($record['plenary_approved_date'])) ?><?php endif; ?>, entitled:</p>
    <p class="document-title"><?= nl2br(e(display_record_title($record['title']))) ?></p>
    <p>Kindly return the duly signed document to the Office of the Sangguniang Panlungsod for recording and further transmittal.</p>
    <p>Thank you.</p>
    <div class="signature"><p>Respectfully yours,</p><br><strong data-display="signatory">____________________________</strong><br><span data-display="position">____________________________</span></div>
</article>
<?php endforeach; ?>
<script>
document.querySelectorAll('[data-edit]').forEach(input => {
    input.addEventListener('input', () => {
        document.querySelectorAll('[data-display="' + input.dataset.edit + '"]').forEach(output => {
            output.textContent = input.value.trim() || '____________________________';
        });
    });
});
document.getElementById('print-for').addEventListener('change', event => {
    document.querySelectorAll('[data-office]').forEach(letter => {
        letter.hidden = event.target.value !== 'both' && event.target.value !== letter.dataset.office;
    });
});
document.getElementById('print-letter').addEventListener('click', () => window.print());
</script>
</body>
</html>
