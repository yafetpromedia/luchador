<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$query = [];
$month = request_str('month');
$day = request_int('day');
$category = request_str('category');
if ($month !== '') {
    $query['month'] = $month;
}
if ($day > 0) {
    $query['day'] = $day;
}
if ($category !== '' && $category !== 'all') {
    $query['category'] = $category;
}
redirect_class_section('events', $query);
