import type { Rol } from './api/tipos'

/** El listado de productos es la entrada de los roles de operación; el contable entra a sus vencimientos (RF-22). */
const inicio: Record<Rol, string> = { admin: '/productos', logistica: '/productos', mostrador: '/productos', contable: '/contable/vencimientos' }

export function rutaInicio(rol: Rol): string {
  return inicio[rol]
}
