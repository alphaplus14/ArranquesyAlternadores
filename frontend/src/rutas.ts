import type { Rol } from './api/tipos'

/**
 * Pantalla de inicio de cada rol: su módulo de trabajo. Se usa al iniciar sesión, en "/", en el
 * logotipo del encabezado, en el comodín "*" y cuando un rol entra a una ruta sin permiso (ConRol).
 * - mostrador y admin: la venta de mostrador (RF-06).
 * - logística: ingresos (RF-04, RF-05); no tiene acceso a ventas.
 */
const inicio: Record<Rol, string> = {
  admin: '/mostrador/venta',
  mostrador: '/mostrador/venta',
  logistica: '/logistica/ingresos',
}

/** RF-21: salidas, reintegros y devoluciones de clientes (logística y admin). */
export const RUTA_AJUSTES = '/logistica/ajustes'

export function rutaInicio(rol: Rol): string {
  return inicio[rol]
}
