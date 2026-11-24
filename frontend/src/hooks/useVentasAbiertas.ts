import { useQuery } from '@tanstack/react-query'
import { api } from '../api/cliente'
import type { Pagina, VentaAbierta } from '../api/tipos'
import { diaEnColombia } from '../utilidades/fechas'
import { hoyEnBogota } from '../utilidades/periodos'

export const CLAVE_ABIERTAS = ['ventas', 'abiertas'] as const

/**
 * S-40: las ventas abiertas del usuario (el admin, de todos), de la más antigua a la más reciente.
 * La comparten la pantalla de Venta y el menú (punto de "venta de un día anterior").
 */
export function useVentasAbiertas(activo = true) {
  return useQuery({
    queryKey: CLAVE_ABIERTAS,
    queryFn: async () => (await api.get<Pagina<VentaAbierta>>('/ventas', { params: { estado: 'abierta' } })).data.data,
    enabled: activo,
    staleTime: 30_000,
  })
}

/** S-40: la venta se abrió un día anterior (en hora de Colombia) y sigue abierta. */
export function esDeDiaAnterior(v: { created_at: string }): boolean {
  return diaEnColombia(v.created_at) < hoyEnBogota()
}
