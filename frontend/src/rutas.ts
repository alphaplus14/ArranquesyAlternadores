import type { Rol } from './api/tipos'

/**
 * Pantalla de inicio de cada rol: su módulo de trabajo. Se usa al iniciar sesión, en "/", en el
 * logotipo del encabezado, en el comodín "*" y cuando un rol entra a una ruta sin permiso (ConRol).
 * mostrador: la venta (RF-06); admin: panel de gestión (RF-23); logística: ingresos (RF-04, RF-05).
 * S-43: el admin no vende; consulta y reversa ventas desde los enlaces de Cartera.
 */
const inicio: Record<Rol, string> = {
  admin: '/admin/panel',
  mostrador: '/mostrador/venta',
  logistica: '/logistica/ingresos',
}

/** RF-21: salidas, reintegros y devoluciones de clientes (logística y admin). */
export const RUTA_AJUSTES = '/logistica/ajustes'

/** RF-23 / S-45: panel de gestión del administrador (su inicio). */
export const RUTA_PANEL = '/admin/panel'

/** RF-15 / RF-18 (S-30): cartera de clientes (mostrador y admin). */
export const RUTA_CARTERA = '/cartera'

export function rutaInicio(rol: Rol): string {
  return inicio[rol]
}
