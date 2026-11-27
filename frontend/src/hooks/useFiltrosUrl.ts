import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'

/**
 * S-89: filtros de una pantalla en la URL (`?q=&estado=…`), con los valores por defecto omitidos,
 * para que al entrar a una cuenta y volver (miga o atrás) se conserven. Cambiar un filtro reinicia
 * la página a 1. Con `prefijo` varias secciones de la misma pantalla no chocan (p. ej. `ec_desde`).
 */
export function useFiltrosUrl<T extends Record<string, string>>(porDefecto: T, prefijo = '') {
  const [params, setParams] = useSearchParams()
  const clavePagina = `${prefijo}pagina`

  const filtros = useMemo(() => {
    const f = { ...porDefecto }
    for (const k of Object.keys(porDefecto) as (keyof T)[]) {
      const v = params.get(`${prefijo}${String(k)}`)
      if (v !== null) f[k] = v as T[keyof T]
    }
    return f
    // porDefecto es un objeto literal en cada render; su contenido es lo que importa.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params, prefijo, JSON.stringify(porDefecto)])

  const pagina = Math.max(1, Number(params.get(clavePagina) ?? 1) || 1)

  const fijar = useCallback((cambios: Partial<T>, { conservarPagina = false } = {}) => {
    setParams((actual) => {
      const n = new URLSearchParams(actual)
      for (const [k, v] of Object.entries(cambios)) {
        const clave = `${prefijo}${k}`
        if (v === undefined || v === '' || v === porDefecto[k]) n.delete(clave)
        else n.set(clave, String(v))
      }
      if (!conservarPagina) n.delete(clavePagina)
      return n
    }, { replace: true })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [setParams, prefijo, clavePagina, JSON.stringify(porDefecto)])

  const irA = useCallback((n: number) => {
    setParams((actual) => {
      const p = new URLSearchParams(actual)
      if (n <= 1) p.delete(clavePagina); else p.set(clavePagina, String(n))
      return p
    }, { replace: true })
  }, [setParams, clavePagina])

  const activos = (Object.keys(porDefecto) as (keyof T)[]).filter((k) => filtros[k] !== porDefecto[k]).length
  const limpiar = useCallback(() => fijar(porDefecto), [fijar, porDefecto])

  return { filtros, fijar, pagina, irA, activos, limpiar }
}
