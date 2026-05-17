import { useState } from 'react';
import { AuthProvider, useAuth } from './context/AuthContext';
import LoginPage from './pages/LoginPage';
import TeacherDashboard from './pages/TeacherDashboard';
import AdminDashboard from './pages/AdminDashboard';
import { ToastContainer, useToast } from './components/Toast';

function AppInner() {
  const { teacher, logout } = useAuth();
  const { toasts, addToast, removeToast } = useToast();
  const [loggedIn, setLoggedIn] = useState(!!teacher);

  const handleLoginSuccess = () => setLoggedIn(true);
  const handleLogout = () => { logout(); setLoggedIn(false); };

  if (!loggedIn || !teacher) {
    return (
      <>
        <LoginPage onLoginSuccess={handleLoginSuccess} />
        <ToastContainer toasts={toasts} onRemove={removeToast} />
      </>
    );
  }

  return (
    <>
      {teacher.role === 'admin' ? (
        <AdminDashboard onLogout={handleLogout} />
      ) : (
        <TeacherDashboard onLogout={handleLogout} addToast={addToast} />
      )}
      <ToastContainer toasts={toasts} onRemove={removeToast} />
    </>
  );
}

export default function App() {
  return (
    <AuthProvider>
      <AppInner />
    </AuthProvider>
  );
}
