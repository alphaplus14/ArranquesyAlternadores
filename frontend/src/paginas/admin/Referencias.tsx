import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import type { Pagina, Referencia } from '../../api/tipos'
import { useCategorias, useMarcas, useProveedores } from '../../hooks/useCatalogos'
import { useDebounce } from '../../hooks/useDebounce'
import Paginacion from '../../componentes/Paginacion'

type Formulario = {
  codigo_referencia: string
  marca_id: string
  proveedor_id: string
  categoria_id: string
  codigo_universal_id: string
  descripcion: string
  precio_venta: string
}

const vacio: Formulario = { codigo_referencia: '', marca_id: '', proveedor_id: '', categoria_id: '', codigo_universal_id: '', descripcion: '', precio_venta: '' }

/** RF-01: catálogo de referencias con búsqueda y paginación en servidor. RF-11: cada edición queda auditada. */
export default function Referencias() {
  const qc = useQueryClient()
  const [q, setQ] = useState('')
  const [proveedorId, setProveedorId] = useState('')
  const [pagina, setPagina] = useState(1)
  const [editando, setEditando] = useState<{ id: number | null; datos: Formulario } | null>(null)
  const termino = useDebounce(q.trim())
  const proveedores = useProveedores()

  const lista = useQuery({
    queryKey: ['referencias', termino, proveedorId, pagina],
    queryFn: async () => (await api.get<Pagina<Referencia>>('/referencias', {
      params: { q: termino || undefined, proveedor_id: proveedorId || undefined, page: pagina },
    })).data,
    placeholderData: (previo) => previo,
  })

  const editar = (r: Referencia) => setEditando({
    id: r.id,
    datos: {
      codigo_referencia: r.codigo_referencia,
      marca_id: String(r.marca_id),
      proveedor_id: String(r.proveedor_id),
      categoria_id: String(r.categoria_id),
      codigo_universal_id: r.codigo_universal_id ? String(r.codigo_universal_id) : '',
      descripcion: r.descripcion,
      precio_venta: r.precio_venta,
    },
  })

  return (
    <div className="panel">
      <div className="fila-titulo">
        <h1>Referencias</h1>
        <button className="primario" onClick={() => setEditando({ id: null, datos: vacio })}>Nueva referencia</button>
      </div>
      <div className="formulario en-linea">
        <input placeholder="Buscar por código o descripción" value={q} onChange={(e) => { setQ(e.target.value); setPagina(1) }} />
        <select value={proveedorId} onChange={(e) => { setProveedorId(e.target.value); setPagina(1) }}>
          <option value="">Todos los proveedores</option>
          {proveedores.data?.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}
        </select>
      </div>

      <table>
        <thead><tr><th>Referencia</th><th>Universal</th><th>Marca</th><th>Proveedor</th><th>Categoría</th><th>Precio</th><th>Existencia</th><th></th></tr></thead>
        <tbody>
          {lista.data?.data.map((r) => (
            <tr key={r.id}>
              <td><strong>{r.codigo_referencia}</strong> <small>{r.descripcion}</small></td>
              <td>{r.codigo_universal ? <Link to={`/admin/universales/${r.codigo_universal.id}`}>{r.codigo_universal.codigo}</Link> : '—'}</td>
              <td>{r.marca?.nombre}</td>
              <td>{r.proveedor?.nombre}</td>
              <td>{r.categoria?.nombre}</td>
              <td>{Number(r.precio_venta).toLocaleString('es-CO')}</td>
              <td className={r.existencia === 0 ? 'agotado' : ''}>{r.existencia}</td>
              <td>
                <button className="enlace" onClick={() => editar(r)}>Editar</button>{' '}
                <Link to={`/admin/auditoria?tabla=referencias&registro_id=${r.id}`}>Historial</Link>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <Paginacion pagina={lista.data} onCambiar={setPagina} />

      {editando && (
        <FormularioReferencia
          id={editando.id}
          inicial={editando.datos}
          onListo={() => { setEditando(null); qc.invalidateQueries({ queryKey: ['referencias'] }) }}
          onCerrar={() => setEditando(null)}
        />
      )}
    </div>
  )
}

function FormularioReferencia({ id, inicial, onListo, onCerrar }: { id: number | null; inicial: Formulario; onListo: () => void; onCerrar: () => void }) {
  const [f, setF] = useState(inicial)
  const marcas = useMarcas()
  const proveedores = useProveedores()
  const categorias = useCategorias()

  const categoria = categorias.data?.find((c) => String(c.id) === f.categoria_id)
  const admiteUniversal = categoria?.admite_codigo_universal ?? false

  const universales = useQuery({
    queryKey: ['universales', 'categoria', f.categoria_id],
    queryFn: async () => (await api.get<Pagina<{ id: number; codigo: string; categoria_id: number }>>('/codigos-universales', { params: { por_pagina: 100 } })).data.data,
    enabled: admiteUniversal,
  })

  const guardar = useMutation({
    mutationFn: async () => {
      // RF-01: si la categoría no admite universal, se envía null.
      const datos = { ...f, codigo_universal_id: admiteUniversal && f.codigo_universal_id ? Number(f.codigo_universal_id) : null }
      return id ? api.put(`/referencias/${id}`, datos) : api.post('/referencias', datos)
    },
    onSuccess: onListo,
  })

  const campo = (k: keyof Formulario) => ({ value: f[k], onChange: (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value }) })

  return (
    <div className="modal">
      <form className="modal-caja" onSubmit={(e) => { e.preventDefault(); guardar.mutate() }}>
        <h2>{id ? 'Editar referencia' : 'Nueva referencia'}</h2>
        <label>Código de referencia<input required {...campo('codigo_referencia')} /></label>
        <label>Descripción<input required {...campo('descripcion')} /></label>
        <label>Marca
          <select required {...campo('marca_id')}><option value="">—</option>{marcas.data?.map((m) => <option key={m.id} value={m.id}>{m.nombre}</option>)}</select>
        </label>
        <label>Proveedor
          <select required {...campo('proveedor_id')}><option value="">—</option>{proveedores.data?.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}</select>
        </label>
        <label>Categoría
          <select required {...campo('categoria_id')}><option value="">—</option>{categorias.data?.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}</select>
        </label>
        {admiteUniversal && (
          <label>Código universal (opcional)
            <select {...campo('codigo_universal_id')}>
              <option value="">Sin universal: la referencia es su código único</option>
              {universales.data?.filter((u) => String(u.categoria_id) === f.categoria_id).map((u) => <option key={u.id} value={u.id}>{u.codigo}</option>)}
            </select>
          </label>
        )}
        <label>Precio de venta<input type="number" min="0" step="0.01" required {...campo('precio_venta')} /></label>
        {guardar.isError && <div className="aviso error">{mensajeError(guardar.error)}</div>}
        <div className="acciones">
          <button type="button" onClick={onCerrar}>Cancelar</button>
          <button className="primario" disabled={guardar.isPending}>Guardar</button>
        </div>
      </form>
    </div>
  )
}
