<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/interactions.php';

admin_boot(['questions.view', 'questions.create', 'questions.moderate', 'questions.delete', 'questions.close']);

$edit = null;
$editId = request_int('edit') ?: (posted('id') !== '' ? (int) posted('id') : 0);
if ($editId) {
    $edit = question_by_id($editId);
}

if (is_post()) {
    require_csrf();
    $action = posted('action');
    try {
        if ($action === 'delete') {
            require_permission('questions.delete');
            $id = request_int('id');
            $row = question_by_id($id);
            delete_question($id);
            log_audit('question.delete', 'question', $id, $row['title'] ?? '');
            flash_set('success', 'Question deleted.');
            redirect('admin/questions.php');
        } elseif ($action === 'close' || $action === 'open' || $action === 'approve_ask') {
            require_permission($action === 'approve_ask' ? 'questions.moderate' : 'questions.close');
            $id = request_int('id');
            $row = question_by_id($id);
            if (!$row) {
                throw new InvalidArgumentException('Question not found.');
            }
            $status = $action === 'close' ? 'closed' : 'open';
            db()->prepare('UPDATE questions SET status = ? WHERE id = ?')->execute([$status, $id]);
            log_audit('question.save', 'question', $id, $row['title'] ?? '');
            if ($status === 'open' && (string) ($row['status'] ?? '') !== 'open') {
                notify_students([
                    'type' => 'question.open',
                    'title' => 'New class question',
                    'body' => (string) ($row['title'] ?? ''),
                    'icon' => 'help',
                    'url' => 'student/question.php?id=' . $id,
                    'target_type' => 'question',
                    'target_id' => $id,
                ]);
            }
            flash_set('success', $status === 'closed' ? 'Question closed.' : 'Question is now visible to the class.');
        } elseif (in_array($action, ['hide', 'show', 'approve', 'useful', 'pin', 'unpin', 'delete_response'], true)) {
            require_permission('questions.moderate');
            moderate_response((int) posted('response_id'), $action === 'delete_response' ? 'delete' : $action);
            log_audit('question.moderate', 'question', $editId, $action);
            flash_set('success', $action === 'approve' ? 'Answer published.' : 'Response updated.');
        } else {
            $id = posted('id') !== '' ? (int) posted('id') : null;
            require_permission($id ? 'questions.moderate' : 'questions.create');
            if ($id && !can('questions.create')) {
                require_permission('questions.moderate');
            }
            $saved = save_question($_POST, $id, (int) (current_user()['id'] ?? 0), $id ? (int) ($edit['created_student_id'] ?? 0) ?: null : null);
            log_audit('question.save', 'question', (int) ($saved['id'] ?? 0), $saved['title'] ?? '');
            flash_set('success', $id ? 'Question updated.' : 'Question saved.');
            redirect('admin/questions.php?edit=' . (int) ($saved['id'] ?? 0));
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('error', 'Unable to save that question.');
    }
    redirect('admin/questions.php' . ($editId ? '?edit=' . $editId : ($action === '' ? '?new=1' : '')));
}

$isNew = !$edit && request_str('new') === '1';
$filter = request_str('filter');
$sql = 'SELECT q.*, s.student_name AS asker_name FROM questions q LEFT JOIN uniforms s ON s.id = q.created_student_id';
if ($filter === 'pending') {
    $sql .= " WHERE q.status = 'pending'";
} elseif ($filter === 'qa') {
    $sql .= " WHERE q.kind IN ('qa','suggestion')";
} elseif ($filter === 'class') {
    $sql .= " WHERE q.kind IN ('open','choices')";
} elseif ($filter === 'responses') {
    $sql .= " WHERE q.id IN (SELECT question_id FROM question_responses WHERE status = 'pending')";
}
$sql .= " ORDER BY (q.status = 'pending') DESC, q.id DESC";
$rows = table_exists(db(), 'questions') ? db()->query($sql)->fetchAll() : [];
$stats = question_response_stats();
$waitingAsks = pending_question_count();
$waitingAnswers = pending_response_count();
$showForm = ($edit && can_any(['questions.create', 'questions.moderate'])) || ($isNew && can('questions.create'));
$options = $edit ? question_options((int) $edit['id']) : [];
$responses = $edit && can('questions.moderate') ? question_responses((int) $edit['id'], true) : [];
$choiceResults = $edit && ($edit['kind'] ?? '') === 'choices' ? question_choice_results((int) $edit['id']) : null;
$choiceLabels = $edit ? question_response_choice_labels((int) $edit['id']) : [];
$optionLocked = $edit && ($edit['kind'] ?? '') === 'choices' && (($stats[(int) $edit['id']]['total'] ?? 0) > 0);
$kind = (string) ($edit['kind'] ?? 'open');
$status = (string) ($edit['status'] ?? 'draft');
$pendingAnswers = $edit ? (int) ($stats[(int) $edit['id']]['pending'] ?? 0) : 0;
$visibleAnswers = $edit ? (int) ($stats[(int) $edit['id']]['visible'] ?? 0) : 0;
$askerName = '';
if ($edit && (int) ($edit['created_student_id'] ?? 0) > 0) {
    $askStmt = db()->prepare('SELECT student_name FROM uniforms WHERE id = ?');
    $askStmt->execute([(int) $edit['created_student_id']]);
    $askerName = trim((string) ($askStmt->fetchColumn() ?: ''));
}

$pageTitle = $edit ? 'Question' : ($isNew ? 'New question' : 'Questions');
$headActions = [];
if (!$edit && !$isNew && can('questions.create')) {
    $headActions[] = '<a class="btn" href="?new=1">New question</a>';
}
admin_header($pageTitle, 'questions');
admin_page_head('Class questions, suggestions, and anonymous asks. Internal student ids stay on file for moderation.', $headActions);
?>

<?php if (!$edit && !$isNew): ?>
<p class="admin-filters">
    <a class="chip <?= $filter === '' ? 'is-active' : '' ?>" href="<?= e(url('admin/questions.php')) ?>">All</a>
    <a class="chip <?= $filter === 'class' ? 'is-active' : '' ?>" href="?filter=class">Class prompts</a>
    <a class="chip <?= $filter === 'qa' ? 'is-active' : '' ?>" href="?filter=qa">Ask / suggestions</a>
    <a class="chip <?= $filter === 'pending' ? 'is-active' : '' ?>" href="?filter=pending">Waiting<?= $waitingAsks ? ' · ' . $waitingAsks : '' ?></a>
    <a class="chip <?= $filter === 'responses' ? 'is-active' : '' ?>" href="?filter=responses">Answers<?= $waitingAnswers ? ' · ' . $waitingAnswers : '' ?></a>
</p>
<?php endif; ?>

<?php if ($edit || $isNew): ?>
<div class="q-manage-nav">
    <a class="text-link" href="<?= e(url('admin/questions.php')) ?>">All questions</a>
</div>
<?php endif; ?>

<?php if ($edit): ?>
<section class="q-manage-head">
    <div>
        <p class="eyebrow"><?= e(question_kinds()[$kind] ?? 'Question') ?></p>
        <h2><?= e((string) $edit['title']) ?></h2>
        <p class="q-manage-meta">
            <?= admin_status_badge($status) ?>
            <?php if (!empty($edit['featured'])): ?><span class="badge">Home</span><?php endif; ?>
            <?php if (!empty($edit['created_student_id'])): ?>
                <span class="badge">From <?= e($askerName !== '' ? $askerName : 'a student') ?></span>
            <?php endif; ?>
            <span class="muted"><?= $visibleAnswers ?> live<?= $pendingAnswers ? ' · ' . $pendingAnswers . ' waiting' : '' ?></span>
        </p>
    </div>
    <div class="q-manage-actions">
        <?php if (can('questions.close') || can('questions.moderate')): ?>
            <?php if ($status !== 'open'): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
                    <input type="hidden" name="action" value="open">
                    <button class="btn" type="submit">Post to class</button>
                </form>
            <?php else: ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
                    <input type="hidden" name="action" value="close">
                    <button class="btn btn-ghost" type="submit">Close</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (can('questions.delete')): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
                <button class="btn btn-ghost btn-danger" data-confirm="Responses for this question will be removed." data-confirm-title="Delete question?" type="submit">Delete</button>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<div class="<?= $edit ? 'q-manage' : '' ?>">
<?php if ($showForm): ?>
<div class="panel">
    <h2><?= $edit ? 'Details' : 'New class question' ?></h2>
    <form method="post" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? '')) ?>">
        <div class="form-grid">
            <div class="form-group full"><label class="req" for="q_title">Question</label><input id="q_title" name="title" required value="<?= e($edit['title'] ?? '') ?>" placeholder="What should we add to our graduation program?"></div>
            <div class="form-group full"><label for="q_body">Context</label><textarea id="q_body" name="body" rows="4" placeholder="Optional background for the class"><?= e($edit['body'] ?? '') ?></textarea></div>
            <div class="form-group">
                <label for="q_kind">Type</label>
                <select id="q_kind" name="kind" data-kind-switch>
                    <?php foreach (question_kinds() as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $kind === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="q_status">Status</label>
                <select id="q_status" name="status">
                    <?php foreach (['draft' => 'Draft', 'pending' => 'Waiting for review', 'open' => 'Open', 'closed' => 'Closed'] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group full" data-kind-panel="choices" <?= $kind === 'choices' ? '' : 'hidden' ?>>
                <label>Choice options<?= $optionLocked ? ' (locked after the first answer)' : '' ?></label>
                <?php
                $labels = $options ? array_column($options, 'label') : ['', '', '', ''];
                while (count($labels) < 4) {
                    $labels[] = '';
                }
                if (!$optionLocked) {
                    $labels[] = '';
                }
                foreach ($labels as $label):
                ?>
                    <input name="options[]" value="<?= e((string) $label) ?>" <?= $optionLocked ? 'readonly' : '' ?> placeholder="Option">
                <?php endforeach; ?>
                <p class="muted">Used only for “Question with choices”. At least two options.</p>
            </div>
            <div class="form-group full">
                <label for="q_official">Committee answer</label>
                <textarea id="q_official" name="official_answer" rows="4" placeholder="Optional. Shown to the class after you save."><?= e($edit['official_answer'] ?? '') ?></textarea>
            </div>
            <div class="form-group full q-settings">
                <label class="check"><input type="checkbox" name="allow_anonymous" value="1" <?= !empty($edit['allow_anonymous']) ? 'checked' : '' ?>><span>Allow anonymous answers<small>Student id stays on file</small></span></label>
                <label class="check"><input type="checkbox" name="allow_change" value="1" <?= !$edit || !empty($edit['allow_change']) ? 'checked' : '' ?>><span>Students can change their answer</span></label>
                <label class="check"><input type="checkbox" name="moderate" value="1" <?= !empty($edit['moderate']) ? 'checked' : '' ?>><span>Hold answers for approval</span></label>
                <label class="check"><input type="checkbox" name="featured" value="1" <?= !empty($edit['featured']) ? 'checked' : '' ?>><span>Question of the week on student home</span></label>
            </div>
        </div>
        <div class="form-actions" style="margin-top:1rem">
            <button class="btn" type="submit"><?= $edit ? 'Save changes' : 'Save question' ?></button>
            <a class="btn btn-ghost" href="<?= e(url('admin/questions.php')) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($edit): ?>
<div class="q-manage-side">
<?php if ($choiceResults): ?>
<section class="panel">
    <h2>Choice results</h2>
    <ul class="poll-results">
        <?php foreach ($choiceResults['options'] as $option): ?>
            <li>
                <span><?= e($option['label']) ?></span>
                <span class="poll-bar"><i style="width: <?= (int) $option['percent'] ?>%"></i></span>
                <b><?= (int) $option['percent'] ?>%</b>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="muted"><?= (int) $choiceResults['total'] ?> visible response<?= (int) $choiceResults['total'] === 1 ? '' : 's' ?>.</p>
</section>
<?php endif; ?>

<?php if (can('questions.moderate')): ?>
<section class="panel">
    <h2>Answers <?= $responses ? '<span class="muted">' . count($responses) . '</span>' : '' ?></h2>
    <?php if (!$responses): ?>
        <p class="muted">No answers yet.</p>
    <?php else: ?>
        <ol class="response-mod-list">
            <?php foreach ($responses as $item): ?>
                <?php
                $itemStatus = (string) ($item['status'] ?? 'visible');
                $picked = trim((string) ($choiceLabels[(int) $item['id']] ?? ''));
                $body = trim((string) ($item['body'] ?? ''));
                ?>
                <li class="response-mod<?= $itemStatus === 'hidden' ? ' is-hidden' : '' ?><?= $itemStatus === 'pending' ? ' is-pending' : '' ?>">
                    <p>
                        <strong><?= e(response_display_name($item, true)) ?></strong>
                        <?= admin_status_badge($itemStatus) ?>
                        <?php if (!empty($item['useful'])): ?><span class="badge">Useful</span><?php endif; ?>
                        <?php if ((int) ($edit['pin_response_id'] ?? 0) === (int) $item['id']): ?><span class="badge">Pinned</span><?php endif; ?>
                    </p>
                    <?php if ($picked !== ''): ?><p class="q-answer-pick"><?= e($picked) ?></p><?php endif; ?>
                    <?php if ($body !== ''): ?><p><?= nl2br(e($body)) ?></p><?php endif; ?>
                    <div class="row-actions">
                        <?php
                        if ($itemStatus === 'pending') {
                            $actions = [['approve', 'Approve'], ['hide', 'Hide'], ['delete_response', 'Delete']];
                        } else {
                            $actions = [
                                $itemStatus === 'visible' ? ['hide', 'Hide'] : ['show', 'Show'],
                                ['useful', !empty($item['useful']) ? 'Unmark useful' : 'Mark useful'],
                                (int) ($edit['pin_response_id'] ?? 0) === (int) $item['id'] ? ['unpin', 'Unpin'] : ['pin', 'Pin'],
                                ['delete_response', 'Delete'],
                            ];
                        }
                        foreach ($actions as [$act, $label]):
                        ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
                                <input type="hidden" name="response_id" value="<?= (int) $item['id'] ?>">
                                <input type="hidden" name="action" value="<?= e($act) ?>">
                                <button class="btn btn-sm <?= $act === 'approve' ? '' : ($act === 'delete_response' ? 'btn-danger' : 'btn-ghost') ?>" type="submit" <?= $act === 'delete_response' ? 'data-confirm="Remove this response?"' : '' ?>><?= e($label) ?></button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
<?php endif; ?>
</div>
<?php endif; ?>
</div>

<?php if (!$edit && !$isNew): ?>
<?php if (!$rows): ?>
    <?php admin_empty('No questions yet.', can('questions.create') ? 'Add a class prompt, or wait for students to ask the committee.' : 'Nothing in this filter.'); ?>
<?php else: ?>
<div class="q-admin-list">
    <?php foreach ($rows as $row): ?>
        <?php
        $rowId = (int) $row['id'];
        $rowStatus = (string) ($row['status'] ?? 'draft');
        $rowKind = (string) ($row['kind'] ?? 'open');
        $rowStats = $stats[$rowId] ?? ['visible' => 0, 'pending' => 0, 'total' => 0];
        $asker = trim((string) ($row['asker_name'] ?? ''));
        $excerpt = excerpt(trim((string) ($row['body'] ?? '')), 140);
        ?>
        <article class="q-admin-row<?= $rowStatus === 'pending' || $rowStats['pending'] > 0 ? ' is-waiting' : '' ?>">
            <a class="q-admin-main" href="?edit=<?= $rowId ?>">
                <p class="eyebrow"><?= e(question_kinds()[$rowKind] ?? 'Question') ?></p>
                <h3><?= e($row['title']) ?></h3>
                <?php if ($excerpt !== ''): ?><p class="muted"><?= e($excerpt) ?></p><?php endif; ?>
                <p class="q-admin-tags">
                    <?= admin_status_badge($rowStatus) ?>
                    <?php if (!empty($row['featured'])): ?><span class="badge">Home</span><?php endif; ?>
                    <?php if (!empty($row['created_student_id'])): ?>
                        <span class="badge">From <?= e($asker !== '' ? $asker : 'a student') ?></span>
                    <?php endif; ?>
                    <span class="muted"><?= (int) $rowStats['visible'] ?> answer<?= (int) $rowStats['visible'] === 1 ? '' : 's' ?><?= $rowStats['pending'] ? ' · ' . (int) $rowStats['pending'] . ' waiting' : '' ?></span>
                </p>
            </a>
            <div class="q-admin-side">
                <a class="btn btn-sm btn-ghost" href="?edit=<?= $rowId ?>">Manage</a>
                <?php if (can('questions.close') || can('questions.moderate')): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $rowId ?>">
                        <input type="hidden" name="action" value="<?= $rowStatus === 'open' ? 'close' : 'open' ?>">
                        <button class="btn btn-sm" type="submit"><?= $rowStatus === 'open' ? 'Close' : 'Post' ?></button>
                    </form>
                <?php endif; ?>
                <?php if (can('questions.delete')): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $rowId ?>"><button class="btn btn-sm btn-danger" data-confirm="Responses for this question will be removed." data-confirm-title="Delete question?">Delete</button></form>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php admin_footer(); ?>
