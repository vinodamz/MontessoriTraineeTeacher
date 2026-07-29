<?php
/**
 * assessment/report_pdf.php — download the child progress report as a real PDF.
 *
 * Same access rule as report.php: admins any child, teachers only their own.
 *
 *   GET ?student_id=N → application/pdf attachment
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/child_report.php';
require_once __DIR__ . '/../includes/child_report_pdf.php';

$user = require_module('montessori');

$studentId = (int)($_GET['student_id'] ?? 0);
if ($studentId <= 0) { http_response_code(400); exit('No child selected.'); }

$d = child_report_data($studentId);
if (!$d) { http_response_code(404); exit('Student not found.'); }

if (($user['role'] ?? '') !== 'admin' && (int)$d['student']['teacher_id'] !== (int)$user['id']) {
    http_response_code(403); exit('You can only view your own students.');
}

child_report_pdf_stream($d, function_exists('app_name') ? app_name() : '');
