<?php

declare(strict_types=1);

function payment_methods(): array
{
    return [
        'bank' => 'Bank transfer',
        'telebirr' => 'Telebirr',
        'cbe_birr' => 'CBE Birr',
        'mobile_money' => 'Mobile money',
        'cash' => 'Cash',
        'other' => 'Other',
    ];
}

function payment_method_label(string $method): string
{
    return payment_methods()[$method] ?? 'Payment';
}

function payment_tx_statuses(): array
{
    return [
        'pending' => 'Pending',
        'verified' => 'Verified',
        'rejected' => 'Rejected',
        'archived' => 'Archived',
        'cancelled' => 'Cancelled',
    ];
}

function payment_reject_reasons(): array
{
    return [
        'unclear' => 'Receipt is unclear',
        'wrong_amount' => 'Wrong amount',
        'duplicate' => 'Duplicate',
        'wrong_account' => 'Wrong account',
        'other' => 'Other',
    ];
}

function format_etb(null|string|float|int $amount): string
{
    if ($amount === null || $amount === '') {
        return '';
    }
    if (!is_numeric($amount)) {
        return '';
    }
    return number_format((float) $amount, 2) . ' ETB';
}

function can_manage_payment_accounts(): bool
{
    return can('payment_accounts.manage') || can('payments.manage');
}

function can_manage_payment_items(): bool
{
    return can('payment_items.manage') || can('payments.manage');
}

function can_verify_payments(): bool
{
    return can('payments.verify') || can('payments.manage');
}

function can_reject_payments(): bool
{
    return can('payments.reject') || can('payments.manage');
}

function can_view_payments(): bool
{
    return can_any([
        'payments.view',
        'payments.manage',
        'payments.verify',
        'payments.reject',
        'payments.export',
        'payment_accounts.manage',
        'payment_items.manage',
    ]);
}

function can_edit_payments(): bool
{
    return can('payments.edit') || can('payments.manage');
}

function can_archive_payments(): bool
{
    return can('payments.delete') || can('payments.manage');
}

function can_export_payments(): bool
{
    return can('payments.export') || can('payments.manage');
}

function payment_date_presets(): array
{
    return [
        '' => 'Any date',
        'today' => 'Today',
        'week' => 'This week',
        'month' => 'This month',
        'custom' => 'Custom range',
    ];
}

function payment_amount_presets(): array
{
    return [
        '' => 'Any amount',
        'under500' => 'Under 500 ETB',
        '500_1000' => '500–1,000 ETB',
        '1000_2000' => '1,000–2,000 ETB',
        'custom' => 'Custom range',
    ];
}

function payment_filters_from(array $src): array
{
    $status = trim((string) ($src['status'] ?? 'pending'));
    if ($status !== 'all' && !isset(payment_tx_statuses()[$status])) {
        $status = 'pending';
    }
    $date = trim((string) ($src['date'] ?? ''));
    if (!isset(payment_date_presets()[$date])) {
        $date = '';
    }
    $amount = trim((string) ($src['amount'] ?? ''));
    if (!isset(payment_amount_presets()[$amount])) {
        $amount = '';
    }
    $from = trim((string) ($src['from'] ?? ''));
    $to = trim((string) ($src['to'] ?? ''));
    $from = ($from !== '' && strtotime($from) !== false) ? date('Y-m-d', (int) strtotime($from)) : '';
    $to = ($to !== '' && strtotime($to) !== false) ? date('Y-m-d', (int) strtotime($to)) : '';
    $method = trim((string) ($src['method'] ?? ''));
    if ($method !== '' && !isset(payment_methods()[$method])) {
        $method = '';
    }
    return [
        'q' => excerpt(trim((string) ($src['q'] ?? '')), 80),
        'status' => $status,
        'purpose' => max(0, (int) ($src['purpose'] ?? 0)),
        'method' => $method,
        'date' => $date,
        'from' => $from,
        'to' => $to,
        'amount' => $amount,
        'amin' => trim((string) ($src['amin'] ?? '')),
        'amax' => trim((string) ($src['amax'] ?? '')),
    ];
}

function payment_filter_query(array $filters, array $extra = []): string
{
    $merged = array_merge($filters, $extra);
    unset($merged['page']);
    $out = [];
    foreach ($merged as $key => $value) {
        if ($value === '' || $value === null || $value === 0 || $value === '0') {
            continue;
        }
        if ($key === 'status' && $value === 'pending') {
            continue;
        }
        $out[$key] = $value;
    }
    return http_build_query($out);
}

function payment_date_bounds(array $filters): array
{
    $preset = (string) ($filters['date'] ?? '');
    $today = date('Y-m-d');
    if ($preset === 'today') {
        return [$today, $today];
    }
    if ($preset === 'week') {
        $from = date('Y-m-d', strtotime('monday this week') ?: time());
        if ($from > $today) {
            $from = date('Y-m-d', strtotime('monday last week') ?: time());
        }
        return [$from, $today];
    }
    if ($preset === 'month') {
        return [date('Y-m-01'), $today];
    }
    $from = trim((string) ($filters['from'] ?? ''));
    $to = trim((string) ($filters['to'] ?? ''));
    return [$from !== '' ? $from : null, $to !== '' ? $to : null];
}

function payment_amount_bounds(array $filters): array
{
    $preset = (string) ($filters['amount'] ?? '');
    if ($preset === 'under500') {
        return [null, 499.99];
    }
    if ($preset === '500_1000') {
        return [500.0, 1000.0];
    }
    if ($preset === '1000_2000') {
        return [1000.0, 2000.0];
    }
    $min = trim((string) ($filters['amin'] ?? ''));
    $max = trim((string) ($filters['amax'] ?? ''));
    return [
        is_numeric($min) ? (float) $min : null,
        is_numeric($max) ? (float) $max : null,
    ];
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

function payment_receipt_url(int $id): string
{
    return url('payment-receipt.php?id=' . $id);
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
    if ($value <= 0 || $value > 999999.99) {
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
    $number = trim((string) ($src['account_number'] ?? ($src['phone_number'] ?? '')));
    $method = (string) ($src['method'] ?? 'bank');
    if (!isset(payment_methods()[$method])) {
        $method = 'other';
    }
    if ($label === '' || $number === '') {
        throw new InvalidArgumentException('Account name and account or phone number are required.');
    }
    $phone = excerpt(trim((string) ($src['phone_number'] ?? '')), 40) ?: null;
    $params = [
        $method,
        excerpt($label, 120),
        excerpt(trim((string) ($src['account_name'] ?? '')), 120) ?: null,
        excerpt($number, 80),
        $phone,
        excerpt(trim((string) ($src['notes'] ?? '')), 240) ?: null,
        max(0, (int) ($src['display_order'] ?? 0)),
        empty($src['is_active']) ? 0 : 1,
    ];
    $hasPhone = column_exists(db(), 'payment_accounts', 'phone_number');
    if ($id) {
        $existing = payment_account_by_id($id);
        if (!$existing) {
            throw new InvalidArgumentException('Payment account not found.');
        }
        $params[] = $id;
        if ($hasPhone) {
            db()->prepare(
                'UPDATE payment_accounts SET method=?, label=?, account_name=?, account_number=?, phone_number=?, notes=?, display_order=?, is_active=? WHERE id=?'
            )->execute($params);
        } else {
            unset($params[4]);
            $params = array_values($params);
            db()->prepare(
                'UPDATE payment_accounts SET method=?, label=?, account_name=?, account_number=?, notes=?, display_order=?, is_active=? WHERE id=?'
            )->execute($params);
        }
        return payment_account_by_id($id) ?? $existing;
    }
    if ($hasPhone) {
        db()->prepare(
            'INSERT INTO payment_accounts (method, label, account_name, account_number, phone_number, notes, display_order, is_active) VALUES (?,?,?,?,?,?,?,?)'
        )->execute($params);
    } else {
        unset($params[4]);
        $params = array_values($params);
        db()->prepare(
            'INSERT INTO payment_accounts (method, label, account_name, account_number, notes, display_order, is_active) VALUES (?,?,?,?,?,?,?)'
        )->execute($params);
    }
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

function payment_items(bool $studentVisible = false): array
{
    if (!table_exists(db(), 'payment_items')) {
        return [];
    }
    $sql = 'SELECT * FROM payment_items';
    if ($studentVisible) {
        $sql .= " WHERE visible_to_students = 1 AND status = 'open'";
    }
    $sql .= ' ORDER BY id ASC';
    return db()->query($sql)->fetchAll();
}

function payment_item_by_id(int $id): ?array
{
    if ($id <= 0 || !table_exists(db(), 'payment_items')) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM payment_items WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function save_payment_item(array $src, ?int $id = null): array
{
    $title = trim((string) ($src['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('Payment item title is required.');
    }
    $targetRaw = trim((string) ($src['target_amount'] ?? ''));
    $target = $targetRaw === '' ? null : parse_payment_amount($targetRaw);
    $status = (string) ($src['status'] ?? 'open');
    if (!in_array($status, ['open', 'closed'], true)) {
        $status = 'open';
    }
    $start = trim((string) ($src['start_date'] ?? ''));
    $end = trim((string) ($src['end_date'] ?? ''));
    $startDate = ($start !== '' && strtotime($start) !== false) ? date('Y-m-d', (int) strtotime($start)) : null;
    $endDate = ($end !== '' && strtotime($end) !== false) ? date('Y-m-d', (int) strtotime($end)) : null;
    $params = [
        excerpt($title, 160),
        excerpt(trim((string) ($src['description'] ?? '')), 600) ?: null,
        $target,
        empty($src['allow_partial_payment']) ? 0 : 1,
        $startDate,
        $endDate,
        $status,
        empty($src['visible_to_students']) ? 0 : 1,
    ];
    if ($id) {
        $existing = payment_item_by_id($id);
        if (!$existing) {
            throw new InvalidArgumentException('Payment item not found.');
        }
        $params[] = $id;
        db()->prepare(
            'UPDATE payment_items SET title=?, description=?, target_amount=?, allow_partial_payment=?, start_date=?, end_date=?, status=?, visible_to_students=? WHERE id=?'
        )->execute($params);
        return payment_item_by_id($id) ?? $existing;
    }
    $params[] = (int) (current_user()['id'] ?? 0) ?: null;
    db()->prepare(
        'INSERT INTO payment_items (title, description, target_amount, allow_partial_payment, start_date, end_date, status, visible_to_students, created_by) VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute($params);
    return payment_item_by_id((int) db()->lastInsertId()) ?? [];
}

function payment_transaction_by_id(int $id): ?array
{
    if ($id <= 0 || !table_exists(db(), 'payment_transactions')) {
        return null;
    }
    $stmt = db()->prepare(
        "SELECT t.*, u.student_name, u.student_code, u.photo AS student_photo, i.title AS item_title
         FROM payment_transactions t
         INNER JOIN uniforms u ON u.id = t.student_id
         LEFT JOIN payment_items i ON i.id = t.payment_item_id
         WHERE t.id = ? LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function payment_transactions_for_student(int $studentId, string $status = 'all'): array
{
    if ($studentId <= 0 || !table_exists(db(), 'payment_transactions')) {
        return [];
    }
    $sql = "SELECT t.*, i.title AS item_title
         FROM payment_transactions t
         LEFT JOIN payment_items i ON i.id = t.payment_item_id
         WHERE t.student_id = ? AND t.status NOT IN ('cancelled','archived')";
    $params = [$studentId];
    if ($status !== 'all' && in_array($status, ['pending', 'verified', 'rejected'], true)) {
        $sql .= ' AND t.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY t.id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function payment_transaction_search(array $filters): array
{
    if (!table_exists(db(), 'payment_transactions')) {
        return [];
    }
    $sql = "SELECT t.*, u.student_name, u.student_code, u.phone_number, u.photo AS student_photo, i.title AS item_title
            FROM payment_transactions t
            INNER JOIN uniforms u ON u.id = t.student_id
            LEFT JOIN payment_items i ON i.id = t.payment_item_id
            WHERE t.status <> 'cancelled'";
    $params = [];
    $status = (string) ($filters['status'] ?? 'pending');
    if ($status === 'archived') {
        $sql .= " AND t.status = 'archived'";
    } elseif ($status !== 'all' && isset(payment_tx_statuses()[$status]) && $status !== 'cancelled') {
        $sql .= ' AND t.status = ?';
        $params[] = $status;
    } else {
        $sql .= " AND t.status IN ('pending','verified','rejected')";
    }
    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $sql .= ' AND (u.student_name LIKE ? OR u.student_code LIKE ? OR u.phone_number LIKE ? OR t.transaction_reference LIKE ? OR i.title LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $itemId = (int) ($filters['purpose'] ?? 0);
    if ($itemId > 0) {
        $sql .= ' AND t.payment_item_id = ?';
        $params[] = $itemId;
    }
    $method = trim((string) ($filters['method'] ?? ''));
    if ($method !== '') {
        $sql .= ' AND t.payment_method = ?';
        $params[] = $method;
    }
    [$from, $to] = payment_date_bounds($filters);
    if ($from) {
        $sql .= ' AND COALESCE(t.payment_date, DATE(t.created_at)) >= ?';
        $params[] = $from;
    }
    if ($to) {
        $sql .= ' AND COALESCE(t.payment_date, DATE(t.created_at)) <= ?';
        $params[] = $to;
    }
    [$min, $max] = payment_amount_bounds($filters);
    if ($min !== null) {
        $sql .= ' AND t.amount >= ?';
        $params[] = $min;
    }
    if ($max !== null) {
        $sql .= ' AND t.amount <= ?';
        $params[] = $max;
    }
    $sql .= ' ORDER BY COALESCE(t.payment_date, DATE(t.created_at)) DESC, t.id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function payment_transaction_list(string $status = 'pending', string $search = ''): array
{
    return payment_transaction_search(['status' => $status, 'q' => $search]);
}

function payment_overview_stats(): array
{
    $empty = ['pending' => 0, 'verified' => 0, 'rejected' => 0, 'collected' => 0.0];
    if (!table_exists(db(), 'payment_transactions')) {
        return $empty;
    }
    $rows = db()->query(
        "SELECT status, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total
         FROM payment_transactions
         WHERE status IN ('pending','verified','rejected')
         GROUP BY status"
    )->fetchAll();
    foreach ($rows as $row) {
        $key = (string) ($row['status'] ?? '');
        if (isset($empty[$key])) {
            $empty[$key] = (int) $row['n'];
        }
        if ($key === 'verified') {
            $empty['collected'] = (float) $row['total'];
        }
    }
    return $empty;
}

function pending_payment_request_count(): int
{
    if (table_exists(db(), 'payment_transactions')) {
        return (int) db()->query("SELECT COUNT(*) FROM payment_transactions WHERE status = 'pending'")->fetchColumn();
    }
    if (!table_exists(db(), 'payment_requests')) {
        return 0;
    }
    return (int) db()->query("SELECT COUNT(*) FROM payment_requests WHERE status = 'pending'")->fetchColumn();
}

function student_item_progress(int $studentId, array $item): array
{
    $itemId = (int) ($item['id'] ?? 0);
    $target = isset($item['target_amount']) && is_numeric((string) $item['target_amount'])
        ? (float) $item['target_amount']
        : null;
    $verified = 0.0;
    $pending = 0.0;
    if ($studentId > 0 && $itemId > 0 && table_exists(db(), 'payment_transactions')) {
        $stmt = db()->prepare(
            "SELECT status, COALESCE(SUM(amount),0) AS total
             FROM payment_transactions
             WHERE student_id = ? AND payment_item_id = ? AND status IN ('pending','verified')
             GROUP BY status"
        );
        $stmt->execute([$studentId, $itemId]);
        foreach ($stmt->fetchAll() as $row) {
            if (($row['status'] ?? '') === 'verified') {
                $verified = (float) $row['total'];
            } elseif (($row['status'] ?? '') === 'pending') {
                $pending = (float) $row['total'];
            }
        }
    }
    $remaining = $target === null ? null : max(0, round($target - $verified, 2));
    $pct = ($target !== null && $target > 0) ? (int) min(100, round(($verified / $target) * 100)) : 0;
    return [
        'item' => $item,
        'target' => $target,
        'verified' => $verified,
        'pending' => $pending,
        'remaining' => $remaining,
        'percent' => $pct,
        'complete' => $remaining !== null && $remaining <= 0 && $verified > 0,
    ];
}

function student_payment_progress_list(int $studentId): array
{
    $out = [];
    foreach (payment_items(true) as $item) {
        $out[] = student_item_progress($studentId, $item);
    }
    return $out;
}

function sync_student_payment_status(int $studentId): string
{
    if ($studentId <= 0 || !column_exists(db(), 'uniforms', 'payment_status')) {
        return 'unpaid';
    }
    $items = payment_items(true);
    $targets = [];
    $verifiedAll = 0.0;
    foreach ($items as $item) {
        $progress = student_item_progress($studentId, $item);
        $verifiedAll += $progress['verified'];
        if ($progress['target'] !== null) {
            $targets[] = $progress;
        }
    }
    $status = 'unpaid';
    if ($targets) {
        $allCovered = true;
        $anyPaid = false;
        foreach ($targets as $progress) {
            if ($progress['verified'] > 0) {
                $anyPaid = true;
            }
            if (($progress['remaining'] ?? 0) > 0) {
                $allCovered = false;
            }
        }
        if ($allCovered && $anyPaid) {
            $status = 'paid';
        } elseif ($anyPaid) {
            $status = 'partial';
        }
    } elseif ($verifiedAll > 0) {
        $status = 'partial';
    }
    db()->prepare('UPDATE uniforms SET payment_status = ? WHERE id = ?')->execute([$status, $studentId]);
    return $status;
}

function payment_proof_conflicts(
    int $studentId,
    string $referenceKey,
    ?string $receiptHash,
    ?int $excludeId = null,
    array $statuses = ['pending', 'verified', 'rejected']
): array {
    if ($studentId <= 0 || !table_exists(db(), 'payment_transactions')) {
        return [];
    }
    $allowed = ['pending', 'verified', 'rejected'];
    $statuses = array_values(array_intersect($statuses, $allowed));
    if ($statuses === []) {
        return [];
    }
    $conflicts = [];
    $in = implode(',', array_fill(0, count($statuses), '?'));
    $excludeSql = $excludeId ? ' AND t.id <> ?' : '';

    if ($referenceKey !== '') {
        $sql = "SELECT t.id, t.student_id, t.status, t.transaction_reference AS reference, 'reference' AS conflict, u.student_name, u.student_code
                FROM payment_transactions t
                INNER JOIN uniforms u ON u.id = t.student_id
                WHERE t.student_id <> ?
                  AND t.status IN ($in)
                  AND t.reference_key = ?
                  $excludeSql
                ORDER BY t.id ASC LIMIT 1";
        $params = array_merge([$studentId], $statuses, [$referenceKey]);
        if ($excludeId) {
            $params[] = $excludeId;
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
        $sql = "SELECT t.id, t.student_id, t.status, t.transaction_reference AS reference, 'receipt' AS conflict, u.student_name, u.student_code
                FROM payment_transactions t
                INNER JOIN uniforms u ON u.id = t.student_id
                WHERE t.student_id <> ?
                  AND t.status IN ($in)
                  AND t.receipt_hash = ?
                  $excludeSql
                ORDER BY t.id ASC LIMIT 1";
        $params = array_merge([$studentId], $statuses, [$hash]);
        if ($excludeId) {
            $params[] = $excludeId;
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
        throw new InvalidArgumentException('This receipt photo was already verified for ' . $who . '. Reject the duplicate first.');
    }
    throw new InvalidArgumentException('This transaction ID was already verified for ' . $who . '. Reject the duplicate first.');
}

function cleanup_student_payments(int $studentId): void
{
    if ($studentId <= 0) {
        return;
    }
    if (table_exists(db(), 'payment_transactions')) {
        $stmt = db()->prepare('SELECT receipt_path FROM payment_transactions WHERE student_id = ?');
        $stmt->execute([$studentId]);
        foreach ($stmt->fetchAll() as $row) {
            delete_upload((string) ($row['receipt_path'] ?? ''));
        }
        db()->prepare('DELETE FROM payment_transactions WHERE student_id = ?')->execute([$studentId]);
    }
    if (table_exists(db(), 'payment_requests')) {
        $stmt = db()->prepare('SELECT receipt FROM payment_requests WHERE student_id = ?');
        $stmt->execute([$studentId]);
        foreach ($stmt->fetchAll() as $row) {
            delete_upload((string) ($row['receipt'] ?? ''));
        }
        db()->prepare('DELETE FROM payment_requests WHERE student_id = ?')->execute([$studentId]);
    }
}

function submit_payment_transaction(array $student, array $src, array $files, int $userId): array
{
    $studentId = (int) ($student['id'] ?? 0);
    if ($studentId <= 0) {
        throw new InvalidArgumentException('Your account is not linked to a class record.');
    }
    if (!table_exists(db(), 'payment_transactions')) {
        throw new InvalidArgumentException('Payments are not ready yet.');
    }

    $items = payment_items(true);
    $itemId = (int) ($src['payment_item_id'] ?? 0);
    $item = $itemId > 0 ? payment_item_by_id($itemId) : null;
    if ($items) {
        if (!$item || (int) ($item['visible_to_students'] ?? 0) !== 1 || ($item['status'] ?? '') !== 'open') {
            throw new InvalidArgumentException('Choose what this payment is for.');
        }
    } else {
        $item = null;
        $itemId = 0;
    }

    $amount = parse_payment_amount((string) ($src['amount'] ?? ''));
    if ($amount === null) {
        throw new InvalidArgumentException('Enter the amount you paid.');
    }
    if ($item) {
        $progress = student_item_progress($studentId, $item);
        if ($progress['remaining'] !== null && $progress['remaining'] <= 0 && $progress['verified'] > 0) {
            throw new InvalidArgumentException('This item is already fully paid.');
        }
        if (empty($item['allow_partial_payment']) && $progress['remaining'] !== null && (float) $amount + 0.001 < (float) $progress['remaining']) {
            throw new InvalidArgumentException('This item does not accept a smaller partial payment.');
        }
    }

    $reference = excerpt(trim((string) ($src['reference'] ?? $src['transaction_reference'] ?? '')), 80);
    $referenceKey = payment_reference_key($reference);
    if ($reference === '' || strlen($referenceKey) < 4) {
        throw new InvalidArgumentException('Enter the full transaction ID from your own receipt.');
    }

    $date = trim((string) ($src['payment_date'] ?? ''));
    if ($date === '') {
        $date = date('Y-m-d');
    }
    $ts = strtotime($date);
    if ($ts === false) {
        throw new InvalidArgumentException('Enter a valid payment date.');
    }
    $date = date('Y-m-d', $ts);
    if ($date > date('Y-m-d')) {
        throw new InvalidArgumentException('Payment date cannot be in the future.');
    }

    $method = (string) ($src['payment_method'] ?? 'other');
    if (!isset(payment_methods()[$method])) {
        $method = 'other';
    }

    $accountId = (int) ($src['account_id'] ?? $src['payment_account_id'] ?? 0);
    $account = $accountId > 0 ? payment_account_by_id($accountId) : null;
    if ($account && empty($account['is_active'])) {
        $account = null;
    }
    $published = payment_accounts(true);
    if ($published && !$account) {
        throw new InvalidArgumentException('Choose the account you paid into.');
    }
    if ($account) {
        $method = (string) ($account['method'] ?? $method);
    }

    if (empty($files['receipt']['name'])) {
        throw new InvalidArgumentException('Upload a photo of the receipt or transfer screenshot.');
    }
    $receiptHash = payment_file_sha256((string) ($files['receipt']['tmp_name'] ?? ''));
    $upload = store_upload($files['receipt'], 'payments');
    if (!$upload['ok']) {
        throw new InvalidArgumentException($upload['error']);
    }
    $receipt = $upload['path'];
    if (!$receiptHash && $receipt) {
        $receiptHash = payment_receipt_hash_from_path((string) $receipt);
    }

    try {
        assert_unique_payment_proof(
            payment_proof_conflicts($studentId, $referenceKey, $receiptHash)
        );
    } catch (Throwable $e) {
        delete_upload((string) $receipt);
        throw $e;
    }

    $snapshot = $account ? payment_account_snapshot($account) : excerpt(trim((string) ($src['account_label'] ?? '')), 180);
    $note = excerpt(trim((string) ($src['student_note'] ?? '')), 180);
    db()->prepare(
        'INSERT INTO payment_transactions
            (student_id, user_id, payment_item_id, amount, payment_date, payment_method, payment_account_id, account_label, transaction_reference, reference_key, receipt_path, receipt_hash, student_note, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,\'pending\')'
    )->execute([
        $studentId,
        $userId,
        $itemId > 0 ? $itemId : null,
        $amount,
        $date,
        $method,
        $account ? (int) $account['id'] : null,
        $snapshot !== '' ? $snapshot : null,
        $reference,
        $referenceKey,
        $receipt,
        $receiptHash,
        $note !== '' ? $note : null,
    ]);

    $saved = payment_transaction_by_id((int) db()->lastInsertId());
    if (!$saved) {
        throw new RuntimeException('Could not save the payment.');
    }
    return $saved;
}

function cancel_payment_transaction(int $studentId, int $txId, int $userId): void
{
    $row = payment_transaction_by_id($txId);
    if (!$row || (int) $row['student_id'] !== $studentId || ($row['status'] ?? '') !== 'pending') {
        throw new InvalidArgumentException('There is no pending payment to withdraw.');
    }
    if ((int) ($row['user_id'] ?? 0) !== $userId && !can_verify_payments()) {
        throw new InvalidArgumentException('You can only withdraw your own pending payment.');
    }
    db()->prepare("UPDATE payment_transactions SET status = 'cancelled' WHERE id = ? AND student_id = ?")
        ->execute([$txId, $studentId]);
}

function verify_payment_transaction(int $txId, string $adminNote = ''): array
{
    $row = payment_transaction_by_id($txId);
    if (!$row || ($row['status'] ?? '') !== 'pending') {
        throw new InvalidArgumentException('That payment is no longer waiting.');
    }
    assert_unique_payment_proof(
        payment_proof_conflicts(
            (int) $row['student_id'],
            trim((string) ($row['reference_key'] ?? '')),
            trim((string) ($row['receipt_hash'] ?? '')) ?: null,
            (int) $row['id'],
            ['verified']
        ),
        false
    );
    $reviewer = (int) (current_user()['id'] ?? 0) ?: null;
    $adminNote = excerpt($adminNote, 180);
    if ($adminNote !== '') {
        db()->prepare(
            "UPDATE payment_transactions
             SET status = 'verified', admin_note = ?, verified_by = ?, verified_at = NOW(), rejected_by = NULL, rejected_at = NULL
             WHERE id = ?"
        )->execute([$adminNote, $reviewer, $txId]);
    } else {
        db()->prepare(
            "UPDATE payment_transactions
             SET status = 'verified', verified_by = ?, verified_at = NOW(), rejected_by = NULL, rejected_at = NULL
             WHERE id = ?"
        )->execute([$reviewer, $txId]);
    }
    sync_student_payment_status((int) $row['student_id']);
    $saved = payment_transaction_by_id($txId);
    if (!$saved) {
        throw new RuntimeException('Could not verify the payment.');
    }
    return $saved;
}

function reject_payment_transaction(int $txId, string $reason = '', string $note = ''): array
{
    $row = payment_transaction_by_id($txId);
    if (!$row || ($row['status'] ?? '') !== 'pending') {
        throw new InvalidArgumentException('That payment is no longer waiting.');
    }
    $reasons = payment_reject_reasons();
    $reasonLabel = $reasons[$reason] ?? '';
    $note = excerpt($note, 180);
    $adminNote = trim($reasonLabel . ($note !== '' ? ($reasonLabel !== '' ? ' — ' : '') . $note : ''));
    $reviewer = (int) (current_user()['id'] ?? 0) ?: null;
    db()->prepare(
        "UPDATE payment_transactions
         SET status = 'rejected', admin_note = ?, rejected_by = ?, rejected_at = NOW(), verified_by = NULL, verified_at = NULL
         WHERE id = ?"
    )->execute([$adminNote !== '' ? $adminNote : null, $reviewer, $txId]);
    sync_student_payment_status((int) $row['student_id']);
    $saved = payment_transaction_by_id($txId);
    if (!$saved) {
        throw new RuntimeException('Could not reject the payment.');
    }
    return $saved;
}

function update_payment_admin_note(int $txId, string $note): array
{
    $row = payment_transaction_by_id($txId);
    if (!$row || !in_array($row['status'] ?? '', ['pending', 'verified', 'rejected', 'archived'], true)) {
        throw new InvalidArgumentException('That payment could not be found.');
    }
    $note = excerpt($note, 180);
    db()->prepare('UPDATE payment_transactions SET admin_note = ? WHERE id = ?')
        ->execute([$note !== '' ? $note : null, $txId]);
    $saved = payment_transaction_by_id($txId);
    if (!$saved) {
        throw new RuntimeException('Could not save the note.');
    }
    return $saved;
}

function reopen_payment_transaction(int $txId): array
{
    $row = payment_transaction_by_id($txId);
    if (!$row || ($row['status'] ?? '') !== 'rejected') {
        throw new InvalidArgumentException('Only a rejected payment can be re-reviewed.');
    }
    db()->prepare(
        "UPDATE payment_transactions
         SET status = 'pending', rejected_by = NULL, rejected_at = NULL, verified_by = NULL, verified_at = NULL
         WHERE id = ?"
    )->execute([$txId]);
    sync_student_payment_status((int) $row['student_id']);
    $saved = payment_transaction_by_id($txId);
    if (!$saved) {
        throw new RuntimeException('Could not reopen the payment.');
    }
    return $saved;
}

function archive_payment_transaction(int $txId): array
{
    $row = payment_transaction_by_id($txId);
    if (!$row || in_array($row['status'] ?? '', ['cancelled', 'archived'], true)) {
        throw new InvalidArgumentException('That payment is not in the active ledger.');
    }
    $reviewer = (int) (current_user()['id'] ?? 0) ?: null;
    if (column_exists(db(), 'payment_transactions', 'archived_by')) {
        db()->prepare(
            "UPDATE payment_transactions SET status = 'archived', archived_by = ?, archived_at = NOW() WHERE id = ?"
        )->execute([$reviewer, $txId]);
    } else {
        db()->prepare("UPDATE payment_transactions SET status = 'archived' WHERE id = ?")->execute([$txId]);
    }
    sync_student_payment_status((int) $row['student_id']);
    $saved = payment_transaction_by_id($txId);
    if (!$saved) {
        throw new RuntimeException('Could not archive the payment.');
    }
    return $saved;
}

function posted_payment_ids(): array
{
    $raw = $_POST['ids'] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    $ids = [];
    foreach ($raw as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function notify_payment_verified(array $saved): void
{
    notify_student_record((int) $saved['student_id'], [
        'type' => 'payment.approved',
        'title' => 'Payment verified',
        'body' => (format_etb($saved['amount'] ?? null) ?: 'Your payment') . ' was verified.',
        'icon' => 'wallet',
        'url' => 'student/payment.php',
        'target_type' => 'student',
        'target_id' => (int) $saved['student_id'],
    ]);
}

function notify_payment_rejected(array $saved): void
{
    notify_student_record((int) $saved['student_id'], [
        'type' => 'payment.rejected',
        'title' => 'Payment not verified',
        'body' => trim((string) ($saved['admin_note'] ?? '')) !== ''
            ? (string) $saved['admin_note']
            : 'Send a clearer receipt, or check the account you paid to.',
        'icon' => 'wallet',
        'url' => 'student/payment.php',
        'target_type' => 'student',
        'target_id' => (int) $saved['student_id'],
    ]);
}

function export_payment_transactions_csv(array $rows): void
{
    $name = 'luchador-payments-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit;
    }
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Student', 'Student ID', 'Phone', 'Purpose', 'Amount', 'Date', 'Method', 'Reference', 'Status', 'Admin note']);
    foreach ($rows as $row) {
        fputcsv($out, [
            (string) ($row['student_name'] ?? ''),
            (string) ($row['student_code'] ?? ''),
            (string) ($row['phone_number'] ?? ''),
            (string) ($row['item_title'] ?? ''),
            (string) ($row['amount'] ?? ''),
            (string) ($row['payment_date'] ?? substr((string) ($row['created_at'] ?? ''), 0, 10)),
            payment_method_label((string) ($row['payment_method'] ?? '')),
            (string) ($row['transaction_reference'] ?? ''),
            status_label((string) ($row['status'] ?? '')),
            (string) ($row['admin_note'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

function student_can_access_transaction(array $tx): bool
{
    $student = current_student();
    return $student !== null && (int) ($tx['student_id'] ?? 0) === (int) ($student['id'] ?? 0);
}

function staff_can_access_transaction(): bool
{
    return can_view_payments();
}

function stream_payment_receipt(int $txId): void
{
    $tx = payment_transaction_by_id($txId);
    if (!$tx) {
        http_response_code(404);
        echo 'Receipt not found.';
        exit;
    }
    if (is_student()) {
        if (!student_can_access_transaction($tx) || in_array($tx['status'] ?? '', ['cancelled', 'archived'], true)) {
            deny_access('You can only view your own payment receipts.');
        }
    } elseif (!staff_can_access_transaction()) {
        deny_access();
    }

    $relative = str_replace('\\', '/', trim((string) ($tx['receipt_path'] ?? $tx['receipt'] ?? '')));
    if ($relative === '' || str_contains($relative, '..') || !str_starts_with($relative, 'uploads/payments/')) {
        http_response_code(404);
        echo 'Receipt not found.';
        exit;
    }
    $full = APP_ROOT . '/' . $relative;
    if (!is_file($full)) {
        http_response_code(404);
        echo 'Receipt not found.';
        exit;
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($full);
    if (!isset(upload_allowed_types()[$mime])) {
        $mime = 'application/octet-stream';
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($full));
    header('Content-Disposition: inline; filename="receipt-' . $txId . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($full);
    exit;
}

function save_payment_instructions(array $src): void
{
    $amount = null;
    try {
        $amount = parse_payment_amount((string) ($src['payment_amount'] ?? ''));
    } catch (InvalidArgumentException $e) {
        $amount = null;
    }
    save_settings([
        'payment_purpose' => excerpt(trim((string) ($src['payment_purpose'] ?? '')), 120),
        'payment_amount' => $amount ?? '',
        'payment_instructions' => excerpt(trim((string) ($src['payment_instructions'] ?? '')), 600),
    ]);
}

function pending_payment_request(int $studentId): ?array
{
    if ($studentId <= 0 || !table_exists(db(), 'payment_transactions')) {
        return null;
    }
    $stmt = db()->prepare("SELECT * FROM payment_transactions WHERE student_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function submit_payment_request(array $student, array $src, array $files, int $userId): array
{
    return submit_payment_transaction($student, $src, $files, $userId);
}

function cancel_payment_request(int $studentId, int $userId): void
{
    $existing = pending_payment_request($studentId);
    if (!$existing) {
        throw new InvalidArgumentException('There is no payment proof waiting.');
    }
    cancel_payment_transaction($studentId, (int) $existing['id'], $userId);
}

function approve_payment_request(int $requestId, string $status = ''): array
{
    $saved = verify_payment_transaction($requestId);
    $student = student_by_id((int) $saved['student_id']);
    if (!$student) {
        throw new InvalidArgumentException('Student record not found.');
    }
    return $student;
}

function reject_payment_request(int $requestId, string $note = ''): void
{
    reject_payment_transaction($requestId, 'other', $note);
}

function pending_payment_requests(): array
{
    return payment_transaction_list('pending');
}

function latest_payment_request(int $studentId): ?array
{
    $rows = payment_transactions_for_student($studentId);
    return $rows[0] ?? null;
}

function payment_audit_detail(array $tx): string
{
    $amount = format_etb($tx['amount'] ?? null) ?: 'a payment';
    $purpose = trim((string) ($tx['item_title'] ?? ''));
    $name = trim((string) ($tx['student_name'] ?? 'a student'));
    $piece = $purpose !== '' ? strtolower($purpose) . ' payment' : 'payment';
    return $amount . ' ' . $piece . ' from ' . $name;
}

function payment_staff_permission_keys(): array
{
    return [
        'payments.view',
        'payments.manage',
        'payments.create',
        'payments.edit',
        'payments.verify',
        'payments.reject',
        'payments.delete',
        'payments.export',
        'payment_accounts.manage',
        'payment_items.manage',
    ];
}
