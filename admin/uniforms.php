<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';
require_once dirname(__DIR__) . '/includes/students.php';

admin_boot(['uniforms.view', 'uniforms.edit']);

if (is_post()) {
    require_csrf();
    try {
        if (posted('action') === 'status') {
            require_permission('uniforms.edit');
            $id = (int) posted('id');
            $status = posted('status');
            if (in_array($status, ['pending', 'ordered', 'ready', 'collected'], true) && $id) {
                $existing = student_by_id($id);
                db()->prepare('UPDATE uniforms SET status = ? WHERE id = ?')->execute([$status, $id]);
                log_audit('uniform.status', 'student', $id, ($existing['student_name'] ?? '') . ' → ' . $status);
                flash_set('success', 'Uniform status updated.');
            }
        }
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save changes. Please try again.');
    }
    $return = http_build_query(array_filter([
        'search' => posted('return_search'),
        'size' => posted('return_size'),
        'section' => posted('return_section'),
        'status' => posted('return_status'),
        'page' => posted('return_page'),
    ], static fn ($value) => $value !== ''));
    redirect('admin/uniforms.php' . ($return !== '' ? '?' . $return : ''));
}

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'size' => trim((string) ($_GET['size'] ?? '')),
    'section' => normalize_class_section((string) ($_GET['section'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'sort' => 'name',
];
if ($filters['section'] !== '' && !is_class_section($filters['section'])) {
    $filters['section'] = '';
}
$all = fetch_students($filters);
$page = max(1, request_int('page', 1));
$perPage = 20;
$pageData = pagination(count($all), $page, $perPage);
$rows = array_slice($all, $pageData['offset'], $perPage);
$query = http_build_query(array_filter($filters, static fn ($value) => $value !== '' && $value !== 'name'));
$counts = dashboard_counts();
$missingLabel = ($filters['status'] === 'missing');
$canEdit = can('uniforms.edit');

$tabQuery = static function (array $current, array $override) : string {
    $merged = array_merge($current, $override);
    unset($merged['sort']);
    $qs = http_build_query(array_filter($merged, static fn ($value) => $value !== ''));
    return url('admin/uniforms.php' . ($qs !== '' ? '?' . $qs : ''));
};

$tabs = [
    ['key' => '', 'label' => 'All', 'count' => (int) $counts['students']],
    ['key' => 'pending', 'label' => 'Pending', 'count' => (int) $counts['pending']],
    ['key' => 'ordered', 'label' => 'Ordered', 'count' => (int) $counts['ordered']],
    ['key' => 'ready', 'label' => 'Ready', 'count' => (int) $counts['ready']],
    ['key' => 'collected', 'label' => 'Collected', 'count' => (int) $counts['collected']],
];
if ((int) $counts['missing'] > 0 || $missingLabel) {
    $tabs[] = ['key' => 'missing', 'label' => 'No size', 'count' => (int) $counts['missing']];
}

admin_header('Uniforms', 'uniforms');
admin_page_head(
    'Who has a uniform, who is waiting, and which sizes are still needed.',
    can_any(['payments.view', 'payments.manage'])
        ? ['<a class="btn btn-ghost" href="' . e(url('admin/payments.php')) . '">' . icon('wallet', 16) . ' Payments</a>']
        : []
);
?>

<nav class="uniform-tabs" aria-label="Uniform status">
    <?php foreach ($tabs as $tab): ?>
        <?php $active = ($filters['status'] === $tab['key']); ?>
        <a href="<?= e($tabQuery($filters, ['status' => $tab['key']])) ?>" <?= $active ? 'aria-current="page"' : '' ?>>
            <strong><?= (int) $tab['count'] ?></strong>
            <span><?= e($tab['label']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<div class="uniform-sizes" aria-label="Sizes on record">
    <?php foreach (uniform_sizes() as $size): ?>
        <?php $n = (int) ($counts['sizes'][$size] ?? 0); ?>
        <a href="<?= e($tabQuery($filters, ['size' => $filters['size'] === $size ? '' : $size])) ?>" class="<?= $filters['size'] === $size ? 'is-active' : '' ?>">
            <b><?= e($size) ?></b>
            <span><?= $n ?></span>
        </a>
    <?php endforeach; ?>
</div>

<form class="toolbar" method="get">
    <?php if ($filters['status'] !== ''): ?>
        <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
    <?php endif; ?>
    <div class="form-group search-field">
        <label class="sr-only" for="uniform-search">Search</label>
        <?= icon('search', 16) ?>
        <input id="uniform-search" name="search" value="<?= e($filters['search']) ?>" placeholder="Search name, ID, or phone">
    </div>
    <div class="form-group">
        <label for="uniform-size">Size</label>
        <select id="uniform-size" name="size">
            <option value="">All sizes</option>
            <?php foreach (uniform_sizes() as $size): ?>
                <option value="<?= e($size) ?>" <?= $filters['size'] === $size ? 'selected' : '' ?>><?= e($size) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="uniform-section">Section</label>
        <select id="uniform-section" name="section">
            <option value="">All sections</option>
            <?php foreach (class_sections() as $sectionName): ?>
                <option value="<?= e($sectionName) ?>" <?= $filters['section'] === $sectionName ? 'selected' : '' ?>><?= e(class_section_label($sectionName)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="btn btn-ghost" type="submit">Filter</button>
</form>

<p class="muted roster-meta"><?= count($all) ?> matching records</p>

<div class="has-cards roster uniform-board">
<div class="table-wrap">
<table>
    <thead><tr><th>Student</th><th>Size</th><th>Qty</th><th>Payment</th><th>Status</th><?php if ($canEdit): ?><th>Update</th><?php endif; ?></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="<?= $canEdit ? 6 : 5 ?>">No matching records.</td></tr>
    <?php else: foreach ($rows as $row): ?>
        <?php
        $status = $row['status'] ?? 'pending';
        $sizeMissing = trim((string) $row['size']) === '';
        $photo = student_photo_url($row);
        $initials = person_initials((string) $row['student_name']);
        $qty = (int) ($row['quantity'] ?? 1);
        ?>
        <tr>
            <td>
                <a class="student-open" href="<?= e(url('admin/students.php?view=' . (int) $row['id'])) ?>">
                    <?php if ($photo): ?>
                        <img class="stu-avatar stu-avatar-sm" src="<?= e($photo) ?>" alt="">
                    <?php else: ?>
                        <span class="stu-avatar stu-avatar-sm" aria-hidden="true"><?= e($initials) ?></span>
                    <?php endif; ?>
                    <span>
                        <strong><?= e($row['student_name']) ?></strong>
                        <span class="muted"><?= e($row['student_code'] ?: ($row['phone_number'] ?: 'No ID')) ?><?= !empty($row['section']) ? ' · ' . e(class_section_label((string) $row['section'])) : '' ?></span>
                    </span>
                </a>
            </td>
            <td><?= $sizeMissing ? '<span class="badge badge-missing">No size</span>' : '<span class="badge">' . e($row['size']) . '</span>' ?></td>
            <td><?= $qty ?></td>
            <td><span class="badge badge-<?= e($row['payment_status'] ?? 'unpaid') ?>"><?= e(status_label($row['payment_status'] ?? 'unpaid')) ?></span></td>
            <td><span class="badge badge-<?= e($status) ?>"><?= e(status_label($status)) ?></span></td>
            <?php if ($canEdit): ?>
            <td>
                <form method="post" class="row-actions uniform-update" data-autosubmit>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <input type="hidden" name="return_search" value="<?= e($filters['search']) ?>">
                    <input type="hidden" name="return_size" value="<?= e($filters['size']) ?>">
                    <input type="hidden" name="return_section" value="<?= e($filters['section']) ?>">
                    <input type="hidden" name="return_status" value="<?= e($filters['status']) ?>">
                    <input type="hidden" name="return_page" value="<?= (int) $pageData['page'] ?>">
                    <select name="status" aria-label="Uniform status for <?= e($row['student_name']) ?>">
                        <?php foreach (['pending','ordered','ready','collected'] as $st): ?>
                            <option value="<?= e($st) ?>" <?= ($status === $st) ? 'selected' : '' ?>><?= e(status_label($st)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-ghost" type="submit">Save</button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<div class="record-cards">
    <?php foreach ($rows as $row): ?>
        <?php
        $status = $row['status'] ?? 'pending';
        $sizeMissing = trim((string) $row['size']) === '';
        $photo = student_photo_url($row);
        $initials = person_initials((string) $row['student_name']);
        ?>
        <article class="record-card">
            <h3>
                <a class="student-open" href="<?= e(url('admin/students.php?view=' . (int) $row['id'])) ?>">
                    <?php if ($photo): ?>
                        <img class="stu-avatar stu-avatar-sm" src="<?= e($photo) ?>" alt="">
                    <?php else: ?>
                        <span class="stu-avatar stu-avatar-sm" aria-hidden="true"><?= e($initials) ?></span>
                    <?php endif; ?>
                    <?= e($row['student_name']) ?>
                </a>
            </h3>
            <dl class="record-meta">
                <div><dt>Size</dt><dd><?= $sizeMissing ? 'No size' : e($row['size']) ?></dd></div>
                <div><dt>Section</dt><dd><?= !empty($row['section']) ? e(class_section_label((string) $row['section'])) : 'Not set' ?></dd></div>
                <div><dt>Status</dt><dd><span class="badge badge-<?= e($status) ?>"><?= e(status_label($status)) ?></span></dd></div>
                <div><dt>Payment</dt><dd><?= e(status_label($row['payment_status'] ?? 'unpaid')) ?></dd></div>
            </dl>
            <?php if ($canEdit): ?>
            <form method="post" data-autosubmit>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="return_search" value="<?= e($filters['search']) ?>">
                <input type="hidden" name="return_size" value="<?= e($filters['size']) ?>">
                <input type="hidden" name="return_section" value="<?= e($filters['section']) ?>">
                <input type="hidden" name="return_status" value="<?= e($filters['status']) ?>">
                <input type="hidden" name="return_page" value="<?= (int) $pageData['page'] ?>">
                <div class="row-actions">
                    <select name="status" aria-label="Uniform status for <?= e($row['student_name']) ?>">
                        <?php foreach (['pending','ordered','ready','collected'] as $st): ?>
                            <option value="<?= e($st) ?>" <?= ($status === $st) ? 'selected' : '' ?>><?= e(status_label($st)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-ghost" type="submit">Save</button>
                </div>
            </form>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <div class="empty-state"><h2>No matching records</h2><p>Try another search, size, or status.</p></div>
    <?php endif; ?>
</div>
</div>
<?php render_pagination($pageData, $query); ?>

<?php admin_footer(); ?>
