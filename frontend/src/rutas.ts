import type { Rol } from './api/tipos'

/** El listado de productos es la pantalla de entrada de los tres roles. */
const inicio: Record<Rol, string> = { admin: '/productos', logistica: '/productos', mostrador: '/productos' }

export function rutaInicio(rol: Rol): string {
  return inicio[rol]
}
