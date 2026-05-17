import { useState } from 'react';
import { Plus, Pencil, Trash2, X, Check, Search, UserRound } from 'lucide-react';
import { Student, Teacher, GRADE_COLORS } from '../types';
import { useAuth } from '../context/AuthContext';

interface Props {
  students: Student[];
  teachers: Teacher[];
  onStudentsChange: (students: Student[]) => void;
  onToast: (type: 'success' | 'error', msg: string) => void;
}

const GRADES = ['Playgroup', 'Nursery', 'LKG', 'UKG'] as const;

interface FormState {
  first_name: string;
  last_name: string;
  grade: string;
  teacher_id: string;
}

const emptyForm = (): FormState => ({ first_name: '', last_name: '', grade: 'Playgroup', teacher_id: '' });

export default function StudentManagement({ students, teachers, onStudentsChange, onToast }: Props) {
  const { db } = useAuth();
  const [search, setSearch] = useState('');
  const [filterGrade, setFilterGrade] = useState('');
  const [filterTeacher, setFilterTeacher] = useState('');
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [form, setForm] = useState<FormState>(emptyForm());
  const [saving, setSaving] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState<string | null>(null);

  const filtered = students.filter(s => {
    const name = `${s.first_name} ${s.last_name}`.toLowerCase();
    if (search && !name.includes(search.toLowerCase())) return false;
    if (filterGrade && s.grade !== filterGrade) return false;
    if (filterTeacher && s.teacher_id !== filterTeacher) return false;
    return true;
  });

  const openAdd = () => {
    setEditingId(null);
    setForm({ ...emptyForm(), teacher_id: teachers[0]?.id ?? '' });
    setShowForm(true);
  };

  const openEdit = (s: Student) => {
    setEditingId(s.id);
    setForm({ first_name: s.first_name, last_name: s.last_name, grade: s.grade, teacher_id: s.teacher_id });
    setShowForm(true);
  };

  const closeForm = () => {
    setShowForm(false);
    setEditingId(null);
    setForm(emptyForm());
  };

  const handleSave = async () => {
    if (!form.first_name.trim() || !form.last_name.trim()) {
      onToast('error', 'First and last name are required');
      return;
    }
    if (!form.teacher_id) {
      onToast('error', 'Please assign a teacher');
      return;
    }
    setSaving(true);
    if (editingId) {
      const { error } = await db
        .from('students')
        .update({ first_name: form.first_name.trim(), last_name: form.last_name.trim(), grade: form.grade, teacher_id: form.teacher_id })
        .eq('id', editingId);
      if (error) { onToast('error', 'Failed to update student'); setSaving(false); return; }
      onStudentsChange(students.map(s => s.id === editingId ? { ...s, ...form, first_name: form.first_name.trim(), last_name: form.last_name.trim() } : s));
      onToast('success', 'Student updated');
    } else {
      const { data, error } = await db
        .from('students')
        .insert({ first_name: form.first_name.trim(), last_name: form.last_name.trim(), grade: form.grade, teacher_id: form.teacher_id })
        .select()
        .single();
      if (error || !data) { onToast('error', 'Failed to add student'); setSaving(false); return; }
      onStudentsChange([...students, data]);
      onToast('success', 'Student added');
    }
    setSaving(false);
    closeForm();
  };

  const handleDelete = async (id: string) => {
    const { error } = await db.from('students').delete().eq('id', id);
    if (error) { onToast('error', 'Failed to delete student'); return; }
    onStudentsChange(students.filter(s => s.id !== id));
    onToast('success', 'Student removed');
    setConfirmDelete(null);
  };

  return (
    <div className="p-5 space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative flex-1 min-w-[180px]">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search students..."
            className="w-full h-9 pl-9 pr-3 rounded-xl border-2 border-gray-200 text-sm focus:border-teal-400 focus:outline-none"
          />
        </div>
        <select
          value={filterGrade}
          onChange={e => setFilterGrade(e.target.value)}
          className="h-9 px-3 rounded-xl border-2 border-gray-200 text-sm font-medium text-gray-700 focus:border-teal-400 focus:outline-none bg-white"
        >
          <option value="">All Grades</option>
          {GRADES.map(g => <option key={g}>{g}</option>)}
        </select>
        <select
          value={filterTeacher}
          onChange={e => setFilterTeacher(e.target.value)}
          className="h-9 px-3 rounded-xl border-2 border-gray-200 text-sm font-medium text-gray-700 focus:border-teal-400 focus:outline-none bg-white"
        >
          <option value="">All Teachers</option>
          {teachers.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
        </select>
        <button
          onClick={openAdd}
          className="flex items-center gap-1.5 h-9 px-4 rounded-xl bg-teal-600 hover:bg-teal-700 text-white text-sm font-semibold transition-colors ml-auto"
        >
          <Plus className="w-4 h-4" /> Add Student
        </button>
      </div>

      <div className="text-xs text-gray-500">{filtered.length} student{filtered.length !== 1 ? 's' : ''}</div>

      {filtered.length === 0 ? (
        <div className="py-16 text-center">
          <UserRound className="w-10 h-10 text-gray-300 mx-auto mb-3" />
          <p className="text-gray-500 font-medium">No students found</p>
        </div>
      ) : (
        <div className="grid gap-2">
          {filtered.map(s => {
            const teacher = teachers.find(t => t.id === s.teacher_id);
            const gc = GRADE_COLORS[s.grade];
            return (
              <div key={s.id} className="flex items-center gap-3 px-4 py-3 bg-gray-50 rounded-xl hover:bg-gray-100 transition-colors group">
                <div className={`w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold flex-shrink-0 ${gc.light} ${gc.text}`}>
                  {s.first_name[0]}{s.last_name[0]}
                </div>
                <div className="flex-1 min-w-0">
                  <p className="font-semibold text-gray-800 text-sm">{s.first_name} {s.last_name}</p>
                  <p className="text-xs text-gray-500 truncate">{teacher?.name ?? 'Unassigned'}</p>
                </div>
                <span className={`px-2 py-0.5 rounded-full text-xs font-bold ${gc.light} ${gc.text} flex-shrink-0`}>
                  {s.grade}
                </span>
                <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                  <button
                    onClick={() => openEdit(s)}
                    className="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-teal-100 text-teal-700 transition-colors"
                  >
                    <Pencil className="w-3.5 h-3.5" />
                  </button>
                  <button
                    onClick={() => setConfirmDelete(s.id)}
                    className="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-red-100 text-red-600 transition-colors"
                  >
                    <Trash2 className="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {showForm && (
        <div className="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md">
            <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
              <h2 className="font-bold text-gray-900">{editingId ? 'Edit Student' : 'Add Student'}</h2>
              <button onClick={closeForm} className="w-8 h-8 flex items-center justify-center rounded-xl hover:bg-gray-100 transition-colors">
                <X className="w-4 h-4 text-gray-500" />
              </button>
            </div>
            <div className="p-6 space-y-4">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-gray-600 mb-1.5">First Name</label>
                  <input
                    value={form.first_name}
                    onChange={e => setForm(f => ({ ...f, first_name: e.target.value }))}
                    placeholder="First name"
                    className="w-full h-10 px-3 rounded-xl border-2 border-gray-200 text-sm focus:border-teal-400 focus:outline-none"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-gray-600 mb-1.5">Last Name</label>
                  <input
                    value={form.last_name}
                    onChange={e => setForm(f => ({ ...f, last_name: e.target.value }))}
                    placeholder="Last name"
                    className="w-full h-10 px-3 rounded-xl border-2 border-gray-200 text-sm focus:border-teal-400 focus:outline-none"
                  />
                </div>
              </div>
              <div>
                <label className="block text-xs font-semibold text-gray-600 mb-1.5">Grade</label>
                <select
                  value={form.grade}
                  onChange={e => setForm(f => ({ ...f, grade: e.target.value }))}
                  className="w-full h-10 px-3 rounded-xl border-2 border-gray-200 text-sm focus:border-teal-400 focus:outline-none bg-white"
                >
                  {GRADES.map(g => <option key={g}>{g}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-xs font-semibold text-gray-600 mb-1.5">Assign Teacher</label>
                <select
                  value={form.teacher_id}
                  onChange={e => setForm(f => ({ ...f, teacher_id: e.target.value }))}
                  className="w-full h-10 px-3 rounded-xl border-2 border-gray-200 text-sm focus:border-teal-400 focus:outline-none bg-white"
                >
                  <option value="">— Select teacher —</option>
                  {teachers.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
                </select>
              </div>
            </div>
            <div className="flex gap-2 px-6 pb-5">
              <button
                onClick={closeForm}
                className="flex-1 h-10 rounded-xl border-2 border-gray-200 text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleSave}
                disabled={saving}
                className="flex-1 h-10 rounded-xl bg-teal-600 hover:bg-teal-700 disabled:opacity-60 text-white text-sm font-semibold transition-colors flex items-center justify-center gap-2"
              >
                {saving ? <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" /> : <Check className="w-4 h-4" />}
                {editingId ? 'Save Changes' : 'Add Student'}
              </button>
            </div>
          </div>
        </div>
      )}

      {confirmDelete && (
        <div className="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 text-center">
            <div className="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
              <Trash2 className="w-5 h-5 text-red-600" />
            </div>
            <h3 className="font-bold text-gray-900 mb-1">Remove Student?</h3>
            <p className="text-sm text-gray-500 mb-6">This will permanently delete the student and all their assessment data.</p>
            <div className="flex gap-2">
              <button
                onClick={() => setConfirmDelete(null)}
                className="flex-1 h-10 rounded-xl border-2 border-gray-200 text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={() => handleDelete(confirmDelete)}
                className="flex-1 h-10 rounded-xl bg-red-600 hover:bg-red-700 text-white text-sm font-semibold transition-colors"
              >
                Remove
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
