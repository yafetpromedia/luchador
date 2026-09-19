<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/payments.php';

require_login();
require_password_change();
stream_payment_receipt(request_int('id'));
