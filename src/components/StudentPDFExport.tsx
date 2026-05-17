import { useEffect, useRef, useState } from 'react';
import { X, Download, Loader2 } from 'lucide-react';
import html2canvas from 'html2canvas';
import jsPDF from 'jspdf';
import { Student, EvaluationCard, RatingConfig, SkillIndicator, AssessmentComment, StudentBaseline } from '../types';

const COLOR_PALETTE = ['#ef4444','#f97316','#3b82f6','#14b8a6','#ec4899','#8b5cf6','#22c55e','#f59e0b','#06b6d4','#6366f1'];
const getCategoryColor = (cat: string, allCats: string[]) => COLOR_PALETTE[allCats.indexOf(cat) % COLOR_PALETTE.length] ?? '#64748b';

interface Props {
  student: Student;
  teacherName: string;
  evalCards: EvaluationCard[];
  indicators: SkillIndicator[];
  ratingConfig: RatingConfig[];
  comments: AssessmentComment[];
  baseline: StudentBaseline | null;
  onClose: () => void;
}

const sortMonths = (months: string[]) =>
  [...months].sort((a, b) => {
    const mo: Record<string, number> = { Jun: 0, Jul: 1, Aug: 2, Sep: 3, Oct: 4, Nov: 5, Dec: 6, Jan: 7, Feb: 8, Mar: 9 };
    const [am, ay] = a.split('-'), [bm, by] = b.split('-');
    const ayn = parseInt(ay), byn = parseInt(by);
    if (ayn !== byn) return ayn - byn;
    return (mo[am] ?? 0) - (mo[bm] ?? 0);
  });

const BASELINE_FIELDS: { key: keyof StudentBaseline; label: string }[] = [
  { key: 'gross_motor', label: 'Gross Motor Skills' },
  { key: 'fine_motor', label: 'Fine Motor Skills' },
  { key: 'literacy', label: 'Literacy & Language' },
  { key: 'numeracy', label: 'Numeracy & Math Awareness' },
  { key: 'social_skills', label: 'Social & Emotional Skills' },
  { key: 'communication', label: 'Communication & Speech' },
];

const cell: React.CSSProperties = { padding: '7px 10px', textAlign: 'center', borderBottom: '1px solid #f1f5f9', fontSize: 10, color: '#374151' };
const hCell: React.CSSProperties = { padding: '8px 10px', textAlign: 'center', fontWeight: 700, color: '#475569', borderBottom: '2px solid #e2e8f0', fontSize: 10, background: '#f8fafc' };

function useBase64Image(src: string) {
  const [dataUrl, setDataUrl] = useState<string>('');
  useEffect(() => {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => {
      const canvas = document.createElement('canvas');
      canvas.width = img.naturalWidth;
      canvas.height = img.naturalHeight;
      canvas.getContext('2d')!.drawImage(img, 0, 0);
      setDataUrl(canvas.toDataURL('image/png'));
    };
    img.src = src;
  }, [src]);
  return dataUrl;
}

export default function StudentPDFExport({ student, teacherName, evalCards, indicators, ratingConfig, comments, baseline, onClose }: Props) {
  const contentRef = useRef<HTMLDivElement>(null);
  const [downloading, setDownloading] = useState(false);
  const logoDataUrl = useBase64Image('/cropped-Finalized-Logo-2-1-removebg-preview.png');

  const downloadPDF = async () => {
    if (!contentRef.current) return;
    setDownloading(true);
    try {
      const el = contentRef.current;
      const canvas = await html2canvas(el, {
        scale: 2,
        useCORS: true,
        allowTaint: true,
        backgroundColor: '#ffffff',
        logging: false,
      });
      const imgData = canvas.toDataURL('image/png');
      const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
      const pageW = pdf.internal.pageSize.getWidth();
      const pageH = pdf.internal.pageSize.getHeight();
      const imgW = pageW;
      const imgH = (canvas.height * imgW) / canvas.width;
      let yOffset = 0;
      let remaining = imgH;
      let page = 0;
      while (remaining > 0) {
        if (page > 0) pdf.addPage();
        pdf.addImage(imgData, 'PNG', 0, -yOffset, imgW, imgH);
        yOffset += pageH;
        remaining -= pageH;
        page++;
      }
      pdf.save(`${student.first_name}_${student.last_name}_Progress_Report.pdf`);
    } finally {
      setDownloading(false);
    }
  };

  const ratingColorMap: Record<string, string> = {};
  const ratingLabelMap: Record<string, string> = {};
  const ratingNumericMap: Record<string, number> = {};
  for (const rc of ratingConfig) {
    ratingColorMap[rc.code] = rc.color;
    ratingLabelMap[rc.code] = rc.label;
    ratingNumericMap[rc.code] = rc.numeric_value;
  }

  const months = sortMonths([...new Set(evalCards.map(e => e.month_year))]);
  const allCategories = [...new Set(indicators.map(i => i.category))].sort();

  const monthData: Record<string, Record<string, number>> = {};
  for (const month of months) {
    monthData[month] = {};
    for (const cat of allCategories) {
      const catIndicatorIds = indicators.filter(i => i.category === cat).map(i => i.id);
      const catEvals = evalCards.filter(e => e.month_year === month && catIndicatorIds.includes(e.indicator_id));
      if (!catEvals.length) continue;
      const numericVals = catEvals.map(e => ratingNumericMap[e.rating] ?? 3);
      monthData[month][cat] = numericVals.reduce((a, b) => a + b, 0) / numericVals.length;
    }
  }

  const categoriesPresent = allCategories.filter(cat => months.some(m => monthData[m]?.[cat] !== undefined));
  const getMonthComments = (month: string) => comments.filter(c => c.month_year === month);

  const getTopRating = (month: string, cat: string) => {
    const catIndicatorIds = indicators.filter(i => i.category === cat).map(i => i.id);
    const catEvals = evalCards.filter(e => e.month_year === month && catIndicatorIds.includes(e.indicator_id));
    if (!catEvals.length) return null;
    const dist: Record<string, number> = {};
    for (const e of catEvals) dist[e.rating] = (dist[e.rating] ?? 0) + 1;
    const topCode = Object.entries(dist).sort((a, b) => b[1] - a[1])[0]?.[0];
    return topCode ? { code: topCode, color: ratingColorMap[topCode] } : null;
  };

  const W = 500, H = 180;
  const PAD = { top: 20, right: 20, bottom: 30, left: 35 };
  const chartW = W - PAD.left - PAD.right;
  const chartH = H - PAD.top - PAD.bottom;
  const allVals = categoriesPresent.flatMap(cat => months.map(m => monthData[m]?.[cat] ?? null)).filter(v => v !== null) as number[];
  const minV = allVals.length ? Math.min(...allVals) : 1;
  const maxV = allVals.length ? Math.max(...allVals) : 5;
  const range = maxV - minV || 1;
  const xPos = (i: number) => PAD.left + (months.length <= 1 ? chartW / 2 : (i / (months.length - 1)) * chartW);
  const yPos = (v: number) => PAD.top + chartH - ((v - minV) / range) * chartH;

  useEffect(() => {
    document.body.style.overflow = 'hidden';
    return () => { document.body.style.overflow = ''; };
  }, []);

  const today = new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
  const categoriesWithIndicators = allCategories.filter(cat => indicators.some(i => i.category === cat));

  const sectionHeading: React.CSSProperties = {
    fontSize: 12,
    fontWeight: 700,
    color: '#0f766e',
    marginBottom: 10,
    textTransform: 'uppercase',
    letterSpacing: 1,
    textAlign: 'center',
    borderBottom: '2px solid #0d9488',
    paddingBottom: 6,
  };

  return (
    <div className="fixed inset-0 z-50 bg-black/60 flex items-start justify-center overflow-y-auto py-6 px-4">
      <div className="w-full max-w-4xl">
        <div className="flex items-center justify-between mb-3 print:hidden">
          <button
            onClick={downloadPDF}
            disabled={downloading}
            className="flex items-center gap-2 px-4 h-9 rounded-xl bg-teal-700 hover:bg-teal-600 disabled:opacity-60 disabled:cursor-not-allowed text-white text-sm font-semibold transition-colors"
          >
            {downloading ? <Loader2 className="w-4 h-4 animate-spin" /> : <Download className="w-4 h-4" />}
            {downloading ? 'Generating...' : 'Download PDF'}
          </button>
          <button
            onClick={onClose}
            className="w-9 h-9 flex items-center justify-center rounded-xl bg-white/10 hover:bg-white/20 text-white transition-colors"
          >
            <X className="w-4 h-4" />
          </button>
        </div>

        <div
          ref={contentRef}
          className="bg-white rounded-2xl overflow-hidden shadow-2xl"
          id="pdf-export-content"
          style={{ fontFamily: 'Arial, sans-serif' }}
        >
          {/* Header */}
          <div style={{ background: 'linear-gradient(135deg, #0d9488 0%, #0f766e 100%)', padding: '20px 32px', WebkitPrintColorAdjust: 'exact', printColorAdjust: 'exact' } as React.CSSProperties}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              {/* Logo */}
              <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                {logoDataUrl && (
                  <img
                    src={logoDataUrl}
                    alt="Little Graduates Logo"
                    style={{ height: 64, width: 'auto', objectFit: 'contain', filter: 'brightness(0) invert(1)' }}
                  />
                )}
                <div>
                  <p style={{ color: 'rgba(255,255,255,0.8)', fontSize: 10, margin: 0, letterSpacing: 1, textTransform: 'uppercase' }}>
                    Little Graduates
                  </p>
                  <p style={{ color: 'rgba(255,255,255,0.65)', fontSize: 9, margin: 0 }}>Early Learning Centre</p>
                </div>
              </div>

              {/* Student Info — centered */}
              <div style={{ textAlign: 'center', flex: 1, padding: '0 24px' }}>
                <h1 style={{ color: 'white', fontSize: 22, fontWeight: 800, margin: '0 0 6px', lineHeight: 1.2 }}>
                  {student.first_name} {student.last_name}
                </h1>
                <div style={{ display: 'flex', gap: 10, justifyContent: 'center', alignItems: 'center' }}>
                  <span style={{ background: 'rgba(255,255,255,0.2)', color: 'white', padding: '2px 12px', borderRadius: 20, fontSize: 11, fontWeight: 600 }}>
                    {student.grade}
                  </span>
                  <span style={{ color: 'rgba(255,255,255,0.85)', fontSize: 11 }}>
                    Teacher: {teacherName}
                  </span>
                </div>
              </div>

              {/* Date / Period */}
              <div style={{ textAlign: 'right', minWidth: 100 }}>
                <p style={{ color: 'rgba(255,255,255,0.7)', fontSize: 10, margin: '0 0 2px', textTransform: 'uppercase', letterSpacing: 0.5 }}>Progress Report</p>
                <p style={{ color: 'white', fontSize: 11, fontWeight: 600, margin: 0 }}>{today}</p>
                {months.length > 0 && (
                  <p style={{ color: 'rgba(255,255,255,0.7)', fontSize: 10, marginTop: 4 }}>
                    {months[0]}{months.length > 1 ? ` – ${months[months.length - 1]}` : ''}
                  </p>
                )}
              </div>
            </div>
          </div>

          <div style={{ padding: '24px 32px' }}>
            {/* Baseline */}
            {baseline && (() => {
              const filledFields = BASELINE_FIELDS.filter(f => (baseline[f.key] as string)?.trim());
              if (!filledFields.length && !baseline.overall_notes?.trim()) return null;
              return (
                <div style={{ marginBottom: 24, border: '1px solid #fed7aa', borderRadius: 12, overflow: 'hidden' }}>
                  <div style={{ background: '#fff7ed', padding: '10px 16px', borderBottom: '1px solid #fed7aa', textAlign: 'center' }}>
                    <h2 style={{ fontSize: 12, fontWeight: 700, color: '#9a3412', margin: 0, textTransform: 'uppercase', letterSpacing: 0.5 }}>
                      Entry Baseline — Before Joining Little Graduates
                    </h2>
                    <p style={{ fontSize: 10, color: '#6b7280', margin: '4px 0 0' }}>
                      {baseline.recorded_at && `Recorded: ${new Date(baseline.recorded_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}`}
                      {baseline.recorded_by && `  ·  By: ${baseline.recorded_by}`}
                    </p>
                  </div>
                  <div style={{ padding: '12px 16px', display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
                    {filledFields.map(({ key, label }) => (
                      <div key={key as string} style={{ background: '#fff7ed', borderRadius: 8, padding: '8px 10px', textAlign: 'center' }}>
                        <p style={{ fontSize: 10, fontWeight: 700, color: '#c2410c', marginBottom: 3 }}>{label}</p>
                        <p style={{ fontSize: 11, color: '#374151', margin: 0, lineHeight: 1.5 }}>{baseline[key] as string}</p>
                      </div>
                    ))}
                  </div>
                  {baseline.overall_notes?.trim() && (
                    <div style={{ padding: '0 16px 12px' }}>
                      <div style={{ background: '#fffbeb', borderRadius: 8, padding: '8px 10px', textAlign: 'center' }}>
                        <p style={{ fontSize: 10, fontWeight: 700, color: '#92400e', marginBottom: 3 }}>Overall Notes</p>
                        <p style={{ fontSize: 11, color: '#374151', margin: 0, lineHeight: 1.5 }}>{baseline.overall_notes}</p>
                      </div>
                    </div>
                  )}
                </div>
              );
            })()}

            {months.length === 0 ? (
              <p style={{ textAlign: 'center', color: '#94a3b8', padding: '32px 0' }}>No assessment data available</p>
            ) : (
              <>
                {/* Progress Chart */}
                {months.length >= 2 && (
                  <div style={{ marginBottom: 24 }}>
                    <h2 style={sectionHeading}>Progress Chart</h2>
                    <div style={{ border: '1px solid #e2e8f0', borderRadius: 12, padding: '12px 8px' }}>
                      <svg viewBox={`0 0 ${W} ${H}`} style={{ width: '100%', display: 'block' }}>
                        {[1,2,3,4,5].map(g => (
                          <line key={g} x1={PAD.left} y1={yPos(g)} x2={W - PAD.right} y2={yPos(g)} stroke="#f1f5f9" strokeWidth="1" />
                        ))}
                        {months.map((m, i) => (
                          <text key={m} x={xPos(i)} y={H - 6} textAnchor="middle" fontSize="8" fill="#94a3b8">{m}</text>
                        ))}
                        {categoriesPresent.map(cat => {
                          const points = months.map((m, i) => {
                            const v = monthData[m]?.[cat];
                            if (!v) return null;
                            return { x: xPos(i), y: yPos(v) };
                          });
                          const defined = points.filter(Boolean) as { x: number; y: number }[];
                          if (!defined.length) return null;
                          const pathD = defined.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x} ${p.y}`).join(' ');
                          return (
                            <g key={cat}>
                              {defined.length > 1 && (
                                <path d={pathD} fill="none" stroke={getCategoryColor(cat, allCategories)} strokeWidth="1.5" strokeLinejoin="round" strokeLinecap="round" />
                              )}
                              {defined.map((p, i) => (
                                <circle key={i} cx={p.x} cy={p.y} r="3" fill={getCategoryColor(cat, allCategories)} stroke="white" strokeWidth="1" />
                              ))}
                            </g>
                          );
                        })}
                      </svg>
                      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px 14px', marginTop: 6, justifyContent: 'center' }}>
                        {categoriesPresent.map(cat => (
                          <div key={cat} style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
                            <span style={{ width: 10, height: 10, borderRadius: '50%', background: getCategoryColor(cat, allCategories), display: 'inline-block', flexShrink: 0 }} />
                            <span style={{ fontSize: 10, color: '#6b7280' }}>{cat}</span>
                          </div>
                        ))}
                      </div>
                    </div>
                  </div>
                )}

                {/* Assessment Summary */}
                <div style={{ marginBottom: 24 }}>
                  <h2 style={sectionHeading}>Assessment Summary</h2>
                  <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 11 }}>
                    <thead>
                      <tr>
                        <th style={hCell}>Month</th>
                        {categoriesPresent.map(cat => (
                          <th key={cat} style={{ ...hCell, whiteSpace: 'nowrap' }}>{cat}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {months.map((month, mi) => (
                        <tr key={month} style={{ background: mi % 2 === 0 ? '#ffffff' : '#f8fafc' }}>
                          <td style={{ ...cell, fontWeight: 700, color: '#1e293b' }}>{month}</td>
                          {categoriesPresent.map(cat => {
                            const top = getTopRating(month, cat);
                            if (!top) return <td key={cat} style={{ ...cell, color: '#cbd5e1' }}>—</td>;
                            return (
                              <td key={cat} style={cell}>
                                <span style={{ background: top.color, color: 'white', padding: '2px 8px', borderRadius: 5, fontWeight: 700, fontSize: 10, WebkitPrintColorAdjust: 'exact', printColorAdjust: 'exact' } as React.CSSProperties}>
                                  {top.code}
                                </span>
                              </td>
                            );
                          })}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                {/* Rating Scale */}
                {ratingConfig.length > 0 && (
                  <div style={{ marginBottom: 24 }}>
                    <h2 style={sectionHeading}>Rating Scale</h2>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12, justifyContent: 'center' }}>
                      {ratingConfig.map(rc => (
                        <div key={rc.id} style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                          <span style={{ background: rc.color, color: 'white', width: 26, height: 26, borderRadius: 6, fontWeight: 800, fontSize: 11, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0, WebkitPrintColorAdjust: 'exact', printColorAdjust: 'exact' } as React.CSSProperties}>
                            {rc.code}
                          </span>
                          <p style={{ fontSize: 11, fontWeight: 600, color: '#374151', margin: 0 }}>{rc.label}</p>
                        </div>
                      ))}
                    </div>
                  </div>
                )}

                {/* Skill Assessment */}
                <div style={{ marginBottom: 24 }}>
                  <h2 style={sectionHeading}>Skill Assessment by Category</h2>
                  {categoriesWithIndicators.map(cat => {
                    const catIndicators = indicators
                      .filter(i => i.category === cat)
                      .sort((a, b) => a.display_order - b.display_order);
                    const catHasData = months.some(m =>
                      catIndicators.some(ind => evalCards.some(e => e.month_year === m && e.indicator_id === ind.id))
                    );
                    if (!catHasData) return null;
                    const catColor = getCategoryColor(cat, allCategories) ?? '#64748b';
                    const catComments = comments.filter(c => c.category === cat);
                    return (
                      <div key={cat} style={{ marginBottom: 20, border: '1px solid #e2e8f0', borderRadius: 10, overflow: 'hidden' }}>
                        <div style={{ background: catColor, padding: '8px 14px', textAlign: 'center', WebkitPrintColorAdjust: 'exact', printColorAdjust: 'exact' } as React.CSSProperties}>
                          <span style={{ color: 'white', fontWeight: 700, fontSize: 12, letterSpacing: 0.5 }}>{cat}</span>
                        </div>
                        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 10 }}>
                          <thead>
                            <tr style={{ background: '#f8fafc' }}>
                              <th style={{ padding: '6px 12px', textAlign: 'center', fontWeight: 700, color: '#475569', borderBottom: '1px solid #e2e8f0', fontSize: 9, width: '45%' }}>
                                Indicator
                              </th>
                              {months.map(m => (
                                <th key={m} style={{ padding: '6px 4px', textAlign: 'center', fontWeight: 700, color: '#475569', borderBottom: '1px solid #e2e8f0', fontSize: 9, whiteSpace: 'nowrap' }}>
                                  {m}
                                </th>
                              ))}
                            </tr>
                          </thead>
                          <tbody>
                            {catIndicators.map((indicator, idx) => {
                              const rowEvals = months.map(m =>
                                evalCards.find(e => e.month_year === m && e.indicator_id === indicator.id) ?? null
                              );
                              const hasAny = rowEvals.some(e => e !== null);
                              if (!hasAny) return null;
                              return (
                                <tr key={indicator.id} style={{ background: idx % 2 === 0 ? '#ffffff' : '#f8fafc' }}>
                                  <td style={{ padding: '7px 12px', color: '#374151', borderBottom: '1px solid #f1f5f9', fontSize: 10, lineHeight: 1.4, textAlign: 'center' }}>
                                    {indicator.indicator_text}
                                  </td>
                                  {rowEvals.map((ev, mi) => {
                                    if (!ev) return (
                                      <td key={mi} style={{ padding: '7px 4px', textAlign: 'center', color: '#cbd5e1', borderBottom: '1px solid #f1f5f9', fontSize: 9 }}>—</td>
                                    );
                                    const color = ratingColorMap[ev.rating] ?? '#94a3b8';
                                    const label = ratingLabelMap[ev.rating] ?? ev.rating;
                                    return (
                                      <td key={mi} style={{ padding: '7px 4px', textAlign: 'center', borderBottom: '1px solid #f1f5f9' }}>
                                        <span
                                          style={{
                                            background: color,
                                            color: 'white',
                                            padding: '2px 7px',
                                            borderRadius: 5,
                                            fontWeight: 700,
                                            fontSize: 9,
                                            display: 'inline-block',
                                            minWidth: 22,
                                            WebkitPrintColorAdjust: 'exact',
                                            printColorAdjust: 'exact',
                                          } as React.CSSProperties}
                                          title={label}
                                        >
                                          {ev.rating}
                                        </span>
                                      </td>
                                    );
                                  })}
                                </tr>
                              );
                            })}
                          </tbody>
                        </table>
                        {catComments.length > 0 && (
                          <div style={{ padding: '8px 12px', background: '#fafafa', borderTop: '1px dashed #e2e8f0' }}>
                            {catComments.map(c => (
                              <div key={c.id} style={{ fontSize: 10, color: '#475569', marginBottom: 3, textAlign: 'center' }}>
                                <span style={{ fontWeight: 600, color: catColor, marginRight: 4 }}>
                                  {c.month_year}:
                                </span>
                                {c.comment}
                              </div>
                            ))}
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>

                {/* Teacher Notes */}
                {months.some(m => getMonthComments(m).some(c => !c.category)) && (
                  <div style={{ marginBottom: 24 }}>
                    <h2 style={sectionHeading}>Teacher Notes</h2>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                      {months.map(month => {
                        const overallComments = getMonthComments(month).filter(c => !c.category);
                        if (!overallComments.length) return null;
                        return (
                          <div key={month} style={{ border: '1px solid #e2e8f0', borderRadius: 8, overflow: 'hidden' }}>
                            <div style={{ background: '#f8fafc', padding: '5px 12px', borderBottom: '1px solid #e2e8f0', textAlign: 'center' }}>
                              <span style={{ fontSize: 11, fontWeight: 700, color: '#475569' }}>{month}</span>
                            </div>
                            <div style={{ padding: '8px 12px', display: 'flex', flexDirection: 'column', gap: 5 }}>
                              {overallComments.map(c => (
                                <div key={c.id} style={{ fontSize: 11, color: '#374151', textAlign: 'center' }}>
                                  <span style={{ fontSize: 10, fontWeight: 700, color: '#64748b', marginRight: 6 }}>Overall:</span>
                                  {c.comment}
                                </div>
                              ))}
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                )}

                {/* Footer */}
                <div style={{ borderTop: '1px solid #e2e8f0', paddingTop: 12, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <p style={{ fontSize: 9, color: '#94a3b8', margin: 0 }}>Little Graduates Early Learning Centre · Progress Report</p>
                  <p style={{ fontSize: 9, color: '#94a3b8', margin: 0 }}>Generated {today}</p>
                </div>
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
