import type { MouseEvent } from 'react'
import { Link } from 'react-router-dom'
import Icono, { type IconoNombre } from './Icono'

export type VarianteBotonFila = 'ver' | 'abrir' | 'pagar' | 'anular' | 'editar'

const ICONO: Record<VarianteBotonFila, IconoNombre> = { ver: 'ojo', abrir: 'abrir', pagar: 'billetera', anular: 'prohibido', editar: 'editar' }
const TEXTO: Record<VarianteBotonFila, string> = { ver: 'Ver', abrir: 'Abrir', pagar: 'Pagar', anular: 'Anular', editar: 'Editar' }

/**
 * S-78: botón de acción de una fila de tabla (columna "Acciones"). Con `to` es un <Link> con
 * apariencia de botón (clic medio o Ctrl+clic abren en otra pestaña); sin `to`, un <button>.
 * `descripcion` va en aria-label: qué abre y de quién (p. ej. "Ver cuenta de Molpartes SAS").
 * Alto: 36 px (32 px con densidad compacta), 44 px en táctil o pantalla angosta; `compacto`, 28 px.
 * S-79: `anular` es secundario con texto y borde en el color de peligro y fondo suave.
 */
export default function BotonFila({ variante, to, onClick, etiqueta, descripcion, disabled = false, title, compacto = false, soloIcono = false, onMouseEnter, onFocus }: {
  variante: VarianteBotonFila
  to?: string
  onClick?: (e: MouseEvent<HTMLButtonElement>) => void
  etiqueta?: string
  descripcion: string
  disabled?: boolean
  title?: string
  compacto?: boolean
  /** Solo el icono. El nombre queda en aria-label y en el título. */
  soloIcono?: boolean
  /** Al pasar el puntero o enfocar: para precargar el destino. */
  onMouseEnter?: () => void
  onFocus?: () => void
}) {
  const clase = soloIcono
    ? 'icono-accion'
    : `boton ${variante === 'pagar' ? 'primario' : 'secundario'} boton-fila${variante === 'anular' ? ' boton-fila-anular' : ''}${compacto ? ' boton-fila-compacto' : ''}`
  const contenido = soloIcono
    ? <Icono nombre={ICONO[variante]} />
    : (
      <>
        <Icono nombre={ICONO[variante]} />
        <span>{etiqueta ?? TEXTO[variante]}</span>
      </>
    )
  const titulo = title ?? (soloIcono ? (etiqueta ?? TEXTO[variante]) : undefined)

  if (to && !disabled) {
    return <Link className={clase} to={to} aria-label={descripcion} title={titulo} onMouseEnter={onMouseEnter} onFocus={onFocus}>{contenido}</Link>
  }

  return (
    <button type="button" className={clase} aria-label={descripcion} title={titulo} disabled={disabled} onClick={onClick} onMouseEnter={onMouseEnter} onFocus={onFocus}>
      {contenido}
    </button>
  )
}
