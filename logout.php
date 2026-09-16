<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    log_audit('user.logout', 'user', (int) current_user()['id'], user_display_name());
}
logout_user();
redirect('login.php');
