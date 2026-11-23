import type { Rol } from './api/tipos'

/** El listado de productos es la pantalla de entrada de los tres roles. */
const inicio: Record<Rol, string> = { admin: '/productos', logistica: '/productos', mostrador: '/productos' }

/** RF-21: salidas, reintegros y devoluciones de clientes (logística y admin). */
export const RUTA_AJUSTES = '/logistica/ajustes'

export function rutaInicio(rol: Rol): string {
  return inicio[rol]
}
