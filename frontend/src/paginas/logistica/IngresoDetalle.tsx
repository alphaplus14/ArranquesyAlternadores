import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import type { Ingreso, Pagina, Referencia } from '../../api/tipos'
import { useDebounce } from '../../hooks/useDebounce'

const pesos = (n: number | string) => Number(n).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })

const lineaVacia = { referencia_id: 0, cantidad: '1', costo_unitario: '', descuento: '0', iva_porcentaje: '19', sin_iva: false }

/**
 * RF-05: ítem por ítem. RF-09: descuento de línea y del documento. RF-10: líneas sin IVA.
 * RF-03: al confirmar se crean las unidades y se pasa a imprimir etiquetas.
 */
export default function IngresoDetalle() {
  const { id } = useParams()
  const qc = useQueryClient()
  const [linea, setLinea] = useState(lineaVacia)
  const [refTexto, setRefTexto] = useState('')
  const termino = useDebounce(refTexto.trim())

  const ingreso = useQuery({
    queryKey: ['ingreso', id],
    queryFn: async () => (await api.get<Ingreso>(`/ingresos/${id}`)).data,
  })

  const sugerencias = useQuery({
    queryKey: ['referencias', 'sugerir', termino, ingreso.data?.proveedor_id],
    queryFn: async () => (await api.get<Pagina<Referencia>>('/referencias', {
      params: { q: termino, proveedor_id: ingreso.data?.proveedor_id, por_pagina: 10 },
    })).data.data,
    enabled: termino.length >= 2 && linea.referencia_id === 0,
  })

  const actualizar = (i: Ingreso) => qc.setQueryData(['ingreso', id], i)

  const agregar = useMutation({
    mutationFn: async () => (await api.post<Ingreso>(`/ingresos/${id}/items`, linea)).data,
    onSuccess: (i) => { actualizar(i); setLinea(lineaVacia); setRefTexto('') },
  })
  const quitar = useMutation({
    mutationFn: async (itemId: number) => (await api.delete<Ingreso>(`/ingresos/${id}/items/${itemId}`)).data,
    onSuccess: actualizar,
  })
  const confirmar = useMutation({
    mutationFn: async () => (await api.post<Ingreso>(`/ingresos/${id}/confirmar`)).data,
    onSuccess: (i) => { actualizar(i); qc.invalidateQueries({ queryKey: ['ingresos'] }) },
  })

  const i = ingreso.data
  if (!i) return <div className="panel">Cargando…</div>
  const borrador = i.estado === 'borrador'
  const error = agregar.error ?? quitar.error ?? confirmar.error

  return (
    <div className="panel">
      <div className="fila-titulo">
        <h1>Remisión {i.numero_documento} <span className={`chip ${i.estado}`}>{i.estado}</span></h1>
        <span>{i.proveedor?.nombre} · {i.fecha}</span>
      </div>

      {borrador && (
        <form className="formulario en-linea" onSubmit={(e) => { e.preventDefault(); agregar.mutate() }}>
          <label className="crece">Referencia
            <input
              required
              placeholder="Código o descripción"
              value={refTexto}
              onChange={(e) => { setRefTexto(e.target.value); setLinea({ ...linea, referencia_id: 0 }) }}
            />
            {sugerencias.data && linea.referencia_id === 0 && (
              <ul className="sugerencias">
                {sugerencias.data.map((r) => (
                  <li key={r.id}>
                    <button type="button" onClick={() => { setLinea({ ...linea, referencia_id: r.id }); setRefTexto(`${r.codigo_referencia} · ${r.descripcion}`) }}>
                      {r.codigo_referencia} · {r.marca?.nombre} · {r.descripcion}
                    </button>
                  </li>
                ))}
                {sugerencias.data.length === 0 && <li><em>Sin referencias de este proveedor. Créela en el catálogo.</em></li>}
              </ul>
            )}
          </label>
          <label>Cantidad<input type="number" min="1" required value={linea.cantidad} onChange={(e) => setLinea({ ...linea, cantidad: e.target.value })} /></label>
          <label>Costo unitario<input type="number" min="0" step="0.01" required value={linea.costo_unitario} onChange={(e) => setLinea({ ...linea, costo_unitario: e.target.value })} /></label>
          <label>Descuento línea<input type="number" min="0" step="0.01" value={linea.descuento} onChange={(e) => setLinea({ ...linea, descuento: e.target.value })} /></label>
          <label>IVA %<input type="number" min="0" max="100" disabled={linea.sin_iva} value={linea.iva_porcentaje} onChange={(e) => setLinea({ ...linea, iva_porcentaje: e.target.value })} /></label>
          <label className="check"><input type="checkbox" checked={linea.sin_iva} onChange={(e) => setLinea({ ...linea, sin_iva: e.target.checked })} /> Sin IVA</label>
          <button className="primario" disabled={linea.referencia_id === 0 || agregar.isPending}>Agregar</button>
        </form>
      )}
      {error && <div className="aviso error">{mensajeError(error)}</div>}

      <table>
        <thead><tr><th>Referencia</th><th>Cant.</th><th>Costo</th><th>Desc.</th><th>IVA</th><th>Subtotal</th><th></th></tr></thead>
        <tbody>
          {i.items.map((it) => (
            <tr key={it.id}>
              <td>{it.referencia?.codigo_referencia} <small>{it.referencia?.descripcion}</small></td>
              <td>{it.cantidad}</td>
              <td>{pesos(it.costo_unitario)}</td>
              <td>{pesos(it.descuento)}</td>
              <td>{it.sin_iva ? <span className="chip">sin IVA</span> : `${Number(it.iva_porcentaje)}% · ${pesos(it.iva)}`}</td>
              <td>{pesos(it.subtotal)}</td>
              <td>{borrador && <button className="enlace" onClick={() => quitar.mutate(it.id)}>Quitar</button>}</td>
            </tr>
          ))}
        </tbody>
      </table>

      {i.totales && (
        <dl className="totales">
          <dt>Subtotal</dt><dd>{pesos(i.totales.subtotal)}</dd>
          <dt>Descuento en líneas</dt><dd>{pesos(i.totales.descuento_lineas)}</dd>
          <dt>Descuento del documento</dt><dd>{pesos(i.totales.descuento_total)}</dd>
          <dt>IVA</dt><dd>{pesos(i.totales.iva)}</dd>
          <dt>Líneas sin IVA</dt><dd>{i.totales.lineas_sin_iva}</dd>
          <dt>Total</dt><dd><strong>{pesos(i.totales.total)}</strong></dd>
        </dl>
      )}

      <div className="acciones">
        {borrador ? (
          <button className="primario" disabled={i.items.length === 0 || confirmar.isPending} onClick={() => confirmar.mutate()}>
            Confirmar ingreso y crear unidades
          </button>
        ) : (
          <Link className="boton primario" to={`/logistica/etiquetas?ingreso=${i.id}`}>Imprimir etiquetas</Link>
        )}
      </div>
    </div>
  )
}
