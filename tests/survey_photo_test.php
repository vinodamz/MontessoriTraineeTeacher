<?php
/**
 * Survey photo questions: spec validation, byte sniffing, collect/re-render,
 * and the staff link stored in place of the file.
 *
 * Run: php tests/survey_photo_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/surveys.php';

$failed = 0;
$passed = 0;

function expect_same($got, $want, string $name): void
{
    global $failed, $passed;
    if ($got === $want) {
        $passed++;
        echo "ok  $name\n";
        return;
    }
    $failed++;
    echo "FAIL $name\n";
    echo "     got  " . var_export($got, true) . "\n";
    echo "     want " . var_export($want, true) . "\n";
}

function expect_true($got, string $name): void
{
    expect_same((bool)$got, true, $name);
}

$tmp = sys_get_temp_dir() . '/survey-photo-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
survey_photos_testing_dir($tmp);

$photoQ = [
    'key' => 'child_photo', 'type' => 'photo',
    'label' => "Upload your child's latest photo", 'required' => true,
];
$spec = [
    'key' => 'photo_demo',
    'title' => 'Photo demo',
    'sections' => [[
        'title' => 'Your child',
        'questions' => [
            ['key' => 'child_name', 'type' => 'text', 'label' => "Child's name", 'required' => true],
            ['key' => 'coming', 'type' => 'radio', 'label' => 'Coming?', 'required' => true,
             'options' => ['yes' => 'Yes', 'no' => 'No']],
            $photoQ,
        ],
    ]],
];

$valid = survey_definition_validate($spec);
expect_true($valid['ok'], 'photo question is an allowed type');
expect_same($valid['spec']['sections'][0]['questions'][2]['type'], 'photo', 'validated spec keeps type photo');
expect_true(!empty($valid['spec']['sections'][0]['questions'][2]['required']), 'required flag is kept');
expect_true(in_array('photo', SURVEY_DEF_ALLOWED_TYPES, true), 'allowed-types list includes photo');

$unknown = $spec;
$unknown['sections'][0]['questions'][2]['type'] = 'file';
$bad = survey_definition_validate($unknown);
expect_true(!$bad['ok'], 'unknown type file is still rejected');

// Sniff: real types, not extensions.
$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$png = $tmp . '/dot.png';
file_put_contents($png, $pngBytes);
expect_same(survey_photo_detect_mime($png), 'image/png', 'png bytes are image/png');

$jpeg = $tmp . '/dot.jpg';
if (function_exists('imagejpeg')) {
    $im = imagecreatetruecolor(8, 8);
    $red = imagecolorallocate($im, 220, 40, 90);
    imagefilledrectangle($im, 0, 0, 7, 7, $red);
    imagejpeg($im, $jpeg, 90);
    imagedestroy($im);
} else {
    file_put_contents($jpeg, "\xFF\xD8\xFF\xD9");
}
expect_same(survey_photo_detect_mime($jpeg), 'image/jpeg', 'jpeg bytes are image/jpeg');

$heic = $tmp . '/phone.heic';
file_put_contents($heic, pack('N', 20) . 'ftypheic' . pack('N', 0) . 'heic');
expect_same(survey_photo_sniff($heic), 'image/heic', 'ftyp heic brand is HEIC');
$heicMime = survey_photo_detect_mime($heic);
expect_true($heicMime === 'image/heic' || $heicMime === 'image/heif', 'HEIC accepted when finfo does not name it');

$heif = $tmp . '/phone.heif';
file_put_contents($heif, pack('N', 20) . 'ftypmif1' . pack('N', 0) . 'mif1');
expect_same(survey_photo_sniff($heif), 'image/heif', 'ftyp mif1 brand is HEIF');

$avif = $tmp . '/phone.avif';
file_put_contents($avif, pack('N', 20) . 'ftypavif' . pack('N', 0) . 'avif');
expect_same(survey_photo_sniff($avif), null, 'AVIF is not a survey photo');

$gif = $tmp . '/anim.gif';
file_put_contents($gif, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
expect_same(survey_photo_detect_mime($gif), null, 'GIF is rejected');

$txt = $tmp . '/notes.jpg';
file_put_contents($txt, "this is not an image\n");
expect_same(survey_photo_detect_mime($txt), null, 'a renamed text file is not an image');

$pdf = $tmp . '/scan.pdf';
file_put_contents($pdf, "%PDF-1.4\n");
expect_same(survey_photo_detect_mime($pdf), null, 'PDF is rejected');

function files_entry(string $path, string $clientName): array
{
    return [
        'name'     => $clientName,
        'type'     => 'application/octet-stream',
        'tmp_name' => $path,
        'error'    => UPLOAD_ERR_OK,
        'size'     => (int)filesize($path),
    ];
}

// Missing required photo keeps the other answers and names the field.
[$answers, $errors] = survey_collect($spec, ['child_name' => 'Aarav Nair', 'coming' => 'yes']);
expect_same($answers['child_name'] ?? null, 'Aarav Nair', 'text answer kept when the photo is missing');
expect_same($answers['coming'] ?? null, 'yes', 'radio answer kept when the photo is missing');
expect_true(isset($errors['child_photo']), 'missing required photo is an error');
expect_same($errors['child_name'] ?? null, null, 'a present name is not an error');

// Non-image: error, other answers kept, nothing staged.
[$answers, $errors, $staged] = survey_collect($spec, [
    'child_name' => 'Aarav Nair',
    'coming'     => 'yes',
], ['child_photo' => files_entry($txt, 'notes.jpg')]);
expect_true(isset($errors['child_photo']), 'non-image upload is an error');
expect_true(str_contains((string)$errors['child_photo'], 'JPG'), 'non-image error names the allowed types');
expect_same($answers['child_name'] ?? null, 'Aarav Nair', 'name survives a rejected file');
expect_same($answers['coming'] ?? null, 'yes', 'radio survives a rejected file');
expect_same($staged, [], 'a rejected file is not staged');
expect_same(glob($tmp . '/p_*') ?: [], [], 'a rejected file is not written');

// A real PNG is staged under an unguessable pending name.
[$answers, $errors, $staged] = survey_collect($spec, [
    'child_name' => 'Aarav Nair',
    'coming'     => 'yes',
], ['child_photo' => files_entry($png, 'Latest Photo.PNG')]);
expect_same($errors, [], 'a png has no field errors');
$ref = survey_photo_ref($answers['child_photo'] ?? null, true);
expect_true($ref !== null && !empty($ref['pending']), 'accepted photo is a pending reference');
expect_true($ref && preg_match('/^p_[a-f0-9]{32}\.png$/', $ref['file']) === 1, 'pending name is random');
expect_true($ref && is_file($tmp . '/' . $ref['file']), 'pending bytes were stored');
expect_same($ref['name'] ?? null, 'Latest Photo.PNG', 'original name is kept for display only');
expect_same($ref['mime'] ?? null, 'image/png', 'stored mime comes from the bytes');

// Another field failing must not drop the photo.
[$answers2, $errors2, $staged2] = survey_collect($spec, [
    'child_name' => '',
    'coming'     => 'yes',
    'child_photo__keep' => (string)$staged['child_photo']['nonce'],
], [], $staged);
expect_true(isset($errors2['child_name']), 'empty name is still an error');
expect_same($errors2['child_photo'] ?? null, null, 'staged photo satisfies the required photo');
expect_same($answers2['child_photo']['file'] ?? null, $ref['file'], 'the same staged file is claimed');
expect_same($answers2['coming'] ?? null, 'yes', 'radio still kept');

// A fresh submit without the nonce must not attach that staged photo.
[$answers3, $errors3] = survey_collect($spec, [
    'child_name' => 'Meera Nair',
    'coming'     => 'no',
], [], $staged);
expect_true(isset($errors3['child_photo']), 'without the keep nonce the photo is not reused');
expect_same($answers3['child_photo'] ?? null, null, 'unstaged submit has no photo answer');
expect_same($answers3['child_name'] ?? null, 'Meera Nair', 'the new name is what was typed');

// Commit copies to a final name the staff endpoint can serve; pending stays until discard.
$stored = survey_photos_commit($answers);
$final = survey_photo_ref($stored['child_photo'] ?? null);
expect_true($final !== null && empty($final['pending']), 'committed photo is a final reference');
expect_true($final && preg_match('/^[a-f0-9]{32}\.png$/', $final['file']) === 1, 'final name is random and not pending');
expect_true($final && is_file($tmp . '/' . $final['file']), 'final bytes exist');
expect_true(is_file($tmp . '/' . $ref['file']), 'pending file remains until discard');
expect_true($final && $ref && $final['file'] !== $ref['file'], 'final name is not the staged name');

survey_photos_discard_pending($answers);
expect_true(!is_file($tmp . '/' . $ref['file']), 'discard removes the staged file');
expect_true($final && is_file($tmp . '/' . $final['file']), 'discard leaves the saved file');

// Rollback removes a committed file that never landed on a row.
survey_photos_rollback_committed($stored);
expect_true($final && !is_file($tmp . '/' . $final['file']), 'rollback removes the unsaved copy');

// Staff cell is a link, never the storage name and never the word Array.
$savedRef = [
    'file' => bin2hex(random_bytes(16)) . '.jpg',
    'mime' => 'image/jpeg',
    'size' => 1200,
    'name' => 'child.jpg',
];
$col = ['key' => 'child_photo', 'label' => 'Photo', 'type' => 'photo', 'q' => $photoQ];
$cellAnswers = ['child_photo' => $savedRef];
expect_same(survey_cell($col, $cellAnswers), 'Photo uploaded', 'photo cell without a response id is a label');
$link = survey_cell($col, $cellAnswers, ['response_id' => 42]);
expect_same($link, '/surveys/photo.php?id=42&k=child_photo', 'csv cell is the staff link');
expect_true(!str_contains($link, $savedRef['file']), 'csv cell does not contain the storage name');
expect_same(survey_cell(
    ['key' => 'child_name', 'type' => 'text', 'q' => []],
    ['child_name' => 'Aarav Nair']
), 'Aarav Nair', 'text cells are unchanged');

// References that are not ours are not photos.
expect_same(survey_photo_ref(['file' => '../' . $savedRef['file'], 'mime' => 'image/jpeg']), null, 'paths are not references');
expect_same(survey_photo_ref(['file' => 'p_' . bin2hex(random_bytes(16)) . '.jpg', 'mime' => 'image/jpeg', 'pending' => true]), null, 'pending names are not servable references');
expect_same(survey_photo_ref('just a string'), null, 'a text answer is not a photo');

// Too big, even when the header looks like a jpeg.
$big = $tmp . '/big.jpg';
$fh = fopen($big, 'wb');
fwrite($fh, "\xFF\xD8\xFF");
ftruncate($fh, SURVEY_PHOTO_MAX_BYTES + 1);
fclose($fh);
$tooBig = survey_photo_accept(files_entry($big, 'huge.jpg'));
expect_true(!$tooBig['ok'], 'over 10 MB is rejected');
expect_true(str_contains($tooBig['error'], 'too large'), 'oversize error says too large');

// Old staged files are removed; a saved (non-pending) file is not.
$old = $tmp . '/p_' . bin2hex(random_bytes(16)) . '.jpg';
file_put_contents($old, 'x');
touch($old, time() - SURVEY_PHOTO_PENDING_MAX_AGE - 10);
$kept = $tmp . '/' . bin2hex(random_bytes(16)) . '.jpg';
file_put_contents($kept, 'x');
touch($kept, time() - SURVEY_PHOTO_PENDING_MAX_AGE - 10);
survey_photo_gc_pending();
expect_true(!is_file($old), 'stale staged files are deleted');
expect_true(is_file($kept), 'saved files are not deleted by staged cleanup');

// Ceiling stays at the product cap when PHP allows more than 10 MB.
expect_true(survey_photo_upload_ceiling() <= SURVEY_PHOTO_MAX_BYTES, 'upload ceiling never exceeds 10 MB');

// Cleanup the temp tree.
foreach (glob($tmp . '/*') ?: [] as $p) @unlink($p);
@rmdir($tmp);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
