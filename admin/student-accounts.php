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
            set_one_time_credentials([$created], 'Account created. Print this slip and give it to the student. The temporary password is not stored.');
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
                set_one_time_credentials($created, count($created) . ' logins are ready. Print the slips and give each student their username and temporary password. They are not stored after you leave this screen.');
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
            header('Content-Disposition: attachment; filename="luchador-student-logins.csv"');
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
            $code = '';
            if (!empty($target['student_id'])) {
                $linked = student_by_id((int) $target['student_id']);
                $code = (string) ($linked['student_code'] ?? '');
            }
            log_audit('user.password_reset', 'user', $userId, $target['full_name'] ?: $target['username']);
            set_one_time_credentials([[
                'username' => $target['username'],
                'full_name' => $target['full_name'] ?: $target['username'],
                'student_code' => $code,
                'password' => $temp,
            ]], 'Temporary password generated. Print this slip and give it to the student. It will not be stored.');
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
$unlinked = students_without_accounts();
$without = array_slice($unlinked, 0, 12);
$credentials = one_time_credentials();
if (request_str('print') === '1') {
    if (!$credentials || empty($credentials['rows'])) {
        flash_set('error', 'There are no temporary passwords to print. Create or reset accounts first.');
        redirect('admin/student-accounts.php');
    }
    render_student_login_slips($credentials);
    exit;
}
$accounts = db()->query(
    'SELECT u.id, u.username, u.full_name, u.is_active, u.last_login_at, u.must_change_password, s.student_name, s.student_code
     FROM users u
     INNER JOIN uniforms s ON s.id = u.student_id
     ORDER BY s.student_name ASC'
)->fetchAll();

admin_header('Student accounts', 'student-accounts');
admin_page_head(
    'Pick a student from the class roster, create their login, then print the slip. This never adds or deletes student records. Committee logins are created separately.',
    [
        '<a class="btn btn-ghost" href="' . e(url('admin/users.php')) . '">Committee logins</a>',
    ]
);
?>

<?php if ($credentials): ?>
    <div class="security-callout" role="status">
        <h2>Ready to print</h2>
        <p><?= e((string) ($credentials['notice'] ?? 'Print these slips now. Temporary passwords are not stored after you leave this screen.')) ?></p>
        <div class="table-wrap cred-table-wrap">
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
        <div class="form-actions" style="margin-top:0.8rem">
            <a class="btn" href="<?= e(url('admin/student-accounts.php?print=1')) ?>" target="_blank" rel="noopener">Print slips</a>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="download_credentials">
                <button class="btn btn-ghost" type="submit">Download CSV</button>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="clear_credentials">
                <button class="btn btn-ghost" type="submit">I have printed these</button>
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
    <p>This creates one login for each existing student who does not already have an account. After it finishes, print the slips and give each student their username and temporary password. Being on the class roster is not enough to sign in. Existing accounts are never overwritten.</p>
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
    <p class="muted">Search the roster, click the student, then create their login. Only students without an account are listed.</p>
    <?php if (!$unlinked): ?>
        <p>Every student on the roster already has a login.</p>
    <?php else: ?>
    <form method="post" data-student-picker>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_one">
        <input type="hidden" name="student_id" data-picker-id value="">
        <div class="form-group" data-picker-search>
            <label for="student-picker-q">Find a student</label>
            <input type="search" id="student-picker-q" data-picker-q placeholder="Type a name or student ID" autocomplete="off">
        </div>
        <div class="picker-choice" data-picker-choice hidden>
            <div>
                <span>Selected</span>
                <strong data-picker-name></strong>
            </div>
            <button class="btn btn-ghost btn-sm" type="button" data-picker-clear>Change</button>
        </div>
        <div class="picker-list" data-picker-list role="listbox" aria-label="Students without an account">
            <?php foreach ($unlinked as $row): ?>
                <button
                    type="button"
                    class="picker-option"
                    role="option"
                    data-id="<?= (int) $row['id'] ?>"
                    data-name="<?= e($row['student_name']) ?>"
                    data-search="<?= e(strtolower(trim($row['student_name'] . ' ' . ($row['student_code'] ?? '')))) ?>"
                >
                    <strong><?= e($row['student_name']) ?></strong>
                    <span><?= e($row['student_code'] ?: 'No student ID') ?></span>
                </button>
            <?php endforeach; ?>
        </div>
        <p class="picker-empty muted" data-picker-empty hidden>No matching students without an account.</p>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit" data-picker-submit disabled>Create account</button>
        </div>
    </form>
    <?php endif; ?>
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
<script>
(function () {
    const root = document.querySelector('[data-student-picker]');
    if (!root) return;
    const q = root.querySelector('[data-picker-q]');
    const search = root.querySelector('[data-picker-search]');
    const list = root.querySelector('[data-picker-list]');
    const empty = root.querySelector('[data-picker-empty]');
    const choice = root.querySelector('[data-picker-choice]');
    const nameEl = root.querySelector('[data-picker-name]');
    const idEl = root.querySelector('[data-picker-id]');
    const submit = root.querySelector('[data-picker-submit]');
    const options = Array.from(root.querySelectorAll('.picker-option'));

    const setSelected = (id, name) => {
        idEl.value = id || '';
        if (nameEl) nameEl.textContent = name || '';
        const has = id !== '';
        if (choice) choice.hidden = !has;
        if (list) list.hidden = has;
        if (search) search.hidden = has;
        if (q && !has) q.focus();
        if (submit) submit.disabled = !has;
        options.forEach((opt) => opt.classList.toggle('is-active', opt.dataset.id === id));
    };

    const filter = () => {
        const term = (q.value || '').trim().toLowerCase();
        let shown = 0;
        options.forEach((opt) => {
            const match = term === '' || (opt.dataset.search || '').includes(term);
            opt.hidden = !match;
            if (match) shown += 1;
        });
        if (empty) empty.hidden = shown > 0;
        if (list) list.hidden = shown === 0;
    };

    options.forEach((opt) => {
        opt.addEventListener('click', () => {
            setSelected(opt.dataset.id || '', opt.dataset.name || '');
        });
    });
    if (q) q.addEventListener('input', filter);
    root.querySelector('[data-picker-clear]')?.addEventListener('click', () => {
        if (q) q.value = '';
        setSelected('', '');
        filter();
    });
    filter();
})();
</script>
<?php admin_footer(); ?>
