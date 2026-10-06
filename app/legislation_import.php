<?php
declare(strict_types=1);
require_once __DIR__ . '/elibrary.php';

function legislation_excel_rows(string $path, string $extension): array
{
    $python = getenv('SP_RECORDS_PYTHON') ?: '';
    if ($python === '') {
        $bundled = (getenv('USERPROFILE') ?: '') . '/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe';
        $python = is_file($bundled) ? $bundled : (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
    }
    $process = proc_open([$python, dirname(__DIR__) . '/scripts/read_legislation_excel.py', $path, $extension],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Unable to start the Excel reader.'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process);
    $result = json_decode($output, true);
    if ($code !== 0 || !isset($result['rows'])) {
        error_log('Excel import: ' . $errors);
        throw new InvalidArgumentException($result['error'] ?? 'Unable to read this workbook. Upload an .xlsx or UTF-8 CSV file.');
    }
    return $result['rows'];
}

function legislation_import_validate(array $rows, array $term): array
{
    $aliases = ['type' => 'kind', 'document_type' => 'kind', 'document_number' => 'number', 'ordinance_resolution_number' => 'number',
        'title_subject' => 'title', 'subject' => 'title', 'date_approved' => 'approved_date', 'approval_date' => 'approved_date',
        'date_approved_mm_dd_yyyy' => 'approved_date', 'approved_date_mm_dd_yyyy' => 'approved_date',
        'authors' => 'author', 'co_authors' => 'co_author'];
    $headers = [];
    foreach (array_shift($rows) ?? [] as $index => $label) {
        $key = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $label))), '_');
        $key = $aliases[$key] ?? $key;
        if ($key === '') { continue; }
        if (isset($headers[$key])) { throw new InvalidArgumentException('Duplicate column: ' . $label); }
        $headers[$key] = $index;
    }
    foreach (['kind', 'number', 'title', 'approved_date'] as $required) {
        if (!isset($headers[$required])) { throw new InvalidArgumentException('Missing column: ' . $required . '. Use the downloadable template.'); }
    }
    $entries = []; $errors = []; $seen = [];
    $existing = db()->prepare('SELECT number,kind FROM legislation_drafts WHERE term_id=? UNION SELECT number,kind FROM legislation_publications WHERE term_id=?');
    $existing->execute([$term['id'], $term['id']]);
    foreach ($existing->fetchAll() as $entry) { $seen[$entry['kind'] . ':' . mb_strtolower($entry['number'])] = true; }
    $categories = ['ordinance' => legislation_categories('ordinance'), 'resolution' => legislation_categories('resolution')];
    $names = db()->prepare("SELECT name FROM city_officials WHERE term_id=? AND position='City Councilor'");
    $names->execute([$term['id']]);
    $allowedNames = array_merge(['N/A'], $names->fetchAll(PDO::FETCH_COLUMN));
    foreach ($rows as $offset => $cells) {
        if (!array_filter($cells, fn ($cell) => trim((string) $cell) !== '')) { continue; }
        $rowNumber = $offset + 2;
        try {
            $values = [];
            foreach (['kind', 'number', 'title', 'approved_date', 'keywords', 'category', 'author', 'co_author', 'folder_code'] as $field) {
                $values[$field] = isset($headers[$field]) ? trim((string) ($cells[$headers[$field]] ?? '')) : '';
            }
            $values['kind'] = strtolower($values['kind']);
            if (!in_array($values['kind'], ['ordinance', 'resolution'], true)) { throw new InvalidArgumentException('Type must be Ordinance or Resolution.'); }
            foreach (['number' => 255, 'title' => 20000, 'keywords' => 1000, 'category' => 255, 'author' => 1000, 'co_author' => 1000, 'folder_code' => 255] as $field => $limit) {
                if (strlen($values[$field]) > $limit || (in_array($field, ['number', 'title'], true) && $values[$field] === '')) { throw new InvalidArgumentException('Invalid or oversized ' . $field . '.'); }
            }
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/D', $values['approved_date'], $dateParts)) {
                if (!checkdate((int) $dateParts[1], (int) $dateParts[2], (int) $dateParts[3])) {
                    throw new InvalidArgumentException('Enter a valid approval date in MM/DD/YYYY format.');
                }
                $values['approved_date'] = sprintf('%04d-%02d-%02d', (int) $dateParts[3], (int) $dateParts[1], (int) $dateParts[2]);
            } elseif (is_numeric($values['approved_date'])) {
                $serial = (float) $values['approved_date'];
                if ($serial < 61 || $serial > 2958465) { throw new InvalidArgumentException('Invalid Excel approval date.'); }
                $values['approved_date'] = (new DateTimeImmutable('1899-12-30'))->modify('+' . (int) $serial . ' days')->format('Y-m-d');
            }
            if (!term_contains_date($term, $values['approved_date'])) {
                throw new InvalidArgumentException('Approval date must be MM/DD/YYYY or an Excel date within the selected term.');
            }
            if ($values['category'] !== '' && !in_array($values['category'], $categories[$values['kind']], true)) { throw new InvalidArgumentException('Category must match a saved category for this type.'); }
            foreach (['author', 'co_author'] as $field) {
                $selection = array_values(array_filter(array_map('trim', explode(';', $values[$field]))));
                foreach ($selection as $name) { if (!in_array($name, $allowedNames, true)) { throw new InvalidArgumentException('Authors must match councilors in the selected term, separated by semicolons, or N/A.'); } }
                if (in_array('N/A', $selection, true) && count($selection) > 1) { throw new InvalidArgumentException('Use N/A alone.'); }
                $values[$field] = implode('; ', array_unique($selection));
            }
            $key = $values['kind'] . ':' . mb_strtolower($values['number']);
            if (isset($seen[$key])) { throw new InvalidArgumentException('This type and number already exists for the term or appears twice in this file.'); }
            $seen[$key] = true;
            $values['keywords'] = implode(', ', legislation_keywords($values['keywords']));
            $entries[] = $values;
        } catch (InvalidArgumentException $error) { $errors[] = 'Row ' . $rowNumber . ': ' . $error->getMessage(); }
    }
    if (!$entries && !$errors) { $errors[] = 'The spreadsheet contains no entries.'; }
    return [$entries, $errors];
}
