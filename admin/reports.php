<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';
require_once dirname(__DIR__) . '/includes/reports.php';

admin_boot(['reports.view', 'reports.export']);

$filters = report_filters_from($_GET);
$columns = report_columns_from($_GET);
$all = fetch_students($filters);
$page = max(1, request_int('page', 1));
$perPage = 15;
$pageData = pagination(count($all), $page, $perPage);
$rows = array_slice($all, $pageData['offset'], $perPage);
$counts = dashboard_counts();
$canExport = can('reports.export');
$query = report_query($filters, $columns);
$baseQuery = $query;

$tabQuery = static function (array $filters, array $columns, array $override) : string {
    $qs = report_query($filters, $columns, $override);
    return url('admin/reports.php' . ($qs !== '' ? '?' . $qs : ''));
};

$tabs = [
    ['key' => '', 'label' => 'All', 'count' => (int) $counts['students']],
    ['key' => 'pending', 'label' => 'Pending', 'count' => (int) $counts['pending']],
    ['key' => 'ordered', 'label' => 'Ordered', 'count' => (int) $counts['ordered']],
    ['key' => 'ready', 'label' => 'Ready', 'count' => (int) $counts['ready']],
    ['key' => 'collected', 'label' => 'Collected', 'count' => (int) $counts['collected']],
];
if ((int) $counts['missing'] > 0 || $filters['status'] === 'missing') {
    $tabs[] = ['key' => 'missing', 'label' => 'No size', 'count' => (int) $counts['missing']];
}

$headers = report_headers($columns);
$exportUrl = url('api/generate_report.php');
$filterNotes = report_filter_labels($filters);

admin_header('Reports', 'reports');
admin_page_head('Preview the roster, choose columns, then download Word, CSV, or print. Filters apply to every export.');
?>

<nav class="uniform-tabs" aria-label="Uniform status">
    <?php foreach ($tabs as $tab): ?>
        <?php $active = ($filters['status'] === $tab['key']); ?>
        <a href="<?= e($tabQuery($filters, $columns, ['status' => $tab['key'], 'page' => ''])) ?>" <?= $active ? 'aria-current="page"' : '' ?>>
            <strong><?= (int) $tab['count'] ?></strong>
            <span><?= e($tab['label']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<div class="uniform-sizes" aria-label="Sizes on record">
    <?php foreach (uniform_sizes() as $size): ?>
        <?php $n = (int) ($counts['sizes'][$size] ?? 0); ?>
        <a href="<?= e($tabQuery($filters, $columns, ['size' => $filters['size'] === $size ? '' : $size, 'page' => ''])) ?>" class="<?= $filters['size'] === $size ? 'is-active' : '' ?>">
            <b><?= e($size) ?></b>
            <span><?= $n ?></span>
        </a>
    <?php endforeach; ?>
</div>

<form class="panel report-panel" method="get" id="report-form" data-report-form>
    <div class="panel-head">
        <h2>Build report</h2>
        <p class="muted"><?= count($all) ?> matching</p>
    </div>

    <div class="toolbar report-toolbar">
        <div class="form-group search-field">
            <label class="sr-only" for="search">Search students</label>
            <?= icon('search', 16) ?>
            <input id="search" name="search" value="<?= e($filters['search']) ?>" placeholder="Search name, ID, or phone">
        </div>
        <input type="hidden" name="size" value="<?= e($filters['size']) ?>">
        <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
        <div class="form-group">
            <label for="filter_section">Section</label>
            <select id="filter_section" name="section">
                <option value="">All sections</option>
                <?php foreach (class_sections() as $sectionName): ?>
                    <option value="<?= e($sectionName) ?>" <?= ($filters['section'] ?? '') === $sectionName ? 'selected' : '' ?>><?= e(class_section_label($sectionName)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-ghost" type="submit">Apply</button>
    </div>

    <p class="section-label">Columns</p>
    <div class="report-cols">
        <?php foreach (report_column_catalog() as $key => $meta): ?>
            <label class="report-col">
                <input type="hidden" name="<?= e($key) ?>" value="0">
                <input type="checkbox" name="<?= e($key) ?>" value="1" data-report-refresh <?= !empty($columns[$key]) ? 'checked' : '' ?>>
                <?= e($meta['label']) ?>
            </label>
        <?php endforeach; ?>
    </div>

    <?php if ($canExport): ?>
        <div class="report-exports">
            <button class="report-export" type="submit" name="format" value="doc" formaction="<?= e($exportUrl) ?>">
                <?= icon('file', 20) ?>
                <strong>Word</strong>
                <span>Download .doc</span>
            </button>
            <button class="report-export" type="submit" name="format" value="csv" formaction="<?= e($exportUrl) ?>">
                <?= icon('download', 20) ?>
                <strong>CSV</strong>
                <span>Open in Excel</span>
            </button>
            <button class="report-export" type="submit" name="format" value="print" formaction="<?= e($exportUrl) ?>" formtarget="_blank">
                <?= icon('printer', 20) ?>
                <strong>Print</strong>
                <span>Opens a print view</span>
            </button>
        </div>
    <?php else: ?>
        <p class="muted">You can preview this report. Exporting needs additional permission.</p>
    <?php endif; ?>
</form>

<section class="panel">
    <div class="panel-head">
        <h2>Preview</h2>
        <p class="muted">
            <?= count($all) ?> matching<?= $filterNotes ? ' · ' . e(implode(' · ', $filterNotes)) : '' ?>
            · export includes everyone who matches, not only this page
        </p>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <?php foreach ($headers as $header): ?>
                        <th><?= e($header) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= count($headers) ?>">No students match these filters.</td></tr>
            <?php else: foreach ($rows as $i => $row): ?>
                <?php $cells = report_row($row, $columns, $pageData['offset'] + $i + 1); ?>
                <tr>
                    <?php foreach ($cells as $col => $cell): ?>
                        <td>
                            <?php if ($col === 1): ?>
                                <?= admin_person_cell((string) $row['student_name'], $row['photo'] ?? null) ?>
                            <?php elseif (!empty($columns['include_status']) && $headers[$col] === 'Status'): ?>
                                <?= admin_status_badge((string) ($row['status'] ?? 'pending')) ?>
                            <?php elseif (!empty($columns['include_payment']) && $headers[$col] === 'Payment'): ?>
                                <span class="badge badge-<?= e((string) ($row['payment_status'] ?? 'unpaid')) ?>"><?= e(status_label((string) ($row['payment_status'] ?? 'unpaid'))) ?></span>
                            <?php else: ?>
                                <?= e($cell !== '' ? (string) $cell : '—') ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php render_pagination($pageData, $baseQuery); ?>
</section>

<?php admin_footer(); ?>
