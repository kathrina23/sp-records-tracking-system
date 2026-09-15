<?php
declare(strict_types=1);

function plenary_session_filter(array $query): string
{
    $value = $query['plenary_date'] ?? '';
    if (!is_string($value)) {
        throw new InvalidArgumentException('Please select a valid plenary session date.');
    }
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Please select a valid plenary session date.');
    }
    return $value;
}

function for_plenary_results_query(string $date): array
{
    $sql = "SELECT r.*, c.name committee_name
        FROM records r
        LEFT JOIN committees c ON c.id = r.committee_id
        WHERE r.document_type IN ('Committee Referrals', 'Certified Urgent')
        AND r.status IN ('For Plenary Session', 'Scheduled for Plenary')";
    $params = [];
    if ($date !== '') {
        $sql .= ' AND r.plenary_session_date = ?';
        $params[] = $date;
    }
    $sql .= " ORDER BY CASE WHEN r.status = 'For Plenary Session' THEN 0 ELSE 1 END,
        r.plenary_session_date ASC, r.updated_at DESC, r.id DESC";
    return [$sql, $params];
}

function for_plenary_results(string $date): array
{
    [$sql, $params] = for_plenary_results_query($date);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}