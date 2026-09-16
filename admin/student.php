<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';
require_once dirname(__DIR__) . '/includes/students.php';

admin_boot('students.view');

$id = request_int('id');
$student = $id > 0 ? student_by_id($id) : null;
if (!$student) {
    flash_set('error', 'Student not found.');
    redirect('admin/students.php');
}

if (is_post()) {
    require_csrf();
    try {
        if (posted('action') === 'delete') {
            require_permission('students.delete');
            $name = $student['student_name'];
            delete_student_record($id);
            log_audit('student.delete', 'student', $id, $name);
            flash_set('success', 'Student deleted successfully.');
            redirect('admin/students.php');
        }
        if (posted('action') === 'status') {
            require_permission('uniforms.edit');
            $status = posted('status');
            if (in_array($status, ['pending', 'ordered', 'ready', 'collected'], true)) {
                db()->prepare('UPDATE uniforms SET status = ? WHERE id = ?')->execute([$status, $id]);
                log_audit('uniform.status', 'student', $id, $student['student_name'] . ' → ' . $status);
                flash_set('success', 'Uniform status updated.');
            }
            redirect('admin/student.php?id=' . $id);
        }
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save changes. Please try again.');
        redirect('admin/student.php?id=' . $id);
    }
}

admin_header($student['student_name'], 'students');
?>

<a class="back-link" href="<?= e(url('admin/students.php')) ?>"><?= icon('chevron-left', 16) ?> Back to students</a>

<div class="profile-hero student-profile-hero">
    <?php if (!empty($student['photo'])): ?>
        <img class="stu-avatar" src="<?= e(url($student['photo'])) ?>" alt="">
    <?php endif; ?>
    <div>
        <h2><?= e($student['student_name']) ?></h2>
        <p class="muted">Student ID: <?= e($student['student_code'] ?: 'Not set') ?></p>
    </div>
</div>

<section class="panel">
    <p class="section-label">Contact</p>
    <dl class="profile-list">
        <div><dt>Student phone</dt><dd><?php if ($student['phone_number']): ?><a href="tel:<?= e($student['phone_number']) ?>"><?= e($student['phone_number']) ?></a><?php else: ?>Not set<?php endif; ?></dd></div>
        <div><dt>Mother</dt><dd><?php
            $mother = trim((string) ($student['mother_name'] ?? ''));
            $motherPhone = trim((string) ($student['mother_phone'] ?? ''));
            if ($mother === '' && $motherPhone === '') {
                echo 'Not set';
            } else {
                echo e($mother !== '' ? $mother : 'Mother');
                if ($motherPhone !== '') {
                    echo '<br><a href="tel:' . e($motherPhone) . '">' . e($motherPhone) . '</a>';
                }
            }
        ?></dd></div>
        <div><dt>Father</dt><dd><?php
            $father = trim((string) ($student['father_name'] ?? ''));
            $fatherPhone = trim((string) ($student['father_phone'] ?? ''));
            if ($father === '' && $fatherPhone === '') {
                echo 'Not set';
            } else {
                echo e($father !== '' ? $father : 'Father');
                if ($fatherPhone !== '') {
                    echo '<br><a href="tel:' . e($fatherPhone) . '">' . e($fatherPhone) . '</a>';
                }
            }
        ?></dd></div>
        <div><dt>Grade</dt><dd><?= e($student['grade'] ?: class_grade()) ?></dd></div>
        <div><dt>Section</dt><dd><?= e($student['section'] ?: 'Not set') ?></dd></div>
    </dl>
</section>

<section class="panel">
    <p class="section-label">Uniform</p>
    <dl class="profile-list">
        <div><dt>Size</dt><dd><span class="badge"><?= e($student['size']) ?></span></dd></div>
        <div><dt>Status</dt><dd><span class="badge badge-<?= e($student['status'] ?? 'pending') ?>"><?= e(status_label($student['status'] ?? 'pending')) ?></span></dd></div>
        <div><dt>Quantity</dt><dd><?= (int) ($student['quantity'] ?? 1) ?></dd></div>
        <div><dt>Payment</dt><dd><?= e(status_label($student['payment_status'] ?? 'unpaid')) ?></dd></div>
    </dl>
    <?php if (can('uniforms.edit')): ?>
    <form method="post" class="form-actions" style="margin-top:1rem" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="status">
        <label class="sr-only" for="profile-status">Uniform status</label>
        <select id="profile-status" name="status">
            <?php foreach (['pending','ordered','ready','collected'] as $st): ?>
                <option value="<?= e($st) ?>" <?= (($student['status'] ?? '') === $st) ? 'selected' : '' ?>><?= e(status_label($st)) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="submit" data-loading-label="Updating…">Update status</button>
    </form>
    <?php endif; ?>
</section>

<section class="panel">
    <p class="section-label">Record information</p>
    <dl class="profile-list">
        <div><dt>Created</dt><dd><?= e($student['created_at'] ? format_when($student['created_at']) : 'Unknown') ?></dd></div>
        <div><dt>Last updated</dt><dd><?= e(!empty($student['updated_at']) ? format_when($student['updated_at']) : 'No later changes recorded') ?></dd></div>
    </dl>
    <?php if (!empty($student['notes'])): ?>
        <p style="margin-top:1rem"><strong>Notes</strong><br><?= nl2br(e($student['notes'])) ?></p>
    <?php endif; ?>
    <div class="form-actions" style="margin-top:1.2rem">
        <?php if (can('students.edit')): ?>
            <a class="btn" href="<?= e(url('admin/students.php?edit=' . (int) $student['id'])) ?>">Edit student</a>
        <?php endif; ?>
        <?php if (can('students.delete')): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <button class="btn btn-danger" type="submit" data-confirm="This action cannot be undone." data-confirm-title="Delete student?" data-confirm-ok="Delete">Delete student</button>
        </form>
        <?php endif; ?>
    </div>
</section>

<?php admin_footer(); ?>
