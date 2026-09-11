<?php

require_once __DIR__ . '/../app/auth.php';
require_login();
ensure_plenary_number_schema();

$recordId = (int) ($_GET['record_id'] ?? 0);
$returnTarget = in_array($_GET['return'] ?? '', ['records', 'dashboard', 'record', 'review'], true)
    ? (string) $_GET['return']
    : 'record';
$isPopup = ($_GET['popup'] ?? '') === '1';
$defaultCloseUrl = url('/record_view.php?id=' . $recordId);
$closeUrl = (string) ($_GET['return_url'] ?? $defaultCloseUrl);
$allowedClosePath = match ($returnTarget) {
    'records' => url('/records.php'),
    'dashboard' => url('/dashboard.php'),
    'review' => url('/record_form.php'),
    default => url('/record_view.php'),
};
$closeParts = parse_url($closeUrl);
if (
    $closeUrl === ''
    || $closeParts === false
    || isset($closeParts['scheme'])
    || isset($closeParts['host'])
    || url($closeParts['path'] ?? '') !== $allowedClosePath
) {
    $closeUrl = $defaultCloseUrl;
}
$closeUrl = url($closeUrl);
$recordStmt = db()->prepare('SELECT * FROM records WHERE id = ?');
$recordStmt->execute([$recordId]);
$record = $recordStmt->fetch();

if (!$record) {
    http_response_code(404);
    exit('Record not found.');
}

if (!can_view_record_attachments($record)) {
    http_response_code(403);
    exit('You are not allowed to view attachments for this record.');
}

$attachmentStmt = db()->prepare("SELECT a.*, COALESCE(NULLIF(u.nickname, ''), u.name) uploaded_by_name
    FROM record_attachments a
    LEFT JOIN users u ON u.id = a.uploaded_by
    WHERE a.record_id = ?
    ORDER BY a.created_at ASC, a.id ASC");
$attachmentStmt->execute([$recordId]);
$attachments = $attachmentStmt->fetchAll();

audit_log(
    'record_attachments_combined_view',
    'Opened the combined attachment viewer for record ' . $record['control_number'] . '.',
    'record',
    $recordId
);

require __DIR__ . '/../app/partials/header.php';
?>
<?php if ($isPopup): ?>
<div class="modal-backdrop" role="presentation">
    <div class="panel user-edit-modal attachment-view-modal" role="dialog" aria-modal="true" aria-labelledby="attachment_view_title">
<?php endif; ?>
<div class="page-head combined-attachments-head">
    <div>
        <h1 id="attachment_view_title">Attachments: <?= e($record['control_number']) ?></h1>
        <p class="muted"><?= e(display_record_title($record['title'])) ?></p>
    </div>
    <div class="actions">
        <?php if ($isPopup): ?>
            <a class="modal-close" href="<?= e($closeUrl) ?>" aria-label="Close attachment viewer">X</a>
        <?php else: ?>
            <a class="btn secondary" href="<?= e($closeUrl) ?>">Back to Record</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($attachments): ?>
    <section class="combined-document" aria-label="Combined record attachments">
        <?php foreach ($attachments as $index => $attachment): ?>
            <?php $isImage = str_starts_with((string) $attachment['mime_type'], 'image/'); $displayTitle = record_attachment_display_title($attachment); ?>
            <article class="combined-document-part">
                <header>
                    <strong>File <?= $index + 1 ?> of <?= count($attachments) ?></strong>
                    <span><?= e($displayTitle) ?></span>
                </header>
                <?php if ($isImage): ?>
                    <div class="combined-image-wrap">
                        <img src="<?= url('/record_attachment.php?id=') ?><?= (int) $attachment['id'] ?>" alt="<?= e($displayTitle) ?>">
                    </div>
                <?php else: ?>
                    <iframe
                        class="combined-pdf-frame"
                        src="<?= url('/record_attachment.php?id=') ?><?= (int) $attachment['id'] ?>#toolbar=1&navpanes=0"
                        title="<?= e($displayTitle) ?>"
                    ></iframe>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
<?php else: ?>
    <section class="panel"><p class="muted">No attachments uploaded for this record.</p></section>
<?php endif; ?>

<?php if ($isPopup): ?>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
