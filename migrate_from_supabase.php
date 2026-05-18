<?php
/**
 * migrate_from_supabase.php — one-shot import of the legacy Supabase data
 * into the local MySQL database.
 *
 * Behaviour:
 *   GET  ?            → preview (counts the rows that will be imported,
 *                       confirms what's already present in MySQL).
 *   GET  ?confirm=1   → run the import inside a transaction.
 *   GET  ?confirm=1&force=1 → also wipes existing assessment/eval/baseline
 *                       rows first. Teachers/students/indicators are upserted
 *                       by their natural keys (name / grade+category+text /
 *                       first_name+last_name+grade) so existing rows are reused.
 *
 * Authentication:
 *   Requires an admin login. install.php already created one; if you haven't
 *   created an admin yet, run that first.
 *
 * DELETE THIS FILE after the migration finishes.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_admin();

// ----- Supabase source ------------------------------------------------------
$SUPA_URL = 'https://uijowedzyssnfcmkhbuo.supabase.co';
$SUPA_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InVpam93ZWR6eXNzbmZjbWtoYnVvIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzMyMTczNzAsImV4cCI6MjA4ODc5MzM3MH0.HIlXvh5k9ZbHqWUhtOCXztE48d1zpVW45lat2ik2kNI';

/**
 * Fetch every row from a Supabase table, following pagination (1k rows/page).
 * Throws on transport or HTTP error so the migration aborts cleanly.
 */
function supa_fetch_all(string $table): array
{
    global $SUPA_URL, $SUPA_KEY;
    $all = [];
    $page = 0;
    $limit = 1000;
    while (true) {
        $from = $page * $limit;
        $to   = $from + $limit - 1;
        $ch = curl_init("$SUPA_URL/rest/v1/$table?select=*");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "apikey: $SUPA_KEY",
                "Authorization: Bearer $SUPA_KEY",
                "Range-Unit: items",
                "Range: $from-$to",
                "Prefer: count=none",
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false)              throw new RuntimeException("supa $table page $page: $err");
        if ($code < 200 || $code >= 300)  throw new RuntimeException("supa $table page $page: HTTP $code – " . substr((string)$body, 0, 200));
        $rows = json_decode((string)$body, true) ?? [];
        if (!$rows) break;
        $all = array_merge($all, $rows);
        if (count($rows) < $limit) break;
        $page++;
        if ($page > 50) throw new RuntimeException("supa $table: paginated past 50k rows, giving up");
    }
    return $all;
}

// ----- Preview -------------------------------------------------------------
$preview = ($_GET['confirm'] ?? '') !== '1';
$force   = ($_GET['force']   ?? '') === '1';

$pageTitle = 'Import from Supabase';
$bodyClass = '';

if ($preview) {
    try {
        $counts = [];
        foreach (['teachers','students','skill_indicators','student_custom_indicators','evaluation_cards','assessments','assessment_comments','student_baselines','rating_config'] as $t) {
            $counts[$t] = ['supa' => count(supa_fetch_all($t))];
        }
    } catch (Throwable $e) {
        flash_set('error', 'Supabase fetch failed: ' . $e->getMessage());
        $counts = null;
    }
    foreach (['teachers','students','skill_indicators','student_custom_indicators','evaluation_cards','assessments','assessment_comments','student_baselines','rating_config'] as $t) {
        $counts[$t]['mysql'] = (int)db()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    }
    require __DIR__ . '/includes/header.php';
    ?>
    <h1>Import from Supabase</h1>
    <p class="muted">Source project: <code>uijowedzyssnfcmkhbuo.supabase.co</code></p>
    <?php if ($counts): ?>
    <div class="card">
        <table class="admin-table">
            <thead><tr><th>Table</th><th>Supabase (source)</th><th>MySQL (current)</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($counts as $t => $c): ?>
                <tr>
                    <td><code><?= e($t) ?></code></td>
                    <td><?= (int)$c['supa'] ?></td>
                    <td><?= (int)$c['mysql'] ?></td>
                    <td>
                    <?php
                        if (in_array($t, ['teachers','students','skill_indicators','student_custom_indicators','rating_config'], true)) {
                            echo 'upsert by natural key';
                        } else {
                            echo '<strong>insert</strong>' . (($c['mysql'] > 0 && !$force) ? ' (skipped: rows present, use <code>&amp;force=1</code> to wipe)' : '');
                        }
                    ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p>
        <a class="btn btn-primary" href="?confirm=1">Run import</a>
        <a class="btn btn-ghost" href="?confirm=1&force=1">Run import + wipe existing assessment data</a>
    </p>
    <?php else: ?>
        <div class="empty">
            <p>Supabase is unreachable right now. Free-tier projects auto-pause after a week of inactivity.</p>
            <p><strong>Wake it up:</strong> Sign into <a href="https://supabase.com/dashboard">supabase.com/dashboard</a> →
            open the <code>Little Graduates</code> project → click <strong>Restore project</strong>. Refresh this page in ~30 seconds.</p>
        </div>
    <?php endif; ?>
    <p class="muted small">After the import succeeds, <strong>delete <code>migrate_from_supabase.php</code></strong> from the server.</p>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ----- Execute import -------------------------------------------------------
$pdo = db();
$report = [];

try {
    // Pull all source rows up front; if any fails we abort before touching MySQL.
    $src = [
        'teachers'                  => supa_fetch_all('teachers'),
        'students'                  => supa_fetch_all('students'),
        'skill_indicators'          => supa_fetch_all('skill_indicators'),
        'student_custom_indicators' => supa_fetch_all('student_custom_indicators'),
        'evaluation_cards'          => supa_fetch_all('evaluation_cards'),
        'assessments'               => supa_fetch_all('assessments'),
        'assessment_comments'       => supa_fetch_all('assessment_comments'),
        'student_baselines'         => supa_fetch_all('student_baselines'),
        'rating_config'             => supa_fetch_all('rating_config'),
    ];

    $pdo->beginTransaction();

    // 1. Teachers — upsert by name. Keep the calling admin's row id intact.
    $teacherMap = [];   // uuid → mysql_id
    $upsT = $pdo->prepare("INSERT INTO teachers (name, pin_hash, role, active, created_at) VALUES (:n,:h,:r,1,:c) ON DUPLICATE KEY UPDATE role = VALUES(role)");
    $selT = $pdo->prepare("SELECT id FROM teachers WHERE name = :n");
    foreach ($src['teachers'] as $row) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') continue;
        // Supabase legacy schema may store plaintext PIN in 'pin'. We hash it.
        $pin  = isset($row['pin']) ? preg_replace('/\D/', '', (string)$row['pin']) : '';
        $hash = ($pin !== '' && strlen($pin) >= 4 && strlen($pin) <= 6)
            ? password_hash($pin, PASSWORD_DEFAULT)
            : password_hash('0000', PASSWORD_DEFAULT);
        $role = ($row['role'] ?? 'teacher') === 'admin' ? 'admin' : 'teacher';
        $created = $row['created_at'] ?? null;
        $created = $created ? date('Y-m-d H:i:s', strtotime($created)) : date('Y-m-d H:i:s');
        $upsT->execute([':n' => $name, ':h' => $hash, ':r' => $role, ':c' => $created]);
        $selT->execute([':n' => $name]);
        $teacherMap[$row['id']] = (int)$selT->fetchColumn();
    }
    $report['teachers'] = count($teacherMap);

    // 2. Students — upsert by (first_name, last_name, grade, teacher_id).
    $studentMap = [];
    $selS = $pdo->prepare("SELECT id FROM students WHERE first_name=:f AND last_name=:l AND grade=:g AND teacher_id=:t");
    $insS = $pdo->prepare("INSERT INTO students (first_name, last_name, grade, teacher_id, created_at) VALUES (:f,:l,:g,:t,:c)");
    foreach ($src['students'] as $row) {
        $tid = $teacherMap[$row['teacher_id'] ?? ''] ?? null;
        if (!$tid) continue;
        $f = trim((string)($row['first_name'] ?? ''));
        $l = trim((string)($row['last_name']  ?? ''));
        $g = $row['grade'] ?? '';
        if ($f === '' || !in_array($g, ['Playgroup','Nursery','LKG','UKG'], true)) continue;
        $selS->execute([':f'=>$f, ':l'=>$l, ':g'=>$g, ':t'=>$tid]);
        $existing = $selS->fetchColumn();
        if ($existing) {
            $studentMap[$row['id']] = (int)$existing;
        } else {
            $created = $row['created_at'] ?? null;
            $created = $created ? date('Y-m-d H:i:s', strtotime($created)) : date('Y-m-d H:i:s');
            $insS->execute([':f'=>$f, ':l'=>$l, ':g'=>$g, ':t'=>$tid, ':c'=>$created]);
            $studentMap[$row['id']] = (int)$pdo->lastInsertId();
        }
    }
    $report['students'] = count($studentMap);

    // 3. skill_indicators — dedupe by (grade, category, indicator_text). Keep
    //    existing rows from sql/seeds.sql; only add new ones.
    $skillMap = [];
    $selI = $pdo->prepare("SELECT id FROM skill_indicators WHERE grade=:g AND category=:c AND indicator_text=:t");
    $insI = $pdo->prepare("INSERT INTO skill_indicators (grade, category, indicator_text, display_order, is_active, created_at) VALUES (:g,:c,:t,:o,:a,:cr)");
    foreach ($src['skill_indicators'] as $row) {
        $g = $row['grade'] ?? '';
        $c = trim((string)($row['category'] ?? ''));
        $t = trim((string)($row['indicator_text'] ?? ''));
        if (!in_array($g, ['Playgroup','Nursery','LKG','UKG'], true) || $c === '' || $t === '') continue;
        $selI->execute([':g'=>$g, ':c'=>$c, ':t'=>$t]);
        $existing = $selI->fetchColumn();
        if ($existing) {
            $skillMap[$row['id']] = (int)$existing;
        } else {
            $created = $row['created_at'] ?? null;
            $created = $created ? date('Y-m-d H:i:s', strtotime($created)) : date('Y-m-d H:i:s');
            $insI->execute([':g'=>$g, ':c'=>$c, ':t'=>$t, ':o'=>(int)($row['display_order'] ?? 0), ':a'=>!empty($row['is_active']) ? 1 : 0, ':cr'=>$created]);
            $skillMap[$row['id']] = (int)$pdo->lastInsertId();
        }
    }
    $report['skill_indicators'] = count($skillMap);

    // 4. student_custom_indicators — upsert by (student_id, category, indicator_text).
    $customMap = [];
    $selC = $pdo->prepare("SELECT id FROM student_custom_indicators WHERE student_id=:s AND category=:c AND indicator_text=:t");
    $insC = $pdo->prepare("INSERT INTO student_custom_indicators (student_id, teacher_id, category, indicator_text, display_order, is_active, created_at) VALUES (:s,:t,:c,:tx,:o,:a,:cr)");
    foreach ($src['student_custom_indicators'] as $row) {
        $sid = $studentMap[$row['student_id'] ?? ''] ?? null;
        $tid = $teacherMap[$row['teacher_id'] ?? ''] ?? null;
        if (!$sid || !$tid) continue;
        $c = trim((string)($row['category'] ?? ''));
        $t = trim((string)($row['indicator_text'] ?? ''));
        if ($c === '' || $t === '') continue;
        $selC->execute([':s'=>$sid, ':c'=>$c, ':t'=>$t]);
        $existing = $selC->fetchColumn();
        if ($existing) {
            $customMap[$row['id']] = (int)$existing;
        } else {
            $created = $row['created_at'] ?? null;
            $created = $created ? date('Y-m-d H:i:s', strtotime($created)) : date('Y-m-d H:i:s');
            $insC->execute([':s'=>$sid, ':t'=>$tid, ':c'=>$c, ':tx'=>$t, ':o'=>(int)($row['display_order'] ?? 0), ':a'=>!empty($row['is_active']) ? 1 : 0, ':cr'=>$created]);
            $customMap[$row['id']] = (int)$pdo->lastInsertId();
        }
    }
    $report['student_custom_indicators'] = count($customMap);

    // The remaining tables aren't natural-keyed, so respect $force.
    if ($force) {
        // Order matters because of FKs, but all CASCADE on student delete; explicit anyway.
        $pdo->exec("DELETE FROM evaluation_cards");
        $pdo->exec("DELETE FROM assessments");
        $pdo->exec("DELETE FROM assessment_comments");
        $pdo->exec("DELETE FROM student_baselines");
    }

    // 5. evaluation_cards
    $report['evaluation_cards'] = 0;
    if ($force || (int)$pdo->query("SELECT COUNT(*) FROM evaluation_cards")->fetchColumn() === 0) {
        $insE = $pdo->prepare("INSERT INTO evaluation_cards (student_id, teacher_id, month_year, indicator_id, rating, is_custom_indicator, created_at) VALUES (:s,:t,:m,:i,:r,:c,:cr)");
        foreach ($src['evaluation_cards'] as $row) {
            $sid = $studentMap[$row['student_id'] ?? ''] ?? null;
            $tid = $teacherMap[$row['teacher_id'] ?? ''] ?? null;
            $isCustom = !empty($row['is_custom_indicator']) ? 1 : 0;
            $ind = $isCustom ? ($customMap[$row['indicator_id'] ?? ''] ?? null)
                              : ($skillMap[$row['indicator_id']  ?? ''] ?? null);
            if (!$sid || !$tid || !$ind) continue;
            $r = $row['rating'] ?? '';
            if (!in_array($r, ['D','P','N'], true)) continue;
            $created = $row['created_at'] ?? null;
            $created = $created ? date('Y-m-d H:i:s', strtotime($created)) : date('Y-m-d H:i:s');
            try {
                $insE->execute([':s'=>$sid, ':t'=>$tid, ':m'=>$row['month_year'], ':i'=>$ind, ':r'=>$r, ':c'=>$isCustom, ':cr'=>$created]);
                $report['evaluation_cards']++;
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), '1062') === false) throw $e;   // ignore dup-key
            }
        }
    }

    // 6. assessments
    $report['assessments'] = 0;
    if ($force || (int)$pdo->query("SELECT COUNT(*) FROM assessments")->fetchColumn() === 0) {
        $insA = $pdo->prepare("INSERT INTO assessments (student_id, teacher_id, month_year, category, score, category_avg, created_at) VALUES (:s,:t,:m,:c,:sc,:av,:cr)");
        foreach ($src['assessments'] as $row) {
            $sid = $studentMap[$row['student_id'] ?? ''] ?? null;
            $tid = $teacherMap[$row['teacher_id'] ?? ''] ?? null;
            if (!$sid || !$tid) continue;
            $created = $row['created_at'] ?? null;
            $created = $created ? date('Y-m-d H:i:s', strtotime($created)) : date('Y-m-d H:i:s');
            try {
                $insA->execute([
                    ':s'=>$sid, ':t'=>$tid, ':m'=>$row['month_year'], ':c'=>$row['category'],
                    ':sc'=>(int)$row['score'], ':av'=>number_format((float)$row['category_avg'], 2, '.', ''),
                    ':cr'=>$created,
                ]);
                $report['assessments']++;
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), '1062') === false) throw $e;
            }
        }
    }

    // 7. assessment_comments
    $report['assessment_comments'] = 0;
    if ($force || (int)$pdo->query("SELECT COUNT(*) FROM assessment_comments")->fetchColumn() === 0) {
        $insC = $pdo->prepare("INSERT INTO assessment_comments (student_id, teacher_id, month_year, category, comment, created_at) VALUES (:s,:t,:m,:c,:b,:cr)");
        foreach ($src['assessment_comments'] as $row) {
            $sid = $studentMap[$row['student_id'] ?? ''] ?? null;
            $tid = $teacherMap[$row['teacher_id'] ?? ''] ?? null;
            if (!$sid || !$tid) continue;
            $body = trim((string)($row['comment'] ?? ''));
            if ($body === '') continue;
            $created = $row['created_at'] ?? null;
            $created = $created ? date('Y-m-d H:i:s', strtotime($created)) : date('Y-m-d H:i:s');
            $insC->execute([
                ':s'=>$sid, ':t'=>$tid, ':m'=>$row['month_year'],
                ':c'=>$row['category'] ?: null, ':b'=>$body, ':cr'=>$created,
            ]);
            $report['assessment_comments']++;
        }
    }

    // 8. student_baselines
    $report['student_baselines'] = 0;
    if ($force || (int)$pdo->query("SELECT COUNT(*) FROM student_baselines")->fetchColumn() === 0) {
        $insB = $pdo->prepare("
            INSERT INTO student_baselines
                (student_id, teacher_id, recorded_by, gross_motor, fine_motor, literacy,
                 numeracy, social_skills, communication, overall_notes, recorded_at, created_at)
            VALUES (:s,:t,:rb,:gm,:fm,:lit,:num,:soc,:com,:notes,:rd,:cr)
            ON DUPLICATE KEY UPDATE
                teacher_id    = VALUES(teacher_id),
                recorded_by   = VALUES(recorded_by),
                gross_motor   = VALUES(gross_motor),
                fine_motor    = VALUES(fine_motor),
                literacy      = VALUES(literacy),
                numeracy      = VALUES(numeracy),
                social_skills = VALUES(social_skills),
                communication = VALUES(communication),
                overall_notes = VALUES(overall_notes),
                recorded_at   = VALUES(recorded_at)
        ");
        foreach ($src['student_baselines'] as $row) {
            $sid = $studentMap[$row['student_id'] ?? ''] ?? null;
            $tid = $teacherMap[$row['teacher_id'] ?? ''] ?? null;
            if (!$sid || !$tid) continue;
            $rd = $row['recorded_at'] ?? null;
            $rd = $rd ? date('Y-m-d', strtotime($rd)) : null;
            $created = $row['created_at'] ?? null;
            $created = $created ? date('Y-m-d H:i:s', strtotime($created)) : date('Y-m-d H:i:s');
            $insB->execute([
                ':s'=>$sid, ':t'=>$tid,
                ':rb'=>$row['recorded_by'] ?? '',
                ':gm'=>$row['gross_motor']    ?: null,
                ':fm'=>$row['fine_motor']     ?: null,
                ':lit'=>$row['literacy']      ?: null,
                ':num'=>$row['numeracy']      ?: null,
                ':soc'=>$row['social_skills'] ?: null,
                ':com'=>$row['communication'] ?: null,
                ':notes'=>$row['overall_notes'] ?: null,
                ':rd'=>$rd, ':cr'=>$created,
            ]);
            $report['student_baselines']++;
        }
    }

    // 9. rating_config — only insert codes not already present.
    $insR = $pdo->prepare("INSERT IGNORE INTO rating_config (code, label, color, numeric_value, is_active) VALUES (:c,:l,:co,:n,:a)");
    foreach ($src['rating_config'] as $row) {
        $code = strtoupper(substr((string)($row['code'] ?? ''), 0, 1));
        if (!preg_match('/^[A-Z]$/', $code)) continue;
        $insR->execute([
            ':c'=>$code, ':l'=>$row['label'] ?? $code, ':co'=>$row['color'] ?? '#888888',
            ':n'=>(int)($row['numeric_value'] ?? 0), ':a'=>!empty($row['is_active']) ? 1 : 0,
        ]);
    }
    $report['rating_config'] = (int)$pdo->query("SELECT COUNT(*) FROM rating_config")->fetchColumn();

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', 'Import failed (rolled back): ' . $e->getMessage());
    redirect('migrate_from_supabase.php');
}

require __DIR__ . '/includes/header.php';
?>
<h1>Import finished ✔</h1>
<div class="card">
    <table class="admin-table">
        <thead><tr><th>Table</th><th>Rows after import</th></tr></thead>
        <tbody>
        <?php foreach ($report as $t => $n): ?>
            <tr><td><code><?= e($t) ?></code></td><td><?= (int)$n ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="flash flash-ok">
    Everything is in the MySQL database. <strong>Now delete this file</strong>
    (<code>migrate_from_supabase.php</code>) via cPanel File Manager.
</div>
<p>
    <a class="btn btn-primary" href="index.php">Back to dashboard</a>
</p>
<p class="muted small">
    <strong>Teacher PINs:</strong> the Supabase schema stored plaintext PINs.
    If a teacher row didn't have a usable PIN, this script assigned <code>0000</code>
    as a placeholder. Visit <strong>Admin → Teachers → Edit</strong> to set real PINs.
</p>
<?php require __DIR__ . '/includes/footer.php'; ?>
