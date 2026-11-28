import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'

// S-94: pruebas de componentes (Vitest + Testing Library) en jsdom. Solo `src/**/*.test.tsx`.
export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    globals: true,
    include: ['src/**/*.test.{ts,tsx}'],
  },
})
