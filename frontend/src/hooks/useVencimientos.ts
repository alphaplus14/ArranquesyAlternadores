import { useQuery } from '@tanstack/react-query'
import { api } from '../api/cliente'
import type { TipoVencimiento, Vencimientos } from '../api/tipos'

/** RF-30: bandeja de vencimientos. La comparten la pantalla y el contador del menú. */
export function useVencimientos({ tipo, pagina = 1, enabled = true }: { tipo?: TipoVencimiento; pagina?: number; enabled?: boolean } = {}) {
  return useQuery({
    queryKey: ['vencimientos', tipo ?? 'todas', pagina],
    queryFn: async () => (await api.get<Vencimientos>('/vencimientos', { params: { tipo, page: pagina, por_pagina: 50 } })).data,
    enabled,
    placeholderData: (previo) => previo,
    refetchInterval: 5 * 60 * 1000,
  })
}
