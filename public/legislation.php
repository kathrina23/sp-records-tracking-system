<?php
require_once __DIR__ . '/../app/elibrary.php';
$search = substr(trim(is_string($_GET['search'] ?? '') ? $_GET['search'] ?? '' : ''), 0, 1000);
$page = min(100000, max(1, (int) ($_GET['page'] ?? 1)));
$terms = db()->query('SELECT id, name, start_year, start_month, end_year, end_month FROM committee_terms ORDER BY start_year DESC, id DESC')->fetchAll();
$filterKind = is_string($_GET['kind'] ?? '') && in_array($_GET['kind'] ?? '', ['ordinance', 'resolution'], true) ? $_GET['kind'] : '';
$filterTermId = (int) ($_GET['term_id'] ?? 0);
if (!in_array($filterTermId, array_map('intval', array_column($terms, 'id')), true)) { $filterTermId = 0; }
$filterCategory = is_string($_GET['category'] ?? '') ? substr(trim($_GET['category'] ?? ''), 0, 255) : '';
$filterAuthor = is_string($_GET['author'] ?? '') ? substr(trim($_GET['author'] ?? ''), 0, 1000) : '';
$filterCoAuthor = is_string($_GET['co_author'] ?? '') ? substr(trim($_GET['co_author'] ?? ''), 0, 1000) : '';
$filterValues = ['term_id' => $filterTermId, 'kind' => $filterKind, 'category' => $filterCategory, 'author' => $filterAuthor, 'co_author' => $filterCoAuthor];
$categoryOptions = db()->query("SELECT DISTINCT category FROM legislation_publications WHERE category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$authorOptions = db()->query("SELECT DISTINCT author FROM legislation_publications WHERE author <> '' ORDER BY author")->fetchAll(PDO::FETCH_COLUMN);
$coAuthorOptions = db()->query("SELECT DISTINCT co_author FROM legislation_publications WHERE co_author <> '' ORDER BY co_author")->fetchAll(PDO::FETCH_COLUMN);
$pageQuery = ['search' => $search] + $filterValues;
$download = in_array($_GET['download'] ?? '', ['pdf', 'csv'], true);
[$sql, $params] = legislation_search_query($search, $page, $filterValues, !$download);
$stmt = db()->prepare($sql);
$stmt->execute($params);
if ($download) {
    require_once __DIR__ . '/../app/legislation_report.php';
    $termName = 'All Terms';
    foreach ($terms as $term) { if ((int) $term['id'] === $filterTermId) { $termName = term_option_label($term); break; } }
    try {
        $pdf = legislation_report_pdf($stmt->fetchAll(), $termName,
            ['Search' => $search, 'Type' => ucfirst($filterKind), 'Category' => $filterCategory, 'Author' => $filterAuthor, 'Co-Author' => $filterCoAuthor]);
    } catch (Throwable $error) {
        error_log($error->getMessage());
        http_response_code(500);
        exit('Unable to generate the PDF report. Please contact the system administrator.');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="legislation-report.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store');
    echo $pdf;
    exit;
}
$entries = $stmt->fetchAll();
$hasNext = count($entries) > 20;
$entries = array_slice($entries, 0, 20);
$legislationBackground = true;
require __DIR__ . '/../app/partials/header.php';
?>
<nav class="legislation-page-nav" aria-label="Public navigation">
    <a class="btn secondary" href="<?= url('/') ?>">Public Main Window</a>
</nav>
<section class="panel legislation-search">
    <div class="page-head"><div><h1>Search Legislation</h1><p class="muted">E-Library of posted ordinances and resolutions of Cagayan de Oro City.</p></div></div>
    <form method="get" class="legislation-search-form">
        <?php foreach (['category' => $filterCategory, 'author' => $filterAuthor, 'co_author' => $filterCoAuthor] as $field => $value): ?><input type="hidden" name="<?= e($field) ?>" value="<?= e($value) ?>"><?php endforeach; ?>
        <div class="legislation-search-controls">
            <label>Term<select name="term_id"><option value="0">All Terms</option><?php foreach ($terms as $term): ?><option value="<?= (int) $term['id'] ?>" <?= $filterTermId === (int) $term['id'] ? 'selected' : '' ?>><?= e(term_option_label($term)) ?></option><?php endforeach; ?></select></label>
            <label>Type<select name="kind"><option value="">All</option><option value="ordinance" <?= $filterKind === 'ordinance' ? 'selected' : '' ?>>Ordinance</option><option value="resolution" <?= $filterKind === 'resolution' ? 'selected' : '' ?>>Resolution</option></select></label>
            <label for="legislation-search">Number / Title / Subject<input id="legislation-search" name="search" value="<?= e($search) ?>" maxlength="1000" placeholder="Search by Number, Subject, or Title..."></label>
            <button class="legislation-search-submit" type="submit"><svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10" cy="10" r="6"></circle><path d="m15 15 5 5"></path></svg>Search</button>
        </div>
        <div class="legislation-advanced-filters"><button type="button" id="open-legislation-filters" aria-haspopup="dialog"><span aria-hidden="true">☷</span> Advanced Filters</button></div>
    </form>
    <p class="muted"><?= $search !== '' ? 'Search results for “' . e($search) . '”' : 'Recently posted legislation' ?></p>
    <?php if (!$entries): ?><p>No posted legislation <?= $search !== '' ? 'matches your search. Try another keyword.' : 'is available yet.' ?></p><?php endif; ?>
    <?php if ($entries): ?>
    <div class="table-wrap legislation-results-wrap">
    <table class="legislation-results-table" data-auto-pagination="off">
        <thead><tr><th scope="col">Type</th><th scope="col">Number</th><th scope="col">Title / Subject</th><th scope="col">Action</th></tr></thead>
        <tbody>
    <?php foreach ($entries as $entry): ?>
        <tr>
            <td class="legislation-type"><?= e(ucfirst($entry['kind'])) ?></td>
            <td class="legislation-number"><?= e($entry['number']) ?></td>
            <td class="legislation-subject">
                <h2><?= e($entry['title']) ?></h2>
                <p class="legislation-approved-date">Approved: <?= e(display_date($entry['approved_date'] ?? '')) ?></p>
            </td>
            <td class="legislation-row-actions">
                <a class="legislation-learn-more" href="<?= url('/legislation_detail.php?id=') . (int) $entry['id'] ?>">Learn More <span aria-hidden="true">ⓘ</span></a>
                <?php if ($entry['stored_name'] !== ''): ?><a class="legislation-copy-link" href="<?= url('/legislation_file.php?id=') . (int) $entry['id'] ?>" target="_blank" rel="noopener"><?= strtolower(pathinfo($entry['stored_name'], PATHINFO_EXTENSION)) === 'pdf' ? 'View PDF' : 'View Copy' ?> <span aria-hidden="true">↗</span></a><?php else: ?><p class="muted">No copy attached.</p><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <nav class="actions" aria-label="Search result pages">
        <?php if ($page > 1): ?><a class="btn secondary" href="<?= url('/legislation.php?') . e(http_build_query($pageQuery + ['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
        <?php if ($hasNext): ?><a class="btn secondary" href="<?= url('/legislation.php?') . e(http_build_query($pageQuery + ['page' => $page + 1])) ?>">Next</a><?php endif; ?>
    </nav>
</section>
<dialog class="legislation-filter-window" id="legislation-filter-window" aria-labelledby="legislation-filter-title">
    <header class="legislation-filter-header"><h2 id="legislation-filter-title">Filters</h2><button type="button" class="window-close-control" id="close-legislation-filters" aria-label="Close filters">X</button></header>
    <form method="get" action="<?= url('/legislation.php') ?>" id="legislation-filter-form">
        <div class="legislation-filter-body">
            <h3>Document Filters</h3>
            <label>Number / Title / Subject<input name="search" value="<?= e($search) ?>" maxlength="1000" placeholder="Search by Number, Subject, or Title..."></label>
            <label>Term<select name="term_id"><option value="0">All Terms</option><?php foreach ($terms as $term): ?><option value="<?= (int) $term['id'] ?>" <?= $filterTermId === (int) $term['id'] ? 'selected' : '' ?>><?= e(term_option_label($term)) ?></option><?php endforeach; ?></select></label>
            <label>Type<select name="kind"><option value="">All</option><option value="ordinance" <?= $filterKind === 'ordinance' ? 'selected' : '' ?>>Ordinance</option><option value="resolution" <?= $filterKind === 'resolution' ? 'selected' : '' ?>>Resolution</option></select></label>
            <?php foreach (['author' => ['Author', 'All Authors', $authorOptions, $filterAuthor], 'co_author' => ['Co-Author', 'All Co-Authors', $coAuthorOptions, $filterCoAuthor], 'category' => ['Category', 'All Categories', $categoryOptions, $filterCategory]] as $field => [$label, $placeholder, $options, $selected]): ?>
            <label><?= e($label) ?><select name="<?= e($field) ?>"><option value=""><?= e($placeholder) ?></option><?php if ($selected !== '' && !in_array($selected, $options, true)): ?><option value="<?= e($selected) ?>" selected><?= e($selected) ?></option><?php endif; ?><?php foreach ($options as $option): ?><option value="<?= e($option) ?>" <?= $selected === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></label>
            <?php endforeach; ?>
            <p class="muted">Term filters use the legislation’s approval year.</p>
        </div>
        <div class="legislation-filter-actions">
            <button type="submit" class="legislation-filter-apply">Apply Filters</button>
            <button type="submit" name="download" value="pdf">↓ Download Report (PDF)</button>
            <button type="button" id="reset-legislation-filters">Reset All Filters</button>
        </div>
    </form>
</dialog>
<script src="<?= url('/assets/legislation-filters.js?v=') . (int) filemtime(__DIR__ . '/assets/legislation-filters.js') ?>"></script>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
