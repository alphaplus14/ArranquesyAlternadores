import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { useDebounce } from '../hooks/useDebounce'
import { hoyEnBogota } from '../utilidades/periodos'
import { ATAJOS, atajoDe, rangoAtajo, type AtajoPeriodo } from '../utilidades/atajosPeriodo'
import Icono from './Icono'

/**
 * S-88: barra de filtros común del módulo contable. Buscador con lupa y limpiar (retardo de 300 ms),
 * atajos de período (con Desde/Hasta en "Personalizado"), los controles propios de la pantalla
 * (`children`), el contador de filtros activos y "Limpiar filtros" (solo si hay alguno). Se envuelve
 * en varias líneas; campos de 44 px; etiquetas visibles.
 */
export default function BarraFiltros({ busqueda, periodo, activos, onLimpiar, children }: {
  busqueda?: { valor: string; onCambio: (v: string) => void; placeholder: string; etiqueta?: string }
  periodo?: { desde: string; hasta: string; onCambio: (desde: string, hasta: string) => void }
  activos: number
  onLimpiar: () => void
  children?: ReactNode
}) {
  const id = useId()
  return (
    <div className="barra-filtros" role="search">
      {busqueda && <Buscador id={`${id}-q`} {...busqueda} />}
      {periodo && <Periodo id={`${id}-periodo`} {...periodo} />}
      {children}
      {activos > 0 && (
        <div className="barra-filtros-limpiar">
          <span className="ayuda" aria-live="polite">{activos === 1 ? '1 filtro activo' : `${activos} filtros activos`}</span>
          <button type="button" className="secundario boton-44" onClick={onLimpiar}>Limpiar filtros</button>
        </div>
      )}
    </div>
  )
}

function Buscador({ id, valor, onCambio, placeholder, etiqueta = 'Buscar' }: { id: string; valor: string; onCambio: (v: string) => void; placeholder: string; etiqueta?: string }) {
  const [texto, setTexto] = useState(valor)
  const retardado = useDebounce(texto.trim(), 300)
  const ultimo = useRef(valor)
  // Si el filtro cambia desde afuera (Limpiar filtros, atrás), el campo lo sigue.
  useEffect(() => { if (valor !== ultimo.current) { ultimo.current = valor; setTexto(valor) } }, [valor])
  useEffect(() => {
    if (retardado !== ultimo.current) { ultimo.current = retardado; onCambio(retardado) }
  }, [retardado, onCambio])

  return (
    <label className="barra-filtros-campo barra-filtros-busqueda" htmlFor={id}>
      <span className="etiqueta-campo">{etiqueta}</span>
      <span className="campo-unico">
        <Icono nombre="buscar" />
        <input id={id} type="search" autoComplete="off" placeholder={placeholder} value={texto} onChange={(e) => setTexto(e.target.value)} />
        {texto && (
          <button type="button" className="busca-limpiar" aria-label="Limpiar la búsqueda" onClick={() => { setTexto(''); ultimo.current = ''; onCambio('') }}>
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden><path d="M6 6l12 12M18 6L6 18" /></svg>
          </button>
        )}
      </span>
    </label>
  )
}

function Periodo({ id, desde, hasta, onCambio }: { id: string; desde: string; hasta: string; onCambio: (desde: string, hasta: string) => void }) {
  const [personal, setPersonal] = useState(() => atajoDe(desde, hasta) === 'personalizado')
  const atajo = personal ? 'personalizado' : atajoDe(desde, hasta)
  const hoy = hoyEnBogota()
  return (
    <>
      <label className="barra-filtros-campo" htmlFor={id}>
        <span className="etiqueta-campo">Período</span>
        <select
          id={id}
          value={atajo}
          onChange={(e) => {
            const v = e.target.value as AtajoPeriodo
            if (v === 'personalizado') { setPersonal(true); return }
            setPersonal(false)
            const r = rangoAtajo(v)
            onCambio(r.desde, r.hasta)
          }}
        >
          {ATAJOS.map((a) => <option key={a.valor} value={a.valor}>{a.texto}</option>)}
          <option value="personalizado">Personalizado</option>
        </select>
      </label>
      {atajo === 'personalizado' && (
        <>
          <label className="barra-filtros-campo">
            <span className="etiqueta-campo">Desde</span>
            <input type="date" max={hasta || hoy} value={desde} onChange={(e) => e.target.value && onCambio(e.target.value, hasta)} />
          </label>
          <label className="barra-filtros-campo">
            <span className="etiqueta-campo">Hasta</span>
            <input type="date" min={desde} max={hoy} value={hasta} onChange={(e) => e.target.value && onCambio(desde, e.target.value)} />
          </label>
        </>
      )}
    </>
  )
}

/** Un select con etiqueta visible, del alto de la barra. */
export function CampoFiltro({ etiqueta, children }: { etiqueta: string; children: ReactNode }) {
  return (
    <label className="barra-filtros-campo">
      <span className="etiqueta-campo">{etiqueta}</span>
      {children}
    </label>
  )
}

/** "Mostrando N de M". */
export function TextoResultados({ mostrando, total, de = 'resultados' }: { mostrando: number; total: number; de?: string }) {
  return <p className="ayuda barra-filtros-resultados" aria-live="polite">Mostrando {mostrando.toLocaleString('es-CO')} de {total.toLocaleString('es-CO')} {de}.</p>
}

/** Estado vacío: con filtros activos ofrece limpiarlos. */
export function VacioFiltros({ hayFiltros, sinFiltros, onLimpiar }: { hayFiltros: boolean; sinFiltros: string; onLimpiar: () => void }) {
  if (!hayFiltros) return <>{sinFiltros}</>
  return (
    <span className="vacio-filtros">
      Nada coincide con estos filtros.{' '}
      <button type="button" className="enlace" onClick={onLimpiar}>Limpiar filtros</button>
    </span>
  )
}
