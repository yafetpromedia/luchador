<?php

declare(strict_types=1);

function app_log(string $message): void
{
    $dir = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__)) . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = date('c') . ' ' . $message . PHP_EOL;
    @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        send_security_headers();
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
    send_security_headers();

    $idleSeconds = 8 * 3600;
    $now = time();
    if (!empty($_SESSION['user_id'])) {
        $last = (int) ($_SESSION['last_activity'] ?? $now);
        if (($now - $last) > $idleSeconds) {
            $_SESSION = [];
            $_SESSION['_flash']['error'] = 'Your session expired. Please sign in again.';
        }
    }
    $_SESSION['last_activity'] = $now;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $docRoot = isset($_SERVER['DOCUMENT_ROOT'])
        ? rtrim(str_replace('\\', '/', (string) $_SERVER['DOCUMENT_ROOT']), '/')
        : '';
    $appRoot = str_replace('\\', '/', defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__));

    if ($docRoot !== '' && str_starts_with($appRoot, $docRoot)) {
        $path = substr($appRoot, strlen($docRoot));
        $base = $path === '' ? '' : $path;
    } else {
        $base = '';
    }

    return $base;
}

function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $base = rtrim(base_url(), '/');
    if ($path === '') {
        return $base === '' ? '/' : $base . '/';
    }
    return ($base === '' ? '' : $base) . '/' . $path;
}

function redirect(string $path): void
{
    if (preg_match('#^https?://#i', $path)) {
        header('Location: ' . $path);
    } else {
        header('Location: ' . url($path));
    }
    exit;
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function is_post(): bool
{
    return request_method() === 'POST';
}

function json_input(): array
{
    static $data = null;
    if (is_array($data)) {
        return $data;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        $data = [];
        return $data;
    }
    $decoded = json_decode($raw, true);
    $data = is_array($decoded) ? $decoded : [];
    return $data;
}

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data);
    exit;
}

function fail_public(string $message = 'Something went wrong. Please try again.', int $status = 500): void
{
    json_response(['error' => $message], $status);
}

function flash_set(string $key, $value): void
{
    $_SESSION['_flash'][$key] = $value;
}

function flash_get(string $key, $default = null)
{
    if (!isset($_SESSION['_flash'][$key])) {
        return $default;
    }
    $value = $_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);
    return $value;
}

function current_page(): string
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    return pathinfo($script, PATHINFO_FILENAME);
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
    );
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

function add_index_if_missing(PDO $pdo, string $table, string $index, string $ddl): void
{
    if (index_exists($pdo, $table, $index)) {
        return;
    }
    try {
        $pdo->exec($ddl);
    } catch (Throwable $e) {
        app_log('index ' . $index . ': ' . $e->getMessage());
    }
}

function generate_temp_password(int $length = 12): string
{
    $length = max(10, $length);
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $max = strlen($alphabet) - 1;
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }
    return $password;
}

function password_min_length(): int
{
    return 10;
}

function client_ip(): ?string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}

function setting_defaults(): array
{
    return [
        'class_name' => 'LUCHADOR',
        'grade' => '12',
        'school_name' => 'Bright Side International School',
        'academic_year' => '',
        'contact_email' => 'luchadore@brightside.edu',
        'contact_phone' => '(123) 456-7890',
        'contact_location' => '',
        'graduation_date' => '',
        'graduation_title' => 'Graduation',
        'graduation_message' => '',
        'countdown_enabled' => '0',
        'hero_tagline' => 'One class. One journey. One final chapter.',
        'hero_message' => 'The senior year of Luchador at Bright Side International School — a shared record of the people, work, and moments that close this chapter.',
        'about_who' => 'Luchador is the Grade 12 class of Bright Side International School. This portal is the digital home of the senior class: a public record of identity, events, and leadership, and a working space for class organization.',
        'about_community' => 'We are a diverse group of students with different talents and interests, united as one class. Luchador is a community first — classmates, officers, and friends moving through the same final year together.',
        'about_academic' => 'Senior year is demanding. Luchador aims for academic excellence while keeping a healthy balance: focused work, mutual support, and a finish that the class can be proud of.',
        'class_message' => '',
        'hero_image' => '',
        'payment_purpose' => '',
        'payment_amount' => '',
        'payment_instructions' => '',
    ];
}

function format_date(?string $date, string $format = 'F j, Y'): string
{
    if (!$date) {
        return '';
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return '';
    }
    return date($format, $ts);
}

function format_when(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '';
    }
    $day = date('Y-m-d', $ts);
    $time = date('g:i A', $ts);
    if ($day === date('Y-m-d')) {
        return 'Today, ' . $time;
    }
    if ($day === date('Y-m-d', strtotime('-1 day'))) {
        return 'Yesterday, ' . $time;
    }
    return date('M j, Y', $ts) . ', ' . $time;
}

function status_label(string $status): string
{
    $map = [
        'pending' => 'Pending',
        'ordered' => 'Ordered',
        'ready' => 'Ready',
        'collected' => 'Collected',
        'upcoming' => 'Upcoming',
        'ongoing' => 'Ongoing',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'published' => 'Published',
        'draft' => 'Draft',
        'unpaid' => 'Unpaid',
        'partial' => 'Partial',
        'paid' => 'Paid',
        'scheduled' => 'Scheduled',
        'active' => 'Active',
        'closed' => 'Closed',
        'open' => 'Open',
        'hidden' => 'Hidden',
        'visible' => 'Visible',
        'qa' => 'Ask committee',
        'suggestion' => 'Suggestion',
        'choices' => 'Choices',
        'open_question' => 'Open question',
        'academic' => 'Academic',
        'competition' => 'Competition',
        'community' => 'Community',
        'class' => 'Class',
        'social' => 'Social',
        'graduation' => 'Graduation',
        'important' => 'Important',
        'student_of_month' => 'Student of the Month',
        'leadership' => 'Leadership',
        'creative' => 'Creative Achievement',
        'president' => 'Class President',
        'committee' => 'Committee',
        'teacher' => 'Teacher',
        'start' => 'Start of Grade 12',
        'semester' => 'Semester',
        'event' => 'Major event',
        'exams' => 'Exams',
        'next' => 'The next chapter',
        'milestone' => 'Milestone',
    ];
    return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function uniform_sizes(): array
{
    return ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
}

function greeting(): string
{
    $hour = (int) date('G');
    if ($hour < 12) {
        return 'Good morning';
    }
    if ($hour < 18) {
        return 'Good afternoon';
    }
    return 'Good evening';
}

function first_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    return (string) ($parts[0] ?? $name);
}

function person_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/u', $name) ?: [];
    $letter = static function (string $part): string {
        $part = trim($part);
        if ($part === '') {
            return '';
        }
        if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
            return mb_strtoupper(mb_substr($part, 0, 1));
        }
        return strtoupper(substr($part, 0, 1));
    };
    $out = $letter((string) ($parts[0] ?? ''));
    if (count($parts) > 1) {
        $out .= $letter((string) $parts[count($parts) - 1]);
    }
    return $out !== '' ? $out : '?';
}

function format_relative(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    $ts = strtotime(strlen($datetime) <= 10 ? $datetime . ' 12:00:00' : $datetime);
    if ($ts === false) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < -120) {
        return format_date(date('Y-m-d', $ts));
    }
    if ($diff < 3600) {
        $mins = max(1, (int) floor($diff / 60));
        return $mins === 1 ? '1 minute ago' : $mins . ' minutes ago';
    }
    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);
        return $hours === 1 ? '1 hour ago' : $hours . ' hours ago';
    }
    if ($diff < 172800) {
        return 'Yesterday';
    }
    if ($diff < 7 * 86400) {
        $days = (int) floor($diff / 86400);
        return $days . ' days ago';
    }
    return format_date(date('Y-m-d', $ts));
}

function pagination(int $total, int $page, int $perPage): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;
    return compact('total', 'page', 'perPage', 'pages', 'offset');
}

function safe_redirect_path(string $path, string $fallback = 'admin/index.php'): string
{
    $path = ltrim($path, '/');
    if ($path === '' || str_contains($path, '..') || str_contains($path, '://') || str_starts_with($path, '//')) {
        return $fallback;
    }
    if (str_starts_with($path, 'admin/') || str_starts_with($path, 'student/')) {
        return $path;
    }
    return $fallback;
}

function current_year(): int
{
    return (int) date('Y');
}

function request_int(string $key, int $default = 0): int
{
    if (is_post() && isset($_POST[$key]) && $_POST[$key] !== '') {
        return (int) $_POST[$key];
    }
    if (isset($_GET[$key]) && $_GET[$key] !== '') {
        return (int) $_GET[$key];
    }
    return $default;
}

function request_str(string $key, string $default = ''): string
{
    if (is_post() && isset($_POST[$key])) {
        return trim((string) $_POST[$key]);
    }
    if (isset($_GET[$key])) {
        return trim((string) $_GET[$key]);
    }
    return $default;
}

function days_until(?string $date): ?int
{
    if (!$date) {
        return null;
    }
    $ts = strtotime($date . ' 00:00:00');
    if ($ts === false) {
        return null;
    }
    $today = strtotime(date('Y-m-d') . ' 00:00:00');
    return (int) floor(($ts - $today) / 86400);
}

function countdown_parts(?string $date): ?array
{
    if (!$date) {
        return null;
    }
    $ts = strtotime($date . ' 00:00:00');
    if ($ts === false) {
        return null;
    }
    $diff = $ts - time();
    $remain = max(0, $diff);
    return [
        'iso' => date('c', $ts),
        'done' => $diff <= 0,
        'days' => intdiv($remain, 86400),
        'hours' => (int) floor(($remain % 86400) / 3600),
        'minutes' => (int) floor(($remain % 3600) / 60),
        'seconds' => (int) ($remain % 60),
        'date' => $date,
    ];
}

function render_countdown(string $date, string $variant = 'full'): void
{
    $parts = countdown_parts($date);
    if (!$parts) {
        return;
    }
    $class = 'countdown';
    if ($variant === 'compact') {
        $class .= ' is-compact';
    }
    if ($variant === 'on-dark') {
        $class .= ' is-compact is-on-dark';
    }
    if ($parts['done']) {
        $class .= ' is-done';
    }
    ?>
    <div class="<?= e($class) ?>" data-countdown="<?= e($parts['iso']) ?>" role="timer" aria-live="polite" aria-label="Time until <?= e(format_date($date)) ?>">
        <p class="countdown-done" data-countdown-done <?= $parts['done'] ? '' : 'hidden' ?>>Graduation day</p>
        <div class="countdown-grid" data-countdown-live <?= $parts['done'] ? 'hidden' : '' ?>>
            <div class="count"><b data-days><?= (int) $parts['days'] ?></b><span>Days</span></div>
            <div class="count"><b data-hours><?= sprintf('%02d', $parts['hours']) ?></b><span>Hours</span></div>
            <div class="count"><b data-minutes><?= sprintf('%02d', $parts['minutes']) ?></b><span>Minutes</span></div>
            <div class="count"><b data-seconds><?= sprintf('%02d', $parts['seconds']) ?></b><span>Seconds</span></div>
        </div>
        <p class="countdown-date"><?= e(format_date($date)) ?></p>
    </div>
    <?php
}

function posted(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function contact_tel_href(string $phone): string
{
    $digits = preg_replace('/[^\d+]/', '', $phone) ?? '';
    return $digits !== '' ? 'tel:' . $digits : '';
}

function excerpt(?string $text, int $length = 140): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $length, '…');
    }
    return strlen($text) > $length ? substr($text, 0, $length - 1) . '…' : $text;
}

function site_font_links(): void
{
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    echo '    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    echo '    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;1,14..32,400&display=swap" rel="stylesheet">' . "\n";
}

function redirect_class_section(string $id, array $query = []): void
{
    $path = 'index.php';
    if ($query) {
        $path .= '?' . http_build_query($query);
    }
    redirect($path . '#' . $id);
}
