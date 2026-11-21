import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { api, mensajeError } from '../../api/cliente'
import type { Ingreso, Pagina, TipoIngreso } from '../../api/tipos'
import { useListaPaginada } from '../../hooks/useListaPaginada'
import { useProveedores } from '../../hooks/useCatalogos'
import Medida from '../../componentes/Medida'
import Paginacion from '../../componentes/Paginacion'

interface FilaIngreso {
  id: number
  tipo: TipoIngreso
  numero_documento: string
  fecha: string
  estado: string
  unidades: string | null
  proveedor: { nombre: string }
  usuario: { name: string }
}

const TIPO: Record<TipoIngreso, string> = { remision: 'Remisión', factura: 'Factura' }

/** id del ingreso que ya tiene ese documento confirmado, si el backend lo rechazó por eso. */
function ingresoExistente(error: unknown): number | null {
  if (!(error instanceof AxiosError)) return null
  const datos = error.response?.data as { motivo?: string; ingreso_id?: number } | undefined
  return datos?.motivo === 'documento_confirmado' && datos.ingreso_id ? datos.ingreso_id : null
}

/**
 * RF-04 / RF-05: remisiones y facturas se crean a mano (tipo, proveedor, número y fecha) y los
 * ítems se escanean en el detalle (RF-20). Un documento repetido en borrador se reabre.
 */
export default function Ingresos() {
  const navegar = useNavigate()
  const proveedores = useProveedores()
  const [form, setForm] = useState({
    tipo: 'factura' as TipoIngreso,
    proveedor_id: '',
    numero_documento: '',
    fecha: new Date().toLocaleDateString('en-CA'),
    descuento_total: '0',
  })

  const { filas, resultado, info, irA } = useListaPaginada<FilaIngreso>({
    clave: ['ingresos'],
    traer: async (pagina) => (await api.get<Pagina<FilaIngreso>>('/ingresos', { params: { page: pagina } })).data,
  })

  const crear = useMutation({
    mutationFn: async () => (await api.post<Ingreso>('/ingresos', form)).data,
    onSuccess: (i) => navegar(`/logistica/ingresos/${i.id}`),
  })

  const borradores = filas.filter((i) => i.estado === 'borrador').length
  const existente = crear.isError ? ingresoExistente(crear.error) : null

  return (
    <div className="pila">
      <div className="pagina-encabezado">
        <h1>Ingresos</h1>
        <p>Remisiones y facturas se crean a mano; los ítems se agregan escaneándolos. Un documento no entra dos veces.</p>
      </div>
      <section className="medidas">
        <Medida etiqueta="Documentos" valor={info ? info.total.toLocaleString('es-CO') : '—'} tono="azul" icono="ingreso" />
        <Medida etiqueta="Página" valor={info ? `${info.actual} / ${info.ultima}` : '—'} tono="amarillo" icono="catalogo" />
        <Medida etiqueta="En esta página" valor={resultado ? filas.length : '—'} tono="verde" icono="caja" />
        <Medida etiqueta="Borradores aquí" valor={resultado ? borradores : '—'} tono="rojo" icono="auditoria" />
      </section>
      <section className="tarjeta-pagina">
        <h2>Nuevo ingreso</h2>
        <form className="formulario en-linea" onSubmit={(e) => { e.preventDefault(); crear.mutate() }}>
          <label>Tipo
            <select value={form.tipo} onChange={(e) => setForm({ ...form, tipo: e.target.value as TipoIngreso })}>
              <option value="factura">Factura</option>
              <option value="remision">Remisión</option>
            </select>
          </label>
          <label>Proveedor
            <select required value={form.proveedor_id} onChange={(e) => setForm({ ...form, proveedor_id: e.target.value })}>
              <option value="">—</option>
              {proveedores.data?.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}
            </select>
          </label>
          <label>N.º del documento<input required maxLength={60} value={form.numero_documento} onChange={(e) => setForm({ ...form, numero_documento: e.target.value })} /></label>
          <label>Fecha<input type="date" required value={form.fecha} onChange={(e) => setForm({ ...form, fecha: e.target.value })} /></label>
          <label>Descuento del documento<input type="number" min="0" step="0.01" value={form.descuento_total} onChange={(e) => setForm({ ...form, descuento_total: e.target.value })} /></label>
          <button className="primario" disabled={crear.isPending}>Crear borrador</button>
        </form>
        <p className="ayuda">Si ese documento ya está en borrador, se abre el mismo para seguir escaneando.</p>
        {crear.isError && (
          <div className="aviso error">
            {mensajeError(crear.error)}
            {existente && <> <Link to={`/logistica/ingresos/${existente}`}>Ver el ingreso #{existente}</Link></>}
          </div>
        )}
      </section>

      <section className="tarjeta-pagina">
        <div className="fila-titulo"><h2>Documentos</h2></div>
        <table>
          <thead><tr><th>#</th><th>Tipo</th><th>Documento</th><th>Proveedor</th><th>Fecha</th><th>Unidades</th><th>Estado</th><th>Usuario</th></tr></thead>
          <tbody>
            {filas.map((i) => (
              <tr key={i.id}>
                <td><Link to={`/logistica/ingresos/${i.id}`}>{i.id}</Link></td>
                <td>{TIPO[i.tipo] ?? i.tipo}</td>
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
        <Paginacion pagina={resultado} onCambiar={irA} />
      </section>
    </div>
  )
}
