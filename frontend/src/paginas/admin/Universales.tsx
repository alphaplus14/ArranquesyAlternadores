import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import type { Pagina } from '../../api/tipos'
import { useListaPaginada } from '../../hooks/useListaPaginada'
import { useCategorias } from '../../hooks/useCatalogos'
import { useDebounce } from '../../hooks/useDebounce'
import Medida from '../../componentes/Medida'
import Paginacion from '../../componentes/Paginacion'

interface FilaUniversal { id: number; codigo: string; descripcion: string | null; existencia: string | null; categoria: { nombre: string } }

/** RF-01 / RF-02: códigos universales con su existencia calculada. */
export default function Universales() {
  const qc = useQueryClient()
  const [q, setQ] = useState('')
  const [nuevo, setNuevo] = useState({ codigo: '', categoria_id: '', descripcion: '' })
  const termino = useDebounce(q.trim())
  const categorias = useCategorias()

  const { filas, resultado, info, irA } = useListaPaginada<FilaUniversal>({
    clave: ['universales', termino],
    filtros: q,
    traer: async (pagina) => (await api.get<Pagina<FilaUniversal>>('/codigos-universales', { params: { q: termino || undefined, page: pagina } })).data,
  })

  const crear = useMutation({
    mutationFn: async () => api.post('/codigos-universales', nuevo),
    onSuccess: () => { setNuevo({ codigo: '', categoria_id: '', descripcion: '' }); qc.invalidateQueries({ queryKey: ['universales'] }) },
  })

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>Códigos universales</h1>
        <p>La existencia del universal es la suma de sus referencias. El detalle no se pierde.</p>
      </div>
      <section className="medidas">
        <Medida etiqueta="Universales" valor={info ? info.total.toLocaleString('es-CO') : '—'} tono="azul" icono="universal" />
        <Medida etiqueta="Página" valor={info ? `${info.actual} / ${info.ultima}` : '—'} tono="amarillo" icono="catalogo" />
        <Medida etiqueta="En esta página" valor={resultado ? filas.length : '—'} tono="verde" icono="caja" />
        <Medida etiqueta="Búsqueda" valor={termino || 'Todas'} tono="rojo" icono="buscar" codigo={!!termino} />
      </section>
      <section className="tarjeta-pagina">
      <h2>Nuevo código</h2>
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
      </section>

      <section className="tarjeta-pagina">
      <div className="fila-titulo">
        <h2>Listado</h2>
        <label className="busca">
          <input aria-label="Buscar código universal" placeholder="Buscar código universal" value={q} onChange={(e) => setQ(e.target.value)} />
        </label>
      </div>
      <table>
        <thead><tr><th>Código</th><th>Categoría</th><th>Descripción</th><th>Existencia total</th></tr></thead>
        <tbody>
          {filas.map((u) => (
            <tr key={u.id}>
              <td><Link to={`/admin/universales/${u.id}`}><strong>{u.codigo}</strong></Link></td>
              <td>{u.categoria.nombre}</td>
              <td>{u.descripcion}</td>
              <td>{Number(u.existencia ?? 0)}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <Paginacion pagina={resultado} onCambiar={irA} />
      </section>
    </div>
  )
}
