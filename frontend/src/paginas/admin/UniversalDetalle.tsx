import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import { useAvisoTexto } from '../../componentes/Avisos'
import type { DesgloseUniversal } from '../../api/tipos'
import ChipCalidad from '../../componentes/ChipCalidad'
import Icono from '../../componentes/Icono'
import Medida from '../../componentes/Medida'
import Resumen from '../../componentes/Resumen'

/**
 * RF-02 / S-23: el total del universal es la suma de sus referencias y siempre se muestra el
 * desglose por referencia (código, proveedor, calidad y existencia). Ej.: IM204 = 6 → AC-77812 2…
 */
export default function UniversalDetalle() {
  const { id } = useParams()
  const { data: u, isError, error } = useQuery({
    queryKey: ['universal', id],
    queryFn: async () => (await api.get<DesgloseUniversal>(`/codigos-universales/${id}`)).data,
  })
  useAvisoTexto(isError ? mensajeError(error) : null)

  if (isError) return <div className="panel">No se pudo cargar el universal.</div>
  if (!u) return <div className="panel">Cargando…</div>

  const proveedores = new Set(u.referencias.map((r) => r.proveedor.id)).size
  const agotadas = u.referencias.filter((r) => r.existencia === 0).length

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
            <span>Proveedores <strong>{proveedores}</strong></span>
            <span className={agotadas > 0 ? 'resumen-agotados' : ''}>Agotadas <strong>{agotadas}</strong></span>
          </>
        )}
      >
        <Medida etiqueta="Existencia total" valor={u.existencia_total} tono="azul" icono="universal" />
        <Medida etiqueta="Referencias" valor={u.referencias.length} tono="amarillo" icono="caja" />
        <Medida etiqueta="Proveedores" valor={proveedores} tono="verde" icono="catalogo" />
        <Medida etiqueta="Agotadas" valor={agotadas} tono="rojo" icono="agotados" />
      </Resumen>
      <div className="panel">
        <div className="fila-titulo">
          <h2>Por referencia</h2>
          <span className="total-universal">{u.codigo} = <strong>{u.existencia_total}</strong></span>
        </div>
        <div className="tabla-desplazable">
          <table>
            <thead><tr><th>Referencia</th><th>Proveedor</th><th>Calidad</th><th>Existencia</th><th>Actualizada</th><th></th></tr></thead>
            <tbody>
              {u.referencias.map((r) => (
                <tr key={r.id}>
                  <td><strong>{r.codigo_referencia}</strong> <small>{r.descripcion}</small></td>
                  <td>{r.proveedor.nombre}</td>
                  <td>{r.calidad ? <ChipCalidad calidad={r.calidad} /> : '—'}</td>
                  <td className={r.existencia === 0 ? 'agotado' : ''}>{r.existencia}</td>
                  <td>{new Date(r.updated_at).toLocaleString()}</td>
                  <td className="col-accion">
                    <Link
                      className="icono-accion"
                      to={`/admin/auditoria?tabla=referencias&registro_id=${r.id}`}
                      aria-label={`Historial de ${r.codigo_referencia}`}
                      title="Historial"
                    >
                      <Icono nombre="auditoria" />
                    </Link>
                  </td>
                </tr>
              ))}
              {u.referencias.length === 0 && <tr><td colSpan={6} className="vacio">Este universal todavía no tiene referencias.</td></tr>}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
