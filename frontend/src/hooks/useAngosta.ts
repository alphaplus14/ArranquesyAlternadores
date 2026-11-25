import { useEffect, useState } from 'react'

/**
 * true en pantallas angostas (por defecto < 640 px, el mismo corte del CSS). Sirve para lo que el
 * CSS no puede cambiar, como el placeholder de un campo o cambiar una barra por un select.
 */
export function useAngosta(maxAncho = 639): boolean {
  const consulta = `(max-width: ${maxAncho}px)`
  const [angosta, setAngosta] = useState(() => typeof window !== 'undefined' && window.matchMedia(consulta).matches)

  useEffect(() => {
    const mq = window.matchMedia(consulta)
    const cambiar = () => setAngosta(mq.matches)
    cambiar()
    mq.addEventListener('change', cambiar)
    return () => mq.removeEventListener('change', cambiar)
  }, [consulta])

  return angosta
}
