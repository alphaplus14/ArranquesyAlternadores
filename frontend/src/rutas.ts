import type { Rol } from './api/tipos'

/**
 * Pantalla de inicio de cada rol. Se usa al iniciar sesión, en "/", en el logotipo, en "*" y en ConRol.
 */
const inicio: Record<Rol, string> = {
  admin: '/mostrador/venta',
  mostrador: '/mostrador/venta',
  logistica: '/logistica/ingresos',
  contable: '/contable/vencimientos',
}

/** RF-21 (S-27): salidas, reintegros y devoluciones de clientes (logística y admin). */
export const RUTA_AJUSTES = '/logistica/ajustes'

/** RF-15 / RF-18 (S-30): cartera de clientes (mostrador y admin). */
export const RUTA_CARTERA = '/cartera'

export function rutaInicio(rol: Rol): string {
  return inicio[rol]
}