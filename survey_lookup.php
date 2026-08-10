<?php
/**
 * survey_lookup.php — PUBLIC typeahead for student/parent pickers.
 *
 *   GET /survey_lookup.php?t=<survey-token>&field=<question_key>&q=adh
 *
 * Privacy: never returns the full roster. Requires 3+ letters, returns at most
 * a handful of matches. The survey token must be valid and active so scrapers
 * cannot probe without a live link.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/surveys.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

function survey_lookup_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$token = (string)($_GET['t'] ?? $_GET['token'] ?? '');
$field = (string)($_GET['field'] ?? '');
$q     = (string)($_GET['q'] ?? '');

$survey = survey_by_token($token);
if (!$survey) {
    survey_lookup_out(['ok' => false, 'error' => 'Survey not available.', 'matches' => []], 404);
}
$spec = survey_spec((string)$survey['spec_key']);
if (!$spec) {
    survey_lookup_out(['ok' => false, 'error' => 'Survey not available.', 'matches' => []], 404);
}

$question = null;
foreach (survey_questions($spec) as $qq) {
    if ((string)($qq['key'] ?? '') === $field) {
        $question = $qq;
        break;
    }
}
if ($question === null) {
    survey_lookup_out(['ok' => false, 'error' => 'Unknown field.', 'matches' => []], 400);
}

$type = (string)($question['type'] ?? 'text');
$opts = $question['options'] ?? null;
$filter = is_array($question['options_filter'] ?? null) ? $question['options_filter'] : [];
$fills = !empty($question['fills']) && is_array($question['fills'])
    ? $question['fills']
    : ['child_name' => 'full_name', 'class' => 'grade', 'parent_name' => 'primary_parent'];

$kind = null;
if ($type === 'student_picker' || $opts === 'students') {
    $kind = 'students';
} elseif ($opts === 'parents') {
    $kind = 'parents';
} else {
    survey_lookup_out(['ok' => false, 'error' => 'Field is not a lookup picker.', 'matches' => []], 400);
}

$raw = $kind === 'students'
    ? survey_student_search($q, $filter)
    : survey_parent_search($q, $filter);

$matches = [];
foreach ($raw as $row) {
    $data = $row['data'] ?? [];
    $matches[] = [
        'id'    => (string)$row['id'],
        'label' => (string)$row['label'],
        // Pre-applied fill values for the form fields (not the whole roster).
        'fills' => survey_apply_fills($fills, is_array($data) ? $data : []),
    ];
}

survey_lookup_out([
    'ok'      => true,
    'q'       => $q,
    'min'     => SURVEY_LOOKUP_MIN_CHARS,
    'matches' => $matches,
    // Deliberately omit total school size / "and N more".
]);
