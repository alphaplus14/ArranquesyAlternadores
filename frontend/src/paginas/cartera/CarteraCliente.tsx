import { useEffect, useRef, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import { NOMBRE_MEDIO, type EstadoCuenta, type DetalleCartera, type EstadoFiltroVenta, type MovimientoCuenta, type VentaCartera } from '../../api/tipos'
import { useAuth } from '../../auth/AuthProvider'
import { useDebounce } from '../../hooks/useDebounce'
import BotonFila from '../../componentes/BotonFila'
import Icono from '../../componentes/Icono'
import Medida from '../../componentes/Medida'
import Resumen from '../../componentes/Resumen'
import ControlSegmentado from '../../componentes/ControlSegmentado'
import FiltroPeriodo, { type ValorPeriodo } from '../../componentes/FiltroPeriodo'
import BarraAntiguedad from '../../componentes/cartera/BarraAntiguedad'
import DialogoAbono from '../../componentes/cartera/DialogoAbono'
import DialogoAnularAbono from '../../componentes/cartera/DialogoAnularAbono'
import { pesos } from '../../componentes/numeros'
import { fechaCorta } from '../../componentes/fechas'
import { nombreVenta, textoSaldo } from '../../utilidades/cartera'
import { formatearFechaHora } from '../../utilidades/fechas'
import { errorRango, esPeriodo, hoyEnBogota, rangoDe } from '../../utilidades/periodos'

type Seccion = 'ventas' | 'cuenta'

/** S-30: estados calculados con el pendiente FIFO de todas las ventas del cliente. */
const ESTADOS: { valor: EstadoFiltroVenta; texto: string }[] = [
  { valor: 'todas', texto: 'Todas' },
  { valor: 'pendientes', texto: 'Con pendiente' },
  { valor: 'vencidas', texto: 'Vencidas' },
  { valor: 'pagadas', texto: 'Pagadas' },
]

const SECCIONES: { valor: Seccion; texto: string }[] = [
  { valor: 'ventas', texto: 'Ventas a crédito' },
  { valor: 'cuenta', texto: 'Estado de cuenta' },
]

/** Fecha y hora en Colombia de un instante (S-38: utilidades/fechas). */
const fechaHora = formatearFechaHora

/** Chip de estado de una venta: el texto dice el estado, el color solo lo refuerza. */
function ChipEstado({ v }: { v: VentaCartera }) {
  const icono = v.estado === 'vencida' ? '!' : v.estado === 'por_vencer' ? '◷' : '✓'
  return <span className={`chip estado-venta ${v.estado}`}><span aria-hidden>{icono} </span>{v.estado_texto}</span>
}

/**
 * RF-18 (S-30): cartera de un cliente. Ventas a crédito con el abono aplicado (FIFO), pendiente y
 * estado; estado de cuenta con saldo acumulado. Registrar abono (mostrador y admin) y anular abono
 * (solo admin). Solo en pantalla.
 */
export default function CarteraCliente() {
  const { clienteId } = useParams()
  const id = Number(clienteId)
  const qc = useQueryClient()
  const { usuario } = useAuth()
  const esAdmin = usuario?.rol === 'admin'
  const [seccion, setSeccion] = useState<Seccion>('ventas')
  const [periodo, setPeriodo] = useState<ValorPeriodo>({ periodo: 'todo', desde: '', hasta: '' })
  /** S-32: abierto con o sin una venta elegida ("Abonar" desde la fila). */
  const [abonando, setAbonando] = useState<{ venta?: VentaCartera } | null>(null)
  /** Botón que abrió la modal de abono: recupera el foco al cerrarla. */
  const disparadorAbono = useRef<HTMLButtonElement | null>(null)
  const [anulando, setAnulando] = useState<MovimientoCuenta | null>(null)
  const [aviso, setAviso] = useState<string | null>(null)
  const botonAbono = useRef<HTMLButtonElement>(null)

  // Encabezado y lista completa: sin filtros, así el saldo, el vencido y la antigüedad nunca cambian
  // al filtrar (S-30).
  const detalle = useQuery({
    queryKey: ['cartera-cliente', id],
    queryFn: async () => (await api.get<DetalleCartera>(`/clientes/${id}/cartera`)).data,
    enabled: Number.isFinite(id),
  })

  // Filtros de "Ventas a crédito" en la URL (?q=&estado=&periodo=; desde/hasta si es personalizado)
  // para que recargar o compartir el enlace conserve la vista.
  const [params, setParams] = useSearchParams()
  const [q, setQ] = useState(() => params.get('q') ?? '')
  const termino = useDebounce(q.trim())
  const crudoEstado = params.get('estado')
  const estado: EstadoFiltroVenta = ESTADOS.some((e) => e.valor === crudoEstado) ? crudoEstado as EstadoFiltroVenta : 'todas'
  const crudoPeriodo = params.get('periodo')
  const periodoVentas: ValorPeriodo = {
    periodo: esPeriodo(crudoPeriodo) ? crudoPeriodo : 'todo',
    desde: params.get('desde') ?? '',
    hasta: params.get('hasta') ?? '',
  }
  const rangoVentas = rangoDe(periodoVentas.periodo, hoyEnBogota(), periodoVentas)
  const errorFechasVentas = errorRango(rangoVentas)
  const hayFiltros = termino !== '' || estado !== 'todas' || periodoVentas.periodo !== 'todo'

  const cambiarParams = (cambios: Record<string, string | null>) => setParams((actual) => {
    const siguiente = new URLSearchParams(actual)
    for (const [k, v] of Object.entries(cambios)) {
      if (v) siguiente.set(k, v)
      else siguiente.delete(k)
    }
    return siguiente
  }, { replace: true })

  useEffect(() => {
    if ((params.get('q') ?? '') !== termino) cambiarParams({ q: termino || null })
    // Solo cuando cambia el término ya demorado.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [termino])

  const cambiarPeriodoVentas = (v: ValorPeriodo) => cambiarParams({
    periodo: v.periodo === 'todo' ? null : v.periodo,
    desde: v.periodo === 'personalizado' && v.desde ? v.desde : null,
    hasta: v.periodo === 'personalizado' && v.hasta ? v.hasta : null,
  })
  const limpiarFiltros = () => {
    setQ('')
    cambiarParams({ q: null, estado: null, periodo: null, desde: null, hasta: null })
  }

  // Con filtros, solo se vuelve a pedir la lista de ventas; el encabezado queda quieto.
  const filtradas = useQuery({
    queryKey: ['cartera-cliente', id, 'ventas', termino, estado, rangoVentas.desde, rangoVentas.hasta],
    queryFn: async () => (await api.get<DetalleCartera>(`/clientes/${id}/cartera`, {
      params: {
        busqueda: termino || undefined,
        estado: estado === 'todas' ? undefined : estado,
        desde: rangoVentas.desde ?? undefined,
        hasta: rangoVentas.hasta ?? undefined,
      },
    })).data,
    enabled: Number.isFinite(id) && hayFiltros && errorFechasVentas === null,
    placeholderData: (previo) => previo,
  })

  const rango = rangoDe(periodo.periodo, hoyEnBogota(), periodo)
  const errorFechas = errorRango(rango)
  const cuenta = useQuery({
    queryKey: ['cartera-cliente', id, 'cuenta', rango.desde, rango.hasta],
    queryFn: async () => (await api.get<EstadoCuenta>(`/clientes/${id}/estado-cuenta`, { params: { desde: rango.desde ?? undefined, hasta: rango.hasta ?? undefined } })).data,
    enabled: Number.isFinite(id) && seccion === 'cuenta' && errorFechas === null,
    placeholderData: (previo) => previo,
  })

  // Al cerrar la modal de abono el foco vuelve al botón que la abrió (el de la fila o el del encabezado).
  // Si ese botón ya no existe (la venta quedó saldada), vuelve a "Registrar abono".
  const cerrarAbono = () => {
    setAbonando(null)
    setTimeout(() => {
      const b = disparadorAbono.current
      ;(b && b.isConnected ? b : botonAbono.current)?.focus()
    }, 0)
  }

  const refrescar = () => {
    qc.invalidateQueries({ queryKey: ['cartera-cliente', id] })
    qc.invalidateQueries({ queryKey: ['cartera'] })
  }

  if (detalle.isError) return <div className="aviso error">{mensajeError(detalle.error)}</div>
  if (!detalle.data) return <div className="panel">Cargando…</div>

  const { resumen, ventas_total: totalVentas } = detalle.data
  const ventas = hayFiltros ? (filtradas.data?.ventas ?? []) : detalle.data.ventas
  const cargandoVentas = hayFiltros && errorFechasVentas === null && (filtradas.isFetching || !filtradas.data)
  const c = resumen.cliente
  if (!c) return <div className="aviso error">Cliente no encontrado.</div>
  const saldo = Number(resumen.saldo)
  // S-79: todo abono va a una venta con pendiente (de todas las del cliente, no solo las filtradas).
  const conSaldo = detalle.data.ventas.some((v) => Number(v.pendiente) > 0)
  const motivoSinAbono = !c.activo ? 'Cliente inactivo' : !conSaldo ? 'Este cliente no tiene facturas con saldo.' : undefined

  return (
    <div className="pila">
      <div className="pagina-encabezado encabezado-con-accion">
        <div>
          <p className="migas"><Link to="/cartera">Cartera</Link> ›</p>
          <h1>{c.nombre}{!c.activo && <span className="chip inactiva"> Inactivo</span>}</h1>
          <p>{[c.documento && `Doc. ${c.documento}`, c.telefono && `Tel. ${c.telefono}`, `Plazo ${c.plazo_dias} días`].filter(Boolean).join(' · ')}</p>
        </div>
        <div className="accion-encabezado">
          <button
            ref={botonAbono}
            type="button"
            className="primario"
            disabled={motivoSinAbono !== undefined}
            title={motivoSinAbono}
            aria-describedby={motivoSinAbono ? 'abono-no-disponible' : undefined}
            onClick={(e) => { disparadorAbono.current = e.currentTarget; setAviso(null); setAbonando({}) }}
          >
            Registrar abono
          </button>
          {motivoSinAbono && <small id="abono-no-disponible" className="ayuda">{motivoSinAbono}</small>}
        </div>
      </div>

      <Resumen
        clave="cartera-cliente-resumen"
        compacto={(
          <>
            <span>Saldo <strong>{textoSaldo(resumen.saldo)}</strong></span>
            <span className={Number(resumen.vencido) > 0 ? 'resumen-agotados' : ''}>Vencido <strong>{pesos(resumen.vencido)}</strong></span>
            <span>Próximo vencimiento <strong>{resumen.proximo_vencimiento ? fechaCorta(resumen.proximo_vencimiento) : '—'}</strong></span>
          </>
        )}
      >
        <Medida etiqueta={saldo < 0 ? 'Saldo a favor' : 'Saldo'} valor={pesos(Math.abs(saldo))} pie={`Ventas ${pesos(resumen.ventas)} · abonos ${pesos(resumen.abonado)}`} tono="azul" icono="billetera" />
        <Medida etiqueta="Vencido" valor={pesos(resumen.vencido)} pie={resumen.vencida_desde ? `desde ${fechaCorta(resumen.vencida_desde)}` : undefined} tono="rojo" icono="agotados" />
        <Medida etiqueta="Próximo vencimiento" valor={resumen.proximo_vencimiento ? fechaCorta(resumen.proximo_vencimiento) : '—'} tono="amarillo" icono="auditoria" />
        <Medida etiqueta="Ventas a crédito" valor={totalVentas} tono="verde" icono="venta" />
      </Resumen>

      {aviso && <div className="aviso ok" role="status">{aviso}</div>}

      <section className="tarjeta-pagina">
        <BarraAntiguedad valores={resumen.antiguedad} titulo="Antigüedad del saldo" />
        <ControlSegmentado id="cartera-seccion" etiqueta="Sección" opciones={SECCIONES} valor={seccion} onCambio={setSeccion} />

        {seccion === 'ventas' ? (
          totalVentas === 0 ? <p className="vacio">Este cliente no tiene ventas a crédito cerradas.</p> : (
          <>
            <div className="formulario en-linea filtros-ventas-cartera">
              <label className="busca">
                <Icono nombre="buscar" />
                <input placeholder="N.º de venta o factura" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Buscar por número de venta o factura de Contapyme" />
              </label>
              <label>Estado
                <select value={estado} onChange={(e) => cambiarParams({ estado: e.target.value === 'todas' ? null : e.target.value })}>
                  {ESTADOS.map((e) => <option key={e.valor} value={e.valor}>{e.texto}</option>)}
                </select>
              </label>
              <FiltroPeriodo id="ventas-periodo" valor={periodoVentas} onCambio={cambiarPeriodoVentas} />
              {hayFiltros && <button type="button" className="secundario boton-44 limpiar-filtros" onClick={limpiarFiltros}>Limpiar filtros</button>}
            </div>
            <p className="ayuda contador-ventas" role="status">
              {errorFechasVentas ? '' : cargandoVentas && !filtradas.data ? 'Buscando…' : `${ventas.length} de ${totalVentas} ventas`}
            </p>
            {errorFechasVentas ? <p className="vacio">Corrija el rango de fechas.</p> : filtradas.isError && hayFiltros ? <div className="aviso error">{mensajeError(filtradas.error)}</div> : ventas.length === 0 ? (
              cargandoVentas ? <p className="vacio">Cargando…</p> : (
                <div className="vacio vacio-con-accion">
                  <p>Ninguna venta coincide con esos filtros.</p>
                  <button type="button" className="secundario boton-44" onClick={limpiarFiltros}>Limpiar filtros</button>
                </div>
              )
            ) : (
            <table className={cargandoVentas ? 'tabla-apilable tabla-cartera-ventas actualizando' : 'tabla-apilable tabla-cartera-ventas'}>
              <caption className="solo-lector">Ventas a crédito, de la más antigua a la más reciente; los abonos se aplican en ese orden</caption>
              <thead><tr><th>Venta</th><th>Factura</th><th>Cierre</th><th className="col-numero" title="Total neto de reversos, con IVA incluido">Total (IVA incl.)</th><th>Vence</th><th className="col-numero">Aplicado</th><th className="col-numero">Pendiente</th><th>Estado</th><th className="boton-fila-columna boton-fila-doble">Acciones</th></tr></thead>
              <tbody>
                {ventas.map((v) => (
                  <tr key={v.id}>
                    <td data-etiqueta="Venta">#{v.id}</td>
                    <td data-etiqueta="Factura">{v.factura_contapyme || '—'}</td>
                    <td data-etiqueta="Cierre">{fechaHora(v.cerrada_at)}</td>
                    <td data-etiqueta="Total" title="Total neto de reversos, con IVA incluido" className="col-numero">{pesos(v.total)}</td>
                    <td data-etiqueta="Vence">{v.vence_el ? fechaCorta(v.vence_el) : '—'}</td>
                    <td data-etiqueta="Aplicado" className="col-numero">
                      {pesos(v.aplicado)}
                      {Number(v.aplicado_dirigido) > 0 && <small className="bloque desglose-dirigido">{pesos(v.aplicado_dirigido)} dirigido</small>}
                    </td>
                    <td data-etiqueta="Pendiente" className="col-numero"><strong>{pesos(v.pendiente)}</strong></td>
                    <td data-etiqueta="Estado"><ChipEstado v={v} /></td>
                    <td className="celda-accion boton-fila-columna boton-fila-doble">
                      <span className="boton-fila-acciones">
                        <BotonFila variante="ver" to={`/mostrador/venta?venta=${v.id}`} etiqueta="Ver venta" descripcion={`Ver la venta ${nombreVenta(v)}`} />
                        {Number(v.pendiente) > 0 && c.activo && (
                          <BotonFila
                            variante="pagar"
                            etiqueta="Abonar"
                            descripcion={`Abonar a la venta ${nombreVenta(v)}`}
                            onClick={(e) => { disparadorAbono.current = e.currentTarget; setAviso(null); setAbonando({ venta: v }) }}
                          />
                        )}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            )}
          </>
          )
        ) : (
          <>
            <div className="formulario en-linea filtros-ajustes">
              <FiltroPeriodo id="cuenta-periodo" valor={periodo} onCambio={setPeriodo} />
            </div>
            {errorFechas ? <p className="vacio">Corrija el rango de fechas.</p> : cuenta.isError ? <div className="aviso error">{mensajeError(cuenta.error)}</div> : !cuenta.data ? <p className="vacio">Cargando…</p> : (
              <table className={cuenta.isFetching ? 'tabla-apilable tabla-cuenta actualizando' : 'tabla-apilable tabla-cuenta'}>
                <caption className="solo-lector">Estado de cuenta en orden de fecha con saldo acumulado</caption>
                <thead><tr><th>Fecha</th><th>Movimiento</th><th className="col-numero" title="Ventas por su total con IVA incluido">Cargo (IVA incl.)</th><th className="col-numero">Abono</th><th className="col-numero">Saldo</th><th className="boton-fila-columna boton-fila-doble">Acciones</th></tr></thead>
                <tbody>
                  {(rango.desde !== null) && (
                    <tr className="fila-saldo-inicial">
                      <td data-etiqueta="Fecha">{rango.desde ? fechaCorta(rango.desde) : ''}</td>
                      <td data-etiqueta="Movimiento">Saldo anterior</td>
                      <td className="col-numero" /><td className="col-numero" />
                      <td data-etiqueta="Saldo" className="col-numero"><strong>{textoSaldo(cuenta.data.saldo_inicial)}</strong></td>
                      <td />
                    </tr>
                  )}
                  {cuenta.data.movimientos.length === 0 && <tr><td colSpan={6} className="vacio">Sin movimientos en este período.</td></tr>}
                  {cuenta.data.movimientos.map((m) => (
                    <tr key={`${m.tipo}-${m.venta_id ?? ''}-${m.reverso_id ?? ''}-${m.abono_id ?? ''}`} className={m.anulado ? 'movimiento-anulado' : undefined}>
                      <td data-etiqueta="Fecha">{fechaCorta(m.dia)}</td>
                      <td data-etiqueta="Movimiento">
                        <span>
                          {m.venta_id && m.tipo === 'venta'
                            ? <span className="texto-movimiento">{m.descripcion}</span>
                            : <span className="texto-movimiento">{m.tipo === 'abono' && m.medio ? `Abono · ${NOMBRE_MEDIO[m.medio]}${m.referencia ? ` · ${m.referencia}` : ''}` : m.descripcion}</span>}
                          {m.anulado && <span className="chip anulado"> Anulado</span>}
                          {m.motivo && <small className="bloque">Motivo: {m.motivo}{m.usuario ? ` · ${m.usuario}` : ''}</small>}
                          {m.anulacion && <small className="bloque">Anulado por {m.anulacion.usuario ?? '—'} el {fechaHora(m.anulacion.fecha)}: {m.anulacion.motivo}</small>}
                          {m.tipo === 'abono' && m.aplicado_a && <small className="bloque aplicado-a">Aplicado a: {m.aplicado_a === 'General' ? 'General (las más antiguas)' : m.aplicado_a}</small>}
                          {m.tipo === 'abono' && !m.anulacion && m.usuario && <small className="bloque">Registró {m.usuario}</small>}
                        </span>
                      </td>
                      <td data-etiqueta="Cargo" className="col-numero">{Number(m.cargo) > 0 ? pesos(m.cargo) : ''}</td>
                      <td data-etiqueta="Abono" className="col-numero"><span className={m.anulado ? 'valor-tachado' : undefined}>{Number(m.abono) > 0 ? pesos(m.abono) : ''}</span></td>
                      <td data-etiqueta="Saldo" className="col-numero"><strong>{textoSaldo(m.saldo)}</strong></td>
                      <td className="celda-accion boton-fila-columna boton-fila-doble">
                        {m.venta_id ? (
                          <BotonFila variante="ver" to={`/mostrador/venta?venta=${m.venta_id}`} etiqueta="Ver venta" descripcion={`Ver la venta #${m.venta_id}`} />
                        ) : null}
                        {esAdmin && m.tipo === 'abono' && !m.anulado && (
                          <button type="button" className="secundario boton-44" onClick={() => { setAviso(null); setAnulando(m) }}>Anular</button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </>
        )}
      </section>

      {abonando && (
        <DialogoAbono
          cliente={c}
          saldo={resumen.saldo}
          ventaInicial={abonando.venta}
          onCerrar={cerrarAbono}
          onGuardado={(abono, nuevo) => {
            // Saldo y antigüedad nuevos al instante; las listas se refrescan en segundo plano.
            qc.setQueryData<DetalleCartera>(['cartera-cliente', id], (d) => (d ? { ...d, resumen: nuevo, abonos: [abono, ...d.abonos] } : d))
            refrescar()
            // S-79: todo abono nuevo va a una venta.
            setAviso(`Abono de ${pesos(abono.valor)} registrado a la venta ${abono.aplicado_a.replace(/^Venta /, '')}. Saldo: ${textoSaldo(nuevo.saldo)}.`)
            cerrarAbono()
          }}
        />
      )}
      {anulando?.abono_id && (
        <DialogoAnularAbono
          abono={{ id: anulando.abono_id, fecha: anulando.dia, valor: anulando.abono, medio: anulando.medio ?? 'otro', referencia: anulando.referencia }}
          onCerrar={() => setAnulando(null)}
          onAnulado={(nuevo) => {
            qc.setQueryData<DetalleCartera>(['cartera-cliente', id], (d) => (d ? { ...d, resumen: nuevo } : d))
            refrescar()
            setAviso(`Abono anulado. Saldo: ${textoSaldo(nuevo.saldo)}.`)
            setAnulando(null)
          }}
        />
      )}
    </div>
  )
}
