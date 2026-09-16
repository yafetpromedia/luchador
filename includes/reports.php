<?php

declare(strict_types=1);

function report_column_catalog(): array
{
    return [
        'include_code' => ['label' => 'Student ID', 'default' => true],
        'include_section' => ['label' => 'Section', 'default' => true],
        'include_phone' => ['label' => 'Student phone', 'default' => true],
        'include_family' => ['label' => 'Parents', 'default' => false],
        'include_size' => ['label' => 'Uniform size', 'default' => true],
        'include_status' => ['label' => 'Uniform status', 'default' => true],
        'include_payment' => ['label' => 'Payment', 'default' => false],
    ];
}

function report_bool(array $src, string $key, bool $default): bool
{
    if (!array_key_exists($key, $src)) {
        return $default;
    }
    $value = $src[$key];
    if (is_array($value)) {
        $value = end($value);
    }
    return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
}

function report_filters_from(array $src): array
{
    $status = trim((string) ($src['status'] ?? ''));
    $allowed = ['pending', 'ordered', 'ready', 'collected', 'missing'];
    $section = normalize_class_section((string) ($src['section'] ?? ''));
    if ($section !== '' && !is_class_section($section)) {
        $section = '';
    }
    return [
        'search' => trim((string) ($src['search'] ?? '')),
        'size' => trim((string) ($src['size'] ?? '')),
        'section' => $section,
        'status' => in_array($status, $allowed, true) ? $status : '',
        'sort' => 'name',
    ];
}

function report_columns_from(array $src): array
{
    $cols = [];
    foreach (report_column_catalog() as $key => $meta) {
        $cols[$key] = report_bool($src, $key, (bool) $meta['default']);
    }
    return $cols;
}

function report_query(array $filters, array $columns, array $override = []): string
{
    $merged = array_merge($filters, $columns, $override);
    unset($merged['sort']);
    $out = [];
    foreach ($merged as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        if (is_bool($value)) {
            $out[$key] = $value ? '1' : '0';
            continue;
        }
        $out[$key] = $value;
    }
    return http_build_query($out);
}

function report_family_line(array $student, string $who): string
{
    $name = trim((string) ($student[$who . '_name'] ?? ''));
    $phone = trim((string) ($student[$who . '_phone'] ?? ''));
    $line = trim($name . ($name !== '' && $phone !== '' ? ' · ' : '') . $phone);
    return $line;
}

function report_headers(array $columns): array
{
    $headers = ['#', 'Student'];
    if (!empty($columns['include_code'])) {
        $headers[] = 'Student ID';
    }
    if (!empty($columns['include_section'])) {
        $headers[] = 'Section';
    }
    if (!empty($columns['include_phone'])) {
        $headers[] = 'Student phone';
    }
    if (!empty($columns['include_family'])) {
        $headers[] = 'Mother';
        $headers[] = 'Father';
    }
    if (!empty($columns['include_size'])) {
        $headers[] = 'Uniform';
    }
    if (!empty($columns['include_status'])) {
        $headers[] = 'Status';
    }
    if (!empty($columns['include_payment'])) {
        $headers[] = 'Payment';
    }
    return $headers;
}

function report_row(array $student, array $columns, int $index): array
{
    $row = [$index, (string) ($student['student_name'] ?? '')];
    if (!empty($columns['include_code'])) {
        $row[] = (string) ($student['student_code'] ?? '');
    }
    if (!empty($columns['include_section'])) {
        $row[] = class_section_label((string) ($student['section'] ?? ''));
    }
    if (!empty($columns['include_phone'])) {
        $row[] = (string) ($student['phone_number'] ?? '');
    }
    if (!empty($columns['include_family'])) {
        $row[] = report_family_line($student, 'mother');
        $row[] = report_family_line($student, 'father');
    }
    if (!empty($columns['include_size'])) {
        $row[] = (string) ($student['size'] ?? '');
    }
    if (!empty($columns['include_status'])) {
        $row[] = status_label((string) ($student['status'] ?? 'pending'));
    }
    if (!empty($columns['include_payment'])) {
        $row[] = status_label((string) ($student['payment_status'] ?? 'unpaid'));
    }
    return $row;
}

function report_filter_labels(array $filters): array
{
    $labels = [];
    if ($filters['search'] !== '') {
        $labels[] = 'Search “' . $filters['search'] . '”';
    }
    if ($filters['size'] !== '') {
        $labels[] = 'Size ' . $filters['size'];
    }
    if (($filters['section'] ?? '') !== '') {
        $labels[] = class_section_label((string) $filters['section']);
    }
    if ($filters['status'] !== '') {
        $labels[] = $filters['status'] === 'missing' ? 'No size' : status_label($filters['status']);
    }
    return $labels;
}
