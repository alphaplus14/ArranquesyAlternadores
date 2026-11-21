import { useState } from 'react'
import { api } from '../api/cliente'
import type { Pagina, Referencia } from '../api/tipos'
import { useProveedores } from '../hooks/useCatalogos'
import { useListaPaginada } from '../hooks/useListaPaginada'
import Medida from '../componentes/Medida'
import Paginacion from '../componentes/Paginacion'

/** RF-08: referencias con existencia 0 de un proveedor. */
export default function Agotados() {
  const proveedores = useProveedores()
  const [proveedorId, setProveedorId] = useState('')

  const { filas, resultado, info, irA } = useListaPaginada<Referencia>({
    clave: ['agotados', proveedorId],
    filtros: proveedorId,
    enabled: proveedorId !== '',
    traer: async (pagina) => (await api.get<Pagina<Referencia>>('/reportes/agotados', { params: { proveedor_id: proveedorId, page: pagina, por_pagina: 50 } })).data,
  })
  const proveedor = proveedores.data?.find((p) => String(p.id) === proveedorId)

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>Agotados por proveedor</h1>
        <p>Referencias con existencia cero. El listado llega paginado desde el servidor.</p>
      </div>
      <section className="medidas">
        <Medida etiqueta="Proveedor" valor={proveedor?.nombre ?? '—'} tono="azul" icono="catalogo" />
        <Medida etiqueta="Agotados" valor={info ? info.total.toLocaleString('es-CO') : '—'} tono="rojo" icono="agotados" />
        <Medida etiqueta="Página" valor={info ? `${info.actual} / ${info.ultima}` : '—'} tono="amarillo" icono="auditoria" />
        <Medida etiqueta="En esta página" valor={resultado ? filas.length : '—'} tono="verde" icono="caja" />
      </section>
    <section className="tarjeta-pagina">
      <div className="fila-titulo">
        <h2>Referencias</h2>
        <select aria-label="Proveedor" value={proveedorId} onChange={(e) => setProveedorId(e.target.value)}>
          <option value="">Elija un proveedor…</option>
          {proveedores.data?.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}
        </select>
      </div>

      {resultado && (
        <>
          <table>
            <thead><tr><th>Referencia</th><th>Universal</th><th>Marca</th><th>Categoría</th></tr></thead>
            <tbody>
              {filas.map((r) => (
                <tr key={r.id}>
                  <td><strong>{r.codigo_referencia}</strong> <small>{r.descripcion}</small></td>
                  <td>{r.codigo_universal?.codigo ?? '—'}</td>
                  <td>{r.marca?.nombre}</td>
                  <td>{r.categoria?.nombre}</td>
                </tr>
              ))}
              {filas.length === 0 && <tr><td colSpan={4} className="vacio">Este proveedor no tiene agotados.</td></tr>}
            </tbody>
          </table>
          <Paginacion pagina={resultado} onCambiar={irA} />
        </>
      )}
      {!proveedorId && <p className="vacio">Elija un proveedor para ver sus agotados.</p>}
    </section>
    </div>
  )
}
