import { useEffect, useMemo, useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { infoPagina, type Pagina } from '../api/tipos'

interface Opciones<T> {
  /** Identidad de la lista, sin el número de página. */
  clave: readonly unknown[]
  traer: (pagina: number) => Promise<Pagina<T>>
  /**
   * Si cambia, la página vuelve a 1 en el mismo render.
   * Puede ser el texto que aún no entra en `clave` (la búsqueda espera el debounce).
   */
  filtros?: unknown
  enabled?: boolean
  /** Pide la página siguiente en cuanto llega la actual. El catálogo de referencias lo usa. */
  prefetch?: boolean
}

function huellaDe(filtros: unknown) {
  return JSON.stringify(filtros ?? null)
}

/**
 * Listado paginado en el servidor: conserva la página anterior mientras llega la nueva
 * y vuelve a la primera cuando cambian los filtros.
 * No pinta filas. El catálogo de referencias sigue recortando lo visible con useVentana.
 */
export function useListaPaginada<T>({ clave, traer, filtros, enabled = true, prefetch = false }: Opciones<T>) {
  const qc = useQueryClient()
  const traerRef = useRef(traer)
  traerRef.current = traer

  const huella = huellaDe(filtros)
  const [estado, setEstado] = useState({ pagina: 1, huella })
  if (estado.huella !== huella) setEstado({ pagina: 1, huella })
  const pagina = estado.huella === huella ? estado.pagina : 1

  const lista = useQuery({
    queryKey: [...clave, pagina],
    queryFn: () => traerRef.current(pagina),
    enabled,
    placeholderData: (previo) => previo,
  })

  const claveJson = JSON.stringify(clave)
  useEffect(() => {
    if (!prefetch || !lista.data) return
    const { actual, ultima } = infoPagina(lista.data)
    if (actual >= ultima) return
    const base = JSON.parse(claveJson) as unknown[]
    const siguiente = actual + 1
    qc.prefetchQuery({
      queryKey: [...base, siguiente],
      queryFn: () => traerRef.current(siguiente),
    })
  }, [prefetch, lista.data, claveJson, qc])

  const filas = useMemo(() => lista.data?.data ?? [], [lista.data])
  const irA = (n: number) => setEstado({ pagina: n, huella })

  return {
    filas,
    resultado: lista.data,
    info: lista.data ? infoPagina(lista.data) : null,
    pagina,
    irA,
    cargando: lista.isFetching,
  }
}
