import { useEffect, useId, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { useBloquearScroll } from '../hooks/useBloquearScroll'

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

export type VarianteDialogo = 'cuenta' | 'ancha' | 'ficha' | 'ingreso' | 'proveedor' | 'confirmar' | 'cierre'

const FONDO: Record<VarianteDialogo, string> = {
  cuenta: 'modal',
  ancha: 'modal',
  ficha: 'modal modal-ficha',
  ingreso: 'modal modal-ingreso',
  proveedor: 'modal modal-proveedor',
  confirmar: 'modal confirmar',
  // S-41: centrado, cabecera y pie fijos, cuerpo con scroll.
  cierre: 'modal modal-cierre',
}

const CAJA: Record<VarianteDialogo, string> = {
  cuenta: 'modal-caja cuenta-modal',
  ancha: 'modal-caja cuenta-modal dialogo-ancho',
  ficha: 'modal-caja cuenta-modal ficha-modal',
  ingreso: 'modal-caja cuenta-modal ingreso-modal',
  proveedor: 'modal-caja cuenta-modal proveedor-modal',
  confirmar: 'modal-caja cuenta-modal',
  cierre: 'modal-caja cuenta-modal cierre-modal',
}

/** El de encima es el único que atiende Escape. Así una confirmación no cierra el diálogo de abajo. */
const pila: number[] = []
const oyentes = new Set<() => void>()
let secuenciaDialogo = 0

function notificar() {
  oyentes.forEach((fn) => fn())
}

function useTope() {
  const id = useRef(0)
  if (id.current === 0) id.current = ++secuenciaDialogo
  const [, pintar] = useState(0)

  useEffect(() => {
    const propio = id.current
    pila.push(propio)
    const actualizar = () => pintar((n) => n + 1)
    oyentes.add(actualizar)
    notificar()
    return () => {
      const i = pila.lastIndexOf(propio)
      if (i >= 0) pila.splice(i, 1)
      oyentes.delete(actualizar)
      notificar()
    }
  }, [])

  return pila[pila.length - 1] === id.current
}

/**
 * Marco de un diálogo: overlay, Escape, foco, scroll y las bandas de cabeza y pie.
 * El cuerpo lo trae quien llama, en una o varias secciones.
 */
export default function Dialogo({
  titulo,
  descripcion,
  marca,
  onCerrar,
  pendiente = false,
  variante = 'cuenta',
  alertdialog = false,
  onEnviar,
  pie,
  children,
}: {
  titulo: string
  descripcion?: ReactNode
  /** Contenido del círculo de la cabeza (inicial o icono). El marco lo pone este module. */
  marca?: ReactNode
  onCerrar: () => void
  /** Con una petición en curso, Escape y la X no cierran. */
  pendiente?: boolean
  variante?: VarianteDialogo
  alertdialog?: boolean
  /** Si viene, el diálogo es un formulario y Enter lo envía. */
  onEnviar?: () => void
  pie?: ReactNode
  children?: ReactNode
}) {
  const tituloId = useId()
  const esTope = useTope()
  useBloquearScroll()

  useEffect(() => {
    if (!esTope) return
    const alPulsar = (e: globalThis.KeyboardEvent) => {
      if (e.key !== 'Escape' || pendiente) return
      e.preventDefault()
      e.stopPropagation()
      onCerrar()
    }
    document.addEventListener('keydown', alPulsar, true)
    return () => document.removeEventListener('keydown', alPulsar, true)
  }, [esTope, pendiente, onCerrar])

  const caja = (
    <>
      <header className="modal-cabeza">
        {marca && <span className="avatar" aria-hidden>{marca}</span>}
        <div className="modal-cabeza-texto">
          <h2 id={tituloId}>{titulo}</h2>
          {descripcion && (typeof descripcion === 'string' ? <p>{descripcion}</p> : descripcion)}
        </div>
        <button type="button" className="icono-boton modal-cerrar" aria-label="Cerrar" disabled={pendiente} onClick={onCerrar}>
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
    onKeyDown: (e: KeyboardEvent<HTMLElement>) => { if (esTope) atraparTab(e) },
  }

  return (
    <div className={FONDO[variante]}>
      {onEnviar
        ? <form {...props} onSubmit={(e) => { e.preventDefault(); onEnviar() }}>{caja}</form>
        : <div {...props}>{caja}</div>}
    </div>
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
