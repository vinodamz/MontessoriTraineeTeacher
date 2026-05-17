export interface Teacher {
  id: string;
  name: string;
  pin: string;
  role: 'teacher' | 'admin';
  created_at?: string;
}

export interface Student {
  id: string;
  first_name: string;
  last_name: string;
  grade: 'Playgroup' | 'Nursery' | 'LKG' | 'UKG';
  teacher_id: string;
  created_at?: string;
}

export interface SkillIndicator {
  id: string;
  grade: string;
  category: string;
  indicator_text: string;
  display_order: number;
  is_active?: boolean;
}

export interface StudentCustomIndicator {
  id: string;
  student_id: string;
  teacher_id: string;
  category: string;
  indicator_text: string;
  display_order: number;
  is_active: boolean;
  created_at?: string;
}

export interface Assessment {
  id: string;
  student_id: string;
  teacher_id: string;
  month_year: string;
  category: string;
  score: number;
  category_avg: number | null;
  submitted_at?: string;
}

export interface EvaluationCard {
  id: string;
  student_id: string;
  teacher_id: string;
  month_year: string;
  indicator_id: string;
  rating: string;
  submitted_at?: string;
}

export interface AssessmentComment {
  id: string;
  student_id: string;
  teacher_id: string;
  month_year: string;
  category: string | null;
  comment: string;
  created_at?: string;
}

export interface StudentBaseline {
  id: string;
  student_id: string;
  teacher_id: string;
  recorded_by: string;
  gross_motor: string;
  fine_motor: string;
  literacy: string;
  numeracy: string;
  social_skills: string;
  communication: string;
  overall_notes: string;
  recorded_at: string;
  created_at?: string;
}

export interface RatingConfig {
  id: string;
  code: string;
  label: string;
  color: string;
  numeric_value: number;
  display_order: number;
  is_active: boolean;
}

export type GradeColor = {
  bg: string;
  text: string;
  border: string;
  light: string;
};

export const GRADE_COLORS: Record<string, GradeColor> = {
  Playgroup: { bg: 'bg-amber-500', text: 'text-amber-800', border: 'border-amber-300', light: 'bg-amber-100' },
  Nursery: { bg: 'bg-emerald-500', text: 'text-emerald-800', border: 'border-emerald-300', light: 'bg-emerald-100' },
  LKG: { bg: 'bg-blue-500', text: 'text-blue-800', border: 'border-blue-300', light: 'bg-blue-100' },
  UKG: { bg: 'bg-violet-500', text: 'text-violet-800', border: 'border-violet-300', light: 'bg-violet-100' },
};

export const CATEGORIES = [
  'Gross Motor',
  'Fine Motor',
  'Literacy',
  'Numeracy',
  'Social Skills',
  'Communication',
];

export const MONTHS = [
  'Jun-25', 'Jul-25', 'Aug-25', 'Sep-25', 'Oct-25',
  'Nov-25', 'Dec-25', 'Jan-26', 'Feb-26', 'Mar-26',
];

export const SCORE_LABELS: Record<number, { label: string; color: string; bg: string }> = {
  1: { label: 'Needs Support', color: 'text-red-700', bg: 'bg-red-500' },
  2: { label: 'Emerging', color: 'text-orange-700', bg: 'bg-orange-500' },
  3: { label: 'Developing', color: 'text-yellow-700', bg: 'bg-yellow-500' },
  4: { label: 'Proficient', color: 'text-green-700', bg: 'bg-green-400' },
  5: { label: 'Mastered', color: 'text-green-800', bg: 'bg-green-600' },
};

export const CATEGORY_COLORS: Record<string, string> = {
  'Gross Motor': '#ef4444',
  'Fine Motor': '#f97316',
  'Literacy': '#3b82f6',
  'Numeracy': '#8b5cf6',
  'Social Skills': '#ec4899',
  'Communication': '#14b8a6',
};
