import { useAuth } from '../auth/AuthProvider'
import type { Rol } from '../api/tipos'

/**
 * S-71: permisos de costos, espejo de backend/app/Enums/Rol.php (puedeVerUltimoCosto y
 * puedeVerHistorialCostos). El backend es quien decide; aquí solo se elige qué pintar.
 * - ultimo: columna "Costo" (último costo de compra) de Inventario (admin, logística y mostrador).
 * - historial: pantalla "Costos por producto", menú y enlace desde la columna (admin y logística).
 */
const ULTIMO: Rol[] = ['admin', 'logistica', 'mostrador']
const HISTORIAL: Rol[] = ['admin', 'logistica']

export function useVerCostos() {
  const { usuario } = useAuth()
  const rol = usuario?.rol
  return {
    ultimo: !!rol && ULTIMO.includes(rol),
    historial: !!rol && HISTORIAL.includes(rol),
  }
}
