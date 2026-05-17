import { useState, useEffect } from 'react';
import { Pencil, Trash2, X, Check, Search, MessageSquare } from 'lucide-react';
import { Student, Teacher, AssessmentComment, GRADE_COLORS, MONTHS, CATEGORIES } from '../../types';
import { useAuth } from '../../context/AuthContext';

interface Props {
  students: Student[];
  teachers: Teacher[];
  onToast: (type: 'success' | 'error', msg: string) => void;
}

export default function CommentsAdmin({ students, teachers, onToast }: Props) {
  const { db } = useAuth();
  const [comments, setComments] = useState<AssessmentComment[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [filterTeacher, setFilterTeacher] = useState('');
  const [filterGrade, setFilterGrade] = useState('');
  const [filterMonth, setFilterMonth] = useState('');
  const [filterCategory, setFilterCategory] = useState('');
  const [editingId, setEditingId] = useState<string | null>(null);
  const [editComment, setEditComment] = useState('');
  const [saving, setSaving] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState<string | null>(null);

  useEffect(() => {
    db.from('assessment_comments').select('*').order('created_at', { ascending: false }).then(({ data }) => {
      setComments(data || []);
      setLoading(false);
    });
  }, [db]);

  const getStudent = (id: string) => students.find(s => s.id === id);
  const getTeacher = (id: string) => teachers.find(t => t.id === id);

  const filtered = comments.filter(c => {
    const student = getStudent(c.student_id);
    if (!student) return false;
    const name = `${student.first_name} ${student.last_name}`.toLowerCase();
    const commentText = c.comment.toLowerCase();
    if (search && !name.includes(search.toLowerCase()) && !commentText.includes(search.toLowerCase())) return false;
    if (filterTeacher && c.teacher_id !== filterTeacher) return false;
    if (filterGrade && student.grade !== filterGrade) return false;
    if (filterMonth && c.month_year !== filterMonth) return false;
    if (filterCategory && c.category !== filterCategory) return false;
    return true;
  });

  const handleSave = async () => {
    if (!editingId || !editComment.trim()) {
      onToast('error', 'Comment cannot be empty');
      return;
    }
    setSaving(true);
    const { error } = await db.from('assessment_comments').update({ comment: editComment.trim() }).eq('id', editingId);
    if (error) { onToast('error', 'Failed to update comment'); setSaving(false); return; }
    setComments(prev => prev.map(c => c.id === editingId ? { ...c, comment: editComment.trim() } : c));
    onToast('success', 'Comment updated');
    setEditingId(null);
    setSaving(false);
  };

  const handleDelete = async (id: string) => {
    const { error } = await db.from('assessment_comments').delete().eq('id', id);
    if (error) { onToast('error', 'Failed to delete comment'); return; }
    setComments(prev => prev.filter(c => c.id !== id));
    onToast('success', 'Comment deleted');
    setConfirmDelete(null);
  };

  if (loading) {
    return <div className="p-8 text-center text-gray-400 text-sm">Loading comments...</div>;
  }

  return (
    <div className="p-5 space-y-4">
      <div className="flex flex-wrap gap-2">
        <div className="relative flex-1 min-w-[180px]">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search student or comment..."
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

      <div className="text-xs text-gray-500">{filtered.length} comment{filtered.length !== 1 ? 's' : ''}</div>

      {filtered.length === 0 ? (
        <div className="py-16 text-center">
          <MessageSquare className="w-10 h-10 text-gray-200 mx-auto mb-3" />
          <p className="text-gray-400 text-sm">No comments found</p>
        </div>
      ) : (
        <div className="space-y-2">
          {filtered.map(c => {
            const student = getStudent(c.student_id);
            const teacher = getTeacher(c.teacher_id);
            const gradeColor = student ? GRADE_COLORS[student.grade] : null;
            const isEditing = editingId === c.id;

            return (
              <div key={c.id} className="bg-white rounded-xl border border-gray-100 px-4 py-3 space-y-2">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex items-center gap-3 min-w-0">
                    {student && gradeColor && (
                      <div className={`w-8 h-8 rounded-lg flex items-center justify-center text-xs font-bold flex-shrink-0 ${gradeColor.light} ${gradeColor.text}`}>
                        {student.first_name[0]}{student.last_name[0]}
                      </div>
                    )}
                    <div className="min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-semibold text-gray-800 text-sm">
                          {student ? `${student.first_name} ${student.last_name}` : 'Unknown'}
                        </span>
                        {student && gradeColor && (
                          <span className={`px-2 py-0.5 rounded-full text-xs font-bold ${gradeColor.light} ${gradeColor.text}`}>
                            {student.grade}
                          </span>
                        )}
                        <span className="text-xs font-mono text-gray-500">{c.month_year}</span>
                        {c.category && (
                          <span className="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">{c.category}</span>
                        )}
                      </div>
                      <p className="text-xs text-gray-400">{teacher?.name ?? '—'} &middot; {new Date(c.created_at ?? '').toLocaleDateString()}</p>
                    </div>
                  </div>
                  <div className="flex items-center gap-1 flex-shrink-0">
                    {isEditing ? (
                      <>
                        <button onClick={handleSave} disabled={saving}
                          className="w-7 h-7 flex items-center justify-center rounded-lg bg-teal-100 hover:bg-teal-200 text-teal-700 transition-colors">
                          {saving ? <div className="w-3 h-3 border-2 border-teal-600 border-t-transparent rounded-full animate-spin" /> : <Check className="w-3.5 h-3.5" />}
                        </button>
                        <button onClick={() => setEditingId(null)}
                          className="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-500 transition-colors">
                          <X className="w-3.5 h-3.5" />
                        </button>
                      </>
                    ) : (
                      <>
                        <button onClick={() => { setEditingId(c.id); setEditComment(c.comment); }}
                          className="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-teal-100 text-teal-700 transition-colors">
                          <Pencil className="w-3.5 h-3.5" />
                        </button>
                        <button onClick={() => setConfirmDelete(c.id)}
                          className="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-red-100 text-red-600 transition-colors">
                          <Trash2 className="w-3.5 h-3.5" />
                        </button>
                      </>
                    )}
                  </div>
                </div>

                {isEditing ? (
                  <textarea
                    value={editComment}
                    onChange={e => setEditComment(e.target.value)}
                    rows={3}
                    className="w-full px-3 py-2 rounded-xl border-2 border-teal-400 text-sm focus:outline-none resize-none"
                    autoFocus
                  />
                ) : (
                  <p className="text-sm text-gray-700 leading-relaxed bg-gray-50 rounded-xl px-3 py-2">{c.comment}</p>
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
            <h3 className="font-bold text-gray-900 mb-1">Delete Comment?</h3>
            <p className="text-sm text-gray-500 mb-6">This will permanently delete this comment.</p>
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
