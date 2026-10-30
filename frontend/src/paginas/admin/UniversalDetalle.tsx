import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../api/cliente'
import type { DesgloseUniversal } from '../../api/tipos'

/** RF-02: el total del universal es la suma de sus referencias; siempre se muestra el desglose. */
export default function UniversalDetalle() {
  const { id } = useParams()
  const { data: u } = useQuery({
    queryKey: ['universal', id],
    queryFn: async () => (await api.get<DesgloseUniversal>(`/codigos-universales/${id}`)).data,
  })

  if (!u) return <div className="panel">Cargando…</div>

  return (
    <div className="panel">
      <div className="fila-titulo">
        <h1>{u.codigo} <small>{u.categoria.nombre}</small></h1>
        <div className="total-grande">{u.existencia_total} <span>unidades</span></div>
      </div>
      <p>{u.descripcion}</p>

      <h3>Por marca</h3>
      <div className="tarjetas">
        {u.por_marca.map((m) => (
          <div key={m.marca_id} className={`tarjeta ${m.existencia === 0 ? 'agotado' : ''}`}>
            <span>{m.marca}</span><strong>{m.existencia}</strong>
          </div>
        ))}
      </div>

      <h3>Por referencia</h3>
      <table>
        <thead><tr><th>Referencia</th><th>Marca</th><th>Proveedor</th><th>Existencia</th><th>Actualizada</th><th></th></tr></thead>
        <tbody>
          {u.referencias.map((r) => (
            <tr key={r.id}>
              <td><strong>{r.codigo_referencia}</strong> <small>{r.descripcion}</small></td>
              <td>{r.marca.nombre}</td>
              <td>{r.proveedor.nombre}</td>
              <td className={r.existencia === 0 ? 'agotado' : ''}>{r.existencia}</td>
              <td>{new Date(r.updated_at).toLocaleString()}</td>
              <td><Link to={`/admin/auditoria?tabla=referencias&registro_id=${r.id}`}>Historial</Link></td>
            </tr>
          ))}
        </tbody>
        <tfoot><tr><td colSpan={3}>Total</td><td><strong>{u.existencia_total}</strong></td><td colSpan={2}></td></tr></tfoot>
      </table>
    </div>
  )
}
