import { useCallback, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { api, erroresCampo, mensajeError } from '../../api/cliente'
import { useAvisoTexto } from '../../componentes/Avisos'
import Dialogo, { DialogoSeccion } from '../../componentes/Dialogo'
import type { FormaPago, Ingreso, Pagina, TipoIngreso } from '../../api/tipos'
import { sumarDias } from '../../componentes/fechas'
import { hoyEnBogota } from '../../utilidades/periodos'
import ControlSegmentado, { type OpcionSegmento } from '../../componentes/ControlSegmentado'
import SelectorProveedor from '../../componentes/SelectorProveedor'
import { useListaPaginada } from '../../hooks/useListaPaginada'
import { useProveedores } from '../../hooks/useCatalogos'
import Medida from '../../componentes/Medida'
import Resumen from '../../componentes/Resumen'
import Paginacion from '../../componentes/Paginacion'
import Icono from '../../componentes/Icono'
import BotonFila from '../../componentes/BotonFila'
import BotonCancelar from '../../componentes/BotonCancelar'

interface FilaIngreso {
  id: number
  tipo: TipoIngreso
  forma_pago: FormaPago
  vence_el: string | null
  numero_documento: string
  fecha: string
  estado: string
  unidades: string | null
  proveedor: { nombre: string; asesor?: string | null }
  usuario: { name: string }
  /** RF-28: el ingreso confirmado tiene correcciones. */
  corregido?: boolean
  correcciones_count?: number
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

  // RF-28: "Solo corregidas" filtra en el servidor.
  const [soloCorregidos, setSoloCorregidos] = useState(false)
  const { filas, resultado, info, irA } = useListaPaginada<FilaIngreso>({
    clave: ['ingresos', soloCorregidos],
    filtros: soloCorregidos,
    porPagina: { defecto: 25 }, // S-70
    traer: async (pagina, porPagina) => (await api.get<Pagina<FilaIngreso>>('/ingresos', { params: { page: pagina, por_pagina: porPagina, corregidos: soloCorregidos ? 1 : undefined } })).data,
  })

  const borradores = filas.filter((i) => i.estado === 'borrador').length

  return (
    <div className="pila">
      <div className="pagina-encabezado encabezado-con-accion">
        <div>
          <h1>Compras</h1>
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
        <Medida etiqueta="Documentos" valor={info ? info.total.toLocaleString('es-CO') : '—'} tono="rojo" icono="ingreso" />
        <Medida etiqueta="Página" valor={info ? `${info.actual} / ${info.ultima}` : '—'} tono="amarillo" icono="catalogo" />
        <Medida etiqueta="En esta página" valor={resultado ? filas.length : '—'} tono="verde" icono="caja" />
        <Medida etiqueta="Borradores aquí" valor={resultado ? borradores : '—'} tono="rojo" icono="auditoria" />
      </Resumen>
      <section className="tarjeta-pagina">
        <div className="fila-titulo">
          <div className="fila-titulo-inicio titulo-con-medida rojo">
            <span className="medida-icono rojo" aria-hidden><Icono nombre="ingreso" /></span>
            <h2>Documentos</h2>
          </div>
          <label className="casilla">
            <input type="checkbox" checked={soloCorregidos} onChange={(e) => setSoloCorregidos(e.target.checked)} /> Solo corregidas
          </label>
        </div>
        <div className="tabla-desplazable">
        <table>
          <thead><tr><th>#</th><th>Tipo</th><th>Documento</th><th>Proveedor</th><th>Fecha</th><th>Pago</th><th>Unidades</th><th>Estado</th><th>Usuario</th><th className="boton-fila-columna">Acciones</th></tr></thead>
          <tbody>
            {filas.map((i) => (
              <tr key={i.id}>
                <td>{i.id}</td>
                <td>{TIPO[i.tipo] ?? i.tipo}</td>
                <td>
                  {i.numero_documento}
                  {i.corregido && <span className="chip corregida" title={`${i.correcciones_count ?? 1} corrección(es) auditada(s)`}>Corregida</span>}
                </td>
                <td>{i.proveedor.nombre}{i.proveedor.asesor && <small className="asesor">Asesor: {i.proveedor.asesor}</small>}</td>
                <td>{i.fecha.slice(0, 10)}</td>
                <td>{i.forma_pago === 'credito' ? <span className="chip credito">Crédito{i.vence_el ? ` · vence ${i.vence_el.slice(0, 10)}` : ''}</span> : 'Contado'}</td>
                <td>{i.unidades ?? 0}</td>
                <td><span className={`chip ${i.estado}`}>{i.estado}</span></td>
                <td>{i.usuario.name}</td>
                {/* S-69: la compra se abre con un botón, no con el número. */}
                <td className="boton-fila-columna">
                  <BotonFila variante="abrir" to={`/logistica/ingresos/${i.id}`} descripcion={`Abrir ${TIPO[i.tipo] ?? 'documento'} ${i.numero_documento}`} />
                </td>
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

const TIPOS: OpcionSegmento<TipoIngreso>[] = [{ valor: 'factura', texto: 'Factura' }, { valor: 'remision', texto: 'Remisión' }]
const PAGOS: OpcionSegmento<FormaPago>[] = [{ valor: 'contado', texto: 'Contado' }, { valor: 'credito', texto: 'Crédito' }]

/** Marca visible de campo obligatorio; el lector de pantalla ya lo anuncia por `required`. */
function Obligatorio() {
  return <span className="obligatorio" aria-hidden="true">*</span>
}

/**
 * Modal "Nuevo ingreso": tipo, proveedor, número, fecha, pago y vencimiento. El descuento del
 * documento no se pide aquí (queda en 0): se fija en el detalle con "Cambiar condiciones".
 * Se monta al abrir, así que cada apertura empieza con los valores iniciales.
 * Solo se cierra sola cuando la creación tiene éxito (navega al detalle); con error queda abierta.
 */
function DialogoNuevoIngreso({ onCerrar }: { onCerrar: () => void }) {
  const navegar = useNavigate()
  const proveedores = useProveedores()
  const [form, setForm] = useState({
    tipo: 'factura' as TipoIngreso,
    proveedor_id: '',
    numero_documento: '',
    fecha: hoyEnBogota(), // S-38: el día en Colombia, no el del navegador
    forma_pago: 'credito' as FormaPago, // S-64: casi toda compra es a crédito
  })
  const campoNumero = useRef<HTMLInputElement>(null)
  /** null = sin tocar: se sugiere fecha + plazo del proveedor. */
  const [venceEscrito, setVenceEscrito] = useState<string | null>(null)
  const plazo = proveedores.data?.find((p) => String(p.id) === form.proveedor_id)?.plazo_dias ?? null
  const venceSugerido = plazo !== null && form.fecha ? sumarDias(form.fecha, plazo) : ''
  const venceEl = venceEscrito ?? venceSugerido
  const credito = form.forma_pago === 'credito'
  // Solo evita envíos vacíos; quien valida es el backend.
  const completo = form.proveedor_id !== '' && form.numero_documento.trim() !== '' && form.fecha !== '' && (!credito || venceEl !== '')

  const crear = useMutation({
    mutationFn: async () =>
      (await api.post<Ingreso>('/ingresos', {
        ...form,
        // Sin espacios al inicio ni al final, ni repetidos por dentro; mayúsculas como se digitaron.
        numero_documento: form.numero_documento.trim().replace(/\s+/g, ' '),
        vence_el: credito ? venceEl || null : null,
      })).data,
    onSuccess: (i) => navegar(`/logistica/ingresos/${i.id}`),
  })
  const existente = crear.isError ? ingresoExistente(crear.error) : null
  const errores = crear.isError ? erroresCampo(crear.error) : {}
  useAvisoTexto(
    crear.isError ? mensajeError(crear.error) : null,
    'error',
    existente ? { to: `/logistica/ingresos/${existente}`, texto: `Ver el ingreso #${existente}` } : null,
  )

  return (
    <Dialogo
      titulo="Nuevo ingreso"
      descripcion={<p className="nota-obligatorio">Los campos con <span className="obligatorio">*</span> son obligatorios.</p>}
      variante="ingreso"
      onCerrar={onCerrar}
      pendiente={crear.isPending}
      onEnviar={() => crear.mutate()}
      pie={(
        <>
          <BotonCancelar />
          <button type="submit" className="primario" disabled={!completo || crear.isPending}>{crear.isPending ? 'Creando…' : 'Crear y escanear'}</button>
        </>
      )}
    >
        <DialogoSeccion modo="cuerpo">
          <div className="ingreso-campos">
            <ControlSegmentado id="nuevo-ingreso-tipo" etiqueta="Tipo" opciones={TIPOS} valor={form.tipo} onCambio={(tipo) => setForm({ ...form, tipo })} />
            <ControlSegmentado id="nuevo-ingreso-pago" etiqueta="Pago" opciones={PAGOS} valor={form.forma_pago} onCambio={(forma_pago) => setForm({ ...form, forma_pago })} />
            <label className="campo-ancho">
              <span className="etiqueta-campo">Proveedor<Obligatorio /></span>
              <SelectorProveedor
                autoFocus
                required
                value={form.proveedor_id}
                onChange={(proveedor_id) => setForm((f) => ({ ...f, proveedor_id }))}
                alElegir={() => campoNumero.current?.focus()}
                permitirCrear
                error={errores.proveedor_id}
              />
            </label>
            <div className="campo-con-ayuda">
              <label>
                <span className="etiqueta-campo">N.º del documento<Obligatorio /></span>
                <input ref={campoNumero} required maxLength={60} aria-describedby="nuevo-ingreso-numero-ayuda" value={form.numero_documento} onChange={(e) => setForm({ ...form, numero_documento: e.target.value })} />
              </label>
              <small id="nuevo-ingreso-numero-ayuda" className="ayuda">Si ese documento ya está en borrador, se abre el mismo para seguir escaneando.</small>
            </div>
            <label>
              <span className="etiqueta-campo">Fecha<Obligatorio /></span>
              <input type="date" required value={form.fecha} onChange={(e) => setForm({ ...form, fecha: e.target.value })} />
            </label>
            {credito && (
              <label className="campo-ancho">
                <span className="etiqueta-campo">
                  Vence el<Obligatorio />
                  {plazo !== null && <span className="chip plazo-proveedor">Plazo del proveedor: {plazo} días</span>}
                </span>
                <input type="date" required aria-invalid={!!errores.vence_el} value={venceEl} onChange={(e) => setVenceEscrito(e.target.value)} />
                {errores.vence_el
                  ? <small className="ayuda error">{errores.vence_el}</small>
                  : <small className="ayuda">{venceEscrito === null && plazo !== null ? `Fecha + ${plazo} días del proveedor; corríjalo con el del documento.` : 'El que trae el documento.'}</small>}
              </label>
            )}
          </div>
        </DialogoSeccion>
    </Dialogo>
  )
}
