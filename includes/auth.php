<?php

declare(strict_types=1);

function normalize_user_row(array $row, bool $includePassword = false): array
{
    $user = [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'full_name' => (string) ($row['full_name'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'role' => (string) ($row['role'] ?? 'super_admin'),
        'student_id' => isset($row['student_id']) && $row['student_id'] !== null && $row['student_id'] !== ''
            ? (int) $row['student_id']
            : null,
        'is_active' => !array_key_exists('is_active', $row) || (int) $row['is_active'] === 1,
        'must_change_password' => !empty($row['must_change_password']),
        'last_login_at' => $row['last_login_at'] ?? null,
        'created_at' => $row['created_at'] ?? null,
    ];
    if ($includePassword) {
        $user['password'] = (string) ($row['password'] ?? '');
    }
    return $user;
}

function fetch_user_by_id(int $id, bool $includePassword = false): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? normalize_user_row($row, $includePassword) : null;
}

function current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $row = fetch_user_by_id((int) $_SESSION['user_id']);
    if ($row === null || empty($row['is_active'])) {
        return null;
    }
    $row['permissions'] = user_permissions_for((int) $row['id'], (string) $row['role']);
    $user = $row;
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function login_default_next(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_contains($script, '/student/')) {
        return 'student/dashboard.php';
    }
    return 'admin/index.php';
}

function password_change_path(?array $user = null): string
{
    $user = $user ?? current_user();
    if ($user && ($user['role'] ?? '') === 'student') {
        return 'student/account.php';
    }
    return 'admin/settings.php';
}

function post_login_path(?array $user, string $requested = ''): string
{
    if ($user === null) {
        return 'login.php';
    }
    $isStudent = ($user['role'] ?? '') === 'student';
    if (!empty($user['must_change_password']) || !empty($_SESSION['must_change_password'])) {
        return $isStudent ? 'student/account.php' : 'admin/settings.php';
    }
    if ($isStudent) {
        return 'student/dashboard.php';
    }
    $fallback = 'admin/index.php';
    $next = safe_redirect_path($requested, $fallback);
    if (str_starts_with($next, 'student/')) {
        return $fallback;
    }
    return $next;
}

function require_login(): void
{
    if (is_logged_in()) {
        return;
    }
    if (wants_json() || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
        json_response(['error' => 'Unauthorized', 'authenticated' => false], 401);
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $base = base_url();
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    $path = ltrim($path, '/');
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    if ($path !== '' && $query !== '') {
        $path .= '?' . $query;
    }
    $next = $path !== '' ? $path : login_default_next();
    redirect('login.php?next=' . urlencode($next));
}

function require_staff(): void
{
    require_login();
    if (is_student()) {
        deny_access();
    }
}

function require_student(): void
{
    require_login();
    if (!is_student()) {
        deny_access();
    }
}

function require_password_change(): void
{
    $user = current_user();
    if (!$user || empty($user['must_change_password'])) {
        return;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $allowed = ['logout.php', 'logout', 'account.php', 'settings.php'];
    foreach ($allowed as $name) {
        if (str_ends_with($script, '/' . $name) || str_ends_with($script, $name)) {
            return;
        }
    }
    flash_set('error', 'Please change your temporary password before continuing.');
    redirect(password_change_path($user));
}

function current_student(): ?array
{
    $user = current_user();
    if ($user === null || ($user['role'] ?? '') !== 'student') {
        return null;
    }
    $studentId = (int) ($user['student_id'] ?? 0);
    if ($studentId <= 0) {
        return null;
    }
    if (!function_exists('student_by_id')) {
        require_once APP_ROOT . '/includes/queries.php';
    }
    return student_by_id($studentId);
}

function require_own_student(int $requestedId): array
{
    $student = current_student();
    if ($student === null) {
        deny_access();
    }
    if ($requestedId > 0 && (int) $student['id'] !== $requestedId) {
        deny_access();
    }
    return $student;
}

function hash_is_default_password(string $hash): bool
{
    return password_verify('admin123', $hash);
}

function mark_password_change_required(int $userId): void
{
    db()->prepare('UPDATE users SET must_change_password = 1 WHERE id = ?')->execute([$userId]);
}

function change_own_password(string $current, string $new, string $confirm): void
{
    $sessionUser = current_user();
    if ($sessionUser === null) {
        throw new InvalidArgumentException('You must be signed in.');
    }
    $user = find_user_by_username((string) $sessionUser['username']);
    if (!$user || !password_verify($current, (string) $user['password'])) {
        throw new InvalidArgumentException('The current password is incorrect.');
    }
    if (strlen($new) < password_min_length()) {
        throw new InvalidArgumentException('Use a new password with at least ' . password_min_length() . ' characters.');
    }
    if ($new !== $confirm) {
        throw new InvalidArgumentException('The new passwords do not match.');
    }
    $hash = password_hash($new, PASSWORD_DEFAULT);
    db()->prepare('UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?')
        ->execute([$hash, (int) $user['id']]);
    $_SESSION['must_change_password'] = false;
    log_audit('password.update', 'user', (int) $user['id'], user_display_name($sessionUser));
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['must_change_password'] = !empty($user['must_change_password']);
    $_SESSION['last_activity'] = time();
    unset($_SESSION['login_attempts'], $_SESSION['login_lock_until']);
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    if (column_exists(db(), 'users', 'last_login_at')) {
        db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $user['id']]);
    }
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function login_is_locked(): bool
{
    $until = (int) ($_SESSION['login_lock_until'] ?? 0);
    return $until > time();
}

function register_login_failure(): void
{
    $attempts = (int) ($_SESSION['login_attempts'] ?? 0) + 1;
    $_SESSION['login_attempts'] = $attempts;
    if ($attempts >= 8) {
        $_SESSION['login_lock_until'] = time() + 15 * 60;
    }
}

function find_user_by_username(string $username): ?array
{
    $username = normalize_login_input($username);
    if ($username === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    return $row ? normalize_user_row($row, true) : null;
}

function find_user_for_login(string $login): ?array
{
    $login = normalize_login_input($login);
    if ($login === '') {
        return null;
    }
    $slug = ascii_login_slug($login);

    $user = find_user_by_username($login);
    if ($user) {
        return $user;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
    $stmt->execute([$login]);
    $row = $stmt->fetch();
    if ($row) {
        return normalize_user_row($row, true);
    }

    if ($slug !== '' && strtolower($login) !== $slug) {
        $stmt = db()->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if ($row) {
            return normalize_user_row($row, true);
        }
    }

    if (column_exists(db(), 'users', 'full_name')) {
        $stmt = db()->prepare('SELECT * FROM users WHERE full_name IS NOT NULL AND TRIM(full_name) != \'\' AND LOWER(TRIM(full_name)) = LOWER(?)');
        $stmt->execute([$login]);
        $rows = $stmt->fetchAll();
        if (count($rows) === 1) {
            return normalize_user_row($rows[0], true);
        }
    }

    if (!column_exists(db(), 'users', 'student_id') || !table_exists(db(), 'uniforms')) {
        return null;
    }

    $pickUnique = static function (array $rows): ?array {
        return count($rows) === 1 ? normalize_user_row($rows[0], true) : null;
    };

    $stmt = db()->prepare(
        'SELECT u.*
         FROM users u
         INNER JOIN uniforms s ON s.id = u.student_id
         WHERE s.student_code IS NOT NULL
           AND TRIM(s.student_code) != \'\'
           AND LOWER(TRIM(s.student_code)) = LOWER(?)
         LIMIT 2'
    );
    $stmt->execute([$login]);
    $unique = $pickUnique($stmt->fetchAll());
    if ($unique) {
        return $unique;
    }

    $stmt = db()->prepare(
        'SELECT u.*
         FROM users u
         INNER JOIN uniforms s ON s.id = u.student_id
         WHERE LOWER(TRIM(s.student_name)) = LOWER(?)'
    );
    $stmt->execute([$login]);
    $unique = $pickUnique($stmt->fetchAll());
    if ($unique) {
        return $unique;
    }

    $stmt = db()->prepare(
        'SELECT u.*
         FROM users u
         INNER JOIN uniforms s ON s.id = u.student_id
         WHERE LOWER(TRIM(SUBSTRING_INDEX(s.student_name, \' \', 1))) = LOWER(?)'
    );
    $stmt->execute([$login]);
    $unique = $pickUnique($stmt->fetchAll());
    if ($unique) {
        return $unique;
    }

    return find_student_user_by_login_slug($slug);
}

function find_student_user_by_login_slug(string $slug): ?array
{
    if (strlen($slug) < 3) {
        return null;
    }

    $stmt = db()->query(
        'SELECT u.*, s.student_name, s.student_code
         FROM users u
         INNER JOIN uniforms s ON s.id = u.student_id'
    );
    $exact = [];
    $suffix = [];
    foreach ($stmt->fetchAll() as $row) {
        $username = ascii_login_slug((string) ($row['username'] ?? ''));
        $first = ascii_login_slug(first_name((string) ($row['student_name'] ?? '')));
        $full = ascii_login_slug((string) ($row['student_name'] ?? ''));
        $code = ascii_login_slug((string) ($row['student_code'] ?? ''));
        $display = ascii_login_slug((string) ($row['full_name'] ?? ''));
        $values = [$username, $first, $full, $code, $display];
        if (in_array($slug, $values, true)) {
            $exact[] = $row;
            continue;
        }
        if (strlen($slug) < 4) {
            continue;
        }
        foreach ([$username, $first] as $value) {
            if ($value !== '' && $value !== $slug && str_ends_with($value, $slug)) {
                $suffix[] = $row;
                break;
            }
        }
    }

    if (count($exact) === 1) {
        return normalize_user_row($exact[0], true);
    }
    if ($exact === [] && count($suffix) === 1) {
        return normalize_user_row($suffix[0], true);
    }
    return null;
}

function authenticate_credentials(string $username, string $password): array
{
    static $dummyHash = null;
    if ($dummyHash === null) {
        $dummyHash = password_hash('invalid-login-placeholder', PASSWORD_DEFAULT);
    }
    $username = normalize_login_input($username);
    $password = normalize_login_input($password);
    $user = $username !== '' ? find_user_for_login($username) : null;
    $hash = $user['password'] ?? $dummyHash;
    $verified = $user !== null && $password !== '' && password_verify($password, $hash);
    if (!$verified) {
        if ($user === null) {
            password_verify($password !== '' ? $password : 'x', $dummyHash);
        }
        register_login_failure();
        throw new RuntimeException($user === null ? 'not_found' : 'invalid');
    }
    if (empty($user['is_active'])) {
        throw new RuntimeException('disabled');
    }
    return $user;
}
