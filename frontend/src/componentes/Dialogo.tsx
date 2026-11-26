import { useId, useRef, type KeyboardEvent, type ReactNode } from 'react'
import { useBloquearScroll } from '../hooks/useBloquearScroll'
import { ATRIBUTO_DIALOGO, ContextoCierreDialogo, useClicEnFondo, useEscape, useSalidaDialogo, useTope } from './dialogoCierre'
import DialogoSalir from './DialogoSalir'

const ENFOCABLES = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

/** Tab y Shift+Tab no salen del diálogo (el lector de la venta queda detrás). */
function atraparTab(e: KeyboardEvent<HTMLElement>) {
  if (e.key !== 'Tab') return
  const controles = [...e.currentTarget.querySelectorAll<HTMLElement>(ENFOCABLES)].filter((c) => c.tabIndex >= 0)
  if (controles.length === 0) return
  const primero = controles[0]
  const ultimo = controles[controles.length - 1]
  const activo = document.activeElement

  if (e.shiftKey && (activo === primero || !e.currentTarget.contains(activo))) {
    e.preventDefault()
    ultimo.focus()
  } else if (!e.shiftKey && (activo === ultimo || !e.currentTarget.contains(activo))) {
    e.preventDefault()
    primero.focus()
  }
}

export type VarianteDialogo = 'cuenta' | 'ancha' | 'ficha' | 'ingreso' | 'proveedor' | 'confirmar' | 'cierre' | 'salir'

const FONDO: Record<VarianteDialogo, string> = {
  cuenta: 'modal',
  ancha: 'modal',
  ficha: 'modal modal-ficha',
  ingreso: 'modal modal-ingreso',
  proveedor: 'modal modal-proveedor',
  confirmar: 'modal confirmar',
  // S-41: centrado, cabecera y pie fijos, cuerpo con scroll.
  cierre: 'modal modal-cierre',
  // ¿Salir sin guardar?: encima de cualquier modal, también de un diálogo apilado.
  salir: 'modal confirmar dialogo-salir',
}

const CAJA: Record<VarianteDialogo, string> = {
  cuenta: 'modal-caja cuenta-modal',
  ancha: 'modal-caja cuenta-modal dialogo-ancho',
  ficha: 'modal-caja cuenta-modal ficha-modal',
  ingreso: 'modal-caja cuenta-modal ingreso-modal',
  proveedor: 'modal-caja cuenta-modal proveedor-modal',
  confirmar: 'modal-caja cuenta-modal',
  cierre: 'modal-caja cuenta-modal cierre-modal',
  salir: 'modal-caja cuenta-modal',
}

/**
 * Marco de un diálogo: overlay, foco, scroll y las bandas de cabeza y pie. El cuerpo lo trae quien
 * llama, en una o varias secciones.
 * Un solo camino para cerrar: la X, Esc, el clic en el fondo y los "Cancelar" (`BotonCancelar` o
 * `useCerrarDialogo`) pasan por `solicitarCierre`, que pregunta si hay cambios sin guardar. Con un
 * envío en curso (`pendiente`) nada cierra. Al cerrar, el foco vuelve a quien abrió la modal.
 */
export default function Dialogo({
  titulo,
  descripcion,
  marca,
  onCerrar,
  pendiente = false,
  variante = 'cuenta',
  alertdialog = false,
  modificado,
  confirmarSalida = true,
  onEnviar,
  pie,
  children,
}: {
  titulo: string
  descripcion?: ReactNode
  /** Contenido del círculo de la cabeza (inicial o icono). El marco lo pone este module. */
  marca?: ReactNode
  /** Cierre directo (sin preguntar): lo usa el padre también después de guardar con éxito. */
  onCerrar: () => void
  /** Con una petición en curso, la X, Esc, el fondo y Cancelar no cierran. */
  pendiente?: boolean
  variante?: VarianteDialogo
  alertdialog?: boolean
  /** Fuerza si hay cambios sin guardar (modales con listas u otro estado que no son campos). */
  modificado?: boolean
  /** false: confirmaciones de sí/no y vistas de solo lectura cierran directo, sin preguntar. */
  confirmarSalida?: boolean
  /** Si viene, el diálogo es un formulario y Enter lo envía. */
  onEnviar?: () => void
  pie?: ReactNode
  children?: ReactNode
}) {
  const tituloId = useId()
  const cajaRef = useRef<HTMLElement | null>(null)
  const esTope = useTope()
  useBloquearScroll()
  const { contexto, preguntando, seguirEditando } = useSalidaDialogo({ cajaRef, onCerrar, pendiente, modificado, confirmarSalida })
  useEscape(esTope, contexto.solicitarCierre)
  const fondo = useClicEnFondo(contexto.solicitarCierre)

  const caja = (
    <>
      <header className="modal-cabeza">
        {marca && <span className="avatar" aria-hidden>{marca}</span>}
        <div className="modal-cabeza-texto">
          <h2 id={tituloId}>{titulo}</h2>
          {descripcion && (typeof descripcion === 'string' ? <p>{descripcion}</p> : descripcion)}
        </div>
        <button type="button" className="icono-boton modal-cerrar" aria-label="Cerrar" title="Cerrar" disabled={pendiente} onClick={contexto.solicitarCierre}>
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" aria-hidden><path d="M6 6l12 12M18 6L6 18" /></svg>
        </button>
      </header>
      {children}
      {pie && <footer className="modal-pie">{pie}</footer>}
    </>
  )

  const props = {
    className: CAJA[variante],
    role: alertdialog ? 'alertdialog' as const : 'dialog' as const,
    'aria-modal': true as const,
    'aria-labelledby': tituloId,
    [ATRIBUTO_DIALOGO]: '',
    onKeyDown: (e: KeyboardEvent<HTMLElement>) => { if (esTope) atraparTab(e) },
  }

  return (
    <ContextoCierreDialogo.Provider value={contexto}>
      <div className={FONDO[variante]} onMouseDown={fondo.onMouseDown} onClick={fondo.onClick}>
        {onEnviar
          ? <form ref={(el) => { cajaRef.current = el }} {...props} onSubmit={(e) => { e.preventDefault(); onEnviar() }}>{caja}</form>
          : <div ref={(el) => { cajaRef.current = el }} {...props}>{caja}</div>}
      </div>
      {preguntando && <DialogoSalir onSeguir={seguirEditando} onSalir={contexto.cerrarSinPreguntar} />}
    </ContextoCierreDialogo.Provider>
  )
}

/** Una banda del cuerpo. `cuerpo` y `selector` conservan el scroll interno de ingreso y de filtros. */
export function DialogoSeccion({
  titulo,
  modo = 'seccion',
  children,
}: {
  titulo?: string
  modo?: 'seccion' | 'cuerpo' | 'selector'
  children: ReactNode
}) {
  const clase = modo === 'cuerpo' ? 'modal-seccion ingreso-modal-cuerpo' : modo === 'selector' ? 'modal-seccion selector-cuerpo' : 'modal-seccion'
  return (
    <section className={clase}>
      {titulo && <h3>{titulo}</h3>}
      {children}
    </section>
  )
}
