import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': 'http://localhost', // /api で始まる通信を Laravel（80番）に転送する
      '/sanctum': 'http://localhost', // ログイン前に CSRF トークンを受け取る /sanctum/csrf-cookie も転送する
    },
  },
})
