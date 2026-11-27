import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { api, erroresCampo, mensajeError } from '../../api/cliente'
import { useAvisoTexto } from '../../componentes/Avisos'
import Dialogo, { DialogoSeccion } from '../../componentes/Dialogo'
import { infoPagina, type FormaPago, type Ingreso, type Pagina, type TipoIngreso } from '../../api/tipos'
import { sumarDias } from '../../componentes/fechas'
import { hoyEnBogota } from '../../utilidades/periodos'
import ControlSegmentado, { type OpcionSegmento } from '../../componentes/ControlSegmentado'
import SelectorProveedor from '../../componentes/SelectorProveedor'
import { useFiltrosUrl } from '../../hooks/useFiltrosUrl'
import { useFilasPorPagina } from '../../preferencias/preferencias'
import BarraFiltros, { CampoFiltro, TextoResultados, VacioFiltros } from '../../componentes/BarraFiltros'
import { useProveedores } from '../../hooks/useCatalogos'
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
  const qc = useQueryClient()
  const [creando, setCreando] = useState(false)
  const botonNuevo = useRef<HTMLButtonElement>(null)
  const cerrar = useCallback(() => {
    setCreando(false)
    // Al cerrar, el foco vuelve al botón que abrió la modal.
    requestAnimationFrame(() => botonNuevo.current?.focus())
  }, [])

  // S-91: filtros y página en la URL (?q=&mes=&borradores=1&corregidos=1&desde=&hasta=&page=), con
  // los valores por defecto omitidos, para volver de un documento sin perder el estado. Todo filtra
  // en el servidor; cambiar un filtro vuelve a la página 1.
  const { filtros: f, fijar, pagina, irA, activos, limpiar } = useFiltrosUrl(
    { q: '', mes: '', borradores: '', corregidos: '', desde: '', hasta: '' }, '', 'page',
  )
  // S-91: período "Por mes" (select Mes) o "Personalizada" (Desde/Hasta). Con fechas en la URL abre
  // solo en Personalizada; mes y fechas no conviven: elegir uno limpia el otro.
  const [personalAbierta, setPersonalAbierta] = useState(() => f.desde !== '' || f.hasta !== '')
  const personalizada = personalAbierta || f.desde !== '' || f.hasta !== ''
  const rangoMalo = f.desde !== '' && f.hasta !== '' && f.hasta < f.desde
  const cambiarModo = (modo: ModoPeriodo) => {
    if (modo === 'personalizada') { setPersonalAbierta(true); fijar({ mes: '' }) } else { setPersonalAbierta(false); fijar({ desde: '', hasta: '' }) }
  }
  // El período cuenta una sola vez (Desde y Hasta son un filtro).
  const activosBarra = activos - (f.desde !== '' && f.hasta !== '' ? 1 : 0)
  const porPagina = useFilasPorPagina(25) // S-70
  const filtrosServidor = {
    q: f.q || undefined,
    borradores: f.borradores ? 1 : undefined,
    corregidos: f.corregidos ? 1 : undefined,
  }
  const paramsEscritos = {
    ...filtrosServidor,
    mes: f.mes || undefined,
    desde: f.mes ? undefined : f.desde || undefined,
    hasta: f.mes ? undefined : f.hasta || undefined,
  }
  // Con Hasta anterior a Desde no se consulta: la tabla se queda con la última consulta válida.
  // Si se entra con el rango ya invertido, la última válida es la misma consulta sin fechas.
  const ultimosValidos = useRef(rangoMalo ? { ...paramsEscritos, desde: undefined, hasta: undefined } : paramsEscritos)
  if (!rangoMalo) ultimosValidos.current = paramsEscritos
  const params = rangoMalo ? ultimosValidos.current : paramsEscritos
  const forma = JSON.stringify(['ingresos', params, porPagina])
  const lista = useQuery({
    queryKey: ['ingresos', params, porPagina, pagina],
    queryFn: async () => (await api.get<Pagina<FilaIngreso>>('/ingresos', { params: { ...params, page: pagina, por_pagina: porPagina } })).data,
    // Solo entre páginas de la misma consulta: con otros filtros se muestra "Cargando…", no filas ajenas.
    placeholderData: (previo, anterior) => (JSON.stringify(anterior?.queryKey.slice(0, 3)) === forma ? previo : undefined),
  })
  const meses = useQuery({
    queryKey: ['ingresos-meses', filtrosServidor],
    queryFn: async () => (await api.get<{ data: MesConCompras[] }>('/ingresos/meses', { params: filtrosServidor })).data.data,
    placeholderData: (previo) => previo,
  })
  // Si el mes elegido deja de tener documentos (p. ej. con otro filtro), vuelve a "Todos los meses".
  useEffect(() => {
    if (f.mes && meses.data && !meses.isPlaceholderData && !meses.data.some((m) => m.mes === f.mes)) fijar({ mes: '' })
  }, [f.mes, meses.data, meses.isPlaceholderData, fijar])

  const resultado = lista.data
  const filas = resultado?.data ?? []
  const info = resultado ? infoPagina(resultado) : null
  const limpiarTodo = () => { setPersonalAbierta(false); limpiar() }
  const precargar = (id: number) => {
    const clave = String(id)
    void qc.prefetchQuery({
      queryKey: ['ingreso', clave],
      queryFn: async () => (await api.get<Ingreso>(`/ingresos/${clave}`)).data,
    })
  }

  return (
    <div className="pila pagina-tabla-fija">
      <div className="pagina-encabezado encabezado-con-accion">
        <div>
          <h1>Compras</h1>
          <p>Remisiones y facturas se crean a mano; los ítems se agregan escaneándolos. Un documento no entra dos veces.</p>
        </div>
        <button ref={botonNuevo} type="button" className="primario" onClick={() => setCreando(true)}>Nuevo ingreso</button>
      </div>
      <section className="tarjeta-pagina tarjeta-tabla">
        <div className="fila-titulo">
          <div className="fila-titulo-inicio titulo-con-medida rojo">
            <span className="medida-icono rojo" aria-hidden><Icono nombre="ingreso" /></span>
            <h2>Documentos</h2>
          </div>
        </div>
        <BarraFiltros
          // S-87: factura, proveedor o NIT; con menos de 2 caracteres no se envía y la tabla conserva lo anterior.
          busqueda={{ valor: f.q, onCambio: (v) => { if (v === '' || v.length >= 2) fijar({ q: v.slice(0, 60) }) }, placeholder: 'Factura, proveedor o NIT', etiqueta: 'Buscar compras' }}
          activos={activosBarra}
          onLimpiar={limpiarTodo}
          clase="barra-compras"
        >
          <CampoFiltro etiqueta="Mes">
            <select className="filtro-mes" disabled={personalizada} value={personalizada ? '' : f.mes} onChange={(e) => fijar({ mes: e.target.value, desde: '', hasta: '' })}>
              <option value="">Todos los meses</option>
              {f.mes && !meses.data?.some((m) => m.mes === f.mes) && <option value={f.mes}>{nombreMes(f.mes)}</option>}
              {(meses.data ?? []).map((m) => <option key={m.mes} value={m.mes}>{nombreMes(m.mes)} · {m.total.toLocaleString('es-CO')}</option>)}
            </select>
          </CampoFiltro>
          <ControlSegmentado<ModoPeriodo>
            id="compras-periodo"
            etiqueta="Período"
            clase="filtro-periodo"
            valor={personalizada ? 'personalizada' : 'mes'}
            onCambio={cambiarModo}
            opciones={[{ valor: 'mes', texto: 'Por mes' }, { valor: 'personalizada', texto: 'Personalizada' }]}
          />
          {personalizada && (
            <>
              <CampoFiltro etiqueta="Desde">
                <input type="date" value={f.desde} onChange={(e) => fijar({ desde: e.target.value, mes: '' })} />
              </CampoFiltro>
              <label className="barra-filtros-campo">
                <span className="etiqueta-campo">Hasta</span>
                <input
                  type="date"
                  value={f.hasta}
                  aria-invalid={rangoMalo}
                  aria-describedby={rangoMalo ? 'compras-hasta-error' : undefined}
                  onChange={(e) => fijar({ hasta: e.target.value, mes: '' })}
                />
                {rangoMalo && <small id="compras-hasta-error" className="ayuda error" role="alert">No puede ser antes de Desde.</small>}
              </label>
            </>
          )}
          <label className="casilla barra-filtros-check">
            <input type="checkbox" checked={f.borradores === '1'} onChange={(e) => fijar({ borradores: e.target.checked ? '1' : '' })} /> Borradores
          </label>
          <label className="casilla barra-filtros-check">
            <input type="checkbox" checked={f.corregidos === '1'} onChange={(e) => fijar({ corregidos: e.target.checked ? '1' : '' })} /> Solo corregidas
          </label>
        </BarraFiltros>
        {info && <TextoResultados mostrando={filas.length} total={info.total} de="documentos" />}
        {!resultado ? (
          <div className="cargando">Cargando…</div>
        ) : filas.length === 0 ? (
          <p className="vacio"><VacioFiltros hayFiltros={activosBarra > 0} sinFiltros="Aún no hay compras." onLimpiar={limpiarTodo} /></p>
        ) : (
        <div className="tabla-desplazable tabla-viva llena" aria-busy={lista.isFetching}>
        <table>
          <thead><tr><th>#</th><th>Tipo</th><th>Documento</th><th>Proveedor</th><th>Fecha</th><th>Pago</th><th>Unidades</th><th>Estado</th><th>Usuario</th><th className="boton-fila-columna boton-fila-icono"><span className="solo-lector">Acciones</span></th></tr></thead>
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
                <td className="boton-fila-columna boton-fila-icono">
                  <BotonFila soloIcono variante="abrir" to={`/logistica/ingresos/${i.id}`} descripcion={`Abrir ${TIPO[i.tipo] ?? 'documento'} ${i.numero_documento}`} onMouseEnter={() => precargar(i.id)} onFocus={() => precargar(i.id)} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        </div>
        )}
        <Paginacion pagina={resultado} onCambiar={irA} />
      </section>

      {creando && <DialogoNuevoIngreso onCerrar={cerrar} />}
    </div>
  )
}

interface MesConCompras { mes: string; total: number }
type ModoPeriodo = 'mes' | 'personalizada'

/** '2026-11' → 'Noviembre 2026'. */
function nombreMes(mes: string): string {
  const [a, m] = mes.split('-').map(Number)
  const texto = new Date(a, m - 1, 1).toLocaleDateString('es-CO', { month: 'long', year: 'numeric' }).replace(' de ', ' ')
  return texto.charAt(0).toUpperCase() + texto.slice(1)
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
