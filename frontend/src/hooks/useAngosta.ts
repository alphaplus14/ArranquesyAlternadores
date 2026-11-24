import { useEffect, useState } from 'react'

const CONSULTA = '(max-width: 639px)'

/**
 * true en pantallas angostas (< 640 px, el mismo corte del CSS). Sirve para lo que el CSS no puede
 * cambiar, como el placeholder de un campo: a 360 px un texto largo se corta.
 */
export function useAngosta(): boolean {
  const [angosta, setAngosta] = useState(() => typeof window !== 'undefined' && window.matchMedia(CONSULTA).matches)

  useEffect(() => {
    const mq = window.matchMedia(CONSULTA)
    const cambiar = () => setAngosta(mq.matches)
    mq.addEventListener('change', cambiar)
    return () => mq.removeEventListener('change', cambiar)
  }, [])

  return angosta
}
