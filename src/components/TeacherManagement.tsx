import { useState } from 'react';
import { Plus, Pencil, Trash2, X, Check, Eye, EyeOff, UserCog } from 'lucide-react';
import { Teacher, Student } from '../types';
import { useAuth } from '../context/AuthContext';

interface Props {
  teachers: Teacher[];
  students: Student[];
  onTeachersChange: (teachers: Teacher[]) => void;
  onToast: (type: 'success' | 'error', msg: string) => void;
}

interface FormState {
  name: string;
  pin: string;
}

const emptyForm = (): FormState => ({ name: '', pin: '' });

export default function TeacherManagement({ teachers, students, onTeachersChange, onToast }: Props) {
  const { db } = useAuth();
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [form, setForm] = useState<FormState>(emptyForm());
  const [saving, setSaving] = useState(false);
  const [showPin, setShowPin] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState<string | null>(null);

  const openAdd = () => {
    setEditingId(null);
    setForm(emptyForm());
    setShowPin(false);
    setShowForm(true);
  };

  const openEdit = (t: Teacher) => {
    setEditingId(t.id);
    setForm({ name: t.name, pin: '' });
    setShowPin(false);
    setShowForm(true);
  };

  const closeForm = () => {
    setShowForm(false);
    setEditingId(null);
    setForm(emptyForm());
  };

  const handleSave = async () => {
    if (!form.name.trim()) { onToast('error', 'Teacher name is required'); return; }
    if (!editingId && !form.pin.trim()) { onToast('error', 'PIN is required'); return; }
    if (form.pin && !/^\d{4,8}$/.test(form.pin)) { onToast('error', 'PIN must be 4–8 digits'); return; }
    setSaving(true);
    if (editingId) {
      const updates: Partial<Teacher> = { name: form.name.trim() };
      if (form.pin.trim()) updates.pin = form.pin.trim();
      const { error } = await db.from('teachers').update(updates).eq('id', editingId);
      if (error) { onToast('error', 'Failed to update teacher'); setSaving(false); return; }
      onTeachersChange(teachers.map(t => t.id === editingId ? { ...t, ...updates } : t));
      onToast('success', 'Teacher updated');
    } else {
      if (teachers.some(t => t.pin === form.pin.trim())) {
        onToast('error', 'That PIN is already in use'); setSaving(false); return;
      }
      const { data, error } = await db
        .from('teachers')
        .insert({ name: form.name.trim(), pin: form.pin.trim(), role: 'teacher' })
        .select()
        .single();
      if (error || !data) { onToast('error', 'Failed to add teacher'); setSaving(false); return; }
      onTeachersChange([...teachers, data]);
      onToast('success', 'Teacher added');
    }
    setSaving(false);
    closeForm();
  };

  const handleDelete = async (id: string) => {
    const { error } = await db.from('teachers').delete().eq('id', id);
    if (error) { onToast('error', 'Failed to delete teacher'); return; }
    onTeachersChange(teachers.filter(t => t.id !== id));
    onToast('success', 'Teacher removed');
    setConfirmDelete(null);
  };

  const getStudentCount = (teacherId: string) => students.filter(s => s.teacher_id === teacherId).length;

  return (
    <div className="p-5 space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-sm text-gray-500">{teachers.length} teacher{teachers.length !== 1 ? 's' : ''}</p>
        <button
          onClick={openAdd}
          className="flex items-center gap-1.5 h-9 px-4 rounded-xl bg-teal-600 hover:bg-teal-700 text-white text-sm font-semibold transition-colors"
        >
          <Plus className="w-4 h-4" /> Add Teacher
        </button>
      </div>

      {teachers.length === 0 ? (
        <div className="py-16 text-center">
          <UserCog className="w-10 h-10 text-gray-300 mx-auto mb-3" />
          <p className="text-gray-500 font-medium">No teachers yet</p>
        </div>
      ) : (
        <div className="grid gap-2">
          {teachers.map(t => {
            const count = getStudentCount(t.id);
            return (
              <div key={t.id} className="flex items-center gap-3 px-4 py-3 bg-gray-50 rounded-xl hover:bg-gray-100 transition-colors group">
                <div className="w-10 h-10 rounded-xl bg-teal-100 flex items-center justify-center flex-shrink-0">
                  <span className="text-teal-800 font-bold text-sm">{t.name.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase()}</span>
                </div>
                <div className="flex-1 min-w-0">
                  <p className="font-semibold text-gray-800 text-sm">{t.name}</p>
                  <p className="text-xs text-gray-500">{count} student{count !== 1 ? 's' : ''}</p>
                </div>
                <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                  <button
                    onClick={() => openEdit(t)}
                    className="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-teal-100 text-teal-700 transition-colors"
                  >
                    <Pencil className="w-3.5 h-3.5" />
                  </button>
                  <button
                    onClick={() => setConfirmDelete(t.id)}
                    className="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-red-100 text-red-600 transition-colors"
                    title={count > 0 ? `${count} students assigned` : 'Delete'}
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
              <h2 className="font-bold text-gray-900">{editingId ? 'Edit Teacher' : 'Add Teacher'}</h2>
              <button onClick={closeForm} className="w-8 h-8 flex items-center justify-center rounded-xl hover:bg-gray-100 transition-colors">
                <X className="w-4 h-4 text-gray-500" />
              </button>
            </div>
            <div className="p-6 space-y-4">
              <div>
                <label className="block text-xs font-semibold text-gray-600 mb-1.5">Full Name</label>
                <input
                  value={form.name}
                  onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
                  placeholder="Teacher's full name"
                  className="w-full h-10 px-3 rounded-xl border-2 border-gray-200 text-sm focus:border-teal-400 focus:outline-none"
                />
              </div>
              <div>
                <label className="block text-xs font-semibold text-gray-600 mb-1.5">
                  PIN {editingId && <span className="text-gray-400 font-normal">(leave blank to keep current)</span>}
                </label>
                <div className="relative">
                  <input
                    value={form.pin}
                    onChange={e => setForm(f => ({ ...f, pin: e.target.value.replace(/\D/g, '').slice(0, 8) }))}
                    placeholder={editingId ? 'Enter new PIN to change' : '4–8 digit PIN'}
                    type={showPin ? 'text' : 'password'}
                    className="w-full h-10 px-3 pr-10 rounded-xl border-2 border-gray-200 text-sm focus:border-teal-400 focus:outline-none"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPin(v => !v)}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                  >
                    {showPin ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                </div>
                <p className="text-xs text-gray-400 mt-1">Teachers use this PIN to log in</p>
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
                {editingId ? 'Save Changes' : 'Add Teacher'}
              </button>
            </div>
          </div>
        </div>
      )}

      {confirmDelete && (() => {
        const teacher = teachers.find(t => t.id === confirmDelete);
        const count = getStudentCount(confirmDelete);
        return (
          <div className="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 text-center">
              <div className="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <Trash2 className="w-5 h-5 text-red-600" />
              </div>
              <h3 className="font-bold text-gray-900 mb-1">Remove {teacher?.name}?</h3>
              {count > 0 ? (
                <p className="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2 mb-4">
                  This teacher has {count} student{count !== 1 ? 's' : ''} assigned. Removing them will unassign those students.
                </p>
              ) : (
                <p className="text-sm text-gray-500 mb-6">This will permanently remove the teacher account.</p>
              )}
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
        );
      })()}
    </div>
  );
}
