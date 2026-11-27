import { useQuery } from '@tanstack/react-query'
import { api } from '../api/cliente'
import type { TipoVencimiento, Vencimientos } from '../api/tipos'

/** S-88: filtros opcionales de la bandeja (el contador del menú no los usa). */
export interface FiltrosVencimientos {
  estado?: 'vencidas' | 'por_vencer'
  q?: string
  vence_desde?: string
  vence_hasta?: string
  orden?: 'pendiente_desc' | 'titular'
}

/** RF-30: bandeja de vencimientos. La comparten la pantalla y el contador del menú. */
export function useVencimientos({ tipo, pagina = 1, enabled = true, filtros = {} }: { tipo?: TipoVencimiento; pagina?: number; enabled?: boolean; filtros?: FiltrosVencimientos } = {}) {
  return useQuery({
    queryKey: ['vencimientos', tipo ?? 'todas', filtros, pagina],
    queryFn: async () => (await api.get<Vencimientos>('/vencimientos', { params: { tipo, ...filtros, page: pagina, por_pagina: 50 } })).data,
    enabled,
    placeholderData: (previo) => previo,
    refetchInterval: 5 * 60 * 1000,
  })
}
