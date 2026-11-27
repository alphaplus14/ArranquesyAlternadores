import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api } from '../api/cliente'
import type { MovimientoInventario, Pagina } from '../api/tipos'

/** RF-21 (S-87): filtros de GET /movimientos-inventario; lo vacío no se envía. */
export interface FiltrosMovimientos {
  referencia_id?: string
  universal_id?: string
  q?: string
  tipo?: string
  desde?: string
  hasta?: string
  proveedor_id?: string
  usuario_id?: string
  metodo_captura?: string
}

/** Página del kardex; conserva la anterior mientras llega la nueva (sin parpadeo). */
export function useMovimientosInventario(filtros: FiltrosMovimientos, page: number, porPagina: number, enabled = true) {
  return useQuery({
    queryKey: ['movimientos-inventario', filtros, page, porPagina],
    queryFn: async () => (await api.get<Pagina<MovimientoInventario>>('/movimientos-inventario', {
      params: {
        ...Object.fromEntries(Object.entries(filtros).filter(([, v]) => v)),
        page,
        por_pagina: porPagina,
      },
    })).data,
    placeholderData: keepPreviousData,
    enabled,
  })
}
