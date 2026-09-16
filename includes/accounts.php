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
    $base = strlen($code) >= 3 ? strtolower($code) : ('s' . (int) $student['id']);
    $username = $base;
    $n = 2;
    while (username_taken($username)) {
        $username = $base . $n;
        $n++;
        if ($n > 50) {
            $username = 's' . (int) $student['id'] . random_int(10, 99);
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
