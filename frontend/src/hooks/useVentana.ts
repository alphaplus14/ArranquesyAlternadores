import { useCallback, useEffect, useRef, useState } from 'react'

const MARGEN = 6

/**
 * Pinta solo las filas que caben en el scroll, más un margen.
 * El catálogo completo no entra al DOM: la página llega del servidor
 * y aquí se recorta lo visible.
 */
export function useVentana<T>(filas: T[], altoFila: number) {
  const ref = useRef<HTMLDivElement>(null)
  const [scrollTop, setScrollTop] = useState(0)
  const [alto, setAlto] = useState(520)

  useEffect(() => {
    const el = ref.current
    if (!el) return
    const medir = () => setAlto(el.clientHeight)
    medir()
    const obs = new ResizeObserver(medir)
    obs.observe(el)
    return () => obs.disconnect()
  }, [])

  const inicio = Math.max(0, Math.floor(scrollTop / altoFila) - MARGEN)
  const cantidad = Math.ceil(alto / altoFila) + MARGEN * 2

  const sincronizar = useCallback(() => setScrollTop(ref.current?.scrollTop ?? 0), [])

  return {
    ref,
    onScroll: sincronizar,
    sincronizar,
    scrollTop,
    inicio,
    visibles: filas.slice(inicio, inicio + cantidad),
    desplazamiento: inicio * altoFila,
    altoTotal: filas.length * altoFila,
  }
}
