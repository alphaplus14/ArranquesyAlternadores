import type { ReactNode } from 'react'
import { useCerrarDialogo } from './dialogoCierre'

/**
 * "Cancelar" de una modal: pasa por el mismo camino que la X, Esc y el fondo (`solicitarCierre`),
 * así pregunta si hay cambios sin guardar. Se desactiva mientras hay un envío en curso.
 */
export default function BotonCancelar({ className = 'secundario', children = 'Cancelar', autoFocus }: {
  className?: string
  children?: ReactNode
  autoFocus?: boolean
}) {
  const { solicitarCierre, pendiente } = useCerrarDialogo()
  return (
    <button type="button" className={className} autoFocus={autoFocus} disabled={pendiente} onClick={solicitarCierre}>
      {children}
    </button>
  )
}
