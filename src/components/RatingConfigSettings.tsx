import { useState, useEffect } from 'react';
import { Plus, Trash2, Save, Loader2, GripVertical, ToggleLeft, ToggleRight } from 'lucide-react';
import { RatingConfig } from '../types';
import { useAuth } from '../context/AuthContext';

const HEX_PRESETS = [
  '#10b981', '#f59e0b', '#ef4444', '#3b82f6', '#8b5cf6',
  '#ec4899', '#14b8a6', '#f97316', '#6366f1', '#84cc16',
];

interface EditableRating extends RatingConfig {
  _dirty?: boolean;
  _new?: boolean;
}

export default function RatingConfigSettings({ onToast }: { onToast: (type: 'success' | 'error', msg: string) => void }) {
  const { db } = useAuth();
  const [ratings, setRatings] = useState<EditableRating[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    db
      .from('rating_config')
      .select('*')
      .order('display_order')
      .then(({ data }) => {
        setRatings(data || []);
        setLoading(false);
      });
  }, [db]);

  const updateRating = (id: string, field: keyof RatingConfig, value: string | number | boolean) => {
    setRatings(prev => prev.map(r => r.id === id ? { ...r, [field]: value, _dirty: true } : r));
  };

  const addRating = () => {
    const newRating: EditableRating = {
      id: `new-${Date.now()}`,
      code: '',
      label: '',
      color: '#10b981',
      numeric_value: 3,
      display_order: ratings.length + 1,
      is_active: true,
      _new: true,
      _dirty: true,
    };
    setRatings(prev => [...prev, newRating]);
  };

  const removeRating = async (id: string) => {
    if (!id.startsWith('new-')) {
      await db.from('rating_config').delete().eq('id', id);
    }
    setRatings(prev => prev.filter(r => r.id !== id));
  };

  const handleSave = async () => {
    setSaving(true);
    try {
      const dirty = ratings.filter(r => r._dirty);
      for (const r of dirty) {
        if (!r.code.trim() || !r.label.trim()) continue;
        const payload = {
          code: r.code.trim(),
          label: r.label.trim(),
          color: r.color,
          numeric_value: r.numeric_value,
          display_order: r.display_order,
          is_active: r.is_active,
        };
        if (r._new) {
          const { data: inserted, error } = await db.from('rating_config').insert(payload).select().single();
          if (error) throw error;
          if (inserted) {
            setRatings(prev => prev.map(p => p.id === r.id ? { ...inserted, _dirty: false, _new: false } : p));
          }
        } else {
          const { error } = await db.from('rating_config').update(payload).eq('id', r.id);
          if (error) throw error;
          setRatings(prev => prev.map(p => p.id === r.id ? { ...p, _dirty: false } : p));
        }
      }
      onToast('success', 'Rating configuration saved');
    } catch {
      onToast('error', 'Failed to save rating configuration');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="flex justify-center py-12">
        <Loader2 className="w-6 h-6 animate-spin text-teal-600" />
      </div>
    );
  }

  return (
    <div className="p-5 space-y-5">
      <div className="flex items-start justify-between">
        <div>
          <h3 className="font-bold text-gray-800">Rating Options</h3>
          <p className="text-xs text-gray-500 mt-0.5">
            Define the rating codes, labels, colors and their numeric equivalents used in assessments.
          </p>
        </div>
        <button
          onClick={addRating}
          className="flex items-center gap-1.5 px-3 h-9 rounded-xl bg-teal-600 hover:bg-teal-700 text-white text-sm font-semibold transition-colors flex-shrink-0"
        >
          <Plus className="w-4 h-4" /> Add Option
        </button>
      </div>

      <div className="space-y-3">
        {ratings.map((r, idx) => (
          <div
            key={r.id}
            className={`bg-white rounded-2xl border-2 p-4 transition-all ${
              r._dirty ? 'border-amber-300 bg-amber-50/30' : 'border-gray-100'
            }`}
          >
            <div className="flex items-center gap-2 mb-3">
              <GripVertical className="w-4 h-4 text-gray-300 flex-shrink-0" />
              <span className="text-xs font-medium text-gray-400">Option {idx + 1}</span>
              <div className="ml-auto flex items-center gap-2">
                <button
                  onClick={() => updateRating(r.id, 'is_active', !r.is_active)}
                  className={`transition-colors ${r.is_active ? 'text-teal-600' : 'text-gray-400'}`}
                  title={r.is_active ? 'Active — click to disable' : 'Inactive — click to enable'}
                >
                  {r.is_active ? <ToggleRight className="w-5 h-5" /> : <ToggleLeft className="w-5 h-5" />}
                </button>
                <button
                  onClick={() => removeRating(r.id)}
                  className="text-gray-300 hover:text-red-500 transition-colors"
                >
                  <Trash2 className="w-4 h-4" />
                </button>
              </div>
            </div>

            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <div>
                <label className="block text-xs font-semibold text-gray-500 mb-1">Code</label>
                <input
                  type="text"
                  value={r.code}
                  onChange={e => updateRating(r.id, 'code', e.target.value.toUpperCase().slice(0, 3))}
                  placeholder="e.g. D"
                  className="w-full h-10 px-3 rounded-xl border-2 border-gray-200 text-sm font-bold text-gray-800 focus:border-teal-400 focus:outline-none uppercase"
                  maxLength={3}
                />
              </div>
              <div className="col-span-2 sm:col-span-1">
                <label className="block text-xs font-semibold text-gray-500 mb-1">Label</label>
                <input
                  type="text"
                  value={r.label}
                  onChange={e => updateRating(r.id, 'label', e.target.value)}
                  placeholder="e.g. Demonstrated"
                  className="w-full h-10 px-3 rounded-xl border-2 border-gray-200 text-sm text-gray-800 focus:border-teal-400 focus:outline-none"
                />
              </div>
              <div>
                <label className="block text-xs font-semibold text-gray-500 mb-1">Score (1–5)</label>
                <select
                  value={r.numeric_value}
                  onChange={e => updateRating(r.id, 'numeric_value', parseInt(e.target.value))}
                  className="w-full h-10 px-3 rounded-xl border-2 border-gray-200 text-sm font-semibold text-gray-800 focus:border-teal-400 focus:outline-none bg-white"
                >
                  {[1,2,3,4,5].map(v => <option key={v} value={v}>{v}</option>)}
                </select>
              </div>
            </div>

            <div className="mt-3">
              <label className="block text-xs font-semibold text-gray-500 mb-1.5">Color</label>
              <div className="flex items-center gap-2 flex-wrap">
                {HEX_PRESETS.map(hex => (
                  <button
                    key={hex}
                    onClick={() => updateRating(r.id, 'color', hex)}
                    className={`w-7 h-7 rounded-lg transition-all ${r.color === hex ? 'ring-2 ring-offset-2 ring-gray-400 scale-110' : 'hover:scale-105'}`}
                    style={{ backgroundColor: hex }}
                    title={hex}
                  />
                ))}
                <div className="flex items-center gap-1.5 ml-1">
                  <input
                    type="color"
                    value={r.color}
                    onChange={e => updateRating(r.id, 'color', e.target.value)}
                    className="w-8 h-8 rounded-lg cursor-pointer border-0 p-0"
                    title="Custom color"
                  />
                  <span className="text-xs text-gray-400 font-mono">{r.color}</span>
                </div>
              </div>
            </div>

            <div className="mt-3 p-2.5 bg-gray-50 rounded-xl">
              <p className="text-xs text-gray-500">Preview:</p>
              <div className="flex items-center gap-2 mt-1">
                <span
                  className="px-3 py-1.5 rounded-xl border-2 text-sm font-extrabold text-white"
                  style={{ backgroundColor: r.color, borderColor: r.color }}
                >
                  {r.code || '?'}
                </span>
                <span className="text-sm font-medium text-gray-700">{r.label || 'Label'}</span>
                <span className="ml-auto text-xs text-gray-400">Numeric value: {r.numeric_value}/5</span>
              </div>
            </div>
          </div>
        ))}

        {ratings.length === 0 && (
          <div className="text-center py-8 text-gray-400">
            <p className="text-3xl mb-2">⭐</p>
            <p className="text-sm">No rating options configured yet.</p>
            <p className="text-xs mt-1">Click "Add Option" to create your first rating.</p>
          </div>
        )}
      </div>

      {ratings.some(r => r._dirty) && (
        <div className="sticky bottom-0 bg-white border-t border-gray-100 pt-4">
          <button
            onClick={handleSave}
            disabled={saving}
            className="w-full flex items-center justify-center gap-2 h-11 bg-teal-600 hover:bg-teal-700 disabled:bg-gray-200 text-white font-bold rounded-xl transition-colors text-sm shadow-md shadow-teal-200"
          >
            {saving ? (
              <><Loader2 className="w-4 h-4 animate-spin" /> Saving...</>
            ) : (
              <><Save className="w-4 h-4" /> Save Changes</>
            )}
          </button>
        </div>
      )}
    </div>
  );
}
