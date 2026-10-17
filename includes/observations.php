<?php
declare(strict_types=1);

function observation_can_access(array $user, array $student): bool
{
    return $user['role'] === 'admin' || (int)$student['teacher_id'] === (int)$user['id'];
}

function observation_indicators(PDO $pdo, array $student): array
{
    $s = $pdo->prepare("SELECT id, category, indicator_text FROM skill_indicators WHERE grade = ? AND is_active = 1 ORDER BY category, display_order, id");
    $s->execute([$student['grade']]);
    $out = [];
    foreach ($s as $r) $out['std:' . $r['id']] = $r;
    $s = $pdo->prepare("SELECT id, category, indicator_text FROM student_custom_indicators WHERE student_id = ? AND is_active = 1 ORDER BY category, display_order, id");
    $s->execute([$student['id']]);
    foreach ($s as $r) $out['cust:' . $r['id']] = $r;
    return $out;
}

/** Validate the complete submission before writing anything. Blank skills are unobserved. */
function observation_validate(array $input, array $indicators, array $ratings, ?string $today = null): array
{
    $date = $input['observed_on'] ?? '';
    $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
    if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > ($today ?? date('Y-m-d')) || $date < '1900-01-01') {
        throw new InvalidArgumentException('Choose a valid observation date on or before today.');
    }
    if (!is_string($input['submission_token'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $input['submission_token'])) {
        throw new InvalidArgumentException('Please reopen the observation form and try again.');
    }
    if (!is_array($input['rating'] ?? null)) throw new InvalidArgumentException('Select at least one observed skill.');
    $skills = [];
    foreach ($input['rating'] as $key => $code) {
        if (!isset($indicators[$key])) throw new InvalidArgumentException('A skill is not available for this child.');
        if ($code === '') continue;
        if (!is_string($code) || !isset($ratings[$code])) throw new InvalidArgumentException('Choose a configured rating.');
        $notes = [];
        foreach (['evidence', 'next_steps'] as $field) {
            $values = $input[$field] ?? [];
            $value = is_array($values) ? ($values[$key] ?? '') : null;
            if (!is_string($value) || mb_strlen($value) > 5000) throw new InvalidArgumentException('Notes must be text of at most 5,000 characters.');
            $notes[$field] = trim($value);
        }
        $skills[$key] = $notes + ['code' => $code, 'indicator' => $indicators[$key], 'rating' => $ratings[$code]];
    }
    if (!$skills) throw new InvalidArgumentException('Select at least one skill you actually observed.');
    return ['date' => $date, 'token' => $input['submission_token'], 'skills' => $skills];
}

function observation_save(PDO $pdo, array $user, array $student, array $input): int
{
    if (!observation_can_access($user, $student)) throw new RuntimeException('You can only observe your own students.');
    // A retry remains successful even if skills/ratings changed after the first save.
    $token = $input['submission_token'] ?? null;
    if (is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)) {
        $existing = $pdo->prepare('SELECT id FROM child_observations WHERE submission_token = ? AND student_id = ? AND author_id = ?');
        $existing->execute([$token, $student['id'], $user['id']]);
        if ($id = $existing->fetchColumn()) return (int)$id;
    }
    $ratings = $pdo->query("SELECT code, label, numeric_value FROM rating_config WHERE is_active = 1 ORDER BY code")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $data = observation_validate($input, observation_indicators($pdo, $student), $ratings);
    $scale = json_encode($ratings, JSON_THROW_ON_ERROR);
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('INSERT INTO child_observations (student_id,author_id,author_name,grade,observed_on,submission_token) VALUES (?,?,?,?,?,?)');
        $s->execute([$student['id'], $user['id'], $user['name'], $student['grade'], $data['date'], $data['token']]);
        $id = (int)$pdo->lastInsertId();
        $s = $pdo->prepare('INSERT INTO child_observation_skills (observation_id,skill_key,category,skill_text,rating_code,rating_label,numeric_value,scale_snapshot,evidence,next_steps) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($data['skills'] as $key => $r) {
            $s->execute([$id,$key,$r['indicator']['category'],$r['indicator']['indicator_text'],$r['code'],$r['rating']['label'],$r['rating']['numeric_value'],$scale,$r['evidence'],$r['next_steps']]);
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($e instanceof PDOException && $e->getCode() == '23000') {
            $s = $pdo->prepare('SELECT id FROM child_observations WHERE submission_token = ? AND student_id = ? AND author_id = ?');
            $s->execute([$data['token'],$student['id'],$user['id']]);
            if ($id = $s->fetchColumn()) return (int)$id;
        }
        throw $e;
    }
}

function observation_history(PDO $pdo, int $studentId): array
{
    try {
        $s = $pdo->prepare('SELECT o.id AS observation_id,o.observed_on,o.author_name,o.grade,o.created_at,s.* FROM child_observations o JOIN child_observation_skills s ON s.observation_id=o.id WHERE o.student_id=? ORDER BY o.observed_on,o.id,s.id');
        $s->execute([$studentId]);
        return $s->fetchAll();
    } catch (PDOException $e) {
        // A report remains readable before the additive migration is installed.
        if ($e->getCode() === '42S02') return [];
        throw $e;
    }
}

function observation_skill_history(array $rows): array
{
    usort($rows, fn($a,$b) => [$a['observed_on'], (int)$a['observation_id']] <=> [$b['observed_on'], (int)$b['observation_id']]);
    $out = [];
    foreach ($rows as $r) {
        // Different grades/scales/text are independent records, never an inferred increase.
        $key = $r['skill_key'] . ':' . hash('sha256', json_encode([$r['grade'],$r['scale_snapshot'],$r['skill_text'],$r['category']]));
        if (!isset($out[$key])) $out[$key] = ['first' => $r, 'latest' => $r];
        else $out[$key]['latest'] = $r;
    }
    return array_values($out);
}

function observation_history_render(array $rows): void
{
    if (!$rows) return;
    echo '<section class="card cr-card cr-section"><h2>Dated observations</h2><p>Only skills observed on each date are recorded. Monthly assessments and their averages are shown separately below.</p>';
    echo '<h3>Earlier and latest recorded levels</h3>';
    foreach (observation_skill_history($rows) as $pair) {
        $a=$pair['first']; $b=$pair['latest'];
        echo '<p><strong>'.e($b['skill_text']).'</strong> · '.e($b['category']).' · '.e($b['grade']);
        if ($a['observation_id'] === $b['observation_id']) echo '<br>Only observation: '.e($a['observed_on'].' — '.$a['rating_label']);
        else echo '<br>Earlier: '.e($a['observed_on'].' — '.$a['rating_label']).'<br>Latest: '.e($b['observed_on'].' — '.$b['rating_label']);
        echo '</p>';
    }
    echo '<h3>Observation entries</h3>';
    $last = null;
    foreach (array_reverse($rows) as $r) {
        if ($last !== $r['observation_id']) {
            echo '<h4>'.e($r['observed_on']).' · '.e($r['author_name']).' · '.e($r['grade']).'</h4>';
            $last=$r['observation_id'];
        }
        echo '<p><strong>'.e($r['skill_text']).'</strong> · '.e($r['category']).' · '.e($r['rating_label']).' ('.e($r['rating_code']).')</p>';
        if ($r['evidence'] !== '') echo '<p>Evidence: '.nl2br(e($r['evidence'])).'</p>';
        if ($r['next_steps'] !== '') echo '<p>Next steps: '.nl2br(e($r['next_steps'])).'</p>';
    }
    echo '</section>';
}
