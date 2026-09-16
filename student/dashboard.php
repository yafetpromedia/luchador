<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/student-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';
require_once dirname(__DIR__) . '/includes/interactions.php';

student_boot();

$student = current_student();
$fullName = $student['student_name'] ?? user_display_name();
$name = first_name($fullName);
$announcements = table_exists(db(), 'announcements') ? published_announcements(3) : [];
$nextEvent = next_published_event();
$photos = table_exists(db(), 'gallery') ? array_slice(published_gallery(), 0, 4) : [];
$gradDate = setting('graduation_date');
$homeDecision = can('polls.view') ? home_decision() : null;
$homePoll = can('polls.view') ? home_poll() : null;
$homeQuestion = can('questions.view') ? home_question() : null;
$voteBlocks = array_values(array_filter([$homeDecision, $homePoll]));
$questionMine = ($homeQuestion && $student) ? question_response_for_student((int) $homeQuestion['id'], (int) $student['id']) : null;
$questionOptions = ($homeQuestion && ($homeQuestion['kind'] ?? '') === 'choices') ? question_options((int) $homeQuestion['id']) : [];
$questionCount = 0;
if ($homeQuestion) {
    $countStmt = db()->prepare("SELECT COUNT(*) FROM question_responses WHERE question_id = ? AND status = 'visible'");
    $countStmt->execute([(int) $homeQuestion['id']]);
    $questionCount = (int) $countStmt->fetchColumn();
}
$hasFeed = (bool) ($nextEvent || $announcements || $photos || $voteBlocks || $homeQuestion);
$studentCode = trim((string) ($student['student_code'] ?? ''));
$uniformSize = trim((string) ($student['size'] ?? ''));
$payStatus = (string) ($student['payment_status'] ?? 'unpaid');

student_header('Home', 'dashboard', true);
?>

<section class="stu-hero">
    <div class="stu-hero-motion" aria-hidden="true">
        <span class="stu-hero-mark"><?= e(class_grade()) ?></span>
        <span class="stu-hero-glow stu-hero-glow-a"></span>
        <span class="stu-hero-glow stu-hero-glow-b"></span>
    </div>
    <div class="stu-hero-inner">
        <p class="stu-hero-kicker"><?= e(school_name()) ?></p>
        <h2><?= e(greeting()) ?>, <?= e($name) ?>.</h2>
        <p class="stu-hero-lede">Vote, answer, and stay with the class — not only when someone asks you to log in.</p>
        <?php if ($student && ($studentCode !== '' || $uniformSize !== '')): ?>
            <ul class="stu-chips">
                <?php if ($studentCode !== ''): ?>
                    <li>ID <?= e($studentCode) ?></li>
                <?php endif; ?>
                <?php if ($uniformSize !== ''): ?>
                    <li>Uniform <?= e($uniformSize) ?></li>
                <?php endif; ?>
            </ul>
        <?php elseif (!$student): ?>
            <p class="stu-hero-note">Your class record is not linked yet. Ask a class administrator.</p>
        <?php endif; ?>
        <?php if ($gradDate): ?>
            <?php render_countdown($gradDate, 'on-dark'); ?>
        <?php endif; ?>
    </div>
</section>

<nav class="stu-status" aria-label="Your class status">
    <a href="<?= e(url('student/payment.php')) ?>">
        <?= icon('wallet', 18) ?>
        <strong>Payment</strong>
        <span><?= $student ? e(status_label($payStatus)) : 'Not linked' ?></span>
    </a>
    <a href="<?= e(url('student/events.php')) ?>">
        <?= icon('calendar', 18) ?>
        <strong>Next event</strong>
        <span><?= $nextEvent ? e($nextEvent['title']) : 'None published' ?></span>
    </a>
    <a href="<?= e(url('student/graduation.php')) ?>">
        <?= icon('graduation', 18) ?>
        <strong>Graduation</strong>
        <span><?= $gradDate ? e(format_date($gradDate)) : 'Date not set' ?></span>
    </a>
    <a href="<?= e(url('student/profile.php')) ?>">
        <?= icon('users', 18) ?>
        <strong>Profile</strong>
        <span><?= $studentCode !== '' ? 'ID ' . e($studentCode) : 'Your details' ?></span>
    </a>
</nav>

<?php foreach ($voteBlocks as $homePoll): ?>
    <?php
    $isDecision = poll_is_decision($homePoll);
    $pollVote = $student ? poll_vote_for_student((int) $homePoll['id'], (int) $student['id']) : null;
    $pollOptions = poll_options((int) $homePoll['id']);
    $pollResults = poll_can_show_results($homePoll, (bool) $pollVote) ? poll_results((int) $homePoll['id']) : null;
    $pollOpen = poll_is_open($homePoll) && $student && can('polls.vote');
    $pollCanVote = $pollOpen && ($pollVote ? !empty($homePoll['allow_change']) : true);
    $pollCloses = interaction_closes_in($homePoll['ends_at'] ?? null);
    $pollMulti = ($homePoll['choice_type'] ?? 'single') === 'multiple';
    ?>
    <section class="stu-block interact-home">
        <div class="stu-block-head">
            <p class="eyebrow"><?= $isDecision ? 'Class decision' : 'Vote now' ?></p>
            <a class="text-link" href="<?= e(url($isDecision ? 'student/decisions.php' : 'student/polls.php')) ?>"><?= $isDecision ? 'All decisions' : 'All polls' ?></a>
        </div>
        <article class="panel poll-card<?= $isDecision ? ' is-decision' : '' ?>">
            <h2><?= e($homePoll['title']) ?></h2>
            <p class="muted">
                <?= $isDecision ? 'This vote counts for the class' : (!empty($homePoll['anonymous']) ? 'Anonymous' : 'Named vote') ?>
                <?= $pollCloses !== '' ? ' · ' . e($pollCloses) : '' ?>
            </p>
            <?php if ($pollVote): ?>
                <p class="poll-recorded">Your vote has been recorded.</p>
            <?php endif; ?>
            <?php if ($pollCanVote && !$pollMulti): ?>
                <form method="post" action="<?= e(url('student/poll.php?id=' . (int) $homePoll['id'])) ?>" class="vote-picks" data-loading>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="vote">
                    <?php foreach ($pollOptions as $option): ?>
                        <button class="vote-pick" type="submit" name="option_id" value="<?= (int) $option['id'] ?>"><?= e($option['label']) ?></button>
                    <?php endforeach; ?>
                </form>
            <?php elseif ($pollCanVote): ?>
                <p><a class="btn" href="<?= e(url('student/poll.php?id=' . (int) $homePoll['id'])) ?>">Choose your answers</a></p>
            <?php else: ?>
                <p><a class="text-link" href="<?= e(url('student/poll.php?id=' . (int) $homePoll['id'])) ?>">Open <?= $isDecision ? 'decision' : 'poll' ?> <?= icon('arrow-right', 16) ?></a></p>
            <?php endif; ?>
            <?php if ($pollResults): ?>
                <ul class="poll-results">
                    <?php foreach ($pollResults['options'] as $option): ?>
                        <li>
                            <span><?= e($option['label']) ?></span>
                            <span class="poll-bar"><i style="width: <?= (int) $option['percent'] ?>%"></i></span>
                            <b><?= (int) $option['percent'] ?>%</b>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="muted"><?= (int) $pollResults['total'] ?> student<?= (int) $pollResults['total'] === 1 ? '' : 's' ?> voted</p>
            <?php endif; ?>
        </article>
    </section>
<?php endforeach; ?>

<?php if ($homeQuestion): ?>
    <?php
    $qKind = (string) ($homeQuestion['kind'] ?? 'open');
    $qOpen = (string) ($homeQuestion['status'] ?? '') === 'open' && $student && can('questions.respond');
    $qCan = $qOpen && ($questionMine ? !empty($homeQuestion['allow_change']) : true);
    ?>
    <section class="stu-block interact-home">
        <div class="stu-block-head">
            <p class="eyebrow">Question of the week</p>
            <a class="text-link" href="<?= e(url('student/questions.php')) ?>">All questions</a>
        </div>
        <article class="panel poll-card">
            <h2><?= e($homeQuestion['title']) ?></h2>
            <p class="muted"><?= (int) $questionCount ?> response<?= $questionCount === 1 ? '' : 's' ?></p>
            <?php if ($qCan && $qKind === 'choices'): ?>
                <form method="post" action="<?= e(url('student/question.php?id=' . (int) $homeQuestion['id'])) ?>" class="vote-picks" data-loading>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="respond">
                    <?php foreach ($questionOptions as $option): ?>
                        <button class="vote-pick" type="submit" name="option_id" value="<?= (int) $option['id'] ?>"><?= e($option['label']) ?></button>
                    <?php endforeach; ?>
                </form>
            <?php elseif ($qCan): ?>
                <form method="post" action="<?= e(url('student/question.php?id=' . (int) $homeQuestion['id'])) ?>" data-loading>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="respond">
                    <div class="form-group">
                        <label class="req" for="home-answer">Write your answer</label>
                        <textarea id="home-answer" name="body" required><?= e((string) ($questionMine['body'] ?? '')) ?></textarea>
                    </div>
                    <?php if (!empty($homeQuestion['allow_anonymous'])): ?>
                        <label class="check"><input type="checkbox" name="is_anonymous" value="1" <?= !empty($questionMine['is_anonymous']) ? 'checked' : '' ?>> Answer anonymously</label>
                    <?php endif; ?>
                    <div class="form-actions" style="margin-top:1rem">
                        <button class="btn" type="submit"><?= $questionMine ? 'Update answer' : 'Submit your answer' ?></button>
                    </div>
                </form>
            <?php else: ?>
                <p><a class="text-link" href="<?= e(url('student/question.php?id=' . (int) $homeQuestion['id'])) ?>">Open question <?= icon('arrow-right', 16) ?></a></p>
            <?php endif; ?>
        </article>
    </section>
<?php endif; ?>

<?php if ($nextEvent): ?>
    <section class="stu-block">
        <div class="stu-block-head">
            <p class="eyebrow">Next up</p>
            <a class="text-link" href="<?= e(url('student/calendar.php')) ?>">Calendar</a>
        </div>
        <article class="feature-block">
            <?php if (!empty($nextEvent['event_date'])): ?>
                <p class="feature-date">
                    <span><?= e(format_date($nextEvent['event_date'], 'D')) ?></span>
                    <b><?= e(format_date($nextEvent['event_date'], 'j')) ?></b>
                    <span><?= e(format_date($nextEvent['event_date'], 'M')) ?></span>
                </p>
            <?php endif; ?>
            <div class="feature-copy">
                <h2><?= e($nextEvent['title']) ?></h2>
                <p class="muted">
                    <?= !empty($nextEvent['event_time']) ? e($nextEvent['event_time']) : 'Time to be announced' ?>
                    <?= !empty($nextEvent['location']) ? ' · ' . e($nextEvent['location']) : '' ?>
                </p>
                <a class="text-link" href="<?= e(url('student/event.php?id=' . (int) $nextEvent['id'])) ?>">Event details <?= icon('arrow-right', 16) ?></a>
            </div>
        </article>
    </section>
<?php endif; ?>

<?php if ($announcements): ?>
    <section class="stu-block">
        <div class="stu-block-head">
            <p class="eyebrow">Class updates</p>
            <a class="text-link" href="<?= e(url('student/announcements.php')) ?>">All updates</a>
        </div>
        <ol class="editorial-list">
            <?php foreach ($announcements as $i => $item): ?>
                <li>
                    <a href="<?= e(url('student/announcements.php')) ?>">
                        <span class="editorial-index"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                        <div>
                            <strong><?= e($item['title']) ?></strong>
                            <p class="muted"><?= e(format_relative($item['announced_on'] ?? $item['created_at'] ?? null) ?: 'Class update') ?></p>
                        </div>
                    </a>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
<?php endif; ?>

<?php if ($photos): ?>
    <section class="stu-block">
        <div class="stu-block-head">
            <p class="eyebrow">From the gallery</p>
            <a class="text-link" href="<?= e(url('student/gallery.php')) ?>">Open gallery</a>
        </div>
        <div class="stu-photos">
            <?php foreach ($photos as $item): ?>
                <a href="<?= e(url($item['image_path'])) ?>" data-lightbox data-caption="<?= e($item['caption'] ?: $item['title']) ?>">
                    <img src="<?= e(url($item['image_path'])) ?>" alt="<?= e($item['title']) ?>" loading="lazy">
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if (!$hasFeed): ?>
    <section class="stu-quiet">
        <p class="eyebrow">Class feed</p>
        <h2>This year is still being written.</h2>
        <p>Announcements, events, and photos appear here once they are published. Nothing is invented.</p>
    </section>
<?php endif; ?>

<?php student_footer('dashboard'); ?>
