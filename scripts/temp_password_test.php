<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/queries.php';

$base = 'http://localhost/luchador';
$cookie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'luchador_tmp_pw.txt';
@unlink($cookie);

function req(string $url, array $post = []): array
{
    global $cookie;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($post) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'header' => substr((string) $raw, 0, $headerSize), 'body' => substr((string) $raw, $headerSize)];
}

$student = db()->query('SELECT * FROM uniforms ORDER BY id ASC LIMIT 1')->fetch();
$username = 'tmpcheck' . (int) $student['id'];
db()->prepare('DELETE FROM users WHERE username = ?')->execute([$username]);
$plain = generate_temp_password(12);
db()->prepare('INSERT INTO users (username, password, must_change_password, full_name, role, is_active, student_id) VALUES (?,?,1,?,?,1,?)')
    ->execute([$username, password_hash($plain, PASSWORD_DEFAULT), $student['student_name'], 'student', (int) $student['id']]);

$page = req($base . '/login.php');
preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m);
$login = req($base . '/login.php', ['_csrf' => $m[1], 'username' => $username, 'password' => $plain]);
echo 'login_redirect=' . $login['code'] . ' loc=' . (preg_match('/Location: (.+)/i', $login['header'], $l) ? trim($l[1]) : '') . PHP_EOL;
$dash = req($base . '/student/dashboard.php');
echo 'dashboard_while_temp=' . $dash['code'] . ' loc=' . (preg_match('/Location: (.+)/i', $dash['header'], $d) ? trim($d[1]) : '') . PHP_EOL;
$account = req($base . '/student/account.php');
echo 'account_page=' . $account['code'] . ' welcome=' . (str_contains($account['body'], 'Welcome to Luchadore') ? 'yes' : 'no') . PHP_EOL;

$id = (int) db()->query('SELECT id FROM users WHERE username = ' . db()->quote($username))->fetchColumn();
db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
echo 'uniforms=' . db()->query('SELECT COUNT(*) FROM uniforms')->fetchColumn() . PHP_EOL;
@unlink($cookie);
