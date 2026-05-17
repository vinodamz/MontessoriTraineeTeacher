import { useState, useRef, useEffect } from 'react';
import { GraduationCap, Eye, EyeOff, Loader2 } from 'lucide-react';
import { supabase } from '../lib/supabase';
import { useAuth } from '../context/AuthContext';
import { Teacher } from '../types';

interface LoginPageProps {
  onLoginSuccess: () => void;
}

export default function LoginPage({ onLoginSuccess }: LoginPageProps) {
  const { login } = useAuth();
  const [teachers, setTeachers] = useState<Teacher[]>([]);
  const [selectedTeacher, setSelectedTeacher] = useState('');
  const [pin, setPin] = useState(['', '', '', '']);
  const [showPin, setShowPin] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [loadingTeachers, setLoadingTeachers] = useState(true);
  const pinRefs = [useRef<HTMLInputElement>(null), useRef<HTMLInputElement>(null), useRef<HTMLInputElement>(null), useRef<HTMLInputElement>(null)];

  useEffect(() => {
    supabase.from('teachers').select('*').then(({ data }) => {
      if (data) setTeachers(data);
      setLoadingTeachers(false);
    });
  }, []);

  const handlePinChange = (idx: number, val: string) => {
    if (!/^\d?$/.test(val)) return;
    const next = [...pin];
    next[idx] = val;
    setPin(next);
    setError('');
    if (val && idx < 3) {
      pinRefs[idx + 1].current?.focus();
    }
  };

  const handlePinKeyDown = (idx: number, e: React.KeyboardEvent) => {
    if (e.key === 'Backspace' && !pin[idx] && idx > 0) {
      pinRefs[idx - 1].current?.focus();
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const enteredPin = pin.join('');
    if (!selectedTeacher || enteredPin.length !== 4) {
      setError('Please select a teacher and enter your 4-digit PIN.');
      return;
    }

    setLoading(true);
    setError('');

    const teacher = teachers.find(t => t.id === selectedTeacher);
    if (!teacher || teacher.pin !== enteredPin) {
      setError('Incorrect PIN. Please try again.');
      setPin(['', '', '', '']);
      pinRefs[0].current?.focus();
      setLoading(false);
      return;
    }

    login(teacher);
    onLoginSuccess();
    setLoading(false);
  };

  const pinStr = pin.join('');

  return (
    <div className="min-h-screen bg-gradient-to-br from-teal-50 via-white to-amber-50 flex flex-col items-center justify-center p-4">
      <div className="w-full max-w-sm">
        <div className="text-center mb-8 animate-slide-up">
          <div className="inline-flex items-center justify-center w-20 h-20 bg-teal-600 rounded-2xl shadow-lg mb-4 rotate-3">
            <GraduationCap className="w-10 h-10 text-white" />
          </div>
          <h1 className="text-2xl font-800 text-gray-900 leading-tight font-bold">Little Graduates</h1>
          <p className="text-teal-600 font-semibold text-sm mt-1">Early Learning Centre</p>
          <p className="text-gray-500 text-xs mt-2">Assessment Portal</p>
        </div>

        <div className="bg-white rounded-3xl shadow-xl p-6 border border-gray-100 animate-scale-in">
          <h2 className="text-lg font-semibold text-gray-800 mb-5 text-center">Welcome Back</h2>

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1.5">Select Your Name</label>
              {loadingTeachers ? (
                <div className="h-12 bg-gray-100 rounded-xl animate-pulse" />
              ) : (
                <select
                  value={selectedTeacher}
                  onChange={e => { setSelectedTeacher(e.target.value); setError(''); }}
                  className="w-full h-12 px-4 rounded-xl border-2 border-gray-200 focus:border-teal-500 focus:outline-none text-gray-800 bg-white font-medium transition-colors"
                >
                  <option value="">Choose teacher...</option>
                  {teachers.map(t => (
                    <option key={t.id} value={t.id}>{t.name}</option>
                  ))}
                </select>
              )}
            </div>

            <div>
              <div className="flex items-center justify-between mb-1.5">
                <label className="text-sm font-medium text-gray-700">4-Digit PIN</label>
                <button
                  type="button"
                  onClick={() => setShowPin(!showPin)}
                  className="text-gray-400 hover:text-teal-600 transition-colors"
                >
                  {showPin ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>
              <div className="flex gap-3 justify-center">
                {pin.map((digit, idx) => (
                  <input
                    key={idx}
                    ref={pinRefs[idx]}
                    type={showPin ? 'text' : 'password'}
                    inputMode="numeric"
                    maxLength={1}
                    value={digit}
                    onChange={e => handlePinChange(idx, e.target.value)}
                    onKeyDown={e => handlePinKeyDown(idx, e)}
                    className={`w-14 h-14 text-center text-2xl font-bold rounded-xl border-2 transition-all focus:outline-none ${
                      digit
                        ? 'border-teal-500 bg-teal-50 text-teal-700'
                        : 'border-gray-200 bg-gray-50 text-gray-800'
                    } focus:border-teal-500 focus:bg-teal-50`}
                  />
                ))}
              </div>
            </div>

            {error && (
              <div className="bg-red-50 border border-red-200 rounded-xl px-4 py-3 animate-scale-in">
                <p className="text-red-700 text-sm font-medium">{error}</p>
              </div>
            )}

            <button
              type="submit"
              disabled={loading || !selectedTeacher || pinStr.length !== 4}
              className="w-full h-12 bg-teal-600 hover:bg-teal-700 disabled:bg-gray-300 disabled:cursor-not-allowed text-white font-semibold rounded-xl transition-all duration-200 flex items-center justify-center gap-2 shadow-md shadow-teal-200 active:scale-95"
            >
              {loading ? (
                <><Loader2 className="w-4 h-4 animate-spin" /> Signing in...</>
              ) : (
                'Sign In'
              )}
            </button>
          </form>
        </div>

        <p className="text-center text-xs text-gray-400 mt-6">
          Secure access for authorised staff only
        </p>
      </div>
    </div>
  );
}
