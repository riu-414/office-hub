import { useState, type FormEvent } from 'react'
import { isAxiosError } from 'axios'
import { useAuth } from './AuthContext'

export function LoginPage() {
  const { login } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  async function handleSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault() // SPA ではページを読み込み直さずに、裏で通信して結果だけ画面に反映したいので、この1行でブラウザ本来の動きを止めてる
    setError(null)
    setIsSubmitting(true)
    try {
      await login({ email, password })
    } catch (err) {
      setError(toErrorMessage(err))
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <main>
      <h1>office-hub</h1>
      <form onSubmit={handleSubmit}>
        <div>
          <label htmlFor="email">メールアドレス</label>
          <input
            id="email"
            type="email"
            autoComplete="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
        </div>
        <div>
          <label htmlFor="password">パスワード</label>
          <input
            id="password"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />
        </div>
        {error && <p role="alert">{error}</p>}
        <button type="submit" disabled={isSubmitting}>
          {isSubmitting ? 'ログイン中…' : 'ログイン'}
        </button>
      </form>
    </main>
  )
}

function toErrorMessage(err: unknown): string {
  if (isAxiosError(err)) {
    if (err.response?.status === 422) {
      return 'メールアドレスまたはパスワードが正しくありません。'
    }
    if (err.response?.status === 429) {
      return 'ログインの試行回数が多すぎます。1分ほど待ってから再度お試しください。'
    }
  }
  return 'ログインに失敗しました。時間をおいて再度お試しください。'
}