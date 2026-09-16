<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Restricted · <?= e(class_name()) ?></title>
    <link rel="icon" href="<?= e(url('assets/images/favicon.svg')) ?>" type="image/svg+xml">
    <?php site_font_links(); ?>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=30">
</head>
<body class="auth-body">
    <main class="auth-card forbidden-card">
        <p class="eyebrow"><?= e(class_name()) ?></p>
        <p class="forbidden-code">403</p>
        <h1>Access Restricted</h1>
        <p class="muted"><?= e($forbiddenMessage ?? 'You don\'t have permission to access this area.') ?></p>
        <p style="margin-top:1.2rem">
            <a class="btn" href="<?= e($forbiddenHome ?? url('login.php')) ?>"><?= e($forbiddenLabel ?? 'Sign in') ?></a>
        </p>
    </main>
</body>
</html>
