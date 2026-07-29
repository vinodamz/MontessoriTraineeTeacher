<?php
/**
 * includes/child_report_pdf.php — the child progress report as a real PDF.
 *
 * Generates a genuine vector PDF (selectable text, proper page breaks, page
 * numbers) rather than relying on the browser's print dialog. Reads exactly
 * the same structure child_report_data() builds, so the PDF and the on-screen
 * report can never disagree about the data.
 *
 * Same rendering rule as the HTML: a section with no data is omitted entirely.
 *
 * Text encoding: FPDF's core fonts are Latin-1, so every string goes through
 * pdf_txt() which transliterates from UTF-8. Scripts with no Latin-1
 * representation (e.g. Devanagari) degrade to "?" — embedding a Unicode TTF
 * plus complex-script shaping is well beyond what this page needs.
 */
declare(strict_types=1);

require_once __DIR__ . '/fpdf/fpdf.php';

/** UTF-8 → Latin-1 for FPDF's core fonts, never throwing on odd input. */
function pdf_txt(?string $s): string
{
    $s = (string)$s;
    if ($s === '') return '';
    if (function_exists('iconv')) {
        $out = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
        if ($out !== false) return $out;
    }
    return preg_replace('/[^\x20-\x7E\n]/', '', $s) ?? '';
}

/** '#rrggbb' → [r,g,b], defaulting to mid-grey. */
function pdf_rgb(?string $hex): array
{
    $hex = ltrim((string)$hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) return [136, 136, 136];
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

final class ChildReportPdf extends FPDF
{
    public string $schoolName = '';
    public string $childName  = '';
    /** @var array<string,array> */
    public array $ratings = [];

    private const MARGIN = 14.0;

    public function Header(): void
    {
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(173, 20, 87);
        $this->Cell(0, 6, pdf_txt(strtoupper($this->schoolName)), 0, 0, 'L');
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 6, pdf_txt($this->childName . ' - progress report'), 0, 1, 'R');
        $this->SetDrawColor(233, 30, 99);
        $this->SetLineWidth(0.6);
        $this->Line(self::MARGIN, $this->GetY() + 1, $this->GetPageWidth() - self::MARGIN, $this->GetY() + 1);
        $this->SetLineWidth(0.2);
        $this->Ln(5);
        $this->SetTextColor(43, 43, 43);
    }

    public function Footer(): void
    {
        $this->SetY(-13);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(140, 140, 140);
        $this->Cell(0, 6, pdf_txt('Generated ' . date('j M Y')), 0, 0, 'L');
        $this->Cell(0, 6, pdf_txt('Page ' . $this->PageNo() . ' of {nb}'), 0, 0, 'R');
        $this->SetTextColor(43, 43, 43);
    }

    /** Usable width between margins. */
    public function contentWidth(): float
    {
        return $this->GetPageWidth() - 2 * self::MARGIN;
    }

    /** Start a new page when $need mm wouldn't fit above the footer. */
    public function need(float $mm): void
    {
        if ($this->GetY() + $mm > $this->GetPageHeight() - 20) $this->AddPage();
    }

    public function sectionTitle(string $t): void
    {
        $this->need(14);
        $this->Ln(2);
        $this->SetFont('Helvetica', 'B', 12);
        $this->SetTextColor(173, 20, 87);
        $this->Cell(0, 7, pdf_txt($t), 0, 1, 'L');
        $this->SetDrawColor(240, 205, 220);
        $this->Line(self::MARGIN, $this->GetY(), $this->GetPageWidth() - self::MARGIN, $this->GetY());
        $this->SetTextColor(43, 43, 43);
        $this->Ln(2);
    }

    public function subTitle(string $t): void
    {
        $this->need(10);
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetTextColor(45, 107, 160);
        $this->Cell(0, 6, pdf_txt($t), 0, 1, 'L');
        $this->SetTextColor(43, 43, 43);
    }

    /** Label/value rows. Values wrap. */
    public function facts(array $rows): void
    {
        $labelW = 46.0;
        $valueW = $this->contentWidth() - $labelW;
        foreach ($rows as $label => $value) {
            $value = trim((string)$value);
            if ($value === '') continue;
            $lines = max(1, (int)ceil($this->GetStringWidth(pdf_txt($value)) / max(1.0, $valueW - 2)));
            $this->need(5.2 * $lines + 1);
            $y = $this->GetY();
            $this->SetFont('Helvetica', '', 9);
            $this->SetTextColor(110, 110, 110);
            $this->SetXY(self::MARGIN, $y);
            $this->Cell($labelW, 5.2, pdf_txt((string)$label), 0, 0, 'L');
            $this->SetTextColor(43, 43, 43);
            $this->SetFont('Helvetica', '', 10);
            $this->SetXY(self::MARGIN + $labelW, $y);
            $this->MultiCell($valueW, 5.2, pdf_txt($value), 0, 'L');
            $this->SetY(max($this->GetY(), $y + 5.2));
        }
    }

    /** Wrapped body paragraph. */
    public function para(string $text, float $size = 10.0): void
    {
        $text = trim($text);
        if ($text === '') return;
        $this->SetFont('Helvetica', '', $size);
        $lines = max(1, (int)ceil($this->GetStringWidth(pdf_txt($text)) / max(1.0, $this->contentWidth() - 2)));
        $this->need(min(40.0, 5.0 * $lines));
        $this->SetX(self::MARGIN);
        $this->MultiCell($this->contentWidth(), 5.0, pdf_txt($text), 0, 'L');
        $this->Ln(1);
    }

    public function muted(string $text, float $size = 8.5): void
    {
        if (trim($text) === '') return;
        $this->SetFont('Helvetica', 'I', $size);
        $this->SetTextColor(120, 120, 120);
        $this->SetX(self::MARGIN);
        $this->MultiCell($this->contentWidth(), 4.4, pdf_txt($text), 0, 'L');
        $this->SetTextColor(43, 43, 43);
    }

    /** A filled rating pill with the code letter, drawn at ($x,$y). */
    public function chip(float $x, float $y, string $code, float $w = 6.4, float $h = 4.6): void
    {
        if ($code === '') return;
        [$r, $g, $b] = pdf_rgb($this->ratings[$code]['color'] ?? '#888888');
        $this->SetFillColor($r, $g, $b);
        $this->Rect($x, $y, $w, $h, 'F');
        $this->SetFont('Helvetica', 'B', 7.5);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY($x, $y - 0.2);
        $this->Cell($w, $h + 0.4, pdf_txt($code), 0, 0, 'C');
        $this->SetTextColor(43, 43, 43);
    }

    /**
     * Generic table. $head = string[]; $rows = array of cells where each cell
     * is ['t' => text] or ['chip' => code] or ['chip' => code, 't' => label].
     * $widths in mm; first column is left-aligned, the rest centred.
     */
    public function table(array $head, array $rows, array $widths, bool $totalLast = false): void
    {
        $rowH = 6.2;
        $drawHead = function () use ($head, $widths, $rowH) {
            $this->SetFont('Helvetica', 'B', 7.6);
            $this->SetFillColor(250, 244, 234);
            $this->SetTextColor(122, 106, 85);
            $this->SetX(self::MARGIN);
            foreach ($head as $i => $h) {
                $this->Cell($widths[$i], $rowH, pdf_txt(strtoupper((string)$h)), 0, 0, $i === 0 ? 'L' : 'C', true);
            }
            $this->Ln($rowH);
            $this->SetTextColor(43, 43, 43);
        };

        $this->need($rowH * 3);
        $drawHead();

        foreach ($rows as $ri => $row) {
            if ($this->GetY() + $rowH > $this->GetPageHeight() - 20) {
                $this->AddPage();
                $drawHead();
            }
            $isTotal = $totalLast && $ri === count($rows) - 1;
            $y = $this->GetY();
            $x = self::MARGIN;
            if ($isTotal) {
                $this->SetFillColor(250, 244, 234);
                $this->Rect($x, $y, array_sum($widths), $rowH, 'F');
            }
            foreach ($row as $ci => $cell) {
                $w = $widths[$ci] ?? 20.0;
                $text = (string)($cell['t'] ?? '');
                $code = (string)($cell['chip'] ?? '');
                $this->SetFont('Helvetica', $isTotal || $ci === 0 ? 'B' : '', $ci === 0 ? 8.4 : 8.2);
                if ($code !== '') {
                    // Chip (+ optional label) centred in the cell.
                    $chipW = 6.4;
                    $labelW = $text !== '' ? $this->GetStringWidth(pdf_txt($text)) + 1.2 : 0.0;
                    $start = $x + max(1.0, ($w - ($chipW + $labelW)) / 2);
                    $this->chip($start, $y + 0.8, $code, $chipW, 4.6);
                    if ($text !== '') {
                        $this->SetFont('Helvetica', '', 7.8);
                        $this->SetXY($start + $chipW + 1.0, $y);
                        $this->Cell($labelW, $rowH, pdf_txt($text), 0, 0, 'L');
                    }
                } else {
                    $this->SetXY($x, $y);
                    // Truncate long first-column labels rather than breaking the grid.
                    if ($ci === 0 && $this->GetStringWidth(pdf_txt($text)) > $w - 2) {
                        while ($text !== '' && $this->GetStringWidth(pdf_txt($text . '...')) > $w - 2) {
                            $text = mb_substr($text, 0, max(0, mb_strlen($text) - 1));
                        }
                        $text .= '...';
                    }
                    $this->Cell($w, $rowH, pdf_txt($text), 0, 0, $ci === 0 ? 'L' : 'C');
                }
                $x += $w;
            }
            $this->SetY($y + $rowH);
            $this->SetDrawColor(239, 230, 216);
            $this->Line(self::MARGIN, $this->GetY(), self::MARGIN + array_sum($widths), $this->GetY());
        }
        $this->Ln(2);
    }

    /** Line chart of category averages, axis labelled with rating codes. */
    public function chart(array $d, array $months): void
    {
        $cats = $d['categories'];
        if (count($months) < 2 || !$cats) return;

        $h = 58.0;
        $this->need($h + 16);
        $x0 = self::MARGIN + 8;
        $y0 = $this->GetY() + 2;
        $w  = $this->contentWidth() - 10;
        $n  = count($months);

        $px = fn(int $i): float => $x0 + ($n === 1 ? $w / 2 : $i * ($w / ($n - 1)));
        $py = fn(float $v): float => $y0 + $h - (max(1.0, min(5.0, $v)) - 1) / 4 * $h;

        // Axis labels use the school's codes, not 1-5.
        $axis = [];
        foreach ($this->ratings as $code => $cfg) {
            $v = (int)($cfg['numeric_value'] ?? 0);
            if ($v >= 1 && $v <= 5 && !isset($axis[$v])) $axis[$v] = (string)$code;
        }
        $this->SetFont('Helvetica', '', 7);
        for ($v = 1; $v <= 5; $v++) {
            $gy = $py((float)$v);
            $this->SetDrawColor(227, 227, 227);
            $this->Line($x0, $gy, $x0 + $w, $gy);
            $this->SetTextColor(136, 136, 136);
            $this->SetXY(self::MARGIN - 2, $gy - 2);
            $this->Cell(9, 4, pdf_txt($axis[$v] ?? (string)$v), 0, 0, 'R');
        }

        // Month labels.
        foreach ($months as $i => $m) {
            $this->SetXY($px($i) - 9, $y0 + $h + 1);
            $this->Cell(18, 4, pdf_txt(month_year_label($m)), 0, 0, 'C');
        }
        $this->SetTextColor(43, 43, 43);

        $palette = [[45,107,160],[91,165,71],[236,64,122],[245,179,66],[126,87,194],[93,168,162],[224,122,95],[160,92,123]];
        $this->SetLineWidth(0.7);
        foreach (array_values($cats) as $ci => $cat) {
            [$r, $g, $b] = $palette[$ci % count($palette)];
            $this->SetDrawColor($r, $g, $b);
            $this->SetFillColor($r, $g, $b);
            $prev = null;
            foreach ($months as $i => $m) {
                if (!isset($d['catAvg'][$m][$cat])) continue;
                $cx = $px($i); $cy = $py((float)$d['catAvg'][$m][$cat]);
                if ($prev !== null) $this->Line($prev[0], $prev[1], $cx, $cy);
                $this->Rect($cx - 0.8, $cy - 0.8, 1.6, 1.6, 'F');
                $prev = [$cx, $cy];
            }
        }
        $this->SetLineWidth(0.2);
        $this->SetY($y0 + $h + 6);

        // Legend.
        $this->SetFont('Helvetica', '', 7.5);
        $lx = self::MARGIN;
        foreach (array_values($cats) as $ci => $cat) {
            [$r, $g, $b] = $palette[$ci % count($palette)];
            $tw = $this->GetStringWidth(pdf_txt($cat)) + 9;
            if ($lx + $tw > $this->GetPageWidth() - self::MARGIN) { $lx = self::MARGIN; $this->Ln(4.5); }
            $this->SetFillColor($r, $g, $b);
            $this->Rect($lx, $this->GetY() + 1.2, 2.6, 2.6, 'F');
            $this->SetXY($lx + 3.6, $this->GetY());
            $this->Cell($tw - 3.6, 4.5, pdf_txt($cat), 0, 0, 'L');
            $lx += $tw;
        }
        $this->Ln(6);
    }
}

/**
 * Build the report PDF and return the raw bytes.
 *
 * @param array  $d          from child_report_data()
 * @param string $schoolName masthead name
 */
function child_report_pdf_bytes(array $d, string $schoolName = ''): string
{
    $s        = $d['student'];
    $ratings  = $d['ratings'];
    $fullName = trim((string)$s['first_name'] . ' ' . (string)$s['last_name']);
    $first    = (string)$s['first_name'];

    // Keep the month grid readable — the widest tables get unusable past ~8.
    $allMonths = $d['months'];
    $months    = count($allMonths) > 8 ? array_slice($allMonths, -8) : $allMonths;
    $trimmed   = count($allMonths) - count($months);

    $pdf = new ChildReportPdf('P', 'mm', 'A4');
    $pdf->schoolName = $schoolName !== '' ? $schoolName : 'Progress report';
    $pdf->childName  = $fullName;
    $pdf->ratings    = $ratings;
    $pdf->SetTitle($fullName . ' - progress report');
    $pdf->SetAuthor($schoolName);
    $pdf->SetCreator('Little Graduates');
    $pdf->SetMargins(14, 12, 14);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AliasNbPages();
    $pdf->AddPage();

    // ---- Title block -----------------------------------------------------
    $pdf->SetFont('Helvetica', 'B', 19);
    $pdf->Cell(0, 9, pdf_txt($fullName), 0, 1, 'L');

    $bits = [];
    if (!empty($s['grade'])) $bits[] = (string)$s['grade'];
    $age = child_report_age($s['dob'] ?? null);
    if ($age !== '') $bits[] = $age;
    if (!empty($s['teacher_name'])) $bits[] = 'Teacher: ' . (string)$s['teacher_name'];
    if ($bits) {
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->Cell(0, 5, pdf_txt(implode('  |  ', $bits)), 0, 1, 'L');
    }
    if ($months) {
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->Cell(0, 5, pdf_txt(
            'Detailed progress report  |  ' . month_year_label($months[0]) . ' - '
            . month_year_label($months[count($months) - 1])
            . '  |  ' . count($months) . ' month' . (count($months) === 1 ? '' : 's') . ' assessed'
        ), 0, 1, 'L');
    }
    $pdf->SetTextColor(43, 43, 43);
    $pdf->Ln(2);
    if ($trimmed > 0) {
        $pdf->muted("Showing the most recent " . count($months) . " months; $trimmed earlier month"
            . ($trimmed === 1 ? ' is' : 's are') . ' not shown in the month-by-month grids.');
    }

    // ---- Child details ---------------------------------------------------
    $facts = [];
    if (!empty($s['admission_number'])) $facts['Admission no.'] = (string)$s['admission_number'];
    if (!empty($s['dob']))              $facts['Date of birth'] = date('j M Y', strtotime((string)$s['dob']));
    if (!empty($s['gender']))           $facts['Gender']        = (string)$s['gender'];
    if (!empty($s['joining_date']))     $facts['Joined']        = date('j M Y', strtotime((string)$s['joining_date']));
    if (!empty($s['mother_tongue']))    $facts['Mother tongue'] = (string)$s['mother_tongue'];
    if ($facts) { $pdf->sectionTitle('Child details'); $pdf->facts($facts); }

    // ---- Attendance ------------------------------------------------------
    $att = array_filter($d['attendance'], fn($n) => $n > 0);
    if (array_sum($att) > 0) {
        $pdf->sectionTitle('Attendance');
        $rows = [];
        foreach ($att as $status => $n) $rows[ucfirst((string)$status)] = $n . ' day' . ($n === 1 ? '' : 's');
        $present = (int)($att['present'] ?? 0) + (int)($att['late'] ?? 0);
        $marked  = array_sum($att) - (int)($att['holiday'] ?? 0);
        if ($marked > 0) $rows['Attendance rate'] = round($present / $marked * 100) . '%';
        $pdf->facts($rows);
    }

    // ---- Entry baseline --------------------------------------------------
    if ($bl = $d['baseline']) {
        $map = [
            'gross_motor' => 'Gross motor', 'fine_motor' => 'Fine motor',
            'literacy' => 'Literacy', 'numeracy' => 'Numeracy',
            'social_skills' => 'Social skills', 'communication' => 'Communication',
        ];
        $rows = [];
        foreach ($map as $k => $label) {
            if (trim((string)($bl[$k] ?? '')) !== '') $rows[$label] = (string)$bl[$k];
        }
        $notes = trim((string)($bl['overall_notes'] ?? ''));
        if ($rows || $notes !== '') {
            $when = !empty($bl['recorded_at']) ? ' (baseline recorded ' . date('j M Y', strtotime((string)$bl['recorded_at'])) . ')' : '';
            $pdf->sectionTitle('Where ' . $first . ' started' . $when);
            if ($rows) $pdf->facts($rows);
            if ($notes !== '') { $pdf->Ln(1); $pdf->para($notes); }
        }
    }

    // ---- Summary by area --------------------------------------------------
    if ($d['categories'] && $months) {
        $pdf->sectionTitle('Summary by area');

        $head = array_merge(['Area'], array_map('month_year_label', $months), ['Change']);
        $areaW = 34.0; $changeW = 22.0;
        $monthW = max(16.0, ($pdf->contentWidth() - $areaW - $changeW) / count($months));
        $widths = array_merge([$areaW], array_fill(0, count($months), $monthW), [$changeW]);

        $rows = [];
        foreach ($d['categories'] as $cat) {
            $levels = [];
            foreach ($months as $m) $levels[$m] = child_report_level($d, $cat, $m);
            $seen = array_values(array_filter($levels, fn($c) => $c !== null));
            $moved = null;
            if (count($seen) > 1) {
                $moved = (int)($ratings[$seen[count($seen) - 1]]['numeric_value'] ?? 0)
                       <=> (int)($ratings[$seen[0]]['numeric_value'] ?? 0);
            }
            $row = [['t' => $cat]];
            foreach ($months as $m) {
                $c = $levels[$m];
                $row[] = $c === null ? ['t' => ''] : ['chip' => $c];
            }
            $row[] = ['t' => $moved === 1 ? 'Moved up' : ($moved === -1 ? 'Slipped' : ($moved === 0 ? 'Steady' : ''))];
            $rows[] = $row;
        }

        // Overall = modal rating across every area that month.
        $overall = [];
        foreach ($months as $m) {
            $all = [];
            foreach ($d['categories'] as $cat) $all = array_merge($all, child_report_codes_for($d, $cat, $m));
            $overall[$m] = child_report_mode($all, $ratings);
        }
        if (array_filter($overall, fn($c) => $c !== null)) {
            $row = [['t' => 'Overall']];
            foreach ($months as $m) $row[] = $overall[$m] === null ? ['t' => ''] : ['chip' => $overall[$m]];
            $row[] = ['t' => ''];
            $rows[] = $row;
            $pdf->table($head, $rows, $widths, true);
        } else {
            $pdf->table($head, $rows, $widths);
        }
        $pdf->muted('Each area shows the level ' . $first . ' worked at most often that month - see the key at the end.');
    }

    // ---- Trend chart ------------------------------------------------------
    if (count($months) >= 2 && $d['categories']) {
        $pdf->sectionTitle('Progress over time');
        $pdf->chart($d, $months);
    }

    // ---- Skill-by-skill ---------------------------------------------------
    if ($d['indicators']) {
        $pdf->sectionTitle('Skill-by-skill detail');
        $pdf->muted('Every indicator assessed, month by month.');
        $pdf->Ln(1);

        foreach ($d['indicators'] as $cat => $list) {
            $catMonths = [];
            foreach ($months as $m) {
                foreach ($list as $ind) { if (isset($ind['ratings'][$m])) { $catMonths[] = $m; break; } }
            }
            if (!$catMonths) continue;

            $pdf->need(26);
            $pdf->subTitle((string)$cat);

            $skillW = 62.0;
            $mW     = max(14.0, ($pdf->contentWidth() - $skillW) / count($catMonths));
            $head   = array_merge(['Skill'], array_map('month_year_label', $catMonths));
            $widths = array_merge([$skillW], array_fill(0, count($catMonths), $mW));

            $rows = [];
            foreach ($list as $ind) {
                $row = [['t' => $ind['text'] . (!empty($ind['custom']) ? ' *' : '')]];
                foreach ($catMonths as $m) {
                    $c = (string)($ind['ratings'][$m] ?? '');
                    $row[] = $c === '' ? ['t' => ''] : ['chip' => $c];
                }
                $rows[] = $row;
            }
            $pdf->table($head, $rows, $widths);

            $catComments = [];
            foreach ($d['comments'] as $m => $byCat) {
                foreach (($byCat[$cat] ?? []) as $c) if (trim($c) !== '') $catComments[] = month_year_label($m) . ': ' . $c;
            }
            foreach ($catComments as $c) $pdf->muted($c, 9);
            if ($catComments) $pdf->Ln(1);
        }
        // Only explain the marker if a custom indicator actually appeared.
        foreach ($d['indicators'] as $list) {
            foreach ($list as $ind) {
                if (!empty($ind['custom'])) { $pdf->muted('* indicator added specifically for ' . $first . '.'); break 2; }
            }
        }
    }

    // ---- Teacher's remarks -------------------------------------------------
    $overallComments = [];
    foreach ($d['comments'] as $m => $byCat) {
        foreach (($byCat[''] ?? []) as $c) if (trim($c) !== '') $overallComments[] = [$m, $c];
    }
    if ($overallComments) {
        $pdf->sectionTitle("Teacher's remarks");
        foreach ($overallComments as [$m, $c]) {
            $pdf->need(12);
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetTextColor(173, 20, 87);
            $pdf->Cell(0, 5, pdf_txt(month_year_label($m)), 0, 1, 'L');
            $pdf->SetTextColor(43, 43, 43);
            $pdf->para($c);
        }
    }

    // ---- Rating key --------------------------------------------------------
    if ($ratings) {
        $ordered = $ratings;
        uasort($ordered, fn($a, $b) => (int)$b['numeric_value'] <=> (int)$a['numeric_value']);
        $pdf->sectionTitle('How to read this report');
        foreach ($ordered as $code => $cfg) {
            $pdf->need(6);
            $y = $pdf->GetY();
            $pdf->chip(14, $y + 0.6, (string)$code);
            $pdf->SetXY(22.5, $y);
            $pdf->SetFont('Helvetica', 'B', 9.5);
            $pdf->Cell(0, 5.6, pdf_txt((string)$cfg['label']), 0, 1, 'L');
        }
    }

    return (string)$pdf->Output('S');
}

/** A safe download filename for a child's report. */
function child_report_pdf_filename(array $d): string
{
    $s    = $d['student'];
    $name = trim((string)$s['first_name'] . ' ' . (string)$s['last_name']);
    $name = preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?: 'report';
    return trim($name, '-') . '-progress-report-' . date('Y-m-d') . '.pdf';
}

/** Stream the PDF as a download and exit. */
function child_report_pdf_stream(array $d, string $schoolName = ''): void
{
    $bytes = child_report_pdf_bytes($d, $schoolName);
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($bytes));
    header('Content-Disposition: attachment; filename="' . child_report_pdf_filename($d) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $bytes;
    exit;
}
