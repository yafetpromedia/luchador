<?php

declare(strict_types=1);

function log_audit(string $action, ?string $targetType = null, ?int $targetId = null, ?string $detail = null): void
{
    try {
        if (!table_exists(db(), 'audit_logs')) {
            return;
        }
        $actor = user_display_name();
        $userId = current_user()['id'] ?? null;
        $ip = client_ip();
        $hasUser = column_exists(db(), 'audit_logs', 'user_id');
        $hasIp = column_exists(db(), 'audit_logs', 'ip');
        if ($hasUser && $hasIp) {
            db()->prepare(
                'INSERT INTO audit_logs (actor, action, target_type, target_id, detail, user_id, ip) VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $actor,
                $action,
                $targetType,
                $targetId,
                $detail !== null ? excerpt($detail, 240) : null,
                $userId,
                $ip,
            ]);
        } else {
            db()->prepare(
                'INSERT INTO audit_logs (actor, action, target_type, target_id, detail) VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $actor,
                $action,
                $targetType,
                $targetId,
                $detail !== null ? excerpt($detail, 240) : null,
            ]);
        }
    } catch (Throwable $e) {
        app_log('audit: ' . $e->getMessage());
    }
}

function recent_audit(int $limit = 8): array
{
    try {
        if (!table_exists(db(), 'audit_logs')) {
            return [];
        }
        return db()->query('SELECT * FROM audit_logs ORDER BY id DESC LIMIT ' . (int) $limit)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function audit_label(array $row): string
{
    $map = [
        'student.create' => 'Added a student',
        'student.update' => 'Updated a student',
        'student.delete' => 'Deleted student records',
        'student.import' => 'Imported students',
        'uniform.status' => 'Updated a uniform status',
        'report.generate' => 'Generated a report',
        'event.save' => 'Saved an event',
        'event.delete' => 'Deleted an event',
        'announcement.save' => 'Saved an announcement',
        'announcement.delete' => 'Deleted an announcement',
        'gallery.upload' => 'Uploaded a gallery image',
        'gallery.delete' => 'Deleted a gallery image',
        'gallery.toggle' => 'Changed gallery visibility',
        'achievement.save' => 'Saved an achievement',
        'achievement.delete' => 'Deleted an achievement',
        'committee.save' => 'Saved a committee member',
        'committee.delete' => 'Deleted a committee member',
        'settings.update' => 'Updated class settings',
        'password.update' => 'Changed the administrator password',
        'timeline.save' => 'Saved a journey milestone',
        'timeline.delete' => 'Deleted a journey milestone',
        'memory.save' => 'Saved a memory',
        'memory.delete' => 'Deleted a memory',
        'spotlight.save' => 'Saved a student spotlight',
        'spotlight.delete' => 'Deleted a student spotlight',
        'message.save' => 'Saved a class message',
        'message.delete' => 'Deleted a class message',
        'user.create' => 'Created a user',
        'user.update' => 'Updated a user',
        'user.disable' => 'Deactivated a user',
        'user.enable' => 'Activated a user',
        'user.password_reset' => 'Reset a user password',
        'user.login' => 'Signed in',
        'user.logout' => 'Signed out',
        'student_accounts.generate' => 'Generated student accounts',
        'payment.account_save' => 'Saved a payment account',
        'payment.account_delete' => 'Deleted a payment account',
        'payment.item_save' => 'Saved a payment item',
        'payment.instructions' => 'Updated payment instructions',
        'payment.request' => 'Submitted payment proof',
        'payment.approve' => 'Approved a payment',
        'payment.verify' => 'Verified a payment',
        'payment.reject' => 'Rejected a payment',
        'payment.reopen' => 'Reopened a payment for review',
        'payment.note' => 'Updated a payment note',
        'payment.archive' => 'Archived a payment',
        'payment.export' => 'Exported payments',
        'payment.cancel' => 'Withdrew payment proof',
        'role.create' => 'Created a role',
        'role.update' => 'Updated a role',
        'role.delete' => 'Deleted a role',
        'permissions.update' => 'Updated user permissions',
        'poll.save' => 'Saved a poll',
        'poll.delete' => 'Deleted a poll',
        'poll.close' => 'Closed a poll',
        'question.save' => 'Saved a class question',
        'question.delete' => 'Deleted a class question',
        'question.moderate' => 'Moderated a class question',
        'poll.vote' => 'Voted in a poll',
        'question.respond' => 'Answered a class question',
        'question.ask' => 'Asked the committee',
    ];
    return $map[$row['action'] ?? ''] ?? ($row['action'] ?? 'Activity');
}

function audit_sentence(array $row): string
{
    $actor = trim((string) ($row['actor'] ?? '')) ?: 'Someone';
    $detail = trim((string) ($row['detail'] ?? ''));
    $verbs = [
        'student.create' => 'added student',
        'student.update' => 'updated student',
        'student.delete' => 'deleted',
        'student.import' => 'imported students',
        'uniform.status' => 'updated uniform status for',
        'report.generate' => 'generated a report',
        'event.save' => 'saved event',
        'event.delete' => 'deleted event',
        'announcement.save' => 'saved announcement',
        'announcement.delete' => 'deleted announcement',
        'gallery.upload' => 'uploaded gallery image',
        'gallery.delete' => 'deleted gallery image',
        'gallery.toggle' => 'changed gallery visibility for',
        'achievement.save' => 'saved achievement',
        'achievement.delete' => 'deleted achievement',
        'committee.save' => 'saved committee member',
        'committee.delete' => 'deleted committee member',
        'settings.update' => 'updated class settings',
        'password.update' => 'changed their password',
        'timeline.save' => 'saved journey milestone',
        'timeline.delete' => 'deleted journey milestone',
        'memory.save' => 'saved a memory',
        'memory.delete' => 'deleted a memory',
        'spotlight.save' => 'saved spotlight',
        'spotlight.delete' => 'deleted spotlight',
        'message.save' => 'saved class message',
        'message.delete' => 'deleted class message',
        'user.create' => 'created user',
        'user.update' => 'updated user',
        'user.disable' => 'deactivated user',
        'user.enable' => 'activated user',
        'user.password_reset' => 'reset password for',
        'user.login' => 'signed in',
        'user.logout' => 'signed out',
        'student_accounts.generate' => 'generated student accounts',
        'profile.request' => 'sent a profile update for',
        'profile.approve' => 'approved a profile update for',
        'profile.reject' => 'rejected a profile update for',
        'profile.cancel' => 'withdrew a profile update for',
        'payment.account_save' => 'saved payment account',
        'payment.account_delete' => 'deleted payment account',
        'payment.item_save' => 'saved payment item',
        'payment.instructions' => 'updated payment instructions',
        'payment.request' => 'submitted payment proof for',
        'payment.approve' => 'approved payment for',
        'payment.verify' => 'verified a',
        'payment.reject' => 'rejected a',
        'payment.reopen' => 'reopened a',
        'payment.note' => 'updated the note on',
        'payment.archive' => 'archived a',
        'payment.export' => 'exported payments',
        'payment.cancel' => 'withdrew payment proof for',
        'role.create' => 'created role',
        'role.update' => 'updated role',
        'role.delete' => 'deleted role',
        'permissions.update' => 'updated permissions for',
    ];
    $verb = $verbs[$row['action'] ?? ''] ?? strtolower(audit_label($row));
    if ($detail === '' || ($row['action'] ?? '') === 'password.update') {
        return $actor . ' ' . $verb;
    }
    return $actor . ' ' . $verb . ' ' . $detail;
}
