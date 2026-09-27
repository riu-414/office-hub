import { Navigate, Outlet } from 'react-router'
import { useAuth } from '../features/auth/AuthContext'

export function GuestOnly() {
  const { user, isLoading } = useAuth()

  if (isLoading) {
    return <p>読み込み中…</p>
  }

  if (user !== null) {
    return <Navigate to="/" replace />
  }

  return <Outlet />
}