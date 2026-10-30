import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import type { Pagina } from '../../api/tipos'
import { useCategorias } from '../../hooks/useCatalogos'
import { useDebounce } from '../../hooks/useDebounce'
import Paginacion from '../../componentes/Paginacion'

interface FilaUniversal { id: number; codigo: string; descripcion: string | null; existencia: string | null; categoria: { nombre: string } }

/** RF-01 / RF-02: códigos universales con su existencia calculada. */
export default function Universales() {
  const qc = useQueryClient()
  const [q, setQ] = useState('')
  const [pagina, setPagina] = useState(1)
  const [nuevo, setNuevo] = useState({ codigo: '', categoria_id: '', descripcion: '' })
  const termino = useDebounce(q.trim())
  const categorias = useCategorias()

  const lista = useQuery({
    queryKey: ['universales', termino, pagina],
    queryFn: async () => (await api.get<Pagina<FilaUniversal>>('/codigos-universales', { params: { q: termino || undefined, page: pagina } })).data,
    placeholderData: (previo) => previo,
  })

  const crear = useMutation({
    mutationFn: async () => api.post('/codigos-universales', nuevo),
    onSuccess: () => { setNuevo({ codigo: '', categoria_id: '', descripcion: '' }); qc.invalidateQueries({ queryKey: ['universales'] }) },
  })

  return (
    <div className="panel">
      <h1>Códigos universales</h1>
      <form className="formulario en-linea" onSubmit={(e) => { e.preventDefault(); crear.mutate() }}>
        <input required placeholder="Código (ej. IM204)" value={nuevo.codigo} onChange={(e) => setNuevo({ ...nuevo, codigo: e.target.value })} />
        <select required value={nuevo.categoria_id} onChange={(e) => setNuevo({ ...nuevo, categoria_id: e.target.value })}>
          <option value="">Categoría…</option>
          {categorias.data?.filter((c) => c.admite_codigo_universal).map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
        </select>
        <input placeholder="Descripción" value={nuevo.descripcion} onChange={(e) => setNuevo({ ...nuevo, descripcion: e.target.value })} />
        <button className="primario" disabled={crear.isPending}>Crear</button>
      </form>
      {crear.isError && <div className="aviso error">{mensajeError(crear.error)}</div>}

      <input placeholder="Buscar código universal" value={q} onChange={(e) => { setQ(e.target.value); setPagina(1) }} />
      <table>
        <thead><tr><th>Código</th><th>Categoría</th><th>Descripción</th><th>Existencia total</th></tr></thead>
        <tbody>
          {lista.data?.data.map((u) => (
            <tr key={u.id}>
              <td><Link to={`/admin/universales/${u.id}`}><strong>{u.codigo}</strong></Link></td>
              <td>{u.categoria.nombre}</td>
              <td>{u.descripcion}</td>
              <td>{Number(u.existencia ?? 0)}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <Paginacion pagina={lista.data} onCambiar={setPagina} />
    </div>
  )
}
