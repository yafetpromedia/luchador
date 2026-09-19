<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/queries.php';

if (is_logged_in()) {
    redirect(post_login_path(current_user(), (string) ($_GET['next'] ?? '')));
}

$error = flash_get('error', '');
$requested = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
$next = safe_redirect_path($requested, 'admin/index.php');

if (is_post()) {
    require_csrf();
    if (login_is_locked()) {
        $error = t('auth.locked');
    } else {
        try {
            $user = authenticate_credentials(posted('username'), (string) ($_POST['password'] ?? ''));
            login_user($user);
            if (hash_is_default_password((string) $user['password'])) {
                mark_password_change_required((int) $user['id']);
                $_SESSION['must_change_password'] = true;
                $user['must_change_password'] = true;
            }
            $detail = user_display_name($user);
            if (($user['role'] ?? '') === 'student' && !empty($user['student_id'])) {
                $linked = student_by_id((int) $user['student_id']);
                if ($linked && !empty($linked['student_code'])) {
                    $detail = 'Student ID ' . $linked['student_code'];
                }
            }
            log_audit('user.login', 'user', (int) $user['id'], $detail);
            redirect(post_login_path($user, $requested));
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'disabled') {
                $error = t('auth.disabled');
            } elseif ($e->getMessage() === 'not_found') {
                $error = t('auth.not_found');
            } else {
                $error = t('auth.invalid');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(t('auth.title')) ?> · <?= e(class_name()) ?></title>
    <link rel="icon" href="<?= e(url('assets/images/favicon.svg')) ?>" type="image/svg+xml">
    <?php site_font_links(); ?>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=59">
</head>
<body class="auth-body">
    <?php render_language_switcher('auth-lang'); ?>
    <div class="auth-motion" aria-hidden="true">
        <span class="auth-mark"><?= e(class_grade()) ?></span>
        <span class="auth-glow auth-glow-a"></span>
        <span class="auth-glow auth-glow-b"></span>
    </div>
    <main class="auth-shell">
        <section class="auth-intro">
            <a class="brand" href="<?= e(url('index.php')) ?>">
                <span class="brand-text">
                    <strong><?= e(class_name()) ?></strong>
                    <span><?= e(t('grade', ['grade' => class_grade()])) ?></span>
                </span>
            </a>
            <p class="auth-kicker"><?= e(school_name()) ?></p>
            <h1><?= e(t('auth.welcome')) ?></h1>
            <p class="auth-lede"><?= e(t('auth.lede', ['grade' => class_grade()])) ?></p>
        </section>
        <section class="auth-card">
            <h2><?= e(t('auth.title')) ?></h2>
            <?php if ($error): ?>
                <div class="alert alert-error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post" data-loading>
                <?= csrf_field() ?>
                <input type="hidden" name="next" value="<?= e($next) ?>">
                <div class="form-group">
                    <label for="username"><?= e(t('auth.username')) ?></label>
                    <input id="username" name="username" type="text" autocomplete="username" autocapitalize="off" spellcheck="false" required autofocus placeholder="<?= e(t('auth.username_ph')) ?>" value="<?= e(is_post() ? posted('username') : '') ?>">
                </div>
                <div class="form-group">
                    <label for="password"><?= e(t('auth.password')) ?></label>
                    <div class="password-field">
                        <input id="password" name="password" type="password" autocomplete="current-password" required>
                        <button type="button" class="password-toggle" data-password-toggle aria-controls="password" aria-pressed="false" aria-label="<?= e(t('auth.show_password')) ?>">
                            <span data-show><?= icon('eye', 18) ?></span>
                            <span data-hide hidden><?= icon('eye-off', 18) ?></span>
                        </button>
                    </div>
                </div>
                <button class="btn auth-submit" type="submit"><?= e(t('auth.submit')) ?></button>
            </form>
            <p class="auth-hint"><?= e(t('auth.hint')) ?></p>
            <?php if (!app_is_production()): ?>
                <p class="auth-hint"><?= e(t('auth.local_copy')) ?> <a href="https://luchador.yafetpromedia.com/login.php">luchador.yafetpromedia.com/login.php</a>.</p>
            <?php endif; ?>
            <p><a class="text-link" href="<?= e(url('index.php')) ?>"><?= e(t('auth.back')) ?> <?= icon('arrow-right', 16) ?></a></p>
        </section>
    </main>
    <script src="<?= e(url('assets/js/app.js')) ?>?v=12"></script>
</body>
</html>
