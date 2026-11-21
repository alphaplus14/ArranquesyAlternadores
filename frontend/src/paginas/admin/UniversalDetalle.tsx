import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../api/cliente'
import type { DesgloseUniversal } from '../../api/tipos'
import Medida from '../../componentes/Medida'
import Resumen from '../../componentes/Resumen'

/** RF-02: el total del universal es la suma de sus referencias; siempre se muestra el desglose. */
export default function UniversalDetalle() {
  const { id } = useParams()
  const { data: u } = useQuery({
    queryKey: ['universal', id],
    queryFn: async () => (await api.get<DesgloseUniversal>(`/codigos-universales/${id}`)).data,
  })

  if (!u) return <div className="panel">Cargando…</div>

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>{u.codigo}</h1>
        <p>{u.categoria.nombre}{u.descripcion ? ` · ${u.descripcion}` : ''}</p>
      </div>
      <Resumen
        clave="universal-detalle-resumen"
        compacto={(
          <>
            <span>Existencia <strong>{u.existencia_total}</strong></span>
            <span>Referencias <strong>{u.referencias.length}</strong></span>
            <span>Marcas <strong>{u.por_marca.length}</strong></span>
            <span className={u.por_marca.some((m) => m.existencia === 0) ? 'resumen-agotados' : ''}>
              Agotadas <strong>{u.por_marca.filter((m) => m.existencia === 0).length}</strong>
            </span>
          </>
        )}
      >
        <Medida etiqueta="Existencia total" valor={u.existencia_total} tono="azul" icono="universal" />
        <Medida etiqueta="Referencias" valor={u.referencias.length} tono="amarillo" icono="caja" />
        <Medida etiqueta="Marcas" valor={u.por_marca.length} tono="verde" icono="catalogo" />
        <Medida etiqueta="Agotadas" valor={u.por_marca.filter((m) => m.existencia === 0).length} tono="rojo" icono="agotados" />
      </Resumen>
      <section className="medidas">
        {u.por_marca.map((m) => (
          <Medida key={m.marca_id} etiqueta={m.marca} valor={m.existencia} tono={m.existencia === 0 ? 'rojo' : 'verde'} icono="etiqueta" />
        ))}
      </section>
    <div className="panel">
      <h2>Por referencia</h2>
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
    </div>
  )
}
