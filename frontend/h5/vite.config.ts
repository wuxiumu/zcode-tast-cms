import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// 本机 8080 被占用，后端开发服务跑在 8090；生产由 Nginx 统一入口（doc/02 §5）
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      '/api': 'http://127.0.0.1:8090',
      '/uploads': 'http://127.0.0.1:8090',
    },
  },
})
