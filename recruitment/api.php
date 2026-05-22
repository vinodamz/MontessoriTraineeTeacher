<?php
/**
 * recruitment/api.php — JSON endpoints for the hiring pipeline.
 *
 * POST-only, CSRF-gated, requires the 'recruitment' module. All ops return
 * application/json with 2xx on success, 4xx on bad input, 5xx on error.
 *
 *   op=move        { id, status }                      → 200 {ok:true}
 *   op=evaluate    { candidate_id, care, ... }         → 200 {ok:true, avg}
 *   op=interview   { candidate_id, stage, occurred_at } → 201 {ok:true, id}
 *   op=hire        { id }                              → 200 {ok:true, user_id}
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/recruitment.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_module('recruitment');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

try {
    csrf_check();
    $op = $_POST['op'] ?? '';

    switch ($op) {

        case 'move': {
            $id = (int)($_POST['id'] ?? 0);
            $st = $_POST['status'] ?? '';
            if ($id <= 0 || !array_key_exists($st, recruit_statuses())) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'bad input']);
                exit;
            }
            // Block direct drops onto 'hired' — must go through op=hire so
            // the user-row promotion runs. Same pattern crm uses to block
            // drops onto 'enrolled' (commit 6e7d150).
            if ($st === 'hired') {
                http_response_code(409);
                echo json_encode(['ok' => false, 'error' => 'Use the Hire action to onboard.']);
                exit;
            }
            db()->prepare("UPDATE recruit_candidates SET status = :s WHERE id = :id")
                ->execute([':s' => $st, ':id' => $id]);
            echo json_encode(['ok' => true]);
            break;
        }

        case 'evaluate': {
            $cid = (int)($_POST['candidate_id'] ?? 0);
            if ($cid <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'candidate_id required']);
                exit;
            }
            $dims = array_keys(recruit_eval_dimensions());
            $vals = [':c' => $cid, ':e' => (int)$user['id']];
            foreach ($dims as $d) {
                $v = $_POST[$d] ?? '';
                if ($v === '' || $v === null) {
                    $vals[":$d"] = null;
                } else {
                    $n = (int)$v;
                    if ($n < 1 || $n > 5) {
                        http_response_code(400);
                        echo json_encode(['ok' => false, 'error' => "$d out of range"]);
                        exit;
                    }
                    $vals[":$d"] = $n;
                }
            }
            $rec = $_POST['overall_recommend'] ?? '';
            $vals[':r'] = ($rec !== '' && array_key_exists($rec, recruit_recommendations())) ? $rec : null;
            $vals[':m'] = trim((string)($_POST['comments'] ?? '')) ?: null;

            $cols   = implode(', ', $dims);
            $params = implode(', ', array_map(fn($d) => ":$d", $dims));
            $setParts = array_map(fn($d) => "$d = VALUES($d)", $dims);
            $setParts[] = 'overall_recommend = VALUES(overall_recommend)';
            $setParts[] = 'comments = VALUES(comments)';

            $sql = "
                INSERT INTO recruit_evaluations
                    (candidate_id, evaluator_id, $cols, overall_recommend, comments)
                VALUES
                    (:c, :e, $params, :r, :m)
                ON DUPLICATE KEY UPDATE " . implode(', ', $setParts);
            db()->prepare($sql)->execute($vals);
            echo json_encode(['ok' => true, 'avg' => recruit_avg_scores($cid)]);
            break;
        }

        case 'interview': {
            $cid   = (int)($_POST['candidate_id'] ?? 0);
            $stage = $_POST['stage'] ?? 'note';
            $when  = trim($_POST['occurred_at'] ?? '');
            if ($cid <= 0 || $when === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'candidate_id and occurred_at required']);
                exit;
            }
            if (!array_key_exists($stage, recruit_interview_stages())) $stage = 'note';
            $outcome = $_POST['outcome'] ?? '';
            $outcome = in_array($outcome, ['pending','passed','failed','no_show'], true) ? $outcome : null;
            $stmt = db()->prepare("
                INSERT INTO recruit_interviews
                    (candidate_id, interviewer_id, stage, occurred_at,
                     duration_min, location, outcome, body, created_by)
                VALUES
                    (:c, :i, :s, :w, :d, :l, :o, :b, :u)
            ");
            $stmt->execute([
                ':c' => $cid,
                ':i' => (int)($_POST['interviewer_id'] ?? $user['id']) ?: null,
                ':s' => $stage,
                ':w' => $when,
                ':d' => ($_POST['duration_min'] ?? '') !== '' ? (int)$_POST['duration_min'] : null,
                ':l' => trim((string)($_POST['location'] ?? '')) ?: null,
                ':o' => $outcome,
                ':b' => trim((string)($_POST['body'] ?? '')) ?: null,
                ':u' => (int)$user['id'],
            ]);
            http_response_code(201);
            echo json_encode(['ok' => true, 'id' => (int)db()->lastInsertId()]);
            break;
        }

        case 'hire': {
            $cid = (int)($_POST['id'] ?? 0);
            if ($cid <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'id required']);
                exit;
            }
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $newUserId = recruit_promote_to_staff($cid, (int)$user['id']);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true, 'user_id' => $newUserId]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'unknown op']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
