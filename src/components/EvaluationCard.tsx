import { useState, useEffect } from 'react';
import { X, Printer, Save, Loader2, Calendar } from 'lucide-react';
import { Student, SkillIndicator, RatingConfig, CATEGORIES } from '../types';
import { useAuth } from '../context/AuthContext';

interface Props {
  student: Student;
  teacherName: string;
  onClose: () => void;
  onSuccess: (msg: string) => void;
  onError: (msg: string) => void;
}

type Ratings = Record<string, string>;

const today = new Date().toISOString().slice(0, 10);

export default function EvaluationCard({ student, teacherName, onClose, onSuccess, onError }: Props) {
  const { teacher, db } = useAuth();
  const [indicators, setIndicators] = useState<SkillIndicator[]>([]);
  const [ratingConfig, setRatingConfig] = useState<RatingConfig[]>([]);
  const [ratings, setRatings] = useState<Ratings>({});
  const [date, setDate] = useState(today);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const getMonthYear = (d: string) => {
    const parts = d.slice(0, 7).split('-');
    const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return `${monthNames[parseInt(parts[1]) - 1]}-${parts[0].slice(2)}`;
  };

  useEffect(() => {
    const monthYear = getMonthYear(date);
    Promise.all([
      db
        .from('skill_indicators')
        .select('*')
        .eq('grade', student.grade)
        .order('category')
        .order('display_order'),
      db
        .from('rating_config')
        .select('*')
        .eq('is_active', true)
        .order('display_order'),
      db
        .from('evaluation_cards')
        .select('indicator_id, rating')
        .eq('student_id', student.id)
        .eq('month_year', monthYear),
    ]).then(([{ data: inds }, { data: rc }, { data: existing }]) => {
      setIndicators(inds || []);
      setRatingConfig(rc || []);
      const preloaded: Ratings = {};
      for (const ev of existing || []) preloaded[ev.indicator_id] = ev.rating;
      setRatings(preloaded);
      setLoading(false);
    });
  }, [db, student.grade, student.id]);

  useEffect(() => {
    if (loading) return;
    const monthYear = getMonthYear(date);
    db
      .from('evaluation_cards')
      .select('indicator_id, rating')
      .eq('student_id', student.id)
      .eq('month_year', monthYear)
      .then(({ data }) => {
        const preloaded: Ratings = {};
        for (const ev of data || []) preloaded[ev.indicator_id] = ev.rating;
        setRatings(preloaded);
      });
  }, [date]);

  const setRating = (indicatorId: string, code: string) => {
    setRatings(prev => ({ ...prev, [indicatorId]: code }));
  };

  const handleSave = async () => {
    if (!teacher) return;
    setSaving(true);
    const formattedMonth = getMonthYear(date);

    try {
      await db
        .from('evaluation_cards')
        .delete()
        .eq('student_id', student.id)
        .eq('teacher_id', teacher.id)
        .eq('month_year', formattedMonth);

      const inserts = Object.entries(ratings).map(([indicator_id, rating]) => ({
        student_id: student.id,
        teacher_id: teacher.id,
        month_year: formattedMonth,
        indicator_id,
        rating,
        submitted_at: new Date().toISOString(),
      }));

      if (inserts.length > 0) {
        const { error } = await db.from('evaluation_cards').insert(inserts);
        if (error) throw error;
      }

      onSuccess(`Evaluation card saved for ${student.first_name}`);
    } catch {
      onError('Failed to save evaluation card.');
    } finally {
      setSaving(false);
    }
  };

  const handlePrint = () => window.print();

  const categoriesWithIndicators = CATEGORIES.filter(cat =>
    indicators.some(i => i.category === cat)
  );

  const getRatingStyle = (rc: RatingConfig, active: boolean) => {
    if (active) return { backgroundColor: rc.color, color: '#fff', borderColor: rc.color };
    return {};
  };

  return (
    <>
      <style>{`
        @media print {
          body > *:not(#eval-print-root) { display: none !important; }
          #eval-print-root { display: block !important; position: static !important; }
          .no-print { display: none !important; }
        }
      `}</style>

      <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4 bg-black/40 backdrop-blur-sm animate-fade-in">
        <div className="bg-white w-full sm:max-w-2xl sm:rounded-3xl rounded-t-3xl shadow-2xl max-h-[95vh] flex flex-col animate-slide-up">
          <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100 no-print">
            <div>
              <h2 className="font-bold text-gray-900 text-lg">Evaluation Card</h2>
              <p className="text-xs text-gray-500">{student.first_name} {student.last_name} · {student.grade}</p>
            </div>
            <button onClick={onClose} className="w-9 h-9 flex items-center justify-center rounded-full hover:bg-gray-100 transition-colors">
              <X className="w-5 h-5 text-gray-500" />
            </button>
          </div>

          <div className="overflow-y-auto flex-1 p-5" id="eval-print-root">
            <div className="mb-5 p-4 bg-teal-50 rounded-2xl border border-teal-100">
              <div className="flex flex-wrap gap-4 items-center justify-between">
                <div>
                  <p className="text-xl font-bold text-teal-800">{student.first_name} {student.last_name}</p>
                  <p className="text-sm text-teal-600">{student.grade} · Teacher: {teacherName}</p>
                </div>
                <div className="flex items-center gap-2">
                  <Calendar className="w-4 h-4 text-teal-600" />
                  <input
                    type="date"
                    value={date}
                    onChange={e => setDate(e.target.value)}
                    className="h-9 px-3 rounded-xl border-2 border-teal-200 bg-white text-sm font-medium text-gray-800 focus:border-teal-500 focus:outline-none"
                  />
                </div>
              </div>
            </div>

            {ratingConfig.length > 0 && (
              <div className="mb-4 flex gap-3 flex-wrap">
                {ratingConfig.map(rc => (
                  <span
                    key={rc.id}
                    className="flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold text-white"
                    style={{ backgroundColor: rc.color }}
                  >
                    <span className="font-extrabold">{rc.code}</span> — {rc.label}
                  </span>
                ))}
              </div>
            )}

            {loading ? (
              <div className="flex justify-center py-12">
                <Loader2 className="w-8 h-8 animate-spin text-teal-600" />
              </div>
            ) : (
              <div className="space-y-5">
                {categoriesWithIndicators.map(cat => {
                  const catIndicators = indicators.filter(i => i.category === cat);
                  return (
                    <div key={cat} className="bg-gray-50 rounded-2xl p-4">
                      <h3 className="font-bold text-gray-800 mb-3">{cat}</h3>
                      <div className="space-y-2">
                        {catIndicators.map(ind => (
                          <div key={ind.id} className="flex items-center justify-between gap-3 bg-white rounded-xl px-3 py-2.5 shadow-sm">
                            <p className="text-sm text-gray-700 flex-1">{ind.indicator_text}</p>
                            <div className="flex gap-1.5 flex-shrink-0">
                              {ratingConfig.map(rc => {
                                const active = ratings[ind.id] === rc.code;
                                return (
                                  <button
                                    key={rc.id}
                                    onClick={() => setRating(ind.id, rc.code)}
                                    style={active ? getRatingStyle(rc, true) : {}}
                                    className={`px-3 py-1.5 rounded-lg border-2 text-xs font-bold transition-all active:scale-95 ${
                                      active
                                        ? 'shadow-sm'
                                        : 'border-gray-200 text-gray-500 hover:border-gray-300 bg-transparent'
                                    }`}
                                    title={rc.label}
                                  >
                                    {rc.code}
                                  </button>
                                );
                              })}
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>
                  );
                })}

                <div className="bg-gray-50 rounded-2xl p-4">
                  <h3 className="font-bold text-gray-800 mb-2">Teacher's Comments</h3>
                  <textarea
                    className="w-full h-20 p-3 rounded-xl border-2 border-gray-200 text-sm text-gray-700 resize-none focus:border-teal-400 focus:outline-none bg-white"
                    placeholder="Add any additional observations or notes here..."
                  />
                </div>
              </div>
            )}
          </div>

          <div className="px-5 py-4 border-t border-gray-100 flex gap-3 no-print">
            <button
              onClick={handlePrint}
              className="flex items-center gap-2 px-4 h-11 rounded-xl border-2 border-gray-200 text-gray-700 font-semibold text-sm hover:bg-gray-50 transition-colors"
            >
              <Printer className="w-4 h-4" /> Print
            </button>
            <button
              onClick={handleSave}
              disabled={saving || Object.keys(ratings).length === 0}
              className="flex-1 flex items-center justify-center gap-2 h-11 bg-teal-600 hover:bg-teal-700 disabled:bg-gray-200 disabled:text-gray-400 text-white font-bold rounded-xl transition-colors text-sm shadow-md shadow-teal-200"
            >
              {saving ? (
                <><Loader2 className="w-4 h-4 animate-spin" /> Saving...</>
              ) : (
                <><Save className="w-4 h-4" /> Save & Print</>
              )}
            </button>
          </div>
        </div>
      </div>
    </>
  );
}
