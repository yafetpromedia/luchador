<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';
require_once dirname(__DIR__) . '/includes/payments.php';

admin_boot(['payments.view', 'payments.manage']);

$edit = null;
$editId = request_int('edit');
if ($editId) {
    $edit = payment_account_by_id($editId);
}

if (is_post()) {
    require_csrf();
    require_manage_on_post('payments.manage');
    $action = posted('action');
    try {
        if ($action === 'account_delete') {
            $id = request_int('id');
            $row = payment_account_by_id($id);
            delete_payment_account($id);
            log_audit('payment.account_delete', 'payment_account', $id, $row['label'] ?? '');
            flash_set('success', 'Payment account removed. Student records were not changed.');
        } elseif ($action === 'account_save') {
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
        } elseif ($action === 'instructions') {
            save_payment_instructions($_POST);
            log_audit('payment.instructions', 'settings', null, posted('payment_purpose') ?: 'Class payment');
            flash_set('success', 'Payment details saved. Students see this on Pay.');
        } elseif ($action === 'payment_approve') {
            $saved = approve_payment_request((int) posted('request_id'), posted('payment_status'));
            log_audit('payment.approve', 'student', (int) $saved['id'], $saved['student_name'] ?? '');
            notify_student_record((int) $saved['id'], [
                'type' => 'payment.approved',
                'title' => 'Payment verified',
                'body' => 'Your payment is now ' . strtolower(status_label((string) ($saved['payment_status'] ?? 'paid'))) . '.',
                'icon' => 'wallet',
                'url' => 'student/payment.php',
                'target_type' => 'student',
                'target_id' => (int) $saved['id'],
            ]);
            flash_set('success', 'Payment verified. ' . ($saved['student_name'] ?? 'Student') . ' is now ' . strtolower(status_label((string) ($saved['payment_status'] ?? 'paid'))) . '.');
        } elseif ($action === 'payment_reject') {
            $requestId = (int) posted('request_id');
            $lookup = db()->prepare('SELECT student_id FROM payment_requests WHERE id = ? LIMIT 1');
            $lookup->execute([$requestId]);
            $studentId = (int) $lookup->fetchColumn();
            reject_payment_request($requestId, posted('note'));
            log_audit('payment.reject', 'student', $studentId, posted('note') ?: 'Receipt rejected');
            notify_student_record($studentId, [
                'type' => 'payment.rejected',
                'title' => 'Payment not verified',
                'body' => posted('note') !== '' ? posted('note') : 'Send a clearer receipt, or check the account you paid to.',
                'icon' => 'wallet',
                'url' => 'student/payment.php',
                'target_type' => 'student',
                'target_id' => $studentId,
            ]);
            flash_set('success', 'Payment proof rejected. The student record was not changed.');
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to complete that payment action.');
    }
    redirect('admin/payments.php');
}

$accounts = payment_accounts();
$requests = pending_payment_requests();
$canManage = can('payments.manage');

admin_header($edit ? 'Edit payment account' : 'Payments', 'payments');
admin_page_head(
    'Publish the class bank and mobile-money accounts. Students pay there, upload a receipt, and you verify before marking them paid.',
    $canManage ? ['<a class="btn btn-ghost" href="' . e(url('admin/payments.php#accounts')) . '">Accounts</a>'] : []
);
?>

<?php if ($requests): ?>
<section class="panel" id="verify">
    <h2>Waiting for verification</h2>
    <p class="muted">Open the receipt, check it against your account, then approve or reject. The same transaction ID or receipt photo cannot be used by two students. Approving updates payment status only — student records are not duplicated.</p>
    <div class="profile-request-list">
        <?php foreach ($requests as $req): ?>
            <?php $receipt = trim((string) ($req['receipt'] ?? '')); ?>
            <article class="profile-request-card">
                <div class="profile-request-head">
                    <?php if (!empty($req['student_photo'])): ?>
                        <img class="stu-avatar stu-avatar-sm" src="<?= e(url((string) $req['student_photo'])) ?>" alt="">
                    <?php else: ?>
                        <span class="stu-avatar stu-avatar-sm" aria-hidden="true"><?= e(person_initials((string) $req['student_name'])) ?></span>
                    <?php endif; ?>
                    <div>
                        <strong><?= e($req['student_name']) ?></strong>
                        <p class="muted">
                            <?= !empty($req['student_code']) ? 'ID ' . e($req['student_code']) : 'Student ID not set' ?>
                            · now <?= e(status_label((string) ($req['current_payment'] ?? 'unpaid'))) ?>
                            · <?= e(format_when((string) $req['created_at'])) ?>
                        </p>
                    </div>
                </div>
                <div class="pay-verify-grid">
                    <dl class="profile-request-diff">
                        <div>
                            <dt>Paid to</dt>
                            <dd><?= e($req['account_label'] ?: 'Not specified') ?></dd>
                        </div>
                        <div>
                            <dt>Amount</dt>
                            <dd><?= e(format_etb($req['amount'] ?? null) ?: 'Not specified') ?></dd>
                        </div>
                        <div>
                            <dt>Reference</dt>
                            <dd><?= e($req['reference'] ?: '—') ?></dd>
                        </div>
                        <div>
                            <dt>They marked</dt>
                            <dd><?= e(status_label((string) ($req['claimed_status'] ?? 'paid'))) ?></dd>
                        </div>
                        <?php if (!empty($req['student_note'])): ?>
                            <div>
                                <dt>Student note</dt>
                                <dd><?= e($req['student_note']) ?></dd>
                            </div>
                        <?php endif; ?>
                    </dl>
                    <?php if ($receipt !== ''): ?>
                        <a class="pay-receipt" href="<?= e(url($receipt)) ?>" target="_blank" rel="noopener">
                            <img src="<?= e(url($receipt)) ?>" alt="Payment receipt">
                            <span>Open receipt</span>
                        </a>
                    <?php endif; ?>
                </div>
                <?php
                $conflicts = payment_request_conflict_messages($req);
                if ($conflicts):
                ?>
                    <div class="pay-dup" role="status">
                        <strong>Possible duplicate</strong>
                        <?php foreach ($conflicts as $message): ?>
                            <p><?= e($message) ?></p>
                        <?php endforeach; ?>
                        <p>Approve only the student who actually paid. Reject the shared receipt.</p>
                    </div>
                <?php endif; ?>
                <?php if ($canManage): ?>
                <div class="form-actions">
                    <form method="post" class="row-actions">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="payment_approve">
                        <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                        <select name="payment_status" aria-label="Mark payment as">
                            <option value="paid" <?= ($req['claimed_status'] ?? '') === 'paid' ? 'selected' : '' ?>>Paid</option>
                            <option value="partial" <?= ($req['claimed_status'] ?? '') === 'partial' ? 'selected' : '' ?>>Partial</option>
                        </select>
                        <button class="btn" type="submit">Approve</button>
                    </form>
                    <form method="post" class="profile-request-reject">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="payment_reject">
                        <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                        <input name="note" placeholder="Reason for the student">
                        <button class="btn btn-ghost" type="submit" data-confirm="The student stays unpaid until a valid receipt is approved." data-confirm-title="Reject this payment?" data-confirm-ok="Reject">Reject</button>
                    </form>
                </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="panel">
    <h2>What students should pay</h2>
    <p class="muted">Shown on the student Pay page. Leave amount blank if it is not set yet. Do not invent a fee.</p>
    <form method="post" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="instructions">
        <div class="form-grid">
            <div class="form-group"><label for="payment_purpose">Purpose</label><input id="payment_purpose" name="payment_purpose" value="<?= e(setting('payment_purpose')) ?>" placeholder="Uniform, graduation, class fund"></div>
            <div class="form-group"><label for="payment_amount">Amount (ETB)</label><input id="payment_amount" name="payment_amount" inputmode="decimal" value="<?= e(setting('payment_amount')) ?>" placeholder="Leave blank if not set"></div>
            <div class="form-group full"><label for="payment_instructions">Instructions</label><textarea id="payment_instructions" name="payment_instructions" rows="3" placeholder="How to pay, what to write as a reference, who to notify."><?= e(setting('payment_instructions')) ?></textarea></div>
        </div>
        <div class="form-actions" style="margin-top:1rem"><button class="btn" type="submit">Save details</button></div>
    </form>
</div>
<?php endif; ?>

<div class="panel" id="accounts">
    <h2><?= $edit ? 'Update account' : 'Class payment accounts' ?></h2>
    <p class="muted">These are the accounts students see and copy. Add the real CBE, Telebirr, or bank details. Nothing is published until you save an account here.</p>
    <?php if ($canManage): ?>
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
            <div class="form-group"><label for="display_order">Order</label><input id="display_order" name="display_order" type="number" min="0" value="<?= e((string) ($edit['display_order'] ?? '0')) ?>"></div>
            <div class="form-group full"><label for="notes">Note for students</label><input id="notes" name="notes" value="<?= e($edit['notes'] ?? '') ?>" placeholder="Branch, what to put in the remark, etc."></div>
            <div class="form-group full">
                <label class="check"><input type="checkbox" name="is_active" value="1" <?= empty($edit) || !empty($edit['is_active']) ? 'checked' : '' ?>> Visible to students</label>
            </div>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit"><?= $edit ? 'Save account' : 'Publish account' ?></button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="<?= e(url('admin/payments.php')) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
    <?php endif; ?>

    <?php if (!$accounts): ?>
        <p class="muted" style="margin-top:1rem">No payment accounts yet. Students cannot see where to pay until you add one.</p>
    <?php else: ?>
        <div class="table-wrap" style="margin-top:1rem">
            <table>
                <thead><tr><th>Account</th><th>Number</th><th>Status</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($accounts as $row): ?>
                    <tr>
                        <td>
                            <strong><?= e($row['label']) ?></strong>
                            <p class="muted"><?= e(payment_method_label((string) $row['method'])) ?><?= !empty($row['account_name']) ? ' · ' . e($row['account_name']) : '' ?></p>
                        </td>
                        <td><code><?= e($row['account_number']) ?></code></td>
                        <td><?= admin_active_badge($row['is_active'] ?? 0) ?></td>
                        <?php if ($canManage): ?>
                        <td class="row-actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/payments.php?edit=' . (int) $row['id'])) ?>">Edit</a>
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

<?php admin_footer(); ?>
