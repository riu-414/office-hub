import axios from 'axios'

// 通信の共通設定
export const apiClient = axios.create({
  headers: {
    // API には常に JSON を要求する
    Accept: 'application/json',
  },
})