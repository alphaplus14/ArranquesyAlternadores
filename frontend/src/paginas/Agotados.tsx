import { useState } from 'react'
import { api } from '../api/cliente'
import type { Pagina, Referencia } from '../api/tipos'
import { useProveedores } from '../hooks/useCatalogos'
import { useListaPaginada } from '../hooks/useListaPaginada'
import Medida from '../componentes/Medida'
import Resumen from '../componentes/Resumen'
import Paginacion from '../componentes/Paginacion'
import SelectorProveedor from '../componentes/SelectorProveedor'
import ChipCalidad from '../componentes/ChipCalidad'

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
      <Resumen
        clave="agotados-resumen"
        compacto={(
          <>
            <span>Proveedor <strong>{proveedor?.nombre ?? '—'}</strong></span>
            <span className={info && info.total > 0 ? 'resumen-agotados' : ''}><strong>{info ? info.total.toLocaleString('es-CO') : '—'}</strong> agotados</span>
            <span>Pág. <strong>{info ? `${info.actual}/${info.ultima}` : '—'}</strong></span>
            <span>En página <strong>{resultado ? filas.length : '—'}</strong></span>
          </>
        )}
      >
        <Medida etiqueta="Proveedor" valor={proveedor?.nombre ?? '—'} tono="azul" icono="catalogo" />
        <Medida etiqueta="Agotados" valor={info ? info.total.toLocaleString('es-CO') : '—'} tono="rojo" icono="agotados" />
        <Medida etiqueta="Página" valor={info ? `${info.actual} / ${info.ultima}` : '—'} tono="amarillo" icono="auditoria" />
        <Medida etiqueta="En esta página" valor={resultado ? filas.length : '—'} tono="verde" icono="caja" />
      </Resumen>
    <section className="tarjeta-pagina">
      <div className="fila-titulo">
        <h2>Referencias</h2>
        <SelectorProveedor className="filtro" aria-label="Proveedor" value={proveedorId} onChange={setProveedorId} placeholder="Elija un proveedor…" limpiable />
      </div>

      {resultado && (
        <>
          <table>
            <thead><tr><th>Referencia</th><th>Universal</th><th>Calidad</th><th>Categoría</th></tr></thead>
            <tbody>
              {filas.map((r) => (
                <tr key={r.id}>
                  <td><strong>{r.codigo_referencia}</strong> <small>{r.descripcion}</small></td>
                  <td>{r.codigo_universal?.codigo ?? '—'}</td>
                  <td><ChipCalidad calidad={r.calidad} /></td>
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
