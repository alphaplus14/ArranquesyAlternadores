import { useSearchParams } from 'react-router-dom'
import { api } from '../../api/cliente'
import type { Auditoria as TAuditoria, Pagina } from '../../api/tipos'
import { useListaPaginada } from '../../hooks/useListaPaginada'
import Medida from '../../componentes/Medida'
import Resumen from '../../componentes/Resumen'
import Paginacion from '../../componentes/Paginacion'

const TABLAS = ['referencias', 'codigos_universales', 'proveedores', 'categorias', 'clientes', 'ingresos', 'ingreso_items', 'users', 'venta_items', 'configuraciones']

/** RF-11: valor anterior, valor nuevo, usuario y fecha de cada edición. */
export default function Auditoria() {
  const [params, setParams] = useSearchParams()
  const tabla = params.get('tabla') ?? ''
  const registroId = params.get('registro_id') ?? ''

  const { filas, resultado, info, irA } = useListaPaginada<TAuditoria>({
    clave: ['auditoria', tabla, registroId],
    filtros: [tabla, registroId],
    traer: async (pagina) => (await api.get<Pagina<TAuditoria>>('/auditoria', {
      params: { tabla: tabla || undefined, registro_id: registroId || undefined, page: pagina },
    })).data,
  })

  const filtrar = (cambios: Record<string, string>) => {
    const p = { tabla, registro_id: registroId, ...cambios }
    setParams(Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '')))
  }

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>Auditoría</h1>
        <p>Cada edición guarda el valor anterior, el nuevo, quién lo cambió y cuándo.</p>
      </div>
      <Resumen
        clave="auditoria-resumen"
        compacto={(
          <>
            <span><strong>{info ? info.total.toLocaleString('es-CO') : '—'}</strong> ediciones</span>
            <span>Pág. <strong>{info ? `${info.actual}/${info.ultima}` : '—'}</strong></span>
            <span>Tabla <strong>{tabla || 'Todas'}</strong></span>
            <span>Registro <strong>{registroId || '—'}</strong></span>
          </>
        )}
      >
        <Medida etiqueta="Ediciones" valor={info ? info.total.toLocaleString('es-CO') : '—'} tono="azul" icono="auditoria" />
        <Medida etiqueta="Página" valor={info ? `${info.actual} / ${info.ultima}` : '—'} tono="amarillo" icono="catalogo" />
        <Medida etiqueta="Tabla" valor={tabla || 'Todas'} tono="verde" icono="caja" codigo={!!tabla} />
        <Medida etiqueta="Registro" valor={registroId || '—'} tono="rojo" icono="buscar" />
      </Resumen>
    <section className="tarjeta-pagina">
      <div className="fila-titulo">
        <h2>Historial</h2>
      <div className="formulario en-linea">
        <select value={tabla} onChange={(e) => filtrar({ tabla: e.target.value, registro_id: '' })}>
          <option value="">Todas las tablas</option>
          {TABLAS.map((t) => <option key={t}>{t}</option>)}
        </select>
        <input aria-label="Id del registro" placeholder="Id del registro" disabled={!tabla} value={registroId} onChange={(e) => filtrar({ registro_id: e.target.value })} />
      </div>
      </div>
      {/* Scroll horizontal propio: la página nunca se desborda. En < 768 px, cada fila es una tarjeta. */}
      <div className="tabla-auditoria-scroll">
        <table className="tabla-auditoria">
          <colgroup>
            <col className="col-fecha" /><col className="col-usuario" /><col className="col-tabla" />
            <col className="col-registro" /><col className="col-campo" /><col /><col />
          </colgroup>
          <thead><tr><th>Fecha</th><th>Usuario</th><th>Tabla</th><th>Registro</th><th>Campo</th><th>Antes</th><th>Después</th></tr></thead>
          <tbody>
            {filas.map((a) => (
              <tr key={a.id}>
                <td data-etiqueta="Fecha">{new Date(a.created_at).toLocaleString()}</td>
                <td data-etiqueta="Usuario">{a.usuario?.name ?? 'sistema'}</td>
                <td data-etiqueta="Tabla">{a.tabla}</td>
                <td data-etiqueta="Registro">{a.registro_id}</td>
                <td data-etiqueta="Campo">{a.campo}</td>
                <td data-etiqueta="Antes" className="antes valor-auditoria">{a.valor_anterior}</td>
                <td data-etiqueta="Después" className="despues valor-auditoria">{a.valor_nuevo}</td>
              </tr>
            ))}
            {resultado && filas.length === 0 && <tr><td colSpan={7} className="vacio">Sin ediciones registradas.</td></tr>}
          </tbody>
        </table>
      </div>
      <Paginacion pagina={resultado} onCambiar={irA} />
    </section>
    </div>
  )
}
