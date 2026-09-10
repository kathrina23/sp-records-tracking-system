<?php

require_once __DIR__ . '/../app/auth.php';
require_login();
ensure_plenary_number_schema();

$recordId = (int) ($_GET['record_id'] ?? 0);
$isPopup = ($_GET['popup'] ?? '') === '1';
$defaultCloseUrl = url('/record_view.php?id=' . $recordId);
$closeUrl = (string) ($_GET['return_url'] ?? $defaultCloseUrl);
$closeParts = parse_url($closeUrl);
if (
    $closeUrl === ''
    || $closeParts === false
    || isset($closeParts['scheme'])
    || isset($closeParts['host'])
    || !in_array(($closeParts['path'] ?? ''), [url('/record_view.php'), url('/dashboard.php')], true)
) {
    $closeUrl = $defaultCloseUrl;
}

$recordStmt = db()->prepare('SELECT * FROM records WHERE id = ?');
$recordStmt->execute([$recordId]);
$record = $recordStmt->fetch();
if (!$record) {
    http_response_code(404);
    exit('Record not found.');
}

if (!can_manage_plenary_record_attachments($record)) {
    http_response_code(403);
    exit('Only the Laws and Rules Secretariat can manage Attachments on File for plenary records.');
}

$attachmentStmt = db()->prepare("SELECT a.*, COALESCE(NULLIF(u.nickname, ''), u.name) uploaded_by_name,
        u.role uploaded_by_role
    FROM record_attachments a
    LEFT JOIN users u ON u.id = a.uploaded_by
    WHERE a.record_id = ?
    ORDER BY a.created_at ASC, a.id ASC");
$attachmentStmt->execute([$recordId]);
$attachments = $attachmentStmt->fetchAll();

$managerParams = ['record_id' => $recordId];
if ($isPopup) {
    $managerParams['popup'] = 1;
    $managerParams['return_url'] = $closeUrl;
}
$managerUrl = url('/record_attachments_manage.php?' . http_build_query($managerParams));

require __DIR__ . '/../app/partials/header.php';
?>
<?php if ($isPopup): ?><div class="modal-backdrop" role="presentation"><?php endif; ?>
<section class="panel <?= $isPopup ? 'user-edit-modal attachment-manager-modal' : 'attachment-manager-page' ?>" <?= $isPopup ? 'role="dialog" aria-modal="true" aria-labelledby="attachment_manager_title"' : '' ?>>
    <?php if ($isPopup): ?>
        <a class="modal-close" href="<?= e($closeUrl) ?>" aria-label="Close Attachments on File">X</a>
    <?php endif; ?>

    <div class="page-head">
        <div>
            <h1 id="attachment_manager_title">Attachments on File</h1>
            <p><strong><?= e($record['control_number']) ?></strong></p>
            <p class="muted"><?= e(display_record_title(record_title_for_current_user($record))) ?></p>
        </div>
        <?php if (!$isPopup): ?>
            <div class="actions">
                <a class="btn secondary" href="<?= e($closeUrl) ?>">Back to Record</a>
            </div>
        <?php endif; ?>
    </div>

    <form method="post" action="<?= url('/record_attachment.php') ?>" enctype="multipart/form-data" class="attachment-manager-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="record_id" value="<?= $recordId ?>">
        <input type="hidden" name="attachment_action" value="manage_plenary_attachments">
        <input type="hidden" name="return_url" value="<?= e($managerUrl) ?>">

        <section class="attachment-manager-section" aria-labelledby="existing_attachment_title">
            <h2 id="existing_attachment_title">Attachments and record entries</h2>
            <p class="muted">Saved titles and files are listed here. A record entry may have a title without an attached file.</p>
            <?php if ($attachments): ?>
                <div class="attachment-manager-existing-list">
                    <?php foreach ($attachments as $attachment): ?>
                        <article class="attachment-manager-existing-item">
                        <?php $hasAttachedFile = trim((string) ($attachment['stored_name'] ?? '')) !== ''; ?>
                            <label>
                                Attachment title
                                <input
                                    type="text"
                                    name="existing_titles[<?= (int) $attachment['id'] ?>]"
                                    value="<?= e(record_attachment_display_title($attachment)) ?>"
                                    maxlength="255"
                                    aria-describedby="attachment_file_<?= (int) $attachment['id'] ?>"
                                >
                            </label>
                            <div class="attachment-manager-file-meta" id="attachment_file_<?= (int) $attachment['id'] ?>">
                                <?php if ($hasAttachedFile): ?>
                                    <a href="<?= url('/record_attachment.php?id=') ?><?= (int) $attachment['id'] ?>" target="_blank" rel="noopener"><?= e($attachment['original_name']) ?></a>
                                <?php else: ?>
                                    <span class="attachment-manager-record-only">Record entry only · No file attached</span>
                                <?php endif; ?>
                                <span>
                                    <?= $hasAttachedFile ? 'File' : 'Record entry' ?> added
                                    <?php if (!empty($attachment['uploaded_by_name'])): ?> by <?= e($attachment['uploaded_by_name']) ?><?php endif; ?>
                                    on <?= e(display_datetime($attachment['created_at'] ?? '')) ?>
                                </span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="muted">No attachments or record entries have been added yet.</p>
            <?php endif; ?>
        </section>

        <section class="attachment-manager-section" aria-labelledby="new_attachment_title">
            <div class="attachment-manager-section-head">
                <div>
                    <h2 id="new_attachment_title">Add attachment or record title</h2>
                    <p class="muted">Enter the title for record purposes. Attaching a PDF or image is optional. Maximum 10 MB per file and 35 MB combined.</p>
                </div>
                <button class="btn secondary attachment-row-add" type="submit" aria-label="Add attachment or record title">
                    <span aria-hidden="true">+</span> Add
                </button>
            </div>

            <div class="attachment-manager-new-list">
                <div class="attachment-manager-new-row">
                    <label>
                        Attachment title
                        <input type="text" name="attachment_titles[]" maxlength="255" required>
                    </label>
                    <label>
                        Attach file <span class="muted">(Optional)</span>
                        <input type="file" name="attachments[]" accept="application/pdf,image/*">
                    </label>
                </div>
            </div>
        </section>

        <div class="actions attachment-manager-actions">
            <button class="btn" type="submit">Save Title Changes</button>
            <a class="btn secondary" href="<?= e($closeUrl) ?>">Cancel</a>
        </div>
    </form>
</section>
<?php if ($isPopup): ?></div><?php endif; ?>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
