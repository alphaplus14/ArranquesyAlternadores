import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import type { Ingreso, Pagina } from '../../api/tipos'
import { useProveedores } from '../../hooks/useCatalogos'
import Paginacion from '../../componentes/Paginacion'

interface FilaIngreso {
  id: number
  numero_documento: string
  fecha: string
  estado: string
  unidades: string | null
  proveedor: { nombre: string }
  usuario: { name: string }
}

/** RF-05: lista de ingresos y creación de un borrador por remisión. */
export default function Ingresos() {
  const navegar = useNavigate()
  const proveedores = useProveedores()
  const [pagina, setPagina] = useState(1)
  const [form, setForm] = useState({ proveedor_id: '', numero_documento: '', fecha: new Date().toISOString().slice(0, 10), descuento_total: '0' })

  const lista = useQuery({
    queryKey: ['ingresos', pagina],
    queryFn: async () => (await api.get<Pagina<FilaIngreso>>('/ingresos', { params: { page: pagina } })).data,
  })

  const crear = useMutation({
    mutationFn: async () => (await api.post<Ingreso>('/ingresos', form)).data,
    onSuccess: (i) => navegar(`/logistica/ingresos/${i.id}`),
  })

  return (
    <div className="panel">
      <h1>Ingreso por remisión</h1>
      <form className="formulario en-linea" onSubmit={(e) => { e.preventDefault(); crear.mutate() }}>
        <label>Proveedor
          <select required value={form.proveedor_id} onChange={(e) => setForm({ ...form, proveedor_id: e.target.value })}>
            <option value="">—</option>
            {proveedores.data?.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}
          </select>
        </label>
        <label>N.º de remisión<input required value={form.numero_documento} onChange={(e) => setForm({ ...form, numero_documento: e.target.value })} /></label>
        <label>Fecha<input type="date" required value={form.fecha} onChange={(e) => setForm({ ...form, fecha: e.target.value })} /></label>
        <label>Descuento del documento<input type="number" min="0" step="0.01" value={form.descuento_total} onChange={(e) => setForm({ ...form, descuento_total: e.target.value })} /></label>
        <button className="primario" disabled={crear.isPending}>Crear borrador</button>
      </form>
      {crear.isError && <div className="aviso error">{mensajeError(crear.error)}</div>}

      <table>
        <thead><tr><th>#</th><th>Documento</th><th>Proveedor</th><th>Fecha</th><th>Unidades</th><th>Estado</th><th>Usuario</th></tr></thead>
        <tbody>
          {lista.data?.data.map((i) => (
            <tr key={i.id}>
              <td><Link to={`/logistica/ingresos/${i.id}`}>{i.id}</Link></td>
              <td>{i.numero_documento}</td>
              <td>{i.proveedor.nombre}</td>
              <td>{i.fecha.slice(0, 10)}</td>
              <td>{i.unidades ?? 0}</td>
              <td><span className={`chip ${i.estado}`}>{i.estado}</span></td>
              <td>{i.usuario.name}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <Paginacion pagina={lista.data} onCambiar={setPagina} />
    </div>
  )
}
