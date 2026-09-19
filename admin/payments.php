<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';
require_once dirname(__DIR__) . '/includes/payments.php';

admin_boot(payment_staff_permission_keys());

$canAccounts = can_manage_payment_accounts();
$canItems = can_manage_payment_items();
$canVerify = can_verify_payments();
$canReject = can_reject_payments();
$canEditPay = can_edit_payments();
$canArchive = can_archive_payments();
$canExport = can_export_payments();

$filters = payment_filters_from($_GET);
$filterQuery = payment_filter_query($filters);
$paymentsReturn = static function (array $extra = []) use ($filters): string {
    $qs = payment_filter_query($filters, $extra);
    return 'admin/payments.php' . ($qs !== '' ? '?' . $qs : '');
};

$edit = null;
$editId = request_int('edit');
if ($editId) {
    $edit = payment_account_by_id($editId);
}

$itemEdit = null;
$itemId = request_int('item');
if ($itemId) {
    $itemEdit = payment_item_by_id($itemId);
}

$review = null;
$reviewId = request_int('review');
if ($reviewId) {
    $review = payment_transaction_by_id($reviewId);
    if (!$review || ($review['status'] ?? '') === 'cancelled') {
        flash_set('error', 'That payment could not be found.');
        redirect($paymentsReturn());
    }
}

if (($_GET['export'] ?? '') === 'csv') {
    if (!$canExport) {
        deny_access();
    }
    $exportRows = payment_transaction_search($filters);
    log_audit('payment.export', 'payment', null, count($exportRows) . ' rows');
    export_payment_transactions_csv($exportRows);
}

$printMode = (($_GET['print'] ?? '') === '1');

if (is_post()) {
    require_csrf();
    $action = posted('action');
    $after = $paymentsReturn();
    try {
        if ($action === 'account_delete') {
            if (!$canAccounts) {
                deny_access();
            }
            $id = request_int('id');
            $row = payment_account_by_id($id);
            delete_payment_account($id);
            log_audit('payment.account_delete', 'payment_account', $id, $row['label'] ?? '');
            flash_set('success', 'Payment account removed. Student records were not changed.');
            $after = $paymentsReturn() . '#accounts';
        } elseif ($action === 'account_save') {
            if (!$canAccounts) {
                deny_access();
            }
            $id = posted('id') !== '' ? (int) posted('id') : null;
            $saved = save_payment_account($_POST, $id);
            log_audit('payment.account_save', 'payment_account', (int) ($saved['id'] ?? 0), $saved['label'] ?? '');
            if (!$id && !empty($saved['is_active'])) {
                notify_students([
                    'type' => 'payment.account',
                    'title' => 'Payment account published',
                    'body' => (string) ($saved['label'] ?? 'Class account'),
                    'icon' => 'wallet',
                    'url' => 'student/payment.php',
                    'target_type' => 'payment_account',
                    'target_id' => (int) ($saved['id'] ?? 0),
                ]);
            }
            flash_set('success', $id ? 'Payment account updated.' : 'Payment account published. Students can now see it.');
            $after = $paymentsReturn() . '#accounts';
        } elseif ($action === 'item_save') {
            if (!$canItems) {
                deny_access();
            }
            $id = posted('id') !== '' ? (int) posted('id') : null;
            $saved = save_payment_item($_POST, $id);
            log_audit('payment.item_save', 'payment_item', (int) ($saved['id'] ?? 0), $saved['title'] ?? '');
            flash_set('success', $id ? 'Payment item updated.' : 'Payment item saved. Students see it if it is visible.');
            $after = $paymentsReturn() . '#items';
        } elseif ($action === 'payment_verify') {
            if (!$canVerify) {
                deny_access();
            }
            $saved = verify_payment_transaction((int) posted('request_id'), posted('admin_note'));
            $detail = payment_audit_detail($saved);
            log_audit('payment.verify', 'payment', (int) $saved['id'], $detail);
            notify_payment_verified($saved);
            flash_set('success', 'Verified ' . $detail . '.');
            $after = $paymentsReturn(['status' => 'verified']);
        } elseif ($action === 'payment_reject') {
            if (!$canReject) {
                deny_access();
            }
            $saved = reject_payment_transaction((int) posted('request_id'), posted('reason'), posted('note'));
            $detail = payment_audit_detail($saved);
            log_audit('payment.reject', 'payment', (int) $saved['id'], $detail . ($saved['admin_note'] ? ' — ' . $saved['admin_note'] : ''));
            notify_payment_rejected($saved);
            flash_set('success', 'Rejected ' . $detail . '. The student can send another receipt.');
            $after = $paymentsReturn(['status' => 'rejected']);
        } elseif ($action === 'payment_note') {
            if (!$canEditPay) {
                deny_access();
            }
            $saved = update_payment_admin_note((int) posted('request_id'), posted('admin_note'));
            log_audit('payment.note', 'payment', (int) $saved['id'], payment_audit_detail($saved));
            flash_set('success', 'Verification note saved.');
            $after = $paymentsReturn() . '&review=' . (int) $saved['id'];
        } elseif ($action === 'payment_reopen') {
            if (!$canVerify) {
                deny_access();
            }
            $saved = reopen_payment_transaction((int) posted('request_id'));
            $detail = payment_audit_detail($saved);
            log_audit('payment.reopen', 'payment', (int) $saved['id'], $detail);
            notify_student_record((int) $saved['student_id'], [
                'type' => 'payment.request',
                'title' => 'Payment being reviewed again',
                'body' => (format_etb($saved['amount'] ?? null) ?: 'Your payment') . ' is in the verification queue again.',
                'icon' => 'wallet',
                'url' => 'student/payment.php',
                'target_type' => 'student',
                'target_id' => (int) $saved['student_id'],
            ]);
            flash_set('success', 'Reopened ' . $detail . ' for review.');
            $after = $paymentsReturn(['status' => 'pending']);
        } elseif ($action === 'payment_archive') {
            if (!$canArchive) {
                deny_access();
            }
            $saved = archive_payment_transaction((int) posted('request_id'));
            $detail = payment_audit_detail($saved);
            log_audit('payment.archive', 'payment', (int) $saved['id'], $detail);
            flash_set('success', 'Archived ' . $detail . '. Totals no longer include it.');
            $after = $paymentsReturn(['status' => 'archived']);
        } elseif ($action === 'bulk_verify') {
            if (!$canVerify) {
                deny_access();
            }
            $ok = 0;
            $fail = 0;
            foreach (posted_payment_ids() as $id) {
                try {
                    $saved = verify_payment_transaction($id);
                    log_audit('payment.verify', 'payment', (int) $saved['id'], payment_audit_detail($saved));
                    notify_payment_verified($saved);
                    $ok++;
                } catch (Throwable $e) {
                    $fail++;
                }
            }
            if ($ok === 0) {
                throw new InvalidArgumentException('No pending receipts were verified. Select pending payments, then confirm.');
            }
            flash_set('success', 'Verified ' . $ok . ' payment' . ($ok === 1 ? '' : 's') . '.' . ($fail ? ' ' . $fail . ' could not be verified.' : ''));
            $after = $paymentsReturn(['status' => 'verified']);
        } elseif ($action === 'bulk_archive') {
            if (!$canArchive) {
                deny_access();
            }
            $ok = 0;
            foreach (posted_payment_ids() as $id) {
                try {
                    $saved = archive_payment_transaction($id);
                    log_audit('payment.archive', 'payment', (int) $saved['id'], payment_audit_detail($saved));
                    $ok++;
                } catch (Throwable $e) {
                    // skip already archived
                }
            }
            if ($ok === 0) {
                throw new InvalidArgumentException('Select payments to archive. History is kept, not erased.');
            }
            flash_set('success', 'Archived ' . $ok . ' payment' . ($ok === 1 ? '' : 's') . '. History is kept.');
            $after = $paymentsReturn(['status' => 'archived']);
        } else {
            flash_set('error', 'Unknown payment action.');
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
        if (in_array($action, ['payment_verify', 'payment_reject', 'payment_note', 'payment_reopen', 'payment_archive'], true) && (int) posted('request_id') > 0) {
            $after = $paymentsReturn() . '&review=' . (int) posted('request_id');
        }
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to complete that payment action.');
    }
    $after = str_replace('admin/payments.php&', 'admin/payments.php?', $after);
    redirect($after);
}

$stats = payment_overview_stats();
$accounts = payment_accounts();
$items = payment_items(false);
$matched = $review ? [] : payment_transaction_search($filters);
$page = max(1, request_int('page', 1));
$perPage = $printMode ? max(1, count($matched) ?: 1) : 25;
$pageData = pagination(count($matched), $page, $perPage);
$queue = $review ? [] : array_slice($matched, $pageData['offset'], $perPage);
$receiptUrl = $review && !empty($review['receipt_path']) ? payment_receipt_url((int) $review['id']) : '';
$listQuery = $filterQuery;
$reviewHref = static function (int $id) use ($filterQuery): string {
    $qs = $filterQuery !== '' ? $filterQuery . '&' : '';
    return url('admin/payments.php?' . $qs . 'review=' . $id);
};

$pageTitle = $printMode ? 'Payment report' : ($review ? 'Review payment' : ($edit ? 'Edit payment account' : ($itemEdit ? 'Edit payment item' : 'Payments')));
admin_header($pageTitle, 'payments', $printMode ? 'pay-print' : '');
if (!$printMode) {
    admin_page_head(
        'Search and filter receipts together, then verify, reject, archive, or export the matching set.',
        [
            '<a class="btn btn-ghost" href="' . e(url($paymentsReturn() . '#verify')) . '">Verification</a>',
            '<a class="btn btn-ghost" href="' . e(url('admin/payments.php#items')) . '">Items</a>',
            '<a class="btn btn-ghost" href="' . e(url('admin/payments.php#accounts')) . '">Accounts</a>',
        ]
    );
}

$metricHref = static function (string $status) use ($paymentsReturn): string {
    return url($paymentsReturn(['status' => $status]));
};
$hasFilters = $filters['q'] !== ''
    || $filters['status'] !== 'pending'
    || $filters['purpose'] > 0
    || $filters['method'] !== ''
    || $filters['date'] !== ''
    || $filters['from'] !== ''
    || $filters['to'] !== ''
    || $filters['amount'] !== ''
    || $filters['amin'] !== ''
    || $filters['amax'] !== '';
?>

<section class="panel pay-overview-panel" aria-label="Payment overview">
    <div class="dash-metrics pay-overview">
        <a class="dash-metric" href="<?= e($metricHref('verified')) ?>">
            <span class="dash-metric-label">Total collected</span>
            <span class="dash-metric-value"><?= e(format_etb($stats['collected']) ?: '0.00 ETB') ?></span>
        </a>
        <a class="dash-metric" href="<?= e($metricHref('pending')) ?>">
            <span class="dash-metric-label">Pending</span>
            <span class="dash-metric-value"><?= (int) $stats['pending'] ?></span>
        </a>
        <a class="dash-metric" href="<?= e($metricHref('verified')) ?>">
            <span class="dash-metric-label">Verified</span>
            <span class="dash-metric-value"><?= (int) $stats['verified'] ?></span>
        </a>
        <a class="dash-metric" href="<?= e($metricHref('rejected')) ?>">
            <span class="dash-metric-label">Rejected</span>
            <span class="dash-metric-value"><?= (int) $stats['rejected'] ?></span>
        </a>
    </div>
</section>

<?php if ($review): ?>
    <?php
    $conflicts = payment_request_conflict_messages($review);
    $reviewStatus = (string) ($review['status'] ?? 'pending');
    ?>
    <section class="panel pay-review" id="review">
        <p class="eyebrow">Receipt verification</p>
        <div class="pay-review-head">
            <?php if (!empty($review['student_photo'])): ?>
                <img class="stu-avatar" src="<?= e(url((string) $review['student_photo'])) ?>" alt="">
            <?php else: ?>
                <span class="stu-avatar" aria-hidden="true"><?= e(person_initials((string) ($review['student_name'] ?? ''))) ?></span>
            <?php endif; ?>
            <div>
                <h2><?= e($review['student_name'] ?? 'Student') ?></h2>
                <p class="muted">
                    <?= !empty($review['student_code']) ? 'Student ID: ' . e($review['student_code']) : 'Student ID not set' ?>
                    · <?= e(status_label($reviewStatus)) ?>
                </p>
            </div>
        </div>

        <div class="pay-verify-grid">
            <dl class="profile-request-diff">
                <div>
                    <dt>Purpose</dt>
                    <dd><?= e($review['item_title'] ?: 'Not specified') ?></dd>
                </div>
                <div>
                    <dt>Amount</dt>
                    <dd><?= e(format_etb($review['amount'] ?? null) ?: 'Not specified') ?></dd>
                </div>
                <div>
                    <dt>Payment date</dt>
                    <dd><?= !empty($review['payment_date']) ? e(format_date((string) $review['payment_date'])) : e(format_when((string) ($review['created_at'] ?? ''))) ?></dd>
                </div>
                <div>
                    <dt>Method</dt>
                    <dd><?= e(payment_method_label((string) ($review['payment_method'] ?? 'other'))) ?></dd>
                </div>
                <div>
                    <dt>Paid to</dt>
                    <dd><?= e($review['account_label'] ?: 'Not specified') ?></dd>
                </div>
                <div>
                    <dt>Reference</dt>
                    <dd><?= e($review['transaction_reference'] ?: '—') ?></dd>
                </div>
                <?php if (!empty($review['student_note'])): ?>
                    <div>
                        <dt>Student note</dt>
                        <dd><?= e($review['student_note']) ?></dd>
                    </div>
                <?php endif; ?>
                <?php if (!empty($review['admin_note'])): ?>
                    <div>
                        <dt><?= $reviewStatus === 'rejected' ? 'Rejection reason' : 'Verification note' ?></dt>
                        <dd><?= e($review['admin_note']) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>
            <?php if ($receiptUrl !== ''): ?>
                <a class="pay-receipt" href="<?= e($receiptUrl) ?>" target="_blank" rel="noopener">
                    <img src="<?= e($receiptUrl) ?>" alt="Payment receipt">
                    <span>Open receipt</span>
                </a>
            <?php else: ?>
                <p class="muted">No receipt image on file.</p>
            <?php endif; ?>
        </div>

        <?php if ($conflicts): ?>
            <div class="pay-dup" role="status">
                <strong>Possible duplicate</strong>
                <?php foreach ($conflicts as $message): ?>
                    <p><?= e($message) ?></p>
                <?php endforeach; ?>
                <p>Verify only the student who actually paid. Reject the shared receipt.</p>
            </div>
        <?php endif; ?>

        <?php if ($reviewStatus === 'pending' && ($canVerify || $canReject)): ?>
            <div class="pay-review-actions">
                <?php if ($canReject): ?>
                    <form method="post" class="pay-reject-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="payment_reject">
                        <input type="hidden" name="request_id" value="<?= (int) $review['id'] ?>">
                        <label for="reject-reason">Reason</label>
                        <select id="reject-reason" name="reason">
                            <option value="">Choose a reason</option>
                            <?php foreach (payment_reject_reasons() as $key => $label): ?>
                                <option value="<?= e($key) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input name="note" placeholder="Optional note for the student">
                        <button class="btn btn-ghost" type="submit" data-confirm="The student can send another receipt. Verified totals will not include this amount." data-confirm-title="Reject this payment?" data-confirm-ok="Reject payment">Reject</button>
                    </form>
                <?php endif; ?>
                <?php if ($canVerify): ?>
                    <form method="post" class="pay-verify-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="payment_verify">
                        <input type="hidden" name="request_id" value="<?= (int) $review['id'] ?>">
                        <input name="admin_note" placeholder="Optional verification note">
                        <button class="btn" type="submit">Verify payment</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($reviewStatus === 'verified' && $canEditPay): ?>
            <form method="post" class="pay-note-form" style="margin-top:1rem">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="payment_note">
                <input type="hidden" name="request_id" value="<?= (int) $review['id'] ?>">
                <label for="edit-note">Verification note</label>
                <div class="pay-review-actions">
                    <input id="edit-note" name="admin_note" value="<?= e((string) ($review['admin_note'] ?? '')) ?>" placeholder="Note for the record">
                    <button class="btn" type="submit">Save note</button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($reviewStatus === 'rejected' && $canVerify): ?>
            <form method="post" style="margin-top:1rem">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="payment_reopen">
                <input type="hidden" name="request_id" value="<?= (int) $review['id'] ?>">
                <button class="btn" type="submit">Re-review</button>
            </form>
        <?php endif; ?>

        <div class="form-actions" style="margin-top:1rem">
            <a class="btn btn-ghost" href="<?= e(url($paymentsReturn())) ?>">Back to verification</a>
            <?php if ($canArchive && $reviewStatus !== 'archived'): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="payment_archive">
                    <input type="hidden" name="request_id" value="<?= (int) $review['id'] ?>">
                    <button class="btn btn-ghost" type="submit" data-confirm="This keeps the receipt in the ledger as archived. Totals will no longer include it." data-confirm-title="Archive this payment?" data-confirm-ok="Archive">Archive</button>
                </form>
            <?php endif; ?>
        </div>
    </section>
<?php else: ?>
    <section class="panel" id="verify">
        <div class="pay-queue-head">
            <div>
                <h2>Receipt verification</h2>
                <p class="muted">Filters work together. Search a student, then add purpose, status, date, or amount.</p>
            </div>
            <?php if (!$printMode): ?>
            <div class="pay-export-actions">
                <?php if ($canExport): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url($paymentsReturn(['export' => 'csv']))) ?>">Export CSV</a>
                <?php endif; ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url($paymentsReturn(['print' => '1']))) ?>">Print</a>
            </div>
            <?php else: ?>
            <p class="pay-print-back"><a class="btn btn-ghost" href="<?= e(url($paymentsReturn(['print' => '']))) ?>">Back</a></p>
            <?php endif; ?>
        </div>

        <?php if (!$printMode): ?>
        <form class="toolbar pay-filters" method="get">
            <div class="form-group search-field">
                <label class="sr-only" for="pay-search">Search</label>
                <?= icon('search', 16) ?>
                <input id="pay-search" name="q" value="<?= e($filters['q']) ?>" placeholder="Student, ID, phone, or reference">
            </div>
            <div class="form-group">
                <label for="pay-status">Status</label>
                <select id="pay-status" name="status">
                    <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="pending" <?= $filters['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="verified" <?= $filters['status'] === 'verified' ? 'selected' : '' ?>>Verified</option>
                    <option value="rejected" <?= $filters['status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    <option value="archived" <?= $filters['status'] === 'archived' ? 'selected' : '' ?>>Archived</option>
                </select>
            </div>
            <div class="form-group">
                <label for="pay-purpose">Payment item</label>
                <select id="pay-purpose" name="purpose">
                    <option value="">All purposes</option>
                    <?php foreach ($items as $itemRow): ?>
                        <option value="<?= (int) $itemRow['id'] ?>" <?= (int) $filters['purpose'] === (int) $itemRow['id'] ? 'selected' : '' ?>><?= e($itemRow['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="pay-method">Method</label>
                <select id="pay-method" name="method">
                    <option value="">All methods</option>
                    <?php foreach (payment_methods() as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $filters['method'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="pay-date">Date</label>
                <select id="pay-date" name="date">
                    <?php foreach (payment_date_presets() as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $filters['date'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="pay-from">From</label>
                <input id="pay-from" type="date" name="from" value="<?= e($filters['from']) ?>">
            </div>
            <div class="form-group">
                <label for="pay-to">To</label>
                <input id="pay-to" type="date" name="to" value="<?= e($filters['to']) ?>">
            </div>
            <div class="form-group">
                <label for="pay-amount">Amount</label>
                <select id="pay-amount" name="amount">
                    <?php foreach (payment_amount_presets() as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $filters['amount'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="pay-amin">Min ETB</label>
                <input id="pay-amin" name="amin" inputmode="decimal" value="<?= e($filters['amin']) ?>" placeholder="Min">
            </div>
            <div class="form-group">
                <label for="pay-amax">Max ETB</label>
                <input id="pay-amax" name="amax" inputmode="decimal" value="<?= e($filters['amax']) ?>" placeholder="Max">
            </div>
            <button class="btn" type="submit">Apply</button>
            <?php if ($hasFilters): ?>
                <a class="btn btn-ghost" href="<?= e(url('admin/payments.php')) ?>">Clear filters</a>
            <?php endif; ?>
        </form>
        <?php endif; ?>

        <p class="muted roster-meta"><?= count($matched) ?> matching receipt<?= count($matched) === 1 ? '' : 's' ?></p>

        <?php if (!$printMode && ($canVerify || $canArchive)): ?>
        <form id="payment-select-form" class="roster-bulk" method="post">
            <?= csrf_field() ?>
            <p class="roster-bulk-count"><strong data-selected-count>0</strong> selected</p>
            <?php if ($canVerify): ?>
                <button class="btn btn-sm" type="submit" name="action" value="bulk_verify" data-confirm="Verify the selected pending receipts? This cannot be undone from this step." data-confirm-title="Verify selected payments?" data-confirm-ok="Verify" disabled>Mark verified</button>
            <?php endif; ?>
            <?php if ($canArchive): ?>
                <button class="btn btn-sm btn-ghost" type="submit" name="action" value="bulk_archive" data-confirm="Selected receipts stay in the ledger as archived. Totals will no longer include them." data-confirm-title="Archive selected payments?" data-confirm-ok="Archive" disabled>Archive</button>
            <?php endif; ?>
        </form>
        <?php endif; ?>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <?php if (!$printMode && ($canVerify || $canArchive)): ?>
                            <th class="roster-check"><label class="sr-only" for="select-page">Select page</label><input id="select-page" type="checkbox" data-select-page-check></th>
                        <?php endif; ?>
                        <th>Student</th>
                        <th>Purpose</th>
                        <th>Amount</th>
                        <th>Date</th>
                        <th>Status</th>
                        <?php if (!$printMode): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$queue): ?>
                    <tr><td colspan="<?= !$printMode && ($canVerify || $canArchive) ? 7 : 6 ?>"><?= $hasFilters ? 'No receipts match these filters.' : 'No receipts in this list.' ?></td></tr>
                <?php else: foreach ($queue as $row): ?>
                    <?php $rowStatus = (string) ($row['status'] ?? 'pending'); ?>
                    <tr>
                        <?php if (!$printMode && ($canVerify || $canArchive)): ?>
                        <td class="roster-check">
                            <input form="payment-select-form" type="checkbox" name="ids[]" value="<?= (int) $row['id'] ?>" data-payment-id="<?= (int) $row['id'] ?>" aria-label="Select <?= e($row['student_name'] ?? 'payment') ?>">
                        </td>
                        <?php endif; ?>
                        <td>
                            <?= admin_person_cell(
                                (string) ($row['student_name'] ?? 'Student'),
                                $row['student_photo'] ?? null,
                                !empty($row['student_code']) ? 'ID ' . $row['student_code'] : 'ID not set'
                            ) ?>
                        </td>
                        <td><?= e($row['item_title'] ?: '—') ?></td>
                        <td><?= e(format_etb($row['amount'] ?? null) ?: '—') ?></td>
                        <td><?= !empty($row['payment_date']) ? e(format_date((string) $row['payment_date'])) : e(format_when((string) ($row['created_at'] ?? ''))) ?></td>
                        <td><?= admin_status_badge($rowStatus) ?></td>
                        <?php if (!$printMode): ?>
                        <td class="row-actions">
                            <a class="btn btn-sm" href="<?= e($reviewHref((int) $row['id'])) ?>">View</a>
                            <?php if ($rowStatus === 'pending' && $canVerify): ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="payment_verify">
                                    <input type="hidden" name="request_id" value="<?= (int) $row['id'] ?>">
                                    <button class="btn btn-sm btn-ghost" type="submit" data-confirm="Verify this receipt? It will count toward the student’s paid total." data-confirm-title="Verify this payment?" data-confirm-ok="Verify">Verify</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($rowStatus === 'pending' && $canReject): ?>
                                <a class="btn btn-sm btn-ghost" href="<?= e($reviewHref((int) $row['id'])) ?>">Reject</a>
                            <?php endif; ?>
                            <?php if ($rowStatus === 'verified' && !empty($row['receipt_path'])): ?>
                                <a class="btn btn-sm btn-ghost" href="<?= e(payment_receipt_url((int) $row['id'])) ?>" target="_blank" rel="noopener">Receipt</a>
                            <?php endif; ?>
                            <?php if ($rowStatus === 'rejected' && $canVerify): ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="payment_reopen">
                                    <input type="hidden" name="request_id" value="<?= (int) $row['id'] ?>">
                                    <button class="btn btn-sm btn-ghost" type="submit">Re-review</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$printMode): ?>
            <?php render_pagination($pageData, $listQuery); ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if (!$review && !$printMode): ?>
<div class="panel" id="items">
    <h2><?= $itemEdit ? 'Update payment item' : 'Payment items' ?></h2>
    <p class="muted">These are what students pay for. Leave the amount blank if it is not set yet. Do not invent a fee.</p>
    <?php if ($canItems): ?>
    <form method="post" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="item_save">
        <input type="hidden" name="id" value="<?= e((string) ($itemEdit['id'] ?? '')) ?>">
        <div class="form-grid">
            <div class="form-group"><label class="req" for="item_title">Title</label><input id="item_title" name="title" required value="<?= e($itemEdit['title'] ?? '') ?>" placeholder="The real purpose students are paying for"></div>
            <div class="form-group"><label for="target_amount">Target amount (ETB)</label><input id="target_amount" name="target_amount" inputmode="decimal" value="<?= e((string) ($itemEdit['target_amount'] ?? '')) ?>" placeholder="Leave blank if not set"></div>
            <div class="form-group"><label for="start_date">Start date</label><input id="start_date" type="date" name="start_date" value="<?= e((string) ($itemEdit['start_date'] ?? '')) ?>"></div>
            <div class="form-group"><label for="end_date">End date</label><input id="end_date" type="date" name="end_date" value="<?= e((string) ($itemEdit['end_date'] ?? '')) ?>"></div>
            <div class="form-group">
                <label for="item_status">Status</label>
                <select id="item_status" name="status">
                    <option value="open" <?= (($itemEdit['status'] ?? 'open') === 'open') ? 'selected' : '' ?>>Open</option>
                    <option value="closed" <?= (($itemEdit['status'] ?? '') === 'closed') ? 'selected' : '' ?>>Closed</option>
                </select>
            </div>
            <div class="form-group full"><label for="item_description">Description</label><textarea id="item_description" name="description" rows="2" placeholder="Optional instructions for this item."><?= e($itemEdit['description'] ?? '') ?></textarea></div>
            <div class="form-group full">
                <label class="check"><input type="checkbox" name="allow_partial_payment" value="1" <?= empty($itemEdit) || !empty($itemEdit['allow_partial_payment']) ? 'checked' : '' ?>> Allow partial payments</label>
            </div>
            <div class="form-group full">
                <label class="check"><input type="checkbox" name="visible_to_students" value="1" <?= empty($itemEdit) || !empty($itemEdit['visible_to_students']) ? 'checked' : '' ?>> Visible to students</label>
            </div>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit"><?= $itemEdit ? 'Save item' : 'Add payment item' ?></button>
            <?php if ($itemEdit): ?><a class="btn btn-ghost" href="<?= e(url('admin/payments.php#items')) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
    <?php endif; ?>

    <?php if (!$items): ?>
        <p class="muted" style="margin-top:1rem">No payment items yet. Students will not see a purpose until you add one from a real class fee.</p>
    <?php else: ?>
        <div class="table-wrap" style="margin-top:1rem">
            <table>
                <thead><tr><th>Item</th><th>Target</th><th>Status</th><?php if ($canItems): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($items as $row): ?>
                    <tr>
                        <td>
                            <strong><?= e($row['title']) ?></strong>
                            <?php if (!empty($row['description'])): ?><p class="muted"><?= e(excerpt((string) $row['description'], 120)) ?></p><?php endif; ?>
                        </td>
                        <td><?= e(format_etb($row['target_amount'] ?? null) ?: 'Not set') ?></td>
                        <td>
                            <?= admin_status_badge((string) ($row['status'] ?? 'open')) ?>
                            <?= empty($row['visible_to_students']) ? ' <span class="badge">Hidden</span>' : '' ?>
                        </td>
                        <?php if ($canItems): ?>
                        <td class="row-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/payments.php?item=' . (int) $row['id'] . '#items')) ?>">Edit</a>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="panel" id="accounts">
    <h2><?= $edit ? 'Update account' : 'Payment accounts' ?></h2>
    <p class="muted">These are the accounts students pay to. Add the real CBE, Telebirr, or bank details. Nothing is published until you save an account here.</p>
    <?php if ($canAccounts): ?>
    <form method="post" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="account_save">
        <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? '')) ?>">
        <div class="form-grid">
            <div class="form-group">
                <label class="req" for="method">Type</label>
                <select id="method" name="method">
                    <?php foreach (payment_methods() as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= (($edit['method'] ?? 'bank') === $key) ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="req" for="label">Bank or wallet name</label><input id="label" name="label" required value="<?= e($edit['label'] ?? '') ?>" placeholder="Commercial Bank of Ethiopia"></div>
            <div class="form-group"><label for="account_name">Account holder</label><input id="account_name" name="account_name" value="<?= e($edit['account_name'] ?? '') ?>" placeholder="Class name or officer"></div>
            <div class="form-group"><label class="req" for="account_number">Account or phone number</label><input id="account_number" name="account_number" required value="<?= e($edit['account_number'] ?? '') ?>" placeholder="The number students pay to"></div>
            <div class="form-group"><label for="phone_number">Phone number</label><input id="phone_number" name="phone_number" value="<?= e($edit['phone_number'] ?? '') ?>" placeholder="If different from the account number"></div>
            <div class="form-group"><label for="display_order">Order</label><input id="display_order" name="display_order" type="number" min="0" value="<?= e((string) ($edit['display_order'] ?? '0')) ?>"></div>
            <div class="form-group full"><label for="notes">Note for students</label><input id="notes" name="notes" value="<?= e($edit['notes'] ?? '') ?>" placeholder="Branch, what to put in the remark, etc."></div>
            <div class="form-group full">
                <label class="check"><input type="checkbox" name="is_active" value="1" <?= empty($edit) || !empty($edit['is_active']) ? 'checked' : '' ?>> Visible to students</label>
            </div>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit"><?= $edit ? 'Save account' : 'Publish account' ?></button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="<?= e(url('admin/payments.php#accounts')) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
    <?php endif; ?>

    <?php if (!$accounts): ?>
        <p class="muted" style="margin-top:1rem">No payment accounts yet. Students cannot see where to pay until you add one.</p>
    <?php else: ?>
        <div class="table-wrap" style="margin-top:1rem">
            <table>
                <thead><tr><th>Account</th><th>Number</th><th>Status</th><?php if ($canAccounts): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($accounts as $row): ?>
                    <tr>
                        <td>
                            <strong><?= e($row['label']) ?></strong>
                            <p class="muted"><?= e(payment_method_label((string) $row['method'])) ?><?= !empty($row['account_name']) ? ' · ' . e($row['account_name']) : '' ?></p>
                        </td>
                        <td><code><?= e($row['account_number']) ?></code></td>
                        <td><?= admin_active_badge($row['is_active'] ?? 0) ?></td>
                        <?php if ($canAccounts): ?>
                        <td class="row-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/payments.php?edit=' . (int) $row['id'] . '#accounts')) ?>">Edit</a>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="account_delete">
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button class="btn btn-sm btn-danger" data-confirm="Students will no longer see this account. Existing student records are not changed." data-confirm-title="Remove this account?" data-confirm-ok="Remove">Remove</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($printMode): ?>
<script>window.addEventListener('load', () => window.print());</script>
<?php endif; ?>

<?php admin_footer(); ?>
