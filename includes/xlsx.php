<?php
/**
 * includes/xlsx.php — minimal .xlsx read/write using PHP's built-in
 * zip + xml extensions. No Composer, no PhpSpreadsheet.
 *
 * Trade-offs we accept to keep this small:
 *   - Every cell is written as a text (inline string) cell with numFmt "@".
 *     This is what makes the non-tech round-trip safe: phone numbers keep
 *     their leading zeros, admission numbers stay as strings, and dates
 *     come back as the YYYY-MM-DD text we exported (no Excel serial-date
 *     surprises).
 *   - The reader handles the three cell shapes Excel actually emits in
 *     practice: shared-string (t="s"), inline-string (t="inlineStr"), and
 *     bare-number/no-t. Rich-text runs are flattened to their visible text.
 *
 * Public API:
 *   xlsx_write(string $path, array $headers, array $rows): void
 *   xlsx_read(string $path): array{headers: string[], rows: array<int, string[]>}
 */
declare(strict_types=1);

/**
 * Write a single-sheet xlsx file to $path.
 *   $headers : ordered list of column header strings (row 1, bold)
 *   $rows    : list of rows; each row is either a positional array OR an
 *              assoc array keyed by header. Missing values render as blank.
 */
function xlsx_write(string $path, array $headers, array $rows): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Cannot open $path for writing");
    }

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
          '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
          '<Default Extension="xml" ContentType="application/xml"/>' .
          '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
          '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
          '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
        '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
          '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>');

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
          '<sheets><sheet name="Students" sheetId="1" r:id="rId1"/></sheets>' .
        '</workbook>');

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
          '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
          '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
        '</Relationships>');

    // Two cell styles, both pinned to text format "@":
    //   s=1 → body cell (text)
    //   s=2 → header cell (text, bold)
    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
          '<numFmts count="1"><numFmt numFmtId="164" formatCode="@"/></numFmts>' .
          '<fonts count="2">' .
            '<font><sz val="11"/><name val="Calibri"/></font>' .
            '<font><b/><sz val="11"/><name val="Calibri"/></font>' .
          '</fonts>' .
          '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>' .
          '<borders count="1"><border/></borders>' .
          '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
          '<cellXfs count="3">' .
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' .
            '<xf numFmtId="164" fontId="0" applyNumberFormat="1"/>' .
            '<xf numFmtId="164" fontId="1" applyNumberFormat="1" applyFont="1"/>' .
          '</cellXfs>' .
        '</styleSheet>');

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
             '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

    $rowNum = 1;
    $sheet .= '<row r="' . $rowNum . '">';
    foreach ($headers as $i => $h) {
        $sheet .= xlsx_inline_cell(xlsx_col_letter($i) . $rowNum, (string)$h, 2);
    }
    $sheet .= '</row>';

    foreach ($rows as $row) {
        $rowNum++;
        $sheet .= '<row r="' . $rowNum . '">';
        foreach ($headers as $i => $h) {
            $val = '';
            if (is_array($row)) {
                $val = (string)($row[$h] ?? $row[$i] ?? '');
            }
            if ($val === '') continue;
            $sheet .= xlsx_inline_cell(xlsx_col_letter($i) . $rowNum, $val, 1);
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);

    $zip->close();
}

/** "A1"-style cell with inline text value and a style index. */
function xlsx_inline_cell(string $ref, string $value, int $styleIdx): string
{
    $safe = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return '<c r="' . $ref . '" t="inlineStr" s="' . $styleIdx . '">' .
             '<is><t xml:space="preserve">' . $safe . '</t></is>' .
           '</c>';
}

/** 0 → "A", 25 → "Z", 26 → "AA", 27 → "AB", … */
function xlsx_col_letter(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $r = ($i - 1) % 26;
        $s = chr(65 + $r) . $s;
        $i = intdiv($i - 1, 26);
    }
    return $s;
}

/** "AB12" → 27 (zero-based column index for "AB"). */
function xlsx_col_index(string $ref): int
{
    $col = '';
    foreach (str_split($ref) as $c) {
        if (!ctype_alpha($c)) break;
        $col .= $c;
    }
    $i = 0;
    foreach (str_split(strtoupper($col)) as $c) {
        $i = $i * 26 + (ord($c) - 64);
    }
    return $i - 1;
}

/**
 * Read a single-sheet xlsx. Returns:
 *   ['headers' => [string, ...],         // lowercased + trimmed
 *    'rows'    => [[string, ...], ...]]  // each row as positional array, padded to header width
 */
function xlsx_read(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open xlsx (corrupt or not an Excel file).');
    }

    // Shared strings (only present if Excel wrote them).
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $sx = @simplexml_load_string($ssXml);
        if ($sx) {
            foreach ($sx->si as $si) {
                // <si><t>txt</t></si>  OR  <si><r><t>part</t></r><r>...</r></si> (rich text)
                if (isset($si->t)) {
                    $shared[] = (string)$si->t;
                } else {
                    $parts = [];
                    foreach ($si->r as $r) $parts[] = (string)$r->t;
                    $shared[] = implode('', $parts);
                }
            }
        }
    }

    // Locate the first worksheet. Most files are xl/worksheets/sheet1.xml,
    // but resolve via the relationships file when the name differs.
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($rels) {
            $rx = @simplexml_load_string($rels);
            if ($rx) {
                foreach ($rx->Relationship as $rel) {
                    $type = (string)$rel['Type'];
                    if (str_ends_with($type, '/worksheet')) {
                        $sheetXml = $zip->getFromName('xl/' . ltrim((string)$rel['Target'], '/'));
                        if ($sheetXml !== false) break;
                    }
                }
            }
        }
    }
    $zip->close();

    if ($sheetXml === false) {
        throw new RuntimeException('No worksheet found inside the xlsx.');
    }

    $reader = new XMLReader();
    $reader->XML($sheetXml, 'UTF-8', LIBXML_NONET);

    $headers = [];
    $rows    = [];
    $rowIdx  = -1;

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'row') continue;
        $rowIdx++;
        $cells = xlsx_read_row_cells($reader, $shared);
        if ($rowIdx === 0) {
            foreach ($cells as $v) $headers[] = mb_strtolower(trim((string)$v));
        } else {
            // Pad/truncate to header width.
            $width = count($headers);
            $row = array_fill(0, $width, '');
            foreach ($cells as $i => $v) {
                if ($i < $width) $row[$i] = (string)$v;
            }
            // Skip fully blank rows.
            foreach ($row as $v) {
                if ($v !== '') { $rows[] = $row; break; }
            }
        }
    }
    $reader->close();

    return ['headers' => $headers, 'rows' => $rows];
}

/** Read one <row>'s cells. Returns [colIdx => value], gaps stay unset. */
function xlsx_read_row_cells(XMLReader $reader, array $shared): array
{
    $out = [];
    if ($reader->isEmptyElement) return $out;

    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'row') break;
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'c') continue;

        $ref  = (string)$reader->getAttribute('r');
        $type = (string)($reader->getAttribute('t') ?? '');
        $col  = $ref !== '' ? xlsx_col_index($ref) : count($out);

        $val = '';
        if (!$reader->isEmptyElement) {
            $depth = $reader->depth;
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::END_ELEMENT
                    && $reader->name === 'c' && $reader->depth === $depth) break;
                if ($reader->nodeType !== XMLReader::ELEMENT) continue;
                if ($reader->name === 'v') {
                    $val = (string)$reader->readString();
                } elseif ($reader->name === 'is' || $reader->name === 't') {
                    // Inline string. readString() flattens nested <t>/<r><t>.
                    $val = (string)$reader->readString();
                }
            }
        }

        if ($type === 's' && $val !== '') {
            $idx = (int)$val;
            $val = $shared[$idx] ?? '';
        }

        $out[$col] = $val;
    }
    return $out;
}
