<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/student-layout.php';
require_once dirname(__DIR__) . '/includes/interactions.php';

student_boot();

$poll = poll_by_id(request_int('id'));
if (!$poll || (string) ($poll['status'] ?? '') === 'draft' || poll_effective_status($poll) === 'scheduled') {
    flash_set('error', 'That poll is not available.');
    redirect('student/polls.php');
}

$student = current_student();
$user = current_user();
$vote = $student ? poll_vote_for_student((int) $poll['id'], (int) $student['id']) : null;
$options = poll_options((int) $poll['id']);
$open = poll_is_open($poll) && $student && can('polls.vote');
$canChange = $open && ($vote ? !empty($poll['allow_change']) : true);
$showResults = poll_can_show_results($poll, (bool) $vote);
$results = $showResults ? poll_results((int) $poll['id']) : null;

if (is_post() && posted('action') === 'vote') {
    require_csrf();
    if (!can('polls.vote')) {
        deny_access();
    }
    try {
        if (!$student) {
            throw new InvalidArgumentException('Your class record is not linked, so you cannot vote.');
        }
        $ids = posted_int_list('option_ids');
        if (posted('option_id') !== '') {
            $ids[] = (int) posted('option_id');
        }
        submit_poll_vote($poll, $student, (int) ($user['id'] ?? 0), $ids);
        log_audit('poll.vote', 'poll', (int) $poll['id'], $poll['title'] ?? '');
        flash_set('success', 'Your vote has been recorded.');
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to record that vote.');
    }
    redirect('student/poll.php?id=' . (int) $poll['id']);
}

student_header(poll_kind_label($poll), poll_is_decision($poll) ? 'decisions' : 'polls', lead: (string) ($poll['title'] ?? 'Class poll'));
?>

<article class="panel poll-card<?= poll_is_decision($poll) ? ' is-decision' : '' ?>">
    <p class="eyebrow"><?= e(poll_kind_label($poll)) ?></p>
    <h2><?= e($poll['title']) ?></h2>
    <?php if (!empty($poll['description'])): ?>
        <p><?= nl2br(e((string) $poll['description'])) ?></p>
    <?php endif; ?>
    <p class="muted">
        <?= e(status_label(poll_effective_status($poll))) ?>
        <?= interaction_closes_in($poll['ends_at'] ?? null) !== '' ? ' · ' . e(interaction_closes_in($poll['ends_at'] ?? null)) : '' ?>
        <?= !empty($poll['anonymous']) ? ' · Anonymous voting' : '' ?>
        <?= ($poll['choice_type'] ?? '') === 'multiple' ? ' · Select all that apply' : '' ?>
        <?= poll_is_decision($poll) ? ' · This vote counts for the class' : '' ?>
    </p>

    <?php if ($vote): ?>
        <p class="poll-recorded">Your vote has been recorded.</p>
    <?php endif; ?>

    <?php if ($canChange): ?>
        <form method="post" class="poll-form" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="vote">
            <?php $multi = ($poll['choice_type'] ?? 'single') === 'multiple'; ?>
            <ul class="poll-options">
                <?php foreach ($options as $option): ?>
                    <li>
                        <label class="check">
                            <input type="<?= $multi ? 'checkbox' : 'radio' ?>" name="<?= $multi ? 'option_ids[]' : 'option_id' ?>" value="<?= (int) $option['id'] ?>" <?= in_array((int) $option['id'], $vote['option_ids'] ?? [], true) ? 'checked' : '' ?> <?= $multi ? '' : 'required' ?>>
                            <?= e($option['label']) ?>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="form-actions">
                <button class="btn" type="submit"><?= $vote ? 'Change vote' : 'Submit vote' ?></button>
            </div>
        </form>
    <?php elseif (!$student): ?>
        <p class="muted">Your account is not linked to a class record, so you cannot vote.</p>
    <?php elseif (!$open && !$vote): ?>
        <p class="muted">Voting is closed.</p>
    <?php endif; ?>

    <?php if ($results): ?>
        <div class="poll-result-block">
            <ul class="poll-results">
                <?php foreach ($results['options'] as $option): ?>
                    <li>
                        <span><?= e($option['label']) ?></span>
                        <span class="poll-bar"><i style="width: <?= (int) $option['percent'] ?>%"></i></span>
                        <b><?= (int) $option['percent'] ?>%</b>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="muted"><?= (int) $results['total'] ?> student<?= (int) $results['total'] === 1 ? '' : 's' ?> voted</p>
        </div>
    <?php elseif ($vote && (string) ($poll['show_results'] ?? '') === 'after_close'): ?>
        <p class="muted">Results appear when this poll closes.</p>
    <?php endif; ?>
</article>

<p><a class="text-link" href="<?= e(url(poll_is_decision($poll) ? 'student/decisions.php' : 'student/polls.php')) ?>"><?= poll_is_decision($poll) ? 'All decisions' : 'All polls' ?></a></p>

<?php student_footer(poll_is_decision($poll) ? 'decisions' : 'polls'); ?>
