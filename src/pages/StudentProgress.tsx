import { useState, useEffect } from 'react';
import { ArrowLeft, BookOpen, Printer } from 'lucide-react';
import { Student, EvaluationCard, RatingConfig, SkillIndicator, AssessmentComment, StudentBaseline, GRADE_COLORS } from '../types';
import { useAuth } from '../context/AuthContext';
import StudentPDFExport from '../components/StudentPDFExport';

interface Props {
  student: Student;
  teacherName: string;
  onBack: () => void;
  onOpenEvalCard: (student: Student) => void;
}

type MonthData = Record<string, Record<string, number>>;
type MonthRatings = Record<string, Record<string, string[]>>;

const COLOR_PALETTE = ['#ef4444','#f97316','#3b82f6','#14b8a6','#ec4899','#8b5cf6','#22c55e','#f59e0b','#06b6d4','#6366f1'];
const getCategoryColor = (cat: string, allCats: string[]) => COLOR_PALETTE[allCats.indexOf(cat) % COLOR_PALETTE.length] ?? '#64748b';

function MiniChart({ data, months, categories }: { data: MonthData; months: string[]; categories: string[] }) {
  const allVals = categories.flatMap(cat => months.map(m => data[m]?.[cat] ?? null)).filter(v => v !== null) as number[];
  const minV = allVals.length ? Math.min(...allVals) : 1;
  const maxV = allVals.length ? Math.max(...allVals) : 5;
  const range = maxV - minV || 1;

  const W = 600, H = 220;
  const PAD = { top: 20, right: 30, bottom: 30, left: 10 };
  const chartW = W - PAD.left - PAD.right;
  const chartH = H - PAD.top - PAD.bottom;

  const xPos = (i: number) => PAD.left + (months.length <= 1 ? chartW / 2 : (i / (months.length - 1)) * chartW);
  const yPos = (v: number) => PAD.top + chartH - ((v - minV) / range) * chartH;

  return (
    <div className="w-full overflow-x-auto">
      <svg viewBox={`0 0 ${W} ${H}`} className="w-full min-w-[300px]" style={{ minHeight: 160 }}>
        {[1,2,3,4,5].map(g => (
          <line key={g} x1={PAD.left} y1={yPos(g)} x2={W - PAD.right} y2={yPos(g)} stroke="#f1f5f9" strokeWidth="1" />
        ))}
        {months.map((m, i) => (
          <text key={m} x={xPos(i)} y={H - 6} textAnchor="middle" fontSize="9" fill="#94a3b8">{m}</text>
        ))}
        {categories.map(cat => {
          const color = getCategoryColor(cat, categories);
          const points = months.map((m, i) => {
            const v = data[m]?.[cat];
            if (!v) return null;
            return { x: xPos(i), y: yPos(v), v };
          });
          const defined = points.filter(Boolean) as { x: number; y: number; v: number }[];
          if (!defined.length) return null;
          const pathD = defined.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x} ${p.y}`).join(' ');
          return (
            <g key={cat}>
              {defined.length > 1 && (
                <path d={pathD} fill="none" stroke={color} strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />
              )}
              {defined.map((p, i) => (
                <circle key={i} cx={p.x} cy={p.y} r="4" fill={color} stroke="white" strokeWidth="1.5" />
              ))}
            </g>
          );
        })}
      </svg>
    </div>
  );
}

const sortMonths = (months: string[]) =>
  [...months].sort((a, b) => {
    const mo: Record<string, number> = { Jun: 0, Jul: 1, Aug: 2, Sep: 3, Oct: 4, Nov: 5, Dec: 6, Jan: 7, Feb: 8, Mar: 9 };
    const [am, ay] = a.split('-'), [bm, by] = b.split('-');
    const ayn = parseInt(ay), byn = parseInt(by);
    if (ayn !== byn) return ayn - byn;
    return (mo[am] ?? 0) - (mo[bm] ?? 0);
  });

export default function StudentProgress({ student, teacherName, onBack, onOpenEvalCard }: Props) {
  const { db } = useAuth();
  const [evalCards, setEvalCards] = useState<EvaluationCard[]>([]);
  const [indicators, setIndicators] = useState<SkillIndicator[]>([]);
  const [ratingConfig, setRatingConfig] = useState<RatingConfig[]>([]);
  const [comments, setComments] = useState<AssessmentComment[]>([]);
  const [baseline, setBaseline] = useState<StudentBaseline | null>(null);
  const [loading, setLoading] = useState(true);
  const [showPDF, setShowPDF] = useState(false);

  useEffect(() => {
    Promise.all([
      db
        .from('evaluation_cards')
        .select('*')
        .eq('student_id', student.id),
      db
        .from('skill_indicators')
        .select('*')
        .eq('grade', student.grade),
      db
        .from('rating_config')
        .select('*')
        .eq('is_active', true)
        .order('display_order'),
      db
        .from('assessment_comments')
        .select('*')
        .eq('student_id', student.id)
        .order('month_year'),
      db
        .from('student_baselines')
        .select('*')
        .eq('student_id', student.id)
        .maybeSingle(),
    ]).then(([{ data: evals }, { data: inds }, { data: ratings }, { data: cmts }, { data: bline }]) => {
      setEvalCards(evals || []);
      setIndicators(inds || []);
      setRatingConfig(ratings || []);
      setComments(cmts || []);
      setBaseline(bline ?? null);
      setLoading(false);
    });
  }, [db, student.id, student.grade]);

  const ratingNumericMap: Record<string, number> = {};
  for (const rc of ratingConfig) ratingNumericMap[rc.code] = rc.numeric_value;

  const months = sortMonths([...new Set(evalCards.map(e => e.month_year))]);

  const allCategories = [...new Set(indicators.map(i => i.category))].sort();

  const monthData: MonthData = {};
  const monthRatings: MonthRatings = {};

  for (const month of months) {
    monthData[month] = {};
    monthRatings[month] = {};
    for (const cat of allCategories) {
      const catIndicatorIds = indicators.filter(i => i.category === cat).map(i => i.id);
      const catEvals = evalCards.filter(e => e.month_year === month && catIndicatorIds.includes(e.indicator_id));
      if (!catEvals.length) continue;
      const numericVals = catEvals.map(e => ratingNumericMap[e.rating] ?? 3);
      monthData[month][cat] = numericVals.reduce((a, b) => a + b, 0) / numericVals.length;
      monthRatings[month][cat] = catEvals.map(e => e.rating);
    }
  }

  const categoriesPresent = allCategories.filter(cat => months.some(m => monthData[m]?.[cat] !== undefined));

  const gradeColor = GRADE_COLORS[student.grade];

  const getRatingDistribution = (month: string, cat: string) => {
    const catRatings = monthRatings[month]?.[cat] ?? [];
    const dist: Record<string, number> = {};
    for (const r of catRatings) dist[r] = (dist[r] ?? 0) + 1;
    return dist;
  };

  const ratingCell = (month: string, cat: string) => {
    const dist = getRatingDistribution(month, cat);
    if (!Object.keys(dist).length) return <td className="px-3 py-2.5 text-center text-gray-300 text-sm">—</td>;
    const topRating = Object.entries(dist).sort((a, b) => b[1] - a[1])[0];
    const topRc = topRating ? ratingConfig.find(r => r.code === topRating[0]) : null;
    if (!topRc) return <td className="px-3 py-2.5 text-center text-gray-300 text-sm">—</td>;
    return (
      <td className="px-3 py-2.5 text-center">
        <span
          className="inline-block px-2.5 py-1 rounded-lg text-xs font-bold text-white"
          style={{ backgroundColor: topRc.color }}
        >
          {topRc.code}
        </span>
      </td>
    );
  };

  return (
    <>
    <div className="min-h-screen bg-gray-50">
      <div className="bg-white border-b border-gray-200 sticky top-0 z-10">
        <div className="max-w-4xl mx-auto px-4 py-3 flex items-center gap-2">
          <button
            onClick={onBack}
            className="w-9 h-9 flex items-center justify-center rounded-xl hover:bg-gray-100 transition-colors"
          >
            <ArrowLeft className="w-5 h-5 text-gray-600" />
          </button>
          <div className="flex-1 min-w-0">
            <h1 className="font-bold text-gray-900 truncate">{student.first_name} {student.last_name}</h1>
            <p className="text-xs text-gray-500">{student.grade} · {teacherName}</p>
          </div>
          <span className={`px-3 py-1 rounded-full text-xs font-bold ${gradeColor.light} ${gradeColor.text} hidden sm:inline`}>
            {student.grade}
          </span>
          {!loading && evalCards.length > 0 && (
            <button
              onClick={() => setShowPDF(true)}
              className="flex items-center gap-1.5 px-3 h-9 rounded-xl bg-teal-600 hover:bg-teal-700 text-white text-sm font-semibold transition-colors shadow-sm"
            >
              <Printer className="w-4 h-4" />
              <span className="hidden sm:inline">Print</span>
            </button>
          )}
        </div>
      </div>

      <div className="max-w-4xl mx-auto px-4 py-5 space-y-5">
        {loading ? (
          <div className="flex justify-center py-16">
            <div className="w-8 h-8 border-4 border-teal-500 border-t-transparent rounded-full animate-spin" />
          </div>
        ) : evalCards.length === 0 ? (
          <div className="bg-white rounded-2xl p-12 text-center shadow-sm border border-gray-100">
            <p className="text-4xl mb-3">📋</p>
            <p className="text-gray-600 font-semibold">No assessments yet for this student</p>
            <p className="text-gray-400 text-sm mt-1">Assessments will appear here after they are submitted</p>
          </div>
        ) : (
          <>
            {baseline && (
              <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div className="px-5 py-4 border-b border-orange-100 bg-orange-50 flex items-center gap-3">
                  <BookOpen className="w-5 h-5 text-orange-500 flex-shrink-0" />
                  <div>
                    <h2 className="font-bold text-gray-800">Entry Baseline</h2>
                    <p className="text-xs text-gray-500 mt-0.5">
                      How {student.first_name} was before joining Little Graduates
                      {baseline.recorded_at && ` · Recorded ${new Date(baseline.recorded_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}`}
                      {baseline.recorded_by && ` by ${baseline.recorded_by}`}
                    </p>
                  </div>
                </div>
                <div className="p-5">
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    {[
                      { key: 'gross_motor', label: 'Gross Motor Skills' },
                      { key: 'fine_motor', label: 'Fine Motor Skills' },
                      { key: 'literacy', label: 'Literacy & Language' },
                      { key: 'numeracy', label: 'Numeracy & Math Awareness' },
                      { key: 'social_skills', label: 'Social & Emotional Skills' },
                      { key: 'communication', label: 'Communication & Speech' },
                    ].map(({ key, label }) => {
                      const value = baseline[key as keyof StudentBaseline] as string;
                      if (!value?.trim()) return null;
                      return (
                        <div key={key} className="bg-orange-50 rounded-xl p-3 border border-orange-100">
                          <p className="text-xs font-bold text-orange-700 mb-1">{label}</p>
                          <p className="text-sm text-gray-700 leading-relaxed">{value}</p>
                        </div>
                      );
                    })}
                  </div>
                  {baseline.overall_notes?.trim() && (
                    <div className="mt-3 bg-amber-50 rounded-xl p-3 border border-amber-100">
                      <p className="text-xs font-bold text-amber-700 mb-1">Overall Notes</p>
                      <p className="text-sm text-gray-700 leading-relaxed">{baseline.overall_notes}</p>
                    </div>
                  )}
                </div>
              </div>
            )}

            {ratingConfig.length > 0 && (
              <div className="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                <p className="text-xs font-semibold text-gray-500 mb-3 uppercase tracking-wide">Rating Scale</p>
                <div className="flex flex-wrap gap-3">
                  {ratingConfig.map(rc => (
                    <div key={rc.id} className="flex items-center gap-2">
                      <span
                        className="w-8 h-8 rounded-lg text-sm font-extrabold flex items-center justify-center text-white"
                        style={{ backgroundColor: rc.color }}
                      >
                        {rc.code}
                      </span>
                      <p className="text-xs font-semibold text-gray-700">{rc.label}</p>
                    </div>
                  ))}
                </div>
              </div>
            )}

            <div className="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
              <h2 className="font-bold text-gray-800 mb-1">Progress Chart</h2>
              <p className="text-xs text-gray-500 mb-4">Monthly ratings by skill category</p>
              <MiniChart data={monthData} months={months} categories={categoriesPresent} />
              <div className="flex flex-wrap gap-3 mt-3">
                {categoriesPresent.map(cat => (
                  <div key={cat} className="flex items-center gap-1.5">
                    <span className="w-3 h-3 rounded-full" style={{ background: getCategoryColor(cat, categoriesPresent) }} />
                    <span className="text-xs text-gray-600">{cat}</span>
                  </div>
                ))}
              </div>
            </div>

            <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
              <div className="px-5 py-4 border-b border-gray-100">
                <h2 className="font-bold text-gray-800">Assessment Summary</h2>
                <p className="text-xs text-gray-500 mt-0.5">Most frequent rating per category each month</p>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="bg-gray-50">
                      <th className="px-3 py-3 text-left font-semibold text-gray-600 text-xs sticky left-0 bg-gray-50">Month</th>
                      {categoriesPresent.map(cat => (
                        <th key={cat} className="px-3 py-3 text-center font-semibold text-gray-600 text-xs whitespace-nowrap">
                          {cat}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-50">
                    {months.map(month => (
                      <tr key={month} className="hover:bg-gray-50 transition-colors">
                        <td className="px-3 py-2.5 font-semibold text-gray-800 text-sm sticky left-0 bg-white">{month}</td>
                        {categoriesPresent.map(cat => ratingCell(month, cat))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>

            {comments.length > 0 && (
              <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div className="px-5 py-4 border-b border-gray-100">
                  <h2 className="font-bold text-gray-800">Teacher Notes</h2>
                </div>
                <div className="p-5 space-y-4">
                  {months.map(month => {
                    const monthComments = comments.filter(c => c.month_year === month);
                    if (!monthComments.length) return null;
                    return (
                      <div key={month}>
                        <p className="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">{month}</p>
                        <div className="space-y-2">
                          {monthComments.map(c => (
                            <div key={c.id} className="flex gap-2 text-sm">
                              {c.category ? (
                                <span className="text-teal-700 font-semibold flex-shrink-0">{c.category}:</span>
                              ) : (
                                <span className="text-gray-500 font-semibold flex-shrink-0">Overall:</span>
                              )}
                              <span className="text-gray-700">{c.comment}</span>
                            </div>
                          ))}
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}
          </>
        )}
      </div>

    </div>

    {showPDF && (
      <StudentPDFExport
        student={student}
        teacherName={teacherName}
        evalCards={evalCards}
        indicators={indicators}
        ratingConfig={ratingConfig}
        comments={comments}
        baseline={baseline}
        onClose={() => setShowPDF(false)}
      />
    )}
    </>
  );
}
