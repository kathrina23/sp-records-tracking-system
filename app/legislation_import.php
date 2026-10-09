<?php
declare(strict_types=1);
require_once __DIR__ . '/elibrary.php';

function legislation_excel_rows(string $path, string $extension): array
{
    // Read in PHP so imports do not depend on a Python runtime being installed on the web server.
    $unreadable = 'Unable to read this workbook. Upload an .xlsx or UTF-8 CSV file.';
    if ($extension === 'csv') {
        $data = file_get_contents($path);
        if ($data === false) { throw new InvalidArgumentException($unreadable); }
        if (!mb_check_encoding($data, 'UTF-8')) { throw new InvalidArgumentException('Save the file as "CSV UTF-8 (Comma delimited)" in Excel, then upload it again.'); }
        $source = fopen('php://temp', 'w+');
        fwrite($source, str_starts_with($data, "\xEF\xBB\xBF") ? substr($data, 3) : $data);
        rewind($source);
        $rows = [];
        while (($row = fgetcsv($source, null, ',', '"', '')) !== false) {
            $rows[] = $row === [null] ? [] : $row;
            if (count($rows) > 1001) { fclose($source); throw new InvalidArgumentException('Limit each upload to 1,000 entries.'); }
        }
        fclose($source);
        return $rows;
    }
    if (!class_exists('ZipArchive') || !class_exists('XMLReader')) { throw new RuntimeException('The PHP zip and xmlreader extensions are required to read .xlsx files.'); }
    $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $archive = new ZipArchive();
    if ($archive->open($path) !== true) { throw new InvalidArgumentException($unreadable); }
    $previous = libxml_use_internal_errors(true);
    try {
        $expanded = 0;
        for ($index = 0; $index < $archive->numFiles; $index++) { $expanded += (int) ($archive->statIndex($index)['size'] ?? 0); }
        if ($expanded > 30 * 1024 * 1024) { throw new InvalidArgumentException('The expanded workbook exceeds 30 MB.'); }
        $part = function (string $name) use ($archive, $unreadable): string {
            $data = $archive->getFromName($name);
            if ($data === false) { throw new InvalidArgumentException($unreadable); }
            if (stripos($data, '<!DOCTYPE') !== false || stripos($data, '<!ENTITY') !== false) { throw new InvalidArgumentException('Unsupported XML declarations in workbook.'); }
            return $data;
        };
        $document = function (string $name) use ($part, $unreadable): DOMDocument {
            $xml = new DOMDocument();
            if (!$xml->loadXML($part($name), LIBXML_NONET)) { throw new InvalidArgumentException($unreadable); }
            return $xml;
        };
        // Stream the large parts so a big worksheet cannot exhaust PHP memory.
        $elements = function (string $name, string $tag) use ($part, $main, $unreadable): Generator {
            $reader = new XMLReader();
            libxml_clear_errors();
            if (!$reader->XML($part($name), null, LIBXML_NONET)) { throw new InvalidArgumentException($unreadable); }
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === $tag && $reader->namespaceURI === $main) {
                    $node = $reader->expand();
                    if (!$node instanceof DOMElement) { break; }
                    yield $node;
                }
            }
            $reader->close();
            if (libxml_get_last_error()) { throw new InvalidArgumentException($unreadable); }
        };
        $children = fn (DOMElement $parent, string $tag): array => array_values(array_filter(iterator_to_array($parent->childNodes),
            fn ($node) => $node instanceof DOMElement && $node->localName === $tag && $node->namespaceURI === $main));

        $workbook = new DOMXPath($document('xl/workbook.xml'));
        $workbook->registerNamespace('s', $main);
        $properties = $workbook->query('/s:workbook/s:workbookPr')->item(0);
        if ($properties instanceof DOMElement && in_array($properties->getAttribute('date1904'), ['1', 'true'], true)) { throw new InvalidArgumentException('Use the standard Excel 1900 date system or ISO date text.'); }
        $sheet = null;
        foreach ($workbook->query('/s:workbook/s:sheets/s:sheet') as $item) {
            if ($item instanceof DOMElement && ($item->getAttribute('state') ?: 'visible') === 'visible') { $sheet = $item; break; }
        }
        if (!$sheet) { throw new InvalidArgumentException('No visible worksheet found.'); }
        $relationId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        $target = null;
        foreach ($document('xl/_rels/workbook.xml.rels')->documentElement->childNodes as $relation) {
            if (!$relation instanceof DOMElement || $relation->getAttribute('Id') !== $relationId) { continue; }
            $target = $relation->getAttribute('Target');
            if ($relation->getAttribute('TargetMode') === 'External' || str_contains($target, '..')) { throw new InvalidArgumentException('Unsupported worksheet reference.'); }
            break;
        }
        if ($target === null) { throw new InvalidArgumentException($unreadable); }
        $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
        $shared = [];
        if ($archive->locateName('xl/sharedStrings.xml') !== false) {
            foreach ($elements('xl/sharedStrings.xml', 'si') as $item) { $shared[] = $item->textContent; }
        }
        $rows = [];
        foreach ($elements($target, 'row') as $row) {
            $values = array_fill(0, 32, '');
            foreach ($children($row, 'c') as $cell) {
                $address = $cell->getAttribute('r');
                $column = 0;
                for ($i = 0; $i < strlen($address) && ctype_alpha($address[$i]); $i++) {
                    $column = $column * 26 + ord(strtoupper($address[$i])) - 64;
                    if ($column > 32) { break; }
                }
                if ($column < 1 || $column > 32) { throw new InvalidArgumentException('Use at most 32 columns in the worksheet.'); }
                if ($children($cell, 'f')) { throw new InvalidArgumentException('Cell ' . $address . ' contains a formula. Paste values before importing.'); }
                $value = ($children($cell, 'v')[0] ?? null)?->textContent ?? '';
                $type = $cell->getAttribute('t');
                if ($type === 's') {
                    if (!ctype_digit($value) || !isset($shared[(int) $value])) { throw new InvalidArgumentException($unreadable); }
                    $value = $shared[(int) $value];
                } elseif ($type === 'inlineStr') {
                    $value = ($children($cell, 'is')[0] ?? null)?->textContent ?? '';
                } elseif ($type === 'e') {
                    throw new InvalidArgumentException('Cell ' . $address . ' contains an Excel error.');
                }
                $values[$column - 1] = $value;
            }
            // Retain gaps so validation reports the actual worksheet row.
            $rowNumber = (int) ($row->getAttribute('r') ?: count($rows) + 1);
            if ($rowNumber > 1001) { throw new InvalidArgumentException('Limit each upload to 1,000 entries starting at row 2.'); }
            while (count($rows) < $rowNumber - 1) { $rows[] = []; }
            $rows[] = $values;
        }
        return $rows;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $archive->close();
    }
}

function legislation_import_failure_message(Throwable $error): string
{
    $state = $error instanceof PDOException ? (string) ($error->errorInfo[0] ?? $error->getCode()) : '';
    if ($state === '23000') { return 'Unable to import entries. No entries were saved. A type and number in this file already exists for the term; check for duplicate numbers and try again.'; }
    if (in_array($state, ['42S02', '42S22'], true)) { return 'Unable to import entries. No entries were saved. The E-Library database tables are not up to date; ask the system administrator to run the E-Library database migrations.'; }
    return 'Unable to import entries. No entries were saved. Ask the system administrator to check the server error log.';
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
