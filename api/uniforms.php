<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/queries.php';
require_once dirname(__DIR__) . '/includes/students.php';

require_staff();
if (request_method() === 'GET') {
    require_permission_any(['students.view', 'uniforms.view']);
} elseif (request_method() === 'POST') {
    $data = json_input();
    if (!$data) {
        $data = $_POST;
    }
    $id = isset($data['id']) && $data['id'] !== '' ? (int) $data['id'] : null;
    require_permission($id ? 'students.edit' : 'students.create');
} elseif (request_method() === 'DELETE') {
    require_permission('students.delete');
}
header('X-Content-Type-Options: nosniff');

try {
    if (request_method() === 'GET') {
        json_response(fetch_students([
            'search' => trim((string) ($_GET['search'] ?? '')),
            'size' => trim((string) ($_GET['size'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'sort' => trim((string) ($_GET['sort'] ?? 'newest')),
        ]));
    }

    if (request_method() === 'POST') {
        require_csrf();
        $data = json_input();
        if (!$data) {
            $data = $_POST;
        }
        $id = isset($data['id']) && $data['id'] !== '' ? (int) $data['id'] : null;
        $saved = save_student($data, $id);
        log_audit($id ? 'student.update' : 'student.create', 'student', (int) $saved['id'], $saved['student_name']);
        json_response($saved);
    }

    if (request_method() === 'DELETE') {
        require_csrf();
        $id = request_int('id');
        if ($id <= 0) {
            json_response(['error' => 'ID is required'], 400);
        }
        $existing = student_by_id($id);
        if (!delete_student_record($id)) {
            json_response(['error' => 'Record not found or could not be deleted'], 404);
        }
        log_audit('student.delete', 'student', $id, $existing['student_name'] ?? '');
        json_response(['success' => true, 'message' => 'Record deleted']);
    }

    json_response(['error' => 'Method not allowed'], 405);
} catch (InvalidArgumentException $e) {
    json_response(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    app_log('uniforms API: ' . $e->getMessage());
    json_response(['error' => 'Something went wrong. Please try again.'], 500);
}
