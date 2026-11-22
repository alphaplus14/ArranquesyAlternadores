import { useEffect } from 'react'

/**
 * Mientras el componente que lo usa está montado (un diálogo), el fondo no hace scroll y no
 * aparece una segunda barra junto a la de la página. Al desmontarse se restaura lo anterior.
 */
export function useBloquearScroll() {
  useEffect(() => {
    const anterior = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => { document.body.style.overflow = anterior }
  }, [])
}
