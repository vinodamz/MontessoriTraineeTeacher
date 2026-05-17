import { useState, useEffect } from 'react';
import { X, Save, Loader2, ClipboardList, Calendar } from 'lucide-react';
import { Student, StudentBaseline } from '../types';
import { useAuth } from '../context/AuthContext';

interface Props {
  student: Student;
  onClose: () => void;
  onSuccess: (msg: string) => void;
  onError: (msg: string) => void;
}

const FIELDS: { key: keyof Omit<StudentBaseline, 'id' | 'student_id' | 'teacher_id' | 'recorded_by' | 'recorded_at' | 'created_at'>; label: string; placeholder: string }[] = [
  { key: 'gross_motor', label: 'Gross Motor Skills', placeholder: 'e.g. Could not walk independently, limited balance, needed assistance with stairs...' },
  { key: 'fine_motor', label: 'Fine Motor Skills', placeholder: 'e.g. Could not hold a pencil, struggled with buttons, limited hand coordination...' },
  { key: 'literacy', label: 'Literacy & Language', placeholder: 'e.g. No letter recognition, could not identify own name, limited vocabulary...' },
  { key: 'numeracy', label: 'Numeracy & Math Awareness', placeholder: 'e.g. Could not count to 3, no number recognition, no concept of more/less...' },
  { key: 'social_skills', label: 'Social & Emotional Skills', placeholder: 'e.g. Cried frequently on separation, did not interact with peers, tantrums...' },
  { key: 'communication', label: 'Communication & Speech', placeholder: 'e.g. Limited to single words, unclear speech, struggled to express needs...' },
  { key: 'overall_notes', label: 'Overall Notes', placeholder: 'Any other observations about the child before joining Little Graduates...' },
];

type FormData = Record<string, string>;

export default function BaselineModal({ student, onClose, onSuccess, onError }: Props) {
  const { teacher, db } = useAuth();
  const [form, setForm] = useState<FormData>({
    recorded_by: '',
    gross_motor: '',
    fine_motor: '',
    literacy: '',
    numeracy: '',
    social_skills: '',
    communication: '',
    overall_notes: '',
    recorded_at: new Date().toISOString().split('T')[0],
  });
  const [existingId, setExistingId] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    db
      .from('student_baselines')
      .select('*')
      .eq('student_id', student.id)
      .maybeSingle()
      .then(({ data }) => {
        if (data) {
          setExistingId(data.id);
          setForm({
            recorded_by: data.recorded_by || '',
            gross_motor: data.gross_motor || '',
            fine_motor: data.fine_motor || '',
            literacy: data.literacy || '',
            numeracy: data.numeracy || '',
            social_skills: data.social_skills || '',
            communication: data.communication || '',
            overall_notes: data.overall_notes || '',
            recorded_at: data.recorded_at || new Date().toISOString().split('T')[0],
          });
        }
        setLoading(false);
      });
  }, [student.id, db]);

  const handleSave = async () => {
    if (!teacher) return;
    setSaving(true);
    try {
      const payload = {
        student_id: student.id,
        teacher_id: teacher.id,
        recorded_by: form.recorded_by.trim(),
        gross_motor: form.gross_motor.trim(),
        fine_motor: form.fine_motor.trim(),
        literacy: form.literacy.trim(),
        numeracy: form.numeracy.trim(),
        social_skills: form.social_skills.trim(),
        communication: form.communication.trim(),
        overall_notes: form.overall_notes.trim(),
        recorded_at: form.recorded_at,
      };

      if (existingId) {
        const { error } = await db.from('student_baselines').update(payload).eq('id', existingId);
        if (error) throw error;
      } else {
        const { error } = await db.from('student_baselines').insert(payload);
        if (error) throw error;
      }

      onSuccess(`Baseline saved for ${student.first_name}`);
      onClose();
    } catch {
      onError('Failed to save baseline. Please try again.');
    } finally {
      setSaving(false);
    }
  };

  const set = (key: string, value: string) => setForm(prev => ({ ...prev, [key]: value }));

  return (
    <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4 bg-black/40 backdrop-blur-sm">
      <div className="bg-white w-full sm:max-w-2xl sm:rounded-3xl rounded-t-3xl shadow-2xl max-h-[95vh] flex flex-col">

        <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 bg-orange-100 rounded-xl flex items-center justify-center">
              <ClipboardList className="w-5 h-5 text-orange-600" />
            </div>
            <div>
              <h2 className="font-bold text-gray-900 text-lg">Entry Baseline</h2>
              <p className="text-xs text-gray-500">{student.first_name} {student.last_name} · {student.grade}</p>
            </div>
          </div>
          <button onClick={onClose} className="w-9 h-9 flex items-center justify-center rounded-full hover:bg-gray-100 transition-colors">
            <X className="w-5 h-5 text-gray-500" />
          </button>
        </div>

        <div className="overflow-y-auto flex-1 p-5">
          {loading ? (
            <div className="flex items-center justify-center py-16">
              <Loader2 className="w-8 h-8 animate-spin text-orange-500" />
            </div>
          ) : (
            <div className="space-y-4">
              <div className="bg-orange-50 border border-orange-100 rounded-2xl p-4">
                <p className="text-sm text-orange-800 font-medium">
                  Record how <strong>{student.first_name}</strong> was when they first joined Little Graduates.
                  This creates a starting point to measure growth and progress over time.
                </p>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-gray-600 mb-1.5">Recorded By</label>
                  <input
                    type="text"
                    value={form.recorded_by}
                    onChange={e => set('recorded_by', e.target.value)}
                    placeholder="Teacher / Parent name"
                    className="w-full h-10 px-3 rounded-xl border-2 border-gray-200 text-sm text-gray-800 focus:border-orange-400 focus:outline-none"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-gray-600 mb-1.5">
                    <span className="flex items-center gap-1.5"><Calendar className="w-3.5 h-3.5" /> Date of Entry</span>
                  </label>
                  <input
                    type="date"
                    value={form.recorded_at}
                    onChange={e => set('recorded_at', e.target.value)}
                    className="w-full h-10 px-3 rounded-xl border-2 border-gray-200 text-sm text-gray-800 focus:border-orange-400 focus:outline-none"
                  />
                </div>
              </div>

              {FIELDS.map(({ key, label, placeholder }) => (
                <div key={key}>
                  <label className="block text-xs font-semibold text-gray-600 mb-1.5">{label}</label>
                  <textarea
                    value={form[key] || ''}
                    onChange={e => set(key, e.target.value)}
                    placeholder={placeholder}
                    rows={2}
                    className="w-full px-3 py-2.5 rounded-xl border-2 border-gray-200 text-sm text-gray-700 resize-none focus:border-orange-400 focus:outline-none placeholder-gray-400"
                  />
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="px-5 py-4 border-t border-gray-100 flex gap-3">
          <button
            onClick={onClose}
            className="px-5 h-11 rounded-xl border-2 border-gray-200 text-gray-700 font-semibold text-sm hover:bg-gray-50 transition-colors"
          >
            Cancel
          </button>
          <button
            onClick={handleSave}
            disabled={saving || loading}
            className="flex-1 flex items-center justify-center gap-2 h-11 bg-orange-500 hover:bg-orange-600 disabled:bg-gray-200 disabled:text-gray-400 text-white font-bold rounded-xl transition-colors text-sm"
          >
            {saving ? (
              <><Loader2 className="w-4 h-4 animate-spin" /> Saving...</>
            ) : (
              <><Save className="w-4 h-4" /> Save Baseline</>
            )}
          </button>
        </div>
      </div>
    </div>
  );
}
