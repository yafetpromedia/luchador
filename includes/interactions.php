<?php

declare(strict_types=1);

function interaction_datetime(?string $raw): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    $raw = str_replace('T', ' ', $raw);
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function interaction_closes_in(?string $endsAt): string
{
    if (!$endsAt) {
        return '';
    }
    $ts = strtotime($endsAt);
    if ($ts === false) {
        return '';
    }
    $diff = $ts - time();
    if ($diff <= 0) {
        return 'Closed';
    }
    if ($diff < 3600) {
        $mins = max(1, (int) floor($diff / 60));
        return $mins === 1 ? 'Closes in 1 minute' : 'Closes in ' . $mins . ' minutes';
    }
    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);
        return $hours === 1 ? 'Closes in 1 hour' : 'Closes in ' . $hours . ' hours';
    }
    $days = (int) floor($diff / 86400);
    return $days === 1 ? 'Closes in 1 day' : 'Closes in ' . $days . ' days';
}

function posted_lines(string $key): array
{
    $raw = $_POST[$key] ?? [];
    if (!is_array($raw)) {
        $raw = preg_split("/\r\n|\n|\r/", (string) $raw) ?: [];
    }
    $out = [];
    foreach ($raw as $value) {
        $value = excerpt(trim((string) $value), 180);
        if ($value !== '') {
            $out[] = $value;
        }
    }
    return $out;
}

function posted_int_list(string $key): array
{
    $raw = $_POST[$key] ?? [];
    if (!is_array($raw)) {
        $raw = $raw === '' ? [] : [$raw];
    }
    $ids = [];
    foreach ($raw as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

function poll_effective_status(array $poll): string
{
    $status = (string) ($poll['status'] ?? 'draft');
    if ($status !== 'active') {
        return $status;
    }
    $now = time();
    if (!empty($poll['starts_at']) && strtotime((string) $poll['starts_at']) > $now) {
        return 'scheduled';
    }
    if (!empty($poll['ends_at']) && strtotime((string) $poll['ends_at']) <= $now) {
        return 'closed';
    }
    return 'active';
}

function poll_is_open(array $poll): bool
{
    return poll_effective_status($poll) === 'active';
}

function poll_by_id(int $id): ?array
{
    if ($id < 1 || !table_exists(db(), 'polls')) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM polls WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function poll_options(int $pollId): array
{
    $stmt = db()->prepare('SELECT * FROM poll_options WHERE poll_id = ? ORDER BY display_order ASC, id ASC');
    $stmt->execute([$pollId]);
    return $stmt->fetchAll() ?: [];
}

function poll_vote_for_student(int $pollId, int $studentId): ?array
{
    $stmt = db()->prepare('SELECT * FROM poll_votes WHERE poll_id = ? AND student_id = ? LIMIT 1');
    $stmt->execute([$pollId, $studentId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $choices = db()->prepare('SELECT option_id FROM poll_vote_choices WHERE vote_id = ?');
    $choices->execute([(int) $row['id']]);
    $row['option_ids'] = array_map('intval', $choices->fetchAll(PDO::FETCH_COLUMN) ?: []);
    return $row;
}

function poll_results(int $pollId): array
{
    $options = poll_options($pollId);
    $countStmt = db()->prepare('SELECT COUNT(*) FROM poll_votes WHERE poll_id = ?');
    $countStmt->execute([$pollId]);
    $total = (int) $countStmt->fetchColumn();
    $choiceStmt = db()->prepare(
        'SELECT option_id, COUNT(*) AS votes FROM poll_vote_choices c
         INNER JOIN poll_votes v ON v.id = c.vote_id
         WHERE v.poll_id = ?
         GROUP BY option_id'
    );
    $choiceStmt->execute([$pollId]);
    $byOption = [];
    foreach ($choiceStmt->fetchAll() ?: [] as $row) {
        $byOption[(int) $row['option_id']] = (int) $row['votes'];
    }
    $out = [];
    foreach ($options as $option) {
        $votes = $byOption[(int) $option['id']] ?? 0;
        $out[] = [
            'id' => (int) $option['id'],
            'label' => (string) $option['label'],
            'votes' => $votes,
            'percent' => $total > 0 ? (int) round($votes / $total * 100) : 0,
        ];
    }
    return ['total' => $total, 'options' => $out];
}

function poll_can_show_results(array $poll, bool $hasVoted): bool
{
    if (can('polls.results')) {
        return true;
    }
    $show = (string) ($poll['show_results'] ?? 'immediate');
    if ($show === 'after_close') {
        return poll_effective_status($poll) === 'closed';
    }
    return $hasVoted || poll_effective_status($poll) === 'closed';
}

function poll_is_decision(array $poll): bool
{
    return !empty($poll['is_decision']);
}

function poll_kind_label(array $poll): string
{
    return poll_is_decision($poll) ? 'Class decision' : 'Class poll';
}

function poll_open_notice(array $poll): array
{
    $decision = poll_is_decision($poll);
    $title = (string) ($poll['title'] ?? '');
    $id = (int) ($poll['id'] ?? 0);
    return [
        'type' => $decision ? 'decision.open' : 'poll.open',
        'title' => $decision ? 'Class decision' : 'New class poll',
        'body' => $title,
        'icon' => $decision ? 'gavel' : 'bar-chart',
        'url' => 'student/poll.php?id=' . $id,
        'target_type' => 'poll',
        'target_id' => $id,
    ];
}

function active_polls(): array
{
    if (!table_exists(db(), 'polls')) {
        return [];
    }
    $order = column_exists(db(), 'polls', 'is_decision')
        ? 'is_decision DESC, featured DESC, id DESC'
        : 'featured DESC, id DESC';
    $rows = db()->query("SELECT * FROM polls WHERE status IN ('active','closed') ORDER BY {$order}")->fetchAll() ?: [];
    return array_values(array_filter($rows, static fn (array $row): bool => poll_effective_status($row) !== 'scheduled' && (string) $row['status'] !== 'draft'));
}

function visible_polls(bool $decisions = false): array
{
    return array_values(array_filter(active_polls(), static fn (array $row): bool => poll_is_decision($row) === $decisions));
}

function pick_home_poll(array $open): ?array
{
    if (!$open) {
        return null;
    }
    foreach ($open as $poll) {
        if (!empty($poll['featured'])) {
            return $poll;
        }
    }
    usort($open, static function (array $a, array $b): int {
        $ae = $a['ends_at'] ?? '';
        $be = $b['ends_at'] ?? '';
        if ($ae === $be) {
            return (int) $b['id'] <=> (int) $a['id'];
        }
        if ($ae === '') {
            return 1;
        }
        if ($be === '') {
            return -1;
        }
        return strcmp((string) $ae, (string) $be);
    });
    return $open[0] ?? null;
}

function home_poll(): ?array
{
    $open = [];
    foreach (visible_polls(false) as $poll) {
        if (poll_is_open($poll)) {
            $open[] = $poll;
        }
    }
    return pick_home_poll($open);
}

function home_decision(): ?array
{
    $open = [];
    foreach (visible_polls(true) as $poll) {
        if (poll_is_open($poll)) {
            $open[] = $poll;
        }
    }
    return pick_home_poll($open);
}

function save_poll_options(int $pollId, array $labels): void
{
    db()->prepare('DELETE FROM poll_options WHERE poll_id = ?')->execute([$pollId]);
    $ins = db()->prepare('INSERT INTO poll_options (poll_id, label, display_order) VALUES (?,?,?)');
    foreach (array_values($labels) as $i => $label) {
        $ins->execute([$pollId, $label, $i]);
    }
}

function save_poll(array $src, ?int $id, int $userId): array
{
    $title = excerpt(trim((string) ($src['title'] ?? '')), 180);
    if ($title === '') {
        throw new InvalidArgumentException('A poll title is required.');
    }
    $options = posted_lines('options');
    if (count($options) < 2) {
        throw new InvalidArgumentException('Add at least two options.');
    }
    $choice = (string) ($src['choice_type'] ?? 'single');
    if (!in_array($choice, ['single', 'multiple'], true)) {
        $choice = 'single';
    }
    $show = (string) ($src['show_results'] ?? 'immediate');
    if (!in_array($show, ['immediate', 'after_close'], true)) {
        $show = 'immediate';
    }
    $status = (string) ($src['status'] ?? 'draft');
    if (!in_array($status, ['draft', 'active', 'closed'], true)) {
        $status = 'draft';
    }
    $params = [
        $title,
        excerpt(trim((string) ($src['description'] ?? '')), 800) ?: null,
        $choice,
        empty($src['anonymous']) ? 0 : 1,
        empty($src['allow_change']) ? 0 : 1,
        $show,
        interaction_datetime((string) ($src['starts_at'] ?? '')),
        interaction_datetime((string) ($src['ends_at'] ?? '')),
        $status,
        empty($src['featured']) ? 0 : 1,
        empty($src['is_decision']) ? 0 : 1,
    ];
    $was = $id ? poll_by_id($id) : null;
    if ($id) {
        if (!$was) {
            throw new InvalidArgumentException('Poll not found.');
        }
        $params[] = $id;
        db()->prepare(
            'UPDATE polls SET title=?, description=?, choice_type=?, anonymous=?, allow_change=?, show_results=?, starts_at=?, ends_at=?, status=?, featured=?, is_decision=? WHERE id=?'
        )->execute($params);
        if ((int) db()->query('SELECT COUNT(*) FROM poll_votes WHERE poll_id = ' . (int) $id)->fetchColumn() === 0) {
            save_poll_options($id, $options);
        }
    } else {
        $params[] = $userId;
        db()->prepare(
            'INSERT INTO polls (title, description, choice_type, anonymous, allow_change, show_results, starts_at, ends_at, status, featured, is_decision, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute($params);
        $id = (int) db()->lastInsertId();
        save_poll_options($id, $options);
    }
    if (!empty($src['featured'])) {
        $decisionFlag = empty($src['is_decision']) ? 0 : 1;
        db()->prepare('UPDATE polls SET featured = 0 WHERE id != ? AND is_decision = ?')->execute([$id, $decisionFlag]);
    }
    $saved = poll_by_id($id);
    if ($saved && $status === 'active' && (string) ($was['status'] ?? '') !== 'active') {
        notify_students(poll_open_notice($saved));
    }
    return $saved ?: [];
}

function delete_poll(int $id): void
{
    $voteIds = db()->prepare('SELECT id FROM poll_votes WHERE poll_id = ?');
    $voteIds->execute([$id]);
    $ids = array_map('intval', $voteIds->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($ids) {
        $in = implode(',', $ids);
        db()->exec('DELETE FROM poll_vote_choices WHERE vote_id IN (' . $in . ')');
    }
    db()->prepare('DELETE FROM poll_votes WHERE poll_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM poll_options WHERE poll_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM polls WHERE id = ?')->execute([$id]);
}

function submit_poll_vote(array $poll, array $student, int $userId, array $optionIds): void
{
    if (!poll_is_open($poll)) {
        throw new InvalidArgumentException('This poll is not open for voting.');
    }
    $studentId = (int) ($student['id'] ?? 0);
    if ($studentId < 1 || $userId < 1) {
        throw new InvalidArgumentException('Your class record is not linked, so you cannot vote.');
    }
    $linked = (int) (current_user()['student_id'] ?? 0);
    if ($linked !== $studentId) {
        throw new InvalidArgumentException('You can only vote as yourself.');
    }
    $valid = array_map(static fn (array $row): int => (int) $row['id'], poll_options((int) $poll['id']));
    $optionIds = array_values(array_intersect($optionIds, $valid));
    if (!$optionIds) {
        throw new InvalidArgumentException('Choose an option.');
    }
    if (($poll['choice_type'] ?? 'single') !== 'multiple') {
        $optionIds = [ $optionIds[0] ];
    }
    $existing = poll_vote_for_student((int) $poll['id'], $studentId);
    if ($existing && empty($poll['allow_change'])) {
        throw new InvalidArgumentException('Your vote is already recorded.');
    }
    db()->beginTransaction();
    try {
        if ($existing) {
            $voteId = (int) $existing['id'];
            db()->prepare('UPDATE poll_votes SET user_id = ?, updated_at = NOW() WHERE id = ? AND student_id = ? AND poll_id = ?')
                ->execute([$userId, $voteId, $studentId, (int) $poll['id']]);
            db()->prepare('DELETE FROM poll_vote_choices WHERE vote_id = ?')->execute([$voteId]);
        } else {
            try {
                db()->prepare('INSERT INTO poll_votes (poll_id, student_id, user_id) VALUES (?,?,?)')
                    ->execute([(int) $poll['id'], $studentId, $userId]);
                $voteId = (int) db()->lastInsertId();
            } catch (PDOException $e) {
                if (($e->errorInfo[0] ?? '') !== '23000') {
                    throw $e;
                }
                db()->rollBack();
                throw new InvalidArgumentException('Your vote is already recorded.');
            }
        }
        $ins = db()->prepare('INSERT INTO poll_vote_choices (vote_id, option_id) VALUES (?,?)');
        foreach ($optionIds as $optionId) {
            $ins->execute([$voteId, $optionId]);
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

function question_kinds(): array
{
    return [
        'open' => 'Open question',
        'choices' => 'Question with choices',
        'qa' => 'Ask the committee',
        'suggestion' => 'Suggestion',
    ];
}

function question_by_id(int $id): ?array
{
    if ($id < 1 || !table_exists(db(), 'questions')) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM questions WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function question_options(int $questionId): array
{
    $stmt = db()->prepare('SELECT * FROM question_options WHERE question_id = ? ORDER BY display_order ASC, id ASC');
    $stmt->execute([$questionId]);
    return $stmt->fetchAll() ?: [];
}

function question_visible_to_students(array $question): bool
{
    $status = (string) ($question['status'] ?? '');
    return in_array($status, ['open', 'closed'], true);
}

function question_response_for_student(int $questionId, int $studentId): ?array
{
    $stmt = db()->prepare('SELECT * FROM question_responses WHERE question_id = ? AND student_id = ? LIMIT 1');
    $stmt->execute([$questionId, $studentId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $choices = db()->prepare('SELECT option_id FROM question_response_choices WHERE response_id = ?');
    $choices->execute([(int) $row['id']]);
    $row['option_ids'] = array_map('intval', $choices->fetchAll(PDO::FETCH_COLUMN) ?: []);
    return $row;
}

function question_responses(int $questionId, bool $includeHidden = false): array
{
    $sql = 'SELECT r.*, s.student_name
            FROM question_responses r
            LEFT JOIN uniforms s ON s.id = r.student_id
            WHERE r.question_id = ?';
    if (!$includeHidden) {
        $sql .= " AND r.status = 'visible'";
    }
    if ($includeHidden) {
        $sql .= " ORDER BY (r.status = 'pending') DESC, (r.id = (SELECT pin_response_id FROM questions WHERE id = r.question_id)) DESC, r.useful DESC, r.id DESC";
    } else {
        $sql .= ' ORDER BY (r.id = (SELECT pin_response_id FROM questions WHERE id = r.question_id)) DESC, r.useful DESC, r.id DESC';
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([$questionId]);
    return $stmt->fetchAll() ?: [];
}

function question_choice_results(int $questionId): array
{
    $options = question_options($questionId);
    $countStmt = db()->prepare("SELECT COUNT(*) FROM question_responses WHERE question_id = ? AND status = 'visible'");
    $countStmt->execute([$questionId]);
    $total = (int) $countStmt->fetchColumn();
    $choiceStmt = db()->prepare(
        "SELECT option_id, COUNT(*) AS votes FROM question_response_choices c
         INNER JOIN question_responses r ON r.id = c.response_id
         WHERE r.question_id = ? AND r.status = 'visible'
         GROUP BY option_id"
    );
    $choiceStmt->execute([$questionId]);
    $byOption = [];
    foreach ($choiceStmt->fetchAll() ?: [] as $row) {
        $byOption[(int) $row['option_id']] = (int) $row['votes'];
    }
    $out = [];
    foreach ($options as $option) {
        $votes = $byOption[(int) $option['id']] ?? 0;
        $out[] = [
            'id' => (int) $option['id'],
            'label' => (string) $option['label'],
            'votes' => $votes,
            'percent' => $total > 0 ? (int) round($votes / $total * 100) : 0,
        ];
    }
    return ['total' => $total, 'options' => $out];
}

function question_response_stats(): array
{
    if (!table_exists(db(), 'question_responses')) {
        return [];
    }
    $out = [];
    foreach (db()->query(
        "SELECT question_id,
                COUNT(*) AS total_n,
                SUM(status = 'visible') AS visible_n,
                SUM(status = 'pending') AS pending_n
         FROM question_responses
         GROUP BY question_id"
    )->fetchAll() ?: [] as $row) {
        $out[(int) $row['question_id']] = [
            'total' => (int) $row['total_n'],
            'visible' => (int) $row['visible_n'],
            'pending' => (int) $row['pending_n'],
        ];
    }
    return $out;
}

function question_response_choice_labels(int $questionId): array
{
    if ($questionId < 1) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT c.response_id, o.label
         FROM question_response_choices c
         INNER JOIN question_options o ON o.id = c.option_id
         INNER JOIN question_responses r ON r.id = c.response_id
         WHERE r.question_id = ?'
    );
    $stmt->execute([$questionId]);
    $out = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $out[(int) $row['response_id']] = (string) $row['label'];
    }
    return $out;
}

function visible_questions(): array
{
    if (!table_exists(db(), 'questions')) {
        return [];
    }
    return db()->query("SELECT * FROM questions WHERE status IN ('open','closed') ORDER BY featured DESC, id DESC")->fetchAll() ?: [];
}

function home_question(): ?array
{
    $rows = visible_questions();
    foreach ($rows as $row) {
        if ((string) $row['status'] === 'open' && !empty($row['featured'])) {
            return $row;
        }
    }
    foreach ($rows as $row) {
        if ((string) $row['status'] === 'open' && in_array((string) $row['kind'], ['open', 'choices'], true)) {
            return $row;
        }
    }
    return null;
}

function save_question_options(int $questionId, array $labels): void
{
    db()->prepare('DELETE FROM question_options WHERE question_id = ?')->execute([$questionId]);
    $ins = db()->prepare('INSERT INTO question_options (question_id, label, display_order) VALUES (?,?,?)');
    foreach (array_values($labels) as $i => $label) {
        $ins->execute([$questionId, $label, $i]);
    }
}

function save_question(array $src, ?int $id, int $userId, ?int $studentId = null): array
{
    $title = excerpt(trim((string) ($src['title'] ?? '')), 180);
    if ($title === '') {
        throw new InvalidArgumentException('A question is required.');
    }
    $kind = (string) ($src['kind'] ?? 'open');
    if (!isset(question_kinds()[$kind])) {
        $kind = 'open';
    }
    $status = (string) ($src['status'] ?? 'draft');
    if (!in_array($status, ['draft', 'pending', 'open', 'closed'], true)) {
        $status = 'draft';
    }
    $options = posted_lines('options');
    if ($kind === 'choices' && count($options) < 2) {
        throw new InvalidArgumentException('Choice questions need at least two options.');
    }
    $params = [
        $title,
        excerpt(trim((string) ($src['body'] ?? '')), 1200) ?: null,
        $kind,
        empty($src['allow_anonymous']) ? 0 : 1,
        empty($src['allow_change']) ? 0 : 1,
        empty($src['moderate']) ? 0 : 1,
        empty($src['featured']) ? 0 : 1,
        $status,
        excerpt(trim((string) ($src['official_answer'] ?? '')), 1200) ?: null,
    ];
    $was = $id ? question_by_id($id) : null;
    if ($id) {
        if (!$was) {
            throw new InvalidArgumentException('Question not found.');
        }
        $params[] = $id;
        db()->prepare(
            'UPDATE questions SET title=?, body=?, kind=?, allow_anonymous=?, allow_change=?, moderate=?, featured=?, status=?, official_answer=? WHERE id=?'
        )->execute($params);
        if ($kind === 'choices') {
            $count = (int) db()->query('SELECT COUNT(*) FROM question_responses WHERE question_id = ' . (int) $id)->fetchColumn();
            if ($count === 0) {
                save_question_options($id, $options);
            }
        }
    } else {
        $params[] = $userId;
        $params[] = $studentId;
        db()->prepare(
            'INSERT INTO questions (title, body, kind, allow_anonymous, allow_change, moderate, featured, status, official_answer, created_by, created_student_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute($params);
        $id = (int) db()->lastInsertId();
        if ($kind === 'choices') {
            save_question_options($id, $options);
        }
    }
    if (!empty($src['featured'])) {
        db()->prepare('UPDATE questions SET featured = 0 WHERE id != ?')->execute([$id]);
    }
    $saved = question_by_id($id);
    if ($saved && $status === 'open' && (string) ($was['status'] ?? '') !== 'open') {
        notify_students([
            'type' => 'question.open',
            'title' => $kind === 'qa' ? 'Committee question' : 'New class question',
            'body' => $title,
            'icon' => 'help',
            'url' => 'student/question.php?id=' . $id,
            'target_type' => 'question',
            'target_id' => $id,
        ]);
    }
    return $saved ?: [];
}

function submit_student_ask(array $student, int $userId, array $src): array
{
    $kind = (string) ($src['kind'] ?? 'qa');
    if (!in_array($kind, ['qa', 'suggestion'], true)) {
        $kind = 'qa';
    }
    $src['kind'] = $kind;
    $src['status'] = 'pending';
    $src['featured'] = 0;
    $src['moderate'] = 1;
    $anonymous = !empty($src['allow_anonymous']);
    $src['allow_anonymous'] = $anonymous ? 1 : 0;
    $saved = save_question($src, null, $userId, (int) $student['id']);
    notify_staff([
        'type' => $kind === 'suggestion' ? 'question.suggestion' : 'question.ask',
        'title' => $anonymous
            ? ($kind === 'suggestion' ? 'Anonymous suggestion waiting' : 'Anonymous question waiting')
            : ($kind === 'suggestion' ? 'New class suggestion' : 'Question waiting for review'),
        'body' => $anonymous ? 'An anonymous student sent a question.' : ((string) ($student['student_name'] ?? 'A student') . ' sent a question.'),
        'icon' => 'help',
        'url' => 'admin/questions.php?edit=' . (int) ($saved['id'] ?? 0),
        'target_type' => 'question',
        'target_id' => (int) ($saved['id'] ?? 0),
    ]);
    if ($anonymous) {
        // keep internal student id; staff notice already hides the name
    }
    return $saved;
}

function submit_question_response(array $question, array $student, int $userId, array $src): void
{
    if ((string) ($question['status'] ?? '') !== 'open') {
        throw new InvalidArgumentException('This question is closed.');
    }
    $studentId = (int) ($student['id'] ?? 0);
    if ($studentId < 1 || $userId < 1) {
        throw new InvalidArgumentException('Your class record is not linked, so you cannot respond.');
    }
    if ((int) (current_user()['student_id'] ?? 0) !== $studentId) {
        throw new InvalidArgumentException('You can only respond as yourself.');
    }
    $kind = (string) ($question['kind'] ?? 'open');
    $optionIds = posted_int_list('option_ids');
    if (isset($src['option_id'])) {
        $optionIds = array_merge($optionIds, [(int) $src['option_id']]);
        $optionIds = array_values(array_unique(array_filter($optionIds)));
    }
    $body = excerpt(trim((string) ($src['body'] ?? '')), 800);
    if ($kind === 'choices') {
        $valid = array_map(static fn (array $row): int => (int) $row['id'], question_options((int) $question['id']));
        $optionIds = array_values(array_intersect($optionIds, $valid));
        if (!$optionIds) {
            throw new InvalidArgumentException('Choose an option.');
        }
        $optionIds = [ $optionIds[0] ];
        $body = null;
    } elseif ($body === '') {
        throw new InvalidArgumentException('Write an answer.');
    }
    $anonymous = !empty($src['is_anonymous']) && !empty($question['allow_anonymous']);
    $status = !empty($question['moderate']) ? 'pending' : 'visible';
    $existing = question_response_for_student((int) $question['id'], $studentId);
    if ($existing && empty($question['allow_change'])) {
        throw new InvalidArgumentException('Your answer is already recorded.');
    }
    db()->beginTransaction();
    try {
        if ($existing) {
            $responseId = (int) $existing['id'];
            db()->prepare(
                'UPDATE question_responses SET user_id=?, body=?, is_anonymous=?, status=?, updated_at=NOW() WHERE id=? AND student_id=? AND question_id=?'
            )->execute([$userId, $body, $anonymous ? 1 : 0, $status, $responseId, $studentId, (int) $question['id']]);
            db()->prepare('DELETE FROM question_response_choices WHERE response_id = ?')->execute([$responseId]);
        } else {
            try {
                db()->prepare(
                    'INSERT INTO question_responses (question_id, student_id, user_id, body, is_anonymous, status) VALUES (?,?,?,?,?,?)'
                )->execute([(int) $question['id'], $studentId, $userId, $body, $anonymous ? 1 : 0, $status]);
                $responseId = (int) db()->lastInsertId();
            } catch (PDOException $e) {
                if (($e->errorInfo[0] ?? '') !== '23000') {
                    throw $e;
                }
                db()->rollBack();
                throw new InvalidArgumentException('Your answer is already recorded.');
            }
        }
        if ($kind === 'choices') {
            $ins = db()->prepare('INSERT INTO question_response_choices (response_id, option_id) VALUES (?,?)');
            foreach ($optionIds as $optionId) {
                $ins->execute([$responseId, $optionId]);
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

function moderate_response(int $responseId, string $action, ?int $questionId = null): void
{
    $stmt = db()->prepare('SELECT * FROM question_responses WHERE id = ?');
    $stmt->execute([$responseId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new InvalidArgumentException('Response not found.');
    }
    $qid = (int) $row['question_id'];
    if ($action === 'hide') {
        db()->prepare("UPDATE question_responses SET status = 'hidden' WHERE id = ?")->execute([$responseId]);
    } elseif ($action === 'show' || $action === 'approve') {
        db()->prepare("UPDATE question_responses SET status = 'visible' WHERE id = ?")->execute([$responseId]);
    } elseif ($action === 'useful') {
        db()->prepare('UPDATE question_responses SET useful = IF(useful = 1, 0, 1) WHERE id = ?')->execute([$responseId]);
    } elseif ($action === 'pin') {
        db()->prepare('UPDATE questions SET pin_response_id = ? WHERE id = ?')->execute([$responseId, $qid]);
    } elseif ($action === 'unpin') {
        db()->prepare('UPDATE questions SET pin_response_id = NULL WHERE id = ? AND pin_response_id = ?')->execute([$qid, $responseId]);
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM question_response_choices WHERE response_id = ?')->execute([$responseId]);
        db()->prepare('DELETE FROM question_responses WHERE id = ?')->execute([$responseId]);
        db()->prepare('UPDATE questions SET pin_response_id = NULL WHERE pin_response_id = ?')->execute([$responseId]);
    }
}

function delete_question(int $id): void
{
    $resp = db()->prepare('SELECT id FROM question_responses WHERE question_id = ?');
    $resp->execute([$id]);
    $ids = array_map('intval', $resp->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($ids) {
        db()->exec('DELETE FROM question_response_choices WHERE response_id IN (' . implode(',', $ids) . ')');
    }
    db()->prepare('DELETE FROM question_responses WHERE question_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM question_options WHERE question_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM questions WHERE id = ?')->execute([$id]);
}

function response_display_name(array $response, bool $staffView = false): string
{
    if (!empty($response['is_anonymous']) && !$staffView) {
        return 'Anonymous';
    }
    if (!empty($response['is_anonymous']) && $staffView) {
        return 'Anonymous · ' . (string) ($response['student_name'] ?? 'Student');
    }
    return (string) ($response['student_name'] ?? 'Student');
}

function pending_question_count(): int
{
    if (!table_exists(db(), 'questions')) {
        return 0;
    }
    return (int) db()->query("SELECT COUNT(*) FROM questions WHERE status = 'pending'")->fetchColumn();
}

function pending_response_count(): int
{
    if (!table_exists(db(), 'question_responses')) {
        return 0;
    }
    return (int) db()->query("SELECT COUNT(*) FROM question_responses WHERE status = 'pending'")->fetchColumn();
}
