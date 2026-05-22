<?php
/**
 * crm/contact_vcard.php — download an inquiry as a vCard (.vcf).
 *
 * On Android, tapping the .vcf opens the Google Contacts save dialog
 * directly; on iOS it adds to iCloud Contacts; on desktop it opens in
 * Outlook / Contacts.app.
 *
 *   GET /crm/contact_vcard.php?id=42
 *
 * The contact name follows the format the team uses internally:
 *   LG-<parentname>-<childname>-Enquiry
 *
 * Where:
 *   - parentname is the first inquiry_parents.name, falling back to
 *     inquiry_families.primary_name.
 *   - childname is the first inquiry_children.first_name, omitted if
 *     no children are on file.
 *
 * Logs `contact_saved` to the audit feed.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

$user = require_module('crm');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo 'Missing id.'; exit; }

$pdo = db();

$famStmt = $pdo->prepare("SELECT * FROM inquiry_families WHERE id = :id");
$famStmt->execute([':id' => $id]);
$family = $famStmt->fetch();
if (!$family) { http_response_code(404); echo 'Inquiry not found.'; exit; }

$parentStmt = $pdo->prepare("
    SELECT name, phone, email
    FROM inquiry_parents
    WHERE family_id = :id
    ORDER BY is_primary DESC, id ASC
    LIMIT 1
");
$parentStmt->execute([':id' => $id]);
$parent = $parentStmt->fetch();

$kidStmt = $pdo->prepare("
    SELECT first_name, last_name
    FROM inquiry_children
    WHERE family_id = :id
    ORDER BY id ASC
    LIMIT 1
");
$kidStmt->execute([':id' => $id]);
$kid = $kidStmt->fetch();

/** Strip everything except letters/digits/dot/underscore so the FN field
 *  doesn't carry vCard-significant characters (comma/semicolon/colon). */
function vcard_slug(string $s): string
{
    $s = trim($s);
    if ($s === '') return '';
    // Collapse internal whitespace to a single space, then to underscore.
    $s = preg_replace('/\s+/', ' ', $s);
    // Drop characters that have special meaning in vCard property values.
    $s = str_replace([';', ',', ':', '\\', "\n", "\r"], '', $s);
    return $s;
}

function vcard_escape(string $s): string
{
    return str_replace(['\\', "\n", "\r", ',', ';'],
                       ['\\\\', '\\n', '',    '\\,', '\\;'], $s);
}

$parentName = vcard_slug((string)($parent['name'] ?? $family['primary_name'] ?? ''));
$kidName    = vcard_slug((string)($kid['first_name'] ?? ''));

$nameParts = ['LG'];
if ($parentName !== '') $nameParts[] = str_replace(' ', '_', $parentName);
if ($kidName    !== '') $nameParts[] = str_replace(' ', '_', $kidName);
$nameParts[] = 'Enquiry';
$displayName = implode('-', $nameParts);

// Filename: same as display name but safe for filesystems.
$filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $displayName) . '.vcf';

$phone = trim((string)$family['primary_phone']);
$intl  = crm_phone_intl_digits($phone);

$email = trim((string)$family['primary_email']) ?: trim((string)($parent['email'] ?? ''));

$noteLines = ['Admissions inquiry'];
if ($kidName    !== '') $noteLines[] = "Child: " . $kidName;
if ($parentName !== '') $noteLines[] = "Parent: " . $parentName;
$status = (string)($family['status'] ?? '');
if ($status !== '') $noteLines[] = "Stage: " . crm_status_label($status);
$noteLines[] = "Inquiry ID: " . $id;
$note = implode("\n", $noteLines);

$lines = [
    'BEGIN:VCARD',
    'VERSION:3.0',
    'FN:'  . vcard_escape($displayName),
    // N:family;given;additional;prefix;suffix
    'N:Enquiry;' . vcard_escape(implode('-', array_slice($nameParts, 0, -1))) . ';;;',
    'ORG:Little Graduates',
];
if ($phone !== '') {
    $telValue = $intl !== '' ? '+' . $intl : $phone;
    $lines[] = 'TEL;TYPE=CELL,VOICE:' . vcard_escape($telValue);
}
if ($email !== '') {
    $lines[] = 'EMAIL;TYPE=INTERNET:' . vcard_escape($email);
}
$lines[] = 'NOTE:' . vcard_escape($note);
$lines[] = 'END:VCARD';

// CRLF line endings per RFC 6350.
$vcard = implode("\r\n", $lines) . "\r\n";

crm_audit_log('contact_saved', $id, [
    'filename' => $filename,
    'name'     => $displayName,
]);

header('Content-Type: text/vcard; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($vcard));
header('Cache-Control: no-store');
echo $vcard;
