import { useId, useLayoutEffect, useRef, type FormEvent, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { atraparTab } from './foco'
import { useBloquearScroll } from '../hooks/useBloquearScroll'
import { ATRIBUTO_DIALOGO, ContextoCierreDialogo, useClicEnFondo, useEscape, useSalidaDialogo, useTope } from './dialogoCierre'
import DialogoSalir from './DialogoSalir'

/**
 * Diálogo que se abre ENCIMA de otro (p. ej. "Nuevo proveedor" sobre "Nuevo ingreso"). Va en un
 * portal; mientras está abierto, la app de abajo queda inerte (sin foco ni clics) y sin scroll.
 * Ninguna tecla sale de aquí: Esc cierra solo este diálogo y Tab queda atrapado dentro, así el de
 * abajo conserva lo escrito. A menos de 640 px ocupa la pantalla con los botones visibles.
 * Mismo camino de cierre que Dialogo: X, Esc, fondo y Cancelar pasan por `solicitarCierre`.
 */
export default function DialogoApilado({ titulo, nota, onCerrar, onEnviar, pie, children, pendiente = false, modificado, confirmarSalida = true }: {
  titulo: string
  /** Texto bajo el título (p. ej. la nota de campos obligatorios, como en Nuevo ingreso). */
  nota?: ReactNode
  /** Cierre directo (sin preguntar): lo usa el padre también después de guardar con éxito. */
  onCerrar: () => void
  onEnviar: () => void
  pie: ReactNode
  children: ReactNode
  /** Con una petición en curso, la X, Esc, el fondo y Cancelar no cierran. */
  pendiente?: boolean
  /** Fuerza si hay cambios sin guardar (estado que no son campos). */
  modificado?: boolean
  /** false: cierra directo, sin preguntar (elegir de una lista, confirmaciones). */
  confirmarSalida?: boolean
}) {
  const tituloId = useId()
  const cajaRef = useRef<HTMLElement | null>(null)
  useBloquearScroll()

  // En layout: al cerrar, la app deja de estar inerte antes de que se le devuelva el foco (por eso
  // este efecto va antes de useSalidaDialogo: las limpiezas corren en el mismo orden).
  useLayoutEffect(() => {
    const app = document.getElementById('root')
    if (!app || app.hasAttribute('inert')) return
    app.setAttribute('inert', '')
    return () => app.removeAttribute('inert')
  }, [])

  const esTope = useTope()
  const { contexto, preguntando, seguirEditando } = useSalidaDialogo({ cajaRef, onCerrar, pendiente, modificado, confirmarSalida })
  useEscape(esTope, contexto.solicitarCierre)
  const fondo = useClicEnFondo(contexto.solicitarCierre)

  return createPortal(
    <ContextoCierreDialogo.Provider value={contexto}>
      <div
        className="modal modal-apilado"
        onMouseDown={fondo.onMouseDown}
        onClick={fondo.onClick}
        onKeyDown={(e) => {
          // Los eventos de un portal también suben por el árbol de React: no deben llegar al diálogo de abajo.
          e.stopPropagation()
          if (esTope) atraparTab(e)
        }}
      >
        <form
          ref={(el) => { cajaRef.current = el }}
          className="modal-caja cuenta-modal dialogo-apilado"
          role="dialog"
          aria-modal="true"
          aria-labelledby={tituloId}
          noValidate
          {...{ [ATRIBUTO_DIALOGO]: '' }}
          onSubmit={(e: FormEvent) => { e.preventDefault(); e.stopPropagation(); onEnviar() }}
        >
          <header className="modal-cabeza">
            <div className="modal-cabeza-texto"><h2 id={tituloId}>{titulo}</h2>{nota}</div>
            <button type="button" className="icono-boton modal-cerrar" aria-label="Cerrar" title="Cerrar" disabled={pendiente} onClick={contexto.solicitarCierre}>
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" aria-hidden><path d="M6 6l12 12M18 6L6 18" /></svg>
            </button>
          </header>
          <section className="modal-seccion dialogo-apilado-cuerpo">{children}</section>
          <footer className="modal-pie">{pie}</footer>
        </form>
      </div>
      {/* Dentro del portal: la app de abajo está inerte. */}
      {preguntando && <DialogoSalir onSeguir={seguirEditando} onSalir={contexto.cerrarSinPreguntar} />}
    </ContextoCierreDialogo.Provider>,
    document.body,
  )
}
