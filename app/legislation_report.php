<?php
declare(strict_types=1);
require_once __DIR__ . '/report_pdf.php';

function legislation_report_pdf(array $entries, string $termName, array $filters): string
{
    foreach ($entries as &$entry) {
        if ($entry['record_id'] === null) {
            $entry['committees'] = $entry['committee_names'] ? explode('; ', $entry['committee_names']) : [];
            continue;
        }
        $stmt = db()->prepare('SELECT committee_id FROM records WHERE id=?');
        $stmt->execute([$entry['record_id']]);
        $fallback = (int) $stmt->fetchColumn();
        $entry['committees'] = array_column(record_committee_rows((int) $entry['record_id'], $fallback ?: null), 'committee_name');
    }
    unset($entry);

    // Landscape A4 in points. Embedded Unicode fonts keep councilor names and the peso sign readable.
    $mm = 72 / 25.4;
    $pdf = new ReportPdf(297 * $mm, 210 * $mm, 'Filtered Ordinances and Resolutions', 'Sangguniang Panlungsod of Cagayan de Oro');
    $windowsFonts = (getenv('WINDIR') ?: 'C:/Windows') . '/Fonts/';
    $fonts = null;
    foreach ([[$windowsFonts . 'times.ttf', $windowsFonts . 'timesbd.ttf'],
        ['/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf'],
        ['/usr/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf', '/usr/share/fonts/truetype/liberation/LiberationSerif-Bold.ttf']] as $pair) {
        if (is_file($pair[0]) && is_file($pair[1])) { $fonts = $pair; break; }
    }
    if (!$fonts) { throw new RuntimeException('Legislation PDF: no report font found (Times New Roman, DejaVu Serif or Liberation Serif).'); }
    $pdf->addFont('regular', $fonts[0]);
    $pdf->addFont('bold', $fonts[1]);

    $left = 12 * $mm + 6;
    $top = 10 * $mm + 6;
    $bottom = (210 - 13) * $mm - 6;
    $center = 297 * $mm / 2;
    $widths = array_map(fn ($width) => $width * $mm, [26, 84, 25, 40, 53, 45]);
    $y = $top;
    $value = fn ($value): string => $value === null || $value === '' ? 'N/A' : (string) $value;
    $newPage = function () use ($pdf, &$y, $top, $mm): void {
        $pdf->addPage();
        $y = $top;
        $label = 'Page ' . $pdf->pageCount();
        $pdf->text($label, 'regular', 8, 285 * $mm - $pdf->textWidth($label, 'regular', 8), (210 - 7) * $mm);
    };
    $centered = function (string $text, string $font, float $size, float $leading) use ($pdf, &$y, $center, $left): void {
        foreach ($pdf->wrap($text, $font, $size, 2 * ($center - $left)) as $line) {
            $pdf->text($line, $font, $size, $center - $pdf->textWidth($line, $font, $size) / 2, $y + $pdf->ascent($font, $size));
            $y += $leading;
        }
    };
    // Table cells are lists of wrapped lines; rows have 5pt top, 7pt bottom, 3pt left and 6pt right padding.
    $cells = fn (array $values, string $font, array $widths): array => array_map(fn ($text, $width) => $pdf->wrap($value($text), $font, 9, $width - 9), $values, $widths);
    $drawRow = function (array $row, string $font) use ($pdf, &$y, $left, $widths): void {
        $x = $left;
        foreach ($row as $index => $lines) {
            foreach ($lines as $number => $line) { $pdf->text($line, $font, 9, $x + 3, $y + 5 + 11 * $number + $pdf->ascent($font, 9)); }
            $x += $widths[$index];
        }
        $y += 12 + 11 * max(array_map('count', $row));
    };
    $header = $cells(["ORD./RES.\nNO.", 'TITLE', "DATE\nAPPROVED", 'AUTHOR(S)', 'COMMITTEE(S)', 'STATUS'], 'bold', $widths);
    $drawHeader = function () use ($pdf, &$y, $left, $widths, $header, $drawRow): void {
        $drawRow($header, 'bold');
        $pdf->line($left, $y, $left + array_sum($widths), $y, 1.8);
    };

    $newPage();
    $pdf->jpeg(dirname(__DIR__) . '/public/assets/splogo.jpg', $center - 9.5 * $mm, $y, 19 * $mm, 19 * $mm);
    $y += 21 * $mm;
    $centered('Sangguniang Panlungsod of Cagayan de Oro', 'bold', 17, 21);
    $centered('[ ' . $value($termName) . ' ]', 'bold', 12, 16);
    $centered('List of Ordinances and Resolutions', 'bold', 12, 16);
    $summary = [];
    foreach ($filters as $label => $filter) { if ((string) $filter !== '') { $summary[] = $label . ': ' . $filter; } }
    if ($summary) {
        $y += 2 * $mm;
        $centered(implode('; ', $summary), 'regular', 8, 10);
    }
    $y += 2 * $mm;
    $centered(count($entries) . ' matching result(s)', 'regular', 8, 10);
    $y += 9 * $mm;
    $drawHeader();

    $rows = [];
    foreach ($entries as $entry) {
        $authors = array_unique(array_filter([$entry['author'], $entry['co_author']], fn ($name) => $name !== null && $name !== '' && $name !== 'N/A'));
        $rows[] = $cells([ucfirst(strtolower((string) $entry['kind'])) . "\n" . $entry['number'], $entry['title'], $entry['approved_date'],
            implode('; ', $authors), implode('; ', $entry['committees']), 'Approved in the Plenary'], 'regular', $widths);
    }
    if (!$entries) { $rows[] = $cells(['No legislation matches the selected filters.'], 'regular', [array_sum($widths)]); }
    $pageCapacity = $bottom - $top - (12 + 11 * max(array_map('count', $header)));
    foreach ($rows as $row) {
        $height = 12 + 11 * max(array_map('count', $row));
        // Keep normal entries together; split only entries taller than a full page.
        if ($y + $height > $bottom + 0.01 && $height <= $pageCapacity) { $newPage(); $drawHeader(); }
        while (true) {
            $fit = (int) floor(($bottom - $y - 12) / 11 + 0.001);
            if ($fit < 1) { $newPage(); $drawHeader(); continue; }
            $drawRow(array_map(fn ($lines) => array_slice($lines, 0, $fit), $row), 'regular');
            $row = array_map(fn ($lines) => array_slice($lines, $fit), $row);
            if (!array_filter($row)) { break; }
            $newPage();
            $drawHeader();
        }
    }
    return $pdf->output();
}
