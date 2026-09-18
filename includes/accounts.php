<?php

declare(strict_types=1);

function username_taken(string $username, int $exceptId = 0): bool
{
    $stmt = db()->prepare('SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1');
    $stmt->execute([$username, $exceptId]);
    return (bool) $stmt->fetchColumn();
}

function student_account_id(int $studentId): ?int
{
    if ($studentId <= 0 || !column_exists(db(), 'users', 'student_id')) {
        return null;
    }
    $stmt = db()->prepare('SELECT id FROM users WHERE student_id = ? LIMIT 1');
    $stmt->execute([$studentId]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

function student_account_username(array $student): string
{
    $code = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($student['student_code'] ?? '')) ?? '';
    if (strlen($code) >= 2) {
        $base = strtolower($code);
    } else {
        $name = trim((string) ($student['student_name'] ?? ''));
        $parts = preg_split('/\s+/', $name) ?: [];
        $first = strtolower(preg_replace('/[^a-z0-9]/', '', (string) ($parts[0] ?? '')) ?? '');
        $base = strlen($first) >= 3 ? $first : ('s' . (int) ($student['id'] ?? 0));
    }
    $username = $base;
    $n = 2;
    while (username_taken($username)) {
        $username = $base . $n;
        $n++;
        if ($n > 50) {
            $username = 's' . (int) ($student['id'] ?? 0) . random_int(10, 99);
            break;
        }
    }
    return $username;
}

function student_account_stats(): array
{
    $total = (int) db()->query('SELECT COUNT(*) FROM uniforms')->fetchColumn();
    $linked = 0;
    if (column_exists(db(), 'users', 'student_id')) {
        $linked = (int) db()->query('SELECT COUNT(*) FROM users WHERE student_id IS NOT NULL')->fetchColumn();
    }
    return [
        'students' => $total,
        'with_accounts' => $linked,
        'without_accounts' => max(0, $total - $linked),
    ];
}

function students_without_accounts(int $limit = 0): array
{
    $sql = 'SELECT u.id, u.student_name, u.student_code
            FROM uniforms u
            LEFT JOIN users usr ON usr.student_id = u.id
            WHERE usr.id IS NULL
            ORDER BY u.student_name ASC';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit;
    }
    return db()->query($sql)->fetchAll();
}

function create_student_user_account(array $student, ?string $plainPassword = null): array
{
    $studentId = (int) ($student['id'] ?? 0);
    if ($studentId <= 0) {
        throw new InvalidArgumentException('Student record is missing.');
    }
    if (student_account_id($studentId)) {
        throw new InvalidArgumentException('That student already has an account.');
    }
    $password = $plainPassword !== null && $plainPassword !== '' ? $plainPassword : generate_temp_password(12);
    if (strlen($password) < password_min_length()) {
        throw new InvalidArgumentException('Temporary passwords must be at least ' . password_min_length() . ' characters.');
    }
    $username = student_account_username($student);
    $fullName = trim((string) ($student['student_name'] ?? '')) ?: $username;
    db()->prepare(
        'INSERT INTO users (username, password, must_change_password, full_name, role, is_active, student_id)
         VALUES (?, ?, 1, ?, ?, 1, ?)'
    )->execute([
        $username,
        password_hash($password, PASSWORD_DEFAULT),
        $fullName,
        'student',
        $studentId,
    ]);
    $userId = (int) db()->lastInsertId();
    save_user_permissions($userId, []);
    return [
        'user_id' => $userId,
        'username' => $username,
        'full_name' => $fullName,
        'student_id' => $studentId,
        'student_code' => (string) ($student['student_code'] ?? ''),
        'password' => $password,
    ];
}

function generate_missing_student_accounts(): array
{
    $created = [];
    $students = students_without_accounts();
    db()->beginTransaction();
    try {
        foreach ($students as $student) {
            $created[] = create_student_user_account($student);
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return $created;
}

function set_one_time_credentials(array $rows, string $notice): void
{
    $_SESSION['one_time_credentials'] = [
        'notice' => $notice,
        'created_at' => time(),
        'rows' => $rows,
    ];
}

function one_time_credentials(): ?array
{
    $payload = $_SESSION['one_time_credentials'] ?? null;
    return is_array($payload) ? $payload : null;
}

function clear_one_time_credentials(): void
{
    unset($_SESSION['one_time_credentials']);
}

function render_student_login_slips(array $payload): void
{
    $rows = $payload['rows'] ?? [];
    if (!$rows) {
        return;
    }
    $loginUrl = absolute_url('login.php');
    $class = class_name();
    $grade = class_grade();
    $school = school_name();
    $count = count($rows);
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print logins · <?= e($class) ?></title>
    <?php site_font_links(); ?>
    <style>
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #16151a; background: #f4f2ee; font-family: Inter, Segoe UI, Arial, sans-serif; }
        .toolbar { display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: center; justify-content: space-between; padding: 1rem 1.2rem; background: #fff; border-bottom: 1px solid #eceae6; }
        .toolbar p { margin: 0; color: #5f5c56; font-size: 0.92rem; }
        .btn { display: inline-flex; align-items: center; gap: 0.4rem; background: #16151a; color: #fff; border: 0; border-radius: 8px; padding: 10px 16px; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
        .btn-ghost { background: transparent; color: #16151a; border: 1px solid #d8d4cc; }
        .sheet { padding: 1rem 1.2rem 2rem; }
        .slips { display: grid; grid-template-columns: 1fr 1fr; gap: 8mm; }
        .slip { background: #fff; border: 1px dashed #b7b2a8; padding: 8mm 9mm; break-inside: avoid; page-break-inside: avoid; }
        .slip-kicker { margin: 0; font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase; color: #73706a; }
        .slip h2 { margin: 0.2rem 0 0.7rem; font-size: 1.12rem; letter-spacing: -0.03em; }
        .slip dl { margin: 0; }
        .slip dt { margin: 0.55rem 0 0; font-size: 10px; letter-spacing: 0.1em; text-transform: uppercase; color: #73706a; }
        .slip dd { margin: 0.15rem 0 0; font-size: 1.02rem; word-break: break-word; }
        .slip .secret { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 1.12rem; letter-spacing: 0.04em; }
        .slip .hint { margin: 0.85rem 0 0; font-size: 0.78rem; color: #5f5c56; line-height: 1.45; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { padding: 0; }
            .slip { box-shadow: none; }
        }
        @media (max-width: 720px) {
            .slips { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <p><?= e((string) $count) ?> login <?= $count === 1 ? 'slip' : 'slips' ?> · Cut and give one to each student. These passwords are shown only this once.</p>
        <div>
            <button class="btn" type="button" onclick="window.print()">Print slips</button>
            <a class="btn btn-ghost" href="<?= e(url('admin/student-accounts.php')) ?>">Back</a>
        </div>
    </div>
    <div class="sheet">
        <div class="slips">
            <?php foreach ($rows as $row): ?>
                <article class="slip">
                    <p class="slip-kicker"><?= e($school) ?> · <?= e($class) ?> · Grade <?= e($grade) ?></p>
                    <h2><?= e((string) ($row['full_name'] ?? '')) ?></h2>
                    <dl>
                        <dt>Sign in at</dt>
                        <dd><?= e($loginUrl) ?></dd>
                        <dt>Username</dt>
                        <dd class="secret"><?= e((string) ($row['username'] ?? '')) ?></dd>
                        <?php if (trim((string) ($row['student_code'] ?? '')) !== ''): ?>
                            <dt>Student ID</dt>
                            <dd><?= e((string) $row['student_code']) ?></dd>
                        <?php endif; ?>
                        <dt>Temporary password</dt>
                        <dd class="secret"><?= e((string) ($row['password'] ?? '')) ?></dd>
                    </dl>
                    <p class="hint">You can also sign in with your student ID or full name. Change this password after the first sign-in.</p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
    <?php
}

function slugify_role_name(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
    $slug = trim($slug, '_');
    if ($slug === '') {
        $slug = 'role';
    }
    if (strlen($slug) > 40) {
        $slug = substr($slug, 0, 40);
    }
    $base = $slug;
    $n = 2;
    while (fetch_role_by_slug($slug)) {
        $suffix = '_' . $n;
        $slug = substr($base, 0, 40 - strlen($suffix)) . $suffix;
        $n++;
    }
    return $slug;
}
