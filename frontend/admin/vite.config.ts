import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// 管理端部署在前端域名下的 /admin/ 子路径（与 H5 同一域名，见 deploy/nginx 配置）
export default defineConfig({
  base: '/admin/',
  plugins: [react()],
  server: {
    port: 5174,
    proxy: {
      '/api': 'http://127.0.0.1:8090',
      '/uploads': 'http://127.0.0.1:8090',
    },
  },
})
