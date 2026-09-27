import { useEffect, useState, type ReactNode } from "react";
import * as authApi from './api'  // api.ts の関数を authApi.login のようにまとめて読み込む書き方
import { AuthContext } from "./AuthContext";
import type { LoginInput, User } from "./types";

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  useEffect(() => {
    authApi
      .fetchMe()
      .then((me) => setUser(me))          // 成功 → ログイン中。ユーザーを覚える
      .catch(() => setUser(null))         // 失敗（401）→ 未ログイン
      .finally(() => setIsLoading(false)) // どちらでも最後に「分かった」にする
  }, [])                                  // 最後の [] は「最初に表示されたときの1回だけ実行する」という意味

  async function login(input:LoginInput) {
    const loggedIn = await authApi.login(input) // API でログインする
    setUser(loggedIn)                           // 返ってきたユーザーを覚える
  }

  async function logout() {
    await authApi.logout()
    setUser(null)
  }

  return (
    <AuthContext value={{ user, isLoading, login, logout }}>
      {children}
    </AuthContext>
  )
}