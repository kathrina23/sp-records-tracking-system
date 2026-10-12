<?php
require_once __DIR__ . '/../app/auth.php';
require_login();
if (!can_city_secretary_action()) {
    http_response_code(403);
    exit('Only the Administrator or City Secretary can view the correction report.');
}
$corrections = db()->query("SELECT a.description, a.created_at, r.id, r.control_number
    FROM audit_logs a INNER JOIN records r ON r.id = a.entity_id
    WHERE a.entity_type = 'record' AND a.action = 'recommendation_history_repair'
    ORDER BY a.id DESC")->fetchAll();
require __DIR__ . '/../app/partials/header.php';
?>
<div class="page-head"><div><h1>Recommendation History Corrections</h1>
<p class="muted">Existing records are corrected automatically when the updated system is opened.</p></div>
<a class="btn secondary" href="<?= url('/records.php') ?>">Back to Records</a></div>
<section class="panel">
<p>Committees added by the former recommending-approval workflow are treated as recommendation recipients. They no longer increase the Joint referral count or appear in the City Secretary's original submission history. Original recommendation entries, dates and notes are retained.</p>
<?php if (!$corrections): ?><p>No automatic corrections have been needed.</p><?php endif; ?>
<?php foreach ($corrections as $correction): ?>
    <p><a href="<?= url('/record_view.php?id=' . $correction['id']) ?>"><?= e($correction['control_number']) ?></a>
    — <?= e(display_datetime($correction['created_at'])) ?><br><?= e($correction['description']) ?></p>
<?php endforeach; ?>
</section>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
