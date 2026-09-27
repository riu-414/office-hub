import { Link, Outlet } from 'react-router'
import { useAuth } from '../../features/auth/AuthContext'

export function AppLayout() {
  const { user, logout } = useAuth()

  return (
    <>
      <header>
        <Link to="/">office-hub</Link>
        <span>{user?.name}</span>
        <button type="button" onClick={logout}>
          ログアウト
        </button>
      </header>
      <main>
        <Outlet />
      </main>
    </>
  )
}