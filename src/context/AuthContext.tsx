import { createContext, useContext, useState, useMemo, ReactNode } from 'react';
import { SupabaseClient } from '@supabase/supabase-js';
import { Teacher } from '../types';
import { supabase, createTeacherClient } from '../lib/supabase';

interface AuthContextType {
  teacher: Teacher | null;
  db: SupabaseClient;
  login: (teacher: Teacher) => void;
  logout: () => void;
}

const AuthContext = createContext<AuthContextType | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [teacher, setTeacher] = useState<Teacher | null>(() => {
    const stored = sessionStorage.getItem('lg_teacher');
    return stored ? JSON.parse(stored) : null;
  });

  const db = useMemo<SupabaseClient>(() => {
    if (teacher?.id) return createTeacherClient(teacher.id);
    return supabase;
  }, [teacher?.id]);

  const login = (t: Teacher) => {
    setTeacher(t);
    sessionStorage.setItem('lg_teacher', JSON.stringify(t));
  };

  const logout = () => {
    setTeacher(null);
    sessionStorage.removeItem('lg_teacher');
  };

  return (
    <AuthContext.Provider value={{ teacher, db, login, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
