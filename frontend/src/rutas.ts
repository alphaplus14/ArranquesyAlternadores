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
  contable: '/contable/vencimientos',
}

/** RF-21: salidas, reintegros y devoluciones de clientes (logística y admin). */
export const RUTA_AJUSTES = '/logistica/ajustes'

/** RF-32 / S-77 (integrado en S-71): la ruta vieja de Precios de compra lleva a Costos por producto. */
export const RUTA_PRECIOS_COMPRA = '/logistica/precios-compra'

/** RF-23 / S-45: panel de gestión del administrador (su inicio). */
export const RUTA_PANEL = '/admin/panel'

/** RF-15 / RF-18 (S-30): cartera de clientes (mostrador y admin). */
export const RUTA_CARTERA = '/cartera'

/** RF-25 / RF-26: caja del día del mostrador (registrar movimientos y cerrar). */
export const RUTA_CAJA = '/caja'

/** RF-25 a RF-27: Caja del administrador (día en consulta, mes, cuentas y categorías, empleados). */
export const RUTA_CAJA_ADMIN = '/admin/caja'

/** S-82: ruta vieja de los códigos universales; redirige a Inventario (/productos?universal=ID). */
export const RUTA_UNIVERSALES = '/universales'

/** S-71: historial de costos de compra por producto (admin y logística). */
export const RUTA_COSTOS = '/consulta/costos'

/** RF-28: correcciones de ingresos confirmados (solo admin, solo consulta). */
export const RUTA_CORRECCIONES = '/admin/correcciones'

export function rutaInicio(rol: Rol): string {
  return inicio[rol]
}
