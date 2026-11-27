import { useEffect, type RefObject } from 'react'

/**
 * El lector y la cabecera de la tabla quedan fijos al hacer scroll: la cabecera va justo debajo del
 * lector, cuyo alto cambia con avisos u opciones. Se publica en --ingreso-lector-alto.
 */
export function useAltoLector(contenedor: RefObject<HTMLElement | null>, zona: RefObject<HTMLElement | null>, activo: boolean) {
  useEffect(() => {
    const el = zona.current
    const caja = contenedor.current
    if (!el || !caja || typeof ResizeObserver === 'undefined') return
    const medir = () => caja.style.setProperty('--ingreso-lector-alto', `${Math.ceil(el.getBoundingClientRect().height)}px`)
    medir()
    const obs = new ResizeObserver(medir)
    obs.observe(el)
    return () => obs.disconnect()
  }, [contenedor, zona, activo])
}
