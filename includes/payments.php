<?php

declare(strict_types=1);

function payment_methods(): array
{
    return [
        'bank' => 'Bank transfer',
        'telebirr' => 'Telebirr',
        'cbe_birr' => 'CBE Birr',
        'other' => 'Other',
    ];
}

function payment_method_label(string $method): string
{
    return payment_methods()[$method] ?? 'Payment';
}

function format_etb(null|string|float|int $amount): string
{
    if ($amount === null || $amount === '') {
        return '';
    }
    if (!is_numeric($amount)) {
        return '';
    }
    return 'ETB ' . number_format((float) $amount, 2);
}

function payment_reference_key(string $raw): string
{
    $key = strtoupper(trim($raw));
    $key = preg_replace('/[\s\-_.]+/', '', $key) ?? '';
    return excerpt($key, 80);
}

function payment_file_sha256(string $path): ?string
{
    if ($path === '' || !is_file($path)) {
        return null;
    }
    $hash = hash_file('sha256', $path);
    return is_string($hash) && $hash !== '' ? $hash : null;
}

function payment_receipt_hash_from_path(string $relativePath): ?string
{
    $relativePath = str_replace('\\', '/', trim($relativePath));
    if ($relativePath === '' || str_contains($relativePath, '..') || !str_starts_with($relativePath, 'uploads/')) {
        return null;
    }
    return payment_file_sha256(APP_ROOT . '/' . $relativePath);
}

function payment_proof_columns_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $pdo = db();
    $ready = table_exists($pdo, 'payment_requests')
        && column_exists($pdo, 'payment_requests', 'reference_key')
        && column_exists($pdo, 'payment_requests', 'receipt_hash');
    return $ready;
}

function payment_proof_conflicts(
    int $studentId,
    string $referenceKey,
    ?string $receiptHash,
    ?int $excludeRequestId = null,
    array $statuses = ['pending', 'approved', 'rejected']
): array {
    if ($studentId <= 0 || !payment_proof_columns_ready()) {
        return [];
    }
    $allowed = ['pending', 'approved', 'rejected', 'cancelled'];
    $statuses = array_values(array_intersect($statuses, $allowed));
    if ($statuses === []) {
        return [];
    }

    $conflicts = [];
    $in = implode(',', array_fill(0, count($statuses), '?'));
    $excludeSql = $excludeRequestId ? ' AND r.id <> ?' : '';

    if ($referenceKey !== '') {
        $sql = "SELECT r.id, r.student_id, r.status, r.reference, 'reference' AS conflict, u.student_name, u.student_code
                FROM payment_requests r
                INNER JOIN uniforms u ON u.id = r.student_id
                WHERE r.student_id <> ?
                  AND r.status IN ($in)
                  AND r.reference_key = ?
                  $excludeSql
                ORDER BY r.id ASC LIMIT 1";
        $params = array_merge([$studentId], $statuses, [$referenceKey]);
        if ($excludeRequestId) {
            $params[] = $excludeRequestId;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row) {
            $conflicts[] = $row;
        }
    }

    $hash = trim((string) $receiptHash);
    if ($hash !== '') {
        $sql = "SELECT r.id, r.student_id, r.status, r.reference, 'receipt' AS conflict, u.student_name, u.student_code
                FROM payment_requests r
                INNER JOIN uniforms u ON u.id = r.student_id
                WHERE r.student_id <> ?
                  AND r.status IN ($in)
                  AND r.receipt_hash = ?
                  $excludeSql
                ORDER BY r.id ASC LIMIT 1";
        $params = array_merge([$studentId], $statuses, [$hash]);
        if ($excludeRequestId) {
            $params[] = $excludeRequestId;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row) {
            $conflicts[] = $row;
        }
    }

    return $conflicts;
}

function payment_request_conflict_messages(array $request): array
{
    $conflicts = payment_proof_conflicts(
        (int) ($request['student_id'] ?? 0),
        trim((string) ($request['reference_key'] ?? '')),
        trim((string) ($request['receipt_hash'] ?? '')) ?: null,
        (int) ($request['id'] ?? 0) ?: null
    );
    $messages = [];
    foreach ($conflicts as $row) {
        $who = trim((string) ($row['student_name'] ?? 'Another student'));
        $code = trim((string) ($row['student_code'] ?? ''));
        if ($code !== '') {
            $who .= ' (ID ' . $code . ')';
        }
        $state = (string) ($row['status'] ?? 'pending');
        if (($row['conflict'] ?? '') === 'receipt') {
            $messages[] = 'This receipt photo was already sent by ' . $who . ' (' . $state . ').';
        } else {
            $messages[] = 'This transaction ID was already sent by ' . $who . ' (' . $state . ').';
        }
    }
    return $messages;
}

function assert_unique_payment_proof(array $conflicts, bool $forStudent = true): void
{
    if (!$conflicts) {
        return;
    }
    $kinds = array_unique(array_map(static fn(array $row): string => (string) ($row['conflict'] ?? ''), $conflicts));
    if ($forStudent) {
        if (in_array('receipt', $kinds, true) && in_array('reference', $kinds, true)) {
            throw new InvalidArgumentException('This receipt and transaction ID are already on file for another student. Send your own payment proof.');
        }
        if (in_array('receipt', $kinds, true)) {
            throw new InvalidArgumentException('This receipt photo was already sent by another student. Upload your own transfer screenshot.');
        }
        throw new InvalidArgumentException('This transaction ID is already on file for another student. Use the ID from your own receipt.');
    }
    $first = $conflicts[0];
    $who = trim((string) ($first['student_name'] ?? 'another student'));
    if (($first['conflict'] ?? '') === 'receipt') {
        throw new InvalidArgumentException('This receipt photo was already approved for ' . $who . '. Reject the duplicate first.');
    }
    throw new InvalidArgumentException('This transaction ID was already approved for ' . $who . '. Reject the duplicate first.');
}

function parse_payment_amount(string $raw): ?string
{
    $raw = trim(str_replace([',', ' '], '', $raw));
    if ($raw === '') {
        return null;
    }
    if (!is_numeric($raw)) {
        throw new InvalidArgumentException('Enter a valid amount.');
    }
    $value = round((float) $raw, 2);
    if ($value < 0 || $value > 999999.99) {
        throw new InvalidArgumentException('Enter a realistic payment amount.');
    }
    return number_format($value, 2, '.', '');
}

function payment_accounts(bool $activeOnly = false): array
{
    if (!table_exists(db(), 'payment_accounts')) {
        return [];
    }
    $sql = 'SELECT * FROM payment_accounts';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY display_order ASC, id ASC';
    return db()->query($sql)->fetchAll();
}

function payment_account_by_id(int $id): ?array
{
    if ($id <= 0 || !table_exists(db(), 'payment_accounts')) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM payment_accounts WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function payment_account_snapshot(array $account): string
{
    $parts = [
        trim((string) ($account['label'] ?? '')),
        trim((string) ($account['account_name'] ?? '')),
        trim((string) ($account['account_number'] ?? '')),
    ];
    return excerpt(implode(' · ', array_filter($parts)), 180);
}

function save_payment_account(array $src, ?int $id = null): array
{
    $label = trim((string) ($src['label'] ?? ''));
    $number = trim((string) ($src['account_number'] ?? ''));
    $method = (string) ($src['method'] ?? 'bank');
    if (!isset(payment_methods()[$method])) {
        $method = 'other';
    }
    if ($label === '' || $number === '') {
        throw new InvalidArgumentException('Account label and account number are required.');
    }
    $params = [
        $method,
        excerpt($label, 120),
        excerpt(trim((string) ($src['account_name'] ?? '')), 120) ?: null,
        excerpt($number, 80),
        excerpt(trim((string) ($src['notes'] ?? '')), 240) ?: null,
        max(0, (int) ($src['display_order'] ?? 0)),
        empty($src['is_active']) ? 0 : 1,
    ];
    if ($id) {
        $existing = payment_account_by_id($id);
        if (!$existing) {
            throw new InvalidArgumentException('Payment account not found.');
        }
        $params[] = $id;
        db()->prepare(
            'UPDATE payment_accounts SET method=?, label=?, account_name=?, account_number=?, notes=?, display_order=?, is_active=? WHERE id=?'
        )->execute($params);
        return payment_account_by_id($id) ?? $existing;
    }
    db()->prepare(
        'INSERT INTO payment_accounts (method, label, account_name, account_number, notes, display_order, is_active) VALUES (?,?,?,?,?,?,?)'
    )->execute($params);
    return payment_account_by_id((int) db()->lastInsertId()) ?? [];
}

function delete_payment_account(int $id): void
{
    $existing = payment_account_by_id($id);
    if (!$existing) {
        throw new InvalidArgumentException('Payment account not found.');
    }
    db()->prepare('DELETE FROM payment_accounts WHERE id = ?')->execute([$id]);
}

function save_payment_instructions(array $src): void
{
    $amount = parse_payment_amount((string) ($src['payment_amount'] ?? ''));
    save_settings([
        'payment_purpose' => excerpt(trim((string) ($src['payment_purpose'] ?? '')), 120),
        'payment_amount' => $amount ?? '',
        'payment_instructions' => excerpt(trim((string) ($src['payment_instructions'] ?? '')), 600),
    ]);
}

function pending_payment_request(int $studentId): ?array
{
    if ($studentId <= 0 || !table_exists(db(), 'payment_requests')) {
        return null;
    }
    $stmt = db()->prepare("SELECT * FROM payment_requests WHERE student_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function latest_payment_request(int $studentId): ?array
{
    if ($studentId <= 0 || !table_exists(db(), 'payment_requests')) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM payment_requests WHERE student_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function pending_payment_requests(): array
{
    if (!table_exists(db(), 'payment_requests')) {
        return [];
    }
    return db()->query(
        "SELECT r.*, u.student_name, u.student_code, u.payment_status AS current_payment, u.photo AS student_photo
         FROM payment_requests r
         INNER JOIN uniforms u ON u.id = r.student_id
         WHERE r.status = 'pending'
         ORDER BY r.id ASC"
    )->fetchAll();
}

function pending_payment_request_count(): int
{
    if (!table_exists(db(), 'payment_requests')) {
        return 0;
    }
    return (int) db()->query("SELECT COUNT(*) FROM payment_requests WHERE status = 'pending'")->fetchColumn();
}

function cleanup_student_payments(int $studentId): void
{
    if ($studentId <= 0 || !table_exists(db(), 'payment_requests')) {
        return;
    }
    $stmt = db()->prepare('SELECT receipt FROM payment_requests WHERE student_id = ?');
    $stmt->execute([$studentId]);
    foreach ($stmt->fetchAll() as $row) {
        delete_upload((string) ($row['receipt'] ?? ''));
    }
    db()->prepare('DELETE FROM payment_requests WHERE student_id = ?')->execute([$studentId]);
}

function submit_payment_request(array $student, array $src, array $files, int $userId): array
{
    $studentId = (int) ($student['id'] ?? 0);
    if ($studentId <= 0) {
        throw new InvalidArgumentException('Your account is not linked to a class record.');
    }
    if ((string) ($student['payment_status'] ?? 'unpaid') === 'paid') {
        throw new InvalidArgumentException('This payment is already marked paid.');
    }

    $claimed = (string) ($src['claimed_status'] ?? 'paid');
    if (!in_array($claimed, ['paid', 'partial'], true)) {
        $claimed = 'paid';
    }
    $amount = parse_payment_amount((string) ($src['amount'] ?? ''));
    $reference = excerpt(trim((string) ($src['reference'] ?? '')), 80);
    $referenceKey = payment_reference_key($reference);
    if ($reference === '' || strlen($referenceKey) < 4) {
        throw new InvalidArgumentException('Enter the full transaction ID from your own receipt.');
    }
    $note = excerpt(trim((string) ($src['student_note'] ?? '')), 180);

    $accountId = (int) ($src['account_id'] ?? 0);
    $account = $accountId > 0 ? payment_account_by_id($accountId) : null;
    if ($account && empty($account['is_active'])) {
        $account = null;
    }
    $published = payment_accounts(true);
    if ($published && !$account) {
        throw new InvalidArgumentException('Choose the account you paid into.');
    }

    $existing = pending_payment_request($studentId);
    $receipt = $existing['receipt'] ?? null;
    $receiptHash = trim((string) ($existing['receipt_hash'] ?? '')) ?: null;
    if (!empty($files['receipt']['name'])) {
        $receiptHash = payment_file_sha256((string) ($files['receipt']['tmp_name'] ?? ''));
        $upload = store_upload($files['receipt'], 'payments');
        if (!$upload['ok']) {
            throw new InvalidArgumentException($upload['error']);
        }
        if ($receipt && $receipt !== $upload['path']) {
            delete_upload((string) $receipt);
        }
        $receipt = $upload['path'];
        if (!$receiptHash && $receipt) {
            $receiptHash = payment_receipt_hash_from_path((string) $receipt);
        }
    }
    if (!$receipt) {
        throw new InvalidArgumentException('Upload a photo of the receipt or transfer screenshot.');
    }
    if (!$receiptHash && $receipt) {
        $receiptHash = payment_receipt_hash_from_path((string) $receipt);
    }

    assert_unique_payment_proof(
        payment_proof_conflicts(
            $studentId,
            $referenceKey,
            $receiptHash,
            $existing ? (int) $existing['id'] : null
        )
    );

    $snapshot = $account ? payment_account_snapshot($account) : excerpt(trim((string) ($src['account_label'] ?? '')), 180);
    $params = [
        $userId,
        $account ? (int) $account['id'] : null,
        $snapshot !== '' ? $snapshot : null,
        $amount,
        $reference !== '' ? $reference : null,
        $referenceKey !== '' ? $referenceKey : null,
        $receipt,
        $receiptHash,
        $note !== '' ? $note : null,
        $claimed,
        $studentId,
    ];

    if ($existing) {
        db()->prepare(
            "UPDATE payment_requests
             SET user_id=?, account_id=?, account_label=?, amount=?, reference=?, reference_key=?, receipt=?, receipt_hash=?, student_note=?, claimed_status=?, status='pending', note=NULL, reviewed_by=NULL, reviewed_at=NULL
             WHERE id=? AND student_id=?"
        )->execute([
            $params[0], $params[1], $params[2], $params[3], $params[4], $params[5], $params[6], $params[7], $params[8], $params[9],
            (int) $existing['id'],
            $studentId,
        ]);
    } else {
        db()->prepare(
            "INSERT INTO payment_requests (user_id, account_id, account_label, amount, reference, reference_key, receipt, receipt_hash, student_note, claimed_status, student_id, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?, 'pending')"
        )->execute($params);
    }

    $saved = pending_payment_request($studentId);
    if (!$saved) {
        throw new RuntimeException('Could not save the payment proof.');
    }
    return $saved;
}

function cancel_payment_request(int $studentId, int $userId): void
{
    $existing = pending_payment_request($studentId);
    if (!$existing) {
        throw new InvalidArgumentException('There is no payment proof waiting.');
    }
    if ((int) ($existing['user_id'] ?? 0) !== $userId && !can('payments.manage')) {
        throw new InvalidArgumentException('You can only withdraw your own payment proof.');
    }
    delete_upload((string) ($existing['receipt'] ?? ''));
    db()->prepare("UPDATE payment_requests SET status = 'cancelled', receipt = NULL, receipt_hash = NULL, reviewed_at = NOW() WHERE id = ?")->execute([(int) $existing['id']]);
}

function approve_payment_request(int $requestId, string $status = ''): array
{
    $stmt = db()->prepare("SELECT * FROM payment_requests WHERE id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        throw new InvalidArgumentException('That payment proof is no longer waiting.');
    }
    $student = student_by_id((int) $request['student_id']);
    if (!$student) {
        throw new InvalidArgumentException('Student record not found.');
    }

    $status = $status !== '' ? $status : (string) ($request['claimed_status'] ?? 'paid');
    if (!in_array($status, ['paid', 'partial'], true)) {
        $status = 'paid';
    }

    assert_unique_payment_proof(
        payment_proof_conflicts(
            (int) $request['student_id'],
            trim((string) ($request['reference_key'] ?? '')),
            trim((string) ($request['receipt_hash'] ?? '')) ?: null,
            (int) $request['id'],
            ['approved']
        ),
        false
    );

    db()->prepare('UPDATE uniforms SET payment_status = ? WHERE id = ?')->execute([$status, (int) $student['id']]);

    $reviewer = (int) (current_user()['id'] ?? 0) ?: null;
    db()->prepare("UPDATE payment_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), note = NULL WHERE id = ?")
        ->execute([$reviewer, $requestId]);

    $saved = student_by_id((int) $student['id']);
    if (!$saved) {
        throw new RuntimeException('Could not update the payment status.');
    }
    return $saved;
}

function reject_payment_request(int $requestId, string $note = ''): void
{
    $stmt = db()->prepare("SELECT * FROM payment_requests WHERE id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        throw new InvalidArgumentException('That payment proof is no longer waiting.');
    }
    $reviewer = (int) (current_user()['id'] ?? 0) ?: null;
    $note = excerpt($note, 180);
    db()->prepare("UPDATE payment_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), note = ? WHERE id = ?")
        ->execute([$reviewer, $note !== '' ? $note : null, $requestId]);
}
