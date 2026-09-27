import { Navigate, Outlet } from 'react-router'
import { useAuth } from '../features/auth/AuthContext'

export function RequireAuth() {
  const { user, isLoading } = useAuth()

  if (isLoading) {
    return <p className="p-8 text-center text-sm text-slate-500">読み込み中…</p>
  }

  if (user === null) {
    return <Navigate to="/login" replace />
  }

  return <Outlet />
}