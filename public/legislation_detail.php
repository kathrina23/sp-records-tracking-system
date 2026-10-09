<?php
require_once __DIR__ . '/../app/elibrary.php';
$stmt = db()->prepare('SELECT * FROM legislation_publications WHERE id=?');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$entry = $stmt->fetch();
if (!$entry) { http_response_code(404); exit('Published legislation not found.'); }
$trackHistory = [];
if (!empty($entry['record_id'])) {
    $historyStmt = db()->prepare('SELECT created_at, to_status FROM record_movements WHERE record_id=? AND (from_status IS NULL OR from_status <> to_status) ORDER BY created_at ASC, id ASC');
    $historyStmt->execute([(int) $entry['record_id']]);
    // Show status changes only; other movement logs remain in the audit history.
    $previousStatus = null;
    foreach ($historyStmt->fetchAll() as $movement) {
        $status = trim((string) ($movement['to_status'] ?? ''));
        if ($status === '' || $status === $previousStatus) {
            continue;
        }
        $trackHistory[] = $movement;
        $previousStatus = $status;
    }
}
if (!$trackHistory && !empty($entry['approved_date'])) {
    $trackHistory[] = ['created_at' => $entry['approved_date'], 'to_status' => 'Approved in the Plenary'];
}
$termName = '';
if ($entry['term_id']) {
    $termStmt = db()->prepare('SELECT name FROM committee_terms WHERE id=?');
    $termStmt->execute([$entry['term_id']]);
    $termName = (string) $termStmt->fetchColumn();
}
$renderDocument = static function () use ($entry): void {
    if ($entry['stored_name'] === '') { echo '<p class="muted">No copy attached.</p>'; return; }
    ?>
    <article class="legislation-document-card">
        <span class="legislation-document-icon" aria-hidden="true">▤</span>
        <h2><?= e(ucfirst($entry['kind']) . ' ' . $entry['number']) ?></h2>
        <p><?= e($entry['original_name']) ?></p>
        <p class="legislation-document-date">Approved: <?= e(display_date($entry['approved_date'] ?? '')) ?></p>
        <a href="<?= url('/legislation_file.php?id=') . (int) $entry['id'] ?>" target="_blank" rel="noopener" class="legislation-document-button"><?= $entry['mime_type'] === 'application/pdf' ? 'View PDF' : 'View Copy' ?> <span aria-hidden="true">↗</span></a>
    </article>
    <?php
};
require __DIR__ . '/../app/partials/header.php';
?>
<section class="panel legislation-detail-page">
    <header class="legislation-detail-header">
        <h1><?= e($entry['title']) ?></h1>
        <div class="legislation-detail-meta"><span><strong><?= e(ucfirst($entry['kind'])) ?> No.:</strong> <?= e($entry['number']) ?></span><span><strong>Date Approved:</strong> <?= e(display_date($entry['approved_date'] ?? '')) ?></span></div>
    </header>
    <div class="legislation-detail-tabs" role="tablist" aria-label="Legislation details">
        <?php foreach (['overview' => 'Overview', 'information' => 'All Information', 'documents' => 'Documents', 'amendments' => 'Amendments'] as $key => $label): ?>
        <button type="button" role="tab" id="tab-<?= $key ?>" aria-controls="panel-<?= $key ?>" aria-selected="<?= $key === 'overview' ? 'true' : 'false' ?>" tabindex="<?= $key === 'overview' ? '0' : '-1' ?>"><?= e($label) ?></button>
        <?php endforeach; ?>
    </div>
    <div role="tabpanel" id="panel-overview" aria-labelledby="tab-overview" tabindex="0">
        <h2 class="legislation-detail-label">Title / Subject:</h2><p><?= e($entry['title']) ?></p>
        <h2 class="legislation-detail-label">Category:</h2><p><?= e($entry['category']) ?></p>
        <?php if ($termName): ?><h2 class="legislation-detail-label">Term:</h2><p><?= e($termName) ?></p><?php endif; ?>
        <?php if ($entry['committee_names']): ?><h2 class="legislation-detail-label">Committee(s):</h2><p><?= e($entry['committee_names']) ?></p><?php endif; ?>
        <h2 class="legislation-detail-label">Legislative Status:</h2><p>Approved in the Plenary · <?= e(display_date($entry['approved_date'] ?? '')) ?></p>
        <h2 class="legislation-detail-label">Track History</h2>
        <div class="table-wrap">
            <table class="legislation-track-history">
                <thead><tr><th scope="col">Date</th><th scope="col">Status</th></tr></thead>
                <tbody>
                    <?php foreach ($trackHistory as $movement): ?>
                        <tr><td><?= e(display_date($movement['created_at'] ?? '')) ?></td><td><?= e($movement['to_status'] ?? '') ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$trackHistory): ?><tr><td colspan="2">No tracking history is available.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php $renderDocument(); ?>
    </div>
    <div role="tabpanel" id="panel-information" aria-labelledby="tab-information" tabindex="0" hidden>
        <dl class="legislation-details">
        <?php if ($termName): ?><dt>Term</dt><dd><?= e($termName) ?></dd><?php endif; ?>
        <?php if ($entry['committee_names']): ?><dt>Committee(s)</dt><dd><?= e($entry['committee_names']) ?></dd><?php endif; ?>
        <?php foreach (['kind' => 'Type', 'number' => 'Number', 'title' => 'Title / Subject', 'approved_date' => 'Date Approved', 'category' => 'Category', 'keywords' => 'Keywords', 'author' => 'Author', 'co_author' => 'Co-Author', 'folder_code' => 'Folder Code'] as $field => $label): ?>
            <dt><?= e($label) ?></dt><dd><?php if ($field === 'keywords'): ?><?php foreach (legislation_keywords($entry[$field]) as $keyword): ?><span class="legislation-keyword"><?= e($keyword) ?></span><?php endforeach; ?><?php else: ?><?= e($field === 'approved_date' ? display_date($entry[$field] ?? '') : ($entry[$field] ?: '—')) ?><?php endif; ?></dd>
        <?php endforeach; ?>
        </dl>
    </div>
    <div role="tabpanel" id="panel-documents" aria-labelledby="tab-documents" tabindex="0" hidden><?php $renderDocument(); ?></div>
    <div role="tabpanel" id="panel-amendments" aria-labelledby="tab-amendments" tabindex="0" hidden>
        <h2>Amendment of</h2>
        <?php render_legislation_amendment_links(json_decode($entry['amendment_ids'] ?? '[]', true) ?: []); ?>
        <h2>Amendments to this legislation</h2>
        <?php
        $amendments = db()->prepare('SELECT id FROM legislation_publications WHERE JSON_CONTAINS(COALESCE(amendment_ids, \'[]\'), ?, \'$\') AND id<>? ORDER BY approved_date DESC,id DESC');
        $amendments->execute([(string) (int) $entry['id'], $entry['id']]);
        render_legislation_amendment_links($amendments->fetchAll(PDO::FETCH_COLUMN));
        ?>
    </div>
</section>
<script>
(() => {
    const tabs = Array.from(document.querySelectorAll('.legislation-detail-tabs [role="tab"]'));
    const activate = tab => {
        tabs.forEach(item => {
            const selected = item === tab;
            item.setAttribute('aria-selected', String(selected));
            item.tabIndex = selected ? 0 : -1;
            document.getElementById(item.getAttribute('aria-controls')).hidden = !selected;
        });
    };
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activate(tab));
        tab.addEventListener('keydown', event => {
            let next;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = tabs.length - 1;
            if (next === undefined) return;
            event.preventDefault();
            activate(tabs[next]);
            tabs[next].focus({preventScroll: true});
        });
    });
})();
</script>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
