import { useState, useEffect } from 'react';
import { X, ChevronRight, ChevronLeft, Save, Loader2, CheckCircle, Calendar, MessageSquare, Plus, Trash2, Pencil, Eye } from 'lucide-react';
import { Student, SkillIndicator, RatingConfig, StudentCustomIndicator, MONTHS } from '../types';
import { useAuth } from '../context/AuthContext';

interface Props {
  students: Student[];
  onClose: () => void;
  onSuccess: (msg: string) => void;
  onError: (msg: string) => void;
  preselectedStudentId?: string;
}

type Ratings = Record<string, string>;
type Comments = Record<string, string>;

function getCurrentMonthYear(): string {
  const d = new Date();
  const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const code = `${monthNames[d.getMonth()]}-${String(d.getFullYear()).slice(2)}`;
  return MONTHS.includes(code) ? code : MONTHS[0];
}

function getWeekOfMonth(date: Date): number {
  const firstDay = new Date(date.getFullYear(), date.getMonth(), 1).getDay();
  return Math.ceil((date.getDate() + firstDay) / 7);
}

function getDateInfo(): { month: string; week: number } {
  const d = new Date();
  const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  return { month: monthNames[d.getMonth()], week: getWeekOfMonth(d) };
}

interface CombinedIndicator {
  id: string;
  indicator_text: string;
  category: string;
  isCustom: boolean;
  customIndicator?: StudentCustomIndicator;
}

export default function AssessmentModal({ students, onClose, onSuccess, onError, preselectedStudentId }: Props) {
  const { teacher, db } = useAuth();
  const currentMonthYear = getCurrentMonthYear();
  const dateInfo = getDateInfo();

  const [step, setStep] = useState(preselectedStudentId ? 2 : 1);
  const [selectedMonth, setSelectedMonth] = useState(currentMonthYear);
  const [selectedStudentId, setSelectedStudentId] = useState(preselectedStudentId || '');
  const [indicators, setIndicators] = useState<SkillIndicator[]>([]);
  const [customIndicators, setCustomIndicators] = useState<StudentCustomIndicator[]>([]);
  const [ratingConfig, setRatingConfig] = useState<RatingConfig[]>([]);
  const [ratings, setRatings] = useState<Ratings>({});
  const [comments, setComments] = useState<Comments>({});
  const [loading, setLoading] = useState(false);
  const [loadingData, setLoadingData] = useState(!!preselectedStudentId);
  const [isExistingReport, setIsExistingReport] = useState(false);
  const [isEditMode, setIsEditMode] = useState(false);
  const [monthsWithReports, setMonthsWithReports] = useState<Set<string>>(new Set());
  const [expandedComments, setExpandedComments] = useState<Record<string, boolean>>({});
  const [addingCustom, setAddingCustom] = useState<Record<string, boolean>>({});
  const [newCustomText, setNewCustomText] = useState<Record<string, string>>({});
  const [savingCustom, setSavingCustom] = useState(false);

  const selectedStudent = students.find(s => s.id === selectedStudentId);

  useEffect(() => {
    db
      .from('rating_config')
      .select('*')
      .eq('is_active', true)
      .order('display_order')
      .then(({ data }) => setRatingConfig(data || []));
  }, [db]);

  useEffect(() => {
    if (!selectedStudentId || !selectedStudent) return;
    setLoadingData(true);
    Promise.all([
      db
        .from('skill_indicators')
        .select('*')
        .eq('grade', selectedStudent.grade)
        .eq('is_active', true)
        .order('category')
        .order('display_order'),
      db
        .from('student_custom_indicators')
        .select('*')
        .eq('student_id', selectedStudentId)
        .eq('is_active', true)
        .order('category')
        .order('display_order'),
    ]).then(([{ data: inds, error: indsErr }, { data: custom, error: customErr }]) => {
      if (indsErr) console.error('skill_indicators error:', indsErr);
      if (customErr) console.error('student_custom_indicators error:', customErr);
      setIndicators(inds || []);
      setCustomIndicators(custom || []);
      setLoadingData(false);
    }).catch((err) => {
      console.error('Failed to load indicators:', err);
      setLoadingData(false);
    });
  }, [selectedStudentId, selectedStudent?.grade, db]);

  useEffect(() => {
    if (!selectedStudentId) return;
    db
      .from('evaluation_cards')
      .select('month_year')
      .eq('student_id', selectedStudentId)
      .then(({ data }) => {
        const months = new Set((data || []).map(e => e.month_year));
        setMonthsWithReports(months);
      });
  }, [selectedStudentId, db]);

  useEffect(() => {
    if (!selectedStudentId || !selectedMonth || step !== 2) return;
    setLoadingData(true);
    setRatings({});
    setComments({});
    setIsExistingReport(false);
    setIsEditMode(false);
    Promise.all([
      db
        .from('evaluation_cards')
        .select('indicator_id, rating, is_custom_indicator')
        .eq('student_id', selectedStudentId)
        .eq('month_year', selectedMonth),
      db
        .from('assessment_comments')
        .select('*')
        .eq('student_id', selectedStudentId)
        .eq('month_year', selectedMonth),
    ]).then(([{ data: cards }, { data: comms }]) => {
      if (cards && cards.length > 0) {
        setIsExistingReport(true);
        const loadedRatings: Ratings = {};
        for (const c of cards) {
          const key = c.is_custom_indicator ? `custom-${c.indicator_id}` : c.indicator_id;
          loadedRatings[key] = c.rating;
        }
        setRatings(loadedRatings);
      }
      if (comms && comms.length > 0) {
        const loadedComments: Comments = {};
        const expanded: Record<string, boolean> = {};
        for (const c of comms) {
          const key = c.category ?? '_overall';
          loadedComments[key] = c.comment;
          if (c.category) expanded[c.category] = true;
        }
        setComments(loadedComments);
        setExpandedComments(expanded);
      }
      setLoadingData(false);
    });
  }, [selectedStudentId, selectedMonth, step, db]);

  const allCategories = (() => {
    const indicatorCats = indicators
      .map(i => i.category)
      .filter((c, idx, arr) => arr.indexOf(c) === idx);
    const customCats = customIndicators
      .map(i => i.category)
      .filter((c, idx, arr) => arr.indexOf(c) === idx && !indicatorCats.includes(c));
    return [...indicatorCats, ...customCats];
  })();

  const getCombinedIndicators = (cat: string): CombinedIndicator[] => {
    const standard: CombinedIndicator[] = indicators
      .filter(i => i.category === cat)
      .map(i => ({ id: i.id, indicator_text: i.indicator_text, category: i.category, isCustom: false }));
    const custom: CombinedIndicator[] = customIndicators
      .filter(i => i.category === cat)
      .map(i => ({ id: `custom-${i.id}`, indicator_text: i.indicator_text, category: i.category, isCustom: true, customIndicator: i }));
    return [...standard, ...custom];
  };

  const getCategoryProgress = (cat: string) => {
    const combined = getCombinedIndicators(cat);
    const rated = combined.filter(i => ratings[i.id]);
    return { rated: rated.length, total: combined.length };
  };

  const allCombined = allCategories.flatMap(cat => getCombinedIndicators(cat));
  const totalRated = allCombined.filter(i => ratings[i.id]).length;
  const totalIndicators = allCombined.length;
  const progress = totalIndicators > 0 ? (totalRated / totalIndicators) * 100 : 0;

  const addCustomIndicator = async (category: string) => {
    const text = (newCustomText[category] || '').trim();
    if (!text || !teacher || !selectedStudentId) return;
    setSavingCustom(true);
    try {
      const existing = customIndicators.filter(i => i.category === category);
      const { data } = await db
        .from('student_custom_indicators')
        .insert({
          student_id: selectedStudentId,
          teacher_id: teacher.id,
          category,
          indicator_text: text,
          display_order: existing.length + 1,
          is_active: true,
        })
        .select()
        .single();
      if (data) {
        setCustomIndicators(prev => [...prev, data]);
      }
      setNewCustomText(prev => ({ ...prev, [category]: '' }));
      setAddingCustom(prev => ({ ...prev, [category]: false }));
    } catch {
      onError('Failed to add custom indicator');
    } finally {
      setSavingCustom(false);
    }
  };

  const deleteCustomIndicator = async (ind: StudentCustomIndicator) => {
    await db.from('student_custom_indicators').delete().eq('id', ind.id);
    setCustomIndicators(prev => prev.filter(i => i.id !== ind.id));
    setRatings(prev => {
      const next = { ...prev };
      delete next[`custom-${ind.id}`];
      return next;
    });
  };

  const handleSubmit = async () => {
    if (!teacher || !selectedStudent) return;
    setLoading(true);
    try {
      await db
        .from('evaluation_cards')
        .delete()
        .eq('student_id', selectedStudentId)
        .eq('month_year', selectedMonth);

      const inserts = Object.entries(ratings).map(([indicator_id, rating]) => {
        const isCustom = indicator_id.startsWith('custom-');
        return {
          student_id: selectedStudentId,
          teacher_id: teacher.id,
          month_year: selectedMonth,
          indicator_id: isCustom ? indicator_id.replace('custom-', '') : indicator_id,
          is_custom_indicator: isCustom,
          rating,
        };
      });

      if (inserts.length > 0) {
        const { error } = await db.from('evaluation_cards').insert(inserts);
        if (error) throw error;
      }

      await db
        .from('assessments')
        .delete()
        .eq('student_id', selectedStudentId)
        .eq('month_year', selectedMonth);

      const ratingNumericMap: Record<string, number> = {};
      for (const rc of ratingConfig) ratingNumericMap[rc.code] = rc.numeric_value;

      const assessmentInserts = allCategories.map(cat => {
        const combined = getCombinedIndicators(cat);
        const rated = combined.filter(i => ratings[i.id]);
        const numericVals = rated.map(i => ratingNumericMap[ratings[i.id]] ?? 3);
        const avg = numericVals.length
          ? numericVals.reduce((a, b) => a + b, 0) / numericVals.length
          : null;
        return {
          student_id: selectedStudentId,
          teacher_id: teacher.id,
          month_year: selectedMonth,
          category: cat,
          score: avg != null ? Math.round(avg) : 3,
          category_avg: avg,
        };
      });
      if (assessmentInserts.length > 0) {
        await db.from('assessments').insert(assessmentInserts);
      }

      await db
        .from('assessment_comments')
        .delete()
        .eq('student_id', selectedStudentId)
        .eq('month_year', selectedMonth);

      const commentInserts = Object.entries(comments)
        .filter(([, text]) => text.trim())
        .map(([key, text]) => ({
          student_id: selectedStudentId,
          teacher_id: teacher.id,
          month_year: selectedMonth,
          category: key === '_overall' ? null : key,
          comment: text.trim(),
        }));
      if (commentInserts.length > 0) {
        await db.from('assessment_comments').insert(commentInserts);
      }

      onSuccess(`Assessment saved for ${selectedStudent.first_name} — ${selectedMonth}`);
      onClose();
    } catch {
      onError('Failed to save assessment. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  const getRatingStyle = (rc: RatingConfig, active: boolean) => {
    if (active) return { backgroundColor: rc.color, color: '#fff', borderColor: rc.color };
    return { backgroundColor: 'transparent', color: '#6b7280', borderColor: '#e5e7eb' };
  };

  const getCategoryAvgLabel = (cat: string) => {
    const combined = getCombinedIndicators(cat);
    const rated = combined.filter(i => ratings[i.id]);
    if (!rated.length) return null;
    const ratingMap: Record<string, number> = {};
    for (const rc of ratingConfig) ratingMap[rc.code] = rc.numeric_value;
    const avg = rated.reduce((sum, i) => sum + (ratingMap[ratings[i.id]] ?? 3), 0) / rated.length;
    return ratingConfig.reduce((prev, curr) =>
      Math.abs(curr.numeric_value - avg) < Math.abs(prev.numeric_value - avg) ? curr : prev
    );
  };

  const toggleComment = (key: string) =>
    setExpandedComments(prev => ({ ...prev, [key]: !prev[key] }));

  const totalComments = Object.values(comments).filter(c => c.trim()).length;

  return (
    <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4 bg-black/40 backdrop-blur-sm animate-fade-in">
      <div className="bg-white w-full sm:max-w-2xl sm:rounded-3xl rounded-t-3xl shadow-2xl max-h-[95vh] flex flex-col animate-slide-up">

        <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
          <div>
            <h2 className="font-bold text-gray-900 text-lg">Monthly Assessment</h2>
            <div className="flex items-center gap-2 mt-0.5">
              <Calendar className="w-3.5 h-3.5 text-teal-600" />
              <p className="text-xs text-teal-700 font-semibold">
                Week {dateInfo.week} · {dateInfo.month}
              </p>
              <span className="text-xs text-gray-400">· {new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}</span>
            </div>
          </div>
          <button onClick={onClose} className="w-9 h-9 flex items-center justify-center rounded-full hover:bg-gray-100 transition-colors">
            <X className="w-5 h-5 text-gray-500" />
          </button>
        </div>

        <div className="overflow-y-auto flex-1">
          {step === 1 && (
            <div className="p-5 space-y-4 animate-fade-in">
              <div>
                <label className="block text-sm font-semibold text-gray-700 mb-2">Assessment Month</label>
                <div className="grid grid-cols-3 sm:grid-cols-5 gap-2">
                  {MONTHS.map(m => {
                    const hasReport = monthsWithReports.has(m);
                    const isSelected = selectedMonth === m;
                    const isCurrent = m === currentMonthYear;
                    return (
                      <button
                        key={m}
                        onClick={() => setSelectedMonth(m)}
                        className={`py-2.5 rounded-xl text-sm font-medium transition-all relative ${
                          isSelected
                            ? 'bg-teal-600 text-white shadow-md shadow-teal-200'
                            : hasReport
                            ? 'bg-emerald-50 text-emerald-800 border-2 border-emerald-300 hover:bg-emerald-100'
                            : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                        }`}
                      >
                        {m}
                        {hasReport && !isSelected && (
                          <span className="absolute -top-1.5 -right-1.5 w-3.5 h-3.5 rounded-full bg-emerald-500 border-2 border-white flex items-center justify-center">
                            <CheckCircle className="w-2 h-2 text-white" />
                          </span>
                        )}
                        {isCurrent && !hasReport && (
                          <span className={`absolute -top-1.5 -right-1.5 w-3 h-3 rounded-full border-2 border-white ${isSelected ? 'bg-amber-400' : 'bg-teal-500'}`} />
                        )}
                      </button>
                    );
                  })}
                </div>
                {selectedMonth === currentMonthYear && !monthsWithReports.has(selectedMonth) && (
                  <p className="text-xs text-teal-600 font-medium mt-2 flex items-center gap-1.5">
                    <span className="w-2 h-2 rounded-full bg-teal-500 inline-block" />
                    Current month selected — Week {dateInfo.week} of {dateInfo.month}
                  </p>
                )}
                {monthsWithReports.has(selectedMonth) && (
                  <p className="text-xs text-emerald-700 font-medium mt-2 flex items-center gap-1.5">
                    <CheckCircle className="w-3.5 h-3.5 text-emerald-600" />
                    Report already submitted for <strong>{selectedMonth}</strong> — you can view or edit it
                  </p>
                )}
              </div>

              <div>
                <label className="block text-sm font-semibold text-gray-700 mb-2">Select Student</label>
                <div className="space-y-2 max-h-64 overflow-y-auto pr-1">
                  {students.map(s => (
                    <button
                      key={s.id}
                      onClick={() => setSelectedStudentId(s.id)}
                      className={`w-full flex items-center gap-3 p-3 rounded-xl border-2 transition-all text-left ${
                        selectedStudentId === s.id
                          ? 'border-teal-500 bg-teal-50'
                          : 'border-gray-200 hover:border-gray-300 bg-white'
                      }`}
                    >
                      <div className={`w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold text-white flex-shrink-0 ${
                        s.grade === 'Playgroup' ? 'bg-amber-500' :
                        s.grade === 'Nursery' ? 'bg-emerald-500' :
                        s.grade === 'LKG' ? 'bg-blue-500' : 'bg-violet-500'
                      }`}>
                        {s.first_name[0]}
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="font-semibold text-gray-800 text-sm">{s.first_name} {s.last_name}</p>
                        <p className="text-xs text-gray-500">{s.grade}</p>
                      </div>
                      {selectedStudentId === s.id && (
                        <CheckCircle className="w-5 h-5 text-teal-600 flex-shrink-0" />
                      )}
                    </button>
                  ))}
                </div>
              </div>

              {ratingConfig.length > 0 && (
                <div className="p-3 bg-gray-50 rounded-xl">
                  <p className="text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Rating Scale</p>
                  <div className="flex flex-wrap gap-2">
                    {ratingConfig.map(rc => (
                      <div key={rc.id} className="flex items-center gap-1.5">
                        <span className="w-7 h-7 rounded-lg text-xs font-bold flex items-center justify-center text-white" style={{ backgroundColor: rc.color }}>
                          {rc.code}
                        </span>
                        <span className="text-xs text-gray-600">{rc.label}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}

          {step === 2 && (
            <div className="p-5 animate-fade-in">
              <div className={`flex items-center gap-3 mb-4 p-3 rounded-xl border ${isExistingReport && !isEditMode ? 'bg-emerald-50 border-emerald-200' : 'bg-teal-50 border-teal-100'}`}>
                <div className="flex-1">
                  <div className="flex items-center gap-2">
                    <span className={`text-sm font-bold ${isExistingReport && !isEditMode ? 'text-emerald-800' : 'text-teal-800'}`}>{selectedMonth}</span>
                    {isExistingReport && !isEditMode ? (
                      <span className="flex items-center gap-1 text-xs font-semibold text-emerald-700 bg-emerald-100 px-2 py-0.5 rounded-full">
                        <CheckCircle className="w-3 h-3" /> Submitted
                      </span>
                    ) : isExistingReport && isEditMode ? (
                      <span className="flex items-center gap-1 text-xs font-semibold text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full">
                        <Pencil className="w-3 h-3" /> Editing
                      </span>
                    ) : (
                      <span className="text-xs text-teal-600">— Week {dateInfo.week} of {dateInfo.month}</span>
                    )}
                  </div>
                  <p className={`text-xs mt-0.5 ${isExistingReport && !isEditMode ? 'text-emerald-600' : 'text-teal-600'}`}>
                    {selectedStudent?.first_name} {selectedStudent?.last_name} · {selectedStudent?.grade}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  {isExistingReport && !isEditMode ? (
                    <button
                      onClick={() => setIsEditMode(true)}
                      className="flex items-center gap-1.5 px-3 py-1.5 bg-white border-2 border-emerald-300 text-emerald-700 rounded-lg text-xs font-semibold hover:bg-emerald-50 transition-colors"
                    >
                      <Pencil className="w-3.5 h-3.5" /> Edit
                    </button>
                  ) : isExistingReport && isEditMode ? (
                    <button
                      onClick={() => setIsEditMode(false)}
                      className="flex items-center gap-1.5 px-3 py-1.5 bg-white border-2 border-gray-200 text-gray-600 rounded-lg text-xs font-semibold hover:bg-gray-50 transition-colors"
                    >
                      <Eye className="w-3.5 h-3.5" /> View
                    </button>
                  ) : (
                    <div className="text-right">
                      <p className="text-xs text-teal-500">{totalRated}/{totalIndicators} rated</p>
                      {totalComments > 0 && (
                        <p className="text-xs text-teal-500">{totalComments} comment{totalComments > 1 ? 's' : ''}</p>
                      )}
                    </div>
                  )}
                </div>
              </div>

              {loadingData ? (
                <div className="flex flex-col items-center justify-center py-16 gap-3">
                  <Loader2 className="w-8 h-8 animate-spin text-teal-600" />
                  <p className="text-gray-500 text-sm">Loading indicators...</p>
                </div>
              ) : totalIndicators === 0 ? (
                <div className="flex flex-col items-center justify-center py-12 gap-3 text-center">
                  <p className="text-gray-500 text-sm font-medium">No skill indicators found for {selectedStudent?.grade}</p>
                  <p className="text-gray-400 text-xs">Check that indicators are active in Skills Config settings.</p>
                </div>
              ) : (
                <div className="space-y-5">
                  <div>
                    <div className="flex items-center justify-between mb-1">
                      <span className="text-xs font-medium text-gray-500">Progress</span>
                      <span className="text-xs font-semibold text-teal-700">{totalRated}/{totalIndicators} rated</span>
                    </div>
                    <div className="h-2 bg-gray-100 rounded-full overflow-hidden">
                      <div
                        className="h-full bg-teal-500 rounded-full transition-all duration-500"
                        style={{ width: `${progress}%` }}
                      />
                    </div>
                  </div>

                  {allCategories.map(cat => {
                    const combined = getCombinedIndicators(cat);
                    const { rated, total } = getCategoryProgress(cat);
                    const avgRating = getCategoryAvgLabel(cat);
                    const showComment = expandedComments[cat];
                    const hasComment = !!comments[cat]?.trim();
                    const isAddingCustom = addingCustom[cat];
                    return (
                      <div key={cat} className="bg-gray-50 rounded-2xl overflow-hidden">
                        <div className="flex items-center justify-between px-4 pt-4 pb-3">
                          <div className="flex items-center gap-2">
                            <h3 className="font-bold text-gray-800">{cat}</h3>
                            <span className="text-xs text-gray-400">({rated}/{total})</span>
                          </div>
                          <div className="flex items-center gap-2">
                            {avgRating && (
                              <span className="text-xs font-bold px-2.5 py-0.5 rounded-full text-white" style={{ backgroundColor: avgRating.color }}>
                                {avgRating.code}
                              </span>
                            )}
                            <button
                              onClick={() => toggleComment(cat)}
                              className={`flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-medium transition-colors ${
                                hasComment ? 'bg-amber-100 text-amber-700' : showComment ? 'bg-gray-200 text-gray-600' : 'bg-white text-gray-400 hover:text-gray-600'
                              }`}
                              title="Add comment for this category"
                            >
                              <MessageSquare className="w-3 h-3" />
                              {hasComment ? 'Note' : 'Add note'}
                            </button>
                          </div>
                        </div>

                        {showComment && (
                          <div className="px-4 pb-3">
                            <textarea
                              value={comments[cat] || ''}
                              onChange={e => !( isExistingReport && !isEditMode) && setComments(prev => ({ ...prev, [cat]: e.target.value }))}
                              readOnly={isExistingReport && !isEditMode}
                              placeholder={`Notes on ${cat} for ${selectedStudent?.first_name}...`}
                              rows={2}
                              className={`w-full px-3 py-2 rounded-xl border-2 text-sm text-gray-700 resize-none focus:outline-none placeholder-gray-400 ${
                                isExistingReport && !isEditMode
                                  ? 'border-gray-200 bg-gray-50 cursor-default'
                                  : 'border-amber-200 bg-amber-50 focus:border-amber-400'
                              }`}
                            />
                          </div>
                        )}

                        <div className="px-4 pb-3 space-y-2">
                          {combined.map(ind => (
                            <div key={ind.id} className={`bg-white rounded-xl p-3 shadow-sm ${ind.isCustom ? 'border-l-4 border-blue-300' : ''}`}>
                              <div className="flex items-start gap-2 mb-2.5">
                                <p className="text-sm text-gray-700 font-medium flex-1">{ind.indicator_text}</p>
                                {ind.isCustom && (
                                  <div className="flex items-center gap-1 flex-shrink-0">
                                    <span className="text-xs bg-blue-100 text-blue-600 px-1.5 py-0.5 rounded-md font-medium">Custom</span>
                                    <button
                                      onClick={() => deleteCustomIndicator(ind.customIndicator!)}
                                      className="w-6 h-6 rounded-lg hover:bg-red-50 text-gray-300 hover:text-red-400 flex items-center justify-center transition-colors"
                                      title="Remove custom indicator"
                                    >
                                      <Trash2 className="w-3 h-3" />
                                    </button>
                                  </div>
                                )}
                              </div>
                              <div className="flex gap-2 flex-wrap">
                                {ratingConfig.map(rc => {
                                  const active = ratings[ind.id] === rc.code;
                                  const readOnly = isExistingReport && !isEditMode;
                                  if (readOnly && !active) return null;
                                  return (
                                    <button
                                      key={rc.id}
                                      onClick={() => !readOnly && setRatings(prev => ({ ...prev, [ind.id]: rc.code }))}
                                      style={getRatingStyle(rc, active)}
                                      disabled={readOnly}
                                      className={`flex items-center gap-1.5 px-3 py-2 rounded-xl border-2 text-sm font-bold transition-all ${
                                        readOnly ? 'cursor-default' : 'active:scale-95'
                                      } ${active ? 'shadow-md ring-2 ring-offset-1' : 'hover:border-gray-300'}`}
                                    >
                                      <span className="font-extrabold">{rc.code}</span>
                                      <span className={`text-xs font-medium hidden sm:inline ${active ? 'opacity-90' : 'opacity-70'}`}>
                                        {rc.label}
                                      </span>
                                    </button>
                                  );
                                })}
                                {isExistingReport && !isEditMode && !ratings[ind.id] && (
                                  <span className="text-xs text-gray-400 italic py-2">Not rated</span>
                                )}
                              </div>
                            </div>
                          ))}

                          {!(isExistingReport && !isEditMode) && isAddingCustom ? (
                            <div className="bg-blue-50 rounded-xl p-3 border-2 border-dashed border-blue-200">
                              <p className="text-xs font-semibold text-blue-700 mb-2">Add custom question for {selectedStudent?.first_name}</p>
                              <div className="flex gap-2">
                                <input
                                  autoFocus
                                  type="text"
                                  value={newCustomText[cat] || ''}
                                  onChange={e => setNewCustomText(prev => ({ ...prev, [cat]: e.target.value }))}
                                  onKeyDown={e => {
                                    if (e.key === 'Enter') addCustomIndicator(cat);
                                    if (e.key === 'Escape') setAddingCustom(prev => ({ ...prev, [cat]: false }));
                                  }}
                                  placeholder="e.g. Can button/zip their jacket independently..."
                                  className="flex-1 h-9 px-3 rounded-lg border-2 border-blue-200 text-sm text-gray-800 focus:border-blue-400 focus:outline-none bg-white"
                                />
                                <button
                                  onClick={() => addCustomIndicator(cat)}
                                  disabled={savingCustom || !(newCustomText[cat] || '').trim()}
                                  className="h-9 px-3 rounded-lg bg-blue-600 hover:bg-blue-700 disabled:bg-gray-200 text-white text-sm font-semibold transition-colors flex items-center gap-1"
                                >
                                  {savingCustom ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Plus className="w-3.5 h-3.5" />}
                                  Add
                                </button>
                                <button
                                  onClick={() => setAddingCustom(prev => ({ ...prev, [cat]: false }))}
                                  className="h-9 w-9 rounded-lg border-2 border-gray-200 text-gray-500 hover:bg-gray-50 flex items-center justify-center transition-colors"
                                >
                                  <X className="w-3.5 h-3.5" />
                                </button>
                              </div>
                            </div>
                          ) : !(isExistingReport && !isEditMode) ? (
                            <button
                              onClick={() => setAddingCustom(prev => ({ ...prev, [cat]: true }))}
                              className="w-full flex items-center justify-center gap-1.5 h-8 rounded-xl border border-dashed border-gray-300 text-gray-400 hover:border-blue-400 hover:text-blue-500 text-xs font-medium transition-colors"
                            >
                              <Plus className="w-3 h-3" /> Add custom question for {selectedStudent?.first_name}
                            </button>
                          ) : null}
                        </div>
                      </div>
                    );
                  })}

                  {(!isExistingReport || isEditMode || comments['_overall']?.trim()) && (
                    <div className="bg-gray-50 rounded-2xl p-4">
                      <div className="flex items-center gap-2 mb-2">
                        <MessageSquare className="w-4 h-4 text-gray-500" />
                        <h3 className="font-bold text-gray-800">Overall Comments</h3>
                        {comments['_overall']?.trim() && (
                          <span className="w-2 h-2 rounded-full bg-amber-500" />
                        )}
                      </div>
                      <textarea
                        value={comments['_overall'] || ''}
                        onChange={e => !(isExistingReport && !isEditMode) && setComments(prev => ({ ...prev, _overall: e.target.value }))}
                        readOnly={isExistingReport && !isEditMode}
                        placeholder={`General observations about ${selectedStudent?.first_name} this month...`}
                        rows={3}
                        className={`w-full px-3 py-2.5 rounded-xl border-2 text-sm text-gray-700 resize-none focus:outline-none placeholder-gray-400 ${
                          isExistingReport && !isEditMode
                            ? 'border-gray-200 bg-gray-50 cursor-default'
                            : 'border-gray-200 bg-white focus:border-teal-400'
                        }`}
                      />
                    </div>
                  )}
                </div>
              )}
            </div>
          )}
        </div>

        <div className="px-5 py-4 border-t border-gray-100 flex gap-3">
          {step === 2 && (
            <button
              onClick={() => { setStep(1); setIsEditMode(false); }}
              className="flex items-center gap-1.5 px-4 h-11 rounded-xl border-2 border-gray-200 text-gray-700 font-semibold text-sm hover:bg-gray-50 transition-colors"
            >
              <ChevronLeft className="w-4 h-4" /> Back
            </button>
          )}
          {step === 1 ? (
            <button
              onClick={() => setStep(2)}
              disabled={!selectedStudentId}
              className="flex-1 flex items-center justify-center gap-1.5 h-11 bg-teal-600 hover:bg-teal-700 disabled:bg-gray-200 disabled:text-gray-400 text-white font-semibold rounded-xl transition-colors text-sm"
            >
              {monthsWithReports.has(selectedMonth) && selectedStudentId
                ? <><Eye className="w-4 h-4" /> View Report</>
                : <>Continue <ChevronRight className="w-4 h-4" /></>
              }
            </button>
          ) : isExistingReport && !isEditMode ? (
            <button
              onClick={onClose}
              className="flex-1 flex items-center justify-center gap-2 h-11 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold rounded-xl transition-colors text-sm"
            >
              Close
            </button>
          ) : (
            <button
              onClick={handleSubmit}
              disabled={loading}
              className="flex-1 flex items-center justify-center gap-2 h-11 bg-teal-600 hover:bg-teal-700 disabled:bg-gray-200 disabled:text-gray-400 text-white font-bold rounded-xl transition-colors text-sm shadow-md shadow-teal-200"
            >
              {loading ? (
                <><Loader2 className="w-4 h-4 animate-spin" /> Saving...</>
              ) : isExistingReport ? (
                <><Save className="w-4 h-4" /> Update Assessment</>
              ) : (
                <><Save className="w-4 h-4" /> Submit Assessment</>
              )}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
