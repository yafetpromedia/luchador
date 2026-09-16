<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/student-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';
require_once dirname(__DIR__) . '/includes/students.php';

student_boot();

$user = current_user();
$ownId = (int) ($user['student_id'] ?? 0);
if (request_int('id') > 0 && request_int('id') !== $ownId) {
    deny_access();
}

$student = current_student();
$fullName = (string) ($student['student_name'] ?? user_display_name($user));
$pending = $student ? pending_profile_request((int) $student['id']) : null;
$canRequest = $student && can('profile.edit_own');

if (is_post() && $student) {
    require_csrf();
    $action = posted('action');
    try {
        if ($action === 'profile_cancel') {
            cancel_profile_request((int) $student['id'], (int) ($user['id'] ?? 0));
            log_audit('profile.cancel', 'student', (int) $student['id'], $student['student_name'] ?? '');
            flash_set('success', 'Your profile update was withdrawn.');
        } elseif ($action === 'profile_request') {
            if (!$canRequest) {
                deny_access();
            }
            submit_profile_request($student, $_POST, $_FILES, (int) ($user['id'] ?? 0));
            log_audit('profile.request', 'student', (int) $student['id'], $student['student_name'] ?? '');
            notify_staff([
                'type' => 'profile.request',
                'title' => 'Profile update waiting',
                'body' => (string) ($student['student_name'] ?? 'A student') . ' sent a change for approval.',
                'icon' => 'users',
                'url' => 'admin/students.php',
                'target_type' => 'student',
                'target_id' => (int) $student['id'],
            ]);
            flash_set('success', 'Sent for approval. Your profile stays as it is until a class administrator approves it.');
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to send that update. Please try again.');
    }
    redirect('student/profile.php');
}

$pendingPhoto = $pending ? student_photo_url($pending) : '';
$code = trim((string) ($student['student_code'] ?? ''));
$size = trim((string) ($student['size'] ?? ''));
$section = trim((string) ($student['section'] ?? ''));
$grade = trim((string) ($student['grade'] ?? '')) ?: class_grade();
$uniformStatus = (string) ($student['status'] ?? 'pending');
$payStatus = (string) ($student['payment_status'] ?? 'unpaid');
$phone = trim((string) ($student['phone_number'] ?? ''));
$mother = trim((string) ($student['mother_name'] ?? ''));
$motherPhone = trim((string) ($student['mother_phone'] ?? ''));
$father = trim((string) ($student['father_name'] ?? ''));
$fatherPhone = trim((string) ($student['father_phone'] ?? ''));
$username = trim((string) ($user['username'] ?? ''));
$lastLogin = !empty($user['last_login_at']) ? format_when((string) $user['last_login_at']) : '';

$onFile = static function (string $value): string {
    return $value !== '' ? $value : 'Not on file';
};
$familyLine = static function (string $name, string $phone): string {
    $name = trim($name);
    $phone = trim($phone);
    if ($name === '' && $phone === '') {
        return '';
    }
    return trim($name . ($name !== '' && $phone !== '' ? ' · ' : '') . $phone);
};

student_header('Profile', 'profile', lead: 'Your class ID. Name and student ID stay with the office.');
?>

<?php if (!$student): ?>
    <div class="empty-state">
        <h2>Your class record is not linked</h2>
        <p>Ask a class administrator to connect this account to your roster. Nothing is shown until then.</p>
    </div>
<?php else: ?>
    <section class="stu-id-card">
        <?php if (!empty($student['photo'])): ?>
            <img class="stu-avatar stu-id-photo" src="<?= e(url($student['photo'])) ?>" alt="">
        <?php else: ?>
            <span class="stu-avatar stu-id-photo" aria-hidden="true"><?= e(person_initials($fullName)) ?></span>
        <?php endif; ?>
        <div class="stu-id-card-copy">
            <p class="eyebrow"><?= e(class_name()) ?> · Grade <?= e($grade) ?></p>
            <h2><?= e($fullName) ?></h2>
            <p class="muted"><?= e(school_name()) ?><?= $section !== '' ? ' · ' . e($section) : '' ?></p>
            <?php if ($code !== '' || $size !== ''): ?>
                <ul class="stu-chips">
                    <?php if ($code !== ''): ?><li>ID <?= e($code) ?></li><?php endif; ?>
                    <?php if ($size !== ''): ?><li>Uniform <?= e($size) ?></li><?php endif; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>

    <nav class="stu-status stu-status-3" aria-label="Your record">
        <a href="<?= e(url('student/payment.php')) ?>">
            <?= icon('wallet', 18) ?>
            <strong>Payment</strong>
            <span><?= e(status_label($payStatus)) ?></span>
        </a>
        <div>
            <?= icon('shirt', 18) ?>
            <strong>Uniform</strong>
            <span><?= e($size !== '' ? $size . ' · ' . status_label($uniformStatus) : status_label($uniformStatus)) ?></span>
        </div>
        <a href="<?= e(url('student/account.php')) ?>">
            <?= icon('lock', 18) ?>
            <strong>Sign-in</strong>
            <span><?= e($lastLogin !== '' ? $lastLogin : ($username !== '' ? $username : 'Password')) ?></span>
        </a>
    </nav>

    <section class="panel stu-profile-card">
        <p class="eyebrow">On file</p>
        <h2>Contact</h2>
        <div class="stu-profile-fields">
            <div>
                <span>Your phone</span>
                <strong class="<?= $phone === '' ? 'is-empty' : '' ?>"><?= e($onFile($phone)) ?></strong>
            </div>
            <div>
                <span>Mother</span>
                <?php $motherLine = $familyLine($mother, $motherPhone); ?>
                <strong class="<?= $motherLine === '' ? 'is-empty' : '' ?>"><?= e($motherLine !== '' ? $motherLine : 'Not on file') ?></strong>
            </div>
            <div>
                <span>Father</span>
                <?php $fatherLine = $familyLine($father, $fatherPhone); ?>
                <strong class="<?= $fatherLine === '' ? 'is-empty' : '' ?>"><?= e($fatherLine !== '' ? $fatherLine : 'Not on file') ?></strong>
            </div>
        </div>
    </section>

    <?php if ($pending): ?>
        <section class="profile-pending">
            <p class="eyebrow">Waiting for approval</p>
            <p>A class administrator still needs to approve this update. What the class sees does not change until then.</p>
            <div class="stu-profile-fields">
                <?php if ($pendingPhoto !== ''): ?>
                    <div>
                        <span>New photo</span>
                        <strong><img class="stu-avatar stu-avatar-sm" src="<?= e($pendingPhoto) ?>" alt=""></strong>
                    </div>
                <?php elseif (!empty($pending['remove_photo'])): ?>
                    <div><span>Photo</span><strong>Remove current photo</strong></div>
                <?php endif; ?>
                <div>
                    <span>Your phone</span>
                    <strong><?= e($onFile(trim((string) ($pending['phone_number'] ?? '')))) ?></strong>
                </div>
                <div>
                    <span>Mother</span>
                    <?php $pMother = $familyLine((string) ($pending['mother_name'] ?? ''), (string) ($pending['mother_phone'] ?? '')); ?>
                    <strong class="<?= $pMother === '' ? 'is-empty' : '' ?>"><?= e($pMother !== '' ? $pMother : 'Not on file') ?></strong>
                </div>
                <div>
                    <span>Father</span>
                    <?php $pFather = $familyLine((string) ($pending['father_name'] ?? ''), (string) ($pending['father_phone'] ?? '')); ?>
                    <strong class="<?= $pFather === '' ? 'is-empty' : '' ?>"><?= e($pFather !== '' ? $pFather : 'Not on file') ?></strong>
                </div>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="profile_cancel">
                <button class="btn btn-ghost" type="submit">Withdraw update</button>
            </form>
        </section>
    <?php elseif ($canRequest): ?>
        <details class="stu-profile-edit">
            <summary>Request a change</summary>
            <p class="muted">Name, student ID, and uniform stay with the class office. Photo and family details are sent for approval first.</p>
            <form method="post" enctype="multipart/form-data" data-loading>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="profile_request">
                <div class="form-grid">
                    <div class="form-group full">
                        <label for="photo">Photo</label>
                        <label class="stu-file">
                            <input id="photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif">
                            <span class="stu-file-icon"><?= icon('camera', 18) ?></span>
                            <span class="stu-file-copy">
                                <strong>Choose a photo</strong>
                                <span data-file-name data-empty="JPEG, PNG, WebP, or GIF">JPEG, PNG, WebP, or GIF</span>
                            </span>
                        </label>
                        <?php if (!empty($student['photo'])): ?>
                            <label class="check"><input type="checkbox" name="remove_photo" value="1"> Remove current photo</label>
                        <?php endif; ?>
                    </div>
                    <div class="form-group"><label for="phone_number">Student phone</label><input id="phone_number" type="tel" name="phone_number" inputmode="tel" autocomplete="tel" placeholder="09…" value="<?= e($phone) ?>"></div>
                    <div class="form-group"><label for="mother_name">Mother’s name</label><input id="mother_name" type="text" name="mother_name" autocomplete="off" placeholder="Full name" value="<?= e($mother) ?>"></div>
                    <div class="form-group"><label for="mother_phone">Mother’s phone</label><input id="mother_phone" type="tel" name="mother_phone" inputmode="tel" autocomplete="tel" placeholder="09…" value="<?= e($motherPhone) ?>"></div>
                    <div class="form-group"><label for="father_name">Father’s name</label><input id="father_name" type="text" name="father_name" autocomplete="off" placeholder="Full name" value="<?= e($father) ?>"></div>
                    <div class="form-group"><label for="father_phone">Father’s phone</label><input id="father_phone" type="tel" name="father_phone" inputmode="tel" autocomplete="tel" placeholder="09…" value="<?= e($fatherPhone) ?>"></div>
                </div>
                <div class="form-actions" style="margin-top:1rem">
                    <button class="btn" type="submit">Send for approval</button>
                </div>
            </form>
        </details>
    <?php else: ?>
        <p class="muted">Personal details are read-only. Ask a class administrator if something needs to be corrected.</p>
    <?php endif; ?>
<?php endif; ?>

<?php student_footer('profile'); ?>
