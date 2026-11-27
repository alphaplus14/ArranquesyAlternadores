import { useEffect, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import type { MetodoCaptura, MovimientoInventario, Pagina, Referencia, TipoMovimientoInventario, Usuario } from '../../api/tipos'
import { useAuth } from '../../auth/AuthProvider'
import ChipCalidad from '../../componentes/ChipCalidad'
import Icono from '../../componentes/Icono'
import Paginacion from '../../componentes/Paginacion'
import SelectorProveedor from '../../componentes/SelectorProveedor'
import { useDebounce } from '../../hooks/useDebounce'
import { useMovimientosInventario, type FiltrosMovimientos } from '../../hooks/useMovimientosInventario'
import { useFilasPorPagina } from '../../preferencias/preferencias'
import { formatearFechaHora } from '../../utilidades/fechas'
import '../consulta/costos.css'

/** RF-21 (S-87): nombre legible de cada tipo de movimiento (ingreso = compra confirmada). */
const NOMBRE_TIPO: Record<TipoMovimientoInventario, string> = {
  ingreso: 'Entrada por compra',
  venta: 'Venta',
  reverso: 'Reverso',
  baja: 'Salida',
  reintegro: 'Reintegro',
  alta: 'Alta',
}
const TIPOS = Object.keys(NOMBRE_TIPO) as TipoMovimientoInventario[]
const NOMBRE_METODO: Record<MetodoCaptura, string> = { escaneado: 'Escaneado', digitado: 'Digitado' }

interface Busqueda { referencias: Referencia[] | { data: Referencia[] } }
interface UniversalOpcion { id: number; codigo: string; descripcion: string | null }

/**
 * S-87: todos los movimientos de un producto (compras, ventas, reversos, salidas, reintegros y altas),
 * con filtros que viven en la URL. Solo consulta, solo en pantalla. Admin y logística.
 */
export default function MovimientosPorProducto() {
  const { usuario } = useAuth()
  const esAdmin = usuario?.rol === 'admin'
  const [params, setParams] = useSearchParams()
  const referencia = params.get('referencia') ?? ''
  const universal = referencia ? '' : params.get('universal') ?? ''
  const nombreProducto = params.get('producto') ?? ''
  const q = params.get('q') ?? ''
  const tipo = params.get('tipo') ?? ''
  const desde = params.get('desde') ?? ''
  const hasta = params.get('hasta') ?? ''
  const proveedor = params.get('proveedor') ?? ''
  const usuarioId = params.get('usuario') ?? ''
  const metodo = params.get('metodo') ?? ''
  const pagina = Math.max(1, Number(params.get('page')) || 1)
  const porPagina = useFilasPorPagina(20, 100)

  /** Cambiar un filtro vuelve a la página 1. */
  const elegir = (cambios: Record<string, string | null>) => setParams((actual) => {
    const p = new URLSearchParams(actual)
    for (const [k, v] of Object.entries(cambios)) {
      if (v) p.set(k, v)
      else p.delete(k)
    }
    if (!('page' in cambios)) p.delete('page')
    return p
  }, { replace: true })

  const filtros: FiltrosMovimientos = {
    referencia_id: referencia,
    universal_id: universal,
    q: q.length >= 2 ? q : '',
    tipo,
    desde,
    hasta,
    proveedor_id: proveedor,
    usuario_id: usuarioId,
    metodo_captura: metodo,
  }
  const rangoMalo = desde !== '' && hasta !== '' && desde > hasta
  const lista = useMovimientosInventario(filtros, pagina, porPagina, !rangoMalo)
  const filas = lista.data?.data ?? []

  // Solo el admin puede listar usuarios; para logística las opciones salen de lo ya visto en pantalla.
  const usuarios = useQuery({
    queryKey: ['usuarios-filtro'],
    queryFn: async () => (await api.get<Pagina<Usuario>>('/usuarios', { params: { por_pagina: 100 } })).data.data,
    enabled: esAdmin,
    staleTime: 5 * 60 * 1000,
  })
  const [vistos, setVistos] = useState<Record<number, string>>({})
  useEffect(() => {
    if (esAdmin || !lista.data) return
    setVistos((v) => {
      const nuevo = { ...v }
      for (const m of lista.data.data) if (m.usuario) nuevo[m.usuario.id] = m.usuario.name
      return Object.keys(nuevo).length === Object.keys(v).length ? v : nuevo
    })
  }, [esAdmin, lista.data])
  const opcionesUsuario = esAdmin
    ? (usuarios.data ?? []).map((u) => ({ id: u.id, name: u.name }))
    : Object.entries(vistos).map(([id, name]) => ({ id: Number(id), name }))

  const etiquetaProducto = referencia
    ? (nombreProducto || `Referencia #${referencia}`)
    : universal ? (nombreProducto || `Universal #${universal}`) : ''

  return (
    <div className="ajustes-seccion costos-pantalla">
      <BuscadorProducto
        texto={q}
        onTexto={(t) => elegir({ q: t || null })}
        onReferencia={(r) => elegir({ referencia: String(r.id), universal: null, producto: r.codigo_referencia })}
        onUniversal={(u) => elegir({ universal: String(u.id), referencia: null, producto: `Universal ${u.codigo}` })}
      />
      {etiquetaProducto && (
        <p className="ayuda">
          Producto: <strong>{etiquetaProducto}</strong>{' '}
          <button type="button" className="enlace" onClick={() => elegir({ referencia: null, universal: null, producto: null })}>Quitar</button>
        </p>
      )}
      <div className="costos-filtros">
        <label className="costos-campo">
          <span className="costos-campo-etiqueta">Tipo</span>
          <select className="costos-select" value={tipo} onChange={(e) => elegir({ tipo: e.target.value || null })}>
            <option value="">Todos los tipos</option>
            {TIPOS.map((t) => <option key={t} value={t}>{NOMBRE_TIPO[t]}</option>)}
          </select>
        </label>
        <label className="costos-campo">
          <span className="costos-campo-etiqueta">Desde</span>
          <input type="date" max={hasta || undefined} value={desde} onChange={(e) => elegir({ desde: e.target.value || null })} />
        </label>
        <label className="costos-campo">
          <span className="costos-campo-etiqueta">Hasta</span>
          <input type="date" min={desde || undefined} value={hasta} onChange={(e) => elegir({ hasta: e.target.value || null })} />
        </label>
        <div className="costos-campo costos-campo-proveedor">
          <span className="costos-campo-etiqueta" aria-hidden>Proveedor</span>
          <SelectorProveedor
            className="filtro costos-filtro"
            aria-label="Proveedor"
            value={proveedor}
            onChange={(v) => elegir({ proveedor: v || null })}
            opcionTodos="Todos los proveedores"
          />
        </div>
        <label className="costos-campo">
          <span className="costos-campo-etiqueta">Usuario</span>
          <select className="costos-select" value={usuarioId} onChange={(e) => elegir({ usuario: e.target.value || null })}>
            <option value="">Todos los usuarios</option>
            {usuarioId && !opcionesUsuario.some((u) => String(u.id) === usuarioId) && <option value={usuarioId}>Usuario #{usuarioId}</option>}
            {opcionesUsuario.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </select>
        </label>
        <label className="costos-campo">
          <span className="costos-campo-etiqueta">Método de captura</span>
          <select className="costos-select" value={metodo} onChange={(e) => elegir({ metodo: e.target.value || null })}>
            <option value="">Todos</option>
            <option value="escaneado">Escaneado</option>
            <option value="digitado">Digitado</option>
          </select>
        </label>
      </div>

      {rangoMalo ? (
        <p className="vacio">La fecha «Desde» no puede ser posterior a «Hasta».</p>
      ) : lista.isError && !lista.data ? (
        <div className="aviso error" role="alert">
          {mensajeError(lista.error)}{' '}
          <button type="button" className="enlace" onClick={() => void lista.refetch()}>Reintentar</button>
        </div>
      ) : !lista.data ? (
        <p className="cargando" role="status">Cargando…</p>
      ) : filas.length === 0 ? (
        <p className="vacio">No hay movimientos con esos filtros</p>
      ) : (
        <div className="tabla-viva" aria-busy={lista.isFetching}>
          <table className={lista.isFetching ? 'tabla-apilable actualizando' : 'tabla-apilable'}>
            <caption className="solo-lector">Movimientos de inventario por producto</caption>
            <thead><tr><th>Fecha y hora</th><th>Tipo</th><th>Producto</th><th>Unidad</th><th>Origen</th><th>Usuario</th><th>Método</th></tr></thead>
            <tbody>
              {filas.map((m) => <Fila key={m.id} m={m} />)}
            </tbody>
          </table>
        </div>
      )}
      {lista.isError && lista.data && <div className="aviso error" role="alert">{mensajeError(lista.error)}</div>}
      {!rangoMalo && lista.data && <Paginacion pagina={lista.data} onCambiar={(n) => elegir({ page: n > 1 ? String(n) : null })} />}
    </div>
  )
}

function Fila({ m }: { m: MovimientoInventario }) {
  const r = m.referencia
  return (
    <tr>
      <td data-etiqueta="Fecha y hora">{formatearFechaHora(m.created_at)}</td>
      <td data-etiqueta="Tipo"><span className={`chip mov-${m.tipo}`}>{NOMBRE_TIPO[m.tipo] ?? m.tipo}</span></td>
      <td data-etiqueta="Producto">
        <strong>{r.codigo_referencia}</strong> <ChipCalidad calidad={r.calidad} />
        <div className="ayuda">{r.descripcion} · {r.proveedor.nombre}</div>
      </td>
      <td data-etiqueta="Unidad">{m.unidad ? <span className="mono">{m.unidad.codigo_barras}</span> : '—'}</td>
      <td data-etiqueta="Origen">{m.origen?.etiqueta ?? '—'}</td>
      <td data-etiqueta="Usuario">{m.usuario?.name ?? '—'}</td>
      <td data-etiqueta="Método">{m.metodo_captura ? NOMBRE_METODO[m.metodo_captura] : '—'}</td>
    </tr>
  )
}

/**
 * Como el de Costos (S-71): escribir filtra por código (q) y lista coincidencias de referencias y
 * códigos universales para fijar un producto exacto.
 */
function BuscadorProducto({ texto, onTexto, onReferencia, onUniversal }: {
  texto: string
  onTexto: (t: string) => void
  onReferencia: (r: Referencia) => void
  onUniversal: (u: UniversalOpcion) => void
}) {
  const campo = useRef<HTMLInputElement>(null)
  const [escrito, setEscrito] = useState(texto)
  const termino = useDebounce(escrito.trim(), 300)
  const activo = termino.length >= 2
  const enUrl = useRef(texto)
  enUrl.current = texto
  const alTexto = useRef(onTexto)
  alTexto.current = onTexto
  useEffect(() => {
    const valor = termino.length >= 2 ? termino : ''
    if (valor !== enUrl.current) alTexto.current(valor)
  }, [termino])

  const referencias = useQuery({
    queryKey: ['costos-buscar', termino],
    queryFn: async () => {
      const { data } = await api.get<Busqueda>('/buscar', { params: { q: termino } })
      return Array.isArray(data.referencias) ? data.referencias : data.referencias.data ?? []
    },
    enabled: activo,
  })
  const universales = useQuery({
    queryKey: ['costos-universales', termino],
    queryFn: async () => (await api.get<Pagina<UniversalOpcion>>('/codigos-universales', { params: { q: termino, por_pagina: 5 } })).data.data,
    enabled: activo,
  })
  const limpiar = () => { setEscrito(''); if (campo.current) campo.current.value = '' }
  const lista = activo ? referencias.data ?? [] : []
  const unis = activo ? universales.data ?? [] : []

  return (
    <div className="costos-buscador">
      <div className="campo-unico">
        <Icono nombre="buscar" />
        <input
          ref={campo}
          className="escaner"
          placeholder="Busque un producto por código de referencia o de fabricante"
          aria-label="Buscar un producto"
          autoComplete="off"
          maxLength={60}
          defaultValue={texto}
          onInput={(e) => setEscrito(e.currentTarget.value)}
          onKeyDown={(e) => { if (e.key === 'Escape' && e.currentTarget.value !== '') { e.preventDefault(); limpiar() } }}
        />
      </div>
      {activo && (unis.length > 0 || lista.length > 0) && (
        <ul className="costos-opciones" aria-label="Coincidencias">
          {unis.map((u) => (
            <li key={`u${u.id}`}>
              <button type="button" className="costos-opcion universal" onClick={() => { limpiar(); onUniversal(u) }}>
                <strong>Todas las referencias del universal {u.codigo}</strong>
                {u.descripcion && <small>{u.descripcion}</small>}
              </button>
            </li>
          ))}
          {lista.map((r) => (
            <li key={r.id}>
              <button type="button" className="costos-opcion" onClick={() => { limpiar(); onReferencia(r) }}>
                <strong>{r.codigo_referencia}</strong>
                <small>{[r.descripcion, r.proveedor?.nombre].filter(Boolean).join(' · ')}</small>
                {r.calidad && <ChipCalidad calidad={r.calidad} />}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
