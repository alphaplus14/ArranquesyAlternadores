import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../api/cliente'
import type { Auditoria as TAuditoria, Pagina } from '../../api/tipos'
import Paginacion from '../../componentes/Paginacion'

const TABLAS = ['referencias', 'codigos_universales', 'marcas', 'proveedores', 'categorias', 'users']

/** RF-11: valor anterior, valor nuevo, usuario y fecha de cada edición. */
export default function Auditoria() {
  const [params, setParams] = useSearchParams()
  const [pagina, setPagina] = useState(1)
  const tabla = params.get('tabla') ?? ''
  const registroId = params.get('registro_id') ?? ''

  const lista = useQuery({
    queryKey: ['auditoria', tabla, registroId, pagina],
    queryFn: async () => (await api.get<Pagina<TAuditoria>>('/auditoria', {
      params: { tabla: tabla || undefined, registro_id: registroId || undefined, page: pagina },
    })).data,
  })

  const filtrar = (cambios: Record<string, string>) => {
    const p = { tabla, registro_id: registroId, ...cambios }
    setParams(Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '')))
    setPagina(1)
  }

  return (
    <div className="panel">
      <h1>Auditoría</h1>
      <div className="formulario en-linea">
        <select value={tabla} onChange={(e) => filtrar({ tabla: e.target.value, registro_id: '' })}>
          <option value="">Todas las tablas</option>
          {TABLAS.map((t) => <option key={t}>{t}</option>)}
        </select>
        <input placeholder="Id del registro" disabled={!tabla} value={registroId} onChange={(e) => filtrar({ registro_id: e.target.value })} />
      </div>
      <table>
        <thead><tr><th>Fecha</th><th>Usuario</th><th>Tabla</th><th>Registro</th><th>Campo</th><th>Antes</th><th>Después</th></tr></thead>
        <tbody>
          {lista.data?.data.map((a) => (
            <tr key={a.id}>
              <td>{new Date(a.created_at).toLocaleString()}</td>
              <td>{a.usuario?.name ?? 'sistema'}</td>
              <td>{a.tabla}</td>
              <td>{a.registro_id}</td>
              <td>{a.campo}</td>
              <td className="antes">{a.valor_anterior}</td>
              <td className="despues">{a.valor_nuevo}</td>
            </tr>
          ))}
          {lista.data?.data.length === 0 && <tr><td colSpan={7} className="vacio">Sin ediciones registradas.</td></tr>}
        </tbody>
      </table>
      <Paginacion pagina={lista.data} onCambiar={setPagina} />
    </div>
  )
}
