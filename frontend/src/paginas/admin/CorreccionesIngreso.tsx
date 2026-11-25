import { Fragment, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../api/cliente'
import type { CorreccionIngreso, Pagina, Usuario } from '../../api/tipos'
import Paginacion from '../../componentes/Paginacion'
import SelectorProveedor from '../../componentes/SelectorProveedor'
import DetalleCorreccion from '../../componentes/ingresos/DetalleCorreccion'
import { pesos } from '../../componentes/numeros'
import { useListaPaginada } from '../../hooks/useListaPaginada'
import { formatearFechaHora } from '../../utilidades/fechas'

/**
 * RF-28: correcciones de ingresos confirmados. Solo admin y solo consulta (en pantalla, sin
 * exportar). Filtros por fecha, proveedor y usuario; cada fila despliega el antes y el después.
 */
export default function CorreccionesIngreso() {
  const [desde, setDesde] = useState('')
  const [hasta, setHasta] = useState('')
  const [proveedorId, setProveedorId] = useState('')
  const [usuarioId, setUsuarioId] = useState('')
  const [abierta, setAbierta] = useState<number | null>(null)

  const usuarios = useQuery({
    queryKey: ['usuarios', 'todos'],
    queryFn: async () => (await api.get<Pagina<Usuario>>('/usuarios', { params: { por_pagina: 100 } })).data.data,
    staleTime: 60_000,
  })

  const filtros = { desde: desde || undefined, hasta: hasta || undefined, proveedor_id: proveedorId || undefined, usuario_id: usuarioId || undefined }
  const { filas, resultado, irA, cargando } = useListaPaginada<CorreccionIngreso>({
    clave: ['correcciones-ingreso', filtros],
    filtros,
    traer: async (pagina) => (await api.get<Pagina<CorreccionIngreso>>('/ingresos/correcciones', { params: { ...filtros, page: pagina } })).data,
  })

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>Correcciones de ingresos</h1>
        <p>Cada corrección de una factura o remisión ya confirmada, con su motivo y lo que cambió. Solo consulta.</p>
      </div>
      <section className="tarjeta-pagina">
        <div className="formulario en-linea filtros-correcciones">
          <label>Desde<input type="date" value={desde} onChange={(e) => setDesde(e.target.value)} /></label>
          <label>Hasta<input type="date" value={hasta} onChange={(e) => setHasta(e.target.value)} /></label>
          <SelectorProveedor value={proveedorId} onChange={setProveedorId} opcionTodos="Todos los proveedores" aria-label="Proveedor" />
          <select aria-label="Usuario" value={usuarioId} onChange={(e) => setUsuarioId(e.target.value)}>
            <option value="">Todos los usuarios</option>
            {usuarios.data?.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </select>
        </div>
        <table className="tabla-apilable tabla-correcciones">
          <thead>
            <tr><th>Fecha</th><th>Usuario</th><th>Ingreso</th><th>Proveedor</th><th>Motivo</th><th className="col-numero">Unidades</th><th className="col-numero">Total</th><th><span className="solo-lector">Detalle</span></th></tr>
          </thead>
          <tbody>
            {filas.map((c) => (
              <Fragment key={c.id}>
                <tr>
                  <td data-etiqueta="Fecha">{formatearFechaHora(c.created_at)}</td>
                  <td data-etiqueta="Usuario">{c.usuario?.name ?? '—'}</td>
                  <td data-etiqueta="Ingreso">
                    {c.ingreso ? <Link to={`/logistica/ingresos/${c.ingreso.id}`}>{c.ingreso.tipo === 'factura' ? 'Factura' : 'Remisión'} {c.ingreso.numero_documento}</Link> : '—'}
                  </td>
                  <td data-etiqueta="Proveedor">{c.ingreso?.proveedor?.nombre ?? '—'}</td>
                  <td data-etiqueta="Motivo">{c.motivo}</td>
                  <td data-etiqueta="Unidades" className="col-numero">+{c.unidades_creadas} / −{c.unidades_retiradas}</td>
                  <td data-etiqueta="Total" className="col-numero">{c.total_anterior === null ? '—' : pesos(c.total_anterior)} → <strong>{pesos(c.total_nuevo)}</strong></td>
                  <td className="celda-accion">
                    <button type="button" className="enlace" aria-expanded={abierta === c.id} onClick={() => setAbierta(abierta === c.id ? null : c.id)}>
                      {abierta === c.id ? 'Ocultar' : 'Ver cambios'}
                    </button>
                  </td>
                </tr>
                {abierta === c.id && (
                  <tr className="fila-detalle"><td colSpan={8}><DetalleCorreccion antes={c.antes} despues={c.despues} /></td></tr>
                )}
              </Fragment>
            ))}
            {filas.length === 0 && !cargando && <tr><td colSpan={8} className="vacio">Sin correcciones para estos filtros.</td></tr>}
          </tbody>
        </table>
        <Paginacion pagina={resultado} onCambiar={irA} />
      </section>
    </div>
  )
}
