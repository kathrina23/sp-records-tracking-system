<?php
declare(strict_types=1);

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
    $python = getenv('SP_RECORDS_PYTHON') ?: '';
    if ($python === '') {
        $bundled = (getenv('USERPROFILE') ?: '') . '/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe';
        $python = is_file($bundled) ? $bundled : (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
    }
    $process = proc_open([$python, dirname(__DIR__) . '/scripts/render_legislation_report.py'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Unable to start PDF report generator.'); }
    $payload = json_encode(['entries' => $entries, 'term' => $termName, 'filters' => $filters], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    $offset = 0;
    while ($offset < strlen($payload)) {
        $written = fwrite($pipes[0], substr($payload, $offset));
        if ($written === false || $written === 0) { break; }
        $offset += $written;
    }
    fclose($pipes[0]);
    $pdf = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0 || !str_starts_with($pdf, '%PDF-')) {
        error_log('Legislation PDF: ' . $errors);
        throw new RuntimeException('Unable to generate the PDF report.');
    }
    return $pdf;
}
