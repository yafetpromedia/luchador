<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!is_logged_in()) {
    json_response(['authenticated' => false], 401);
}

json_response([
    'authenticated' => true,
    'username' => current_user()['username'] ?? '',
    'name' => user_display_name(),
    'role' => current_user()['role'] ?? '',
    'must_change_password' => !empty(current_user()['must_change_password']),
    'student' => is_student(),
]);
