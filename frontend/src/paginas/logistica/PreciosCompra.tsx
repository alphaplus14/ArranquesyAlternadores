import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../api/cliente'
import type { LineaPrecioCompra, PaginaPreciosCompra, TipoIngreso } from '../../api/tipos'
import { useCategorias } from '../../hooks/useCatalogos'
import { useDebounce } from '../../hooks/useDebounce'
import { useListaPaginada } from '../../hooks/useListaPaginada'
import Medida from '../../componentes/Medida'
import Paginacion from '../../componentes/Paginacion'
import SelectorProveedor from '../../componentes/SelectorProveedor'
import { fechaCorta } from '../../componentes/fechas'
import { pesos } from '../../componentes/numeros'

const NOMBRE_TIPO: Record<TipoIngreso, string> = { factura: 'Factura', remision: 'Remisión' }

/**
 * RF-32 / S-70: último precio de compra y el anterior, con proveedor, y el historial filtrable.
 * Solo en pantalla. Logística y administrador (la ruta ya lo exige).
 */
export default function PreciosCompra() {
  const categorias = useCategorias()
  const [q, setQ] = useState('')
  const [proveedorId, setProveedorId] = useState('')
  const [categoriaId, setCategoriaId] = useState('')
  const [desde, setDesde] = useState('')
  const [hasta, setHasta] = useState('')
  const [referenciaId, setReferenciaId] = useState('')
  const termino = useDebounce(q.trim())

  const lista = useListaPaginada<LineaPrecioCompra>({
    clave: ['precios-compra', termino, proveedorId, categoriaId, desde, hasta, referenciaId],
    filtros: [termino, proveedorId, categoriaId, desde, hasta, referenciaId],
    traer: async (pagina) => (await api.get<PaginaPreciosCompra>('/reportes/precios-compra', {
      params: {
        q: referenciaId ? undefined : termino || undefined,
        proveedor_id: proveedorId || undefined,
        categoria_id: categoriaId || undefined,
        desde: desde || undefined,
        hasta: hasta || undefined,
        referencia_id: referenciaId || undefined,
        page: pagina,
        por_pagina: 50,
      },
    })).data,
  })

  const resumen = (lista.resultado as PaginaPreciosCompra | undefined)?.resumen ?? null
  const enfocado = resumen?.ultimo?.referencia ?? lista.filas[0]?.referencia ?? null
  const variacion = resumen?.variacion != null ? Number(resumen.variacion) : null

  const verReferencia = (id: number) => {
    setReferenciaId(String(id))
    setQ('')
  }

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>Precios de compra</h1>
        <p>Último precio de un producto, el anterior y el proveedor. Busque por referencia, código universal o descripción.</p>
      </div>

      {resumen && (
        <div className="medidas medidas-precios">
          <Medida
            etiqueta="Último precio"
            valor={resumen.ultimo ? pesos(resumen.ultimo.costo_unitario) : '—'}
            pie={resumen.ultimo ? pieCompra(resumen.ultimo) : 'Sin compras con estos filtros'}
            tono="azul"
            icono="ingreso"
          />
          <Medida
            etiqueta="Precio anterior"
            valor={resumen.anterior ? pesos(resumen.anterior.costo_unitario) : '—'}
            pie={resumen.anterior ? pieCompra(resumen.anterior) : 'No hay una compra previa'}
            tono="amarillo"
            icono="auditoria"
          />
          <Medida
            etiqueta="Variación"
            valor={variacion === null ? '—' : variacion === 0 ? pesos(0) : `${variacion > 0 ? '+' : '−'}${pesos(Math.abs(variacion))}`}
            pie={variacion === null ? 'Hace falta una compra anterior' : variacion > 0 ? 'Subió frente al anterior' : variacion < 0 ? 'Bajó frente al anterior' : 'Igual que el anterior'}
            tono={variacion === null || variacion === 0 ? 'amarillo' : variacion > 0 ? 'rojo' : 'verde'}
            icono="panel"
          />
        </div>
      )}

      <section className="tarjeta-pagina">
        <div className="formulario en-linea filtros-informe">
          <label>Buscar
            <input
              value={q}
              placeholder="Referencia, universal o descripción"
              aria-label="Buscar producto"
              onChange={(e) => { setQ(e.target.value); setReferenciaId('') }}
            />
          </label>
          <label>Proveedor
            <SelectorProveedor
              className="filtro"
              aria-label="Proveedor"
              value={proveedorId}
              onChange={setProveedorId}
              placeholder="Todos los proveedores"
              limpiable
            />
          </label>
          <label>Categoría
            <select value={categoriaId} onChange={(e) => setCategoriaId(e.target.value)}>
              <option value="">Todas</option>
              {categorias.data?.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
            </select>
          </label>
          <label>Desde
            <input type="date" max={hasta || undefined} value={desde} onChange={(e) => setDesde(e.target.value)} />
          </label>
          <label>Hasta
            <input type="date" min={desde || undefined} value={hasta} onChange={(e) => setHasta(e.target.value)} />
          </label>
        </div>

        {referenciaId && enfocado && (
          <p className="ayuda">
            Compras de <strong>{enfocado.codigo_referencia}</strong>
            {enfocado.codigo_universal ? ` (universal ${enfocado.codigo_universal.codigo})` : ''}.{' '}
            <button type="button" className="enlace" onClick={() => setReferenciaId('')}>Ver todas</button>
          </p>
        )}
        <p className="ayuda">El neto es el costo unitario menos el descuento de esa línea. El descuento del documento no se resta. Una remisión ya cubierta por una factura no aparece: manda el precio de la factura.</p>

        {lista.resultado && (
          <>
            <div className="tabla-desplazable">
              <table className="tabla-apilable">
                <caption className="solo-lector">Compras confirmadas, de la más reciente a la más antigua</caption>
                <thead>
                  <tr>
                    <th>Fecha</th>
                    <th>Producto</th>
                    <th>Proveedor</th>
                    <th>Documento</th>
                    <th className="col-numero">Costo</th>
                    <th className="col-numero">Neto</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {lista.filas.map((fila) => (
                    <tr key={fila.id}>
                      <td data-etiqueta="Fecha">{fechaCorta(fila.fecha)}</td>
                      <td data-etiqueta="Producto">
                        <strong>{fila.referencia.codigo_referencia}</strong>
                        {fila.referencia.codigo_universal && <small className="bloque">Universal {fila.referencia.codigo_universal.codigo}</small>}
                        {fila.referencia.descripcion && <small className="bloque">{fila.referencia.descripcion}</small>}
                      </td>
                      <td data-etiqueta="Proveedor">{fila.proveedor.nombre}</td>
                      <td data-etiqueta="Documento">
                        {NOMBRE_TIPO[fila.tipo]} {fila.numero_documento}
                        {fila.corregido && <span className="chip corregida">Corregido</span>}
                      </td>
                      <td data-etiqueta="Costo" className="col-numero">{pesos(fila.costo_unitario)}</td>
                      <td data-etiqueta="Neto" className="col-numero">{pesos(fila.costo_neto)}</td>
                      <td data-etiqueta="" className="acciones-linea">
                        <button type="button" className="enlace" onClick={() => verReferencia(fila.referencia.id)}>Ver este</button>
                        <Link to={`/logistica/ingresos/${fila.ingreso_id}`}>Abrir</Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {lista.filas.length === 0 && <p className="vacio">No hay compras con estos filtros.</p>}
            <Paginacion pagina={lista.resultado} onCambiar={lista.irA} />
          </>
        )}
      </section>
    </div>
  )
}

function pieCompra(fila: LineaPrecioCompra): string {
  const universal = fila.referencia.codigo_universal ? ` · ${fila.referencia.codigo_universal.codigo}` : ''
  return `${fila.referencia.codigo_referencia}${universal} · ${fila.proveedor.nombre} · ${fechaCorta(fila.fecha)} · ${NOMBRE_TIPO[fila.tipo]} ${fila.numero_documento}`
}
