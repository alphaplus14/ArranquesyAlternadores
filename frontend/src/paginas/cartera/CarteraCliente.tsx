import { useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, mensajeError } from '../../api/cliente'
import { NOMBRE_MEDIO, type DetalleCartera, type EstadoCuenta, type MovimientoCuenta, type VentaCartera } from '../../api/tipos'
import { useAuth } from '../../auth/AuthProvider'
import Medida from '../../componentes/Medida'
import Resumen from '../../componentes/Resumen'
import ControlSegmentado from '../../componentes/ControlSegmentado'
import FiltroPeriodo, { type ValorPeriodo } from '../../componentes/FiltroPeriodo'
import BarraAntiguedad from '../../componentes/cartera/BarraAntiguedad'
import DialogoAbono from '../../componentes/cartera/DialogoAbono'
import DialogoAnularAbono from '../../componentes/cartera/DialogoAnularAbono'
import { pesos } from '../../componentes/numeros'
import { fechaCorta } from '../../componentes/fechas'
import { textoSaldo } from '../../utilidades/cartera'
import { errorRango, hoyEnBogota, rangoDe } from '../../utilidades/periodos'

type Seccion = 'ventas' | 'cuenta'

const SECCIONES: { valor: Seccion; texto: string }[] = [
  { valor: 'ventas', texto: 'Ventas a crédito' },
  { valor: 'cuenta', texto: 'Estado de cuenta' },
]

/** Fecha y hora local (es-CO) de un instante ISO. */
const fechaHora = (iso: string) => new Date(iso).toLocaleString('es-CO', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })

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
  const [abonando, setAbonando] = useState(false)
  const [anulando, setAnulando] = useState<MovimientoCuenta | null>(null)
  const [aviso, setAviso] = useState<string | null>(null)
  const botonAbono = useRef<HTMLButtonElement>(null)

  const detalle = useQuery({
    queryKey: ['cartera-cliente', id],
    queryFn: async () => (await api.get<DetalleCartera>(`/clientes/${id}/cartera`)).data,
    enabled: Number.isFinite(id),
  })

  const rango = rangoDe(periodo.periodo, hoyEnBogota(), periodo)
  const errorFechas = errorRango(rango)
  const cuenta = useQuery({
    queryKey: ['cartera-cliente', id, 'cuenta', rango.desde, rango.hasta],
    queryFn: async () => (await api.get<EstadoCuenta>(`/clientes/${id}/estado-cuenta`, { params: { desde: rango.desde ?? undefined, hasta: rango.hasta ?? undefined } })).data,
    enabled: Number.isFinite(id) && seccion === 'cuenta' && errorFechas === null,
    placeholderData: (previo) => previo,
  })

  const refrescar = () => {
    qc.invalidateQueries({ queryKey: ['cartera-cliente', id] })
    qc.invalidateQueries({ queryKey: ['cartera'] })
  }

  if (detalle.isError) return <div className="aviso error">{mensajeError(detalle.error)}</div>
  if (!detalle.data) return <div className="panel">Cargando…</div>

  const { resumen, ventas } = detalle.data
  const c = resumen.cliente
  if (!c) return <div className="aviso error">Cliente no encontrado.</div>
  const saldo = Number(resumen.saldo)

  return (
    <div className="pila">
      <div className="pagina-encabezado encabezado-con-accion">
        <div>
          <p className="migas"><Link to="/cartera">Cartera</Link> ›</p>
          <h1>{c.nombre}{!c.activo && <span className="chip inactiva"> Inactivo</span>}</h1>
          <p>{[c.documento && `Doc. ${c.documento}`, c.telefono && `Tel. ${c.telefono}`, `Plazo ${c.plazo_dias} días`].filter(Boolean).join(' · ')}</p>
        </div>
        <button ref={botonAbono} type="button" className="primario" disabled={!c.activo} title={c.activo ? undefined : 'Cliente inactivo'} onClick={() => { setAviso(null); setAbonando(true) }}>
          Registrar abono
        </button>
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
        <Medida etiqueta="Ventas a crédito" valor={ventas.length} tono="verde" icono="venta" />
      </Resumen>

      {aviso && <div className="aviso ok" role="status">{aviso}</div>}

      <section className="tarjeta-pagina">
        <BarraAntiguedad valores={resumen.antiguedad} titulo="Antigüedad del saldo" />
        <ControlSegmentado id="cartera-seccion" etiqueta="Sección" opciones={SECCIONES} valor={seccion} onCambio={setSeccion} />

        {seccion === 'ventas' ? (
          ventas.length === 0 ? <p className="vacio">Este cliente no tiene ventas a crédito cerradas.</p> : (
            <table className="tabla-apilable tabla-cartera-ventas">
              <caption className="solo-lector">Ventas a crédito, de la más antigua a la más reciente; los abonos se aplican en ese orden</caption>
              <thead><tr><th>Venta</th><th>Cierre</th><th className="col-numero">Total neto</th><th>Vence</th><th className="col-numero">Aplicado</th><th className="col-numero">Pendiente</th><th>Estado</th></tr></thead>
              <tbody>
                {ventas.map((v) => (
                  <tr key={v.id}>
                    <td data-etiqueta="Venta"><Link to={`/mostrador/venta?venta=${v.id}`}>#{v.id}</Link></td>
                    <td data-etiqueta="Cierre">{fechaHora(v.cerrada_at.replace(' ', 'T') + 'Z')}</td>
                    <td data-etiqueta="Total neto" className="col-numero">{pesos(v.total)}</td>
                    <td data-etiqueta="Vence">{v.vence_el ? fechaCorta(v.vence_el) : '—'}</td>
                    <td data-etiqueta="Aplicado" className="col-numero">{pesos(v.aplicado)}</td>
                    <td data-etiqueta="Pendiente" className="col-numero"><strong>{pesos(v.pendiente)}</strong></td>
                    <td data-etiqueta="Estado"><ChipEstado v={v} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          )
        ) : (
          <>
            <div className="formulario en-linea filtros-ajustes">
              <FiltroPeriodo id="cuenta-periodo" valor={periodo} onCambio={setPeriodo} />
            </div>
            {errorFechas ? <p className="vacio">Corrija el rango de fechas.</p> : cuenta.isError ? <div className="aviso error">{mensajeError(cuenta.error)}</div> : !cuenta.data ? <p className="vacio">Cargando…</p> : (
              <table className={cuenta.isFetching ? 'tabla-apilable tabla-cuenta actualizando' : 'tabla-apilable tabla-cuenta'}>
                <caption className="solo-lector">Estado de cuenta en orden de fecha con saldo acumulado</caption>
                <thead><tr><th>Fecha</th><th>Movimiento</th><th className="col-numero">Cargo</th><th className="col-numero">Abono</th><th className="col-numero">Saldo</th><th><span className="solo-lector">Acciones</span></th></tr></thead>
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
                            ? <Link to={`/mostrador/venta?venta=${m.venta_id}`}>{m.descripcion}</Link>
                            : <span className="texto-movimiento">{m.tipo === 'abono' && m.medio ? `Abono · ${NOMBRE_MEDIO[m.medio]}${m.referencia ? ` · ${m.referencia}` : ''}` : m.descripcion}</span>}
                          {m.anulado && <span className="chip anulado"> Anulado</span>}
                          {m.motivo && <small className="bloque">Motivo: {m.motivo}{m.usuario ? ` · ${m.usuario}` : ''}</small>}
                          {m.anulacion && <small className="bloque">Anulado por {m.anulacion.usuario ?? '—'} el {fechaHora(m.anulacion.fecha)}: {m.anulacion.motivo}</small>}
                          {m.tipo === 'abono' && !m.anulacion && m.usuario && <small className="bloque">Registró {m.usuario}</small>}
                        </span>
                      </td>
                      <td data-etiqueta="Cargo" className="col-numero">{Number(m.cargo) > 0 ? pesos(m.cargo) : ''}</td>
                      <td data-etiqueta="Abono" className="col-numero"><span className={m.anulado ? 'valor-tachado' : undefined}>{Number(m.abono) > 0 ? pesos(m.abono) : ''}</span></td>
                      <td data-etiqueta="Saldo" className="col-numero"><strong>{textoSaldo(m.saldo)}</strong></td>
                      <td className="celda-accion">
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
          onCerrar={() => { setAbonando(false); setTimeout(() => botonAbono.current?.focus(), 0) }}
          onGuardado={(abono, nuevo) => {
            // Saldo y antigüedad nuevos al instante; las listas se refrescan en segundo plano.
            qc.setQueryData<DetalleCartera>(['cartera-cliente', id], (d) => (d ? { ...d, resumen: nuevo, abonos: [abono, ...d.abonos] } : d))
            refrescar()
            setAviso(`Abono de ${pesos(abono.valor)} registrado. Saldo: ${textoSaldo(nuevo.saldo)}.`)
            setAbonando(false)
            setTimeout(() => botonAbono.current?.focus(), 0)
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
