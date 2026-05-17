import { useState, useEffect } from 'react';
import { LogOut, GraduationCap, Users, ClipboardCheck, Download, Filter, Settings, UserCog, UserRound, BarChart2, MessageSquare, Database, Activity } from 'lucide-react';
import { Student, Teacher, Assessment, GRADE_COLORS, MONTHS } from '../types';
import { useAuth } from '../context/AuthContext';
import { StatCardSkeleton } from '../components/LoadingSkeleton';
import RatingConfigSettings from '../components/RatingConfigSettings';
import SkillsConfigSettings from '../components/SkillsConfigSettings';
import StudentManagement from '../components/StudentManagement';
import TeacherManagement from '../components/TeacherManagement';
import AssessmentsAdmin from '../components/admin/AssessmentsAdmin';
import EvaluationCardsAdmin from '../components/admin/EvaluationCardsAdmin';
import BaselineAdmin from '../components/admin/BaselineAdmin';
import CommentsAdmin from '../components/admin/CommentsAdmin';
import StudentProgress from './StudentProgress';

interface Props {
  onLogout: () => void;
}

type Tab =
  | 'students'
  | 'classes'
  | 'grades'
  | 'manage-students'
  | 'manage-teachers'
  | 'data-assessments'
  | 'data-eval-cards'
  | 'data-baselines'
  | 'data-comments'
  | 'skills'
  | 'settings';

type TabGroup = 'analytics' | 'manage' | 'data' | 'config';

const TAB_GROUPS: { id: TabGroup; label: string; icon: React.ReactNode }[] = [
  { id: 'analytics', label: 'Analytics', icon: <BarChart2 className="w-4 h-4" /> },
  { id: 'manage', label: 'Manage', icon: <Users className="w-4 h-4" /> },
  { id: 'data', label: 'All Data', icon: <Database className="w-4 h-4" /> },
  { id: 'config', label: 'Config', icon: <Settings className="w-4 h-4" /> },
];

const GROUP_TABS: Record<TabGroup, { id: Tab; label: string; icon?: React.ReactNode }[]> = {
  analytics: [
    { id: 'students', label: 'Student Progress' },
    { id: 'classes', label: 'Class Summary' },
    { id: 'grades', label: 'Grade Overview' },
  ],
  manage: [
    { id: 'manage-students', label: 'Students', icon: <UserRound className="w-3.5 h-3.5" /> },
    { id: 'manage-teachers', label: 'Teachers', icon: <UserCog className="w-3.5 h-3.5" /> },
  ],
  data: [
    { id: 'data-assessments', label: 'Assessments', icon: <Activity className="w-3.5 h-3.5" /> },
    { id: 'data-eval-cards', label: 'Eval Cards', icon: <ClipboardCheck className="w-3.5 h-3.5" /> },
    { id: 'data-baselines', label: 'Baselines', icon: <Users className="w-3.5 h-3.5" /> },
    { id: 'data-comments', label: 'Comments', icon: <MessageSquare className="w-3.5 h-3.5" /> },
  ],
  config: [
    { id: 'skills', label: 'Skills' },
    { id: 'settings', label: 'Rating Config', icon: <Settings className="w-3.5 h-3.5" /> },
  ],
};

function getGroupForTab(tab: Tab): TabGroup {
  for (const [group, tabs] of Object.entries(GROUP_TABS)) {
    if (tabs.some(t => t.id === tab)) return group as TabGroup;
  }
  return 'analytics';
}

export default function AdminDashboard({ onLogout }: Props) {
  const { teacher: admin, db } = useAuth();
  const [students, setStudents] = useState<Student[]>([]);
  const [teachers, setTeachers] = useState<Teacher[]>([]);
  const [assessments, setAssessments] = useState<Assessment[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterTeacher, setFilterTeacher] = useState('');
  const [filterGrade, setFilterGrade] = useState('');
  const [filterMonth, setFilterMonth] = useState('');
  const [activeTab, setActiveTab] = useState<Tab>('students');
  const [activeGroup, setActiveGroup] = useState<TabGroup>('analytics');
  const [toastMsg, setToastMsg] = useState<{ type: 'success' | 'error'; msg: string } | null>(null);
  const [viewingStudent, setViewingStudent] = useState<Student | null>(null);

  const showToast = (type: 'success' | 'error', msg: string) => {
    setToastMsg({ type, msg });
    setTimeout(() => setToastMsg(null), 3000);
  };

  const currentMonthYear = (() => {
    const d = new Date();
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return `${months[d.getMonth()]}-${String(d.getFullYear()).slice(2)}`;
  })();

  useEffect(() => {
    Promise.all([
      db.from('students').select('*'),
      db.from('teachers').select('*').neq('role', 'admin'),
      db.from('assessments').select('*'),
    ]).then(([{ data: s }, { data: t }, { data: a }]) => {
      setStudents(s || []);
      setTeachers(t || []);
      setAssessments(a || []);
      setLoading(false);
    });
  }, [db]);

  const handleGroupChange = (group: TabGroup) => {
    setActiveGroup(group);
    setActiveTab(GROUP_TABS[group][0].id);
  };

  const handleTabChange = (tab: Tab) => {
    setActiveTab(tab);
    setActiveGroup(getGroupForTab(tab));
  };

  const filteredAssessments = assessments.filter(a => {
    const student = students.find(s => s.id === a.student_id);
    if (!student) return false;
    if (filterTeacher && student.teacher_id !== filterTeacher) return false;
    if (filterGrade && student.grade !== filterGrade) return false;
    if (filterMonth && a.month_year !== filterMonth) return false;
    return true;
  });

  const thisMonthAssessments = assessments.filter(a => a.month_year === currentMonthYear);
  const assessedStudentIds = new Set(thisMonthAssessments.map(a => a.student_id));

  const teachersNotAssessedThisMonth = teachers.filter(t => {
    const teacherStudents = students.filter(s => s.teacher_id === t.id);
    return teacherStudents.length > 0 && !teacherStudents.some(s => assessedStudentIds.has(s.id));
  }).length;

  const hasStudentAssessment = (studentId: string, month: string) =>
    assessments.some(a => a.student_id === studentId && a.month_year === month);

  const getFilteredStudents = () =>
    students.filter(s => {
      if (filterTeacher && s.teacher_id !== filterTeacher) return false;
      if (filterGrade && s.grade !== filterGrade) return false;
      return true;
    });

  const getDisplayMonths = () => {
    if (filterMonth) return [filterMonth];
    const months = [...new Set(filteredAssessments.map(a => a.month_year))];
    return months.sort((a, b) => {
      const mo: Record<string, number> = { Jun: 0, Jul: 1, Aug: 2, Sep: 3, Oct: 4, Nov: 5, Dec: 6, Jan: 7, Feb: 8, Mar: 9 };
      const [am, ay] = a.split('-'), [bm, by] = b.split('-');
      const ayn = parseInt(ay), byn = parseInt(by);
      if (ayn !== byn) return ayn - byn;
      return (mo[am] ?? 0) - (mo[bm] ?? 0);
    });
  };

  const assessedCell = (assessed: boolean) => (
    <td className="px-3 py-2.5 text-center">
      {assessed
        ? <span className="inline-block px-2 py-0.5 rounded-lg text-xs font-bold bg-emerald-100 text-emerald-800">Done</span>
        : <span className="text-gray-300 text-sm">—</span>
      }
    </td>
  );

  const exportCSV = () => {
    const displayStudents = getFilteredStudents();
    const displayMonths = getDisplayMonths();
    const rows: string[][] = [];
    rows.push(['Student', 'Grade', 'Teacher', ...displayMonths.map(m => `${m} Assessed`)]);
    displayStudents.forEach(s => {
      const t = teachers.find(t => t.id === s.teacher_id);
      const row = [
        `${s.first_name} ${s.last_name}`,
        s.grade,
        t?.name ?? '',
        ...displayMonths.map(m => hasStudentAssessment(s.id, m) ? 'Yes' : ''),
      ];
      rows.push(row);
    });
    const csv = rows.map(r => r.map(v => `"${v}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'assessments.csv';
    a.click();
  };

  const isAnalyticsTab = activeGroup === 'analytics';
  const currentGroupTabs = GROUP_TABS[activeGroup];

  if (viewingStudent) {
    const teacher = teachers.find(t => t.id === viewingStudent.teacher_id);
    return (
      <StudentProgress
        student={viewingStudent}
        teacherName={teacher?.name ?? ''}
        onBack={() => setViewingStudent(null)}
        onOpenEvalCard={() => {}}
      />
    );
  }

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Header */}
      <div className="bg-white border-b border-gray-200 sticky top-0 z-10 shadow-sm">
        <div className="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 bg-teal-600 rounded-xl flex items-center justify-center">
              <GraduationCap className="w-5 h-5 text-white" />
            </div>
            <div>
              <p className="text-xs text-gray-500 leading-none">Little Graduates</p>
              <p className="font-bold text-gray-900 leading-tight">Admin Dashboard</p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <span className="hidden sm:block text-xs text-gray-500 bg-gray-100 px-3 py-1.5 rounded-full font-medium">
              {admin?.name}
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

      <div className="max-w-7xl mx-auto px-4 py-5 space-y-5">
        {/* Stat cards */}
        {loading ? (
          <div className="grid grid-cols-3 gap-3">
            {[1,2,3].map(i => <StatCardSkeleton key={i} />)}
          </div>
        ) : (
          <div className="grid grid-cols-3 gap-3">
            <div className="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 bg-teal-100 rounded-xl flex items-center justify-center">
                  <Users className="w-5 h-5 text-teal-700" />
                </div>
                <div>
                  <p className="text-xs text-gray-500">Total Students</p>
                  <p className="text-2xl font-bold text-gray-900">{students.length}</p>
                </div>
              </div>
            </div>
            <div className="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 bg-amber-100 rounded-xl flex items-center justify-center">
                  <ClipboardCheck className="w-5 h-5 text-amber-700" />
                </div>
                <div>
                  <p className="text-xs text-gray-500">Assessed This Month</p>
                  <p className="text-2xl font-bold text-gray-900">{assessedStudentIds.size}</p>
                </div>
              </div>
            </div>
            <div className="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 bg-orange-100 rounded-xl flex items-center justify-center">
                  <ClipboardCheck className="w-5 h-5 text-orange-600" />
                </div>
                <div>
                  <p className="text-xs text-gray-500">Classes Not Assessed</p>
                  <p className="text-2xl font-bold text-gray-900">{teachersNotAssessedThisMonth}</p>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Filters for analytics */}
        {isAnalyticsTab && (
          <div className="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
            <div className="flex items-center gap-2 mb-3">
              <Filter className="w-4 h-4 text-gray-500" />
              <span className="text-sm font-semibold text-gray-700">Filters</span>
            </div>
            <div className="flex flex-wrap gap-2">
              <select
                value={filterTeacher}
                onChange={e => setFilterTeacher(e.target.value)}
                className="h-9 px-3 rounded-xl border-2 border-gray-200 text-sm font-medium text-gray-700 focus:border-teal-400 focus:outline-none bg-white"
              >
                <option value="">All Teachers</option>
                {teachers.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
              </select>
              <select
                value={filterGrade}
                onChange={e => setFilterGrade(e.target.value)}
                className="h-9 px-3 rounded-xl border-2 border-gray-200 text-sm font-medium text-gray-700 focus:border-teal-400 focus:outline-none bg-white"
              >
                <option value="">All Grades</option>
                {['Playgroup','Nursery','LKG','UKG'].map(g => <option key={g}>{g}</option>)}
              </select>
              <select
                value={filterMonth}
                onChange={e => setFilterMonth(e.target.value)}
                className="h-9 px-3 rounded-xl border-2 border-gray-200 text-sm font-medium text-gray-700 focus:border-teal-400 focus:outline-none bg-white"
              >
                <option value="">All Months</option>
                {MONTHS.map(m => <option key={m}>{m}</option>)}
              </select>
              <button
                onClick={exportCSV}
                className="flex items-center gap-1.5 h-9 px-4 rounded-xl bg-teal-600 hover:bg-teal-700 text-white text-sm font-semibold transition-colors ml-auto"
              >
                <Download className="w-3.5 h-3.5" /> Export CSV
              </button>
            </div>
          </div>
        )}

        {/* Group nav + tabs */}
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
          {/* Group pills */}
          <div className="flex gap-1 p-3 bg-gray-50 border-b border-gray-100 overflow-x-auto">
            {TAB_GROUPS.map(group => (
              <button
                key={group.id}
                onClick={() => handleGroupChange(group.id)}
                className={`flex items-center gap-1.5 px-4 py-2 rounded-xl text-sm font-semibold transition-all flex-shrink-0 ${
                  activeGroup === group.id
                    ? 'bg-teal-600 text-white shadow-sm'
                    : 'text-gray-600 hover:bg-white hover:shadow-sm'
                }`}
              >
                {group.icon}
                {group.label}
              </button>
            ))}
          </div>

          {/* Sub-tabs */}
          <div className="flex border-b border-gray-100 overflow-x-auto">
            {currentGroupTabs.map(tab => (
              <button
                key={tab.id}
                onClick={() => handleTabChange(tab.id)}
                className={`flex-shrink-0 flex items-center gap-1.5 px-4 py-3 text-sm font-semibold transition-colors ${
                  activeTab === tab.id
                    ? 'text-teal-700 border-b-2 border-teal-600 bg-teal-50'
                    : 'text-gray-500 hover:text-gray-700'
                }`}
              >
                {tab.icon}
                {tab.label}
              </button>
            ))}
          </div>

          {/* Tab content */}
          {activeTab === 'students' && (
            <div className="overflow-x-auto">
              {getFilteredStudents().length === 0 ? (
                <div className="p-12 text-center">
                  <p className="text-gray-500">No students match the current filters</p>
                </div>
              ) : (
                <table className="w-full text-sm">
                  <thead>
                    <tr className="bg-gray-50">
                      <th className="px-4 py-3 text-left font-semibold text-gray-600 text-xs sticky left-0 bg-gray-50">
                        Student <span className="font-normal text-gray-400">(click to view progress)</span>
                      </th>
                      <th className="px-3 py-3 text-left font-semibold text-gray-600 text-xs">Grade</th>
                      <th className="px-3 py-3 text-left font-semibold text-gray-600 text-xs">Teacher</th>
                      {getDisplayMonths().slice(-3).map(m => (
                        <th key={m} className="px-3 py-3 text-center font-semibold text-gray-600 text-xs">{m}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-50">
                    {getFilteredStudents().map(s => {
                      const t = teachers.find(t => t.id === s.teacher_id);
                      const gradeColor = GRADE_COLORS[s.grade];
                      return (
                        <tr
                          key={s.id}
                          onClick={() => setViewingStudent(s)}
                          className="hover:bg-teal-50 transition-colors cursor-pointer group"
                        >
                          <td className="px-4 py-3 font-semibold text-gray-800 text-sm sticky left-0 bg-white group-hover:bg-teal-50 transition-colors">
                            <span className="group-hover:text-teal-700 transition-colors">{s.first_name} {s.last_name}</span>
                          </td>
                          <td className="px-3 py-3">
                            <span className={`px-2 py-0.5 rounded-full text-xs font-bold ${gradeColor.light} ${gradeColor.text}`}>
                              {s.grade}
                            </span>
                          </td>
                          <td className="px-3 py-3 text-gray-600 text-xs">{t?.name}</td>
                          {getDisplayMonths().slice(-3).map(m => assessedCell(hasStudentAssessment(s.id, m)))}
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              )}
            </div>
          )}

          {activeTab === 'classes' && (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="bg-gray-50">
                    <th className="px-4 py-3 text-left font-semibold text-gray-600 text-xs">Teacher</th>
                    {getDisplayMonths().map(m => (
                      <th key={m} className="px-3 py-3 text-center font-semibold text-gray-600 text-xs border-l border-gray-100">
                        {m}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {teachers.map(t => {
                    const teacherStudents = students.filter(s =>
                      s.teacher_id === t.id && (!filterGrade || s.grade === filterGrade)
                    );
                    if (filterTeacher && t.id !== filterTeacher) return null;
                    return (
                      <tr key={t.id} className="hover:bg-gray-50 transition-colors">
                        <td className="px-4 py-3 font-semibold text-gray-800">{t.name}</td>
                        {getDisplayMonths().map(m => {
                          const monthAsmts = assessments.filter(a =>
                            teacherStudents.some(s => s.id === a.student_id) && a.month_year === m
                          );
                          const uniqueStudents = new Set(monthAsmts.map(a => a.student_id)).size;
                          const totalStudents = teacherStudents.length;
                          return (
                            <td key={m} className="px-3 py-2.5 text-center border-l border-gray-100">
                              {uniqueStudents > 0
                                ? <span className={`inline-block px-2 py-0.5 rounded-lg text-xs font-bold ${uniqueStudents === totalStudents ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'}`}>
                                    {uniqueStudents}/{totalStudents}
                                  </span>
                                : <span className="text-gray-300 text-sm">—</span>
                              }
                            </td>
                          );
                        })}
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}

          {activeTab === 'grades' && (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="bg-gray-50">
                    <th className="px-4 py-3 text-left font-semibold text-gray-600 text-xs">Grade</th>
                    {getDisplayMonths().map(m => (
                      <th key={m} className="px-3 py-3 text-center font-semibold text-gray-600 text-xs">{m}</th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {['Playgroup','Nursery','LKG','UKG'].map(grade => {
                    if (filterGrade && grade !== filterGrade) return null;
                    const gradeStudents = students.filter(s => s.grade === grade &&
                      (!filterTeacher || s.teacher_id === filterTeacher)
                    );
                    const gradeColor = GRADE_COLORS[grade];
                    return (
                      <tr key={grade} className="hover:bg-gray-50 transition-colors">
                        <td className="px-4 py-3">
                          <span className={`px-3 py-1 rounded-full text-xs font-bold ${gradeColor.light} ${gradeColor.text}`}>
                            {grade}
                          </span>
                        </td>
                        {getDisplayMonths().map(m => {
                          const assessedInMonth = gradeStudents.filter(s => hasStudentAssessment(s.id, m)).length;
                          const total = gradeStudents.length;
                          return (
                            <td key={m} className="px-3 py-2.5 text-center">
                              {assessedInMonth > 0
                                ? <span className={`inline-block px-2 py-0.5 rounded-lg text-xs font-bold ${assessedInMonth === total ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'}`}>
                                    {assessedInMonth}/{total}
                                  </span>
                                : <span className="text-gray-300 text-sm">—</span>
                              }
                            </td>
                          );
                        })}
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}

          {activeTab === 'manage-students' && (
            <StudentManagement
              students={students}
              teachers={teachers}
              onStudentsChange={setStudents}
              onToast={showToast}
            />
          )}

          {activeTab === 'manage-teachers' && (
            <TeacherManagement
              teachers={teachers}
              students={students}
              onTeachersChange={setTeachers}
              onToast={showToast}
            />
          )}

          {activeTab === 'data-assessments' && (
            <AssessmentsAdmin
              students={students}
              teachers={teachers}
              onToast={showToast}
            />
          )}

          {activeTab === 'data-eval-cards' && (
            <EvaluationCardsAdmin
              students={students}
              teachers={teachers}
              onToast={showToast}
            />
          )}

          {activeTab === 'data-baselines' && (
            <BaselineAdmin
              students={students}
              teachers={teachers}
              onToast={showToast}
            />
          )}

          {activeTab === 'data-comments' && (
            <CommentsAdmin
              students={students}
              teachers={teachers}
              onToast={showToast}
            />
          )}

          {activeTab === 'skills' && (
            <SkillsConfigSettings onToast={showToast} />
          )}

          {activeTab === 'settings' && (
            <RatingConfigSettings onToast={showToast} />
          )}
        </div>

        {!loading && isAnalyticsTab && getDisplayMonths().length === 0 && (
          <div className="bg-white rounded-2xl p-12 text-center shadow-sm border border-gray-100">
            <p className="text-gray-600 font-semibold">No assessment data available</p>
            <p className="text-gray-400 text-sm mt-1">Assessments will appear here once teachers start submitting them</p>
          </div>
        )}
      </div>

      {toastMsg && (
        <div className={`fixed bottom-4 right-4 z-50 px-4 py-3 rounded-xl shadow-lg text-sm font-medium text-white transition-all ${
          toastMsg.type === 'success' ? 'bg-emerald-600' : 'bg-red-600'
        }`}>
          {toastMsg.msg}
        </div>
      )}
    </div>
  );
}
