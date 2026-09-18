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
    $code = ascii_login_slug((string) ($student['student_code'] ?? ''));
    if (strlen($code) >= 2) {
        $base = $code;
    } else {
        $first = ascii_login_slug(first_name((string) ($student['student_name'] ?? '')));
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
        'INSERT INTO users (username, `password`, must_change_password, full_name, role, is_active, student_id)
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
        :root {
            --ink: #111113;
            --muted: #71717a;
            --line: #e4e4e7;
            --paper: #f4f4f5;
            --card: #fff;
            --fill: #f4f4f5;
            --font: Inter, system-ui, sans-serif;
            --mono: ui-monospace, SFMono-Regular, Consolas, monospace;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: var(--ink);
            background: var(--paper);
            font-family: var(--font);
            -webkit-font-smoothing: antialiased;
        }
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 2;
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
            justify-content: space-between;
            padding: 0.9rem 1.25rem;
            background: rgba(255, 255, 255, 0.92);
            border-bottom: 1px solid var(--line);
            backdrop-filter: blur(12px);
        }
        .toolbar p {
            margin: 0;
            color: var(--muted);
            font-size: 0.84rem;
            letter-spacing: -0.01em;
        }
        .toolbar-actions { display: flex; gap: 0.45rem; flex-shrink: 0; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 0 14px;
            border: 0;
            border-radius: 999px;
            background: var(--ink);
            color: #fff;
            font: inherit;
            font-size: 0.84rem;
            font-weight: 600;
            letter-spacing: -0.01em;
            text-decoration: none;
            cursor: pointer;
        }
        .btn-ghost {
            background: transparent;
            color: var(--ink);
            border: 1px solid var(--line);
        }
        .sheet { padding: 1.25rem; }
        .slips {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .slip {
            display: flex;
            flex-direction: column;
            padding: 22px 22px 18px;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .slip-top {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
        }
        .brand {
            margin: 0;
            font-size: 0.68rem;
            font-weight: 600;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }
        .meta {
            margin: 0;
            color: var(--muted);
            font-size: 0.68rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .slip h2 {
            margin: 18px 0 4px;
            font-size: 1.28rem;
            font-weight: 600;
            letter-spacing: -0.045em;
            line-height: 1.15;
        }
        .school {
            margin: 0 0 16px;
            color: var(--muted);
            font-size: 0.78rem;
        }
        .creds {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .cred {
            min-width: 0;
            padding: 10px 12px 11px;
            background: var(--fill);
            border-radius: 12px;
        }
        .cred span {
            display: block;
            margin-bottom: 3px;
            color: var(--muted);
            font-size: 0.68rem;
            letter-spacing: 0.04em;
        }
        .cred b {
            display: block;
            font-family: var(--mono);
            font-size: 1.18rem;
            font-weight: 650;
            letter-spacing: 0.03em;
            line-height: 1.3;
            word-break: break-all;
            user-select: all;
        }
        .url {
            margin: 14px 0 0;
            color: var(--muted);
            font-size: 0.75rem;
            line-height: 1.45;
            word-break: break-all;
        }
        .url b {
            display: block;
            margin-top: 1px;
            color: var(--ink);
            font-size: 0.8rem;
            font-weight: 500;
            user-select: all;
        }
        .hint {
            margin: auto 0 0;
            padding-top: 14px;
            color: var(--muted);
            font-size: 0.72rem;
            line-height: 1.45;
        }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { padding: 0; }
            .slips { gap: 8mm; }
            .slip {
                border-radius: 0;
                border-color: #d4d4d8;
            }
            .cred { background: #f4f4f5; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        @media (max-width: 720px) {
            .slips, .creds { grid-template-columns: 1fr; }
            .sheet { padding: 1rem; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <p><?= (int) $count ?> <?= $count === 1 ? 'slip' : 'slips' ?> · Cut one per student. Passwords are shown only this once.</p>
        <div class="toolbar-actions">
            <button class="btn" type="button" onclick="window.print()">Print</button>
            <a class="btn btn-ghost" href="<?= e(url('admin/student-accounts.php')) ?>">Back</a>
        </div>
    </div>
    <div class="sheet">
        <div class="slips">
            <?php foreach ($rows as $row): ?>
                <?php $studentCode = trim((string) ($row['student_code'] ?? '')); ?>
                <article class="slip">
                    <div class="slip-top">
                        <p class="brand"><?= e($class) ?></p>
                        <p class="meta">Grade <?= e($grade) ?></p>
                    </div>
                    <h2><?= e((string) ($row['full_name'] ?? '')) ?></h2>
                    <p class="school"><?= e($school) ?><?= $studentCode !== '' ? ' · ' . e($studentCode) : '' ?></p>
                    <div class="creds">
                        <div class="cred">
                            <span>Username</span>
                            <b><?= e((string) ($row['username'] ?? '')) ?></b>
                        </div>
                        <div class="cred">
                            <span>Password</span>
                            <b><?= e((string) ($row['password'] ?? '')) ?></b>
                        </div>
                    </div>
                    <p class="url">Sign in at<b><?= e($loginUrl) ?></b></p>
                    <p class="hint">You can also use your full name. Change this password after the first sign-in.</p>
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
