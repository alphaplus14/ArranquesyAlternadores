import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'

// Sanctum SPA: el proxy deja API y frontend en el mismo origen, así las cookies de sesión viajan solas.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const backend = env.BACKEND_URL || 'http://localhost:8000'

  return {
    plugins: [react()],
    server: {
      port: 5173,
      proxy: {
        '/api': { target: backend, changeOrigin: false },
        '/sanctum': { target: backend, changeOrigin: false },
      },
    },
  }
})
