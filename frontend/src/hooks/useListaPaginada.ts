import { useEffect, useMemo, useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { infoPagina, type Pagina } from '../api/tipos'
import { useFilasPorPagina } from '../preferencias/preferencias'

interface Opciones<T> {
  /** Identidad de la lista, sin el número de página. */
  clave: readonly unknown[]
  /** Recibe la página y, si el listado usa `porPagina`, las filas por página que debe pedir. */
  traer: (pagina: number, porPagina: number) => Promise<Pagina<T>>
  /**
   * Si cambia, la página vuelve a 1 en el mismo render.
   * Puede ser el texto que aún no entra en `clave` (la búsqueda espera el debounce).
   */
  filtros?: unknown
  enabled?: boolean
  /** Pide la página siguiente en cuanto llega la actual. El catálogo de referencias lo usa. */
  prefetch?: boolean
  /**
   * S-70: filas por página. `defecto` es el valor de siempre del listado; si el usuario guardó una
   * preferencia, se usa esa (hasta `tope`, el máximo del endpoint). Cambiarla vuelve a la página 1.
   */
  porPagina?: { defecto: number; tope?: number }
}

function huellaDe(filtros: unknown) {
  return JSON.stringify(filtros ?? null)
}

/**
 * Listado paginado en el servidor: conserva la página anterior mientras llega la nueva
 * y vuelve a la primera cuando cambian los filtros.
 * No pinta filas. El catálogo de referencias sigue recortando lo visible con useVentana.
 */
export function useListaPaginada<T>({ clave, traer, filtros, enabled = true, prefetch = false, porPagina }: Opciones<T>) {
  const qc = useQueryClient()
  const traerRef = useRef(traer)
  traerRef.current = traer

  // S-70: filas por página (la preferencia del usuario o el valor del listado). Entra en la huella:
  // al cambiar, vuelve a la página 1; y en la clave, para no mezclar páginas de otro tamaño.
  const preferidas = useFilasPorPagina(porPagina?.defecto ?? 0, porPagina?.tope ?? 100)
  const n = porPagina ? preferidas : 0
  const huella = huellaDe([filtros ?? null, n])
  const [estado, setEstado] = useState({ pagina: 1, huella })
  if (estado.huella !== huella) setEstado({ pagina: 1, huella })
  const pagina = estado.huella === huella ? estado.pagina : 1

  const lista = useQuery({
    queryKey: [...clave, ...(n ? [n] : []), pagina],
    queryFn: () => traerRef.current(pagina, n),
    enabled,
    placeholderData: (previo) => previo,
  })

  const claveJson = JSON.stringify(n ? [...clave, n] : clave)
  useEffect(() => {
    if (!prefetch || !lista.data) return
    const { actual, ultima } = infoPagina(lista.data)
    if (actual >= ultima) return
    const base = JSON.parse(claveJson) as unknown[]
    const siguiente = actual + 1
    qc.prefetchQuery({
      queryKey: [...base, siguiente],
      queryFn: () => traerRef.current(siguiente, n),
    })
  }, [prefetch, lista.data, claveJson, qc, n])

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
