import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../api/cliente'
import type { Pagina, Referencia } from '../api/tipos'
import { useProveedores } from '../hooks/useCatalogos'
import Paginacion from '../componentes/Paginacion'

/** RF-08: referencias con existencia 0 de un proveedor. */
export default function Agotados() {
  const proveedores = useProveedores()
  const [proveedorId, setProveedorId] = useState('')
  const [pagina, setPagina] = useState(1)

  const lista = useQuery({
    queryKey: ['agotados', proveedorId, pagina],
    queryFn: async () => (await api.get<Pagina<Referencia>>('/reportes/agotados', { params: { proveedor_id: proveedorId, page: pagina } })).data,
    enabled: proveedorId !== '',
  })

  return (
    <div className="panel">
      <h1>Agotados por proveedor</h1>
      <select value={proveedorId} onChange={(e) => { setProveedorId(e.target.value); setPagina(1) }}>
        <option value="">Elija un proveedor…</option>
        {proveedores.data?.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}
      </select>

      {lista.data && (
        <>
          <table>
            <thead><tr><th>Referencia</th><th>Universal</th><th>Marca</th><th>Categoría</th></tr></thead>
            <tbody>
              {lista.data.data.map((r) => (
                <tr key={r.id}>
                  <td><strong>{r.codigo_referencia}</strong> <small>{r.descripcion}</small></td>
                  <td>{r.codigo_universal?.codigo ?? '—'}</td>
                  <td>{r.marca?.nombre}</td>
                  <td>{r.categoria?.nombre}</td>
                </tr>
              ))}
              {lista.data.data.length === 0 && <tr><td colSpan={4} className="vacio">Este proveedor no tiene agotados.</td></tr>}
            </tbody>
          </table>
          <Paginacion pagina={lista.data} onCambiar={setPagina} />
        </>
      )}
    </div>
  )
}
