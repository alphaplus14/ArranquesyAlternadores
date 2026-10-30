import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, pedirCsrf } from '../api/cliente'
import type { Rol, Usuario } from '../api/tipos'

interface AuthContexto {
  usuario: Usuario | null
  cargando: boolean
  entrar: (email: string, password: string) => Promise<void>
  salir: () => Promise<void>
  tieneRol: (...roles: Rol[]) => boolean
}

const Contexto = createContext<AuthContexto | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [usuario, setUsuario] = useState<Usuario | null>(null)
  const [cargando, setCargando] = useState(true)

  useEffect(() => {
    api.get<Usuario>('/me')
      .then((r) => setUsuario(r.data))
      .catch(() => setUsuario(null))
      .finally(() => setCargando(false))
  }, [])

  const entrar = useCallback(async (email: string, password: string) => {
    await pedirCsrf()
    const r = await api.post<Usuario>('/login', { email, password })
    setUsuario(r.data)
  }, [])

  const salir = useCallback(async () => {
    await api.post('/logout')
    setUsuario(null)
  }, [])

  // Igual que el backend: el admin entra a todos los módulos.
  const tieneRol = useCallback(
    (...roles: Rol[]) => !!usuario && (usuario.rol === 'admin' || roles.includes(usuario.rol)),
    [usuario],
  )

  return <Contexto.Provider value={{ usuario, cargando, entrar, salir, tieneRol }}>{children}</Contexto.Provider>
}

export function useAuth(): AuthContexto {
  const ctx = useContext(Contexto)
  if (!ctx) throw new Error('useAuth fuera de AuthProvider')
  return ctx
}
