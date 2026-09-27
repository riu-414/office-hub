import { createContext, useContext } from "react";
import type { LoginInput, User } from "./types";

// 箱に入れる中身の型。ログイン中のユーザー、読み込み中かどうか、ログインとログアウトの関数
export type AuthContextValue = {
  user: User | null
  isLoading: boolean
  login: (input: LoginInput) => Promise<void>
  logout: () => Promise<void>
}

// 箱を作る。 最初は空（null）
export const AuthContext = createContext<AuthContextValue | null>(null)

// 箱から中身を取り出す関数。 各画面ではこれを呼ぶ
export function useAuth(): AuthContextValue {
  const value = useContext(AuthContext)
  if (value === null) {
    throw new Error('useAuth は AuthProvider の内側で使ってください')
  }
  return value
}