<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/queries.php';

$out = [];
$out['uniforms'] = (int) db()->query('SELECT COUNT(*) FROM uniforms')->fetchColumn();
$out['student_id_column'] = column_exists(db(), 'users', 'student_id') ? 'yes' : 'no';
$out['roles'] = table_exists(db(), 'roles')
    ? db()->query('SELECT slug, name FROM roles ORDER BY id')->fetchAll()
    : [];
$out['committee_users'] = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'committee'")->fetchColumn();
$out['super_admins'] = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn();
$out['student_users'] = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
$out['linked_accounts'] = column_exists(db(), 'users', 'student_id')
    ? (int) db()->query('SELECT COUNT(*) FROM users WHERE student_id IS NOT NULL')->fetchColumn()
    : 0;
$out['permissions'] = table_exists(db(), 'permissions')
    ? (int) db()->query('SELECT COUNT(*) FROM permissions')->fetchColumn()
    : 0;
$dup = 0;
if (column_exists(db(), 'users', 'student_id')) {
    $dup = (int) db()->query('SELECT COUNT(*) FROM (SELECT student_id FROM users WHERE student_id IS NOT NULL GROUP BY student_id HAVING COUNT(*) > 1) t')->fetchColumn();
}
$out['duplicate_student_links'] = $dup;
echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
