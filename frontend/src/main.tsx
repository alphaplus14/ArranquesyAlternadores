import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AuthProvider } from './auth/AuthProvider'
import { AvisosProvider } from './componentes/Avisos'
import App from './App'
import './index.css'

// Cada pantalla es un paquete aparte (App.tsx) y `vite build` borra los del despliegue anterior: una
// pestaña abierta desde antes ya no encuentra el de la pantalla que abre. Se recarga para traer la
// versión vigente, a lo sumo una vez cada 10 s para no entrar en un ciclo si el paquete de verdad falta.
const CLAVE_RECARGA = 'recarga-por-paquete'
window.addEventListener('vite:preloadError', (evento) => {
  const ahora = Date.now()
  let ultima = 0
  try {
    ultima = Number(sessionStorage.getItem(CLAVE_RECARGA)) || 0
    sessionStorage.setItem(CLAVE_RECARGA, String(ahora))
  } catch { /* sin sessionStorage se recarga igual */ }
  if (ahora - ultima < 10_000) return
  evento.preventDefault()
  window.location.reload()
})

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, refetchOnWindowFocus: false, staleTime: 10_000 },
  },
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <AuthProvider>
          <AvisosProvider>
            <App />
          </AvisosProvider>
        </AuthProvider>
      </BrowserRouter>
    </QueryClientProvider>
  </StrictMode>,
)
