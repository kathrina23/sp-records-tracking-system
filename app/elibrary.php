<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/term_dates.php';

function legislation_amendment_options(int $draftId = 0): array
{
    $stmt = db()->prepare('SELECT p.id,p.kind,p.number,p.title,t.name term_name FROM legislation_publications p LEFT JOIN committee_terms t ON t.id=p.term_id WHERE NOT (p.source_draft_id <=> ?) AND NOT EXISTS (SELECT 1 FROM legislation_drafts d WHERE d.id=? AND d.record_id=p.record_id AND d.kind=p.kind) ORDER BY p.approved_date DESC,p.id DESC');
    $stmt->execute([$draftId, $draftId]);
    return $stmt->fetchAll();
}

function legislation_amendment_ids(array $input, int $draftId = 0): string
{
    $selection = $input['amendment_ids'] ?? [];
    if (!is_array($selection) || count($selection) > 100) {
        throw new InvalidArgumentException('Choose valid amendment records (up to 100).');
    }
    $allowed = array_map('intval', array_column(legislation_amendment_options($draftId), 'id'));
    $ids = [];
    foreach ($selection as $id) {
        if (!is_scalar($id) || !ctype_digit((string) $id) || !in_array((int) $id, $allowed, true)) {
            throw new InvalidArgumentException('Choose an existing published ordinance or resolution. A document cannot amend itself.');
        }
        $ids[] = (int) $id;
    }
    return json_encode(array_values(array_unique($ids)));
}

function render_legislation_amendment_selector(array $values, int $draftId = 0): void
{
    $selected = json_decode($values['amendment_ids'] ?? '[]', true) ?: [];
    $selected = is_array($selected) ? array_map('intval', array_filter($selected, 'is_scalar')) : [];
    ?>
    <fieldset class="historical-choice-group"><legend>Amendment of (optional)</legend>
        <p class="muted">Tag the existing legislation that this document amends. Each document keeps its own details and copy.</p>
        <input type="search" id="amendment-search" placeholder="Search number, title or term" aria-label="Search legislation to tag">
        <div class="historical-choices" id="amendment-choices">
        <?php $options = legislation_amendment_options($draftId); foreach ($options as $option): ?>
            <label><input type="checkbox" name="amendment_ids[]" value="<?= (int) $option['id'] ?>" <?= in_array((int) $option['id'], $selected, true) ? 'checked' : '' ?>><span><?= e(ucfirst($option['kind']) . ' ' . $option['number'] . ' — ' . $option['title'] . ($option['term_name'] ? ' (' . $option['term_name'] . ')' : '')) ?></span></label>
        <?php endforeach; if (!$options): ?><p class="muted">No published legislation available to tag.</p><?php endif; ?>
        </div>
    </fieldset>
    <script>
    document.getElementById('amendment-search').addEventListener('input', event => {
        const query = event.target.value.trim().toLocaleLowerCase();
        document.querySelectorAll('#amendment-choices label').forEach(label => {
            label.style.display = label.textContent.toLocaleLowerCase().includes(query) ? '' : 'none';
        });
    });
    </script>
    <?php
}

function render_legislation_amendment_links(array $ids): void
{
    if (!$ids) { echo '<p class="muted">No tagged legislation.</p>'; return; }
    $stmt = db()->prepare('SELECT id,kind,number,title FROM legislation_publications WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY approved_date DESC,id DESC');
    $stmt->execute($ids);
    echo '<ul>';
    foreach ($stmt->fetchAll() as $item) {
        echo '<li><a href="' . e(url('/legislation_detail.php?id=') . (int) $item['id']) . '">' . e(ucfirst($item['kind']) . ' ' . $item['number'] . ' — ' . $item['title']) . '</a></li>';
    }
    echo '</ul>';
}

function require_elibrary_staff(): void
{
    require_login();
    if ((current_user()['role'] ?? '') !== 'lmis_data_entry') {
        http_response_code(403);
        exit('Only LMIS Data Entry Staff can post legislation.');
    }
}

function legislation_number(array $record, string $kind): string
{
    if (!in_array($kind, ['ordinance', 'resolution'], true) || !record_has_plenary_approval($record)) {
        return '';
    }
    return trim((string) ($record['approved_' . $kind . '_number'] ?? ''));
}

function legislation_keywords(string $value): array
{
    return array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), fn ($keyword) => $keyword !== '')));
}

function legislation_categories(string $kind): array
{
    $stmt = db()->prepare('SELECT name FROM legislation_categories WHERE kind=? ORDER BY name');
    $stmt->execute([$kind]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function legislation_fields(array $input, ?string $kind = null): array
{
    $values = [];
    foreach (['keywords' => 1000, 'category' => 255, 'author' => 1000, 'co_author' => 1000, 'folder_code' => 255] as $field => $limit) {
        $value = $input[$field] ?? '';
        if (!is_string($value)) {
            throw new InvalidArgumentException('Please enter valid legislation details.');
        }
        $value = trim($value);
        if ($field === 'keywords') {
            $value = implode(', ', legislation_keywords($value));
        }
        if ((!in_array($field, ['co_author', 'folder_code'], true) && $value === '') || strlen($value) > $limit) {
            throw new InvalidArgumentException('Please complete ' . str_replace('_', ' ', $field) . ' within its allowed length.');
        }
        $values[$field] = $value;
    }
    if ($kind !== null && !in_array($values['category'], legislation_categories($kind), true)) {
        throw new InvalidArgumentException('Please select a saved category for this legislation type.');
    }
    return $values;
}

function legislation_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Please select a final signed PDF or image within the server upload limit.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > 10 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('The final signed file must be between 1 byte and 10 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $types = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($types[$mime])) {
        throw new InvalidArgumentException('Only PDF, JPEG, and PNG files are allowed.');
    }
    $dir = dirname(__DIR__) . '/storage/elibrary';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('Unable to create the E-Library upload directory.');
    }
    $stored = bin2hex(random_bytes(24)) . '.' . $types[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        throw new RuntimeException('Unable to save the signed file.');
    }
    return ['original_name' => substr(basename(str_replace('\\', '/', $file['name'])), 0, 255),
        'stored_name' => $stored, 'mime_type' => $mime, 'file_size' => $size];
}

function legislation_search_query(string $query, int $page, array $filters = [], bool $paginate = true): array
{
    $sql = 'SELECT id, record_id, term_id, committee_names, kind, number, title, approved_date, keywords, category, author, co_author, folder_code, original_name, stored_name FROM legislation_publications';
    $params = [];
    $words = preg_split('/[\s,]+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $clauses = [];
    if (in_array($filters['kind'] ?? '', ['ordinance', 'resolution'], true)) {
        $clauses[] = 'kind = ?';
        $params[] = $filters['kind'];
    }
    if (!empty($filters['term_id'])) {
        $clauses[] = "(term_id = ? OR (term_id IS NULL AND EXISTS (SELECT 1 FROM committee_terms t WHERE t.id = ? AND DATE_FORMAT(legislation_publications.approved_date, '%Y-%m') BETWEEN CONCAT(t.start_year, '-', LPAD(COALESCE(t.start_month, 1), 2, '0')) AND CONCAT(t.end_year, '-', LPAD(COALESCE(t.end_month, 12), 2, '0')))))";
        $params[] = (int) $filters['term_id'];
        $params[] = (int) $filters['term_id'];
    }
    if (!empty($filters['category'])) {
        $clauses[] = 'category = ?';
        $params[] = $filters['category'];
    }
    foreach (['author', 'co_author'] as $authorField) {
        if (!empty($filters[$authorField])) {
            $clauses[] = "$authorField LIKE ? ESCAPE '='";
            $params[] = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $filters[$authorField]) . '%';
        }
    }
    foreach (array_slice($words, 0, 20) as $word) {
        $clauses[] = "CONCAT_WS(' ', kind, number, title, keywords, category, author, co_author, folder_code) LIKE ? ESCAPE '='";
        $params[] = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $word) . '%';
    }
    if ($clauses) {
        $sql .= ' WHERE ' . implode(' AND ', $clauses);
    }
    $sql .= ' ORDER BY approved_date DESC, id DESC';
    if ($paginate) { $sql .= ' LIMIT 21 OFFSET ' . ((max(1, $page) - 1) * 20); }
    return [$sql, $params];
}
