<?php
declare(strict_types=1);

function term_period_input(array $term, string $side): string
{
    if (empty($term[$side . '_month'])) { return ''; }
    return sprintf('%04d-%02d', (int) $term[$side . '_year'], (int) $term[$side . '_month']);
}

function term_period_label(array $term): string
{
    $labels = [];
    foreach (['start', 'end'] as $side) {
        $period = term_period_input($term, $side);
        $labels[] = $period !== '' ? (new DateTimeImmutable($period . '-01'))->format('F Y') : $term[$side . '_year'] . ' (month not set)';
    }
    return implode(' – ', $labels);
}

function term_option_label(array $term): string
{
    return $term['name'] . ' — ' . term_period_label($term);
}

function term_contains_date(array $term, string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) { return false; }
    // Keep legacy year-only terms inclusive until their months are specified.
    $start = sprintf('%04d-%02d', (int) $term['start_year'], (int) ($term['start_month'] ?? 1));
    $end = sprintf('%04d-%02d', (int) $term['end_year'], (int) ($term['end_month'] ?? 12));
    return $date->format('Y-m') >= $start && $date->format('Y-m') <= $end;
}

function term_parse_period(string $value): array
{
    if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', $value, $match) || (int) $match[1] < 1901 || (int) $match[1] > 2155) {
        throw new InvalidArgumentException('Choose a valid month and year between 1901 and 2155.');
    }
    return [(int) $match[1], (int) $match[2]];
}
