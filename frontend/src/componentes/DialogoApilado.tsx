import { useEffect, useId, type FormEvent, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { atraparTab } from './foco'
import { useBloquearScroll } from '../hooks/useBloquearScroll'

/**
 * Diálogo que se abre ENCIMA de otro (p. ej. "Nuevo proveedor" sobre "Nuevo ingreso"). Va en un
 * portal; mientras está abierto, la app de abajo queda inerte (sin foco ni clics) y sin scroll.
 * Ninguna tecla sale de aquí: Esc cierra solo este diálogo y Tab queda atrapado dentro, así el de
 * abajo conserva lo escrito. A menos de 640 px ocupa la pantalla con los botones visibles.
 */
export default function DialogoApilado({ titulo, onCerrar, onEnviar, pie, children }: {
  titulo: string
  onCerrar: () => void
  onEnviar: () => void
  pie: ReactNode
  children: ReactNode
}) {
  const tituloId = useId()
  useBloquearScroll()

  useEffect(() => {
    const app = document.getElementById('root')
    if (!app || app.hasAttribute('inert')) return
    app.setAttribute('inert', '')
    return () => app.removeAttribute('inert')
  }, [])

  return createPortal(
    <div
      className="modal modal-apilado"
      onKeyDown={(e) => {
        // Los eventos de un portal también suben por el árbol de React: no deben llegar al diálogo de abajo.
        e.stopPropagation()
        if (e.key === 'Escape') { e.preventDefault(); onCerrar() } else atraparTab(e)
      }}
    >
      <form
        className="modal-caja cuenta-modal dialogo-apilado"
        role="dialog"
        aria-modal="true"
        aria-labelledby={tituloId}
        noValidate
        onSubmit={(e: FormEvent) => { e.preventDefault(); e.stopPropagation(); onEnviar() }}
      >
        <header className="modal-cabeza">
          <div className="modal-cabeza-texto"><h2 id={tituloId}>{titulo}</h2></div>
          <button type="button" className="icono-boton modal-cerrar" aria-label="Cerrar" onClick={onCerrar}>
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" aria-hidden><path d="M6 6l12 12M18 6L6 18" /></svg>
          </button>
        </header>
        <section className="modal-seccion dialogo-apilado-cuerpo">{children}</section>
        <footer className="modal-pie">{pie}</footer>
      </form>
    </div>,
    document.body,
  )
}
