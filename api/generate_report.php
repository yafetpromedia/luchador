<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/queries.php';
require_once dirname(__DIR__) . '/includes/reports.php';

require_staff();
require_permission('reports.export');

$filters = report_filters_from($_GET);
$columns = report_columns_from($_GET);
$format = strtolower(trim((string) ($_GET['format'] ?? 'doc')));
if (!in_array($format, ['doc', 'csv', 'print'], true)) {
    $format = 'doc';
}

$students = fetch_students($filters);
$stamp = date('Y-m-d');
$fileBase = 'luchadore-students-' . $stamp;
$headers = report_headers($columns);
$filterNotes = report_filter_labels($filters);

log_audit('report.generate', 'report', null, strtoupper($format) . ', ' . count($students) . ' rows');

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileBase . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($students as $index => $student) {
        fputcsv($out, report_row($student, $columns, $index + 1));
    }
    fclose($out);
    exit;
}

$title = class_name() . ' · Grade ' . class_grade();
$generated = date('F j, Y');
$rowsHtml = '';
foreach ($students as $index => $student) {
    $rowsHtml .= '<tr>';
    foreach (report_row($student, $columns, $index + 1) as $cell) {
        $rowsHtml .= '<td>' . e($cell !== '' ? (string) $cell : '—') . '</td>';
    }
    $rowsHtml .= '</tr>';
}

$th = '';
foreach ($headers as $header) {
    $th .= '<th>' . e($header) . '</th>';
}

$filterLine = $filterNotes ? '<p>Filtered by ' . e(implode(' · ', $filterNotes)) . '</p>' : '';

$html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . e($title) . ' report</title>
<style>
@page { margin: 18mm 14mm; }
body{font-family:Inter,Segoe UI,Arial,sans-serif;margin:0;color:#16151a;background:#fff}
.sheet{max-width:960px;margin:0 auto;padding:28px 8px 40px}
.kicker{margin:0;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#73706a}
h1{margin:.2rem 0 .35rem;font-size:26px;letter-spacing:-.04em}
.meta{margin:0 0 1.25rem;color:#5f5c56;font-size:13px;line-height:1.55}
table{width:100%;border-collapse:collapse}
th{text-align:left;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#73706a;border-bottom:1px solid #16151a;padding:8px 8px 8px 0}
td{border-bottom:1px solid #eceae6;padding:9px 8px 9px 0;font-size:13px;vertical-align:top}
td:first-child,th:first-child{width:2.2rem;color:#73706a}
.foot{margin-top:14px;display:flex;justify-content:space-between;color:#73706a;font-size:12px}
.no-print{margin-top:20px}
.btn{display:inline-flex;align-items:center;gap:.4rem;background:#16151a;color:#fff;border:0;border-radius:8px;padding:10px 16px;font:inherit;font-weight:600;cursor:pointer}
@media print { .no-print{display:none} body{background:#fff} .sheet{padding:0;max-width:none} }
</style></head><body><div class="sheet">';
$html .= '<p class="kicker">' . e(school_name()) . '</p>';
$html .= '<h1>' . e($title) . '</h1>';
$html .= '<div class="meta"><p>Student roster · ' . e($generated) . '</p>' . $filterLine . '</div>';
$html .= '<table><thead><tr>' . $th . '</tr></thead><tbody>';
$html .= $rowsHtml !== '' ? $rowsHtml : '<tr><td colspan="' . count($headers) . '">No students match these filters.</td></tr>';
$html .= '</tbody></table>';
$html .= '<div class="foot"><span>' . count($students) . ' ' . (count($students) === 1 ? 'student' : 'students') . '</span><span>' . e(class_name()) . '</span></div>';

if ($format === 'print') {
    $html .= '<p class="no-print"><button class="btn" type="button" onclick="window.print()">Print</button></p>';
    $html .= '</div></body></html>';
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

$html .= '</div></body></html>';
header('Content-Type: application/msword');
header('Content-Disposition: attachment; filename="' . $fileBase . '.doc"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
echo $html;
exit;
