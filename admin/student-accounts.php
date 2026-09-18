<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';

admin_boot('users.manage');

if (is_post()) {
    require_csrf();
    $action = posted('action');
    try {
        if ($action === 'clear_credentials') {
            clear_one_time_credentials();
            flash_set('success', 'Temporary credentials were cleared from this screen.');
            redirect('admin/student-accounts.php');
        }
        if ($action === 'create_one') {
            $studentId = (int) posted('student_id');
            $record = student_by_id($studentId);
            if (!$record) {
                throw new InvalidArgumentException('Student record not found.');
            }
            $created = create_student_user_account($record);
            log_audit('user.create', 'user', (int) $created['user_id'], $created['full_name']);
            set_one_time_credentials([$created], 'Account created. Copy the temporary password now — it is not stored.');
            flash_set('success', 'Student account created.');
            redirect('admin/student-accounts.php');
        }
        if ($action === 'generate') {
            if (posted('confirm') !== '1') {
                throw new InvalidArgumentException('Confirm the bulk operation before generating accounts.');
            }
            $before = (int) db()->query('SELECT COUNT(*) FROM uniforms')->fetchColumn();
            $created = generate_missing_student_accounts();
            $after = (int) db()->query('SELECT COUNT(*) FROM uniforms')->fetchColumn();
            if ($after !== $before) {
                app_log('student account generate: uniform count changed from ' . $before . ' to ' . $after);
            }
            log_audit('student_accounts.generate', 'user', null, (string) count($created) . ' accounts');
            if ($created) {
                set_one_time_credentials($created, count($created) . ' temporary passwords were generated. Download or copy them now. They are not stored permanently.');
            }
            flash_set('success', $created ? count($created) . ' student accounts created.' : 'Every student already has an account.');
            redirect('admin/student-accounts.php');
        }
        if ($action === 'download_credentials') {
            $payload = one_time_credentials();
            if (!$payload || empty($payload['rows'])) {
                throw new InvalidArgumentException('There are no temporary credentials to download.');
            }
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="luchadore-student-credentials.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['full_name', 'username', 'student_code', 'temporary_password']);
            foreach ($payload['rows'] as $row) {
                fputcsv($out, [
                    $row['full_name'] ?? '',
                    $row['username'] ?? '',
                    $row['student_code'] ?? '',
                    $row['password'] ?? '',
                ]);
            }
            fclose($out);
            exit;
        }
        if ($action === 'reset_password') {
            $userId = (int) posted('id');
            $target = fetch_user_by_id($userId);
            if (!$target || ($target['role'] ?? '') !== 'student') {
                throw new InvalidArgumentException('Student account not found.');
            }
            $temp = generate_temp_password(12);
            db()->prepare('UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?')
                ->execute([password_hash($temp, PASSWORD_DEFAULT), $userId]);
            log_audit('user.password_reset', 'user', $userId, $target['full_name'] ?: $target['username']);
            set_one_time_credentials([[
                'username' => $target['username'],
                'full_name' => $target['full_name'] ?: $target['username'],
                'student_code' => '',
                'password' => $temp,
            ]], 'Temporary password generated. Copy it now — it will not be stored.');
            flash_set('success', 'Password reset.');
            redirect('admin/student-accounts.php');
        }
        if ($action === 'deactivate' || $action === 'activate') {
            $userId = (int) posted('id');
            $target = fetch_user_by_id($userId);
            if (!$target || ($target['role'] ?? '') !== 'student') {
                throw new InvalidArgumentException('Student account not found.');
            }
            $active = $action === 'activate';
            db()->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $userId]);
            log_audit($active ? 'user.enable' : 'user.disable', 'user', $userId, $target['full_name'] ?: $target['username']);
            flash_set('success', $active ? 'Account activated.' : 'Account disabled.');
            redirect('admin/student-accounts.php');
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to complete that account action.');
    }
    redirect('admin/student-accounts.php');
}

$stats = student_account_stats();
$without = students_without_accounts(12);
$credentials = one_time_credentials();
$accounts = db()->query(
    'SELECT u.id, u.username, u.full_name, u.is_active, u.last_login_at, u.must_change_password, s.student_name, s.student_code
     FROM users u
     INNER JOIN uniforms s ON s.id = u.student_id
     ORDER BY s.student_name ASC'
)->fetchAll();

admin_header('Student accounts', 'student-accounts');
admin_page_head('Create logins for existing students. This never adds, edits, or deletes student records.');
?>

<?php if ($credentials): ?>
    <div class="security-callout" role="status">
        <h2>Temporary credentials</h2>
        <p><?= e((string) ($credentials['notice'] ?? 'Copy these now. They are not stored permanently.')) ?></p>
        <?php if (count($credentials['rows']) <= 12): ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Name</th><th>Username</th><th>Temporary password</th></tr></thead>
                    <tbody>
                    <?php foreach ($credentials['rows'] as $row): ?>
                        <tr>
                            <td><?= e($row['full_name'] ?? '') ?></td>
                            <td><?= e($row['username'] ?? '') ?></td>
                            <td><code><?= e($row['password'] ?? '') ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="muted"><?= count($credentials['rows']) ?> passwords were generated. Download the CSV — it is not kept on the server.</p>
        <?php endif; ?>
        <div class="form-actions" style="margin-top:0.8rem">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="download_credentials">
                <button class="btn" type="submit">Download CSV</button>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="clear_credentials">
                <button class="btn btn-ghost" type="submit">I have copied these</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="account-stats">
    <section class="panel"><p class="muted">Still need a login</p><p class="student-stat"><?= (int) $stats['without_accounts'] ?></p></section>
    <section class="panel"><p class="muted">Already have accounts</p><p class="student-stat"><?= (int) $stats['with_accounts'] ?></p></section>
</div>

<section class="panel">
    <h2>Generate student accounts</h2>
    <p>This creates one login for each existing student who does not already have an account. Being on the class roster is not enough to sign in. Existing accounts are never overwritten. Student and uniform records are not changed.</p>
    <?php if ($without): ?>
        <p class="muted">Examples of students still without accounts:</p>
        <ul>
            <?php foreach ($without as $row): ?>
                <li><?= e($row['student_name']) ?><?= !empty($row['student_code']) ? ' · ' . e($row['student_code']) : '' ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="generate">
        <label class="check" style="margin:0.8rem 0">
            <input type="checkbox" name="confirm" value="1" required>
            I understand this will create <?= (int) $stats['without_accounts'] ?> new login<?= $stats['without_accounts'] === 1 ? '' : 's' ?> and will not change student records.
        </label>
        <div class="form-actions">
            <button class="btn" type="submit" <?= $stats['without_accounts'] === 0 ? 'disabled' : '' ?>>Generate accounts</button>
        </div>
    </form>
</section>

<section class="panel">
    <h2>Create one account</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_one">
        <div class="form-group">
            <label>Student without an account</label>
            <select name="student_id" required>
                <option value="">Select</option>
                <?php foreach (students_without_accounts() as $row): ?>
                    <option value="<?= (int) $row['id'] ?>"><?= e($row['student_name']) ?><?= !empty($row['student_code']) ? ' · ' . e($row['student_code']) : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit">Create account</button>
        </div>
    </form>
</section>

<div class="table-wrap user-table">
<table>
    <thead><tr><th>Student</th><th>Student ID</th><th>Username</th><th>Status</th><th>Last login</th><th></th></tr></thead>
    <tbody>
    <?php if (!$accounts): ?>
        <tr><td colspan="6">No student accounts yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($accounts as $row): ?>
        <tr>
            <td><?= e($row['student_name']) ?></td>
            <td><?= e($row['student_code'] ?: '—') ?></td>
            <td><?= e($row['username']) ?></td>
            <td><?= admin_active_badge($row['is_active'] ?? 0) ?><?= !empty($row['must_change_password']) ? ' <span class="badge">Temporary password</span>' : '' ?></td>
            <td><?= $row['last_login_at'] ? e(format_when($row['last_login_at'])) : 'Never' ?></td>
            <td class="row-actions">
                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/users.php?edit=' . (int) $row['id'])) ?>">Edit</a>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <input type="hidden" name="action" value="reset_password">
                    <button class="btn btn-sm btn-ghost" type="submit">Reset password</button>
                </form>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <input type="hidden" name="action" value="<?= !empty($row['is_active']) ? 'deactivate' : 'activate' ?>">
                    <button class="btn btn-sm <?= !empty($row['is_active']) ? 'btn-danger' : '' ?>" type="submit"><?= !empty($row['is_active']) ? 'Disable' : 'Enable' ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php admin_footer(); ?>
