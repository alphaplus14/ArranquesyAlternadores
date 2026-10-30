import type { Rol } from './api/tipos'

export function rutaInicio(rol: Rol): string {
  return { admin: '/admin/referencias', logistica: '/logistica/ingresos', mostrador: '/mostrador/venta' }[rol]
}
