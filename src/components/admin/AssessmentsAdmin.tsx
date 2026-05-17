import { useState, useEffect } from 'react';
import { Pencil, Trash2, X, Check, Search, ChevronDown } from 'lucide-react';
import { Student, Teacher, Assessment, GRADE_COLORS, MONTHS, CATEGORIES, SCORE_LABELS } from '../../types';
import { useAuth } from '../../context/AuthContext';

interface Props {
  students: Student[];
  teachers: Teacher[];
  onToast: (type: 'success' | 'error', msg: string) => void;
}

interface EditForm {
  score: number;
  category_avg: number | null;
}

export default function AssessmentsAdmin({ students, teachers, onToast }: Props) {
  const { db } = useAuth();
  const [assessments, setAssessments] = useState<Assessment[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [filterTeacher, setFilterTeacher] = useState('');
  const [filterGrade, setFilterGrade] = useState('');
  const [filterMonth, setFilterMonth] = useState('');
  const [filterCategory, setFilterCategory] = useState('');
  const [editingId, setEditingId] = useState<string | null>(null);
  const [editForm, setEditForm] = useState<EditForm>({ score: 3, category_avg: null });
  const [saving, setSaving] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState<string | null>(null);
  const [expanded, setExpanded] = useState<string | null>(null);

  useEffect(() => {
    db.from('assessments').select('*').order('submitted_at', { ascending: false }).then(({ data }) => {
      setAssessments(data || []);
      setLoading(false);
    });
  }, [db]);

  const getStudent = (id: string) => students.find(s => s.id === id);
  const getTeacher = (id: string) => teachers.find(t => t.id === id);

  const filtered = assessments.filter(a => {
    const student = getStudent(a.student_id);
    if (!student) return false;
    const name = `${student.first_name} ${student.last_name}`.toLowerCase();
    if (search && !name.includes(search.toLowerCase())) return false;
    if (filterTeacher && a.teacher_id !== filterTeacher) return false;
    if (filterGrade && student.grade !== filterGrade) return false;
    if (filterMonth && a.month_year !== filterMonth) return false;
    if (filterCategory && a.category !== filterCategory) return false;
    return true;
  });

  const openEdit = (a: Assessment) => {
    setEditingId(a.id);
    setEditForm({ score: a.score, category_avg: a.category_avg });
  };

  const handleSave = async () => {
    if (!editingId) return;
    setSaving(true);
    const { error } = await db.from('assessments').update({
      score: editForm.score,
      category_avg: editForm.category_avg,
    }).eq('id', editingId);
    if (error) { onToast('error', 'Failed to update assessment'); setSaving(false); return; }
    setAssessments(prev => prev.map(a => a.id === editingId ? { ...a, ...editForm } : a));
    onToast('success', 'Assessment updated');
    setEditingId(null);
    setSaving(false);
  };

  const handleDelete = async (id: string) => {
    const { error } = await db.from('assessments').delete().eq('id', id);
    if (error) { onToast('error', 'Failed to delete assessment'); return; }
    setAssessments(prev => prev.filter(a => a.id !== id));
    onToast('success', 'Assessment deleted');
    setConfirmDelete(null);
  };

  const scoreInfo = (score: number) => SCORE_LABELS[score] ?? { label: String(score), color: 'text-gray-700', bg: 'bg-gray-400' };

  if (loading) {
    return (
      <div className="p-8 text-center text-gray-400 text-sm">Loading assessments...</div>
    );
  }

  return (
    <div className="p-5 space-y-4">
      <div className="flex flex-wrap gap-2">
        <div className="relative flex-1 min-w-[180px]">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search student..."
            className="w-full h-9 pl-9 pr-3 rounded-xl border-2 border-gray-200 text-sm focus:border-teal-400 focus:outline-none"
          />
        </div>
        <select value={filterTeacher} onChange={e => setFilterTeacher(e.target.value)}
          className="h-9 px-3 rounded-xl border-2 border-gray-200 text-sm font-medium text-gray-700 focus:border-teal-400 focus:outline-none bg-white">
          <option value="">All Teachers</option>
          {teachers.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
        </select>
        <select value={filterGrade} onChange={e => setFilterGrade(e.target.value)}
          className="h-9 px-3 rounded-xl border-2 border-gray-200 text-sm font-medium text-gray-700 focus:border-teal-400 focus:outline-none bg-white">
          <option value="">All Grades</option>
          {['Playgroup','Nursery','LKG','UKG'].map(g => <option key={g}>{g}</option>)}
        </select>
        <select value={filterMonth} onChange={e => setFilterMonth(e.target.value)}
          className="h-9 px-3 rounded-xl border-2 border-gray-200 text-sm font-medium text-gray-700 focus:border-teal-400 focus:outline-none bg-white">
          <option value="">All Months</option>
          {MONTHS.map(m => <option key={m}>{m}</option>)}
        </select>
        <select value={filterCategory} onChange={e => setFilterCategory(e.target.value)}
          className="h-9 px-3 rounded-xl border-2 border-gray-200 text-sm font-medium text-gray-700 focus:border-teal-400 focus:outline-none bg-white">
          <option value="">All Categories</option>
          {CATEGORIES.map(c => <option key={c}>{c}</option>)}
        </select>
      </div>

      <div className="text-xs text-gray-500">{filtered.length} record{filtered.length !== 1 ? 's' : ''}</div>

      {filtered.length === 0 ? (
        <div className="py-16 text-center text-gray-400 text-sm">No assessments found</div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-gray-100">
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-gray-50">
                <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600">Student</th>
                <th className="px-3 py-3 text-left text-xs font-semibold text-gray-600">Grade</th>
                <th className="px-3 py-3 text-left text-xs font-semibold text-gray-600">Teacher</th>
                <th className="px-3 py-3 text-left text-xs font-semibold text-gray-600">Month</th>
                <th className="px-3 py-3 text-left text-xs font-semibold text-gray-600">Category</th>
                <th className="px-3 py-3 text-center text-xs font-semibold text-gray-600">Score</th>
                <th className="px-3 py-3 text-center text-xs font-semibold text-gray-600">Avg</th>
                <th className="px-3 py-3 text-center text-xs font-semibold text-gray-600">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {filtered.map(a => {
                const student = getStudent(a.student_id);
                const teacher = getTeacher(a.teacher_id);
                const gradeColor = student ? GRADE_COLORS[student.grade] : null;
                const si = scoreInfo(a.score);
                const isEditing = editingId === a.id;

                return (
                  <tr key={a.id} className="hover:bg-gray-50 transition-colors">
                    <td className="px-4 py-2.5 font-medium text-gray-800 text-sm">
                      {student ? `${student.first_name} ${student.last_name}` : <span className="text-gray-400">Unknown</span>}
                    </td>
                    <td className="px-3 py-2.5">
                      {student && gradeColor && (
                        <span className={`px-2 py-0.5 rounded-full text-xs font-bold ${gradeColor.light} ${gradeColor.text}`}>
                          {student.grade}
                        </span>
                      )}
                    </td>
                    <td className="px-3 py-2.5 text-xs text-gray-500">{teacher?.name ?? '—'}</td>
                    <td className="px-3 py-2.5 text-xs font-mono text-gray-600">{a.month_year}</td>
                    <td className="px-3 py-2.5 text-xs text-gray-700">{a.category}</td>
                    <td className="px-3 py-2.5 text-center">
                      {isEditing ? (
                        <select
                          value={editForm.score}
                          onChange={e => setEditForm(f => ({ ...f, score: Number(e.target.value) }))}
                          className="h-8 px-2 rounded-lg border-2 border-teal-400 text-xs font-semibold focus:outline-none bg-white"
                        >
                          {[1,2,3,4,5].map(v => (
                            <option key={v} value={v}>{v} — {SCORE_LABELS[v]?.label}</option>
                          ))}
                        </select>
                      ) : (
                        <span className={`inline-block px-2 py-0.5 rounded-lg text-xs font-bold text-white ${si.bg}`}>
                          {a.score} — {si.label}
                        </span>
                      )}
                    </td>
                    <td className="px-3 py-2.5 text-center">
                      {isEditing ? (
                        <input
                          type="number"
                          min={1} max={5} step={0.01}
                          value={editForm.category_avg ?? ''}
                          onChange={e => setEditForm(f => ({ ...f, category_avg: e.target.value ? Number(e.target.value) : null }))}
                          className="w-16 h-8 px-2 rounded-lg border-2 border-teal-400 text-xs text-center focus:outline-none"
                        />
                      ) : (
                        <span className="text-xs text-gray-600">{a.category_avg != null ? a.category_avg.toFixed(2) : '—'}</span>
                      )}
                    </td>
                    <td className="px-3 py-2.5">
                      <div className="flex items-center justify-center gap-1">
                        {isEditing ? (
                          <>
                            <button
                              onClick={handleSave}
                              disabled={saving}
                              className="w-7 h-7 flex items-center justify-center rounded-lg bg-teal-100 hover:bg-teal-200 text-teal-700 transition-colors"
                            >
                              {saving ? <div className="w-3 h-3 border-2 border-teal-600 border-t-transparent rounded-full animate-spin" /> : <Check className="w-3.5 h-3.5" />}
                            </button>
                            <button
                              onClick={() => setEditingId(null)}
                              className="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-500 transition-colors"
                            >
                              <X className="w-3.5 h-3.5" />
                            </button>
                          </>
                        ) : (
                          <>
                            <button
                              onClick={() => openEdit(a)}
                              className="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-teal-100 text-teal-700 transition-colors"
                            >
                              <Pencil className="w-3.5 h-3.5" />
                            </button>
                            <button
                              onClick={() => setConfirmDelete(a.id)}
                              className="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-red-100 text-red-600 transition-colors"
                            >
                              <Trash2 className="w-3.5 h-3.5" />
                            </button>
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {confirmDelete && (
        <div className="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 text-center">
            <div className="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
              <Trash2 className="w-5 h-5 text-red-600" />
            </div>
            <h3 className="font-bold text-gray-900 mb-1">Delete Assessment?</h3>
            <p className="text-sm text-gray-500 mb-6">This will permanently delete this assessment record.</p>
            <div className="flex gap-2">
              <button onClick={() => setConfirmDelete(null)}
                className="flex-1 h-10 rounded-xl border-2 border-gray-200 text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors">
                Cancel
              </button>
              <button onClick={() => handleDelete(confirmDelete)}
                className="flex-1 h-10 rounded-xl bg-red-600 hover:bg-red-700 text-white text-sm font-semibold transition-colors">
                Delete
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
