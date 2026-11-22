import { useEffect, useRef, useState } from 'react'

/** Ancho actual (px) de un elemento, con ResizeObserver. 0 hasta la primera medición. */
export function useAncho<T extends HTMLElement>() {
  const ref = useRef<T>(null)
  const [ancho, setAncho] = useState(0)

  useEffect(() => {
    const el = ref.current
    if (!el) return
    const medir = () => setAncho(el.clientWidth)
    medir()
    const obs = new ResizeObserver(medir)
    obs.observe(el)
    return () => obs.disconnect()
  }, [])

  return { ref, ancho }
}
