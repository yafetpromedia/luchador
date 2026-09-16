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
$pending = $student ? pending_payment_request((int) $student['id']) : null;
$latest = $student ? latest_payment_request((int) $student['id']) : null;
$status = (string) ($student['payment_status'] ?? 'unpaid');
$canSubmit = $student && can('payment.submit_own') && $status !== 'paid' && !$pending;
$purpose = setting('payment_purpose');
$due = setting('payment_amount');
$instructions = setting('payment_instructions');

if (is_post() && $student) {
    require_csrf();
    $action = posted('action');
    try {
        if ($action === 'payment_cancel') {
            cancel_payment_request((int) $student['id'], (int) ($user['id'] ?? 0));
            log_audit('payment.cancel', 'student', (int) $student['id'], $student['student_name'] ?? '');
            flash_set('success', 'Your payment proof was withdrawn.');
        } elseif ($action === 'payment_request') {
            if (!can('payment.submit_own')) {
                deny_access();
            }
            submit_payment_request($student, $_POST, $_FILES, (int) ($user['id'] ?? 0));
            log_audit('payment.request', 'student', (int) $student['id'], $student['student_name'] ?? '');
            notify_staff([
                'type' => 'payment.request',
                'title' => 'Payment receipt waiting',
                'body' => (string) ($student['student_name'] ?? 'A student') . ' sent proof of payment.',
                'icon' => 'wallet',
                'url' => 'admin/payments.php',
                'target_type' => 'student',
                'target_id' => (int) $student['id'],
            ]);
            flash_set('success', 'Receipt sent. Your payment stays as it is until a class administrator verifies it.');
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to send that payment proof. Please try again.');
    }
    redirect('student/payment.php');
}

$pendingReceipt = $pending && !empty($pending['receipt']) ? url((string) $pending['receipt']) : '';
student_header('Pay', 'payment', lead: 'Copy a class account, pay, then send the receipt.');
?>

<article class="profile-sheet pay-sheet">
    <div class="pay-status">
        <p class="eyebrow">Your payment</p>
        <p><span class="badge badge-<?= e($status) ?>"><?= e(status_label($status)) ?></span></p>
        <?php if ($purpose !== '' || $due !== ''): ?>
            <p>
                <?php if ($purpose !== ''): ?><strong><?= e($purpose) ?></strong><?php endif; ?>
                <?php if ($due !== ''): ?><span class="student-stat"><?= e(format_etb($due)) ?></span><?php endif; ?>
            </p>
        <?php endif; ?>
        <?php if ($instructions !== ''): ?>
            <p class="muted"><?= nl2br(e($instructions)) ?></p>
        <?php endif; ?>
    </div>

    <?php if (!$student): ?>
        <p class="muted">Your account is not linked to a class record. Please contact the class administrator.</p>
    <?php elseif ($status === 'paid'): ?>
        <div class="profile-pending">
            <p class="eyebrow">Verified</p>
            <p>A class administrator confirmed this payment. You do not need to send another receipt.</p>
        </div>
    <?php endif; ?>

    <section class="pay-accounts" aria-labelledby="pay-accounts-title">
        <h2 id="pay-accounts-title">Where to pay</h2>
        <?php if (!$accounts): ?>
            <p class="muted">Payment accounts appear here once the class office publishes them. Ask a class administrator if you need to pay now.</p>
        <?php else: ?>
            <p class="muted">Use one of these class accounts. Copy the number, pay, then send the receipt below.</p>
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
                        <?php if (!empty($account['notes'])): ?>
                            <p class="muted"><?= e($account['notes']) ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($pending): ?>
        <div class="profile-pending">
            <p class="eyebrow">Waiting for verification</p>
            <p>A class administrator still needs to check this receipt. Your payment status does not change until then.</p>
            <dl class="profile-meta">
                <div><dt>Paid to</dt><dd><?= e($pending['account_label'] ?: '—') ?></dd></div>
                <div><dt>Amount</dt><dd><?= e(format_etb($pending['amount'] ?? null) ?: '—') ?></dd></div>
                <div><dt>Reference</dt><dd><?= e($pending['reference'] ?: '—') ?></dd></div>
                <div><dt>Marked as</dt><dd><?= e(status_label((string) ($pending['claimed_status'] ?? 'paid'))) ?></dd></div>
                <?php if ($pendingReceipt !== ''): ?>
                    <div>
                        <dt>Receipt</dt>
                        <dd><a href="<?= e($pendingReceipt) ?>" target="_blank" rel="noopener">View uploaded photo</a></dd>
                    </div>
                <?php endif; ?>
            </dl>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="payment_cancel">
                <button class="btn btn-ghost" type="submit">Withdraw receipt</button>
            </form>
        </div>
    <?php elseif ($canSubmit): ?>
        <?php if ($latest && ($latest['status'] ?? '') === 'rejected'): ?>
            <div class="profile-pending">
                <p class="eyebrow">Not verified</p>
                <p><?= e($latest['note'] ?: 'The last receipt was not accepted. Upload a clearer photo or check the account you paid to.') ?></p>
            </div>
        <?php endif; ?>
        <form class="profile-edit" method="post" enctype="multipart/form-data" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="payment_request">
            <p class="eyebrow">Send proof</p>
            <p class="muted">Pay first, then upload a photo of your own receipt or transfer screenshot. A transaction ID or photo already used by another student is rejected. Status stays unpaid until it is approved.</p>
            <div class="form-grid">
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
                <div class="form-group"><label for="amount">Amount (ETB)</label><input id="amount" type="text" name="amount" inputmode="decimal" autocomplete="off" value="<?= e($due) ?>" placeholder="0.00"></div>
                <div class="form-group">
                    <label class="req" for="reference">Reference / transaction ID</label>
                    <input id="reference" type="text" name="reference" autocomplete="off" maxlength="80" required placeholder="From your receipt">
                </div>
                <div class="form-group">
                    <label for="claimed_status">This covers</label>
                    <select id="claimed_status" name="claimed_status">
                        <option value="paid">Full payment</option>
                        <option value="partial">Partial payment</option>
                    </select>
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
    <?php elseif ($student && $status !== 'paid'): ?>
        <p class="muted">Payment proof is sent from this page once the class office enables it.</p>
    <?php endif; ?>
</article>

<?php student_footer('payment'); ?>
