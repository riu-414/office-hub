import { Link } from 'react-router'
import { useAuth } from '../auth/AuthContext'

export function PortalPage() {
  const { user } = useAuth()
  const systems = user?.systems ?? []

  return (
    <>
      <h1>ポータル</h1>
      {systems.length === 0 ? (
        <p>利用できるシステムがありません。管理者にお問い合わせください。</p>
      ) : (
        <ul>
          {systems.map((system) => (
            <li key={system.key}>
              <Link to={`/${system.key}`}>{system.name}</Link>
              <span>(権限：{system.role})</span>
            </li>
          ))}
        </ul>
      )}
    </>
  )
}