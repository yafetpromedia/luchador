<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/student-layout.php';
require_once dirname(__DIR__) . '/includes/interactions.php';

student_boot();

$question = question_by_id(request_int('id'));
if (!$question || !question_visible_to_students($question)) {
    flash_set('error', 'That question is not available.');
    redirect('student/questions.php');
}

$student = current_student();
$user = current_user();
$mine = $student ? question_response_for_student((int) $question['id'], (int) $student['id']) : null;
$open = (string) ($question['status'] ?? '') === 'open' && $student && can('questions.respond');
$canChange = $open && ($mine ? !empty($question['allow_change']) : true);
$kind = (string) ($question['kind'] ?? 'open');
$options = $kind === 'choices' ? question_options((int) $question['id']) : [];
$responses = question_responses((int) $question['id']);
$choiceResults = $kind === 'choices' ? question_choice_results((int) $question['id']) : null;
$showChoices = $kind === 'choices' && ($mine || (string) ($question['status'] ?? '') === 'closed');

if (is_post() && posted('action') === 'respond') {
    require_csrf();
    if (!can('questions.respond')) {
        deny_access();
    }
    try {
        if (!$student) {
            throw new InvalidArgumentException('Your class record is not linked.');
        }
        submit_question_response($question, $student, (int) ($user['id'] ?? 0), $_POST);
        log_audit('question.respond', 'question', (int) $question['id'], $question['title'] ?? '');
        flash_set('success', !empty($question['moderate']) ? 'Answer sent for approval.' : 'Your answer has been recorded.');
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save that answer.');
    }
    redirect('student/question.php?id=' . (int) $question['id']);
}

student_header('Question', 'questions', lead: (string) ($question['title'] ?? 'Class question'));
?>

<article class="panel poll-card">
    <p class="eyebrow"><?= e(question_kinds()[$kind] ?? 'Class question') ?></p>
    <h2><?= e($question['title']) ?></h2>
    <?php if (!empty($question['body'])): ?>
        <p><?= nl2br(e((string) $question['body'])) ?></p>
    <?php endif; ?>
    <p class="muted"><?= count($responses) ?> response<?= count($responses) === 1 ? '' : 's' ?><?= (string) ($question['status'] ?? '') === 'closed' ? ' · Closed' : '' ?></p>

    <?php if (!empty($question['official_answer'])): ?>
        <div class="official-answer">
            <p class="eyebrow">Committee</p>
            <p><?= nl2br(e((string) $question['official_answer'])) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($canChange): ?>
        <form method="post" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="respond">
            <?php if ($kind === 'choices'): ?>
                <ul class="poll-options">
                    <?php foreach ($options as $option): ?>
                        <li>
                            <label class="check">
                                <input type="radio" name="option_id" value="<?= (int) $option['id'] ?>" required <?= in_array((int) $option['id'], $mine['option_ids'] ?? [], true) ? 'checked' : '' ?>>
                                <?= e($option['label']) ?>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="form-group">
                    <label class="req">Your answer</label>
                    <textarea name="body" rows="4" required placeholder="Write your answer"><?= e((string) ($mine['body'] ?? '')) ?></textarea>
                </div>
                <?php if (!empty($question['allow_anonymous'])): ?>
                    <label class="check"><input type="checkbox" name="is_anonymous" value="1" <?= !empty($mine['is_anonymous']) ? 'checked' : '' ?>> Answer anonymously</label>
                <?php endif; ?>
            <?php endif; ?>
            <div class="form-actions" style="margin-top:1rem">
                <button class="btn" type="submit"><?= $mine ? 'Update answer' : 'Submit your answer' ?></button>
            </div>
        </form>
    <?php elseif ($mine && !empty($question['moderate']) && ($mine['status'] ?? '') === 'pending'): ?>
        <p class="muted">Your answer is waiting for approval.</p>
    <?php endif; ?>

    <?php if ($showChoices && $choiceResults): ?>
        <ul class="poll-results">
            <?php foreach ($choiceResults['options'] as $option): ?>
                <li>
                    <span><?= e($option['label']) ?></span>
                    <span class="poll-bar"><i style="width: <?= (int) $option['percent'] ?>%"></i></span>
                    <b><?= (int) $option['percent'] ?>%</b>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</article>

<?php if ($kind !== 'choices' && $responses): ?>
<section class="panel">
    <h2>Class answers</h2>
    <ol class="response-list">
        <?php foreach ($responses as $item): ?>
            <li>
                <p class="muted">
                    <?= e(response_display_name($item, false)) ?>
                    <?php if (!empty($item['useful'])): ?> · Useful<?php endif; ?>
                    <?php if ((int) ($question['pin_response_id'] ?? 0) === (int) $item['id']): ?> · Pinned<?php endif; ?>
                </p>
                <p><?= nl2br(e((string) ($item['body'] ?? ''))) ?></p>
            </li>
        <?php endforeach; ?>
    </ol>
</section>
<?php endif; ?>

<p><a class="text-link" href="<?= e(url('student/questions.php')) ?>">All questions</a></p>

<?php student_footer('questions'); ?>
