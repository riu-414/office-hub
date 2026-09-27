import { apiClient } from "../../shared/api/client";
import type { LoginInput, User } from "./types";

// フロントの通信関数は、Laravel 側のルートとコントローラを見ながら書く

export async function login(input: LoginInput): Promise<User> {
  await apiClient.get('/sanctum/csrf-cookie')
  const res = await apiClient.post<{ data: User }>('/api/v1/auth/login', input)
  return res.data.data
  // res            ← axios が返す「通信結果」全体（ステータスコードなども入っている）
  // res.data       ← サーバーから届いた本文 = { "data": { "id": 2, ... } }
  // res.data.data  ← API が包んでいた中身 = { "id": 2, ... }（これが User）
}

export async function logout(): Promise<void> {
  await apiClient.post('/api/v1/auth/logout')
}

export async function fetchMe(): Promise<User> {
  const res = await apiClient.get<{ data: User }>('/api/v1/auth/me')
  return res.data.data
}