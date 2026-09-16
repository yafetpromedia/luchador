<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';
require_once dirname(__DIR__) . '/includes/students.php';

admin_boot(['students.view', 'students.create', 'students.edit', 'students.delete', 'students.import']);

if (isset($_GET['download']) && $_GET['download'] === 'xlsx') {
    require_permission('students.import');
    send_student_import_xlsx();
}
if (isset($_GET['download']) && $_GET['download'] === 'template') {
    require_permission('students.import');
    send_student_import_csv();
}

$edit = null;
if (request_int('edit') > 0) {
    $edit = student_by_id(request_int('edit'));
}

$importPreview = $_SESSION['csv_import'] ?? [];
$showImport = isset($_GET['import']) || !empty($importPreview['valid']) || !empty($importPreview['errors']);
$showForm = $edit || isset($_GET['new']);

if (is_post()) {
    require_csrf();
    $action = posted('action');
    try {
        if ($action === 'delete') {
            require_permission('students.delete');
            $id = (int) posted('id');
            if ($id > 0) {
                $existing = student_by_id($id);
                delete_student_record($id);
                log_audit('student.delete', 'student', $id, $existing['student_name'] ?? '');
                flash_set('success', 'Student deleted successfully.');
            }
        } elseif ($action === 'delete_selected') {
            require_permission('students.delete');
            $filters = [
                'search' => posted('search'),
                'size' => posted('size'),
                'section' => posted('section'),
                'status' => posted('status'),
                'sort' => posted('sort') !== '' ? posted('sort') : 'name',
            ];
            if (posted('delete_matching') === '1') {
                $ids = array_map(static fn (array $row): int => (int) $row['id'], fetch_students($filters));
            } else {
                $ids = posted_student_ids();
            }
            if (!$ids) {
                throw new InvalidArgumentException('Select at least one student to delete.');
            }
            $deleted = delete_students_by_ids($ids);
            log_audit('student.delete', 'student', null, $deleted . ' student' . ($deleted === 1 ? '' : 's'));
            flash_set('success', $deleted . ' student' . ($deleted === 1 ? '' : 's') . ' deleted. Linked student logins were disabled.');
            $return = http_build_query(array_filter([
                'search' => $filters['search'],
                'size' => $filters['size'],
                'section' => $filters['section'],
                'status' => $filters['status'],
            ], static fn ($value) => $value !== ''));
            redirect('admin/students.php' . ($return !== '' ? '?' . $return : ''));
        } elseif ($action === 'profile_approve') {
            require_permission('students.edit');
            $saved = approve_profile_request((int) posted('request_id'));
            log_audit('profile.approve', 'student', (int) $saved['id'], $saved['student_name'] ?? '');
            notify_student_record((int) $saved['id'], [
                'type' => 'profile.approved',
                'title' => 'Profile update approved',
                'body' => 'Your class record now shows the new details.',
                'icon' => 'users',
                'url' => 'student/profile.php',
                'target_type' => 'student',
                'target_id' => (int) $saved['id'],
            ]);
            flash_set('success', 'Profile update approved. The student record now shows the new details.');
        } elseif ($action === 'profile_reject') {
            require_permission('students.edit');
            $requestId = (int) posted('request_id');
            $lookup = db()->prepare(
                'SELECT r.student_id, s.student_name FROM profile_requests r INNER JOIN uniforms s ON s.id = r.student_id WHERE r.id = ? LIMIT 1'
            );
            $lookup->execute([$requestId]);
            $row = $lookup->fetch() ?: [];
            reject_profile_request($requestId, posted('note'));
            log_audit('profile.reject', 'student', (int) ($row['student_id'] ?? 0), $row['student_name'] ?? '');
            notify_student_record((int) ($row['student_id'] ?? 0), [
                'type' => 'profile.rejected',
                'title' => 'Profile update not approved',
                'body' => posted('note') !== '' ? posted('note') : 'Your live profile was not changed.',
                'icon' => 'users',
                'url' => 'student/profile.php',
                'target_type' => 'student',
                'target_id' => (int) ($row['student_id'] ?? 0),
            ]);
            flash_set('success', 'Profile update rejected. The student record was not changed.');
        } elseif ($action === 'import_preview') {
            require_permission('students.import');
            $file = $_FILES['csv'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Choose an Excel or CSV file to import.');
            }
            if (($file['size'] ?? 0) > 2097152) {
                throw new InvalidArgumentException('Import files must be 2 MB or smaller.');
            }
            $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'xlsx'], true)) {
                throw new InvalidArgumentException('Upload an Excel .xlsx file or a .csv file.');
            }
            $parsed = parse_student_import_file($file['tmp_name'], (string) ($file['name'] ?? 'import.csv'));
            $_SESSION['csv_import'] = [
                'valid' => $parsed['valid'],
                'errors' => $parsed['errors'],
                'token' => bin2hex(random_bytes(8)),
            ];
            flash_set('success', 'Preview ready. Review the rows below before importing.');
        } elseif ($action === 'import_confirm') {
            require_permission('students.import');
            $preview = $_SESSION['csv_import'] ?? null;
            if (!$preview || posted('import_token') !== ($preview['token'] ?? '')) {
                throw new InvalidArgumentException('Import preview expired. Upload the CSV again.');
            }
            $imported = import_students($preview['valid'] ?? []);
            $errors = $preview['errors'] ?? [];
            $duplicates = 0;
            $invalid = 0;
            foreach ($errors as $err) {
                if (($err['type'] ?? '') === 'duplicate') {
                    $duplicates++;
                } else {
                    $invalid++;
                }
            }
            unset($_SESSION['csv_import']);
            log_audit('student.import', 'student', null, $imported . ' imported, ' . $duplicates . ' duplicates, ' . $invalid . ' invalid');
            $parts = [$imported . ' student' . ($imported === 1 ? '' : 's') . ' imported successfully.'];
            if ($duplicates) {
                $parts[] = $duplicates . ' duplicate' . ($duplicates === 1 ? '' : 's') . ' skipped.';
            }
            if ($invalid) {
                $parts[] = $invalid . ' invalid row' . ($invalid === 1 ? '' : 's') . ' skipped.';
            }
            flash_set('success', implode(' ', $parts));
        } elseif ($action === 'import_cancel') {
            unset($_SESSION['csv_import']);
            flash_set('success', 'Import cancelled. No records were added.');
        } else {
            $id = posted('id') !== '' ? (int) posted('id') : null;
            require_permission($id ? 'students.edit' : 'students.create');
            $saved = save_student($_POST, $id, $_FILES);
            log_audit($id ? 'student.update' : 'student.create', 'student', (int) $saved['id'], $saved['student_name']);
            flash_set('success', $id ? 'Student updated successfully.' : 'Student added successfully.');
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
        if (!in_array($action, ['delete', 'delete_selected', 'import_preview', 'import_confirm', 'import_cancel', 'profile_approve', 'profile_reject'], true)) {
            $keepId = posted('id') !== '' ? (int) posted('id') : 0;
            redirect($keepId > 0 ? 'admin/students.php?edit=' . $keepId : 'admin/students.php?new=1');
        }
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save changes. Please try again.');
    }
    redirect('admin/students.php');
}

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'size' => trim((string) ($_GET['size'] ?? '')),
    'section' => normalize_class_section((string) ($_GET['section'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'sort' => trim((string) ($_GET['sort'] ?? 'name')),
];
if ($filters['section'] !== '' && !is_class_section($filters['section'])) {
    $filters['section'] = '';
}
$all = fetch_students($filters);
$page = max(1, request_int('page', 1));
$perPage = 15;
$pageData = pagination(count($all), $page, $perPage);
$rows = array_slice($all, $pageData['offset'], $perPage);
$query = http_build_query(array_filter($filters));
$totalAll = (int) db()->query('SELECT COUNT(*) FROM uniforms')->fetchColumn();
$profileRequests = pending_profile_requests();
$pendingIds = [];
foreach ($profileRequests as $req) {
    $pendingIds[(int) $req['student_id']] = true;
}
if ($showImport && !can('students.import')) {
    $showImport = false;
}
if ($edit && !can('students.edit')) {
    $edit = null;
    $showForm = false;
}
if ($showForm && !$edit && !can('students.create')) {
    $showForm = false;
}
$previewErrors = $importPreview['errors'] ?? [];
$previewDup = 0;
$previewInvalid = 0;
foreach ($previewErrors as $err) {
    if (($err['type'] ?? '') === 'duplicate') {
        $previewDup++;
    } else {
        $previewInvalid++;
    }
}

admin_header($edit ? 'Edit student' : 'Students', 'students');
$studentActions = [];
if (can('students.import')) {
    $studentActions[] = '<a class="btn btn-ghost" href="' . e(url('admin/students.php?download=xlsx')) . '">' . icon('download', 16) . ' Excel template</a>';
    $studentActions[] = '<a class="btn btn-ghost" href="' . e(url('admin/students.php?download=template')) . '">CSV</a>';
    $studentActions[] = '<a class="btn btn-ghost" href="' . e(url('admin/students.php?import=1')) . '">' . icon('upload', 16) . ' Import</a>';
}
if (can('students.create')) {
    $studentActions[] = '<a class="btn" href="' . e(url('admin/students.php?new=1')) . '" data-student-form="new">' . icon('plus', 16) . ' Add student</a>';
}
admin_page_head('Manage the Grade 12 class roster.', $studentActions);
?>

<?php if ($profileRequests): ?>
<section class="panel" id="profile-requests">
    <h2>Profile changes waiting</h2>
    <p class="muted">Students sent these updates. Approve to apply them, or reject to leave the record as it is.</p>
    <div class="profile-request-list">
        <?php foreach ($profileRequests as $req): ?>
            <?php
            $newPhoto = student_photo_url($req);
            $oldPhoto = student_photo_url(['photo' => $req['current_photo'] ?? '']);
            ?>
            <article class="profile-request-card">
                <div class="profile-request-head">
                    <?php if ($newPhoto !== ''): ?>
                        <img class="stu-avatar stu-avatar-sm" src="<?= e($newPhoto) ?>" alt="">
                    <?php elseif ($oldPhoto !== '' && empty($req['remove_photo'])): ?>
                        <img class="stu-avatar stu-avatar-sm" src="<?= e($oldPhoto) ?>" alt="">
                    <?php else: ?>
                        <span class="stu-avatar stu-avatar-sm" aria-hidden="true"><?= e(person_initials((string) $req['student_name'])) ?></span>
                    <?php endif; ?>
                    <div>
                        <strong><?= e($req['student_name']) ?></strong>
                        <p class="muted"><?= !empty($req['student_code']) ? 'ID ' . e($req['student_code']) : 'Student ID not set' ?> · <?= e(format_when((string) $req['created_at'])) ?></p>
                    </div>
                </div>
                <dl class="profile-request-diff">
                    <div>
                        <dt>Student phone</dt>
                        <dd><?= e($req['current_phone'] ?: '—') ?> → <strong><?= e($req['phone_number'] ?: '—') ?></strong></dd>
                    </div>
                    <div>
                        <dt>Mother</dt>
                        <dd><?= e(trim(($req['current_mother_name'] ?? '') . ' ' . ($req['current_mother_phone'] ?? '')) ?: '—') ?> → <strong><?= e(trim(($req['mother_name'] ?? '') . ' ' . ($req['mother_phone'] ?? '')) ?: '—') ?></strong></dd>
                    </div>
                    <div>
                        <dt>Father</dt>
                        <dd><?= e(trim(($req['current_father_name'] ?? '') . ' ' . ($req['current_father_phone'] ?? '')) ?: '—') ?> → <strong><?= e(trim(($req['father_name'] ?? '') . ' ' . ($req['father_phone'] ?? '')) ?: '—') ?></strong></dd>
                    </div>
                    <?php if ($newPhoto !== ''): ?>
                        <div><dt>Photo</dt><dd>New photo attached</dd></div>
                    <?php elseif (!empty($req['remove_photo'])): ?>
                        <div><dt>Photo</dt><dd>Remove current photo</dd></div>
                    <?php endif; ?>
                </dl>
                <?php if (can('students.edit')): ?>
                <div class="form-actions">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="profile_approve">
                        <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                        <button class="btn" type="submit">Approve</button>
                    </form>
                    <form method="post" class="profile-request-reject">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="profile_reject">
                        <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                        <input name="note" placeholder="Optional reason">
                        <button class="btn btn-ghost" type="submit" data-confirm="The student record will not change." data-confirm-title="Reject this update?" data-confirm-ok="Reject">Reject</button>
                    </form>
                </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($showImport): ?>
<div class="panel">
    <h2>Import students</h2>
    <p class="muted">Download a template, fill real student rows, then upload it. Existing students are never overwritten. Matching is by Student ID, or by full name and student phone.</p>
    <div class="form-actions" style="margin-bottom:1rem">
        <a class="btn" href="<?= e(url('admin/students.php?download=xlsx')) ?>"><?= icon('download', 16) ?> Excel template</a>
        <a class="btn btn-ghost" href="<?= e(url('admin/students.php?download=template')) ?>">CSV template</a>
    </div>
    <p class="muted">Required: Full name, Uniform size (<?= e(implode(', ', uniform_sizes())) ?>), and at least one phone. Section must be one of the three Grade 12 groups if filled. Format phone columns as Text in Excel.</p>
    <form method="post" enctype="multipart/form-data" data-loading data-loading-label="Uploading…">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_preview">
        <div class="form-grid">
            <div class="form-group"><label class="req" for="csv">Excel or CSV file</label><input id="csv" type="file" name="csv" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></div>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit" data-loading-label="Uploading…">Preview import</button>
            <a class="btn btn-ghost" href="<?= e(url('admin/students.php')) ?>">Cancel</a>
        </div>
    </form>
    <?php if (!empty($importPreview['valid']) || !empty($importPreview['errors'])): ?>
        <div class="import-summary">
            <div class="import-stat"><b><?= count($importPreview['valid'] ?? []) ?></b><span>Valid rows</span></div>
            <div class="import-stat"><b><?= $previewInvalid ?></b><span>Invalid rows</span></div>
            <div class="import-stat"><b><?= $previewDup ?></b><span>Duplicates</span></div>
        </div>
        <?php if (!empty($importPreview['errors'])): ?>
            <div class="table-wrap" style="margin-top:0.8rem">
                <table>
                    <thead><tr><th>Line</th><th>Name</th><th>Issue</th></tr></thead>
                    <tbody>
                    <?php foreach ($importPreview['errors'] as $err): ?>
                        <tr><td><?= (int) $err['line'] ?></td><td><?= e($err['name']) ?></td><td><?= e($err['message']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if (!empty($importPreview['valid'])): ?>
            <div class="table-wrap" style="margin-top:0.8rem">
                <table>
                    <thead><tr><th>Name</th><th>Phone</th><th>Size</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($importPreview['valid'], 0, 25) as $row): ?>
                        <tr><td><?= e($row['student_name']) ?></td><td><?= e($row['phone_number']) ?></td><td><?= e($row['size']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($importPreview['valid']) > 25): ?>
                <p class="muted">Showing the first 25 valid rows.</p>
            <?php endif; ?>
            <form method="post" class="form-actions" style="margin-top:1rem">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="import_confirm">
                <input type="hidden" name="import_token" value="<?= e($importPreview['token']) ?>">
                <button class="btn" type="submit" data-confirm="Existing students will not be overwritten." data-confirm-title="Import <?= count($importPreview['valid']) ?> students?" data-confirm-ok="Import">Import <?= count($importPreview['valid']) ?> students</button>
            </form>
        <?php endif; ?>
        <form method="post" style="margin-top:0.6rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="import_cancel">
            <button class="btn btn-ghost" type="submit">Cancel import</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<form class="toolbar" method="get">
    <div class="form-group search-field">
        <label class="sr-only" for="search">Search students</label>
        <?= icon('search', 16) ?>
        <input id="search" name="search" value="<?= e($filters['search']) ?>" placeholder="Search name, ID, or phone">
    </div>
    <div class="form-group">
        <label for="filter_size">Size</label>
        <select id="filter_size" name="size">
            <option value="">All</option>
            <?php foreach (uniform_sizes() as $size): ?>
                <option value="<?= e($size) ?>" <?= $filters['size'] === $size ? 'selected' : '' ?>><?= e($size) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter_section">Section</label>
        <select id="filter_section" name="section">
            <option value="">All</option>
            <?php foreach (class_sections() as $sectionName): ?>
                <option value="<?= e($sectionName) ?>" <?= $filters['section'] === $sectionName ? 'selected' : '' ?>><?= e(class_section_label($sectionName)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter_status">Status</label>
        <select id="filter_status" name="status">
            <option value="">All</option>
            <?php foreach (['pending','ordered','ready','collected'] as $st): ?>
                <option value="<?= e($st) ?>" <?= $filters['status'] === $st ? 'selected' : '' ?>><?= e(status_label($st)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="btn btn-ghost" type="submit">Filter</button>
</form>

<p class="muted roster-meta"><?= count($all) ?> matching of <?= $totalAll ?> students</p>

<?php $canBulk = can('students.delete') && $rows; ?>
<?php if ($canBulk): ?>
<form id="student-select-form" class="roster-bulk" method="post" data-loading data-loading-label="Deleting…" data-matching-count="<?= (int) count($all) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_selected">
    <input type="hidden" name="delete_matching" id="delete-matching" value="0">
    <input type="hidden" name="search" value="<?= e($filters['search']) ?>">
    <input type="hidden" name="size" value="<?= e($filters['size']) ?>">
    <input type="hidden" name="section" value="<?= e($filters['section']) ?>">
    <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
    <p class="roster-bulk-count"><strong data-selected-count>0</strong> selected</p>
    <button type="button" class="btn btn-ghost btn-sm" data-select-page>Select this page</button>
    <button type="button" class="btn btn-ghost btn-sm" data-select-matching>Select all <?= (int) count($all) ?> matching</button>
    <button type="button" class="btn btn-ghost btn-sm" data-select-none>Clear</button>
    <button type="submit" class="btn btn-danger btn-sm" data-confirm="Selected student records will be permanently deleted. Linked student logins will be disabled." data-confirm-title="Delete selected students?" data-confirm-ok="Delete" disabled>Delete selected</button>
</form>
<?php endif; ?>

<div class="has-cards roster">
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <?php if ($canBulk): ?>
                    <th class="roster-check"><label class="sr-only" for="select-page">Select page</label><input id="select-page" type="checkbox" data-select-page-check></th>
                <?php endif; ?>
                <th>Student</th><th>ID</th><th>Section</th><th>Student phone</th><th>Mother</th><th>Father</th><th>Uniform</th><th>Status</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="<?= $canBulk ? 10 : 9 ?>">No students found.</td></tr>
        <?php else: foreach ($rows as $row): ?>
            <?php $view = student_view_payload($row); ?>
            <tr>
                <?php if ($canBulk): ?>
                    <td class="roster-check">
                        <input form="student-select-form" type="checkbox" name="ids[]" value="<?= (int) $row['id'] ?>" data-student-id="<?= (int) $row['id'] ?>" aria-label="Select <?= e($row['student_name']) ?>">
                    </td>
                <?php endif; ?>
                <td class="roster-student">
                    <button type="button" class="student-open" data-student-open="<?= e(json_encode($view, JSON_UNESCAPED_UNICODE)) ?>">
                        <?php if ($view['photo']): ?>
                            <img class="stu-avatar stu-avatar-sm" src="<?= e($view['photo']) ?>" alt="">
                        <?php else: ?>
                            <span class="stu-avatar stu-avatar-sm" aria-hidden="true"><?= e($view['initials']) ?></span>
                        <?php endif; ?>
                        <span><?= e($row['student_name']) ?></span>
                    </button>
                    <?php if (!empty($pendingIds[(int) $row['id']])): ?><span class="badge badge-pending">Update waiting</span><?php endif; ?>
                </td>
                <td><?= e($row['student_code'] ?: '—') ?></td>
                <td><?= ($row['section'] ?? '') !== '' ? e(class_section_label((string) $row['section'])) : '—' ?></td>
                <td><?= e($row['phone_number'] ?: '—') ?></td>
                <td class="roster-parent"><?php if (($view['mother_name'] === '') && ($view['mother_phone'] === '')): ?>—<?php else: ?><?php if ($view['mother_name'] !== ''): ?><span><?= e($view['mother_name']) ?></span><?php endif; ?><?php if ($view['mother_phone'] !== ''): ?><span><?= e($view['mother_phone']) ?></span><?php endif; ?><?php endif; ?></td>
                <td class="roster-parent"><?php if (($view['father_name'] === '') && ($view['father_phone'] === '')): ?>—<?php else: ?><?php if ($view['father_name'] !== ''): ?><span><?= e($view['father_name']) ?></span><?php endif; ?><?php if ($view['father_phone'] !== ''): ?><span><?= e($view['father_phone']) ?></span><?php endif; ?><?php endif; ?></td>
                <td><span class="badge"><?= e($row['size']) ?></span></td>
                <td><span class="badge badge-<?= e($row['status'] ?? 'pending') ?>"><?= e(status_label($row['status'] ?? 'pending')) ?></span></td>
                <td class="row-actions">
                    <button type="button" class="btn btn-sm btn-ghost" data-student-open="<?= e(json_encode($view, JSON_UNESCAPED_UNICODE)) ?>">View</button>
                    <?php if (can('students.edit')): ?>
                        <a class="btn btn-sm btn-ghost" href="?edit=<?= (int) $row['id'] ?>" data-student-form="<?= e(json_encode($view, JSON_UNESCAPED_UNICODE)) ?>" aria-label="Edit <?= e($row['student_name']) ?>"><?= icon('edit', 14) ?></a>
                    <?php endif; ?>
                    <?php if (can('students.delete')): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                        <button class="btn btn-sm btn-danger btn-ghost" type="submit" data-confirm="This action cannot be undone." data-confirm-title="Delete student?" data-confirm-ok="Delete" aria-label="Delete <?= e($row['student_name']) ?>"><?= icon('trash', 14) ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<div class="record-cards">
    <?php foreach ($rows as $row): ?>
        <?php $view = student_view_payload($row); ?>
        <article class="record-card">
            <?php if ($canBulk): ?>
                <label class="roster-check roster-check-card">
                    <input form="student-select-form" type="checkbox" name="ids[]" value="<?= (int) $row['id'] ?>" data-student-id="<?= (int) $row['id'] ?>">
                    <span>Select</span>
                </label>
            <?php endif; ?>
            <h3>
                <button type="button" class="student-open" data-student-open="<?= e(json_encode($view, JSON_UNESCAPED_UNICODE)) ?>">
                    <?php if ($view['photo']): ?>
                        <img class="stu-avatar stu-avatar-sm" src="<?= e($view['photo']) ?>" alt="">
                    <?php endif; ?>
                    <?= e($row['student_name']) ?>
                </button>
            </h3>
            <?php if (!empty($pendingIds[(int) $row['id']])): ?><p><span class="badge badge-pending">Update waiting</span></p><?php endif; ?>
            <p class="muted">Student ID: <?= e($row['student_code'] ?: 'Not set') ?></p>
            <dl class="record-meta">
                <div><dt>Student phone</dt><dd><?= e($row['phone_number'] ?: '—') ?></dd></div>
                <div><dt>Mother</dt><dd><?= e(trim($view['mother_name'] . ' ' . $view['mother_phone']) ?: '—') ?></dd></div>
                <div><dt>Father</dt><dd><?= e(trim($view['father_name'] . ' ' . $view['father_phone']) ?: '—') ?></dd></div>
                <div><dt>Uniform</dt><dd><?= e($row['size']) ?></dd></div>
                <div><dt>Status</dt><dd><span class="badge badge-<?= e($row['status'] ?? 'pending') ?>"><?= e(status_label($row['status'] ?? 'pending')) ?></span></dd></div>
            </dl>
            <div class="row-actions">
                <button type="button" class="btn btn-sm" data-student-open="<?= e(json_encode($view, JSON_UNESCAPED_UNICODE)) ?>">View</button>
                <?php if (can('students.edit')): ?>
                    <a class="btn btn-sm btn-ghost" href="?edit=<?= (int) $row['id'] ?>" data-student-form="<?= e(json_encode($view, JSON_UNESCAPED_UNICODE)) ?>">Edit</a>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <div class="empty-state">
            <h2>No students found</h2>
            <p>Try another search<?php if (can('students.create')): ?>, or <a href="<?= e(url('admin/students.php?new=1')) ?>" data-student-form="new">add a student</a><?php endif; ?>.</p>
        </div>
    <?php endif; ?>
</div>
</div>
<?php render_pagination($pageData, $query); ?>

<?php if (can('students.create') || can('students.edit')): ?>
<div class="modal-root" id="student-form-modal" hidden>
    <div class="modal-card student-form-card" role="dialog" aria-modal="true" aria-labelledby="student-form-title">
        <button type="button" class="icon-btn modal-close" data-student-form-close aria-label="Close"><?= icon('x', 18) ?></button>
        <form method="post" enctype="multipart/form-data" id="student-form" data-loading data-default-grade="<?= e(class_grade()) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="student-form-id" value="">

            <div class="student-form-head">
                <label class="student-form-avatar">
                    <img id="student-form-preview" alt="" hidden>
                    <span class="stu-avatar" id="student-form-initials" aria-hidden="true">+</span>
                    <input id="photo" class="sr-only" type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif">
                </label>
                <div>
                    <p class="eyebrow" id="student-form-kicker">New record</p>
                    <h2 id="student-form-title">Add student</h2>
                    <p class="muted">Photo, phones, and family details stay on this record.</p>
                    <p class="muted student-form-photo-hint">Tap the circle to add a photo.</p>
                </div>
            </div>
            <p id="student-form-remove-wrap" class="student-form-remove" hidden>
                <label class="check"><input id="remove_photo" type="checkbox" name="remove_photo" value="1"> Remove current photo</label>
            </p>

            <p class="section-label">Student</p>
            <div class="form-grid">
                <div class="form-group"><label class="req" for="student_name">Full name</label><input id="student_name" name="student_name" required autocomplete="name"></div>
                <div class="form-group"><label for="student_code">Student ID</label><input id="student_code" name="student_code"></div>
                <div class="form-group"><label for="phone_number">Student phone</label><input id="phone_number" name="phone_number" inputmode="tel" autocomplete="tel"></div>
                <div class="form-group"><label for="grade">Grade</label><input id="grade" name="grade" value="<?= e(class_grade()) ?>"></div>
                <div class="form-group">
                    <label for="section">Section</label>
                    <select id="section" name="section">
                        <option value="">Choose section</option>
                        <?php foreach (class_sections() as $sectionName): ?>
                            <option value="<?= e($sectionName) ?>" data-official="1"><?= e($sectionName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <p class="section-label">Family</p>
            <div class="form-grid">
                <div class="form-group"><label for="mother_name">Mother’s name</label><input id="mother_name" name="mother_name"></div>
                <div class="form-group"><label for="mother_phone">Mother’s phone</label><input id="mother_phone" name="mother_phone" inputmode="tel"></div>
                <div class="form-group"><label for="father_name">Father’s name</label><input id="father_name" name="father_name"></div>
                <div class="form-group"><label for="father_phone">Father’s phone</label><input id="father_phone" name="father_phone" inputmode="tel"></div>
            </div>

            <p class="section-label">Uniform</p>
            <div class="form-grid">
                <div class="form-group">
                    <label class="req" for="size">Size</label>
                    <select id="size" name="size" required>
                        <option value="">Select size</option>
                        <?php foreach (uniform_sizes() as $size): ?>
                            <option value="<?= e($size) ?>"><?= e($size) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <?php foreach (['pending','ordered','ready','collected'] as $st): ?>
                            <option value="<?= e($st) ?>"><?= e(status_label($st)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="payment_status">Payment</label>
                    <select id="payment_status" name="payment_status">
                        <?php foreach (['unpaid','partial','paid'] as $st): ?>
                            <option value="<?= e($st) ?>"><?= e(status_label($st)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label for="quantity">Quantity</label><input id="quantity" name="quantity" type="number" min="1" value="1"></div>
                <div class="form-group full"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="3"></textarea></div>
            </div>

            <div class="form-actions student-form-actions">
                <button class="btn" type="submit" id="student-form-submit">Add student</button>
                <button type="button" class="btn btn-ghost" data-student-form-close>Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="modal-root" id="student-view-modal" hidden>
    <div class="modal-card student-view-card" role="dialog" aria-modal="true" aria-labelledby="student-view-name">
        <button type="button" class="icon-btn modal-close" data-student-close aria-label="Close"><?= icon('x', 18) ?></button>
        <div class="student-view-head">
            <img class="stu-avatar" id="student-view-photo" alt="" hidden>
            <span class="stu-avatar" id="student-view-initials" aria-hidden="true"></span>
            <div>
                <p class="eyebrow">Student record</p>
                <h2 id="student-view-name"></h2>
                <p class="muted" id="student-view-id"></p>
            </div>
        </div>
        <div class="student-contacts">
            <article>
                <p class="eyebrow">Student phone</p>
                <a id="student-view-student-phone" href="#"></a>
                <p id="student-view-student-phone-empty" class="muted" hidden>Not set</p>
            </article>
            <article>
                <p class="eyebrow">Mother</p>
                <p id="student-view-mother-name"></p>
                <a id="student-view-mother-phone" href="#"></a>
                <p id="student-view-mother-empty" class="muted" hidden>Not set</p>
            </article>
            <article>
                <p class="eyebrow">Father</p>
                <p id="student-view-father-name"></p>
                <a id="student-view-father-phone" href="#"></a>
                <p id="student-view-father-empty" class="muted" hidden>Not set</p>
            </article>
        </div>
        <dl class="profile-list student-view-grid">
            <div><dt>Grade</dt><dd id="student-view-grade"></dd></div>
            <div><dt>Section</dt><dd id="student-view-section"></dd></div>
            <div><dt>Uniform</dt><dd id="student-view-size"></dd></div>
            <div><dt>Quantity</dt><dd id="student-view-quantity"></dd></div>
            <div><dt>Status</dt><dd id="student-view-status"></dd></div>
            <div><dt>Payment</dt><dd id="student-view-payment"></dd></div>
            <div><dt>Created</dt><dd id="student-view-created"></dd></div>
            <div><dt>Last updated</dt><dd id="student-view-updated"></dd></div>
        </dl>
        <div class="student-view-notes" id="student-view-notes-wrap" hidden>
            <p class="eyebrow">Notes</p>
            <p id="student-view-notes"></p>
        </div>
        <div class="form-actions student-view-actions">
            <button type="button" class="btn" id="student-view-edit">Edit student</button>
            <?php if (can('students.delete')): ?>
            <form method="post" id="student-view-delete">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="student-view-delete-id" value="">
                <button class="btn btn-danger" type="submit" data-confirm="This action cannot be undone." data-confirm-title="Delete student?" data-confirm-ok="Delete">Delete</button>
            </form>
            <?php endif; ?>
            <button type="button" class="btn btn-ghost" data-student-close>Close</button>
        </div>
    </div>
</div>
<?php
$viewOpen = request_int('view') > 0 ? student_by_id(request_int('view')) : null;
if ($viewOpen): ?>
<script>window.__openStudent = <?= json_encode(student_view_payload($viewOpen), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<?php endif; ?>
<?php if ($showForm): ?>
<script>window.__openStudentForm = <?= $edit ? json_encode(student_view_payload($edit), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) : 'true' ?>;</script>
<?php endif; ?>

<?php admin_footer(); ?>
