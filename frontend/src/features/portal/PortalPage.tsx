import { Link } from 'react-router'
import { useAuth } from '../auth/AuthContext'

export function PortalPage() {
  const { user } = useAuth()
  const systems = user?.systems ?? []

  return (
    <>
      <h1 className="text-2xl font-bold tracking-tight">ポータル</h1>
      {systems.length === 0 ? (
        <p className="mt-6 rounded-xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center text-sm text-slate-600">
          利用できるシステムがありません。管理者にお問い合わせください。
        </p>
      ) : (
        <ul className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {systems.map((system) => (
            <li key={system.key}>
              <Link
                to={`/${system.key}`}
                className="block rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-indigo-300 hover:shadow-md"
              >
                <span className="block font-semibold">{system.name}</span>
                <span className="mt-3 inline-block rounded-full bg-slate-100 px-2.5 py-0.5 text-xs text-slate-600">
                  (権限：{system.role})
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </>
  )
}