<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/student-layout.php';
require_once dirname(__DIR__) . '/includes/interactions.php';

student_boot();

$student = current_student();
$user = current_user();

if (is_post() && posted('action') === 'ask') {
    require_csrf();
    if (!can('questions.ask')) {
        deny_access();
    }
    try {
        if (!$student) {
            throw new InvalidArgumentException('Your class record is not linked.');
        }
        submit_student_ask($student, (int) ($user['id'] ?? 0), $_POST);
        log_audit('question.ask', 'question', null, posted('title'));
        flash_set('success', 'Sent to the committee. It stays private until they approve it.');
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to send that question.');
    }
    redirect('student/questions.php');
}

$items = can('questions.view') ? visible_questions() : [];
student_header('Questions', 'questions', lead: 'Answer class prompts, or ask the committee.');
?>

<?php if (can('questions.ask') && $student): ?>
<section class="panel">
    <p class="eyebrow">Ask the committee</p>
    <h2>Send a question or suggestion</h2>
    <p class="muted">Your name is hidden if you send it anonymously. The class office still keeps an internal record for moderation.</p>
    <form method="post" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ask">
        <div class="form-grid">
            <div class="form-group">
                <label for="kind">Type</label>
                <select id="kind" name="kind">
                    <option value="qa">Ask the committee</option>
                    <option value="suggestion">Suggestion</option>
                </select>
            </div>
            <div class="form-group full"><label class="req" for="question_title">Your question</label><input id="question_title" type="text" name="title" required maxlength="180" placeholder="Ask the committee"></div>
            <div class="form-group full"><label for="question_body">Details</label><textarea id="question_body" name="body" rows="4" placeholder="Optional context"></textarea></div>
            <div class="form-group full"><label class="check"><input type="checkbox" name="allow_anonymous" value="1"> Send anonymously</label></div>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit">Send</button>
        </div>
    </form>
</section>
<?php endif; ?>

<?php if (!can('questions.view')): ?>
    <div class="empty-state">
        <h2>Questions are not available</h2>
        <p>Ask a class administrator if you should be able to take part.</p>
    </div>
<?php elseif (!$items): ?>
    <div class="empty-state">
        <h2>No class questions yet</h2>
        <p>Prompts from the committee appear here once they are opened.</p>
    </div>
<?php else: ?>
    <div class="stu-stack">
        <?php foreach ($items as $item): ?>
            <?php
            $countStmt = db()->prepare("SELECT COUNT(*) FROM question_responses WHERE question_id = ? AND status = 'visible'");
            $countStmt->execute([(int) $item['id']]);
            $n = (int) $countStmt->fetchColumn();
            ?>
            <a class="panel interact-link" href="<?= e(url('student/question.php?id=' . (int) $item['id'])) ?>">
                <p class="eyebrow"><?= e(question_kinds()[$item['kind'] ?? 'open'] ?? 'Question') ?></p>
                <h2><?= e($item['title']) ?></h2>
                <p class="muted"><?= (int) $n ?> response<?= $n === 1 ? '' : 's' ?> · <?= e(status_label((string) ($item['status'] ?? 'open'))) ?></p>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php student_footer('questions'); ?>
