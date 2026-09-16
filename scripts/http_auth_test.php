<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/queries.php';

$base = 'http://localhost/luchador';
$cookie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'luchador_auth_test.txt';
@unlink($cookie);

function http_request(string $url, array $opts = []): array
{
    global $cookie;
    $ch = curl_init($url);
    $headers = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 20,
    ]);
    if (!empty($opts['post'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['post']);
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $header = substr((string) $raw, 0, $headerSize);
    $body = substr((string) $raw, $headerSize);
    return ['code' => $code, 'body' => $body, 'header' => $header, 'error' => $err];
}

function csrf_from(string $html): string
{
    if (preg_match('/name="_csrf" value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

function report(string $name, bool $ok, string $detail = ''): void
{
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
}

$results = [];
$loginPage = http_request($base . '/login.php');
report('login page', $loginPage['code'] === 200, (string) $loginPage['code']);
$csrf = csrf_from($loginPage['body']);
report('login csrf', $csrf !== '', $csrf === '' ? 'missing token' : 'present');

$adminLogin = http_request($base . '/login.php', [
    'post' => http_build_query([
        '_csrf' => $csrf,
        'username' => 'admin',
        'password' => 'admin123',
    ]),
]);
$adminOk = $adminLogin['code'] === 302 && str_contains($adminLogin['header'], 'admin/');
if (!$adminOk && $adminLogin['code'] === 200 && str_contains($adminLogin['body'], 'Invalid username or password')) {
    report('super admin login with default password', false, 'default password no longer valid (expected if already changed)');
} else {
    report('super admin login', $adminOk, 'HTTP ' . $adminLogin['code']);
    $dash = http_request($base . '/admin/index.php');
    report('super admin dashboard', $dash['code'] === 200, (string) $dash['code']);
    $users = http_request($base . '/admin/users.php');
    report('super admin users page', $users['code'] === 200, (string) $users['code']);
    $roles = http_request($base . '/admin/roles.php');
    report('super admin roles page', $roles['code'] === 200, (string) $roles['code']);
    http_request($base . '/logout.php');
}

$student = db()->query('SELECT * FROM uniforms ORDER BY id ASC LIMIT 1')->fetch();
$other = db()->query('SELECT id FROM uniforms ORDER BY id DESC LIMIT 1')->fetch();
if (!$student) {
    report('student fixture', false, 'no uniform rows');
    exit(1);
}

$username = 'authcheck' . (int) $student['id'];
db()->prepare('DELETE FROM user_permissions WHERE user_id IN (SELECT id FROM (SELECT id FROM users WHERE username = ?) t)')->execute([$username]);
db()->prepare('DELETE FROM users WHERE username = ?')->execute([$username]);
$plain = generate_temp_password(12);
db()->prepare('INSERT INTO users (username, password, must_change_password, full_name, role, is_active, student_id) VALUES (?,?,0,?,?,1,?)')
    ->execute([$username, password_hash($plain, PASSWORD_DEFAULT), $student['student_name'], 'student', (int) $student['id']]);

@unlink($cookie);
$loginPage = http_request($base . '/login.php');
$csrf = csrf_from($loginPage['body']);
$stLogin = http_request($base . '/login.php', [
    'post' => http_build_query([
        '_csrf' => $csrf,
        'username' => $username,
        'password' => $plain,
    ]),
]);
$studentLoggedIn = $stLogin['code'] === 302 && str_contains($stLogin['header'], 'student/');
report('student login', $studentLoggedIn, 'HTTP ' . $stLogin['code']);
$stDash = http_request($base . '/student/dashboard.php');
report('student dashboard', $stDash['code'] === 200 && str_contains($stDash['body'], 'Student portal'), (string) $stDash['code']);
$adminAsStudent = http_request($base . '/admin/index.php');
report('student blocked from admin dashboard', $adminAsStudent['code'] === 403, 'HTTP ' . $adminAsStudent['code']);
$usersAsStudent = http_request($base . '/admin/users.php');
report('student blocked from users', $usersAsStudent['code'] === 403, 'HTTP ' . $usersAsStudent['code']);
$apiAsStudent = http_request($base . '/api/uniforms.php');
report('student blocked from staff API', $apiAsStudent['code'] === 403, 'HTTP ' . $apiAsStudent['code']);
$idor = http_request($base . '/student/profile.php?id=' . (int) $other['id']);
report('student IDOR blocked', $idor['code'] === 403, 'HTTP ' . $idor['code']);
$own = http_request($base . '/student/profile.php');
report('student own profile', $own['code'] === 200, 'HTTP ' . $own['code']);

http_request($base . '/logout.php');
$afterLogout = http_request($base . '/student/dashboard.php');
report('student session cleared after logout', $afterLogout['code'] === 302, 'HTTP ' . $afterLogout['code']);

$userId = (int) db()->query("SELECT id FROM users WHERE username = " . db()->quote($username))->fetchColumn();
db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$userId]);
@unlink($cookie);
$loginPage = http_request($base . '/login.php');
$csrf = csrf_from($loginPage['body']);
$disabled = http_request($base . '/login.php', [
    'post' => http_build_query([
        '_csrf' => $csrf,
        'username' => $username,
        'password' => $plain,
    ]),
]);
report('disabled student cannot login', $disabled['code'] === 200 && str_contains($disabled['body'], 'currently disabled'), 'HTTP ' . $disabled['code']);

$bad = http_request($base . '/login.php', [
    'post' => http_build_query([
        '_csrf' => csrf_from(http_request($base . '/login.php')['body']),
        'username' => $username,
        'password' => 'wrong-password-value',
    ]),
]);
report('invalid login is generic', $bad['code'] === 200 && str_contains($bad['body'], 'Invalid username or password') && !str_contains($bad['body'], 'currently disabled'), 'HTTP ' . $bad['code']);

db()->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$userId]);
db()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
$uniforms = (int) db()->query('SELECT COUNT(*) FROM uniforms')->fetchColumn();
report('uniforms still 166 after test cleanup', $uniforms === 166, (string) $uniforms);

$committee = db()->query("SELECT id, username FROM users WHERE role = 'committee' ORDER BY id ASC")->fetchAll();
report('five committee accounts exist', count($committee) >= 5, (string) count($committee));
if ($committee) {
    $cid = (int) $committee[0]['id'];
    $cuser = (string) $committee[0]['username'];
    $old = db()->query('SELECT password, must_change_password FROM users WHERE id = ' . $cid)->fetch();
    $temp = generate_temp_password(12);
    db()->prepare('UPDATE users SET password = ?, must_change_password = 0, is_active = 1 WHERE id = ?')
        ->execute([password_hash($temp, PASSWORD_DEFAULT), $cid]);
    @unlink($cookie);
    $loginPage = http_request($base . '/login.php');
    $csrf = csrf_from($loginPage['body']);
    $cLogin = http_request($base . '/login.php', [
        'post' => http_build_query(['_csrf' => $csrf, 'username' => $cuser, 'password' => $temp]),
    ]);
    report('committee login', $cLogin['code'] === 302 && str_contains($cLogin['header'], 'admin/'), 'HTTP ' . $cLogin['code']);
    $cDash = http_request($base . '/admin/index.php');
    report('committee dashboard with default ACL', $cDash['code'] === 200, (string) $cDash['code']);
    $cUsers = http_request($base . '/admin/users.php');
    report('committee blocked from users', $cUsers['code'] === 403, 'HTTP ' . $cUsers['code']);
    $cStudents = http_request($base . '/admin/students.php');
    report('committee blocked from students without permission', $cStudents['code'] === 403, 'HTTP ' . $cStudents['code']);
    $cStudentPortal = http_request($base . '/student/dashboard.php');
    report('committee blocked from student portal', $cStudentPortal['code'] === 403, 'HTTP ' . $cStudentPortal['code']);
    http_request($base . '/logout.php');
    db()->prepare('UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?')
        ->execute([$old['password'], $cid]);
}

$last = (int) db()->query("SELECT id FROM users WHERE role = 'super_admin' AND is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
report('last super admin protection helper', is_last_active_super_admin($last), 'id ' . $last);
report('uniforms final', ((int) db()->query('SELECT COUNT(*) FROM uniforms')->fetchColumn()) === 166);

@unlink($cookie);
