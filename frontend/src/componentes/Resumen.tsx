import { useState, type ReactNode } from 'react'
import Icono from './Icono'

/**
 * Las tarjetas de resumen ocupan el alto que necesita el trabajo de la página.
 * Cerrado, queda una línea con los mismos datos. La elección se recuerda en este navegador.
 */
export default function Resumen({ clave, compacto, children }: {
  clave: string
  compacto: ReactNode
  children: ReactNode
}) {
  const [abierto, setAbierto] = useState(() => {
    try {
      return localStorage.getItem(clave) === '1'
    } catch {
      return false
    }
  })

  return (
    <div className={abierto ? 'resumen abierto' : 'resumen'}>
      <button
        type="button"
        className="resumen-barra"
        aria-expanded={abierto}
        aria-controls={clave}
        onClick={() => setAbierto((actual) => {
          const siguiente = !actual
          try { localStorage.setItem(clave, siguiente ? '1' : '0') } catch { /* el navegador puede bloquear el almacenamiento */ }
          return siguiente
        })}
      >
        <span className="resumen-titulo">{abierto ? 'Ocultar resumen' : 'Resumen'}</span>
        {!abierto && <span className="resumen-datos">{compacto}</span>}
        <span className="resumen-chevron" aria-hidden><Icono nombre="chevron" /></span>
      </button>
      {abierto && (
        <section className="medidas" id={clave}>
          {children}
        </section>
      )}
    </div>
  )
}
