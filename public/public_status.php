<?php

require_once __DIR__ . '/../app/auth.php';

$controlNumber = strtoupper(trim($_GET['control_number'] ?? ''));
$recordId = max(0, (int) ($_GET['record_id'] ?? 0));
$lookupRequested = $recordId > 0 || $controlNumber !== '';
$record = null;
$forwardedTo = '';

if ($lookupRequested) {
    $lookupColumn = $recordId > 0 ? 'r.id' : 'r.control_number';
    $lookupValue = $recordId > 0 ? $recordId : $controlNumber;
    $stmt = db()->prepare("SELECT r.id, r.control_number, r.title, r.document_type, r.status, r.received_date, r.updated_at, c.name committee_name
        FROM records r
        LEFT JOIN committees c ON c.id = r.committee_id
        WHERE $lookupColumn = ?
        LIMIT 1");
    $stmt->execute([$lookupValue]);
    $record = $stmt->fetch();
    if ($record) {
        $controlNumber = (string) $record['control_number'];
    }

    if ($record && in_array($record['status'], ['Referred To', 'Referred', 'Forwarded for Review'], true)) {
        $forwardedStmt = db()->prepare("SELECT notes
            FROM record_movements
            WHERE record_id = ?
            AND to_status IN ('Referred To', 'Referred', 'Forwarded for Review')
            ORDER BY created_at DESC, id DESC
            LIMIT 1");
        $forwardedStmt->execute([(int) $record['id']]);
        $forwardedNotes = (string) ($forwardedStmt->fetchColumn() ?: '');
        if (preg_match('/Office \\/ Organization \\/ Individual:\\s*(.+?)(?:\\R|$)/', $forwardedNotes, $matches)) {
            $forwardedTo = trim($matches[1]);
        } elseif (preg_match('/Office \\/ Person \\/ Organization:\\s*(.+?)(?:\\R|$)/', $forwardedNotes, $matches)) {
            $forwardedTo = trim($matches[1]);
        } elseif (preg_match('/Office \\/ Organization:\\s*(.+?)(?:\\R|$)/', $forwardedNotes, $matches)) {
            $forwardedTo = trim($matches[1]);
        }
    }
}

require __DIR__ . '/../app/partials/header.php';
?>
<section class="landing-window public-status-window">
    <div class="landing-head">
        <img src="<?= url('/assets/splogo.jpg') ?>" alt="Sangguniang Panlungsod logo">
        <div>
            <h1>Request Status</h1>
            <p class="muted">Search the latest status using your request communication number.</p>
        </div>
    </div>

    <section class="panel">
        <form method="get" class="public-search-form public-search-wide">
            <label>Communication Number
                <input name="control_number" placeholder="Example: L-00001-2026" value="<?= e($controlNumber) ?>" required autofocus>
            </label>
            <button class="btn" type="submit">Search</button>
            <a class="btn secondary" href="<?= url('/') ?>">Back</a>
        </form>
    </section>

    <?php if ($lookupRequested && !$record): ?>
        <section class="panel public-result-card">
            <h2>No Record Found</h2>
            <p class="muted">Please check the communication number and try again.</p>
        </section>
    <?php elseif ($record): ?>
        <section class="panel public-result-card">
            <div class="panel-title-row">
                <h2><?= e($record['control_number']) ?></h2>
                <span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span>
            </div>
            <div class="detail-list">
                <div class="full"><strong>Title</strong><br><?= nl2br(e(display_record_title($record['title']))) ?></div>
                <div><strong>Record Type</strong><br><?= e($record['document_type']) ?></div>
                <div><strong>Date Received</strong><br><?= e(display_date($record['received_date'] ?? '')) ?></div>
                <?php if ($record['document_type'] === 'Committee Referrals'): ?>
                    <div><strong>Committee</strong><br><?= e($record['committee_name'] ?: 'For Committee Assignment') ?></div>
                <?php endif; ?>
                <div><strong>Latest Status</strong><br><span class="badge <?= e(status_class($record['status'])) ?>"><?= e($record['status']) ?></span></div>
                <?php if (in_array($record['status'], ['Referred To', 'Referred', 'Forwarded for Review'], true) && $forwardedTo !== ''): ?>
                    <div><strong>Referred To</strong><br><?= e($forwardedTo) ?></div>
                <?php endif; ?>
                <div><strong>Last Updated</strong><br><?= e(display_datetime($record['updated_at'] ?? '')) ?></div>
            </div>
            <p class="muted public-note">Only the latest public status is shown. Full tracking details are available to authorized users.</p>
        </section>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
