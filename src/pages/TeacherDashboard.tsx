import { useState, useEffect } from 'react';
import { Plus, BarChart2, ClipboardList, LogOut, GraduationCap, BookOpen } from 'lucide-react';
import { Student, StudentBaseline, GRADE_COLORS } from '../types';
import { useAuth } from '../context/AuthContext';
import AssessmentModal from '../components/AssessmentModal';
import BaselineModal from '../components/BaselineModal';
import EvaluationCard from '../components/EvaluationCard';
import StudentProgress from './StudentProgress';
import { StudentCardSkeleton } from '../components/LoadingSkeleton';

interface Props {
  onLogout: () => void;
  addToast: (type: 'success' | 'error' | 'warning', msg: string) => void;
}

type View = 'dashboard' | 'progress';

export default function TeacherDashboard({ onLogout, addToast }: Props) {
  const { teacher, db } = useAuth();
  const [students, setStudents] = useState<Student[]>([]);
  const [lastAssessments, setLastAssessments] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(true);
  const [showAssessmentModal, setShowAssessmentModal] = useState(false);
  const [showEvalCard, setShowEvalCard] = useState<Student | null>(null);
  const [showBaselineModal, setShowBaselineModal] = useState<Student | null>(null);
  const [view, setView] = useState<View>('dashboard');
  const [selectedStudent, setSelectedStudent] = useState<Student | null>(null);
  const [assessStudentId, setAssessStudentId] = useState<string | undefined>();
  const [baselines, setBaselines] = useState<Record<string, StudentBaseline>>({});

  const currentMonthYear = (() => {
    const d = new Date();
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return `${months[d.getMonth()]}-${String(d.getFullYear()).slice(2)}`;
  })();

  useEffect(() => {
    if (!teacher) return;
    Promise.all([
      db.from('students').select('*').eq('teacher_id', teacher.id).order('first_name'),
      db
        .from('evaluation_cards')
        .select('student_id, month_year')
        .eq('teacher_id', teacher.id),
      db
        .from('student_baselines')
        .select('*'),
    ]).then(([{ data: studs }, { data: evals }, { data: blines }]) => {
      const loadedStudents = studs || [];
      setStudents(loadedStudents);
      const monthMap: Record<string, string> = {};
      const monthOrder: Record<string, number> = {
        Jun: 0, Jul: 1, Aug: 2, Sep: 3, Oct: 4, Nov: 5, Dec: 6, Jan: 7, Feb: 8, Mar: 9,
      };
      for (const ev of evals || []) {
        const current = monthMap[ev.student_id];
        if (!current) {
          monthMap[ev.student_id] = ev.month_year;
        } else {
          const [cm, cy] = current.split('-');
          const [nm, ny] = ev.month_year.split('-');
          const cyN = parseInt(cy), nyN = parseInt(ny);
          if (nyN > cyN || (nyN === cyN && (monthOrder[nm] ?? 0) > (monthOrder[cm] ?? 0))) {
            monthMap[ev.student_id] = ev.month_year;
          }
        }
      }
      setLastAssessments(monthMap);
      const blineMap: Record<string, StudentBaseline> = {};
      for (const b of blines || []) blineMap[b.student_id] = b;
      setBaselines(blineMap);
      setLoading(false);
    });
  }, [teacher, db]);

  const refreshAssessments = () => {
    if (!teacher) return;
    db
      .from('evaluation_cards')
      .select('student_id, month_year')
      .eq('teacher_id', teacher.id)
      .then(({ data }) => {
        const monthMap: Record<string, string> = {};
        const monthOrder: Record<string, number> = {
          Jun: 0, Jul: 1, Aug: 2, Sep: 3, Oct: 4, Nov: 5, Dec: 6, Jan: 7, Feb: 8, Mar: 9,
        };
        for (const ev of data || []) {
          const current = monthMap[ev.student_id];
          if (!current) {
            monthMap[ev.student_id] = ev.month_year;
          } else {
            const [cm, cy] = current.split('-');
            const [nm, ny] = ev.month_year.split('-');
            const cyN = parseInt(cy), nyN = parseInt(ny);
            if (nyN > cyN || (nyN === cyN && (monthOrder[nm] ?? 0) > (monthOrder[cm] ?? 0))) {
              monthMap[ev.student_id] = ev.month_year;
            }
          }
        }
        setLastAssessments(monthMap);
      });
  };

  if (view === 'progress' && selectedStudent) {
    return (
      <StudentProgress
        student={selectedStudent}
        teacherName={teacher?.name ?? ''}
        onBack={() => { setView('dashboard'); setSelectedStudent(null); }}
        onOpenEvalCard={(student) => setShowEvalCard(student)}
      />
    );
  }

  return (
    <div className="min-h-screen bg-gray-50">
      <div className="bg-white border-b border-gray-200 sticky top-0 z-10 shadow-sm">
        <div className="max-w-4xl mx-auto px-4 py-3 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 bg-teal-600 rounded-xl flex items-center justify-center">
              <GraduationCap className="w-5 h-5 text-white" />
            </div>
            <div>
              <p className="text-xs text-gray-500 leading-none">Little Graduates</p>
              <p className="font-bold text-gray-900 leading-tight">Welcome, {teacher?.name}</p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <span className="hidden sm:block text-xs font-medium text-teal-700 bg-teal-50 px-3 py-1 rounded-full border border-teal-100">
              {currentMonthYear}
            </span>
            <button
              onClick={onLogout}
              className="w-9 h-9 flex items-center justify-center rounded-xl hover:bg-gray-100 transition-colors text-gray-500"
            >
              <LogOut className="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>

      <div className="max-w-4xl mx-auto px-4 py-5">
        <div className="flex items-center justify-between mb-4">
          <div>
            <h2 className="font-bold text-gray-800">My Students</h2>
            <p className="text-xs text-gray-500">{students.length} students</p>
          </div>
          <button
            onClick={() => { setAssessStudentId(undefined); setShowAssessmentModal(true); }}
            className="flex items-center gap-2 px-4 h-10 bg-teal-600 hover:bg-teal-700 text-white font-semibold rounded-xl text-sm transition-colors shadow-md shadow-teal-200 active:scale-95"
          >
            <Plus className="w-4 h-4" /> New Assessment
          </button>
        </div>

        {loading ? (
          <div className="grid grid-cols-2 gap-3">
            {Array.from({ length: 6 }).map((_, i) => <StudentCardSkeleton key={i} />)}
          </div>
        ) : students.length === 0 ? (
          <div className="bg-white rounded-2xl p-12 text-center shadow-sm border border-gray-100">
            <p className="text-4xl mb-3">👶</p>
            <p className="text-gray-600 font-semibold">No students assigned yet</p>
          </div>
        ) : (
          <div className="grid grid-cols-2 gap-3">
            {students.map(student => {
              const gradeColor = GRADE_COLORS[student.grade];
              const lastMonth = lastAssessments[student.id];
              const hasThisMonth = lastMonth === currentMonthYear;
              const hasBaseline = !!baselines[student.id];
              return (
                <div
                  key={student.id}
                  className="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 hover:shadow-md hover:border-teal-100 transition-all duration-200 animate-fade-in"
                >
                  <div className="flex items-start justify-between mb-2">
                    <div className="flex-1 min-w-0">
                      <p className="font-bold text-gray-900 text-sm leading-tight truncate">
                        {student.first_name}
                      </p>
                      <p className="text-xs text-gray-500 truncate">{student.last_name}</p>
                    </div>
                    <span className={`flex-shrink-0 ml-1 px-2 py-0.5 rounded-full text-xs font-bold ${gradeColor.light} ${gradeColor.text}`}>
                      {student.grade === 'Playgroup' ? 'PG' : student.grade}
                    </span>
                  </div>

                  <div className="mb-3 space-y-1">
                    {lastMonth ? (
                      <div className="flex items-center gap-1.5">
                        <div className={`w-2 h-2 rounded-full ${hasThisMonth ? 'bg-green-500' : 'bg-amber-400'}`} />
                        <p className="text-xs text-gray-500">
                          {hasThisMonth ? 'Done: ' : 'Last: '}{lastMonth}
                        </p>
                      </div>
                    ) : (
                      <div className="flex items-center gap-1.5">
                        <div className="w-2 h-2 rounded-full bg-gray-300" />
                        <p className="text-xs text-gray-400">No assessments yet</p>
                      </div>
                    )}
                    <div className="flex items-center gap-1.5">
                      <div className={`w-2 h-2 rounded-full ${hasBaseline ? 'bg-orange-400' : 'bg-gray-200'}`} />
                      <p className={`text-xs ${hasBaseline ? 'text-orange-600 font-medium' : 'text-gray-400'}`}>
                        {hasBaseline ? 'Baseline recorded' : 'No baseline yet'}
                      </p>
                    </div>
                  </div>

                  <div className="flex flex-col gap-1.5">
                    <button
                      onClick={() => { setSelectedStudent(student); setView('progress'); }}
                      className="flex items-center justify-center gap-1.5 h-8 w-full rounded-xl bg-teal-50 hover:bg-teal-100 text-teal-700 text-xs font-semibold transition-colors"
                    >
                      <BarChart2 className="w-3.5 h-3.5" /> View Progress
                    </button>
                    <button
                      onClick={() => { setAssessStudentId(student.id); setShowAssessmentModal(true); }}
                      className="flex items-center justify-center gap-1.5 h-8 w-full rounded-xl bg-amber-50 hover:bg-amber-100 text-amber-700 text-xs font-semibold transition-colors"
                    >
                      <ClipboardList className="w-3.5 h-3.5" /> Assess
                    </button>
                    <button
                      onClick={() => setShowBaselineModal(student)}
                      className={`flex items-center justify-center gap-1.5 h-8 w-full rounded-xl text-xs font-semibold transition-colors ${
                        hasBaseline
                          ? 'bg-orange-50 hover:bg-orange-100 text-orange-600'
                          : 'bg-gray-50 hover:bg-orange-50 text-gray-500 hover:text-orange-600 border border-dashed border-gray-300 hover:border-orange-300'
                      }`}
                    >
                      <BookOpen className="w-3.5 h-3.5" />
                      {hasBaseline ? 'Edit Baseline' : 'Add Baseline'}
                    </button>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>

      {showAssessmentModal && (
        <AssessmentModal
          students={students}
          preselectedStudentId={assessStudentId}
          onClose={() => { setShowAssessmentModal(false); setAssessStudentId(undefined); }}
          onSuccess={(msg) => { addToast('success', msg); refreshAssessments(); }}
          onError={(msg) => addToast('error', msg)}
        />
      )}

      {showEvalCard && (
        <EvaluationCard
          student={showEvalCard}
          teacherName={teacher?.name ?? ''}
          onClose={() => setShowEvalCard(null)}
          onSuccess={(msg) => addToast('success', msg)}
          onError={(msg) => addToast('error', msg)}
        />
      )}

      {showBaselineModal && (
        <BaselineModal
          student={showBaselineModal}
          onClose={() => setShowBaselineModal(null)}
          onSuccess={(msg) => {
            addToast('success', msg);
            db.from('student_baselines').select('*').then(({ data }) => {
              const blineMap: Record<string, StudentBaseline> = {};
              for (const b of data || []) blineMap[b.student_id] = b;
              setBaselines(blineMap);
            });
            setShowBaselineModal(null);
          }}
          onError={(msg) => addToast('error', msg)}
        />
      )}
    </div>
  );
}
