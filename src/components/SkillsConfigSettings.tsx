import { useState, useEffect } from 'react';
import { Plus, Trash2, Loader2, GripVertical, ToggleLeft, ToggleRight, Pencil, X, Check, ChevronDown, ChevronRight } from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import { SkillIndicator, CATEGORIES } from '../types';

const GRADES = ['Playgroup', 'Nursery', 'LKG', 'UKG'] as const;
type Grade = typeof GRADES[number];

const GRADE_COLORS: Record<Grade, string> = {
  Playgroup: 'bg-amber-100 text-amber-800 border-amber-200',
  Nursery: 'bg-emerald-100 text-emerald-800 border-emerald-200',
  LKG: 'bg-blue-100 text-blue-800 border-blue-200',
  UKG: 'bg-violet-100 text-violet-800 border-violet-200',
};

interface EditableIndicator extends SkillIndicator {
  _editing?: boolean;
  _new?: boolean;
  _editText?: string;
}

interface NewIndicatorForm {
  category: string;
  text: string;
}

export default function SkillsConfigSettings({ onToast }: { onToast: (type: 'success' | 'error', msg: string) => void }) {
  const { db } = useAuth();
  const [activeGrade, setActiveGrade] = useState<Grade>('Playgroup');
  const [indicators, setIndicators] = useState<Record<Grade, EditableIndicator[]>>({
    Playgroup: [], Nursery: [], LKG: [], UKG: [],
  });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [collapsedCats, setCollapsedCats] = useState<Record<string, boolean>>({});
  const [newForm, setNewForm] = useState<NewIndicatorForm | null>(null);
  const [newCategoryName, setNewCategoryName] = useState('');
  const [showNewCategory, setShowNewCategory] = useState(false);

  useEffect(() => {
    db
      .from('skill_indicators')
      .select('*')
      .order('grade')
      .order('category')
      .order('display_order')
      .then(({ data }) => {
        const grouped: Record<Grade, EditableIndicator[]> = { Playgroup: [], Nursery: [], LKG: [], UKG: [] };
        for (const ind of data || []) {
          if (ind.grade in grouped) grouped[ind.grade as Grade].push(ind);
        }
        setIndicators(grouped);
        setLoading(false);
      });
  }, []);

  const gradeIndicators = indicators[activeGrade];
  const categories = [
    ...CATEGORIES.filter(cat => gradeIndicators.some(i => i.category === cat)),
    ...gradeIndicators
      .map(i => i.category)
      .filter(cat => !CATEGORIES.includes(cat))
      .filter((cat, idx, arr) => arr.indexOf(cat) === idx),
  ];

  const startEdit = (id: string) => {
    setIndicators(prev => ({
      ...prev,
      [activeGrade]: prev[activeGrade].map(i =>
        i.id === id ? { ...i, _editing: true, _editText: i.indicator_text } : i
      ),
    }));
  };

  const cancelEdit = (id: string) => {
    setIndicators(prev => ({
      ...prev,
      [activeGrade]: prev[activeGrade]
        .map(i => i.id === id ? { ...i, _editing: false, _editText: undefined } : i)
        .filter(i => !i._new || i.id !== id),
    }));
  };

  const saveEdit = async (ind: EditableIndicator) => {
    const text = (ind._editText || '').trim();
    if (!text) return;
    setSaving(true);
    try {
      if (ind._new) {
        const { data } = await db
          .from('skill_indicators')
          .insert({ grade: activeGrade, category: ind.category, indicator_text: text, display_order: ind.display_order, is_active: true })
          .select()
          .single();
        if (data) {
          setIndicators(prev => ({
            ...prev,
            [activeGrade]: prev[activeGrade].map(i => i.id === ind.id ? { ...data, _editing: false } : i),
          }));
        }
      } else {
        await db.from('skill_indicators').update({ indicator_text: text }).eq('id', ind.id);
        setIndicators(prev => ({
          ...prev,
          [activeGrade]: prev[activeGrade].map(i =>
            i.id === ind.id ? { ...i, indicator_text: text, _editing: false, _editText: undefined } : i
          ),
        }));
      }
      onToast('success', 'Indicator saved');
    } catch {
      onToast('error', 'Failed to save');
    } finally {
      setSaving(false);
    }
  };

  const toggleActive = async (ind: EditableIndicator) => {
    const newVal = !ind.is_active;
    await db.from('skill_indicators').update({ is_active: newVal }).eq('id', ind.id);
    setIndicators(prev => ({
      ...prev,
      [activeGrade]: prev[activeGrade].map(i => i.id === ind.id ? { ...i, is_active: newVal } : i),
    }));
  };

  const deleteIndicator = async (id: string) => {
    if (!confirm('Delete this indicator? This cannot be undone.')) return;
    await db.from('skill_indicators').delete().eq('id', id);
    setIndicators(prev => ({
      ...prev,
      [activeGrade]: prev[activeGrade].filter(i => i.id !== id),
    }));
    onToast('success', 'Indicator deleted');
  };

  const addIndicator = (category: string) => {
    const catIndicators = gradeIndicators.filter(i => i.category === category);
    const tempId = `new-${Date.now()}`;
    const newInd: EditableIndicator = {
      id: tempId,
      grade: activeGrade,
      category,
      indicator_text: '',
      display_order: catIndicators.length + 1,
      is_active: true,
      _editing: true,
      _new: true,
      _editText: '',
    };
    setIndicators(prev => ({ ...prev, [activeGrade]: [...prev[activeGrade], newInd] }));
    setCollapsedCats(prev => ({ ...prev, [category]: false }));
  };

  const handleAddFromForm = async () => {
    if (!newForm || !newForm.category || !newForm.text.trim()) return;
    setSaving(true);
    try {
      const catIndicators = gradeIndicators.filter(i => i.category === newForm.category);
      const { data } = await db
        .from('skill_indicators')
        .insert({
          grade: activeGrade,
          category: newForm.category,
          indicator_text: newForm.text.trim(),
          display_order: catIndicators.length + 1,
          is_active: true,
        })
        .select()
        .single();
      if (data) {
        setIndicators(prev => ({ ...prev, [activeGrade]: [...prev[activeGrade], data] }));
        setCollapsedCats(prev => ({ ...prev, [newForm.category]: false }));
      }
      setNewForm(null);
      onToast('success', 'Indicator added');
    } catch {
      onToast('error', 'Failed to add indicator');
    } finally {
      setSaving(false);
    }
  };

  const addNewCategory = async () => {
    const name = newCategoryName.trim();
    if (!name) return;
    if (categories.includes(name)) {
      onToast('error', 'Category already exists for this grade');
      return;
    }
    setSaving(true);
    try {
      const { data } = await db
        .from('skill_indicators')
        .insert({ grade: activeGrade, category: name, indicator_text: `New indicator for ${name}`, display_order: 1, is_active: true })
        .select()
        .single();
      if (data) {
        setIndicators(prev => ({ ...prev, [activeGrade]: [...prev[activeGrade], { ...data, _editing: true, _editText: data.indicator_text }] }));
      }
      setNewCategoryName('');
      setShowNewCategory(false);
      onToast('success', `Category "${name}" added`);
    } catch {
      onToast('error', 'Failed to add category');
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
    <div className="p-5">
      <div className="flex items-start justify-between mb-5">
        <div>
          <h3 className="font-bold text-gray-800">Skill Indicators</h3>
          <p className="text-xs text-gray-500 mt-0.5">Manage skill indicators per grade and category. Disabled indicators are hidden from assessments.</p>
        </div>
      </div>

      <div className="flex gap-2 mb-5 overflow-x-auto pb-1">
        {GRADES.map(g => (
          <button
            key={g}
            onClick={() => setActiveGrade(g)}
            className={`flex-shrink-0 px-4 py-2 rounded-xl text-sm font-semibold border-2 transition-all ${
              activeGrade === g
                ? GRADE_COLORS[g] + ' border-current shadow-sm'
                : 'bg-white text-gray-500 border-gray-200 hover:border-gray-300'
            }`}
          >
            {g}
            <span className="ml-1.5 text-xs opacity-70">({indicators[g].filter(i => i.is_active !== false).length})</span>
          </button>
        ))}
      </div>

      <div className="space-y-3">
        {categories.map(cat => {
          const catIndicators = gradeIndicators.filter(i => i.category === cat);
          const collapsed = collapsedCats[cat];
          const activeCount = catIndicators.filter(i => i.is_active !== false && !i._new).length;
          return (
            <div key={cat} className="bg-gray-50 rounded-2xl overflow-hidden border border-gray-100">
              <div
                className="flex items-center gap-3 px-4 py-3 cursor-pointer select-none"
                onClick={() => setCollapsedCats(prev => ({ ...prev, [cat]: !prev[cat] }))}
              >
                {collapsed ? <ChevronRight className="w-4 h-4 text-gray-400" /> : <ChevronDown className="w-4 h-4 text-gray-400" />}
                <span className="font-bold text-gray-800 flex-1">{cat}</span>
                <span className="text-xs text-gray-400">{activeCount} active</span>
                <button
                  onClick={e => { e.stopPropagation(); addIndicator(cat); }}
                  className="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-teal-50 hover:bg-teal-100 text-teal-700 text-xs font-semibold transition-colors"
                >
                  <Plus className="w-3 h-3" /> Add
                </button>
              </div>

              {!collapsed && (
                <div className="px-4 pb-4 space-y-2">
                  {catIndicators.map(ind => (
                    <div
                      key={ind.id}
                      className={`flex items-start gap-2 bg-white rounded-xl px-3 py-2.5 shadow-sm border transition-colors ${
                        ind.is_active === false ? 'opacity-50 border-gray-100' : 'border-transparent'
                      }`}
                    >
                      <GripVertical className="w-4 h-4 text-gray-300 mt-0.5 flex-shrink-0" />

                      {ind._editing ? (
                        <div className="flex-1 flex items-center gap-2">
                          <input
                            autoFocus
                            type="text"
                            value={ind._editText ?? ind.indicator_text}
                            onChange={e => setIndicators(prev => ({
                              ...prev,
                              [activeGrade]: prev[activeGrade].map(i =>
                                i.id === ind.id ? { ...i, _editText: e.target.value } : i
                              ),
                            }))}
                            onKeyDown={e => { if (e.key === 'Enter') saveEdit(ind); if (e.key === 'Escape') cancelEdit(ind.id); }}
                            placeholder="Enter indicator text..."
                            className="flex-1 h-8 px-3 rounded-lg border-2 border-teal-300 text-sm text-gray-800 focus:border-teal-500 focus:outline-none bg-teal-50"
                          />
                          <button onClick={() => saveEdit(ind)} disabled={saving} className="w-7 h-7 rounded-lg bg-teal-500 hover:bg-teal-600 text-white flex items-center justify-center flex-shrink-0 transition-colors">
                            {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Check className="w-3.5 h-3.5" />}
                          </button>
                          <button onClick={() => cancelEdit(ind.id)} className="w-7 h-7 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-500 flex items-center justify-center flex-shrink-0 transition-colors">
                            <X className="w-3.5 h-3.5" />
                          </button>
                        </div>
                      ) : (
                        <>
                          <p className="flex-1 text-sm text-gray-700 pt-0.5">{ind.indicator_text}</p>
                          <div className="flex items-center gap-1 flex-shrink-0">
                            <button onClick={() => startEdit(ind.id)} className="w-7 h-7 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-teal-600 flex items-center justify-center transition-colors" title="Edit">
                              <Pencil className="w-3.5 h-3.5" />
                            </button>
                            <button onClick={() => toggleActive(ind)} className={`w-7 h-7 rounded-lg flex items-center justify-center transition-colors ${ind.is_active !== false ? 'text-teal-500 hover:bg-teal-50' : 'text-gray-300 hover:bg-gray-100'}`} title={ind.is_active !== false ? 'Disable' : 'Enable'}>
                              {ind.is_active !== false ? <ToggleRight className="w-4 h-4" /> : <ToggleLeft className="w-4 h-4" />}
                            </button>
                            <button onClick={() => deleteIndicator(ind.id)} className="w-7 h-7 rounded-lg hover:bg-red-50 text-gray-300 hover:text-red-500 flex items-center justify-center transition-colors" title="Delete">
                              <Trash2 className="w-3.5 h-3.5" />
                            </button>
                          </div>
                        </>
                      )}
                    </div>
                  ))}

                  {catIndicators.length === 0 && (
                    <p className="text-xs text-gray-400 text-center py-2">No indicators yet — click Add to create one</p>
                  )}
                </div>
              )}
            </div>
          );
        })}

        {showNewCategory ? (
          <div className="bg-white rounded-2xl border-2 border-dashed border-teal-300 p-4">
            <p className="text-sm font-semibold text-gray-700 mb-2">New Category for {activeGrade}</p>
            <div className="flex gap-2">
              <input
                autoFocus
                type="text"
                value={newCategoryName}
                onChange={e => setNewCategoryName(e.target.value)}
                onKeyDown={e => { if (e.key === 'Enter') addNewCategory(); if (e.key === 'Escape') { setShowNewCategory(false); setNewCategoryName(''); } }}
                placeholder="e.g. Creative Arts, Science..."
                className="flex-1 h-10 px-3 rounded-xl border-2 border-gray-200 text-sm text-gray-800 focus:border-teal-400 focus:outline-none"
              />
              <button onClick={addNewCategory} disabled={saving || !newCategoryName.trim()} className="h-10 px-4 rounded-xl bg-teal-600 hover:bg-teal-700 disabled:bg-gray-200 text-white text-sm font-semibold transition-colors">
                Add
              </button>
              <button onClick={() => { setShowNewCategory(false); setNewCategoryName(''); }} className="h-10 w-10 rounded-xl border-2 border-gray-200 text-gray-500 hover:bg-gray-50 flex items-center justify-center transition-colors">
                <X className="w-4 h-4" />
              </button>
            </div>
          </div>
        ) : (
          <button
            onClick={() => setShowNewCategory(true)}
            className="w-full flex items-center justify-center gap-2 h-10 rounded-2xl border-2 border-dashed border-gray-300 text-gray-500 hover:border-teal-400 hover:text-teal-600 text-sm font-medium transition-colors"
          >
            <Plus className="w-4 h-4" /> Add New Category
          </button>
        )}
      </div>
    </div>
  );
}
