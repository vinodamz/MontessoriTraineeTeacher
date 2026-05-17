import { useState, useEffect } from 'react';
import { Pencil, Trash2, X, Check, Search, ChevronDown, ChevronUp } from 'lucide-react';
import { Student, Teacher, StudentBaseline, GRADE_COLORS } from '../../types';
import { useAuth } from '../../context/AuthContext';

interface Props {
  students: Student[];
  teachers: Teacher[];
  onToast: (type: 'success' | 'error', msg: string) => void;
}

const DOMAIN_FIELDS: { key: keyof Pick<StudentBaseline, 'gross_motor'|'fine_motor'|'literacy'|'numeracy'|'social_skills'|'communication'>; label: string }[] = [
  { key: 'gross_motor', label: 'Gross Motor' },
  { key: 'fine_motor', label: 'Fine Motor' },
  { key: 'literacy', label: 'Literacy' },
  { key: 'numeracy', label: 'Numeracy' },
  { key: 'social_skills', label: 'Social Skills' },
  { key: 'communication', label: 'Communication' },
];

type EditForm = Pick<StudentBaseline, 'gross_motor'|'fine_motor'|'literacy'|'numeracy'|'social_skills'|'communication'|'overall_notes'>;

export default function BaselineAdmin({ students, teachers, onToast }: Props) {
  const { db } = useAuth();
  const [baselines, setBaselines] = useState<StudentBaseline[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [filterTeacher, setFilterTeacher] = useState('');
  const [filterGrade, setFilterGrade] = useState('');
  const [editingId, setEditingId] = useState<string | null>(null);
  const [editForm, setEditForm] = useState<EditForm>({ gross_motor: '', fine_motor: '', literacy: '', numeracy: '', social_skills: '', communication: '', overall_notes: '' });
  const [saving, setSaving] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState<string | null>(null);
  const [expandedId, setExpandedId] = useState<string | null>(null);

  useEffect(() => {
    db.from('student_baselines').select('*').order('created_at', { ascending: false }).then(({ data }) => {
      setBaselines(data || []);
      setLoading(false);
    });
  }, [db]);

  const getStudent = (id: string) => students.find(s => s.id === id);
  const getTeacher = (id: string) => teachers.find(t => t.id === id);

  const filtered = baselines.filter(b => {
    const student = getStudent(b.student_id);
    if (!student) return false;
    const name = `${student.first_name} ${student.last_name}`.toLowerCase();
    if (search && !name.includes(search.toLowerCase())) return false;
    if (filterTeacher && b.teacher_id !== filterTeacher) return false;
    if (filterGrade && student.grade !== filterGrade) return false;
    return true;
  });

  const openEdit = (b: StudentBaseline) => {
    setEditingId(b.id);
    setEditForm({
      gross_motor: b.gross_motor,
      fine_motor: b.fine_motor,
      literacy: b.literacy,
      numeracy: b.numeracy,
      social_skills: b.social_skills,
      communication: b.communication,
      overall_notes: b.overall_notes,
    });
    setExpandedId(b.id);
  };

  const handleSave = async () => {
    if (!editingId) return;
    setSaving(true);
    const { error } = await db.from('student_baselines').update(editForm).eq('id', editingId);
    if (error) { onToast('error', 'Failed to update baseline'); setSaving(false); return; }
    setBaselines(prev => prev.map(b => b.id === editingId ? { ...b, ...editForm } : b));
    onToast('success', 'Baseline updated');
    setEditingId(null);
    setSaving(false);
  };

  const handleDelete = async (id: string) => {
    const { error } = await db.from('student_baselines').delete().eq('id', id);
    if (error) { onToast('error', 'Failed to delete baseline'); return; }
    setBaselines(prev => prev.filter(b => b.id !== id));
    onToast('success', 'Baseline deleted');
    setConfirmDelete(null);
  };

  if (loading) {
    return <div className="p-8 text-center text-gray-400 text-sm">Loading baselines...</div>;
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
      </div>

      <div className="text-xs text-gray-500">{filtered.length} baseline{filtered.length !== 1 ? 's' : ''}</div>

      {filtered.length === 0 ? (
        <div className="py-16 text-center text-gray-400 text-sm">No baselines found</div>
      ) : (
        <div className="space-y-2">
          {filtered.map(b => {
            const student = getStudent(b.student_id);
            const teacher = getTeacher(b.teacher_id);
            const gradeColor = student ? GRADE_COLORS[student.grade] : null;
            const isEditing = editingId === b.id;
            const isExpanded = expandedId === b.id;

            return (
              <div key={b.id} className="rounded-xl border border-gray-100 overflow-hidden">
                <div className="flex items-center gap-3 px-4 py-3 bg-white hover:bg-gray-50 transition-colors">
                  {student && gradeColor && (
                    <div className={`w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold flex-shrink-0 ${gradeColor.light} ${gradeColor.text}`}>
                      {student.first_name[0]}{student.last_name[0]}
                    </div>
                  )}
                  <div className="flex-1 min-w-0">
                    <p className="font-semibold text-gray-800 text-sm">
                      {student ? `${student.first_name} ${student.last_name}` : 'Unknown Student'}
                    </p>
                    <p className="text-xs text-gray-500">
                      {teacher?.name ?? '—'} &middot; {new Date(b.recorded_at).toLocaleDateString()}
                    </p>
                  </div>
                  {student && gradeColor && (
                    <span className={`px-2 py-0.5 rounded-full text-xs font-bold flex-shrink-0 ${gradeColor.light} ${gradeColor.text}`}>
                      {student.grade}
                    </span>
                  )}
                  <div className="flex items-center gap-1 flex-shrink-0">
                    <button onClick={() => openEdit(b)}
                      className="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-teal-100 text-teal-700 transition-colors">
                      <Pencil className="w-3.5 h-3.5" />
                    </button>
                    <button onClick={() => setConfirmDelete(b.id)}
                      className="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-red-100 text-red-600 transition-colors">
                      <Trash2 className="w-3.5 h-3.5" />
                    </button>
                    <button onClick={() => setExpandedId(isExpanded ? null : b.id)}
                      className="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-500 transition-colors">
                      {isExpanded ? <ChevronUp className="w-3.5 h-3.5" /> : <ChevronDown className="w-3.5 h-3.5" />}
                    </button>
                  </div>
                </div>

                {isExpanded && (
                  <div className="px-4 pb-4 pt-1 bg-gray-50 border-t border-gray-100">
                    {isEditing ? (
                      <div className="space-y-3">
                        <div className="grid grid-cols-2 gap-3">
                          {DOMAIN_FIELDS.map(({ key, label }) => (
                            <div key={key}>
                              <label className="block text-xs font-semibold text-gray-600 mb-1">{label}</label>
                              <textarea
                                value={editForm[key]}
                                onChange={e => setEditForm(f => ({ ...f, [key]: e.target.value }))}
                                rows={2}
                                className="w-full px-3 py-2 rounded-xl border-2 border-gray-200 text-sm focus:border-teal-400 focus:outline-none resize-none"
                              />
                            </div>
                          ))}
                        </div>
                        <div>
                          <label className="block text-xs font-semibold text-gray-600 mb-1">Overall Notes</label>
                          <textarea
                            value={editForm.overall_notes}
                            onChange={e => setEditForm(f => ({ ...f, overall_notes: e.target.value }))}
                            rows={3}
                            className="w-full px-3 py-2 rounded-xl border-2 border-gray-200 text-sm focus:border-teal-400 focus:outline-none resize-none"
                          />
                        </div>
                        <div className="flex gap-2 pt-1">
                          <button onClick={() => setEditingId(null)}
                            className="flex items-center gap-1.5 h-9 px-4 rounded-xl border-2 border-gray-200 text-sm font-semibold text-gray-600 hover:bg-white transition-colors">
                            <X className="w-3.5 h-3.5" /> Cancel
                          </button>
                          <button onClick={handleSave} disabled={saving}
                            className="flex items-center gap-1.5 h-9 px-4 rounded-xl bg-teal-600 hover:bg-teal-700 disabled:opacity-60 text-white text-sm font-semibold transition-colors">
                            {saving ? <div className="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin" /> : <Check className="w-3.5 h-3.5" />}
                            Save Changes
                          </button>
                        </div>
                      </div>
                    ) : (
                      <div className="space-y-2 mt-1">
                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                          {DOMAIN_FIELDS.map(({ key, label }) => (
                            <div key={key} className="bg-white rounded-xl px-3 py-2 border border-gray-100">
                              <p className="text-xs font-semibold text-gray-500 mb-0.5">{label}</p>
                              <p className="text-sm text-gray-800">{b[key] || <span className="text-gray-300">—</span>}</p>
                            </div>
                          ))}
                        </div>
                        {b.overall_notes && (
                          <div className="bg-white rounded-xl px-3 py-2 border border-gray-100">
                            <p className="text-xs font-semibold text-gray-500 mb-0.5">Overall Notes</p>
                            <p className="text-sm text-gray-800">{b.overall_notes}</p>
                          </div>
                        )}
                        <p className="text-xs text-gray-400">Recorded by: {b.recorded_by}</p>
                      </div>
                    )}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}

      {confirmDelete && (
        <div className="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 text-center">
            <div className="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
              <Trash2 className="w-5 h-5 text-red-600" />
            </div>
            <h3 className="font-bold text-gray-900 mb-1">Delete Baseline?</h3>
            <p className="text-sm text-gray-500 mb-6">This will permanently delete this baseline record.</p>
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
