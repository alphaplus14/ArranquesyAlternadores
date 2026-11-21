import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { api, erroresCampo, mensajeError } from '../../api/cliente'
import type { FormaPago, Ingreso, Pagina, TipoIngreso } from '../../api/tipos'
import { sumarDias } from '../../componentes/fechas'
import CampoDescuento, { type ModoDescuento } from '../../componentes/CampoDescuento'
import { useListaPaginada } from '../../hooks/useListaPaginada'
import { useProveedores } from '../../hooks/useCatalogos'
import Medida from '../../componentes/Medida'
import Resumen from '../../componentes/Resumen'
import Paginacion from '../../componentes/Paginacion'

interface FilaIngreso {
  id: number
  tipo: TipoIngreso
  forma_pago: FormaPago
  vence_el: string | null
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
 * RF-16: contado o crédito; a crédito el vencimiento se prellena con el plazo del proveedor y se
 * corrige con el que trae el documento. El backend valida.
 */
export default function Ingresos() {
  const [creando, setCreando] = useState(false)
  const botonNuevo = useRef<HTMLButtonElement>(null)
  const cerrar = useCallback(() => {
    setCreando(false)
    // Al cerrar, el foco vuelve al botón que abrió la modal.
    requestAnimationFrame(() => botonNuevo.current?.focus())
  }, [])

  const { filas, resultado, info, irA } = useListaPaginada<FilaIngreso>({
    clave: ['ingresos'],
    traer: async (pagina) => (await api.get<Pagina<FilaIngreso>>('/ingresos', { params: { page: pagina } })).data,
  })

  const borradores = filas.filter((i) => i.estado === 'borrador').length

  return (
    <div className="pila">
      <div className="pagina-encabezado encabezado-con-accion">
        <div>
          <h1>Ingresos</h1>
          <p>Remisiones y facturas se crean a mano; los ítems se agregan escaneándolos. Un documento no entra dos veces.</p>
        </div>
        <button ref={botonNuevo} type="button" className="primario" onClick={() => setCreando(true)}>Nuevo ingreso</button>
      </div>
      <Resumen
        clave="ingresos-resumen"
        compacto={(
          <>
            <span><strong>{info ? info.total.toLocaleString('es-CO') : '—'}</strong> documentos</span>
            <span>Pág. <strong>{info ? `${info.actual}/${info.ultima}` : '—'}</strong></span>
            <span>En página <strong>{resultado ? filas.length : '—'}</strong></span>
            <span className={resultado && borradores > 0 ? 'resumen-agotados' : ''}>
              <strong>{resultado ? borradores : '—'}</strong> borradores
            </span>
          </>
        )}
      >
        <Medida etiqueta="Documentos" valor={info ? info.total.toLocaleString('es-CO') : '—'} tono="azul" icono="ingreso" />
        <Medida etiqueta="Página" valor={info ? `${info.actual} / ${info.ultima}` : '—'} tono="amarillo" icono="catalogo" />
        <Medida etiqueta="En esta página" valor={resultado ? filas.length : '—'} tono="verde" icono="caja" />
        <Medida etiqueta="Borradores aquí" valor={resultado ? borradores : '—'} tono="rojo" icono="auditoria" />
      </Resumen>
      <section className="tarjeta-pagina">
        <div className="fila-titulo"><h2>Documentos</h2></div>
        <div className="tabla-desplazable">
        <table>
          <thead><tr><th>#</th><th>Tipo</th><th>Documento</th><th>Proveedor</th><th>Fecha</th><th>Pago</th><th>Unidades</th><th>Estado</th><th>Usuario</th></tr></thead>
          <tbody>
            {filas.map((i) => (
              <tr key={i.id}>
                <td><Link to={`/logistica/ingresos/${i.id}`}>{i.id}</Link></td>
                <td>{TIPO[i.tipo] ?? i.tipo}</td>
                <td>{i.numero_documento}</td>
                <td>{i.proveedor.nombre}</td>
                <td>{i.fecha.slice(0, 10)}</td>
                <td>{i.forma_pago === 'credito' ? <span className="chip credito">Crédito{i.vence_el ? ` · vence ${i.vence_el.slice(0, 10)}` : ''}</span> : 'Contado'}</td>
                <td>{i.unidades ?? 0}</td>
                <td><span className={`chip ${i.estado}`}>{i.estado}</span></td>
                <td>{i.usuario.name}</td>
              </tr>
            ))}
          </tbody>
        </table>
        </div>
        <Paginacion pagina={resultado} onCambiar={irA} />
      </section>

      {creando && <DialogoNuevoIngreso onCerrar={cerrar} />}
    </div>
  )
}

/**
 * Modal "Nuevo ingreso": el mismo formulario de antes (tipo, proveedor, número, fecha, descuento,
 * pago y vencimiento). Se monta al abrir, así que cada apertura empieza con los valores iniciales.
 * Solo se cierra sola cuando la creación tiene éxito (navega al detalle); con error queda abierta.
 */
function DialogoNuevoIngreso({ onCerrar }: { onCerrar: () => void }) {
  const navegar = useNavigate()
  const proveedores = useProveedores()
  const [form, setForm] = useState({
    tipo: 'factura' as TipoIngreso,
    proveedor_id: '',
    numero_documento: '',
    fecha: new Date().toLocaleDateString('en-CA'),
    forma_pago: 'contado' as FormaPago,
  })
  // S-22: el descuento del documento es opcional (0), en pesos o en %, en remisiones y facturas.
  const [modoDescuento, setModoDescuento] = useState<ModoDescuento>('$')
  const [descuento, setDescuento] = useState('0')
  /** null = sin tocar: se sugiere fecha + plazo del proveedor. */
  const [venceEscrito, setVenceEscrito] = useState<string | null>(null)
  const plazo = proveedores.data?.find((p) => String(p.id) === form.proveedor_id)?.plazo_dias ?? null
  const venceSugerido = plazo !== null && form.fecha ? sumarDias(form.fecha, plazo) : ''
  const venceEl = venceEscrito ?? venceSugerido

  const crear = useMutation({
    mutationFn: async () =>
      (await api.post<Ingreso>('/ingresos', {
        ...form,
        vence_el: form.forma_pago === 'credito' ? venceEl || null : null,
        [modoDescuento === '%' ? 'descuento_documento_porcentaje' : 'descuento_documento']: descuento === '' ? 0 : descuento,
      })).data,
    onSuccess: (i) => navegar(`/logistica/ingresos/${i.id}`),
  })
  const existente = crear.isError ? ingresoExistente(crear.error) : null
  const errores = crear.isError ? erroresCampo(crear.error) : {}

  // Esc cierra; el fondo no se desplaza mientras la modal está abierta.
  useEffect(() => {
    const alPulsar = (e: KeyboardEvent) => { if (e.key === 'Escape') onCerrar() }
    document.addEventListener('keydown', alPulsar)
    const desbordeAnterior = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', alPulsar)
      document.body.style.overflow = desbordeAnterior
    }
  }, [onCerrar])

  return (
    <div className="modal modal-ingreso">
      <form
        className="modal-caja cuenta-modal ingreso-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="nuevo-ingreso-titulo"
        onSubmit={(e) => { e.preventDefault(); crear.mutate() }}
      >
        <header className="modal-cabeza">
          <div className="modal-cabeza-texto">
            <h2 id="nuevo-ingreso-titulo">Nuevo ingreso</h2>
          </div>
          <button type="button" className="icono-boton modal-cerrar" aria-label="Cerrar" onClick={onCerrar}>
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" aria-hidden><path d="M6 6l12 12M18 6L6 18" /></svg>
          </button>
        </header>

        <section className="modal-seccion ingreso-modal-cuerpo">
          <div className="ingreso-campos">
            <label>Tipo
              <select autoFocus value={form.tipo} onChange={(e) => setForm({ ...form, tipo: e.target.value as TipoIngreso })}>
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
            <CampoDescuento
              id="descuento-nuevo"
              modo={modoDescuento}
              valor={descuento}
              error={errores.descuento_documento ?? errores.descuento_documento_porcentaje}
              ayuda={modoDescuento === '%' ? 'Se aplica sobre las líneas que escanee.' : undefined}
              onModo={setModoDescuento}
              onValor={setDescuento}
            />
            <label>Pago
              <select value={form.forma_pago} onChange={(e) => setForm({ ...form, forma_pago: e.target.value as FormaPago })}>
                <option value="contado">Contado</option>
                <option value="credito">Crédito</option>
              </select>
            </label>
            {form.forma_pago === 'credito' && (
              <label>Vence el
                <input type="date" aria-invalid={!!errores.vence_el} value={venceEl} onChange={(e) => setVenceEscrito(e.target.value)} />
                {errores.vence_el
                  ? <small className="ayuda error">{errores.vence_el}</small>
                  : <small className="ayuda">{venceEscrito === null && plazo !== null ? `Fecha + ${plazo} días del proveedor; corríjalo con el del documento.` : 'El que trae el documento.'}</small>}
              </label>
            )}
          </div>
          <p className="ayuda">Si ese documento ya está en borrador, se abre el mismo para seguir escaneando.</p>
          {crear.isError && (
            <div className="aviso error" role="alert">
              {mensajeError(crear.error)}
              {existente && <> <Link to={`/logistica/ingresos/${existente}`}>Ver el ingreso #{existente}</Link></>}
            </div>
          )}
        </section>

        <footer className="modal-pie">
          <button type="button" className="secundario" onClick={onCerrar}>Cancelar</button>
          <button className="primario" disabled={crear.isPending}>{crear.isPending ? 'Creando…' : 'Crear borrador'}</button>
        </footer>
      </form>
    </div>
  )
}
