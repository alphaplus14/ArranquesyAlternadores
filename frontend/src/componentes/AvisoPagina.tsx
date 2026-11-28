import { useState, type ReactNode } from 'react'
import { duracionAviso, useAutoCierre, type TonoAlerta } from '../hooks/useAutoCierre'

interface Props {
  tono: Exclude<TonoAlerta, 'info'>
  /** Texto del aviso. Un texto nuevo lo vuelve a mostrar y reinicia la cuenta. */
  children: string
  className?: string
  /** ms hasta cerrarse solo; sin valor lo da el tono y el largo del texto. */
  espera?: number
  extra?: ReactNode
}

/**
 * Banner dentro de la página (resultado de guardar, error de un formulario) que se va solo.
 * Pausa con el mouse o el foco encima y se puede cerrar a mano. Para estados que deben seguir a
 * la vista (caja cerrada, sin precio) se usa un `.aviso` normal, no este.
 */
export function AvisoPagina({ tono, children, className = '', espera, extra }: Props) {
  const [cerrado, setCerrado] = useState<string | null>(null)
  const visible = cerrado !== children
  const ms = espera ?? duracionAviso(tono, children)
  const { pausado, pausar, reanudar } = useAutoCierre(visible ? ms : null, () => setCerrado(children), children)
  if (!visible) return null
  return (
    <div
      className={`aviso temporal ${tono} ${className}`.trim()}
      role={tono === 'error' ? 'alert' : 'status'}
      onMouseEnter={pausar}
      onMouseLeave={reanudar}
      onFocus={pausar}
      onBlur={reanudar}
    >
      <div className="aviso-temporal-texto">{children}{extra}</div>
      <button type="button" className="aviso-cerrar" aria-label="Cerrar aviso" onClick={() => setCerrado(children)}>
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" aria-hidden><path d="M6 6l12 12M18 6L6 18" /></svg>
      </button>
      <span className="aviso-progreso" aria-hidden style={{ animationDuration: `${ms}ms`, animationPlayState: pausado ? 'paused' : 'running' }} />
    </div>
  )
}
