<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/student-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';
require_once dirname(__DIR__) . '/includes/payments.php';

student_boot();

$student = current_student();
$user = current_user();
$accounts = payment_accounts(true);
$progressList = $student ? student_payment_progress_list((int) $student['id']) : [];
$historyAll = $student ? payment_transactions_for_student((int) $student['id']) : [];
$historyStatus = trim((string) ($_GET['status'] ?? 'all'));
if (!in_array($historyStatus, ['all', 'pending', 'verified', 'rejected'], true)) {
    $historyStatus = 'all';
}
$history = $student ? payment_transactions_for_student((int) $student['id'], $historyStatus) : [];
$historyCounts = ['all' => 0, 'pending' => 0, 'verified' => 0, 'rejected' => 0];
foreach ($historyAll as $row) {
    $historyCounts['all']++;
    $st = (string) ($row['status'] ?? '');
    if (isset($historyCounts[$st])) {
        $historyCounts[$st]++;
    }
}
$historyGroups = [];
foreach ($history as $row) {
    $label = trim((string) ($row['item_title'] ?? '')) ?: 'Payment';
    $historyGroups[$label][] = $row;
}
$status = (string) ($student['payment_status'] ?? 'unpaid');
$canSubmit = $student && can('payment.submit_own');
$today = date('Y-m-d');

if (is_post() && $student) {
    require_csrf();
    $action = posted('action');
    try {
        if ($action === 'payment_cancel') {
            cancel_payment_transaction((int) $student['id'], (int) posted('transaction_id'), (int) ($user['id'] ?? 0));
            log_audit('payment.cancel', 'student', (int) $student['id'], $student['student_name'] ?? '');
            flash_set('success', 'Your payment proof was withdrawn.');
        } elseif ($action === 'payment_request') {
            if (!can('payment.submit_own')) {
                deny_access();
            }
            $saved = submit_payment_transaction($student, $_POST, $_FILES, (int) ($user['id'] ?? 0));
            log_audit('payment.request', 'student', (int) $student['id'], payment_audit_detail($saved + ['student_name' => $student['student_name'] ?? '']));
            notify_staff([
                'type' => 'payment.request',
                'title' => 'Payment receipt waiting',
                'body' => (string) ($student['student_name'] ?? 'A student') . ' sent a ' . (format_etb($saved['amount'] ?? null) ?: 'payment') . ' receipt.',
                'icon' => 'wallet',
                'url' => 'admin/payments.php?review=' . (int) $saved['id'],
                'target_type' => 'payment',
                'target_id' => (int) $saved['id'],
            ]);
            flash_set('success', 'Receipt sent. This payment stays pending until a class administrator verifies it.');
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to send that payment proof. Please try again.');
    }
    redirect('student/payment.php');
}

student_header('Pay', 'payment', lead: 'Choose what you are paying for, send the money, then upload each receipt.');
?>

<article class="profile-sheet pay-sheet">
    <?php if (!$student): ?>
        <p class="muted">Your account is not linked to a class record. Please contact the class administrator.</p>
    <?php else: ?>
        <div class="pay-status">
            <p class="eyebrow">My payments</p>
            <p><span class="badge badge-<?= e($status) ?>"><?= e(status_label($status)) ?></span></p>
        </div>

        <?php if ($progressList): ?>
            <section class="pay-progress-list" aria-label="What you need to pay">
                <?php foreach ($progressList as $progress): ?>
                    <?php $item = $progress['item']; ?>
                    <article class="pay-progress-card">
                        <h2><?= e($item['title'] ?? 'Payment') ?></h2>
                        <?php if (!empty($item['description'])): ?>
                            <p class="muted"><?= nl2br(e((string) $item['description'])) ?></p>
                        <?php endif; ?>
                        <?php if ($progress['target'] !== null): ?>
                            <p class="pay-progress-meta">
                                <span>Target: <?= e(format_etb($progress['target'])) ?></span>
                                <span>Paid: <?= e(format_etb($progress['verified']) ?: '0.00 ETB') ?></span>
                                <span>Remaining: <?= e(format_etb($progress['remaining']) ?: '0.00 ETB') ?></span>
                            </p>
                            <?php if ($progress['pending'] > 0): ?>
                                <p class="muted">Pending: <?= e(format_etb($progress['pending'])) ?></p>
                            <?php endif; ?>
                            <div class="pay-bar" role="img" aria-label="<?= (int) $progress['percent'] ?> percent verified">
                                <span style="width: <?= (int) $progress['percent'] ?>%"></span>
                            </div>
                            <p class="muted"><?= (int) $progress['percent'] ?>%</p>
                        <?php else: ?>
                            <p class="muted">
                                Verified: <?= e(format_etb($progress['verified']) ?: '0.00 ETB') ?>
                                <?php if ($progress['pending'] > 0): ?> · Pending: <?= e(format_etb($progress['pending'])) ?><?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <section class="pay-accounts" aria-labelledby="pay-accounts-title">
        <h2 id="pay-accounts-title">Where to pay</h2>
        <?php if (!$accounts): ?>
            <p class="muted">Payment accounts appear here once the class office publishes them. Ask a class administrator if you need to pay now.</p>
        <?php else: ?>
            <p class="muted">Use one of these class accounts. Copy the number, pay, then send a receipt for each transfer.</p>
            <div class="pay-account-list">
                <?php foreach ($accounts as $account): ?>
                    <article class="pay-account-card">
                        <p class="eyebrow"><?= e(payment_method_label((string) $account['method'])) ?></p>
                        <h3><?= e($account['label']) ?></h3>
                        <?php if (!empty($account['account_name'])): ?>
                            <p class="muted"><?= e($account['account_name']) ?></p>
                        <?php endif; ?>
                        <p class="pay-account-number"><code><?= e($account['account_number']) ?></code></p>
                        <button type="button" class="btn btn-ghost btn-sm" data-copy="<?= e($account['account_number']) ?>">Copy number</button>
                        <?php if (!empty($account['phone_number']) && (string) $account['phone_number'] !== (string) $account['account_number']): ?>
                            <p class="muted"><?= e($account['phone_number']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($account['notes'])): ?>
                            <p class="muted"><?= e($account['notes']) ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($canSubmit): ?>
        <form class="profile-edit" method="post" enctype="multipart/form-data" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="payment_request">
            <p class="eyebrow">Send a receipt</p>
            <p class="muted">You can send more than one receipt. Each transfer is saved separately. A transaction ID or photo already used by another student is rejected.</p>
            <div class="form-grid">
                <?php if ($progressList): ?>
                <div class="form-group">
                    <label class="req" for="payment_item_id">This payment is for</label>
                    <select id="payment_item_id" name="payment_item_id" required>
                        <option value="">Choose purpose</option>
                        <?php foreach ($progressList as $progress): ?>
                            <?php $item = $progress['item']; ?>
                            <option value="<?= (int) $item['id'] ?>"><?= e($item['title']) ?><?= $progress['remaining'] !== null ? ' · remaining ' . format_etb($progress['remaining']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if ($accounts): ?>
                <div class="form-group">
                    <label class="req" for="account_id">Account you paid</label>
                    <select id="account_id" name="account_id" required>
                        <option value="">Choose account</option>
                        <?php foreach ($accounts as $account): ?>
                            <option value="<?= (int) $account['id'] ?>"><?= e($account['label'] . ' · ' . $account['account_number']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label class="req" for="amount">Amount paid (ETB)</label>
                    <input id="amount" type="text" name="amount" inputmode="decimal" autocomplete="off" required placeholder="0.00">
                </div>
                <div class="form-group">
                    <label class="req" for="payment_date">Payment date</label>
                    <input id="payment_date" type="date" name="payment_date" required max="<?= e($today) ?>" value="<?= e($today) ?>">
                </div>
                <div class="form-group">
                    <label class="req" for="reference">Reference / transaction ID</label>
                    <input id="reference" type="text" name="reference" autocomplete="off" maxlength="80" required placeholder="From your receipt">
                </div>
                <div class="form-group full">
                    <label class="req" for="receipt">Receipt photo</label>
                    <label class="stu-file">
                        <input id="receipt" type="file" name="receipt" accept="image/jpeg,image/png,image/webp,image/gif" required>
                        <span class="stu-file-icon"><?= icon('upload', 18) ?></span>
                        <span class="stu-file-copy">
                            <strong>Upload receipt</strong>
                            <span data-file-name data-empty="JPEG, PNG, WebP, or GIF">JPEG, PNG, WebP, or GIF</span>
                        </span>
                    </label>
                </div>
                <div class="form-group full"><label for="student_note">Note</label><input id="student_note" type="text" name="student_note" autocomplete="off" placeholder="Optional"></div>
            </div>
            <div class="form-actions" style="margin-top:1rem">
                <button class="btn" type="submit">Send for verification</button>
            </div>
        </form>
    <?php elseif ($student): ?>
        <p class="muted">Payment proof is sent from this page once the class office enables it.</p>
    <?php endif; ?>

    <?php if ($student): ?>
        <section class="pay-history" aria-labelledby="pay-history-title">
            <h2 id="pay-history-title">Payment history</h2>
            <nav class="pay-history-tabs" aria-label="Payment status">
                <?php
                $historyTabs = [
                    'all' => 'All',
                    'pending' => 'Pending',
                    'verified' => 'Verified',
                    'rejected' => 'Rejected',
                ];
                foreach ($historyTabs as $key => $label):
                    $href = url('student/payment.php' . ($key === 'all' ? '' : '?status=' . $key));
                ?>
                    <a href="<?= e($href) ?>" <?= $historyStatus === $key ? 'aria-current="page"' : '' ?>>
                        <strong><?= (int) $historyCounts[$key] ?></strong>
                        <span><?= e($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <?php if (!$history): ?>
                <p class="muted">No receipts in this list. Each payment you send appears here with its status.</p>
            <?php else: ?>
                <?php foreach ($historyGroups as $groupTitle => $groupRows): ?>
                    <h3 class="pay-history-group"><?= e($groupTitle) ?></h3>
                    <ul class="pay-history-list">
                        <?php foreach ($groupRows as $row): ?>
                            <?php
                            $rowStatus = (string) ($row['status'] ?? 'pending');
                            $ownReceipt = !empty($row['receipt_path']) ? payment_receipt_url((int) $row['id']) : '';
                            ?>
                            <li>
                                <div>
                                    <strong><?= e(format_etb($row['amount'] ?? null) ?: 'Amount not set') ?></strong>
                                    <p class="muted">
                                        <?= !empty($row['payment_date']) ? e(format_date((string) $row['payment_date'])) : e(format_when((string) ($row['created_at'] ?? ''))) ?>
                                    </p>
                                    <?php if ($rowStatus === 'rejected' && !empty($row['admin_note'])): ?>
                                        <p class="muted"><?= e($row['admin_note']) ?></p>
                                    <?php endif; ?>
                                    <?php if ($ownReceipt !== ''): ?>
                                        <p><a href="<?= e($ownReceipt) ?>" target="_blank" rel="noopener">View receipt</a></p>
                                    <?php endif; ?>
                                </div>
                                <div class="pay-history-side">
                                    <span class="badge badge-<?= e($rowStatus) ?>"><?= e(status_label($rowStatus)) ?></span>
                                    <?php if ($rowStatus === 'pending'): ?>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="payment_cancel">
                                            <input type="hidden" name="transaction_id" value="<?= (int) $row['id'] ?>">
                                            <button class="btn btn-ghost btn-sm" type="submit">Withdraw</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</article>

<?php student_footer('payment'); ?>
